<!-- Resident report submission and tracking page. -->
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="csrf-token" content="{{ csrf_token() }}">
  <meta name="google-maps-api-key" content="{{ config('services.google_maps.key') }}">
  <title>Barangay Issue Reporting System - Resident Portal</title>
  <link rel="stylesheet" href="{{ \App\Support\Asset::url('/frontend/css/resident-portal.css') }}">
  <link rel="stylesheet" href="{{ \App\Support\Asset::url('/frontend/css/notifications.css') }}">
  <link rel="stylesheet" href="{{ \App\Support\Asset::url('/frontend/css/account.css') }}">
  <link rel="stylesheet" href="{{ \App\Support\Asset::url('/frontend/vendor/uicons-regular-rounded/css/uicons-regular-rounded.css') }}">
</head>
<body>

  <!-- Navigation Bar -->
  <nav class="navbar navbar-dark sticky-top">
    <div class="container">
      <div class="d-flex align-items-center gap-3 navbar-actions">
        <span id="residentName" class="small text-white-50 fw-semibold">Welcome,</span>
        <button class="btn btn-light btn-sm" id="logoutBtn" type="button">Logout</button>
      </div>
    </div>
  </nav>

  <!-- Main Content -->
  <main class="container page-wrap resident-layout">
    <aside class="resident-sidebar card" aria-label="Resident navigation">
      <p class="sidebar-label">Menu</p>
      <button class="sidebar-link active" type="button" data-resident-view="report" aria-current="page">
        <i class="fi fi-rr-megaphone sidebar-icon" aria-hidden="true"></i>
        Report an Issue
      </button>
      <button class="sidebar-link" type="button" data-resident-view="reports">
        <i class="fi fi-rr-clipboard-list sidebar-icon" aria-hidden="true"></i>
        My Reports
      </button>
      <button class="sidebar-link" type="button" data-resident-view="notifications">
        <i class="fi fi-rr-bell sidebar-icon" aria-hidden="true"></i>
        Notifications
        <span class="sidebar-count" id="notifNavCount" aria-label="Unread notifications">0</span>
      </button>
      <button class="sidebar-link" type="button" data-resident-view="account">
        <i class="fi fi-rr-user sidebar-icon" aria-hidden="true"></i>
        Account
      </button>
    </aside>

    <div class="resident-content">
      <!-- Report an issue -->
      <section id="reportView" class="resident-view" aria-labelledby="reportTitle">
        <div class="mb-4">
          <h1 id="reportTitle" class="section-title mb-1">Report an Issue</h1>
          <p class="text-secondary mb-0">Submit an incident and receive an automated tracking ID.</p>
        </div>

        <div class="card p-4 mb-4">
          <h2 class="card-heading">How your report is handled</h2>
          <ol class="workflow-steps text-secondary mb-0">
            <li><strong>Submit:</strong> Fill out the incident details.</li>
            <li><strong>Validation:</strong> Officials review and confirm report accuracy.</li>
            <li><strong>Dispatch:</strong> Field team/purok leader is deployed.</li>
            <li><strong>Resolution:</strong> Issue resolved and closed with timestamp.</li>
          </ol>
        </div>

        <div class="card p-4">
          <form id="issueForm" novalidate>
            <div class="row g-3">

              <div class="col-md-4">
                <label class="form-label fw-medium" for="category">Issue Category</label>
                <select id="category" class="form-select" required>
                  <option value="">Choose category</option>
                </select>
              </div>

              <div class="col-md-4">
                <label class="form-label fw-medium" for="phase">Village / Phase</label>
                <select id="phase" class="form-select" required>
                  <option value="">Choose village / phase</option>
                </select>
              </div>

              <div class="col-md-4">
                <label class="form-label fw-medium" for="purok">Street</label>
                <select id="purok" class="form-select" required disabled>
                  <option value="">Choose village / phase first</option>
                </select>
              </div>

              <div class="col-12">
                <label class="form-label fw-medium" for="description">Detailed Description</label>
                <textarea id="description" class="form-control" rows="4" minlength="1" maxlength="500" required placeholder="Describe the incident in 1 to 500 characters."></textarea>
                <small class="form-text text-secondary">Include enough detail for a response team to understand the issue (up to 500 characters).</small>
                <div id="categorySuggestion" class="category-suggestion" aria-live="polite" hidden></div>
              </div>

              <div class="col-md-7">
                <label class="form-label fw-medium" for="location">Specific Landmark / Marker</label>
                <div class="input-group">
                  <input id="location" class="form-control" maxlength="150" placeholder="e.g. Near Barangay Hall">
                  <button type="button" id="geoBtn" class="btn btn-outline-primary">
                    <i class="fi fi-rr-map-marker" aria-hidden="true"></i>
                    Tag Location
                  </button>
                </div>
                <small id="geoMsg" class="text-secondary d-block mt-1"></small>
              </div>

              <dialog id="locationMapDialog" class="location-map-dialog" aria-labelledby="locationMapTitle" aria-describedby="mapStatus">
                <div class="location-map-header">
                  <div>
                    <h3 id="locationMapTitle">Choose the incident location</h3>
                    <p id="mapStatus" class="text-secondary mb-0">Click the map to place a marker, or drag it to adjust the location.</p>
                  </div>
                  <button type="button" class="btn btn-light btn-sm" id="closeMapBtn" aria-label="Close map">Close</button>
                </div>
                <div id="locationMap" class="location-map" role="application" aria-label="Google Map location picker"></div>
                <div class="location-map-footer">
                  <button type="button" class="btn btn-outline-primary" id="currentLocationBtn">Use my current location</button>
                  <button type="button" class="btn btn-primary" id="confirmLocationBtn">Use this location</button>
                </div>
              </dialog>

              <div class="col-md-5">
                <label class="form-label fw-medium" for="image">Image Attachment</label>
                <input id="image" type="file" class="form-control" accept="image/*">
                <img id="preview" class="img-fluid rounded mt-2 d-none" style="max-height: 140px; width: 100%; object-fit: cover;">
              </div>

              <div class="col-12 mt-4">
                <div id="submitAlert"></div>
                <button type="submit" class="btn btn-primary btn-lg w-100">Submit Issue & Generate Tracking ID</button>
              </div>

            </div>
          </form>
        </div>
      </section>

      <!-- Submitted reports tracker -->
      <section id="reportsView" class="resident-view" aria-labelledby="reportsTitle" hidden>
        <div class="mb-4">
          <h1 id="reportsTitle" class="section-title mb-1">My Reports</h1>
          <p class="text-secondary mb-0">Follow open issues or review reports that have been closed.</p>
        </div>
        <div class="card p-4 reports-panel" id="reportsPanel">
          <div class="reports-header">
        <div class="report-controls">
          <div class="report-filters" role="group" aria-label="Filter my reports">
            <button class="report-filter active" type="button" data-report-filter="active" aria-pressed="true">Current</button>
            <button class="report-filter" type="button" data-report-filter="past" aria-pressed="false">Past Reports</button>
            <button class="report-filter" type="button" data-report-filter="all" aria-pressed="false">All</button>
          </div>
          <span id="issueCount" class="badge-soft soft-blue">0 reports</span>
        </div>
      </div>

      <div id="issuesList" class="issues-list mt-3" aria-live="polite">
        <!-- Dynamically rendered reports -->
      </div>
      <div id="reportPagination" class="d-flex justify-content-between align-items-center gap-3 mt-3" aria-label="Report pagination"></div>
        </div>
      </section>

      <!-- Notifications -->
      <section id="notificationsView" class="resident-view" aria-labelledby="notificationsTitle" hidden>
        <div class="mb-4">
          <h1 id="notificationsTitle" class="section-title mb-1">Notifications</h1>
          <p class="text-secondary mb-0">Updates from the barangay about your reports.</p>
        </div>
        <div class="card p-4">
          <div class="reports-header notifications-header mb-3">
            <div class="report-filters" role="group" aria-label="Filter notifications">
              <button class="report-filter" type="button" data-notif-filter="current" aria-pressed="false">Current</button>
              <button class="report-filter" type="button" data-notif-filter="past" aria-pressed="false">Past</button>
              <button class="report-filter active" type="button" data-notif-filter="all" aria-pressed="true">All</button>
            </div>
          </div>
          <div id="notifications">
            <div class="text-secondary small">No new updates available yet.</div>
          </div>
        </div>
      </section>

      @include('partials.account-view', ['viewClass' => 'resident-view'])
    </div>
  </main>
  <script src="{{ \App\Support\Asset::url('/frontend/js/notifications.js') }}" nonce="{{ app('csp.nonce') }}"></script>
  <script src="{{ \App\Support\Asset::url('/frontend/js/session-handler.js') }}" nonce="{{ app('csp.nonce') }}"></script>
  <script src="{{ \App\Support\Asset::url('/frontend/js/password-toggle.js') }}" nonce="{{ app('csp.nonce') }}"></script>
  <script src="{{ \App\Support\Asset::url('/frontend/js/account-security.js') }}" nonce="{{ app('csp.nonce') }}"></script>
  <script src="{{ \App\Support\Asset::url('/frontend/js/resident-portal.js') }}" nonce="{{ app('csp.nonce') }}"></script>

</body>
</html>