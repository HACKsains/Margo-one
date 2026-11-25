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
            `source` VARCHAR(32) NOT NULL DEFAULT 'admin',
            `source_ref_id` BIGINT NULL DEFAULT NULL,
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
            `visibility_scope` VARCHAR(32) NOT NULL DEFAULT 'all',
            `target_kelas_id` INT NULL DEFAULT NULL,
            `target_kelas_name` VARCHAR(120) NULL DEFAULT NULL,
            `target_mapel_id` INT NULL DEFAULT NULL,
            `target_mapel_name` VARCHAR(150) NULL DEFAULT NULL,
            `target_user_ids` TEXT NULL DEFAULT NULL,
            `target_user_names` TEXT NULL DEFAULT NULL,
            `target_group_key` VARCHAR(120) NULL DEFAULT NULL,
            `target_group_name` VARCHAR(200) NULL DEFAULT NULL,
            `created_by` INT NULL DEFAULT NULL,
            `created_by_name` VARCHAR(150) NULL DEFAULT NULL,
            `created_by_role` VARCHAR(30) NULL DEFAULT NULL,
            `is_active` TINYINT(1) NOT NULL DEFAULT 1,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY `uq_agenda_source_ref` (`source`(28), `source_ref_id`),
            INDEX `idx_agenda_dates` (`is_active`, `start_date`, `end_date`),
            INDEX `idx_agenda_scope` (`visibility_scope`, `target_kelas_id`),
            INDEX `idx_agenda_creator` (`created_by`, `source`(20))
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // Migrasi skema tabel agendas eksisting: source ENUM -> VARCHAR(32), visibility_scope ENUM -> VARCHAR(32), kolom mapel/peer/kelompok
    try {
        $agCols = $apdo->query("SHOW COLUMNS FROM `agendas`")->fetchAll(PDO::FETCH_ASSOC);
        $colMap = [];
        foreach ($agCols as $col) {
            $colMap[strtolower((string)$col['Field'])] = strtolower((string)($col['Type'] ?? ''));
        }

        if (isset($colMap['source']) && strpos($colMap['source'], 'enum') === 0) {
            $apdo->exec("ALTER TABLE `agendas` MODIFY COLUMN `source` VARCHAR(32) NOT NULL DEFAULT 'admin'");
        }
        if (isset($colMap['visibility_scope']) && strpos($colMap['visibility_scope'], 'enum') === 0) {
            $apdo->exec("ALTER TABLE `agendas` MODIFY COLUMN `visibility_scope` VARCHAR(32) NOT NULL DEFAULT 'all'");
        }
        if (isset($colMap['source_ref_id']) && strpos($colMap['source_ref_id'], 'bigint') === false) {
            $apdo->exec("ALTER TABLE `agendas` MODIFY COLUMN `source_ref_id` BIGINT NULL DEFAULT NULL");
        }
        if (!isset($colMap['color_hex'])) {
            $apdo->exec("ALTER TABLE `agendas` ADD COLUMN `color_hex` VARCHAR(20) NULL DEFAULT NULL AFTER `category`");
        }
        if (!isset($colMap['target_mapel_id'])) {
            $apdo->exec("ALTER TABLE `agendas` ADD COLUMN `target_mapel_id` INT NULL DEFAULT NULL AFTER `target_kelas_name`");
        }
        if (!isset($colMap['target_mapel_name'])) {
            $apdo->exec("ALTER TABLE `agendas` ADD COLUMN `target_mapel_name` VARCHAR(150) NULL DEFAULT NULL AFTER `target_mapel_id`");
        }
        if (!isset($colMap['target_user_ids'])) {
            $apdo->exec("ALTER TABLE `agendas` ADD COLUMN `target_user_ids` TEXT NULL DEFAULT NULL AFTER `target_mapel_name`");
        }
        if (!isset($colMap['target_user_names'])) {
            $apdo->exec("ALTER TABLE `agendas` ADD COLUMN `target_user_names` TEXT NULL DEFAULT NULL AFTER `target_user_ids`");
        }
        if (!isset($colMap['target_group_key'])) {
            $apdo->exec("ALTER TABLE `agendas` ADD COLUMN `target_group_key` VARCHAR(120) NULL DEFAULT NULL AFTER `target_user_names`");
        }
        if (!isset($colMap['target_group_name'])) {
            $apdo->exec("ALTER TABLE `agendas` ADD COLUMN `target_group_name` VARCHAR(200) NULL DEFAULT NULL AFTER `target_group_key`");
        }
    } catch (Throwable $eCol) {
        error_log('[MargoOne] Agenda schema migration warning: ' . $eCol->getMessage());
    }

    // Tabel Notifikasi In-App Agenda (Kelas/Mapel, Sesama Guru, Sesama Siswa, Kelompok Tugas/Proyek)
    try {
        $apdo->exec("
            CREATE TABLE IF NOT EXISTS `agenda_notifications` (
                `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
                `agenda_id` INT NOT NULL,
                `recipient_user_id` INT NOT NULL,
                `sender_user_id` INT NULL DEFAULT NULL,
                `sender_name` VARCHAR(150) NOT NULL DEFAULT '',
                `scope_type` VARCHAR(32) NOT NULL DEFAULT 'kelas',
                `badge_label` VARCHAR(80) NOT NULL DEFAULT 'AGENDA BARU',
                `title` VARCHAR(220) NOT NULL,
                `body` TEXT NULL,
                `meta_label` VARCHAR(220) NULL DEFAULT NULL,
                `start_date` DATE NOT NULL,
                `start_time` VARCHAR(12) NULL DEFAULT NULL,
                `url` VARCHAR(255) NULL DEFAULT NULL,
                `is_read` TINYINT(1) NOT NULL DEFAULT 0,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY `uq_ag_notif_user` (`agenda_id`, `recipient_user_id`),
                INDEX `idx_ag_notif_recip` (`recipient_user_id`, `created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } catch (Throwable $eNotifTbl) {
        error_log('[MargoOne] Agenda notifications schema warning: ' . $eNotifTbl->getMessage());
    }

    // Bersihkan sekali data dummy bawaan lama (yang created_by IS NULL) agar agenda 100% murni dari database nyata (Kaldik + input pengguna)
    try {
        $dummyTitles = [
            'Sinkronisasi Akun & Tautan Google SSO Ekosistem Ilmuna',
            'Koordinasi Pembelajaran & Evaluasi Kurikulum PAI',
            'Persiapan Asesmen Sumatif & Uji Coba CBT Exam Browser',
            'Audit Berkala & Rotasi Kunci JWT RS256 Margo One',
        ];
        $phD = implode(',', array_fill(0, count($dummyTitles), '?'));
        $stClean = $apdo->prepare("DELETE FROM `agendas` WHERE `source` != 'kaldik' AND `created_by` IS NULL AND `title` IN ({$phD})");
        $stClean->execute($dummyTitles);

        if ($ssoPdo !== null && (bool)$ssoPdo->query("SHOW TABLES LIKE 'sso_agendas'")->fetch()) {
            $stCleanOld = $ssoPdo->prepare("DELETE FROM `sso_agendas` WHERE `created_by` IS NULL AND `title` IN ({$phD})");
            $stCleanOld->execute($dummyTitles);
        }
    } catch (Throwable $eClean) {
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
    $agendaDb  = preg_replace('/[^a-zA-Z0-9_\-]/', '', (string)$agInfo['db_name']);
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
    $learnDb = preg_replace('/[^a-zA-Z0-9_\-]/', '', (string)$learnInfo['db_name']);
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
        $sourceDbUsed = (string)$learnInfo['db_name'];
        $rows = $lpdo->query("
            SELECT id, tahun_pelajaran, semester, tanggal, hari, status_hari, keterangan
            FROM `kalender_pendidikan`
            ORDER BY tanggal ASC, id ASC
        ")->fetchAll(PDO::FETCH_ASSOC);

        // Jika kalender_pendidikan di DB default kosong, cek apakah ada schema lain yang bisa diakses dengan kalender_pendidikan terisi
        if (empty($rows)) {
            try {
                $altSchemas = $lpdo->query("
                    SELECT TABLE_SCHEMA
                    FROM INFORMATION_SCHEMA.TABLES
                    WHERE TABLE_NAME = 'kalender_pendidikan'
                      AND TABLE_SCHEMA NOT IN ('information_schema', 'mysql', 'performance_schema', 'sys')
                ")->fetchAll(PDO::FETCH_COLUMN);
                foreach ($altSchemas as $sch) {
                    $schClean = preg_replace('/[^a-zA-Z0-9_\-]/', '', (string)$sch);
                    if ($schClean === '' || $schClean === $sourceDbUsed) {
                        continue;
                    }
                    try {
                        $candRows = $lpdo->query("
                            SELECT id, tahun_pelajaran, semester, tanggal, hari, status_hari, keterangan
                            FROM `{$schClean}`.`kalender_pendidikan`
                            ORDER BY tanggal ASC, id ASC
                        ")->fetchAll(PDO::FETCH_ASSOC);
                        if (!empty($candRows)) {
                            $rows = $candRows;
                            $sourceDbUsed = $schClean;
                            break;
                        }
                    } catch (Throwable $eAltQ) {}
                }
            } catch (Throwable $eAltS) {}
        }

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

        $syncedCount = count($rows);
        $msg = $syncedCount > 0
            ? ('Seluruh ' . $syncedCount . ' agenda resmi Kaldik (dari ' . $sourceDbUsed . '.kalender_pendidikan) berhasil disinkronkan ke database ' . $agInfo['db_name'] . '.')
            : ('Sinkronisasi selesai, tetapi tabel ' . $sourceDbUsed . '.kalender_pendidikan masih berisi 0 baris. Pastikan Kaldik di Ilmuna Learn sudah disimpan ke MySQL database ' . $sourceDbUsed . '.');

        return [
            'ok'        => true,
            'synced'    => $syncedCount,
            'deleted'   => $deleted,
            'source_db' => $sourceDbUsed,
            'message'   => $msg,
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
        $stU = $ssoPdo->prepare("SELECT * FROM `sso_users` WHERE `id` = ? LIMIT 1");
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
        $stU = $ssoPdo->prepare("SELECT * FROM `sso_users` WHERE `id` = ? LIMIT 1");
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
 * Helper: ambil daftar user SSO berdasarkan role (mendukung tabel `sso_users` maupun `users`)
 */
function margo_agenda_fetch_sso_users_by_role(PDO $ssoPdo, array $roleCodes, $excludeUserId = 0)
{
    $roleCodes = array_values(array_unique(array_filter(array_map('strtolower', $roleCodes))));
    if (empty($roleCodes)) {
        return [];
    }
    $excludeUserId = (int)$excludeUserId;
    $inR = implode(',', array_fill(0, count($roleCodes), '?'));

    $combos = [
        ['sso_users', 'sso_user_roles', 'sso_roles'],
        ['users', 'user_roles', 'roles'],
    ];

    foreach ($combos as $c) {
        list($uTbl, $urTbl, $rTbl) = $c;
        try {
            $hasU = (bool)$ssoPdo->query("SHOW TABLES LIKE '{$uTbl}'")->fetch();
            $hasUr = (bool)$ssoPdo->query("SHOW TABLES LIKE '{$urTbl}'")->fetch();
            $hasR = (bool)$ssoPdo->query("SHOW TABLES LIKE '{$rTbl}'")->fetch();
            if (!$hasU || !$hasUr || !$hasR) {
                continue;
            }
            $urCols = $ssoPdo->query("SHOW COLUMNS FROM `{$urTbl}`")->fetchAll(PDO::FETCH_COLUMN);
            $rCols  = $ssoPdo->query("SHOW COLUMNS FROM `{$rTbl}`")->fetchAll(PDO::FETCH_COLUMN);
            $uidCol = in_array('user_id', $urCols, true) ? 'user_id' : 'id_user';
            $ridCol = in_array('role_id', $urCols, true) ? 'role_id' : 'id_roles';
            $codeCol = in_array('code', $rCols, true) ? 'code' : (in_array('slug', $rCols, true) ? 'slug' : 'code');

            $sql = "
                SELECT DISTINCT u.id, u.username, u.full_name,
                       COALESCE(NULLIF(u.email_google, ''), u.email, '') AS email,
                       COALESCE(u.nisn_nip, '') AS nisn_nip
                FROM `{$uTbl}` u
                JOIN `{$urTbl}` ur ON ur.`{$uidCol}` = u.id
                JOIN `{$rTbl}` r ON r.id = ur.`{$ridCol}`
                WHERE LOWER(r.`{$codeCol}`) IN ({$inR})
                  AND (? <= 0 OR u.id <> ?)
                ORDER BY u.full_name ASC
            ";
            $st = $ssoPdo->prepare($sql);
            $st->execute(array_merge($roleCodes, [$excludeUserId, $excludeUserId]));
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            $out = [];
            foreach ($rows as $r) {
                $out[] = [
                    'user_id'   => (int)$r['id'],
                    'username'  => (string)($r['username'] ?? ''),
                    'full_name' => (string)($r['full_name'] ?: ($r['username'] ?? 'Pengguna')),
                    'email'     => strtolower(trim((string)($r['email'] ?? ''))),
                    'nisn_nip'  => trim((string)($r['nisn_nip'] ?? '')),
                ];
            }
            return $out;
        } catch (Throwable $e) {
            continue;
        }
    }
    return [];
}

/**
 * Helper: cocokan daftar identitas Learn (guru/siswa/anggota kelompok) ke user_id SSO
 */
function margo_agenda_build_sso_lookup(array $ssoUsers)
{
    $byKey = [];
    foreach ($ssoUsers as $u) {
        $uid = (int)$u['user_id'];
        if ($uid <= 0) {
            continue;
        }
        $keys = [
            strtolower(trim((string)($u['username'] ?? ''))),
            strtolower(trim((string)($u['email'] ?? ''))),
            strtolower(trim((string)($u['nisn_nip'] ?? ''))),
        ];
        foreach ($keys as $k) {
            if ($k !== '' && !isset($byKey[$k])) {
                $byKey[$k] = $u;
            }
        }
    }
    return $byKey;
}

/**
 * Helper: ambil daftar SSO user_id siswa yang berada pada kelas-kelas tertentu (untuk notifikasi)
 */
function margo_agenda_resolve_kelas_student_sso_ids(PDO $ssoPdo, array $kelasIds, $excludeUserId = 0)
{
    $kelasIds = array_values(array_unique(array_filter(array_map('intval', $kelasIds))));
    if (empty($kelasIds)) {
        return [];
    }
    $ssoSiswa = margo_agenda_fetch_sso_users_by_role($ssoPdo, ['siswa', 'ketua_kelas'], $excludeUserId);
    if (empty($ssoSiswa)) {
        return [];
    }
    $lookup = margo_agenda_build_sso_lookup($ssoSiswa);

    $matchedIds = [];
    $learnInfo = margo_get_learn_db($ssoPdo);
    if ($learnInfo) {
        try {
            $inK = implode(',', array_fill(0, count($kelasIds), '?'));
            $st = $learnInfo['pdo']->prepare("
                SELECT nis, nisn, COALESCE(gmail, '') AS email
                FROM `siswa`
                WHERE `id_kelas` IN ({$inK})
            ");
            $st->execute($kelasIds);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $sr) {
                foreach (['nis', 'nisn', 'email'] as $col) {
                    $k = strtolower(trim((string)($sr[$col] ?? '')));
                    if ($k !== '' && isset($lookup[$k])) {
                        $matchedIds[(int)$lookup[$k]['user_id']] = (int)$lookup[$k]['user_id'];
                        break;
                    }
                }
            }
        } catch (Throwable $e) {}
    }

    return array_values($matchedIds);
}

/**
 * Helper: simpan notifikasi In-App ke `agenda_notifications` + kirim Web Push ke penerima agenda
 */
function margo_agenda_dispatch_notifications(PDO $ssoPdo, PDO $apdo, array $agendaRow, array $ctx, array $recipientUserIds)
{
    $senderId   = (int)($ctx['user_id'] ?? 0);
    $senderName = (string)($ctx['full_name'] ?? 'Pengguna');
    $agendaId   = (int)($agendaRow['id'] ?? 0);
    $scope      = (string)($agendaRow['visibility_scope'] ?? 'private');

    if ($agendaId <= 0 || $scope === 'private') {
        return 0;
    }

    $recips = [];
    foreach ($recipientUserIds as $rid) {
        $rid = (int)$rid;
        if ($rid > 0 && $rid !== $senderId) {
            $recips[$rid] = $rid;
        }
    }
    $recips = array_values($recips);
    if (empty($recips)) {
        return 0;
    }

    $badgeMap = [
        'kelas'       => 'AGENDA KELAS & MAPEL',
        'semua_kelas' => 'AGENDA SEMUA KELAS',
        'peer_guru'   => 'AGENDA SESAMA GURU',
        'peer_siswa'  => 'AGENDA SESAMA SISWA',
        'kelompok'    => 'AGENDA KELOMPOK',
        'guru'        => 'AGENDA GURU',
        'siswa'       => 'AGENDA SISWA',
        'all'         => 'AGENDA EKOSISTEM',
    ];
    $badge = $badgeMap[$scope] ?? 'AGENDA BARU';

    $metaParts = [];
    if (!empty($agendaRow['target_kelas_name'])) {
        $metaParts[] = (string)$agendaRow['target_kelas_name'];
    }
    if (!empty($agendaRow['target_mapel_name'])) {
        $metaParts[] = 'Mapel: ' . (string)$agendaRow['target_mapel_name'];
    }
    if (!empty($agendaRow['target_group_name'])) {
        $metaParts[] = (string)$agendaRow['target_group_name'];
    }
    if (empty($metaParts) && !empty($agendaRow['target_user_names'])) {
        $metaParts[] = (string)$agendaRow['target_user_names'];
    }
    $metaLabel = implode(' · ', $metaParts);

    $title     = (string)($agendaRow['title'] ?? 'Agenda Baru');
    $startDate = (string)($agendaRow['start_date'] ?? date('Y-m-d'));
    $startTime = trim((string)($agendaRow['start_time'] ?? ''));
    $desc      = trim((string)($agendaRow['description'] ?? ''));
    $dateInfo  = $startDate . ($startTime !== '' ? (' pukul ' . substr($startTime, 0, 5) . ' WIB') : '');
    $bodyText  = ($senderName !== '' ? ($senderName . ' membuat agenda: ') : '') . $title
        . ($metaLabel !== '' ? (' (' . $metaLabel . ')') : '')
        . ' pada ' . $dateInfo
        . ($desc !== '' ? (' — ' . margo_mb_substr($desc, 0, 180)) : '');
    $url       = '/agenda?id=' . $agendaId;

    // 1. Simpan notifikasi in-app persisten
    try {
        $stN = $apdo->prepare("
            INSERT INTO `agenda_notifications`
                (`agenda_id`, `recipient_user_id`, `sender_user_id`, `sender_name`, `scope_type`,
                 `badge_label`, `title`, `body`, `meta_label`, `start_date`, `start_time`, `url`, `is_read`)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0)
            ON DUPLICATE KEY UPDATE
                `title`      = VALUES(`title`),
                `body`       = VALUES(`body`),
                `meta_label` = VALUES(`meta_label`),
                `start_date` = VALUES(`start_date`),
                `start_time` = VALUES(`start_time`),
                `is_read`    = 0,
                `created_at` = CURRENT_TIMESTAMP
        ");
        foreach ($recips as $rid) {
            $stN->execute([
                $agendaId,
                $rid,
                $senderId ?: null,
                margo_mb_substr($senderName, 0, 150),
                $scope,
                $badge,
                margo_mb_substr($title, 0, 220),
                $bodyText,
                $metaLabel !== '' ? margo_mb_substr($metaLabel, 0, 220) : null,
                $startDate,
                $startTime !== '' ? margo_mb_substr($startTime, 0, 12) : null,
                $url,
            ]);
        }
    } catch (Throwable $eInApp) {
        error_log('[MargoOne] In-app agenda notif warning: ' . $eInApp->getMessage());
    }

    // 2. Kirim Web Push Notification (non-blocking, batasi maksimal 40 penerima agar respons tetap cepat)
    $pushFile = BASE_PATH . '/includes/webpush-engine.php';
    if (file_exists($pushFile)) {
        try {
            require_once $pushFile;
            if (function_exists('margo_push_send_to_user')) {
                $pushPayload = [
                    'title'    => '📅 ' . $badge . ': ' . $title,
                    'body'     => margo_mb_substr($bodyText, 0, 220),
                    'category' => 'agenda_' . $scope,
                    'tag'      => 'agenda-new-' . $agendaId,
                    'url'      => rtrim(BASE_URL, '/') . $url,
                    'notif_id' => 'ag_invite_' . $agendaId,
                ];
                $dedupKey = 'ag_new_' . $agendaId . '_' . md5($startDate . '|' . $title);
                $pushLimit = array_slice($recips, 0, 40);
                foreach ($pushLimit as $rid) {
                    @margo_push_send_to_user($ssoPdo, $rid, $pushPayload, $dedupKey);
                }
            }
        } catch (Throwable $ePush) {}
    }

    return count($recips);
}

/**
 * Ambil daftar notifikasi agenda khusus milik user (dipanggil oleh portal-data.php?resource=notifications)
 */
function margo_agenda_fetch_user_notifications(PDO $ssoPdo, $userId, $limit = 20)
{
    $userId = (int)$userId;
    if ($userId <= 0) {
        return [];
    }
    try {
        $agInfo = margo_agenda_ensure_schema($ssoPdo);
        $apdo   = $agInfo['pdo'];
        $lim    = max(1, min(50, (int)$limit));
        $st = $apdo->prepare("
            SELECT n.*
            FROM `agenda_notifications` n
            JOIN `agendas` a ON a.id = n.agenda_id AND a.is_active = 1
            WHERE n.recipient_user_id = ?
            ORDER BY n.created_at DESC, n.id DESC
            LIMIT {$lim}
        ");
        $st->execute([$userId]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Bangun konteks hak akses agenda untuk pengguna yang sedang login
 */
function margo_agenda_build_user_context(PDO $ssoPdo, array $userSession, $includeOptions = true)
{
    $roles = isset($userSession['roles']) && is_array($userSession['roles'])
        ? array_values(array_unique(array_map('strtolower', $userSession['roles'])))
        : [];
    $isAdmin = in_array('admin', $roles, true);
    $isGuru  = in_array('guru', $roles, true);
    $isSiswa = in_array('siswa', $roles, true) || in_array('ketua_kelas', $roles, true);
    $uidCtx  = (int)($userSession['id'] ?? 0);

    // 1. Tarik opsi terpadu dari Ilmuna Learn (api/sso-broadcast.php?resource=agenda_options)
    $learnOpts = [];
    if ($includeOptions) {
        $bridgeFile = BASE_PATH . '/includes/learn-realtime-bridge.php';
        if (file_exists($bridgeFile)) {
            try {
                require_once $bridgeFile;
                if (function_exists('margo_learn_fetch_agenda_options')) {
                    $optWrap = margo_learn_fetch_agenda_options($ssoPdo, $userSession, false);
                    if (!empty($optWrap['ok']) && is_array($optWrap['data'])) {
                        $learnOpts = $optWrap['data'];
                    }
                }
            } catch (Throwable $eOpt) {}
        }
    }

    // 2. Kelas & Mapel yang diampu Guru (prioritas dari API Learn, fallback query langsung Learn DB)
    $guruClasses  = [];
    $guruMapelAll = [];
    if ($isGuru || $isAdmin) {
        if (!empty($learnOpts['guru_classes']) && is_array($learnOpts['guru_classes'])) {
            $guruClasses  = $learnOpts['guru_classes'];
            $guruMapelAll = is_array($learnOpts['guru_mapel_all'] ?? null) ? $learnOpts['guru_mapel_all'] : [];
        } else {
            $guruClasses = margo_agenda_get_guru_classes($ssoPdo, $userSession);
        }
    }

    // 3. Info Kelas Siswa
    $siswaKelas = ($isSiswa || !$isAdmin) ? margo_agenda_get_siswa_kelas($ssoPdo, $userSession) : ['kelas_id' => null, 'nama_kelas' => null];
    if (empty($siswaKelas['kelas_id']) && !empty($learnOpts['identity_context']['siswa']['id_kelas'])) {
        $siswaKelas['kelas_id']   = (int)$learnOpts['identity_context']['siswa']['id_kelas'];
        $siswaKelas['nama_kelas'] = (string)($learnOpts['identity_context']['siswa']['nama_kelas'] ?? '');
    }
    if ($uidCtx > 0 && empty($siswaKelas['kelas_id'])) {
        if (!empty($_SESSION['margo_learn_kelas_ids_' . $uidCtx][0])) {
            $siswaKelas['kelas_id']   = (int)$_SESSION['margo_learn_kelas_ids_' . $uidCtx][0];
            $siswaKelas['nama_kelas'] = (string)($_SESSION['margo_learn_kelas_nama_' . $uidCtx] ?? ($siswaKelas['nama_kelas'] ?? ''));
        } else {
            $kCacheFile = sys_get_temp_dir() . '/margo_learn_user_kelas_' . $uidCtx . '.json';
            if (file_exists($kCacheFile)) {
                $kCache = json_decode((string)@file_get_contents($kCacheFile), true);
                if (!empty($kCache['kelas_ids'][0])) {
                    $siswaKelas['kelas_id']   = (int)$kCache['kelas_ids'][0];
                    $siswaKelas['nama_kelas'] = (string)($kCache['nama_kelas'] ?? ($siswaKelas['nama_kelas'] ?? ''));
                }
            }
        }
    }

    // 4. Daftar Sesama Guru (untuk Guru / Admin) — gabungkan user SSO ber-role guru + metadata dari Learn API
    $peersGuru = [];
    if ($includeOptions && ($isGuru || $isAdmin)) {
        $ssoGurus = margo_agenda_fetch_sso_users_by_role($ssoPdo, ['guru'], $uidCtx);
        $learnGuruByKey = [];
        foreach (($learnOpts['peers_guru'] ?? []) as $lg) {
            foreach (['nip', 'username', 'email'] as $kCol) {
                $kv = strtolower(trim((string)($lg[$kCol] ?? '')));
                if ($kv !== '' && !isset($learnGuruByKey[$kv])) {
                    $learnGuruByKey[$kv] = $lg;
                }
            }
        }
        foreach ($ssoGurus as $sg) {
            $meta = null;
            foreach (['nisn_nip', 'username', 'email'] as $kCol) {
                $kv = strtolower(trim((string)($sg[$kCol] ?? '')));
                if ($kv !== '' && isset($learnGuruByKey[$kv])) {
                    $meta = $learnGuruByKey[$kv];
                    break;
                }
            }
            $peersGuru[] = [
                'user_id'   => (int)$sg['user_id'],
                'full_name' => (string)$sg['full_name'],
                'nip'       => (string)($meta['nip'] ?? $sg['nisn_nip'] ?? ''),
                'kode_guru' => (string)($meta['kode_guru'] ?? ''),
                'email'     => (string)$sg['email'],
            ];
        }
    }

    // 5. Daftar Sesama Siswa & Kelompok Tugas/Proyek (untuk Siswa)
    $peersSiswa          = [];
    $ketuaKelompok       = [];
    $anggotaKelompokKeys = is_array($learnOpts['anggota_kelompok_keys'] ?? null)
        ? array_values(array_unique(array_filter(array_map('strval', $learnOpts['anggota_kelompok_keys']))))
        : [];

    if ($includeOptions && $isSiswa) {
        $ssoSiswas = margo_agenda_fetch_sso_users_by_role($ssoPdo, ['siswa', 'ketua_kelas'], $uidCtx);
        $ssoSiswaAll = margo_agenda_fetch_sso_users_by_role($ssoPdo, ['siswa', 'ketua_kelas'], 0);
        $ssoLookupAll = margo_agenda_build_sso_lookup($ssoSiswaAll);

        $learnSiswaByKey = [];
        foreach (($learnOpts['peers_siswa'] ?? []) as $ls) {
            foreach (['nis', 'nisn', 'email'] as $kCol) {
                $kv = strtolower(trim((string)($ls[$kCol] ?? '')));
                if ($kv !== '' && !isset($learnSiswaByKey[$kv])) {
                    $learnSiswaByKey[$kv] = $ls;
                }
            }
        }

        foreach ($ssoSiswas as $ss) {
            $meta = null;
            foreach (['username', 'nisn_nip', 'email'] as $kCol) {
                $kv = strtolower(trim((string)($ss[$kCol] ?? '')));
                if ($kv !== '' && isset($learnSiswaByKey[$kv])) {
                    $meta = $learnSiswaByKey[$kv];
                    break;
                }
            }
            $sameClass = !empty($meta['same_class']);
            $peersSiswa[] = [
                'user_id'    => (int)$ss['user_id'],
                'full_name'  => (string)$ss['full_name'],
                'nis'        => (string)($meta['nis'] ?? $ss['username'] ?? ''),
                'nama_kelas' => (string)($meta['nama_kelas'] ?? ''),
                'same_class' => $sameClass,
            ];
        }
        usort($peersSiswa, function ($a, $b) {
            if ($a['same_class'] !== $b['same_class']) {
                return $a['same_class'] ? -1 : 1;
            }
            return strcasecmp($a['full_name'], $b['full_name']);
        });

        // Kelompok di mana siswa ini adalah KETUA KELOMPOK (resolve SSO user_id tiap anggota)
        foreach (($learnOpts['ketua_kelompok'] ?? []) as $kk) {
            $memberSsoIds = [];
            foreach (($kk['anggota'] ?? []) as $ag) {
                foreach (['nis', 'nisn', 'email'] as $kCol) {
                    $kv = strtolower(trim((string)($ag[$kCol] ?? '')));
                    if ($kv !== '' && isset($ssoLookupAll[$kv])) {
                        $mid = (int)$ssoLookupAll[$kv]['user_id'];
                        $memberSsoIds[$mid] = $mid;
                        break;
                    }
                }
            }
            $kk['member_sso_ids'] = array_values($memberSsoIds);
            $ketuaKelompok[] = $kk;
        }
    }

    // Daftar scope audiens yang diizinkan untuk dibuat oleh user ini
    $allowedScopes = [];
    if ($isAdmin) {
        $allowedScopes = ['all', 'guru', 'siswa', 'peer_guru', 'private'];
        if (!empty($guruClasses)) {
            $allowedScopes[] = 'kelas';
            $allowedScopes[] = 'semua_kelas';
        }
    } elseif ($isGuru) {
        $allowedScopes = ['private', 'peer_guru'];
        if (!empty($guruClasses)) {
            $allowedScopes[] = 'kelas';
            $allowedScopes[] = 'semua_kelas';
        }
    } else {
        // Siswa: pribadi, sesama siswa, dan kelompok (khusus jika menjadi Ketua Kelompok Tugas/Proyek)
        $allowedScopes = ['private', 'peer_siswa'];
        if (!empty($ketuaKelompok)) {
            $allowedScopes[] = 'kelompok';
        }
    }

    return [
        'user_id'               => $uidCtx,
        'full_name'             => (string)($userSession['full_name'] ?? $userSession['username'] ?? 'Pengguna'),
        'roles'                 => $roles,
        'primary_role'          => (string)($userSession['primary_role'] ?? ($roles[0] ?? 'siswa')),
        'is_admin'              => $isAdmin,
        'is_guru'               => $isGuru,
        'is_siswa'              => $isSiswa,
        'guru_classes'          => $guruClasses,
        'guru_mapel_all'        => $guruMapelAll,
        'peers_guru'            => $peersGuru,
        'peers_siswa'           => $peersSiswa,
        'ketua_kelompok'        => $ketuaKelompok,
        'anggota_kelompok_keys' => $anggotaKelompokKeys,
        'siswa_kelas_id'        => $siswaKelas['kelas_id'],
        'siswa_kelas'           => $siswaKelas['nama_kelas'],
        'allowed_scopes'        => $allowedScopes,
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
    // Semua agenda bersumber dari Ilmuna Learn (source mulai 'learn_') SELALU terkunci (is_locked = 1)
    $isLearnSource = (strpos($source, 'learn_') === 0);
    $isLocked  = ($source === 'kaldik' || $isLearnSource || (int)($r['is_locked'] ?? 0) === 1);
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
    $mapelSuffix = !empty($r['target_mapel_name']) ? (' · ' . (string)$r['target_mapel_name']) : '';
    $scopeLabelMap = [
        'all'         => 'Semua Orang',
        'guru'        => 'Khusus Guru',
        'siswa'       => 'Khusus Siswa',
        'kelas'       => (!empty($r['target_kelas_name']) ? ('Kelas ' . $r['target_kelas_name']) : 'Kelas Diampu') . $mapelSuffix,
        'semua_kelas' => (!empty($r['target_kelas_name']) ? (string)$r['target_kelas_name'] : 'Semua Kelas') . $mapelSuffix,
        'peer_guru'   => !empty($r['target_user_names']) ? ('Sesama Guru · ' . $r['target_user_names']) : 'Sesama Guru',
        'peer_siswa'  => !empty($r['target_user_names']) ? ('Sesama Siswa · ' . $r['target_user_names']) : 'Sesama Siswa',
        'kelompok'    => !empty($r['target_group_name']) ? ('Kelompok · ' . $r['target_group_name']) : 'Kelompok Tugas/Proyek',
        'private'     => 'Pribadi (Saya Saja)',
    ];

    $rawTargetUids = trim((string)($r['target_user_ids'] ?? ''), ', ');
    $targetUserIdsArr = [];
    if ($rawTargetUids !== '') {
        foreach (explode(',', $rawTargetUids) as $pUid) {
            $iv = (int)trim($pUid);
            if ($iv > 0) {
                $targetUserIdsArr[] = $iv;
            }
        }
    }

    // Label sumber yang lebih informatif untuk agenda dari Ilmuna Learn
    $learnSourceLabels = [
        'learn_tugas_buat'      => 'Ilmuna Learn · Tugas (Buat)',
        'learn_tugas_deadline'  => 'Ilmuna Learn · Deadline Tugas',
        'learn_proyek_buat'     => 'Ilmuna Learn · Proyek (Mulai)',
        'learn_proyek_deadline' => 'Ilmuna Learn · Deadline Proyek',
        'learn_asesmen'         => 'Ilmuna Learn · Jadwal Asesmen',
    ];

    if ($source === 'kaldik') {
        $scopeLabel = 'Kaldik · Semua Orang';
    } elseif (isset($learnSourceLabels[$source])) {
        $kNm = !empty($r['target_kelas_name']) ? (' · Kelas ' . $r['target_kelas_name']) : '';
        $scopeLabel = $learnSourceLabels[$source] . $kNm;
    } else {
        $scopeLabel = isset($scopeLabelMap[$scope]) ? $scopeLabelMap[$scope] : 'Semua Orang';
    }

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
        'target_mapel_id'   => isset($r['target_mapel_id']) && $r['target_mapel_id'] !== null ? (int)$r['target_mapel_id'] : null,
        'target_mapel_name' => (string)($r['target_mapel_name'] ?? ''),
        'target_user_ids'   => $targetUserIdsArr,
        'target_user_names' => (string)($r['target_user_names'] ?? ''),
        'target_group_key'  => (string)($r['target_group_key'] ?? ''),
        'target_group_name' => (string)($r['target_group_name'] ?? ''),
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
    static $syncingLearn = false;
    margo_agenda_bootstrap($ssoPdo, false);

    if (!$syncingLearn && empty($options['skip_learn_sync'])) {
        $bridgeFile = BASE_PATH . '/includes/learn-realtime-bridge.php';
        if (file_exists($bridgeFile)) {
            $syncingLearn = true;
            try {
                require_once $bridgeFile;
                if (function_exists('margo_learn_sync_class_agendas')) {
                    @margo_learn_sync_class_agendas($ssoPdo, $userSession, false);
                }
            } catch (Throwable $eLS) {
                // Non-fatal
            }
            $syncingLearn = false;
        }
    }

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

    // Siswa / Ketua Kelas melihat agenda kelas & semua_kelas yang ditujukan ke kelasnya
    if (!empty($ctx['siswa_kelas_id'])) {
        $skId = (int)$ctx['siswa_kelas_id'];
        $visClauses[] = "(`visibility_scope` = 'kelas' AND `target_kelas_id` = ?)";
        $params[] = $skId;
        $visClauses[] = "(`visibility_scope` = 'semua_kelas' AND FIND_IN_SET(?, REPLACE(COALESCE(`target_group_key`, ''), 'kelas_ids:', '')) > 0)";
        $params[] = (string)$skId;
    }

    // Peer-to-peer (Sesama Guru, Sesama Siswa) & Kelompok berdasarkan target_user_ids (,id,)
    if ($ctx['user_id'] > 0) {
        $visClauses[] = "(`visibility_scope` IN ('peer_guru', 'peer_siswa', 'kelompok') AND `target_user_ids` LIKE ?)";
        $params[] = '%,' . (int)$ctx['user_id'] . ',%';
    }

    // Anggota Kelompok Tugas/Proyek melihat agenda kelompok berdasarkan target_group_key
    if (!empty($ctx['anggota_kelompok_keys']) && is_array($ctx['anggota_kelompok_keys'])) {
        $gkList = array_values(array_unique(array_filter(array_map('strval', $ctx['anggota_kelompok_keys']))));
        if (!empty($gkList)) {
            $gkIn = implode(',', array_fill(0, count($gkList), '?'));
            $visClauses[] = "(`visibility_scope` = 'kelompok' AND `target_group_key` IN ({$gkIn}))";
            foreach ($gkList as $gkVal) {
                $params[] = $gkVal;
            }
        }
    }

    // Guru melihat agenda kelas untuk SEMUA kelas yang ia ampu (termasuk agenda Learn yang tersinkron)
    if (!$ctx['is_admin'] && $ctx['is_guru'] && !empty($ctx['guru_classes'])) {
        $guruKelasIds = [];
        foreach ($ctx['guru_classes'] as $kc) {
            if (!empty($kc['id'])) {
                $guruKelasIds[] = (int)$kc['id'];
            }
        }
        $guruKelasIds = array_values(array_unique($guruKelasIds));
        if (!empty($guruKelasIds)) {
            $gIn = implode(',', array_fill(0, count($guruKelasIds), '?'));
            $visClauses[] = "(`visibility_scope` = 'kelas' AND `target_kelas_id` IN ({$gIn}))";
            foreach ($guruKelasIds as $gkId) {
                $params[] = $gkId;
            }
        }
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
    $reqScope     = strtolower(trim((string)($input['visibility_scope'] ?? '')));
    $rawKelasIn   = trim((string)($input['target_kelas_id'] ?? ''));
    $reqKelasId   = ($rawKelasIn === 'all') ? 0 : (int)$rawKelasIn;
    $reqMapelId   = (int)($input['target_mapel_id'] ?? 0);
    $reqMapelName = trim((string)($input['target_mapel_name'] ?? ''));
    $reqGroupKey  = trim((string)($input['target_group_key'] ?? ''));

    // Parse target_user_ids (array atau comma-separated string)
    $rawUids = $input['target_user_ids'] ?? [];
    if (is_string($rawUids)) {
        $decU = json_decode($rawUids, true);
        $rawUids = is_array($decU) ? $decU : explode(',', $rawUids);
    }
    $reqTargetUids = [];
    foreach ((array)$rawUids as $ru) {
        $iv = (int)trim((string)$ru);
        if ($iv > 0 && $iv !== (int)$ctx['user_id']) {
            $reqTargetUids[$iv] = $iv;
        }
    }
    $reqTargetUids = array_values($reqTargetUids);

    $kelasId         = null;
    $kelasName       = null;
    $mapelId         = null;
    $mapelName       = null;
    $targetUserIdsDb = null;
    $targetUserNames = null;
    $targetGroupKey  = null;
    $targetGroupName = null;
    $notifRecipients = [];

    // Helper validasi Kelas & Mapel untuk Guru / Admin
    $resolveKelasMapel = function($isAllKelas) use ($ctx, $reqKelasId, $reqMapelId, $reqMapelName) {
        if ($isAllKelas) {
            if (empty($ctx['guru_classes'])) {
                return ['ok' => false, 'message' => 'Anda belum memiliki kelas yang diampu di Ilmuna Learn.'];
            }
            $matchedMapel = null;
            foreach (($ctx['guru_mapel_all'] ?? []) as $gm) {
                if (($reqMapelId > 0 && (int)$gm['id_mapel'] === $reqMapelId)
                    || ($reqMapelName !== '' && strcasecmp((string)$gm['nama_mapel'], $reqMapelName) === 0)
                ) {
                    $matchedMapel = $gm;
                    break;
                }
            }
            // Jika hanya ada 1 mapel di semua kelas, bisa auto-pilih; jika tidak, wajib pilih mapel
            if (!$matchedMapel && !empty($ctx['guru_mapel_all'])) {
                return ['ok' => false, 'message' => 'Pilih Mata Pelajaran terlebih dahulu untuk agenda Semua Kelas.'];
            }
            $kIds = [];
            $kNames = [];
            $allowedKidSet = $matchedMapel && !empty($matchedMapel['kelas_ids'])
                ? array_map('intval', $matchedMapel['kelas_ids'])
                : [];
            foreach ($ctx['guru_classes'] as $kc) {
                $kid = (int)$kc['id'];
                if (empty($allowedKidSet) || in_array($kid, $allowedKidSet, true)) {
                    $kIds[]   = $kid;
                    $kNames[] = (string)$kc['nama_kelas'];
                }
            }
            $kLbl = count($kNames) <= 3
                ? ('Semua Kelas (' . implode(', ', $kNames) . ')')
                : ('Semua Kelas (' . count($kNames) . ' Rombel)');
            return [
                'ok'         => true,
                'kelas_id'   => null,
                'kelas_ids'  => $kIds,
                'kelas_name' => $kLbl,
                'group_key'  => 'kelas_ids:' . implode(',', $kIds),
                'mapel_id'   => $matchedMapel ? (int)$matchedMapel['id_mapel'] : ($reqMapelId ?: null),
                'mapel_name' => $matchedMapel ? (string)$matchedMapel['nama_mapel'] : ($reqMapelName ?: null),
            ];
        }

        $matchedClass = null;
        foreach (($ctx['guru_classes'] ?? []) as $kc) {
            if ((int)$kc['id'] === $reqKelasId) {
                $matchedClass = $kc;
                break;
            }
        }
        if (!$matchedClass) {
            return ['ok' => false, 'message' => 'Pilih Kelas yang Anda ampu terlebih dahulu.'];
        }
        $classMapels = is_array($matchedClass['mapel'] ?? null) ? $matchedClass['mapel'] : [];
        $matchedMapel = null;
        if (!empty($classMapels)) {
            foreach ($classMapels as $mp) {
                if (($reqMapelId > 0 && (int)$mp['id_mapel'] === $reqMapelId)
                    || ($reqMapelName !== '' && strcasecmp((string)$mp['nama_mapel'], $reqMapelName) === 0)
                ) {
                    $matchedMapel = $mp;
                    break;
                }
            }
            if (!$matchedMapel) {
                return ['ok' => false, 'message' => 'Pilih Mata Pelajaran pada kelas ' . $matchedClass['nama_kelas'] . ' terlebih dahulu.'];
            }
        }
        return [
            'ok'         => true,
            'kelas_id'   => (int)$matchedClass['id'],
            'kelas_ids'  => [(int)$matchedClass['id']],
            'kelas_name' => (string)$matchedClass['nama_kelas'],
            'group_key'  => null,
            'mapel_id'   => $matchedMapel ? (int)$matchedMapel['id_mapel'] : ($reqMapelId ?: null),
            'mapel_name' => $matchedMapel ? (string)$matchedMapel['nama_mapel'] : ($reqMapelName ?: null),
        ];
    };

    // Jika user memilih scope 'kelas' tapi target_kelas_id = 'all', konversi ke 'semua_kelas'
    if ($reqScope === 'kelas' && $rawKelasIn === 'all') {
        $reqScope = 'semua_kelas';
    }

    if ($ctx['is_admin']) {
        $source = 'admin';
        $roleTag = 'admin';
        if (!in_array($reqScope, ['all', 'guru', 'siswa', 'private', 'kelas', 'semua_kelas', 'peer_guru'], true)) {
            $reqScope = 'all';
        }
        if ($reqScope === 'kelas' || $reqScope === 'semua_kelas') {
            $kmRes = $resolveKelasMapel($reqScope === 'semua_kelas');
            if (!$kmRes['ok']) {
                return ['success' => false, 'message' => $kmRes['message']];
            }
            $kelasId        = $kmRes['kelas_id'];
            $kelasName      = $kmRes['kelas_name'];
            $targetGroupKey = $kmRes['group_key'];
            $mapelId        = $kmRes['mapel_id'];
            $mapelName      = $kmRes['mapel_name'];
            $notifRecipients = margo_agenda_resolve_kelas_student_sso_ids($ssoPdo, $kmRes['kelas_ids'], $ctx['user_id']);
        } elseif ($reqScope === 'peer_guru') {
            $validPeers = [];
            $peerNames  = [];
            $peerById = [];
            foreach (($ctx['peers_guru'] ?? []) as $pg) {
                $peerById[(int)$pg['user_id']] = $pg;
            }
            foreach ($reqTargetUids as $ru) {
                if (isset($peerById[$ru])) {
                    $validPeers[] = $ru;
                    $peerNames[]  = (string)$peerById[$ru]['full_name'];
                }
            }
            if (empty($validPeers)) {
                return ['success' => false, 'message' => 'Pilih minimal 1 guru penerima agenda.'];
            }
            $targetUserIdsDb = ',' . implode(',', $validPeers) . ',';
            $targetUserNames = count($peerNames) <= 3 ? implode(', ', $peerNames) : ($peerNames[0] . ', ' . $peerNames[1] . ' +' . (count($peerNames) - 2) . ' guru');
            $notifRecipients = $validPeers;
        } elseif ($reqScope === 'guru') {
            foreach (margo_agenda_fetch_sso_users_by_role($ssoPdo, ['guru'], $ctx['user_id']) as $gu) {
                $notifRecipients[] = (int)$gu['user_id'];
            }
        } elseif ($reqScope === 'siswa') {
            foreach (margo_agenda_fetch_sso_users_by_role($ssoPdo, ['siswa', 'ketua_kelas'], $ctx['user_id']) as $su) {
                $notifRecipients[] = (int)$su['user_id'];
            }
        } elseif ($reqScope === 'all') {
            foreach (margo_agenda_fetch_sso_users_by_role($ssoPdo, ['guru', 'siswa', 'ketua_kelas'], $ctx['user_id']) as $au) {
                $notifRecipients[] = (int)$au['user_id'];
            }
        }
    } elseif ($ctx['is_guru']) {
        $source  = 'guru';
        $roleTag = 'guru';
        if (!in_array($reqScope, ['private', 'kelas', 'semua_kelas', 'peer_guru'], true)) {
            $reqScope = 'private';
        }
        if ($reqScope === 'kelas' || $reqScope === 'semua_kelas') {
            $kmRes = $resolveKelasMapel($reqScope === 'semua_kelas');
            if (!$kmRes['ok']) {
                return ['success' => false, 'message' => $kmRes['message']];
            }
            $kelasId        = $kmRes['kelas_id'];
            $kelasName      = $kmRes['kelas_name'];
            $targetGroupKey = $kmRes['group_key'];
            $mapelId        = $kmRes['mapel_id'];
            $mapelName      = $kmRes['mapel_name'];
            $notifRecipients = margo_agenda_resolve_kelas_student_sso_ids($ssoPdo, $kmRes['kelas_ids'], $ctx['user_id']);
        } elseif ($reqScope === 'peer_guru') {
            $validPeers = [];
            $peerNames  = [];
            $peerById = [];
            foreach (($ctx['peers_guru'] ?? []) as $pg) {
                $peerById[(int)$pg['user_id']] = $pg;
            }
            foreach ($reqTargetUids as $ru) {
                if (isset($peerById[$ru])) {
                    $validPeers[] = $ru;
                    $peerNames[]  = (string)$peerById[$ru]['full_name'];
                }
            }
            if (empty($validPeers)) {
                return ['success' => false, 'message' => 'Pilih minimal 1 sesama guru penerima agenda.'];
            }
            $targetUserIdsDb = ',' . implode(',', $validPeers) . ',';
            $targetUserNames = count($peerNames) <= 3 ? implode(', ', $peerNames) : ($peerNames[0] . ', ' . $peerNames[1] . ' +' . (count($peerNames) - 2) . ' guru');
            $notifRecipients = $validPeers;
        }
    } else {
        // Siswa / Ketua Kelas: pribadi, sesama siswa, atau kelompok tugas/proyek (khusus Ketua Kelompok!)
        $source  = 'siswa';
        $roleTag = $ctx['primary_role'] ?: 'siswa';
        if (!in_array($reqScope, ['private', 'peer_siswa', 'kelompok'], true)) {
            $reqScope = 'private';
        }
        if ($reqScope === 'peer_siswa') {
            $validPeers = [];
            $peerNames  = [];
            $peerById = [];
            foreach (($ctx['peers_siswa'] ?? []) as $ps) {
                $peerById[(int)$ps['user_id']] = $ps;
            }
            foreach ($reqTargetUids as $ru) {
                if (isset($peerById[$ru])) {
                    $validPeers[] = $ru;
                    $peerNames[]  = (string)$peerById[$ru]['full_name'];
                }
            }
            if (empty($validPeers)) {
                return ['success' => false, 'message' => 'Pilih minimal 1 sesama siswa penerima agenda.'];
            }
            $targetUserIdsDb = ',' . implode(',', $validPeers) . ',';
            $targetUserNames = count($peerNames) <= 3 ? implode(', ', $peerNames) : ($peerNames[0] . ', ' . $peerNames[1] . ' +' . (count($peerNames) - 2) . ' siswa');
            $notifRecipients = $validPeers;
        } elseif ($reqScope === 'kelompok') {
            $matchedGroup = null;
            foreach (($ctx['ketua_kelompok'] ?? []) as $kk) {
                if ((string)$kk['group_key'] === $reqGroupKey) {
                    $matchedGroup = $kk;
                    break;
                }
            }
            if (!$matchedGroup) {
                return ['success' => false, 'message' => 'Hanya Ketua Kelompok Tugas/Proyek yang dapat membuat agenda untuk kelompoknya.'];
            }
            $targetGroupKey  = (string)$matchedGroup['group_key'];
            $sumberLbl       = ((string)$matchedGroup['sumber'] === 'proyek') ? 'Proyek' : 'Tugas';
            $targetGroupName = margo_mb_substr($matchedGroup['nama_kelompok'] . ' · ' . $sumberLbl . ': ' . $matchedGroup['judul_induk'], 0, 200);
            $mapelName       = !empty($matchedGroup['nama_mapel']) ? (string)$matchedGroup['nama_mapel'] : null;
            $kelasName       = !empty($matchedGroup['nama_kelas']) ? (string)$matchedGroup['nama_kelas'] : null;

            $memberSsoIds = array_values(array_unique(array_filter(array_map('intval', $matchedGroup['member_sso_ids'] ?? []))));
            if (!empty($memberSsoIds)) {
                $targetUserIdsDb = ',' . implode(',', $memberSsoIds) . ',';
            }
            $memNames = [];
            foreach (($matchedGroup['anggota'] ?? []) as $ag) {
                if (!empty($ag['nama'])) {
                    $memNames[] = (string)$ag['nama'];
                }
            }
            $targetUserNames = !empty($memNames) ? margo_mb_substr(implode(', ', $memNames), 0, 250) : null;
            $notifRecipients = $memberSsoIds;
        }
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
                `target_kelas_id` = ?, `target_kelas_name` = ?, `target_mapel_id` = ?, `target_mapel_name` = ?,
                `target_user_ids` = ?, `target_user_names` = ?, `target_group_key` = ?, `target_group_name` = ?,
                `is_active` = ?
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
            $mapelId,
            $mapelName !== null ? margo_mb_substr($mapelName, 0, 150) : null,
            $targetUserIdsDb,
            $targetUserNames,
            $targetGroupKey,
            $targetGroupName,
            $isActive,
            $id,
        ]);

        $stFresh = $apdo->prepare("SELECT * FROM `agendas` WHERE `id` = ? LIMIT 1");
        $stFresh->execute([$id]);
        $fresh = $stFresh->fetch(PDO::FETCH_ASSOC);

        $notifiedCnt = 0;
        if ($fresh && !empty($notifRecipients)) {
            $notifiedCnt = margo_agenda_dispatch_notifications($ssoPdo, $apdo, $fresh, $ctx, $notifRecipients);
        }

        return [
            'success'  => true,
            'message'  => 'Agenda "' . $title . '" berhasil diperbarui' . ($notifiedCnt > 0 ? (' & notifikasi dikirim ke ' . $notifiedCnt . ' penerima.') : '.'),
            'notified' => $notifiedCnt,
            'data'     => $fresh ? margo_agenda_format_row($fresh, $ctx) : ['id' => $id],
        ];
    }

    $stIns = $apdo->prepare("
        INSERT INTO `agendas`
            (`source`, `source_ref_id`, `is_locked`, `title`, `description`, `start_date`, `end_date`, `start_time`,
             `location`, `category`, `color_hex`, `link_url`, `visibility_scope`,
             `target_kelas_id`, `target_kelas_name`, `target_mapel_id`, `target_mapel_name`,
             `target_user_ids`, `target_user_names`, `target_group_key`, `target_group_name`,
             `created_by`, `created_by_name`, `created_by_role`, `is_active`)
        VALUES
            (?, NULL, 0, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
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
        $mapelId,
        $mapelName !== null ? margo_mb_substr($mapelName, 0, 150) : null,
        $targetUserIdsDb,
        $targetUserNames,
        $targetGroupKey,
        $targetGroupName,
        $ctx['user_id'],
        margo_mb_substr($ctx['full_name'], 0, 150),
        $roleTag,
        $isActive,
    ]);
    $newId = (int)$apdo->lastInsertId();

    $stFresh = $apdo->prepare("SELECT * FROM `agendas` WHERE `id` = ? LIMIT 1");
    $stFresh->execute([$newId]);
    $fresh = $stFresh->fetch(PDO::FETCH_ASSOC);

    $notifiedCnt = 0;
    if ($fresh && !empty($notifRecipients)) {
        $notifiedCnt = margo_agenda_dispatch_notifications($ssoPdo, $apdo, $fresh, $ctx, $notifRecipients);
    }

    return [
        'success'  => true,
        'message'  => 'Agenda baru "' . $title . '" berhasil disimpan' . ($notifiedCnt > 0 ? (' & notifikasi dikirim ke ' . $notifiedCnt . ' penerima.') : '.'),
        'notified' => $notifiedCnt,
        'data'     => $fresh ? margo_agenda_format_row($fresh, $ctx) : ['id' => $newId],
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
    $isLearnSrc = (strpos($source, 'learn_') === 0);
    if ($source === 'kaldik' || $scope === 'all' || $isLearnSrc) {
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
    } elseif ($scope === 'semua_kelas' && !empty($ctx['siswa_kelas_id'])) {
        $kList = explode(',', str_replace('kelas_ids:', '', (string)($r['target_group_key'] ?? '')));
        if (in_array((string)$ctx['siswa_kelas_id'], array_map('trim', $kList), true)) {
            $allowed = true;
        }
    } elseif ($scope === 'kelas' && $ctx['is_guru'] && !empty($ctx['guru_classes'])) {
        $tid = (int)($r['target_kelas_id'] ?? 0);
        foreach ($ctx['guru_classes'] as $kc) {
            if ((int)$kc['id'] === $tid) {
                $allowed = true;
                break;
            }
        }
    } elseif (in_array($scope, ['peer_guru', 'peer_siswa', 'kelompok'], true) && $ctx['user_id'] > 0) {
        if (strpos((string)($r['target_user_ids'] ?? ''), ',' . (int)$ctx['user_id'] . ',') !== false) {
            $allowed = true;
        } elseif ($scope === 'kelompok' && !empty($r['target_group_key']) && in_array((string)$r['target_group_key'], $ctx['anggota_kelompok_keys'] ?? [], true)) {
            $allowed = true;
        }
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
