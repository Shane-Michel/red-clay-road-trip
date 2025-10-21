<?php

declare(strict_types=1);

require_once __DIR__ . '/UserScope.php';

final class ShareToken
{
    private const DEFAULT_TTL = 'P14D';
    private const SECRET_ENV = 'SHARE_SECRET';

    public static function create(int $tripId, ?string $ttl = null, ?UserScope $scope = null): array
    {
        $scope = $scope ?? UserScope::fromRequest();
        $expires = self::calculateExpiry($ttl);
        $signature = self::sign($tripId, $expires, $scope->storageKey());

        return [
            'trip_id' => $tripId,
            'expires' => $expires->getTimestamp(),
            'signature' => $signature,
            'scope' => $scope->storageKey(),
        ];
    }

    public static function validate(int $tripId, int $expires, string $signature, ?string $storageKey = null): bool
    {
        if ($expires <= 0) {
            return false;
        }

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        if ($expires < $now->getTimestamp()) {
            return false;
        }

        $scope = $storageKey ? UserScope::fromStorageKey($storageKey) : UserScope::default();
        $expected = self::sign(
            $tripId,
            (new \DateTimeImmutable('@' . $expires))->setTimezone(new \DateTimeZone('UTC')),
            $scope->storageKey()
        );
        return hash_equals($expected, $signature);
    }

    public static function buildRelativeUrl(array $token): string
    {
        $base = sprintf(
            '/share.php?trip=%d&expires=%d&sig=%s',
            $token['trip_id'],
            $token['expires'],
            rawurlencode($token['signature'])
        );

        $scope = isset($token['scope']) ? (string) $token['scope'] : '';
        if ($scope !== '' && $scope !== UserScope::default()->storageKey()) {
            $base .= '&scope=' . rawurlencode($scope);
        }

        return $base;
    }

    private static function calculateExpiry(?string $ttl): \DateTimeImmutable
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        if ($ttl) {
            try {
                $interval = new \DateInterval($ttl);
                return $now->add($interval);
            } catch (\Exception $exception) {
                // fall back to default
            }
        }

        $interval = new \DateInterval(self::DEFAULT_TTL);
        return $now->add($interval);
    }

    private static function sign(int $tripId, \DateTimeImmutable $expires, string $scopeKey): string
    {
        $payload = $tripId . '|' . $expires->getTimestamp() . '|' . $scopeKey;
        $secret = self::getSecret();
        $hash = hash_hmac('sha256', $payload, $secret, true);
        return rtrim(strtr(base64_encode($hash), '+/', '-_'), '=');
    }

    private static function getSecret(): string
    {
        $secret = getenv(self::SECRET_ENV);
        if ($secret && is_string($secret) && $secret !== '') {
            return $secret;
        }

        $fallback = getenv('APP_SECRET') ?: getenv('APP_KEY') ?: '';
        if ($fallback !== '') {
            return $fallback;
        }

        return 'redclay-share-secret';
    }
}
