<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';
require_once dirname(__DIR__, 2) . '/src/lib/TripRepository.php';

header('Content-Type: application/json');

TripRepository::initialize();

function respond(int $status, array $data): void
{
    http_response_code($status);
    echo json_encode($data, JSON_PRETTY_PRINT);
    exit;
}

$rawInput = file_get_contents('php://input');

try {
    $input = json_decode($rawInput ?: '[]', true, 512, JSON_THROW_ON_ERROR);
} catch (JsonException $exception) {
    Logger::logThrowable($exception, [
        'endpoint' => 'save_trip',
        'stage' => 'decode_payload',
        'raw_input' => $rawInput,
    ]);
    respond(400, ['error' => 'Invalid JSON payload: ' . $exception->getMessage()]);
}

$required = ['start_location', 'departure_datetime', 'city_of_interest', 'stops'];
foreach ($required as $field) {
    if (empty($input[$field])) {
        respond(422, ['error' => sprintf('Missing field: %s', $field)]);
    }
}

try {
    $id = TripRepository::saveTrip($input);
    respond(201, ['id' => $id]);
} catch (Throwable $exception) {
    Logger::logThrowable($exception, [
        'endpoint' => 'save_trip',
        'payload' => $input,
    ]);

    respond(500, ['error' => $exception->getMessage()]);
}
