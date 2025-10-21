<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';
require_once dirname(__DIR__) . '/src/lib/OpenAIClient.php';
require_once dirname(__DIR__) . '/src/lib/TripRepository.php';
require_once dirname(__DIR__) . '/src/lib/UserScope.php';

// Ensure database is initialized
$scope = UserScope::fromRequest();
$scope->persist();
TripRepository::initialize($scope);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Red Clay Road Trip</title>
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" crossorigin="" />
    <link rel="stylesheet" href="assets/css/style.css" />
</head>
<body>
    <header class="hero">
        <div class="hero__overlay"></div>
        <div class="hero__content container">
            <h1>Red Clay Road Trip</h1>
            <p>Design a road trip that blends stories, local legends, fun facts, and hidden bites along the way.</p>
        </div>
    </header>
    <main class="container">
        <section class="account" aria-labelledby="account-title">
            <div class="account__header">
                <h2 id="account-title">Traveler Account (optional)</h2>
                <p>Use a private sync key to keep your itineraries separate. Share the key with another device to view the same history.</p>
            </div>
            <div class="account__status" id="account-status" role="status" aria-live="polite"></div>
            <div class="account__controls">
                <div class="account__row">
                    <label for="account-key" class="account__label">Your sync key</label>
                    <div class="account__input-group">
                        <input type="text" id="account-key" autocomplete="off" inputmode="text" spellcheck="false" placeholder="Enter an existing key to sign in" />
                        <button type="button" id="account-apply" class="btn btn--secondary">Use key</button>
                    </div>
                </div>
                <div class="account__actions">
                    <button type="button" id="account-generate" class="btn">Generate new private key</button>
                    <button type="button" id="account-copy" class="btn btn--ghost" hidden>Copy key</button>
                    <button type="button" id="account-reset" class="btn btn--ghost">Use shared demo space</button>
                </div>
                <p class="account__hint">Keys never leave your browser except when you send requests with them. They are hashed on the server before storage so other travelers can't see your trips.</p>
            </div>
        </section>

        <section class="planner" aria-labelledby="planner-title">
            <div class="planner__intro">
                <h2 id="planner-title">Plan Your Story-Filled Adventure</h2>
                <p>Enter your starting point, when you're traveling, and the cities you want to explore. Our AI travel host will craft a road trip packed with fun facts, must-see spots, and hidden places to eat.</p>
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
                    <label for="cities-of-interest">Cities to Explore</label>
                    <div id="cities-of-interest" class="field__group">
                        <div class="field__repeatable" data-city-index="0">
                            <input type="text" name="cities_of_interest[]" placeholder="e.g., Savannah, GA" required />
                        </div>
                    </div>
                    <button type="button" id="add-city" class="btn btn--ghost field__action">Add Another City</button>
                    <p class="field__help">List every city or town you'd like to include. We'll weave them into one continuous route.</p>
                </div>
                <div class="field field--textarea">
                    <label for="traveler_preferences">Traveler Preferences <span class="optional">(optional)</span></label>
                    <textarea id="traveler_preferences" name="traveler_preferences" placeholder="Share accessibility needs, interests, travel companions, or must-try flavors."></textarea>
                </div>
                <button type="submit" class="btn">Generate Road Trip</button>
            </form>
        </section>

        <section id="results" class="results" aria-live="polite" hidden>
            <div class="results__header">
                <h2>Your Personalized Itinerary</h2>
                <div class="results__actions">
                    <button id="save-trip" class="btn btn--secondary" type="button">Save Trip</button>
                    <button id="edit-trip" class="btn btn--ghost" type="button">Edit Itinerary</button>
                    <button id="download-ics" class="btn btn--ghost" type="button">Download ICS</button>
                    <button id="download-pdf" class="btn btn--ghost" type="button">Download PDF</button>
                    <button id="share-trip" class="btn btn--ghost" type="button">Share Link</button>
                </div>
            </div>
            <div id="share-feedback" class="results__share" role="status" aria-live="polite" hidden></div>
            <div class="results__layout">
                <section class="results__map" aria-label="Map preview of your itinerary">
                    <div id="map" class="map" role="presentation"></div>
                    <p id="map-message" class="map__message" aria-live="polite">Map preview will appear once your itinerary is ready.</p>
                </section>
                <div class="results__content" role="list">
                    <div id="itinerary" role="presentation"></div>
                    <section id="itinerary-editor" class="editor" hidden aria-label="Edit itinerary stops">
                        <header class="editor__header">
                            <h3>Fine-tune your stops</h3>
                            <p>Update the details below, then apply changes to refresh the itinerary and map.</p>
                        </header>
                        <div id="editor-stops" class="editor__stops"></div>
                        <div class="editor__actions">
                            <button id="add-stop" class="btn btn--ghost" type="button">Add Stop</button>
                            <button id="apply-edits" class="btn" type="button" disabled>Apply edits</button>
                        </div>
                    </section>
                </div>
            </div>
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

    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" crossorigin="" defer></script>
    <script src="assets/js/app.js" defer></script>
</body>
</html>
