<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';
require_once dirname(__DIR__, 2) . '/src/lib/LiveDataClient.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

function respond(int $status, array $payload): void
{
    http_response_code($status);
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        http_response_code(500);
        echo json_encode(['error' => ['message' => 'Failed to encode response.']]);
        exit;
    }
    echo $json;
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    respond(405, ['error' => ['message' => 'Method not allowed. Use POST.']]);
}

$rawInput = file_get_contents('php://input') ?: '';
$input = null;

$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
if (stripos($contentType, 'application/json') !== false) {
    try {
        /** @var array<string, mixed>|null $decoded */
        $decoded = json_decode($rawInput, true, 512, JSON_THROW_ON_ERROR);
        if (is_array($decoded)) {
            $input = $decoded;
        }
    } catch (\JsonException $exception) {
        Logger::logThrowable($exception, [
            'endpoint' => 'live_lookup',
            'stage' => 'decode_json',
            'raw' => $rawInput,
        ]);
        respond(400, ['error' => ['message' => 'Invalid JSON payload.']]);
    }
}

if ($input === null) {
    $input = $_POST ?: [];
    if (empty($input) && $rawInput !== '') {
        parse_str($rawInput, $parsed);
        if (is_array($parsed)) {
            $input = $parsed;
        }
    }
}

$query = isset($input['query']) ? trim((string) $input['query']) : '';
if ($query === '') {
    respond(422, ['error' => ['message' => 'Missing required field: query.']]);
}

$context = [
    'address' => isset($input['address']) ? trim((string) $input['address']) : '',
    'city' => isset($input['city']) ? trim((string) $input['city']) : '',
    'region' => isset($input['region']) ? trim((string) $input['region']) : '',
    'country' => isset($input['country']) ? trim((string) $input['country']) : '',
];

foreach (['latitude', 'longitude'] as $coordinate) {
    if (isset($input[$coordinate]) && $input[$coordinate] !== '') {
        $value = is_numeric($input[$coordinate]) ? (float) $input[$coordinate] : null;
        if ($value !== null && is_finite($value)) {
            $context[$coordinate] = $value;
        }
    }
}

try {
    $client = new LiveDataClient();
    $data = $client->fetchPlaceData($query, $context);
    respond(200, ['data' => $data]);
} catch (\Throwable $exception) {
    Logger::logThrowable($exception, [
        'endpoint' => 'live_lookup',
        'query' => $query,
        'context' => $context,
    ]);
    respond(500, ['error' => ['message' => $exception->getMessage() ?: 'Failed to fetch live data.']]);
}
