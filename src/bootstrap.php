<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/Logger.php';

(static function (): void {
    $rootPath = dirname(__DIR__);

    Logger::setup();

    if (!isset($_ENV) || !is_array($_ENV)) {
        $_ENV = [];
    }

    if (!isset($_SERVER) || !is_array($_SERVER)) {
        $_SERVER = [];
    }

    $autoloadPath = $rootPath . '/vendor/autoload.php';
    if (is_readable($autoloadPath)) {
        require_once $autoloadPath;

        if (class_exists('Dotenv\\Dotenv')) {
            \Dotenv\Dotenv::createImmutable($rootPath)->safeLoad();
            return;
        }
    }

    $envPath = $rootPath . '/.env';
    if (!is_readable($envPath)) {
        return;
    }

    $lines = preg_split("/(\r\n|\r|\n)/", (string) file_get_contents($envPath));
    if (!is_array($lines)) {
        return;
    }

    foreach ($lines as $line) {
        if ($line === null) {
            continue;
        }

        $trimmed = trim($line);
        if ($trimmed === '' || $trimmed[0] === '#') {
            continue;
        }

        $separatorPosition = strpos($trimmed, '=');
        if ($separatorPosition === false) {
            continue;
        }

        $key = trim(substr($trimmed, 0, $separatorPosition));
        if ($key === '') {
            continue;
        }

        $value = trim(substr($trimmed, $separatorPosition + 1));
        if ($value !== '') {
            $quoteChar = $value[0];
            if (($quoteChar === '"' || $quoteChar === "'") && substr($value, -1) === $quoteChar) {
                $value = substr($value, 1, -1);
            }
        }

        if (getenv($key) !== false || array_key_exists($key, $_ENV)) {
            continue;
        }

        putenv($key . '=' . $value);
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }
})();
