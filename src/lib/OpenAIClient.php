<?php

declare(strict_types=1);

require_once __DIR__ . '/Logger.php';


class OpenAIClient
{
    /** @var string */
    private $apiKey;
    /** @var string */
    private $model;

    public function __construct(?string $apiKey = null, string $model = 'gpt-4.1-mini')
    {
        if ($apiKey === null) {
            $apiKey = $this->readFromEnvironment('OPENAI_API_KEY');
        }
        if ($apiKey === '') {
            throw new RuntimeException('OPENAI_API_KEY is not set.');
        }

        $this->apiKey = $apiKey;
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
        $requestBody = [
            'model' => $this->model,
            'input' => [
                ['role' => 'system', 'content' => 'You are a travel historian who designs detailed, accessible scavenger hunts for road trips.'],
                ['role' => 'user', 'content' => sprintf(
                    "Plan a historical road trip scavenger hunt.\n\nStart location: %s\nDeparture: %s\nCity of interest: %s\nPreferences: %s\n\nProvide an overview, driving segments, 4-6 stops with history challenges, travel tips, and accessibility notes.",
                    $payload['start_location'],
                    $payload['departure_datetime'],
                    $payload['city_of_interest'],
                    $payload['traveler_preferences'] ?? 'None provided'
                )],
            ],
            'response_format' => [
                'type' => 'json_schema',
                'json_schema' => [
                    'name' => 'historical_road_trip',
                    'schema' => $this->schema(),
                ],
            ],
        ];

        try {
            $response = $this->request($requestBody);
        } catch (Throwable $exception) {
            Logger::logThrowable($exception, [
                'client_method' => __METHOD__,
                'request_context' => [
                    'start_location' => $payload['start_location'] ?? null,
                    'departure_datetime' => $payload['departure_datetime'] ?? null,
                    'city_of_interest' => $payload['city_of_interest'] ?? null,
                ],
            ]);
            throw $exception;
        }

        $text = $response['output'][0]['content'][0]['text'] ?? null;
        if (!is_string($text) || trim($text) === '') {
            return [];
        }

        try {
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode($text, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable $exception) {
            Logger::logThrowable($exception, [
                'client_method' => __METHOD__,
                'stage' => 'decode_itinerary',
            ]);

            throw new RuntimeException('Unable to decode itinerary payload.', 0, $exception);
        }

        return $decoded;
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function request(array $body): array
    {
        $ch = curl_init('https://api.openai.com/v1/responses');
        try {
            $encodedBody = json_encode($body, JSON_THROW_ON_ERROR);
        } catch (Throwable $exception) {
            Logger::logThrowable($exception, [
                'client_method' => __METHOD__,
                'stage' => 'encode_request',
            ]);
            throw $exception;
        }

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->apiKey,
            ],
            CURLOPT_POSTFIELDS => $encodedBody,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 60,
        ]);

        $raw = curl_exec($ch);
        if ($raw === false) {
            $errorMessage = 'Failed to contact OpenAI: ' . curl_error($ch);
            Logger::log($errorMessage, [
                'client_method' => __METHOD__,
            ]);
            curl_close($ch);
            throw new RuntimeException($errorMessage);
        }

        $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable $exception) {
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
                'status' => $status,
                'response' => $decoded,
            ]);
            throw new RuntimeException('OpenAI API error: ' . ($decoded['error']['message'] ?? 'Unknown error'));
        }

        return $decoded;
    }

    /**
     * @return array<string, mixed>
     */
    private function schema(): array
    {
        return [
            '$schema' => 'http://json-schema.org/draft-07/schema#',
            'type' => 'object',
            'required' => ['route_overview', 'total_travel_time', 'stops'],
            'properties' => [
                'route_overview' => ['type' => 'string'],
                'total_travel_time' => ['type' => 'string'],
                'summary' => ['type' => 'string'],
                'additional_tips' => ['type' => 'string'],
                'start_location' => ['type' => 'string'],
                'departure_datetime' => ['type' => 'string'],
                'city_of_interest' => ['type' => 'string'],
                'traveler_preferences' => ['type' => 'string'],
                'stops' => [
                    'type' => 'array',
                    'minItems' => 3,
                    'items' => [
                        'type' => 'object',
                        'required' => ['title', 'address', 'duration', 'description', 'historical_note', 'challenge'],
                        'properties' => [
                            'title' => ['type' => 'string'],
                            'address' => ['type' => 'string'],
                            'duration' => ['type' => 'string'],
                            'description' => ['type' => 'string'],
                            'historical_note' => ['type' => 'string'],
                            'challenge' => ['type' => 'string'],
                        ],
                    ],
                ],
            ],
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
