USE barangay_reporting;

-- Create a lookup table for report assignment teams.
CREATE TABLE TEAM (
  team_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(100) NOT NULL,
  PRIMARY KEY (team_id),
  UNIQUE KEY uq_team_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add the teams used by the application.
INSERT INTO TEAM (name) VALUES
  ('Field Team A'),
  ('Field Team B'),
  ('Purok Leader'),
  ('Barangay Safety Team'),
  ('Maintenance & Engineering Crew');

-- Set each category's severity weight.
UPDATE CATEGORY
SET severity_weight = CASE name
  WHEN 'Public Safety' THEN 5
  WHEN 'Road Damage' THEN 3
  WHEN 'Clogged Drainage' THEN 3
  ELSE 1
END;

-- Link reports to teams before removing old assignment fields.
ALTER TABLE ISSUE_REPORT
  ADD COLUMN team_id INT UNSIGNED NULL AFTER category_id;

-- Copy existing team names into the new team relationship.
UPDATE ISSUE_REPORT r
JOIN TEAM t ON t.name = r.assigned_team
SET r.team_id = t.team_id;

-- Preserve old dispatch notes in the status history.
INSERT INTO STATUS_LOG (report_id, changed_by, old_status, new_status, remarks)
SELECT r.report_id, NULL, NULL, r.status, r.dispatch_note
FROM ISSUE_REPORT r
WHERE r.dispatch_note IS NOT NULL
  AND NOT EXISTS (
    SELECT 1
    FROM STATUS_LOG s
    WHERE s.report_id = r.report_id
      AND s.remarks = r.dispatch_note
  );

-- Remove old fields after their data has been migrated.
ALTER TABLE ISSUE_REPORT
  DROP FOREIGN KEY fk_report_assigned,
  DROP INDEX idx_report_assigned,
  DROP COLUMN assigned_to,
  DROP COLUMN assigned_team,
  DROP COLUMN dispatch_note,
  DROP COLUMN priority_level,
  DROP COLUMN dss_score,
  ADD KEY idx_report_team (team_id),
  ADD CONSTRAINT fk_report_team FOREIGN KEY (team_id) REFERENCES TEAM (team_id)
    ON UPDATE CASCADE ON DELETE SET NULL;

-- Require each attachment to use either a file path or image data.
ALTER TABLE ATTACHMENT
  ADD CONSTRAINT chk_attachment_storage CHECK (
    (file_data IS NOT NULL AND mime_type IS NOT NULL AND file_path IS NULL)
    OR (file_data IS NULL AND file_path IS NOT NULL)
  );

-- Remove the retired analytics table.
DROP TABLE IF EXISTS ANALYTICS_RECORD;
