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

        $tripAdvisor = $this->fetchFromTripAdvisor($query, $context, $result['coordinates']);
        if ($tripAdvisor !== null) {
            $result['matched'] = true;
            if ($tripAdvisor['name'] !== '') {
                $result['name'] = $tripAdvisor['name'];
            }
            if ($tripAdvisor['category'] !== '') {
                $result['category'] = $tripAdvisor['category'];
            }
            if ($tripAdvisor['coordinates'] !== null) {
                $result['coordinates'] = $tripAdvisor['coordinates'];
            }
            $result['contact'] = $this->mergeContact($result['contact'], $tripAdvisor['contact']);
            if ($tripAdvisor['rating'] !== null) {
                $result['rating'] = $tripAdvisor['rating'];
            }
            if ($tripAdvisor['price'] !== '') {
                $result['price'] = $tripAdvisor['price'];
            }
            if ($result['source_url'] === '' && $tripAdvisor['source_url'] !== '') {
                $result['source_url'] = $tripAdvisor['source_url'];
            }
            $result['sources']['tripadvisor'] = $tripAdvisor['source'];
        }

        $wikipedia = $this->fetchFromWikipedia($openTrip ?? $tripAdvisor, $query);
        if ($wikipedia !== null) {
            $result['sources']['wikipedia'] = $wikipedia;
            if ($result['source_url'] === '' && isset($wikipedia['url']) && $wikipedia['url'] !== '') {
                $result['source_url'] = $wikipedia['url'];
            }
        }

        if ($result['coordinates'] !== null) {
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
     * @param array<string, mixed> $context
     * @param array{lat: float, lon: float}|null $coordinates
     * @return array<string, mixed>|null
     */
    private function fetchFromTripAdvisor(string $query, array $context, ?array $coordinates): ?array
    {
        $apiKey = $this->readFromEnvironment('TRIPADVISOR_API_KEY');
        if ($apiKey === '') {
            return null;
        }

        $searchQuery = $this->buildTripAdvisorSearchQuery($query, $context);
        $params = [
            'key' => $apiKey,
            'searchQuery' => $searchQuery,
            'language' => 'en',
        ];

        if ($coordinates !== null) {
            $params['latLong'] = sprintf('%.6f,%.6f', $coordinates['lat'], $coordinates['lon']);
        }

        $url = 'https://api.content.tripadvisor.com/api/v1/location/search?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        $listing = $this->requestJson($url);
        if ($listing === null || !isset($listing['data']) || !is_array($listing['data'])) {
            return null;
        }

        $best = null;
        $bestScore = -INF;
        foreach ($listing['data'] as $candidate) {
            if (!is_array($candidate)) {
                continue;
            }

            $name = isset($candidate['name']) ? trim((string) $candidate['name']) : '';
            if ($name === '') {
                continue;
            }

            $score = $this->nameSimilarity($query, $name);
            if ($coordinates !== null && isset($candidate['latitude'], $candidate['longitude']) && is_numeric($candidate['latitude']) && is_numeric($candidate['longitude'])) {
                $distance = $this->distanceInKilometers($coordinates['lat'], $coordinates['lon'], (float) $candidate['latitude'], (float) $candidate['longitude']);
                if (is_finite($distance)) {
                    $score += max(0.0, 1.0 - min($distance, 50.0) / 50.0);
                }
            }

            if ($score > $bestScore) {
                $best = $candidate;
                $bestScore = $score;
            }
        }

        if ($best === null) {
            return null;
        }

        $detail = null;
        $locationId = isset($best['location_id']) ? trim((string) $best['location_id']) : '';
        if ($locationId !== '') {
            $detailParams = [
                'key' => $apiKey,
                'language' => 'en',
            ];
            $detailUrl = 'https://api.content.tripadvisor.com/api/v1/location/' . rawurlencode($locationId) . '/details?' . http_build_query($detailParams, '', '&', PHP_QUERY_RFC3986);
            $detail = $this->requestJson($detailUrl);
        }

        return $this->formatTripAdvisorDetail($best, $detail);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function buildTripAdvisorSearchQuery(string $query, array $context): string
    {
        $parts = [];
        $query = trim($query);
        if ($query !== '') {
            $parts[] = $query;
        }

        foreach (['city', 'region', 'country'] as $key) {
            if (isset($context[$key]) && is_string($context[$key])) {
                $value = trim($context[$key]);
                if ($value !== '') {
                    $parts[] = $value;
                }
            }
        }

        if (!$parts) {
            return $query;
        }

        $parts = array_values(array_unique($parts));
        return implode(' ', $parts);
    }

    /**
     * @param array<string, mixed> $listing
     * @param array<string, mixed>|null $detail
     * @return array<string, mixed>
     */
    private function formatTripAdvisorDetail(array $listing, ?array $detail): array
    {
        $detailData = is_array($detail) ? $detail : [];
        $name = $this->coalesceString([
            $detailData['name'] ?? null,
            $listing['name'] ?? null,
        ]);

        $coordinates = $this->extractTripAdvisorCoordinates($listing, $detailData);
        $contact = $this->extractTripAdvisorContact($listing, $detailData);

        $rating = null;
        if (isset($detailData['rating']) && is_numeric($detailData['rating'])) {
            $rating = (float) $detailData['rating'];
        } elseif (isset($listing['rating']) && is_numeric($listing['rating'])) {
            $rating = (float) $listing['rating'];
        }

        $price = $this->extractTripAdvisorPrice($listing, $detailData);
        $sourceUrl = $this->coalesceString([
            $detailData['web_url'] ?? null,
            $listing['web_url'] ?? null,
        ]);

        $bubbleImageUrl = $this->coalesceString([
            $detailData['rating_image_url'] ?? null,
            $detailData['rating_image_url_large'] ?? null,
            $listing['rating_image_url'] ?? null,
            $listing['rating_image_url_large'] ?? null,
        ]);

        $bubbleImageSmallUrl = $this->coalesceString([
            $detailData['rating_image_url_small'] ?? null,
            $listing['rating_image_url_small'] ?? null,
        ]);

        $source = $this->filterTripAdvisorSource([
            'id' => $this->coalesceString([
                $detailData['location_id'] ?? null,
                $listing['location_id'] ?? null,
            ]),
            'url' => $sourceUrl,
            'name' => $name !== '' ? $name : ($listing['name'] ?? ''),
            'rating' => $rating,
            'review_count' => isset($detailData['num_reviews']) && is_numeric($detailData['num_reviews'])
                ? (int) $detailData['num_reviews']
                : null,
            'ranking' => isset($detailData['ranking']) && is_string($detailData['ranking'])
                ? trim($detailData['ranking'])
                : '',
            'rating_image_url' => $bubbleImageUrl,
            'rating_image_url_small' => $bubbleImageSmallUrl,
        ]);

        return [
            'name' => $name,
            'category' => $this->extractTripAdvisorCategory($listing, $detailData),
            'coordinates' => $coordinates,
            'contact' => $contact,
            'rating' => $rating,
            'price' => $price,
            'source_url' => $sourceUrl,
            'source' => $source,
        ];
    }

    /**
     * @param array<string, mixed> $listing
     * @param array<string, mixed> $detail
     * @return array{lat: float, lon: float}|null
     */
    private function extractTripAdvisorCoordinates(array $listing, array $detail): ?array
    {
        $latitude = null;
        $longitude = null;

        foreach ([
            $detail['latitude'] ?? null,
            $listing['latitude'] ?? null,
        ] as $candidateLatitude) {
            if (is_numeric($candidateLatitude)) {
                $latitude = (float) $candidateLatitude;
                break;
            }
        }

        foreach ([
            $detail['longitude'] ?? null,
            $listing['longitude'] ?? null,
        ] as $candidateLongitude) {
            if (is_numeric($candidateLongitude)) {
                $longitude = (float) $candidateLongitude;
                break;
            }
        }

        if ($latitude !== null && $longitude !== null) {
            return ['lat' => $latitude, 'lon' => $longitude];
        }

        return null;
    }

    /**
     * @param array<string, mixed> $listing
     * @param array<string, mixed> $detail
     * @return array<string, string>
     */
    private function extractTripAdvisorContact(array $listing, array $detail): array
    {
        $address = $this->formatTripAdvisorAddress($detail['address_obj'] ?? null);
        if ($address === '') {
            $address = $this->formatTripAdvisorAddress($listing['address_obj'] ?? null);
        }
        if ($address === '' && isset($listing['address']) && is_string($listing['address'])) {
            $address = trim($listing['address']);
        }

        $hours = $this->extractTripAdvisorHours($detail['hours'] ?? ($listing['hours'] ?? null));

        return [
            'address' => $address,
            'hours' => $hours,
            'phone' => $this->coalesceString([
                $detail['phone'] ?? null,
                $detail['phone_number'] ?? null,
                $listing['phone'] ?? null,
                $listing['phone_number'] ?? null,
            ]),
            'website' => $this->coalesceString([
                $detail['website'] ?? null,
                $detail['website_url'] ?? null,
                $listing['website'] ?? null,
            ]),
        ];
    }

    private function extractTripAdvisorHours($hours): string
    {
        if (!is_array($hours)) {
            return '';
        }

        if (isset($hours['weekday_text']) && is_array($hours['weekday_text'])) {
            $lines = array_filter(array_map(static function ($line): string {
                return is_string($line) ? trim($line) : '';
            }, $hours['weekday_text']));
            if ($lines) {
                return implode(' • ', $lines);
            }
        }

        foreach (['display_text', 'text'] as $key) {
            if (isset($hours[$key]) && is_string($hours[$key])) {
                $value = trim($hours[$key]);
                if ($value !== '') {
                    return $value;
                }
            }
        }

        if (isset($hours['week_ranges']) && is_array($hours['week_ranges'])) {
            $daysOfWeek = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
            $segments = [];
            foreach ($hours['week_ranges'] as $index => $ranges) {
                if (!is_array($ranges)) {
                    continue;
                }
                $formattedRanges = [];
                foreach ($ranges as $range) {
                    if (!is_array($range)) {
                        continue;
                    }
                    $open = isset($range['open_time']) ? trim((string) $range['open_time']) : '';
                    $close = isset($range['close_time']) ? trim((string) $range['close_time']) : '';
                    if ($open !== '' && $close !== '') {
                        $formattedRanges[] = $open . '–' . $close;
                    }
                }
                if ($formattedRanges) {
                    $dayName = is_string($index)
                        ? $this->formatSentence(str_replace('_', ' ', $index))
                        : ($daysOfWeek[(int) $index] ?? (string) $index);
                    $segments[] = $dayName . ': ' . implode(', ', $formattedRanges);
                }
            }
            if ($segments) {
                return implode(' • ', $segments);
            }
        }

        return '';
    }

    private function formatTripAdvisorAddress($value): string
    {
        if (!is_array($value)) {
            return '';
        }

        if (isset($value['address_string']) && is_string($value['address_string'])) {
            $address = trim($value['address_string']);
            if ($address !== '') {
                return $address;
            }
        }

        $parts = [];
        foreach (['street1', 'street2', 'city', 'state', 'postalcode', 'country'] as $key) {
            if (isset($value[$key]) && is_string($value[$key])) {
                $piece = trim($value[$key]);
                if ($piece !== '') {
                    $parts[] = $piece;
                }
            }
        }

        return $parts ? implode(', ', $parts) : '';
    }

    /**
     * @param array<string, mixed> $listing
     * @param array<string, mixed> $detail
     */
    private function extractTripAdvisorCategory(array $listing, array $detail): string
    {
        $categories = [];

        if (isset($detail['subcategory']) && is_array($detail['subcategory'])) {
            foreach ($detail['subcategory'] as $entry) {
                if (is_array($entry) && isset($entry['name']) && is_string($entry['name'])) {
                    $label = trim($entry['name']);
                    if ($label !== '') {
                        $categories[] = $this->formatSentence($label);
                    }
                }
            }
        }

        if (!$categories && isset($detail['category']['name']) && is_string($detail['category']['name'])) {
            $label = trim($detail['category']['name']);
            if ($label !== '') {
                $categories[] = $this->formatSentence($label);
            }
        }

        if (!$categories && isset($listing['category']) && is_string($listing['category'])) {
            $label = trim($listing['category']);
            if ($label !== '') {
                $categories[] = $this->formatSentence($label);
            }
        }

        if (!$categories && isset($listing['location_type']) && is_string($listing['location_type'])) {
            $label = trim(str_replace('_', ' ', $listing['location_type']));
            if ($label !== '') {
                $categories[] = $this->formatSentence($label);
            }
        }

        $categories = array_values(array_unique(array_filter($categories, static fn ($value) => $value !== '')));
        return $categories ? implode(' • ', $categories) : '';
    }

    /**
     * @param array<string, mixed> $listing
     * @param array<string, mixed> $detail
     */
    private function extractTripAdvisorPrice(array $listing, array $detail): string
    {
        foreach ([
            $detail['price_level'] ?? null,
            $detail['price'] ?? null,
            $listing['price_level'] ?? null,
            $listing['price'] ?? null,
        ] as $value) {
            if (is_string($value)) {
                $trimmed = trim($value);
                if ($trimmed !== '') {
                    return $trimmed;
                }
            }
        }

        return '';
    }

    /**
     * @param array<string, mixed> $source
     * @return array<string, mixed>
     */
    private function filterTripAdvisorSource(array $source): array
    {
        return array_filter($source, static function ($value) {
            if ($value === null) {
                return false;
            }
            if (is_string($value)) {
                return trim($value) !== '';
            }
            return true;
        });
    }

    /**
     * @param array<int, mixed> $values
     */
    private function coalesceString(array $values): string
    {
        foreach ($values as $value) {
            if (is_string($value)) {
                $trimmed = trim($value);
                if ($trimmed !== '') {
                    return $trimmed;
                }
            } elseif (is_numeric($value)) {
                $stringValue = trim((string) $value);
                if ($stringValue !== '') {
                    return $stringValue;
                }
            }
        }

        return '';
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
     * @param array{lat: float, lon: float} $coordinates
     * @return array<string, mixed>|null
     */
    private function fetchFromOpenWeather(array $coordinates): ?array
    {
        $apiKey = $this->readFromEnvironment('OPENWEATHER_API_KEY');
        if ($apiKey === '') {
            return null;
        }

        $params = [
            'lat' => $coordinates['lat'],
            'lon' => $coordinates['lon'],
            'appid' => $apiKey,
            'units' => 'imperial',
        ];

        $url = 'https://api.openweathermap.org/data/2.5/weather?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        $data = $this->requestJson($url);
        if ($data === null) {
            return null;
        }

        $main = isset($data['main']) && is_array($data['main']) ? $data['main'] : [];
        $temperature = isset($main['temp']) && is_numeric($main['temp']) ? (float) $main['temp'] : null;
        $feelsLike = isset($main['feels_like']) && is_numeric($main['feels_like']) ? (float) $main['feels_like'] : null;

        $conditions = '';
        if (isset($data['weather']) && is_array($data['weather'])) {
            foreach ($data['weather'] as $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                if (isset($entry['description']) && is_string($entry['description'])) {
                    $description = $this->formatSentence($entry['description']);
                    if ($description !== '') {
                        $conditions = $description;
                        break;
                    }
                }
            }
        }

        $updatedAt = '';
        if (isset($data['dt']) && is_numeric($data['dt'])) {
            try {
                $timestamp = (int) $data['dt'];
                $date = (new \DateTimeImmutable('@' . $timestamp))->setTimezone(new \DateTimeZone('UTC'));
                $updatedAt = $date->format(\DateTimeInterface::ATOM);
            } catch (\Throwable $exception) {
                Logger::logThrowable($exception, [
                    'client_method' => __METHOD__,
                    'stage' => 'parse_timestamp',
                ]);
            }
        }
        if ($updatedAt === '') {
            $updatedAt = $this->now();
        }

        $sourceUrl = '';
        if (isset($data['id']) && is_numeric($data['id'])) {
            $sourceUrl = 'https://openweathermap.org/city/' . (int) $data['id'];
        } else {
            $sourceUrl = 'https://openweathermap.org/';
        }

        return [
            'temperature' => $temperature,
            'feels_like' => $feelsLike,
            'conditions' => $conditions,
            'updated_at' => $updatedAt,
            'source_url' => $sourceUrl,
        ];
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

        $summary = $this->fetchWikipediaSummaryByTitle($title);
        if ($summary !== null) {
            return $summary;
        }

        $alternate = $this->searchWikipediaTitle($title);
        if ($alternate !== null && strcasecmp($alternate, $title) !== 0) {
            $summary = $this->fetchWikipediaSummaryByTitle($alternate);
            if ($summary !== null) {
                return $summary;
            }
        }

        return null;
    }

    private function fetchWikipediaSummaryByTitle(string $title): ?array
    {
        $slug = rawurlencode(str_replace(' ', '_', $title));
        $url = 'https://en.wikipedia.org/api/rest_v1/page/summary/' . $slug;
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

    private function searchWikipediaTitle(string $query): ?string
    {
        $query = trim($query);
        if ($query === '') {
            return null;
        }

        $params = [
            'action' => 'query',
            'list' => 'search',
            'srsearch' => $query,
            'srlimit' => 1,
            'format' => 'json',
            'utf8' => 1,
        ];

        $url = 'https://en.wikipedia.org/w/api.php?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        $data = $this->requestJson($url, ['Accept: application/json; charset=utf-8']);
        if ($data === null || !isset($data['query']) || !is_array($data['query'])) {
            return null;
        }

        $search = $data['query']['search'] ?? null;
        if (!is_array($search)) {
            return null;
        }

        foreach ($search as $result) {
            if (!is_array($result)) {
                continue;
            }
            if (isset($result['title']) && is_string($result['title'])) {
                $title = trim($result['title']);
                if ($title !== '') {
                    return $title;
                }
            }
        }

        return null;
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

    private function distanceInKilometers(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earthRadius = 6371.0;
        $lat1 = deg2rad($lat1);
        $lon1 = deg2rad($lon1);
        $lat2 = deg2rad($lat2);
        $lon2 = deg2rad($lon2);

        $deltaLat = $lat2 - $lat1;
        $deltaLon = $lon2 - $lon1;

        $a = sin($deltaLat / 2) ** 2 + cos($lat1) * cos($lat2) * sin($deltaLon / 2) ** 2;
        $c = 2 * atan2(sqrt($a), sqrt(max(0.0, 1 - $a)));

        return $earthRadius * $c;
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
