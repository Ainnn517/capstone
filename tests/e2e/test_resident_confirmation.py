"""After the barangay resolves a report, the resident confirms the fix or reopens it."""

from playwright.sync_api import expect

from conftest import _artisan, confirm_dialog, log_in, open_resident_view

RESOLVE = (
    "--execute=$t = App\\Models\\Team::first(); App\\Models\\IssueReport::query()->update(["
    "'status' => 'Resolved', 'team_id' => $t->team_id, 'date_resolved' => now()]);"
)


def submit_report(page) -> None:
    page.select_option("#category", label="Streetlights")
    page.select_option("#phase", index=1)
    if page.locator("#purok").is_enabled():
        page.select_option("#purok", index=1)
    page.fill("#location", "Near the chapel")
    page.fill("#description", "The streetlight at the corner has been off for weeks.")
    page.click("#issueForm button[type=submit]")
    expect(page.locator("#submitAlert")).to_contain_text("Report Submitted")


def test_resident_reopens_then_confirms_the_fix(app, new_page, mail):
    resident = new_page()
    log_in(resident, app, "e2eresident")
    submit_report(resident)
    _artisan("tinker", RESOLVE)
    resident.reload()
    open_resident_view(resident, "reports")

    card = resident.locator(".issue-card").first
    expect(card.locator(".resolution-check")).to_contain_text("Is the problem fixed?")

    # Not fixed: reopen with a ready-made reason.
    card.locator("[data-report-reopen]").click()
    resident.locator(".app-prompt-suggestions button", has_text="It was only partly fixed.").click()
    confirm_dialog(resident)
    expect(resident.locator("#toastContainer")).to_contain_text("reopened")
    expect(card.locator(".resolution-reopened")).to_be_visible()

    admin = new_page()
    log_in(admin, app, "e2eadmin", mail=mail)
    admin.click("[data-admin-view=incidents]")
    row = admin.locator("#incidentBody tr", has_text="Streetlights")
    expect(row).to_contain_text("Validated")
    expect(row).to_contain_text("Reopened")

    # Resolved again: this time the resident confirms.
    _artisan("tinker", RESOLVE)
    resident.reload()
    open_resident_view(resident, "reports")
    card = resident.locator(".issue-card").first
    card.locator("[data-report-confirm]").click()
    confirm_dialog(resident)
    expect(resident.locator("#toastContainer")).to_contain_text("Thank you for confirming")
    # Closed reports are under Past Reports, with a way to reopen if the problem comes back.
    resident.click("[data-report-filter=past]")
    past_card = resident.locator(".issue-card", has_text="You confirmed this is fixed")
    expect(past_card.locator("[data-report-reopen]")).to_have_text("Problem came back?")

    admin.click("[data-admin-view=resolved]")
    expect(admin.locator("#resolvedBody tr", has_text="Streetlights")).to_contain_text("Resident confirmed")
    admin.click("[data-admin-view=analytics]")
    expect(admin.locator("#confirmedCount")).to_have_text("1")
    expect(admin.locator("#reopenedCount")).to_have_text("1")
