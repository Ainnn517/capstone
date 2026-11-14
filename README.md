# Barangay Reporting System

A Laravel 12, PHP 8.2+, and MySQL application for residents to submit and track barangay reports and for administrators to coordinate status, assignments, notifications, and response prioritization.

## Local setup

1. Install PHP 8.2+ with `pdo_mysql`, `fileinfo`, `mbstring`, and XML/DOM, plus Composer and MySQL.
2. Create an empty MySQL database (`CREATE DATABASE barangay_reporting CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci`). After configuring `.env` (step 3), build the tables with `php artisan migrate`. Existing databases can run `php artisan migrate` too (back up first); very old ones need [`database/upgrade_existing_database.sql`](./database/upgrade_existing_database.sql) first. See [DEPLOYMENT.md](./DEPLOYMENT.md).
3. Copy `.env.example` to `.env` and set your private database credentials. Configure `GOOGLE_MAPS_API_KEY` if you want to enable map selection.
4. Install dependencies, generate the app key, and seed the reference data (plus pilot accounts outside production; use `php artisan admin:create` for a production administrator):

   ```sh
   composer install
   php artisan key:generate
   php artisan migrate
   php artisan db:seed
   ```

5. Start the local application with `php artisan serve`.

The schema is managed with Laravel migrations (`database/migrations`); `database/mysql_schema.sql` is kept identical for manual imports. Seeding after schema setup adds the configured categories, teams, and active approved locations; for existing data, it also reactivates configured locations while keeping unrecognized legacy locations inactive.

## Main capabilities

- Session-based authentication, role checks, CSRF protection, login rate limiting, and admin approval for resident accounts.
- Resident report submission and status tracking, with ownership-checked attachments and notifications.
- Admin report processing, team assignment, archive/restore workflows, and paginated lists.
- Backend DSS scoring with visible score breakdowns, category team suggestions, and saved snapshot history.
- Authorization, report validation, notification, DSS, and account workflow tests.

The DSS is a configurable heuristic, not a prediction model. Its exact formula, configuration, and limitations are in [`docs/DSS-METHODOLOGY.md`](./docs/DSS-METHODOLOGY.md).

## Documentation

- [API reference](./API.md)
- [Deployment, mail, backups, and testing](./DEPLOYMENT.md)
- [Privacy notice](./resources/views/pages/privacy.blade.php)

Never commit `.env`, passwords, API keys, or local configuration. For public deployment, expose only `public/`, use HTTPS, and review the deployment and privacy checklists first.
