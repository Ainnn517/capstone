CREATE DATABASE IF NOT EXISTS barangay_reporting
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;
USE barangay_reporting;

-- Store resident and admin accounts.
CREATE TABLE IF NOT EXISTS USERS (
  user_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  username VARCHAR(50) NOT NULL,
  full_name VARCHAR(100) NOT NULL,
  email VARCHAR(120) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('resident', 'admin') NOT NULL DEFAULT 'resident',
  account_status ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'approved',
  contact_number VARCHAR(30) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id),
  UNIQUE KEY uq_users_username (username),
  UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Serialize public registrations so the configured account cap cannot be exceeded.
CREATE TABLE IF NOT EXISTS REGISTRATION_CAPACITY_LOCK (
  id TINYINT UNSIGNED NOT NULL,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO REGISTRATION_CAPACITY_LOCK (id) VALUES (1);

-- Store report categories and their severity weights.
CREATE TABLE IF NOT EXISTS CATEGORY (
  category_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(100) NOT NULL,
  description TEXT NULL,
  severity_weight TINYINT UNSIGNED NOT NULL DEFAULT 1,
  PRIMARY KEY (category_id),
  UNIQUE KEY uq_category_name (name),
  CONSTRAINT chk_category_severity CHECK (severity_weight BETWEEN 1 AND 5)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Store teams that can receive report assignments.
CREATE TABLE IF NOT EXISTS TEAM (
  team_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(100) NOT NULL,
  PRIMARY KEY (team_id),
  UNIQUE KEY uq_team_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Store streets and areas where reports happen.
CREATE TABLE IF NOT EXISTS LOCATION (
  location_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(150) NOT NULL,
  area_name VARCHAR(150) NOT NULL,
  PRIMARY KEY (location_id),
  UNIQUE KEY uq_location_name_area (name, area_name),
  KEY idx_location_area (area_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Store report details and current status.
CREATE TABLE IF NOT EXISTS ISSUE_REPORT (
  report_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  tracking_code VARCHAR(23) NULL,
  user_id INT UNSIGNED NOT NULL,
  category_id INT UNSIGNED NOT NULL,
  team_id INT UNSIGNED NULL,
  description TEXT NOT NULL,
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
  KEY idx_report_user (user_id),
  KEY idx_report_category (category_id),
  KEY idx_report_team (team_id),
  KEY idx_report_location (location_id),
  KEY idx_report_status (status),
  KEY idx_report_submitted (date_submitted),
  KEY idx_report_archived_at (archived_at),
  CONSTRAINT chk_report_latitude CHECK (latitude IS NULL OR latitude BETWEEN -90 AND 90),
  CONSTRAINT chk_report_longitude CHECK (longitude IS NULL OR longitude BETWEEN -180 AND 180),
  CONSTRAINT chk_report_resolved_date CHECK (status <> 'Resolved' OR date_resolved IS NOT NULL),
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

-- Record each report status change.
CREATE TABLE IF NOT EXISTS STATUS_LOG (
  log_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  report_id INT UNSIGNED NOT NULL,
  changed_by INT UNSIGNED NULL,
  old_status ENUM('Submitted', 'Validated', 'Dispatched', 'Resolved', 'Rejected') NULL,
  new_status ENUM('Submitted', 'Validated', 'Dispatched', 'Resolved', 'Rejected') NOT NULL,
  remarks VARCHAR(1000) NULL,
  changed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (log_id),
  KEY idx_log_report (report_id),
  KEY idx_log_changed_by (changed_by),
  CONSTRAINT fk_log_report FOREIGN KEY (report_id) REFERENCES ISSUE_REPORT (report_id)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_log_changed_by FOREIGN KEY (changed_by) REFERENCES USERS (user_id)
    ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Store report images and legacy file paths.
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

-- Store updates sent to residents.
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
  KEY idx_notif_report (report_id),
  CONSTRAINT chk_notification_is_read CHECK (is_read IN (0, 1)),
  CONSTRAINT fk_notification_report FOREIGN KEY (report_id) REFERENCES ISSUE_REPORT (report_id)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_notification_user FOREIGN KEY (user_id) REFERENCES USERS (user_id)
    ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add the default report categories.
INSERT IGNORE INTO CATEGORY (name, severity_weight) VALUES
  ('Sanitation', 1),
  ('Clogged Drainage', 3),
  ('Streetlights', 1),
  ('Road Damage', 3),
  ('Public Safety', 5),
  ('Others', 1);

-- Add the default response teams.
INSERT IGNORE INTO TEAM (name) VALUES
  ('Field Team A'),
  ('Field Team B'),
  ('Purok Leader'),
  ('Barangay Safety Team'),
  ('Maintenance & Engineering Crew');
