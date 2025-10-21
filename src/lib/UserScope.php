<?php

declare(strict_types=1);

final class UserScope
{
    private const DEFAULT_KEY = 'public';
    private const HEADER_NAME = 'x-user-scope';
    private const COOKIE_NAME = 'user_scope';
    private const QUERY_PARAM = 'user_scope';
    private const COOKIE_TTL = 31536000; // 1 year

    private string $raw;
    private string $storageKey;
    private bool $isDefault;

    private function __construct(string $raw, string $storageKey, bool $isDefault)
    {
        $this->raw = $raw;
        $this->storageKey = $storageKey;
        $this->isDefault = $isDefault;
    }

    public static function default(): self
    {
        return new self(self::DEFAULT_KEY, self::DEFAULT_KEY, true);
    }

    public static function fromRequest(): self
    {
        $candidates = [];

        $headers = self::collectHeaders();
        if (isset($headers[self::HEADER_NAME])) {
            $candidates[] = $headers[self::HEADER_NAME];
        }

        if (isset($_GET[self::QUERY_PARAM])) {
            $candidates[] = (string) $_GET[self::QUERY_PARAM];
        }

        if (isset($_COOKIE[self::COOKIE_NAME])) {
            $candidates[] = (string) $_COOKIE[self::COOKIE_NAME];
        }

        foreach ($candidates as $candidate) {
            $scope = self::fromRaw($candidate);
            if (!$scope->isDefault() || trim((string) $candidate) !== '') {
                return $scope;
            }
        }

        return self::default();
    }

    public static function fromRaw(string $raw): self
    {
        $trimmed = trim($raw);
        if ($trimmed === '') {
            return self::default();
        }

        $storageKey = self::hashScope($trimmed);
        return new self($trimmed, $storageKey, false);
    }

    public static function fromStorageKey(string $key): self
    {
        $sanitized = self::sanitizeStorageKey($key);
        if ($sanitized === '' || $sanitized === self::DEFAULT_KEY) {
            return self::default();
        }

        return new self($sanitized, $sanitized, false);
    }

    public function raw(): string
    {
        return $this->raw;
    }

    public function storageKey(): string
    {
        return $this->storageKey;
    }

    public function isDefault(): bool
    {
        return $this->isDefault;
    }

    public function persist(): void
    {
        if (headers_sent()) {
            return;
        }

        setcookie(self::COOKIE_NAME, $this->storageKey, [
            'expires' => time() + self::COOKIE_TTL,
            'path' => '/',
            'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on',
            'httponly' => false,
            'samesite' => 'Lax',
        ]);
    }

    private static function hashScope(string $value): string
    {
        return substr(hash('sha256', $value), 0, 40);
    }

    private static function sanitizeStorageKey(string $value): string
    {
        $normalized = strtolower(trim($value));
        $normalized = preg_replace('/[^a-z0-9]+/', '', $normalized ?? '');
        return $normalized ?? '';
    }

    private static function collectHeaders(): array
    {
        $headers = [];

        if (function_exists('getallheaders')) {
            $fetched = getallheaders();
            if (is_array($fetched)) {
                foreach ($fetched as $key => $value) {
                    $headers[strtolower((string) $key)] = (string) $value;
                }
            }
            return $headers;
        }

        foreach ($_SERVER as $key => $value) {
            if (strpos($key, 'HTTP_') !== 0) {
                continue;
            }

            $header = strtolower(str_replace('_', '-', substr($key, 5)));
            $headers[$header] = (string) $value;
        }

        return $headers;
    }
}
