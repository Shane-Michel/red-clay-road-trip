<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';
require_once dirname(__DIR__) . '/src/lib/TripRepository.php';
require_once dirname(__DIR__) . '/src/lib/ShareToken.php';

TripRepository::initialize();

$tripId = isset($_GET['trip']) ? (int) $_GET['trip'] : 0;
$expires = isset($_GET['expires']) ? (int) $_GET['expires'] : 0;
$signature = isset($_GET['sig']) ? (string) $_GET['sig'] : '';

$valid = $tripId > 0 && $expires > 0 && $signature !== '' && ShareToken::validate($tripId, $expires, $signature);

if (!$valid) {
    http_response_code(403);
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>Trip share expired</title><link rel="stylesheet" href="assets/css/style.css"></head><body><main class="container"><section class="results"><h1>Share link unavailable</h1><p>The requested trip could not be loaded. The link may have expired or been revoked.</p></section></main></body></html>';
    exit;
}

$trip = TripRepository::getTrip($tripId);
if (!$trip) {
    http_response_code(404);
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>Trip not found</title><link rel="stylesheet" href="assets/css/style.css"></head><body><main class="container"><section class="results"><h1>Trip not found</h1><p>The requested trip no longer exists.</p></section></main></body></html>';
    exit;
}

$trip = normalizeTrip($trip);
$expiresAt = (new \DateTimeImmutable('@' . $expires))->setTimezone(new \DateTimeZone('UTC'))->format(\DateTimeInterface::ATOM);

function escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function normalizeTrip(array $trip): array
{
    $defaults = [
        'route_overview' => '',
        'total_travel_time' => '',
        'summary' => '',
        'additional_tips' => '',
        'start_location' => '',
        'departure_datetime' => '',
        'city_of_interest' => '',
        'traveler_preferences' => '',
        'stops' => [],
    ];
    $trip = array_merge($defaults, $trip);
    $trip['stops'] = array_map(static function ($stop): array {
        $stopDefaults = [
            'title' => '',
            'address' => '',
            'duration' => '',
            'description' => '',
            'historical_note' => '',
            'challenge' => '',
        ];
        return array_merge($stopDefaults, is_array($stop) ? $stop : []);
    }, $trip['stops']);

    return $trip;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Shared Road Trip Itinerary</title>
    <link rel="stylesheet" href="assets/css/style.css" />
</head>
<body>
    <main class="container">
        <section class="results">
            <div class="results__header">
                <h1>Shared Road Trip</h1>
                <p class="results__share-meta">Link valid until <time datetime="<?= escape($expiresAt) ?>"><?= escape((new \DateTimeImmutable($expiresAt))->format('F j, Y g:i A T')) ?></time></p>
            </div>
            <article class="itinerary-intro">
                <p><strong>Route:</strong> <?= escape($trip['route_overview'] ?: '—') ?></p>
                <p><strong>Total Travel Time:</strong> <?= escape($trip['total_travel_time'] ?: '—') ?></p>
                <p><strong>Departure:</strong> <?= escape($trip['departure_datetime'] ?: '—') ?></p>
                <p><strong>Starting From:</strong> <?= escape($trip['start_location'] ?: '—') ?></p>
                <p><strong>City of Interest:</strong> <?= escape($trip['city_of_interest'] ?: '—') ?></p>
            </article>

            <?php if ($trip['summary']): ?>
                <p><strong>Summary:</strong> <?= escape($trip['summary']) ?></p>
            <?php endif; ?>

            <?php foreach ($trip['stops'] as $index => $stop): ?>
                <article class="itinerary-stop">
                    <h2>Stop <?= $index + 1 ?>: <?= escape($stop['title'] ?: 'Untitled stop') ?></h2>
                    <p class="itinerary-stop__meta"><?= escape($stop['address'] ?: '—') ?> &bull; Suggested time: <?= escape($stop['duration'] ?: '—') ?></p>
                    <?php if ($stop['description']): ?>
                        <p><?= nl2br(escape($stop['description'])) ?></p>
                    <?php endif; ?>
                    <?php if ($stop['historical_note']): ?>
                        <p><strong>Historical Significance:</strong> <?= nl2br(escape($stop['historical_note'])) ?></p>
                    <?php endif; ?>
                    <?php if ($stop['challenge']): ?>
                        <p><strong>Challenge:</strong> <?= nl2br(escape($stop['challenge'])) ?></p>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>

            <?php if ($trip['additional_tips']): ?>
                <div class="itinerary-tips">
                    <h2>Travel Tips</h2>
                    <p><?= nl2br(escape($trip['additional_tips'])) ?></p>
                </div>
            <?php endif; ?>
        </section>
    </main>
</body>
</html>
