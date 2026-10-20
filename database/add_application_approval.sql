-- Adds HGE application approval tracking to graduate_applications: a row
-- with approved_at IS NULL means filed but still processing; approved_at
-- NOT NULL means approved. Filing itself is unchanged (see
-- includes/graduates.php:file_application()) — approval is additive.
--
-- The project has no migration runner: run this ONCE on an existing
-- database (e.g. in phpMyAdmin, with the hge_masterlist database selected).
-- A fresh install gets these columns/ENUM value from schema.sql.

ALTER TABLE graduate_applications
  ADD COLUMN approved_at DATE NULL AFTER application_date,
  ADD COLUMN approved_by INT UNSIGNED NULL AFTER approved_at,
  ADD CONSTRAINT fk_graduate_applications_approved_by FOREIGN KEY (approved_by) REFERENCES users(id);

ALTER TABLE activity_log
  MODIFY action ENUM('login','logout','pdf_upload','pdf_delete','template_upload','record_created','record_updated','record_removed','record_restored','record_purged','record_draft_saved','search','user_created','user_updated','user_removed','school_created','school_updated','school_removed','application_filed','application_approved','db_backup','ocr_keyword_created','ocr_keyword_removed','ai_settings_updated') NOT NULL;
