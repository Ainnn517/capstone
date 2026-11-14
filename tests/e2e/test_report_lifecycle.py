"""The main journey: sign-up, approval, a report from submission to resolution."""

from playwright.sync_api import expect
import re

from conftest import confirm_dialog, log_in

NEW_PASSWORD = "Juan-delacruz123!"


def register_and_verify(app, page, mail) -> None:
    page.goto(f"{app}/frontend/pages/index.html")
    page.click("#formSwitch")
    page.fill("#registerName", "Juan Dela Cruz")
    page.fill("#registerEmail", "juan@example.test")
    page.fill("#registerUsername", "juandc")
    page.fill("#registerPassword", NEW_PASSWORD)
    page.fill("#confirmPassword", NEW_PASSWORD)
    page.click(".privacy-consent .check-box")
    mail.mark()
    page.click("#registerForm button[type=submit]")
    expect(page.locator('#formMessage')).to_contain_text('Registration received')

    link = mail.wait_for(r'(http://127\.0\.0\.1:\d+/api/email/verify/[^\s"<>]+)')
    page.goto(link.replace("&amp;", "&"))
    assert "verified=1" in page.url


def test_report_goes_from_sign_up_to_resolved(app, new_page, mail):
    resident = new_page()
    register_and_verify(app, resident, mail)

    # Not approved yet: sign-in is refused with a clear message.
    resident.fill("#username", "juandc")
    resident.fill("#password", NEW_PASSWORD)
    resident.click("#loginForm button[type=submit]")
    expect(resident.locator('#formMessage')).to_contain_text('awaiting administrator approval')

    # The administrator approves the sign-up.
    admin = new_page()
    log_in(admin, app, "e2eadmin", mail=mail)
    admin.click("[data-admin-view=pendingApprovals]")
    card = admin.locator("#pendingApprovalList .resident-card", has_text="Juan Dela Cruz")
    card.locator("[data-resident-approval=approved]").click()
    confirm_dialog(admin)
    expect(admin.locator('#pendingApprovalList')).not_to_contain_text('Juan Dela Cruz')

    # The resident signs in and files a report, accepting the suggested category.
    log_in(resident, app, "juandc", password=NEW_PASSWORD)
    resident.select_option("#phase", index=1)
    if resident.locator("#purok").is_enabled():
        resident.select_option("#purok", index=1)
    resident.fill("#description", "Barado ang kanal sa harap ng bahay namin, bumabaha tuwing umuulan.")
    resident.click("#location")
    resident.locator("[data-use-category]").click()
    assert resident.input_value("#category") == "Clogged Drainage"
    resident.fill("#location", "Near the chapel")
    resident.click("#issueForm button[type=submit]")
    expect(resident.locator('#submitAlert')).to_contain_text('Report Submitted')
    tracking = re.search(r"BR-[\w-]+", resident.inner_text("#submitAlert")).group(0)

    def open_manage():
        admin.click("[data-admin-view=incidents]")
        admin.wait_for_load_state("networkidle")
        admin.locator("#incidentBody tr", has_text=tracking).locator("[data-report-id]").click()
        admin.wait_for_selector("#actionModal.show")

    def save():
        admin.click("#saveActionBtn")

    # Validate, using the drafted message.
    open_manage()
    assert admin.input_value("#editStatus") == "Validated"
    admin.locator("[data-assistant-draft]").click()
    save()
    admin.wait_for_selector("#actionModal.show", state="hidden")

    # Dispatch is refused without a team, then works with the suggested team.
    open_manage()
    assert admin.input_value("#editStatus") == "Dispatched"
    admin.fill("#dispatchNote", "Sending a team.")
    save()
    admin.wait_for_selector("text=Assign a team before marking this report Dispatched.")
    admin.locator("[data-assistant-assign]").click()
    assert admin.input_value("#assignee") == "Maintenance & Engineering Crew"
    admin.locator("[data-assistant-draft]").click()
    save()
    admin.wait_for_selector("#actionModal.show", state="hidden")

    # Resolve.
    open_manage()
    assert admin.input_value("#editStatus") == "Resolved"
    admin.locator("[data-assistant-draft]").click()
    save()
    admin.wait_for_selector("#actionModal.show", state="hidden")
    admin.click("[data-admin-view=resolved]")
    admin.wait_for_selector(f"#resolvedBody >> text={tracking}")

    # The resident sees the outcome.
    resident.reload()
    resident.wait_for_load_state("networkidle")
    resident.click("[data-resident-view=reports]")
    resident.click("[data-report-filter=past]")
    resident.wait_for_selector(f"#issuesList >> text={tracking}")
    resident.click("[data-resident-view=notifications]")
    resident.wait_for_selector("#notifications >> text=Your report has been resolved")
