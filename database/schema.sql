CREATE DATABASE IF NOT EXISTS hge_masterlist CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE hge_masterlist;

CREATE TABLE users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(150) NOT NULL,
  username VARCHAR(50) NOT NULL UNIQUE,
  email VARCHAR(150) NOT NULL UNIQUE,
  password VARCHAR(255) NOT NULL,
  role ENUM('administrator','user') NOT NULL DEFAULT 'user',
  status ENUM('active','disabled') NOT NULL DEFAULT 'active',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE schools (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  school_name VARCHAR(255) NOT NULL,
  address VARCHAR(500) NOT NULL DEFAULT '',
  campus VARCHAR(255) NOT NULL DEFAULT '',
  status ENUM('active','disabled') NOT NULL DEFAULT 'active',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_school_name_campus (school_name, campus)
) ENGINE=InnoDB;

CREATE TABLE latin_honor_uploads (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  school_id INT UNSIGNED NOT NULL,
  school_year VARCHAR(9) NOT NULL,
  original_filename VARCHAR(255) NOT NULL,
  stored_filename VARCHAR(255) NOT NULL,
  file_path VARCHAR(500) NOT NULL,
  file_size INT UNSIGNED NOT NULL,
  file_hash CHAR(64) NOT NULL,
  uploaded_by INT UNSIGNED NOT NULL,
  upload_number INT UNSIGNED NOT NULL DEFAULT 1,
  extraction_status ENUM('pending','extracted','reviewed','failed','draft') NOT NULL DEFAULT 'pending',
  records_extracted INT UNSIGNED NOT NULL DEFAULT 0,
  total_pages INT UNSIGNED NULL,
  extracted_through_page INT UNSIGNED NOT NULL DEFAULT 0,
  uploaded_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (school_id) REFERENCES schools(id),
  FOREIGN KEY (uploaded_by) REFERENCES users(id),
  KEY idx_school_id (school_id),
  KEY idx_file_hash (file_hash),
  UNIQUE KEY uq_school_year_uploadnum (school_id, school_year, upload_number)
) ENGINE=InnoDB;

CREATE TABLE extraction_review_drafts (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  upload_id INT UNSIGNED NOT NULL,
  rows_json LONGTEXT NOT NULL,
  saved_by INT UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (upload_id) REFERENCES latin_honor_uploads(id),
  FOREIGN KEY (saved_by) REFERENCES users(id),
  UNIQUE KEY uq_upload_id (upload_id)
) ENGINE=InnoDB;

CREATE TABLE latin_honor_graduates (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  upload_id INT UNSIGNED NOT NULL,
  school_id INT UNSIGNED NOT NULL,
  graduate_name VARCHAR(255) NOT NULL,
  course VARCHAR(255) NOT NULL DEFAULT '',
  latin_honor ENUM('CUM LAUDE','MAGNA CUM LAUDE','SUMMA CUM LAUDE') NOT NULL,
  graduation_date DATE NULL,
  graduation_year YEAR NOT NULL,
  source ENUM('ai','ocr','text','manual') NULL DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (upload_id) REFERENCES latin_honor_uploads(id),
  FOREIGN KEY (school_id) REFERENCES schools(id),
  KEY idx_school_id (school_id),
  KEY idx_graduation_year (graduation_year),
  KEY idx_latin_honor (latin_honor),
  KEY idx_upload_id (upload_id),
  FULLTEXT KEY ft_graduate_name (graduate_name)
) ENGINE=InnoDB;

CREATE TABLE activity_log (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NULL,
  action ENUM('login','logout','pdf_upload','pdf_delete','template_upload','record_created','record_updated','record_removed','record_restored','record_purged','record_draft_saved','search','user_created','user_updated','user_removed','school_created','school_updated','school_removed','application_filed','db_backup','ocr_keyword_created','ocr_keyword_removed','ai_settings_updated') NOT NULL,
  details TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id),
  KEY idx_created_at (created_at)
) ENGINE=InnoDB;

CREATE TABLE ocr_course_keywords (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  keyword VARCHAR(50) NOT NULL,
  created_by INT UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (created_by) REFERENCES users(id),
  UNIQUE KEY uq_keyword (keyword)
) ENGINE=InnoDB;

CREATE TABLE app_settings (
  setting_key VARCHAR(50) PRIMARY KEY,
  setting_value TEXT NULL,
  updated_by INT UNSIGNED NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (updated_by) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE graduate_applications (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  graduate_id INT UNSIGNED NOT NULL,
  applied_by INT UNSIGNED NOT NULL,
  application_date DATE NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (graduate_id) REFERENCES latin_honor_graduates(id),
  FOREIGN KEY (applied_by) REFERENCES users(id),
  UNIQUE KEY uq_graduate_id (graduate_id)
) ENGINE=InnoDB;

CREATE TABLE graduate_recycle_bin (
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

CREATE TABLE bulk_hge_review_items (
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
