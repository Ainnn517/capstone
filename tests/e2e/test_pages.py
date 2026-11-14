"""Every page and view loads without errors, failed requests, or sideways scrolling."""

import re
import pytest

from conftest import log_in

PHONE = {"width": 390, "height": 844}
DESKTOP = {"width": 1352, "height": 760}


@pytest.mark.parametrize("size", [DESKTOP, PHONE], ids=["desktop", "phone"])
def test_all_views_render_cleanly(app, new_page, mail, size):
    page = new_page(**size)
    failures = []
    page.on("response", lambda r: r.status >= 400 and failures.append(f"{r.status} {r.url}"))

    def check(label):
        overflow = page.evaluate("document.documentElement.scrollWidth > innerWidth + 1")
        assert not overflow, f"{label}: page scrolls sideways"

    for url, action in (("/frontend/pages/index.html", None), ("/frontend/pages/index.html", "#formSwitch"), ("/privacy", None)):
        page.goto(app + url)
        page.wait_for_load_state("networkidle")
        if action:
            page.click(action)
        check(url)

    log_in(page, app, "e2eresident")
    for view in ("report", "reports", "notifications", "account"):
        page.click(f"[data-resident-view={view}]")
        check(f"resident/{view}")
    page.click("#logoutBtn")
    page.locator(".app-confirm-button").click()
    page.wait_for_url(re.compile(r"/index\.html"))

    log_in(page, app, "e2eadmin", mail=mail)
    for view in ("dashboard", "analytics", "incidents", "resolved", "archived", "referenceData", "account", "residents", "pendingApprovals"):
        page.click(f"[data-admin-view={view}]")
        page.wait_for_load_state("networkidle")
        check(f"admin/{view}")

    assert not failures, f"Failed requests: {failures}"
