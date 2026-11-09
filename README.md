# Barangay Reporting System

The application uses Laravel 12, PHP 8.2 or newer, and MySQL. The existing HTML, CSS, and JavaScript screens are served through Laravel, and the API, authentication, report workflows, image attachments, and database access use Laravel controllers and Eloquent models. Database structure is managed with the manual SQL schema in `database/mysql_schema.sql`.

## Set up locally

1. Install PHP 8.2+ with the `pdo_mysql`, `fileinfo`, `mbstring`, and `xml` extensions (the XML package should include DOM), plus Composer.
2. Create the MySQL database and import `database/mysql_schema.sql` into it. The script creates the `barangay_reporting` database and its tables, including the default categories and teams.
3. From the project root, copy `.env.example` to `.env` and set `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, and `DB_PASSWORD` to match your MySQL server. The database defaults to `barangay_reporting`. To enable the resident Google Maps location picker, set `GOOGLE_MAPS_API_KEY` to a key with the Maps JavaScript API enabled.
4. Install dependencies, generate an application key, and seed any optional pilot accounts:

   ```sh
   composer install
   php artisan key:generate
   php artisan db:seed
   ```

   The Composer `setup` script also runs the seeder and assumes the SQL schema has already been imported.

Account registration and administrator approval/rejection updates are emailed to the resident. Rejections require an administrator-entered reason. The example environment uses Laravel's `log` mailer, which does not deliver to inboxes. Gmail SMTP can send from a Gmail account without owning a custom domain. Enable Google 2-Step Verification and create an App Password, then in the private `.env` or hosting-provider secret settings set `MAIL_MAILER=smtp`, `MAIL_HOST=smtp.gmail.com`, `MAIL_PORT=587`, `MAIL_USERNAME` to the full Gmail address, `MAIL_PASSWORD` to the Google App Password, and `MAIL_FROM_ADDRESS` to the same Gmail address. Keep `MAIL_SCHEME=null` for SMTP STARTTLS on port 587. Do not use the normal Google account password, and never commit or share the App Password. Gmail has sending limits and is best for a small project or demo; for production bulk mail, use a transactional provider and a domain you control. After changing mail settings, run `php artisan config:clear` or restart the application if configuration is not cached. The app reports email delivery as not configured until valid SMTP credentials and a non-placeholder sender are present.

5. Start Laravel and open the URL it prints:

   ```sh
   php artisan serve
   ```

Optional pilot admin/resident accounts can be provisioned by setting the matching `PILOT_ADMIN_*` and `PILOT_RESIDENT_*` variables in `.env` before running `php artisan db:seed`. Use strong, private passwords. Registration is available from the login screen; new resident accounts remain pending until an administrator approves them from the Residents page.

The map picker uses the Google Maps JavaScript API and requires a Google Maps API key with billing enabled. Restrict the key to the Maps JavaScript API and to the application's allowed HTTP referrers. Keep the key in `.env` or your hosting provider's secret configuration; never commit it. After changing the key in a deployment with cached configuration, refresh the Laravel configuration cache.

## Production deployment checklist

The local `.env.example` is for development. Do not copy its `APP_DEBUG=true` setting into a public deployment. Configure `APP_ENV=production`, `APP_DEBUG=false`, `SESSION_SECURE_COOKIE=true`, and `APP_URL` set to the actual HTTPS address supplied by your hosting provider in that provider's private environment/secrets manager. A custom domain is optional; do not enter a placeholder URL as a real setting.

Set production MySQL credentials and a strong `APP_KEY` privately. Generate the key once for a new installation (`php artisan key:generate`) and preserve it for subsequent deployments. Never commit or share `.env`, keys, database passwords, or pilot-account passwords.

Before launch:

1. Use a supported PHP version and install the required extensions listed above.
2. Configure the web server document root to the project's `public/` directory only. Do not expose the repository root or use `php artisan serve` for public hosting.
3. Use HTTPS, keep the database inaccessible from the public internet, and give the application database account only the permissions it needs.
4. Install production dependencies with `composer install --no-dev --optimize-autoloader`.
5. Create a new database from `database/mysql_schema.sql` before deployment. For schema changes to an existing database, take a backup, review and apply explicit SQL changes, and verify the resulting schema before deploying the application.
6. Ensure `storage/` and `bootstrap/cache/` are writable by the application process, then run `php artisan config:cache`, `php artisan route:cache`, and `php artisan view:cache`.
7. Leave pilot-account variables unset unless those accounts are explicitly needed, and use unique strong passwords if enabled.
8. Verify the HTTPS site, login, registration, report submission, image access, administrator actions, and logout on the deployed host.

## Resident privacy and data operations

The `/privacy` page describes the account and report data collected, its use, administrator access, and retention. Reports are retained in the live system until an administrator permanently deletes an archived report. The barangay should add its approved contact details and review the notice with its responsible privacy officer before accepting real resident data.

Set up automated encrypted database backups before launch. The database contains report descriptions, account details, location/GPS information, and image attachments, so a database dump includes sensitive data. Store backups outside the web root and away from the application host, restrict access, and test restoring them periodically. A practical starting schedule is a daily backup with a 30-day rolling backup-retention window; permanently deleted data may remain in those backups until the window expires. Document who is responsible for monitoring backup success and recovery.

Administrators can archive an individual unresolved or rejected report from its active-list row, or use "Archive Incidents" to archive all unresolved and rejected reports at once. Archived reports can be restored individually or permanently deleted (including their attachments and status history) from the archive view.

## API authentication and authorization

The API uses Laravel's session guard and the `web` middleware group, including session cookies and CSRF protection for state-changing requests. Public account routes are `POST /api/login` (limited to 10 requests per minute per IP and three failed attempts per minute per account) and `POST /api/register` (limited to five requests per minute per IP); registration always creates a pending resident account, which cannot sign in or access protected APIs until an administrator approves it. Signup success and valid-address signup failures include an email update when a delivery transport is configured. Administrators can approve or reject pending accounts from the Residents page; rejection requires a reason, and approval/rejection updates are emailed when delivery is configured. Rejected accounts cannot sign in, and an administrator can later approve them. Registration is closed once the database contains 9,000 accounts. Registration requests are serialized using a database lock so concurrent requests cannot exceed the limit. Protected API routes reject missing or unapproved sessions with `401`. The example mailer is `log`, so real inbox delivery requires private mail-provider configuration as described above. Session cookies are HttpOnly and SameSite=Lax; set `SESSION_SECURE_COOKIE=true` when deploying behind HTTPS (it defaults on when `APP_ENV=production`).

Resident report reads are limited to the signed-in resident, and attachment reads check the owning report. Residents can submit reports but cannot set owner, status, or assignment fields. Only admins can list residents, update report status/assignment, archive unresolved or rejected reports, review archived reports, or permanently delete an archived report. Archived reports and their attachments are hidden from resident lists and attachment access. This deployment is intended for one barangay, so admin report access is system-wide within that barangay.

Run the authentication and authorization feature tests with `php artisan test` (or `composer test`). Tests use MySQL and the separate `barangay_reporting_test` database configured in `phpunit.xml`; the test harness drops and recreates its application tables, so never point it at the live `barangay_reporting` database. Create the test database and grant the configured application account access using a MySQL administrator account:

```sql
CREATE DATABASE IF NOT EXISTS barangay_reporting_test
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;
GRANT ALL PRIVILEGES ON barangay_reporting_test.* TO 'barangay_app'@'127.0.0.1';
```

If your `.env` uses a different MySQL account or host, adjust the `GRANT` target accordingly. The test database schema is created by the test harness; it does not need the production schema imported.

## Existing MySQL database

The application does not automatically create or update database tables. For a new database, use `database/mysql_schema.sql`. For an existing database, back it up and compare its schema with that SQL file and the application models before making any changes. In particular, registration requires `USERS.username VARCHAR(50)`, `USERS.full_name VARCHAR(100)`, and `USERS.email VARCHAR(120)`, plus the `REGISTRATION_CAPACITY_LOCK` row with `id = 1`. An existing installation with narrower account columns can widen them with `ALTER TABLE USERS MODIFY username VARCHAR(50) NOT NULL, MODIFY full_name VARCHAR(100) NOT NULL, MODIFY email VARCHAR(120) NOT NULL;`. Ensure the lock row exists with `INSERT IGNORE INTO REGISTRATION_CAPACITY_LOCK (id) VALUES (1);`. The scripts `database/migrate_attachment_blobs.sql` and `database/migrate_normalized_schema.sql` are one-time legacy conversion scripts; inspect their statements and the target schema carefully before using them, because they are not general-purpose or safe to rerun.

The old `config.local.php` is not loaded by Laravel. Transfer its database settings to your local `.env`; do not commit `.env` or share its credentials. The Laravel seeder imports pilot accounts only when you configure their `PILOT_*` values.

## Project structure

- `app/Http/Controllers/`: API request handling, split between authentication, reports, residents, and attachments.
- `app/Http/Middleware/`: session authentication and role checks for protected API routes.
- `app/Models/`: Eloquent models mapped to the existing uppercase MySQL tables.
- `routes/web.php`: browser pages and session-backed API routes.
- `resources/views/pages/`: Blade versions of the existing screens, including CSRF tokens.
- `public/frontend/` and `public/images/`: the single source of static frontend assets (no duplicate frontend tree).
- `database/mysql_schema.sql`: manual schema creation for a new MySQL database.
- `database/seeders/`: default data and optional pilot accounts.

Do not use Laravel's development server or this pilot configuration for public production hosting.
