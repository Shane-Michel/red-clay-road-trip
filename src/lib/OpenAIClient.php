<?php

declare(strict_types=1);

require_once __DIR__ . '/Logger.php';
require_once __DIR__ . '/LiveDataClient.php';
require_once __DIR__ . '/NominatimGeocoder.php';

final class OpenAIClient
{
    private const MAX_ATTEMPTS = 3;
    private const CACHE_TTL_SECONDS = 900; // 15 minutes
    private const CACHE_MAX_ENTRIES = 32;
    private const RATE_LIMIT_INTERVAL = 60; // seconds
    private const RATE_LIMIT_MAX_REQUESTS = 12; // sliding window per minute

    /** @var string */
    private $apiKey;
    /** @var string */
    private $model;

    public function __construct(?string $apiKey = null, string $model = 'gpt-4.1-nano')
    {
        // Prefer explicit API key; otherwise read from environment
        if ($apiKey === null) {
            $apiKey = $this->readFromEnvironment('OPENAI_API_KEY');
        }
        if ($apiKey === '') {
            throw new \RuntimeException('OPENAI_API_KEY is not set.');
        }

        $this->apiKey = $apiKey;

        // Allow model override via env
        $configuredModel = $this->readFromEnvironment('OPENAI_MODEL');
        if ($configuredModel !== '') {
            $model = $configuredModel;
        }
        $this->model = $model;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function generateItinerary(array $payload): array
    {
        $intent = $this->normalizeIntent($payload);
        $plan = $this->expandPreferencePlan($intent);
        $candidates = $this->acquireFactualData($intent, $plan);
        $resolved = $this->resolveAndFilterEntities($candidates);
        $selection = $this->scoreAndSelectEntities($intent, $plan, $resolved);

        if (empty($selection['selected'])) {
            return $this->normalizeItinerary($this->fallbackItinerary($intent));
        }

        $rawItinerary = $this->synthesizeFromGroundedData($intent, $plan, $selection);
        $validated = $this->attachGroundTruth($rawItinerary, $intent, $selection);

        return $this->normalizeItinerary($validated);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function normalizeIntent(array $payload): array
    {
        $start = trim((string)($payload['start_location'] ?? ''));
        $departureRaw = trim((string)($payload['departure_datetime'] ?? ''));
        $preferences = trim((string)($payload['traveler_preferences'] ?? ''));

        $citiesInput = $payload['cities_of_interest'] ?? [];
        if (!is_array($citiesInput)) {
            $citiesInput = [$citiesInput];
        }

        $cities = [];
        foreach ($citiesInput as $value) {
            $city = trim((string) $value);
            if ($city !== '') {
                $cities[] = $city;
            }
        }

        if (!$cities && $start !== '') {
            $cities[] = $start;
        }

        $startGeo = $this->geocodeSafely($start);
        $departure = $this->parseDeparture($departureRaw);
        $departureIso = $departure->format(\DateTimeInterface::ATOM);

        $legs = [];
        $cursor = $departure;
        foreach ($cities as $index => $city) {
            $cityGeo = $this->geocodeSafely($city);
            $arrival = $cursor;
            $departureEstimate = $arrival->add(new \DateInterval('PT6H'));
            $legs[] = [
                'index' => $index,
                'city' => $city,
                'arrival' => $arrival->format(\DateTimeInterface::ATOM),
                'departure' => $departureEstimate->format(\DateTimeInterface::ATOM),
                'coordinates' => $cityGeo,
            ];

            // Advance to next day to space legs apart.
            $cursor = $arrival->add(new \DateInterval('P1D'));
        }

        $tripEnd = $cursor->format(\DateTimeInterface::ATOM);

        return [
            'start_location' => $start,
            'start_coordinates' => $startGeo,
            'departure_input' => $departureRaw,
            'departure_iso' => $departureIso,
            'cities_of_interest' => $cities,
            'traveler_preferences' => $preferences,
            'legs' => $legs,
            'trip_window' => [
                'start' => $departureIso,
                'end' => $tripEnd,
            ],
        ];
    }

    private function parseDeparture(string $value): \DateTimeImmutable
    {
        if ($value !== '') {
            try {
                return new \DateTimeImmutable($value);
            } catch (\Throwable $exception) {
                Logger::logThrowable($exception, [
                    'client_method' => __METHOD__,
                    'stage' => 'parse_departure',
                    'value' => $value,
                ]);
            }
        }

        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    /**
     * @return array{lat: float, lon: float, display_name: string}|null
     */
    private function geocodeSafely(string $query): ?array
    {
        $query = trim($query);
        if ($query === '') {
            return null;
        }

        try {
            return NominatimGeocoder::geocode($query);
        } catch (\Throwable $exception) {
            Logger::logThrowable($exception, [
                'client_method' => __METHOD__,
                'stage' => 'geocode',
                'query' => $query,
            ]);
            return null;
        }
    }

    /**
     * @param array<string, mixed> $intent
     * @return array<string, mixed>
     */
    private function expandPreferencePlan(array $intent): array
    {
        $messages = $this->buildPreferenceExpansionMessages($intent);
        $schema = $this->preferenceExpansionSchema();

        $decoded = $this->callJsonSchema($messages, $schema, 'preference_plan');
        $plan = $this->normalizePreferencePlan($decoded, $intent);

        if (empty($plan['cities'])) {
            return $this->defaultPreferencePlan($intent);
        }

        return $plan;
    }

    /**
     * @param array<string, mixed> $intent
     * @return array<int, array<string, mixed>>
     */
    private function buildPreferenceExpansionMessages(array $intent): array
    {
        $summary = [
            'start_location' => $intent['start_location'],
            'departure' => $intent['departure_iso'],
            'cities' => array_map(static function ($leg): array {
                return [
                    'city' => (string) $leg['city'],
                    'arrival' => (string) $leg['arrival'],
                ];
            }, $intent['legs']),
            'traveler_preferences' => $intent['traveler_preferences'],
        ];

        $payload = json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (!is_string($payload)) {
            $payload = '{}';
        }

        $system = 'You convert ambiguous travel preferences into typed, data-friendly search filters.';
        $user = <<<PROMPT
Interpret the traveler context below and expand it into structured filters for data fetchers.

Guidelines:
- Use neutral, factual language.
- Output only JSON that matches the provided schema.
- Recommend 2-3 filters per city. Each filter should include a category label, optional keywords, and the ideal time of day.
- Include modifiers like accessibility or kid friendliness only if the traveler implies them.

Traveler context:
$payload
PROMPT;

        return [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $user],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function preferenceExpansionSchema(): array
    {
        return [
            '$schema' => 'http://json-schema.org/draft-07/schema#',
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'global' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'properties' => [
                        'pace' => ['type' => 'string'],
                        'budget' => ['type' => 'string'],
                        'notes' => [
                            'type' => 'array',
                            'items' => ['type' => 'string'],
                        ],
                    ],
                    'required' => ['pace'],
                ],
                'cities' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'properties' => [
                            'city' => ['type' => 'string'],
                            'filters' => [
                                'type' => 'array',
                                'items' => [
                                    'type' => 'object',
                                    'additionalProperties' => false,
                                    'properties' => [
                                        'category' => ['type' => 'string'],
                                        'keywords' => [
                                            'type' => 'array',
                                            'items' => ['type' => 'string'],
                                        ],
                                        'time_of_day' => ['type' => 'string'],
                                        'duration_hint' => ['type' => 'string'],
                                        'modifiers' => [
                                            'type' => 'array',
                                            'items' => ['type' => 'string'],
                                        ],
                                    ],
                                    'required' => ['category'],
                                ],
                                'minItems' => 1,
                            ],
                        ],
                        'required' => ['city', 'filters'],
                    ],
                ],
            ],
            'required' => ['cities'],
        ];
    }

    /**
     * @param array<string, mixed>|null $decoded
     * @param array<string, mixed> $intent
     * @return array<string, mixed>
     */
    private function normalizePreferencePlan(?array $decoded, array $intent): array
    {
        $plan = [
            'global' => [
                'pace' => 'moderate',
                'budget' => 'flexible',
                'notes' => [],
            ],
            'cities' => [],
        ];

        if (isset($decoded['global']) && is_array($decoded['global'])) {
            $plan['global']['pace'] = isset($decoded['global']['pace']) ? (string) $decoded['global']['pace'] : 'moderate';
            if (isset($decoded['global']['budget'])) {
                $plan['global']['budget'] = (string) $decoded['global']['budget'];
            }
            if (isset($decoded['global']['notes']) && is_array($decoded['global']['notes'])) {
                $notes = [];
                foreach ($decoded['global']['notes'] as $note) {
                    $note = trim((string) $note);
                    if ($note !== '') {
                        $notes[] = $note;
                    }
                }
                $plan['global']['notes'] = $notes;
            }
        }

        if (isset($decoded['cities']) && is_array($decoded['cities'])) {
            foreach ($decoded['cities'] as $entry) {
                if (!is_array($entry) || !isset($entry['city'])) {
                    continue;
                }
                $city = trim((string) $entry['city']);
                if ($city === '') {
                    continue;
                }
                $filters = [];
                if (isset($entry['filters']) && is_array($entry['filters'])) {
                    foreach ($entry['filters'] as $filter) {
                        if (!is_array($filter)) {
                            continue;
                        }
                        $filters[] = $this->normalizeFilter($filter);
                    }
                }
                if ($filters === []) {
                    $filters[] = $this->normalizeFilter(['category' => 'landmark']);
                }
                $plan['cities'][] = [
                    'city' => $city,
                    'filters' => $filters,
                ];
            }
        }

        if ($plan['cities'] === []) {
            foreach ($intent['cities_of_interest'] as $city) {
                $plan['cities'][] = [
                    'city' => $city,
                    'filters' => [
                        $this->normalizeFilter(['category' => 'landmark']),
                        $this->normalizeFilter(['category' => 'local dining']),
                    ],
                ];
            }
        }

        if ($plan['global']['notes'] === [] && $intent['traveler_preferences'] !== '') {
            $plan['global']['notes'][] = $intent['traveler_preferences'];
        }

        return $plan;
    }

    /**
     * @param array<string, mixed> $filter
     * @return array<string, mixed>
     */
    private function normalizeFilter(array $filter): array
    {
        $category = isset($filter['category']) ? trim((string) $filter['category']) : 'landmark';
        if ($category === '') {
            $category = 'landmark';
        }

        $keywords = [];
        if (isset($filter['keywords']) && is_array($filter['keywords'])) {
            foreach ($filter['keywords'] as $keyword) {
                $keyword = trim((string) $keyword);
                if ($keyword !== '') {
                    $keywords[] = $keyword;
                }
            }
        }

        $timeOfDay = isset($filter['time_of_day']) ? trim((string) $filter['time_of_day']) : '';
        if ($timeOfDay === '' && isset($filter['timeOfDay'])) {
            $timeOfDay = trim((string) $filter['timeOfDay']);
        }
        if ($timeOfDay === '') {
            $timeOfDay = 'afternoon';
        }

        $durationHint = isset($filter['duration_hint']) ? trim((string) $filter['duration_hint']) : '';
        $modifiers = [];
        if (isset($filter['modifiers']) && is_array($filter['modifiers'])) {
            foreach ($filter['modifiers'] as $modifier) {
                $modifier = trim((string) $modifier);
                if ($modifier !== '') {
                    $modifiers[] = $modifier;
                }
            }
        }

        return [
            'category' => $category,
            'keywords' => $keywords,
            'time_of_day' => $timeOfDay,
            'duration_hint' => $durationHint,
            'modifiers' => $modifiers,
        ];
    }

    /**
     * @param array<string, mixed> $intent
     * @return array<string, mixed>
     */
    private function defaultPreferencePlan(array $intent): array
    {
        $plan = [
            'global' => [
                'pace' => 'moderate',
                'budget' => 'flexible',
                'notes' => $intent['traveler_preferences'] !== '' ? [$intent['traveler_preferences']] : [],
            ],
            'cities' => [],
        ];

        $defaultFilters = [
            $this->normalizeFilter(['category' => 'historic landmark', 'time_of_day' => 'morning']),
            $this->normalizeFilter(['category' => 'museum', 'time_of_day' => 'afternoon']),
            $this->normalizeFilter(['category' => 'local dining', 'time_of_day' => 'evening']),
        ];

        foreach ($intent['cities_of_interest'] as $city) {
            $plan['cities'][] = [
                'city' => $city,
                'filters' => $defaultFilters,
            ];
        }

        return $plan;
    }

    /**
     * @param array<int, array<string, mixed>> $messages
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    private function callJsonSchema(array $messages, array $schema, string $name): array
    {
        try {
            $response = $this->request($this->buildSchemaRequest($messages, $schema, $name));
        } catch (\Throwable $exception) {
            Logger::logThrowable($exception, [
                'client_method' => __METHOD__,
                'stage' => 'request',
                'schema' => $name,
            ]);

            return [];
        }

        return $this->decodeStructuredResponse($response);
    }

    /**
     * @param array<string, mixed> $intent
     * @param array<string, mixed> $plan
     * @return array<int, array<string, mixed>>
     */
    private function acquireFactualData(array $intent, array $plan): array
    {
        $client = new LiveDataClient();
        $candidates = [];

        foreach ($plan['cities'] as $cityPlan) {
            if (!is_array($cityPlan) || !isset($cityPlan['city'])) {
                continue;
            }
            $city = trim((string) $cityPlan['city']);
            if ($city === '') {
                continue;
            }

            $leg = $this->matchLegForCity($intent['legs'], $city);
            $context = $this->buildLiveContext($city, $leg);

            $filters = isset($cityPlan['filters']) && is_array($cityPlan['filters'])
                ? $cityPlan['filters']
                : [$this->normalizeFilter(['category' => 'landmark'])];

            foreach ($filters as $filter) {
                $filter = $this->normalizeFilter(is_array($filter) ? $filter : []);
                $queries = $this->buildQueriesForFilter($city, $filter);
                $attempts = 0;
                foreach ($queries as $query) {
                    if (++$attempts > 3) {
                        break;
                    }

                    try {
                        $data = $client->fetchPlaceData($query, $context);
                    } catch (\Throwable $exception) {
                        Logger::logThrowable($exception, [
                            'client_method' => __METHOD__,
                            'stage' => 'fetch_place',
                            'query' => $query,
                            'city' => $city,
                        ]);
                        continue;
                    }

                    if (!is_array($data) || empty($data['matched'])) {
                        continue;
                    }

                    $candidates[] = [
                        'city' => $city,
                        'filter' => $filter,
                        'query' => $query,
                        'data' => $data,
                        'leg' => $leg,
                    ];
                }
            }
        }

        return $candidates;
    }

    /**
     * @param array<int, array<string, mixed>> $legs
     * @return array<string, mixed>
     */
    private function matchLegForCity(array $legs, string $city): array
    {
        $target = $this->normalizeCityKey($city);
        foreach ($legs as $leg) {
            if (!is_array($leg) || !isset($leg['city'])) {
                continue;
            }
            if ($this->normalizeCityKey((string) $leg['city']) === $target) {
                return $leg;
            }
        }

        return $legs[0] ?? [
            'city' => $city,
            'arrival' => '',
            'departure' => '',
            'coordinates' => null,
        ];
    }

    /**
     * @param array<string, mixed> $leg
     * @return array<string, mixed>
     */
    private function buildLiveContext(string $city, array $leg): array
    {
        $parts = $this->splitLocationParts($city);

        $context = [
            'address' => $city,
            'city' => $parts['city'],
            'region' => $parts['region'],
            'country' => $parts['country'],
            'latitude' => null,
            'longitude' => null,
        ];

        if (isset($leg['coordinates']) && is_array($leg['coordinates'])) {
            if (isset($leg['coordinates']['lat']) && is_numeric($leg['coordinates']['lat'])) {
                $context['latitude'] = (float) $leg['coordinates']['lat'];
            }
            if (isset($leg['coordinates']['lon']) && is_numeric($leg['coordinates']['lon'])) {
                $context['longitude'] = (float) $leg['coordinates']['lon'];
            }
        }

        return $context;
    }

    /**
     * @return array{city: string, region: string, country: string}
     */
    private function splitLocationParts(string $location): array
    {
        $pieces = array_map(static fn ($part): string => trim((string) $part), explode(',', $location));

        return [
            'city' => $pieces[0] ?? $location,
            'region' => $pieces[1] ?? '',
            'country' => $pieces[2] ?? '',
        ];
    }

    /**
     * @param array<string, mixed> $filter
     * @return array<int, string>
     */
    private function buildQueriesForFilter(string $city, array $filter): array
    {
        $queries = [];
        foreach ($filter['keywords'] as $keyword) {
            $keyword = trim((string) $keyword);
            if ($keyword !== '') {
                $queries[] = $keyword . ' ' . $city;
            }
        }

        $category = trim((string) $filter['category']);
        if ($category !== '') {
            $queries[] = $category . ' ' . $city;
        }

        $queries[] = $city;

        $unique = [];
        foreach ($queries as $query) {
            $query = trim($query);
            if ($query === '') {
                continue;
            }
            $unique[$query] = true;
        }

        return array_keys($unique);
    }

    /**
     * @param array<int, array<string, mixed>> $candidates
     * @return array<int, array<string, mixed>>
     */
    private function resolveAndFilterEntities(array $candidates): array
    {
        $resolved = [];

        foreach ($candidates as $candidate) {
            if (!is_array($candidate)) {
                continue;
            }

            $data = $candidate['data'] ?? null;
            if (!is_array($data) || empty($data['matched'])) {
                continue;
            }

            $name = isset($data['name']) ? trim((string) $data['name']) : '';
            if ($name === '') {
                continue;
            }

            $key = strtolower(trim((string) ($data['source_url'] ?? '')));
            if ($key === '') {
                $key = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $name) ?? $name);
            }
            if ($key === '') {
                $key = md5($name);
            }

            if (!isset($resolved[$key])) {
                $resolved[$key] = [
                    'city' => (string) ($candidate['city'] ?? ''),
                    'name' => $name,
                    'category' => isset($data['category']) ? (string) $data['category'] : '',
                    'contact' => $this->normalizeContact($data['contact'] ?? []),
                    'coordinates' => $this->normalizeCoordinates($data['coordinates'] ?? null),
                    'rating' => isset($data['rating']) && is_numeric($data['rating']) ? (float) $data['rating'] : null,
                    'price' => isset($data['price']) ? (string) $data['price'] : '',
                    'source_url' => isset($data['source_url']) ? (string) $data['source_url'] : '',
                    'sources' => is_array($data['sources'] ?? null) ? $data['sources'] : [],
                    'weather' => isset($data['weather']) && is_array($data['weather']) ? $data['weather'] : null,
                    'filters' => [],
                    'lastChecked' => isset($data['lastChecked']) ? (string) $data['lastChecked'] : '',
                    'raw' => $data,
                    'leg' => $candidate['leg'] ?? [],
                ];
            }

            $resolved[$key]['filters'][] = $candidate['filter'] ?? [];

            if ($resolved[$key]['rating'] === null && isset($data['rating']) && is_numeric($data['rating'])) {
                $resolved[$key]['rating'] = (float) $data['rating'];
            }
            if ($resolved[$key]['category'] === '' && isset($data['category'])) {
                $resolved[$key]['category'] = (string) $data['category'];
            }
            if ($resolved[$key]['contact']['address'] === '' && isset($data['contact']['address'])) {
                $resolved[$key]['contact']['address'] = (string) $data['contact']['address'];
            }
        }

        $filtered = [];
        foreach ($resolved as $entry) {
            if ($this->entityPassesQualityChecks($entry)) {
                $filtered[] = $entry;
            }
        }

        return $filtered;
    }

    /**
     * @param array<string, mixed> $entry
     */
    private function entityPassesQualityChecks(array $entry): bool
    {
        if (!isset($entry['coordinates']) || $entry['coordinates'] === null) {
            return false;
        }

        if (isset($entry['rating']) && is_numeric($entry['rating']) && (float) $entry['rating'] < 2.5) {
            return false;
        }

        return true;
    }

    /**
     * @param array<string, mixed> $contact
     * @return array<string, string>
     */
    private function normalizeContact($contact): array
    {
        $normalized = [
            'address' => '',
            'hours' => '',
            'phone' => '',
            'website' => '',
        ];

        if (!is_array($contact)) {
            return $normalized;
        }

        foreach ($normalized as $key => $_) {
            if (isset($contact[$key])) {
                $normalized[$key] = trim((string) $contact[$key]);
            }
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed>|null $coordinates
     * @return array{lat: float, lon: float}|null
     */
    private function normalizeCoordinates($coordinates): ?array
    {
        if (!is_array($coordinates)) {
            return null;
        }

        if (!isset($coordinates['lat'], $coordinates['lon'])) {
            return null;
        }

        if (!is_numeric($coordinates['lat']) || !is_numeric($coordinates['lon'])) {
            return null;
        }

        $lat = (float) $coordinates['lat'];
        $lon = (float) $coordinates['lon'];

        if (!is_finite($lat) || !is_finite($lon)) {
            return null;
        }

        return ['lat' => $lat, 'lon' => $lon];
    }

    /**
     * @param array<string, mixed> $intent
     * @param array<string, mixed> $plan
     * @param array<int, array<string, mixed>> $entities
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function scoreAndSelectEntities(array $intent, array $plan, array $entities): array
    {
        if ($entities === []) {
            return ['selected' => [], 'fallbacks' => []];
        }

        $scored = [];
        foreach ($entities as $entity) {
            $entity['score'] = $this->scoreEntity($entity);
            $entity['summary'] = $this->extractEntitySummary($entity);
            $entity['highlights'] = $this->extractEntityHighlights($entity);
            $entity['alignment'] = $this->describeAlignment($entity);
            $entity['duration_minutes'] = $this->estimateDurationMinutes($entity);
            $entity['duration_label'] = $this->formatDurationMinutes($entity['duration_minutes']);
            $entity['arrival'] = $this->estimateArrivalTime($entity, $intent);
            $entity['departure'] = $this->estimateDepartureTime($entity['arrival'], $entity['duration_minutes']);
            $entity['live_details'] = $entity['raw'] ?? [];
            $scored[] = $entity;
        }

        $grouped = [];
        foreach ($scored as $entity) {
            $key = $this->normalizeCityKey($entity['city']);
            if (!isset($grouped[$key])) {
                $grouped[$key] = [];
            }
            $grouped[$key][] = $entity;
        }

        foreach ($grouped as &$bucket) {
            usort($bucket, static function ($a, $b): int {
                return $b['score'] <=> $a['score'];
            });
        }
        unset($bucket);

        $selected = [];
        $fallbacks = [];
        $remaining = [];
        $idCounter = 1;

        foreach ($grouped as $cityKey => $bucket) {
            if ($bucket === []) {
                continue;
            }

            $primary = array_shift($bucket);
            $primary['id'] = $this->makeEntityId($cityKey, $idCounter++);
            $selected[] = $this->formatSelectedEntity($primary);

            if ($bucket !== []) {
                $fallback = array_shift($bucket);
                $fallback['id'] = $this->makeEntityId($cityKey . '-fb', $idCounter++);
                $fallbacks[] = $this->formatSelectedEntity($fallback);
            }

            foreach ($bucket as $entity) {
                $entity['id'] = $this->makeEntityId($cityKey . '-alt', $idCounter++);
                $remaining[] = $entity;
            }
        }

        usort($remaining, static function ($a, $b): int {
            return $b['score'] <=> $a['score'];
        });

        $target = min(6, max(4, count($scored)));
        while (count($selected) < $target && $remaining) {
            $next = array_shift($remaining);
            $selected[] = $this->formatSelectedEntity($next);
        }

        return [
            'selected' => $selected,
            'fallbacks' => $fallbacks,
        ];
    }

    /**
     * @param array<string, mixed> $entity
     */
    private function scoreEntity(array $entity): float
    {
        $score = 0.0;

        if (isset($entity['rating']) && is_numeric($entity['rating'])) {
            $score += 1.5 + min(5.0, max(0.0, (float) $entity['rating']));
        } else {
            $score += 1.0;
        }

        $score += count($entity['filters']) * 1.2;

        if (isset($entity['price']) && $entity['price'] !== '') {
            $score += 0.4;
        }

        if (isset($entity['sources']['wikipedia'])) {
            $score += 0.7;
        }

        if (isset($entity['weather']) && is_array($entity['weather'])) {
            $score += 0.3;
        }

        return $score;
    }

    /**
     * @param array<string, mixed> $entity
     */
    private function extractEntitySummary(array $entity): string
    {
        if (isset($entity['sources']['wikipedia']['extract'])) {
            $summary = trim((string) $entity['sources']['wikipedia']['extract']);
            if ($summary !== '') {
                return $summary;
            }
        }

        if (isset($entity['sources']['opentripmap']['preview']['description'])) {
            $summary = trim((string) $entity['sources']['opentripmap']['preview']['description']);
            if ($summary !== '') {
                return $summary;
            }
        }

        if (isset($entity['sources']['tripadvisor']['ranking']) && $entity['sources']['tripadvisor']['ranking'] !== '') {
            return (string) $entity['sources']['tripadvisor']['ranking'];
        }

        return 'A recommended stop that aligns with the traveler preferences.';
    }

    /**
     * @param array<string, mixed> $entity
     * @return array<int, string>
     */
    private function extractEntityHighlights(array $entity): array
    {
        $highlights = [];

        if (isset($entity['sources']['tripadvisor']['ranking']) && $entity['sources']['tripadvisor']['ranking'] !== '') {
            $highlights[] = (string) $entity['sources']['tripadvisor']['ranking'];
        }

        if (isset($entity['sources']['wikipedia']['description']) && $entity['sources']['wikipedia']['description'] !== '') {
            $highlights[] = (string) $entity['sources']['wikipedia']['description'];
        }

        if (isset($entity['price']) && $entity['price'] !== '') {
            $highlights[] = 'Price: ' . $entity['price'];
        }

        if (isset($entity['weather']['conditions']) && $entity['weather']['conditions'] !== '') {
            $highlights[] = 'Weather outlook: ' . $entity['weather']['conditions'];
        }

        return array_values(array_unique(array_filter($highlights)));
    }

    /**
     * @param array<string, mixed> $entity
     */
    private function describeAlignment(array $entity): string
    {
        $parts = [];

        if (!empty($entity['filters'])) {
            $categories = [];
            $modifiers = [];
            foreach ($entity['filters'] as $filter) {
                if (isset($filter['category'])) {
                    $categories[] = (string) $filter['category'];
                }
                if (isset($filter['modifiers']) && is_array($filter['modifiers'])) {
                    foreach ($filter['modifiers'] as $modifier) {
                        $modifier = trim((string) $modifier);
                        if ($modifier !== '') {
                            $modifiers[] = $modifier;
                        }
                    }
                }
            }
            if ($categories) {
                $parts[] = 'Focus: ' . implode(', ', array_unique($categories));
            }
            if ($modifiers) {
                $parts[] = 'Modifiers: ' . implode(', ', array_unique($modifiers));
            }
        }

        return $parts ? implode('. ', $parts) : 'Balanced stop aligned with the trip goals.';
    }

    /**
     * @param array<string, mixed> $entity
     */
    private function estimateDurationMinutes(array $entity): int
    {
        $category = strtolower((string) ($entity['category'] ?? ''));
        $duration = 90;

        if (strpos($category, 'museum') !== false) {
            $duration = 120;
        } elseif (strpos($category, 'tour') !== false) {
            $duration = 150;
        } elseif (strpos($category, 'dining') !== false || strpos($category, 'restaurant') !== false) {
            $duration = 90;
        } elseif (strpos($category, 'park') !== false || strpos($category, 'garden') !== false) {
            $duration = 110;
        }

        foreach ($entity['filters'] as $filter) {
            if (isset($filter['duration_hint'])) {
                $hint = trim((string) $filter['duration_hint']);
                if ($hint !== '' && preg_match('/(\d+)\s*(min|minute)/i', $hint, $matches) === 1) {
                    $duration = max(30, (int) $matches[1]);
                } elseif ($hint !== '' && preg_match('/(\d+)\s*h/i', $hint, $matches) === 1) {
                    $duration = max(30, (int) $matches[1] * 60);
                }
            }
        }

        return $duration;
    }

    private function formatDurationMinutes(int $minutes): string
    {
        if ($minutes % 60 === 0) {
            $hours = (int) ($minutes / 60);
            return $hours === 1 ? '1 hour' : $hours . ' hours';
        }

        if ($minutes > 120) {
            $hours = floor($minutes / 60);
            $remainder = $minutes % 60;
            return $hours . 'h ' . $remainder . 'm';
        }

        return $minutes . ' minutes';
    }

    /**
     * @param array<string, mixed> $entity
     * @param array<string, mixed> $intent
     */
    private function estimateArrivalTime(array $entity, array $intent): string
    {
        $rawArrival = $entity['leg']['arrival'] ?? $intent['departure_iso'];
        try {
            $arrival = new \DateTimeImmutable((string) $rawArrival);
        } catch (\Throwable $exception) {
            Logger::logThrowable($exception, [
                'client_method' => __METHOD__,
                'stage' => 'parse_arrival',
                'value' => $rawArrival,
            ]);
            $arrival = new \DateTimeImmutable($intent['departure_iso']);
        }

        $timeOfDay = 'afternoon';
        foreach ($entity['filters'] as $filter) {
            if (isset($filter['time_of_day'])) {
                $timeOfDay = strtolower((string) $filter['time_of_day']);
                break;
            }
        }

        switch ($timeOfDay) {
            case 'morning':
                $arrival = $arrival->setTime(9, 0);
                break;
            case 'evening':
                $arrival = $arrival->setTime(18, 0);
                break;
            case 'night':
                $arrival = $arrival->setTime(20, 0);
                break;
            default:
                $arrival = $arrival->setTime(13, 0);
        }

        return $arrival->format(\DateTimeInterface::ATOM);
    }

    private function estimateDepartureTime(string $arrivalIso, int $minutes): string
    {
        try {
            $arrival = new \DateTimeImmutable($arrivalIso);
        } catch (\Throwable $exception) {
            Logger::logThrowable($exception, [
                'client_method' => __METHOD__,
                'stage' => 'parse_depart_time',
                'value' => $arrivalIso,
            ]);
            $arrival = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        }

        return $arrival->add(new \DateInterval('PT' . max(30, $minutes) . 'M'))->format(\DateTimeInterface::ATOM);
    }

    private function normalizeCityKey(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]+/i', '-', $value) ?? $value;
        return trim((string) $value, '-');
    }

    private function makeEntityId(string $key, int $counter): string
    {
        $slug = $this->normalizeCityKey($key);
        if ($slug === '') {
            $slug = 'stop';
        }

        return $slug . '-' . $counter;
    }

    /**
     * @param array<string, mixed> $entity
     * @return array<string, mixed>
     */
    private function formatSelectedEntity(array $entity): array
    {
        return [
            'id' => (string) $entity['id'],
            'city' => (string) $entity['city'],
            'name' => (string) $entity['name'],
            'category' => (string) $entity['category'],
            'contact' => $entity['contact'],
            'coordinates' => $entity['coordinates'],
            'rating' => $entity['rating'],
            'price' => (string) $entity['price'],
            'source_url' => (string) $entity['source_url'],
            'sources' => $entity['sources'],
            'weather' => $entity['weather'],
            'filters' => $entity['filters'],
            'score' => (float) $entity['score'],
            'summary' => (string) $entity['summary'],
            'highlights' => $entity['highlights'],
            'alignment' => (string) $entity['alignment'],
            'arrival' => (string) $entity['arrival'],
            'departure' => (string) $entity['departure'],
            'duration_minutes' => (int) $entity['duration_minutes'],
            'duration_label' => (string) $entity['duration_label'],
            'live_details' => $entity['live_details'],
        ];
    }

    /**
     * @param array<string, mixed> $intent
     * @return array<string, mixed>
     */
    private function fallbackItinerary(array $intent): array
    {
        return [
            'route_overview' => 'We were unable to assemble a data-grounded itinerary at this time. Please try again later.',
            'total_travel_time' => '',
            'summary' => 'No verified stops were available from partner data sources.',
            'additional_tips' => 'Regenerate the trip once live data sources respond or adjust your cities of interest.',
            'start_location' => $intent['start_location'],
            'departure_datetime' => $intent['departure_iso'],
            'city_of_interest' => $intent['cities_of_interest'][0] ?? '',
            'cities_of_interest' => $intent['cities_of_interest'],
            'traveler_preferences' => $intent['traveler_preferences'],
            'stops' => [],
        ];
    }

    /**
     * @param array<string, mixed> $intent
     * @param array<string, mixed> $plan
     * @param array<string, array<int, array<string, mixed>>> $selection
     * @return array<string, mixed>
     */
    private function synthesizeFromGroundedData(array $intent, array $plan, array $selection): array
    {
        $context = $this->prepareSynthesisContext($intent, $plan, $selection);
        $messages = $this->buildSynthesisMessages($context);

        $response = $this->callJsonSchema($messages, $this->schema(), 'historical_road_trip');
        if ($response === []) {
            return $this->fallbackItinerary($intent);
        }

        return $response;
    }

    /**
     * @param array<string, mixed> $intent
     * @param array<string, mixed> $plan
     * @param array<string, array<int, array<string, mixed>>> $selection
     * @return array<string, mixed>
     */
    private function prepareSynthesisContext(array $intent, array $plan, array $selection): array
    {
        $cities = [];
        foreach ($intent['legs'] as $leg) {
            if (!is_array($leg)) {
                continue;
            }
            $cities[] = [
                'city' => (string) ($leg['city'] ?? ''),
                'arrival' => (string) ($leg['arrival'] ?? ''),
                'departure' => (string) ($leg['departure'] ?? ''),
                'coordinates' => $leg['coordinates'] ?? null,
            ];
        }

        $stops = [];
        foreach ($selection['selected'] as $entity) {
            $stops[] = [
                'id' => $entity['id'],
                'city' => $entity['city'],
                'name' => $entity['name'],
                'category' => $entity['category'],
                'summary' => $entity['summary'],
                'highlights' => $entity['highlights'],
                'contact' => $entity['contact'],
                'rating' => $entity['rating'],
                'price' => $entity['price'],
                'schedule' => [
                    'arrival' => $entity['arrival'],
                    'departure' => $entity['departure'],
                    'duration_label' => $entity['duration_label'],
                    'duration_minutes' => $entity['duration_minutes'],
                ],
                'weather' => $entity['weather'],
                'sources' => $entity['sources'],
                'alignment' => $entity['alignment'],
            ];
        }

        $fallbacks = [];
        foreach ($selection['fallbacks'] as $entity) {
            $fallbacks[] = [
                'id' => $entity['id'],
                'city' => $entity['city'],
                'name' => $entity['name'],
                'category' => $entity['category'],
                'summary' => $entity['summary'],
                'highlights' => $entity['highlights'],
                'contact' => $entity['contact'],
                'rating' => $entity['rating'],
                'price' => $entity['price'],
                'weather' => $entity['weather'],
                'sources' => $entity['sources'],
                'alignment' => $entity['alignment'],
            ];
        }

        return [
            'trip' => [
                'start_location' => $intent['start_location'],
                'departure' => $intent['departure_iso'],
                'trip_window' => $intent['trip_window'],
                'traveler_preferences' => $intent['traveler_preferences'],
                'cities' => $cities,
                'plan' => $plan,
            ],
            'selected_stops' => $stops,
            'fallback_stops' => $fallbacks,
        ];
    }

    /**
     * @param array<string, mixed> $context
     * @return array<int, array<string, mixed>>
     */
    private function buildSynthesisMessages(array $context): array
    {
        $contextJson = json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (!is_string($contextJson)) {
            $contextJson = '{}';
        }

        $system = 'You are a travel writer who produces road trip itineraries strictly from verified data. Never invent facts.';
        $user = <<<PROMPT
Synthesize a chronological itinerary using only the provided data. Requirements:
- Use each primary stop in the order they appear and respect their scheduled windows.
- Reference fallback stops only if a primary stop lacks sufficient information.
- Include concise transitions between stops.
- Surface weather notes when available.
- Set `entity_id` for every stop to the matching `id` from the selected stop data.
- Return strict JSON that matches the schema skeleton below and do not emit markdown fences or extra text.

Schema skeleton:
{$this->jsonSkeleton()}

Verified data:
$contextJson
PROMPT;

        return [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $user],
        ];
    }

    /**
     * @param array<string, mixed>|null $rawItinerary
     * @param array<string, mixed> $intent
     * @param array<string, array<int, array<string, mixed>>> $selection
     * @return array<string, mixed>
     */
    private function attachGroundTruth($rawItinerary, array $intent, array $selection): array
    {
        if (!is_array($rawItinerary)) {
            $rawItinerary = [];
        }

        $rawItinerary['start_location'] = $intent['start_location'];
        $rawItinerary['departure_datetime'] = $intent['departure_iso'];
        $rawItinerary['cities_of_interest'] = $intent['cities_of_interest'];
        if (!isset($rawItinerary['city_of_interest']) || trim((string) $rawItinerary['city_of_interest']) === '') {
            $rawItinerary['city_of_interest'] = $intent['cities_of_interest'][0] ?? '';
        }
        $rawItinerary['traveler_preferences'] = $intent['traveler_preferences'];

        $entitiesById = [];
        foreach ($selection['selected'] as $entity) {
            $entitiesById[$entity['id']] = $entity;
        }
        foreach ($selection['fallbacks'] as $entity) {
            $entitiesById[$entity['id']] = $entity;
        }

        $fallbackByCity = [];
        foreach ($selection['fallbacks'] as $entity) {
            $key = $this->normalizeCityKey($entity['city']);
            if (!isset($fallbackByCity[$key])) {
                $fallbackByCity[$key] = [];
            }
            $fallbackByCity[$key][] = $entity;
        }

        $stops = isset($rawItinerary['stops']) && is_array($rawItinerary['stops']) ? $rawItinerary['stops'] : [];
        $merged = [];
        $used = [];
        $selectedQueue = $selection['selected'];

        foreach ($stops as $index => $stop) {
            $stopArray = is_array($stop) ? $stop : $this->emptyStopShell();
            $entity = null;
            $entityId = isset($stopArray['entity_id']) ? trim((string) $stopArray['entity_id']) : '';
            if ($entityId !== '' && isset($entitiesById[$entityId])) {
                $entity = $entitiesById[$entityId];
            }

            if ($entity === null) {
                foreach ($selectedQueue as $candidate) {
                    if (!isset($used[$candidate['id']])) {
                        $entity = $candidate;
                        break;
                    }
                }
            }

            if ($entity === null) {
                $fallbackCity = '';
                $title = isset($stopArray['title']) ? trim((string) $stopArray['title']) : '';
                if ($title !== '') {
                    foreach ($selection['selected'] as $candidate) {
                        if (strcasecmp($candidate['name'], $title) === 0) {
                            $fallbackCity = $candidate['city'];
                            break;
                        }
                    }
                }
                if ($fallbackCity === '' && isset($selection['selected'][0])) {
                    $fallbackCity = $selection['selected'][0]['city'];
                }
                $entity = $this->takeFallback($fallbackByCity, $fallbackCity);
            }

            if ($entity === null) {
                continue;
            }

            $used[$entity['id']] = true;
            $merged[] = $this->mergeStopWithEntity($stopArray, $entity);
        }

        foreach ($selection['selected'] as $entity) {
            if (!isset($used[$entity['id']])) {
                $merged[] = $this->mergeStopWithEntity($this->emptyStopShell(), $entity);
                $used[$entity['id']] = true;
            }
        }

        $rawItinerary['stops'] = $merged;

        return $rawItinerary;
    }

    /**
     * @param array<string, mixed> $stop
     * @param array<string, mixed> $entity
     * @return array<string, mixed>
     */
    private function mergeStopWithEntity(array $stop, array $entity): array
    {
        $stop = array_merge($this->emptyStopShell(), $stop);
        $stop['entity_id'] = $entity['id'];
        $stop['title'] = $entity['name'];
        if ($entity['contact']['address'] !== '') {
            $stop['address'] = $entity['contact']['address'];
        }
        $stop['duration'] = $entity['duration_label'];
        if (trim((string) $stop['description']) === '') {
            $stop['description'] = $entity['summary'];
        }
        if (trim((string) $stop['highlight']) === '' && $entity['highlights']) {
            $stop['highlight'] = $entity['highlights'][0];
        }
        if (trim((string) $stop['fun_fact']) === '' && isset($entity['highlights'][1])) {
            $stop['fun_fact'] = $entity['highlights'][1];
        }
        if (trim((string) $stop['challenge']) === '') {
            $stop['challenge'] = $entity['alignment'];
        }
        if (trim((string) $stop['food_pick']) === '' && stripos($entity['category'], 'dining') !== false) {
            $stop['food_pick'] = $entity['name'];
        }
        $stop['live_details'] = $entity['live_details'];

        return $stop;
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyStopShell(): array
    {
        return [
            'entity_id' => '',
            'title' => '',
            'address' => '',
            'duration' => '',
            'description' => '',
            'historical_note' => '',
            'challenge' => '',
            'fun_fact' => '',
            'highlight' => '',
            'food_pick' => '',
            'live_details' => null,
        ];
    }

    /**
     * @param array<string, array<int, array<string, mixed>>> $fallbackByCity
     * @return array<string, mixed>|null
     */
    private function takeFallback(array &$fallbackByCity, string $cityKey): ?array
    {
        $cityKey = $this->normalizeCityKey($cityKey);
        if (isset($fallbackByCity[$cityKey]) && $fallbackByCity[$cityKey]) {
            return array_shift($fallbackByCity[$cityKey]);
        }

        foreach ($fallbackByCity as $key => $list) {
            if ($list) {
                return array_shift($fallbackByCity[$key]);
            }
        }

        return null;
    }

    /**
     * @param array<int, array<string, mixed>> $messages
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    private function buildSchemaRequest(array $messages, array $schema, string $name): array
    {
        return [
            'model' => $this->model,
            'input' => $messages,
            'text' => [
                'format' => [
                    'type' => 'json_schema',
                    'name' => $name,
                    'schema' => $schema,
                ],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $response
     * @return array<string, mixed>
     */
    private function decodeStructuredResponse(array $response): array
    {
        $raw = $this->extractResponsePayload($response);
        if ($raw === null) {
            return [];
        }

        if (is_array($raw)) {
            return $raw;
        }

        $raw = $this->stripJsonCodeFence((string) $raw);

        try {
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            return $decoded;
        } catch (\Throwable $exception) {
            Logger::logThrowable($exception, [
                'client_method' => __METHOD__,
                'stage' => 'decode_structured',
            ]);
            return [];
        }
    }

    /**
     * @param array<string, mixed> $response
     * @return array<string, mixed>|string|null
     */
    private function extractResponsePayload(array $response)
    {
        $output = $response['output'] ?? null;
        if (is_array($output)) {
            foreach ($output as $message) {
                $candidate = $this->extractRawItinerary($message);
                if ($candidate !== null) {
                    return $candidate;
                }
            }
        }

        $outputText = $response['output_text'] ?? null;
        if (is_array($outputText)) {
            foreach ($outputText as $chunk) {
                if (is_string($chunk) && trim($chunk) !== '') {
                    return $chunk;
                }
            }
        } elseif (is_string($outputText) && trim($outputText) !== '') {
            return $outputText;
        }

        return null;
    }

    /**
     * @param mixed $part
     * @return array<string, mixed>|string|null
     */
    private function extractRawItinerary($part)
    {
        if (is_string($part)) {
            $part = trim($part);
            return $part === '' ? null : $part;
        }

        if (!is_array($part)) {
            return null;
        }

        if (isset($part['json'])) {
            $json = $part['json'];
            if (is_array($json)) {
                return $json;
            }
            if (is_string($json)) {
                $json = trim($json);
                if ($json !== '') {
                    return $json;
                }
            }
        }

        if (isset($part['data'])) {
            $data = $part['data'];
            if (is_array($data)) {
                return $data;
            }
            if (is_string($data)) {
                $data = trim($data);
                if ($data !== '') {
                    return $data;
                }
            }
        }

        $text = $part['text'] ?? null;
        if (is_string($text) && trim($text) !== '') {
            return $text;
        }

        if (isset($part['content']) && is_array($part['content'])) {
            foreach ($part['content'] as $nested) {
                $candidate = $this->extractRawItinerary($nested);
                if ($candidate !== null) {
                    return $candidate;
                }
            }
        }

        return null;
    }

    private function stripJsonCodeFence(string $value): string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return '';
        }

        if (preg_match('/^```[a-zA-Z0-9]*\s*(.*?)```$/s', $trimmed, $matches) === 1) {
            return trim($matches[1]);
        }

        return $trimmed;
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function request(array $body): array
    {
        if (!function_exists('curl_init')) {
            throw new \RuntimeException('cURL extension is not enabled in PHP.');
        }

        try {
            $encodedBody = json_encode($body, JSON_THROW_ON_ERROR);
        } catch (\Throwable $exception) {
            Logger::logThrowable($exception, [
                'client_method' => __METHOD__,
                'stage' => 'encode_request',
            ]);
            throw $exception;
        }

        $signature = hash('sha256', $encodedBody);
        $cacheDir = $this->cacheDirectory();

        $cached = $this->readCachedResponse($cacheDir, $signature);
        if ($cached !== null) {
            return $cached;
        }

        $lockHandle = $this->acquireLock($cacheDir, $signature);

        try {
            // Another request may have primed the cache while we waited for the lock.
            $cached = $this->readCachedResponse($cacheDir, $signature);
            if ($cached !== null) {
                return $cached;
            }

            $this->enforceRateLimit($cacheDir);

            $response = $this->dispatchRequest($encodedBody);
            $this->storeCachedResponse($cacheDir, $signature, $response);

            return $response;
        } finally {
            $this->releaseLock($lockHandle);
        }
    }

    /**
     * Execute the HTTP request with retries/backoff and return the decoded payload.
     *
     * @return array<string, mixed>
     */
    private function dispatchRequest(string $encodedBody): array
    {
        $status = null;
        $raw = null;
        $lastErrorMessage = null;

        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            $ch = curl_init('https://api.openai.com/v1/responses');

            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER     => [
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . $this->apiKey,
                    'User-Agent: ' . $this->userAgent(),
                ],
                CURLOPT_POSTFIELDS     => $encodedBody,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT        => 60,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
            ]);

            $raw = curl_exec($ch);

            if ($raw !== false) {
                $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
                curl_close($ch);
                break;
            }

            $errorCode = curl_errno($ch);
            $lastErrorMessage = sprintf(
                'Failed to contact OpenAI (attempt %d/%d): [%d] %s',
                $attempt,
                self::MAX_ATTEMPTS,
                $errorCode,
                curl_error($ch)
            );

            Logger::log($lastErrorMessage, [
                'client_method'   => __METHOD__,
                'attempt'         => $attempt,
                'max_attempts'    => self::MAX_ATTEMPTS,
                'curl_error_code' => $errorCode,
            ]);

            curl_close($ch);

            if ($attempt === self::MAX_ATTEMPTS) {
                throw new \RuntimeException($lastErrorMessage ?? 'Failed to contact OpenAI.');
            }

            $this->backoffWithJitter($attempt);
        }

        if ($raw === null) {
            throw new \RuntimeException('Failed to contact OpenAI.');
        }
        if ($status === null) {
            $status = 0;
        }

        try {
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable $exception) {
            Logger::logThrowable($exception, [
                'client_method' => __METHOD__,
                'stage' => 'decode_response',
                'status' => $status,
            ]);
            throw $exception;
        }

        if ($status >= 400) {
            Logger::log('OpenAI API error', [
                'client_method' => __METHOD__,
                'status'        => $status,
                'response'      => $decoded,
            ]);
            $detail = is_array($decoded) ? ($decoded['error']['message'] ?? 'Unknown error') : 'Unknown error';
            throw new \RuntimeException('OpenAI API error: ' . $detail);
        }

        return $decoded;
    }

    /**
     * JSON schema for Responses API text.format.
     * @return array<string, mixed>
     */
    private function schema(): array
    {
        return [
            '$schema' => 'http://json-schema.org/draft-07/schema#',
            'type' => 'object',
            'additionalProperties' => false, // REQUIRED by Responses API json_schema
            'properties' => [
                'route_overview'        => ['type' => 'string'],
                'total_travel_time'     => ['type' => 'string'],
                'summary'               => ['type' => 'string'],
                'additional_tips'       => ['type' => 'string'],
                'start_location'        => ['type' => 'string'],
                'departure_datetime'    => ['type' => 'string'],
                'city_of_interest'      => ['type' => 'string'],
                'cities_of_interest'    => [
                    'type' => 'array',
                    'minItems' => 1,
                    'items' => ['type' => 'string'],
                ],
                'traveler_preferences'  => ['type' => 'string'],
                'stops' => [
                    'type' => 'array',
                    'minItems' => 3,
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'properties' => [
                            'entity_id'      => ['type' => 'string'],
                            'title'           => ['type' => 'string'],
                            'address'         => ['type' => 'string'],
                            'duration'        => ['type' => 'string'],
                            'description'     => ['type' => 'string'],
                            'historical_note' => ['type' => 'string'],
                            'challenge'       => ['type' => 'string'],
                            'fun_fact'        => ['type' => 'string'],
                            'highlight'       => ['type' => 'string'],
                            'food_pick'       => ['type' => 'string'],
                        ],
                        'required' => [
                            'entity_id','title','address','duration','description','historical_note','challenge','fun_fact','highlight','food_pick'
                        ],
                    ],
                ],
            ],
            // Responses API requires every key listed in properties to appear in required
            'required' => [
                'route_overview',
                'total_travel_time',
                'summary',
                'additional_tips',
                'start_location',
                'departure_datetime',
                'city_of_interest',
                'cities_of_interest',
                'traveler_preferences',
                'stops'
            ],
        ];
    }

    private function jsonSkeleton(): string
    {
        $example = [
            'route_overview' => 'string',
            'total_travel_time' => 'string',
            'summary' => 'string',
            'additional_tips' => 'string',
            'start_location' => 'string',
            'departure_datetime' => 'string',
            'city_of_interest' => 'string',
            'cities_of_interest' => ['string'],
            'traveler_preferences' => 'string',
            'stops' => [
                [
                    'entity_id' => 'string',
                    'title' => 'string',
                    'address' => 'string',
                    'duration' => 'string',
                    'description' => 'string',
                    'historical_note' => 'string',
                    'challenge' => 'string',
                    'fun_fact' => 'string',
                    'highlight' => 'string',
                    'food_pick' => 'string',
                ],
            ],
        ];

        return (string) json_encode($example, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Normalize the itinerary so FE is always safe to render/use.
     * @param array<string, mixed> $x
     * @return array<string, mixed>
     */
    private function normalizeItinerary(array $x): array
    {
        $stopsIn = isset($x['stops']) && is_array($x['stops']) ? $x['stops'] : [];
        $citiesIn = isset($x['cities_of_interest']) && is_array($x['cities_of_interest']) ? $x['cities_of_interest'] : [];
        $citiesOut = [];
        foreach ($citiesIn as $city) {
            $city = trim((string) $city);
            if ($city !== '') {
                $citiesOut[] = $city;
            }
        }

        $citiesOut = array_values($citiesOut);

        $stopsOut = [];
        foreach ($stopsIn as $s) {
            $stopsOut[] = [
                'entity_id'       => isset($s['entity_id']) ? (string)$s['entity_id'] : '',
                'title'           => isset($s['title']) ? (string)$s['title'] : '',
                'address'         => isset($s['address']) ? (string)$s['address'] : '',
                'duration'        => isset($s['duration']) ? (string)$s['duration'] : '',
                'description'     => isset($s['description']) ? (string)$s['description'] : '',
                'historical_note' => isset($s['historical_note']) ? (string)$s['historical_note'] : '',
                'challenge'       => isset($s['challenge']) ? (string)$s['challenge'] : '',
                'fun_fact'        => isset($s['fun_fact']) ? (string)$s['fun_fact'] : '',
                'highlight'       => isset($s['highlight']) ? (string)$s['highlight'] : '',
                'food_pick'       => isset($s['food_pick']) ? (string)$s['food_pick'] : '',
                'live_details'    => $this->normalizeStopLiveDetails($s['live_details'] ?? null),
            ];
        }

        $primaryCity = isset($x['city_of_interest']) ? (string)$x['city_of_interest'] : '';
        if ($primaryCity === '' && $citiesOut) {
            $primaryCity = $citiesOut[0];
        }

        return [
            'route_overview'       => isset($x['route_overview']) ? (string)$x['route_overview'] : '',
            'total_travel_time'    => isset($x['total_travel_time']) ? (string)$x['total_travel_time'] : '',
            'summary'              => isset($x['summary']) ? (string)$x['summary'] : '',
            'additional_tips'      => isset($x['additional_tips']) ? (string)$x['additional_tips'] : '',
            'start_location'       => isset($x['start_location']) ? (string)$x['start_location'] : '',
            'departure_datetime'   => isset($x['departure_datetime']) ? (string)$x['departure_datetime'] : '',
            'city_of_interest'     => $primaryCity,
            'cities_of_interest'   => $citiesOut,
            'traveler_preferences' => isset($x['traveler_preferences']) ? (string)$x['traveler_preferences'] : '',
            // id is optional; FE guards it
            'id'                   => $x['id'] ?? null,
            'stops'                => $stopsOut,
        ];
    }

    /**
     * @param mixed $value
     * @return array<string, mixed>|null
     */
    private function normalizeStopLiveDetails($value): ?array
    {
        if (!is_array($value)) {
            return null;
        }

        $contact = [
            'address' => '',
            'hours' => '',
            'phone' => '',
            'website' => '',
        ];
        if (isset($value['contact']) && is_array($value['contact'])) {
            foreach ($contact as $key => $_) {
                if (isset($value['contact'][$key])) {
                    $contact[$key] = (string) $value['contact'][$key];
                }
            }
        }

        $coordinates = null;
        if (isset($value['coordinates']) && is_array($value['coordinates'])) {
            $lat = isset($value['coordinates']['lat']) ? (float) $value['coordinates']['lat'] : null;
            $lon = isset($value['coordinates']['lon']) ? (float) $value['coordinates']['lon'] : null;
            if ($lat !== null && $lon !== null && is_finite($lat) && is_finite($lon)) {
                $coordinates = ['lat' => $lat, 'lon' => $lon];
            }
        }

        $rating = null;
        if (isset($value['rating']) && is_numeric($value['rating'])) {
            $rating = (float) $value['rating'];
        }

        $weather = null;
        if (isset($value['weather']) && is_array($value['weather'])) {
            $weather = [
                'temperature' => isset($value['weather']['temperature']) && is_numeric($value['weather']['temperature'])
                    ? (float) $value['weather']['temperature']
                    : null,
                'feels_like' => isset($value['weather']['feels_like']) && is_numeric($value['weather']['feels_like'])
                    ? (float) $value['weather']['feels_like']
                    : null,
                'conditions' => isset($value['weather']['conditions']) ? (string) $value['weather']['conditions'] : '',
                'updated_at' => isset($value['weather']['updated_at']) ? (string) $value['weather']['updated_at'] : '',
                'source_url' => isset($value['weather']['source_url']) ? (string) $value['weather']['source_url'] : '',
            ];
        }

        return [
            'query' => isset($value['query']) ? (string) $value['query'] : '',
            'matched' => !empty($value['matched']),
            'name' => isset($value['name']) ? (string) $value['name'] : '',
            'category' => isset($value['category']) ? (string) $value['category'] : '',
            'contact' => $contact,
            'coordinates' => $coordinates,
            'rating' => $rating,
            'price' => isset($value['price']) ? (string) $value['price'] : '',
            'source_url' => isset($value['source_url']) ? (string) $value['source_url'] : '',
            'lastChecked' => isset($value['lastChecked']) ? (string) $value['lastChecked'] : '',
            'sources' => isset($value['sources']) && is_array($value['sources']) ? $this->normalizeLiveSources($value['sources']) : [],
            'weather' => $weather,
        ];
    }

    /**
     * @param array<string, mixed> $sources
     * @return array<string, array<string, mixed>>
     */
    private function normalizeLiveSources(array $sources): array
    {
        $normalized = [];
        foreach ($sources as $key => $data) {
            if (!is_array($data)) {
                continue;
            }
            $entry = [];
            foreach ($data as $field => $value) {
                if (is_string($value)) {
                    $entry[(string) $field] = $value;
                } elseif (is_numeric($value)) {
                    $entry[(string) $field] = 0 + $value;
                }
            }
            if ($entry !== []) {
                $normalized[(string) $key] = $entry;
            }
        }

        return $normalized;
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
        if ($value === false) {
            return '';
        }
        return (string) $value;
    }

    private function userAgent(): string
    {
        return 'RedClayRoadTrip/1.1 (+php; litespeed)';
    }

    private function cacheDirectory(): string
    {
        $root = dirname(__DIR__, 2);
        $dir = $root . '/data/cache/openai';

        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('Unable to initialize OpenAI cache directory.');
        }

        return $dir;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readCachedResponse(string $cacheDir, string $signature): ?array
    {
        $path = $cacheDir . '/' . $signature . '.json';

        if (!is_file($path)) {
            return null;
        }

        $expiresAt = filemtime($path);
        if ($expiresAt === false || ($expiresAt + self::CACHE_TTL_SECONDS) < time()) {
            @unlink($path);
            return null;
        }

        $contents = @file_get_contents($path);
        if ($contents === false) {
            return null;
        }

        try {
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable $exception) {
            Logger::logThrowable($exception, [
                'client_method' => __METHOD__,
                'stage' => 'decode_cache',
            ]);
            @unlink($path);
            return null;
        }

        return $decoded;
    }

    /**
     * @param array<string, mixed> $response
     */
    private function storeCachedResponse(string $cacheDir, string $signature, array $response): void
    {
        $path = $cacheDir . '/' . $signature . '.json';

        try {
            $payload = json_encode($response, JSON_THROW_ON_ERROR);
        } catch (\Throwable $exception) {
            Logger::logThrowable($exception, [
                'client_method' => __METHOD__,
                'stage' => 'encode_cache',
            ]);
            return;
        }

        @file_put_contents($path, $payload, LOCK_EX);
        $this->pruneCache($cacheDir);
    }

    private function pruneCache(string $cacheDir): void
    {
        $files = glob($cacheDir . '/*.json');
        if (!is_array($files)) {
            return;
        }

        if (count($files) <= self::CACHE_MAX_ENTRIES) {
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
     * @return resource|null
     */
    private function acquireLock(string $cacheDir, string $signature)
    {
        $lockPath = $cacheDir . '/' . $signature . '.lock';
        $handle = @fopen($lockPath, 'c');
        if ($handle === false) {
            Logger::log('Failed to open cache lock', [
                'client_method' => __METHOD__,
                'path' => $lockPath,
            ]);
            return null;
        }

        if (!flock($handle, LOCK_EX)) {
            fclose($handle);
            Logger::log('Failed to acquire cache lock', [
                'client_method' => __METHOD__,
                'path' => $lockPath,
            ]);
            return null;
        }

        return $handle;
    }

    /**
     * @param resource|null $handle
     */
    private function releaseLock($handle): void
    {
        if (!is_resource($handle)) {
            return;
        }

        try {
            flock($handle, LOCK_UN);
        } finally {
            fclose($handle);
        }
    }

    private function enforceRateLimit(string $cacheDir): void
    {
        $lockPath = $cacheDir . '/rate-limit.lock';
        $dataPath = $cacheDir . '/rate-limit.json';

        $lockHandle = @fopen($lockPath, 'c');
        if ($lockHandle === false) {
            Logger::log('Unable to open rate limit lock', ['client_method' => __METHOD__]);
            return;
        }

        if (!flock($lockHandle, LOCK_EX)) {
            fclose($lockHandle);
            Logger::log('Unable to lock rate limit file', ['client_method' => __METHOD__]);
            return;
        }

        try {
            $now = time();
            $cutoff = $now - self::RATE_LIMIT_INTERVAL;

            $timestamps = [];
            if (is_file($dataPath)) {
                $raw = @file_get_contents($dataPath);
                if ($raw !== false && $raw !== '') {
                    $decoded = json_decode($raw, true);
                    if (is_array($decoded)) {
                        $timestamps = array_filter($decoded, static function ($value) use ($cutoff) {
                            return is_int($value) && $value >= $cutoff;
                        });
                    }
                }
            }

            $timestamps = array_values($timestamps);

            if (count($timestamps) >= self::RATE_LIMIT_MAX_REQUESTS) {
                $retryAfter = max(1, ($timestamps[0] + self::RATE_LIMIT_INTERVAL) - $now);
                throw new \RuntimeException('Rate limit exceeded. Please retry in ' . $retryAfter . ' seconds.');
            }

            $timestamps[] = $now;
            @file_put_contents($dataPath, json_encode($timestamps));
        } finally {
            flock($lockHandle, LOCK_UN);
            fclose($lockHandle);
        }
    }

    private function backoffWithJitter(int $attempt): void
    {
        $baseDelaySeconds = 1 << ($attempt - 1); // 1, 2, 4, ...
        $jitterMilliseconds = random_int(100, 750);
        $sleepMicroseconds = ($baseDelaySeconds * 1_000 + $jitterMilliseconds) * 1_000;
        usleep($sleepMicroseconds);
    }
}
