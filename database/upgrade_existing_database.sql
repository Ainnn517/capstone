-- =============================================================================
-- Upgrade an EXISTING Barangay Reporting database to schema v2 (see mysql_schema_v2.sql).
-- Safe to run more than once. It works whether or not migrate_security_hardening.sql was
-- already applied. BACK UP THE DATABASE FIRST (mysqldump barangay_reporting > backup.sql).
--
-- What it does:
--   * Adds missing columns, indexes, CHECK constraints and foreign keys (only if absent).
--   * Creates PASSWORD_RESET_TOKENS (needed by forgot-password).
--   * Marks existing APPROVED accounts as email-verified, because login now requires a
--     verified email and these users registered before verification existed.
--   * Fixes LOCATION.is_active (default 1) and re-activates locations if every one was
--     switched off by the old hardening script.
--   * Fills missing tracking codes, makes tracking_code NOT NULL, limits description to 500.
--   * Seeds categories, teams, recommended teams and the village/street catalog (INSERT IGNORE).
--
-- Requires MySQL 8.0.16+ or MariaDB 10.2.1+ (CHECK constraints). Uses no stored procedures
-- and no DELIMITER, so it runs in the mysql client and in phpMyAdmin.
-- =============================================================================

USE barangay_reporting;
SET NAMES utf8mb4;

-- ---- Preflight: every count below should be 0. If not, fix those rows first. ----
SELECT COUNT(*) AS usernames_shorter_than_3 FROM USERS WHERE CHAR_LENGTH(username) < 3;
SELECT COUNT(*) AS descriptions_over_500 FROM ISSUE_REPORT WHERE CHAR_LENGTH(description) > 500;
SELECT COUNT(*) AS empty_descriptions FROM ISSUE_REPORT WHERE CHAR_LENGTH(TRIM(description)) < 1;
SELECT COUNT(*) AS reports_with_only_one_gps_value FROM ISSUE_REPORT WHERE (latitude IS NULL) <> (longitude IS NULL);
SELECT COUNT(*) AS non_resolved_with_resolved_date FROM ISSUE_REPORT WHERE status <> 'Resolved' AND date_resolved IS NOT NULL;

-- ============================ USERS ============================
SET @sql := (SELECT IF(COUNT(*) = 0, 'ALTER TABLE USERS ADD COLUMN privacy_consent_at DATETIME NULL', 'DO 0') FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'USERS' AND column_name = 'privacy_consent_at');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql := (SELECT IF(COUNT(*) = 0, 'ALTER TABLE USERS ADD COLUMN privacy_notice_version VARCHAR(50) NULL', 'DO 0') FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'USERS' AND column_name = 'privacy_notice_version');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql := (SELECT IF(COUNT(*) = 0, 'ALTER TABLE USERS ADD COLUMN email_verified_at DATETIME NULL', 'DO 0') FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'USERS' AND column_name = 'email_verified_at');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
-- New accounts must wait for admin approval (fail-closed default).
ALTER TABLE USERS MODIFY account_status ENUM('pending', 'approved', 'rejected', 'suspended') NOT NULL DEFAULT 'pending';
ALTER TABLE USERS MODIFY contact_number VARCHAR(30) NULL;

-- Existing approved accounts predate email verification, so treat them as verified.
UPDATE USERS SET email_verified_at = NOW() WHERE account_status = 'approved' AND email_verified_at IS NULL;

SET @sql := (SELECT IF(COUNT(*) = 0, 'ALTER TABLE USERS ADD KEY idx_users_role_status (role, account_status)', 'DO 0') FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'USERS' AND index_name = 'idx_users_role_status');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql := (SELECT IF(COUNT(*) = 0, 'ALTER TABLE USERS ADD CONSTRAINT chk_users_username_length CHECK (CHAR_LENGTH(username) >= 3)', 'DO 0') FROM information_schema.table_constraints WHERE table_schema = DATABASE() AND table_name = 'USERS' AND constraint_name = 'chk_users_username_length' AND constraint_type = 'CHECK');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql := (SELECT IF(COUNT(*) = 0, 'ALTER TABLE USERS ADD CONSTRAINT chk_users_consent_pair CHECK ((privacy_consent_at IS NULL) = (privacy_notice_version IS NULL))', 'DO 0') FROM information_schema.table_constraints WHERE table_schema = DATABASE() AND table_name = 'USERS' AND constraint_name = 'chk_users_consent_pair' AND constraint_type = 'CHECK');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
-- ===================== PASSWORD_RESET_TOKENS ====================
CREATE TABLE IF NOT EXISTS PASSWORD_RESET_TOKENS (
  email VARCHAR(120) NOT NULL,
  token VARCHAR(255) NOT NULL,
  created_at DATETIME NULL,
  PRIMARY KEY (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ======================== TEAM and CATEGORY =====================
-- Match the app's 100-character validation (older databases used VARCHAR(50)).
ALTER TABLE TEAM MODIFY name VARCHAR(100) NOT NULL;

INSERT IGNORE INTO TEAM (name) VALUES
  ('Field Team A'),
  ('Field Team B'),
  ('Purok Leader'),
  ('Barangay Safety Team'),
  ('Maintenance & Engineering Crew');

INSERT IGNORE INTO CATEGORY (name, severity_weight) VALUES
  ('Sanitation', 1),
  ('Clogged Drainage', 3),
  ('Streetlights', 1),
  ('Road Damage', 3),
  ('Public Safety', 5),
  ('Others', 1);

SET @sql := (SELECT IF(COUNT(*) = 0, 'ALTER TABLE CATEGORY ADD COLUMN recommended_team_id INT UNSIGNED NULL', 'DO 0') FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'CATEGORY' AND column_name = 'recommended_team_id');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql := (SELECT IF(COUNT(*) = 0, 'ALTER TABLE CATEGORY ADD KEY idx_category_recommended_team (recommended_team_id)', 'DO 0') FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'CATEGORY' AND index_name = 'idx_category_recommended_team');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql := (SELECT IF(COUNT(*) = 0, 'ALTER TABLE CATEGORY ADD CONSTRAINT fk_category_recommended_team FOREIGN KEY (recommended_team_id) REFERENCES TEAM (team_id) ON UPDATE CASCADE ON DELETE RESTRICT', 'DO 0') FROM information_schema.table_constraints WHERE table_schema = DATABASE() AND table_name = 'CATEGORY' AND constraint_name = 'fk_category_recommended_team' AND constraint_type = 'FOREIGN KEY');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
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

-- =========================== LOCATION ==========================
SET @sql := (SELECT IF(COUNT(*) = 0, 'ALTER TABLE LOCATION ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1', 'DO 0') FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'LOCATION' AND column_name = 'is_active');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
ALTER TABLE LOCATION MODIFY is_active TINYINT(1) NOT NULL DEFAULT 1;
-- Match the app's 150-character validation (older databases used VARCHAR(100)).
ALTER TABLE LOCATION MODIFY name VARCHAR(150) NOT NULL, MODIFY area_name VARCHAR(150) NOT NULL;

-- Only re-activate when NO location is active (the state the old hardening script leaves behind).
UPDATE LOCATION SET is_active = 1 WHERE (SELECT n FROM (SELECT COUNT(*) AS n FROM LOCATION WHERE is_active = 1) active_count) = 0;

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

-- ========================== ISSUE_REPORT =========================
-- Give old reports without a tracking code one (same BR-yymmdd-HHMMSS-XXXXXX shape, 23 chars).
UPDATE ISSUE_REPORT
SET tracking_code = CONCAT('BR-', DATE_FORMAT(date_submitted, '%y%m%d-%H%i%s'), '-', UPPER(SUBSTRING(MD5(CONCAT(report_id, RAND())), 1, 6)))
WHERE tracking_code IS NULL;

ALTER TABLE ISSUE_REPORT MODIFY tracking_code VARCHAR(23) NOT NULL;
ALTER TABLE ISSUE_REPORT MODIFY description VARCHAR(500) NOT NULL;
-- Match the app's 150-character landmark validation (older databases used VARCHAR(100)).
ALTER TABLE ISSUE_REPORT MODIFY landmark VARCHAR(150) NULL;

SET @sql := (SELECT IF(COUNT(*) = 0, 'ALTER TABLE ISSUE_REPORT ADD KEY idx_report_user_archived (user_id, archived_at)', 'DO 0') FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'ISSUE_REPORT' AND index_name = 'idx_report_user_archived');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql := (SELECT IF(COUNT(*) = 0, 'ALTER TABLE ISSUE_REPORT ADD KEY idx_report_archive_status_submitted (archived_at, status, date_submitted)', 'DO 0') FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'ISSUE_REPORT' AND index_name = 'idx_report_archive_status_submitted');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql := (SELECT IF(COUNT(*) = 0, 'ALTER TABLE ISSUE_REPORT ADD KEY idx_report_archived_by (archived_by)', 'DO 0') FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'ISSUE_REPORT' AND index_name = 'idx_report_archived_by');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
-- Drop the auto-created duplicate of idx_report_archived_by left by older schemas.
SET @sql := (SELECT IF(COUNT(*) > 0, 'ALTER TABLE ISSUE_REPORT DROP INDEX fk_report_archived_by', 'DO 0') FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'ISSUE_REPORT' AND index_name = 'fk_report_archived_by');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql := (SELECT IF(COUNT(*) = 0, 'ALTER TABLE ISSUE_REPORT ADD CONSTRAINT chk_report_description CHECK (CHAR_LENGTH(TRIM(description)) BETWEEN 1 AND 500)', 'DO 0') FROM information_schema.table_constraints WHERE table_schema = DATABASE() AND table_name = 'ISSUE_REPORT' AND constraint_name = 'chk_report_description' AND constraint_type = 'CHECK');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql := (SELECT IF(COUNT(*) = 0, 'ALTER TABLE ISSUE_REPORT ADD CONSTRAINT chk_report_gps_pair CHECK ((latitude IS NULL) = (longitude IS NULL))', 'DO 0') FROM information_schema.table_constraints WHERE table_schema = DATABASE() AND table_name = 'ISSUE_REPORT' AND constraint_name = 'chk_report_gps_pair' AND constraint_type = 'CHECK');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql := (SELECT IF(COUNT(*) = 0, 'ALTER TABLE ISSUE_REPORT ADD CONSTRAINT chk_report_resolved_only CHECK (status = ''Resolved'' OR date_resolved IS NULL)', 'DO 0') FROM information_schema.table_constraints WHERE table_schema = DATABASE() AND table_name = 'ISSUE_REPORT' AND constraint_name = 'chk_report_resolved_only' AND constraint_type = 'CHECK');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
-- Remove single-column indexes that the composite indexes above already cover.
SET @sql := (SELECT IF(COUNT(*) > 0, 'ALTER TABLE ISSUE_REPORT DROP INDEX idx_report_user', 'DO 0') FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'ISSUE_REPORT' AND index_name = 'idx_report_user');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql := (SELECT IF(COUNT(*) > 0, 'ALTER TABLE ISSUE_REPORT DROP INDEX idx_report_status', 'DO 0') FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'ISSUE_REPORT' AND index_name = 'idx_report_status');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql := (SELECT IF(COUNT(*) > 0, 'ALTER TABLE ISSUE_REPORT DROP INDEX idx_report_archived_at', 'DO 0') FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'ISSUE_REPORT' AND index_name = 'idx_report_archived_at');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
-- ====================== STATUS_LOG / NOTIFICATION ======================
SET @sql := (SELECT IF(COUNT(*) = 0, 'ALTER TABLE STATUS_LOG ADD KEY idx_log_report_time (report_id, changed_at)', 'DO 0') FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'STATUS_LOG' AND index_name = 'idx_log_report_time');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql := (SELECT IF(COUNT(*) > 0, 'ALTER TABLE STATUS_LOG DROP INDEX idx_log_report', 'DO 0') FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'STATUS_LOG' AND index_name = 'idx_log_report');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql := (SELECT IF(COUNT(*) = 0, 'ALTER TABLE NOTIFICATION ADD KEY idx_notif_user_time (user_id, `timestamp`)', 'DO 0') FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'NOTIFICATION' AND index_name = 'idx_notif_user_time');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
-- ============================== Done ==============================
SELECT 'Upgrade to schema v2 finished.' AS result;
