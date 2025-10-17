<?php

declare(strict_types=1);

header('Content-Type: application/json');

require_once dirname(__DIR__, 2) . '/src/lib/TripRepository.php';

TripRepository::initialize();

try {
    $trips = TripRepository::listTrips();
    echo json_encode($trips, JSON_PRETTY_PRINT);
} catch (Throwable $exception) {
    http_response_code(500);
    echo json_encode(['error' => $exception->getMessage()]);
}
