<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';
require_once dirname(__DIR__, 2) . '/src/lib/TripRepository.php';

header('Content-Type: application/json');

TripRepository::initialize();

try {
    $trips = TripRepository::listTrips();
    echo json_encode($trips, JSON_PRETTY_PRINT);
} catch (\Throwable $exception) {
    Logger::logThrowable($exception, ['endpoint' => 'list_trips']);
    http_response_code(500);
    echo json_encode(['error' => $exception->getMessage()]);
}
