"""Browser end-to-end tests: real browsers driving the real app.

The harness builds the dedicated TEST database (barangay_reporting_test) from the migrations,
seeds reference data and two accounts, and serves the app on a free local port. Emails are
written to the Laravel log (MAIL_MAILER=log), and helpers read links and codes from there.
"""

import os
import quopri
import re
import socket
import subprocess
import time
import urllib.request
from pathlib import Path

import pytest
from playwright.sync_api import sync_playwright

PROJECT = Path(__file__).resolve().parents[2]
LOG_FILE = PROJECT / "storage" / "logs" / "laravel.log"
TEST_DATABASE = "barangay_reporting_test"
PASSWORD = "E2e-password123!"
BROWSERS = os.environ.get("E2E_BROWSERS", "firefox,chromium").split(",")


def _free_port() -> int:
    with socket.socket() as sock:
        sock.bind(("127.0.0.1", 0))
        return sock.getsockname()[1]


PORT = _free_port()
BASE_URL = f"http://127.0.0.1:{PORT}"

# Environment for both the server and the setup commands. Real environment variables take
# precedence over .env, so this can never point at the development database.
ENV = {
    **os.environ,
    "APP_ENV": "local",
    "APP_DEBUG": "false",
    "APP_URL": BASE_URL,
    "DB_DATABASE": TEST_DATABASE,
    "MAIL_MAILER": "log",
    "CACHE_STORE": "file",
    "SESSION_DRIVER": "file",
    "ADMIN_TWO_FACTOR": "true",
    "EMAIL_VERIFICATION_REQUIRED": "true",
    "TRIAGE_AI_ENABLED": "false",
    "ANTHROPIC_API_KEY": "",
    "GOOGLE_MAPS_API_KEY": "",
    "PILOT_ADMIN_USERNAME": "",
    "PILOT_RESIDENT_USERNAME": "",
}


def _artisan(*args: str) -> None:
    subprocess.run(["php", "artisan", *args], cwd=PROJECT, env=ENV, check=True, capture_output=True)


def reset_database() -> None:
    """Fresh schema, reference data, and the two E2E accounts."""
    _artisan("migrate:fresh", "--force")
    _artisan("db:seed", "--force")
    _artisan("cache:clear")
    subprocess.run(["php", "tests/e2e/fixtures/users.php"], cwd=PROJECT, env=ENV, check=True, capture_output=True)


@pytest.fixture(scope="session")
def server():
    LOG_FILE.parent.mkdir(parents=True, exist_ok=True)
    LOG_FILE.touch()
    process = subprocess.Popen(
        ["php", "-S", f"127.0.0.1:{PORT}", "../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php"],
        cwd=PROJECT / "public", env=ENV, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
    )
    for _ in range(50):
        try:
            urllib.request.urlopen(BASE_URL + "/up", timeout=1)
            break
        except OSError:
            time.sleep(0.2)
    else:
        process.terminate()
        pytest.fail("The app server did not start.")
    yield BASE_URL
    process.terminate()
    process.wait(timeout=10)


@pytest.fixture(scope="session")
def playwright_instance():
    with sync_playwright() as playwright:
        yield playwright


@pytest.fixture(params=BROWSERS)
def browser(request, playwright_instance):
    browser = getattr(playwright_instance, request.param.strip()).launch()
    yield browser
    browser.close()


@pytest.fixture
def app(server):
    """A clean database for every test."""
    reset_database()
    return server


@pytest.fixture
def new_page(browser, app):
    """Opens isolated browser windows (separate sessions, like separate people)."""
    contexts, errors = [], []

    def open_page(width: int = 1352, height: int = 760):
        context = browser.new_context(viewport={"width": width, "height": height})
        contexts.append(context)
        page = context.new_page()
        page.set_default_timeout(10_000)
        page.on("pageerror", lambda error: errors.append(str(error)))
        return page

    yield open_page
    for context in contexts:
        context.close()
    assert not errors, f"JavaScript errors in the page: {errors}"


class MailLog:
    """Reads emails that the app wrote to the Laravel log since the mark."""

    def __init__(self):
        self.mark()

    def mark(self) -> None:
        self.offset = LOG_FILE.stat().st_size

    def text(self) -> str:
        with LOG_FILE.open("rb") as handle:
            handle.seek(self.offset)
            raw = handle.read()
        # Emails can contain both a plain and a quoted-printable copy; decoding the plain copy
        # would corrupt it (for example "?email=e2e..." looks like the escape "=e2"), so
        # search the raw text first and the decoded text after it.
        return raw.decode("utf-8", errors="replace") + "\n" + quopri.decodestring(raw).decode("utf-8", errors="replace")

    def wait_for(self, pattern: str, timeout: float = 10.0) -> str:
        deadline = time.time() + timeout
        while time.time() < deadline:
            matches = [m for m in re.findall(pattern, self.text()) if "\ufffd" not in m]
            if matches:
                return matches[0]
            time.sleep(0.2)
        raise AssertionError(f"No email matching {pattern!r} was sent.")

    def code(self) -> str:
        return self.wait_for(r'letter-spacing: 6px;">\s*(\d{6})\s*<')


@pytest.fixture
def mail():
    return MailLog()


def log_in(page, base_url: str, username: str, password: str = PASSWORD, mail: MailLog | None = None) -> None:
    """Signs in through the login form; administrators also enter the emailed code."""
    if mail:
        mail.mark()
    page.goto(base_url + "/frontend/pages/index.html")
    page.fill("#username", username)
    page.fill("#password", password)
    page.click("#loginForm button[type=submit]")
    if mail is not None:
        page.wait_for_selector("#twoFactorForm:not([hidden])")
        page.fill("#loginCode", mail.code())
        page.click("#twoFactorForm button[type=submit]")
    page.wait_for_url(re.compile(r"(admin-dashboard|resident-portal)\.html"))
    page.wait_for_load_state("networkidle")


def confirm_dialog(page) -> None:
    page.locator(".app-confirm-dialog .app-confirm-button").click()
