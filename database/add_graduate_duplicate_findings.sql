-- Run once on a live database (there is no migration runner):
--   mysql -u root hge_masterlist < database/add_graduate_duplicate_findings.sql
-- One row for every duplicate an administrator CONFIRMED during a review (SAVE RECORDS prompt): the
-- later, duplicate record is removed from the review, and what was found about it is kept here against
-- the saved ORIGINAL record, which shows a "DUPLICATE FOUND" badge in Look-Up. No foreign keys on
-- purpose: removing, moving or restoring the original must never be blocked by (or lose) its findings.
CREATE TABLE IF NOT EXISTS graduate_duplicate_findings (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  original_graduate_id INT UNSIGNED NOT NULL,
  upload_id INT UNSIGNED NULL,
  duplicate_name VARCHAR(255) NOT NULL DEFAULT '',
  school VARCHAR(255) NOT NULL DEFAULT '',
  course VARCHAR(255) NOT NULL DEFAULT '',
  latin_honor VARCHAR(30) NOT NULL DEFAULT '',
  graduation_year YEAR NULL,
  graduation_date DATE NULL,
  source_filename VARCHAR(255) NOT NULL DEFAULT '',
  confirmed_by INT UNSIGNED NULL,
  found_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_original_graduate (original_graduate_id)
) ENGINE=InnoDB;
