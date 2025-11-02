<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';
require_once dirname(__DIR__, 2) . '/src/lib/Auth.php';
require_once dirname(__DIR__, 2) . '/src/lib/UserScope.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

Auth::ensureSession();

$raw = file_get_contents('php://input');
if ($raw === false) {
    $raw = '';
}

try {
    $input = $raw !== '' ? json_decode($raw, true, 512, JSON_THROW_ON_ERROR) : [];
} catch (\JsonException $exception) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON payload']);
    exit;
}

$email = isset($input['email']) ? (string) $input['email'] : '';
$password = isset($input['password']) ? (string) $input['password'] : '';

try {
    $user = Auth::login($email, $password);
    $scope = UserScope::fromRequest();
    echo json_encode([
        'user' => [
            'email' => $user['email'],
            'created_at' => $user['created_at'],
        ],
        'scope' => $scope->storageKey(),
    ], JSON_PRETTY_PRINT);
} catch (\InvalidArgumentException $exception) {
    http_response_code(422);
    echo json_encode(['error' => $exception->getMessage()]);
} catch (\RuntimeException $exception) {
    http_response_code(401);
    echo json_encode(['error' => $exception->getMessage()]);
} catch (\Throwable $exception) {
    Logger::logThrowable($exception, [
        'endpoint' => 'login',
    ]);
    http_response_code(500);
    echo json_encode(['error' => 'Unable to sign in at this time. Please try again later.']);
}
