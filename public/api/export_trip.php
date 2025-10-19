<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';
require_once dirname(__DIR__, 2) . '/src/lib/ItineraryExporter.php';
require_once dirname(__DIR__, 2) . '/src/lib/Logger.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$raw = file_get_contents('php://input');

try {
    $input = json_decode($raw ?: '[]', true, 512, JSON_THROW_ON_ERROR);
} catch (\Throwable $exception) {
    Logger::logThrowable($exception, [
        'endpoint' => 'export_trip',
        'stage' => 'decode_payload',
    ]);
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Invalid JSON payload']);
    exit;
}

$format = strtolower((string) ($input['format'] ?? ''));
$trip = $input['trip'] ?? null;

if (!in_array($format, ['ics', 'pdf'], true)) {
    http_response_code(422);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Unsupported format requested']);
    exit;
}

if (!is_array($trip)) {
    http_response_code(422);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Missing trip data for export']);
    exit;
}

try {
    if ($format === 'ics') {
        $payload = ItineraryExporter::createIcs($trip);
        header('Content-Type: text/calendar; charset=utf-8');
        header('Content-Disposition: attachment; filename="road-trip.ics"');
        echo $payload;
        exit;
    }

    $payload = ItineraryExporter::createPdf($trip);
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="road-trip.pdf"');
    echo $payload;
    exit;
} catch (\Throwable $exception) {
    Logger::logThrowable($exception, [
        'endpoint' => 'export_trip',
        'format' => $format,
    ]);
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Unable to generate export']);
    exit;
}
