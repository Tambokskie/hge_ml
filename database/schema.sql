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
  extraction_status ENUM('pending','extracted','reviewed','failed') NOT NULL DEFAULT 'pending',
  records_extracted INT UNSIGNED NOT NULL DEFAULT 0,
  uploaded_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (school_id) REFERENCES schools(id),
  FOREIGN KEY (uploaded_by) REFERENCES users(id),
  KEY idx_school_id (school_id),
  KEY idx_file_hash (file_hash),
  UNIQUE KEY uq_school_year_uploadnum (school_id, school_year, upload_number)
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
  action ENUM('login','logout','pdf_upload','pdf_delete','record_created','record_updated','record_removed','search','user_created','user_updated','school_created','school_updated','school_removed') NOT NULL,
  details TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id),
  KEY idx_created_at (created_at)
) ENGINE=InnoDB;
