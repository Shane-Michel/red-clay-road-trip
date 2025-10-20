<?php

declare(strict_types=1);

require_once __DIR__ . '/Logger.php';

final class OpenAIClient
{
    private const MAX_ATTEMPTS = 3;

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
        $city = (string)($payload['city_of_interest'] ?? '');
        $prefs = (string)($payload['traveler_preferences'] ?? 'None provided');

        $requestBody = [
            'model' => $this->model,
            'input' => [
                [
                    'role' => 'system',
                    'content' => 'You are a travel historian who designs detailed, accessible scavenger hunts for road trips.',
                ],
                [
                    'role' => 'user',
                    'content' => sprintf(
                        "Plan a historical road trip scavenger hunt.\n\n".
                        "Start location: %s\nDeparture: %s\nCity of interest: %s\nPreferences: %s\n\n".
                        "Provide an overview, driving segments, 4-6 stops with history challenges, travel tips, and accessibility notes.",
                        $start,
                        $depart,
                        $city,
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
                ],
            ]);
            throw $exception;
        }

        $content = $response['output'][0]['content'] ?? null;
        $rawItinerary = null;

        if (is_array($content)) {
            foreach ($content as $part) {
                if (!is_array($part)) {
                    continue;
                }

                // Responses API with json_schema may return `output_json` blocks
                if (isset($part['json']) && is_array($part['json'])) {
                    $rawItinerary = $part['json'];
                    break;
                }

                $text = $part['text'] ?? null;
                if (is_string($text) && trim($text) !== '') {
                    $rawItinerary = $text;
                    break;
                }
            }
        }

        if ($rawItinerary === null) {
            // Return a normalized empty object to keep FE happy
            return $this->normalizeItinerary([]);
        }

        if (is_array($rawItinerary)) {
            return $this->normalizeItinerary($rawItinerary);
        }

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
                    'User-Agent: RedClayRoadTrip/1.0 (+php; litespeed)',
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

            // Exponential backoff: 1s, 2s
            sleep(1 << ($attempt - 1));
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
                        ],
                        'required' => [
                            'title','address','duration','description','historical_note','challenge'
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

        $stopsOut = [];
        foreach ($stopsIn as $s) {
            $stopsOut[] = [
                'title'           => isset($s['title']) ? (string)$s['title'] : '',
                'address'         => isset($s['address']) ? (string)$s['address'] : '',
                'duration'        => isset($s['duration']) ? (string)$s['duration'] : '',
                'description'     => isset($s['description']) ? (string)$s['description'] : '',
                'historical_note' => isset($s['historical_note']) ? (string)$s['historical_note'] : '',
                'challenge'       => isset($s['challenge']) ? (string)$s['challenge'] : '',
            ];
        }

        return [
            'route_overview'       => isset($x['route_overview']) ? (string)$x['route_overview'] : '',
            'total_travel_time'    => isset($x['total_travel_time']) ? (string)$x['total_travel_time'] : '',
            'summary'              => isset($x['summary']) ? (string)$x['summary'] : '',
            'additional_tips'      => isset($x['additional_tips']) ? (string)$x['additional_tips'] : '',
            'start_location'       => isset($x['start_location']) ? (string)$x['start_location'] : '',
            'departure_datetime'   => isset($x['departure_datetime']) ? (string)$x['departure_datetime'] : '',
            'city_of_interest'     => isset($x['city_of_interest']) ? (string)$x['city_of_interest'] : '',
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
}