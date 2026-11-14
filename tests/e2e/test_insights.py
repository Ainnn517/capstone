"""Overdue reports are flagged, and Analytics shows resolution times."""

from playwright.sync_api import expect

from conftest import _artisan, log_in


def test_overdue_report_is_flagged_for_the_admin(app, new_page, mail):
    resident = new_page()
    log_in(resident, app, "e2eresident")
    resident.select_option("#category", label="Streetlights")
    resident.select_option("#phase", index=1)
    if resident.locator("#purok").is_enabled():
        resident.select_option("#purok", index=1)
    resident.fill("#description", "The streetlight at the corner has been off for weeks.")
    resident.fill("#location", "Near the chapel")
    resident.click("#issueForm button[type=submit]")
    expect(resident.locator("#submitAlert")).to_contain_text("Report Submitted")

    # Pretend it was filed 30 days ago, well past every priority's target.
    _artisan("tinker", "--execute=DB::table('ISSUE_REPORT')->update(['date_submitted' => now()->subDays(30)]);")

    admin = new_page()
    log_in(admin, app, "e2eadmin", mail=mail)
    admin.click("[data-admin-view=incidents]")
    row = admin.locator("#incidentBody tr", has_text="Streetlights")
    expect(row.locator(".overdue-badge")).to_have_text("Overdue")

    admin.click("[data-admin-view=analytics]")
    expect(admin.locator("#resolutionOverdue")).to_have_text("1 overdue")
    streetlights = admin.locator("#resolutionBody tr", has_text="Streetlights")
    expect(streetlights).to_contain_text("No resolved reports yet")
