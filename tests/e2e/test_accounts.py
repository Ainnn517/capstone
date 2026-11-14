"""Password change with an emailed code, password reset, and suspension."""

import re
from playwright.sync_api import expect
from conftest import PASSWORD, confirm_dialog, log_in


def test_password_change_needs_the_emailed_code(app, new_page, mail):
    page = new_page()
    log_in(page, app, "e2eresident")
    page.click("[data-resident-view=account]")
    page.fill("#currentPassword", PASSWORD)
    page.fill("#newPassword", "Brand-new-pass456!")
    page.fill("#newPasswordConfirmation", "Brand-new-pass456!")
    mail.mark()
    page.click("#sendPasswordCode")
    page.wait_for_selector("#confirmPasswordForm:not([hidden])")
    page.fill("#passwordCode", mail.code())
    page.click("#confirmPasswordForm button[type=submit]")
    expect(page.locator('#changePasswordMessage')).to_contain_text('has been changed')

    other = new_page()
    log_in(other, app, "e2eresident", password="Brand-new-pass456!")


def test_forgotten_password_can_be_reset_from_the_email(app, new_page, mail):
    page = new_page()
    page.goto(f"{app}/frontend/pages/index.html")
    page.click("#forgotPasswordButton")
    page.fill("#forgotEmail", "e2e-resident@example.test")
    mail.mark()
    page.click("#forgotPasswordForm button[type=submit]")
    link = mail.wait_for(r'(http://127\.0\.0\.1:\d+/reset-password/[^\s"<>]+)')

    page.goto(link.replace("&amp;", "&"))
    page.fill("#resetPassword", "Reset-password789!")
    page.fill("#resetPasswordConfirmation", "Reset-password789!")
    page.click("#passwordResetForm button[type=submit]")
    expect(page.locator('#passwordResetMessage')).to_contain_text('reset')

    log_in(new_page(), app, "e2eresident", password="Reset-password789!")


def test_a_suspended_resident_is_signed_out_and_cannot_sign_in(app, new_page, mail):
    resident = new_page()
    log_in(resident, app, "e2eresident")

    admin = new_page()
    log_in(admin, app, "e2eadmin", mail=mail)
    admin.click("[data-admin-view=residents]")
    admin.locator("#residentsList .resident-card", has_text="E2E Resident").locator("[data-resident-approval=suspended]").click()
    admin.fill(".app-prompt-input", "Repeated false reports.")
    confirm_dialog(admin)
    admin.wait_for_selector("#residentsList >> text=Suspended")

    # The open session is ended on its next request.
    resident.click("[data-resident-view=reports]")
    resident.reload()
    resident.wait_for_url(re.compile(r"/index\.html"))
    resident.fill("#username", "e2eresident")
    resident.fill("#password", PASSWORD)
    resident.click("#loginForm button[type=submit]")
    expect(resident.locator('#formMessage')).to_contain_text('suspended')
