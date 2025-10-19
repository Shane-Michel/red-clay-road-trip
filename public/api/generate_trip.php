<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';
require_once dirname(__DIR__, 2) . '/src/lib/OpenAIClient.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function respond(int $status, array $data): void
{
    http_response_code($status);
    // Ensure JSON errors show up as proper errors rather than silent failures
    $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        http_response_code(500);
        echo json_encode(['error' => ['message' => 'Failed to encode response JSON']]);
        exit;
    }
    echo $json;
    exit;
}

// Enforce POST
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    respond(405, ['error' => ['message' => 'Method not allowed. Use POST.']]);
}

// Pull & trim inputs (supports multipart/form-data from FormData)
$startLocation     = trim((string)($_POST['start_location'] ?? ''));
$departureDatetime = trim((string)($_POST['departure_datetime'] ?? ''));
$cityOfInterest    = trim((string)($_POST['city_of_interest'] ?? ''));
$preferences       = trim((string)($_POST['traveler_preferences'] ?? ''));

// Basic validation
$missing = [];
if ($startLocation === '')     { $missing[] = 'start_location'; }
if ($departureDatetime === '') { $missing[] = 'departure_datetime'; }
if ($cityOfInterest === '')    { $missing[] = 'city_of_interest'; }

if ($missing) {
    respond(422, [
        'error' => [
            'message' => 'Missing required fields: ' . implode(', ', $missing) . '.'
        ]
    ]);
}

// Optional: very light datetime sanity check (does not hard-fail on timezone)
if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}/', $departureDatetime)) {
    // Don’t hard block—just warn; front-end shows message but can proceed.
    // If you prefer to block, change status to 422 and return.
    Logger::log('generate_trip warning: departure_datetime not ISO-like', [
        'value' => $departureDatetime,
    ]);
}

try {
    $client = new OpenAIClient();
    $itinerary = $client->generateItinerary([
        'start_location'       => $startLocation,
        'departure_datetime'   => $departureDatetime,
        'city_of_interest'     => $cityOfInterest,
        'traveler_preferences' => $preferences,
    ]);

    // Belt-and-suspenders: ensure stops is always an array for the FE
    if (!isset($itinerary['stops']) || !is_array($itinerary['stops'])) {
        $itinerary['stops'] = [];
    }

    respond(200, ['data' => $itinerary]);
} catch (\Throwable $exception) {
    // Classify common failures as 400 (bad request) vs 500 (server)
    $status = 400;
    $msg = $exception->getMessage();
    // If something unexpected (coding error, type error) → 500
    if (!($exception instanceof \RuntimeException)) {
        $status = 500;
    }

    Logger::logThrowable($exception, [
        'endpoint' => 'generate_trip',
        'request_context' => [
            'start_location'     => $startLocation,
            'departure_datetime' => $departureDatetime,
            'city_of_interest'   => $cityOfInterest,
        ],
    ]);

    respond($status, ['error' => ['message' => $msg ?: 'Failed to generate itinerary.']]);
}
