"""Admin search, CSV export, printable summary, and report-update emails."""

from playwright.sync_api import expect

from conftest import log_in, open_resident_view
from test_resident_confirmation import submit_report


def test_search_export_and_summary(app, new_page, mail):
    resident = new_page()
    log_in(resident, app, "e2eresident")
    submit_report(resident)

    admin = new_page()
    log_in(admin, app, "e2eadmin", mail=mail)
    admin.click("[data-admin-view=incidents]")
    admin.fill("#incidentSearch", "streetlight")
    expect(admin.locator("#incidentBody tr", has_text="Streetlights")).to_have_count(1)
    admin.fill("#incidentSearch", "no-such-report")
    expect(admin.locator("#incidentBody")).to_contain_text("No open incidents match your search.")

    admin.click("[data-admin-view=analytics]")
    with admin.expect_download() as download_info:
        admin.click("#downloadCsvBtn")
    download = download_info.value
    assert download.suggested_filename.startswith("barangay-reports-")
    content = open(download.path(), encoding="utf-8-sig").read()
    assert "Streetlights" in content and "Tracking code" in content

    with admin.expect_popup() as popup_info:
        admin.click("#openSummaryBtn")
    summary = popup_info.value
    expect(summary.locator("h1")).to_contain_text("Monthly Report Summary")
    expect(summary.locator(".summary-totals")).to_contain_text("1")
    expect(summary.locator("table").first).to_contain_text("Streetlights")


def test_resident_gets_an_email_and_can_turn_them_off(app, new_page, mail):
    resident = new_page()
    log_in(resident, app, "e2eresident")
    submit_report(resident)
    open_resident_view(resident, "account")
    expect(resident.locator("#emailAlertsToggle")).to_be_checked()

    admin = new_page()
    log_in(admin, app, "e2eadmin", mail=mail)
    mail.mark()
    status = admin.evaluate("""async () => {
        const reports = await (await fetch('/api/reports?view=active', { headers: { Accept: 'application/json' } })).json();
        const response = await fetch('/api/reports/update', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json',
                       'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
            body: JSON.stringify({ id: reports.reports[0].id, status: 'Validated', assignee: 'Unassigned', dispatchNote: 'We checked it.' })
        });
        return response.status;
    }""")
    assert status == 200
    mail.wait_for(r"Subject: Report BR-[0-9A-F-]+: Validated")
    mail.wait_for(r"The barangay reviewed your report and accepted it\.")

    resident.locator("#emailAlertsToggle").uncheck()
    expect(resident.locator("#emailAlertsMessage")).to_contain_text("Emails turned off")
    resident.reload()
    open_resident_view(resident, "account")
    expect(resident.locator("#emailAlertsToggle")).not_to_be_checked()
