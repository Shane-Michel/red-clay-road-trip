<?php

declare(strict_types=1);

require_once __DIR__ . '/Logger.php';

class TripRepository
{
    private const DB_PATH = __DIR__ . '/../../data/trips.sqlite';

    public static function initialize(): void
    {
        try {
            $db = self::getConnection();
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
        } catch (\Throwable $exception) {
            Logger::logThrowable($exception, ['repository_method' => __METHOD__]);
            throw $exception;
        }
    }

    public static function saveTrip(array $itinerary): int
    {
        try {
            $db = self::getConnection();
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
                ':start_location' => $itinerary['start_location'],
                ':departure_datetime' => $itinerary['departure_datetime'],
                ':city_of_interest' => $itinerary['city_of_interest'],
                ':itinerary_json' => json_encode($itinerary, JSON_THROW_ON_ERROR),
                ':summary' => $itinerary['summary'] ?? substr($itinerary['route_overview'] ?? '', 0, 200),
                ':created_at' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM),
            ]);

            return (int) $db->lastInsertId();
        } catch (\Throwable $exception) {
            Logger::logThrowable($exception, [
                'repository_method' => __METHOD__,
                'itinerary_context' => [
                    'start_location' => $itinerary['start_location'] ?? null,
                    'departure_datetime' => $itinerary['departure_datetime'] ?? null,
                    'city_of_interest' => $itinerary['city_of_interest'] ?? null,
                ],
            ]);
            throw $exception;
        }
    }

    public static function updateTrip(int $id, array $itinerary): bool
    {
        try {
            $db = self::getConnection();
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
                ':start_location' => $itinerary['start_location'],
                ':departure_datetime' => $itinerary['departure_datetime'],
                ':city_of_interest' => $itinerary['city_of_interest'],
                ':itinerary_json' => json_encode($itinerary, JSON_THROW_ON_ERROR),
                ':summary' => $itinerary['summary'] ?? substr($itinerary['route_overview'] ?? '', 0, 200),
                ':id' => $id,
            ]);
        } catch (\Throwable $exception) {
            Logger::logThrowable($exception, [
                'repository_method' => __METHOD__,
                'trip_id' => $id,
            ]);
            throw $exception;
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function listTrips(): array
    {
        try {
            $db = self::getConnection();
            $sql = <<<'SQL'
SELECT
    id,
    start_location,
    departure_datetime,
    city_of_interest,
    summary,
    created_at
FROM trips
ORDER BY created_at DESC
LIMIT 12
SQL;

            $stmt = $db->query($sql);
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            return array_map(static function (array $row): array {
                $row['created_at'] = (new \DateTimeImmutable($row['created_at']))->setTimezone(new \DateTimeZone('UTC'))->format(\DateTimeInterface::ATOM);
                return $row;
            }, $rows ?: []);
        } catch (\Throwable $exception) {
            Logger::logThrowable($exception, ['repository_method' => __METHOD__]);
            throw $exception;
        }
    }

    public static function getTrip(int $id): ?array
    {
        try {
            $db = self::getConnection();
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
                'trip_id' => $id,
            ]);
            throw $exception;
        }
    }

    public static function tripExists(int $id): bool
    {
        try {
            $db = self::getConnection();
            $stmt = $db->prepare('SELECT 1 FROM trips WHERE id = :id');
            $stmt->execute([':id' => $id]);
            return (bool) $stmt->fetchColumn();
        } catch (\Throwable $exception) {
            Logger::logThrowable($exception, [
                'repository_method' => __METHOD__,
                'trip_id' => $id,
            ]);
            throw $exception;
        }
    }

    private static function getConnection(): \PDO
    {
        static $pdo = null;
        if ($pdo instanceof \PDO) {
            return $pdo;
        }

        try {
            $dbDirectory = dirname(self::DB_PATH);
            if (!is_dir($dbDirectory)) {
                if (!mkdir($dbDirectory, 0775, true) && !is_dir($dbDirectory)) {
                    throw new \RuntimeException('Unable to create database directory: ' . $dbDirectory);
                }
            }

            $pdo = new \PDO('sqlite:' . self::DB_PATH);
            $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);

            return $pdo;
        } catch (\Throwable $exception) {
            Logger::logThrowable($exception, ['repository_method' => __METHOD__]);
            throw $exception;
        }
    }
}
