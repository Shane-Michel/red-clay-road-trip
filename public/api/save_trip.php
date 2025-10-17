<?php

declare(strict_types=1);

header('Content-Type: application/json');

$rootPath = dirname(__DIR__, 2);
require_once $rootPath . '/src/bootstrap.php';
require_once $rootPath . '/src/lib/TripRepository.php';

TripRepository::initialize();

function respond(int $status, array $data): void
{
    http_response_code($status);
    echo json_encode($data, JSON_PRETTY_PRINT);
    exit;
}

try {
    $input = json_decode(file_get_contents('php://input') ?: '[]', true, 512, JSON_THROW_ON_ERROR);
} catch (JsonException $exception) {
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
    respond(500, ['error' => $exception->getMessage()]);
}
