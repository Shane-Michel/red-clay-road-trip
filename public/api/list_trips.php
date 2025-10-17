<?php

declare(strict_types=1);

header('Content-Type: application/json');

$rootPath = dirname(__DIR__, 2);
require_once $rootPath . '/src/bootstrap.php';
require_once $rootPath . '/src/lib/TripRepository.php';

TripRepository::initialize();

try {
    $trips = TripRepository::listTrips();
    echo json_encode($trips, JSON_PRETTY_PRINT);
} catch (Throwable $exception) {
    http_response_code(500);
    echo json_encode(['error' => $exception->getMessage()]);
}
