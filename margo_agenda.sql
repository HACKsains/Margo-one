-- ==============================================================================
-- MARGO ONE — ILMUNA SSO · DEDICATED AGENDA DATABASE & KALDIK TRIGGERS
-- ==============================================================================
-- Database khusus Agenda: `uofruaju_margo_agenda`
-- Catatan cPanel: Buat database `uofruaju_margo_agenda` di cPanel -> MySQL Databases
-- lalu beri hak akses ALL PRIVILEGES kepada user `uofruaju_margo_one` & `uofruaju_Learn_Ilmuna`.
-- Skema tabel & 5 MySQL Trigger ini juga dipasang otomatis oleh `includes/agenda-engine.php`.
-- ==============================================================================

CREATE DATABASE IF NOT EXISTS `uofruaju_margo_agenda`
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

USE `uofruaju_margo_agenda`;

CREATE TABLE IF NOT EXISTS `agendas` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `source` ENUM('kaldik','admin','guru','siswa') NOT NULL DEFAULT 'admin',
    `source_ref_id` INT NULL DEFAULT NULL,
    `is_locked` TINYINT(1) NOT NULL DEFAULT 0,
    `title` VARCHAR(200) NOT NULL,
    `description` TEXT NULL,
    `start_date` DATE NOT NULL,
    `end_date` DATE NULL,
    `start_time` VARCHAR(12) NULL,
    `location` VARCHAR(160) NULL,
    `category` VARCHAR(40) NOT NULL DEFAULT 'akademik',
    `status_hari` VARCHAR(60) NULL DEFAULT NULL,
    `tahun_pelajaran` VARCHAR(30) NULL DEFAULT NULL,
    `semester` VARCHAR(20) NULL DEFAULT NULL,
    `link_url` VARCHAR(255) NULL,
    `visibility_scope` ENUM('all','guru','siswa','kelas','private') NOT NULL DEFAULT 'all',
    `target_kelas_id` INT NULL DEFAULT NULL,
    `target_kelas_name` VARCHAR(120) NULL DEFAULT NULL,
    `created_by` INT NULL DEFAULT NULL,
    `created_by_name` VARCHAR(150) NULL DEFAULT NULL,
    `created_by_role` VARCHAR(30) NULL DEFAULT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_agenda_source_ref` (`source`, `source_ref_id`),
    INDEX `idx_agenda_dates` (`is_active`, `start_date`, `end_date`),
    INDEX `idx_agenda_scope` (`visibility_scope`, `target_kelas_id`),
    INDEX `idx_agenda_creator` (`created_by`, `source`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DELIMITER $$

DROP TRIGGER IF EXISTS `trg_agendas_lock_kaldik_bu`$$
CREATE TRIGGER `trg_agendas_lock_kaldik_bu`
BEFORE UPDATE ON `agendas`
FOR EACH ROW
BEGIN
    IF (OLD.source = 'kaldik' OR OLD.is_locked = 1)
       AND COALESCE(@margo_kaldik_sync_bypass, 0) != 1 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Agenda dari Kalender Pendidikan (Kaldik) terkunci permanen dan tidak dapat diubah.';
    END IF;
END$$

DROP TRIGGER IF EXISTS `trg_agendas_lock_kaldik_bd`$$
CREATE TRIGGER `trg_agendas_lock_kaldik_bd`
BEFORE DELETE ON `agendas`
FOR EACH ROW
BEGIN
    IF (OLD.source = 'kaldik' OR OLD.is_locked = 1)
       AND COALESCE(@margo_kaldik_sync_bypass, 0) != 1 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Agenda dari Kalender Pendidikan (Kaldik) terkunci permanen dan tidak dapat dihapus.';
    END IF;
END$$

DELIMITER ;

-- ==============================================================================
-- TRIGGER SINKRONISASI REAL-TIME PADA `uofruaju_learn_ilmuna`.`kalender_pendidikan`
-- ==============================================================================
USE `uofruaju_learn_ilmuna`;

DELIMITER $$

DROP TRIGGER IF EXISTS `trg_kaldik_to_agenda_ai`$$
CREATE TRIGGER `trg_kaldik_to_agenda_ai`
AFTER INSERT ON `kalender_pendidikan`
FOR EACH ROW
BEGIN
    IF (
        (NEW.status_hari != 'Efektif KBM' AND COALESCE(NEW.keterangan, '') NOT LIKE 'Libur Akhir Pekan%')
        OR
        (NEW.status_hari = 'Efektif KBM' AND TRIM(COALESCE(NEW.keterangan, '')) != '' AND TRIM(COALESCE(NEW.keterangan, '')) != 'KBM Reguler')
    ) THEN
        SET @margo_kaldik_sync_bypass = 1;
        INSERT INTO `uofruaju_margo_agenda`.`agendas`
            (`source`, `source_ref_id`, `is_locked`, `title`, `description`, `start_date`, `end_date`, `start_time`, `location`, `category`, `status_hari`, `tahun_pelajaran`, `semester`, `link_url`, `visibility_scope`, `created_by_name`, `created_by_role`, `is_active`)
        VALUES (
            'kaldik', NEW.id, 1,
            CASE WHEN TRIM(COALESCE(NEW.keterangan, '')) = '' OR TRIM(COALESCE(NEW.keterangan, '')) = 'KBM Reguler' THEN NEW.status_hari ELSE LEFT(TRIM(NEW.keterangan), 200) END,
            CONCAT('Agenda resmi Kalender Pendidikan (Kaldik) · TA ', COALESCE(NEW.tahun_pelajaran, '-'), ' Semester ', COALESCE(NEW.semester, '-'), ' · Status: ', NEW.status_hari, IF(TRIM(COALESCE(NEW.keterangan, '')) != '', CONCAT(' — ', TRIM(NEW.keterangan)), '')),
            NEW.tanggal, NEW.tanggal, NULL, 'Kalender Pendidikan Sekolah',
            CASE WHEN NEW.status_hari = 'Kegiatan Sekolah/Ujian' THEN 'kegiatan' WHEN NEW.status_hari IN ('Libur Nasional', 'Libur Khusus Keagamaan', 'Libur Semester') THEN 'kaldik' ELSE 'akademik' END,
            NEW.status_hari, NEW.tahun_pelajaran, NEW.semester, 'https://learn.ilmuna.my.id', 'all', 'Kalender Pendidikan (Kaldik)', 'kaldik', 1
        )
        ON DUPLICATE KEY UPDATE
            `is_locked` = 1, `title` = VALUES(`title`), `description` = VALUES(`description`),
            `start_date` = VALUES(`start_date`), `end_date` = VALUES(`end_date`),
            `category` = VALUES(`category`), `status_hari` = VALUES(`status_hari`),
            `tahun_pelajaran` = VALUES(`tahun_pelajaran`), `semester` = VALUES(`semester`),
            `visibility_scope` = 'all', `is_active` = 1;
        SET @margo_kaldik_sync_bypass = 0;
    END IF;
END$$

DROP TRIGGER IF EXISTS `trg_kaldik_to_agenda_au`$$
CREATE TRIGGER `trg_kaldik_to_agenda_au`
AFTER UPDATE ON `kalender_pendidikan`
FOR EACH ROW
BEGIN
    SET @margo_kaldik_sync_bypass = 1;
    IF (
        (NEW.status_hari != 'Efektif KBM' AND COALESCE(NEW.keterangan, '') NOT LIKE 'Libur Akhir Pekan%')
        OR
        (NEW.status_hari = 'Efektif KBM' AND TRIM(COALESCE(NEW.keterangan, '')) != '' AND TRIM(COALESCE(NEW.keterangan, '')) != 'KBM Reguler')
    ) THEN
        INSERT INTO `uofruaju_margo_agenda`.`agendas`
            (`source`, `source_ref_id`, `is_locked`, `title`, `description`, `start_date`, `end_date`, `start_time`, `location`, `category`, `status_hari`, `tahun_pelajaran`, `semester`, `link_url`, `visibility_scope`, `created_by_name`, `created_by_role`, `is_active`)
        VALUES (
            'kaldik', NEW.id, 1,
            CASE WHEN TRIM(COALESCE(NEW.keterangan, '')) = '' OR TRIM(COALESCE(NEW.keterangan, '')) = 'KBM Reguler' THEN NEW.status_hari ELSE LEFT(TRIM(NEW.keterangan), 200) END,
            CONCAT('Agenda resmi Kalender Pendidikan (Kaldik) · TA ', COALESCE(NEW.tahun_pelajaran, '-'), ' Semester ', COALESCE(NEW.semester, '-'), ' · Status: ', NEW.status_hari, IF(TRIM(COALESCE(NEW.keterangan, '')) != '', CONCAT(' — ', TRIM(NEW.keterangan)), '')),
            NEW.tanggal, NEW.tanggal, NULL, 'Kalender Pendidikan Sekolah',
            CASE WHEN NEW.status_hari = 'Kegiatan Sekolah/Ujian' THEN 'kegiatan' WHEN NEW.status_hari IN ('Libur Nasional', 'Libur Khusus Keagamaan', 'Libur Semester') THEN 'kaldik' ELSE 'akademik' END,
            NEW.status_hari, NEW.tahun_pelajaran, NEW.semester, 'https://learn.ilmuna.my.id', 'all', 'Kalender Pendidikan (Kaldik)', 'kaldik', 1
        )
        ON DUPLICATE KEY UPDATE
            `is_locked` = 1, `title` = VALUES(`title`), `description` = VALUES(`description`),
            `start_date` = VALUES(`start_date`), `end_date` = VALUES(`end_date`),
            `category` = VALUES(`category`), `status_hari` = VALUES(`status_hari`),
            `tahun_pelajaran` = VALUES(`tahun_pelajaran`), `semester` = VALUES(`semester`),
            `visibility_scope` = 'all', `is_active` = 1;
    ELSE
        DELETE FROM `uofruaju_margo_agenda`.`agendas`
        WHERE `source` = 'kaldik' AND `source_ref_id` = NEW.id;
    END IF;
    SET @margo_kaldik_sync_bypass = 0;
END$$

DROP TRIGGER IF EXISTS `trg_kaldik_to_agenda_ad`$$
CREATE TRIGGER `trg_kaldik_to_agenda_ad`
AFTER DELETE ON `kalender_pendidikan`
FOR EACH ROW
BEGIN
    SET @margo_kaldik_sync_bypass = 1;
    DELETE FROM `uofruaju_margo_agenda`.`agendas`
    WHERE `source` = 'kaldik' AND `source_ref_id` = OLD.id;
    SET @margo_kaldik_sync_bypass = 0;
END$$

DELIMITER ;
