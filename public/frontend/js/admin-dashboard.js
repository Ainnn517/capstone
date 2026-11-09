// Handles the admin dashboard, report actions, and analytics.

// API routes used by the admin dashboard.
const API_ENDPOINTS = {
  getReports: '/api/reports',
  getArchivedReports: '/api/reports/archived',
  getResidents: '/api/residents',
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

  const endpoint = API_ENDPOINTS[action].replace('{id}', encodeURIComponent(resourceId));
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

// Load an external script, such as Chart.js.
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
  const CATEGORIES = ['Sanitation', 'Clogged Drainage', 'Streetlights', 'Road Damage', 'Public Safety', 'Others'];

  let pieChartInstance = null;
  let lineChartInstance = null;
  let currentReports = [];
  let residents = [];
  let residentReports = [];

  // Load reports for the dashboard.
  async function syncIssuesFromServer() {
    const result = await apiRequest('getReports', {}, 'GET');
    return result.reports || [];
  }

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
    let issuesList;
    try {
      issuesList = await syncIssuesFromServer();
    } catch (error) {
      const errorRow = '<tr><td colspan="7" class="text-center text-danger py-5">Could not load reports. Check the API connection and your session.</td></tr>';
      document.getElementById('incidentBody').innerHTML = errorRow;
      document.getElementById('resolvedBody').innerHTML = errorRow;
      return;
    }
    currentReports = issuesList;
    const statusFilter = document.getElementById('filterStatus').value;
    const priorityFilter = document.getElementById('filterPriority').value;

    const activeReports = issuesList.filter(item => item.stage !== 'Resolved');
    const resolvedReports = issuesList.filter(item => item.stage === 'Resolved');
    const filtered = activeReports.filter(item => {
      const matchesStatus = (statusFilter === 'All Status' || item.stage === statusFilter);
      const matchesPriority = (priorityFilter === 'All Priority' || item.priority === priorityFilter);
      return matchesStatus && matchesPriority;
    });

    document.getElementById('incidentBody').innerHTML = filtered.length
      ? filtered.map(item => createIncidentRowHTML(item)).join('')
      : '<tr><td colspan="7" class="admin-empty-state">No active incidents match the selected filters.</td></tr>';

    document.getElementById('resolvedBody').innerHTML = resolvedReports.length
      ? resolvedReports.map(item => createIncidentRowHTML(item, { readOnly: true })).join('')
      : '<tr><td colspan="7" class="admin-empty-state">No resolved incidents yet.</td></tr>';
    document.getElementById('resolvedCount').textContent = `${resolvedReports.length} resolved`;
    document.getElementById('resolvedNavCount').textContent = resolvedReports.length;

    const resolvedCount = resolvedReports.length;
    document.getElementById('mTotal').textContent = issuesList.length;
    document.getElementById('mDispatch').textContent = issuesList.filter(i => i.stage === 'Dispatched').length;
    document.getElementById('mResolution').textContent = issuesList.length ? Math.round((resolvedCount / issuesList.length) * 100) + '%' : '0%';

    const purokCounts = {};
    activeReports.forEach(i => {
      purokCounts[i.purok] = (purokCounts[i.purok] || 0) + 1;
    });
    document.getElementById('mHotspots').textContent = Object.values(purokCounts).filter(val => val >= 3).length;

    renderDSSRecommendations(issuesList);
    if (!document.getElementById('analyticsView').hidden) {
      renderAnalytics(issuesList);
    }
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
                    <button class="btn btn-sm btn-outline-primary" data-report-id="${escapeHTML(item.id)}">Manage</button>
                    <button class="btn btn-sm btn-outline-secondary" data-archive-report-id="${escapeHTML(item.id)}">Archive</button>
                  </div>`}
          </td>
        </tr>
      `;
  }

  // Refresh the analytics charts and hotspot grid.
  function renderAnalytics(issuesList) {
    renderCharts(issuesList);
    renderHotspotGrid(issuesList);
  }

  // Draw report totals by category and month.
  function renderCharts(issuesList) {
    const categoryCounts = CATEGORIES.map(c => issuesList.filter(i => i.category === c).length);

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
    const monthlyData = months.map((_, monthIndex) => {
      return issuesList.filter(i => new Date(i.created).getMonth() === monthIndex).length;
    });

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
  function renderHotspotGrid(issuesList) {
    const activeIssues = issuesList.filter(issue => !['Resolved', 'Rejected'].includes(issue.stage));
    const locations = [...new Set(activeIssues.map(issue => issue.purok).filter(Boolean))]
      .sort((first, second) => first.localeCompare(second));

    if (locations.length === 0) {
      document.getElementById('hotspotGrid').innerHTML =
        '<div class="text-secondary small">No active incidents to map.</div>';
      return;
    }

    const gridHTML = locations.map(location => CATEGORIES.map(category => {
      const count = activeIssues.filter(issue =>
        issue.purok === location && issue.category === category
      ).length;
      const heatLevel = Math.min(5, 1 + Math.floor(count / 2));
      const shortCategory = category.replace('Clogged Drainage', 'Drainage').replace('Public Safety', 'Safety');

      return `
        <div class="hotspot-cell heat-${heatLevel}">
          <span>${escapeHTML(location)}</span>
          <span>${shortCategory}</span>
          <strong>${count} active</strong>
        </div>
      `;
    }).join('')).join('');

    document.getElementById('hotspotGrid').innerHTML = gridHTML;
  }

  // Rank active report groups and show suggested responses.
  function renderDSSRecommendations(issuesList) {
    const activeIssues = issuesList.filter(i => !['Resolved', 'Rejected'].includes(i.stage));
    const grouped = {};

    activeIssues.forEach(item => {
      const key = `${item.purok}|${item.category}`;
      if (!grouped[key]) grouped[key] = [];
      grouped[key].push(item);
    });

    const recommendations = Object.entries(grouped).map(([key, items]) => {
      const [purok, category] = key.split('|');
      const avgAgeDays = items.reduce((sum, item) => sum + (Date.now() - new Date(item.created).getTime()) / 86400000, 0) / items.length;
      const criticalCount = items.filter(item => item.priority === 'Critical').length;

      const score = (items.length * 3) + avgAgeDays + (criticalCount * 5);

      return {
        purok,
        category,
        count: items.length,
        avgAge: avgAgeDays,
        score
      };
    })
      .sort((a, b) => b.score - a.score)
      .slice(0, 5);

    const dssContainer = document.getElementById('dssPanel');

    if (recommendations.length === 0) {
      dssContainer.innerHTML = '<div class="admin-empty-state">No active unresolved incidents for DSS calculation.</div>';
    } else {
      dssContainer.innerHTML = recommendations.map(rec => {
        const isHighUrgency = rec.score > 8;
        return `
          <div class="border rounded-4 p-3 mb-2 bg-white">
            <div class="d-flex justify-content-between align-items-start">
              <strong>${escapeHTML(rec.purok)} — ${escapeHTML(rec.category)}</strong>
              <span class="badge-soft ${isHighUrgency ? 'soft-red' : 'soft-yellow'}">
                ${isHighUrgency ? 'High Urgency' : 'Moderate'}
              </span>
            </div>
            <div class="small text-secondary mt-1">
              ${rec.count} active report(s), avg age ${rec.avgAge.toFixed(1)} day(s).
            </div>
            <div class="small mt-2 pt-2 border-top">
              <strong>Action:</strong> ${isHighUrgency ? 'Deploy field response team immediately.' : 'Schedule field inspection.'}
            </div>
          </div>
        `;
      }).join('');
    }
  }

  // Load resident accounts and their reports.
  async function loadResidents() {
    const residentsList = document.getElementById('residentsList');
    residentsList.innerHTML = '<div class="card p-4 text-secondary">Loading residents and complaints…</div>';
    try {
      const [residentResult, reportResult] = await Promise.all([
        apiRequest('getResidents', {}, 'GET'),
        apiRequest('getReports', {}, 'GET')
      ]);
      residents = residentResult.residents || [];
      residentReports = reportResult.reports || [];
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

  // Filter and display residents with their complaints.
  function renderResidents() {
    const search = document.getElementById('residentSearch').value.trim().toLowerCase();
    const matchingResidents = residents.filter(resident => {
      const complaints = residentReports.filter(report => report.residentId === resident.id);
      const residentDetails = `${resident.fullName} ${resident.username} ${resident.email}`.toLowerCase();
      const complaintDetails = complaints.map(report =>
        `${report.tracking} ${report.category} ${report.purok} ${report.stage} ${report.description}`
      ).join(' ').toLowerCase();
      return !search || residentDetails.includes(search) || complaintDetails.includes(search);
    });
    const complaintCount = residentReports.length;
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
    if (matchingResidents.length === 0) {
      document.getElementById('residentsList').innerHTML =
        '<div class="card p-4 text-secondary">No residents or complaints match your search.</div>';
      return;
    }

    document.getElementById('residentsList').innerHTML = matchingResidents.map(resident => {
      const complaints = residentReports.filter(report => report.residentId === resident.id);
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
      const complaintHTML = complaints.length
        ? complaints.map(report => `
          <article class="resident-complaint">
            <div class="complaint-heading">
              <strong>${escapeHTML(report.category)}</strong>
              <span class="badge-soft ${report.stage === 'Resolved' ? 'soft-green' : report.stage === 'Rejected' ? 'soft-red' : 'soft-yellow'}">${escapeHTML(report.stage)}</span>
            </div>
            <div class="small text-secondary">${escapeHTML(report.tracking)} · ${escapeHTML(report.purok)} · ${new Date(report.created).toLocaleString()}</div>
            <p>${escapeHTML(report.description)}</p>
            ${report.location ? `<div class="small text-secondary">Landmark: ${escapeHTML(report.location)}</div>` : ''}
          </article>
        `).join('')
        : '<p class="resident-empty text-secondary">This resident has not submitted any complaints.</p>';

      return `
        <article class="resident-card card">
          <div class="resident-card-heading">
            <div>
              <h2>${escapeHTML(resident.fullName)}</h2>
              <div class="small text-secondary">@${escapeHTML(resident.username)} · ${escapeHTML(resident.email)}</div>
            </div>
            <div class="d-flex flex-column align-items-end gap-2">
              ${statusBadge}
              <span class="badge-soft soft-blue">${complaints.length} complaint${complaints.length === 1 ? '' : 's'}</span>
            </div>
          </div>
          <div class="resident-card-meta">Registered ${new Date(resident.registered).toLocaleDateString()}</div>
          ${approvalActions}
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
    document.getElementById('editStatus').value = issue.stage;
    document.getElementById('assignee').value = issue.assignee || 'Unassigned';
    document.getElementById('dispatchNote').value = issue.dispatchNote || '';
    document.getElementById('decision').value = 'Validate';

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
      window.alert('Status update saved and sent to the resident.');
    } catch (error) {
      window.alert(error.message);
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
  document.getElementById('filterStatus').addEventListener('change', render);
  document.getElementById('filterPriority').addEventListener('change', render);
  document.getElementById('residentSearch').addEventListener('input', renderResidents);
  async function handleResidentApproval(event) {
    const button = event.target.closest('[data-resident-approval]');
    if (!button) return;

    const status = button.dataset.residentApproval;
    const action = status === 'approved' ? 'approve' : 'reject';
    let reason;
    if (status === 'rejected') {
      reason = window.prompt('Enter the reason for rejection (for example, not a resident):');
      if (reason === null) return;
      if (!reason.trim()) {
        window.alert('A rejection reason is required.');
        return;
      }
    }

    const confirmed = window.confirm(
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
      window.alert(`Resident account ${status}.${emailNotice}`);
    } catch (error) {
      window.alert(error.message);
    }
  }
  document.getElementById('residentsList').addEventListener('click', handleResidentApproval);
  document.getElementById('pendingApprovalList').addEventListener('click', handleResidentApproval);
  document.getElementById('incidentBody').addEventListener('click', async event => {
    const button = event.target.closest('[data-archive-report-id]');
    if (!button) return;

    const confirmed = window.confirm('Archive this report? You can restore it later from Archived Incidents.');
    if (!confirmed) return;

    try {
      await apiRequest('archiveReport', {}, 'POST', button.dataset.archiveReportId);
      await render();
    } catch (error) {
      window.alert(error.message);
    }
  });

  document.getElementById('archivedBody').addEventListener('click', async event => {
    const restoreButton = event.target.closest('[data-restore-report-id]');
    if (restoreButton) {
      try {
        await apiRequest('restoreReport', {}, 'POST', restoreButton.dataset.restoreReportId);
        await renderArchivedReports();
      } catch (error) {
        window.alert(error.message);
      }
      return;
    }

    const deleteButton = event.target.closest('[data-delete-archived-id]');
    if (!deleteButton) return;

    const confirmed = window.confirm('Permanently delete this archived report, its attachments, and its history? This cannot be undone.');
    if (!confirmed) return;

    try {
      await apiRequest('deleteArchivedReport', {}, 'DELETE', deleteButton.dataset.deleteArchivedId);
      await renderArchivedReports();
    } catch (error) {
      window.alert(error.message);
    }
  });

  async function renderArchivedReports() {
    const body = document.getElementById('archivedBody');
    try {
      const result = await apiRequest('getArchivedReports', {}, 'GET');
      const reports = result.reports || [];
      body.innerHTML = reports.length
        ? reports.map(item => createIncidentRowHTML(item, { readOnly: true, archived: true })).join('')
        : '<tr><td colspan="7" class="admin-empty-state">No archived reports.</td></tr>';
      document.getElementById('archivedNavCount').textContent = reports.length;
    } catch (error) {
      body.innerHTML = '<tr><td colspan="7" class="admin-empty-state alert-error">Could not load archived reports.</td></tr>';
      window.alert(error.message);
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
      } else {
        render();
        loadResidents();
      }
    });
  });

  // Archive unresolved and rejected reports after confirmation.
  document.getElementById('clearReportsBtn').addEventListener('click', async () => {
    const confirmed = window.confirm('Archive all unresolved and rejected incidents? Resolved incidents will be kept. Archived reports remain available to administrators.');
    if (!confirmed) return;

    try {
      const result = await apiRequest('clearReports');
      await render();
      window.alert(`${result.archived} incident(s) archived. Administrators can review them in Archived Incidents.`);
    } catch (error) {
      window.alert(error.message);
    }
  });

  // End the admin session and return to the login page.
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
loadScript('https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js', 'chartjs-admin')
  .then(() => startAdminDashboard())
  .catch(() => console.error('Chart.js failed to load for admin-dashboard.html'));
