// Handles the admin dashboard, report actions, and analytics.

// API routes used by the admin dashboard.
const API_ENDPOINTS = {
  getReports: '/api/reports',
  getArchivedReports: '/api/reports/archived',
  getReportSummary: '/api/analytics/summary',
  getDssRecommendations: '/api/analytics/dss',
  saveDssSnapshot: '/api/analytics/dss/snapshots',
  getResidents: '/api/residents',
  getReferenceData: '/api/reference-data',
  createReference: '/api/reference-data/{resource}',
  updateReference: '/api/reference-data/{resource}/{id}',
  deleteReference: '/api/reference-data/{resource}/{id}',
  getReportHistory: '/api/reports/{id}/history',
  updateResidentApproval: '/api/residents/{id}/approval',
  session: '/api/session',
  logout: '/api/logout',
  archiveReport: '/api/reports/{id}/archive',
  restoreReport: '/api/reports/{id}/restore',
  updateReport: '/api/reports/update',
  clearReports: '/api/reports/clear',
  deleteArchivedReport: '/api/reports/{id}/archived'
};

// Send a request to the Laravel API.
async function apiRequest(action, payload = {}, method = 'POST', resourceId = '') {
  const options = {
    method,
    credentials: 'same-origin',
    headers: {
      'Accept': 'application/json',
      'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
    }
  };

  if (method !== 'GET') {
    options.headers['Content-Type'] = 'application/json';
    options.body = JSON.stringify(payload);
  }

  let endpoint = API_ENDPOINTS[action]
    .replace('{resource}', encodeURIComponent(payload.resource || ''))
    .replace('{id}', encodeURIComponent(resourceId || payload.id || ''));
  if (method === 'GET' && Object.keys(payload).length > 0) {
    const query = new URLSearchParams(payload);
    endpoint += `?${query.toString()}`;
  }
  const response = await fetch(endpoint, options);
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

// Load a script asset before initializing the dashboard.
function loadScript(src, id) {
  return new Promise((resolve, reject) => {
    if (document.querySelector(`script[src="${src}"]`)) {
      resolve();
      return;
    }

    const script = document.createElement('script');
    script.src = src;
    script.id = id;
    script.async = false;
    script.onload = resolve;
    script.onerror = reject;
    document.head.appendChild(script);
  });
}

// Set up dashboard controls, reports, and analytics.
function initAdminDashboardPage() {
  let CATEGORIES = ['Sanitation', 'Clogged Drainage', 'Streetlights', 'Road Damage', 'Public Safety', 'Others'];

  let pieChartInstance = null;
  let lineChartInstance = null;
  let currentReports = [];
  let residents = [];
  const residentReportPages = new Map();
  let referenceData = { categories: [], teams: [], locations: [] };
  let incidentPage = 1;
  let resolvedPage = 1;
  let archivedPage = 1;

  // Escape text before placing it in HTML.
  function escapeHTML(value) {
    return String(value ?? '').replace(/[&<>"']/g, character => ({
      '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
    })[character]);
  }

  // Return a colored badge for a report's priority.
  function getPriorityBadge(priority) {
    const p = priority || 'Normal';
    const badgeClass = p === 'Critical' ? 'soft-red' : p === 'High' ? 'soft-yellow' : 'soft-blue';
    return `<span class="badge-soft ${badgeClass}">${p}</span>`;
  }

  // Refresh report tables, metrics, and recommendations.
  async function render() {
    let activeResult;
    let resolvedResult;
    let summary;
    let dss;
    try {
      [activeResult, resolvedResult, summary, dss] = await Promise.all([
        apiRequest('getReports', {
          view: 'active',
          page: incidentPage,
          status: document.getElementById('filterStatus').value,
          priority: document.getElementById('filterPriority').value
        }, 'GET'),
        apiRequest('getReports', { view: 'resolved', page: resolvedPage }, 'GET'),
        apiRequest('getReportSummary', {}, 'GET'),
        apiRequest('getDssRecommendations', {}, 'GET')
      ]);
    } catch (error) {
      const errorRow = '<tr><td colspan="7" class="text-center text-danger py-5">Could not load reports. Check the API connection and your session.</td></tr>';
      document.getElementById('incidentBody').innerHTML = errorRow;
      document.getElementById('resolvedBody').innerHTML = errorRow;
      window.showToast(error.message, 'error');
      return;
    }

    currentReports = activeResult.reports || [];
    const resolvedReports = resolvedResult.reports || [];
    document.getElementById('incidentBody').innerHTML = currentReports.length
      ? currentReports.map(item => createIncidentRowHTML(item)).join('')
      : '<tr><td colspan="7" class="admin-empty-state">No active incidents match the selected filters.</td></tr>';

    document.getElementById('resolvedBody').innerHTML = resolvedReports.length
      ? resolvedReports.map(item => createIncidentRowHTML(item, { readOnly: true })).join('')
      : '<tr><td colspan="7" class="admin-empty-state">No resolved incidents yet.</td></tr>';
    document.getElementById('resolvedCount').textContent = `${summary.summary.resolved} resolved`;
    document.getElementById('resolvedNavCount').textContent = summary.summary.resolved;
    document.getElementById('mTotal').textContent = summary.summary.total;
    document.getElementById('mDispatch').textContent = summary.summary.dispatches;
    document.getElementById('mResolution').textContent = `${summary.summary.resolutionRate}%`;
    document.getElementById('mHotspots').textContent = summary.summary.hotspotCount;
    renderPagination('incidentPagination', activeResult.pagination, page => {
      incidentPage = page;
      render();
    });
    renderPagination('resolvedPagination', resolvedResult.pagination, page => {
      resolvedPage = page;
      render();
    });
    renderDSSRecommendations(dss.recommendations || []);
    renderDssHistory(dss.history || []);
    if (!document.getElementById('analyticsView').hidden) {
      renderAnalytics(summary.summary);
    }
  }

  function renderPagination(containerId, pagination, onPageChange) {
    const container = document.getElementById(containerId);
    if (!container || !pagination) return;
    const { currentPage, lastPage, total } = pagination;
    container.innerHTML = `
      <button class="btn btn-sm btn-outline-secondary" type="button" data-page="${currentPage - 1}" ${currentPage <= 1 ? 'disabled' : ''}>Previous</button>
      <span class="small text-secondary">Page ${currentPage} of ${lastPage} · ${total} reports</span>
      <button class="btn btn-sm btn-outline-secondary" type="button" data-page="${currentPage + 1}" ${currentPage >= lastPage ? 'disabled' : ''}>Next</button>
    `;
    container.onclick = event => {
      const button = event.target.closest('[data-page]');
      if (button && !button.disabled) onPageChange(Number(button.dataset.page));
    };
  }

  // Build one report row for the active or resolved table.
  function createIncidentRowHTML(item, { readOnly = false, archived = false } = {}) {
    return `
        <tr>
          <td>
            <strong>${escapeHTML(item.tracking)}</strong>
            <div class="small text-secondary">Reported ${new Date(item.created).toLocaleString()}</div>
            ${item.resolved ? `<div class="small text-secondary">Resolved ${new Date(item.resolved).toLocaleString()}</div>` : ''}
            ${archived ? `<div class="small text-secondary">Archived ${new Date(item.archivedAt).toLocaleString()}</div>` : ''}
          </td>
          <td>
            <strong>${escapeHTML(item.category)}</strong>
            <div class="small text-secondary" title="${escapeHTML(item.description)}">
              ${escapeHTML(item.description.length > 50 ? item.description.slice(0, 50) + '…' : item.description)}
            </div>
          </td>
          <td>
            ${escapeHTML(item.purok)}<br>
            <small class="text-secondary">${escapeHTML(item.location || '')}</small>
          </td>
          <td><span class="badge text-bg-light border">${item.stage}</span></td>
          <td>${getPriorityBadge(item.priority)}</td>
          <td>
            ${item.attachmentId
              ? `<a href="/api/attachments/${encodeURIComponent(item.attachmentId)}" aria-label="View attachment for ${escapeHTML(item.tracking)}"><img class="img-fluid rounded" src="/api/attachments/${encodeURIComponent(item.attachmentId)}" alt="Resident upload for ${escapeHTML(item.tracking)}" loading="lazy" style="width: 88px; height: 64px; object-fit: cover;"></a>`
              : '<span class="small text-secondary">None</span>'}
          </td>
          <td>
            ${archived
              ? `<div class="report-actions">
                  <button class="btn btn-sm btn-outline-primary" data-restore-report-id="${escapeHTML(item.id)}">Restore</button>
                  <button class="btn btn-sm btn-outline-danger" data-delete-archived-id="${escapeHTML(item.id)}">Delete permanently</button>
                </div>`
              : readOnly
                ? '<span class="badge-soft soft-green">Read-only</span>'
                : `<div class="report-actions">
                    ${item.stage === 'Rejected' ? '' : `<button class="btn btn-sm btn-outline-primary" data-report-id="${escapeHTML(item.id)}">Manage</button>`}
                    <button class="btn btn-sm btn-outline-secondary" data-archive-report-id="${escapeHTML(item.id)}">Archive</button>
                  </div>`}
          </td>
        </tr>
      `;
  }

  // Refresh the analytics charts and hotspot grid.
  function renderAnalytics(summary) {
    renderCharts(summary);
    renderHotspotGrid(summary.hotspots || []);
  }

  // Draw report totals by category and month using server-side aggregates.
  function renderCharts(summary) {
    const categoryCounts = CATEGORIES.map(category => Number(summary.categories[category] || 0));

    if (pieChartInstance) pieChartInstance.destroy();
    const pieCtx = document.getElementById('pieChart').getContext('2d');
    pieChartInstance = new Chart(pieCtx, {
      type: 'doughnut',
      data: {
        labels: CATEGORIES,
        datasets: [{
          data: categoryCounts,
          backgroundColor: ['#145da0', '#198754', '#f59e0b', '#dc3545', '#0ea5e9', '#64748b']
        }]
      },
      options: {
        responsive: true,
        plugins: { legend: { position: 'bottom', labels: { color: '#fff' } } }
      }
    });

    const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    const monthlyData = summary.monthly;

    if (lineChartInstance) lineChartInstance.destroy();
    const lineCtx = document.getElementById('lineChart').getContext('2d');
    lineChartInstance = new Chart(lineCtx, {
      type: 'line',
      data: {
        labels: months,
        datasets: [{
          label: 'Total Incidents',
          data: monthlyData,
          borderColor: '#145da0',
          backgroundColor: 'rgba(20, 93, 160, 0.1)',
          tension: 0.35,
          fill: true
        }]
      },
      options: {
        responsive: true,
        plugins: { legend: { labels: { color: '#fff' } } },
        scales: {
          x: { ticks: { color: '#fff' } },
          y: { beginAtZero: true, ticks: { precision: 0, color: '#fff' } }
        }
      }
    });
  }

  // Show active report counts by area and category.
  function renderHotspotGrid(hotspots) {
    const countsByLocation = new Map();
    hotspots.forEach(hotspot => {
      if (!countsByLocation.has(hotspot.location)) countsByLocation.set(hotspot.location, new Map());
      countsByLocation.get(hotspot.location).set(hotspot.category, hotspot.count);
    });
    const locations = [...countsByLocation.keys()].sort((first, second) => first.localeCompare(second));

    if (locations.length === 0) {
      document.getElementById('hotspotGrid').innerHTML =
        '<div class="text-secondary small">No active incidents to map.</div>';
      return;
    }

    const gridHTML = locations.map(location => CATEGORIES.map(category => {
      const count = countsByLocation.get(location)?.get(category) || 0;
      const heatLevel = Math.min(5, 1 + Math.floor(count / 2));
      const shortCategory = category.replace('Clogged Drainage', 'Drainage').replace('Public Safety', 'Safety');

      return `
        <div class="hotspot-cell heat-${heatLevel}">
          <span>${escapeHTML(location)}</span>
          <span>${escapeHTML(shortCategory)}</span>
          <strong>${count} active</strong>
        </div>
      `;
    }).join('')).join('');

    document.getElementById('hotspotGrid').innerHTML = gridHTML;
  }

  // Display recommendations computed by the backend DSS service.
  function renderDSSRecommendations(recommendations) {
    const dssContainer = document.getElementById('dssPanel');

    if (recommendations.length === 0) {
      dssContainer.innerHTML = '<div class="admin-empty-state">No active unresolved incidents for DSS calculation.</div>';
    } else {
      dssContainer.innerHTML = recommendations.map(rec => {
        const isHighUrgency = rec.urgency === 'High';
        return `
          <div class="border rounded-4 p-3 mb-2 bg-white">
            <div class="d-flex justify-content-between align-items-start">
              <strong>${escapeHTML(rec.location)} — ${escapeHTML(rec.category)}</strong>
              <span class="badge-soft ${isHighUrgency ? 'soft-red' : 'soft-yellow'}">
                ${isHighUrgency ? 'High Urgency' : 'Moderate'}
              </span>
            </div>
            <div class="small text-secondary mt-1">
              ${rec.reportCount} active report(s), avg age ${Number(rec.averageAgeDays).toFixed(1)} day(s), ${rec.criticalCount} critical.
            </div>
            <div class="small text-secondary">Score ${Number(rec.score).toFixed(2)} = reports ${Number(rec.scoreBreakdown.reportCount).toFixed(2)} + age ${Number(rec.scoreBreakdown.averageAgeDays).toFixed(2)} + severity ${Number(rec.scoreBreakdown.severity).toFixed(2)}. Critical count is informational and is not scored separately.</div>
            <div class="small mt-2 pt-2 border-top">
              <strong>Suggested team:</strong> ${escapeHTML(rec.recommendedTeam || 'No configured team')}<br>
              <strong>Action:</strong> ${escapeHTML(rec.recommendedAction)}
            </div>
          </div>
        `;
      }).join('');
    }
  }

  function renderDssHistory(history) {
    const container = document.getElementById('dssHistory');
    container.innerHTML = history.length
      ? history.map(snapshot => `
        <div class="small border-top pt-2 mt-2">
          <strong>${new Date(snapshot.generatedAt).toLocaleString()}</strong>
          · ${snapshot.sourceReportCount} source report(s)
          · ${snapshot.recommendations.length} recommendation(s)
        </div>
      `).join('')
      : '<div class="small text-secondary">No saved DSS snapshots yet.</div>';
  }

  document.getElementById('saveDssSnapshotBtn').addEventListener('click', async () => {
    try {
      await apiRequest('saveDssSnapshot');
      await render();
      window.showToast('DSS recommendation snapshot saved.', 'success');
    } catch (error) {
      window.showToast(error.message, 'error');
    }
  });

  // Load resident accounts and aggregate report counts.
  async function loadResidents() {
    const residentsList = document.getElementById('residentsList');
    residentsList.innerHTML = '<div class="card p-4 text-secondary">Loading residents and complaints…</div>';
    try {
      const residentResult = await apiRequest('getResidents', {}, 'GET');
      residents = residentResult.residents || [];
      residentReportPages.clear();
      document.getElementById('residentNavCount').textContent = residents.length;
      renderPendingApprovals();
      renderResidents();
    } catch (error) {
      const message = escapeHTML(error.message || 'Could not load residents. Check the API connection and your session.');
      residentsList.innerHTML = `<div class="card p-4 alert-error" role="alert">${message}</div>`;
      document.getElementById('residentsSummary').textContent = '';
      document.getElementById('pendingApprovalList').innerHTML =
        `<div class="card p-4 alert-error" role="alert">${message}</div>`;
    }
  }

  // Show pending account requests in their own workspace section.
  function renderPendingApprovals() {
    const pendingResidents = residents.filter(resident => resident.accountStatus === 'pending');
    const count = pendingResidents.length;
    document.getElementById('pendingApprovalNavCount').textContent = count;
    document.getElementById('pendingApprovalsPageCount').textContent = `${count} pending`;

    const pendingApprovalList = document.getElementById('pendingApprovalList');
    if (count === 0) {
      pendingApprovalList.innerHTML =
        '<div class="card p-4 text-secondary">There are no pending resident registrations.</div>';
      return;
    }

    pendingApprovalList.innerHTML = pendingResidents.map(resident => `
      <article class="resident-card card">
        <div class="resident-card-heading">
          <div>
            <h2>${escapeHTML(resident.fullName)}</h2>
            <div class="small text-secondary">@${escapeHTML(resident.username)} · ${escapeHTML(resident.email)}</div>
          </div>
          <span class="badge-soft soft-yellow">Pending approval</span>
        </div>
        <div class="resident-card-meta">Registered ${new Date(resident.registered).toLocaleDateString()}</div>
        <div class="d-flex gap-2 mt-3">
          <button class="btn btn-sm btn-success" type="button" data-resident-approval="approved" data-resident-id="${escapeHTML(resident.id)}">Approve</button>
          <button class="btn btn-sm btn-outline-danger" type="button" data-resident-approval="rejected" data-resident-id="${escapeHTML(resident.id)}">Reject</button>
        </div>
      </article>
    `).join('');
  }

  // Filter and display residents; individual reports are fetched on demand.
  function renderResidents() {
    const search = document.getElementById('residentSearch').value.trim().toLowerCase();
    const matchingResidents = residents.filter(resident =>
      `${resident.fullName} ${resident.username} ${resident.email}`.toLowerCase().includes(search)
    );
    const complaintCount = residents.reduce((total, resident) => total + Number(resident.reportCount || 0), 0);
    const pendingCount = residents.filter(resident => resident.accountStatus === 'pending').length;

    document.getElementById('residentsSummary').innerHTML = `
      <div class="resident-stat"><strong>${residents.length}</strong><span>Registered residents</span></div>
      <div class="resident-stat"><strong>${pendingCount}</strong><span>Pending approvals</span></div>
      <div class="resident-stat"><strong>${complaintCount}</strong><span>Total complaints</span></div>
    `;

    if (residents.length === 0) {
      document.getElementById('residentsList').innerHTML =
        '<div class="card p-4 text-secondary">No registered residents found.</div>';
      return;
    }

    function renderReferenceData() {
      const renderRows = (items, resource, fields) => items.map(item => `
        <div class="row g-2 align-items-center border-top py-2" data-reference-row="${resource}" data-reference-id="${item[fields.id]}">
          ${fields.inputs.map(field => `
            <div class="${field.className}">
              ${field.type === 'checkbox'
                ? `<label class="form-check"><input class="form-check-input" type="checkbox" name="${field.name}" ${item[field.name] ? 'checked' : ''}><span class="form-check-label">${field.label}</span></label>`
                : field.type === 'select'
                  ? `<label class="visually-hidden" for="${resource}-${item[fields.id]}-${field.name}">${field.label}</label><select id="${resource}-${item[fields.id]}-${field.name}" class="form-select" name="${field.name}" aria-label="${field.label}"><option value="">No suggested team</option>${referenceData.teams.map(team => `<option value="${team.team_id}" ${String(team.team_id) === String(item[field.name] ?? '') ? 'selected' : ''}>${escapeHTML(team.name)}</option>`).join('')}</select>`
                  : `<label class="visually-hidden" for="${resource}-${item[fields.id]}-${field.name}">${field.label}</label><input id="${resource}-${item[fields.id]}-${field.name}" class="form-control" type="${field.type || 'text'}" name="${field.name}" value="${escapeHTML(item[field.name] ?? '')}" ${field.type === 'number' ? 'min="1" max="5"' : ''} maxlength="${field.maxlength || ''}" aria-label="${field.label}">`}
            </div>
          `).join('')}
          <div class="col-md-2 d-flex gap-2">
            <button class="btn btn-sm btn-outline-primary" type="button" data-reference-save>Save</button>
            <button class="btn btn-sm btn-outline-danger" type="button" data-reference-delete>Delete</button>
          </div>
        </div>
      `).join('') || '<p class="text-secondary mb-0">No entries.</p>';

      document.getElementById('referenceCategories').innerHTML = renderRows(referenceData.categories, 'categories', {
        id: 'category_id',
        inputs: [
          { name: 'name', label: 'Category name', maxlength: 100, className: 'col-md-3' },
          { name: 'description', label: 'Description', maxlength: 1000, className: 'col-md-3' },
          { name: 'severity_weight', label: 'Severity weight', type: 'number', className: 'col-md-2' },
          { name: 'recommended_team_id', label: 'Suggested team', type: 'select', className: 'col-md-2' }
        ]
      });
      document.getElementById('referenceTeams').innerHTML = renderRows(referenceData.teams, 'teams', {
        id: 'team_id',
        inputs: [{ name: 'name', label: 'Team name', maxlength: 100, className: 'col-md-10' }]
      });
      document.getElementById('referenceLocations').innerHTML = renderRows(referenceData.locations, 'locations', {
        id: 'location_id',
        inputs: [
          { name: 'name', label: 'Street or Area-wide', maxlength: 150, className: 'col-md-4' },
          { name: 'area_name', label: 'Village or phase', maxlength: 150, className: 'col-md-4' },
          { name: 'is_active', label: 'Available to residents', type: 'checkbox', className: 'col-md-2' }
        ]
      });
    }

    async function loadReferenceData() {
      const status = document.getElementById('referenceDataStatus');
      status.textContent = 'Loading reference data…';
      try {
        referenceData = await apiRequest('getReferenceData', {}, 'GET');
        CATEGORIES = referenceData.categories.map(category => category.name);
        const assignee = document.getElementById('assignee');
        const selectedTeam = assignee.value;
        assignee.innerHTML = '<option value="Unassigned">Unassigned</option>' +
          referenceData.teams.map(team => `<option>${escapeHTML(team.name)}</option>`).join('');
        const newCategoryTeam = document.getElementById('newCategoryTeam');
        newCategoryTeam.innerHTML = '<option value="">No suggested team</option>' +
          referenceData.teams.map(team => `<option value="${team.team_id}">${escapeHTML(team.name)}</option>`).join('');
        if ([...assignee.options].some(option => option.value === selectedTeam)) {
          assignee.value = selectedTeam;
        }
        renderReferenceData();
        status.textContent = '';
      } catch (error) {
        status.textContent = error.message || 'Reference data could not be loaded.';
      }
    }

    async function saveReferenceRow(row) {
      const resource = row.dataset.referenceRow;
      const payload = { resource };
      row.querySelectorAll('[name]').forEach(input => {
        payload[input.name] = input.type === 'checkbox' ? input.checked : input.value.trim();
      });
      try {
        await apiRequest('updateReference', payload, 'PUT', row.dataset.referenceId);
        await loadReferenceData();
        window.showToast('Reference data saved.', 'success');
      } catch (error) {
        window.showToast(error.message, 'error');
      }
    }

    async function deleteReferenceRow(row) {
      if (!await window.confirmAction('Delete this reference item? Items used by reports must be deactivated instead.')) return;
      try {
        await apiRequest('deleteReference', { resource: row.dataset.referenceRow }, 'DELETE', row.dataset.referenceId);
        await loadReferenceData();
        window.showToast('Reference item deleted.', 'success');
      } catch (error) {
        window.showToast(error.message, 'error');
      }
    }

    async function createReference(resource, values, form) {
      try {
        await apiRequest('createReference', { resource, ...values }, 'POST');
        form.reset();
        await loadReferenceData();
        window.showToast('Reference item added.', 'success');
      } catch (error) {
        window.showToast(error.message, 'error');
      }
    }

    document.getElementById('createCategoryForm').addEventListener('submit', event => {
      event.preventDefault();
      createReference('categories', {
        name: document.getElementById('newCategoryName').value.trim(),
        description: document.getElementById('newCategoryDescription').value.trim(),
        severity_weight: document.getElementById('newCategoryWeight').value,
        recommended_team_id: document.getElementById('newCategoryTeam').value || null
      }, event.currentTarget);
    });
    document.getElementById('createTeamForm').addEventListener('submit', event => {
      event.preventDefault();
      createReference('teams', { name: document.getElementById('newTeamName').value.trim() }, event.currentTarget);
    });
    document.getElementById('createLocationForm').addEventListener('submit', event => {
      event.preventDefault();
      createReference('locations', {
        name: document.getElementById('newLocationName').value.trim(),
        area_name: document.getElementById('newLocationArea').value.trim(),
        is_active: true
      }, event.currentTarget);
    });
    ['referenceCategories', 'referenceTeams', 'referenceLocations'].forEach(id => {
      document.getElementById(id).addEventListener('click', event => {
        const row = event.target.closest('[data-reference-row]');
        if (!row) return;
        if (event.target.closest('[data-reference-save]')) saveReferenceRow(row);
        if (event.target.closest('[data-reference-delete]')) deleteReferenceRow(row);
      });
    });
    if (matchingResidents.length === 0) {
      document.getElementById('residentsList').innerHTML =
        '<div class="card p-4 text-secondary">No residents match your search.</div>';
      return;
    }

    document.getElementById('residentsList').innerHTML = matchingResidents.map(resident => {
      const reportPage = residentReportPages.get(resident.id);
      const statusBadge = resident.accountStatus === 'approved'
        ? '<span class="badge-soft soft-green">Approved</span>'
        : resident.accountStatus === 'rejected'
          ? '<span class="badge-soft soft-red">Rejected</span>'
          : '<span class="badge-soft soft-yellow">Pending approval</span>';
      const approvalActions = resident.accountStatus === 'pending' || resident.accountStatus === 'rejected'
        ? `<div class="d-flex gap-2 mt-3">
            <button class="btn btn-sm btn-success" type="button" data-resident-approval="approved" data-resident-id="${escapeHTML(resident.id)}">
              ${resident.accountStatus === 'rejected' ? 'Approve account' : 'Approve'}
            </button>
            ${resident.accountStatus === 'pending'
              ? `<button class="btn btn-sm btn-outline-danger" type="button" data-resident-approval="rejected" data-resident-id="${escapeHTML(resident.id)}">Reject</button>`
              : ''}
          </div>`
        : '';
      const complaintHTML = reportPage
        ? reportPage.reports.map(report => `
          <article class="resident-complaint">
            <div class="complaint-heading">
              <strong>${escapeHTML(report.category)}</strong>
              <span class="badge-soft ${report.stage === 'Resolved' ? 'soft-green' : report.stage === 'Rejected' ? 'soft-red' : 'soft-yellow'}">${escapeHTML(report.stage)}</span>
            </div>
            <div class="small text-secondary">${escapeHTML(report.tracking)} · ${escapeHTML(report.purok)} · ${new Date(report.created).toLocaleString()}</div>
            <p>${escapeHTML(report.description)}</p>
            ${report.location ? `<div class="small text-secondary">Landmark: ${escapeHTML(report.location)}</div>` : ''}
          </article>
        `).join('') + `
          <div class="d-flex justify-content-between align-items-center mt-2">
            <button class="btn btn-sm btn-outline-secondary" data-resident-report-page="${escapeHTML(resident.id)}" data-page="${reportPage.pagination.currentPage - 1}" ${reportPage.pagination.currentPage <= 1 ? 'disabled' : ''}>Previous</button>
            <span class="small text-secondary">Page ${reportPage.pagination.currentPage} of ${reportPage.pagination.lastPage}</span>
            <button class="btn btn-sm btn-outline-secondary" data-resident-report-page="${escapeHTML(resident.id)}" data-page="${reportPage.pagination.currentPage + 1}" ${reportPage.pagination.currentPage >= reportPage.pagination.lastPage ? 'disabled' : ''}>Next</button>
          </div>`
        : '';
      const reportButton = Number(resident.reportCount) > 0
        ? `<button class="btn btn-sm btn-outline-primary mt-3" data-resident-report-page="${escapeHTML(resident.id)}" data-page="1">${reportPage ? 'Refresh reports' : `View ${resident.reportCount} report${resident.reportCount === 1 ? '' : 's'}`}</button>`
        : '<p class="resident-empty text-secondary mt-3">This resident has not submitted any complaints.</p>';

      return `
        <article class="resident-card card">
          <div class="resident-card-heading">
            <div>
              <h2>${escapeHTML(resident.fullName)}</h2>
              <div class="small text-secondary">@${escapeHTML(resident.username)} · ${escapeHTML(resident.email)}</div>
            </div>
            <div class="d-flex flex-column align-items-end gap-2">
              ${statusBadge}
              <span class="badge-soft soft-blue">${Number(resident.reportCount || 0)} complaint${Number(resident.reportCount || 0) === 1 ? '' : 's'}</span>
            </div>
          </div>
          <div class="resident-card-meta">Registered ${new Date(resident.registered).toLocaleDateString()}</div>
          ${approvalActions}
          ${reportButton}
          <div class="resident-complaints">${complaintHTML}</div>
        </article>
      `;
    }).join('');
  }

  // Fill and open the report action dialog.
  function openActionModal(id) {
    const issue = currentReports.find(i => i.id === id);
    if (!issue) return;

    document.getElementById('editId').value = issue.id;
    document.getElementById('incidentSummary').innerHTML = `
      <strong>${escapeHTML(issue.tracking)}</strong> — ${escapeHTML(issue.category)} in <strong>${escapeHTML(issue.purok)}</strong><br>
      <small class="text-secondary">${escapeHTML(issue.description)}</small>
      ${issue.attachmentId ? `<div class="mt-2"><img class="img-fluid rounded" src="/api/attachments/${encodeURIComponent(issue.attachmentId)}" alt="Report attachment" loading="lazy" style="max-height: 240px;"></div>` : ''}
    `;

    document.getElementById('editPriority').value = issue.priority || 'Normal';
    const nextStatuses = {
      Submitted: ['Validated'],
      Validated: ['Dispatched'],
      Dispatched: ['Resolved'],
      Rejected: []
    }[issue.stage] || [];
    const editStatus = document.getElementById('editStatus');
    editStatus.innerHTML = nextStatuses.map(status => `<option>${status}</option>`).join('');
    editStatus.disabled = nextStatuses.length === 0;
    if (nextStatuses.length > 0) editStatus.value = nextStatuses[0];
    document.getElementById('assignee').value = issue.assignee || 'Unassigned';
    document.getElementById('dispatchNote').value = issue.dispatchNote || '';
    document.getElementById('decision').value = 'Advance';
    const historyPanel = document.getElementById('reportStatusHistory');
    historyPanel.textContent = 'Loading status history…';
    apiRequest('getReportHistory', { id: issue.id }, 'GET')
      .then(result => {
        historyPanel.innerHTML = (result.history || []).map(entry => `
          <div class="border-top py-2">
            <strong>${escapeHTML(entry.newStatus)}</strong> · ${new Date(entry.changedAt).toLocaleString()}
            <div>${escapeHTML(entry.remarks || '')}</div>
          </div>
        `).join('') || '<p class="text-secondary">No status history is available.</p>';
      })
      .catch(error => {
        historyPanel.textContent = error.message || 'Status history could not be loaded.';
      });

    const modal = document.getElementById('actionModal');
    modal.classList.add('show');
    modal.style.display = 'block';
    document.body.classList.add('modal-open');
  }

  // Hide the report action dialog.
  function closeActionModal() {
    const modal = document.getElementById('actionModal');
    modal.classList.remove('show');
    modal.style.display = 'none';
    document.body.classList.remove('modal-open');
  }

  // Save the selected status, team, and resident message.
  async function saveAction() {
    const targetId = document.getElementById('editId').value;
    const issue = currentReports.find(i => i.id === targetId);

    if (!issue) return;

    const decision = document.getElementById('decision').value;
    const assignee = document.getElementById('assignee').value;

    const status = (decision === 'Reject') ? 'Rejected' : document.getElementById('editStatus').value;
    const dispatchNote = document.getElementById('dispatchNote').value.trim();

    if (!dispatchNote) {
      document.getElementById('dispatchNote').focus();
      throw new Error('Enter a message for the resident before saving.');
    }

    await apiRequest('updateReport', {
      id: targetId,
      status,
      assignee,
      dispatchNote
    });

    closeActionModal();
    await render();
  }

  // Save the selected report action.
  document.getElementById('saveActionBtn').addEventListener('click', async () => {
    try {
      await saveAction();
      window.showToast('Status update saved and sent to the resident.', 'success');
    } catch (error) {
      window.showToast(error.message, 'error');
    }
  });

  // Close the dialog from its close buttons.
  document.querySelectorAll('#actionModal [data-bs-dismiss="modal"]').forEach(button => {
    button.addEventListener('click', closeActionModal);
  });

  // Open the report action dialog from either table.
  ['incidentBody', 'resolvedBody'].forEach(tableBodyId => {
    document.getElementById(tableBodyId).addEventListener('click', event => {
      const button = event.target.closest('[data-report-id]');
      if (button) openActionModal(button.dataset.reportId);
    });
  });

  // Filter reports and residents as the controls change.
  // Reload reports when filters change and residents when the search changes.
  document.getElementById('filterStatus').addEventListener('change', () => {
    incidentPage = 1;
    render();
  });
  document.getElementById('filterPriority').addEventListener('change', () => {
    incidentPage = 1;
    render();
  });
  document.getElementById('residentSearch').addEventListener('input', renderResidents);
  async function handleResidentApproval(event) {
    const reportButton = event.target.closest('[data-resident-report-page]');
    if (reportButton) {
      try {
        const result = await apiRequest('getReports', {
          user_id: reportButton.dataset.residentReportPage,
          page: reportButton.dataset.page
        }, 'GET');
        residentReportPages.set(reportButton.dataset.residentReportPage, result);
        renderResidents();
      } catch (error) {
        window.showToast(error.message, 'error');
      }
      return;
    }

    const button = event.target.closest('[data-resident-approval]');
    if (!button) return;

    const status = button.dataset.residentApproval;
    const action = status === 'approved' ? 'approve' : 'reject';
    let reason;
    if (status === 'rejected') {
      reason = window.prompt('Enter the reason for rejection (for example, not a resident):');
      if (reason === null) return;
      if (!reason.trim()) {
        window.showToast('A rejection reason is required.', 'error');
        return;
      }
    }

    const confirmed = await window.confirmAction(
      `${action === 'approve' ? 'Approve' : 'Reject'} this resident account?`
    );
    if (!confirmed) return;

    try {
      const result = await apiRequest('updateResidentApproval', { status, reason }, 'POST', button.dataset.residentId);
      await loadResidents();
      const emailNotice = result.emailStatus === 'sent'
        ? ' An email update was sent to the resident.'
        : result.emailStatus === 'not_configured'
          ? ' Email delivery is not configured, so no email was sent.'
          : result.emailStatus === 'failed'
            ? ' The account was updated, but email delivery failed.'
            : '';
      window.showToast(`Resident account ${status}.${emailNotice}`, 'success');
    } catch (error) {
      window.showToast(error.message, 'error');
    }
  }
  document.getElementById('residentsList').addEventListener('click', handleResidentApproval);
  document.getElementById('pendingApprovalList').addEventListener('click', handleResidentApproval);
  document.getElementById('incidentBody').addEventListener('click', async event => {
    const button = event.target.closest('[data-archive-report-id]');
    if (!button) return;

    const confirmed = await window.confirmAction('Archive this report? You can restore it later from Archived Incidents.');
    if (!confirmed) return;

    try {
      await apiRequest('archiveReport', {}, 'POST', button.dataset.archiveReportId);
      await render();
    } catch (error) {
      window.showToast(error.message, 'error');
    }
  });

  document.getElementById('archivedBody').addEventListener('click', async event => {
    const restoreButton = event.target.closest('[data-restore-report-id]');
    if (restoreButton) {
      try {
        await apiRequest('restoreReport', {}, 'POST', restoreButton.dataset.restoreReportId);
        await renderArchivedReports();
      } catch (error) {
        window.showToast(error.message, 'error');
      }
      return;
    }

    const deleteButton = event.target.closest('[data-delete-archived-id]');
    if (!deleteButton) return;

    const confirmed = await window.confirmAction('Permanently delete this archived report, its attachments, and its history? This cannot be undone.');
    if (!confirmed) return;

    try {
      await apiRequest('deleteArchivedReport', {}, 'DELETE', deleteButton.dataset.deleteArchivedId);
      await renderArchivedReports();
    } catch (error) {
      window.showToast(error.message, 'error');
    }
  });

  async function renderArchivedReports() {
    const body = document.getElementById('archivedBody');
    try {
      const result = await apiRequest('getArchivedReports', { page: archivedPage }, 'GET');
      const reports = result.reports || [];
      body.innerHTML = reports.length
        ? reports.map(item => createIncidentRowHTML(item, { readOnly: true, archived: true })).join('')
        : '<tr><td colspan="7" class="admin-empty-state">No archived reports.</td></tr>';
      document.getElementById('archivedNavCount').textContent = result.pagination.total;
      renderPagination('archivedPagination', result.pagination, page => {
        archivedPage = page;
        renderArchivedReports();
      });
    } catch (error) {
      body.innerHTML = '<tr><td colspan="7" class="admin-empty-state alert-error">Could not load archived reports.</td></tr>';
      window.showToast(error.message, 'error');
    }
  }

  // Switch between dashboard sections.
  document.querySelectorAll('[data-admin-view]').forEach(button => {
    button.addEventListener('click', () => {
      const selectedView = button.dataset.adminView;
      document.querySelectorAll('[data-admin-view]').forEach(navButton => {
        const isActive = navButton === button;
        navButton.classList.toggle('active', isActive);
        if (isActive) {
          navButton.setAttribute('aria-current', 'page');
        } else {
          navButton.removeAttribute('aria-current');
        }
      });
      document.querySelectorAll('.admin-view').forEach(view => {
        view.hidden = view.id !== `${selectedView}View`;
      });
      if (selectedView === 'residents') {
        loadResidents();
      } else if (selectedView === 'pendingApprovals') {
        loadResidents();
      } else if (selectedView === 'archived') {
        renderArchivedReports();
      } else if (selectedView === 'referenceData') {
        loadReferenceData();
      } else if (['dashboard', 'incidents', 'resolved', 'analytics'].includes(selectedView)) {
        render();
      }
    });
  });

  // Archive unresolved and rejected reports after confirmation.
  document.getElementById('clearReportsBtn').addEventListener('click', async () => {
    const confirmed = await window.confirmAction('Archive all unresolved and rejected incidents? Resolved incidents will be kept. Archived reports remain available to administrators.');
    if (!confirmed) return;

    try {
      const result = await apiRequest('clearReports', { confirmed: true });
      await render();
      window.showToast(`${result.archived} incident(s) archived. Administrators can review them in Archived Incidents.`, 'success');
    } catch (error) {
      window.showToast(error.message, 'error');
    }
  });

  // End the admin session and return to the login page.
  document.getElementById('logoutBtn').addEventListener('click', async () => {
    const confirmed = await window.confirmAction('Are you sure you want to log out?');
    if (confirmed) {
      try {
        await apiRequest('logout');
      } finally {
        window.location.href = 'index.html';
      }
    }
  });

  render();
}

// Check the user's role before showing the admin dashboard.
async function startAdminDashboard() {
  try {
    const result = await apiRequest('session', {}, 'GET');
    if (result.user.role !== 'admin') {
      window.location.href = result.user.role === 'resident' ? 'resident-portal.html' : 'index.html';
      return;
    }
    document.getElementById('adminName').textContent = 'Welcome ! Admin';
    initAdminDashboardPage();
  } catch (error) {
    window.location.href = 'index.html';
  }
}

// Load Chart.js before starting the dashboard.
loadScript('/frontend/vendor/chart.js/chart.umd.js', 'chartjs-admin')
  .then(() => startAdminDashboard())
  .catch(() => console.error('Chart.js failed to load for admin-dashboard.html'));
