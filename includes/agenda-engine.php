<?php
/**
 * ==============================================================================
 * MARGO ONE — ILMUNA SSO · DEDICATED AGENDA DATABASE & KALDIK TRIGGER ENGINE
 * ==============================================================================
 * Arsitektur Database Khusus Agenda (`uofruaju_margo_agenda`):
 *   1. Database terpisah (`AGENDA_DB_NAME`, default `uofruaju_margo_agenda`) dengan
 *      fallback otomatis ke database aktif jika hosting cPanel membatasi CREATE DATABASE.
 *   2. Sinkronisasi Real-Time 100% Otomatis dari `uofruaju_learn_ilmuna.kalender_pendidikan`
 *      melalui MySQL Database Triggers (`AFTER INSERT`, `AFTER UPDATE`, `AFTER DELETE`)
 *      plus PHP backfill fallback untuk hosting tanpa hak akses TRIGGER.
 *   3. Proteksi Read-Only Berlapis (Trigger `BEFORE UPDATE`/`BEFORE DELETE` + Guard API):
 *      Agenda bersumber dari Kaldik (`source = 'kaldik'` / `is_locked = 1`) SAMA SEKALI
 *      TIDAK DAPAT diubah, ditimpa, atau dihapus oleh siapapun (termasuk Admin).
 *   4. Aturan Hak Akses & Audiens Berbasis Peran:
 *      - Admin : dapat membuat agenda untuk Semua Orang (`all`), Khusus Guru (`guru`),
 *                Khusus Siswa (`siswa`), atau Pribadi (`private`).
 *      - Guru  : dapat membuat agenda untuk Diri Sendiri (`private`) atau Kelas yang
 *                ia ampu (`kelas` -> tervalidasi via `kelas_mata_pelajaran`).
 *      - Siswa : dapat membuat agenda HANYA untuk Diri Sendiri (`private`).
 *
 * 100% kompatibel PHP 7.4 (ea-php74) hingga PHP 8.3.
 * ==============================================================================
 */

if (!defined('BASE_PATH')) {
    require_once dirname(__DIR__) . '/config.php';
}

/**
 * Buat koneksi PDO dengan opsi standar utf8mb4 + Asia/Jakarta
 */
function margo_agenda_pdo_options()
{
    $opts = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];
    if (defined('PDO::MYSQL_ATTR_INIT_COMMAND')) {
        $opts[PDO::MYSQL_ATTR_INIT_COMMAND] = "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci, time_zone = '+07:00'";
    }
    return $opts;
}

/**
 * Deteksi apakah sedang berjalan di lingkungan lokal (Laragon/XAMPP/CLI)
 */
function margo_agenda_is_local_env()
{
    $hostNow = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
    return (php_sapi_name() === 'cli')
        || strpos($hostNow, 'localhost') === 0
        || strpos($hostNow, '127.0.0.1') === 0
        || substr($hostNow, -5) === '.test';
}

/**
 * Koneksi ke Database Khusus Agenda (`uofruaju_margo_agenda`)
 * Mengembalikan ['pdo' => PDO, 'db_name' => string, 'is_dedicated' => bool]
 */
function margo_get_agenda_db(PDO $fallbackPdo = null)
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    $targetDb = defined('AGENDA_DB_NAME') ? (string)AGENDA_DB_NAME : 'uofruaju_margo_agenda';
    $host     = defined('DB_HOST') ? (string)DB_HOST : 'localhost';
    $port     = defined('DB_PORT') ? (string)DB_PORT : '3306';
    $charset  = defined('DB_CHARSET') ? (string)DB_CHARSET : 'utf8mb4';
    $opts     = margo_agenda_pdo_options();

    $credList = [
        [defined('AGENDA_DB_USER') ? (string)AGENDA_DB_USER : (string)DB_USER, defined('AGENDA_DB_PASS') ? (string)AGENDA_DB_PASS : (string)DB_PASS],
        ['uofruaju_Learn_Ilmuna', defined('DB_PASS') ? (string)DB_PASS : 'Ilmuna28@'],
        ['uofruaju_margo_agenda', defined('AGENDA_DB_PASS') ? (string)AGENDA_DB_PASS : 'Ilmuna28@'],
    ];
    if (margo_agenda_is_local_env()) {
        $credList[] = ['root', ''];
    }

    // 1. Coba langsung konek ke database dedicated uofruaju_margo_agenda
    foreach ($credList as $cred) {
        try {
            $hUse = ($cred[0] === 'root' && margo_agenda_is_local_env()) ? '127.0.0.1' : $host;
            $dsn = "mysql:host={$hUse};port={$port};dbname={$targetDb};charset={$charset}";
            $apdo = new PDO($dsn, $cred[0], $cred[1], $opts);
            $apdo->exec("SET time_zone = '+07:00'");
            $cache = [
                'pdo'          => $apdo,
                'db_name'      => $targetDb,
                'is_dedicated' => true,
            ];
            return $cache;
        } catch (Throwable $e) {
            // Lanjut ke kandidat berikutnya
        }
    }

    // 2. Jika database belum ada, coba CREATE DATABASE IF NOT EXISTS (sukses di lokal & server berhak CREATE)
    foreach ($credList as $cred) {
        try {
            $hUse = ($cred[0] === 'root' && margo_agenda_is_local_env()) ? '127.0.0.1' : $host;
            $dsnNoDb = "mysql:host={$hUse};port={$port};charset={$charset}";
            $tmp = new PDO($dsnNoDb, $cred[0], $cred[1], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_TIMEOUT => 3,
            ]);
            $tmp->exec("CREATE DATABASE IF NOT EXISTS `{$targetDb}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $dsnCreated = "mysql:host={$hUse};port={$port};dbname={$targetDb};charset={$charset}";
            $apdo = new PDO($dsnCreated, $cred[0], $cred[1], $opts);
            $apdo->exec("SET time_zone = '+07:00'");
            $cache = [
                'pdo'          => $apdo,
                'db_name'      => $targetDb,
                'is_dedicated' => true,
            ];
            return $cache;
        } catch (Throwable $e) {
            // Lanjut ke fallback berikutnya
        }
    }

    // 3. Fallback aman untuk cPanel shared hosting (jika database dedicated belum dibuat lewat menu MySQL Databases cPanel)
    $pdoUse = $fallbackPdo ?: getDBConnection();
    $curDb = '';
    try {
        $curDb = (string)$pdoUse->query("SELECT DATABASE()")->fetchColumn();
    } catch (Throwable $e) {
        $curDb = defined('DB_NAME') ? (string)DB_NAME : 'uofruaju_margo_one';
    }
    $cache = [
        'pdo'          => $pdoUse,
        'db_name'      => $curDb,
        'is_dedicated' => ($curDb === $targetDb),
    ];
    return $cache;
}

/**
 * Koneksi ke Database Ilmuna Learn (`uofruaju_learn_ilmuna`) untuk Kaldik, Kelas, Guru & Siswa
 * Mengembalikan ['pdo' => PDO, 'db_name' => string] atau null jika tidak tersedia
 */
function margo_get_learn_db(PDO $ssoPdo = null)
{
    static $cache = false;
    if ($cache !== false) {
        return $cache;
    }

    $learnDb = defined('LEARN_DB_NAME') ? (string)LEARN_DB_NAME : 'uofruaju_learn_ilmuna';
    $host    = defined('DB_HOST') ? (string)DB_HOST : 'localhost';
    $port    = defined('DB_PORT') ? (string)DB_PORT : '3306';
    $charset = defined('DB_CHARSET') ? (string)DB_CHARSET : 'utf8mb4';
    $opts    = margo_agenda_pdo_options();

    // Cek apakah koneksi SSO saat ini sudah langsung berada di DB Learn
    if ($ssoPdo !== null) {
        try {
            $curDb = (string)$ssoPdo->query("SELECT DATABASE()")->fetchColumn();
            if ($curDb === $learnDb || (bool)$ssoPdo->query("SHOW TABLES LIKE 'kalender_pendidikan'")->fetch()) {
                $cache = ['pdo' => $ssoPdo, 'db_name' => $curDb];
                return $cache;
            }
        } catch (Throwable $e) {}
    }

    $credList = [
        [defined('LEARN_DB_USER') ? (string)LEARN_DB_USER : 'uofruaju_Learn_Ilmuna', defined('LEARN_DB_PASS') ? (string)LEARN_DB_PASS : (string)DB_PASS],
        [defined('DB_USER') ? (string)DB_USER : 'uofruaju_margo_one', defined('DB_PASS') ? (string)DB_PASS : 'Ilmuna28@'],
    ];
    if (margo_agenda_is_local_env()) {
        $credList[] = ['root', ''];
    }

    foreach ($credList as $cred) {
        try {
            $hUse = ($cred[0] === 'root' && margo_agenda_is_local_env()) ? '127.0.0.1' : $host;
            $dsn = "mysql:host={$hUse};port={$port};dbname={$learnDb};charset={$charset}";
            $lpdo = new PDO($dsn, $cred[0], $cred[1], $opts);
            $lpdo->exec("SET time_zone = '+07:00'");
            $cache = ['pdo' => $lpdo, 'db_name' => $learnDb];
            return $cache;
        } catch (Throwable $e) {
            // Coba kredensial berikutnya
        }
    }

    $cache = null;
    return null;
}

/**
 * Pastikan skema tabel `agendas` di database khusus agenda tersedia + migrasi sekali dari `sso_agendas`
 */
function margo_agenda_ensure_schema(PDO $ssoPdo = null)
{
    $agInfo = margo_get_agenda_db($ssoPdo);
    /** @var PDO $apdo */
    $apdo = $agInfo['pdo'];

    $apdo->exec("
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
            `color_hex` VARCHAR(20) NULL DEFAULT NULL,
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // Pastikan kolom color_hex tersedia pada tabel agendas eksisting
    try {
        $agCols = $apdo->query("SHOW COLUMNS FROM `agendas`")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('color_hex', $agCols, true)) {
            $apdo->exec("ALTER TABLE `agendas` ADD COLUMN `color_hex` VARCHAR(20) NULL DEFAULT NULL AFTER `category`");
        }
    } catch (Throwable $eCol) {}

    // Migrasi sekali data lama dari sso_agendas (bila tabel agendas belum memiliki agenda non-kaldik)
    try {
        $nonKaldikCnt = (int)$apdo->query("SELECT COUNT(*) FROM `agendas` WHERE `source` != 'kaldik'")->fetchColumn();
        if ($nonKaldikCnt === 0 && $ssoPdo !== null) {
            $hasLegacy = (bool)$ssoPdo->query("SHOW TABLES LIKE 'sso_agendas'")->fetch();
            if ($hasLegacy) {
                $oldRows = $ssoPdo->query("
                    SELECT id, title, description, start_date, end_date, start_time, location, category, link_url, is_active, created_by
                    FROM `sso_agendas`
                    ORDER BY id ASC
                ")->fetchAll(PDO::FETCH_ASSOC);

                if (!empty($oldRows)) {
                    $ins = $apdo->prepare("
                        INSERT IGNORE INTO `agendas`
                            (source, source_ref_id, is_locked, title, description, start_date, end_date, start_time, location, category, link_url, visibility_scope, created_by, created_by_name, created_by_role, is_active)
                        VALUES
                            ('admin', ?, 0, ?, ?, ?, ?, ?, ?, ?, ?, 'all', ?, 'Administrator SSO', 'admin', ?)
                    ");
                    foreach ($oldRows as $r) {
                        $ins->execute([
                            (int)$r['id'],
                            (string)$r['title'],
                            $r['description'] !== null ? (string)$r['description'] : null,
                            (string)$r['start_date'],
                            !empty($r['end_date']) ? (string)$r['end_date'] : null,
                            !empty($r['start_time']) ? (string)$r['start_time'] : null,
                            !empty($r['location']) ? (string)$r['location'] : null,
                            !empty($r['category']) ? (string)$r['category'] : 'akademik',
                            !empty($r['link_url']) ? (string)$r['link_url'] : null,
                            !empty($r['created_by']) ? (int)$r['created_by'] : null,
                            (int)($r['is_active'] ?? 1),
                        ]);
                    }
                    // Lepas source_ref_id pada hasil migrasi admin agar tidak berbenturan dengan ID baru
                    $apdo->exec("UPDATE `agendas` SET `source_ref_id` = NULL WHERE `source` = 'admin' AND `source_ref_id` IS NOT NULL");
                }
            }
        }
    } catch (Throwable $eMig) {
        // Non-fatal
    }

    return $agInfo;
}

/**
 * Deteksi apakah teks Kaldik merupakan Ujian / Asesmen (dibedakan warnanya dari Kegiatan Sekolah biasa)
 */
function margo_agenda_is_exam_text($text)
{
    $s = strtolower(trim((string)$text));
    if ($s === '') {
        return false;
    }
    return (bool)preg_match('/\b(ujian|asesmen|pts|pas|sas|pat|sumatif|cbt|tryout|try\s*out|ulangan|evaluasi|uts|uas)\b/i', $s);
}

/**
 * Warna default konsisten & kontras jelas untuk setiap status Kaldik
 * (Libur Merah, KBM Hijau, Ujian Kuning Emas, Kegiatan Oranye, Keagamaan Ungu, Libur Semester Abu)
 * dan setiap kategori agenda (Akademik, Rapat, Ujian, Kegiatan, Sistem SSO, Pengumuman, Pribadi)
 */
function margo_agenda_default_color($source, $statusHari = '', $category = 'akademik', $scope = 'all', $titleText = '')
{
    if ((string)$source === 'kaldik') {
        switch ((string)$statusHari) {
            case 'Efektif KBM':
                return '#10B981'; // Hijau Zamrud (KBM Efektif)
            case 'Libur Nasional':
                return '#EF4444'; // Merah (Libur Nasional)
            case 'Libur Semester':
                return '#64748B'; // Abu Slate (Libur Semester / Akhir Pekan)
            case 'Kegiatan Sekolah/Ujian':
                return margo_agenda_is_exam_text($titleText)
                    ? '#F59E0B'   // Kuning Amber (Ujian / Asesmen)
                    : '#EA580C';  // Oranye (Kegiatan Sekolah / P5 / Class Meeting)
            case 'Libur Khusus Keagamaan':
                return '#8B5CF6'; // Ungu (Hari Besar / Libur Keagamaan)
            default:
                return '#10B981';
        }
    }

    $catMap = [
        'akademik'   => '#0D7A87', // Teal Ilmuna
        'rapat'      => '#4F46E5', // Indigo (Rapat / Koordinasi)
        'ujian'      => '#F59E0B', // Kuning Amber (Ujian / Asesmen)
        'kegiatan'   => '#EA580C', // Oranye (Kegiatan)
        'sistem'     => '#1D3B5E', // Navy (Sistem SSO)
        'pengumuman' => '#0284C7', // Sky Blue (Pengumuman)
        'pribadi'    => '#DB2777', // Rose Magenta (Pribadi / Pengingat)
    ];
    $cat = strtolower(trim((string)$category));
    if (isset($catMap[$cat])) {
        return $catMap[$cat];
    }
    if ((string)$scope === 'kelas') {
        return '#0284C7';
    }
    if ((string)$scope === 'private') {
        return '#DB2777';
    }
    return '#0D7A87';
}

/**
 * Validasi hex color (#RRGGBB)
 */
function margo_agenda_normalize_color($rawColor, $fallbackHex)
{
    $c = strtoupper(trim((string)$rawColor));
    if (preg_match('/^#[0-9A-F]{6}$/', $c)) {
        return $c;
    }
    return strtoupper((string)$fallbackHex);
}

/**
 * Pasang Database Triggers Otomatis:
 *   A. Pada `<agenda_db>.agendas`:
 *      - `trg_agendas_lock_kaldik_bu` (BEFORE UPDATE): blokir segala perubahan pada agenda Kaldik di luar bypass trigger
 *      - `trg_agendas_lock_kaldik_bd` (BEFORE DELETE): blokir segala penghapusan pada agenda Kaldik di luar bypass trigger
 *   B. Pada `uofruaju_learn_ilmuna.kalender_pendidikan`:
 *      - `trg_kaldik_to_agenda_ai` (AFTER INSERT) -> SEMUA baris Kaldik (termasuk KBM Reguler) langsung masuk ke `<agenda_db>.agendas`
 *      - `trg_kaldik_to_agenda_au` (AFTER UPDATE) -> langsung perbarui di `<agenda_db>.agendas`
 *      - `trg_kaldik_to_agenda_ad` (AFTER DELETE) -> langsung hapus di `<agenda_db>.agendas`
 */
function margo_agenda_install_kaldik_triggers(PDO $ssoPdo = null)
{
    $result = [
        'agenda_db'            => '',
        'learn_db'             => '',
        'lock_triggers_ok'     => false,
        'kaldik_triggers_ok'   => false,
        'errors'               => [],
    ];

    $agInfo = margo_agenda_ensure_schema($ssoPdo);
    /** @var PDO $apdo */
    $apdo      = $agInfo['pdo'];
    $agendaDb  = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$agInfo['db_name']);
    $result['agenda_db'] = $agendaDb;

    // A. Pasang Trigger Proteksi Read-Only untuk Agenda Kaldik di tabel `agendas`
    try {
        $apdo->exec("DROP TRIGGER IF EXISTS `trg_agendas_lock_kaldik_bu`");
        $apdo->exec("
            CREATE TRIGGER `trg_agendas_lock_kaldik_bu`
            BEFORE UPDATE ON `agendas`
            FOR EACH ROW
            BEGIN
                IF (OLD.source = 'kaldik' OR OLD.is_locked = 1)
                   AND COALESCE(@margo_kaldik_sync_bypass, 0) != 1 THEN
                    SIGNAL SQLSTATE '45000'
                        SET MESSAGE_TEXT = 'Agenda dari Kalender Pendidikan (Kaldik) terkunci permanen dan tidak dapat diubah.';
                END IF;
            END
        ");

        $apdo->exec("DROP TRIGGER IF EXISTS `trg_agendas_lock_kaldik_bd`");
        $apdo->exec("
            CREATE TRIGGER `trg_agendas_lock_kaldik_bd`
            BEFORE DELETE ON `agendas`
            FOR EACH ROW
            BEGIN
                IF (OLD.source = 'kaldik' OR OLD.is_locked = 1)
                   AND COALESCE(@margo_kaldik_sync_bypass, 0) != 1 THEN
                    SIGNAL SQLSTATE '45000'
                        SET MESSAGE_TEXT = 'Agenda dari Kalender Pendidikan (Kaldik) terkunci permanen dan tidak dapat dihapus.';
                END IF;
            END
        ");
        $result['lock_triggers_ok'] = true;
    } catch (Throwable $eLock) {
        $result['errors'][] = 'Lock trigger: ' . $eLock->getMessage();
    }

    // B. Pasang Trigger Real-Time pada `uofruaju_learn_ilmuna.kalender_pendidikan` (SEMUA BARIS TANPA TERKECUALI)
    $learnInfo = margo_get_learn_db($ssoPdo);
    if ($learnInfo === null) {
        $result['errors'][] = 'Database Ilmuna Learn tidak ditemukan / tidak dapat diakses.';
        return $result;
    }

    /** @var PDO $lpdo */
    $lpdo    = $learnInfo['pdo'];
    $learnDb = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$learnInfo['db_name']);
    $result['learn_db'] = $learnDb;

    try {
        $hasKaldik = (bool)$lpdo->query("SHOW TABLES LIKE 'kalender_pendidikan'")->fetch();
        if (!$hasKaldik) {
            $result['errors'][] = 'Tabel kalender_pendidikan tidak ditemukan di ' . $learnDb;
            return $result;
        }

        $titleExpr = "CASE
            WHEN NEW.status_hari = 'Efektif KBM' THEN IF(TRIM(COALESCE(NEW.keterangan, '')) = '', 'Efektif KBM', LEFT(TRIM(NEW.keterangan), 200))
            WHEN TRIM(COALESCE(NEW.keterangan, '')) = '' OR TRIM(COALESCE(NEW.keterangan, '')) = 'KBM Reguler' THEN NEW.status_hari
            ELSE LEFT(TRIM(NEW.keterangan), 200)
        END";

        $isExamSql = "(LOWER(COALESCE(NEW.keterangan, '')) REGEXP 'ujian|asesmen|pts|pas|sas|pat|sumatif|cbt|tryout|ulangan|uts|uas')";

        $catExpr = "CASE
            WHEN NEW.status_hari = 'Efektif KBM' THEN 'kbm'
            WHEN NEW.status_hari = 'Kegiatan Sekolah/Ujian' AND {$isExamSql} THEN 'ujian'
            WHEN NEW.status_hari = 'Kegiatan Sekolah/Ujian' THEN 'kegiatan'
            WHEN NEW.status_hari = 'Libur Nasional' THEN 'libur_nasional'
            WHEN NEW.status_hari = 'Libur Khusus Keagamaan' THEN 'keagamaan'
            WHEN NEW.status_hari = 'Libur Semester' THEN 'libur_semester'
            ELSE 'kaldik'
        END";

        $colorExpr = "CASE
            WHEN NEW.status_hari = 'Efektif KBM' THEN '#10B981'
            WHEN NEW.status_hari = 'Libur Nasional' THEN '#EF4444'
            WHEN NEW.status_hari = 'Libur Semester' THEN '#64748B'
            WHEN NEW.status_hari = 'Kegiatan Sekolah/Ujian' AND {$isExamSql} THEN '#F59E0B'
            WHEN NEW.status_hari = 'Kegiatan Sekolah/Ujian' THEN '#EA580C'
            WHEN NEW.status_hari = 'Libur Khusus Keagamaan' THEN '#8B5CF6'
            ELSE '#10B981'
        END";

        $descExpr = "CONCAT('Agenda resmi Kalender Pendidikan (Kaldik) · TA ', COALESCE(NEW.tahun_pelajaran, '-'), ' Semester ', COALESCE(NEW.semester, '-'), ' · Status: ', NEW.status_hari, IF(TRIM(COALESCE(NEW.keterangan, '')) != '', CONCAT(' — ', TRIM(NEW.keterangan)), ''))";

        $lpdo->exec("DROP TRIGGER IF EXISTS `trg_kaldik_to_agenda_ai`");
        $lpdo->exec("
            CREATE TRIGGER `trg_kaldik_to_agenda_ai`
            AFTER INSERT ON `kalender_pendidikan`
            FOR EACH ROW
            BEGIN
                /* v3_distinct_colors */
                SET @margo_kaldik_sync_bypass = 1;
                INSERT INTO `{$agendaDb}`.`agendas`
                    (`source`, `source_ref_id`, `is_locked`, `title`, `description`, `start_date`, `end_date`, `start_time`, `location`, `category`, `color_hex`, `status_hari`, `tahun_pelajaran`, `semester`, `link_url`, `visibility_scope`, `created_by_name`, `created_by_role`, `is_active`)
                VALUES
                    ('kaldik', NEW.id, 1, {$titleExpr}, {$descExpr}, NEW.tanggal, NEW.tanggal, NULL, 'Kalender Pendidikan Sekolah', {$catExpr}, {$colorExpr}, NEW.status_hari, NEW.tahun_pelajaran, NEW.semester, 'https://learn.ilmuna.my.id', 'all', 'Kalender Pendidikan (Kaldik)', 'kaldik', 1)
                ON DUPLICATE KEY UPDATE
                    `is_locked`        = 1,
                    `title`            = VALUES(`title`),
                    `description`      = VALUES(`description`),
                    `start_date`       = VALUES(`start_date`),
                    `end_date`         = VALUES(`end_date`),
                    `category`         = VALUES(`category`),
                    `color_hex`        = VALUES(`color_hex`),
                    `status_hari`      = VALUES(`status_hari`),
                    `tahun_pelajaran`  = VALUES(`tahun_pelajaran`),
                    `semester`         = VALUES(`semester`),
                    `visibility_scope` = 'all',
                    `is_active`        = 1;
                SET @margo_kaldik_sync_bypass = 0;
            END
        ");

        $lpdo->exec("DROP TRIGGER IF EXISTS `trg_kaldik_to_agenda_au`");
        $lpdo->exec("
            CREATE TRIGGER `trg_kaldik_to_agenda_au`
            AFTER UPDATE ON `kalender_pendidikan`
            FOR EACH ROW
            BEGIN
                /* v3_distinct_colors */
                SET @margo_kaldik_sync_bypass = 1;
                INSERT INTO `{$agendaDb}`.`agendas`
                    (`source`, `source_ref_id`, `is_locked`, `title`, `description`, `start_date`, `end_date`, `start_time`, `location`, `category`, `color_hex`, `status_hari`, `tahun_pelajaran`, `semester`, `link_url`, `visibility_scope`, `created_by_name`, `created_by_role`, `is_active`)
                VALUES
                    ('kaldik', NEW.id, 1, {$titleExpr}, {$descExpr}, NEW.tanggal, NEW.tanggal, NULL, 'Kalender Pendidikan Sekolah', {$catExpr}, {$colorExpr}, NEW.status_hari, NEW.tahun_pelajaran, NEW.semester, 'https://learn.ilmuna.my.id', 'all', 'Kalender Pendidikan (Kaldik)', 'kaldik', 1)
                ON DUPLICATE KEY UPDATE
                    `is_locked`        = 1,
                    `title`            = VALUES(`title`),
                    `description`      = VALUES(`description`),
                    `start_date`       = VALUES(`start_date`),
                    `end_date`         = VALUES(`end_date`),
                    `category`         = VALUES(`category`),
                    `color_hex`        = VALUES(`color_hex`),
                    `status_hari`      = VALUES(`status_hari`),
                    `tahun_pelajaran`  = VALUES(`tahun_pelajaran`),
                    `semester`         = VALUES(`semester`),
                    `visibility_scope` = 'all',
                    `is_active`        = 1;
                SET @margo_kaldik_sync_bypass = 0;
            END
        ");

        $lpdo->exec("DROP TRIGGER IF EXISTS `trg_kaldik_to_agenda_ad`");
        $lpdo->exec("
            CREATE TRIGGER `trg_kaldik_to_agenda_ad`
            AFTER DELETE ON `kalender_pendidikan`
            FOR EACH ROW
            BEGIN
                /* v3_distinct_colors */
                SET @margo_kaldik_sync_bypass = 1;
                DELETE FROM `{$agendaDb}`.`agendas`
                WHERE `source` = 'kaldik' AND `source_ref_id` = OLD.id;
                SET @margo_kaldik_sync_bypass = 0;
            END
        ");

        $result['kaldik_triggers_ok'] = true;
    } catch (Throwable $eTrg) {
        $result['errors'][] = 'Kaldik trigger: ' . $eTrg->getMessage();
    }

    return $result;
}

/**
 * Cek apakah 3 trigger Kaldik versi v2_sync_all_kaldik sudah terpasang di MySQL
 */
function margo_agenda_has_kaldik_triggers(PDO $ssoPdo = null)
{
    $learnInfo = margo_get_learn_db($ssoPdo);
    if ($learnInfo === null) {
        return false;
    }
    try {
        $rows = $learnInfo['pdo']->query("SHOW TRIGGERS LIKE 'kalender_pendidikan'")->fetchAll(PDO::FETCH_ASSOC);
        $byName = [];
        foreach ($rows as $r) {
            $byName[(string)$r['Trigger']] = (string)($r['Statement'] ?? '');
        }
        return isset($byName['trg_kaldik_to_agenda_ai'], $byName['trg_kaldik_to_agenda_au'], $byName['trg_kaldik_to_agenda_ad'])
            && strpos($byName['trg_kaldik_to_agenda_ai'], 'v3_distinct_colors') !== false
            && strpos($byName['trg_kaldik_to_agenda_au'], 'v3_distinct_colors') !== false;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Sinkronisasi Backfill penuh SEMUA baris dari `uofruaju_learn_ilmuna.kalender_pendidikan` -> `<agenda_db>.agendas`
 * (Tanpa terkecuali: Efektif KBM, Libur Nasional, Libur Semester, Kegiatan Sekolah/Ujian, Libur Keagamaan)
 */
function margo_agenda_backfill_from_kaldik(PDO $ssoPdo = null)
{
    $agInfo    = margo_agenda_ensure_schema($ssoPdo);
    $learnInfo = margo_get_learn_db($ssoPdo);
    if ($learnInfo === null) {
        return ['synced' => 0, 'deleted' => 0, 'ok' => false, 'message' => 'Database Ilmuna Learn tidak dapat dihubungi.'];
    }

    /** @var PDO $apdo */
    $apdo = $agInfo['pdo'];
    /** @var PDO $lpdo */
    $lpdo = $learnInfo['pdo'];

    try {
        $rows = $lpdo->query("
            SELECT id, tahun_pelajaran, semester, tanggal, hari, status_hari, keterangan
            FROM `kalender_pendidikan`
            ORDER BY tanggal ASC, id ASC
        ")->fetchAll(PDO::FETCH_ASSOC);

        $apdo->exec("SET @margo_kaldik_sync_bypass = 1");
        $apdo->beginTransaction();

        $validIds = [];
        $stUpsert = $apdo->prepare("
            INSERT INTO `agendas`
                (`source`, `source_ref_id`, `is_locked`, `title`, `description`, `start_date`, `end_date`, `start_time`, `location`, `category`, `color_hex`, `status_hari`, `tahun_pelajaran`, `semester`, `link_url`, `visibility_scope`, `created_by_name`, `created_by_role`, `is_active`)
            VALUES
                ('kaldik', ?, 1, ?, ?, ?, ?, NULL, 'Kalender Pendidikan Sekolah', ?, ?, ?, ?, ?, 'https://learn.ilmuna.my.id', 'all', 'Kalender Pendidikan (Kaldik)', 'kaldik', 1)
            ON DUPLICATE KEY UPDATE
                `is_locked`        = 1,
                `title`            = VALUES(`title`),
                `description`      = VALUES(`description`),
                `start_date`       = VALUES(`start_date`),
                `end_date`         = VALUES(`end_date`),
                `category`         = VALUES(`category`),
                `color_hex`        = VALUES(`color_hex`),
                `status_hari`      = VALUES(`status_hari`),
                `tahun_pelajaran`  = VALUES(`tahun_pelajaran`),
                `semester`         = VALUES(`semester`),
                `visibility_scope` = 'all',
                `is_active`        = 1
        ");

        foreach ($rows as $r) {
            $refId = (int)$r['id'];
            $validIds[] = $refId;
            $ketTrim = trim((string)($r['keterangan'] ?? ''));
            $status  = (string)$r['status_hari'];
            if ($status === 'Efektif KBM') {
                $title = ($ketTrim === '') ? 'Efektif KBM' : margo_mb_substr($ketTrim, 0, 200);
            } else {
                $title = ($ketTrim === '' || $ketTrim === 'KBM Reguler') ? $status : margo_mb_substr($ketTrim, 0, 200);
            }
            if ($status === 'Kegiatan Sekolah/Ujian') {
                $cat = margo_agenda_is_exam_text($ketTrim) ? 'ujian' : 'kegiatan';
            } else {
                $catMap = [
                    'Efektif KBM'            => 'kbm',
                    'Libur Nasional'         => 'libur_nasional',
                    'Libur Khusus Keagamaan' => 'keagamaan',
                    'Libur Semester'         => 'libur_semester',
                ];
                $cat = isset($catMap[$status]) ? $catMap[$status] : 'kaldik';
            }
            $color = margo_agenda_default_color('kaldik', $status, $cat, 'all', $ketTrim);
            $desc  = 'Agenda resmi Kalender Pendidikan (Kaldik) · TA ' . ($r['tahun_pelajaran'] ?: '-')
                   . ' Semester ' . ($r['semester'] ?: '-')
                   . ' · Status: ' . $status
                   . ($ketTrim !== '' ? (' — ' . $ketTrim) : '');

            $stUpsert->execute([
                $refId,
                $title,
                $desc,
                (string)$r['tanggal'],
                (string)$r['tanggal'],
                $cat,
                $color,
                $status,
                (string)($r['tahun_pelajaran'] ?? ''),
                (string)($r['semester'] ?? ''),
            ]);
        }

        // Hapus agenda kaldik lama yang sudah dihapus dari kalender_pendidikan Learn
        $deleted = 0;
        if (!empty($validIds)) {
            $ph = implode(',', array_fill(0, count($validIds), '?'));
            $stDel = $apdo->prepare("DELETE FROM `agendas` WHERE `source` = 'kaldik' AND `source_ref_id` NOT IN ({$ph})");
            $stDel->execute($validIds);
            $deleted = $stDel->rowCount();
        } else {
            $deleted = (int)$apdo->exec("DELETE FROM `agendas` WHERE `source` = 'kaldik'");
        }

        $apdo->commit();
        $apdo->exec("SET @margo_kaldik_sync_bypass = 0");

        return [
            'ok'      => true,
            'synced'  => count($rows),
            'deleted' => $deleted,
            'message' => 'Seluruh ' . count($rows) . ' agenda resmi Kaldik (termasuk KBM, Libur, Ujian & Keagamaan) berhasil disinkronkan ke database ' . $agInfo['db_name'] . '.',
        ];
    } catch (Throwable $e) {
        try {
            if ($apdo->inTransaction()) {
                $apdo->rollBack();
            }
            $apdo->exec("SET @margo_kaldik_sync_bypass = 0");
        } catch (Throwable $e2) {}
        return ['ok' => false, 'synced' => 0, 'deleted' => 0, 'message' => $e->getMessage()];
    }
}

/**
 * Bootstrap otomatis sekali per request / sekali per versi migrasi
 */
function margo_agenda_bootstrap(PDO $ssoPdo = null, $force = false)
{
    static $done = false;
    if ($done && !$force) {
        return;
    }
    $done = true;

    $agInfo = margo_agenda_ensure_schema($ssoPdo);
    $flagFile = sys_get_temp_dir() . '/margo_agenda_kaldik_trg_v3_colors_' . md5($agInfo['db_name']) . '.flag';

    if ($force || !file_exists($flagFile)) {
        $trgRes = margo_agenda_install_kaldik_triggers($ssoPdo);
        margo_agenda_backfill_from_kaldik($ssoPdo);
        if (!empty($trgRes['kaldik_triggers_ok']) || $force) {
            @file_put_contents($flagFile, json_encode($trgRes));
        }
    } else {
        // Pastikan jika tabel masih kosong dari Kaldik, tetap lakukan backfill
        try {
            $kCnt = (int)$agInfo['pdo']->query("SELECT COUNT(*) FROM `agendas` WHERE `source` = 'kaldik'")->fetchColumn();
            if ($kCnt === 0) {
                margo_agenda_backfill_from_kaldik($ssoPdo);
            }
        } catch (Throwable $e) {}
    }
}

/**
 * Ambil daftar lengkap kelas yang diampu oleh Guru yang sedang login
 * Membaca dari database Ilmuna Learn (`guru` -> `guru_mata_pelajaran` -> `kelas_mata_pelajaran` -> `kelas`)
 */
function margo_agenda_get_guru_classes(PDO $ssoPdo, array $userSession)
{
    $learnInfo = margo_get_learn_db($ssoPdo);
    $lpdo = $learnInfo ? $learnInfo['pdo'] : $ssoPdo;

    // Ambil row user lengkap dari SSO DB
    $uRow = $userSession;
    try {
        $stU = $ssoPdo->prepare("SELECT * FROM `users` WHERE `id` = ? LIMIT 1");
        $stU->execute([(int)($userSession['id'] ?? 0)]);
        $dbU = $stU->fetch(PDO::FETCH_ASSOC);
        if ($dbU) {
            $uRow = $dbU;
        }
    } catch (Throwable $e) {}

    // Cari user.id padanan di Learn DB (jika ID berbeda antara DB SSO dan DB Learn)
    $learnUserId = (int)($uRow['id'] ?? 0);
    if ($learnInfo && $lpdo !== $ssoPdo) {
        try {
            $stLu = $lpdo->prepare("
                SELECT id FROM `users`
                WHERE LOWER(username) = LOWER(?)
                   OR (email IS NOT NULL AND email != '' AND LOWER(email) = LOWER(?))
                   OR (email_google IS NOT NULL AND email_google != '' AND LOWER(email_google) = LOWER(?))
                LIMIT 1
            ");
            $stLu->execute([
                (string)($uRow['username'] ?? ''),
                (string)($uRow['email'] ?? ''),
                (string)($uRow['email_google'] ?? $uRow['email'] ?? ''),
            ]);
            $foundLu = (int)$stLu->fetchColumn();
            if ($foundLu > 0) {
                $learnUserId = $foundLu;
            }
        } catch (Throwable $e) {}
    }

    // Resolusi guru.id di Learn DB
    $guruId = 0;
    try {
        $uRowForLearn = $uRow;
        $uRowForLearn['id'] = $learnUserId;
        if (function_exists('margo_resolve_logged_guru')) {
            $gRes = margo_resolve_logged_guru($lpdo, $uRowForLearn);
            if (!empty($gRes['id_guru'])) {
                $guruId = (int)$gRes['id_guru'];
            }
        }
        if ($guruId <= 0 && $learnUserId > 0) {
            $stG = $lpdo->prepare("SELECT id FROM `guru` WHERE `id_user` = ? LIMIT 1");
            $stG->execute([$learnUserId]);
            $guruId = (int)$stG->fetchColumn();
        }
    } catch (Throwable $e) {}

    if ($guruId <= 0) {
        return [];
    }

    $classes = [];
    try {
        $hasKmp = (bool)$lpdo->query("SHOW TABLES LIKE 'kelas_mata_pelajaran'")->fetch();
        $hasGmp = (bool)$lpdo->query("SHOW TABLES LIKE 'guru_mata_pelajaran'")->fetch();
        $hasKls = (bool)$lpdo->query("SHOW TABLES LIKE 'kelas'")->fetch();
        if ($hasKmp && $hasGmp && $hasKls) {
            $hasMp = (bool)$lpdo->query("SHOW TABLES LIKE 'mata_pelajaran'")->fetch();
            if ($hasMp) {
                $st = $lpdo->prepare("
                    SELECT k.id, k.nama_kelas, k.tahun_pelajaran,
                           GROUP_CONCAT(DISTINCT mp.nama_mata_pelajaran ORDER BY mp.nama_mata_pelajaran SEPARATOR ', ') AS mapel_list
                    FROM `kelas_mata_pelajaran` kmp
                    JOIN `kelas` k ON k.id = kmp.id_kelas
                    JOIN `guru_mata_pelajaran` gmp ON gmp.id = kmp.id_guru_mata_pelajaran
                    LEFT JOIN `mata_pelajaran` mp ON mp.id = kmp.id_mata_pelajaran
                    WHERE gmp.id_guru = ?
                    GROUP BY k.id, k.nama_kelas, k.tahun_pelajaran
                    ORDER BY k.nama_kelas ASC
                ");
            } else {
                $st = $lpdo->prepare("
                    SELECT DISTINCT k.id, k.nama_kelas, k.tahun_pelajaran, '' AS mapel_list
                    FROM `kelas_mata_pelajaran` kmp
                    JOIN `kelas` k ON k.id = kmp.id_kelas
                    JOIN `guru_mata_pelajaran` gmp ON gmp.id = kmp.id_guru_mata_pelajaran
                    WHERE gmp.id_guru = ?
                    ORDER BY k.nama_kelas ASC
                ");
            }
            $st->execute([$guruId]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $r) {
                $classes[(int)$r['id']] = [
                    'id'              => (int)$r['id'],
                    'nama_kelas'      => (string)$r['nama_kelas'],
                    'tahun_pelajaran' => (string)($r['tahun_pelajaran'] ?? ''),
                    'mapel_list'      => (string)($r['mapel_list'] ?? ''),
                ];
            }
        }
    } catch (Throwable $e) {
        // Non-fatal
    }

    return array_values($classes);
}

/**
 * Ambil informasi kelas milik Siswa/Ketua Kelas yang sedang login dari Ilmuna Learn
 */
function margo_agenda_get_siswa_kelas(PDO $ssoPdo, array $userSession)
{
    $learnInfo = margo_get_learn_db($ssoPdo);
    $lpdo = $learnInfo ? $learnInfo['pdo'] : $ssoPdo;

    $uRow = $userSession;
    try {
        $stU = $ssoPdo->prepare("SELECT * FROM `users` WHERE `id` = ? LIMIT 1");
        $stU->execute([(int)($userSession['id'] ?? 0)]);
        $dbU = $stU->fetch(PDO::FETCH_ASSOC);
        if ($dbU) {
            $uRow = $dbU;
        }
    } catch (Throwable $e) {}

    if (function_exists('margo_resolve_logged_siswa')) {
        $sInfo = margo_resolve_logged_siswa($lpdo, $uRow);
        if (!empty($sInfo['kelas_id'])) {
            return [
                'kelas_id'   => (int)$sInfo['kelas_id'],
                'nama_kelas' => (string)($sInfo['nama_kelas'] ?? ''),
            ];
        }
        // Fallback juga cek di SSO DB
        if ($lpdo !== $ssoPdo) {
            $sInfo2 = margo_resolve_logged_siswa($ssoPdo, $uRow);
            if (!empty($sInfo2['kelas_id'])) {
                return [
                    'kelas_id'   => (int)$sInfo2['kelas_id'],
                    'nama_kelas' => (string)($sInfo2['nama_kelas'] ?? ''),
                ];
            }
        }
    }
    return ['kelas_id' => null, 'nama_kelas' => null];
}

/**
 * Bangun konteks hak akses agenda untuk pengguna yang sedang login
 */
function margo_agenda_build_user_context(PDO $ssoPdo, array $userSession)
{
    $roles = isset($userSession['roles']) && is_array($userSession['roles'])
        ? array_values(array_unique(array_map('strtolower', $userSession['roles'])))
        : [];
    $isAdmin = in_array('admin', $roles, true);
    $isGuru  = in_array('guru', $roles, true);
    $isSiswa = in_array('siswa', $roles, true) || in_array('ketua_kelas', $roles, true);

    $guruClasses = ($isGuru || $isAdmin) ? margo_agenda_get_guru_classes($ssoPdo, $userSession) : [];
    $siswaKelas  = ($isSiswa || !$isAdmin) ? margo_agenda_get_siswa_kelas($ssoPdo, $userSession) : ['kelas_id' => null, 'nama_kelas' => null];

    // Daftar scope audiens yang diizinkan untuk dibuat oleh user ini
    $allowedScopes = [];
    if ($isAdmin) {
        $allowedScopes = ['all', 'guru', 'siswa', 'private'];
        if (!empty($guruClasses)) {
            $allowedScopes[] = 'kelas';
        }
    } elseif ($isGuru) {
        $allowedScopes = ['private'];
        if (!empty($guruClasses)) {
            $allowedScopes[] = 'kelas';
        }
    } else {
        // Siswa / Ketua Kelas: hanya diketahui oleh dirinya sendiri saja
        $allowedScopes = ['private'];
    }

    return [
        'user_id'        => (int)($userSession['id'] ?? 0),
        'full_name'      => (string)($userSession['full_name'] ?? $userSession['username'] ?? 'Pengguna'),
        'roles'          => $roles,
        'primary_role'   => (string)($userSession['primary_role'] ?? ($roles[0] ?? 'siswa')),
        'is_admin'       => $isAdmin,
        'is_guru'        => $isGuru,
        'is_siswa'       => $isSiswa,
        'guru_classes'   => $guruClasses,
        'siswa_kelas_id' => $siswaKelas['kelas_id'],
        'siswa_kelas'    => $siswaKelas['nama_kelas'],
        'allowed_scopes' => $allowedScopes,
    ];
}

/**
 * Normalisasi 1 baris agenda ke format API/UI yang kaya & konsisten
 */
function margo_agenda_format_row(array $r, array $ctx)
{
    $bulanIndo = ['', 'Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
    $ts = strtotime((string)$r['start_date']);
    $mIdx = $ts ? (int)date('n', $ts) : 1;

    $rawLnk = trim((string)($r['link_url'] ?? ''));
    $resLnk = ($rawLnk === '' || preg_match('#^https?://#i', $rawLnk))
        ? $rawLnk
        : (rtrim(BASE_URL, '/') . '/' . ltrim($rawLnk, '/'));

    $source    = (string)($r['source'] ?? 'admin');
    $isLocked  = ($source === 'kaldik' || (int)($r['is_locked'] ?? 0) === 1);
    $creatorId = isset($r['created_by']) ? (int)$r['created_by'] : 0;
    $userId    = (int)$ctx['user_id'];

    $canEdit = false;
    if (!$isLocked && $userId > 0) {
        if ($ctx['is_admin'] && $source === 'admin') {
            $canEdit = true;
        } elseif ($creatorId > 0 && $creatorId === $userId) {
            $canEdit = true;
        }
    }

    $scope = (string)($r['visibility_scope'] ?? 'all');
    $scopeLabelMap = [
        'all'     => 'Semua Orang',
        'guru'    => 'Khusus Guru',
        'siswa'   => 'Khusus Siswa',
        'kelas'   => !empty($r['target_kelas_name']) ? ('Kelas ' . $r['target_kelas_name']) : 'Kelas Diampu',
        'private' => 'Pribadi (Saya Saja)',
    ];
    $scopeLabel = ($source === 'kaldik')
        ? 'Kaldik · Semua Orang'
        : (isset($scopeLabelMap[$scope]) ? $scopeLabelMap[$scope] : 'Semua Orang');

    $defaultColor = margo_agenda_default_color($source, (string)($r['status_hari'] ?? ''), (string)($r['category'] ?? 'akademik'), $scope, (string)($r['title'] ?? ''));
    $colorHex     = margo_agenda_normalize_color($r['color_hex'] ?? '', $defaultColor);

    return [
        'id'                => (int)$r['id'],
        'source'            => $source,
        'source_ref_id'     => $r['source_ref_id'] !== null ? (int)$r['source_ref_id'] : null,
        'is_locked'         => $isLocked ? 1 : 0,
        'can_edit'          => $canEdit,
        'can_delete'        => $canEdit,
        'title'             => (string)$r['title'],
        'description'       => (string)($r['description'] ?? ''),
        'start_date'        => (string)$r['start_date'],
        'end_date'          => !empty($r['end_date']) ? (string)$r['end_date'] : (string)$r['start_date'],
        'start_time'        => !empty($r['start_time']) ? (string)$r['start_time'] : '',
        'location'          => (string)($r['location'] ?? ''),
        'category'          => (string)($r['category'] ?? 'akademik'),
        'color_hex'         => $colorHex,
        'status_hari'       => (string)($r['status_hari'] ?? ''),
        'tahun_pelajaran'   => (string)($r['tahun_pelajaran'] ?? ''),
        'semester'          => (string)($r['semester'] ?? ''),
        'link_url'          => $resLnk,
        'link_url_raw'      => $rawLnk,
        'visibility_scope'  => $scope,
        'visibility_label'  => $scopeLabel,
        'target_kelas_id'   => $r['target_kelas_id'] !== null ? (int)$r['target_kelas_id'] : null,
        'target_kelas_name' => (string)($r['target_kelas_name'] ?? ''),
        'created_by'        => $creatorId ?: null,
        'created_by_name'   => (string)($r['created_by_name'] ?? ''),
        'created_by_role'   => (string)($r['created_by_role'] ?? ''),
        'is_active'         => (int)($r['is_active'] ?? 1),
        'year'              => $ts ? date('Y', $ts) : '',
        'month_short'       => isset($bulanIndo[$mIdx]) ? $bulanIndo[$mIdx] : '',
        'day_num'           => $ts ? date('d', $ts) : '',
    ];
}

/**
 * Ambil daftar agenda yang BERHAK dilihat oleh pengguna sesuai aturan role & audiens
 *
 * Options:
 *   - 'upcoming' => bool (hanya agenda mendatang untuk Right Sidebar)
 *   - 'month'    => 'YYYY-MM' (opsional)
 *   - 'limit'    => int (default 500)
 *   - 'include_inactive_admin' => bool (khusus panel admin manajemen agenda)
 */
function margo_agenda_fetch_visible(PDO $ssoPdo, array $userSession, array $options = [])
{
    margo_agenda_bootstrap($ssoPdo, false);
    $agInfo = margo_get_agenda_db($ssoPdo);
    /** @var PDO $apdo */
    $apdo = $agInfo['pdo'];

    $ctx = margo_agenda_build_user_context($ssoPdo, $userSession);
    $upcoming = !empty($options['upcoming']);
    $month    = trim((string)($options['month'] ?? ''));
    $limit    = max(1, min(2000, (int)($options['limit'] ?? ($upcoming ? 12 : 1500))));
    $incDraft = !empty($options['include_inactive_admin']) && $ctx['is_admin'];

    $where  = [];
    $params = [];

    if (!$incDraft) {
        $where[] = '`is_active` = 1';
    }

    // Filter visibilitas KETAT berbasis peran
    $visClauses = [
        "`source` = 'kaldik'",
        "`visibility_scope` = 'all'",
    ];

    if ($ctx['user_id'] > 0) {
        $visClauses[] = "(`visibility_scope` = 'private' AND `created_by` = ?)";
        $params[] = $ctx['user_id'];
        $visClauses[] = "(`created_by` = ?)";
        $params[] = $ctx['user_id'];
    }

    if ($ctx['is_admin']) {
        // Admin melihat seluruh agenda resmi (kaldik, all, guru, siswa) + miliknya sendiri (tidak mengintip agenda private guru/siswa lain)
        $visClauses[] = "`visibility_scope` IN ('guru', 'siswa')";
    } else {
        if ($ctx['is_guru']) {
            $visClauses[] = "`visibility_scope` = 'guru'";
        }
        if ($ctx['is_siswa']) {
            $visClauses[] = "`visibility_scope` = 'siswa'";
        }
    }

    // Siswa / Ketua Kelas melihat agenda kelas yang dibuat Guru untuk kelasnya
    if (!empty($ctx['siswa_kelas_id'])) {
        $visClauses[] = "(`visibility_scope` = 'kelas' AND `target_kelas_id` = ?)";
        $params[] = (int)$ctx['siswa_kelas_id'];
    }

    $where[] = '(' . implode(' OR ', $visClauses) . ')';

    if ($month !== '' && preg_match('/^\d{4}-\d{2}$/', $month)) {
        $mStart = $month . '-01';
        $mEnd   = date('Y-m-t', strtotime($mStart));
        $where[]  = "(`start_date` <= ? AND COALESCE(`end_date`, `start_date`) >= ?)";
        $params[] = $mEnd;
        $params[] = $mStart;
    }

    $whereSql = implode(' AND ', $where);

    if ($upcoming) {
        // Untuk widget Right Sidebar: tampilkan agenda mendatang hari ini + kegiatan/rapat/libur/ujian/pribadi/kelas (agar tidak tertutup deretan KBM Reguler rutin)
        $sql = "
            SELECT * FROM `agendas`
            WHERE {$whereSql}
              AND COALESCE(`end_date`, `start_date`) >= CURDATE()
              AND (
                  `source` != 'kaldik'
                  OR `start_date` = CURDATE()
                  OR (`status_hari` != 'Efektif KBM' AND `title` NOT LIKE 'Libur Akhir Pekan%')
                  OR (`status_hari` = 'Efektif KBM' AND `title` != 'KBM Reguler')
              )
            ORDER BY `start_date` ASC, `start_time` ASC, `id` ASC
            LIMIT {$limit}
        ";
        $st = $apdo->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);

        // Jika hasil sedikit / kosong, lengkapi dari semua agenda mendatang termasuk KBM Reguler
        if (empty($rows)) {
            $sqlAllUp = "
                SELECT * FROM `agendas`
                WHERE {$whereSql} AND COALESCE(`end_date`, `start_date`) >= CURDATE()
                ORDER BY `start_date` ASC, `start_time` ASC, `id` ASC
                LIMIT {$limit}
            ";
            $stUp = $apdo->prepare($sqlAllUp);
            $stUp->execute($params);
            $rows = $stUp->fetchAll(PDO::FETCH_ASSOC);
        }

        if (empty($rows)) {
            $sqlFb = "
                SELECT * FROM `agendas`
                WHERE {$whereSql}
                ORDER BY `start_date` DESC, `id` DESC
                LIMIT 6
            ";
            $stFb = $apdo->prepare($sqlFb);
            $stFb->execute($params);
            $rows = $stFb->fetchAll(PDO::FETCH_ASSOC);
        }
    } else {
        $sql = "
            SELECT * FROM `agendas`
            WHERE {$whereSql}
            ORDER BY `start_date` ASC, `start_time` ASC, `id` ASC
            LIMIT {$limit}
        ";
        $st = $apdo->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    }

    $out = [];
    foreach ($rows as $r) {
        $out[] = margo_agenda_format_row($r, $ctx);
    }
    return $out;
}

/**
 * Validasi & simpan (Tambah / Edit) Agenda berdasarkan Role & Aturan Audiens
 * MENOLAK KERAS setiap upaya ubah pada agenda bersumber dari Kaldik!
 */
function margo_agenda_save(PDO $ssoPdo, array $userSession, array $input)
{
    margo_agenda_bootstrap($ssoPdo, false);
    $agInfo = margo_get_agenda_db($ssoPdo);
    /** @var PDO $apdo */
    $apdo = $agInfo['pdo'];
    $ctx  = margo_agenda_build_user_context($ssoPdo, $userSession);

    if ($ctx['user_id'] <= 0) {
        return ['success' => false, 'message' => 'Sesi login diperlukan.'];
    }

    $id        = (int)($input['id'] ?? 0);
    $title     = trim((string)($input['title'] ?? ''));
    $desc      = trim((string)($input['description'] ?? ''));
    $startDate = trim((string)($input['start_date'] ?? ''));
    $endDate   = trim((string)($input['end_date'] ?? ''));
    $startTime = trim((string)($input['start_time'] ?? ''));
    $location  = trim((string)($input['location'] ?? ''));
    $category  = strtolower(trim((string)($input['category'] ?? 'akademik')));
    $linkUrl   = trim((string)($input['link_url'] ?? ''));
    $isActive  = array_key_exists('is_active', $input) ? (!empty($input['is_active']) ? 1 : 0) : 1;

    if ($title === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate)) {
        return ['success' => false, 'message' => 'Judul agenda dan tanggal mulai (YYYY-MM-DD) wajib diisi.'];
    }
    if ($endDate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) {
        $endDate = $startDate;
    }
    if ($endDate < $startDate) {
        $tmp = $startDate;
        $startDate = $endDate;
        $endDate = $tmp;
    }
    if (!in_array($category, ['akademik', 'sistem', 'rapat', 'ujian', 'kegiatan', 'pengumuman', 'pribadi'], true)) {
        $category = 'akademik';
    }

    // Validasi & paksa aturan audiens sesuai Role
    $reqScope   = strtolower(trim((string)($input['visibility_scope'] ?? '')));
    $reqKelasId = (int)($input['target_kelas_id'] ?? 0);
    $kelasName  = null;
    $kelasId    = null;

    if ($ctx['is_admin']) {
        $source = 'admin';
        $roleTag = 'admin';
        if (!in_array($reqScope, ['all', 'guru', 'siswa', 'private', 'kelas'], true)) {
            $reqScope = 'all';
        }
        if ($reqScope === 'kelas') {
            $matchedClass = null;
            foreach ($ctx['guru_classes'] as $kc) {
                if ((int)$kc['id'] === $reqKelasId) {
                    $matchedClass = $kc;
                    break;
                }
            }
            if (!$matchedClass) {
                return ['success' => false, 'message' => 'Pilih kelas yang valid untuk agenda khusus kelas.'];
            }
            $kelasId   = (int)$matchedClass['id'];
            $kelasName = (string)$matchedClass['nama_kelas'];
        }
    } elseif ($ctx['is_guru']) {
        $source  = 'guru';
        $roleTag = 'guru';
        if (!in_array($reqScope, ['private', 'kelas'], true)) {
            $reqScope = 'private';
        }
        if ($reqScope === 'kelas') {
            $matchedClass = null;
            foreach ($ctx['guru_classes'] as $kc) {
                if ((int)$kc['id'] === $reqKelasId) {
                    $matchedClass = $kc;
                    break;
                }
            }
            if (!$matchedClass) {
                return ['success' => false, 'message' => 'Guru hanya dapat membagikan agenda ke kelas yang ia ampu.'];
            }
            $kelasId   = (int)$matchedClass['id'];
            $kelasName = (string)$matchedClass['nama_kelas'];
        }
    } else {
        // Siswa / Ketua Kelas: DIKUNCI KETAT ke 'private' (hanya diketahui oleh dirinya sendiri)
        $source   = 'siswa';
        $roleTag  = $ctx['primary_role'] ?: 'siswa';
        $reqScope = 'private';
        $kelasId  = null;
        $kelasName = null;
    }

    $defColor = margo_agenda_default_color($source, '', $category, $reqScope);
    $colorHex = margo_agenda_normalize_color($input['color_hex'] ?? '', $defColor);

    if ($id > 0) {
        $stEx = $apdo->prepare("SELECT * FROM `agendas` WHERE `id` = ? LIMIT 1");
        $stEx->execute([$id]);
        $existing = $stEx->fetch(PDO::FETCH_ASSOC);
        if (!$existing) {
            return ['success' => false, 'message' => 'Agenda tidak ditemukan.'];
        }
        // PROTEKSI MUTLAK KALDIK
        if ((string)$existing['source'] === 'kaldik' || (int)$existing['is_locked'] === 1) {
            return ['success' => false, 'message' => 'Agenda dari Kalender Pendidikan (Kaldik) bersifat read-only dan tidak dapat diubah!'];
        }
        // Cek kepemilikan
        $isOwner = ((int)($existing['created_by'] ?? 0) === $ctx['user_id']);
        $canEditAdmin = ($ctx['is_admin'] && (string)$existing['source'] === 'admin');
        if (!$isOwner && !$canEditAdmin) {
            return ['success' => false, 'message' => 'Anda tidak memiliki izin untuk mengubah agenda ini.'];
        }

        $stUpd = $apdo->prepare("
            UPDATE `agendas`
            SET `title` = ?, `description` = ?, `start_date` = ?, `end_date` = ?, `start_time` = ?,
                `location` = ?, `category` = ?, `color_hex` = ?, `link_url` = ?, `visibility_scope` = ?,
                `target_kelas_id` = ?, `target_kelas_name` = ?, `is_active` = ?
            WHERE `id` = ? AND `source` != 'kaldik' AND `is_locked` = 0
        ");
        $stUpd->execute([
            margo_mb_substr($title, 0, 200),
            $desc !== '' ? $desc : null,
            $startDate,
            $endDate,
            $startTime !== '' ? margo_mb_substr($startTime, 0, 12) : null,
            $location !== '' ? margo_mb_substr($location, 0, 160) : null,
            $category,
            $colorHex,
            $linkUrl !== '' ? margo_mb_substr($linkUrl, 0, 255) : null,
            $reqScope,
            $kelasId,
            $kelasName,
            $isActive,
            $id,
        ]);

        $stFresh = $apdo->prepare("SELECT * FROM `agendas` WHERE `id` = ? LIMIT 1");
        $stFresh->execute([$id]);
        $fresh = $stFresh->fetch(PDO::FETCH_ASSOC);

        return [
            'success' => true,
            'message' => 'Agenda "' . $title . '" berhasil diperbarui.',
            'data'    => $fresh ? margo_agenda_format_row($fresh, $ctx) : ['id' => $id],
        ];
    }

    $stIns = $apdo->prepare("
        INSERT INTO `agendas`
            (`source`, `source_ref_id`, `is_locked`, `title`, `description`, `start_date`, `end_date`, `start_time`,
             `location`, `category`, `color_hex`, `link_url`, `visibility_scope`, `target_kelas_id`, `target_kelas_name`,
             `created_by`, `created_by_name`, `created_by_role`, `is_active`)
        VALUES
            (?, NULL, 0, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stIns->execute([
        $source,
        margo_mb_substr($title, 0, 200),
        $desc !== '' ? $desc : null,
        $startDate,
        $endDate,
        $startTime !== '' ? margo_mb_substr($startTime, 0, 12) : null,
        $location !== '' ? margo_mb_substr($location, 0, 160) : null,
        $category,
        $colorHex,
        $linkUrl !== '' ? margo_mb_substr($linkUrl, 0, 255) : null,
        $reqScope,
        $kelasId,
        $kelasName,
        $ctx['user_id'],
        margo_mb_substr($ctx['full_name'], 0, 150),
        $roleTag,
        $isActive,
    ]);
    $newId = (int)$apdo->lastInsertId();

    $stFresh = $apdo->prepare("SELECT * FROM `agendas` WHERE `id` = ? LIMIT 1");
    $stFresh->execute([$newId]);
    $fresh = $stFresh->fetch(PDO::FETCH_ASSOC);

    return [
        'success' => true,
        'message' => 'Agenda baru "' . $title . '" berhasil disimpan.',
        'data'    => $fresh ? margo_agenda_format_row($fresh, $ctx) : ['id' => $newId],
    ];
}

/**
 * Hapus Agenda (MENOLAK KERAS setiap penghapusan agenda Kaldik!)
 */
function margo_agenda_delete(PDO $ssoPdo, array $userSession, $id)
{
    margo_agenda_bootstrap($ssoPdo, false);
    $agInfo = margo_get_agenda_db($ssoPdo);
    /** @var PDO $apdo */
    $apdo = $agInfo['pdo'];
    $ctx  = margo_agenda_build_user_context($ssoPdo, $userSession);

    $id = (int)$id;
    if ($id <= 0 || $ctx['user_id'] <= 0) {
        return ['success' => false, 'message' => 'ID agenda tidak valid.'];
    }

    $st = $apdo->prepare("SELECT * FROM `agendas` WHERE `id` = ? LIMIT 1");
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return ['success' => false, 'message' => 'Agenda tidak ditemukan.'];
    }

    // PROTEKSI MUTLAK KALDIK
    if ((string)$row['source'] === 'kaldik' || (int)$row['is_locked'] === 1) {
        return ['success' => false, 'message' => 'Agenda dari Kalender Pendidikan (Kaldik) terkunci permanen dan tidak dapat dihapus!'];
    }

    $isOwner      = ((int)($row['created_by'] ?? 0) === $ctx['user_id']);
    $canDelAdmin  = ($ctx['is_admin'] && (string)$row['source'] === 'admin');
    if (!$isOwner && !$canDelAdmin) {
        return ['success' => false, 'message' => 'Anda tidak memiliki izin untuk menghapus agenda ini.'];
    }

    $apdo->prepare("DELETE FROM `agendas` WHERE `id` = ? AND `source` != 'kaldik' AND `is_locked` = 0")
        ->execute([$id]);

    return ['success' => true, 'message' => 'Agenda "' . $row['title'] . '" berhasil dihapus.'];
}

/**
 * Ambil 1 detail agenda berdasarkan ID dengan verifikasi hak akses role (untuk Lazy-Load Modal Right Sidebar)
 */
function margo_agenda_fetch_by_id(PDO $ssoPdo, array $userSession, $id)
{
    margo_agenda_bootstrap($ssoPdo, false);
    $id = (int)$id;
    if ($id <= 0) {
        return null;
    }
    $agInfo = margo_get_agenda_db($ssoPdo);
    /** @var PDO $apdo */
    $apdo = $agInfo['pdo'];
    $ctx  = margo_agenda_build_user_context($ssoPdo, $userSession);

    $st = $apdo->prepare("SELECT * FROM `agendas` WHERE `id` = ? LIMIT 1");
    $st->execute([$id]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) {
        return null;
    }
    if ((int)($r['is_active'] ?? 1) !== 1 && !$ctx['is_admin']) {
        return null;
    }

    $source = (string)($r['source'] ?? 'admin');
    $scope  = (string)($r['visibility_scope'] ?? 'all');
    $creatorId = (int)($r['created_by'] ?? 0);

    $allowed = false;
    if ($source === 'kaldik' || $scope === 'all') {
        $allowed = true;
    } elseif ($ctx['user_id'] > 0 && $creatorId === $ctx['user_id']) {
        $allowed = true;
    } elseif ($ctx['is_admin'] && in_array($scope, ['guru', 'siswa'], true)) {
        $allowed = true;
    } elseif ($scope === 'guru' && $ctx['is_guru']) {
        $allowed = true;
    } elseif ($scope === 'siswa' && $ctx['is_siswa']) {
        $allowed = true;
    } elseif ($scope === 'kelas' && !empty($ctx['siswa_kelas_id']) && (int)($r['target_kelas_id'] ?? 0) === (int)$ctx['siswa_kelas_id']) {
        $allowed = true;
    }

    return $allowed ? margo_agenda_format_row($r, $ctx) : null;
}

/**
 * Informasi diagnostik Database Agenda & Status Trigger Kaldik (untuk Admin & /database.php)
 */
function margo_agenda_get_status(PDO $ssoPdo = null)
{
    $agInfo = margo_agenda_ensure_schema($ssoPdo);
    /** @var PDO $apdo */
    $apdo = $agInfo['pdo'];
    $counts = ['total' => 0, 'kaldik' => 0, 'admin' => 0, 'guru' => 0, 'siswa' => 0];
    try {
        $rows = $apdo->query("SELECT `source`, COUNT(*) AS c FROM `agendas` GROUP BY `source`")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $src = (string)$r['source'];
            $cnt = (int)$r['c'];
            if (isset($counts[$src])) {
                $counts[$src] = $cnt;
            }
            $counts['total'] += $cnt;
        }
    } catch (Throwable $e) {}

    return [
        'db_name'             => $agInfo['db_name'],
        'is_dedicated'        => $agInfo['is_dedicated'],
        'kaldik_triggers_ok'  => margo_agenda_has_kaldik_triggers($ssoPdo),
        'counts'              => $counts,
    ];
}
