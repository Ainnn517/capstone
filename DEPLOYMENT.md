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

Run `php artisan data:purge --dry-run` to see what would be removed. Take a database backup before the first real run.

### Backups and restores

`php artisan backup:run` writes a compressed database dump and an archive of uploaded photos to `BACKUP_PATH` (default `storage/app/backups`, files readable only by the app's system user), then deletes backups older than `BACKUP_KEEP_DAYS` (default 14). The scheduler runs it nightly at 01:30, before the clean-up. It needs the `mysqldump` and `mysql` client tools on the server.

To restore:

```
php artisan backup:run                                   # keep the current state first
php artisan backup:restore storage/app/backups/barangay_reporting-YYYYMMDD-HHMMSS.sql.gz
tar -xzf storage/app/backups/attachments-YYYYMMDD-HHMMSS.tar.gz -C storage/app   # photos
```

The restore asks you to type the database name before replacing any data. The test suite backs up, damages and restores a database on every run (`tests/Feature/BackupRestoreTest.php`), but still rehearse a restore on the real server once after deployment. Backups on the same server do not survive losing that server: copy them regularly to separate, access-controlled storage (for example the host's backup service or an encrypted drive), because they contain resident data.

### Least-privilege database accounts

`database/least_privilege_users.sql` creates two MySQL accounts. The web app's account (`DB_USERNAME`) can only read and change rows; it cannot create, alter or drop tables. A separate owner account (`DB_ADMIN_USERNAME` / `DB_ADMIN_PASSWORD`) is used only from the command line for migrations and backups:

```
php artisan migrate --database=mysql_admin --force
php artisan backup:run
```

If `DB_ADMIN_USERNAME` is not set, both use the app account (fine for local development).

## Continuous integration

Every push to GitHub runs code style checks, the full test suite against MySQL 8, and the browser tests in Firefox and Chromium, then repeats the browser tests in production mode (`APP_ENV=production` with config, routes and views cached) to catch problems that only appear after deployment (`.github/workflows/tests.yml`). Run the same rehearsal locally with `E2E_PRODUCTION=1 python -m pytest tests/e2e`. Dependabot opens pull requests for security and dependency updates weekly (`.github/dependabot.yml`); merge them after the tests pass.

## Production checklist

- Set `APP_ENV=production`, `APP_DEBUG=false`, the real HTTPS `APP_URL`, and `SESSION_SECURE_COOKIE=true`.
- If the app is behind a reverse proxy, set `TRUSTED_PROXIES` to its exact IP address(es) or CIDR ranges. Do not trust arbitrary forwarded headers.
- Keep `.env`, keys, database passwords, and pilot credentials out of GitHub.
- Use a supported PHP runtime and install production dependencies with `composer install --no-dev --optimize-autoloader`.
- Expose only `public/`; use HTTPS and keep MySQL private. Create the least-privilege accounts with `database/least_privilege_users.sql` and run migrations with `--database=mysql_admin`.
- Ensure `storage/` and `bootstrap/cache/` are writable; run `php artisan config:cache`, `php artisan route:cache`, and `php artisan view:cache`.
- Confirm the nightly `backup:run` produces files, rehearse one `backup:restore`, and copy backups off the server to encrypted, access-controlled storage under an approved retention schedule. Reports, account details, GPS data, and attachments are sensitive.
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
