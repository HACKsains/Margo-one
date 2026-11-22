-- ==============================================================================
-- MARGO ONE — ILMUNA SSO · MASTER DATABASE SCHEMA (MySQL InnoDB utf8mb4)
-- Target Database : uofruaju_margo_one (Database Resmi Margo One SSO)
-- Kompatibilitas  : MySQL 5.7+ / MySQL 8.0+ / MariaDB 10.3+ (phpMyAdmin Ready)
-- Timezone        : Asia/Jakarta (+07:00)
-- ==============================================================================

CREATE DATABASE IF NOT EXISTS `uofruaju_margo_one` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `uofruaju_margo_one`;

SET NAMES utf8mb4;
SET time_zone = '+07:00';
SET FOREIGN_KEY_CHECKS = 0;

-- ------------------------------------------------------------------------------
-- 1. TABEL IDENTITAS INTI (SHARED DENGAN LEARN.ILMUNA.MY.ID)
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `roles` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `code` VARCHAR(50) NOT NULL UNIQUE,
    `name` VARCHAR(100) NOT NULL,
    `description` TEXT NULL,
    `badge_color` VARCHAR(30) DEFAULT 'teal',
    `icon` VARCHAR(80) DEFAULT 'solar:user-bold',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `users` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `username` VARCHAR(80) NOT NULL UNIQUE,
    `email` VARCHAR(150) NULL,
    `email_google` VARCHAR(150) NULL,
    `google_id` VARCHAR(120) NULL,
    `password_hash` VARCHAR(255) NOT NULL,
    `full_name` VARCHAR(150) NOT NULL,
    `nisn_nip` VARCHAR(60) NULL,
    `gender` VARCHAR(20) NULL,
    `phone` VARCHAR(30) NULL,
    `foto_profil` VARCHAR(255) NULL,
    `status` VARCHAR(20) NOT NULL DEFAULT 'active',
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `last_login` DATETIME NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_users_email` (`email`),
    INDEX `idx_users_email_google` (`email_google`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `user_roles` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `role_id` INT NOT NULL,
    `is_primary` TINYINT(1) NOT NULL DEFAULT 0,
    `assigned_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_user_role` (`user_id`, `role_id`),
    INDEX `idx_ur_user` (`user_id`),
    INDEX `idx_ur_role` (`role_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `kelas` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `nama_kelas` VARCHAR(100) NOT NULL,
    `tingkat` VARCHAR(20) DEFAULT 'X',
    `tahun_pelajaran` VARCHAR(30) DEFAULT '2026/2027',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `guru` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `id_user` INT NULL,
    `nip` VARCHAR(60) NULL,
    `nama_guru` VARCHAR(150) NOT NULL,
    `email` VARCHAR(150) NULL,
    `no_hp` VARCHAR(30) NULL,
    `jenis_kelamin` VARCHAR(20) NULL,
    `status_aktif` VARCHAR(20) DEFAULT 'Aktif',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_guru_user` (`id_user`),
    INDEX `idx_guru_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `siswa` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `id_kelas` INT NULL,
    `nisn` VARCHAR(40) NULL,
    `nis` VARCHAR(40) NULL,
    `nama_lengkap` VARCHAR(150) NOT NULL,
    `jenis_kelamin` VARCHAR(20) NULL,
    `gmail` VARCHAR(150) NULL,
    `no_hp` VARCHAR(30) NULL,
    `status_siswa` VARCHAR(20) DEFAULT 'Aktif',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_siswa_kelas` (`id_kelas`),
    INDEX `idx_siswa_nisn` (`nisn`),
    INDEX `idx_siswa_gmail` (`gmail`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 2. TABEL INTI MARGO ONE SSO & KEAMANAN ZERO-TRUST (8 TABEL)
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `sso_clients` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `client_name` VARCHAR(120) NOT NULL,
    `client_slug` VARCHAR(60) NOT NULL UNIQUE,
    `public_key` VARCHAR(120) NOT NULL UNIQUE,
    `private_key_enc` TEXT NOT NULL,
    `private_key_hash` CHAR(64) NOT NULL,
    `rsa_public_pem` TEXT NULL,
    `sig_mode` ENUM('HMAC-SHA256','RSA-SHA256','DUAL') NOT NULL DEFAULT 'HMAC-SHA256',
    `app_url` VARCHAR(255) NOT NULL,
    `app_icon` VARCHAR(80) NOT NULL DEFAULT 'solar:widget-5-bold',
    `redirect_uris` TEXT NOT NULL,
    `logout_webhook_url` VARCHAR(255) NULL,
    `allowed_domains` TEXT NOT NULL,
    `allowed_ips` TEXT NULL,
    `allowed_scopes` VARCHAR(255) NOT NULL DEFAULT 'openid,profile,email,roles',
    `allowed_roles` VARCHAR(255) NOT NULL DEFAULT 'admin,guru,ketua_kelas,siswa',
    `rate_limit_per_min` INT NOT NULL DEFAULT 180,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `last_used_at` DATETIME NULL,
    `last_ip` VARCHAR(64) NULL,
    `request_count` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_sso_clients_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `sso_sessions` (
    `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
    `session_token_hash` CHAR(64) NOT NULL UNIQUE,
    `user_id` INT NOT NULL,
    `active_role` VARCHAR(40) NULL,
    `ip_address` VARCHAR(64) NOT NULL,
    `user_agent` VARCHAR(255) NULL,
    `login_method` ENUM('password','google_oauth','magic_qr') NOT NULL DEFAULT 'password',
    `expires_at` DATETIME NOT NULL,
    `revoked_at` DATETIME NULL,
    `last_activity_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_sso_sess_user` (`user_id`, `revoked_at`),
    INDEX `idx_sso_sess_exp` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `sso_auth_codes` (
    `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
    `code_hash` CHAR(64) NOT NULL UNIQUE,
    `client_id` INT NOT NULL,
    `user_id` INT NOT NULL,
    `sso_session_id` BIGINT NOT NULL,
    `redirect_uri` VARCHAR(255) NOT NULL,
    `scope` VARCHAR(255) NOT NULL,
    `code_challenge` VARCHAR(128) NOT NULL,
    `code_challenge_method` VARCHAR(10) NOT NULL DEFAULT 'S256',
    `nonce` VARCHAR(128) NULL,
    `expires_at` DATETIME NOT NULL,
    `used_at` DATETIME NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_sso_code_exp` (`expires_at`),
    INDEX `idx_sso_code_client` (`client_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `sso_refresh_tokens` (
    `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
    `token_hash` CHAR(64) NOT NULL UNIQUE,
    `family_id` CHAR(36) NOT NULL,
    `client_id` INT NOT NULL,
    `user_id` INT NOT NULL,
    `sso_session_id` BIGINT NOT NULL,
    `scope` VARCHAR(255) NOT NULL DEFAULT 'openid profile email roles',
    `expires_at` DATETIME NOT NULL,
    `rotated_at` DATETIME NULL,
    `revoked_at` DATETIME NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_sso_rt_family` (`family_id`),
    INDEX `idx_sso_rt_user` (`user_id`, `revoked_at`),
    INDEX `idx_sso_rt_sess` (`sso_session_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `sso_nonces` (
    `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
    `public_key` VARCHAR(120) NOT NULL,
    `nonce_hash` CHAR(64) NOT NULL,
    `expires_at` DATETIME NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_sso_pk_nonce` (`public_key`, `nonce_hash`),
    INDEX `idx_sso_nonce_exp` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `sso_rate_limits` (
    `bucket_key` CHAR(64) PRIMARY KEY,
    `hits` INT NOT NULL DEFAULT 1,
    `expires_at` DATETIME NOT NULL,
    INDEX `idx_sso_rl_exp` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `sso_audit_logs` (
    `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NULL,
    `client_id` INT NULL,
    `event_type` VARCHAR(60) NOT NULL,
    `http_status` SMALLINT NOT NULL DEFAULT 200,
    `ip_address` VARCHAR(64) NOT NULL,
    `user_agent` VARCHAR(255) NULL,
    `detail_json` TEXT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_sso_audit_created` (`created_at`),
    INDEX `idx_sso_audit_event` (`event_type`),
    INDEX `idx_sso_audit_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `sso_jwks_keys` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `kid` VARCHAR(80) NOT NULL UNIQUE,
    `public_pem` TEXT NOT NULL,
    `private_pem_enc` MEDIUMTEXT NOT NULL,
    `fingerprint` CHAR(64) NOT NULL,
    `is_primary` TINYINT(1) NOT NULL DEFAULT 0,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `expires_at` DATETIME NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_sso_jwks_active` (`is_active`, `is_primary`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 3. TABEL PORTAL DALAM, AGENDA & INFO EKOSISTEM (3 TABEL)
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `sso_agendas` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `title` VARCHAR(180) NOT NULL,
    `description` TEXT NULL,
    `start_date` DATE NOT NULL,
    `end_date` DATE NULL,
    `start_time` VARCHAR(12) NULL,
    `location` VARCHAR(160) NULL,
    `category` VARCHAR(40) NOT NULL DEFAULT 'akademik',
    `link_url` VARCHAR(255) NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_by` INT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_sso_agenda_date` (`start_date`, `is_active`),
    INDEX `idx_sso_agenda_cat` (`category`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `sso_announcements` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `title` VARCHAR(180) NOT NULL,
    `excerpt` TEXT NULL,
    `link_url` VARCHAR(255) NULL,
    `badge_label` VARCHAR(40) NOT NULL DEFAULT 'INFO',
    `published_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_by` INT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_sso_ann_pub` (`published_at`, `is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `sso_external_apps` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `app_name` VARCHAR(120) NOT NULL,
    `description` VARCHAR(255) NULL,
    `app_url` VARCHAR(255) NOT NULL,
    `app_icon` VARCHAR(80) NOT NULL DEFAULT 'solar:widget-4-bold',
    `badge_label` VARCHAR(40) NOT NULL DEFAULT 'EKOSISTEM',
    `sort_order` INT NOT NULL DEFAULT 10,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_sso_ext_active` (`is_active`, `sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 4. SEED ROLE STANDAR & AKUN ADMIN UTAMA (IDEMPOTEN)
-- Catatan: Klien SSO (pk_sso_*/sk_sso_*) & RSA-2048 JWKS key dibangkitkan otomatis
-- saat pertama kali membuka /database.php atau /login.php agar kunci selalu unik.
-- ------------------------------------------------------------------------------
INSERT IGNORE INTO `roles` (`code`, `name`, `description`, `badge_color`, `icon`) VALUES
('admin',       'Administrator', 'Pengelola penuh ekosistem Ilmuna & Margo One SSO', 'navy',    'solar:shield-user-bold'),
('ketua_kelas', 'Ketua Kelas',   'Koordinator kelas dan pemantau kedisiplinan',      'teal',    'solar:crown-bold'),
('siswa',       'Siswa',         'Peserta didik aktif ekosistem Ilmuna',             'sky',     'solar:user-id-bold'),
('guru',        'Guru Pengampu', 'Dewan guru dan penyusun materi pembelajaran',      'emerald', 'solar:user-speak-bold');

INSERT IGNORE INTO `users` (`username`, `email`, `password_hash`, `full_name`, `status`, `is_active`) VALUES
('Syahriel28', 'syahriel.andika28@gmail.com', '$2y$10$HQpOWy3lcAA8zk5PZQM9tOhD/pE/qAREgFYC0qi7AAulVndd1kbAW', 'Syahriel Andika', 'active', 1);

INSERT IGNORE INTO `user_roles` (`user_id`, `role_id`, `is_primary`)
SELECT u.id, r.id, 1
FROM `users` u
JOIN `roles` r ON r.code = 'admin'
WHERE u.username = 'Syahriel28';

SET FOREIGN_KEY_CHECKS = 1;