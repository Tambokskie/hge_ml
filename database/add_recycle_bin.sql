-- Run once on the live hge_masterlist database (no migration runner in this project).
CREATE TABLE IF NOT EXISTS graduate_recycle_bin (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  original_id INT UNSIGNED NOT NULL,
  upload_id INT UNSIGNED NOT NULL,
  school_id INT UNSIGNED NOT NULL,
  graduate_name VARCHAR(255) NOT NULL,
  course VARCHAR(255) NOT NULL DEFAULT '',
  latin_honor ENUM('CUM LAUDE','MAGNA CUM LAUDE','SUMMA CUM LAUDE') NOT NULL,
  graduation_date DATE NULL,
  graduation_year YEAR NOT NULL,
  source ENUM('ai','ocr','text','manual') NULL DEFAULT NULL,
  original_created_at TIMESTAMP NULL DEFAULT NULL,
  deleted_by INT UNSIGNED NULL,
  deleted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  deleted_reason VARCHAR(500) NOT NULL DEFAULT '',
  deleted_via ENUM('manual','duplicate_cleanup','review_edit') NOT NULL DEFAULT 'manual',
  FOREIGN KEY (deleted_by) REFERENCES users(id),
  KEY idx_deleted_at (deleted_at),
  KEY idx_original_id (original_id)
) ENGINE=InnoDB;

-- Allow the two new activity-log actions (re-running is harmless).
ALTER TABLE activity_log MODIFY action ENUM('login','logout','pdf_upload','pdf_delete','template_upload','record_created','record_updated','record_removed','record_restored','record_purged','record_draft_saved','search','user_created','user_updated','user_removed','school_created','school_updated','school_removed','application_filed','db_backup','ocr_keyword_created','ocr_keyword_removed','ai_settings_updated') NOT NULL;
