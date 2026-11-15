"""The resident portal switches between English and Filipino, and remembers the choice."""

from playwright.sync_api import expect

from conftest import log_in, open_resident_view
from test_resident_confirmation import submit_report


def test_resident_portal_in_filipino(app, new_page):
    page = new_page()
    log_in(page, app, "e2eresident")
    submit_report(page)
    expect(page.locator("#reportTitle")).to_have_text("Report an Issue")

    page.click("[data-language=fil]")
    expect(page.locator("#reportTitle")).to_have_text("Mag-ulat ng Problema")
    expect(page.locator("#residentName")).to_have_text("Maligayang pagdating, e2eresident")
    expect(page.locator("#description")).to_have_attribute("placeholder", "Ilarawan ang problema (1 hanggang 500 titik).")
    expect(page.locator("html")).to_have_attribute("lang", "fil")

    open_resident_view(page, "reports")
    card = page.locator(".issue-card").first
    expect(page.locator("#issueCount")).to_have_text("1 kasalukuyang ulat")
    expect(card.locator("[data-report-history]")).to_have_text("Kasaysayan ng status")
    expect(card).to_contain_text("Priyoridad: Karaniwan")
    # The resident's own words are never changed.
    expect(card.locator(".issue-description")).to_have_text("The streetlight at the corner has been off for weeks.")

    # The choice is remembered on this device.
    page.reload()
    expect(page.locator("#reportTitle")).to_have_text("Mag-ulat ng Problema")

    page.click("[data-language=en]")
    expect(page.locator("#reportTitle")).to_have_text("Report an Issue")
    expect(page.locator("#residentName")).to_have_text("Welcome, e2eresident")
    open_resident_view(page, "reports")
    expect(page.locator("#issueCount")).to_have_text("1 current report")
