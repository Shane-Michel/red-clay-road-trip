const form = document.getElementById('trip-form');
const resultsSection = document.getElementById('results');
const itineraryContainer = document.getElementById('itinerary');
const saveButton = document.getElementById('save-trip');
const editButton = document.getElementById('edit-trip');
const downloadIcsButton = document.getElementById('download-ics');
const downloadPdfButton = document.getElementById('download-pdf');
const shareButton = document.getElementById('share-trip');
const historyList = document.getElementById('history-list');
const historyTemplate = document.getElementById('history-item-template');
const mapElement = document.getElementById('map');
const mapMessage = document.getElementById('map-message');
const shareFeedback = document.getElementById('share-feedback');
const editorSection = document.getElementById('itinerary-editor');
const editorStops = document.getElementById('editor-stops');
const addStopButton = document.getElementById('add-stop');
const applyEditsButton = document.getElementById('apply-edits');

let mapInstance = null;
let mapMarkers = [];
let routeLayer = null;
const geocodeCache = new Map();
let currentTrip = null;
let editorPendingChanges = false;

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

function clearShareFeedback() {
  if (!shareFeedback) return;
  shareFeedback.hidden = true;
  shareFeedback.textContent = '';
  shareFeedback.classList.remove('results__share--error');
}

function showShareFeedback(message, { html = false, error = false } = {}) {
  if (!shareFeedback) return;
  shareFeedback.hidden = false;
  if (html) {
    shareFeedback.innerHTML = message;
  } else {
    shareFeedback.textContent = message;
  }
  if (error) {
    shareFeedback.classList.add('results__share--error');
  } else {
    shareFeedback.classList.remove('results__share--error');
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

  const safeTrip = (trip && typeof trip === 'object') ? trip : {};
  const points = [];
  const stops = Array.isArray(safeTrip.stops) ? safeTrip.stops : [];

  if (safeTrip.start_location) {
    points.push({
      label: 'Start',
      description: safeTrip.start_location,
    });
  }

  stops.forEach((stop, index) => {
    const stopLabel = `Stop ${index + 1}: ${stop.title || stop.address || 'Scheduled stop'}`;
    const lookup = stop.address || stop.title || safeTrip.city_of_interest || '';
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
  const stops = Array.isArray(obj.stops) ? obj.stops : [];
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
    stops: stops.map((stop) => ({
      title: String(stop?.title ?? ''),
      address: String(stop?.address ?? ''),
      duration: String(stop?.duration ?? ''),
      description: String(stop?.description ?? ''),
      historical_note: String(stop?.historical_note ?? ''),
      challenge: String(stop?.challenge ?? ''),
    })),
  };
}

function cloneTrip(trip) {
  return JSON.parse(JSON.stringify(trip));
}

function escapeHtml(value) {
  return String(value).replace(/[&<>"']/g, (char) => {
    switch (char) {
      case '&': return '&amp;';
      case '<': return '&lt;';
      case '>': return '&gt;';
      case '"': return '&quot;';
      case "'": return '&#39;';
      default: return char;
    }
  });
}

function renderTripDetails(trip) {
  if (!itineraryContainer) return;

  const safeTrip = (trip && typeof trip === 'object') ? trip : {};
  const stops = Array.isArray(safeTrip.stops) ? safeTrip.stops : [];

  itineraryContainer.innerHTML = '';

  const intro = document.createElement('div');
  intro.className = 'itinerary-intro';

  const route = document.createElement('p');
  const routeLabel = document.createElement('strong');
  routeLabel.textContent = 'Route: ';
  route.append(routeLabel, document.createTextNode(safeTrip.route_overview || '—'));
  intro.appendChild(route);

  const travelTime = document.createElement('p');
  const travelLabel = document.createElement('strong');
  travelLabel.textContent = 'Total Travel Time: ';
  travelTime.append(travelLabel, document.createTextNode(safeTrip.total_travel_time || '—'));
  intro.appendChild(travelTime);

  itineraryContainer.appendChild(intro);

  if (safeTrip.summary) {
    const summaryParagraph = document.createElement('p');
    const summaryStrong = document.createElement('strong');
    summaryStrong.textContent = 'Summary: ';
    summaryParagraph.append(summaryStrong, document.createTextNode(safeTrip.summary));
    itineraryContainer.appendChild(summaryParagraph);
  }

  if (!stops.length) {
    const emptyMessage = document.createElement('p');
    emptyMessage.className = 'error';
    emptyMessage.textContent = 'No stops were provided for this itinerary.';
    itineraryContainer.appendChild(emptyMessage);
  }

  stops.forEach((stop, index) => {
    const article = document.createElement('article');
    article.className = 'itinerary-stop';

    const heading = document.createElement('h3');
    heading.textContent = `Stop ${index + 1}: ${stop.title || 'Untitled'}`;
    article.appendChild(heading);

    const meta = document.createElement('p');
    meta.className = 'itinerary-stop__meta';
    meta.textContent = `${stop.address || '—'} • Suggested time: ${stop.duration || '—'}`;
    article.appendChild(meta);

    if (stop.description) {
      const description = document.createElement('p');
      description.textContent = stop.description;
      article.appendChild(description);
    }

    if (stop.historical_note) {
      const historical = document.createElement('p');
      const label = document.createElement('strong');
      label.textContent = 'Historical Significance: ';
      historical.append(label, document.createTextNode(stop.historical_note));
      article.appendChild(historical);
    }

    if (stop.challenge) {
      const challenge = document.createElement('p');
      const label = document.createElement('strong');
      label.textContent = 'Challenge: ';
      challenge.append(label, document.createTextNode(stop.challenge));
      article.appendChild(challenge);
    }

    itineraryContainer.appendChild(article);
  });

  if (safeTrip.additional_tips) {
    const tips = document.createElement('div');
    tips.className = 'itinerary-tips';

    const title = document.createElement('h3');
    title.textContent = 'Travel Tips';
    tips.appendChild(title);

    const content = document.createElement('p');
    content.textContent = safeTrip.additional_tips;
    tips.appendChild(content);

    itineraryContainer.appendChild(tips);
  }
}

function createEmptyStop() {
  return {
    title: '',
    address: '',
    duration: '',
    description: '',
    historical_note: '',
    challenge: '',
  };
}

function buildEditor() {
  if (!editorStops || !editorSection) return;

  editorStops.innerHTML = '';

  if (!currentTrip) {
    editorSection.hidden = true;
    return;
  }

  const stops = Array.isArray(currentTrip.stops) ? currentTrip.stops : [];

  if (!stops.length) {
    const empty = document.createElement('p');
    empty.className = 'editor__empty';
    empty.textContent = 'No stops yet. Add one to personalize your itinerary.';
    editorStops.appendChild(empty);
  }

  stops.forEach((stop, index) => {
    const wrapper = document.createElement('article');
    wrapper.className = 'editor-stop';
    wrapper.dataset.index = String(index);

    const header = document.createElement('div');
    header.className = 'editor-stop__header';

    const title = document.createElement('h4');
    title.textContent = `Stop ${index + 1}`;
    header.appendChild(title);

    const controls = document.createElement('div');
    controls.className = 'editor-stop__controls';

    const moveUp = document.createElement('button');
    moveUp.type = 'button';
    moveUp.dataset.action = 'move-up';
    moveUp.textContent = 'Move Up';
    moveUp.disabled = index === 0;
    controls.appendChild(moveUp);

    const moveDown = document.createElement('button');
    moveDown.type = 'button';
    moveDown.dataset.action = 'move-down';
    moveDown.textContent = 'Move Down';
    moveDown.disabled = index === stops.length - 1;
    controls.appendChild(moveDown);

    const remove = document.createElement('button');
    remove.type = 'button';
    remove.dataset.action = 'remove';
    remove.textContent = 'Remove';
    controls.appendChild(remove);

    header.appendChild(controls);
    wrapper.appendChild(header);

    const fields = [
      { label: 'Title', field: 'title', type: 'text', placeholder: 'Name of the stop' },
      { label: 'Address or area', field: 'address', type: 'text', placeholder: 'Address or neighborhood' },
      { label: 'Suggested time', field: 'duration', type: 'text', placeholder: 'e.g., 90 minutes' },
      { label: 'Description', field: 'description', type: 'textarea', placeholder: 'What should travelers explore here?' },
      { label: 'Historical significance', field: 'historical_note', type: 'textarea', placeholder: 'Key facts or stories' },
      { label: 'Challenge', field: 'challenge', type: 'textarea', placeholder: 'Optional activity or prompt' },
    ];

    fields.forEach((config) => {
      const fieldWrapper = document.createElement('label');
      fieldWrapper.className = 'editor-stop__field';

      const label = document.createElement('span');
      label.textContent = config.label;
      fieldWrapper.appendChild(label);

      let input;
      if (config.type === 'textarea') {
        input = document.createElement('textarea');
      } else {
        input = document.createElement('input');
        input.type = 'text';
      }

      input.value = stop[config.field] || '';
      input.dataset.field = config.field;
      if (config.placeholder) {
        input.placeholder = config.placeholder;
      }

      fieldWrapper.appendChild(input);
      wrapper.appendChild(fieldWrapper);
    });

    editorStops.appendChild(wrapper);
  });

  if (applyEditsButton) {
    applyEditsButton.disabled = !editorPendingChanges;
  }
}

function updateActionButtons() {
  const hasTrip = Boolean(currentTrip);

  if (saveButton) {
    saveButton.disabled = !hasTrip;
  }

  if (editButton) {
    editButton.disabled = !hasTrip;
    const editorVisible = editorSection && !editorSection.hidden;
    editButton.textContent = editorVisible ? 'Hide Editor' : 'Edit Itinerary';
  }

  if (downloadIcsButton) {
    downloadIcsButton.disabled = !hasTrip;
  }

  if (downloadPdfButton) {
    downloadPdfButton.disabled = !hasTrip;
  }

  if (shareButton) {
    shareButton.disabled = !(hasTrip && currentTrip && currentTrip.id);
  }
}

function markEditorDirty() {
  editorPendingChanges = true;
  if (applyEditsButton) {
    applyEditsButton.disabled = false;
  }
  if (saveButton && currentTrip) {
    saveButton.dataset.itineraryPayload = JSON.stringify(currentTrip);
  }
}

function applyTripState(trip) {
  currentTrip = cloneTrip(trip);
  if (saveButton) {
    saveButton.dataset.itineraryPayload = JSON.stringify(currentTrip);
    if (currentTrip.id) {
      saveButton.dataset.itineraryId = currentTrip.id;
    } else {
      delete saveButton.dataset.itineraryId;
    }
  }

  renderTripDetails(currentTrip);
  buildEditor();
  editorPendingChanges = false;
  if (applyEditsButton) {
    applyEditsButton.disabled = true;
  }

  resultsSection.hidden = false;
  updateActionButtons();
  clearShareFeedback();

  updateMap(currentTrip).catch((error) => {
    console.error('Map rendering failed', error);
    resetMap('Map preview unavailable for this itinerary.');
  });
}

async function downloadTrip(format) {
  if (!currentTrip) return;

  clearShareFeedback();

  try {
    const response = await fetch('api/export_trip.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ format, trip: currentTrip }),
    });

    if (!response.ok) {
      let message = `Unable to download ${format.toUpperCase()} export.`;
      try {
        const data = await response.json();
        if (data && typeof data === 'object' && data.error) {
          message = data.error;
        }
      } catch (error) {
        // ignore parsing failures
      }
      throw new Error(message);
    }

    const blob = await response.blob();
    const filename = `road-trip-${new Date().toISOString().slice(0, 10)}.${format}`;
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = filename;
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    URL.revokeObjectURL(url);
  } catch (error) {
    console.error(error);
    showShareFeedback(error.message || `Unable to download ${format.toUpperCase()} export.`, { error: true });
  }
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
  if (itineraryContainer) {
    itineraryContainer.innerHTML = '';
  }

  clearShareFeedback();

  const payload = unwrapItineraryPayload(itinerary);

  if (payload && typeof payload === 'object' && payload.error) {
    const errSource = payload.error;
    const errMsg = typeof errSource === 'string'
      ? errSource
      : (errSource.message || 'Unable to display the itinerary at this time.');
    if (itineraryContainer) {
      const errorParagraph = document.createElement('p');
      errorParagraph.className = 'error';
      errorParagraph.textContent = errMsg;
      itineraryContainer.appendChild(errorParagraph);
    }
    currentTrip = null;
    editorPendingChanges = false;
    if (applyEditsButton) {
      applyEditsButton.disabled = true;
    }
    if (saveButton) {
      saveButton.disabled = true;
      delete saveButton.dataset.itineraryPayload;
      delete saveButton.dataset.itineraryId;
    }
    if (editorSection) {
      editorSection.hidden = true;
    }
    updateActionButtons();
    resultsSection.hidden = false;
    resetMap('Map preview unavailable for this itinerary.');
    return;
  }

  const trip = normalizeItinerary(payload);
  applyTripState(trip);
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
  if (editorSection) {
    editorSection.hidden = true;
  }
  clearShareFeedback();

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
  clearShareFeedback();

  const payload = JSON.parse(saveButton.dataset.itineraryPayload);

  try {
    const response = await fetchJSON('api/save_trip.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload),
    });

    if (currentTrip) {
      currentTrip.id = response.id ?? currentTrip.id;
      saveButton.dataset.itineraryPayload = JSON.stringify(currentTrip);
      if (currentTrip.id) {
        saveButton.dataset.itineraryId = currentTrip.id;
      }
    }

    await loadHistory();
    updateActionButtons();

    const savedLabel = response && response.updated ? 'Updated!' : 'Saved!';
    saveButton.textContent = savedLabel;
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
  clearShareFeedback();
  if (editorSection) {
    editorSection.hidden = true;
  }

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

if (editButton && editorSection) {
  editButton.addEventListener('click', () => {
    if (!currentTrip) return;
    editorSection.hidden = !editorSection.hidden;
    buildEditor();
    updateActionButtons();
  });
}

if (addStopButton) {
  addStopButton.addEventListener('click', () => {
    if (!currentTrip) return;
    currentTrip.stops.push(createEmptyStop());
    buildEditor();
    markEditorDirty();
  });
}

if (editorStops) {
  editorStops.addEventListener('input', (event) => {
    const input = event.target;
    if (!input.dataset.field) return;
    const wrapper = input.closest('.editor-stop');
    if (!wrapper) return;
    const index = Number.parseInt(wrapper.dataset.index ?? '', 10);
    if (!currentTrip || Number.isNaN(index) || !currentTrip.stops[index]) return;
    currentTrip.stops[index][input.dataset.field] = input.value;
    markEditorDirty();
  });

  editorStops.addEventListener('click', (event) => {
    const button = event.target.closest('button[data-action]');
    if (!button) return;
    const wrapper = button.closest('.editor-stop');
    if (!wrapper) return;
    const index = Number.parseInt(wrapper.dataset.index ?? '', 10);
    if (!currentTrip || Number.isNaN(index) || !currentTrip.stops[index]) return;

    if (button.dataset.action === 'remove') {
      currentTrip.stops.splice(index, 1);
      buildEditor();
      markEditorDirty();
      return;
    }

    const offset = button.dataset.action === 'move-up' ? -1 : 1;
    const targetIndex = index + offset;
    if (targetIndex < 0 || targetIndex >= currentTrip.stops.length) {
      return;
    }
    const temp = currentTrip.stops[index];
    currentTrip.stops[index] = currentTrip.stops[targetIndex];
    currentTrip.stops[targetIndex] = temp;
    buildEditor();
    markEditorDirty();
  });
}

if (applyEditsButton) {
  applyEditsButton.addEventListener('click', () => {
    if (!currentTrip) return;
    clearShareFeedback();
    renderTripDetails(currentTrip);
    editorPendingChanges = false;
    applyEditsButton.disabled = true;
    saveButton.dataset.itineraryPayload = JSON.stringify(currentTrip);
    updateMap(currentTrip).catch((error) => {
      console.error('Map rendering failed', error);
      resetMap('Map preview unavailable for this itinerary.');
    });
  });
}

if (downloadIcsButton) {
  downloadIcsButton.addEventListener('click', () => downloadTrip('ics'));
}

if (downloadPdfButton) {
  downloadPdfButton.addEventListener('click', () => downloadTrip('pdf'));
}

if (shareButton) {
  shareButton.addEventListener('click', async () => {
    if (!currentTrip) return;
    if (!currentTrip.id) {
      showShareFeedback('Save this itinerary before sharing it with others.', { error: true });
      return;
    }

    clearShareFeedback();
    const originalLabel = shareButton.textContent;
    shareButton.textContent = 'Generating...';
    shareButton.disabled = true;

    try {
      const data = await fetchJSON('api/create_share_link.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ id: currentTrip.id }),
      });

      const shareUrl = new URL(data.share_url, window.location.origin).toString();
      const safeUrl = escapeHtml(shareUrl);
      const anchor = `<a href="${safeUrl}" target="_blank" rel="noopener noreferrer">Open shared itinerary</a>`;
      let message = `Shareable link ready: ${anchor}`;

      if (data.expires_at) {
        const expires = escapeHtml(new Date(data.expires_at).toLocaleString());
        message += ` (expires ${expires})`;
      }

      try {
        await navigator.clipboard.writeText(shareUrl);
        message = `Link copied to clipboard! ${anchor}`;
        if (data.expires_at) {
          const expires = escapeHtml(new Date(data.expires_at).toLocaleString());
          message += ` (expires ${expires})`;
        }
      } catch (copyError) {
        console.warn('Unable to copy share link automatically', copyError);
      }

      showShareFeedback(message, { html: true });
    } catch (error) {
      console.error(error);
      showShareFeedback(error.message || 'Unable to create share link.', { error: true });
    } finally {
      shareButton.textContent = originalLabel;
      shareButton.disabled = false;
      updateActionButtons();
    }
  });
}

updateActionButtons();
loadHistory();