<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';
require_once dirname(__DIR__, 2) . '/src/lib/TripRepository.php';
require_once dirname(__DIR__, 2) . '/src/lib/ShareToken.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

TripRepository::initialize();

$raw = file_get_contents('php://input');

try {
    $input = json_decode($raw ?: '[]', true, 512, JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    Logger::logThrowable($exception, [
        'endpoint' => 'create_share_link',
        'stage' => 'decode_payload',
    ]);
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON payload']);
    exit;
}

$tripId = isset($input['id']) ? (int) $input['id'] : 0;
if ($tripId <= 0) {
    http_response_code(422);
    echo json_encode(['error' => 'Trip id is required']);
    exit;
}

if (!TripRepository::tripExists($tripId)) {
    http_response_code(404);
    echo json_encode(['error' => 'Trip not found']);
    exit;
}

$ttl = null;
if (isset($input['expires_in'])) {
    $seconds = max(300, min((int) $input['expires_in'], 60 * 60 * 24 * 30));
    $ttl = 'PT' . $seconds . 'S';
} elseif (!empty($input['ttl'])) {
    $ttl = (string) $input['ttl'];
}

try {
    $token = ShareToken::create($tripId, $ttl);
    $url = ShareToken::buildRelativeUrl($token);

    echo json_encode([
        'share_url' => $url,
        'expires' => $token['expires'],
        'expires_at' => (new \DateTimeImmutable('@' . $token['expires']))->setTimezone(new \DateTimeZone('UTC'))->format(\DateTimeInterface::ATOM),
    ]);
} catch (Throwable $exception) {
    Logger::logThrowable($exception, [
        'endpoint' => 'create_share_link',
        'trip_id' => $tripId,
    ]);
    http_response_code(500);
    echo json_encode(['error' => 'Unable to create share link']);
}
