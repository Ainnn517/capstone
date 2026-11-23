"""Monthly summary: choosing a month shows it straight away, and its CSV holds that month's reports."""

import csv
import re
import subprocess

from playwright.sync_api import expect

from conftest import ENV, PROJECT, log_in


def test_changing_the_month_shows_it_and_the_csv_matches(app, new_page, mail):
    # Demo reports spread over the last six months.
    subprocess.run(["php", "artisan", "demo:residents", "--force"], cwd=PROJECT, env=ENV, check=True, capture_output=True)
    page = new_page()
    log_in(page, app, "e2eadmin", mail=mail)
    page.goto(app + "/admin/summary")

    # The month two months back, picked without pressing Show.
    target = page.evaluate("""() => { const d = new Date(); d.setDate(1); d.setMonth(d.getMonth() - 2);
      return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`; }""")
    page.locator("#month").fill(target)
    page.wait_for_url(re.compile(rf"month={target}$"))
    heading = page.locator("h1")
    expect(heading).not_to_contain_text(page.evaluate("new Date().toLocaleString('en-US', {month: 'long'})"))
    submitted = int(page.locator(".summary-totals div strong").first.inner_text())

    with page.expect_download() as info:
        page.click(".summary-csv")
    assert info.value.suggested_filename.startswith(f"barangay-reports-{target}-01-to-{target}-")
    with open(info.value.path(), encoding="utf-8-sig", newline="") as handle:
        rows = list(csv.DictReader(handle))
    assert rows, "the CSV for that month has no reports"
    assert all(row["Submitted"].startswith(target) for row in rows), [row["Submitted"] for row in rows]
    assert len(rows) == submitted, (len(rows), submitted)
