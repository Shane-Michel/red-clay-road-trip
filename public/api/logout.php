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

try {
    Auth::logout();
    $scope = UserScope::fromRequest();
    echo json_encode([
        'success' => true,
        'scope' => $scope->storageKey(),
    ], JSON_PRETTY_PRINT);
} catch (\Throwable $exception) {
    Logger::logThrowable($exception, [
        'endpoint' => 'logout',
    ]);
    http_response_code(500);
    echo json_encode(['error' => 'Unable to sign out. Please try again.']);
}
