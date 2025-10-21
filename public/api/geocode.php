<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';
require_once dirname(__DIR__, 2) . '/src/lib/NominatimGeocoder.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

$location = isset($_GET['q']) ? trim((string) $_GET['q']) : '';

if ($location === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Missing required parameter "q".']);
    exit;
}

try {
    $result = NominatimGeocoder::geocode($location);

    echo json_encode([
        'query' => $location,
        'found' => $result !== null,
        'lat' => $result['lat'] ?? null,
        'lon' => $result['lon'] ?? null,
        'display_name' => $result['display_name'] ?? null,
    ], JSON_PRETTY_PRINT);
} catch (\Throwable $exception) {
    Logger::logThrowable($exception, [
        'endpoint' => 'geocode',
        'query' => $location,
    ]);

    http_response_code(502);
    echo json_encode(['error' => 'Geocoding service unavailable.']);
}
