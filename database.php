<?php
/**
 * ==============================================================================
 * MARGO ONE — ILMUNA SSO · DATABASE MIGRATION & HEALTH ENGINE
 * ==============================================================================
 * Memastikan seluruh tabel identitas inti (kompatibel 100% dengan learn.ilmuna.my.id)
 * dan seluruh tabel Margo One SSO (8 tabel) tersedia dengan engine InnoDB utf8mb4.
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
 * Jalankan migrasi lengkap Margo One SSO
 */
function runSsoDatabaseMigration(PDO $pdo)
{
    margo_sso_ensure_identity_tables($pdo);
    margo_sso_ensure_schema($pdo, true);

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

    return [
        'status' => 'success',
        'tables' => $stats,
    ];
}

// CLI / Direct migration trigger (?auto=1 hanya dari CLI atau localhost)
if (php_sapi_name() === 'cli' && isset($argv[0]) && realpath($argv[0]) === realpath(__FILE__)) {
    $pdo = getDBConnection();
    $res = runSsoDatabaseMigration($pdo);
    echo json_encode($res, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
}
