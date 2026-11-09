USE barangay_reporting;

-- Add columns for storing an uploaded image and its MIME type.
ALTER TABLE ATTACHMENT
  MODIFY file_path VARCHAR(255) NULL,
  ADD COLUMN mime_type VARCHAR(50) NULL AFTER file_path,
  ADD COLUMN file_data MEDIUMBLOB NULL AFTER mime_type;
