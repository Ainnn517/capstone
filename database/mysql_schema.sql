-- =============================================================================
-- Barangay Issue Reporting and Tracking System - database schema v2
-- For a NEW database. For an existing database use upgrade_existing_database.sql.
-- Target: MySQL 8.0.16+ (CHECK constraints are enforced from this version).
--
-- Matches the Laravel code at commit 86665d5 and fixes these problems found in the audit:
--   * PASSWORD_RESET_TOKENS was missing (forgot-password would crash).
--   * ISSUE_REPORT.tracking_code could be NULL; description was unbounded TEXT.
--   * No CHECK for GPS pairs, resolved-date consistency, username length, or consent pair.
--   * Redundant single-column indexes; no (report_id, changed_at) or (user_id, timestamp)
--     indexes for the timeline and notification lists.
--   * Seed data (categories, teams, recommended teams, street catalog) is now included.
--
-- Rules MySQL cannot express as CHECK (columns with foreign-key actions are not allowed
-- in CHECK) and that stay in the application: Dispatched needs a team, allowed status
-- transitions, archived_at and archived_by set together.
--
-- Time zone: DATETIME defaults use the session time zone. config/database.php already sets
-- the connection to +08:00 (Asia/Manila), so both sides agree.
-- =============================================================================

CREATE DATABASE IF NOT EXISTS barangay_reporting
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;
USE barangay_reporting;
SET NAMES utf8mb4;

-- Resident and administrator accounts.
CREATE TABLE IF NOT EXISTS USERS (
  user_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  username VARCHAR(50) NOT NULL,
  full_name VARCHAR(100) NOT NULL,
  email VARCHAR(120) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('resident', 'admin') NOT NULL DEFAULT 'resident',
  account_status ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
  privacy_consent_at DATETIME NULL,
  privacy_notice_version VARCHAR(50) NULL,
  email_verified_at DATETIME NULL,
  contact_number VARCHAR(30) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id),
  UNIQUE KEY uq_users_username (username),
  UNIQUE KEY uq_users_email (email),
  KEY idx_users_role_status (role, account_status),
  CONSTRAINT chk_users_username_length CHECK (CHAR_LENGTH(username) >= 3),
  CONSTRAINT chk_users_consent_pair CHECK ((privacy_consent_at IS NULL) = (privacy_notice_version IS NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Password reset tokens (config/auth.php uses PASSWORD_RESET_TOKENS).
CREATE TABLE IF NOT EXISTS PASSWORD_RESET_TOKENS (
  email VARCHAR(120) NOT NULL,
  token VARCHAR(255) NOT NULL,
  created_at DATETIME NULL,
  PRIMARY KEY (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Serializes public registrations so the account cap cannot be exceeded.
CREATE TABLE IF NOT EXISTS REGISTRATION_CAPACITY_LOCK (
  id TINYINT UNSIGNED NOT NULL,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Response teams that reports can be assigned to.
CREATE TABLE IF NOT EXISTS TEAM (
  team_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(100) NOT NULL,
  PRIMARY KEY (team_id),
  UNIQUE KEY uq_team_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Report categories, severity weight (1 to 5) and the team the DSS recommends.
CREATE TABLE IF NOT EXISTS CATEGORY (
  category_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(100) NOT NULL,
  description TEXT NULL,
  severity_weight TINYINT UNSIGNED NOT NULL DEFAULT 1,
  recommended_team_id INT UNSIGNED NULL,
  PRIMARY KEY (category_id),
  UNIQUE KEY uq_category_name (name),
  KEY idx_category_recommended_team (recommended_team_id),
  CONSTRAINT chk_category_severity CHECK (severity_weight BETWEEN 1 AND 5),
  CONSTRAINT fk_category_recommended_team FOREIGN KEY (recommended_team_id) REFERENCES TEAM (team_id)
    ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Catalog of streets (name) inside each village or phase (area_name).
-- Areas without a street list use the street name 'Area-wide' (a landmark is then required).
CREATE TABLE IF NOT EXISTS LOCATION (
  location_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(150) NOT NULL,
  area_name VARCHAR(150) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (location_id),
  UNIQUE KEY uq_location_name_area (name, area_name),
  KEY idx_location_area (area_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One row per resident report, holding its current status.
CREATE TABLE IF NOT EXISTS ISSUE_REPORT (
  report_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  tracking_code VARCHAR(23) NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  category_id INT UNSIGNED NOT NULL,
  team_id INT UNSIGNED NULL,
  description VARCHAR(500) NOT NULL,
  location_id INT UNSIGNED NOT NULL,
  landmark VARCHAR(150) NULL,
  latitude DECIMAL(10,7) NULL,
  longitude DECIMAL(11,8) NULL,
  status ENUM('Submitted', 'Validated', 'Dispatched', 'Resolved', 'Rejected') NOT NULL DEFAULT 'Submitted',
  date_submitted DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  date_resolved DATETIME NULL,
  archived_at DATETIME NULL,
  archived_by INT UNSIGNED NULL,
  PRIMARY KEY (report_id),
  UNIQUE KEY uq_report_tracking_code (tracking_code),
  KEY idx_report_user_archived (user_id, archived_at),
  KEY idx_report_archive_status_submitted (archived_at, status, date_submitted),
  KEY idx_report_submitted (date_submitted),
  KEY idx_report_category (category_id),
  KEY idx_report_team (team_id),
  KEY idx_report_location (location_id),
  KEY idx_report_archived_by (archived_by),
  CONSTRAINT chk_report_description CHECK (CHAR_LENGTH(TRIM(description)) BETWEEN 1 AND 500),
  CONSTRAINT chk_report_latitude CHECK (latitude IS NULL OR latitude BETWEEN -90 AND 90),
  CONSTRAINT chk_report_longitude CHECK (longitude IS NULL OR longitude BETWEEN -180 AND 180),
  CONSTRAINT chk_report_gps_pair CHECK ((latitude IS NULL) = (longitude IS NULL)),
  CONSTRAINT chk_report_resolved_date CHECK (status <> 'Resolved' OR date_resolved IS NOT NULL),
  CONSTRAINT chk_report_resolved_only CHECK (status = 'Resolved' OR date_resolved IS NULL),
  CONSTRAINT chk_report_date_order CHECK (date_resolved IS NULL OR date_resolved >= date_submitted),
  CONSTRAINT fk_report_user FOREIGN KEY (user_id) REFERENCES USERS (user_id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_report_category FOREIGN KEY (category_id) REFERENCES CATEGORY (category_id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_report_team FOREIGN KEY (team_id) REFERENCES TEAM (team_id)
    ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT fk_report_location FOREIGN KEY (location_id) REFERENCES LOCATION (location_id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_report_archived_by FOREIGN KEY (archived_by) REFERENCES USERS (user_id)
    ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Every status change of a report (the report timeline).
CREATE TABLE IF NOT EXISTS STATUS_LOG (
  log_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  report_id INT UNSIGNED NOT NULL,
  changed_by INT UNSIGNED NULL,
  old_status ENUM('Submitted', 'Validated', 'Dispatched', 'Resolved', 'Rejected') NULL,
  new_status ENUM('Submitted', 'Validated', 'Dispatched', 'Resolved', 'Rejected') NOT NULL,
  remarks VARCHAR(1000) NULL,
  changed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (log_id),
  KEY idx_log_report_time (report_id, changed_at),
  KEY idx_log_changed_by (changed_by),
  CONSTRAINT fk_log_report FOREIGN KEY (report_id) REFERENCES ISSUE_REPORT (report_id)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_log_changed_by FOREIGN KEY (changed_by) REFERENCES USERS (user_id)
    ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Report photos. file_data holds the image; file_path is only for legacy rows.
CREATE TABLE IF NOT EXISTS ATTACHMENT (
  attachment_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  report_id INT UNSIGNED NOT NULL,
  file_path VARCHAR(255) NULL,
  mime_type VARCHAR(50) NULL,
  file_data MEDIUMBLOB NULL,
  uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (attachment_id),
  KEY idx_attachment_report (report_id),
  CONSTRAINT chk_attachment_storage CHECK (
    (file_data IS NOT NULL AND mime_type IS NOT NULL AND file_path IS NULL)
    OR (file_data IS NULL AND file_path IS NOT NULL)
  ),
  CONSTRAINT fk_attachment_report FOREIGN KEY (report_id) REFERENCES ISSUE_REPORT (report_id)
    ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Updates shown to residents.
CREATE TABLE IF NOT EXISTS NOTIFICATION (
  notif_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  report_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  message VARCHAR(255) NOT NULL,
  type ENUM('submitted', 'assigned', 'status_update', 'resolved') NOT NULL,
  `timestamp` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  is_read TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (notif_id),
  KEY idx_notif_user_read (user_id, is_read),
  KEY idx_notif_user_time (user_id, `timestamp`),
  KEY idx_notif_report (report_id),
  CONSTRAINT chk_notification_is_read CHECK (is_read IN (0, 1)),
  CONSTRAINT fk_notification_report FOREIGN KEY (report_id) REFERENCES ISSUE_REPORT (report_id)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_notification_user FOREIGN KEY (user_id) REFERENCES USERS (user_id)
    ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Saved DSS recommendation snapshots (analytics history).
CREATE TABLE IF NOT EXISTS DSS_ANALYTICS_RECORD (
  snapshot_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  created_by INT UNSIGNED NULL,
  source_report_count INT UNSIGNED NOT NULL DEFAULT 0,
  recommendations JSON NOT NULL,
  generated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (snapshot_id),
  KEY idx_dss_generated_at (generated_at),
  CONSTRAINT fk_dss_created_by FOREIGN KEY (created_by) REFERENCES USERS (user_id)
    ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Audit trail of destructive or privilege-affecting administrator actions.
CREATE TABLE IF NOT EXISTS ADMIN_AUDIT_LOG (
  audit_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  actor_user_id INT UNSIGNED NULL,
  action VARCHAR(100) NOT NULL,
  details JSON NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (audit_id),
  KEY idx_admin_audit_actor_time (actor_user_id, created_at),
  CONSTRAINT fk_admin_audit_actor FOREIGN KEY (actor_user_id) REFERENCES USERS (user_id)
    ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===== SEED DATA (safe to re-run; the Laravel seeder also maintains these rows) =====

INSERT IGNORE INTO REGISTRATION_CAPACITY_LOCK (id) VALUES (1);

INSERT IGNORE INTO TEAM (name) VALUES
  ('Field Team A'),
  ('Field Team B'),
  ('Purok Leader'),
  ('Barangay Safety Team'),
  ('Maintenance & Engineering Crew');

-- Severity 5 is treated as Critical by the DSS.
INSERT IGNORE INTO CATEGORY (name, severity_weight) VALUES
  ('Sanitation', 1),
  ('Clogged Drainage', 3),
  ('Streetlights', 1),
  ('Road Damage', 3),
  ('Public Safety', 5),
  ('Others', 1);

-- Recommended team per category (same mapping as config/dss.php team_by_category).
UPDATE CATEGORY c
JOIN TEAM t ON t.name = CASE c.name
    WHEN 'Sanitation' THEN 'Purok Leader'
    WHEN 'Clogged Drainage' THEN 'Maintenance & Engineering Crew'
    WHEN 'Streetlights' THEN 'Maintenance & Engineering Crew'
    WHEN 'Road Damage' THEN 'Maintenance & Engineering Crew'
    WHEN 'Public Safety' THEN 'Barangay Safety Team'
    WHEN 'Others' THEN 'Field Team A'
    ELSE NULL END
SET c.recommended_team_id = t.team_id
WHERE c.recommended_team_id IS NULL;

-- Village and street catalog from the resident report form (config/locations.php).
INSERT IGNORE INTO LOCATION (name, area_name) VALUES
  ('Laurel Street', 'St. Joseph Village 10 - Phase 1'),
  ('Coconut Street', 'St. Joseph Village 10 - Phase 1'),
  ('Star Apple Street', 'St. Joseph Village 10 - Phase 1'),
  ('Mango Street', 'St. Joseph Village 10 - Phase 1'),
  ('Sandalwood Street', 'St. Joseph Village 10 - Phase 1'),
  ('Chestnut Street', 'St. Joseph Village 10 - Phase 1'),
  ('Apitong Street', 'St. Joseph Village 10 - Phase 2'),
  ('Antipolo Street', 'St. Joseph Village 10 - Phase 2'),
  ('Lawaan Street', 'St. Joseph Village 10 - Phase 2'),
  ('Kamagong Street', 'St. Joseph Village 10 - Phase 2'),
  ('Tindalo Street', 'St. Joseph Village 10 - Phase 2'),
  ('Almond Street', 'St. Joseph Village 10 - Phase 3'),
  ('Birch Street', 'St. Joseph Village 10 - Phase 3'),
  ('Cedar Street', 'St. Joseph Village 10 - Phase 3'),
  ('Mulberry Street', 'St. Joseph Village 10 - Phase 3'),
  ('Cherry Street', 'St. Joseph Village 10 - Phase 3'),
  ('Pine Street', 'St. Joseph Village 10 - Phase 3'),
  ('Walnut Street', 'St. Joseph Village 10 - Phase 3'),
  ('Pear Street', 'St. Joseph Village 10 - Phase 3'),
  ('Cashew Street', 'St. Joseph Village 10 - Phase 3'),
  ('Camachile Street', 'St. Joseph Village 10 - Phase 3'),
  ('Molave Street', 'St. Joseph Village 10 - Phase 3'),
  ('Neem Street', 'St. Joseph Village 10 - Phase 3'),
  ('Maple Street', 'St. Joseph Village 10 - Phase 3'),
  ('Linden Street', 'St. Joseph Village 10 - Phase 3'),
  ('Gumamela Street', 'St. Joseph Village 9'),
  ('Dahua Street', 'St. Joseph Village 9'),
  ('Daffodil Street', 'St. Joseph Village 9'),
  ('Chrysanthemum Street', 'St. Joseph Village 9'),
  ('Carnation Street', 'St. Joseph Village 9'),
  ('Camellia Street', 'St. Joseph Village 9'),
  ('Calendula Street', 'St. Joseph Village 9'),
  ('Bougainvillea Street', 'St. Joseph Village 9'),
  ('Buttercup Street', 'St. Joseph Village 9'),
  ('Aster Street', 'St. Joseph Village 9'),
  ('Everlasting Street', 'St. Joseph Village 9'),
  ('Iris Street', 'St. Joseph Village 9'),
  ('Lotus Street', 'St. Joseph Village 9'),
  ('Lobelia Street', 'St. Joseph Village 9'),
  ('Meadowsweet Street', 'St. Joseph Village 9'),
  ('Magnolia Street', 'St. Joseph Village 9'),
  ('Marigold Street', 'St. Joseph Village 9'),
  ('Sampaguita Street', 'St. Joseph Village 9'),
  ('Speedwell Street', 'St. Joseph Village 9'),
  ('Sunflower Street', 'St. Joseph Village 9'),
  ('Tulip Street', 'St. Joseph Village 9'),
  ('Jade Street', 'St. Joseph Village 9'),
  ('Lavender Street', 'St. Joseph Village 9'),
  ('Lily Street', 'St. Joseph Village 9'),
  ('Rose Street', 'St. Joseph Village 9'),
  ('Orchid Street', 'St. Joseph Village 9'),
  ('Navidad Avenue', 'St. Joseph Village 9'),
  ('R. Magsaysay Avenue', 'St. Joseph Village 9'),
  ('Area-wide', 'St. Joseph Ridgeview - Phase 1'),
  ('Area-wide', 'St. Joseph Ridgeview - Phase 2'),
  ('Area-wide', 'Southern Heights');

-- No user accounts are created here. Create the first administrator with the Laravel
-- seeder (PILOT_ADMIN_* settings); it sets account_status approved and email_verified_at.
