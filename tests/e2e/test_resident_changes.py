"""Residents edit a report before review and withdraw it before dispatch."""

from playwright.sync_api import expect

from conftest import log_in, open_resident_view


def file_report(page, description: str) -> None:
    page.select_option("#category", label="Road Damage")
    page.select_option("#phase", index=1)
    if page.locator("#purok").is_enabled():
        page.select_option("#purok", index=1)
    page.fill("#description", description)
    page.fill("#location", "Near the chapel")
    page.click("#issueForm button[type=submit]")
    expect(page.locator("#submitAlert")).to_contain_text("Report Submitted")


def test_resident_edits_then_withdraws_a_report(app, new_page):
    page = new_page()
    log_in(page, app, "e2eresident")
    file_report(page, "Big pothole near the corner.")
    open_resident_view(page, 'reports')
    card = page.locator(".issue-card").first

    # Edit while it is still Submitted.
    card.locator("[data-report-edit]").click()
    expect(page.locator("#editReportDialog")).to_be_visible()
    page.select_option("#editReportCategory", label="Clogged Drainage")
    page.fill("#editReportDescription", "Correction: the canal is blocked and flooding the road.")
    page.click("#editReportForm button[type=submit]")
    expect(page.locator("#editReportDialog")).to_be_hidden()
    expect(card).to_contain_text("Correction: the canal is blocked")
    expect(card).to_contain_text("Clogged Drainage")

    # Withdrawing deletes the report: it is gone from both Current and Past.
    card.locator("[data-report-withdraw]").click()
    page.locator(".app-confirm-dialog .app-confirm-button").click()
    expect(page.locator("#issuesList")).not_to_contain_text("Correction: the canal is blocked")
    page.click("[data-report-filter=past]")
    expect(page.locator("#issuesList")).not_to_contain_text("Correction: the canal is blocked")
