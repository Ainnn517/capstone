USE barangay_reporting;

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
