<!-- Admin report management and analytics page. -->
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="csrf-token" content="{{ csrf_token() }}">
  <title>Barangay Command Center - Operations Dashboard</title>
  <link rel="stylesheet" href="{{ \App\Support\Asset::url('/frontend/css/admin-dashboard.css') }}">
  <link rel="stylesheet" href="{{ \App\Support\Asset::url('/frontend/css/notifications.css') }}">
  <link rel="stylesheet" href="{{ \App\Support\Asset::url('/frontend/css/account.css') }}">
  <link rel="stylesheet" href="{{ \App\Support\Asset::url('/frontend/vendor/uicons-regular-rounded/css/uicons-regular-rounded.css') }}">
</head>
<body>

  <!-- Command Center Header Nav -->
  <nav class="navbar navbar-dark">
    <div class="container-fluid px-4">
      <div class="d-flex gap-2 align-items-center">
        <span id="adminName" class="text-white-50 small">Welcome</span>
        <button class="btn btn-light btn-sm" id="logoutBtn" type="button">Logout</button>
      </div>
    </div>
  </nav>

  <!-- Main Container -->
  <main class="container-fluid page-wrap px-3 px-lg-4 admin-layout">
    <aside class="admin-sidebar card" aria-label="Admin navigation">
      <p class="sidebar-label">Overview</p>
      <button class="sidebar-link active" type="button" data-admin-view="dashboard" aria-current="page">
        <i class="fi fi-rr-dashboard sidebar-icon" aria-hidden="true"></i>
        Dashboard
      </button>
      <button class="sidebar-link" type="button" data-admin-view="analytics">
        <i class="fi fi-rr-chart-histogram sidebar-icon" aria-hidden="true"></i>
        Analytics
      </button>

      <p class="sidebar-label">Reports</p>
      <button class="sidebar-link" type="button" data-admin-view="incidents">
        <i class="fi fi-rr-flag sidebar-icon" aria-hidden="true"></i>
        Incident Management
        <span class="priority-counts" id="incidentPriorityCounts" aria-label="Open incidents by priority"></span>
      </button>
      <button class="sidebar-link" type="button" data-admin-view="resolved">
        <i class="fi fi-rr-check-circle sidebar-icon" aria-hidden="true"></i>
        Resolved Incidents
        <span class="sidebar-count" id="resolvedNavCount">0</span>
      </button>
      <button class="sidebar-link" type="button" data-admin-view="archived">
        <i class="fi fi-rr-archive sidebar-icon" aria-hidden="true"></i>
        Archived Incidents
        <span class="sidebar-count" id="archivedNavCount">0</span>
      </button>

      <p class="sidebar-label">Settings</p>
      <button class="sidebar-link" type="button" data-admin-view="referenceData">
        <i class="fi fi-rr-settings sidebar-icon" aria-hidden="true"></i>
        Reference Data
      </button>
      <button class="sidebar-link" type="button" data-admin-view="evaluation">
        <i class="fi fi-rr-clipboard-list sidebar-icon" aria-hidden="true"></i>
        Evaluation
      </button>
      <button class="sidebar-link" type="button" data-admin-view="account">
        <i class="fi fi-rr-user sidebar-icon" aria-hidden="true"></i>
        Account
      </button>

      <hr class="sidebar-divider">
      <button class="sidebar-link" type="button" data-admin-view="residents">
        <i class="fi fi-rr-users-alt sidebar-icon" aria-hidden="true"></i>
        Residents
        <span class="sidebar-count" id="residentNavCount">—</span>
      </button>
      <button class="sidebar-link" type="button" data-admin-view="pendingApprovals">
        <i class="fi fi-rr-user-time sidebar-icon" aria-hidden="true"></i>
        Pending Approvals
        <span class="sidebar-count" id="pendingApprovalNavCount" aria-label="Pending approvals">0</span>
      </button>
    </aside>

    <div class="admin-content">
      <section id="dashboardView" class="admin-view">
    
    <!-- Title Section -->
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-3">
      <div>
        <h1 class="section-title mb-1">Operations Dashboard</h1>
        <p class="text-secondary mb-0">A quick overview of reports and response activity.</p>
      </div>
    </div>

    <!-- Key Metrics Row -->
    <div class="row g-3 mb-4">
      <div class="col-6 col-xl-3">
        <div class="card metric">
          <div class="label">Total Reports</div>
          <div id="mTotal" class="value">0</div>
        </div>
      </div>
      <div class="col-6 col-xl-3">
        <div class="card metric">
          <div class="label">Active Dispatches</div>
          <div id="mDispatch" class="value">0</div>
        </div>
      </div>
      <div class="col-6 col-xl-3">
        <div class="card metric">
          <div class="label">Resolution Rate</div>
          <div id="mResolution" class="value">0%</div>
        </div>
      </div>
      <div class="col-6 col-xl-3">
        <div class="card metric">
          <div class="label">Critical Hotspots</div>
          <div id="mHotspots" class="value">0</div>
        </div>
      </div>
    </div>

      </section>

      <section id="incidentsView" class="admin-view" hidden>
    <!-- Incident Table & DSS Panel -->
    <div class="row g-4">
      
      <!-- Incident Table -->
      <div class="col-12">
        <div class="card p-4 admin-panel">
          <div class="incident-toolbar">
            <div>
              <h1 class="section-title mb-1">Incident Management</h1>
              <small class="text-secondary">Open and rejected incidents that still need review or follow-up.</small>
            </div>
            
            <!-- Filters -->
            <div class="incident-filters">
              <select id="filterStatus" class="form-select form-select-sm" aria-label="Filter by status">
                <option>All Status</option>
                <option>Submitted</option>
                <option>Validated</option>
                <option>Dispatched</option>
                <option>Rejected</option>
                <option>Withdrawn</option>
              </select>
              <select id="filterPriority" class="form-select form-select-sm" aria-label="Filter by priority">
                <option>All Priority</option>
                <option>Critical</option>
                <option>High</option>
                <option>Normal</option>
              </select>
              <button id="clearReportsBtn" class="btn btn-outline-danger btn-sm" type="button">Archive Incidents</button>
            </div>
          </div>

          <!-- Table Content -->
          <div class="table-responsive">
            <table class="table align-middle">
              <thead>
                <tr>
                  <th>Tracking</th>
                  <th>Issue</th>
                  <th>Location</th>
                  <th>Status</th>
                  <th>Priority</th>
                  <th>Resident Upload</th>
                  <th>Action</th>
                </tr>
              </thead>
              <tbody id="incidentBody">
                <!-- Dynamically populated rows -->
              </tbody>
            </table>
          </div>
          <div id="incidentPagination" class="d-flex justify-content-between align-items-center gap-3 mt-3" aria-label="Incident report pagination"></div>
        </div>
      </div>

      <!-- DSS Recommendation System -->
      <div class="col-12">
        <div class="card p-4 h-100 admin-panel dss-card">
          <h4 class="section-title mb-1">DSS Recommendations</h4>
          <p class="small text-secondary mb-3">A plain-language summary of what to prioritise, written from the DSS scores. Save a snapshot to keep a record of today's recommendations.</p>
          <div class="dss-actions mb-3">
            <button id="saveDssSnapshotBtn" class="btn btn-outline-primary btn-sm" type="button">Save DSS snapshot</button>
            <button id="clearDssSnapshotsBtn" class="btn btn-outline-danger btn-sm" type="button" disabled>Clear snapshot history</button>
          </div>
          <div id="dssPanel" class="dss-panel">
            <!-- Dynamic recommendation items -->
          </div>
          <h5 class="dss-history-title">Saved snapshots</h5>
          <div id="dssHistory" aria-label="Saved DSS analytics history"></div>
        </div>
      </div>

    </div>

      </section>

      <section id="archivedView" class="admin-view" hidden>
        <div class="card p-4 admin-panel">
          <div class="admin-panel-header">
            <h1 class="section-title mb-1">Archived Incidents</h1>
            <small class="text-secondary">Archived records are hidden from active lists. Permanent deletion also removes related attachments and history.</small>
          </div>
          <div class="table-responsive">
            <table class="table align-middle">
              <thead>
                <tr>
                  <th>Tracking</th>
                  <th>Issue</th>
                  <th>Location</th>
                  <th>Status</th>
                  <th>Priority</th>
                  <th>Resident Upload</th>
                  <th>Retention</th>
                </tr>
              </thead>
              <tbody id="archivedBody"></tbody>
            </table>
          </div>
          <div id="archivedPagination" class="d-flex justify-content-between align-items-center gap-3 mt-3" aria-label="Archived report pagination"></div>
        </div>
      </section>

      <section id="resolvedView" class="admin-view" hidden>
        <div class="card p-4 admin-panel">
          <div class="admin-panel-header">
            <div>
              <h1 class="section-title mb-1">Resolved Incidents</h1>
              <small class="text-secondary">Completed reports are read-only and cannot be changed.</small>
            </div>
            <span id="resolvedCount" class="badge-soft soft-green">0 resolved</span>
          </div>
          <div class="table-responsive">
            <table class="table align-middle">
              <thead>
                <tr>
                  <th>Tracking</th>
                  <th>Issue</th>
                  <th>Location</th>
                  <th>Status</th>
                  <th>Priority</th>
                  <th>Resident Upload</th>
                  <th>Record</th>
                </tr>
              </thead>
              <tbody id="resolvedBody"></tbody>
            </table>
          </div>
          <div id="resolvedPagination" class="d-flex justify-content-between align-items-center gap-3 mt-3" aria-label="Resolved report pagination"></div>
        </div>
      </section>

      <section id="analyticsView" class="admin-view" hidden>
        <div class="mb-4">
          <h1 class="section-title mb-1">Analytics</h1>
          <p class="text-secondary mb-0">Explore report categories, monthly trends, and active issue hotspots.</p>
        </div>
    <!-- Analytics Charts Row -->
    <div class="row g-4 mt-1">
      <div class="col-lg-5">
        <div class="card p-4 h-100">
          <h5 class="fw-bold mb-3">Category Breakdown</h5>
          <div class="chart-donut"><canvas id="pieChart" height="240"></canvas></div>
          <ul id="pieLegend" class="chart-legend" aria-label="Reports by category"></ul>
        </div>
      </div>

      <div class="col-lg-7">
        <div class="card p-4 h-100">
          <h5 class="fw-bold mb-3">Monthly Report Trend</h5>
          <canvas id="lineChart" height="240"></canvas>
        </div>
      </div>
    </div>

    <!-- Hotspot Matrix Heatmap -->
    <div class="card p-4 mt-4">
      <div class="d-flex justify-content-between align-items-center">
        <div>
          <h4 class="section-title mb-1">Hotspot Map / Grid Matrix</h4>
          <p class="small text-secondary mb-0">Issue density across Puroks and categories.</p>
        </div>
        <span class="badge-soft soft-red">High Density Monitor</span>
      </div>
      <div id="hotspotGrid" class="hotspot-grid mt-3">
        <!-- Dynamic Hotspot Cells -->
      </div>
    </div>

    <!-- Resolution times learned from resolved reports -->
    <div class="card p-4 mt-4">
      <div class="d-flex justify-content-between align-items-center">
        <div>
          <h4 class="section-title mb-1">Resolution Times</h4>
          <p class="small text-secondary mb-0">Average time from report to resolution, against the target for each category's priority.</p>
        </div>
        <span id="resolutionOverdue" class="badge-soft soft-green">None overdue</span>
      </div>
      <div class="table-responsive mt-3">
        <table class="table align-middle" aria-label="Resolution times by category">
          <thead><tr><th>Category</th><th>Resolved</th><th>Average time</th><th>Target</th><th>Open &amp; overdue</th><th>Status</th></tr></thead>
          <tbody id="resolutionBody"></tbody>
        </table>
      </div>
    </div>

      </section>

      <section id="residentsView" class="admin-view" hidden>
        <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-3">
          <div>
            <h1 class="section-title mb-1">Registered Residents</h1>
            <p class="text-secondary mb-0">Review account requests, manage resident access, and view complaint history.</p>
          </div>
          <label class="resident-search">
            <span class="visually-hidden">Search residents or complaints</span>
            <input id="residentSearch" class="form-control" type="search" placeholder="Search residents or complaints" aria-label="Search residents">
          </label>
        </div>
        <div id="residentsSummary" class="resident-summary"></div>
        <div id="residentsList" class="resident-list" aria-live="polite">
          <div class="card p-4 text-secondary">Select Residents to load the directory.</div>
        </div>
      </section>

      <section id="pendingApprovalsView" class="admin-view" hidden>
        <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-3">
          <div>
            <h1 class="section-title mb-1">Pending Approvals</h1>
            <p class="text-secondary mb-0">Review registration requests before residents can sign in.</p>
          </div>
          <span id="pendingApprovalsPageCount" class="badge-soft soft-yellow">0 pending</span>
        </div>
        <div id="pendingApprovalList" class="resident-list" aria-live="polite">
          <div class="card p-4 text-secondary">Loading pending registrations…</div>
        </div>
      </section>

      <section id="referenceDataView" class="admin-view" hidden>
        <div class="mb-4">
          <h1 class="section-title mb-1">Reference Data</h1>
          <p class="text-secondary mb-0">Manage report categories, response teams, and approved report locations.</p>
        </div>
        <div id="referenceDataStatus" role="status" aria-live="polite"></div>
        <div class="card p-4 mb-4">
          <h2 class="h5">Categories</h2>
          <form id="createCategoryForm" class="row g-2 mb-3">
            <div class="col-md-3"><label class="visually-hidden" for="newCategoryName">Category name</label><input id="newCategoryName" class="form-control" maxlength="100" placeholder="Category name" required></div>
            <div class="col-md-3"><label class="visually-hidden" for="newCategoryDescription">Description</label><input id="newCategoryDescription" class="form-control" maxlength="1000" placeholder="Description"></div>
            <div class="col-md-2"><label class="visually-hidden" for="newCategoryWeight">Severity weight</label><input id="newCategoryWeight" class="form-control" type="number" min="1" max="5" value="1" required></div>
            <div class="col-md-2"><label class="visually-hidden" for="newCategoryTeam">Suggested team</label><select id="newCategoryTeam" class="form-select"><option value="">No suggested team</option></select></div>
            <div class="col-md-2"><button class="btn btn-primary w-100" type="submit">Add</button></div>
          </form>
          <div id="referenceCategories"></div>
        </div>
        <div class="card p-4 mb-4">
          <h2 class="h5">Teams</h2>
          <form id="createTeamForm" class="row g-2 mb-3">
            <div class="col-md-10"><label class="visually-hidden" for="newTeamName">Team name</label><input id="newTeamName" class="form-control" maxlength="100" placeholder="Team name" required></div>
            <div class="col-md-2"><button class="btn btn-primary w-100" type="submit">Add</button></div>
          </form>
          <div id="referenceTeams"></div>
        </div>
        <div class="card p-4">
          <h2 class="h5">Locations</h2>
          <form id="createLocationForm" class="row g-2 mb-3">
            <div class="col-md-5"><label class="visually-hidden" for="newLocationName">Street</label><input id="newLocationName" class="form-control" maxlength="150" placeholder="Street or Area-wide" required></div>
            <div class="col-md-5"><label class="visually-hidden" for="newLocationArea">Village or phase</label><input id="newLocationArea" class="form-control" maxlength="150" placeholder="Village or phase" required></div>
            <div class="col-md-2"><button class="btn btn-primary w-100" type="submit">Add</button></div>
          </form>
          <div id="referenceLocations"></div>
        </div>
      </section>

      <section id="evaluationView" class="admin-view" aria-labelledby="evaluationTitle" hidden>
        <div class="mb-4">
          <h1 id="evaluationTitle" class="section-title mb-1">Evaluation</h1>
          <p class="text-secondary mb-0">Measure how often category suggestions are right, using real reports. Label each report yourself without seeing what the resident or the system chose. Ideally, ask someone who did not set up the keyword rules to do the labelling.</p>
        </div>

        <div class="card p-4 mb-4">
          <div class="evaluation-head">
            <h2 class="card-heading mb-0">Label reports</h2>
            <span id="evaluationProgress" class="badge-soft soft-blue">0 labelled</span>
          </div>
          <div id="evaluationTask" aria-live="polite"></div>
        </div>

        <div class="card p-4">
          <div class="evaluation-head">
            <h2 class="card-heading mb-0">Results</h2>
            <a id="evaluationExport" class="btn btn-sm btn-light" href="/api/evaluation/export" hidden>Export CSV</a>
          </div>
          <div id="evaluationResults"></div>
          <p class="small text-secondary mt-3 mb-0">The exported CSV has no names, and contact details in descriptions are masked. AI accuracy is added when the system's technical administrator runs the AI check on the server.</p>
        </div>
      </section>

      @include('partials.account-view', ['viewClass' => 'admin-view'])
    </div>
  </main>

  <!-- Incident Action & Dispatch Modal -->
  <div class="modal fade" id="actionModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title fw-bold">Incident Action & Dispatch</h5>
          <button class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <form id="actionForm">
            <input type="hidden" id="editId">
            <div class="action-layout">
            <!-- Left: report details. Right: the controls, kept in view on wide screens. -->
            <div class="action-details">
            <div id="incidentSummary" class="alert alert-light border mb-3"></div>
            <section aria-labelledby="reportStatusHistoryTitle" class="mb-3">
              <h6 id="reportStatusHistoryTitle">Status history</h6>
              <div id="reportStatusHistory" aria-live="polite"></div>
            </section>
            <section aria-labelledby="triagePanelTitle" class="triage-panel mb-3">
              <h6 id="triagePanelTitle">Triage assistant <span id="triageSource" class="badge-soft soft-blue"></span></h6>
              <div id="triageActions" class="triage-actions"></div>
              <div id="triagePanel" aria-live="polite"></div>
              <p class="triage-note">Suggestions only. Review them before acting; nothing is changed automatically.</p>
            </section>
            </div>

            <div class="action-controls row g-3">
              <div class="col-6">
                <label class="form-label fw-medium" for="decision">Decision</label>
                <select id="decision" class="form-select">
                  <option value="Advance">Continue Workflow</option>
                  <option value="Reject">Reject / Close Report</option>
                </select>
              </div>

              <div class="col-6">
                <label class="form-label fw-medium" for="editPriority">Automatic Priority</label>
                <input id="editPriority" class="form-control" readonly aria-describedby="priorityHelp">
                <div id="priorityHelp" class="form-text">Set automatically from the report category.</div>
              </div>

              <div class="col-6">
                <label class="form-label fw-medium" for="editStatus">Workflow Stage</label>
                <select id="editStatus" class="form-select">
                  <option>Submitted</option>
                  <option>Validated</option>
                  <option>Dispatched</option>
                  <option>Resolved</option>
                  <option>Rejected</option>
                </select>
              </div>

              <div class="col-6">
                <label class="form-label fw-medium" for="assignee">Assign Field Personnel / Team</label>
                <select id="assignee" class="form-select">
                  <option value="Unassigned">Unassigned</option>
                  <option>Field Team A</option>
                  <option>Field Team B</option>
                  <option>Purok Leader</option>
                  <option>Barangay Safety Team</option>
                  <option>Maintenance & Engineering Crew</option>
                </select>
              </div>

              <div class="col-12">
                <label class="form-label fw-medium" for="dispatchNote">Message to resident</label>
                <textarea id="dispatchNote" class="form-control" rows="3" maxlength="255" required placeholder="Explain the current status and next steps"></textarea>
              </div>
            </div>
            </div>
          </form>
        </div>
        <div class="modal-footer">
          <button class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
          <button id="saveActionBtn" class="btn btn-primary" type="button">Save Changes & Notify</button>
        </div>
      </div>
    </div>
  </div>

  <script src="{{ \App\Support\Asset::url('/frontend/js/notifications.js') }}" nonce="{{ app('csp.nonce') }}"></script>
  <script src="{{ \App\Support\Asset::url('/frontend/js/session-handler.js') }}" nonce="{{ app('csp.nonce') }}"></script>
  <script src="{{ \App\Support\Asset::url('/frontend/js/password-toggle.js') }}" nonce="{{ app('csp.nonce') }}"></script>
  <script src="{{ \App\Support\Asset::url('/frontend/js/account-security.js') }}" nonce="{{ app('csp.nonce') }}"></script>
  <script src="{{ \App\Support\Asset::url('/frontend/js/admin-dashboard.js') }}" nonce="{{ app('csp.nonce') }}"></script>

</body>
</html>