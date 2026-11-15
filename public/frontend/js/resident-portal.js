// Handles report submission, tracking, and resident notifications.

// API routes used by the resident portal.
const API_ENDPOINTS = {
  getReferenceData: '/api/reference-data',
  getReports: '/api/reports',
  getNotifications: '/api/notifications',
  markNotificationRead: '/api/notifications/{id}/read',
  getReportHistory: '/api/reports/{id}/history',
  createReport: '/api/reports',
  updateReport: '/api/reports/{id}',
  withdrawReport: '/api/reports/{id}/withdraw',
  confirmReport: '/api/reports/{id}/confirm',
  reopenReport: '/api/reports/{id}/reopen',
  suggestCategory: '/api/triage/category',
  session: '/api/session',
  logout: '/api/logout'
};

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
  const response = await window.csrfFetch(endpoint, options);
  window.handleExpiredSession(response);
  if (!(response.headers.get('content-type') || '').includes('application/json')) {
    throw new Error('The server could not complete that request. Please try again in a moment.');
  }

  let data;
  try {
    data = await response.json();
  } catch {
    throw new Error('The server returned an unexpected response. Please try again; contact the system administrator if it continues.');
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
  // Notifications default to All so a "resolved" update (which counts as Past) is never hidden.
  let notifFilter = 'all';
  let reportPage = 1;
  let reportPagination = { currentPage: 1, lastPage: 1, total: 0 };

  const phaseSel = document.getElementById('phase');
  const streetSel = document.getElementById('purok');
  let LOCATIONS = {};

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
    } else if (streets.length === 0 || (streets.length === 1 && streets[0].name === 'Area-wide')) {
      streetSel.add(new Option('Area-wide', streets[0]?.location_id || ''));
      streetSel.disabled = true;
      streetSel.required = false;
      // A map pin marks the spot precisely, so it stands in for the landmark.
      const pinned = locationInput.dataset.pinned === '1';
      locationInput.required = !pinned;
      locationInput.placeholder = pinned ? 'Optional: the map pin marks the spot' : 'Enter a specific landmark or location marker';
    } else {
      streetSel.add(new Option('Choose street', ''));
      streets.forEach(location => streetSel.add(new Option(location.name, location.location_id)));
      streetSel.disabled = false;
      streetSel.required = true;
      locationInput.required = false;
      locationInput.placeholder = 'e.g. Near Barangay Hall';
    }
  }

  phaseSel.addEventListener('change', updateStreets);

  function applyReferenceData(referenceData) {
    LOCATIONS = (referenceData.locations || []).filter(location => location.is_active).reduce((groups, location) => {
      groups[location.area_name] ||= [];
      groups[location.area_name].push(location);
      return groups;
    }, {});
    phaseSel.length = 1;
    Object.keys(LOCATIONS).forEach(area => phaseSel.add(new Option(area, area)));
    const categorySelect = document.getElementById('category');
    categorySelect.length = 1;
    (referenceData.categories || []).forEach(category => categorySelect.add(new Option(category.name, category.name)));
    updateStreets();
  }

  // Category suggestion (AI, or keyword rules when AI is unavailable). It is only a hint:
  // the resident decides whether to use it. Runs when the resident leaves the description
  // field, so typing does not send a request on every keystroke.
  const descriptionInput = document.getElementById('description');
  const categoryInput = document.getElementById('category');
  const suggestionBox = document.getElementById('categorySuggestion');
  let lastSuggestedText = '';

  function hideCategorySuggestion() {
    suggestionBox.hidden = true;
    suggestionBox.innerHTML = '';
  }

  async function suggestCategory() {
    const text = descriptionInput.value.trim();
    if (text.length < 10 || text === lastSuggestedText) return;
    lastSuggestedText = text;
    suggestionBox.textContent = 'Checking which category fits…';
    suggestionBox.hidden = false;
    try {
      const { suggestion } = await apiRequest('suggestCategory', { description: text });
      if (descriptionInput.value.trim() !== text) return; // The text changed while waiting.
      if (!suggestion.category || suggestion.category === categoryInput.value) {
        hideCategorySuggestion();
        return;
      }
      const label = suggestion.source === 'ai' ? 'AI suggestion' : 'Suggested from keywords';
      suggestionBox.innerHTML = `
        <span><strong>${escapeHTML(label)}:</strong> ${escapeHTML(suggestion.category)}</span>
        <button type="button" class="btn btn-sm btn-light" data-use-category="${escapeHTML(suggestion.category)}">Use this</button>
        ${suggestion.reason ? `<small class="d-block">${escapeHTML(suggestion.reason)}</small>` : ''}
      `;
    } catch {
      hideCategorySuggestion(); // Suggestions are optional; never block the form.
    }
  }

  // When the resident leaves the description by pressing a button (Submit, Tag Location),
  // wait until that press finishes: the hint appearing mid-click would push the button
  // down and swallow the click.
  let pointerHeld = false;
  let suggestionPending = false;
  document.addEventListener('pointerdown', () => { pointerHeld = true; }, true);
  document.addEventListener('pointerup', () => {
    pointerHeld = false;
    if (suggestionPending) {
      suggestionPending = false;
      setTimeout(suggestCategory, 0);
    }
  }, true);
  descriptionInput.addEventListener('blur', () => {
    if (pointerHeld) suggestionPending = true;
    else suggestCategory();
  });
  suggestionBox.addEventListener('click', event => {
    const button = event.target.closest('[data-use-category]');
    if (!button) return;
    categoryInput.value = button.dataset.useCategory;
    hideCategorySuggestion();
  });

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
      updateNotifications(await apiRequest('getNotifications', { view: notifFilter }, 'GET'));
    } catch (error) {
      const notifContainer = document.getElementById('notifications');
      notifContainer.textContent = `Notifications unavailable: ${error.message}`;
      document.getElementById('notifNavCount').textContent = '!';
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

  // After the barangay resolves a report, ask the resident whether it is really fixed.
  function resolutionCheckHTML(item) {
    const id = escapeHTML(item.id);
    if (item.confirmation === 'awaiting') {
      const deadline = new Date(item.respondBy).toLocaleDateString([], { month: 'long', day: 'numeric' });
      return `
        <div class="resolution-check" role="group" aria-label="Is the problem in report ${escapeHTML(item.tracking)} fixed?">
          <p class="resolution-check-question">The barangay marked this report resolved. Is the problem fixed?</p>
          <p class="resolution-check-hint">Please answer by ${escapeHTML(deadline)}. With no answer, the report closes as fixed.</p>
          <div class="issue-actions">
            <button class="btn btn-sm btn-success" type="button" data-report-confirm="${id}">Yes, it's fixed</button>
            ${item.canReopen
              ? `<button class="btn btn-sm btn-outline-danger" type="button" data-report-reopen="${id}">No, still a problem</button>`
              : '<span class="resolution-check-hint">Still a problem? This report was already reopened twice, so please contact the barangay office.</span>'}
          </div>
        </div>`;
    }
    if (item.confirmation === 'confirmed') {
      return `<div class="resolution-done"><i class="fi fi-rr-check" aria-hidden="true"></i> You confirmed this is fixed on ${escapeHTML(new Date(item.confirmedAt).toLocaleDateString())}.</div>`;
    }
    if (item.confirmation === 'accepted') {
      return '<div class="resolution-done resolution-done-auto">Closed as fixed (no reply within 7 days).</div>';
    }
    if (item.reopenCount > 0 && !['Rejected', 'Withdrawn'].includes(item.stage)) {
      return '<div class="resolution-reopened">You reopened this report. The barangay will send the team again.</div>';
    }
    return '';
  }

  // Build the HTML for one report card.
  function createReportCardHTML(item) {
    const currentStageIndex = STAGES.indexOf(item.stage);

    const priorityClass = item.priority === 'Critical' ? 'soft-red' :
      item.priority === 'High' ? 'soft-yellow' : 'soft-blue';

    const workflowHTML = item.stage === 'Rejected'
      ? '<span class="badge-soft soft-red">Report Rejected</span>'
      : item.stage === 'Withdrawn'
        ? '<span class="badge-soft">Withdrawn by you</span>'
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

        ${resolutionCheckHTML(item)}

        <div class="issue-status-note">
          <strong>Latest Status:</strong> ${escapeHTML(item.updatedMessage || 'Report received and queued for administrative validation.')}
        </div>
        <div class="issue-actions mt-2">
          <button class="btn btn-sm btn-outline-secondary" type="button" data-report-history="${escapeHTML(item.id)}">Status history</button>
          ${item.stage === 'Submitted' ? `<button class="btn btn-sm btn-outline-secondary" type="button" data-report-edit="${escapeHTML(item.id)}">Edit</button>` : ''}
          ${['Submitted', 'Validated'].includes(item.stage) ? `<button class="btn btn-sm btn-outline-danger" type="button" data-report-withdraw="${escapeHTML(item.id)}">Withdraw</button>` : ''}
        </div>
        <ol class="report-history mt-2" data-history-for="${escapeHTML(item.id)}" hidden></ol>
      </article>
    `;
  }

  // Show the latest report updates and notification count.
  function updateNotifications(result) {
    const notifContainer = document.getElementById('notifications');
    const notifications = result.notifications || [];
    if (notifications.length === 0) {
      const emptyMessage = {
        current: 'No updates about reports that are still in progress.',
        past: 'No updates about resolved or closed reports.',
        all: 'No updates available yet.'
      }[notifFilter];
      notifContainer.innerHTML = `<div class="text-secondary small">${escapeHTML(emptyMessage)}</div>`;
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
    document.getElementById('notifNavCount').textContent = result.unreadCount;
  }

  document.getElementById('notifications').addEventListener('click', async event => {
    const button = event.target.closest('[data-mark-notification-read]');
    if (!button) return;
    try {
      await apiRequest('markNotificationRead', { id: button.dataset.markNotificationRead });
      updateNotifications(await apiRequest('getNotifications', { view: notifFilter }, 'GET'));
    } catch (error) {
      window.showToast(error.message, 'error');
    }
  });

  // Edit (before review) and withdraw (before dispatch) a resident's own report.
  const editDialog = document.getElementById('editReportDialog');
  let editingReportId = null;

  document.getElementById('issuesList').addEventListener('click', async event => {
    const editButton = event.target.closest('[data-report-edit]');
    if (editButton) {
      const report = currentReports.find(item => item.id === editButton.dataset.reportEdit);
      if (!report) return;
      editingReportId = report.id;
      const categorySelect = document.getElementById('editReportCategory');
      categorySelect.innerHTML = [...document.getElementById('category').options]
        .filter(option => option.value)
        .map(option => `<option${option.value === report.category ? ' selected' : ''}>${escapeHTML(option.value)}</option>`)
        .join('');
      document.getElementById('editReportDescription').value = report.description;
      document.getElementById('editReportLandmark').value = report.landmark || '';
      document.getElementById('editReportMessage').textContent = '';
      editDialog.showModal();
      return;
    }

    const confirmButton = event.target.closest('[data-report-confirm]');
    if (confirmButton) {
      const confirmed = await window.confirmAction('Confirm that the problem is fixed? This closes the report.');
      if (!confirmed) return;
      try {
        await apiRequest('confirmReport', { id: confirmButton.dataset.reportConfirm });
        window.showToast('Thank you for confirming. The report is now closed.', 'success');
        await render();
      } catch (error) {
        window.showToast(error.message, 'error');
      }
      return;
    }

    const reopenButton = event.target.closest('[data-report-reopen]');
    if (reopenButton) {
      const reason = await window.promptAction(
        'What is still wrong? The report goes back to the barangay so they can send the team again.',
        {
          maxLength: 300,
          placeholder: 'For example: the pothole was only partly filled',
          confirmLabel: 'Reopen report',
          suggestions: ['The problem is still there.', 'It was only partly fixed.', 'The problem came back.']
        }
      );
      if (reason === null) return;
      try {
        await apiRequest('reopenReport', { id: reopenButton.dataset.reportReopen, reason });
        window.showToast('Your report was reopened and sent back to the barangay.', 'success');
        await render();
      } catch (error) {
        window.showToast(error.message, 'error');
      }
      return;
    }

    const withdrawButton = event.target.closest('[data-report-withdraw]');
    if (withdrawButton) {
      const reason = await window.promptAction(
        'Withdraw this report? The barangay will stop working on it. You can tell them why (optional).',
        { required: false, maxLength: 200, placeholder: 'For example: the problem was fixed', confirmLabel: 'Withdraw report' }
      );
      if (reason === null) return;
      try {
        await apiRequest('withdrawReport', { id: withdrawButton.dataset.reportWithdraw, reason });
        window.showToast('Your report has been withdrawn.', 'success');
        await render();
      } catch (error) {
        window.showToast(error.message, 'error');
      }
      return;
    }

    const button = event.target.closest('[data-report-history]');
    if (!button) return;
    const historyList = document.querySelector(`[data-history-for="${CSS.escape(button.dataset.reportHistory)}"]`);
    if (!historyList) return;
    if (!historyList.hidden) {
      historyList.hidden = true;
      return;
    }
    try {
      const result = await apiRequest('getReportHistory', { id: button.dataset.reportHistory }, 'GET');
      historyList.innerHTML = (result.history || []).map(entry => `
        <li><strong>${escapeHTML(entry.newStatus)}</strong> · ${new Date(entry.changedAt).toLocaleString()}
          <div>${escapeHTML(entry.remarks || '')}</div>
        </li>
      `).join('');
      historyList.hidden = false;
    } catch (error) {
      window.showToast(error.message);
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
  let gpsCoordinates = null;
  let locationMap = null;
  let locationMarker = null;

  function setSelectedLocation(latitude, longitude) {
    gpsCoordinates = { latitude, longitude };
    locationInput.dataset.pinned = '1';
    locationInput.required = false;
    if (locationInput.placeholder.startsWith('Enter a specific')) {
      locationInput.placeholder = 'Optional: the map pin marks the spot';
    }
    if (!locationMarker) {
      locationMarker = L.marker([latitude, longitude], { draggable: true, keyboard: true, title: 'Incident location' })
        .addTo(locationMap);
      locationMarker.on('dragend', () => {
        const position = locationMarker.getLatLng();
        setSelectedLocation(position.lat, position.lng);
      });
    } else {
      locationMarker.setLatLng([latitude, longitude]);
    }
    mapStatus.textContent = `Selected location: ${latitude.toFixed(5)}, ${longitude.toFixed(5)}. Click or drag the marker to adjust it.`;
    geoMsg.textContent = `Map location attached: ${latitude.toFixed(5)}, ${longitude.toFixed(5)}`;
  }

  function initializeLocationMap() {
    locationMap = L.map(mapCanvas, { center: window.MAP_DEFAULT_CENTER, zoom: 14 });
    L.tileLayer(window.MAP_TILE_URL, { maxZoom: 19, attribution: window.MAP_ATTRIBUTION }).addTo(locationMap);
    locationMap.on('click', event => setSelectedLocation(event.latlng.lat, event.latlng.lng));
  }

  geoBtn.addEventListener('click', async () => {
    geoMsg.textContent = 'Loading the map...';
    try {
      await window.loadLeaflet();
      locationMapDialog.showModal();
      if (!locationMap) initializeLocationMap();
      // The dialog was hidden while the map was created, so let Leaflet measure it again.
      locationMap.invalidateSize();
      if (gpsCoordinates) {
        locationMap.setView([gpsCoordinates.latitude, gpsCoordinates.longitude], 17);
        setSelectedLocation(gpsCoordinates.latitude, gpsCoordinates.longitude);
      } else {
        mapStatus.textContent = 'Map centered near San Jose del Monte. Click the map to place a marker, or drag it to adjust the location.';
        geoMsg.textContent = '';
      }
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
        locationMap.setView([position.coords.latitude, position.coords.longitude], 17);
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
    const description = document.getElementById('description').value.trim();
    const landmark = locationInput.value.trim();
    const streets = LOCATIONS[phase] || [];
    const street = streetSel.value;

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

    if (streets.length === 0 && !landmark && !gpsCoordinates) {
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
      const payload = new FormData();
      payload.append('category', categoryValue);
      payload.append('location_id', streetSel.value);
      payload.append('description', description);
      payload.append('location', landmark);
      if (gpsCoordinates) {
        payload.append('latitude', gpsCoordinates.latitude);
        payload.append('longitude', gpsCoordinates.longitude);
      }
      if (imageInput.files[0]) payload.append('image', imageInput.files[0]);

      const result = await apiRequest('createReport', payload);

      this.reset();
      delete locationInput.dataset.pinned;
      updateStreets();
      hideCategorySuggestion();
      lastSuggestedText = '';
      gpsCoordinates = null;
      if (locationMarker) {
        locationMarker.remove();
        locationMarker = null;
      }
      this.classList.remove('was-validated');
      imagePreview.classList.add('d-none');
      geoMsg.textContent = '';

      document.getElementById('submitAlert').innerHTML = `
        <div class="alert alert-success alert-dismissible fade show" role="alert">
          <strong>Report Submitted!</strong> Your tracking ID is <strong>${escapeHTML(result.tracking)}</strong>.
          <button type="button" class="btn btn-sm btn-light ms-2" data-go-view="reports">View in My Reports</button>
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
        window.location.href = result.user.role === 'admin' ? '/admin' : '/';
        return;
      }
      applyReferenceData(await apiRequest('getReferenceData', {}, 'GET'));
      document.getElementById('residentName').textContent = `Welcome, ${result.user.username}`;
      window.fillAccountDetails(result.user);
      showView(window.location.hash.slice(1));
      await render();
      setInterval(render, 15000);
    } catch (error) {
      window.location.href = '/';
    }
  }

  // Sidebar views. The current view is kept in the address (#reports) so a refresh stays on it.
  const RESIDENT_VIEWS = ['report', 'reports', 'notifications', 'account'];
  function showView(name) {
    const view = RESIDENT_VIEWS.includes(name) ? name : 'report';
    document.querySelectorAll('[data-resident-view]').forEach(button => {
      const isActive = button.dataset.residentView === view;
      button.classList.toggle('active', isActive);
      if (isActive) {
        button.setAttribute('aria-current', 'page');
      } else {
        button.removeAttribute('aria-current');
      }
    });
    document.querySelectorAll('.resident-view').forEach(section => {
      section.hidden = section.id !== `${view}View`;
    });
    if (window.location.hash !== `#${view}`) {
      history.replaceState(null, '', `#${view}`);
    }
  }

  // ☰ menu: a drawer that opens from the left and closes after choosing a view,
  // clicking outside it, pressing Escape, or the close button.
  const menu = document.getElementById('residentMenu');
  const menuToggle = document.getElementById('menuToggle');
  const menuBackdrop = document.getElementById('menuBackdrop');
  function setMenu(open) {
    const focusWasInMenu = menu.contains(document.activeElement);
    menu.classList.toggle('open', open);
    menu.inert = !open;
    menuBackdrop.hidden = !open;
    document.body.classList.toggle('menu-open', open);
    menuToggle.setAttribute('aria-expanded', String(open));
    menuToggle.setAttribute('aria-label', open ? 'Close menu' : 'Open menu');
    if (open) {
      (menu.querySelector('.sidebar-link.active') || menu.querySelector('.sidebar-link')).focus();
    } else if (focusWasInMenu) {
      menuToggle.focus();
    }
  }
  menuToggle.addEventListener('click', () => setMenu(!menu.classList.contains('open')));
  document.getElementById('menuClose').addEventListener('click', () => setMenu(false));
  menuBackdrop.addEventListener('click', () => setMenu(false));
  document.addEventListener('keydown', event => {
    if (event.key === 'Escape' && menu.classList.contains('open')) setMenu(false);
  });

  // The unread count lives in the menu; mirror it on the ☰ button while the menu is closed.
  const notifCount = document.getElementById('notifNavCount');
  const menuBadge = document.getElementById('menuNotifBadge');
  const syncMenuBadge = () => {
    const text = notifCount.textContent.trim();
    menuBadge.textContent = text;
    menuBadge.hidden = text === '' || text === '0';
  };
  new MutationObserver(syncMenuBadge).observe(notifCount, { childList: true, characterData: true, subtree: true });
  syncMenuBadge();

  document.querySelectorAll('[data-resident-view]').forEach(button => {
    button.addEventListener('click', () => {
      showView(button.dataset.residentView);
      setMenu(false);
    });
  });
  // Buttons elsewhere on the page (for example "View in My Reports") can open a view too.
  document.addEventListener('click', event => {
    const link = event.target.closest('[data-go-view]');
    if (link) showView(link.dataset.goView);
  });

  document.getElementById('editReportCancel').addEventListener('click', () => editDialog.close());
  document.getElementById('editReportForm').addEventListener('submit', async event => {
    event.preventDefault();
    const message = document.getElementById('editReportMessage');
    const description = document.getElementById('editReportDescription').value.trim();
    if (!description) {
      message.textContent = 'Please describe the issue.';
      return;
    }
    try {
      await apiRequest('updateReport', {
        id: editingReportId,
        category: document.getElementById('editReportCategory').value,
        description,
        location: document.getElementById('editReportLandmark').value.trim()
      }, 'PUT');
      editDialog.close();
      window.showToast('Your report has been updated.', 'success');
      await render();
    } catch (error) {
      message.textContent = error.message;
    }
  });

  // Change which notification group is shown.
  document.querySelectorAll('[data-notif-filter]').forEach(button => {
    button.addEventListener('click', () => {
      notifFilter = button.dataset.notifFilter;
      document.querySelectorAll('[data-notif-filter]').forEach(filterButton => {
        const isSelected = filterButton === button;
        filterButton.classList.toggle('active', isSelected);
        filterButton.setAttribute('aria-pressed', String(isSelected));
      });
      render();
    });
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
    const confirmed = await window.confirmAction('Are you sure you want to log out?');
    if (confirmed) {
      try {
        await apiRequest('logout');
      } finally {
        window.location.href = '/';
      }
    }
  });

  startPortal();
}

initResidentPortalPage();