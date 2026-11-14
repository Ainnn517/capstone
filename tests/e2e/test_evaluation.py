"""Admin Evaluation page: label real reports blind and see accuracy."""

from playwright.sync_api import expect

from conftest import log_in


def test_admin_labels_reports_and_sees_results(app, new_page, mail):
    resident = new_page()
    log_in(resident, app, "e2eresident")
    for description, category in (
        ("Barado ang kanal sa harap ng bahay, bumabaha tuwing umuulan.", "Others"),
        ("Walang ilaw sa poste sa dulo ng kalye.", "Streetlights"),
    ):
        resident.select_option("#category", label=category)
        resident.select_option("#phase", index=1)
        if resident.locator("#purok").is_enabled():
            resident.select_option("#purok", index=1)
        resident.fill("#description", description)
        resident.fill("#location", "Near the chapel")
        resident.click("#issueForm button[type=submit]")
        expect(resident.locator("#submitAlert")).to_contain_text("Report Submitted")

    admin = new_page()
    log_in(admin, app, "e2eadmin", mail=mail)
    admin.click("[data-admin-view=evaluation]")
    expect(admin.locator("#evaluationProgress")).to_contain_text("0 of 2 reports labelled")
    # Blind: only the description is shown.
    expect(admin.locator(".evaluation-description")).to_contain_text("Barado ang kanal")
    admin.locator("[data-eval-category]", has_text="Clogged Drainage").click()
    expect(admin.locator(".evaluation-description")).to_contain_text("Walang ilaw")
    admin.locator("[data-eval-category]", has_text="Streetlights").click()
    expect(admin.locator("#evaluationTask")).to_contain_text("Every report has been labelled")

    # Residents chose wrongly once (Others), the keyword rules were right both times.
    stats = admin.locator(".evaluation-stat")
    expect(stats.nth(0)).to_contain_text("50%")
    expect(stats.nth(1)).to_contain_text("100%")
    expect(stats.nth(2)).to_contain_text("Not run yet")

    with admin.expect_download() as download:
        admin.click("#evaluationExport")
    assert download.value.suggested_filename.startswith("evaluation-set-")
