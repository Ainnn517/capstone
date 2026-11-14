# Deployment and operations

## Requirements and initial setup

The application requires PHP 8.2+ with `pdo_mysql`, `fileinfo`, `mbstring`, and XML/DOM, Composer, and MySQL. The browser screens and Laravel API are served by Laravel; point the web server document root to `public/`.

1. Create an empty MySQL database (`CREATE DATABASE your_database CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci`), set its details in `.env` (step 2), then build the tables with `php artisan migrate`. The migrations in `database/migrations` are the source of truth for the schema; `database/mysql_schema.sql` describes the same schema for manual imports (for example through phpMyAdmin), and a test keeps the two identical.
   - **Existing database already on the current schema:** back it up, then run `php artisan migrate`. It recognises the existing tables and only records them as done.
   - **Older database from before these changes:** back it up, run `mysql -u user -p your_database < database/upgrade_existing_database.sql`, then `php artisan migrate`.
   - **Changing the schema later:** add a new migration (`php artisan make:migration ...`) and update `database/mysql_schema.sql` to match; the test suite fails until both agree.
2. Copy `.env.example` to `.env`. Configure `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, and `DB_PASSWORD`. Set a Google Maps JavaScript API key if the map picker is needed.
3. Install dependencies, generate an application key, and seed the reference data (categories, teams, locations). Outside production the seeder also creates the pilot accounts from `.env`; it never changes an account that already exists:

   ```sh
   composer install
   php artisan key:generate
   php artisan db:seed
   ```

## Fresh online database (recommended for the first deployment)

Start the hosted system with a clean database rather than copying development data:

1. Create the database on the host and run `php artisan migrate --force`.
2. Run `php artisan db:seed` to add the categories, teams, recommended teams, and the street catalog. In production the seeder creates **no accounts**.
3. Create the first administrator interactively (the password is never stored in `.env`):

   ```sh
   php artisan admin:create
   ```

4. Residents then register through the site and the administrator approves them.

4. Start locally with `php artisan serve`.

After schema setup or upgrade, run `php artisan db:seed` to add or refresh default categories, teams, recommended team assignments, and approved locations. Existing location rows not present in the configuration remain inactive. Verify the resulting schema before deploying the matching code. The old security-hardening SQL migration is retained under `database/legacy/` and is superseded by `database/upgrade_existing_database.sql`.

## Mail and maps

The example `.env` uses Laravel's `log` mailer and does not send inbox email. Email verification is required by default; configure a mail provider before deployment, or set `EMAIL_VERIFICATION_REQUIRED=false` if the installation intentionally does not use inbox verification. With verification disabled, administrators can approve accounts without residents needing to receive an email. To verify an account manually, run `php artisan accounts:verify {username}`. To enable account/approval mail, configure a provider privately via `.env` or the hosting secret manager. For Gmail SMTP, use an App Password—not the normal account password—and set the SMTP host, port, username, password, and sender address. Never commit or share credentials.

The location picker requires the Google Maps JavaScript API, billing, and an API key restricted to the required API and allowed referrers. This map integration still requires network access. The charts and Uicons used by the operations/resident pages are hosted locally.

## Administrator sign-in codes

Administrators confirm every sign-in with a 6-digit code emailed to them (`ADMIN_TWO_FACTOR=true`, the default). Email delivery must therefore work before the first administrator signs in on a server. For local development without email, set `ADMIN_TWO_FACTOR=false`, or keep `MAIL_MAILER=log` and read the code from `storage/logs/laravel.log`.

## Scheduled clean-up and backups

`php artisan data:purge` deletes rejected applications after 30 days, archived reports (with their photos and history) after one year, administrator audit entries after two years, and expired password-reset links. The periods are `RETENTION_*` settings in `.env`; `0` disables a rule. It runs daily at 02:00 through Laravel's scheduler, which needs one cron entry on the server:

```
* * * * * cd /path/to/CAPSTONE && php artisan schedule:run >> /dev/null 2>&1
```

Run `php artisan data:purge --dry-run` to see what would be removed. Take a database backup before the first real run, and keep nightly backups thereafter (see the checklist below).

## Continuous integration

Every push to GitHub runs code style checks and the full test suite against MySQL 8 (`.github/workflows/tests.yml`). Dependabot opens pull requests for security and dependency updates weekly (`.github/dependabot.yml`); merge them after the tests pass.

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

Browser end-to-end tests (Firefox and Chromium, full user journeys) live in `tests/e2e`; see [tests/e2e/README.md](./tests/e2e/README.md). They use the same `barangay_reporting_test` database.


Run `php artisan test` or `composer test`. Tests use the separate `barangay_reporting_test` MySQL database configured in `phpunit.xml`; the test harness drops and recreates its application tables. Never point it at the live database. Create the test database and grant the configured application account access:

```sql
CREATE DATABASE IF NOT EXISTS barangay_reporting_test
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;
GRANT ALL PRIVILEGES ON barangay_reporting_test.* TO 'barangay_app'@'127.0.0.1';
```

Adjust the grant target to match the private MySQL account and host configured locally.
