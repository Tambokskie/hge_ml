-- Adds 'application_reverted' to activity_log.action for the lookup.php
-- revert button (undoes an approval, or an unapproved filing entirely —
-- see includes/graduates.php:revert_application()).
--
-- The project has no migration runner: run this ONCE on an existing
-- database (e.g. in phpMyAdmin, with the hge_masterlist database selected).
-- A fresh install gets this ENUM value from schema.sql.

ALTER TABLE activity_log
  MODIFY action ENUM('login','logout','pdf_upload','pdf_delete','template_upload','record_created','record_updated','record_removed','record_restored','record_purged','record_draft_saved','search','user_created','user_updated','user_removed','school_created','school_updated','school_removed','application_filed','application_approved','application_reverted','db_backup','ocr_keyword_created','ocr_keyword_removed','ai_settings_updated') NOT NULL;
