"""A page whose CSRF token went stale (log in and out in another tab) still works."""

import re

from playwright.sync_api import expect

from conftest import confirm_dialog, log_in, open_resident_view

PASSWORD = "Maria-santos123!"


def test_sign_up_after_logging_out_in_another_tab(app, new_page):
    signup = new_page()
    signup.goto(f"{app}/frontend/pages/index.html")
    signup.click("#formSwitch")

    # Same browser, second tab: log in and out, which gives the session a new token.
    other = signup.context.new_page()
    log_in(other, app, "e2eresident")
    open_resident_view(other, 'account')
    other.click("#logoutBtn")
    confirm_dialog(other)
    other.wait_for_url(re.compile(r"/index\.html"))
    other.close()

    # The first tab still holds the old token; the sign-up must succeed anyway.
    signup.fill("#registerName", "Maria Santos")
    signup.fill("#registerEmail", "maria@example.test")
    signup.fill("#registerUsername", "mariasantos")
    signup.fill("#registerPassword", PASSWORD)
    signup.fill("#confirmPassword", PASSWORD)
    signup.click(".privacy-consent .check-box")
    signup.click("#registerForm button[type=submit]")
    expect(signup.locator("#formMessage")).to_contain_text("Registration received")
