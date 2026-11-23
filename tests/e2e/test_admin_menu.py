"""Admin desktop: the ☰ button hides and shows the sidebar, and the choice survives a reload."""

from playwright.sync_api import expect
from conftest import log_in
def test_desktop_menu_button_hides_the_sidebar_and_remembers_it(app, new_page, mail):
    page = new_page(1352, 760)
    log_in(page, app, "e2eadmin", mail=mail)
    expect(page.locator("#adminMenuToggle")).to_be_visible()
    expect(page.locator("#adminMenu")).to_be_visible()
    page.click("#adminMenuToggle")
    expect(page.locator("#adminMenu")).to_be_hidden()
    page.reload(); page.wait_for_load_state("networkidle")
    expect(page.locator("#adminMenu")).to_be_hidden()
    page.click("#adminMenuToggle")
    expect(page.locator("#adminMenu")).to_be_visible()


def test_scrolling_over_the_desktop_sidebar_scrolls_only_the_sidebar(app, new_page, mail):
    # A short window, so the sidebar is taller than the space it has.
    page = new_page(1366, 640)
    log_in(page, app, "e2eadmin", mail=mail)
    box = page.locator("#adminMenu").bounding_box()
    page.mouse.move(box["x"] + 100, box["y"] + 150)
    for _ in range(6):
        page.mouse.wheel(0, 400)
        page.wait_for_timeout(150)
    scrolled = page.evaluate("({page: scrollY, menu: document.getElementById('adminMenu').scrollTop})")
    assert scrolled["menu"] > 0, scrolled
    assert scrolled["page"] == 0, scrolled
