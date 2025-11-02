<?php

declare(strict_types=1);

require_once __DIR__ . '/Auth.php';

final class UserScope
{
    private const DEFAULT_KEY = 'public';
    private const QUERY_PARAM = 'user_scope';

    private string $storageKey;
    private bool $isDefault;

    private function __construct(string $storageKey, bool $isDefault)
    {
        $this->storageKey = $storageKey;
        $this->isDefault = $isDefault;
    }

    public static function default(): self
    {
        return new self(self::DEFAULT_KEY, true);
    }

    public static function fromRequest(): self
    {
        Auth::ensureSession();
        $user = Auth::currentUser();
        if ($user && isset($user['scope_key'])) {
            $key = self::sanitizeStorageKey((string) $user['scope_key']);
            if ($key !== '') {
                return new self($key, false);
            }
        }

        if (isset($_GET[self::QUERY_PARAM])) {
            $candidate = self::sanitizeStorageKey((string) $_GET[self::QUERY_PARAM]);
            if ($candidate !== '' && $candidate !== self::DEFAULT_KEY) {
                return new self($candidate, false);
            }
        }

        return self::default();
    }

    public static function fromStorageKey(string $key): self
    {
        $sanitized = self::sanitizeStorageKey($key);
        if ($sanitized === '' || $sanitized === self::DEFAULT_KEY) {
            return self::default();
        }

        return new self($sanitized, false);
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
        Auth::ensureSession();
    }

    private static function sanitizeStorageKey(string $value): string
    {
        $normalized = strtolower(trim($value));
        $normalized = preg_replace('/[^a-z0-9]+/', '', $normalized ?? '');
        return $normalized ?? '';
    }
}
