# Data-Grounded Itinerary Pipeline

This document outlines the recommended orchestration flow for generating itineraries without allowing the language model to invent or infer unsupported facts. The goal is to treat external data sources as the single source of truth, while the LLM focuses purely on interpretation and narrative synthesis.

## 1. Normalize user intent
1. Validate the raw request and trim whitespace.
2. Parse out the canonical fields:
   - Start location
   - Date/time window
   - Trip length (derive from inputs when omitted)
   - Interests and desired pace
   - Budget and mobility constraints
3. Geocode each location, snap the route to roads when routing is available, and construct a timezone-aware trip window.
4. Break the full trip into legs with estimated ETAs per stop.

## 2. Controlled preference expansion
1. Run a **lightweight LLM pass** (optional but recommended) that converts fuzzy interests into typed filters.
2. The LLM must return strictly typed JSON that maps preferences to categories, radiuses, and modifiers such as `kidFriendly`, `mobilityFriendly`, or `outdoorOnly`.
3. The resulting JSON becomes the search plan that downstream data fetchers consume.

## 3. Factual data acquisition (parallelized)
1. Query factual APIs in parallel using the structured search plan:
   - **TripAdvisor** for points of interest, including ratings, price level, contact info, and hours.
   - **Wikipedia/Wikidata** for summaries, coordinates, and freshness timestamps.
   - **OpenWeather** for forecasts keyed to arrival windows.
2. Ensure routing results are available before calling the weather API so that ETAs align with the forecast windows.

## 4. Entity resolution & filtering
1. Deduplicate the raw candidates by combining name similarity with spatial distance thresholds.
2. Drop options that lack coordinates, have insufficient ratings, or expose outdated data.
3. Compare expected arrival times with business hours to flag "open on arrival" and "peak vs off-peak".
4. Cross-check forecast data to mark weather-sensitive activities as risky or safe.

## 5. Scoring & selection
1. Score every remaining candidate using weighted factors such as:
   - Preference match
   - Rating & review count
   - Proximity to the route or hub
   - Open-on-arrival likelihood
   - Weather resilience
   - Diversity penalty to avoid repetitive picks
2. Pick one to three top options per slot and always store at least one fallback option.

## 6. Final LLM synthesis (narrative only)
1. Provide the LLM with the exact set of selected entities, including names, links, hours, ETAs, weather notes, and safety/parking details.
2. Enforce a strict response schema that returns structured JSON only.
3. Instruct the model explicitly: **"Do not invent facts; only use the supplied fields."**
4. Ask for a day-by-day schedule, transitions between stops, concise blurbs, and weather-aware alternates.

## 7. Post-synthesis validation
1. Verify that the synthesized JSON references only approved entity IDs.
2. Re-check business hours against scheduled times; if conflicts appear, swap in the fallback entry and re-run the synthesis step.
3. Confirm that the output stays within the trip window and that no time overlaps occur.

## 8. Delivery & resilience
1. Assemble the final payload with canonical links (TripAdvisor, Wikipedia) and attach weather icons or indicators.
2. Surface a disclaimer when forecasts extend beyond high-confidence windows (72+ hours).
3. Cache API results (POIs, wiki entries) with short TTLs and purge stale contact/hour data frequently.
4. Gracefully degrade when an upstream API is unavailable: deliver the best available subset and record fallbacks.
5. Log data lineage per stop so auditors can trace which API supplied each detail.

## 9. Why this order works
- Interpreting intent before data fetch guarantees that the LLM never fabricates facts—it only guides filtering.
- Weather lookups depend on ETA data; therefore routing must happen before weather queries.
- TripAdvisor and Wikipedia can run in parallel, but both should feed into the synthesis phase only after deduplication and validation ensure every fact is grounded.

## Implementation reminders
- Enforce JSON schemas for both LLM passes and reject any prose-only responses.
- Keep LLM temperature low to maintain deterministic filter generation.
- Rate-limit and cache external API calls to stay within provider quotas.
- Log every failure mode and implement exponential backoff for transient HTTP errors.
