<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';
require_once dirname(__DIR__, 2) . '/src/lib/Auth.php';
require_once dirname(__DIR__, 2) . '/src/lib/UserScope.php';

header('Content-Type: application/json');

Auth::ensureSession();

$scope = UserScope::fromRequest();
$user = Auth::currentUser();

$response = [
    'authenticated' => $user !== null,
    'user' => null,
    'scope' => $scope->storageKey(),
];

if ($user) {
    $response['user'] = [
        'email' => $user['email'],
        'created_at' => $user['created_at'],
    ];
}

echo json_encode($response, JSON_PRETTY_PRINT);
