<?php

class OpenAIClient
{
    /** @var string */
    private $apiKey;
    /** @var string */
    private $model;

    public function __construct(?string $apiKey = null, string $model = 'gpt-4.1-mini')
    {
        $apiKey ??= getenv('OPENAI_API_KEY') ?: '';
        if ($apiKey === '') {
            throw new RuntimeException('OPENAI_API_KEY is not set.');
        }

        $this->apiKey = $apiKey;
        $configuredModel = getenv('OPENAI_MODEL');
        if (is_string($configuredModel) && $configuredModel !== '') {
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
        $systemPrompt = 'You are a travel historian who designs detailed, accessible scavenger hunts for road trips.';

        $userPrompt = sprintf(
            "Plan a historical road trip scavenger hunt.\n\nStart location: %s\nDeparture: %s\nCity of interest: %s\nPreferences: %s\n\nProvide an overview, driving segments, 4-6 stops with history challenges, travel tips, and accessibility notes.",
            $payload['start_location'],
            $payload['departure_datetime'],
            $payload['city_of_interest'],
            $payload['traveler_preferences'] ?? 'None provided'
        );

        $response = $this->request([
            'model' => $this->model,
            'input' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $userPrompt],
            ],
            'response_format' => [
                'type' => 'json_schema',
                'json_schema' => [
                    'name' => 'historical_road_trip',
                    'schema' => $this->schema(),
                ],
            ],
        ]);

        return $response['output'][0]['content'][0]['text'] ?? [];
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function request(array $body): array
    {
        $ch = curl_init('https://api.openai.com/v1/responses');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->apiKey,
            ],
            CURLOPT_POSTFIELDS => json_encode($body, JSON_THROW_ON_ERROR),
        ]);

        $raw = curl_exec($ch);
        if ($raw === false) {
            throw new RuntimeException('Failed to contact OpenAI: ' . curl_error($ch));
        }

        $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        if ($status >= 400) {
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
}
