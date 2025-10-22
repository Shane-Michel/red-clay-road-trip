<?php

declare(strict_types=1);

final class NominatimGeocoder
{
    private const SEARCH_ENDPOINT = 'https://nominatim.openstreetmap.org/search';
    private const REVERSE_ENDPOINT = 'https://nominatim.openstreetmap.org/reverse';
    private const DEFAULT_USER_AGENT = 'RedClayRoadTrip/1.0 (+https://redclayroadtrip.s-sites.com)';
    private const TIMEOUT_SECONDS = 10;

    /**
     * @return array{lat: float, lon: float, display_name: string}|null
     */
    public static function geocode(string $query): ?array
    {
        $trimmed = trim($query);
        if ($trimmed === '') {
            return null;
        }

        $url = self::SEARCH_ENDPOINT . '?' . http_build_query([
            'format' => 'json',
            'limit' => 1,
            'q' => $trimmed,
        ], '', '&', PHP_QUERY_RFC3986);

        $decoded = self::requestJson($url);
        if (count($decoded) === 0) {
            return null;
        }

        $match = $decoded[0];
        if (!is_array($match) || !isset($match['lat'], $match['lon'])) {
            return null;
        }

        if (!is_numeric($match['lat']) || !is_numeric($match['lon'])) {
            return null;
        }

        $displayName = isset($match['display_name']) && is_string($match['display_name'])
            ? trim($match['display_name'])
            : $trimmed;

        return [
            'lat' => (float) $match['lat'],
            'lon' => (float) $match['lon'],
            'display_name' => $displayName !== '' ? $displayName : $trimmed,
        ];
    }

    /**
     * @return array{lat: float, lon: float, display_name: string}|null
     */
    public static function reverse(float $latitude, float $longitude): ?array
    {
        if (!is_finite($latitude) || !is_finite($longitude)) {
            return null;
        }

        $url = self::REVERSE_ENDPOINT . '?' . http_build_query([
            'format' => 'json',
            'lat' => $latitude,
            'lon' => $longitude,
            'zoom' => 14,
            'addressdetails' => 1,
        ], '', '&', PHP_QUERY_RFC3986);

        $decoded = self::requestJson($url);

        if (!isset($decoded['lat'], $decoded['lon'])) {
            return null;
        }

        if (!is_numeric($decoded['lat']) || !is_numeric($decoded['lon'])) {
            return null;
        }

        $displayName = isset($decoded['display_name']) && is_string($decoded['display_name'])
            ? trim($decoded['display_name'])
            : '';

        if ($displayName === '' && isset($decoded['address']) && is_array($decoded['address'])) {
            $displayName = implode(', ', array_filter(array_map(
                static fn ($value) => is_string($value) ? trim($value) : '',
                $decoded['address']
            )));
        }

        if ($displayName === '') {
            $displayName = sprintf('%.5f, %.5f', $latitude, $longitude);
        }

        return [
            'lat' => (float) $decoded['lat'],
            'lon' => (float) $decoded['lon'],
            'display_name' => $displayName,
        ];
    }

    private static function resolveUserAgent(): string
    {
        $configured = getenv('NOMINATIM_USER_AGENT');
        $agent = is_string($configured) ? trim($configured) : '';
        return $agent !== '' ? $agent : self::DEFAULT_USER_AGENT;
    }

    /**
     * @return array<mixed>
     */
    private static function requestJson(string $url): array
    {
        $handle = curl_init($url);
        if ($handle === false) {
            throw new \RuntimeException('Unable to initialize geocoding request.');
        }

        $headers = [
            'Accept: application/json',
            'Accept-Language: en',
        ];

        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::TIMEOUT_SECONDS,
            CURLOPT_CONNECTTIMEOUT => self::TIMEOUT_SECONDS,
            CURLOPT_USERAGENT => self::resolveUserAgent(),
            CURLOPT_HTTPHEADER => $headers,
        ]);

        $responseBody = curl_exec($handle);
        if ($responseBody === false) {
            $errorMessage = curl_error($handle);
            curl_close($handle);
            throw new \RuntimeException(
                'Failed to contact geocoding service' . ($errorMessage !== '' ? (': ' . $errorMessage) : '.')
            );
        }

        $statusCode = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        curl_close($handle);

        if ($statusCode >= 500) {
            throw new \RuntimeException('Geocoding service temporarily unavailable (HTTP ' . $statusCode . ').');
        }

        if ($statusCode !== 200) {
            throw new \RuntimeException('Geocoding request failed with HTTP ' . $statusCode . '.');
        }

        $decoded = json_decode($responseBody, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('Unexpected response from geocoding service.');
        }

        return $decoded;
    }
}
