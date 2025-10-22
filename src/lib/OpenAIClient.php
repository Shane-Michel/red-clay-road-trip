<?php

declare(strict_types=1);

require_once __DIR__ . '/Logger.php';

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

    public function __construct(?string $apiKey = null, string $model = 'gpt-4.1-mini')
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
        // Read fields defensively to avoid PHP notices
        $start = (string)($payload['start_location'] ?? '');
        $depart = (string)($payload['departure_datetime'] ?? '');
        $citiesRaw = $payload['cities_of_interest'] ?? [];
        if (!is_array($citiesRaw)) {
            $citiesRaw = [$citiesRaw];
        }
        $cities = [];
        foreach ($citiesRaw as $cityName) {
            $cityName = trim((string) $cityName);
            if ($cityName !== '') {
                $cities[] = $cityName;
            }
        }
        $city = (string)($payload['city_of_interest'] ?? '');
        if ($city === '' && $cities) {
            $city = $cities[0];
        }
        $cityList = $cities ? implode(', ', $cities) : $city;
        $prefs = (string)($payload['traveler_preferences'] ?? 'None provided');

        $requestBody = [
            'model' => $this->model,
            'input' => [
                [
                    'role' => 'system',
                    'content' => 'You are a travel concierge who blends regional history, quirky discoveries, and foodie finds into road trip itineraries.',
                ],
                [
                    'role' => 'user',
                    'content' => sprintf(
                        "Plan a story-rich road trip that mixes history, fun facts, hidden gems, and memorable food stops.\n\n" .
                        "Start location: %s\nDeparture: %s\nCities to explore: %s\nTraveler preferences: %s\n\n" .
                        "Provide a route overview, total drive time, and 4-6 stops that follow the cities in a logical order. " .
                        "For each stop include: title, address, suggested duration, engaging description, story or cultural context, a fun fact, a highlight (interesting place or experience), a hidden bite (local food or drink pick), and a lighthearted challenge. " .
                        "Close with practical travel tips and reminders.",
                        $start,
                        $depart,
                        $cityList,
                        $prefs
                    ),
                ],
            ],
            // Responses API: use text.format with a json_schema
            'text' => [
                'format' => [
                    'type'   => 'json_schema',
                    'name'   => 'historical_road_trip',
                    'schema' => $this->schema(),
                ],
            ],
        ];

        try {
            $response = $this->request($requestBody);
        } catch (\Throwable $exception) {
            Logger::logThrowable($exception, [
                'client_method' => __METHOD__,
                'request_context' => [
                    'start_location' => $start,
                    'departure_datetime' => $depart,
                    'city_of_interest' => $city,
                    'cities_of_interest' => $cities,
                ],
            ]);
            throw $exception;
        }

        $rawItinerary = null;

        $output = $response['output'] ?? null;
        if (is_array($output)) {
            foreach ($output as $message) {
                $candidate = $this->extractRawItinerary($message);
                if ($candidate !== null) {
                    $rawItinerary = $candidate;
                    break;
                }

                if (!is_array($message)) {
                    continue;
                }

                $content = $message['content'] ?? null;
                if (!is_array($content)) {
                    continue;
                }

                foreach ($content as $part) {
                    $candidate = $this->extractRawItinerary($part);
                    if ($candidate !== null) {
                        $rawItinerary = $candidate;
                        break 2;
                    }
                }
            }
        }

        if ($rawItinerary === null) {
            $outputText = $response['output_text'] ?? null;
            if (is_array($outputText)) {
                foreach ($outputText as $textChunk) {
                    if (is_string($textChunk) && trim($textChunk) !== '') {
                        $rawItinerary = $textChunk;
                        break;
                    }
                }
            } elseif (is_string($outputText) && trim($outputText) !== '') {
                $rawItinerary = $outputText;
            }
        }

        if ($rawItinerary === null) {
            // Return a normalized empty object to keep FE happy
            return $this->normalizeItinerary([]);
        }

        if (is_array($rawItinerary)) {
            return $this->normalizeItinerary($rawItinerary);
        }

        $rawItinerary = $this->stripJsonCodeFence((string) $rawItinerary);

        try {
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode($rawItinerary, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable $exception) {
            Logger::logThrowable($exception, [
                'client_method' => __METHOD__,
                'stage' => 'decode_itinerary',
            ]);
            throw new \RuntimeException('Unable to decode itinerary payload.', 0, $exception);
        }

        // Normalize to guarantee keys your FE expects
        return $this->normalizeItinerary($decoded);
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
                            'title','address','duration','description','historical_note','challenge','fun_fact','highlight','food_pick'
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
                'title'           => isset($s['title']) ? (string)$s['title'] : '',
                'address'         => isset($s['address']) ? (string)$s['address'] : '',
                'duration'        => isset($s['duration']) ? (string)$s['duration'] : '',
                'description'     => isset($s['description']) ? (string)$s['description'] : '',
                'historical_note' => isset($s['historical_note']) ? (string)$s['historical_note'] : '',
                'challenge'       => isset($s['challenge']) ? (string)$s['challenge'] : '',
                'fun_fact'        => isset($s['fun_fact']) ? (string)$s['fun_fact'] : '',
                'highlight'       => isset($s['highlight']) ? (string)$s['highlight'] : '',
                'food_pick'       => isset($s['food_pick']) ? (string)$s['food_pick'] : '',
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
