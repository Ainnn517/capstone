-- Least-privilege MySQL accounts for the Barangay Reporting System.
--
-- Run once as the MySQL root/administrator account (for example `sudo mysql < this-file`)
-- after replacing the two passwords and, if different, the database name and host.
--
--   barangay_app    the web application (DB_USERNAME / DB_PASSWORD in .env).
--                   Can only read and change rows; cannot create, alter or drop tables,
--                   so a bug or an attacker using the app cannot change the schema.
--   barangay_owner  migrations, backups and restores (DB_ADMIN_USERNAME / DB_ADMIN_PASSWORD).
--                   Used only from the command line:
--                     php artisan migrate --database=mysql_admin --force
--                     php artisan backup:run
--
-- Use a host of 'localhost' if the app connects through the socket, or the app server's
-- private IP if MySQL runs on another machine. Never use '%' on a public server.

CREATE USER IF NOT EXISTS 'barangay_app'@'127.0.0.1' IDENTIFIED BY 'REPLACE-WITH-A-LONG-RANDOM-PASSWORD';
CREATE USER IF NOT EXISTS 'barangay_owner'@'127.0.0.1' IDENTIFIED BY 'REPLACE-WITH-A-DIFFERENT-LONG-RANDOM-PASSWORD';

-- If barangay_app already exists with broader rights (as in development), remove them first.
REVOKE ALL PRIVILEGES, GRANT OPTION FROM 'barangay_app'@'127.0.0.1';

GRANT SELECT, INSERT, UPDATE, DELETE ON `barangay_reporting`.* TO 'barangay_app'@'127.0.0.1';

-- SHOW VIEW, TRIGGER and EVENT let mysqldump make complete backups; the rest run migrations and restores.
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, DROP, INDEX, REFERENCES,
      CREATE VIEW, SHOW VIEW, TRIGGER, EVENT, LOCK TABLES
   ON `barangay_reporting`.* TO 'barangay_owner'@'127.0.0.1';

FLUSH PRIVILEGES;
