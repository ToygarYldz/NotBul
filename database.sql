CREATE DATABASE IF NOT EXISTS notbul CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

USE notbul;

CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    first_name VARCHAR(50) NOT NULL,
    last_name VARCHAR(50) NOT NULL,
    email VARCHAR(100) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    verified TINYINT(1) NOT NULL DEFAULT 0,
    role ENUM('user', 'admin') NOT NULL DEFAULT 'user',
    email_verification_token CHAR(64) NULL,
    email_verification_token_expires_at DATETIME NULL,
    password_reset_token CHAR(64) NULL,
    password_reset_token_expires_at DATETIME NULL,
    verified_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Existing installations migration.
ALTER TABLE users
    ADD COLUMN IF NOT EXISTS verified TINYINT(1) NOT NULL DEFAULT 0 AFTER password,
    ADD COLUMN IF NOT EXISTS role ENUM('user', 'admin') NOT NULL DEFAULT 'user' AFTER verified,
    ADD COLUMN IF NOT EXISTS email_verification_token CHAR(64) NULL AFTER role,
    ADD COLUMN IF NOT EXISTS email_verification_token_expires_at DATETIME NULL AFTER email_verification_token,
    ADD COLUMN IF NOT EXISTS password_reset_token CHAR(64) NULL AFTER email_verification_token_expires_at,
    ADD COLUMN IF NOT EXISTS password_reset_token_expires_at DATETIME NULL AFTER password_reset_token,
    ADD COLUMN IF NOT EXISTS verified_at DATETIME NULL AFTER password_reset_token_expires_at;

-- Keep pre-existing accounts active after adding the verification columns.
UPDATE users
SET verified = 1,
    verified_at = COALESCE(verified_at, NOW())
WHERE verified = 0
  AND email_verification_token IS NULL;

CREATE INDEX IF NOT EXISTS idx_users_verified ON users(verified);
CREATE INDEX IF NOT EXISTS idx_users_role ON users(role);
CREATE INDEX IF NOT EXISTS idx_users_email_verification_token ON users(email_verification_token);
CREATE INDEX IF NOT EXISTS idx_users_password_reset_token ON users(password_reset_token);

-- First admin account setup:
-- 1) Create a normal user through the app or insert one manually with a password_hash() value.
-- 2) Promote that account:
-- UPDATE users
-- SET role = 'admin',
--     verified = 1,
--     verified_at = COALESCE(verified_at, NOW())
-- WHERE email = 'admin@mail.com';

CREATE TABLE IF NOT EXISTS notes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    title VARCHAR(160) NOT NULL,
    description TEXT,
    university_id VARCHAR(50),
    department_type VARCHAR(50),
    department_id VARCHAR(50),
    class_id VARCHAR(50),
    course VARCHAR(150),
    topic VARCHAR(150),
    tags VARCHAR(255),
    original_filename VARCHAR(255) NOT NULL,
    stored_filename VARCHAR(255) NULL,
    storage_disk VARCHAR(32) NOT NULL DEFAULT 'local',
    storage_path VARCHAR(255) NOT NULL DEFAULT '',
    sha256 CHAR(64) NOT NULL DEFAULT '',
    file_size BIGINT UNSIGNED NOT NULL,
    mime_type VARCHAR(100) NOT NULL,
    upload_status ENUM('pending', 'ready', 'rejected') NOT NULL DEFAULT 'pending',
    scan_status ENUM('pending', 'clean', 'infected') NOT NULL DEFAULT 'pending',
    download_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at DATETIME NULL,
    deleted_by INT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Existing installations migration.
ALTER TABLE notes
    MODIFY COLUMN stored_filename VARCHAR(255) NULL,
    MODIFY COLUMN file_size BIGINT UNSIGNED NOT NULL,
    ADD COLUMN IF NOT EXISTS storage_disk VARCHAR(32) NOT NULL DEFAULT 'local' AFTER stored_filename,
    ADD COLUMN IF NOT EXISTS storage_path VARCHAR(255) NOT NULL DEFAULT '' AFTER storage_disk,
    ADD COLUMN IF NOT EXISTS sha256 CHAR(64) NOT NULL DEFAULT '' AFTER storage_path,
    ADD COLUMN IF NOT EXISTS upload_status ENUM('pending', 'ready', 'rejected') NOT NULL DEFAULT 'pending' AFTER mime_type,
    ADD COLUMN IF NOT EXISTS scan_status ENUM('pending', 'clean', 'infected') NOT NULL DEFAULT 'pending' AFTER upload_status,
    ADD COLUMN IF NOT EXISTS download_count BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER scan_status,
    ADD COLUMN IF NOT EXISTS updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at,
    ADD COLUMN IF NOT EXISTS deleted_at DATETIME NULL AFTER updated_at,
    ADD COLUMN IF NOT EXISTS deleted_by INT NULL AFTER deleted_at;

-- Backfill for previous schema rows.
UPDATE notes
SET storage_disk = 'local'
WHERE storage_disk IS NULL OR storage_disk = '';

UPDATE notes
SET storage_path = stored_filename
WHERE (storage_path IS NULL OR storage_path = '')
  AND stored_filename IS NOT NULL
  AND stored_filename <> '';

UPDATE notes
SET upload_status = 'ready'
WHERE upload_status IS NULL OR upload_status = '';

UPDATE notes
SET scan_status = 'clean'
WHERE scan_status IS NULL OR scan_status = '';

UPDATE notes
SET download_count = 0
WHERE download_count IS NULL;

CREATE INDEX IF NOT EXISTS idx_notes_user_id ON notes(user_id);
CREATE INDEX IF NOT EXISTS idx_notes_created_at ON notes(created_at);
CREATE INDEX IF NOT EXISTS idx_notes_status ON notes(upload_status, scan_status);
CREATE INDEX IF NOT EXISTS idx_notes_course ON notes(course);
CREATE INDEX IF NOT EXISTS idx_notes_sha256 ON notes(sha256);
CREATE INDEX IF NOT EXISTS idx_notes_deleted_at ON notes(deleted_at);
CREATE INDEX IF NOT EXISTS idx_notes_user_deleted_at ON notes(user_id, deleted_at, created_at);
