<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Privacy Notice - Barangay Reporting System</title>
  <link rel="stylesheet" href="/frontend/css/privacy.css">
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

    <h2>Retention and deletion</h2>
    <p>Reports and attachments are retained until an administrator permanently deletes an archived report. Archiving does not delete the report. Permanent deletion also removes associated attachments and report history from the live database. Separate backups may retain deleted information until those backups expire under the barangay's backup schedule.</p>

    <h2>Account and privacy questions</h2>
    <p>Contact the barangay office for questions about your information or to request assistance. The barangay should provide its approved contact details here before launch.</p>
  </main>
</body>
</html>
