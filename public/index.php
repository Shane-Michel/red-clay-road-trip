<?php
$rootPath = dirname(__DIR__);
require_once $rootPath . '/src/bootstrap.php';
require_once $rootPath . '/src/lib/OpenAIClient.php';
require_once $rootPath . '/src/lib/TripRepository.php';

// Ensure database is initialized
TripRepository::initialize();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Red Clay Road Trip</title>
    <link rel="stylesheet" href="assets/css/style.css" />
</head>
<body>
    <header class="hero">
        <div class="hero__overlay"></div>
        <div class="hero__content container">
            <h1>Red Clay Road Trip</h1>
            <p>Design a historical scavenger hunt and uncover stories hidden along the road.</p>
        </div>
    </header>
    <main class="container">
        <section class="planner" aria-labelledby="planner-title">
            <div class="planner__intro">
                <h2 id="planner-title">Plan Your Historical Adventure</h2>
                <p>Enter your starting point, when you&apos;re traveling, and the city you want to explore. Our AI historian will craft a road trip filled with intriguing stops and history-rich discoveries.</p>
            </div>
            <form id="trip-form" class="planner__form" autocomplete="off">
                <div class="field">
                    <label for="start_location">Start Location</label>
                    <input type="text" id="start_location" name="start_location" placeholder="e.g., 123 Main St, Atlanta, GA" required />
                </div>
                <div class="field">
                    <label for="departure_datetime">Departure Date &amp; Time</label>
                    <input type="datetime-local" id="departure_datetime" name="departure_datetime" required />
                </div>
                <div class="field">
                    <label for="city_of_interest">City of Interest</label>
                    <input type="text" id="city_of_interest" name="city_of_interest" placeholder="e.g., Savannah, GA" required />
                </div>
                <div class="field field--textarea">
                    <label for="traveler_preferences">Traveler Preferences <span class="optional">(optional)</span></label>
                    <textarea id="traveler_preferences" name="traveler_preferences" placeholder="Share accessibility needs, interests, or travel companions."></textarea>
                </div>
                <button type="submit" class="btn">Generate Road Trip</button>
            </form>
        </section>

        <section id="results" class="results" aria-live="polite" hidden>
            <div class="results__header">
                <h2>Your Personalized Itinerary</h2>
                <button id="save-trip" class="btn btn--secondary" type="button">Save Trip</button>
            </div>
            <div id="itinerary" class="results__content"></div>
        </section>

        <section class="history" aria-labelledby="history-title">
            <h2 id="history-title">Recent Adventures</h2>
            <p>Revisit past journeys or gain inspiration from recent requests.</p>
            <div id="history-list" class="history__list" role="list"></div>
        </section>
    </main>

    <template id="history-item-template">
        <article class="history__item" role="listitem">
            <header>
                <h3></h3>
                <p class="history__meta"></p>
            </header>
            <p class="history__summary"></p>
            <button class="btn btn--ghost" data-action="load">Load Itinerary</button>
        </article>
    </template>

    <script src="assets/js/app.js" defer></script>
</body>
</html>
