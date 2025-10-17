<?php

class TripRepository
{
    private const DB_PATH = __DIR__ . '/../data/trips.sqlite';

    public static function initialize(): void
    {
        $db = self::getConnection();
        $db->exec(
            'CREATE TABLE IF NOT EXISTS trips (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                start_location TEXT NOT NULL,
                departure_datetime TEXT NOT NULL,
                city_of_interest TEXT NOT NULL,
                itinerary_json TEXT NOT NULL,
                summary TEXT NOT NULL,
                created_at TEXT NOT NULL
            )'
        );
    }

    public static function saveTrip(array $itinerary): int
    {
        $db = self::getConnection();
        $stmt = $db->prepare(
            'INSERT INTO trips (start_location, departure_datetime, city_of_interest, itinerary_json, summary, created_at)
             VALUES (:start_location, :departure_datetime, :city_of_interest, :itinerary_json, :summary, :created_at)'
        );

        $stmt->execute([
            ':start_location' => $itinerary['start_location'],
            ':departure_datetime' => $itinerary['departure_datetime'],
            ':city_of_interest' => $itinerary['city_of_interest'],
            ':itinerary_json' => json_encode($itinerary, JSON_THROW_ON_ERROR),
            ':summary' => $itinerary['summary'] ?? substr($itinerary['route_overview'] ?? '', 0, 200),
            ':created_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM),
        ]);

        return (int) $db->lastInsertId();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function listTrips(): array
    {
        $db = self::getConnection();
        $stmt = $db->query('SELECT id, start_location, departure_datetime, city_of_interest, summary, created_at FROM trips ORDER BY created_at DESC LIMIT 12');
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return array_map(static function (array $row): array {
            $row['created_at'] = (new DateTimeImmutable($row['created_at']))->setTimezone(new DateTimeZone('UTC'))->format(DateTimeInterface::ATOM);
            return $row;
        }, $rows ?: []);
    }

    public static function getTrip(int $id): ?array
    {
        $db = self::getConnection();
        $stmt = $db->prepare('SELECT itinerary_json FROM trips WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $data = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$data) {
            return null;
        }

        return json_decode($data['itinerary_json'], true, 512, JSON_THROW_ON_ERROR);
    }

    private static function getConnection(): PDO
    {
        static $pdo = null;
        if ($pdo instanceof PDO) {
            return $pdo;
        }

        $pdo = new PDO('sqlite:' . self::DB_PATH);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        return $pdo;
    }
}
