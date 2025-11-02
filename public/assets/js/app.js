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
const generationStatus = document.getElementById('generation-status');
const editorSection = document.getElementById('itinerary-editor');
const editorStops = document.getElementById('editor-stops');
const addStopButton = document.getElementById('add-stop');
const applyEditsButton = document.getElementById('apply-edits');
const citiesContainer = document.getElementById('cities-of-interest');
const addCityButton = document.getElementById('add-city');
const accountStatus = document.getElementById('account-status');
const accountForm = document.getElementById('account-form');
const accountEmailInput = document.getElementById('account-email');
const accountPasswordInput = document.getElementById('account-password');
const accountLoginButton = document.getElementById('account-login');
const accountRegisterButton = document.getElementById('account-register');
const accountLogoutButton = document.getElementById('account-logout');
const startLocationInput = document.getElementById('start_location');
const useCurrentLocationButton = document.getElementById('use-current-location');
const startLocationStatus = document.getElementById('start-location-status');

let mapInstance = null;
let mapMarkers = [];
let routeLayer = null;
const geocodeCache = new Map();
const liveLookupCache = new Map();
let currentTrip = null;
let editorPendingChanges = false;
const useCurrentLocationDefaultLabel = useCurrentLocationButton
  ? (useCurrentLocationButton.textContent || '').trim() || 'Use My Location'
  : 'Use My Location';
let startLocationStatusTimeout = null;

const generationAdRotation = [
  {
    headline: 'Need a website without the headaches?',
    body: 'I’ll design, build, host, and maintain it—all for $50/month. Your domain, your brand, done for you.',
    linkText: 'Start today',
    url: 'https://developer.shanemichel.net/services/hands-free',
  },
  {
    headline: 'Stop fighting DIY website builders.',
    body: 'Get a pro site, hosting, updates, and support—hands-free for $50/month.',
    linkText: 'Launch now',
    url: 'https://developer.shanemichel.net/services/hands-free',
  },
  {
    headline: 'Website, hosting, updates—done for you.',
    body: '$50/month covers it all so you can focus on the adventure.',
    linkText: 'See what’s included',
    url: 'https://developer.shanemichel.net/services/hands-free',
  },
  {
    headline: 'Design + dev + hosting + care plan in one simple subscription.',
    body: '$50/month and you never touch a line of code.',
    linkText: 'Discover the plan',
    url: 'https://developer.shanemichel.net/services/hands-free',
  },
  {
    headline: 'Overwhelmed by websites? I handle everything—domain, design, hosting, updates—so you don’t have to.',
    body: '$50/month keeps your site fresh while you stay road-trip ready.',
    linkText: 'Get full-service help',
    url: 'https://developer.shanemichel.net/services/hands-free',
  },
];

let generationAdIndex = 0;

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

function nextGenerationAd() {
  if (!generationAdRotation.length) {
    return {
      headline: 'Crafting your itinerary…',
      body: 'Our AI travel host is piecing everything together.',
      linkText: 'Explore more travel inspiration',
      url: 'https://developer.shanemichel.net/services/hands-free',
    };
  }

  const ad = generationAdRotation[generationAdIndex % generationAdRotation.length];
  generationAdIndex = (generationAdIndex + 1) % generationAdRotation.length;
  return ad;
}

function withHttps(url) {
  const value = String(url || '').trim();
  if (!value) return 'https://developer.shanemichel.net/services/hands-free';
  return /^https?:\/\//i.test(value) ? value : `https://${value}`;
}

function normalizeExternalUrl(url) {
  const value = String(url ?? '').trim();
  if (!value) return '';
  return /^https?:\/\//i.test(value) ? value : `https://${value}`;
}

function showGenerationAd() {
  if (!generationStatus) return;
  const ad = nextGenerationAd();
  const href = withHttps(ad.url);
  const lead = 'Our AI travel host is crafting your story-filled road trip. Sit tight while we map the magic!';
  const linkLabel = ad.linkText || ad.url.replace(/^https?:\/\//i, '');
  const bodyHtml = ad.body ? `<p class="generation-status__body">${escapeHtml(ad.body)}</p>` : '';

  generationStatus.innerHTML = `
    <div class="generation-status__spinner" aria-hidden="true"></div>
    <div class="generation-status__content">
      <p class="generation-status__lead">${escapeHtml(lead)}</p>
      <div class="generation-status__ad">
        <p class="generation-status__headline">${escapeHtml(ad.headline)}</p>
        ${bodyHtml}
        <p class="generation-status__link"><a href="${escapeHtml(href)}" target="_blank" rel="noopener">${escapeHtml(linkLabel)}</a></p>
      </div>
    </div>
  `;
  generationStatus.hidden = false;
  generationStatus.classList.remove('generation-status--error');
}

function hideGenerationStatus() {
  if (!generationStatus) return;
  generationStatus.hidden = true;
  generationStatus.classList.remove('generation-status--error');
  generationStatus.innerHTML = '';
}

function showGenerationError(message) {
  if (!generationStatus) return;
  const safeMessage = message ? escapeHtml(message) : 'We hit a snag while generating your road trip.';
  const suggestion = 'Please try again or adjust your cities and preferences.';

  generationStatus.innerHTML = `
    <div class="generation-status__spinner generation-status__spinner--paused" aria-hidden="true"></div>
    <div class="generation-status__content">
      <p class="generation-status__lead">${safeMessage}</p>
      <p class="generation-status__body">${escapeHtml(suggestion)}</p>
    </div>
  `;
  generationStatus.hidden = false;
  generationStatus.classList.add('generation-status--error');
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
    points.push({ label: 'Start', description: safeTrip.start_location, coords: null });
  }

  stops.forEach((stop, index) => {
    const stopLabel = `Stop ${index + 1}: ${stop?.title || stop?.address || 'Scheduled stop'}`;
    const lookup = stop?.address || stop?.title || cityFallback || '';
    const liveCoords = stop?.live_details?.coordinates;
    const coords = liveCoords && Number.isFinite(liveCoords.lat) && Number.isFinite(liveCoords.lon)
      ? { lat: liveCoords.lat, lon: liveCoords.lon }
      : null;
    points.push({ label: stopLabel, description: lookup, coords });
  });

  if (!points.length) {
    resetMap('Map preview unavailable for this itinerary.');
    return;
  }

  const resolved = [];
  for (const p of points) {
    if (p.coords && Number.isFinite(p.coords.lat) && Number.isFinite(p.coords.lon)) {
      resolved.push({ ...p, coords: p.coords });
      continue;
    }
    const target = (p.description && p.description.trim()) ? p.description : p.label;
    if (!target) continue;
    // sequential geocoding to avoid Nominatim rate limits
    const coords = await geocodeLocation(target); // eslint-disable-line no-await-in-loop
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

const AI_CACHE_TTL = 5 * 60 * 1000; // 5 minutes
const AI_CACHE_LIMIT = 24;
const aiResponseCache = new Map();
const aiPendingRequests = new Map();
let aiCacheLastSweep = 0;

function delay(ms) {
  return new Promise((resolve) => setTimeout(resolve, ms));
}

function stableStringify(value) {
  if (value === null || typeof value !== 'object') {
    return JSON.stringify(value);
  }
  if (Array.isArray(value)) {
    return `[${value.map((item) => stableStringify(item)).join(',')}]`;
  }
  const keys = Object.keys(value).sort();
  return `{${keys.map((key) => `${JSON.stringify(key)}:${stableStringify(value[key])}`).join(',')}}`;
}

function cloneForCache(value) {
  if (typeof structuredClone === 'function') {
    try { return structuredClone(value); }
    catch { /* ignore */ }
  }
  try { return JSON.parse(JSON.stringify(value)); }
  catch { return value; }
}

function serializeFormData(formData) {
  const payload = {};
  if (!(formData instanceof FormData)) return payload;

  formData.forEach((rawValue, key) => {
    let value = rawValue;
    if (typeof File !== 'undefined' && value instanceof File) {
      value = { name: value.name, size: value.size, type: value.type };
    } else if (typeof value === 'string') {
      value = value.trim();
    }

    if (Object.prototype.hasOwnProperty.call(payload, key)) {
      const existing = payload[key];
      if (Array.isArray(existing)) existing.push(value);
      else payload[key] = [existing, value];
    } else {
      payload[key] = value;
    }
  });

  return payload;
}

function buildFormData(serialized) {
  const formData = new FormData();
  if (!serialized || typeof serialized !== 'object') return formData;

  Object.entries(serialized).forEach(([key, value]) => {
    if (Array.isArray(value)) {
      value.forEach((entry) => {
        const normalized = entry == null ? '' : String(entry);
        formData.append(key, normalized);
      });
      return;
    }

    if (value && typeof value === 'object') {
      formData.append(key, JSON.stringify(value));
      return;
    }

    const normalized = value == null ? '' : String(value);
    formData.append(key, normalized);
  });

  return formData;
}

function sweepAiCache() {
  const now = Date.now();
  if (now - aiCacheLastSweep < 30_000) return;
  aiCacheLastSweep = now;

  aiResponseCache.forEach((entry, key) => {
    if (!entry || entry.expiresAt <= now) {
      aiResponseCache.delete(key);
    }
  });

  if (aiResponseCache.size <= AI_CACHE_LIMIT) return;

  const entries = Array.from(aiResponseCache.entries())
    .sort((a, b) => a[1].expiresAt - b[1].expiresAt);

  while (entries.length > AI_CACHE_LIMIT) {
    const [key] = entries.shift();
    aiResponseCache.delete(key);
  }
}

function getCachedItinerary(key) {
  const entry = aiResponseCache.get(key);
  if (!entry) return null;

  if (entry.expiresAt <= Date.now()) {
    aiResponseCache.delete(key);
    return null;
  }

  return cloneForCache(entry.payload);
}

function setCachedItinerary(key, payload) {
  aiResponseCache.set(key, {
    payload: cloneForCache(payload),
    expiresAt: Date.now() + AI_CACHE_TTL,
  });
  sweepAiCache();
}

async function requestItinerary(serializedPayload, { attempts = 3, baseDelayMs = 700 } = {}) {
  const maxAttempts = Number.isInteger(attempts) && attempts > 0 ? attempts : 1;
  const baseDelay = Number.isFinite(baseDelayMs) && baseDelayMs > 0 ? baseDelayMs : 500;

  let attempt = 0;
  let lastError = null;

  while (attempt < maxAttempts) {
    attempt += 1;
    try {
      const body = buildFormData(serializedPayload);
      return await fetchJSON('api/generate_trip.php', { method: 'POST', body });
    } catch (error) {
      lastError = error instanceof Error ? error : new Error('Failed to contact the itinerary service.');
      if (attempt >= maxAttempts) break;

      const delayMs = baseDelay * (2 ** (attempt - 1));
      const jitter = Math.floor(Math.random() * baseDelay);
      await delay(delayMs + jitter);
    }
  }

  throw lastError || new Error('Failed to contact the itinerary service.');
}

/* -------------------------- Authentication management ------------------- */

const accountLoginDefaultLabel = accountLoginButton
  ? (accountLoginButton.textContent || '').trim() || 'Sign in'
  : 'Sign in';
const accountRegisterDefaultLabel = accountRegisterButton
  ? (accountRegisterButton.textContent || '').trim() || 'Create account'
  : 'Create account';
const accountLogoutDefaultLabel = accountLogoutButton
  ? (accountLogoutButton.textContent || '').trim() || 'Sign out'
  : 'Sign out';

const auth = (() => {
  let currentUser = null;
  let initialized = false;
  const listeners = new Set();

  function notify() {
    listeners.forEach((listener) => {
      try {
        listener(currentUser, initialized);
      } catch (error) {
        console.error('auth listener error', error);
      }
    });
  }

  async function refresh() {
    try {
      const data = await fetchJSON('api/auth_status.php');
      if (data && typeof data === 'object' && data.authenticated && data.user) {
        currentUser = {
          email: String(data.user.email ?? ''),
          created_at: String(data.user.created_at ?? ''),
          scope: typeof data.scope === 'string' && data.scope ? data.scope : 'public',
        };
      } else {
        currentUser = null;
      }
    } catch (error) {
      console.warn('Unable to load auth status', error);
      currentUser = null;
    } finally {
      initialized = true;
      notify();
    }
  }

  async function login(email, password) {
    const payload = { email, password };
    const data = await fetchJSON('api/login.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload),
    });
    if (data && typeof data === 'object' && data.user) {
      currentUser = {
        email: String(data.user.email ?? ''),
        created_at: String(data.user.created_at ?? ''),
        scope: typeof data.scope === 'string' && data.scope ? data.scope : 'public',
      };
    } else {
      currentUser = null;
    }
    initialized = true;
    notify();
    return currentUser;
  }

  async function register(email, password) {
    const payload = { email, password };
    const data = await fetchJSON('api/register.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload),
    });
    if (data && typeof data === 'object' && data.user) {
      currentUser = {
        email: String(data.user.email ?? ''),
        created_at: String(data.user.created_at ?? ''),
        scope: typeof data.scope === 'string' && data.scope ? data.scope : 'public',
      };
    } else {
      currentUser = null;
    }
    initialized = true;
    notify();
    return currentUser;
  }

  async function logout() {
    await fetchJSON('api/logout.php', { method: 'POST' });
    currentUser = null;
    initialized = true;
    notify();
  }

  function onChange(listener) {
    if (typeof listener !== 'function') return () => {};
    listeners.add(listener);
    if (initialized) {
      try {
        listener(currentUser, initialized);
      } catch (error) {
        console.error('auth listener error', error);
      }
    }
    return () => listeners.delete(listener);
  }

  return {
    refresh,
    login,
    register,
    logout,
    onChange,
    user: () => currentUser,
    isAuthenticated: () => currentUser != null,
    isReady: () => initialized,
    cacheKey: () => (currentUser && currentUser.email ? `user:${currentUser.email}` : 'guest'),
    scope: () => (currentUser && currentUser.scope ? currentUser.scope : 'public'),
  };
})();

let accountAnnouncement = '';
let accountAnnouncementIsError = false;
let accountAnnouncementTimeout = null;
let lastAccountUser = null;

function updateAccountStatus(user) {
  if (!accountStatus) return;

  const isAuthenticated = Boolean(user && user.email);
  const wasAuthenticated = Boolean(lastAccountUser && lastAccountUser.email);

  if (accountEmailInput) {
    if (isAuthenticated) {
      accountEmailInput.value = String(user.email ?? '');
      accountEmailInput.readOnly = true;
      accountEmailInput.disabled = true;
    } else {
      if (wasAuthenticated) accountEmailInput.value = '';
      accountEmailInput.readOnly = false;
      accountEmailInput.disabled = false;
    }
  }

  if (accountPasswordInput) {
    accountPasswordInput.value = '';
    accountPasswordInput.disabled = isAuthenticated;
  }

  if (accountLoginButton) {
    if (isAuthenticated) {
      accountLoginButton.disabled = true;
      accountLoginButton.textContent = 'Signed in';
    } else {
      accountLoginButton.disabled = false;
      accountLoginButton.textContent = accountLoginDefaultLabel;
    }
  }

  if (accountRegisterButton) {
    accountRegisterButton.hidden = isAuthenticated;
    accountRegisterButton.disabled = isAuthenticated;
    if (!isAuthenticated) accountRegisterButton.textContent = accountRegisterDefaultLabel;
  }

  if (accountLogoutButton) {
    accountLogoutButton.hidden = !isAuthenticated;
    accountLogoutButton.disabled = false;
    accountLogoutButton.textContent = accountLogoutDefaultLabel;
  }

  let base;
  if (isAuthenticated) {
    const email = String(user.email ?? '');
    base = `<span>Signed in as <strong>${escapeHtml(email)}</strong>. Trips you save are private to this account.</span>`;
  } else {
    base = `<span>${escapeHtml('Currently using the shared demo space. Sign in to keep your itineraries private across devices.')}</span>`;
  }

  const message = accountAnnouncement
    ? `<span class="account__announcement${accountAnnouncementIsError ? ' account__announcement--error' : ''}">${escapeHtml(accountAnnouncement)}</span>`
    : '';
  accountStatus.innerHTML = `${base}${message}`;

  lastAccountUser = isAuthenticated ? { email: String(user.email ?? '') } : null;
}

function announceAccount(message, { error = false } = {}) {
  accountAnnouncement = message || '';
  accountAnnouncementIsError = Boolean(error);
  if (accountAnnouncementTimeout) {
    clearTimeout(accountAnnouncementTimeout);
    accountAnnouncementTimeout = null;
  }
  if (accountAnnouncement) {
    accountAnnouncementTimeout = setTimeout(() => {
      accountAnnouncement = '';
      accountAnnouncementIsError = false;
      accountAnnouncementTimeout = null;
      updateAccountStatus(auth.user());
    }, 5000);
  }
  updateAccountStatus(auth.user());
}

function setAuthLoading(isLoading) {
  const busy = Boolean(isLoading);

  if (!auth.isAuthenticated()) {
    if (accountEmailInput) accountEmailInput.disabled = busy;
    if (accountPasswordInput) accountPasswordInput.disabled = busy;
  }

  if (accountLoginButton) {
    if (!auth.isAuthenticated()) {
      accountLoginButton.disabled = busy;
      accountLoginButton.textContent = busy ? 'Signing in…' : accountLoginDefaultLabel;
    } else {
      accountLoginButton.disabled = true;
      accountLoginButton.textContent = 'Signed in';
    }
  }

  if (accountRegisterButton) {
    if (!auth.isAuthenticated()) {
      accountRegisterButton.disabled = busy;
      accountRegisterButton.textContent = busy ? 'Creating…' : accountRegisterDefaultLabel;
    } else {
      accountRegisterButton.disabled = true;
      accountRegisterButton.hidden = true;
    }
  }

  if (accountLogoutButton) {
    accountLogoutButton.disabled = busy;
    accountLogoutButton.textContent = busy ? 'Working…' : accountLogoutDefaultLabel;
  }

  if (accountForm) {
    accountForm.classList.toggle('account--loading', busy);
  }
}

async function handleLogin() {
  if (!accountEmailInput || !accountPasswordInput) return;
  const email = (accountEmailInput.value || '').trim();
  const password = accountPasswordInput.value || '';

  if (!email || !password) {
    announceAccount('Enter your email and password to sign in.', { error: true });
    return;
  }

  setAuthLoading(true);
  try {
    await auth.login(email, password);
    announceAccount('Signed in successfully.');
  } catch (error) {
    const message = error instanceof Error ? error.message : 'Unable to sign in right now.';
    announceAccount(message, { error: true });
  } finally {
    setAuthLoading(false);
  }
}

async function handleRegister() {
  if (!accountEmailInput || !accountPasswordInput) return;
  const email = (accountEmailInput.value || '').trim();
  const password = accountPasswordInput.value || '';

  if (!email) {
    announceAccount('Enter an email address to create an account.', { error: true });
    return;
  }

  if (password.length < 8) {
    announceAccount('Password must be at least 8 characters long.', { error: true });
    return;
  }

  setAuthLoading(true);
  try {
    await auth.register(email, password);
    announceAccount('Account created and signed in.');
  } catch (error) {
    const message = error instanceof Error ? error.message : 'Unable to create an account right now.';
    announceAccount(message, { error: true });
  } finally {
    setAuthLoading(false);
  }
}

async function handleLogout() {
  setAuthLoading(true);
  try {
    await auth.logout();
    announceAccount('Signed out. Using the shared demo space.');
  } catch (error) {
    const message = error instanceof Error ? error.message : 'Unable to sign out right now.';
    announceAccount(message, { error: true });
  } finally {
    setAuthLoading(false);
  }
}

async function fetchJSON(url, options = {}) {
  const opts = { credentials: 'same-origin', ...options };
  const headers = new Headers(options && options.headers ? options.headers : undefined);
  if (!headers.has('Accept')) headers.set('Accept', 'application/json');
  opts.headers = headers;

  const response = await fetch(url, opts);
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

function clearStartLocationStatusTimer() {
  if (startLocationStatusTimeout) {
    clearTimeout(startLocationStatusTimeout);
    startLocationStatusTimeout = null;
  }
}

function setStartLocationStatus(message, { error = false, autoHide = false } = {}) {
  if (!startLocationStatus) return;
  clearStartLocationStatusTimer();

  if (!message) {
    startLocationStatus.textContent = '';
    startLocationStatus.hidden = true;
    startLocationStatus.classList.remove('field__status--error');
    return;
  }

  startLocationStatus.textContent = message;
  startLocationStatus.hidden = false;
  if (error) startLocationStatus.classList.add('field__status--error');
  else startLocationStatus.classList.remove('field__status--error');

  if (autoHide && !error) {
    startLocationStatusTimeout = setTimeout(() => {
      setStartLocationStatus('');
    }, 6000);
  }
}

function setCurrentLocationLoading(isLoading) {
  if (!useCurrentLocationButton) return;
  useCurrentLocationButton.disabled = Boolean(isLoading);
  useCurrentLocationButton.textContent = isLoading ? 'Locating…' : useCurrentLocationDefaultLabel;
}

function geolocationSupported() {
  return typeof navigator !== 'undefined' && !!navigator.geolocation;
}

function requestCurrentPosition(options = {}) {
  return new Promise((resolve, reject) => {
    if (!geolocationSupported()) {
      reject(new Error('Geolocation is not supported in this environment.'));
      return;
    }

    navigator.geolocation.getCurrentPosition(resolve, reject, options);
  });
}

async function reverseGeocodeCoordinates(latitude, longitude) {
  try {
    const data = await fetchJSON(
      `api/reverse_geocode.php?lat=${encodeURIComponent(latitude)}&lon=${encodeURIComponent(longitude)}`
    );
    if (data && typeof data.display_name === 'string') {
      const trimmed = data.display_name.trim();
      if (trimmed) return trimmed;
    }
  } catch (error) {
    console.warn('Reverse geocoding failed', error);
  }
  return '';
}

function normalizeLiveSources(sources) {
  if (!sources || typeof sources !== 'object') return {};
  const normalized = {};
  Object.entries(sources).forEach(([key, value]) => {
    if (!value || typeof value !== 'object') return;
    const entry = {};
    Object.entries(value).forEach(([field, fieldValue]) => {
      if (typeof fieldValue === 'string') entry[field] = fieldValue;
      else if (typeof fieldValue === 'number') entry[field] = fieldValue;
    });
    if (Object.keys(entry).length) normalized[key] = entry;
  });
  return normalized;
}

function normalizeLiveWeather(weather) {
  if (!weather || typeof weather !== 'object') return null;
  const temperature = Number.parseFloat(weather.temperature);
  const feelsLike = Number.parseFloat(weather.feels_like);
  return {
    temperature: Number.isFinite(temperature) ? temperature : null,
    feels_like: Number.isFinite(feelsLike) ? feelsLike : null,
    conditions: String(weather.conditions ?? ''),
    updated_at: weather.updated_at ? new Date(weather.updated_at).toISOString() : '',
    source_url: String(weather.source_url ?? ''),
  };
}

function normalizeLiveDetails(details) {
  if (!details || typeof details !== 'object') return null;

  const contact = {
    address: String(details?.contact?.address ?? ''),
    hours: String(details?.contact?.hours ?? ''),
    phone: String(details?.contact?.phone ?? ''),
    website: String(details?.contact?.website ?? ''),
  };

  let coordinates = null;
  if (details.coordinates && typeof details.coordinates === 'object') {
    const lat = Number.parseFloat(details.coordinates.lat);
    const lon = Number.parseFloat(details.coordinates.lon);
    if (Number.isFinite(lat) && Number.isFinite(lon)) coordinates = { lat, lon };
  }

  const rating = Number.parseFloat(details.rating);
  const lastChecked = details.lastChecked ? new Date(details.lastChecked).toISOString() : '';

  return {
    query: String(details.query ?? ''),
    matched: Boolean(details.matched),
    name: String(details.name ?? ''),
    category: String(details.category ?? ''),
    contact,
    coordinates,
    rating: Number.isFinite(rating) ? rating : null,
    price: details.price === undefined || details.price === null ? '' : String(details.price),
    source_url: String(details.source_url ?? ''),
    lastChecked,
    sources: normalizeLiveSources(details.sources),
    weather: normalizeLiveWeather(details.weather),
  };
}

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
      live_details: normalizeLiveDetails(stop?.live_details),
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
    live_details: null,
  };
}

function findLiveContainer(index) {
  if (!itineraryContainer) return null;
  return itineraryContainer.querySelector(`.itinerary-stop__live[data-stop-index="${index}"]`);
}

function renderLiveDetailsInContainer(container, details, { loading = false, error = false, message = null } = {}) {
  if (!container) return;
  container.innerHTML = '';

  const heading = document.createElement('h4');
  heading.className = 'itinerary-stop__live-title';
  heading.textContent = 'Live details';
  container.appendChild(heading);

  if (!details) {
    const status = document.createElement('p');
    status.className = 'itinerary-stop__live-status';
    if (error) status.classList.add('itinerary-stop__live-status--error');
    status.textContent = message || (loading ? 'Fetching live details…' : 'Live details will appear here soon.');
    container.appendChild(status);
    return;
  }

  let ratingHandledInBadge = false;
  const tripAdvisorSource = details.sources?.tripadvisor;
  const bubbleImageUrl = tripAdvisorSource?.rating_image_url || tripAdvisorSource?.rating_image_url_small || '';
  const normalizedBubbleUrl = bubbleImageUrl ? normalizeExternalUrl(bubbleImageUrl) : '';

  if (tripAdvisorSource && normalizedBubbleUrl) {
    ratingHandledInBadge = true;
    const badge = document.createElement('div');
    badge.className = 'tripadvisor-badge';

    const brand = document.createElement('div');
    brand.className = 'tripadvisor-badge__brand';
    const logo = document.createElement('img');
    logo.src = 'assets/img/tripadvisor-ollie-black.svg';
    logo.alt = 'Tripadvisor';
    logo.loading = 'lazy';
    brand.appendChild(logo);
    badge.appendChild(brand);

    const content = document.createElement('div');
    content.className = 'tripadvisor-badge__content';

    const ratingRow = document.createElement('div');
    ratingRow.className = 'tripadvisor-badge__rating';

    const ratingImage = document.createElement('img');
    ratingImage.src = normalizedBubbleUrl;
    ratingImage.alt = 'Tripadvisor bubble rating';
    ratingImage.loading = 'lazy';
    ratingRow.appendChild(ratingImage);

    const badgeRatingValue = Number.isFinite(details.rating)
      ? details.rating
      : Number.parseFloat(tripAdvisorSource.rating);
    if (Number.isFinite(badgeRatingValue)) {
      const score = document.createElement('span');
      score.className = 'tripadvisor-badge__rating-score';
      score.textContent = badgeRatingValue.toFixed(1);
      ratingRow.appendChild(score);
    }

    content.appendChild(ratingRow);

    const meta = document.createElement('p');
    meta.className = 'tripadvisor-badge__meta';
    const reviewCount = Number.parseInt(tripAdvisorSource.review_count, 10);
    const metaParts = ['Tripadvisor'];
    if (Number.isFinite(reviewCount) && reviewCount > 0) {
      metaParts.push(`Based on ${reviewCount.toLocaleString()} reviews`);
    }
    meta.textContent = metaParts.join(' • ');
    content.appendChild(meta);

    if (tripAdvisorSource.url) {
      const link = document.createElement('a');
      link.className = 'tripadvisor-badge__link';
      link.href = normalizeExternalUrl(tripAdvisorSource.url);
      link.target = '_blank';
      link.rel = 'noopener';
      link.textContent = 'See it on Tripadvisor';
      content.appendChild(link);
    }

    badge.appendChild(content);
    container.appendChild(badge);
  }

  const items = [];
  const normalizedName = details.name || '';
  const normalizedQuery = (details.query || '').toLowerCase();
  if (normalizedName && normalizedName.toLowerCase() !== normalizedQuery) {
    items.push({ label: 'Match', value: normalizedName });
  }

  if (details.category) items.push({ label: 'Category', value: details.category });
  if (details.contact?.address) items.push({ label: 'Address', value: details.contact.address });
  if (details.contact?.hours) items.push({ label: 'Hours', value: details.contact.hours });
  if (details.contact?.phone) items.push({ label: 'Phone', value: details.contact.phone });
  if (details.contact?.website) items.push({ label: 'Website', value: details.contact.website, link: true });

  if (Number.isFinite(details.rating) && !ratingHandledInBadge) {
    items.push({ label: 'Rating', value: `${details.rating.toFixed(1)} / 5` });
  }

  if (details.price) items.push({ label: 'Price', value: details.price });

  if (details.weather) {
    const pieces = [];
    if (details.weather.conditions) pieces.push(details.weather.conditions);
    if (Number.isFinite(details.weather.temperature)) pieces.push(`${details.weather.temperature.toFixed(1)}°F`);
    if (Number.isFinite(details.weather.feels_like)) pieces.push(`feels like ${details.weather.feels_like.toFixed(1)}°F`);
    if (pieces.length) items.push({ label: 'Weather', value: pieces.join(', ') });
  }

  if (details.sources?.wikipedia?.extract) {
    items.push({ label: 'About', value: details.sources.wikipedia.extract });
  }

  const wikipediaUrl = details.sources?.wikipedia?.url;
  if (wikipediaUrl && wikipediaUrl !== details.source_url) {
    items.push({ label: 'Wikipedia', value: wikipediaUrl, link: true });
  }

  if (details.source_url) {
    items.push({ label: 'Source', value: details.source_url, link: true });
  }

  const lastChecked = details.lastChecked ? new Date(details.lastChecked).toLocaleString() : '';
  if (lastChecked) {
    items.push({ label: 'Checked', value: lastChecked });
  }

  if (!items.length) {
    const status = document.createElement('p');
    status.className = 'itinerary-stop__live-status';
    status.textContent = message || 'Live data will appear after fetching.';
    container.appendChild(status);
    return;
  }

  const list = document.createElement('ul');
  list.className = 'itinerary-stop__live-list';

  items.forEach((item) => {
    const textValue = typeof item.value === 'number' ? item.value.toString() : String(item.value ?? '');
    if (!textValue) return;
    const li = document.createElement('li');
    const strong = document.createElement('strong');
    strong.textContent = `${item.label}: `;
    li.appendChild(strong);
    if (item.link) {
      const href = normalizeExternalUrl(textValue);
      if (!href) {
        li.appendChild(document.createTextNode(textValue));
      } else {
        const link = document.createElement('a');
        link.href = href;
        link.target = '_blank';
        link.rel = 'noopener noreferrer';
        link.textContent = href.replace(/^https?:\/\//i, '');
        li.appendChild(link);
      }
    } else {
      li.appendChild(document.createTextNode(textValue));
    }
    list.appendChild(li);
  });

  container.appendChild(list);
}

function updateStopLiveSection(index, details, options = {}) {
  const container = findLiveContainer(index);
  if (!container) return;
  renderLiveDetailsInContainer(container, details, options);
}

function shouldRefreshLiveDetails(stop, query) {
  if (!stop?.live_details) return true;
  const details = stop.live_details;
  if ((details.query || '').toLowerCase() !== query.toLowerCase()) return true;
  if (!details.lastChecked) return true;
  const timestamp = Date.parse(details.lastChecked);
  if (Number.isNaN(timestamp)) return true;
  return (Date.now() - timestamp) > 4 * 60 * 60 * 1000;
}

async function fetchLiveDetailsForStop(trip, index) {
  if (!trip || !Array.isArray(trip.stops) || !trip.stops[index]) return;
  const stop = trip.stops[index];
  const query = (stop.title || stop.address || '').trim();
  if (!query) return;

  if (stop.live_details && !shouldRefreshLiveDetails(stop, query)) {
    updateStopLiveSection(index, stop.live_details);
    return;
  }

  updateStopLiveSection(index, null, { loading: true });

  const contextCity = Array.isArray(trip.cities_of_interest) && trip.cities_of_interest.length
    ? trip.cities_of_interest[Math.min(index, trip.cities_of_interest.length - 1)] || trip.cities_of_interest[0]
    : (trip.city_of_interest || '');

  let latitude;
  let longitude;
  const lookup = stop.address || (contextCity ? `${stop.title || ''}, ${contextCity}`.trim() : stop.title || '');
  if (lookup) {
    try {
      const coords = await geocodeLocation(lookup); // eslint-disable-line no-await-in-loop
      if (coords) {
        latitude = coords.lat;
        longitude = coords.lon;
      }
    } catch (error) {
      console.warn('Geocoding failed for live lookup', error);
    }
  }

  const payload = {
    query,
    address: stop.address || '',
    city: contextCity || '',
  };
  if (Number.isFinite(latitude)) payload.latitude = latitude;
  if (Number.isFinite(longitude)) payload.longitude = longitude;

  const cacheKey = JSON.stringify(payload);
  let promise = liveLookupCache.get(cacheKey);
  if (!promise) {
    promise = fetchJSON('api/live_lookup.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload),
    }).then((response) => normalizeLiveDetails(response?.data))
      .catch((error) => {
        liveLookupCache.delete(cacheKey);
        throw error;
      });
    liveLookupCache.set(cacheKey, promise);
  }

  try {
    const details = await promise;
    if (details) {
      trip.stops[index].live_details = details;
      if (currentTrip === trip) currentTrip.stops[index].live_details = details;
      updateStopLiveSection(index, details);
      if (saveButton) saveButton.dataset.itineraryPayload = JSON.stringify(currentTrip);
    } else {
      updateStopLiveSection(index, null, { message: 'No live details found for this stop yet.' });
    }
  } catch (error) {
    console.warn('Live data lookup failed', error);
    updateStopLiveSection(index, null, { error: true, message: 'Live details unavailable right now.' });
  }
}

async function kickoffLiveDetails(trip) {
  if (!trip || !Array.isArray(trip.stops)) return;
  for (let index = 0; index < trip.stops.length; index += 1) {
    const stop = trip.stops[index];
    if (!stop) continue;
    const query = (stop.title || stop.address || '').trim();
    if (!query) continue;
    if (stop.live_details && !shouldRefreshLiveDetails(stop, query)) {
      updateStopLiveSection(index, stop.live_details);
      continue;
    }
    try { // eslint-disable-line no-await-in-loop
      await fetchLiveDetailsForStop(trip, index);
    } catch (error) { // eslint-disable-line no-await-in-loop
      console.warn('Live detail fetch failed', error);
    }
  }
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

    const liveContainer = document.createElement('div');
    liveContainer.className = 'itinerary-stop__live';
    liveContainer.dataset.stopIndex = String(index);
    renderLiveDetailsInContainer(liveContainer, stop?.live_details || null, {
      message: stop?.live_details ? null : 'Live details will appear here soon.',
    });
    article.appendChild(liveContainer);

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

  kickoffLiveDetails(currentTrip).catch((error) => {
    console.warn('Live details refresh failed', error);
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
    const message = auth.isAuthenticated()
      ? 'No trips saved yet. Generate one to get started!'
      : 'No trips saved yet. Trips here are shared with other visitors.';
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
      const message = auth.isAuthenticated()
        ? 'Unable to load your saved trips. Please try again later.'
        : 'Unable to load shared trips right now. Please try again later.';
      historyList.innerHTML = `<p>${escapeHtml(message)}</p>`;
    }
  }
}

/* ----------------------------- Event wiring ---------------------------- */

updateAccountStatus(null);

auth.onChange((user, ready) => {
  updateAccountStatus(user);
  if (ready) {
    loadHistory();
  }
});

auth.refresh();

if (accountForm) {
  accountForm.addEventListener('submit', (event) => {
    event.preventDefault();
    if (auth.isAuthenticated()) {
      announceAccount('Already signed in.');
      return;
    }
    handleLogin();
  });
}

if (accountRegisterButton) {
  accountRegisterButton.addEventListener('click', (event) => {
    event.preventDefault();
    if (auth.isAuthenticated()) {
      announceAccount('Already signed in.');
      return;
    }
    handleRegister();
  });
}

if (accountLogoutButton) {
  accountLogoutButton.addEventListener('click', (event) => {
    event.preventDefault();
    if (!auth.isAuthenticated()) {
      announceAccount('You are already using the shared demo space.');
      return;
    }
    handleLogout();
  });
}

if (startLocationInput) {
  startLocationInput.addEventListener('input', () => {
    setStartLocationStatus('');
  });
}

if (useCurrentLocationButton) {
  if (!geolocationSupported()) {
    useCurrentLocationButton.disabled = true;
    useCurrentLocationButton.title = 'Geolocation not supported in this browser.';
  }

  useCurrentLocationButton.addEventListener('click', async () => {
    if (!geolocationSupported()) {
      setStartLocationStatus(
        'Location services are unavailable in this browser. Please enter your starting point manually.',
        { error: true }
      );
      return;
    }

    setCurrentLocationLoading(true);
    setStartLocationStatus('Locating you...');

    try {
      const position = await requestCurrentPosition({
        enableHighAccuracy: true,
        timeout: 15000,
        maximumAge: 120000,
      });

      const coords = position && position.coords ? position.coords : null;
      const latitude = coords ? Number(coords.latitude) : Number.NaN;
      const longitude = coords ? Number(coords.longitude) : Number.NaN;

      if (!Number.isFinite(latitude) || !Number.isFinite(longitude)) {
        throw new Error('Unable to determine your location. Please enter it manually.');
      }

      let label = await reverseGeocodeCoordinates(latitude, longitude);
      const usingFallback = !label;
      if (usingFallback) {
        label = `${latitude.toFixed(5)}, ${longitude.toFixed(5)}`;
      }

      if (startLocationInput) {
        startLocationInput.value = label;
        startLocationInput.dispatchEvent(new Event('input', { bubbles: true }));
        startLocationInput.dispatchEvent(new Event('change', { bubbles: true }));
      }

      setStartLocationStatus(
        usingFallback
          ? 'Using GPS coordinates—feel free to refine them if needed.'
          : 'Start location updated from your current position.',
        { autoHide: true }
      );
    } catch (error) {
      let message = 'Unable to access your location. Please enter your starting point manually.';
      if (error && typeof error === 'object' && 'code' in error) {
        if (error.code === 1) message = 'Location permission denied. Enter your starting point manually.';
        else if (error.code === 2) message = 'Unable to determine your location. Try again or type it in manually.';
        else if (error.code === 3) message = 'Location request timed out. Try again or enter your starting point manually.';
      } else if (error instanceof Error && error.message) {
        message = error.message;
      }
      setStartLocationStatus(message, { error: true });
    } finally {
      setCurrentLocationLoading(false);
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
    const serialized = serializeFormData(formData);
    const cacheKey = stableStringify({ scope: auth.cacheKey(), payload: serialized });

    if (saveButton) saveButton.disabled = true;
    if (resultsSection) resultsSection.hidden = true;
    if (itineraryContainer) itineraryContainer.innerHTML = '<p>Generating your itinerary...</p>';
    resetMap('Generating map preview...');
    if (editorSection) editorSection.hidden = true;
    clearShareFeedback();
    showGenerationAd();

    let addedPending = false;
    let generationHadError = false;

    try {
      let raw = getCachedItinerary(cacheKey);

      if (!raw) {
        let pending = aiPendingRequests.get(cacheKey);
        if (!pending) {
          pending = requestItinerary(serialized);
          aiPendingRequests.set(cacheKey, pending);
          addedPending = true;
        }

        raw = await pending;
        setCachedItinerary(cacheKey, raw);
      }

      renderItinerary(raw); // normalizes & sets state
    } catch (e) {
      generationHadError = true;
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
      showGenerationError(e.message);
    } finally {
      if (!generationHadError) hideGenerationStatus();
      if (addedPending) aiPendingRequests.delete(cacheKey);
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
    if (currentTrip.stops[index].live_details) {
      delete currentTrip.stops[index].live_details;
      updateStopLiveSection(index, null, { message: 'Live details will refresh after you apply edits.' });
    }
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

    kickoffLiveDetails(currentTrip).catch((error) => {
      console.warn('Live details refresh failed', error);
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