"""Admin levels: a viewer sees everything but no change buttons; a super admin adds accounts."""

from playwright.sync_api import expect

from conftest import log_in
from test_resident_confirmation import submit_report


def test_viewer_sees_reports_without_change_buttons(app, new_page, mail):
    resident = new_page()
    log_in(resident, app, "e2eresident")
    submit_report(resident)

    viewer = new_page()
    log_in(viewer, app, "e2eviewer", mail=mail)
    expect(viewer.locator("#viewOnlyBadge")).to_be_visible()
    viewer.click("[data-admin-view=incidents]")
    row = viewer.locator("#incidentBody tr", has_text="Streetlights")
    expect(row).to_be_visible()
    expect(row.locator("[data-report-id]")).to_be_hidden()
    expect(row.locator("[data-archive-report-id]")).to_be_hidden()
    expect(viewer.locator("#clearReportsBtn")).to_be_hidden()
    expect(viewer.locator("[data-admin-view=referenceData]")).to_be_hidden()
    expect(viewer.locator("[data-admin-view=adminAccounts]")).to_be_hidden()
    viewer.click("[data-admin-view=account]")
    expect(viewer.locator("#accountRole")).to_have_text("Administrator (Viewer)")


def test_super_admin_adds_a_staff_account(app, new_page, mail):
    admin = new_page()
    log_in(admin, app, "e2eadmin", mail=mail)
    expect(admin.locator("#viewOnlyBadge")).to_be_hidden()
    admin.click("[data-admin-view=adminAccounts]")
    expect(admin.locator("#adminAccountsBody tr")).to_have_count(2)
    expect(admin.locator("#adminAccountsBody tr", has_text="e2eadmin")).to_contain_text("(you)")

    admin.fill("#newAdminName", "Kagawad Reyes")
    admin.fill("#newAdminUsername", "kreyes")
    admin.fill("#newAdminEmail", "kreyes@example.test")
    admin.select_option("#newAdminLevel", "staff")
    admin.fill("#newAdminPassword", "Kagawad-pass123!")
    admin.fill("#newAdminPasswordConfirm", "Kagawad-pass123!")
    admin.click("#addAdminForm button[type=submit]")
    expect(admin.locator("#addAdminMessage")).to_contain_text("Administrator added")
    row = admin.locator("#adminAccountsBody tr", has_text="kreyes")
    expect(row.locator("select")).to_have_value("staff")

    row.locator("[data-admin-active-for]").click()
    admin.locator(".app-confirm-dialog .app-confirm-button").click()
    expect(row).to_contain_text("Disabled")
