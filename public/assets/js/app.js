const form = document.getElementById('trip-form');
const resultsSection = document.getElementById('results');
const itineraryContainer = document.getElementById('itinerary');
const saveButton = document.getElementById('save-trip');
const historyList = document.getElementById('history-list');
const historyTemplate = document.getElementById('history-item-template');
const mapElement = document.getElementById('map');
const mapMessage = document.getElementById('map-message');

let mapInstance = null;
let mapMarkers = [];
let routeLayer = null;
const geocodeCache = new Map();

function setMapMessage(text) {
  if (!mapMessage) return;
  if (!text) {
    mapMessage.textContent = '';
    mapMessage.hidden = true;
    return;
  }
  mapMessage.textContent = text;
  mapMessage.hidden = false;
}

function ensureMap() {
  if (!mapElement || typeof L === 'undefined') {
    return null;
  }

  if (mapInstance) {
    return mapInstance;
  }

  mapInstance = L.map(mapElement, {
    zoomControl: true,
    scrollWheelZoom: false,
  });

  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
    maxZoom: 19,
  }).addTo(mapInstance);

  // Default view over the southeastern US, adjust once coordinates load
  mapInstance.setView([33.5207, -86.8025], 6);

  return mapInstance;
}

function resetMap(message = '') {
  if (mapInstance) {
    mapMarkers.forEach((marker) => marker.remove());
    mapMarkers = [];
    if (routeLayer) {
      routeLayer.remove();
      routeLayer = null;
    }
  }

  if (message) {
    setMapMessage(message);
  } else {
    setMapMessage('');
  }
}

async function geocodeLocation(query) {
  const trimmed = (query || '').trim();
  if (!trimmed) return null;

  if (geocodeCache.has(trimmed)) {
    return geocodeCache.get(trimmed);
  }

  const url = `https://nominatim.openstreetmap.org/search?format=json&limit=1&q=${encodeURIComponent(trimmed)}`;

  try {
    const response = await fetch(url, {
      headers: {
        'Accept-Language': 'en',
      },
    });
    if (!response.ok) {
      throw new Error(`Geocoding failed with status ${response.status}`);
    }
    const data = await response.json();
    const match = Array.isArray(data) && data.length ? data[0] : null;
    const lat = match ? Number.parseFloat(match.lat) : null;
    const lon = match ? Number.parseFloat(match.lon) : null;
    const valid = Number.isFinite(lat) && Number.isFinite(lon);
    const coords = valid ? {
      lat,
      lon,
      name: match.display_name || trimmed,
    } : null;
    geocodeCache.set(trimmed, coords);
    return coords;
  } catch (error) {
    console.warn('Geocoding error for', trimmed, error);
    geocodeCache.set(trimmed, null);
    return null;
  }
}

async function fetchRoute(points) {
  if (!Array.isArray(points) || points.length < 2) {
    return null;
  }

  const coords = points
    .map((point) => `${point.coords.lon},${point.coords.lat}`)
    .join(';');

  const url = `https://router.project-osrm.org/route/v1/driving/${coords}?overview=full&geometries=geojson`; // eslint-disable-line max-len

  try {
    const response = await fetch(url);
    if (!response.ok) {
      throw new Error(`Routing failed with status ${response.status}`);
    }
    const data = await response.json();
    if (!data.routes || !data.routes.length) {
      return null;
    }
    const geometry = data.routes[0].geometry;
    if (!geometry || !Array.isArray(geometry.coordinates)) {
      return null;
    }
    return geometry.coordinates.map(([lon, lat]) => [lat, lon]);
  } catch (error) {
    console.warn('Routing error', error);
    return null;
  }
}

async function updateMap(trip) {
  if (!mapElement) return;

  if (typeof L === 'undefined') {
    setMapMessage('Map preview unavailable because the map library could not be loaded.');
    return;
  }

  const map = ensureMap();
  if (!map) {
    setMapMessage('Map preview unavailable for this itinerary.');
    return;
  }

  resetMap('Plotting your road trip...');

  const points = [];
  if (trip.start_location) {
    points.push({
      label: 'Start',
      description: trip.start_location,
    });
  }

  trip.stops.forEach((stop, index) => {
    const stopLabel = `Stop ${index + 1}: ${stop.title || stop.address || 'Scheduled stop'}`;
    const lookup = stop.address || stop.title || trip.city_of_interest || '';
    points.push({
      label: stopLabel,
      description: lookup,
    });
  });

  if (!points.length) {
    resetMap('Map preview unavailable for this itinerary.');
    return;
  }

  const resolved = [];
  for (const point of points) {
    // eslint-disable-next-line no-await-in-loop
    const coords = await geocodeLocation(point.description);
    if (coords) {
      resolved.push({ ...point, coords });
    }
  }

  if (!resolved.length) {
    resetMap('Map preview unavailable for this itinerary.');
    return;
  }

  setMapMessage('');

  mapMarkers = resolved.map((point) => {
    const marker = L.marker([point.coords.lat, point.coords.lon], { title: point.label }).addTo(map);
    marker.bindPopup(`<strong>${point.label}</strong><br>${point.description}`);
    return marker;
  });

  const bounds = L.latLngBounds(resolved.map((point) => [point.coords.lat, point.coords.lon]));
  map.fitBounds(bounds, { padding: [24, 24] });

  const route = await fetchRoute(resolved);
  if (route && route.length) {
    routeLayer = L.polyline(route, {
      color: '#c3562d',
      weight: 4,
      opacity: 0.85,
      lineJoin: 'round',
    }).addTo(map);
    map.fitBounds(routeLayer.getBounds(), { padding: [24, 24] });
  }

  setTimeout(() => {
    map.invalidateSize();
  }, 150);
}

async function fetchJSON(url, options) {
  const response = await fetch(url, options);
  const contentType = response.headers.get('content-type') || '';

  // Try to parse JSON if available (both for ok and error responses)
  let parsed = null;
  if (contentType.includes('application/json')) {
    try { parsed = await response.json(); } catch { /* fall through */ }
  } else {
    // fallback: text
    try { parsed = await response.text(); } catch { /* noop */ }
  }

  if (!response.ok) {
    const msg =
      (parsed && parsed.error && parsed.error.message) ? parsed.error.message :
      (typeof parsed === 'string' && parsed.trim() ? parsed.trim() : 'Unexpected server error');
    throw new Error(msg);
  }

  // If server returned text (unlikely), coerce to object
  if (parsed === null || typeof parsed !== 'object') {
    return {};
  }
  return parsed;
}

// Normalize an itinerary-like object into a safe shape for rendering/storage
function normalizeItinerary(x) {
  const obj = (x && typeof x === 'object') ? x : {};
  return {
    route_overview: String(obj.route_overview ?? ''),
    total_travel_time: String(obj.total_travel_time ?? ''),
    summary: String(obj.summary ?? ''),
    additional_tips: String(obj.additional_tips ?? ''),
    start_location: String(obj.start_location ?? ''),
    departure_datetime: String(obj.departure_datetime ?? ''),
    city_of_interest: String(obj.city_of_interest ?? ''),
    traveler_preferences: String(obj.traveler_preferences ?? ''),
    id: obj.id ?? '',
    stops: Array.isArray(obj.stops) ? obj.stops : []
  };
}

function unwrapItineraryPayload(payload) {
  if (!payload || typeof payload !== 'object') return payload;

  // Surface nested error objects consistently
  if (payload.error) {
    return { error: payload.error };
  }

  if (payload.data && typeof payload.data === 'object') {
    const nested = payload.data;
    if (nested && typeof nested === 'object') {
      if (nested.error) {
        return { error: nested.error };
      }
      return nested;
    }
  }

  return payload;
}

function renderItinerary(itinerary) {
  itineraryContainer.innerHTML = '';

  const payload = unwrapItineraryPayload(itinerary);

  // Handle error shapes: { error: "msg" } or { error: { message: "msg" } }
  if (payload && typeof payload === 'object' && payload.error) {
    const errSource = payload.error;
    const errMsg = typeof errSource === 'string'
      ? errSource
      : (errSource.message || 'Unable to display the itinerary at this time.');
    itineraryContainer.innerHTML = `<p class="error">${errMsg}</p>`;
    resultsSection.hidden = false;
    saveButton.disabled = true;
    delete saveButton.dataset.itineraryPayload;
    delete saveButton.dataset.itineraryId;
    resetMap('Map preview unavailable for this itinerary.');
    return;
  }

  const trip = normalizeItinerary(payload);

  const intro = document.createElement('div');
  intro.className = 'itinerary-intro';
  intro.innerHTML = `
      <p><strong>Route:</strong> ${trip.route_overview || '—'}</p>
      <p><strong>Total Travel Time:</strong> ${trip.total_travel_time || '—'}</p>
  `;
  itineraryContainer.appendChild(intro);

  if (!trip.stops.length) {
    const emptyMessage = document.createElement('p');
    emptyMessage.className = 'error';
    emptyMessage.textContent = 'No stops were provided for this itinerary.';
    itineraryContainer.appendChild(emptyMessage);
  }

  trip.stops.forEach((stop, index) => {
    const safe = {
      title: String(stop?.title ?? 'Untitled'),
      address: String(stop?.address ?? '—'),
      duration: String(stop?.duration ?? '—'),
      description: String(stop?.description ?? ''),
      historical_note: String(stop?.historical_note ?? ''),
      challenge: String(stop?.challenge ?? '')
    };

    const article = document.createElement('article');
    article.className = 'itinerary-stop';
    article.innerHTML = `
        <h3>Stop ${index + 1}: ${safe.title}</h3>
        <p class="itinerary-stop__meta">${safe.address} &bull; Suggested time: ${safe.duration}</p>
        <p>${safe.description}</p>
        <p><strong>Historical Significance:</strong> ${safe.historical_note}</p>
        <p><strong>Challenge:</strong> ${safe.challenge}</p>
    `;
    itineraryContainer.appendChild(article);
  });

  if (trip.additional_tips) {
    const tips = document.createElement('div');
    tips.className = 'itinerary-tips';
    tips.innerHTML = `
        <h3>Travel Tips</h3>
        <p>${trip.additional_tips}</p>
    `;
    itineraryContainer.appendChild(tips);
  }

  resultsSection.hidden = false;
  saveButton.disabled = false;
  saveButton.dataset.itineraryId = trip.id;
  // Keep normalized shape for saving
  saveButton.dataset.itineraryPayload = JSON.stringify(trip);

  updateMap(trip).catch((error) => {
    console.error('Map rendering failed', error);
    resetMap('Map preview unavailable for this itinerary.');
  });
}

function renderHistory(trips) {
  historyList.innerHTML = '';

  if (!Array.isArray(trips) || trips.length === 0) {
    historyList.innerHTML = '<p>No trips saved yet. Generate one to get started!</p>';
    return;
  }

  trips.forEach((trip) => {
    const clone = historyTemplate.content.firstElementChild.cloneNode(true);
    const title = `${trip.city_of_interest ?? '—'} from ${trip.start_location ?? '—'}`;
    const when = trip.created_at ? new Date(trip.created_at).toLocaleString() : '';

    clone.querySelector('h3').textContent = title;
    clone.querySelector('.history__meta').textContent = when;
    clone.querySelector('.history__summary').textContent = trip.summary || '—';
    clone.querySelector('[data-action="load"]').dataset.tripId = trip.id ?? '';
    historyList.appendChild(clone);
  });
}

async function loadHistory() {
  try {
    const trips = await fetchJSON('api/list_trips.php');
    renderHistory(trips);
  } catch (error) {
    console.error(error);
    historyList.innerHTML = '<p>Unable to load saved trips. Please try again later.</p>';
  }
}

form.addEventListener('submit', async (event) => {
  event.preventDefault();
  const formData = new FormData(form);

  saveButton.disabled = true;
  resultsSection.hidden = true;
  itineraryContainer.innerHTML = '<p>Generating your itinerary...</p>';
  resetMap('Generating map preview...');

  try {
    const raw = await fetchJSON('api/generate_trip.php', {
      method: 'POST',
      body: formData,
    });
    renderItinerary(raw); // renderItinerary will normalize & set payload
  } catch (error) {
    itineraryContainer.innerHTML = `<p class="error">${error.message || 'Something went wrong while generating your trip.'}</p>`;
    resultsSection.hidden = false;
    saveButton.disabled = true;
    delete saveButton.dataset.itineraryPayload;
    delete saveButton.dataset.itineraryId;
    resetMap('Map preview unavailable for this itinerary.');
  }
});

saveButton.addEventListener('click', async () => {
  if (!saveButton.dataset.itineraryPayload) return;

  saveButton.disabled = true;
  const payload = JSON.parse(saveButton.dataset.itineraryPayload);

  try {
    await fetchJSON('api/save_trip.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload),
    });
    await loadHistory();
    saveButton.textContent = 'Saved!';
    setTimeout(() => {
      saveButton.textContent = 'Save Trip';
      saveButton.disabled = false;
    }, 2000);
  } catch (error) {
    console.error(error);
    saveButton.disabled = false;
    saveButton.textContent = 'Try Saving Again';
  }
});

historyList.addEventListener('click', async (event) => {
  const button = event.target.closest('button[data-action="load"]');
  if (!button) return;

  const tripId = button.dataset.tripId;
  if (!tripId) return;

  saveButton.disabled = true;
  resultsSection.hidden = true;
  itineraryContainer.innerHTML = '<p>Loading itinerary...</p>';
  resetMap('Loading map preview...');

  try {
    const raw = await fetchJSON(`api/get_trip.php?id=${encodeURIComponent(tripId)}`);
    renderItinerary(raw); // renderItinerary will normalize & set payload
  } catch (error) {
    itineraryContainer.innerHTML = `<p class="error">${error.message || 'Unable to load itinerary.'}</p>`;
    resultsSection.hidden = false;
    saveButton.disabled = true;
    delete saveButton.dataset.itineraryPayload;
    delete saveButton.dataset.itineraryId;
    resetMap('Map preview unavailable for this itinerary.');
  }
});

loadHistory();