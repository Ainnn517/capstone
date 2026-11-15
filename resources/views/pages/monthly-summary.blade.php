<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Monthly Report Summary - {{ $month->format('F Y') }}</title>
  <link rel="stylesheet" href="{{ \App\Support\Asset::url('/frontend/css/summary.css') }}">
</head>
<body>
  <div class="summary-actions">
    <form method="get" action="{{ route('admin.summary', [], false) }}" class="summary-month">
      <label for="month">Month</label>
      <input id="month" name="month" type="month" value="{{ $month->format('Y-m') }}" max="{{ now()->format('Y-m') }}">
      <button type="submit">Show</button>
    </form>
    <button id="printSummary" type="button" class="summary-print">Print or save as PDF</button>
  </div>

  <main class="summary-page">
    <header class="summary-header">
      <img src="/images/school_logo.png" alt="Barangay seal">
      <div>
        <p class="summary-eyebrow">Barangay Reporting System</p>
        <h1>Monthly Report Summary: {{ $month->format('F Y') }}</h1>
        <p class="summary-meta">Generated {{ $generatedAt->format('F j, Y g:i A') }}. Counts reports submitted or resolved this month, including archived reports.</p>
      </div>
    </header>

    <section class="summary-totals" aria-label="Totals for the month">
      <div><strong>{{ $totals['submitted'] }}</strong><span>Reports submitted</span></div>
      <div><strong>{{ $totals['resolved'] }}</strong><span>Reports resolved</span></div>
      <div><strong>{{ $totals['stillOpen'] }}</strong><span>Submitted and still open</span></div>
      <div><strong>{{ \App\Services\MonthlySummaryService::duration($totals['averageDays']) }}</strong><span>Average time to resolve</span></div>
    </section>
    <p class="summary-note">Also this month: {{ $totals['rejected'] }} rejected and {{ $totals['withdrawn'] }} withdrawn by residents.</p>

    <h2>By category</h2>
    @if (count($categories))
      <table>
        <thead><tr><th>Category</th><th>Priority</th><th class="num">Submitted</th><th class="num">Resolved</th><th class="num">Average time</th><th class="num">Target</th></tr></thead>
        <tbody>
          @foreach ($categories as $row)
            <tr>
              <td>{{ $row['category'] }}</td>
              <td>{{ $row['priority'] }}</td>
              <td class="num">{{ $row['submitted'] }}</td>
              <td class="num">{{ $row['resolved'] }}</td>
              <td class="num {{ $row['averageDays'] !== null && $row['averageDays'] > $row['targetDays'] ? 'late' : '' }}">{{ \App\Services\MonthlySummaryService::duration($row['averageDays']) }}</td>
              <td class="num">{{ $row['targetDays'] }} days</td>
            </tr>
          @endforeach
        </tbody>
      </table>
    @else
      <p class="summary-empty">No reports this month.</p>
    @endif

    <div class="summary-columns">
      <section>
        <h2>By area</h2>
        @if (count($areas))
          <table>
            <thead><tr><th>Area</th><th class="num">Submitted</th><th class="num">Still open</th></tr></thead>
            <tbody>
              @foreach ($areas as $row)
                <tr><td>{{ $row['area'] }}</td><td class="num">{{ $row['submitted'] }}</td><td class="num">{{ $row['open'] }}</td></tr>
              @endforeach
            </tbody>
          </table>
        @else
          <p class="summary-empty">No reports this month.</p>
        @endif
      </section>

      <section>
        <h2>By team (resolved)</h2>
        @if (count($teams))
          <table>
            <thead><tr><th>Team</th><th class="num">Resolved</th><th class="num">Average time</th></tr></thead>
            <tbody>
              @foreach ($teams as $row)
                <tr><td>{{ $row['team'] }}</td><td class="num">{{ $row['resolved'] }}</td><td class="num">{{ \App\Services\MonthlySummaryService::duration($row['averageDays']) }}</td></tr>
              @endforeach
            </tbody>
          </table>
        @else
          <p class="summary-empty">No reports resolved this month.</p>
        @endif
      </section>
    </div>

    <h2>Resident confirmation</h2>
    <p class="summary-note">Of the {{ $totals['resolved'] }} reports resolved this month: {{ $confirmation['confirmed'] }} confirmed fixed by the resident, {{ $confirmation['awaiting'] }} still waiting for an answer, {{ $confirmation['noReply'] }} closed with no reply. {{ $confirmation['reopened'] }} of this month's reports were reopened by residents at least once.</p>

    <footer class="summary-footer">
      <span>Prepared by: ______________________</span>
      <span>Noted by: ______________________</span>
    </footer>
  </main>

  <script nonce="{{ app('csp.nonce') }}">
    document.getElementById('printSummary').addEventListener('click', () => window.print());
  </script>
</body>
</html>
