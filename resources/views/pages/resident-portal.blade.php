<!-- Resident report submission and tracking page. -->
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="csrf-token" content="{{ csrf_token() }}">
  <meta name="google-maps-api-key" content="{{ config('services.google_maps.key') }}">
  <title>Barangay Issue Reporting System - Resident Portal</title>
  <link rel="stylesheet" href="/frontend/css/resident-portal.css">
  <link rel="stylesheet" href="https://cdn-uicons.flaticon.com/uicons-regular-rounded/css/uicons-regular-rounded.css">
</head>
<body>

  <!-- Navigation Bar -->
  <nav class="navbar navbar-dark sticky-top">
    <div class="container">
      <div class="d-flex align-items-center gap-3 navbar-actions">
        <button class="btn btn-light position-relative notification-button" id="notifBtn" type="button" title="View notifications" aria-label="View notifications">
          <i class="fi fi-rr-bell" aria-hidden="true"></i>
          <span id="notifCount" class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger">0</span>
        </button>
        <span id="residentName" class="small text-white-50 fw-semibold">Welcome,</span>
        <button class="btn btn-light btn-sm" id="logoutBtn" type="button">Logout</button>
      </div>
    </div>
  </nav>

  <!-- Main Content -->
  <main class="container page-wrap">

    <div class="card p-4 mb-4">
      <h5 class="fw-bold mb-3">Reporting Workflow</h5>
      <ol class="workflow-steps text-secondary mb-0">
        <li><strong>Submit:</strong> Fill out the incident details.</li>
        <li><strong>Validation:</strong> Officials review and confirm report accuracy.</li>
        <li><strong>Dispatch:</strong> Field team/purok leader is deployed.</li>
        <li><strong>Resolution:</strong> Issue resolved and closed with timestamp.</li>
      </ol>
    </div>

    <div class="row g-4">

      <!-- Issue Reporting Form Column -->
      <div class="col-lg-7">
        <div class="card p-4">
          <div class="d-flex justify-content-between align-items-start mb-3">
            <div>
              <h2 class="section-title mb-1">Report a Barangay Issue</h2>
              <p class="text-secondary mb-0">Submit an incident and receive an automated tracking ID.</p>
            </div>
            <span class="badge-soft soft-green">Citizen Portal</span>
          </div>

          <form id="issueForm" novalidate>
            <div class="row g-3">

              <div class="col-md-4">
                <label class="form-label fw-medium">Issue Category</label>
                <select id="category" class="form-select" required>
                  <option value="">Choose category</option>
                  <option>Sanitation</option>
                  <option>Clogged Drainage</option>
                  <option>Streetlights</option>
                  <option>Road Damage</option>
                  <option>Public Safety</option>
                  <option>Others</option>
                </select>
              </div>

              <div class="col-md-4">
                <label class="form-label fw-medium">Village / Phase</label>
                <select id="phase" class="form-select" required>
                  <option value="">Choose village / phase</option>
                </select>
              </div>

              <div class="col-md-4">
                <label class="form-label fw-medium">Street</label>
                <select id="purok" class="form-select" required disabled>
                  <option value="">Choose village / phase first</option>
                </select>
              </div>

              <div class="col-12">
                <label class="form-label fw-medium">Detailed Description</label>
                <textarea id="description" class="form-control" rows="4" minlength="1" maxlength="50" required placeholder="Describe the incident in 1 to 50 characters."></textarea>
              </div>

              <div class="col-md-7">
                <label class="form-label fw-medium">Specific Landmark / Marker</label>
                <div class="input-group">
                  <input id="location" class="form-control" placeholder="e.g. Near Barangay Hall">
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
                <label class="form-label fw-medium">Image Attachment</label>
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
      </div>

      <!-- Notifications & Workflow Information -->
      <div class="col-lg-5">

        <div class="card p-4 mb-4">
          <h5 class="fw-bold mb-3">Live Notification Updates</h5>
          <div id="notifications">
            <div class="text-secondary small">No new updates available yet.</div>
          </div>
        </div>

      </div>

    </div>

    <!-- Submitted Reports Tracker -->
    <section class="card p-4 mt-4 reports-panel" id="reportsPanel" aria-labelledby="reportsTitle">
      <div class="reports-header">
        <div>
          <h3 id="reportsTitle" class="section-title mb-1">My Reports</h3>
          <p class="text-secondary mb-0">Follow open issues or review reports that have been closed.</p>
        </div>
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
    </section>

  </main>

  <script src="/frontend/js/resident-portal.js"></script>

</body>
</html>