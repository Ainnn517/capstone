"""Tapping Submit several times sends one report, then the button waits a minute with a countdown."""

import re

from playwright.sync_api import expect

from conftest import log_in


def test_quick_taps_send_one_report_and_the_button_counts_down(app, new_page):
    page = new_page()
    log_in(page, app, "e2eresident")

    # Count the report requests the page sends, and answer as the server does when the
    # one-minute wait is on (the test server runs with the wait off).
    sent = []

    def answer(route):
        if route.request.method != "POST":
            return route.continue_()
        sent.append(route.request.url)
        page.wait_for_timeout(300)  # a slow connection, so taps arrive while it is sending
        route.fulfill(status=201, content_type="application/json",
                      body='{"success": true, "id": "1", "tracking": "BR-TEST-1", "nextReportIn": 60}')

    page.route(re.compile(r"/api/reports$"), answer)

    page.select_option("#category", label="Road Damage")
    page.select_option("#phase", index=1)
    if page.locator("#purok").is_enabled():
        page.select_option("#purok", index=1)
    page.fill("#description", "Trash in the streets")
    page.fill("#location", "Near the chapel")
    button = page.locator("#issueForm button[type=submit]")
    button.click()
    button.click(force=True)
    button.click(force=True)

    expect(page.locator("#submitAlert")).to_contain_text("BR-TEST-1")
    expect(button).to_be_disabled()
    expect(button).to_have_text(re.compile(r"You can send another report in \d+s"))
    assert len(sent) == 1, sent
