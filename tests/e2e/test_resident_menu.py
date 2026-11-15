"""The resident menu sits behind the ☰ button and opens as a drawer."""

from playwright.sync_api import expect

from conftest import log_in


def test_menu_opens_and_closes(app, new_page):
    page = new_page(390, 800)
    log_in(page, app, "e2eresident")
    menu, toggle = page.locator("#residentMenu"), page.locator("#menuToggle")

    expect(menu).not_to_be_in_viewport()
    expect(toggle).to_have_attribute("aria-expanded", "false")

    toggle.click()
    expect(menu).to_be_in_viewport()
    expect(toggle).to_have_attribute("aria-expanded", "true")
    page.keyboard.press("Escape")
    expect(menu).not_to_be_in_viewport()

    toggle.click()
    page.locator("#menuBackdrop").click(position={"x": 370, "y": 400})
    expect(menu).not_to_be_in_viewport()

    # Choosing a view switches to it and closes the menu.
    toggle.click()
    page.click("[data-resident-view=account]")
    expect(page.locator("#accountView")).to_be_visible()
    expect(menu).not_to_be_in_viewport()
