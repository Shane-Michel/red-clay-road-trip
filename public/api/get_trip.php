<?php

declare(strict_types=1);

header('Content-Type: application/json');

$rootPath = dirname(__DIR__, 2);
require_once $rootPath . '/src/bootstrap.php';
require_once $rootPath . '/src/lib/TripRepository.php';

TripRepository::initialize();

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($id <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid trip id']);
    exit;
}

try {
    $trip = TripRepository::getTrip($id);
    if ($trip === null) {
        http_response_code(404);
        echo json_encode(['error' => 'Trip not found']);
        exit;
    }

    echo json_encode($trip, JSON_PRETTY_PRINT);
} catch (Throwable $exception) {
    http_response_code(500);
    echo json_encode(['error' => $exception->getMessage()]);
}
