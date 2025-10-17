<?php

declare(strict_types=1);

header('Content-Type: application/json');

require_once __DIR__ . '/../lib/OpenAIClient.php';

function respond(int $status, array $data): void
{
    http_response_code($status);
    echo json_encode($data, JSON_PRETTY_PRINT);
    exit;
}

$startLocation = trim($_POST['start_location'] ?? '');
$departureDatetime = trim($_POST['departure_datetime'] ?? '');
$cityOfInterest = trim($_POST['city_of_interest'] ?? '');
$preferences = trim($_POST['traveler_preferences'] ?? '');

if ($startLocation === '' || $departureDatetime === '' || $cityOfInterest === '') {
    respond(422, ['error' => 'Missing required fields.']);
}

try {
    $client = new OpenAIClient();
    $raw = $client->generateItinerary([
        'start_location' => $startLocation,
        'departure_datetime' => $departureDatetime,
        'city_of_interest' => $cityOfInterest,
        'traveler_preferences' => $preferences,
    ]);

    if (is_string($raw)) {
        $itinerary = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    } else {
        $itinerary = $raw;
    }

    if (!is_array($itinerary) || empty($itinerary['stops'])) {
        throw new RuntimeException('Incomplete itinerary received from OpenAI.');
    }

    $itinerary['start_location'] = $startLocation;
    $itinerary['departure_datetime'] = $departureDatetime;
    $itinerary['city_of_interest'] = $cityOfInterest;
    $itinerary['traveler_preferences'] = $preferences;

    if (!isset($itinerary['summary'])) {
        $itinerary['summary'] = sprintf('Road trip from %s to explore %s.', $startLocation, $cityOfInterest);
    }

    respond(200, $itinerary);
} catch (Throwable $exception) {
    respond(500, ['error' => $exception->getMessage()]);
}
