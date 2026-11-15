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
    <span class="navbar-brand">
      <img src="/images/school_logo.png" alt="Barangay seal">
      <span>Barangay Reporting System</span>
    </span>
    <a class="back-link" href="{{ route('home', [], false) }}">Back to the reporting system</a>
  </nav>
  <main class="privacy-card">
    <header>
      <p class="eyebrow">YOUR INFORMATION</p>
      <h1>Privacy Notice</h1>
      <p>This notice explains what information the Barangay Reporting System collects, why, who can see it, and how long it is kept, in line with the Data Privacy Act of 2012 (Republic Act No. 10173).</p>
      <p class="privacy-updated">Last updated: November 15, 2026</p>
    </header>

    <h2>Information collected</h2>
    <ul>
      <li>Account details: your full name, username, email address, and password (stored only in scrambled, hashed form that no one can read).</li>
      <li>Your home address (village or phase, street, and house number or landmark) and, if you choose to give it, your mobile number. Barangay staff use these only to confirm that you live in the barangay and to contact you about your account or reports.</li>
      <li>Report details: issue category, description, selected area or street, optional landmark, optional GPS location, and optional photos.</li>
      <li>Report history: when a report was submitted, edited, withdrawn, reviewed, assigned to a team, and resolved.</li>
      <li>Your consent to this notice and the date you gave it.</li>
      <li>Short-lived security records, such as a count of failed sign-in attempts, used to protect accounts from misuse.</li>
    </ul>

    <h2>How information is used</h2>
    <ul>
      <li>To create and secure your account, including email verification and emailed codes for sign-in and password changes.</li>
      <li>To receive, review, prioritise, assign, and resolve barangay issue reports, and to show you updates about your own reports.</li>
      <li>To produce summaries for barangay staff, such as report counts by area and category, overdue reports, and average resolution times. These summaries do not show residents' names.</li>
    </ul>
    <p>Your information is not sold, and it is not used for advertising.</p>

    <h2>Who can see your information</h2>
    <p>Authorized barangay administrators can see resident account details (including your address and mobile number), reports, and attached photos in order to handle them. Residents can see only their own reports. New resident accounts stay pending and cannot sign in until an administrator approves them.</p>

    <h2>Service providers</h2>
    <p>The system relies on a few outside services to work:</p>
    <ul>
      <li><strong>Cloudflare</strong> carries traffic between your browser and the system and protects it with HTTPS encryption.</li>
      <li><strong>Resend</strong> delivers the system's emails (verification links, sign-in and password codes, account updates, and updates about your reports, which you can turn off in Account or from any of those emails). It receives your name, email address, and the content of those emails.</li>
      <li><strong>OpenStreetMap</strong> provides the map pictures shown when you pin a report's location and on the administrators' hotspot map. Your browser downloads these map pictures directly from OpenStreetMap, which sees your internet address and the map area being viewed, but not your account or report details.</li>
    </ul>

    <h2>Automated suggestions (AI-assisted triage)</h2>
    <p>To help route reports faster, the system can suggest an issue category while you write a report, point out to administrators when several open reports at the same location may describe the same problem, and suggest which team to send based on how quickly past reports were resolved. These are suggestions only: you choose your report's category, and barangay administrators make every decision about status, priority, and assignment.</p>
    <p>When the AI feature is enabled, the text of report descriptions is sent to an artificial intelligence service provided by Anthropic to produce these suggestions. Your name, username, email address, GPS location, and photos are not sent, and email addresses and phone numbers written inside a description are masked before sending. When the feature is turned off, suggestions are produced on the barangay's own system using keyword rules, and no report text leaves the system.</p>

    <h2>How your information is protected</h2>
    <p>All connections use HTTPS encryption. Passwords are stored only in hashed form. Administrator sign-in requires an emailed one-time code, repeated sign-in attempts are limited, and changing your password signs out your other devices.</p>

    <h2>Retention and deletion</h2>
    <p>Reports and attachments are kept while they are active. Archived reports, with their attachments and history, are deleted automatically one year after archiving, or earlier when an administrator deletes them. Registration requests that were not approved are deleted 30 days after rejection, and sign-ups whose email address is never verified are deleted after 7 days. Administrator activity records are kept for two years. Daily backups are kept for 14 days, so deleted information can remain in a backup until that backup expires.</p>

    <h2>Your rights</h2>
    <p>Under the Data Privacy Act, you have the right to be informed about how your information is used, to access it, to have mistakes corrected, to object to its processing, and to ask for it to be deleted when it is no longer needed. You may also file a complaint with the National Privacy Commission.</p>

    <h2>Contact</h2>
    <p>For questions about your information or to exercise your rights, email <a href="mailto:appbarangay@gmail.com">appbarangay@gmail.com</a>.</p>
  </main>
</body>
</html>
