<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';
require_once dirname(__DIR__, 2) . '/src/lib/OpenAIClient.php';

header('Content-Type: application/json');

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
    $itinerary = $client->generateItinerary([
        'start_location' => $startLocation,
        'departure_datetime' => $departureDatetime,
        'city_of_interest' => $cityOfInterest,
        'traveler_preferences' => $preferences,
    ]);

    respond(200, ['data' => $itinerary]);
} catch (Throwable $exception) {
    Logger::logThrowable($exception, [
        'endpoint' => 'generate_trip',
        'request_context' => [
            'start_location' => $startLocation,
            'departure_datetime' => $departureDatetime,
            'city_of_interest' => $cityOfInterest,
        ],
    ]);

    respond(500, ['error' => $exception->getMessage()]);
}
