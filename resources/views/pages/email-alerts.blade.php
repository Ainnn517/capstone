<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Email updates - Barangay Reporting System</title>
  <link rel="stylesheet" href="{{ \App\Support\Asset::url('/frontend/css/privacy.css') }}">
</head>
<body>
  <nav class="navbar">
    <span class="navbar-brand">
      <img src="/images/school_logo.png" alt="Barangay seal">
      <span>Barangay Reporting System</span>
    </span>
    <a class="back-link" href="{{ route('home', [], false) }}">Go to the reporting system</a>
  </nav>
  <main class="privacy-card">
    <header>
      <p class="eyebrow">EMAIL UPDATES</p>
      @if ($done)
        <h1>Emails turned off</h1>
        <p>You will no longer get emails when the barangay updates your reports. You can still see every update in the Notifications section after signing in, and you can turn emails back on in Account.</p>
      @elseif ($alreadyOff)
        <h1>Emails are already off</h1>
        <p>You are not getting emails about your reports. You can turn them back on in Account after signing in.</p>
      @else
        <h1>Stop emails about your reports?</h1>
        <p>You will stop getting an email each time the barangay updates one of your reports. Updates will still appear in Notifications when you sign in.</p>
        <form method="post" action="{{ request()->fullUrl() }}">
          @csrf
          <button class="back-link" type="submit">Turn off emails</button>
        </form>
      @endif
    </header>
  </main>
</body>
</html>
