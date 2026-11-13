<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Privacy Notice - Barangay Reporting System</title>
  <link rel="stylesheet" href="{{ \App\Support\Asset::url('/frontend/css/privacy.css') }}">
</head>
<body>
  <nav class="navbar">
    <a class="navbar-brand" href="{{ route('home', [], false) }}">
      <img src="/images/school_logo.png" alt="Barangay seal">
      <span>Barangay Reporting System</span>
    </a>
    <a class="back-link" href="{{ route('home', [], false) }}">Back to the reporting system</a>
  </nav>
  <main class="privacy-card">
    <header>
      <p class="eyebrow">YOUR INFORMATION</p>
      <h1>Privacy Notice</h1>
      <p>This notice explains how the Barangay Reporting System uses information submitted through the service. The barangay should review and approve this notice before collecting real resident information.</p>
    </header>

    <h2>Information collected</h2>
    <ul>
      <li>Account details such as your name, username, and email address.</li>
      <li>Issue category, description, selected area or street, and report status.</li>
      <li>Optional landmark details, GPS coordinates, and image attachments.</li>
      <li>Submission and status-update timestamps needed to track reports.</li>
    </ul>

    <h2>How information is used and who can see it</h2>
    <p>Account details are also used by authorized barangay administrators to review resident registrations. New resident accounts remain pending and cannot sign in until approved; rejected applicants cannot access the system. Information is used to receive, review, assign, and resolve barangay issue reports and to show you updates about your own reports. Authorized barangay administrators can review reports and their attachments. Residents can view their own active reports. Archived reports are hidden from active lists and remain available to administrators.</p>

    <h2>Automated suggestions (AI-assisted triage)</h2>
    <p>To help route reports faster, the system can suggest an issue category while you write a report and can point out to administrators when several open reports at the same location may describe the same problem. These are suggestions only: you choose your report's category, and barangay administrators make every decision about status, priority, and assignment.</p>
    <p>When this feature is enabled, the text of report descriptions is sent to an artificial intelligence service provided by Anthropic to produce these suggestions. Your name, username, email address, GPS coordinates, and photos are not sent, and email addresses and phone numbers written inside a description are masked before sending. When the feature is turned off or unavailable, suggestions are produced on the barangay's own server using keyword rules, and no report text leaves the system.</p>

    <h2>Retention and deletion</h2>
    <p>Reports and attachments are kept while they are active. Archived reports, with their attachments and history, are deleted automatically one year after archiving, or earlier when an administrator deletes them. Registration requests that were not approved are deleted 30 days after rejection. Administrator activity records are kept for two years. Separate backups may retain deleted information until those backups expire under the barangay's backup schedule.</p>

    <h2>Account and privacy questions</h2>
    <p>Contact the barangay office for questions about your information or to request assistance. The barangay should provide its approved contact details here before launch.</p>

    <section class="privacy-ack" aria-label="Acknowledge this notice">
      <label class="privacy-ack-check">
        <input type="checkbox" id="privacyAck" class="check-input">
        <span class="check-box" aria-hidden="true"></span>
        <span>I have read and understood this Privacy Notice.</span>
      </label>
      <button id="privacyContinue" class="privacy-continue" type="button" disabled>Continue to create an account</button>
    </section>
  </main>
  <script src="{{ \App\Support\Asset::url('/frontend/js/privacy.js') }}" nonce="{{ app('csp.nonce') }}"></script>
</body>
</html>
