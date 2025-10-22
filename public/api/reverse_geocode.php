<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';
require_once dirname(__DIR__, 2) . '/src/lib/NominatimGeocoder.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

$lat = isset($_GET['lat']) ? (float) $_GET['lat'] : null;
$lon = isset($_GET['lon']) ? (float) $_GET['lon'] : null;

if ($lat === null || $lon === null || !is_finite($lat) || !is_finite($lon)) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing or invalid coordinates.']);
    exit;
}

try {
    $result = NominatimGeocoder::reverse($lat, $lon);

    echo json_encode([
        'lat' => $lat,
        'lon' => $lon,
        'found' => $result !== null,
        'display_name' => $result['display_name'] ?? null,
    ], JSON_PRETTY_PRINT);
} catch (\Throwable $exception) {
    Logger::logThrowable($exception, [
        'endpoint' => 'reverse_geocode',
        'lat' => $lat,
        'lon' => $lon,
    ]);

    http_response_code(502);
    echo json_encode(['error' => 'Reverse geocoding service unavailable.']);
}
