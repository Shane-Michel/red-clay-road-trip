/* app.js — hardened */

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
const citiesContainer = document.getElementById('cities-of-interest');
const addCityButton = document.getElementById('add-city');
const accountStatus = document.getElementById('account-status');
const accountKeyInput = document.getElementById('account-key');
const accountApplyButton = document.getElementById('account-apply');
const accountGenerateButton = document.getElementById('account-generate');
const accountCopyButton = document.getElementById('account-copy');
const accountResetButton = document.getElementById('account-reset');

let mapInstance = null;
let mapMarkers = [];
let routeLayer = null;
const geocodeCache = new Map();
let currentTrip = null;
let editorPendingChanges = false;

/* ----------------------------- Map helpers ----------------------------- */

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
  if (!mapElement || typeof L === 'undefined') return null;
  if (mapInstance) return mapInstance;

  mapInstance = L.map(mapElement, { zoomControl: true, scrollWheelZoom: false });

  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    attribution:
      '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
    maxZoom: 19,
  }).addTo(mapInstance);

  // Default view over the Southeast; will fit to markers after geocoding
  mapInstance.setView([33.5207, -86.8025], 6);
  return mapInstance;
}

function resetMap(message = '') {
  if (mapInstance) {
    mapMarkers.forEach((m) => m && typeof m.remove === 'function' && m.remove());
    mapMarkers = [];
    if (routeLayer && typeof routeLayer.remove === 'function') {
      routeLayer.remove();
      routeLayer = null;
    }
  }
  setMapMessage(message || '');
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
  if (html) shareFeedback.innerHTML = message;
  else shareFeedback.textContent = message;
  if (error) shareFeedback.classList.add('results__share--error');
  else shareFeedback.classList.remove('results__share--error');
}

async function geocodeLocation(query) {
  const trimmed = (query || '').trim();
  if (!trimmed) return null;
  if (geocodeCache.has(trimmed)) return geocodeCache.get(trimmed);

  const url = `api/geocode.php?q=${encodeURIComponent(trimmed)}`;

  try {
    const data = await fetchJSON(url);
    const lat = Number.parseFloat(data?.lat);
    const lon = Number.parseFloat(data?.lon);
    const displayName =
      (typeof data?.display_name === 'string' && data.display_name.trim()) ? data.display_name.trim() : trimmed;
    const valid = Number.isFinite(lat) && Number.isFinite(lon);
    const coords = valid ? { lat, lon, name: displayName } : null;
    geocodeCache.set(trimmed, coords);
    return coords;
  } catch (e) {
    console.warn('Geocoding error:', trimmed, e);
    geocodeCache.set(trimmed, null);
    return null;
  }
}

async function fetchRoute(points) {
  if (!Array.isArray(points) || points.length < 2) return null;

  const coords = points.map((p) => `${p.coords.lon},${p.coords.lat}`).join(';');
  const url = `https://router.project-osrm.org/route/v1/driving/${coords}?overview=full&geometries=geojson`;

  try {
    const response = await fetch(url);
    if (!response.ok) throw new Error(`Routing failed (${response.status})`);
    const data = await response.json();
    if (!data.routes || !data.routes.length) return null;
    const geometry = data.routes[0]?.geometry;
    if (!geometry || !Array.isArray(geometry.coordinates)) return null;
    return geometry.coordinates.map(([lon, lat]) => [lat, lon]);
  } catch (e) {
    console.warn('Routing error:', e);
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
  const cityFallback = Array.isArray(safeTrip.cities_of_interest) && safeTrip.cities_of_interest.length
    ? safeTrip.cities_of_interest[0]
    : safeTrip.city_of_interest;

  if (safeTrip.start_location) {
    points.push({ label: 'Start', description: safeTrip.start_location });
  }

  stops.forEach((stop, index) => {
    const stopLabel = `Stop ${index + 1}: ${stop?.title || stop?.address || 'Scheduled stop'}`;
    const lookup = stop?.address || stop?.title || cityFallback || '';
    points.push({ label: stopLabel, description: lookup });
  });

  if (!points.length) {
    resetMap('Map preview unavailable for this itinerary.');
    return;
  }

  const resolved = [];
  for (const p of points) {
    // sequential geocoding to avoid Nominatim rate limits
    const coords = await geocodeLocation(p.description); // eslint-disable-line no-await-in-loop
    if (coords) resolved.push({ ...p, coords });
  }

  if (!resolved.length) {
    resetMap('Map preview unavailable for this itinerary.');
    return;
  }

  setMapMessage('');
  mapMarkers = resolved.map((p) => {
    const marker = L.marker([p.coords.lat, p.coords.lon], { title: p.label }).addTo(map);
    marker.bindPopup(`<strong>${p.label}</strong><br>${p.description}`);
    return marker;
  });

  const bounds = L.latLngBounds(resolved.map((p) => [p.coords.lat, p.coords.lon]));
  map.fitBounds(bounds, { padding: [24, 24] });

  const route = await fetchRoute(resolved);
  if (Array.isArray(route) && route.length) {
    routeLayer = L.polyline(route, { color: '#c3562d', weight: 4, opacity: 0.85, lineJoin: 'round' }).addTo(map);
    map.fitBounds(routeLayer.getBounds(), { padding: [24, 24] });
  }

  setTimeout(() => map.invalidateSize(), 150);
}

/* ----------------------------- Networking ------------------------------ */

/* -------------------------- User scope management ----------------------- */

const travelerScope = (() => {
  const STORAGE_KEY = 'redclay.travelerKey';
  let stored = '';
  try {
    stored = window.localStorage ? window.localStorage.getItem(STORAGE_KEY) : '';
  } catch (error) {
    console.warn('Unable to read traveler scope', error);
  }
  let current = sanitize(stored);
  const listeners = new Set();

  function sanitize(value) {
    if (value == null) return '';
    const trimmed = String(value).trim();
    if (!trimmed) return '';
    const withoutBreaks = trimmed.replace(/\s+/g, ' ');
    const filtered = withoutBreaks.replace(/[^A-Za-z0-9@._-]/g, '');
    return filtered.slice(0, 120);
  }

  function persist() {
    try {
      if (current) window.localStorage.setItem(STORAGE_KEY, current);
      else window.localStorage.removeItem(STORAGE_KEY);
    } catch (error) {
      console.warn('Unable to persist traveler scope', error);
    }
  }

  function notify() {
    listeners.forEach((listener) => {
      try {
        listener(current);
      } catch (error) {
        console.error('travelerScope listener error', error);
      }
    });
  }

  function set(value) {
    current = sanitize(value);
    persist();
    notify();
    return current;
  }

  return {
    current: () => current,
    isDefault: () => current === '',
    set,
    clear: () => set(''),
    generate: () => {
      const base = (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function')
        ? crypto.randomUUID()
        : `${Date.now()}-${Math.random()}`;
      const cleaned = base.replace(/[^a-z0-9]/gi, '').toLowerCase();
      const padded = cleaned.padEnd(24, 'x');
      return `rc${padded}`.slice(0, 40);
    },
    decorate: (options = {}) => {
      const opts = { ...options };
      const headers = new Headers(options && options.headers ? options.headers : undefined);
      if (current) headers.set('X-User-Scope', current);
      opts.headers = headers;
      return opts;
    },
    onChange: (listener) => {
      if (typeof listener !== 'function') return () => {};
      listeners.add(listener);
      listener(current);
      return () => listeners.delete(listener);
    },
  };
})();

let accountAnnouncement = '';
let accountAnnouncementTimeout = null;

function updateAccountStatus(key) {
  if (!accountStatus) return;

  let base;
  if (!key) {
    base = `<span>${escapeHtml('Currently using the shared demo space. Saved trips here are visible to other visitors.')}</span>`;
    if (accountKeyInput) accountKeyInput.value = '';
    if (accountCopyButton) accountCopyButton.hidden = true;
    if (accountResetButton) accountResetButton.disabled = true;
  } else {
    const masked = key.length > 12 ? `${key.slice(0, 6)}…${key.slice(-4)}` : key;
    base = `<span>Private key active (<code>${escapeHtml(masked)}</code>). Trips saved now are isolated to this key.</span>`;
    if (accountKeyInput) accountKeyInput.value = key;
    if (accountCopyButton) accountCopyButton.hidden = false;
    if (accountResetButton) accountResetButton.disabled = false;
  }

  const message = accountAnnouncement
    ? `<span class="account__announcement">${escapeHtml(accountAnnouncement)}</span>`
    : '';
  accountStatus.innerHTML = `${base}${message}`;
}

function announceAccount(message) {
  accountAnnouncement = message || '';
  if (accountAnnouncementTimeout) {
    clearTimeout(accountAnnouncementTimeout);
    accountAnnouncementTimeout = null;
  }
  if (accountAnnouncement) {
    accountAnnouncementTimeout = setTimeout(() => {
      accountAnnouncement = '';
      updateAccountStatus(travelerScope.current());
    }, 5000);
  }
  updateAccountStatus(travelerScope.current());
}

async function fetchJSON(url, options = {}) {
  const response = await fetch(url, travelerScope.decorate(options));
  const contentType = response.headers.get('content-type') || '';

  let parsed = null;
  if (contentType.includes('application/json')) {
    try { parsed = await response.json(); } catch { /* ignore */ }
  } else {
    try { parsed = await response.text(); } catch { /* ignore */ }
  }

  if (!response.ok) {
    const msg =
      (parsed && parsed.error && parsed.error.message) ? parsed.error.message :
      (typeof parsed === 'string' && parsed.trim() ? parsed.trim() : 'Unexpected server error');
    throw new Error(msg);
  }

  if (parsed === null || typeof parsed !== 'object') return {};
  return parsed;
}

/* ------------------------------- Helpers ------------------------------- */

function normalizeItinerary(x) {
  const obj = (x && typeof x === 'object') ? x : {};
  const stops = Array.isArray(obj.stops) ? obj.stops : [];
  const cities = Array.isArray(obj.cities_of_interest)
    ? obj.cities_of_interest.map((city) => String(city ?? '').trim()).filter((city) => city !== '')
    : [];
  const primaryCity = String(obj.city_of_interest ?? '').trim() || (cities.length ? cities[0] : '');
  return {
    route_overview: String(obj.route_overview ?? ''),
    total_travel_time: String(obj.total_travel_time ?? ''),
    summary: String(obj.summary ?? ''),
    additional_tips: String(obj.additional_tips ?? ''),
    start_location: String(obj.start_location ?? ''),
    departure_datetime: String(obj.departure_datetime ?? ''),
    city_of_interest: primaryCity,
    cities_of_interest: cities,
    traveler_preferences: String(obj.traveler_preferences ?? ''),
    id: obj.id ?? '',
    stops: stops.map((stop) => ({
      title: String(stop?.title ?? ''),
      address: String(stop?.address ?? ''),
      duration: String(stop?.duration ?? ''),
      description: String(stop?.description ?? ''),
      historical_note: String(stop?.historical_note ?? ''),
      challenge: String(stop?.challenge ?? ''),
      fun_fact: String(stop?.fun_fact ?? ''),
      highlight: String(stop?.highlight ?? ''),
      food_pick: String(stop?.food_pick ?? ''),
    })),
  };
}

function cloneTrip(trip) {
  try { return JSON.parse(JSON.stringify(trip)); }
  catch { return normalizeItinerary(trip); }
}

function escapeHtml(value) {
  return String(value).replace(/[&<>"']/g, (c) => (
    c === '&' ? '&amp;'
    : c === '<' ? '&lt;'
    : c === '>' ? '&gt;'
    : c === '"' ? '&quot;'
    : '&#39;'
  ));
}

function createEmptyStop() {
  return {
    title: '',
    address: '',
    duration: '',
    description: '',
    historical_note: '',
    challenge: '',
    fun_fact: '',
    highlight: '',
    food_pick: '',
  };
}

function createCityField(value = '') {
  if (!citiesContainer) return null;
  const wrapper = document.createElement('div');
  wrapper.className = 'field__repeatable';

  const input = document.createElement('input');
  input.type = 'text';
  input.name = 'cities_of_interest[]';
  input.required = true;
  input.placeholder = 'e.g., Savannah, GA';
  if (value) input.value = value;
  wrapper.appendChild(input);

  const remove = document.createElement('button');
  remove.type = 'button';
  remove.className = 'btn btn--ghost field__remove';
  remove.textContent = 'Remove';
  remove.dataset.action = 'remove-city';
  wrapper.appendChild(remove);

  return wrapper;
}

function refreshCityRemoveButtons() {
  if (!citiesContainer) return;
  const wrappers = Array.from(citiesContainer.querySelectorAll('.field__repeatable'));
  wrappers.forEach((wrapper) => {
    const remove = wrapper.querySelector('[data-action="remove-city"]');
    if (!remove) return;
    const disabled = wrappers.length === 1;
    remove.disabled = disabled;
    remove.hidden = disabled;
  });
}

/* ---------------------------- Rendering UI ----------------------------- */

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

  const cityLine = document.createElement('p');
  const cityLabel = document.createElement('strong');
  cityLabel.textContent = 'Cities to Explore: ';
  const cityNames = (Array.isArray(safeTrip.cities_of_interest) && safeTrip.cities_of_interest.length)
    ? safeTrip.cities_of_interest.join(' • ')
    : (safeTrip.city_of_interest || '—');
  cityLine.append(cityLabel, document.createTextNode(cityNames));
  intro.appendChild(cityLine);

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
    heading.textContent = `Stop ${index + 1}: ${stop?.title || 'Untitled'}`;
    article.appendChild(heading);

    const meta = document.createElement('p');
    meta.className = 'itinerary-stop__meta';
    meta.textContent = `${stop?.address || '—'} • Suggested time: ${stop?.duration || '—'}`;
    article.appendChild(meta);

    if (stop?.description) {
      const description = document.createElement('p');
      description.textContent = stop.description;
      article.appendChild(description);
    }

    if (stop?.historical_note) {
      const historical = document.createElement('p');
      const label = document.createElement('strong');
      label.textContent = 'Story or significance: ';
      historical.append(label, document.createTextNode(stop.historical_note));
      article.appendChild(historical);
    }

    if (stop?.challenge) {
      const challenge = document.createElement('p');
      const label = document.createElement('strong');
      label.textContent = 'Challenge: ';
      challenge.append(label, document.createTextNode(stop.challenge));
      article.appendChild(challenge);
    }

    if (stop?.fun_fact) {
      const funFact = document.createElement('p');
      const label = document.createElement('strong');
      label.textContent = 'Fun fact: ';
      funFact.append(label, document.createTextNode(stop.fun_fact));
      article.appendChild(funFact);
    }

    if (stop?.highlight) {
      const highlight = document.createElement('p');
      const label = document.createElement('strong');
      label.textContent = "Don't miss: ";
      highlight.append(label, document.createTextNode(stop.highlight));
      article.appendChild(highlight);
    }

    if (stop?.food_pick) {
      const food = document.createElement('p');
      const label = document.createElement('strong');
      label.textContent = 'Hidden bite: ';
      food.append(label, document.createTextNode(stop.food_pick));
      article.appendChild(food);
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
      { label: 'Story or significance', field: 'historical_note', type: 'textarea', placeholder: 'Key facts, legends, or context' },
      { label: 'Challenge', field: 'challenge', type: 'textarea', placeholder: 'Optional activity or prompt' },
      { label: 'Fun fact', field: 'fun_fact', type: 'textarea', placeholder: 'Surprising tidbit or local lore' },
      { label: "Don't miss", field: 'highlight', type: 'textarea', placeholder: 'Interesting place or experience nearby' },
      { label: 'Hidden bite', field: 'food_pick', type: 'textarea', placeholder: 'Under-the-radar food or drink stop' },
    ];

    fields.forEach((cfg) => {
      const fieldWrapper = document.createElement('label');
      fieldWrapper.className = 'editor-stop__field';

      const lbl = document.createElement('span');
      lbl.textContent = cfg.label;
      fieldWrapper.appendChild(lbl);

      let input;
      if (cfg.type === 'textarea') input = document.createElement('textarea');
      else { input = document.createElement('input'); input.type = 'text'; }

      input.value = stop?.[cfg.field] || '';
      input.dataset.field = cfg.field;
      if (cfg.placeholder) input.placeholder = cfg.placeholder;

      fieldWrapper.appendChild(input);
      wrapper.appendChild(fieldWrapper);
    });

    editorStops.appendChild(wrapper);
  });

  if (applyEditsButton) applyEditsButton.disabled = !editorPendingChanges;
}

function updateActionButtons() {
  const hasTrip = Boolean(currentTrip);

  if (saveButton) saveButton.disabled = !hasTrip;

  if (editButton) {
    editButton.disabled = !hasTrip;
    const editorVisible = editorSection && !editorSection.hidden;
    editButton.textContent = editorVisible ? 'Hide Editor' : 'Edit Itinerary';
  }

  if (downloadIcsButton) downloadIcsButton.disabled = !hasTrip;
  if (downloadPdfButton) downloadPdfButton.disabled = !hasTrip;
  if (shareButton) shareButton.disabled = !(hasTrip && currentTrip && currentTrip.id);
}

function markEditorDirty() {
  editorPendingChanges = true;
  if (applyEditsButton) applyEditsButton.disabled = false;
  if (saveButton && currentTrip) {
    saveButton.dataset.itineraryPayload = JSON.stringify(currentTrip);
  }
}

function applyTripState(trip) {
  currentTrip = cloneTrip(trip);

  if (saveButton) {
    saveButton.dataset.itineraryPayload = JSON.stringify(currentTrip);
    if (currentTrip.id) saveButton.dataset.itineraryId = currentTrip.id;
    else delete saveButton.dataset.itineraryId;
  }

  renderTripDetails(currentTrip);
  buildEditor();
  editorPendingChanges = false;
  if (applyEditsButton) applyEditsButton.disabled = true;

  resultsSection.hidden = false;
  updateActionButtons();
  clearShareFeedback();

  updateMap(currentTrip).catch((e) => {
    console.error('Map rendering failed:', e);
    resetMap('Map preview unavailable for this itinerary.');
  });
}

/* --------------------------- Export & Sharing -------------------------- */

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
        if (data && typeof data === 'object' && data.error) message = data.error;
      } catch { /* ignore */ }
      throw new Error(message);
    }

    const blob = await response.blob();
    const filename = `road-trip-${new Date().toISOString().slice(0, 10)}.${format}`;
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url; link.download = filename;
    document.body.appendChild(link); link.click();
    document.body.removeChild(link); URL.revokeObjectURL(url);
  } catch (e) {
    console.error(e);
    showShareFeedback(e.message || `Unable to download ${format.toUpperCase()} export.`, { error: true });
  }
}

/* ------------------------- Itinerary orchestration --------------------- */

function unwrapItineraryPayload(payload) {
  if (!payload || typeof payload !== 'object') return payload;
  if (payload.error) return { error: payload.error };
  if (payload.data && typeof payload.data === 'object') {
    const nested = payload.data;
    if (nested?.error) return { error: nested.error };
    return nested;
  }
  return payload;
}

function renderItinerary(itinerary) {
  if (itineraryContainer) itineraryContainer.innerHTML = '';
  clearShareFeedback();

  const payload = unwrapItineraryPayload(itinerary);

  if (payload && typeof payload === 'object' && payload.error) {
    const errSource = payload.error;
    const errMsg = typeof errSource === 'string'
      ? errSource
      : (errSource.message || 'Unable to display the itinerary at this time.');

    if (itineraryContainer) {
      const p = document.createElement('p');
      p.className = 'error';
      p.textContent = errMsg;
      itineraryContainer.appendChild(p);
    }

    currentTrip = null;
    editorPendingChanges = false;
    if (applyEditsButton) applyEditsButton.disabled = true;
    if (saveButton) {
      saveButton.disabled = true;
      delete saveButton.dataset.itineraryPayload;
      delete saveButton.dataset.itineraryId;
    }
    if (editorSection) editorSection.hidden = true;

    updateActionButtons();
    resultsSection.hidden = false;
    resetMap('Map preview unavailable for this itinerary.');
    return;
  }

  const trip = normalizeItinerary(payload);
  applyTripState(trip);
}

/* ------------------------------- History -------------------------------- */

function renderHistory(trips) {
  if (!historyList) return;
  historyList.innerHTML = '';

  if (!Array.isArray(trips) || trips.length === 0) {
    const message = travelerScope.isDefault()
      ? 'No trips saved yet. Generate one to get started!'
      : 'No trips saved for this key yet. Save a trip to see it here.';
    historyList.innerHTML = `<p>${escapeHtml(message)}</p>`;
    return;
  }

  trips.forEach((trip) => {
    const clone = historyTemplate.content.firstElementChild.cloneNode(true);
    const cityLabel = Array.isArray(trip?.cities_of_interest) && trip.cities_of_interest.length
      ? trip.cities_of_interest.join(' • ')
      : (trip?.city_of_interest ?? '—');
    const title = `${cityLabel} from ${trip?.start_location ?? '—'}`;
    const when = trip?.created_at ? new Date(trip.created_at).toLocaleString() : '';

    clone.querySelector('h3').textContent = title;
    clone.querySelector('.history__meta').textContent = when;
    clone.querySelector('.history__summary').textContent = trip?.summary || '—';
    clone.querySelector('[data-action="load"]').dataset.tripId = trip?.id ?? '';
    historyList.appendChild(clone);
  });
}

async function loadHistory() {
  try {
    const trips = await fetchJSON('api/list_trips.php');
    renderHistory(trips);
  } catch (e) {
    console.error(e);
    if (historyList) {
      const message = travelerScope.isDefault()
        ? 'Unable to load saved trips. Please try again later.'
        : 'Unable to load trips for this key. Please try again later.';
      historyList.innerHTML = `<p>${escapeHtml(message)}</p>`;
    }
  }
}

/* ----------------------------- Event wiring ---------------------------- */

let scopeInitialized = false;
travelerScope.onChange((key) => {
  updateAccountStatus(key);
  if (scopeInitialized) {
    loadHistory();
  } else {
    scopeInitialized = true;
  }
});

if (accountGenerateButton) {
  accountGenerateButton.addEventListener('click', async () => {
    const newKey = travelerScope.generate();
    const applied = travelerScope.set(newKey);
    if (accountKeyInput) {
      accountKeyInput.focus();
      accountKeyInput.select();
    }

    if (
      applied
      && typeof navigator !== 'undefined'
      && navigator.clipboard
      && typeof navigator.clipboard.writeText === 'function'
    ) {
      try {
        await navigator.clipboard.writeText(applied);
        announceAccount('Generated a new private key and copied it to your clipboard.');
        return;
      } catch (error) {
        console.warn('Unable to copy private key automatically', error);
      }
    }

    announceAccount('Generated a new private key. Copy it somewhere safe.');
  });
}

if (accountApplyButton) {
  accountApplyButton.addEventListener('click', () => {
    const value = accountKeyInput ? accountKeyInput.value : '';
    const applied = travelerScope.set(value);
    if (!applied) announceAccount('Using the shared demo space.');
    else announceAccount('Private key applied. History will refresh.');
  });
}

if (accountResetButton) {
  accountResetButton.addEventListener('click', () => {
    if (travelerScope.isDefault()) {
      announceAccount('Already using the shared demo space.');
      return;
    }
    travelerScope.clear();
    if (accountKeyInput) accountKeyInput.value = '';
    announceAccount('Switched back to the shared demo space.');
  });
}

if (accountCopyButton) {
  accountCopyButton.addEventListener('click', async () => {
    const key = travelerScope.current();
    if (!key) {
      announceAccount('No private key to copy yet.');
      return;
    }

    if (
      typeof navigator === 'undefined'
      || !navigator.clipboard
      || typeof navigator.clipboard.writeText !== 'function'
    ) {
      announceAccount('Clipboard access unavailable. Select the key and copy it manually.');
      return;
    }

    try {
      await navigator.clipboard.writeText(key);
      announceAccount('Private key copied to clipboard.');
    } catch (error) {
      console.warn('Clipboard write failed', error);
      announceAccount('Unable to copy automatically. Select and copy the key manually.');
    }
  });
}

if (accountKeyInput) {
  accountKeyInput.addEventListener('keydown', (event) => {
    if (event.key === 'Enter') {
      event.preventDefault();
      if (accountApplyButton) accountApplyButton.click();
    }
  });
}

if (citiesContainer) {
  const wrappers = Array.from(citiesContainer.querySelectorAll('.field__repeatable'));
  if (!wrappers.length) {
    const field = createCityField();
    if (field) citiesContainer.appendChild(field);
  } else {
    wrappers.forEach((wrapper) => {
      if (!wrapper.querySelector('input[name="cities_of_interest[]"]')) {
        const input = document.createElement('input');
        input.type = 'text';
        input.name = 'cities_of_interest[]';
        input.required = true;
        input.placeholder = 'e.g., Savannah, GA';
        wrapper.appendChild(input);
      }
      if (!wrapper.querySelector('[data-action="remove-city"]')) {
        const remove = document.createElement('button');
        remove.type = 'button';
        remove.className = 'btn btn--ghost field__remove';
        remove.textContent = 'Remove';
        remove.dataset.action = 'remove-city';
        wrapper.appendChild(remove);
      }
    });
  }
  refreshCityRemoveButtons();
}

if (addCityButton && citiesContainer) {
  addCityButton.addEventListener('click', () => {
    const field = createCityField();
    if (!field) return;
    citiesContainer.appendChild(field);
    refreshCityRemoveButtons();
    const input = field.querySelector('input');
    if (input) input.focus();
  });
}

if (citiesContainer) {
  citiesContainer.addEventListener('click', (event) => {
    const button = event.target.closest('button[data-action="remove-city"]');
    if (!button) return;
    const wrapper = button.closest('.field__repeatable');
    if (!wrapper) return;
    const wrappers = Array.from(citiesContainer.querySelectorAll('.field__repeatable'));
    if (wrappers.length <= 1) return;
    wrapper.remove();
    refreshCityRemoveButtons();
  });
}

if (form) {
  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    const formData = new FormData(form);

    if (saveButton) saveButton.disabled = true;
    if (resultsSection) resultsSection.hidden = true;
    if (itineraryContainer) itineraryContainer.innerHTML = '<p>Generating your itinerary...</p>';
    resetMap('Generating map preview...');
    if (editorSection) editorSection.hidden = true;
    clearShareFeedback();

    try {
      const raw = await fetchJSON('api/generate_trip.php', { method: 'POST', body: formData });
      renderItinerary(raw); // normalizes & sets state
    } catch (e) {
      if (itineraryContainer) {
        itineraryContainer.innerHTML = `<p class="error">${e.message || 'Something went wrong while generating your trip.'}</p>`;
      }
      if (resultsSection) resultsSection.hidden = false;
      if (saveButton) {
        saveButton.disabled = true;
        delete saveButton.dataset.itineraryPayload;
        delete saveButton.dataset.itineraryId;
      }
      resetMap('Map preview unavailable for this itinerary.');
    }
  });
}

if (saveButton) {
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
        currentTrip.id = response?.id ?? currentTrip.id;
        saveButton.dataset.itineraryPayload = JSON.stringify(currentTrip);
        if (currentTrip.id) saveButton.dataset.itineraryId = currentTrip.id;
      }

      await loadHistory();
      updateActionButtons();

      const savedLabel = (response && response.updated) ? 'Updated!' : 'Saved!';
      saveButton.textContent = savedLabel;
      setTimeout(() => {
        saveButton.textContent = 'Save Trip';
        saveButton.disabled = false;
      }, 2000);
    } catch (e) {
      console.error(e);
      saveButton.disabled = false;
      saveButton.textContent = 'Try Saving Again';
    }
  });
}

if (historyList) {
  historyList.addEventListener('click', async (event) => {
    const button = event.target.closest('button[data-action="load"]');
    if (!button) return;

    const tripId = button.dataset.tripId;
    if (!tripId) return;

    if (saveButton) saveButton.disabled = true;
    if (resultsSection) resultsSection.hidden = true;
    if (itineraryContainer) itineraryContainer.innerHTML = '<p>Loading itinerary...</p>';
    resetMap('Loading map preview...');
    clearShareFeedback();
    if (editorSection) editorSection.hidden = true;

    try {
      const raw = await fetchJSON(`api/get_trip.php?id=${encodeURIComponent(tripId)}`);
      renderItinerary(raw);
    } catch (e) {
      if (itineraryContainer) {
        itineraryContainer.innerHTML = `<p class="error">${e.message || 'Unable to load itinerary.'}</p>`;
      }
      if (resultsSection) resultsSection.hidden = false;
      if (saveButton) {
        saveButton.disabled = true;
        delete saveButton.dataset.itineraryPayload;
        delete saveButton.dataset.itineraryId;
      }
      resetMap('Map preview unavailable for this itinerary.');
    }
  });
}

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
    if (!input || !input.dataset || !input.dataset.field) return;
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
    if (targetIndex < 0 || targetIndex >= currentTrip.stops.length) return;

    const tmp = currentTrip.stops[index];
    currentTrip.stops[index] = currentTrip.stops[targetIndex];
    currentTrip.stops[targetIndex] = tmp;
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
    if (saveButton) saveButton.dataset.itineraryPayload = JSON.stringify(currentTrip);
    updateMap(currentTrip).catch((e) => {
      console.error('Map rendering failed:', e);
      resetMap('Map preview unavailable for this itinerary.');
    });
  });
}

if (downloadIcsButton) downloadIcsButton.addEventListener('click', () => downloadTrip('ics'));
if (downloadPdfButton) downloadPdfButton.addEventListener('click', () => downloadTrip('pdf'));

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
    } catch (e) {
      console.error(e);
      showShareFeedback(e.message || 'Unable to create share link.', { error: true });
    } finally {
      shareButton.textContent = originalLabel;
      shareButton.disabled = false;
      updateActionButtons();
    }
  });
}

/* --------------------------------- Init -------------------------------- */

updateActionButtons();
loadHistory();