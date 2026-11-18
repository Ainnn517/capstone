"""A resident pins a report on the map; the admin dashboard picks it up live and maps it."""

from playwright.sync_api import expect

from conftest import _artisan, log_in


def submit_pinned_report(page) -> None:
    page.select_option("#category", label="Road Damage")
    page.select_option("#phase", index=1)
    if page.locator("#purok").is_enabled():
        page.select_option("#purok", index=1)
    # Typing, then pressing a button straight away: the category hint must not swallow the click.
    page.fill("#description", "Deep pothole in the middle of the road near the corner.")
    page.click("#geoBtn")
    expect(page.locator("#locationMapDialog")).to_be_visible()
    expect(page.locator("#locationMap .leaflet-tile-pane")).to_be_attached()
    page.locator("#locationMap").click()
    expect(page.locator("#geoMsg")).to_contain_text("Map location attached")
    page.click("#confirmLocationBtn")
    expect(page.locator("#locationMapDialog")).to_be_hidden()
    page.click("#issueForm button[type=submit]")
    expect(page.locator("#submitAlert")).to_contain_text("Report Submitted")


def test_new_pinned_report_appears_live_and_on_the_hotspot_map(app, new_page, mail):
    admin = new_page()
    log_in(admin, app, "e2eadmin", mail=mail)
    expect(admin.locator("#liveStatusText")).to_contain_text("Live")

    resident = new_page()
    log_in(resident, app, "e2eresident")
    submit_pinned_report(resident)

    # Returning to the tab runs the same refresh the 30-second timer runs.
    admin.evaluate("document.dispatchEvent(new Event('visibilitychange'))")
    expect(admin.locator("#toastContainer")).to_contain_text("1 new report received.")
    expect(admin.locator("#mTotal")).to_have_text("1")

    # The hotspot map is on the Operations Dashboard, the page admins land on.
    expect(admin.locator("#dashboardView #hotspotMap")).to_be_visible()
    expect(admin.locator("#hotspotMapNote")).to_have_text("1 open report on the map.")
    expect(admin.locator("#hotspotMap path.leaflet-interactive")).to_have_count(1)
    admin.locator("#hotspotMap path.leaflet-interactive").click()
    expect(admin.locator("#hotspotMap .leaflet-popup-content")).to_contain_text("Road Damage")

    # Two more open reports at the same spot make it a hotspot (threshold 3).
    _artisan("tinker", "--execute=" + (
        "$r = App\\Models\\IssueReport::first(); foreach ([1, 2] as $i) { "
        "$c = $r->replicate(); $c->tracking_code = 'BR-COPY'.$i; $c->save(); }"
    ))
    admin.select_option("#hotspotMapScope", "all")
    expect(admin.locator("#hotspotMapNote")).to_have_text("3 reports on the map · 1 hotspot.")
    # Three pins plus one hotspot ring.
    expect(admin.locator("#hotspotMap path.leaflet-interactive")).to_have_count(4)


def test_refresh_waits_while_the_admin_is_typing(app, new_page, mail):
    admin = new_page()
    log_in(admin, app, "e2eadmin", mail=mail)
    admin.click("[data-admin-view=referenceData]")
    admin.click("#newCategoryName")

    admin.evaluate("document.dispatchEvent(new Event('visibilitychange'))")
    expect(admin.locator("#liveStatusText")).to_have_text("Paused while you work")
