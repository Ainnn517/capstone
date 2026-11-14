# Browser end-to-end tests

Real browsers (Firefox and Chromium) drive the real application through complete journeys:
sign-up and approval, a report from submission to resolution, administrator two-factor
sign-in, password change and reset, suspension, page access rules, and every view at desktop
and phone size.

## Running

```sh
python3 -m venv .venv-e2e
.venv-e2e/bin/pip install -r tests/e2e/requirements.txt
.venv-e2e/bin/playwright install firefox chromium
.venv-e2e/bin/python -m pytest tests/e2e -q
```

Run one browser with `E2E_BROWSERS=firefox`.

## What the harness does

- Uses **only** the `barangay_reporting_test` database (same as the PHPUnit tests): each test
  rebuilds it with `php artisan migrate:fresh`, seeds the reference data, and creates two
  accounts (`e2eadmin`, `e2eresident`, password `E2e-password123!`).
- Starts its own PHP server on a free port; your development server is not touched.
- Sends email to the Laravel log (`MAIL_MAILER=log`) and reads verification links, reset
  links, and 6-digit codes from there.
