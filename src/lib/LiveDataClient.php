<?php

declare(strict_types=1);

require_once __DIR__ . '/Logger.php';
require_once __DIR__ . '/NominatimGeocoder.php';

final class LiveDataClient
{
    private const CACHE_TTL_SECONDS = 10800; // 3 hours
    private const CACHE_MAX_ENTRIES = 200;
    private const HTTP_TIMEOUT_SECONDS = 12;

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function fetchPlaceData(string $query, array $context = []): array
    {
        $query = trim($query);
        if ($query === '') {
            throw new \InvalidArgumentException('Query is required.');
        }

        $context = $this->normalizeContext($context);
        $signature = $this->cacheSignature($query, $context);
        $cacheDir = $this->cacheDirectory();

        $cached = $this->readCache($cacheDir, $signature);
        if ($cached !== null) {
            return $cached;
        }

        $now = $this->now();
        $coordinates = $this->resolveCoordinates($query, $context);

        $result = [
            'query' => $query,
            'matched' => false,
            'name' => '',
            'category' => '',
            'contact' => [
                'address' => $context['address'] ?? '',
                'hours' => '',
                'phone' => '',
                'website' => '',
            ],
            'coordinates' => $coordinates,
            'rating' => null,
            'price' => '',
            'source_url' => '',
            'lastChecked' => $now,
            'sources' => [],
        ];

        $openTrip = $this->fetchFromOpenTripMap($query, $context, $coordinates);
        if ($openTrip !== null) {
            $result['matched'] = true;
            if ($openTrip['name'] !== '') {
                $result['name'] = $openTrip['name'];
            }
            if ($openTrip['category'] !== '') {
                $result['category'] = $openTrip['category'];
            }
            if ($openTrip['coordinates'] !== null) {
                $result['coordinates'] = $openTrip['coordinates'];
            }
            $result['contact'] = $this->mergeContact($result['contact'], $openTrip['contact']);
            if ($openTrip['rating'] !== null) {
                $result['rating'] = $openTrip['rating'];
            }
            if ($openTrip['price'] !== '') {
                $result['price'] = $openTrip['price'];
            }
            if ($openTrip['source_url'] !== '') {
                $result['source_url'] = $openTrip['source_url'];
            }
            $result['sources']['opentripmap'] = $openTrip['source'];
        }

        $wikipedia = $this->fetchFromWikipedia($openTrip, $query);
        if ($wikipedia !== null) {
            $result['sources']['wikipedia'] = $wikipedia;
            if ($result['source_url'] === '' && isset($wikipedia['url']) && $wikipedia['url'] !== '') {
                $result['source_url'] = $wikipedia['url'];
            }
        }

        if ($result['coordinates'] !== null) {
            $yelp = $this->fetchFromYelp($result, $context);
            if ($yelp !== null) {
                if ($yelp['name'] !== '') {
                    $result['name'] = $yelp['name'];
                }
                if ($yelp['rating'] !== null) {
                    $result['rating'] = $yelp['rating'];
                }
                if ($yelp['price'] !== '') {
                    $result['price'] = $yelp['price'];
                }
                $result['contact'] = $this->mergeContact($result['contact'], $yelp['contact']);
                if ($yelp['url'] !== '') {
                    $result['source_url'] = $yelp['url'];
                }
                $result['sources']['yelp'] = $yelp['source'];
            }

            $weather = $this->fetchFromOpenWeather($result['coordinates']);
            if ($weather !== null) {
                $result['weather'] = $weather;
            }
        }

        $this->writeCache($cacheDir, $signature, $result);

        return $result;
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function normalizeContext(array $context): array
    {
        $normalized = [
            'address' => isset($context['address']) ? trim((string) $context['address']) : '',
            'city' => isset($context['city']) ? trim((string) $context['city']) : '',
            'region' => isset($context['region']) ? trim((string) $context['region']) : '',
            'country' => isset($context['country']) ? trim((string) $context['country']) : '',
            'latitude' => null,
            'longitude' => null,
        ];

        foreach (['latitude', 'longitude'] as $key) {
            if (isset($context[$key]) && is_numeric($context[$key])) {
                $value = (float) $context[$key];
                if (is_finite($value)) {
                    $normalized[$key] = $value;
                }
            }
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $contact
     * @param array<string, string> $update
     * @return array<string, string>
     */
    private function mergeContact(array $contact, array $update): array
    {
        foreach (['address', 'hours', 'phone', 'website'] as $key) {
            if (isset($update[$key]) && $update[$key] !== '') {
                $contact[$key] = $update[$key];
            }
        }

        return $contact;
    }

    /**
     * @param array<string, mixed> $context
     * @return array{lat: float, lon: float}|null
     */
    private function resolveCoordinates(string $query, array &$context): ?array
    {
        if (is_float($context['latitude']) && is_float($context['longitude'])) {
            return ['lat' => $context['latitude'], 'lon' => $context['longitude']];
        }

        $candidates = [];
        if ($context['address'] !== '') {
            $candidates[] = $context['address'];
        }
        $cityParts = array_filter([
            $context['city'],
            $context['region'],
            $context['country'],
        ], static fn ($part) => is_string($part) && trim($part) !== '');
        if ($cityParts) {
            $candidates[] = trim($query . ', ' . implode(', ', $cityParts));
            $candidates[] = implode(', ', $cityParts);
        }
        $candidates[] = $query;

        foreach ($candidates as $candidate) {
            $candidate = trim((string) $candidate);
            if ($candidate === '') {
                continue;
            }

            try {
                $geo = NominatimGeocoder::geocode($candidate);
            } catch (\Throwable $exception) {
                Logger::logThrowable($exception, [
                    'client_method' => __METHOD__,
                    'stage' => 'geocode_candidate',
                    'candidate' => $candidate,
                ]);
                continue;
            }

            if ($geo !== null) {
                $context['latitude'] = $geo['lat'];
                $context['longitude'] = $geo['lon'];
                if ($context['address'] === '' && isset($geo['display_name'])) {
                    $context['address'] = (string) $geo['display_name'];
                }
                return ['lat' => $geo['lat'], 'lon' => $geo['lon']];
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $context
     * @param array{lat: float, lon: float}|null $coordinates
     * @return array<string, mixed>|null
     */
    private function fetchFromOpenTripMap(string $query, array $context, ?array $coordinates): ?array
    {
        $apiKey = $this->readFromEnvironment('OPENTRIPMAP_API_KEY');
        if ($apiKey === '') {
            return null;
        }

        $params = [
            'name' => $query,
            'limit' => 5,
            'apikey' => $apiKey,
        ];

        if ($coordinates !== null) {
            $params['lat'] = $coordinates['lat'];
            $params['lon'] = $coordinates['lon'];
            $params['radius'] = 10000; // 10km radius
        }

        $url = 'https://api.opentripmap.com/0.1/en/places/autosuggest?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        $listing = $this->requestJson($url);
        if ($listing === null || !isset($listing['features']) || !is_array($listing['features'])) {
            return null;
        }

        $best = null;
        $bestScore = -INF;
        foreach ($listing['features'] as $feature) {
            if (!is_array($feature)) {
                continue;
            }
            $properties = $feature['properties'] ?? null;
            if (!is_array($properties)) {
                continue;
            }
            $name = isset($properties['name']) ? trim((string) $properties['name']) : '';
            if ($name === '') {
                continue;
            }
            $score = $this->nameSimilarity($query, $name);
            if (isset($properties['rate']) && is_numeric($properties['rate'])) {
                $score += (float) $properties['rate'] / 10.0;
            }
            if ($score > $bestScore) {
                $best = [
                    'feature' => $feature,
                    'properties' => $properties,
                ];
                $bestScore = $score;
            }
        }

        if ($best === null) {
            return null;
        }

        $properties = $best['properties'];
        $xid = isset($properties['xid']) ? (string) $properties['xid'] : '';
        $detail = null;
        if ($xid !== '') {
            $detailUrl = 'https://api.opentripmap.com/0.1/en/places/xid/' . rawurlencode($xid) . '?apikey=' . rawurlencode($apiKey);
            $detail = $this->requestJson($detailUrl);
        }

        return $this->formatOpenTripMapDetail($query, $best['feature'], $properties, $detail);
    }

    /**
     * @param array<string, mixed>|null $detail
     * @return array<string, mixed>|null
     */
    private function fetchFromWikipedia(?array $openTrip, string $fallbackQuery): ?array
    {
        $title = null;
        if ($openTrip !== null) {
            $source = $openTrip['source'] ?? [];
            if (isset($source['wikipedia']) && $source['wikipedia'] !== '') {
                $title = $this->extractWikipediaTitle($source['wikipedia']);
            }
            if ($title === null && isset($openTrip['name']) && $openTrip['name'] !== '') {
                $title = $openTrip['name'];
            }
        }

        if ($title === null) {
            $title = $fallbackQuery;
        }

        $summary = $this->requestWikipediaSummary($title);
        if ($summary === null && $title !== $fallbackQuery) {
            $summary = $this->requestWikipediaSummary($fallbackQuery);
        }

        return $summary;
    }

    /**
     * @param array<string, mixed> $baseResult
     * @param array<string, mixed> $context
     * @return array<string, mixed>|null
     */
    private function fetchFromYelp(array $baseResult, array $context): ?array
    {
        $apiKey = $this->readFromEnvironment('YELP_API_KEY');
        if ($apiKey === '') {
            return null;
        }

        $coords = $baseResult['coordinates'] ?? null;
        if (!is_array($coords) || !isset($coords['lat'], $coords['lon'])) {
            return null;
        }
        if (!is_numeric($coords['lat']) || !is_numeric($coords['lon'])) {
            return null;
        }

        $lat = (float) $coords['lat'];
        $lon = (float) $coords['lon'];

        $name = $baseResult['name'] !== '' ? $baseResult['name'] : $baseResult['query'];

        $params = [
            'term' => $name,
            'latitude' => $lat,
            'longitude' => $lon,
            'limit' => 5,
            'sort_by' => 'best_match',
        ];

        if (!empty($context['city'])) {
            $params['location'] = $context['city'];
        }

        $searchUrl = 'https://api.yelp.com/v3/businesses/search?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        $search = $this->requestJson($searchUrl, ['Authorization: Bearer ' . $apiKey]);
        if ($search === null || !isset($search['businesses']) || !is_array($search['businesses'])) {
            return null;
        }

        $best = $this->chooseBestYelpBusiness($search['businesses'], $name);
        if ($best === null || !isset($best['id'])) {
            return null;
        }

        $detailUrl = 'https://api.yelp.com/v3/businesses/' . rawurlencode((string) $best['id']);
        $detail = $this->requestJson($detailUrl, ['Authorization: Bearer ' . $apiKey]);
        if ($detail === null) {
            $detail = $best;
        }

        $contact = [
            'address' => $this->formatYelpAddress($detail),
            'hours' => $this->formatYelpHours($detail['hours'] ?? null),
            'phone' => $this->extractYelpPhone($detail),
            'website' => isset($detail['url']) ? (string) $detail['url'] : '',
        ];

        return [
            'name' => isset($detail['name']) ? trim((string) $detail['name']) : $name,
            'rating' => isset($detail['rating']) && is_numeric($detail['rating']) ? (float) $detail['rating'] : (isset($best['rating']) && is_numeric($best['rating']) ? (float) $best['rating'] : null),
            'price' => isset($detail['price']) ? trim((string) $detail['price']) : (isset($best['price']) ? trim((string) $best['price']) : ''),
            'url' => isset($detail['url']) ? trim((string) $detail['url']) : (isset($best['url']) ? trim((string) $best['url']) : ''),
            'contact' => $contact,
            'source' => [
                'id' => (string) ($detail['id'] ?? $best['id']),
                'url' => isset($detail['url']) ? trim((string) $detail['url']) : (isset($best['url']) ? trim((string) $best['url']) : ''),
                'review_count' => isset($detail['review_count']) ? (int) $detail['review_count'] : (isset($best['review_count']) ? (int) $best['review_count'] : 0),
                'rating' => isset($detail['rating']) && is_numeric($detail['rating']) ? (float) $detail['rating'] : (isset($best['rating']) && is_numeric($best['rating']) ? (float) $best['rating'] : null),
                'price' => isset($detail['price']) ? trim((string) $detail['price']) : (isset($best['price']) ? trim((string) $best['price']) : ''),
            ],
        ];
    }

    /**
     * @param array<string, mixed>|null $coordinates
     * @return array<string, mixed>|null
     */
    private function fetchFromOpenWeather(?array $coordinates): ?array
    {
        if ($coordinates === null || !isset($coordinates['lat'], $coordinates['lon'])) {
            return null;
        }

        $apiKey = $this->readFromEnvironment('OPENWEATHER_API_KEY');
        if ($apiKey === '') {
            return null;
        }

        $lat = (float) $coordinates['lat'];
        $lon = (float) $coordinates['lon'];
        if (!is_finite($lat) || !is_finite($lon)) {
            return null;
        }

        $params = [
            'lat' => $lat,
            'lon' => $lon,
            'units' => 'imperial',
            'appid' => $apiKey,
        ];

        $url = 'https://api.openweathermap.org/data/2.5/weather?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        $data = $this->requestJson($url);
        if ($data === null || !isset($data['weather'], $data['main']) || !is_array($data['weather']) || !isset($data['weather'][0])) {
            return null;
        }

        $conditions = isset($data['weather'][0]['description']) ? $this->formatSentence((string) $data['weather'][0]['description']) : '';
        $temperature = isset($data['main']['temp']) && is_numeric($data['main']['temp']) ? round((float) $data['main']['temp'], 1) : null;
        $feelsLike = isset($data['main']['feels_like']) && is_numeric($data['main']['feels_like']) ? round((float) $data['main']['feels_like'], 1) : null;
        $timestamp = isset($data['dt']) && is_numeric($data['dt']) ? (int) $data['dt'] : time();

        return [
            'temperature' => $temperature,
            'feels_like' => $feelsLike,
            'conditions' => $conditions,
            'updated_at' => gmdate(\DateTimeInterface::ATOM, $timestamp),
            'source_url' => isset($data['id']) && is_numeric($data['id'])
                ? 'https://openweathermap.org/city/' . $data['id']
                : 'https://openweathermap.org/',
        ];
    }

    /**
     * @param array<string, mixed> $feature
     * @param array<string, mixed> $properties
     * @param array<string, mixed>|null $detail
     * @return array<string, mixed>
     */
    private function formatOpenTripMapDetail(string $query, array $feature, array $properties, ?array $detail): array
    {
        $name = isset($detail['name']) && $detail['name'] !== ''
            ? trim((string) $detail['name'])
            : (isset($properties['name']) ? trim((string) $properties['name']) : $query);

        $point = $detail['point'] ?? ($feature['geometry']['coordinates'] ?? null);
        $coordinates = null;
        if (is_array($point)) {
            if (isset($point['lat'], $point['lon']) && is_numeric($point['lat']) && is_numeric($point['lon'])) {
                $coordinates = ['lat' => (float) $point['lat'], 'lon' => (float) $point['lon']];
            } elseif (isset($point[1], $point[0]) && is_numeric($point[1]) && is_numeric($point[0])) {
                $coordinates = ['lat' => (float) $point[1], 'lon' => (float) $point[0]];
            }
        }

        $category = isset($detail['kinds']) ? $this->formatKinds((string) $detail['kinds']) : (isset($properties['kinds']) ? $this->formatKinds((string) $properties['kinds']) : '');
        $address = $this->formatOpenTripMapAddress($detail['address'] ?? ($properties['address'] ?? null));
        $hours = $this->extractOpeningHours($detail['opening_hours'] ?? null);
        $contact = $this->extractOpenTripMapContact($detail['contacts'] ?? ($detail['contact'] ?? null));
        if ($address !== '') {
            $contact['address'] = $address;
        }
        if ($hours !== '') {
            $contact['hours'] = $hours;
        }

        $rating = isset($detail['rate']) && is_numeric($detail['rate']) ? (float) $detail['rate'] : null;
        $price = $this->extractOpenTripMapPrice($detail);

        $sourceUrl = '';
        if (isset($detail['otm']) && is_string($detail['otm'])) {
            $sourceUrl = trim($detail['otm']);
        } elseif (isset($detail['url']) && is_string($detail['url'])) {
            $sourceUrl = trim($detail['url']);
        }

        $source = [
            'xid' => isset($detail['xid']) ? (string) $detail['xid'] : (isset($properties['xid']) ? (string) $properties['xid'] : ''),
            'url' => $sourceUrl,
            'kinds' => isset($detail['kinds']) ? (string) $detail['kinds'] : (isset($properties['kinds']) ? (string) $properties['kinds'] : ''),
            'wikipedia' => isset($detail['wikipedia']) ? (string) $detail['wikipedia'] : (isset($properties['wikipedia']) ? (string) $properties['wikipedia'] : ''),
            'wikidata' => isset($detail['wikidata']) ? (string) $detail['wikidata'] : (isset($properties['wikidata']) ? (string) $properties['wikidata'] : ''),
        ];

        return [
            'name' => $name,
            'category' => $category,
            'coordinates' => $coordinates,
            'contact' => $contact,
            'rating' => $rating,
            'price' => $price,
            'source_url' => $sourceUrl,
            'source' => $source,
        ];
    }

    /**
     * @param array<string, mixed> $businesses
     * @return array<string, mixed>|null
     */
    private function chooseBestYelpBusiness(array $businesses, string $query): ?array
    {
        $best = null;
        $bestScore = -INF;
        foreach ($businesses as $business) {
            if (!is_array($business) || !isset($business['name'])) {
                continue;
            }
            $name = trim((string) $business['name']);
            if ($name === '') {
                continue;
            }
            $score = $this->nameSimilarity($query, $name);
            if (isset($business['rating']) && is_numeric($business['rating'])) {
                $score += (float) $business['rating'] / 10.0;
            }
            if ($score > $bestScore) {
                $best = $business;
                $bestScore = $score;
            }
        }

        return $best;
    }

    private function extractYelpPhone(array $detail): string
    {
        if (isset($detail['display_phone']) && is_string($detail['display_phone'])) {
            return trim($detail['display_phone']);
        }
        if (isset($detail['phone']) && is_string($detail['phone'])) {
            return trim($detail['phone']);
        }
        return '';
    }

    /**
     * @param array<string, mixed>|null $hours
     */
    private function formatYelpHours($hours): string
    {
        if (!is_array($hours) || !isset($hours[0]) || !is_array($hours[0])) {
            return '';
        }

        $open = $hours[0]['open'] ?? null;
        if (!is_array($open)) {
            return '';
        }

        $days = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
        $segments = [];
        foreach ($open as $entry) {
            if (!is_array($entry) || !isset($entry['day'], $entry['start'], $entry['end'])) {
                continue;
            }
            $dayIndex = (int) $entry['day'];
            if (!isset($days[$dayIndex])) {
                continue;
            }
            $start = $this->formatYelpTime($entry['start']);
            $end = $this->formatYelpTime($entry['end']);
            if ($start === '' || $end === '') {
                continue;
            }
            $segments[] = sprintf('%s %s–%s', $days[$dayIndex], $start, $end);
        }

        return $segments ? implode('; ', $segments) : '';
    }

    private function formatYelpTime($value): string
    {
        $digits = is_string($value) ? preg_replace('/[^0-9]/', '', $value) : '';
        if ($digits === null || strlen($digits) !== 4) {
            return '';
        }

        $hours = substr($digits, 0, 2);
        $minutes = substr($digits, 2, 2);
        return $hours . ':' . $minutes;
    }

    /**
     * @param array<string, mixed>|null $detail
     */
    private function formatYelpAddress($detail): string
    {
        if (!is_array($detail)) {
            return '';
        }

        $location = $detail['location'] ?? null;
        if (!is_array($location)) {
            return '';
        }

        $display = $location['display_address'] ?? null;
        if (is_array($display)) {
            $parts = array_filter(array_map(static function ($part) {
                return is_string($part) ? trim($part) : '';
            }, $display));
            return $parts ? implode(', ', $parts) : '';
        }

        $segments = [];
        foreach (['address1', 'address2', 'address3', 'city', 'state', 'zip_code', 'country'] as $key) {
            if (isset($location[$key]) && is_string($location[$key])) {
                $value = trim($location[$key]);
                if ($value !== '') {
                    $segments[] = $value;
                }
            }
        }

        return $segments ? implode(', ', $segments) : '';
    }

    private function extractWikipediaTitle(string $slug): ?string
    {
        $slug = trim($slug);
        if ($slug === '') {
            return null;
        }
        $parts = explode(':', $slug, 2);
        return isset($parts[1]) ? $parts[1] : $parts[0];
    }

    private function requestWikipediaSummary(string $title): ?array
    {
        $title = trim($title);
        if ($title === '') {
            return null;
        }
        $url = 'https://en.wikipedia.org/api/rest_v1/page/summary/' . rawurlencode(str_replace(' ', '_', $title));
        $data = $this->requestJson($url, ['Accept: application/json; charset=utf-8']);
        if ($data === null || isset($data['type']) && $data['type'] === 'https://mediawiki.org/wiki/HyperSwitch/errors/not_found') {
            return null;
        }

        return [
            'title' => isset($data['title']) ? (string) $data['title'] : $title,
            'description' => isset($data['description']) ? (string) $data['description'] : '',
            'extract' => isset($data['extract']) ? (string) $data['extract'] : '',
            'url' => $this->extractWikipediaUrl($data),
            'last_modified' => isset($data['timestamp']) ? (string) $data['timestamp'] : '',
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function extractWikipediaUrl(array $data): string
    {
        if (isset($data['content_urls']['desktop']['page']) && is_string($data['content_urls']['desktop']['page'])) {
            return $data['content_urls']['desktop']['page'];
        }
        if (isset($data['content_urls']['mobile']['page']) && is_string($data['content_urls']['mobile']['page'])) {
            return $data['content_urls']['mobile']['page'];
        }
        if (isset($data['titles']['canonical']) && is_string($data['titles']['canonical'])) {
            return 'https://en.wikipedia.org/wiki/' . rawurlencode($data['titles']['canonical']);
        }
        return '';
    }

    private function extractOpeningHours($value): string
    {
        if (is_string($value)) {
            return trim($value);
        }

        if (is_array($value) && isset($value['text']) && is_string($value['text'])) {
            return trim($value['text']);
        }

        return '';
    }

    private function extractOpenTripMapContact($value): array
    {
        $contact = [
            'address' => '',
            'hours' => '',
            'phone' => '',
            'website' => '',
        ];

        if (!is_array($value)) {
            return $contact;
        }

        foreach (['phone', 'email', 'contact:phone'] as $key) {
            if (isset($value[$key]) && is_string($value[$key])) {
                $contact['phone'] = trim($value[$key]);
                break;
            }
        }

        foreach (['website', 'url', 'contact:website'] as $key) {
            if (isset($value[$key]) && is_string($value[$key])) {
                $contact['website'] = trim($value[$key]);
                break;
            }
        }

        return $contact;
    }

    private function extractOpenTripMapPrice(array $detail): string
    {
        if (isset($detail['price']) && is_string($detail['price'])) {
            return trim($detail['price']);
        }
        if (isset($detail['info']['price']) && is_string($detail['info']['price'])) {
            return trim($detail['info']['price']);
        }
        if (isset($detail['extratags']['fee']) && is_string($detail['extratags']['fee'])) {
            return trim($detail['extratags']['fee']);
        }
        return '';
    }

    private function formatOpenTripMapAddress($value): string
    {
        if (!is_array($value)) {
            return '';
        }

        $parts = [];
        foreach (['house_number', 'road', 'neighbourhood', 'suburb', 'city', 'state', 'postcode', 'country'] as $key) {
            if (isset($value[$key]) && is_string($value[$key])) {
                $piece = trim($value[$key]);
                if ($piece !== '') {
                    $parts[] = $piece;
                }
            }
        }

        return $parts ? implode(', ', $parts) : '';
    }

    private function formatKinds(string $kinds): string
    {
        $parts = array_filter(array_map(function (string $kind): string {
            $kind = trim($kind);
            if ($kind === '') {
                return '';
            }
            $kind = str_replace('_', ' ', $kind);
            return $this->formatSentence($kind);
        }, explode(',', $kinds)));

        return $parts ? implode(' • ', $parts) : '';
    }

    private function nameSimilarity(string $a, string $b): float
    {
        $a = strtolower(preg_replace('/[^a-z0-9]+/i', '', $a) ?? '');
        $b = strtolower(preg_replace('/[^a-z0-9]+/i', '', $b) ?? '');
        if ($a === '' || $b === '') {
            return 0.0;
        }
        if ($a === $b) {
            return 1.0;
        }
        similar_text($a, $b, $percent);
        return $percent / 100.0;
    }

    private function formatSentence(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        $value = strtolower($value);
        return ucfirst($value);
    }

    private function now(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM);
    }

    private function userAgent(): string
    {
        return 'RedClayRoadTrip/1.2 (+https://redclayroadtrip.s-sites.com)';
    }

    /**
     * @param array<string, mixed> $context
     */
    private function cacheSignature(string $query, array $context): string
    {
        try {
            return hash('sha256', json_encode([
                'q' => strtolower($query),
                'context' => $context,
            ], JSON_THROW_ON_ERROR));
        } catch (\JsonException $exception) {
            Logger::logThrowable($exception, [
                'client_method' => __METHOD__,
                'query' => $query,
            ]);
            return hash('sha256', strtolower($query));
        }
    }

    private function cacheDirectory(): string
    {
        $root = dirname(__DIR__, 2);
        $dir = $root . '/data/cache/live';
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('Unable to initialize live data cache directory.');
        }
        return $dir;
    }

    private function readCache(string $cacheDir, string $signature): ?array
    {
        $path = $cacheDir . '/' . $signature . '.json';
        if (!is_file($path)) {
            return null;
        }

        $expires = filemtime($path);
        if ($expires === false || ($expires + self::CACHE_TTL_SECONDS) < time()) {
            @unlink($path);
            return null;
        }

        $contents = @file_get_contents($path);
        if ($contents === false) {
            return null;
        }

        try {
            $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable $exception) {
            Logger::logThrowable($exception, [
                'client_method' => __METHOD__,
                'stage' => 'decode_cache',
            ]);
            @unlink($path);
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function writeCache(string $cacheDir, string $signature, array $payload): void
    {
        $path = $cacheDir . '/' . $signature . '.json';
        try {
            $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (\Throwable $exception) {
            Logger::logThrowable($exception, [
                'client_method' => __METHOD__,
                'stage' => 'encode_cache',
            ]);
            return;
        }

        @file_put_contents($path, $json, LOCK_EX);
        $this->pruneCache($cacheDir);
    }

    private function pruneCache(string $cacheDir): void
    {
        $files = glob($cacheDir . '/*.json');
        if (!is_array($files) || count($files) <= self::CACHE_MAX_ENTRIES) {
            return;
        }

        usort($files, static function (string $a, string $b): int {
            $ma = filemtime($a) ?: 0;
            $mb = filemtime($b) ?: 0;
            return $ma <=> $mb;
        });

        $excess = array_slice($files, 0, max(0, count($files) - self::CACHE_MAX_ENTRIES));
        foreach ($excess as $file) {
            @unlink($file);
        }
    }

    /**
     * @param array<int, string>|null $headers
     */
    private function requestJson(string $url, ?array $headers = null): ?array
    {
        $handle = curl_init($url);
        if ($handle === false) {
            Logger::log('LiveDataClient: Failed to initialize curl handle', [
                'url' => $url,
            ]);
            return null;
        }

        $httpHeaders = [
            'Accept: application/json',
            'Accept-Language: en',
            'User-Agent: ' . $this->userAgent(),
        ];
        if ($headers) {
            $httpHeaders = array_merge($httpHeaders, $headers);
        }

        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::HTTP_TIMEOUT_SECONDS,
            CURLOPT_CONNECTTIMEOUT => self::HTTP_TIMEOUT_SECONDS,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTPHEADER => $httpHeaders,
        ]);

        $body = curl_exec($handle);
        if ($body === false) {
            $error = curl_error($handle);
            curl_close($handle);
            Logger::log('LiveDataClient: HTTP request failed', [
                'url' => $url,
                'error' => $error,
            ]);
            return null;
        }

        $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        curl_close($handle);

        if ($status < 200 || $status >= 300) {
            Logger::log('LiveDataClient: Non-success response', [
                'url' => $url,
                'status' => $status,
            ]);
            return null;
        }

        $decoded = json_decode($body, true);
        return is_array($decoded) ? $decoded : null;
    }

    private function readFromEnvironment(string $key): string
    {
        if (isset($_ENV[$key]) && $_ENV[$key] !== '') {
            return (string) $_ENV[$key];
        }
        if (isset($_SERVER[$key]) && $_SERVER[$key] !== '') {
            return (string) $_SERVER[$key];
        }
        $value = getenv($key);
        return $value === false ? '' : (string) $value;
    }
}
