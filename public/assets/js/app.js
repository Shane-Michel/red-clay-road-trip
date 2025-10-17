const form = document.getElementById('trip-form');
const resultsSection = document.getElementById('results');
const itineraryContainer = document.getElementById('itinerary');
const saveButton = document.getElementById('save-trip');
const historyList = document.getElementById('history-list');
const historyTemplate = document.getElementById('history-item-template');

async function fetchJSON(url, options) {
    const response = await fetch(url, options);
    if (!response.ok) {
        const text = await response.text();
        throw new Error(text || 'Unexpected server error');
    }
    return response.json();
}

function renderItinerary(itinerary) {
    itineraryContainer.innerHTML = '';

    if (!itinerary || typeof itinerary !== 'object') {
        itineraryContainer.innerHTML = '<p class="error">Unable to display the itinerary at this time.</p>';
        resultsSection.hidden = false;
        saveButton.disabled = true;
        delete saveButton.dataset.itineraryPayload;
        delete saveButton.dataset.itineraryId;
        return;
    }

    if (typeof itinerary.error === 'string' && itinerary.error.trim() !== '') {
        itineraryContainer.innerHTML = `<p class="error">${itinerary.error}</p>`;
        resultsSection.hidden = false;
        saveButton.disabled = true;
        delete saveButton.dataset.itineraryPayload;
        delete saveButton.dataset.itineraryId;
        return;
    }

    const intro = document.createElement('div');
    intro.className = 'itinerary-intro';
    intro.innerHTML = `
        <p><strong>Route:</strong> ${itinerary.route_overview ?? '—'}</p>
        <p><strong>Total Travel Time:</strong> ${itinerary.total_travel_time ?? '—'}</p>
    `;
    itineraryContainer.appendChild(intro);

    const stops = Array.isArray(itinerary.stops) ? itinerary.stops : [];

    if (!stops.length) {
        const emptyMessage = document.createElement('p');
        emptyMessage.className = 'error';
        emptyMessage.textContent = 'No stops were provided for this itinerary.';
        itineraryContainer.appendChild(emptyMessage);
    }

    stops.forEach((stop, index) => {
        const article = document.createElement('article');
        article.className = 'itinerary-stop';
        article.innerHTML = `
            <h3>Stop ${index + 1}: ${stop.title}</h3>
            <p class="itinerary-stop__meta">${stop.address} &bull; Suggested time: ${stop.duration}</p>
            <p>${stop.description}</p>
            <p><strong>Historical Significance:</strong> ${stop.historical_note}</p>
            <p><strong>Challenge:</strong> ${stop.challenge}</p>
        `;
        itineraryContainer.appendChild(article);
    });

    if (itinerary.additional_tips) {
        const tips = document.createElement('div');
        tips.className = 'itinerary-tips';
        tips.innerHTML = `
            <h3>Travel Tips</h3>
            <p>${itinerary.additional_tips}</p>
        `;
        itineraryContainer.appendChild(tips);
    }

    resultsSection.hidden = false;
    saveButton.disabled = false;
    saveButton.dataset.itineraryId = itinerary.id ?? '';
}

function renderHistory(trips) {
    historyList.innerHTML = '';

    if (!trips.length) {
        historyList.innerHTML = '<p>No trips saved yet. Generate one to get started!</p>';
        return;
    }

    trips.forEach((trip) => {
        const clone = historyTemplate.content.firstElementChild.cloneNode(true);
        clone.querySelector('h3').textContent = `${trip.city_of_interest} from ${trip.start_location}`;
        clone.querySelector('.history__meta').textContent = new Date(trip.created_at).toLocaleString();
        clone.querySelector('.history__summary').textContent = trip.summary;
        clone.querySelector('[data-action="load"]').dataset.tripId = trip.id;
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

    try {
        const itinerary = await fetchJSON('api/generate_trip.php', {
            method: 'POST',
            body: formData,
        });
        renderItinerary(itinerary);
        if (itinerary && typeof itinerary === 'object') {
            saveButton.dataset.itineraryPayload = JSON.stringify(itinerary);
        } else {
            delete saveButton.dataset.itineraryPayload;
        }
    } catch (error) {
        itineraryContainer.innerHTML = `<p class="error">${error.message || 'Something went wrong while generating your trip.'}</p>`;
        resultsSection.hidden = false;
    }
});

saveButton.addEventListener('click', async () => {
    if (!saveButton.dataset.itineraryPayload) {
        return;
    }

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

    try {
        const itinerary = await fetchJSON(`api/get_trip.php?id=${encodeURIComponent(tripId)}`);
        renderItinerary(itinerary);
        if (itinerary && typeof itinerary === 'object') {
            saveButton.dataset.itineraryPayload = JSON.stringify(itinerary);
        } else {
            delete saveButton.dataset.itineraryPayload;
        }
    } catch (error) {
        itineraryContainer.innerHTML = `<p class="error">${error.message || 'Unable to load itinerary.'}</p>`;
        resultsSection.hidden = false;
    }
});

loadHistory();
