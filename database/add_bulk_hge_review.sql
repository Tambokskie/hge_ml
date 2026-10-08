-- Run once on the live hge_masterlist database (no migration runner in this project).
-- Holds the bulk-update applicants whose match was uncertain, until an admin files or dismisses each.
CREATE TABLE IF NOT EXISTS bulk_hge_review_items (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  person_key CHAR(40) NOT NULL,
  last_name VARCHAR(120) NOT NULL,
  first_name VARCHAR(120) NOT NULL,
  mi CHAR(1) NOT NULL DEFAULT '',
  middle_name VARCHAR(120) NOT NULL DEFAULT '',
  ext_name VARCHAR(20) NOT NULL DEFAULT '',
  application_date DATE NOT NULL,
  reason ENUM('loose','ambiguous','claimed') NOT NULL,
  candidate_ids TEXT NOT NULL,
  source_filename VARCHAR(100) NOT NULL DEFAULT '',
  file_row INT UNSIGNED NOT NULL DEFAULT 0,
  status ENUM('pending','filed','dismissed') NOT NULL DEFAULT 'pending',
  created_by INT UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  resolved_by INT UNSIGNED NULL DEFAULT NULL,
  resolved_at TIMESTAMP NULL DEFAULT NULL,
  resolved_graduate_id INT UNSIGNED NULL DEFAULT NULL,
  FOREIGN KEY (created_by) REFERENCES users(id),
  FOREIGN KEY (resolved_by) REFERENCES users(id),
  UNIQUE KEY uq_person_key (person_key),
  KEY idx_status_id (status, id)
) ENGINE=InnoDB;
