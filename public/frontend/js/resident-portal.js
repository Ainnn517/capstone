// Handles report submission, tracking, and resident notifications.

// API routes used by the resident portal.
const API_ENDPOINTS = {
  getReports: '/api/reports',
  getNotifications: '/api/notifications',
  markNotificationRead: '/api/notifications/{id}/read',
  createReport: '/api/reports',
  session: '/api/session',
  logout: '/api/logout'
};

let googleMapsLoaderPromise;

function loadGoogleMapsApi(apiKey) {
  if (window.google?.maps) {
    return Promise.resolve();
  }

  if (!googleMapsLoaderPromise) {
    googleMapsLoaderPromise = new Promise((resolve, reject) => {
      const script = document.createElement('script');
      script.src = `https://maps.googleapis.com/maps/api/js?key=${encodeURIComponent(apiKey)}&loading=async&v=weekly`;
      script.async = true;
      window.gm_authFailure = () => {
        const status = document.getElementById('mapStatus');
        if (status) {
          status.textContent = 'Google Maps authorization failed. Check the API key, billing, and allowed website referrers.';
        }
      };
      script.onerror = () => {
        googleMapsLoaderPromise = null;
        reject(new Error('Google Maps could not be loaded. Check your API key, enabled API, billing, and network connection.'));
      };
      script.onload = () => {
        if (window.google?.maps) {
          resolve();
        } else {
          googleMapsLoaderPromise = null;
          reject(new Error('Google Maps did not initialize. Check that the Maps JavaScript API is enabled for your key.'));
        }
      };
      document.head.appendChild(script);
    });
  }

  return googleMapsLoaderPromise;
}

// Send a request to the Laravel API.
async function apiRequest(action, payload = {}, method = 'POST') {
  const isFormData = payload instanceof FormData;
  const options = {
    method,
    credentials: 'same-origin',
    headers: {
      'Accept': 'application/json',
      'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
    }
  };

  if (method !== 'GET') {
    if (isFormData) {
      options.body = payload;
    } else {
      options.headers['Content-Type'] = 'application/json';
      options.body = JSON.stringify(payload);
    }
  }

  let endpoint = API_ENDPOINTS[action].replace('{id}', encodeURIComponent(payload.id || ''));
  if (method === 'GET' && Object.keys(payload).length > 0) {
    const query = new URLSearchParams(payload);
    endpoint += `?${query.toString()}`;
  }
  const response = await fetch(endpoint, options);
  if (!(response.headers.get('content-type') || '').includes('application/json')) {
    throw new Error('The Laravel API did not respond. Start the app with Artisan and open the Laravel server URL.');
  }

  let data;
  try {
    data = await response.json();
  } catch {
    throw new Error('The Laravel API returned an empty or invalid response. Check the Laravel server output and database connection.');
  }

  if (!response.ok || !data.success) {
    throw new Error(data.message || 'Request failed.');
  }

  return data;
}

// Escape text before placing it in HTML.
function escapeHTML(value) {
  return String(value ?? '').replace(/[&<>"']/g, character => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
  })[character]);
}

// Set up resident portal controls and report display.
function initResidentPortalPage() {
  const STAGES = ['Submitted', 'Validated', 'Dispatched', 'Resolved'];
  let currentReports = [];
  let reportFilter = 'active';
  let reportPage = 1;
  let reportPagination = { currentPage: 1, lastPage: 1, total: 0 };

  // Village / Phase -> list of streets
  const LOCATIONS = {
    'St. Joseph Village 10 - Phase 1': ['Laurel Street', 'Coconut Street', 'Star Apple Street', 'Mango Street', 'Sandalwood Street', 'Chestnut Street'],
    'St. Joseph Village 10 - Phase 2': ['Apitong Street', 'Antipolo Street', 'Lawaan Street', 'Kamagong Street', 'Tindalo Street'],
    'St. Joseph Village 10 - Phase 3': ['Almond Street', 'Birch Street', 'Cedar Street', 'Mulberry Street', 'Cherry Street', 'Pine Street', 'Walnut Street', 'Pear Street', 'Cashew Street', 'Camachile Street', 'Molave Street', 'Neem Street', 'Maple Street', 'Linden Street'],
    'St. Joseph Village 9': ['Gumamela Street', 'Dahua Street', 'Daffodil Street', 'Chrysanthemum Street', 'Carnation Street', 'Camellia Street', 'Calendula Street', 'Bougainvillea Street', 'Buttercup Street', 'Aster Street', 'Everlasting Street', 'Iris Street', 'Lotus Street', 'Lobelia Street', 'Meadowsweet Street', 'Magnolia Street', 'Marigold Street', 'Sampaguita Street', 'Speedwell Street', 'Sunflower Street', 'Tulip Street', 'Jade Street', 'Lavender Street', 'Lily Street', 'Rose Street', 'Orchid Street', 'Navidad Avenue', 'R. Magsaysay Avenue'],
    'St. Joseph Ridgeview - Phase 1': [],
    'St. Joseph Ridgeview - Phase 2': [],
    'Southern Heights': []
  };

  const phaseSel = document.getElementById('phase');
  const streetSel = document.getElementById('purok');

  Object.keys(LOCATIONS).forEach(p => phaseSel.add(new Option(p, p)));

  // Update the street list when the resident chooses an area.
  function updateStreets() {
    const streets = LOCATIONS[phaseSel.value] || [];
    streetSel.length = 0;

    if (!phaseSel.value) {
      streetSel.add(new Option('Choose village / phase first', ''));
      streetSel.disabled = true;
      streetSel.required = false;
      locationInput.required = false;
      locationInput.placeholder = 'e.g. Near Barangay Hall';
    } else if (streets.length === 0) {
      streetSel.add(new Option('No street list for this area', ''));
      streetSel.disabled = true;
      streetSel.required = false;
      locationInput.required = true;
      locationInput.placeholder = 'Enter a specific landmark or location marker';
    } else {
      streetSel.add(new Option('Choose street', ''));
      streets.forEach(s => streetSel.add(new Option(s, s)));
      streetSel.disabled = false;
      streetSel.required = true;
      locationInput.required = false;
      locationInput.placeholder = 'e.g. Near Barangay Hall';
    }
  }

  phaseSel.addEventListener('change', updateStreets);

  // Load the resident's reports from the server.
  async function syncIssuesFromServer() {
    const view = reportFilter === 'active' ? 'current' : reportFilter;
    return apiRequest('getReports', { view, page: reportPage }, 'GET');
  }

  // Refresh report data and update the page.
  async function render() {
    const issueCountBadge = document.getElementById('issueCount');
    const issuesListContainer = document.getElementById('issuesList');
    try {
      const result = await syncIssuesFromServer();
      currentReports = result.reports || [];
      reportPagination = result.pagination;
    } catch (error) {
      issueCountBadge.textContent = 'Unavailable';
      issuesListContainer.textContent = error.message || 'Could not load reports. Please check your connection and sign in again.';
      return;
    }

    renderReports();
    try {
      updateNotifications(await apiRequest('getNotifications', {}, 'GET'));
    } catch (error) {
      const notifContainer = document.getElementById('notifications');
      notifContainer.textContent = `Notifications unavailable: ${error.message}`;
      document.getElementById('notifCount').textContent = '!';
    }
  }

  // Show reports that match the selected filter.
  function renderReports() {
    const issuesListContainer = document.getElementById('issuesList');
    const issueCountBadge = document.getElementById('issueCount');
    const filterLabel = reportFilter === 'past' ? 'past report' : reportFilter === 'active' ? 'current report' : 'report';
    issueCountBadge.textContent = `${reportPagination.total} ${filterLabel}${reportPagination.total === 1 ? '' : 's'}`;

    if (reportPagination.total === 0) {
      const emptyMessage = reportFilter === 'active'
        ? 'You have no open reports right now. Select Past Reports to review completed reports.'
        : reportFilter === 'past'
          ? 'Resolved and rejected reports will appear here.'
          : 'Submit an issue above and it will appear here with its tracking ID and status.';
      issuesListContainer.innerHTML = `<div class="empty-reports">${escapeHTML(emptyMessage)}</div>`;
    } else {
      issuesListContainer.innerHTML = currentReports.map(createReportCardHTML).join('');
    }
    renderReportPagination();
  }

  function renderReportPagination() {
    const container = document.getElementById('reportPagination');
    const { currentPage, lastPage, total } = reportPagination;
    container.innerHTML = `
      <button class="btn btn-sm btn-outline-secondary" type="button" data-report-page="${currentPage - 1}" ${currentPage <= 1 ? 'disabled' : ''}>Previous</button>
      <span class="small text-secondary">Page ${currentPage} of ${lastPage} · ${total} reports</span>
      <button class="btn btn-sm btn-outline-secondary" type="button" data-report-page="${currentPage + 1}" ${currentPage >= lastPage ? 'disabled' : ''}>Next</button>
    `;
  }

  // Build the HTML for one report card.
  function createReportCardHTML(item) {
    const currentStageIndex = STAGES.indexOf(item.stage);

    const priorityClass = item.priority === 'Critical' ? 'soft-red' :
      item.priority === 'High' ? 'soft-yellow' : 'soft-blue';

    const workflowHTML = item.stage === 'Rejected'
      ? '<span class="badge-soft soft-red">Report Rejected</span>'
      : `<div class="workflow">${STAGES.map((stageName, index) => {
      const isDone = index < currentStageIndex;
      const isActive = index === currentStageIndex;
      return `
        <div class="step ${isDone ? 'done' : ''} ${isActive ? 'active' : ''}">
          <div class="step-dot">${isDone ? '<i class="fi fi-rr-check" aria-hidden="true"></i>' : index + 1}</div>
          <div class="step-label">${stageName}</div>
        </div>
      `;
    }).join('')}</div>`;

    return `
      <article class="issue-card">
        <div class="issue-card-header">
          <div>
            <span class="issue-tracking">${escapeHTML(item.tracking)}</span>
            <div class="issue-meta">
              ${escapeHTML(item.category)} · ${escapeHTML(item.purok)} · ${new Date(item.created).toLocaleString()}
            </div>
          </div>
          <span class="badge-soft ${priorityClass}">${item.priority || 'Normal'} Priority</span>
        </div>

        <p class="issue-description">${escapeHTML(item.description)}</p>

        ${item.attachmentId ? `<img class="issue-attachment" src="/api/attachments/${encodeURIComponent(item.attachmentId)}" alt="Report attachment" loading="lazy">` : ''}

        ${workflowHTML}

        <div class="issue-status-note">
          <strong>Latest Status:</strong> ${escapeHTML(item.updatedMessage || 'Report received and queued for administrative validation.')}
        </div>
      </article>
    `;
  }

  // Show the latest report updates and notification count.
  function updateNotifications(result) {
    const notifContainer = document.getElementById('notifications');
    const notifBadge = document.getElementById('notifCount');
    const notifications = result.notifications || [];
    if (notifications.length === 0) {
      notifContainer.innerHTML = '<div class="text-secondary small">No new updates available yet.</div>';
    } else {
      notifContainer.innerHTML = notifications.map(notification => `
        <div class="notification mb-2 ${notification.isRead ? 'text-secondary' : ''}">
          <strong>${escapeHTML(notification.tracking)}</strong>
          <div class="small">${escapeHTML(notification.message)}</div>
          <div class="small text-secondary">${new Date(notification.timestamp).toLocaleString()}</div>
          ${notification.isRead ? '<span class="small">Read</span>' : `<button class="btn btn-sm btn-link p-0" type="button" data-mark-notification-read="${escapeHTML(notification.id)}">Mark as read</button>`}
        </div>
      `).join('');
    }
    notifBadge.textContent = result.unreadCount;
  }

  document.getElementById('notifications').addEventListener('click', async event => {
    const button = event.target.closest('[data-mark-notification-read]');
    if (!button) return;
    try {
      await apiRequest('markNotificationRead', { id: button.dataset.markNotificationRead });
      updateNotifications(await apiRequest('getNotifications', {}, 'GET'));
    } catch (error) {
      window.alert(error.message);
    }
  });

  document.getElementById('reportPagination').addEventListener('click', event => {
    const button = event.target.closest('[data-report-page]');
    if (!button || button.disabled) return;
    reportPage = Number(button.dataset.reportPage);
    render();
  });

  const imageInput = document.getElementById('image');
  const imagePreview = document.getElementById('preview');

  // Preview the selected report image.
  imageInput.addEventListener('change', () => {
    const file = imageInput.files[0];
    if (file) {
      const reader = new FileReader();
      reader.onload = () => {
        imagePreview.src = reader.result;
        imagePreview.classList.remove('d-none');
      };
      reader.readAsDataURL(file);
    } else {
      imagePreview.classList.add('d-none');
    }
  });

  const geoBtn = document.getElementById('geoBtn');
  const geoMsg = document.getElementById('geoMsg');
  const locationInput = document.getElementById('location');
  const locationMapDialog = document.getElementById('locationMapDialog');
  const mapCanvas = document.getElementById('locationMap');
  const mapStatus = document.getElementById('mapStatus');
  const mapApiKey = document.querySelector('meta[name="google-maps-api-key"]').content.trim();
  const defaultMapCenter = { lat: 14.8136, lng: 121.045 };
  let gpsCoordinates = null;
  let locationMap = null;
  let locationMarker = null;

  function setSelectedLocation(latitude, longitude) {
    gpsCoordinates = { latitude, longitude };
    const position = { lat: latitude, lng: longitude };
    if (!locationMarker) {
      locationMarker = new google.maps.Marker({ map: locationMap, draggable: true });
      locationMarker.addListener('dragend', event => {
        setSelectedLocation(event.latLng.lat(), event.latLng.lng());
      });
    }
    locationMarker.setPosition(position);
    mapStatus.textContent = `Selected location: ${latitude.toFixed(5)}, ${longitude.toFixed(5)}. Click or drag the marker to adjust it.`;
    geoMsg.textContent = `Map location attached: ${latitude.toFixed(5)}, ${longitude.toFixed(5)}`;
  }

  function initializeLocationMap() {
    const center = gpsCoordinates
      ? { lat: gpsCoordinates.latitude, lng: gpsCoordinates.longitude }
      : defaultMapCenter;

    locationMap = new google.maps.Map(mapCanvas, {
      center,
      zoom: gpsCoordinates ? 17 : 14,
      mapTypeControl: false,
      streetViewControl: false,
      fullscreenControl: false
    });

    locationMap.addListener('click', event => {
      if (event.latLng) {
        setSelectedLocation(event.latLng.lat(), event.latLng.lng());
      }
    });

    if (gpsCoordinates) {
      setSelectedLocation(gpsCoordinates.latitude, gpsCoordinates.longitude);
    }
  }

  geoBtn.addEventListener('click', async () => {
    if (!mapApiKey) {
      geoMsg.textContent = 'Google Maps is not configured. Add GOOGLE_MAPS_API_KEY to .env and clear the Laravel config cache.';
      return;
    }

    geoMsg.textContent = 'Loading Google Maps...';
    try {
      await loadGoogleMapsApi(mapApiKey);
      locationMapDialog.showModal();
      if (!locationMap) {
        initializeLocationMap();
      } else {
        const center = gpsCoordinates
          ? { lat: gpsCoordinates.latitude, lng: gpsCoordinates.longitude }
          : locationMap.getCenter();
        locationMap.setCenter(center);
      }
      google.maps.event.trigger(locationMap, 'resize');
      mapStatus.textContent = gpsCoordinates
        ? `Selected location: ${gpsCoordinates.latitude.toFixed(5)}, ${gpsCoordinates.longitude.toFixed(5)}. Click or drag the marker to adjust it.`
        : 'Map centered near San Jose del Monte. Click the map to place a marker, or drag it to adjust the location.';
      geoMsg.textContent = '';
    } catch (error) {
      geoMsg.textContent = error.message;
    }
  });

  document.getElementById('closeMapBtn').addEventListener('click', () => {
    locationMapDialog.close();
  });

  document.getElementById('confirmLocationBtn').addEventListener('click', () => {
    if (!gpsCoordinates) {
      mapStatus.textContent = 'Click the map to select the incident location before continuing.';
      return;
    }
    locationMapDialog.close();
  });

  document.getElementById('currentLocationBtn').addEventListener('click', () => {
    if (!navigator.geolocation) {
      mapStatus.textContent = 'Geolocation is not supported by your browser. Click the map to place the marker manually.';
      return;
    }
    mapStatus.textContent = 'Acquiring your current location...';

    navigator.geolocation.getCurrentPosition(
      (position) => {
        locationMap.setCenter({
          lat: position.coords.latitude,
          lng: position.coords.longitude
        });
        locationMap.setZoom(17);
        setSelectedLocation(position.coords.latitude, position.coords.longitude);
      },
      () => {
        mapStatus.textContent = 'Unable to fetch your location. Please allow location access or click the map to place the marker manually.';
      },
      { enableHighAccuracy: true, timeout: 10000 }
    );
  });

  // Validate and submit a new report.
  document.getElementById('issueForm').addEventListener('submit', async function (e) {
    e.preventDefault();

    const categoryValue = document.getElementById('category').value.trim();
    const phase = phaseSel.value;
    const street = streetSel.value.trim();
    const description = document.getElementById('description').value.trim();
    const landmark = locationInput.value.trim();
    const streets = LOCATIONS[phase] || [];

    if (!categoryValue) {
      this.classList.add('was-validated');
      document.getElementById('submitAlert').innerHTML = '<div class="alert alert-danger" role="alert">Select an issue category.</div>';
      document.getElementById('category').focus();
      return;
    }

    if (!phase) {
      this.classList.add('was-validated');
      document.getElementById('submitAlert').innerHTML = '<div class="alert alert-danger" role="alert">Choose the village or phase.</div>';
      phaseSel.focus();
      return;
    }

    if (streets.length > 0 && !street) {
      this.classList.add('was-validated');
      document.getElementById('submitAlert').innerHTML = '<div class="alert alert-danger" role="alert">Select a street for the chosen village.</div>';
      streetSel.focus();
      return;
    }

    if (streets.length === 0 && !landmark) {
      this.classList.add('was-validated');
      document.getElementById('submitAlert').innerHTML = '<div class="alert alert-danger" role="alert">Add a landmark when the selected area has no street list.</div>';
      locationInput.focus();
      return;
    }

    if (description.length < 1 || description.length > 500) {
      this.classList.add('was-validated');
      document.getElementById('submitAlert').innerHTML = '<div class="alert alert-danger" role="alert">The incident description must be between 1 and 500 characters.</div>';
      document.getElementById('description').focus();
      return;
    }

    document.getElementById('submitAlert').innerHTML = '';

    try {
      const fullLocation = street ? `${street}, ${phase}` : phase;

      const payload = new FormData();
      payload.append('category', categoryValue);
      payload.append('purok', fullLocation);
      payload.append('description', description);
      payload.append('location', landmark);
      if (gpsCoordinates) {
        payload.append('latitude', gpsCoordinates.latitude);
        payload.append('longitude', gpsCoordinates.longitude);
      }
      if (imageInput.files[0]) payload.append('image', imageInput.files[0]);

      const result = await apiRequest('createReport', payload);

      this.reset();
      updateStreets();
      gpsCoordinates = null;
      if (locationMarker) {
        locationMarker.setMap(null);
        locationMarker = null;
      }
      this.classList.remove('was-validated');
      imagePreview.classList.add('d-none');
      geoMsg.textContent = '';

      document.getElementById('submitAlert').innerHTML = `
        <div class="alert alert-success alert-dismissible fade show" role="alert">
          <strong>Report Submitted!</strong> Your tracking ID is <strong>${result.tracking}</strong>.
          <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
      `;

      await render();
    } catch (error) {
      document.getElementById('submitAlert').innerHTML = `<div class="alert alert-danger" role="alert">${escapeHTML(error.message)}</div>`;
    }
  });

  // Check the session and start loading resident reports.
  async function startPortal() {
    try {
      const result = await apiRequest('session', {}, 'GET');
      if (result.user.role !== 'resident') {
        window.location.href = result.user.role === 'admin' ? 'admin-dashboard.html' : 'index.html';
        return;
      }
      document.getElementById('residentName').textContent = `Welcome, ${result.user.username}`;
      await render();
      setInterval(render, 15000);
    } catch (error) {
      window.location.href = 'index.html';
    }
  }

  // Scroll to notifications when the notification button is clicked.
  document.getElementById('notifBtn').addEventListener('click', () => {
    document.getElementById('notifications').scrollIntoView({ behavior: 'smooth' });
  });

  // Change which report group is shown.
  document.querySelectorAll('[data-report-filter]').forEach(button => {
    button.addEventListener('click', () => {
      reportFilter = button.dataset.reportFilter;
      reportPage = 1;
      document.querySelectorAll('[data-report-filter]').forEach(filterButton => {
        const isSelected = filterButton === button;
        filterButton.classList.toggle('active', isSelected);
        filterButton.setAttribute('aria-pressed', String(isSelected));
      });
      render();
    });
  });

  // End the session and return to the login page.
  document.getElementById('logoutBtn').addEventListener('click', async () => {
    const confirmed = window.confirm('Are you sure you want to log out?');
    if (confirmed) {
      try {
        await apiRequest('logout');
      } finally {
        localStorage.removeItem('birResidentName');
        window.location.href = 'index.html';
      }
    }
  });

  startPortal();
}

initResidentPortalPage();