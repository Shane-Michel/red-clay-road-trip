# Red Clay Road Trip

Red Clay Road Trip is a single-page web experience that builds an AI-assisted historical scavenger hunt. Enter your starting point, departure date, and the city you want to explore; the application will request a tailored itinerary from OpenAI and allow you to save and revisit trips using SQLite.

## Features

- **AI-generated itineraries** powered by the OpenAI Responses API.
- **Rich client experience** with a responsive layout, dark-mode support, and dynamic rendering.
- **Trip history** stored locally in SQLite so returning visitors can revisit or reload previous adventures.

## Getting started

### Requirements

- PHP 7.4.33 with the `curl`, `json`, and `sqlite3` extensions enabled
- Composer (optional, not required)
- An OpenAI API key with access to the `responses` endpoint

### Installation

1. Clone the repository and install dependencies (if any).
2. Copy `.env.example` (if you create one) or export your API key directly before starting the server:

   ```bash
   export OPENAI_API_KEY="sk-..."
   ```

3. Start PHP&apos;s development server from the project root:

   ```bash
   php -S localhost:8080
   ```

4. Visit `http://localhost:8080` in your browser.

The first request will automatically create the SQLite database at `data/trips.sqlite`.

## API endpoints

- `POST /api/generate_trip.php`
  - Accepts form-data fields (`start_location`, `departure_datetime`, `city_of_interest`, and optional `traveler_preferences`).
  - Calls OpenAI to generate a JSON itinerary structure.
- `POST /api/save_trip.php`
  - Accepts a JSON itinerary from the front-end and stores it in SQLite.
- `GET /api/list_trips.php`
  - Returns a list of recent trips for display in the history panel.
- `GET /api/get_trip.php?id=123`
  - Returns the full itinerary for a saved trip.

## Environment variables

| Variable         | Description                                  |
| ---------------- | -------------------------------------------- |
| `OPENAI_API_KEY` | Required. Used to authenticate with OpenAI. |

## Development notes

- The OpenAI call requests a JSON schema ensuring the response matches what the UI expects.
- All dates are stored in UTC ISO-8601 format for consistency.
- Front-end code is located in `assets/js/app.js` and `assets/css/style.css`.

## License

This project is provided as-is for demonstration purposes.
