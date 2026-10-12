-- Recycle bin for everything that can be removed besides graduate records
-- (uploads and their stored file, schools, users, OCR course keywords).
-- Graduate records already have graduate_recycle_bin.
--
-- The project has no migration runner: run this ONCE on an existing database
-- (e.g. in phpMyAdmin, with the hge_masterlist database selected). A fresh
-- install gets the table from schema.sql. Until it is run, removing an upload,
-- school, user or keyword is refused with a message and nothing is removed.

CREATE TABLE IF NOT EXISTS recycle_bin_items (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  item_type ENUM('upload','school','user','ocr_keyword') NOT NULL,
  original_id INT UNSIGNED NOT NULL,
  label VARCHAR(255) NOT NULL,
  details VARCHAR(500) NOT NULL DEFAULT '',
  payload LONGTEXT NOT NULL,
  bin_file VARCHAR(255) NULL DEFAULT NULL,
  deleted_by INT UNSIGNED NULL,
  deleted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  deleted_reason VARCHAR(500) NOT NULL DEFAULT '',
  FOREIGN KEY (deleted_by) REFERENCES users(id),
  KEY idx_item_type (item_type),
  KEY idx_deleted_at (deleted_at)
) ENGINE=InnoDB;
