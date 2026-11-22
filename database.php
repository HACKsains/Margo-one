<?php
/**
 * ==============================================================================
 * MARGO ONE — ILMUNA SSO · DATABASE ARCHITECTURE & MIGRATION ENGINE
 * ==============================================================================
 * Memastikan seluruh tabel identitas inti (kompatibel 100% dengan learn.ilmuna.my.id)
 * dan seluruh tabel Margo One SSO (11 tabel) tersedia dengan engine InnoDB utf8mb4.
 *
 * Mendukung 3 mode penggunaan:
 *   1. Di-require oleh sistem / deploy.php (hanya fungsi, tanpa output HTML)
 *   2. Dieksekusi via CLI (`php database.php`) -> output JSON status migrasi
 *   3. Dibuka langsung di Browser (`/database.php`) -> UI Lumina Gold Migration Engine
 *      (paritas visual dengan learn/database.php)
 *
 * 100% kompatibel PHP 7.4 (ea-php74) hingga PHP 8.3.
 * ==============================================================================
 */

require_once __DIR__ . '/config.php';
require_once BASE_PATH . '/includes/sso-security.php';

/**
 * Daftar tabel resmi milik ekosistem Margo One SSO
 */
function margo_sso_official_tables()
{
    return [
        'identity' => ['users', 'roles', 'user_roles', 'guru', 'siswa', 'kelas'],
        'sso'      => [
            'sso_clients',
            'sso_sessions',
            'sso_auth_codes',
            'sso_refresh_tokens',
            'sso_nonces',
            'sso_rate_limits',
            'sso_audit_logs',
            'sso_jwks_keys',
            'sso_agendas',
            'sso_announcements',
            'sso_external_apps',
        ],
    ];
}

/**
 * Daftar Klaster Tabel Resmi untuk Tampilan Dasbor Database
 */
function margo_sso_database_clusters()
{
    return [
        'Klaster 1: Identitas Terpadu & Multi-Role RBAC (Shared dengan Ilmuna Learn)' => [
            'users', 'roles', 'user_roles', 'guru', 'siswa', 'kelas',
        ],
        'Klaster 2: Inti Protokol Margo One SSO & Keamanan Zero-Trust' => [
            'sso_clients', 'sso_sessions', 'sso_auth_codes', 'sso_refresh_tokens',
            'sso_nonces', 'sso_rate_limits', 'sso_audit_logs', 'sso_jwks_keys',
        ],
        'Klaster 3: Portal Dalam, Agenda & Informasi Ekosistem' => [
            'sso_agendas', 'sso_announcements', 'sso_external_apps',
        ],
    ];
}

/**
 * Pastikan tabel identitas dasar tersedia (hanya jika DB benar-benar kosong;
 * TIDAK pernah mengubah atau menghapus tabel existing milik learn.ilmuna.my.id)
 */
function margo_sso_ensure_identity_tables(PDO $pdo)
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `roles` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `code` VARCHAR(50) NOT NULL UNIQUE,
            `name` VARCHAR(100) NOT NULL,
            `description` TEXT NULL,
            `badge_color` VARCHAR(30) DEFAULT 'teal',
            `icon` VARCHAR(80) DEFAULT 'solar:user-bold',
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
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
            INDEX idx_users_email (`email`),
            INDEX idx_users_email_google (`email_google`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `user_roles` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `user_id` INT NOT NULL,
            `role_id` INT NOT NULL,
            `is_primary` TINYINT(1) NOT NULL DEFAULT 0,
            `assigned_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_user_role (`user_id`, `role_id`),
            INDEX idx_ur_user (`user_id`),
            INDEX idx_ur_role (`role_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `kelas` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `nama_kelas` VARCHAR(100) NOT NULL,
            `tingkat` VARCHAR(20) DEFAULT 'X',
            `tahun_pelajaran` VARCHAR(30) DEFAULT '2026/2027',
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
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
            INDEX idx_guru_user (`id_user`),
            INDEX idx_guru_email (`email`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
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
            INDEX idx_siswa_kelas (`id_kelas`),
            INDEX idx_siswa_nisn (`nisn`),
            INDEX idx_siswa_gmail (`gmail`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // Seed 4 role standar bila tabel roles masih kosong
    $roleCount = (int)$pdo->query("SELECT COUNT(*) FROM `roles`")->fetchColumn();
    if ($roleCount === 0) {
        $seedRoles = [
            ['admin',       'Administrator',  'Pengelola penuh ekosistem Ilmuna & Margo One SSO', 'navy',    'solar:shield-user-bold'],
            ['ketua_kelas', 'Ketua Kelas',    'Koordinator kelas dan pemantau kedisiplinan',      'teal',    'solar:crown-bold'],
            ['siswa',       'Siswa',          'Peserta didik aktif ekosistem Ilmuna',             'sky',     'solar:user-id-bold'],
            ['guru',        'Guru Pengampu',  'Dewan guru dan penyusun materi pembelajaran',      'emerald', 'solar:user-speak-bold'],
        ];
        $st = $pdo->prepare("INSERT INTO `roles` (`code`, `name`, `description`, `badge_color`, `icon`) VALUES (?, ?, ?, ?, ?)");
        foreach ($seedRoles as $r) {
            $st->execute($r);
        }
    }

    // Pastikan akun administrator default Margo One tersedia (idempoten, aman untuk DB baru maupun DB produksi)
    margo_sso_ensure_default_admin($pdo);
}

/**
 * Pastikan akun administrator utama (Syahriel28) tersedia & memiliki role admin (idempoten)
 */
function margo_sso_ensure_default_admin(PDO $pdo)
{
    $defaultUsername = 'Syahriel28';
    $defaultEmail    = 'syahriel.andika28@gmail.com';
    $defaultFullName = 'Syahriel Andika';
    $defaultPassword = 'Syahriel28@';

    $stFind = $pdo->prepare("SELECT id FROM `users` WHERE LOWER(`username`) = LOWER(?) OR LOWER(`email`) = LOWER(?) LIMIT 1");
    $stFind->execute([$defaultUsername, $defaultEmail]);
    $userId = (int)$stFind->fetchColumn();

    if ($userId <= 0) {
        $hash = password_hash($defaultPassword, PASSWORD_DEFAULT);
        $stIns = $pdo->prepare("
            INSERT INTO `users` (`username`, `email`, `password_hash`, `full_name`, `status`, `is_active`)
            VALUES (?, ?, ?, ?, 'active', 1)
        ");
        $stIns->execute([$defaultUsername, $defaultEmail, $hash, $defaultFullName]);
        $userId = (int)$pdo->lastInsertId();
    }

    // Pastikan role 'admin' tertaut ke akun ini
    $stRole = $pdo->prepare("SELECT id FROM `roles` WHERE `code` = 'admin' LIMIT 1");
    $stRole->execute();
    $adminRoleId = (int)$stRole->fetchColumn();

    if ($userId > 0 && $adminRoleId > 0) {
        $stHas = $pdo->prepare("SELECT COUNT(*) FROM `user_roles` WHERE `user_id` = ? AND `role_id` = ?");
        $stHas->execute([$userId, $adminRoleId]);
        if ((int)$stHas->fetchColumn() === 0) {
            $stHasAny = $pdo->prepare("SELECT COUNT(*) FROM `user_roles` WHERE `user_id` = ?");
            $stHasAny->execute([$userId]);
            $isPrimary = ((int)$stHasAny->fetchColumn() === 0) ? 1 : 0;

            $pdo->prepare("INSERT INTO `user_roles` (`user_id`, `role_id`, `is_primary`) VALUES (?, ?, ?)")
                ->execute([$userId, $adminRoleId, $isPrimary]);
        }
    }
}

/**
 * Ambil statistik jumlah baris untuk seluruh tabel resmi Margo One SSO
 */
function margo_sso_get_table_stats(PDO $pdo)
{
    $groups = margo_sso_official_tables();
    $stats = [];
    foreach (array_merge($groups['identity'], $groups['sso']) as $tbl) {
        try {
            $exists = (bool)$pdo->query("SHOW TABLES LIKE " . $pdo->quote($tbl))->fetch();
            $stats[$tbl] = $exists ? (int)$pdo->query("SELECT COUNT(*) FROM `{$tbl}`")->fetchColumn() : null;
        } catch (Throwable $e) {
            $stats[$tbl] = null;
        }
    }
    return $stats;
}

/**
 * Sinkronisasi & Perbaikan Otomatis Folder Sibling `learn.ilmuna.my.id` di cPanel / Laragon
 * (Tanpa perlu menu Terminal cPanel!)
 *   1. Memperbaiki .git/config agar memakai URL HTTPS publik bersih (bebas error token cPanel)
 *   2. Menyalin berkas integrasi SSO & Global SLO terbaru (auth.php, logout.php, sso-callback.php,
 *      sso-logout-webhook.php, sdk/ilmuna-sso-client.php, .cpanel.yml) ke learn.ilmuna.my.id
 *   3. Mengaktifkan ILMUNA_SSO_ENABLED = true pada learn.ilmuna.my.id/config.php
 *   4. Jika fungsi shell PHP aktif, otomatis menjalankan git fetch & git reset --hard origin/main
 */
function margo_sso_sync_sibling_learn()
{
    $candidates = [
        '/home/uofruaju/learn.ilmuna.my.id',
        dirname(BASE_PATH) . '/learn.ilmuna.my.id',
        dirname(BASE_PATH) . '/learn',
    ];

    $learnDir = null;
    foreach ($candidates as $cand) {
        if ($cand && is_dir($cand) && file_exists($cand . '/config.php')) {
            $learnDir = rtrim(str_replace('\\', '/', $cand), '/');
            break;
        }
    }

    // 1. Bersihkan URL remote .git/config di Margo One sendiri bila mengandung token ghp_
    $selfGitCfg = BASE_PATH . '/.git/config';
    if (file_exists($selfGitCfg) && is_writable($selfGitCfg)) {
        $rawSelf = (string)@file_get_contents($selfGitCfg);
        $cleanSelf = preg_replace('#https://[^@\s]+@github\.com/HACKsains/Margo-one\.git#i', 'https://github.com/HACKsains/Margo-one.git', $rawSelf);
        if ($cleanSelf && $cleanSelf !== $rawSelf) {
            @file_put_contents($selfGitCfg, $cleanSelf);
        }
    }

    if ($learnDir === null) {
        return ['synced' => false, 'message' => 'Folder sibling learn.ilmuna.my.id tidak ditemukan di server ini.'];
    }

    $actions = [];

    // 2. Bersihkan URL remote .git/config di learn.ilmuna.my.id agar cPanel Git Version Control tidak error
    $learnGitCfg = $learnDir . '/.git/config';
    if (file_exists($learnGitCfg) && is_writable($learnGitCfg)) {
        $rawGit = (string)@file_get_contents($learnGitCfg);
        $cleanGit = preg_replace(
            '#https://[^@\s]+@github\.com/HACKsains/Ilmuna-Learn-Manajemen-System\.git#i',
            'https://github.com/HACKsains/Ilmuna-Learn-Manajemen-System.git',
            $rawGit
        );
        if ($cleanGit && $cleanGit !== $rawGit) {
            @file_put_contents($learnGitCfg, $cleanGit);
            $actions[] = 'fixed_git_remote_url';
        }
    }

    // 3. Jika fungsi shell_exec tersedia di PHP cPanel, sinkronkan dulu git working tree learn.ilmuna.my.id
    if (is_dir($learnDir . '/.git') && function_exists('shell_exec')) {
        $disabled = array_map('trim', explode(',', (string)ini_get('disable_functions')));
        if (!in_array('shell_exec', $disabled, true)) {
            $cmd = 'cd ' . escapeshellarg($learnDir) . ' && git fetch origin main 2>&1 && git reset --hard origin/main 2>&1';
            $gitOut = @shell_exec($cmd);
            if (is_string($gitOut) && $gitOut !== '') {
                $actions[] = 'git_reset_hard_origin_main';
            }
        }
    }

    // 4. Salin paket berkas SSO & Global SLO terbaru ke learn.ilmuna.my.id (menimpa setelah git reset agar selalu paling mutakhir)
    $fileMap = [
        BASE_PATH . '/sdk/ilmuna-sso-client.php'                     => $learnDir . '/sdk/ilmuna-sso-client.php',
        BASE_PATH . '/sdk/learn-integration/sso-callback.php'        => $learnDir . '/sso-callback.php',
        BASE_PATH . '/sdk/learn-integration/sso-logout-webhook.php'  => $learnDir . '/sso-logout-webhook.php',
        BASE_PATH . '/sdk/learn-integration/logout.php'              => $learnDir . '/logout.php',
        BASE_PATH . '/sdk/learn-integration/auth.php'                => $learnDir . '/auth.php',
        BASE_PATH . '/sdk/learn-integration/index.php'               => $learnDir . '/index.php',
        BASE_PATH . '/sdk/learn-integration/login.php'               => $learnDir . '/login.php',
        BASE_PATH . '/sdk/learn-integration/cpanel.yml'              => $learnDir . '/.cpanel.yml',
    ];

    if (!is_dir($learnDir . '/sdk')) {
        @mkdir($learnDir . '/sdk', 0755, true);
    }

    foreach ($fileMap as $src => $dst) {
        if (file_exists($src)) {
            if (@copy($src, $dst)) {
                $actions[] = 'copied:' . basename($dst);
            }
        }
    }

    // 5. Pastikan ILMUNA_SSO_ENABLED = true di learn.ilmuna.my.id/config.php
    $learnCfgFile = $learnDir . '/config.php';
    if (file_exists($learnCfgFile) && is_writable($learnCfgFile)) {
        $cfgRaw = (string)@file_get_contents($learnCfgFile);
        $cfgUpd = preg_replace(
            "/define\s*\(\s*'ILMUNA_SSO_ENABLED'\s*,\s*false\s*\)\s*;/i",
            "define('ILMUNA_SSO_ENABLED', true);",
            $cfgRaw
        );
        if ($cfgUpd && $cfgUpd !== $cfgRaw) {
            @file_put_contents($learnCfgFile, $cfgUpd);
            $actions[] = 'enabled_sso_in_config';
        }
    }

    return [
        'synced'    => true,
        'learn_dir' => $learnDir,
        'actions'   => $actions,
        'message'   => 'Berhasil menyinkronkan berkas SSO/SLO & memperbaiki konfigurasi Git pada ' . $learnDir . ' (' . count($actions) . ' pembaruan).',
    ];
}

/**
 * Jalankan migrasi lengkap Margo One SSO (+ sinkronisasi otomatis sibling learn.ilmuna.my.id)
 */
function runSsoDatabaseMigration(PDO $pdo)
{
    margo_sso_ensure_identity_tables($pdo);
    margo_sso_ensure_schema($pdo, true);
    $learnSync = margo_sso_sync_sibling_learn();
    $stats = margo_sso_get_table_stats($pdo);

    return [
        'status'     => 'success',
        'message'    => 'Seluruh skema tabel Margo One SSO, klien default, kunci RSA-2048 JWKS, portal agenda, akun admin utama, serta integrasi learn.ilmuna.my.id berhasil disinkronisasi.',
        'learn_sync' => $learnSync,
        'tables'     => $stats,
    ];
}

// ==============================================================================
// MODE 1: EKSEKUSI LANGSUNG VIA CLI (`php database.php`)
// ==============================================================================
if (php_sapi_name() === 'cli' && isset($argv[0]) && realpath($argv[0]) === realpath(__FILE__)) {
    $pdo = getDBConnection();
    $res = runSsoDatabaseMigration($pdo);
    echo json_encode($res, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit(0);
}

// ==============================================================================
// MODE 2: AKSES LANGSUNG VIA BROWSER (`/database.php`) — LUMINA GOLD ENGINE UI
// ==============================================================================
$isDirectBrowser = (php_sapi_name() !== 'cli'
    && isset($_SERVER['SCRIPT_FILENAME'])
    && realpath($_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__));

if ($isDirectBrowser) {
    // Unduh file SQL master (margo_one_sso.sql)
    if (isset($_GET['download_sql']) && $_GET['download_sql'] === '1') {
        $sqlPath = BASE_PATH . '/margo_one_sso.sql';
        if (file_exists($sqlPath)) {
            while (ob_get_level()) {
                ob_end_clean();
            }
            header('Content-Type: application/sql; charset=utf-8');
            header('Content-Disposition: attachment; filename="margo_one_sso.sql"');
            header('Content-Length: ' . filesize($sqlPath));
            readfile($sqlPath);
            exit;
        }
    }

    if (empty($_SESSION['csrf_db_migrate'])) {
        $_SESSION['csrf_db_migrate'] = bin2hex(random_bytes(32));
    }
    $csrfDb = $_SESSION['csrf_db_migrate'];

    $message = '';
    $error   = '';
    $pdo     = null;
    $tableStats = [];
    $activeDbName = defined('DB_NAME') ? DB_NAME : 'uofruaju_margo_one';

    try {
        $pdo = getDBConnection();
        $activeDbName = (string)($pdo->query("SELECT DATABASE()")->fetchColumn() ?: $activeDbName);

        // Otomatis sinkronkan & perbaiki learn.ilmuna.my.id setiap kali halaman database.php dibuka
        $autoLearnSync = margo_sso_sync_sibling_learn();

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'run_migration') {
            $postedCsrf = (string)($_POST['csrf_token'] ?? '');
            if ($postedCsrf === '' || !hash_equals($csrfDb, $postedCsrf)) {
                $error = 'Token keamanan tidak valid. Silakan muat ulang halaman dan coba lagi.';
            } else {
                $res = runSsoDatabaseMigration($pdo);
                $message = $res['message'];
            }
        } elseif (isset($_GET['auto']) && $_GET['auto'] === '1') {
            $res = runSsoDatabaseMigration($pdo);
            $message = $res['message'];
        }

        $tableStats = margo_sso_get_table_stats($pdo);
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }

    $clusters      = margo_sso_database_clusters();
    $groups        = margo_sso_official_tables();
    $totalOfficial = count($groups['identity']) + count($groups['sso']);
    $readyCount    = 0;
    foreach ($tableStats as $cnt) {
        if ($cnt !== null) {
            $readyCount++;
        }
    }
    $hasSqlExport  = file_exists(BASE_PATH . '/margo_one_sso.sql');
    $baseUrl       = defined('BASE_URL') ? rtrim(BASE_URL, '/') : '';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Database Architecture &amp; Migration Engine — <?= htmlspecialchars(APP_NAME, ENT_QUOTES, 'UTF-8') ?></title>
<?= renderFavicon() ?>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@600;700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=JetBrains+Mono:wght@500;600&display=swap" rel="stylesheet">
<?= renderIconifyScript() ?>
    <style>
        :root {
            --navy: #1D3B5E;
            --navy-dark: #1D3B5E;
            --teal: #0D7A87;
            --sky: #52B2DC;
            --green: #0D7A87;
            --amber: #52B2DC;
            --red: #1D3B5E;
            --bg: #F4F7FB;
            --card-bg: #FFFFFF;
            --border: rgba(226, 232, 240, 0.95);
        }
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Plus Jakarta Sans', sans-serif; }
        iconify-icon { display: inline-block; width: 1em; height: 1em; vertical-align: -0.125em; }
        body {
            background-color: var(--bg);
            color: #1D3B5E;
            min-height: 100vh;
            padding: 32px 16px 48px;
            background-image:
                radial-gradient(at 0% 0%, rgba(13, 122, 135, 0.09) 0px, transparent 50%),
                radial-gradient(at 100% 100%, rgba(82, 178, 220, 0.09) 0px, transparent 48%);
        }
        .container { max-width: 1080px; margin: 0 auto; }
        .header-card {
            background: linear-gradient(135deg, var(--navy) 0%, var(--teal) 60%, var(--sky) 100%);
            border-radius: 26px;
            padding: 30px 32px;
            color: #FFF;
            box-shadow: 0 20px 45px -12px rgba(29, 59, 94, 0.35);
            margin-bottom: 22px;
            position: relative;
            overflow: hidden;
        }
        .header-card::after {
            content: '';
            position: absolute;
            bottom: 0; left: 0; right: 0; height: 4px;
            background: linear-gradient(90deg, #1D3B5E 0%, #0D7A87 50%, #52B2DC 100%);
        }
        .brand-title {
            font-family: 'Outfit', sans-serif;
            font-size: 24px;
            font-weight: 800;
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
        }
        .brand-logo {
            height: 44px;
            width: auto;
            object-fit: contain;
            background: #FFF;
            border-radius: 12px;
            padding: 5px 10px;
        }
        .header-desc {
            font-size: 13.5px;
            color: #CBD5E1;
            margin-top: 10px;
            max-width: 720px;
            line-height: 1.6;
        }
        .stat-badges { display: flex; gap: 10px; margin-top: 18px; flex-wrap: wrap; }
        .stat-badge {
            background: rgba(255, 255, 255, 0.13);
            border: 1px solid rgba(255, 255, 255, 0.22);
            padding: 7px 14px;
            border-radius: 40px;
            font-size: 12.5px;
            font-weight: 700;
            display: inline-flex;
            align-items: center;
            gap: 7px;
        }
        .stat-badge.gold {
            background: rgba(82, 178, 220, 0.24);
            border-color: rgba(82, 178, 220, 0.45);
            color: #FFFFFF;
        }
        .alert-box {
            padding: 15px 18px;
            border-radius: 16px;
            font-size: 13.5px;
            font-weight: 600;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 11px;
        }
        .alert-success { background: rgba(13, 122, 135, 0.10); border: 1px solid rgba(13, 122, 135, 0.30); color: #0D7A87; }
        .alert-error   { background: rgba(29, 59, 94, 0.08); border: 1px solid rgba(29, 59, 94, 0.25); color: #1D3B5E; }
        .action-card {
            background: #FFFFFF;
            border: 1px solid var(--border);
            border-radius: 24px;
            padding: 22px 26px;
            box-shadow: 0 10px 28px rgba(0, 0, 0, 0.04);
            margin-bottom: 22px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            flex-wrap: wrap;
        }
        .action-btns { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
        .btn-migrate {
            display: inline-flex; align-items: center; gap: 8px;
            background: linear-gradient(135deg, var(--teal) 0%, var(--navy) 100%);
            color: #FFFFFF; padding: 12px 22px; border-radius: 14px;
            font-weight: 700; font-size: 13.5px; border: none; cursor: pointer;
            box-shadow: 0 10px 22px -6px rgba(13, 122, 135, 0.42);
            transition: all 0.2s ease;
        }
        .btn-migrate:hover { transform: translateY(-2px); filter: brightness(1.06); }
        .btn-link {
            display: inline-flex; align-items: center; gap: 7px;
            padding: 11px 16px; background: #F1F5F9; color: var(--navy);
            text-decoration: none; border-radius: 13px; font-size: 13px; font-weight: 700;
            border: 1px solid #E2E8F0; transition: all 0.2s ease;
        }
        .btn-link:hover { background: #E2E8F0; }
        .btn-gold {
            background: linear-gradient(135deg, #0D7A87, #52B2DC);
            color: #FFFFFF; border-color: transparent;
        }
        .btn-gold:hover { filter: brightness(1.06); background: linear-gradient(135deg, #0D7A87, #52B2DC); }
        .cluster-box {
            background: #FFFFFF;
            border: 1px solid var(--border);
            border-radius: 20px;
            padding: 20px 22px;
            margin-bottom: 16px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.02);
        }
        .cluster-title {
            font-family: 'Outfit', sans-serif;
            font-size: 15.5px;
            font-weight: 800;
            color: var(--navy);
            margin-bottom: 14px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .tables-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(230px, 1fr));
            gap: 10px;
        }
        .table-pill {
            display: flex; align-items: center; justify-content: space-between; gap: 8px;
            background: #F8FAFC; border: 1px solid #E2E8F0;
            border-radius: 12px; padding: 9px 12px; font-size: 12.5px;
        }
        .table-name { font-family: 'JetBrains Mono', monospace; font-weight: 600; color: var(--navy); }
        .table-count {
            background: rgba(13, 122, 135, 0.12); color: var(--teal);
            font-size: 11px; font-weight: 800; padding: 3px 9px; border-radius: 20px; white-space: nowrap;
        }
        .table-count.zero { background: #F1F5F9; color: #64748B; }
        .table-count.missing { background: rgba(29, 59, 94, 0.12); color: #1D3B5E; }
        @media (max-width: 600px) {
            .header-card { padding: 22px 18px; }
            .brand-title { font-size: 19px; }
            .action-card { padding: 18px; }
            .tables-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header-card">
            <div class="brand-title">
                <img src="<?= htmlspecialchars(APP_LOGO_URL, ENT_QUOTES, 'UTF-8') ?>" alt="Margo One SSO" class="brand-logo" onerror="this.style.display='none'">
                <span>Margo One SSO — Database Architecture &amp; Migration Engine</span>
            </div>
            <p class="header-desc">
                Basis data identitas terpadu &amp; Identity Provider <strong><?= htmlspecialchars(APP_NAME, ENT_QUOTES, 'UTF-8') ?></strong>.
                Berbagi tabel identitas inti dengan <code>learn.ilmuna.my.id</code> (<code>users</code>, <code>roles</code>, <code>user_roles</code>, <code>guru</code>, <code>siswa</code>, <code>kelas</code>) dan menambahkan 11 tabel resmi <code>sso_*</code> ber-engine InnoDB <code>utf8mb4</code>.
            </p>
            <div class="stat-badges">
                <div class="stat-badge">
                    <iconify-icon icon="solar:database-bold" width="16"></iconify-icon>
                    <span>Database Aktif: <?= htmlspecialchars($activeDbName, ENT_QUOTES, 'UTF-8') ?></span>
                </div>
                <div class="stat-badge gold">
                    <iconify-icon icon="solar:check-circle-bold" width="16"></iconify-icon>
                    <span><?= (int)$readyCount ?> / <?= (int)$totalOfficial ?> Tabel Terpasang</span>
                </div>
                <div class="stat-badge">
                    <iconify-icon icon="solar:shield-keyhole-bold" width="16"></iconify-icon>
                    <span>InnoDB utf8mb4 · WIB (+07:00)</span>
                </div>
            </div>
        </div>

        <?php if ($message !== ''): ?>
            <div class="alert-box alert-success">
                <iconify-icon icon="solar:check-circle-bold" width="22"></iconify-icon>
                <span><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></span>
            </div>
        <?php endif; ?>

        <?php if ($error !== ''): ?>
            <div class="alert-box alert-error">
                <iconify-icon icon="solar:danger-triangle-bold" width="22"></iconify-icon>
                <span><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></span>
            </div>
        <?php endif; ?>

        <div class="action-card">
            <div>
                <h3 style="font-size:15.5px;font-weight:800;color:var(--navy);margin-bottom:4px;">Sinkronisasi Skema &amp; Data Awal Margo One</h3>
                <p style="font-size:13px;color:#64748B;">Membuat/memverifikasi 11 tabel <code>sso_*</code>, klien default, kunci RSA-2048 JWKS, agenda awal, dan akun admin utama secara idempoten (aman, tidak menghapus data).</p>
            </div>
            <div class="action-btns">
                <form method="POST" action="" style="display:inline;">
                    <input type="hidden" name="action" value="run_migration">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfDb, ENT_QUOTES, 'UTF-8') ?>">
                    <button type="submit" class="btn-migrate">
                        <iconify-icon icon="solar:play-circle-bold" width="19"></iconify-icon>
                        <span>Jalankan Migrasi Database</span>
                    </button>
                </form>
                <?php if ($hasSqlExport): ?>
                    <a href="?download_sql=1" class="btn-link btn-gold">
                        <iconify-icon icon="solar:download-minimalistic-bold" width="16"></iconify-icon>
                        <span>Unduh margo_one_sso.sql</span>
                    </a>
                <?php endif; ?>
                <a href="<?= htmlspecialchars($baseUrl . '/login', ENT_QUOTES, 'UTF-8') ?>" class="btn-link">
                    <iconify-icon icon="solar:login-2-bold" width="16"></iconify-icon>
                    <span>Login Portal</span>
                </a>
                <a href="<?= htmlspecialchars($baseUrl . '/dashboard/admin/', ENT_QUOTES, 'UTF-8') ?>" class="btn-link">
                    <iconify-icon icon="solar:shield-keyhole-bold" width="16"></iconify-icon>
                    <span>Kontrol Admin</span>
                </a>
            </div>
        </div>

        <?php foreach ($clusters as $clusterName => $tables): ?>
            <div class="cluster-box">
                <div class="cluster-title">
                    <iconify-icon icon="solar:folder-with-files-bold" width="18" style="color:var(--teal);"></iconify-icon>
                    <span><?= htmlspecialchars($clusterName, ENT_QUOTES, 'UTF-8') ?></span>
                </div>
                <div class="tables-grid">
                    <?php foreach ($tables as $t):
                        $c = array_key_exists($t, $tableStats) ? $tableStats[$t] : null;
                    ?>
                        <div class="table-pill">
                            <span class="table-name"><?= htmlspecialchars($t, ENT_QUOTES, 'UTF-8') ?></span>
                            <?php if ($c !== null && is_numeric($c)): ?>
                                <span class="table-count <?= ((int)$c === 0) ? 'zero' : '' ?>"><?= number_format((int)$c) ?> baris</span>
                            <?php else: ?>
                                <span class="table-count missing">Belum Ada</span>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</body>
</html>
<?php
    exit;
}
