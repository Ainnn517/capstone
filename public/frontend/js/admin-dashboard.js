// Handles the admin dashboard, report actions, and analytics.

// API routes used by the admin dashboard.
const API_ENDPOINTS = {
  getReports: '/api/reports',
  getArchivedReports: '/api/reports/archived',
  getReportSummary: '/api/analytics/summary',
  getDssRecommendations: '/api/analytics/dss',
  saveDssSnapshot: '/api/analytics/dss/snapshots',
  clearDssSnapshots: '/api/analytics/dss/snapshots',
  getResidents: '/api/residents',
  getReferenceData: '/api/reference-data',
  createReference: '/api/reference-data/{resource}',
  updateReference: '/api/reference-data/{resource}/{id}',
  deleteReference: '/api/reference-data/{resource}/{id}',
  getReportHistory: '/api/reports/{id}/history',
  getReportTriage: '/api/reports/{id}/triage',
  evaluationNext: '/api/evaluation/next',
  evaluationLabel: '/api/evaluation/labels',
  evaluationSummary: '/api/evaluation/summary',
  updateResidentApproval: '/api/residents/{id}/approval',
  deleteResident: '/api/residents/{id}',
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
    renderPriorityCounts(summary.summary.openByPriority || {});
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
    renderDSSRecommendations(dss);
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
  // Red / yellow / green counters on the Incident Management menu item (open incidents by
  // priority). Zero counts are hidden; with nothing open, a single green 0 shows.
  function renderPriorityCounts(counts) {
    const container = document.getElementById('incidentPriorityCounts');
    const pills = [
      ['critical', 'Critical', counts.critical],
      ['high', 'High', counts.high],
      ['normal', 'Normal', counts.normal]
    ].filter(([, , count]) => Number(count) > 0);
    container.innerHTML = pills.length
      ? pills.map(([level, label, count]) => `<span class="priority-pill priority-${level}" title="${count} open ${label.toLowerCase()} priority">${count}</span>`).join('')
      : '<span class="priority-pill priority-normal" title="No open incidents">0</span>';
    container.setAttribute('aria-label', pills.length
      ? 'Open incidents: ' + pills.map(([, label, count]) => `${count} ${label}`).join(', ')
      : 'No open incidents');
  }

  function createIncidentRowHTML(item, { readOnly = false, archived = false } = {}) {
    const priorityClass = `row-priority-${String(item.priority || 'Normal').toLowerCase()}`;
    return `
        <tr class="${priorityClass}">
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

  // Legend for the category chart: colour, name, count, and share of all reports.
  function renderPieLegend(counts, colors) {
    const legend = document.getElementById('pieLegend');
    const total = counts.reduce((sum, count) => sum + count, 0);
    if (total === 0) {
      legend.innerHTML = '<li class="chart-legend-empty">No reports yet.</li>';
      return;
    }
    legend.innerHTML = CATEGORIES.map((category, index) => `
      <li>
        <span class="chart-legend-swatch" style="background:${colors[index]}"></span>
        <span class="chart-legend-name">${escapeHTML(category)}</span>
        <span class="chart-legend-value">${counts[index]} <small>(${Math.round((counts[index] / total) * 100)}%)</small></span>
      </li>
    `).join('');
  }

  // Refresh the analytics charts and hotspot grid.
  function renderAnalytics(summary) {
    renderCharts(summary);
    renderHotspotGrid(summary.hotspots || []);
  }

  // Draw report totals by category and month using server-side aggregates.
  function renderCharts(summary) {
    const categoryCounts = CATEGORIES.map(category => Number(summary.categories[category] || 0));
    // Enough distinct colours for categories added later in Reference Data (they repeat after 10).
    const palette = ['#145da0', '#198754', '#f59e0b', '#dc3545', '#0ea5e9', '#64748b', '#8b5cf6', '#ec4899', '#14b8a6', '#eab308'];
    const categoryColors = CATEGORIES.map((_, index) => palette[index % palette.length]);

    if (pieChartInstance) pieChartInstance.destroy();
    const pieCtx = document.getElementById('pieChart').getContext('2d');
    pieChartInstance = new Chart(pieCtx, {
      type: 'doughnut',
      data: {
        labels: CATEGORIES,
        datasets: [{
          data: categoryCounts,
          backgroundColor: categoryColors
        }]
      },
      options: {
        responsive: true,
        // The legend is HTML (below) so its rows line up in a grid; Chart.js draws its own
        // legend into the canvas, where rows wrap unevenly.
        plugins: { legend: { display: false } }
      }
    });
    renderPieLegend(categoryCounts, categoryColors);

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

  // DSS recommendations written as a message from a "DSS Assistant". The wording is built
  // from the backend DSS scores with fixed rules (not an AI model), so it cannot invent facts.
  // Exact scores are kept in saved snapshots and explained in docs/DSS-METHODOLOGY.md.
  function renderDSSRecommendations(dss) {
    const recommendations = dss.recommendations || [];
    const container = document.getElementById('dssPanel');
    const hour = new Date().getHours();
    const greeting = hour < 12 ? 'Good morning!' : hour < 18 ? 'Good afternoon!' : 'Good evening!';
    const plural = (count, word) => `${count} ${word}${count === 1 ? '' : 's'}`;

    // "Lawaan Street, St. Joseph Village 10 - Phase 2" -> "on Lawaan Street (St. Joseph Village 10 - Phase 2)"
    const place = location => {
      const [street, ...rest] = String(location).split(', ');
      const area = rest.join(', ');
      if (street === 'Area-wide') return `across ${escapeHTML(area || 'the area')}`;
      return `on <strong>${escapeHTML(street)}</strong>${area ? ` (${escapeHTML(area)})` : ''}`;
    };
    const age = (days, count) => {
      const text = days < 1 ? 'filed today' : days < 2 ? 'about a day old' : `${Math.round(days)} days old`;
      return count > 1 && days >= 1 ? `${text} on average` : text;
    };
    const lowerFirst = text => text ? text.charAt(0).toLowerCase() + text.slice(1).replace(/\.$/, '') : '';

    let message;
    if (recommendations.length === 0) {
      message = `<p>${greeting} There are no open reports that need prioritising right now. I'll let you know here when something comes in.</p>`;
    } else {
      const high = recommendations.filter(rec => rec.urgency === 'High').length;
      const intro = `<p>${greeting} I went through ${plural(Number(dss.sourceReportCount || 0), 'open report')}. `
        + (high > 0 ? `${high === 1 ? 'One area is' : `${high} areas are`} urgent. Here's what I'd prioritise, most important first:` : `Nothing is urgent right now, but here's what I'd schedule next:`)
        + '</p>';
      const items = recommendations.map((rec, index) => {
        const reports = plural(Number(rec.reportCount), 'open report');
        const critical = Number(rec.criticalCount) > 0 ? `, and ${Number(rec.criticalCount) === 1 ? 'it is' : `${rec.criticalCount} are`} critical` : '';
        const urgency = rec.urgency !== 'High'
          ? '<span class="dss-urgency">can be scheduled</span>'
          : index === 0
            ? '<span class="dss-urgency dss-urgency-high">needs attention first</span>'
            : '<span class="dss-urgency dss-urgency-high">is also urgent</span>';
        const team = rec.recommendedTeam
          ? `I suggest sending the <strong>${escapeHTML(rec.recommendedTeam)}</strong> to ${escapeHTML(lowerFirst(rec.recommendedAction))}.`
          : `No team is set for this category yet. Next step: ${escapeHTML(lowerFirst(rec.recommendedAction))}.`;
        return `
          <li>
            <strong>${escapeHTML(rec.category)}</strong> ${place(rec.location)} ${urgency}.
            There ${Number(rec.reportCount) === 1 ? 'is' : 'are'} ${reports}, ${age(Number(rec.averageAgeDays), Number(rec.reportCount))}${critical}.
            ${team}
          </li>`;
      }).join('');
      message = `${intro}<ol class="dss-items">${items}</ol>`;
    }

    container.innerHTML = `
      <div class="dss-chat">
        <div class="dss-avatar" aria-hidden="true"><i class="fi fi-rr-chart-histogram"></i></div>
        <div class="dss-bubble">
          <div class="dss-name">DSS Assistant</div>
          ${message}
        </div>
      </div>`;
  }

  function renderDssHistory(history) {
    const container = document.getElementById('dssHistory');
    dssSnapshotCount = history.length;
    document.getElementById('clearDssSnapshotsBtn').disabled = history.length === 0;
    // Each snapshot expands to show exactly what the DSS recommended at that moment.
    container.innerHTML = history.length
      ? history.map(snapshot => {
        const recommendations = snapshot.recommendations || [];
        const items = recommendations.map((rec, index) => `
          <li>
            <div class="snapshot-rec-head">
              <strong>#${index + 1} ${escapeHTML(rec.category)}</strong>
              <span class="badge-soft ${rec.urgency === 'High' ? 'soft-red' : 'soft-yellow'}">${escapeHTML(rec.urgency)} urgency</span>
            </div>
            <div>${escapeHTML(rec.location)}</div>
            <div class="small text-secondary">Score ${Number(rec.score).toFixed(2)} · ${rec.reportCount} report(s) · avg age ${Number(rec.averageAgeDays).toFixed(1)} day(s) · ${rec.criticalCount} critical</div>
            <div class="small">Team: ${escapeHTML(rec.recommendedTeam || 'None set')} · ${escapeHTML(rec.recommendedAction || '')}</div>
          </li>`).join('');
        return `
          <details class="snapshot-entry">
            <summary>
              <strong>${new Date(snapshot.generatedAt).toLocaleString()}</strong>
              <span class="small text-secondary">· ${snapshot.sourceReportCount} open report(s) · ${recommendations.length} recommendation(s)${snapshot.savedBy ? ` · saved by ${escapeHTML(snapshot.savedBy)}` : ''}</span>
            </summary>
            ${recommendations.length
              ? `<ol class="snapshot-recs">${items}</ol>`
              : '<p class="small text-secondary mb-0">There were no open reports to prioritise at this time.</p>'}
          </details>`;
      }).join('')
      : '<div class="small text-secondary">No saved DSS snapshots yet.</div>';
  }

  let dssSnapshotCount = 0;
  document.getElementById('clearDssSnapshotsBtn').addEventListener('click', async () => {
    const shown = dssSnapshotCount >= 10 ? 'all saved' : `the ${dssSnapshotCount} saved`;
    const confirmed = await window.confirmAction(
      `Delete ${shown} DSS snapshot${dssSnapshotCount === 1 ? '' : 's'}? Current recommendations are not affected, but the saved history cannot be recovered.`
    );
    if (!confirmed) return;
    try {
      const result = await apiRequest('clearDssSnapshots', { confirmed: true }, 'DELETE');
      await render();
      window.showToast(`Snapshot history cleared (${result.cleared} deleted).`, 'success');
    } catch (error) {
      window.showToast(error.message, 'error');
    }
  });

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
      // Pending and rejected applications are listed under Pending Approvals, not under Residents.
      document.getElementById('residentNavCount').textContent = residents.filter(resident => ['approved', 'suspended'].includes(resident.accountStatus)).length;
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
  // Applications: pending sign-ups to review, then rejected ones (which can be approved
  // after all, or deleted). Neither appears in the Residents list.
  function renderPendingApprovals() {
    const pendingResidents = residents.filter(resident => resident.accountStatus === 'pending');
    const rejectedResidents = residents.filter(resident => resident.accountStatus === 'rejected');
    const count = pendingResidents.length;
    document.getElementById('pendingApprovalNavCount').textContent = count;
    document.getElementById('pendingApprovalsPageCount').textContent = `${count} pending`;

    const applicationCard = (resident, badge, buttons) => `
      <article class="resident-card card">
        <div class="resident-card-heading">
          <div>
            <h2>${escapeHTML(resident.fullName)}</h2>
            <div class="small text-secondary">@${escapeHTML(resident.username)} · ${escapeHTML(resident.email)}</div>
          </div>
          ${badge}
        </div>
        <div class="resident-card-meta">Registered ${new Date(resident.registered).toLocaleDateString()}</div>
        <div class="d-flex gap-2 mt-3">${buttons}</div>
      </article>`;

    const pendingHTML = count === 0
      ? '<div class="card p-4 text-secondary">There are no pending resident registrations.</div>'
      : pendingResidents.map(resident => applicationCard(resident,
          '<span class="badge-soft soft-yellow">Pending approval</span>',
          `<button class="btn btn-sm btn-success" type="button" data-resident-approval="approved" data-resident-id="${escapeHTML(resident.id)}">Approve</button>
           <button class="btn btn-sm btn-outline-danger" type="button" data-resident-approval="rejected" data-resident-id="${escapeHTML(resident.id)}">Reject</button>`
        )).join('');

    const rejectedHTML = rejectedResidents.length === 0 ? '' : `
      <h2 class="applications-subheading">Rejected applications <span class="badge-soft soft-red">${rejectedResidents.length}</span></h2>
      ${rejectedResidents.map(resident => applicationCard(resident,
        '<span class="badge-soft soft-red">Rejected</span>',
        `<button class="btn btn-sm btn-success" type="button" data-resident-approval="approved" data-resident-id="${escapeHTML(resident.id)}">Approve account</button>
         <button class="btn btn-sm btn-outline-danger" type="button" data-resident-delete data-resident-id="${escapeHTML(resident.id)}">Delete account</button>`
      )).join('')}`;

    document.getElementById('pendingApprovalList').innerHTML = pendingHTML + rejectedHTML;
  }

  // Filter and display residents; individual reports are fetched on demand.
  // ---- Reference data (categories, teams, locations) ----
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

    // Renaming changes the name shown on every past report linked to this item, so ask first.
    // `defaultValue` still holds the name the row was loaded with.
    const renamed = [...row.querySelectorAll('input[name="name"], input[name="area_name"]')]
      .filter(input => input.value.trim() !== input.defaultValue.trim() && input.value.trim() !== '');
    if (renamed.length > 0) {
      const changes = renamed
        .map(input => `"${input.defaultValue.trim()}" to "${input.value.trim()}"`)
        .join(', and ');
      const message = {
        locations: `Rename this location from ${changes}? Every past report at this location will show the new name. If the street was replaced by a different one, add a new location and uncheck "Available to residents" on this one instead.`,
        categories: `Rename this category from ${changes}? Every past report in this category will show the new name. Automatic category suggestions and DSS recommended actions are set up under the current name and will need to be updated by the system maintainer.`,
        teams: `Rename this team from ${changes}? Every past report assigned to this team will show the new name.`
      }[resource];
      if (!await window.confirmAction(message)) return;
    }

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

  function renderResidents() {
    const search = document.getElementById('residentSearch').value.trim().toLowerCase();
    // Only actual residents (approved or suspended). Pending and rejected applications are
    // shown under Pending Approvals instead.
    const listedResidents = residents.filter(resident => ['approved', 'suspended'].includes(resident.accountStatus));
    const matchingResidents = listedResidents.filter(resident =>
      `${resident.fullName} ${resident.username} ${resident.email}`.toLowerCase().includes(search)
    );
    const complaintCount = listedResidents.reduce((total, resident) => total + Number(resident.reportCount || 0), 0);
    const pendingCount = residents.filter(resident => resident.accountStatus === 'pending').length;

    document.getElementById('residentsSummary').innerHTML = `
      <div class="resident-stat"><strong>${listedResidents.length}</strong><span>Registered residents</span></div>
      <div class="resident-stat"><strong>${pendingCount}</strong><span>Pending approvals</span></div>
      <div class="resident-stat"><strong>${complaintCount}</strong><span>Total complaints</span></div>
    `;

    if (listedResidents.length === 0) {
      document.getElementById('residentsList').innerHTML =
        '<div class="card p-4 text-secondary">No registered residents found.</div>';
      return;
    }

    if (matchingResidents.length === 0) {
      document.getElementById('residentsList').innerHTML =
        '<div class="card p-4 text-secondary">No residents match your search.</div>';
      return;
    }

    document.getElementById('residentsList').innerHTML = matchingResidents.map(resident => {
      const reportPage = residentReportPages.get(resident.id);
      const statusBadge = {
        approved: '<span class="badge-soft soft-green">Approved</span>',
        rejected: '<span class="badge-soft soft-red">Rejected</span>',
        suspended: '<span class="badge-soft soft-red">Suspended</span>'
      }[resident.accountStatus] || '<span class="badge-soft soft-yellow">Pending approval</span>';
      const residentId = escapeHTML(resident.id);
      const approvalButtons = {
        pending: `
          <button class="btn btn-sm btn-success" type="button" data-resident-approval="approved" data-resident-id="${residentId}">Approve</button>
          <button class="btn btn-sm btn-outline-danger" type="button" data-resident-approval="rejected" data-resident-id="${residentId}">Reject</button>`,
        rejected: `
          <button class="btn btn-sm btn-success" type="button" data-resident-approval="approved" data-resident-id="${residentId}">Approve account</button>
          <button class="btn btn-sm btn-outline-danger" type="button" data-resident-delete data-resident-id="${residentId}">Delete account</button>`,
        approved: `
          <button class="btn btn-sm btn-outline-danger" type="button" data-resident-approval="suspended" data-resident-id="${residentId}">Suspend account</button>`,
        suspended: `
          <button class="btn btn-sm btn-success" type="button" data-resident-approval="approved" data-resident-id="${residentId}">Reactivate account</button>`
      }[resident.accountStatus];
      const approvalActions = approvalButtons
        ? `<div class="d-flex gap-2 mt-3">${approvalButtons}</div>`
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
    // Start empty: re-using the previous status message would send the resident an outdated update.
    document.getElementById('dispatchNote').value = '';
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
    renderTriageActions(issue);
    loadTriage(issue);

    const modal = document.getElementById('actionModal');
    modal.classList.add('show');
    // Clear the inline style so the CSS `.modal.show { display: flex }` can center the dialog.
    modal.style.display = '';
    document.body.classList.add('modal-open');
  }

  // ---- Evaluation: label real reports blind, then compare residents, rules, and AI ----
  let evaluationSkip = [];
  let evaluationReportId = null;

  async function loadEvaluation() {
    await Promise.all([loadEvaluationTask(), loadEvaluationResults()]);
  }

  async function loadEvaluationTask() {
    const task = document.getElementById('evaluationTask');
    try {
      const result = await apiRequest('evaluationNext', evaluationSkip.length ? { skip: evaluationSkip.join(',') } : {}, 'GET');
      document.getElementById('evaluationProgress').textContent = `${result.labelled} of ${result.total} reports labelled`;
      if (!result.report) {
        evaluationReportId = null;
        task.innerHTML = evaluationSkip.length
          ? '<p class="mb-0">No more reports to label. <button class="btn btn-sm btn-light" type="button" data-eval-reset-skip>Show skipped reports again</button></p>'
          : '<p class="mb-0">Every report has been labelled. New reports will appear here as residents submit them.</p>';
        return;
      }
      evaluationReportId = result.report.id;
      const categories = (referenceData.categories || []).map(category => `
        <button class="btn btn-sm btn-light" type="button" data-eval-category="${escapeHTML(category.category_id)}">${escapeHTML(category.name)}</button>`).join('');
      task.innerHTML = `
        <p class="small text-secondary mb-2">Which category does this report really belong to?</p>
        <blockquote class="evaluation-description">${escapeHTML(result.report.description)}</blockquote>
        <div class="evaluation-choices">${categories}</div>
        <div class="evaluation-actions">
          <button class="btn btn-sm btn-outline-secondary" type="button" data-eval-skip>Skip (unclear)</button>
        </div>`;
    } catch (error) {
      task.textContent = error.message || 'The next report could not be loaded.';
    }
  }

  async function loadEvaluationResults() {
    const container = document.getElementById('evaluationResults');
    try {
      const result = await apiRequest('evaluationSummary', {}, 'GET');
      if (result.labelled === 0) {
        container.innerHTML = '<p class="text-secondary mb-0">No reports labelled yet. Results appear here after the first labels.</p>';
        return;
      }
      const tile = (title, data, note) => `
        <div class="evaluation-stat">
          <span class="evaluation-stat-title">${title}</span>
          <strong>${data ? `${data.percent}%` : '—'}</strong>
          <span class="small text-secondary">${data ? `${data.correct} of ${data.total} correct` : note}</span>
        </div>`;
      const rows = result.perCategory.map(row => `
        <tr><td>${escapeHTML(row.category)}</td><td>${row.labelled}</td><td>${row.resident}</td><td>${row.rules}</td></tr>`).join('');
      const misses = result.misses.map(miss => `
        <li><span class="small">${escapeHTML(miss.description)}</span>
          <div class="small text-secondary">Correct: <strong>${escapeHTML(miss.label)}</strong> · rules said ${escapeHTML(miss.rules || '(nothing)')} · resident chose ${escapeHTML(miss.resident || '—')}</div></li>`).join('');
      container.innerHTML = `
        <div class="evaluation-stats">
          ${tile('Residents\' own choice', result.accuracy.resident, '')}
          ${tile('Keyword rules', result.accuracy.rules, '')}
          ${tile('AI', result.accuracy.ai, 'Not run yet')}
        </div>
        <p class="small text-secondary">${result.labelled} labelled report${result.labelled === 1 ? '' : 's'} · ${result.labellers} labeller${result.labellers === 1 ? '' : 's'}${result.labelled < 100 ? ' · aim for at least 100 for results you can report' : ''}</p>
        <div class="table-responsive">
          <table class="evaluation-table">
            <thead><tr><th>Correct category</th><th>Labelled</th><th>Residents right</th><th>Rules right</th></tr></thead>
            <tbody>${rows}</tbody>
          </table>
        </div>
        ${misses ? `<details class="evaluation-misses"><summary>Where the keyword rules were wrong (${result.misses.length})</summary><ul>${misses}</ul></details>` : ''}`;
    } catch (error) {
      container.textContent = error.message || 'Results could not be loaded.';
    }
  }

  document.getElementById('evaluationTask').addEventListener('click', async event => {
    const choice = event.target.closest('[data-eval-category]');
    if (choice && evaluationReportId) {
      try {
        await apiRequest('evaluationLabel', { report_id: Number(evaluationReportId), category_id: Number(choice.dataset.evalCategory) });
        await loadEvaluation();
      } catch (error) {
        window.showToast(error.message, 'error');
      }
      return;
    }
    if (event.target.closest('[data-eval-skip]') && evaluationReportId) {
      evaluationSkip.push(Number(evaluationReportId));
      await loadEvaluationTask();
      return;
    }
    if (event.target.closest('[data-eval-reset-skip]')) {
      evaluationSkip = [];
      await loadEvaluationTask();
    }
  });

  // Show the triage assistant's category check and possible duplicates for a report.
  // Assistant "Next step": suggest the category's team and draft the message to the resident.
  // Works instantly from Reference Data and the selected action; nothing is saved until "Save".
  function renderTriageActions(issue) {
    const container = document.getElementById('triageActions');
    const category = (referenceData.categories || []).find(item => item.name === issue.category);
    const team = (referenceData.teams || []).find(item => String(item.team_id) === String(category?.recommended_team_id ?? ''));
    const assignee = document.getElementById('assignee');

    const teamHTML = team
      ? (assignee.value === team.name
        ? `<p class="mb-2"><strong>Suggested team:</strong> ${escapeHTML(team.name)} <span class="small">(already assigned)</span></p>`
        : `<p class="mb-2"><strong>Suggested team:</strong> ${escapeHTML(team.name)}
             <button class="btn btn-sm btn-light ms-1" type="button" data-assistant-assign="${escapeHTML(team.name)}">Assign</button></p>`)
      : '<p class="mb-2"><strong>Suggested team:</strong> none set for this category (Reference Data).</p>';

    container.innerHTML = `${teamHTML}
      <p class="mb-2"><strong>Message to resident:</strong>
        <button class="btn btn-sm btn-light ms-1" type="button" data-assistant-draft>Draft it for me</button></p>`;
  }

  function draftResidentMessage() {
    const decision = document.getElementById('decision').value;
    const status = decision === 'Reject' ? 'Rejected' : document.getElementById('editStatus').value;
    const team = document.getElementById('assignee').value;
    const teamText = team && team !== 'Unassigned' ? `The ${team}` : 'A response team';
    return {
      Rejected: 'Thank you for your report. After review, we could not validate it, so it has been closed. Please contact the barangay office if you have more details.',
      Validated: 'Your report has been reviewed and confirmed by the barangay. A response team will be assigned soon.',
      Dispatched: `${teamText} has been assigned to your report and will respond soon.`,
      Resolved: 'Your report has been resolved. Thank you for helping keep our barangay safe and clean.'
    }[status] || 'Your report has been updated.';
  }

  document.getElementById('triageActions').addEventListener('click', event => {
    const assignButton = event.target.closest('[data-assistant-assign]');
    if (assignButton) {
      document.getElementById('assignee').value = assignButton.dataset.assistantAssign;
      const issue = currentReports.find(item => item.id === document.getElementById('editId').value);
      if (issue) renderTriageActions(issue);
      return;
    }
    if (event.target.closest('[data-assistant-draft]')) {
      const note = document.getElementById('dispatchNote');
      note.value = draftResidentMessage().slice(0, Number(note.maxLength) > 0 ? Number(note.maxLength) : 255);
      note.focus();
    }
  });

  async function loadTriage(issue) {
    const panel = document.getElementById('triagePanel');
    const sourceBadge = document.getElementById('triageSource');
    panel.textContent = 'Checking for duplicates and category fit…';
    sourceBadge.textContent = '';
    try {
      const result = await apiRequest('getReportTriage', {}, 'GET', issue.id);
      if (document.getElementById('editId').value !== issue.id) return; // Another report was opened.
      sourceBadge.textContent = result.source === 'ai' ? 'AI' : 'Keyword rules';

      const suggestion = result.category || {};
      let categoryHTML;
      if (suggestion.category && suggestion.category !== issue.category) {
        categoryHTML = `<p class="mb-2"><strong>Category check:</strong> this reads like <strong>${escapeHTML(suggestion.category)}</strong>
          (the resident chose ${escapeHTML(issue.category)}).
          ${suggestion.reason ? `<span class="d-block small">${escapeHTML(suggestion.reason)}</span>` : ''}</p>`;
      } else if (suggestion.category) {
        categoryHTML = '<p class="mb-2"><strong>Category check:</strong> matches the category the resident chose.</p>';
      } else {
        categoryHTML = '<p class="mb-2"><strong>Category check:</strong> no confident suggestion.</p>';
      }

      const duplicates = result.duplicates || [];
      const duplicatesHTML = duplicates.length
        ? `<p class="mb-1"><strong>Possible duplicates (${duplicates.length}):</strong> open reports at the same location that may be the same issue.</p>
           <ul class="triage-duplicates">${duplicates.map(item => `
             <li><strong>${escapeHTML(item.tracking)}</strong> · ${escapeHTML(item.category || '')} · ${escapeHTML(item.status)} · ${new Date(item.submitted).toLocaleDateString()}
               <div class="small">${escapeHTML(item.description)}</div></li>`).join('')}</ul>`
        : '<p class="mb-0"><strong>Possible duplicates:</strong> none found among recent open reports at this location.</p>';

      panel.innerHTML = categoryHTML + duplicatesHTML;
    } catch (error) {
      panel.textContent = 'The triage assistant is unavailable right now. You can still process this report normally.';
    }
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

    if (['Dispatched', 'Resolved'].includes(status) && assignee === 'Unassigned') {
      document.getElementById('assignee').focus();
      throw new Error(`Assign a team before marking this report ${status}.`);
    }

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

    const deleteButton = event.target.closest('[data-resident-delete]');
    if (deleteButton) {
      const resident = residents.find(item => item.id === deleteButton.dataset.residentId);
      const name = resident ? `${resident.fullName} (@${resident.username})` : 'this resident';
      const confirmed = await window.confirmAction(
        `Permanently delete the rejected account of ${name}? This cannot be undone.`
      );
      if (!confirmed) return;
      try {
        await apiRequest('deleteResident', {}, 'DELETE', deleteButton.dataset.residentId);
        await loadResidents();
        window.showToast('Rejected resident account deleted.', 'success');
      } catch (error) {
        window.showToast(error.message, 'error');
      }
      return;
    }

    const button = event.target.closest('[data-resident-approval]');
    if (!button) return;

    const status = button.dataset.residentApproval;
    let reason;
    if (status === 'rejected' || status === 'suspended') {
      // One themed dialog asks for the reason and acts as the confirmation.
      reason = await window.promptAction(
        status === 'rejected'
          ? 'Enter the reason for rejecting this account. It will be emailed to the applicant.'
          : 'Enter the reason for suspending this account. The resident will be signed out and the reason emailed to them.',
        {
          placeholder: status === 'rejected' ? 'For example: not a resident of this barangay' : 'For example: repeated false reports',
          confirmLabel: status === 'rejected' ? 'Reject account' : 'Suspend account'
        }
      );
      if (reason === null) return;
    } else {
      const reactivating = button.textContent.trim().startsWith('Reactivate');
      const confirmed = await window.confirmAction(reactivating ? 'Reactivate this resident account?' : 'Approve this resident account?');
      if (!confirmed) return;
    }

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
      } else if (selectedView === 'evaluation') {
        loadEvaluation();
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

  // Load teams and categories from the server first, so the team dropdown and the
  // hotspot map include items added in Reference Data (not just the built-in defaults).
  loadReferenceData().finally(render);
  // Load resident accounts now so the Residents and Pending Approvals counts are right
  // before those views are opened (a waiting sign-up should be visible immediately).
  loadResidents();
}

// Check the user's role before showing the admin dashboard.
async function startAdminDashboard() {
  try {
    const result = await apiRequest('session', {}, 'GET');
    if (result.user.role !== 'admin') {
      window.location.href = result.user.role === 'resident' ? 'resident-portal.html' : 'index.html';
      return;
    }
    document.getElementById('adminName').textContent = `Welcome, ${result.user.fullName || result.user.username}`;
    window.fillAccountDetails(result.user);
    initAdminDashboardPage();
  } catch (error) {
    window.location.href = 'index.html';
  }
}

// Load Chart.js before starting the dashboard.
loadScript('/frontend/vendor/chart.js/chart.umd.js', 'chartjs-admin')
  .then(() => startAdminDashboard())
  .catch(() => console.error('Chart.js failed to load for admin-dashboard.html'));
