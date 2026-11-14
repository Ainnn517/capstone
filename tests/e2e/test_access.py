"""Who can open which page, and administrator two-factor sign-in."""

from playwright.sync_api import expect
from conftest import PASSWORD, log_in


def test_signed_out_visitors_are_sent_to_the_login_page(app, new_page):
    page = new_page()
    for portal in ("admin-dashboard.html", "resident-portal.html"):
        page.goto(f"{app}/frontend/pages/{portal}")
        assert page.url.endswith("/frontend/pages/index.html")
        assert page.locator(".admin-sidebar, .resident-sidebar").count() == 0


def test_each_role_is_kept_to_its_own_portal(app, new_page, mail):
    resident = new_page()
    log_in(resident, app, "e2eresident")
    resident.goto(f"{app}/frontend/pages/admin-dashboard.html")
    assert resident.url.endswith("resident-portal.html")

    admin = new_page()
    log_in(admin, app, "e2eadmin", mail=mail)
    admin.goto(f"{app}/frontend/pages/resident-portal.html")
    assert admin.url.endswith("admin-dashboard.html")


def test_administrator_needs_the_emailed_code(app, new_page, mail):
    page = new_page()
    page.goto(f"{app}/frontend/pages/index.html")
    page.fill("#username", "e2eadmin")
    page.fill("#password", PASSWORD)
    page.click("#loginForm button[type=submit]")
    page.wait_for_selector("#twoFactorForm:not([hidden])")
    assert "e***@example.test" in page.inner_text("#twoFactorIntro")

    code = mail.code()
    page.fill("#loginCode", "000000" if code != "000000" else "111111")
    page.click("#twoFactorForm button[type=submit]")
    expect(page.locator('#twoFactorMessage')).to_contain_text('incorrect')
    assert page.url.endswith("index.html")

    page.fill("#loginCode", code)
    page.click("#twoFactorForm button[type=submit]")
    page.wait_for_url("**/admin-dashboard.html")


def test_wrong_password_shows_an_error_and_does_not_sign_in(app, new_page):
    page = new_page()
    page.goto(f"{app}/frontend/pages/index.html")
    page.fill("#username", "e2eresident")
    page.fill("#password", "Wrong-password1!")
    page.click("#loginForm button[type=submit]")
    expect(page.locator('#formMessage')).to_contain_text('Incorrect')
    assert page.url.endswith("index.html")
