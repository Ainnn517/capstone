# Deployment and operations

## Requirements and initial setup

The application requires PHP 8.2+ with `pdo_mysql`, `fileinfo`, `mbstring`, and XML/DOM, Composer, and MySQL. The browser screens and Laravel API are served by Laravel; point the web server document root to `public/`.

1. For a new installation, create a MySQL database and import `database/mysql_schema.sql`. For an existing installation, back up the database first, then apply `database/upgrade_existing_database.sql`.
2. Copy `.env.example` to `.env`. Configure `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, and `DB_PASSWORD`. Set a Google Maps JavaScript API key if the map picker is needed.
3. Install dependencies, generate an application key, and optionally seed pilot accounts:

   ```sh
   composer install
   php artisan key:generate
   php artisan db:seed
   ```

4. Start locally with `php artisan serve`.

The database schema is managed manually. After schema setup or upgrade, run `php artisan db:seed` to add or refresh default categories, teams, recommended team assignments, and approved locations. Existing location rows not present in the configuration remain inactive. Verify the resulting schema before deploying the matching code. The old security-hardening SQL migration is retained under `database/legacy/` and is superseded by `database/upgrade_existing_database.sql`.

## Mail and maps

The example `.env` uses Laravel's `log` mailer and does not send inbox email. Email verification is required by default; configure a mail provider before deployment, or set `EMAIL_VERIFICATION_REQUIRED=false` if the installation intentionally does not use inbox verification. With verification disabled, administrators can approve accounts without residents needing to receive an email. To verify an account manually, run `php artisan accounts:verify {username}`. To enable account/approval mail, configure a provider privately via `.env` or the hosting secret manager. For Gmail SMTP, use an App Password—not the normal account password—and set the SMTP host, port, username, password, and sender address. Never commit or share credentials.

The location picker requires the Google Maps JavaScript API, billing, and an API key restricted to the required API and allowed referrers. This map integration still requires network access. The charts and Uicons used by the operations/resident pages are hosted locally.

## Production checklist

- Set `APP_ENV=production`, `APP_DEBUG=false`, the real HTTPS `APP_URL`, and `SESSION_SECURE_COOKIE=true`.
- If the app is behind a reverse proxy, set `TRUSTED_PROXIES` to its exact IP address(es) or CIDR ranges. Do not trust arbitrary forwarded headers.
- Keep `.env`, keys, database passwords, and pilot credentials out of GitHub.
- Use a supported PHP runtime and install production dependencies with `composer install --no-dev --optimize-autoloader`.
- Expose only `public/`; use HTTPS and keep MySQL private with a least-privilege application account.
- Ensure `storage/` and `bootstrap/cache/` are writable; run `php artisan config:cache`, `php artisan route:cache`, and `php artisan view:cache`.
- Keep encrypted database backups outside the web root, restrict access, test restores, and apply an approved retention schedule. Reports, account details, GPS data, and attachments are sensitive.
- Review the `/privacy` notice with the barangay privacy officer before accepting real data.
- Verify registration, login, report submission, notification read state, attachments, admin actions, and logout on the deployed host.

## Tests

Run `php artisan test` or `composer test`. Tests use the separate `barangay_reporting_test` MySQL database configured in `phpunit.xml`; the test harness drops and recreates its application tables. Never point it at the live database. Create the test database and grant the configured application account access:

```sql
CREATE DATABASE IF NOT EXISTS barangay_reporting_test
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;
GRANT ALL PRIVILEGES ON barangay_reporting_test.* TO 'barangay_app'@'127.0.0.1';
```

Adjust the grant target to match the private MySQL account and host configured locally.
