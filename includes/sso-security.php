<?php
/**
 * ==============================================================================
 * MARGO ONE — ILMUNA SSO · ZERO-TRUST SECURITY & CRYPTOGRAPHY ENGINE
 * ==============================================================================
 * Adaptasi langsung dari learn/includes/wp-api-security.php untuk Identity Provider
 * https://margo-one.ilmuna.my.id
 *
 * Proteksi Berlapis:
 *   1. Public Key (pk_sso_*) + Private Key (sk_sso_*) terenkripsi AES-256-CBC+HMAC at-rest
 *   2. Cryptographic Request Signing: HMAC-SHA256 | RSA-SHA256 | DUAL
 *   3. Anti-Replay Protection: Timestamp +/- 300s + single-use sso_nonces
 *   4. Domain, Origin/Referer, IP/CIDR & Exact Redirect URI Allowlist
 *   5. Sliding-Window Rate Limiter (sso_rate_limits)
 *   6. Signed JSON Response (X-Ilmuna-Response-Timestamp & X-Ilmuna-Response-Signature)
 *   7. RSA-2048 JWKS Key Lifecycle (Auto-seed + Graceful Rotation)
 *   8. Signed Back-Channel Single Logout (SLO) Webhook Dispatcher
 *   9. Audit Trail Lengkap (sso_audit_logs)
 *
 * 100% kompatibel PHP 7.4 (ea-php74) hingga PHP 8.3.
 * ==============================================================================
 */

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}
if (!defined('MARGO_SSO_API_VERSION')) {
    define('MARGO_SSO_API_VERSION', '1.0.0');
}
if (!defined('MARGO_SSO_MAX_SKEW')) {
    define('MARGO_SSO_MAX_SKEW', 300); // +/- 5 menit
}

require_once __DIR__ . '/sso-jwt.php';

/**
 * Normalisasi URL Foto/Logo/Icon Aplikasi (mendukung file upload relatif, URL penuh, atau data URI)
 */
if (!function_exists('margo_resolve_app_logo_url')) {
    function margo_resolve_app_logo_url($rawLogo, $rawIcon = '', $slugHint = '')
    {
        $base = defined('BASE_URL') ? rtrim(BASE_URL, '/') : '';
        $root = defined('BASE_PATH') ? rtrim(str_replace('\\', '/', BASE_PATH), '/') : '';

        foreach ([$rawLogo, $rawIcon] as $cand) {
            $s = trim((string)$cand);
            if ($s === '' || stripos($s, 'solar:') === 0) {
                continue;
            }
            if (preg_match('#^https?://#i', $s) || strpos($s, 'data:image/') === 0) {
                return $s;
            }
            $rel = ltrim(str_replace('\\', '/', $s), '/');
            $abs = $root !== '' ? ($root . '/' . $rel) : '';
            if ($abs !== '' && file_exists($abs)) {
                $ver = @filemtime($abs);
                return $base . '/' . $rel . ($ver ? ('?v=' . $ver) : '');
            }
            return $base . '/' . $rel;
        }

        // Default logo resmi bawaan untuk aplikasi inti ekosistem bila admin belum mengunggah logo kustom
        $hint = strtolower(trim((string)$slugHint));
        $builtinMap = [
            'margo-admin'            => 'assets/images/logo-margo-one.png',
            'ilmuna-learn'           => 'assets/images/logo-learn.png',
            'ilmuna-wp'              => 'assets/images/logo.png',
            'ilmuna website resmi'   => 'assets/images/logo.png',
            'portal wordpress ilmuna'=> 'assets/images/logo.png',
            'ilmuna learn lms'       => 'assets/images/logo-learn.png',
        ];
        if ($hint !== '' && isset($builtinMap[$hint])) {
            $relDef = $builtinMap[$hint];
            $absDef = $root !== '' ? ($root . '/' . $relDef) : '';
            if ($absDef === '' || file_exists($absDef)) {
                $ver = ($absDef !== '' && file_exists($absDef)) ? @filemtime($absDef) : '';
                return $base . '/' . $relDef . ($ver ? ('?v=' . $ver) : '');
            }
        }
        return null;
    }
}

/**
 * Verifikasi apakah pengguna (berdasarkan daftar Role + Izin Aplikasi spesifik user)
 * memiliki izin (permission) untuk login ke aplikasi klien SSO tertentu.
 */
if (!function_exists('margo_user_can_access_client')) {
    function margo_user_can_access_client(array $client, array $userRoles, $userAllowedApps = null)
    {
        // 1. Cek izin berbasis Role pada aplikasi (sso_clients.allowed_roles)
        $allowedRoles = margo_sso_parse_list($client['allowed_roles'] ?? '');
        $roleAllowed  = empty($allowedRoles);
        if (!$roleAllowed) {
            foreach ($userRoles as $ur) {
                if (in_array(strtolower(trim((string)$ur)), $allowedRoles, true)) {
                    $roleAllowed = true;
                    break;
                }
            }
        }
        if (!$roleAllowed) {
            return false;
        }

        // 2. Cek izin aplikasi spesifik pada pengguna/role (users.allowed_apps) bila diatur
        $rawAllowedApps = trim((string)$userAllowedApps);
        if ($rawAllowedApps !== '' && $rawAllowedApps !== '*') {
            if ($rawAllowedApps === '__none__') {
                return false;
            }
            $allowedSlugs = margo_sso_parse_list($rawAllowedApps);
            $clientSlug   = strtolower(trim((string)($client['client_slug'] ?? '')));
            $clientIdStr  = (string)($client['id'] ?? '');
            if (!in_array($clientSlug, $allowedSlugs, true) && !in_array($clientIdStr, $allowedSlugs, true)) {
                return false;
            }
        }

        return true;
    }
}

/**
 * Kunci master internal untuk enkripsi at-rest (Private Key klien & RSA Private Key IdP)
 * Stabil lintas nama database (uofruaju_margo_one maupun uofruaju_learn_ilmuna)
 */
function margo_sso_master_key($dbSeedOverride = null)
{
    $seedParts = [
        defined('DB_PASS') ? DB_PASS : 'margo_local_pass',
        $dbSeedOverride !== null ? (string)$dbSeedOverride : 'MARGO_ONE_UNIFIED_IDP_KEY',
        defined('APP_DOMAIN') ? APP_DOMAIN : 'margo-one.ilmuna.my.id',
        'MARGO_ONE_SSO_ENC_V1_SALT_2026',
    ];
    return hash('sha256', implode('|', $seedParts), true);
}

/**
 * Enkripsi AES-256-CBC + HMAC-SHA256 (Encrypt-then-MAC)
 */
function margo_sso_encrypt_secret($plaintext)
{
    $plaintext = (string)$plaintext;
    $masterKey = margo_sso_master_key();
    $encKey = hash_hmac('sha256', 'sso-enc', $masterKey, true);
    $macKey = hash_hmac('sha256', 'sso-mac', $masterKey, true);

    $iv = random_bytes(16);
    $cipher = openssl_encrypt($plaintext, 'aes-256-cbc', $encKey, OPENSSL_RAW_DATA, $iv);
    if ($cipher === false) {
        throw new RuntimeException('Gagal mengenkripsi secret SSO menggunakan OpenSSL.');
    }
    $mac = hash_hmac('sha256', $iv . $cipher, $macKey, true);
    return 'v1:' . base64_encode($iv . $mac . $cipher);
}

/**
 * Dekripsi AES-256-CBC + verifikasi HMAC-SHA256 constant-time (mendukung fallback seed lama)
 */
function margo_sso_decrypt_secret($encoded)
{
    $encoded = (string)$encoded;
    if (strpos($encoded, 'v1:') !== 0) {
        return '';
    }
    $raw = base64_decode(substr($encoded, 3), true);
    if ($raw === false || strlen($raw) <= 48) {
        return '';
    }

    $iv     = substr($raw, 0, 16);
    $mac    = substr($raw, 16, 32);
    $cipher = substr($raw, 48);

    $candidateSeeds = [
        null,
        defined('DB_NAME') ? DB_NAME : 'uofruaju_margo_one',
        'uofruaju_margo_one',
        'uofruaju_learn_ilmuna',
    ];

    foreach ($candidateSeeds as $seed) {
        $masterKey   = margo_sso_master_key($seed);
        $encKey      = hash_hmac('sha256', 'sso-enc', $masterKey, true);
        $macKey      = hash_hmac('sha256', 'sso-mac', $masterKey, true);
        $expectedMac = hash_hmac('sha256', $iv . $cipher, $macKey, true);
        if (hash_equals($expectedMac, $mac)) {
            $plain = openssl_decrypt($cipher, 'aes-256-cbc', $encKey, OPENSSL_RAW_DATA, $iv);
            if ($plain !== false) {
                return $plain;
            }
        }
    }

    return '';
}

/**
 * Generate pasangan Public Key (pk_sso_*) & Private Key (sk_sso_*)
 */
function margo_sso_generate_keypair()
{
    return [
        'public_key'  => 'pk_sso_' . bin2hex(random_bytes(16)), // 39 chars
        'private_key' => 'sk_sso_' . bin2hex(random_bytes(32)), // 71 chars (256-bit)
    ];
}

/**
 * Generate UUID v4 standar (untuk family_id refresh token & jti)
 */
function margo_sso_uuid_v4()
{
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
}

/**
 * Temukan lokasi openssl.cnf untuk Windows/Laragon maupun Linux cPanel
 */
function margo_sso_find_openssl_cnf()
{
    $candidates = [
        getenv('OPENSSL_CONF') ?: '',
        'C:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/extras/ssl/openssl.cnf',
        'C:/laragon/bin/php/php-8.3.36-Win32-vs16-x64/extras/ssl/openssl.cnf',
        'C:/laragon/bin/php/php-8.4.12-nts-Win32-vs17-x64/extras/ssl/openssl.cnf',
        'C:/laragon/bin/apache/httpd-2.4.54-win64-VS16/conf/openssl.cnf',
        'C:/laragon/etc/ssl/openssl.cnf',
        '/etc/ssl/openssl.cnf',
        '/usr/lib/ssl/openssl.cnf',
        '/etc/pki/tls/openssl.cnf',
    ];

    // Cari juga secara dinamis di folder PHP saat ini (Windows)
    if (defined('PHP_BINARY') && PHP_BINARY !== '') {
        $phpDir = dirname(PHP_BINARY);
        $candidates[] = $phpDir . '/extras/ssl/openssl.cnf';
    }

    foreach ($candidates as $path) {
        if ($path !== '' && @file_exists($path)) {
            return $path;
        }
    }
    return null;
}

/**
 * Generate pasangan RSA-2048 PEM (Public PEM + Private PEM)
 */
function margo_sso_generate_rsa_pair()
{
    if (!extension_loaded('openssl')) {
        return ['success' => false, 'message' => 'Ekstensi OpenSSL tidak aktif di server PHP.'];
    }
    $cfg = [
        'private_key_bits' => 2048,
        'private_key_type' => OPENSSL_KEYTYPE_RSA,
    ];
    $cnfPath = margo_sso_find_openssl_cnf();
    if ($cnfPath !== null) {
        $cfg['config'] = $cnfPath;
    }

    $res = @openssl_pkey_new($cfg);
    if ($res === false) {
        return ['success' => false, 'message' => 'OpenSSL gagal membangkitkan kunci RSA-2048: ' . (openssl_error_string() ?: 'unknown error')];
    }

    $privatePem = '';
    $exported = ($cnfPath !== null)
        ? @openssl_pkey_export($res, $privatePem, null, ['config' => $cnfPath])
        : @openssl_pkey_export($res, $privatePem);
    if (!$exported || empty($privatePem)) {
        return ['success' => false, 'message' => 'Gagal mengekspor RSA Private Key PEM.'];
    }

    $details = @openssl_pkey_get_details($res);
    $publicPem = is_array($details) && !empty($details['key']) ? $details['key'] : '';
    if (empty($publicPem)) {
        return ['success' => false, 'message' => 'Gagal mengekstrak RSA Public Key PEM.'];
    }

    return [
        'success'     => true,
        'public_pem'  => trim($publicPem),
        'private_pem' => trim($privatePem),
        'fingerprint' => hash('sha256', trim($publicPem)),
    ];
}

/**
 * Auto-migrasi seluruh 8 tabel Margo One SSO + seed 2 klien default + RSA-2048 JWKS key
 */
function margo_sso_ensure_schema(PDO $pdo, $force = false)
{
    static $ensured = false;
    if ($ensured && !$force) {
        return;
    }

    // 0. Pastikan tabel identitas pelengkap (kelas, guru, siswa) juga tersedia di DB dedicated
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

    // 1. sso_clients
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS sso_clients (
            id INT AUTO_INCREMENT PRIMARY KEY,
            client_name VARCHAR(120) NOT NULL,
            client_slug VARCHAR(60) NOT NULL UNIQUE,
            public_key VARCHAR(120) NOT NULL UNIQUE,
            private_key_enc TEXT NOT NULL,
            private_key_hash CHAR(64) NOT NULL,
            rsa_public_pem TEXT NULL,
            sig_mode ENUM('HMAC-SHA256','RSA-SHA256','DUAL') NOT NULL DEFAULT 'HMAC-SHA256',
            app_url VARCHAR(255) NOT NULL,
            app_icon VARCHAR(80) NOT NULL DEFAULT 'solar:widget-5-bold',
            app_logo VARCHAR(255) NULL DEFAULT NULL,
            redirect_uris TEXT NOT NULL,
            logout_webhook_url VARCHAR(255) NULL,
            allowed_domains TEXT NOT NULL,
            allowed_ips TEXT NULL,
            allowed_scopes VARCHAR(255) NOT NULL DEFAULT 'openid,profile,email,roles',
            allowed_roles VARCHAR(255) NOT NULL DEFAULT 'admin,guru,ketua_kelas,siswa',
            rate_limit_per_min INT NOT NULL DEFAULT 180,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            last_used_at DATETIME NULL,
            last_ip VARCHAR(64) NULL,
            request_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_sso_clients_active (is_active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // Pastikan kolom app_logo ada pada tabel sso_clients eksisting
    try {
        $clientCols = $pdo->query("SHOW COLUMNS FROM sso_clients")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('app_logo', $clientCols, true)) {
            $pdo->exec("ALTER TABLE sso_clients ADD COLUMN app_logo VARCHAR(255) NULL DEFAULT NULL AFTER app_icon");
        }
    } catch (Throwable $eColC) {
        // Non-fatal
    }

    // Pastikan kolom allowed_apps ada pada tabel users eksisting (untuk permission login per aplikasi)
    try {
        $uColsCheck = $pdo->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_COLUMN);
        if (!empty($uColsCheck) && !in_array('allowed_apps', $uColsCheck, true)) {
            $pdo->exec("ALTER TABLE users ADD COLUMN allowed_apps TEXT NULL DEFAULT NULL");
        }
    } catch (Throwable $eColU) {
        // Non-fatal
    }

    // 2. sso_sessions
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS sso_sessions (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            session_token_hash CHAR(64) NOT NULL UNIQUE,
            user_id INT NOT NULL,
            active_role VARCHAR(40) NULL,
            ip_address VARCHAR(64) NOT NULL,
            user_agent VARCHAR(255) NULL,
            login_method ENUM('password','google_oauth','magic_qr') NOT NULL DEFAULT 'password',
            expires_at DATETIME NOT NULL,
            revoked_at DATETIME NULL,
            last_activity_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_sso_sess_user (user_id, revoked_at),
            INDEX idx_sso_sess_exp (expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // 3. sso_auth_codes
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS sso_auth_codes (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            code_hash CHAR(64) NOT NULL UNIQUE,
            client_id INT NOT NULL,
            user_id INT NOT NULL,
            sso_session_id BIGINT NOT NULL,
            redirect_uri VARCHAR(255) NOT NULL,
            scope VARCHAR(255) NOT NULL,
            code_challenge VARCHAR(128) NOT NULL,
            code_challenge_method VARCHAR(10) NOT NULL DEFAULT 'S256',
            nonce VARCHAR(128) NULL,
            expires_at DATETIME NOT NULL,
            used_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_sso_code_exp (expires_at),
            INDEX idx_sso_code_client (client_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // 4. sso_refresh_tokens
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS sso_refresh_tokens (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            token_hash CHAR(64) NOT NULL UNIQUE,
            family_id CHAR(36) NOT NULL,
            client_id INT NOT NULL,
            user_id INT NOT NULL,
            sso_session_id BIGINT NOT NULL,
            scope VARCHAR(255) NOT NULL DEFAULT 'openid profile email roles',
            expires_at DATETIME NOT NULL,
            rotated_at DATETIME NULL,
            revoked_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_sso_rt_family (family_id),
            INDEX idx_sso_rt_user (user_id, revoked_at),
            INDEX idx_sso_rt_sess (sso_session_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // 5. sso_nonces
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS sso_nonces (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            public_key VARCHAR(120) NOT NULL,
            nonce_hash CHAR(64) NOT NULL,
            expires_at DATETIME NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_sso_pk_nonce (public_key, nonce_hash),
            INDEX idx_sso_nonce_exp (expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // 6. sso_rate_limits
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS sso_rate_limits (
            bucket_key CHAR(64) PRIMARY KEY,
            hits INT NOT NULL DEFAULT 1,
            expires_at DATETIME NOT NULL,
            INDEX idx_sso_rl_exp (expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // 7. sso_audit_logs
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS sso_audit_logs (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NULL,
            client_id INT NULL,
            event_type VARCHAR(60) NOT NULL,
            http_status SMALLINT NOT NULL DEFAULT 200,
            ip_address VARCHAR(64) NOT NULL,
            user_agent VARCHAR(255) NULL,
            detail_json TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_sso_audit_created (created_at),
            INDEX idx_sso_audit_event (event_type),
            INDEX idx_sso_audit_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // 8. sso_jwks_keys (RSA-2048 IdP JWT signing keys, encrypted private PEM at-rest)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS sso_jwks_keys (
            id INT AUTO_INCREMENT PRIMARY KEY,
            kid VARCHAR(80) NOT NULL UNIQUE,
            public_pem TEXT NOT NULL,
            private_pem_enc MEDIUMTEXT NOT NULL,
            fingerprint CHAR(64) NOT NULL,
            is_primary TINYINT(1) NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            expires_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_sso_jwks_active (is_active, is_primary)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // Seed 2 klien default jika sso_clients masih kosong (+ sinkronisasi kunci awal produksi & lokal)
    $defaults = [
        [
            'client_name'        => 'Ilmuna Learn LMS',
            'client_slug'        => 'ilmuna-learn',
            'public_key'         => 'pk_sso_7d42b19f20e10e6a9b6c1e40dbb8582f',
            'private_key'        => 'sk_sso_3a73a15e291c401208776c82ad5816752cf5eb9df802b28ec335a8c0dd232456',
            'app_url'            => 'https://learn.ilmuna.my.id',
            'app_icon'           => 'solar:square-academic-cap-2-bold',
            'redirect_uris'      => "https://learn.ilmuna.my.id/sso-callback\nhttps://learn.ilmuna.my.id/sso-callback.php\nhttp://learn.ilmuna.my.id/sso-callback\nhttp://learn.ilmuna.my.id/sso-callback.php\nhttp://localhost/learn/sso-callback\nhttp://localhost/learn/sso-callback.php\nhttp://127.0.0.1/learn/sso-callback\nhttp://127.0.0.1/learn/sso-callback.php\nhttp://learn.test/sso-callback",
            'logout_webhook_url' => 'https://learn.ilmuna.my.id/sso-logout-webhook.php',
            'allowed_domains'    => 'learn.ilmuna.my.id,margo-one.ilmuna.my.id,learn.test,localhost,127.0.0.1',
            'allowed_scopes'     => 'openid,profile,email,roles',
            'allowed_roles'      => 'admin,guru,ketua_kelas,siswa',
            'rate_limit_per_min' => 240,
        ],
        [
            'client_name'        => 'Portal WordPress Ilmuna',
            'client_slug'        => 'ilmuna-wp',
            'public_key'         => 'pk_sso_670c3653054dc61659cd64e450396d7a',
            'private_key'        => 'sk_sso_b966874701749abba5cd4c93b9d420690b2ae58cc612bf6baf9604a6edbbb64e',
            'app_url'            => 'https://ilmuna.my.id',
            'app_icon'           => 'solar:globe-bold',
            'redirect_uris'      => "https://ilmuna.my.id/?margo_one_callback=1\nhttps://www.ilmuna.my.id/?margo_one_callback=1\nhttp://localhost/ilmuna/?margo_one_callback=1",
            'logout_webhook_url' => 'https://ilmuna.my.id/?margo_one_slo=1',
            'allowed_domains'    => 'ilmuna.my.id,www.ilmuna.my.id,margo-one.ilmuna.my.id,localhost,127.0.0.1',
            'allowed_scopes'     => 'openid,profile,email,roles',
            'allowed_roles'      => 'admin,guru,ketua_kelas,siswa',
            'rate_limit_per_min' => 180,
        ],
    ];

    $clientCount = (int)$pdo->query("SELECT COUNT(*) FROM sso_clients")->fetchColumn();
    if ($clientCount === 0) {
        $insClient = $pdo->prepare("
            INSERT INTO sso_clients
            (client_name, client_slug, public_key, private_key_enc, private_key_hash, sig_mode, app_url, app_icon, redirect_uris, logout_webhook_url, allowed_domains, allowed_ips, allowed_scopes, allowed_roles, rate_limit_per_min, is_active)
            VALUES (?, ?, ?, ?, ?, 'HMAC-SHA256', ?, ?, ?, ?, ?, '', ?, ?, ?, 1)
        ");

        foreach ($defaults as $d) {
            $insClient->execute([
                $d['client_name'],
                $d['client_slug'],
                $d['public_key'],
                margo_sso_encrypt_secret($d['private_key']),
                hash('sha256', $d['private_key']),
                $d['app_url'],
                $d['app_icon'],
                $d['redirect_uris'],
                $d['logout_webhook_url'],
                $d['allowed_domains'],
                $d['allowed_scopes'],
                $d['allowed_roles'],
                $d['rate_limit_per_min'],
            ]);
        }
    } else {
        // Sinkronisasi otomatis klien default yang belum pernah dirotasi manual oleh Admin agar public_key konsisten 100% antara lokal & produksi
        $rotatedCount = 0;
        try {
            $rotatedCount = (int)$pdo->query("SELECT COUNT(*) FROM sso_audit_logs WHERE event_type = 'admin_client_keys_rotated'")->fetchColumn();
        } catch (Throwable $eRot) {
            $rotatedCount = 0;
        }
        $stChk = $pdo->prepare("SELECT id, public_key, private_key_enc FROM sso_clients WHERE client_slug = ? LIMIT 1");
        $stSync = $pdo->prepare("
            UPDATE sso_clients
            SET public_key = ?, private_key_enc = ?, private_key_hash = ?, redirect_uris = ?, allowed_domains = ?
            WHERE id = ?
        ");
        foreach ($defaults as $d) {
            $stChk->execute([$d['client_slug']]);
            $rowC = $stChk->fetch(PDO::FETCH_ASSOC);
            if ($rowC) {
                $dec = margo_sso_decrypt_secret($rowC['private_key_enc']);
                if (($rotatedCount === 0 && $rowC['public_key'] !== $d['public_key']) || $dec === '') {
                    $stSync->execute([
                        $d['public_key'],
                        margo_sso_encrypt_secret($d['private_key']),
                        hash('sha256', $d['private_key']),
                        $d['redirect_uris'],
                        $d['allowed_domains'],
                        (int)$rowC['id'],
                    ]);
                }
            }
        }
    }

    // Seed RSA-2048 JWKS signing key jika belum ada kunci aktif primary
    $jwksCount = (int)$pdo->query("SELECT COUNT(*) FROM sso_jwks_keys WHERE is_active = 1 AND is_primary = 1")->fetchColumn();
    if ($jwksCount === 0) {
        margo_sso_rotate_jwk_key($pdo, 24);
    }

    // 9. sso_agendas (Agenda Ekosistem Margo One & Ilmuna)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS sso_agendas (
            id INT AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(180) NOT NULL,
            description TEXT NULL,
            start_date DATE NOT NULL,
            end_date DATE NULL,
            start_time VARCHAR(12) NULL,
            location VARCHAR(160) NULL,
            category VARCHAR(40) NOT NULL DEFAULT 'akademik',
            link_url VARCHAR(255) NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_by INT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_sso_agenda_date (start_date, is_active),
            INDEX idx_sso_agenda_cat (category)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // 10. sso_announcements (Berita & Info Ekosistem di Right Sidebar Portal)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS sso_announcements (
            id INT AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(180) NOT NULL,
            excerpt TEXT NULL,
            link_url VARCHAR(255) NULL,
            badge_label VARCHAR(40) NOT NULL DEFAULT 'INFO',
            published_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_by INT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_sso_ann_pub (published_at, is_active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // 10b. margo_slo_revocations (Pencabutan Sesi Global SLO)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS margo_slo_revocations (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            sso_session_id BIGINT NULL,
            user_id INT NULL,
            revoked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_slo_sid (sso_session_id),
            INDEX idx_slo_uid (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // 11. sso_external_apps (Layanan Ekosistem Non-SSO / Tautan Cepat)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS sso_external_apps (
            id INT AUTO_INCREMENT PRIMARY KEY,
            app_name VARCHAR(120) NOT NULL,
            description VARCHAR(255) NULL,
            app_url VARCHAR(255) NOT NULL,
            app_icon VARCHAR(80) NOT NULL DEFAULT 'solar:widget-4-bold',
            app_logo VARCHAR(255) NULL DEFAULT NULL,
            badge_label VARCHAR(40) NOT NULL DEFAULT 'EKOSISTEM',
            sort_order INT NOT NULL DEFAULT 10,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_sso_ext_active (is_active, sort_order)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    try {
        $extCols = $pdo->query("SHOW COLUMNS FROM sso_external_apps")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('app_logo', $extCols, true)) {
            $pdo->exec("ALTER TABLE sso_external_apps ADD COLUMN app_logo VARCHAR(255) NULL DEFAULT NULL AFTER app_icon");
        }
    } catch (Throwable $eColExt) {
        // Non-fatal
    }

    // 12. sso_settings (Konfigurasi Terenkripsi di Database — misal Google OAuth Client ID/Secret)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS sso_settings (
            setting_key VARCHAR(80) PRIMARY KEY,
            setting_value TEXT NULL,
            is_encrypted TINYINT(1) NOT NULL DEFAULT 0,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // Catatan: Tidak ada auto-seed data dummy hardcode untuk sso_agendas, sso_announcements,
    // maupun sso_external_apps. Seluruh data agenda (termasuk sinkronisasi Kaldik), pengumuman,
    // dan layanan ekosistem 100% bersumber murni dari database.
    $ensured = true;
}

/**
 * Ambil nilai pengaturan dari tabel sso_settings (mendukung dekripsi AES-256-CBC+HMAC otomatis)
 */
if (!function_exists('margo_sso_get_setting')) {
    function margo_sso_get_setting(PDO $pdo, $key, $default = '')
    {
        try {
            $st = $pdo->prepare("SELECT setting_value, is_encrypted FROM sso_settings WHERE setting_key = ? LIMIT 1");
            $st->execute([(string)$key]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!$row || $row['setting_value'] === null || $row['setting_value'] === '') {
                return $default;
            }
            if ((int)$row['is_encrypted'] === 1) {
                $dec = margo_sso_decrypt_secret((string)$row['setting_value']);
                return $dec !== '' ? $dec : $default;
            }
            return (string)$row['setting_value'];
        } catch (Throwable $e) {
            return $default;
        }
    }
}

/**
 * Simpan pengaturan ke tabel sso_settings (dengan opsi enkripsi at-rest AES-256-CBC+HMAC)
 */
if (!function_exists('margo_sso_set_setting')) {
    function margo_sso_set_setting(PDO $pdo, $key, $value, $encrypt = false)
    {
        margo_sso_ensure_schema($pdo);
        $valStr = trim((string)$value);
        $stored = ($encrypt && $valStr !== '') ? margo_sso_encrypt_secret($valStr) : $valStr;
        $st = $pdo->prepare("
            INSERT INTO sso_settings (setting_key, setting_value, is_encrypted)
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE
                setting_value = VALUES(setting_value),
                is_encrypted  = VALUES(is_encrypted),
                updated_at    = CURRENT_TIMESTAMP
        ");
        $st->execute([(string)$key, $stored, $encrypt ? 1 : 0]);
        return true;
    }
}

/**
 * Bangkitkan RSA-2048 JWKS key baru & jadikan primary (kunci lama tetap aktif selama grace period)
 */
function margo_sso_rotate_jwk_key(PDO $pdo, $graceHours = 24)
{
    $rsa = margo_sso_generate_rsa_pair();
    if (empty($rsa['success'])) {
        throw new RuntimeException($rsa['message'] ?? 'Gagal membangkitkan kunci RSA-2048 JWKS.');
    }

    $kid = 'margo-' . date('Ymd') . '-' . bin2hex(random_bytes(6));
    $encPriv = margo_sso_encrypt_secret($rsa['private_pem']);
    $graceHours = max(1, (int)$graceHours);
    $graceExp = date('Y-m-d H:i:s', time() + ($graceHours * 3600));

    $pdo->beginTransaction();
    try {
        // Kunci lama tetap aktif selama grace period agar token yang sudah terbit tetap valid
        $stmtOld = $pdo->prepare("
            UPDATE sso_jwks_keys
            SET is_primary = 0,
                expires_at = COALESCE(expires_at, ?)
            WHERE is_primary = 1
        ");
        $stmtOld->execute([$graceExp]);

        $stmtNew = $pdo->prepare("
            INSERT INTO sso_jwks_keys (kid, public_pem, private_pem_enc, fingerprint, is_primary, is_active, expires_at)
            VALUES (?, ?, ?, ?, 1, 1, NULL)
        ");
        $stmtNew->execute([$kid, $rsa['public_pem'], $encPriv, $rsa['fingerprint']]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    return [
        'kid'         => $kid,
        'public_pem'  => $rsa['public_pem'],
        'private_pem' => $rsa['private_pem'],
        'fingerprint' => $rsa['fingerprint'],
    ];
}

/**
 * Ambil kunci primary RSA-2048 aktif untuk menandatangani JWT RS256
 */
function margo_sso_get_primary_jwk_key(PDO $pdo)
{
    margo_sso_ensure_schema($pdo);
    $row = $pdo->query("SELECT * FROM sso_jwks_keys WHERE is_active = 1 AND is_primary = 1 ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return margo_sso_rotate_jwk_key($pdo, 24);
    }
    $privPem = margo_sso_decrypt_secret($row['private_pem_enc']);
    if ($privPem === '') {
        return margo_sso_rotate_jwk_key($pdo, 24);
    }
    return [
        'id'          => (int)$row['id'],
        'kid'         => (string)$row['kid'],
        'public_pem'  => (string)$row['public_pem'],
        'private_pem' => $privPem,
        'fingerprint' => (string)$row['fingerprint'],
    ];
}

/**
 * Ambil seluruh kunci publik JWKS aktif (primary + kunci lama yang masih dalam grace period)
 */
function margo_sso_get_active_jwk_keys(PDO $pdo)
{
    margo_sso_ensure_schema($pdo);
    // Nonaktifkan kunci grace period yang sudah lewat
    $pdo->exec("UPDATE sso_jwks_keys SET is_active = 0 WHERE is_primary = 0 AND is_active = 1 AND expires_at IS NOT NULL AND expires_at < NOW()");

    return $pdo->query("
        SELECT id, kid, public_pem, fingerprint, is_primary, is_active, expires_at, created_at
        FROM sso_jwks_keys
        WHERE is_active = 1 AND (expires_at IS NULL OR expires_at >= NOW())
        ORDER BY is_primary DESC, id DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Ambil header HTTP lowercase secara portabel (Apache / LiteSpeed / Nginx / CLI)
 */
function margo_sso_get_headers(array $overrideHeaders = null)
{
    $headers = [];
    if (is_array($overrideHeaders)) {
        foreach ($overrideHeaders as $k => $v) {
            $headers[strtolower(trim((string)$k))] = trim((string)$v);
        }
        return $headers;
    }

    if (function_exists('getallheaders')) {
        $all = @getallheaders();
        if (is_array($all)) {
            foreach ($all as $k => $v) {
                $headers[strtolower(trim((string)$k))] = trim((string)$v);
            }
        }
    }

    foreach ($_SERVER as $key => $val) {
        if (strpos($key, 'HTTP_') === 0) {
            $name = strtolower(str_replace('_', '-', substr($key, 5)));
            if (!isset($headers[$name])) {
                $headers[$name] = trim((string)$val);
            }
        }
    }

    if (!isset($headers['authorization']) && !empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
        $headers['authorization'] = trim((string)$_SERVER['REDIRECT_HTTP_AUTHORIZATION']);
    }

    return $headers;
}

/**
 * Ambil IP klien yang aman
 */
function margo_sso_client_ip()
{
    $candidates = [];
    if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
        $candidates[] = $_SERVER['HTTP_CF_CONNECTING_IP'];
    }
    if (!empty($_SERVER['REMOTE_ADDR'])) {
        $candidates[] = $_SERVER['REMOTE_ADDR'];
    }
    foreach ($candidates as $ip) {
        $ip = trim((string)$ip);
        if (filter_var($ip, FILTER_VALIDATE_IP)) {
            return $ip;
        }
    }
    return '127.0.0.1';
}

/**
 * Normalisasi canonical path untuk endpoint /api/sso/*
 * Contoh: /SSO/api/sso/token.php atau /api/sso/token -> /api/sso/token
 */
function margo_sso_canonical_path($requestUri)
{
    $path = parse_url((string)$requestUri, PHP_URL_PATH);
    if (!is_string($path) || $path === '') {
        $path = '/api/sso/token';
    }
    $base = basename($path);
    $base = preg_replace('/\.php$/i', '', $base);
    if ($base === '' || $base === '/' || $base === '.') {
        $base = 'token';
    }
    return '/api/sso/' . strtolower($base);
}

/**
 * Normalisasi query string deterministik (diurutkan per kunci, RFC3986)
 */
function margo_sso_canonical_query(array $queryParams)
{
    $normalized = [];
    foreach ($queryParams as $k => $v) {
        if (is_array($v)) {
            $normalized[(string)$k] = implode(',', array_map('strval', $v));
        } else {
            $normalized[(string)$k] = (string)$v;
        }
    }
    ksort($normalized, SORT_STRING);
    return http_build_query($normalized, '', '&', PHP_QUERY_RFC3986);
}

/**
 * Canonical String untuk penandatanganan & verifikasi request server-to-server
 */
function margo_sso_canonical_string($method, $canonicalPath, array $queryParams, $timestamp, $nonce, $rawBody = '')
{
    $method   = strtoupper(trim((string)$method));
    $cPath    = margo_sso_canonical_path($canonicalPath);
    $cQuery   = margo_sso_canonical_query($queryParams);
    $bodyHash = hash('sha256', (string)$rawBody);

    return implode("\n", [
        $method,
        $cPath,
        $cQuery,
        (string)$timestamp,
        (string)$nonce,
        $bodyHash,
    ]);
}

function margo_sso_sign_hmac($canonicalString, $privateKey)
{
    return hash_hmac('sha256', (string)$canonicalString, (string)$privateKey);
}

function margo_sso_sign_rsa($canonicalString, $rsaPrivatePem)
{
    $sig = '';
    $ok = @openssl_sign((string)$canonicalString, $sig, (string)$rsaPrivatePem, OPENSSL_ALGO_SHA256);
    return $ok ? base64_encode($sig) : '';
}

function margo_sso_sign_response($responseTimestamp, $jsonBody, $privateKey)
{
    return hash_hmac('sha256', (string)$responseTimestamp . "\n" . (string)$jsonBody, (string)$privateKey);
}

function margo_sso_extract_host($value)
{
    $value = trim(strtolower((string)$value));
    if ($value === '') {
        return '';
    }
    if (strpos($value, '://') === false) {
        $value = 'https://' . $value;
    }
    $host = parse_url($value, PHP_URL_HOST);
    return is_string($host) ? strtolower(trim($host)) : '';
}

function margo_sso_parse_list($raw)
{
    $parts = preg_split('/[\s,;]+/', (string)$raw);
    $out = [];
    if (is_array($parts)) {
        foreach ($parts as $p) {
            $p = strtolower(trim($p));
            if ($p !== '') {
                $out[] = $p;
            }
        }
    }
    return array_values(array_unique($out));
}

/**
 * Parse daftar Redirect URI (case-sensitive path, dipisahkan newline atau koma)
 */
function margo_sso_parse_redirect_uris($raw)
{
    $lines = preg_split('/[\r\n,]+/', (string)$raw);
    $out = [];
    if (is_array($lines)) {
        foreach ($lines as $line) {
            $u = trim($line);
            if ($u !== '') {
                $out[] = $u;
            }
        }
    }
    return array_values(array_unique($out));
}

/**
 * Validasi exact-match redirect_uri terhadap whitelist klien
 */
function margo_sso_redirect_uri_allowed($redirectUri, $allowedUrisRaw)
{
    $redirectUri = trim((string)$redirectUri);
    if ($redirectUri === '') {
        return false;
    }
    $parsed = parse_url($redirectUri);
    if (!is_array($parsed) || empty($parsed['scheme']) || empty($parsed['host'])) {
        return false;
    }
    $scheme = strtolower((string)$parsed['scheme']);
    if (!in_array($scheme, ['https', 'http'], true)) {
        return false;
    }
    // Fragment (#...) dilarang keras di OAuth 2.0 redirect_uri (RFC 6749 Section 3.1.2)
    if (isset($parsed['fragment'])) {
        return false;
    }

    $allowed = margo_sso_parse_redirect_uris($allowedUrisRaw);
    foreach ($allowed as $entry) {
        if (hash_equals($entry, $redirectUri)) {
            return true;
        }
    }
    return false;
}

/**
 * Cek apakah domain diizinkan (mendukung wildcard *.ilmuna.my.id)
 */
function margo_sso_domain_allowed($host, array $allowedDomains)
{
    $host = strtolower(trim((string)$host));
    if ($host === '') {
        return false;
    }
    foreach ($allowedDomains as $pattern) {
        $pattern = strtolower(trim($pattern));
        if ($pattern === '') {
            continue;
        }
        if ($pattern === $host) {
            return true;
        }
        if (strpos($pattern, '*.') === 0) {
            $suffix = substr($pattern, 1);
            $len = strlen($suffix);
            if ($len > 0 && strlen($host) > $len && substr($host, -$len) === $suffix) {
                return true;
            }
        }
    }
    return false;
}

/**
 * Cek kecocokan IP terhadap whitelist (exact IPv4/IPv6 atau CIDR IPv4)
 */
function margo_sso_ip_allowed($clientIp, array $allowedIps)
{
    if (empty($allowedIps)) {
        return true;
    }
    foreach ($allowedIps as $rule) {
        $rule = trim($rule);
        if ($rule === '') {
            continue;
        }
        if ($rule === $clientIp) {
            return true;
        }
        if (strpos($rule, '/') !== false && filter_var($clientIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            list($subnet, $mask) = explode('/', $rule, 2);
            $maskInt = (int)$mask;
            if (filter_var($subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && $maskInt >= 0 && $maskInt <= 32) {
                $ipLong = ip2long($clientIp);
                $subLong = ip2long($subnet);
                $maskLong = ($maskInt === 0) ? 0 : (~((1 << (32 - $maskInt)) - 1));
                if (($ipLong & $maskLong) === ($subLong & $maskLong)) {
                    return true;
                }
            }
        }
    }
    return false;
}

/**
 * Sliding-window Rate Limit per menit berdasarkan Public Key / bucket + IP
 */
function margo_sso_check_rate_limit(PDO $pdo, $bucketScope, $clientIp, $limitPerMin)
{
    $limitPerMin = max(5, (int)$limitPerMin);
    $minuteWindow = (int)floor(time() / 60);
    $bucketKey = hash('sha256', $bucketScope . '|' . $clientIp . '|' . $minuteWindow);
    $expiresAt = date('Y-m-d H:i:s', ($minuteWindow + 2) * 60);

    if (mt_rand(1, 20) === 1) {
        $pdo->exec("DELETE FROM sso_rate_limits WHERE expires_at < NOW()");
    }

    $stmt = $pdo->prepare("
        INSERT INTO sso_rate_limits (bucket_key, hits, expires_at)
        VALUES (?, 1, ?)
        ON DUPLICATE KEY UPDATE hits = hits + 1
    ");
    $stmt->execute([$bucketKey, $expiresAt]);

    $sel = $pdo->prepare("SELECT hits FROM sso_rate_limits WHERE bucket_key = ?");
    $sel->execute([$bucketKey]);
    $hits = (int)$sel->fetchColumn();

    return [
        'allowed'   => ($hits <= $limitPerMin),
        'limit'     => $limitPerMin,
        'hits'      => $hits,
        'remaining' => max(0, $limitPerMin - $hits),
        'reset_in'  => 60 - (time() % 60),
    ];
}

/**
 * Catat jejak audit SSO ke sso_audit_logs
 */
function margo_sso_log_audit(PDO $pdo, $eventType, array $data = [])
{
    try {
        if (mt_rand(1, 30) === 1) {
            $pdo->exec("DELETE FROM sso_audit_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL 60 DAY)");
        }
        $userId     = isset($data['user_id']) && $data['user_id'] !== null ? (int)$data['user_id'] : null;
        $clientId   = isset($data['client_id']) && $data['client_id'] !== null ? (int)$data['client_id'] : null;
        $httpStatus = isset($data['http_status']) ? (int)$data['http_status'] : 200;
        $ip         = isset($data['ip_address']) ? (string)$data['ip_address'] : margo_sso_client_ip();
        $ua         = isset($data['user_agent']) ? (string)$data['user_agent'] : (string)($_SERVER['HTTP_USER_AGENT'] ?? 'CLI');
        $detail     = isset($data['detail']) ? $data['detail'] : null;
        $detailJson = $detail !== null ? json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;

        $stmt = $pdo->prepare("
            INSERT INTO sso_audit_logs (user_id, client_id, event_type, http_status, ip_address, user_agent, detail_json)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $userId,
            $clientId,
            substr((string)$eventType, 0, 60),
            $httpStatus,
            substr($ip, 0, 64),
            substr($ua, 0, 255),
            $detailJson,
        ]);
    } catch (Throwable $e) {
        // Kegagalan audit log tidak boleh menghentikan respon utama
    }
}

/**
 * Verifikasi autentikasi Zero-Trust penuh untuk endpoint server-to-server (/api/sso/*)
 */
function margo_sso_authenticate_client(PDO $pdo, array $opts = [])
{
    margo_sso_ensure_schema($pdo);

    $method     = strtoupper((string)($opts['method'] ?? ($_SERVER['REQUEST_METHOD'] ?? 'POST')));
    $requestUri = (string)($opts['uri'] ?? ($_SERVER['REQUEST_URI'] ?? '/api/sso/token.php'));
    $query      = isset($opts['query']) && is_array($opts['query']) ? $opts['query'] : $_GET;
    $rawBody    = isset($opts['body']) ? (string)$opts['body'] : '';
    $headers    = margo_sso_get_headers(isset($opts['headers']) ? $opts['headers'] : null);
    $clientIp   = (string)($opts['client_ip'] ?? margo_sso_client_ip());

    $publicKey  = isset($headers['x-ilmuna-public-key']) ? trim($headers['x-ilmuna-public-key']) : '';
    $timestamp  = isset($headers['x-ilmuna-timestamp']) ? trim($headers['x-ilmuna-timestamp']) : '';
    $nonce      = isset($headers['x-ilmuna-nonce']) ? trim($headers['x-ilmuna-nonce']) : '';
    $signature  = isset($headers['x-ilmuna-signature']) ? trim($headers['x-ilmuna-signature']) : '';
    $rsaSig     = isset($headers['x-ilmuna-rsa-signature']) ? trim($headers['x-ilmuna-rsa-signature']) : '';
    $siteHeader = isset($headers['x-ilmuna-site']) ? trim($headers['x-ilmuna-site']) : '';
    $originHdr  = isset($headers['origin']) ? trim($headers['origin']) : '';
    $refererHdr = isset($headers['referer']) ? trim($headers['referer']) : '';

    // 1. Header wajib
    if ($publicKey === '' || $timestamp === '' || $nonce === '') {
        return [
            'ok'      => false,
            'status'  => 401,
            'code'    => 'MISSING_AUTH_HEADERS',
            'message' => 'Header autentikasi wajib (X-Ilmuna-Public-Key, X-Ilmuna-Timestamp, X-Ilmuna-Nonce) tidak lengkap.',
            'meta'    => ['public_key' => $publicKey, 'client_ip' => $clientIp, 'client_site' => $siteHeader],
        ];
    }

    // 2. Validasi Timestamp (+/- 300 detik)
    if (!ctype_digit($timestamp)) {
        return [
            'ok'      => false,
            'status'  => 401,
            'code'    => 'INVALID_TIMESTAMP',
            'message' => 'Header X-Ilmuna-Timestamp harus berupa Unix timestamp angka bulat.',
            'meta'    => ['public_key' => $publicKey, 'client_ip' => $clientIp, 'client_site' => $siteHeader],
        ];
    }
    $tsInt = (int)$timestamp;
    $now = time();
    if (abs($now - $tsInt) > MARGO_SSO_MAX_SKEW) {
        return [
            'ok'      => false,
            'status'  => 401,
            'code'    => 'TIMESTAMP_EXPIRED',
            'message' => 'Timestamp request kedaluwarsa (maks selisih ' . MARGO_SSO_MAX_SKEW . ' detik).',
            'meta'    => ['public_key' => $publicKey, 'client_ip' => $clientIp, 'client_site' => $siteHeader, 'server_time' => $now],
        ];
    }

    // 3. Validasi format Nonce
    if (!preg_match('/^[a-zA-Z0-9_\-]{16,128}$/', $nonce)) {
        return [
            'ok'      => false,
            'status'  => 401,
            'code'    => 'INVALID_NONCE_FORMAT',
            'message' => 'Header X-Ilmuna-Nonce wajib string acak minimal 16 karakter alfanumerik/hex.',
            'meta'    => ['public_key' => $publicKey, 'client_ip' => $clientIp, 'client_site' => $siteHeader],
        ];
    }

    // 4. Lookup Client berdasarkan Public Key
    $stmt = $pdo->prepare("SELECT * FROM sso_clients WHERE public_key = ? LIMIT 1");
    $stmt->execute([$publicKey]);
    $client = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$client) {
        return [
            'ok'      => false,
            'status'  => 401,
            'code'    => 'INVALID_PUBLIC_KEY',
            'message' => 'Public Key klien SSO tidak dikenali oleh Margo One.',
            'meta'    => ['public_key' => $publicKey, 'client_ip' => $clientIp, 'client_site' => $siteHeader],
        ];
    }

    if ((int)$client['is_active'] !== 1) {
        return [
            'ok'      => false,
            'status'  => 403,
            'code'    => 'CLIENT_DISABLED',
            'message' => 'Aplikasi klien SSO sedang dinonaktifkan oleh Administrator.',
            'meta'    => ['client_id' => (int)$client['id'], 'public_key' => $publicKey, 'client_ip' => $clientIp, 'client_site' => $siteHeader],
        ];
    }

    // 5. Rate Limit per Public Key + IP
    $rl = margo_sso_check_rate_limit($pdo, $publicKey, $clientIp, (int)$client['rate_limit_per_min']);
    if (!$rl['allowed']) {
        return [
            'ok'         => false,
            'status'     => 429,
            'code'       => 'RATE_LIMIT_EXCEEDED',
            'message'    => 'Batas request SSO (' . $rl['limit'] . '/menit) terlampaui. Coba lagi dalam ' . $rl['reset_in'] . ' detik.',
            'rate_limit' => $rl,
            'meta'       => ['client_id' => (int)$client['id'], 'public_key' => $publicKey, 'client_ip' => $clientIp, 'client_site' => $siteHeader],
        ];
    }

    // 6. Whitelist IP
    $allowedIps = margo_sso_parse_list($client['allowed_ips']);
    if (!empty($allowedIps) && !margo_sso_ip_allowed($clientIp, $allowedIps)) {
        return [
            'ok'         => false,
            'status'     => 403,
            'code'       => 'IP_NOT_ALLOWED',
            'message'    => 'Alamat IP pengirim (' . $clientIp . ') tidak terdaftar dalam whitelist IP.',
            'rate_limit' => $rl,
            'meta'       => ['client_id' => (int)$client['id'], 'public_key' => $publicKey, 'client_ip' => $clientIp, 'client_site' => $siteHeader],
        ];
    }

    // 7. Whitelist Domain (X-Ilmuna-Site, Origin, Referer)
    $allowedDomains = margo_sso_parse_list($client['allowed_domains']);
    if (!empty($allowedDomains)) {
        if ($siteHeader !== '') {
            $siteHost = margo_sso_extract_host($siteHeader);
            if (!margo_sso_domain_allowed($siteHost, $allowedDomains)) {
                return [
                    'ok'         => false,
                    'status'     => 403,
                    'code'       => 'DOMAIN_NOT_ALLOWED',
                    'message'    => 'Domain klien (' . $siteHost . ') tidak diizinkan mengakses endpoint SSO ini.',
                    'rate_limit' => $rl,
                    'meta'       => ['client_id' => (int)$client['id'], 'public_key' => $publicKey, 'client_ip' => $clientIp, 'client_site' => $siteHost],
                ];
            }
        }
        if ($originHdr !== '') {
            $originHost = margo_sso_extract_host($originHdr);
            if (!margo_sso_domain_allowed($originHost, $allowedDomains)) {
                return [
                    'ok'         => false,
                    'status'     => 403,
                    'code'       => 'ORIGIN_NOT_ALLOWED',
                    'message'    => 'Origin (' . $originHost . ') diblokir oleh kebijakan keamanan SSO.',
                    'rate_limit' => $rl,
                    'meta'       => ['client_id' => (int)$client['id'], 'public_key' => $publicKey, 'client_ip' => $clientIp, 'client_site' => $originHost],
                ];
            }
        }
        if ($refererHdr !== '') {
            $refHost = margo_sso_extract_host($refererHdr);
            if ($refHost !== '' && !margo_sso_domain_allowed($refHost, $allowedDomains)) {
                return [
                    'ok'         => false,
                    'status'     => 403,
                    'code'       => 'REFERER_NOT_ALLOWED',
                    'message'    => 'Referer (' . $refHost . ') tidak masuk dalam domain yang diizinkan.',
                    'rate_limit' => $rl,
                    'meta'       => ['client_id' => (int)$client['id'], 'public_key' => $publicKey, 'client_ip' => $clientIp, 'client_site' => $refHost],
                ];
            }
        }
    }

    // 8. Dekripsi Private Key Klien
    $privateKey = margo_sso_decrypt_secret($client['private_key_enc']);
    if ($privateKey === '') {
        return [
            'ok'         => false,
            'status'     => 500,
            'code'       => 'SERVER_KEY_ERROR',
            'message'    => 'Gagal mendekripsi Private Key klien SSO. Lakukan rotasi kunci di panel Admin.',
            'rate_limit' => $rl,
            'meta'       => ['client_id' => (int)$client['id'], 'public_key' => $publicKey, 'client_ip' => $clientIp, 'client_site' => $siteHeader],
        ];
    }

    $sigMode   = (string)$client['sig_mode'];
    $canonical = margo_sso_canonical_string($method, $requestUri, $query, $timestamp, $nonce, $rawBody);

    // 8a. Verifikasi HMAC-SHA256
    if ($sigMode === 'HMAC-SHA256' || $sigMode === 'DUAL') {
        if ($signature === '') {
            return [
                'ok'         => false,
                'status'     => 401,
                'code'       => 'MISSING_HMAC_SIGNATURE',
                'message'    => 'Header X-Ilmuna-Signature (HMAC-SHA256) wajib disertakan.',
                'rate_limit' => $rl,
                'meta'       => ['client_id' => (int)$client['id'], 'public_key' => $publicKey, 'client_ip' => $clientIp, 'sig_mode' => $sigMode],
            ];
        }
        $expectedHmac = margo_sso_sign_hmac($canonical, $privateKey);
        if (!hash_equals($expectedHmac, strtolower($signature))) {
            return [
                'ok'         => false,
                'status'     => 401,
                'code'       => 'INVALID_HMAC_SIGNATURE',
                'message'    => 'Tanda tangan HMAC-SHA256 tidak valid.',
                'rate_limit' => $rl,
                'meta'       => ['client_id' => (int)$client['id'], 'public_key' => $publicKey, 'client_ip' => $clientIp, 'sig_mode' => $sigMode],
            ];
        }
    }

    // 8b. Verifikasi RSA-SHA256
    if ($sigMode === 'RSA-SHA256' || $sigMode === 'DUAL') {
        $rsaPublicPem = trim((string)($client['rsa_public_pem'] ?? ''));
        if ($rsaPublicPem === '') {
            return [
                'ok'         => false,
                'status'     => 500,
                'code'       => 'RSA_PUBLIC_KEY_NOT_CONFIGURED',
                'message'    => 'Mode ' . $sigMode . ' aktif tetapi RSA Public Key PEM belum dikonfigurasi.',
                'rate_limit' => $rl,
                'meta'       => ['client_id' => (int)$client['id'], 'public_key' => $publicKey, 'client_ip' => $clientIp, 'sig_mode' => $sigMode],
            ];
        }
        if ($rsaSig === '') {
            return [
                'ok'         => false,
                'status'     => 401,
                'code'       => 'MISSING_RSA_SIGNATURE',
                'message'    => 'Header X-Ilmuna-RSA-Signature wajib disertakan untuk mode ' . $sigMode . '.',
                'rate_limit' => $rl,
                'meta'       => ['client_id' => (int)$client['id'], 'public_key' => $publicKey, 'client_ip' => $clientIp, 'sig_mode' => $sigMode],
            ];
        }
        $rawRsaSig = base64_decode($rsaSig, true);
        if ($rawRsaSig === false) {
            return [
                'ok'         => false,
                'status'     => 401,
                'code'       => 'INVALID_RSA_SIGNATURE_ENCODING',
                'message'    => 'Format X-Ilmuna-RSA-Signature harus Base64 valid.',
                'rate_limit' => $rl,
                'meta'       => ['client_id' => (int)$client['id'], 'public_key' => $publicKey, 'client_ip' => $clientIp, 'sig_mode' => $sigMode],
            ];
        }
        $verified = @openssl_verify($canonical, $rawRsaSig, $rsaPublicPem, OPENSSL_ALGO_SHA256);
        if ($verified !== 1) {
            return [
                'ok'         => false,
                'status'     => 401,
                'code'       => 'INVALID_RSA_SIGNATURE',
                'message'    => 'Verifikasi tanda tangan asimetris RSA-2048 gagal.',
                'rate_limit' => $rl,
                'meta'       => ['client_id' => (int)$client['id'], 'public_key' => $publicKey, 'client_ip' => $clientIp, 'sig_mode' => $sigMode],
            ];
        }
    }

    // 9. Anti-Replay Nonce Protection (hanya direkam setelah signature lolos!)
    if (mt_rand(1, 20) === 1) {
        $pdo->exec("DELETE FROM sso_nonces WHERE expires_at < NOW()");
    }
    $nonceHash = hash('sha256', $nonce);
    $nonceExp  = date('Y-m-d H:i:s', $now + (MARGO_SSO_MAX_SKEW * 2));
    try {
        $insNonce = $pdo->prepare("INSERT INTO sso_nonces (public_key, nonce_hash, expires_at) VALUES (?, ?, ?)");
        $insNonce->execute([$publicKey, $nonceHash, $nonceExp]);
    } catch (PDOException $e) {
        if ((string)$e->getCode() === '23000') {
            return [
                'ok'         => false,
                'status'     => 401,
                'code'       => 'REPLAY_DETECTED',
                'message'    => 'Nonce sudah pernah digunakan (potensi replay attack diblokir).',
                'rate_limit' => $rl,
                'meta'       => ['client_id' => (int)$client['id'], 'public_key' => $publicKey, 'client_ip' => $clientIp, 'sig_mode' => $sigMode],
            ];
        }
        throw $e;
    }

    // 10. Update statistik klien
    $upd = $pdo->prepare("UPDATE sso_clients SET last_used_at = NOW(), last_ip = ?, request_count = request_count + 1 WHERE id = ?");
    $upd->execute([$clientIp, (int)$client['id']]);

    return [
        'ok'              => true,
        'client'          => $client,
        'private_key'     => $privateKey,
        'rate_limit'      => $rl,
        'sig_mode'        => $sigMode,
        'client_ip'       => $clientIp,
        'client_site'     => ($siteHeader !== '' ? margo_sso_extract_host($siteHeader) : margo_sso_extract_host($originHdr)),
        'origin'          => $originHdr,
        'allowed_domains' => $allowedDomains,
    ];
}

/**
 * Emit respon JSON standar untuk endpoint /api/sso/* lengkap dengan signed response headers
 */
function margo_sso_emit_json($httpStatus, array $payload, array $extraHeaders = [], $privateKey = null, $cacheControl = 'no-store, no-cache, must-revalidate, private')
{
    while (ob_get_level()) {
        ob_end_clean();
    }

    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        $httpStatus = 500;
        $json = '{"success":false,"error":{"code":"JSON_ENCODE_ERROR","message":"Gagal menyusun respon JSON."}}';
    }

    if (!headers_sent()) {
        http_response_code((int)$httpStatus);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('X-Robots-Tag: noindex, nofollow');
        header('Cache-Control: ' . $cacheControl);
        header('X-Margo-SSO-Version: ' . MARGO_SSO_API_VERSION);

        if ($privateKey !== null && $privateKey !== '') {
            $respTs = time();
            $respSig = margo_sso_sign_response($respTs, $json, $privateKey);
            header('X-Ilmuna-Response-Timestamp: ' . $respTs);
            header('X-Ilmuna-Response-Signature: ' . $respSig);
        }

        foreach ($extraHeaders as $k => $v) {
            header($k . ': ' . $v);
        }
    }

    echo $json;
    exit;
}

/**
 * Catat pencabutan sesi global (SLO) ke database Margo One & database sibling (uofruaju_learn_ilmuna)
 * agar aplikasi di server MySQL yang sama langsung mendeteksi logout dalam <1ms walaupun webhook HTTP
 * terhalang Cloudflare / firewall loopback.
 */
function margo_sso_record_cross_db_revocation(PDO $pdo, $userId, $ssoSessionId = null)
{
    $uid = (int)$userId > 0 ? (int)$userId : null;
    $sid = (int)$ssoSessionId > 0 ? (int)$ssoSessionId : null;
    if ($uid === null && $sid === null) {
        return;
    }

    $createSql = "
        CREATE TABLE IF NOT EXISTS margo_slo_revocations (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            sso_session_id BIGINT NULL,
            user_id INT NULL,
            revoked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_slo_sid (sso_session_id),
            INDEX idx_slo_uid (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ";

    // 1. Catat di DB aktif Margo One
    try {
        $pdo->exec($createSql);
        $st = $pdo->prepare("INSERT INTO margo_slo_revocations (sso_session_id, user_id, revoked_at) VALUES (?, ?, NOW())");
        $st->execute([$sid, $uid]);
    } catch (Throwable $e) {
        // Non-fatal
    }

    // 2. Catat langsung ke DB uofruaju_learn_ilmuna bila berada di server MySQL yang sama (Laragon / cPanel)
    $siblingDbs = ['uofruaju_learn_ilmuna', 'uofruaju_margo_one'];
    foreach ($siblingDbs as $dbName) {
        try {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS `{$dbName}`.`margo_slo_revocations` (
                    id BIGINT AUTO_INCREMENT PRIMARY KEY,
                    sso_session_id BIGINT NULL,
                    user_id INT NULL,
                    revoked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_slo_sid (sso_session_id),
                    INDEX idx_slo_uid (user_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
            $stSib = $pdo->prepare("INSERT INTO `{$dbName}`.`margo_slo_revocations` (sso_session_id, user_id, revoked_at) VALUES (?, ?, NOW())");
            $stSib->execute([$sid, $uid]);

            if ($uid !== null) {
                $stSess = $pdo->prepare("UPDATE `{$dbName}`.`sso_sessions` SET revoked_at = NOW() WHERE user_id = ? AND revoked_at IS NULL");
                @$stSess->execute([$uid]);
            } elseif ($sid !== null) {
                $stSess = $pdo->prepare("UPDATE `{$dbName}`.`sso_sessions` SET revoked_at = NOW() WHERE id = ? AND revoked_at IS NULL");
                @$stSess->execute([$sid]);
            }
        } catch (Throwable $eSib) {
            // Abaikan bila user MySQL tidak memiliki grant lintas DB tersebut
        }
    }
}

/**
 * Kirim Back-Channel Single Logout (SLO) Webhook bertanda tangan ke semua klien aktif
 */
function margo_sso_dispatch_slo_webhooks(PDO $pdo, $userId, $ssoSessionId = null)
{
    margo_sso_record_cross_db_revocation($pdo, $userId, $ssoSessionId);

    $results = [];
    try {
        $clients = $pdo->query("
            SELECT id, client_name, public_key, private_key_enc, logout_webhook_url
            FROM sso_clients
            WHERE is_active = 1 AND logout_webhook_url IS NOT NULL AND TRIM(logout_webhook_url) != ''
        ")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return $results;
    }

    if (empty($clients)) {
        return $results;
    }

    $primaryKey = margo_sso_get_primary_jwk_key($pdo);
    $now = time();

    foreach ($clients as $c) {
        $url = trim((string)$c['logout_webhook_url']);
        if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
            continue;
        }
        $privKey = margo_sso_decrypt_secret($c['private_key_enc']);
        if ($privKey === '') {
            continue;
        }

        // Logout Token JWT (RFC 8935 / OpenID Back-Channel Logout)
        $logoutJwt = margo_jwt_sign_rs256([
            'iss'    => SSO_ISSUER,
            'sub'    => (string)$userId,
            'aud'    => (string)$c['public_key'],
            'iat'    => $now,
            'exp'    => $now + 120,
            'jti'    => margo_sso_uuid_v4(),
            'sid'    => $ssoSessionId ? (string)$ssoSessionId : null,
            'events' => [
                'http://schemas.openid.net/event/backchannel-logout' => new stdClass(),
            ],
        ], $primaryKey['private_pem'], $primaryKey['kid']);

        $body = json_encode([
            'event'        => 'single_logout',
            'user_id'      => (int)$userId,
            'sid'          => $ssoSessionId ? (int)$ssoSessionId : null,
            'logout_token' => $logoutJwt,
            'timestamp'    => $now,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $nonce = bin2hex(random_bytes(16));
        $ts    = (string)$now;
        $sig   = hash_hmac('sha256', $ts . "\n" . $nonce . "\n" . $body, $privKey);

        $ok = false;
        $httpCode = 0;

        // Jangan memblokir lama saat lokal atau server tujuan offline (timeout singkat 2 detik)
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $body,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 2,
                CURLOPT_CONNECTTIMEOUT => 1,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => 0,
                CURLOPT_HTTPHEADER     => [
                    'Content-Type: application/json',
                    'X-Ilmuna-Public-Key: ' . $c['public_key'],
                    'X-Ilmuna-Timestamp: ' . $ts,
                    'X-Ilmuna-Nonce: ' . $nonce,
                    'X-Ilmuna-Signature: ' . $sig,
                    'User-Agent: MargoOne-SSO-SLO/1.0',
                ],
            ]);
            @curl_exec($ch);
            $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            $ok = ($httpCode >= 200 && $httpCode < 300);
        }

        $results[] = [
            'client_id'   => (int)$c['id'],
            'client_name' => (string)$c['client_name'],
            'http_code'   => $httpCode,
            'ok'          => $ok,
        ];
    }

    return $results;
}
