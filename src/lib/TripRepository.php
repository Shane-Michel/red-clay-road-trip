<?php

declare(strict_types=1);

require_once __DIR__ . '/Logger.php';
require_once __DIR__ . '/UserScope.php';

class TripRepository
{
    private const BASE_DB_DIR = __DIR__ . '/../../data';
    private const DEFAULT_DB_FILENAME = 'trips.sqlite';

    /** @var array<string, \PDO> */
    private static array $connections = [];

    /** @var array<string, bool> */
    private static array $initialized = [];

    public static function initialize(?UserScope $scope = null): void
    {
        $scope = $scope ?? UserScope::fromRequest();
        $path = self::resolveDatabasePath($scope);
        if (isset(self::$initialized[$path])) {
            return;
        }

        try {
            $db = self::getConnection($scope);
            $sql = <<<'SQL'
CREATE TABLE IF NOT EXISTS trips (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    start_location TEXT NOT NULL,
    departure_datetime TEXT NOT NULL,
    city_of_interest TEXT NOT NULL,
    itinerary_json TEXT NOT NULL,
    summary TEXT NOT NULL,
    created_at TEXT NOT NULL
)
SQL;

            $db->exec($sql);
            self::$initialized[$path] = true;
        } catch (\Throwable $exception) {
            Logger::logThrowable($exception, [
                'repository_method' => __METHOD__,
                'scope' => $scope->storageKey(),
            ]);
            throw $exception;
        }
    }

    public static function saveTrip(UserScope $scope, array $itinerary): int
    {
        $normalized = $itinerary;
        try {
            $normalized = self::prepareForPersistence($itinerary);
            self::initialize($scope);
            $db = self::getConnection($scope);
            $sql = <<<'SQL'
INSERT INTO trips (
    start_location,
    departure_datetime,
    city_of_interest,
    itinerary_json,
    summary,
    created_at
)
VALUES (
    :start_location,
    :departure_datetime,
    :city_of_interest,
    :itinerary_json,
    :summary,
    :created_at
)
SQL;

            $stmt = $db->prepare($sql);

            $stmt->execute([
                ':start_location' => $normalized['start_location'],
                ':departure_datetime' => $normalized['departure_datetime'],
                ':city_of_interest' => $normalized['city_of_interest'],
                ':itinerary_json' => json_encode($normalized, JSON_THROW_ON_ERROR),
                ':summary' => $normalized['summary'] ?? substr($normalized['route_overview'] ?? '', 0, 200),
                ':created_at' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM),
            ]);

            return (int) $db->lastInsertId();
        } catch (\Throwable $exception) {
            Logger::logThrowable($exception, [
                'repository_method' => __METHOD__,
                'scope' => $scope->storageKey(),
                'itinerary_context' => [
                    'start_location' => $normalized['start_location'] ?? null,
                    'departure_datetime' => $normalized['departure_datetime'] ?? null,
                    'city_of_interest' => $normalized['city_of_interest'] ?? null,
                    'cities_of_interest' => $normalized['cities_of_interest'] ?? null,
                ],
            ]);
            throw $exception;
        }
    }

    public static function updateTrip(UserScope $scope, int $id, array $itinerary): bool
    {
        $normalized = $itinerary;
        try {
            $normalized = self::prepareForPersistence($itinerary);
            self::initialize($scope);
            $db = self::getConnection($scope);
            $sql = <<<'SQL'
UPDATE trips
SET
    start_location = :start_location,
    departure_datetime = :departure_datetime,
    city_of_interest = :city_of_interest,
    itinerary_json = :itinerary_json,
    summary = :summary
WHERE id = :id
SQL;

            $stmt = $db->prepare($sql);

            return $stmt->execute([
                ':start_location' => $normalized['start_location'],
                ':departure_datetime' => $normalized['departure_datetime'],
                ':city_of_interest' => $normalized['city_of_interest'],
                ':itinerary_json' => json_encode($normalized, JSON_THROW_ON_ERROR),
                ':summary' => $normalized['summary'] ?? substr($normalized['route_overview'] ?? '', 0, 200),
                ':id' => $id,
            ]);
        } catch (\Throwable $exception) {
            Logger::logThrowable($exception, [
                'repository_method' => __METHOD__,
                'scope' => $scope->storageKey(),
                'trip_id' => $id,
                'cities_of_interest' => $normalized['cities_of_interest'] ?? null,
            ]);
            throw $exception;
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function listTrips(UserScope $scope): array
    {
        try {
            self::initialize($scope);
            $db = self::getConnection($scope);
            $sql = <<<'SQL'
SELECT
    id,
    start_location,
    departure_datetime,
    city_of_interest,
    summary,
    created_at,
    itinerary_json
FROM trips
ORDER BY created_at DESC
LIMIT 12
SQL;

            $stmt = $db->query($sql);
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            return array_map(static function (array $row): array {
                $row['created_at'] = (new \DateTimeImmutable($row['created_at']))->setTimezone(new \DateTimeZone('UTC'))->format(\DateTimeInterface::ATOM);
                $row['cities_of_interest'] = [];
                if (!empty($row['itinerary_json'])) {
                    try {
                        $decoded = json_decode($row['itinerary_json'], true, 512, JSON_THROW_ON_ERROR);
                        if (isset($decoded['cities_of_interest']) && is_array($decoded['cities_of_interest'])) {
                            $row['cities_of_interest'] = array_values(array_filter(array_map(static function ($city) {
                                $city = trim((string) $city);
                                return $city === '' ? null : $city;
                            }, $decoded['cities_of_interest'])));
                            if ($row['cities_of_interest']) {
                                $row['city_of_interest'] = implode(' • ', $row['cities_of_interest']);
                            }
                        }
                    } catch (\Throwable $e) {
                        // If decoding fails, we keep defaults but still log for observability.
                        Logger::logThrowable($e, [
                            'repository_method' => __METHOD__,
                            'stage' => 'decode_itinerary_preview',
                            'trip_id' => $row['id'] ?? null,
                        ]);
                    }
                }
                unset($row['itinerary_json']);
                return $row;
            }, $rows ?: []);
        } catch (\Throwable $exception) {
            Logger::logThrowable($exception, [
                'repository_method' => __METHOD__,
                'scope' => $scope->storageKey(),
            ]);
            throw $exception;
        }
    }

    public static function getTrip(UserScope $scope, int $id): ?array
    {
        try {
            self::initialize($scope);
            $db = self::getConnection($scope);
            $stmt = $db->prepare('SELECT itinerary_json FROM trips WHERE id = :id');
            $stmt->execute([':id' => $id]);
            $data = $stmt->fetch(\PDO::FETCH_ASSOC);
            if (!$data) {
                return null;
            }

            $decoded = json_decode($data['itinerary_json'], true, 512, JSON_THROW_ON_ERROR);
            if (is_array($decoded)) {
                $decoded['id'] = $id;
            }

            return $decoded;
        } catch (\Throwable $exception) {
            Logger::logThrowable($exception, [
                'repository_method' => __METHOD__,
                'scope' => $scope->storageKey(),
                'trip_id' => $id,
            ]);
            throw $exception;
        }
    }

    public static function tripExists(UserScope $scope, int $id): bool
    {
        try {
            self::initialize($scope);
            $db = self::getConnection($scope);
            $stmt = $db->prepare('SELECT 1 FROM trips WHERE id = :id');
            $stmt->execute([':id' => $id]);
            return (bool) $stmt->fetchColumn();
        } catch (\Throwable $exception) {
            Logger::logThrowable($exception, [
                'repository_method' => __METHOD__,
                'scope' => $scope->storageKey(),
                'trip_id' => $id,
            ]);
            throw $exception;
        }
    }

    private static function getConnection(UserScope $scope): \PDO
    {
        $path = self::resolveDatabasePath($scope);
        if (isset(self::$connections[$path]) && self::$connections[$path] instanceof \PDO) {
            return self::$connections[$path];
        }

        try {
            $dbDirectory = dirname($path);
            if (!is_dir($dbDirectory)) {
                if (!mkdir($dbDirectory, 0775, true) && !is_dir($dbDirectory)) {
                    throw new \RuntimeException('Unable to create database directory: ' . $dbDirectory);
                }
            }

            $pdo = new \PDO('sqlite:' . $path);
            $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);

            self::$connections[$path] = $pdo;

            return $pdo;
        } catch (\Throwable $exception) {
            Logger::logThrowable($exception, [
                'repository_method' => __METHOD__,
                'scope' => $scope->storageKey(),
            ]);
            throw $exception;
        }
    }

    private static function resolveDatabasePath(UserScope $scope): string
    {
        if ($scope->isDefault()) {
            return self::BASE_DB_DIR . '/' . self::DEFAULT_DB_FILENAME;
        }

        return self::BASE_DB_DIR . '/users/' . $scope->storageKey() . '.sqlite';
    }

    private static function prepareForPersistence(array $itinerary): array
    {
        $cities = [];
        if (isset($itinerary['cities_of_interest']) && is_array($itinerary['cities_of_interest'])) {
            foreach ($itinerary['cities_of_interest'] as $city) {
                $city = trim((string) $city);
                if ($city !== '') {
                    $cities[] = $city;
                }
            }
        }

        $itinerary['cities_of_interest'] = $cities;
        $primaryCity = isset($itinerary['city_of_interest']) ? trim((string) $itinerary['city_of_interest']) : '';
        if ($primaryCity === '' && $cities) {
            $primaryCity = $cities[0];
        }
        $itinerary['city_of_interest'] = $primaryCity;

        return $itinerary;
    }
}
