USE barangay_reporting;

ALTER TABLE USERS
  MODIFY account_status ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
  ADD COLUMN privacy_consent_at DATETIME NULL,
  ADD COLUMN privacy_notice_version VARCHAR(50) NULL,
  ADD COLUMN email_verified_at DATETIME NULL;

ALTER TABLE ISSUE_REPORT
  ADD KEY idx_report_user_archived (user_id, archived_at),
  ADD KEY idx_report_archive_status_submitted (archived_at, status, date_submitted);

ALTER TABLE LOCATION
  ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 0;

ALTER TABLE CATEGORY
  ADD COLUMN recommended_team_id INT UNSIGNED NULL,
  ADD KEY idx_category_recommended_team (recommended_team_id),
  ADD CONSTRAINT fk_category_recommended_team FOREIGN KEY (recommended_team_id) REFERENCES TEAM (team_id)
    ON UPDATE CASCADE ON DELETE RESTRICT;

CREATE TABLE IF NOT EXISTS PASSWORD_RESET_TOKENS (
  email VARCHAR(120) NOT NULL,
  token VARCHAR(255) NOT NULL,
  created_at DATETIME NULL,
  PRIMARY KEY (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
