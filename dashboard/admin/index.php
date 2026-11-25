<?php
/**
 * ==============================================================================
 * MARGO ONE — ILMUNA SSO · PANEL ADMIN TERPADU (DATA-FIRST LUMINA GOLD)
 * ==============================================================================
 * 4 Segmented Tabs (.curriculum-main-tabs):
 *   1. Aplikasi Klien (sso_clients) — CRUD, Public/Private Key, Rotasi, RSA-2048 PEM, Uji Koneksi Signed
 *   2. Sesi Aktif Global (sso_sessions) — Monitoring & Force Logout 1-klik / Semua Sesi User
 *   3. Identitas & Multi-Role (users, roles, user_roles) — Google SSO, Reset Password, Role, Orphan Guru Auto-Heal
 *   4. JWKS RS256, SDK & Audit Log (sso_jwks_keys, sso_audit_logs) — Rotasi JWT Key + Unduh SDK + 100 Log
 *
 * Standar Mutlak: Data-First Zero Title, Zero KPI Cards, Iconify Solar Icons (solar:*),
 *                 window.Ilmuna (alert.js), Zero Page Reload, Mobile 360px+, PHP 7.4–8.3.
 * ==============================================================================
 */

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../auth.php';
require_once __DIR__ . '/../../database.php';
require_once BASE_PATH . '/includes/agenda-engine.php';

requireRole('admin');

$pdo = getDBConnection();
$pdo->exec("SET time_zone = '+07:00'");
margo_sso_ensure_schema($pdo);

if (empty($_SESSION['csrf_admin_sso'])) {
    $_SESSION['csrf_admin_sso'] = bin2hex(random_bytes(32));
}
$csrfAdmin = $_SESSION['csrf_admin_sso'];

/* ==============================================================================
 * UNDUH SDK & PAKET INTEGRASI (?download_sdk=client|learn|wp)
 * ============================================================================== */
if (isset($_GET['download_sdk'])) {
    $pkg = strtolower(trim((string)$_GET['download_sdk']));
    while (ob_get_level()) {
        ob_end_clean();
    }

    if ($pkg === 'client') {
        $f = BASE_PATH . '/sdk/ilmuna-sso-client.php';
        if (!file_exists($f)) {
            http_response_code(404);
            exit('SDK file tidak ditemukan.');
        }
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="ilmuna-sso-client.php"');
        header('Content-Length: ' . filesize($f));
        header('Cache-Control: no-store');
        readfile($f);
        exit;
    }

    if ($pkg === 'wp') {
        $f = BASE_PATH . '/sdk/margo-one-wp-sso/margo-one-wp-sso.php';
        if (!file_exists($f)) {
            http_response_code(404);
            exit('Plugin WordPress tidak ditemukan.');
        }
        if (class_exists('ZipArchive')) {
            $tmpZip = tempnam(sys_get_temp_dir(), 'margo_wp_');
            $zip = new ZipArchive();
            if ($zip->open($tmpZip, ZipArchive::OVERWRITE) === true) {
                $zip->addFile($f, 'margo-one-wp-sso/margo-one-wp-sso.php');
                $zip->close();
                header('Content-Type: application/zip');
                header('Content-Disposition: attachment; filename="margo-one-wp-sso.zip"');
                header('Content-Length: ' . filesize($tmpZip));
                header('Cache-Control: no-store');
                readfile($tmpZip);
                @unlink($tmpZip);
                exit;
            }
        }
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="margo-one-wp-sso.php"');
        header('Content-Length: ' . filesize($f));
        readfile($f);
        exit;
    }

    if ($pkg === 'learn') {
        $files = [
            'sdk/ilmuna-sso-client.php'                 => BASE_PATH . '/sdk/ilmuna-sso-client.php',
            'sso-callback.php'                          => BASE_PATH . '/sdk/learn-integration/sso-callback.php',
            'sso-logout-webhook.php'                    => BASE_PATH . '/sdk/learn-integration/sso-logout-webhook.php',
            'INTEGRATION_GUIDE.md'                      => BASE_PATH . '/sdk/learn-integration/INTEGRATION_GUIDE.md',
        ];
        if (class_exists('ZipArchive')) {
            $tmpZip = tempnam(sys_get_temp_dir(), 'margo_learn_');
            $zip = new ZipArchive();
            if ($zip->open($tmpZip, ZipArchive::OVERWRITE) === true) {
                foreach ($files as $entry => $path) {
                    if (file_exists($path)) {
                        $zip->addFile($path, 'margo-one-learn-integration/' . $entry);
                    }
                }
                $zip->close();
                header('Content-Type: application/zip');
                header('Content-Disposition: attachment; filename="margo-one-learn-integration.zip"');
                header('Content-Length: ' . filesize($tmpZip));
                header('Cache-Control: no-store');
                readfile($tmpZip);
                @unlink($tmpZip);
                exit;
            }
        }
        http_response_code(500);
        exit('ZipArchive tidak tersedia.');
    }
}

/* ==============================================================================
 * AJAX JSON API (?api=1) — ZERO PAGE RELOAD
 * ============================================================================== */
function margo_admin_handle_app_logo_upload($fileKey, $slugPrefix = 'app')
{
    if (empty($_FILES[$fileKey]) || !is_array($_FILES[$fileKey])) {
        return null;
    }
    $f = $_FILES[$fileKey];
    if ((int)($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE || empty($f['tmp_name'])) {
        return null;
    }
    if ((int)($f['error'] ?? 0) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Gagal mengunggah file gambar/icon aplikasi (kode error: ' . (int)$f['error'] . ').');
    }
    if ((int)($f['size'] ?? 0) > 4 * 1024 * 1024) {
        throw new RuntimeException('Ukuran foto/icon aplikasi maksimal 4 MB.');
    }
    $origName = strtolower((string)($f['name'] ?? ''));
    $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
    if (!in_array($ext, ['png', 'jpg', 'jpeg', 'webp', 'svg', 'ico', 'gif'], true)) {
        throw new RuntimeException('Format foto/icon aplikasi harus PNG, JPG, WEBP, SVG, atau ICO.');
    }
    $uploadDir = BASE_PATH . '/uploads/apps';
    if (!is_dir($uploadDir)) {
        @mkdir($uploadDir, 0755, true);
    }
    $safeSlug = preg_replace('/[^a-z0-9\-_]+/i', '-', strtolower((string)$slugPrefix));
    $safeSlug = trim($safeSlug, '-') ?: 'app';
    $fileName = 'app_' . $safeSlug . '_' . time() . '_' . substr(bin2hex(random_bytes(4)), 0, 6) . '.' . $ext;
    $destPath = $uploadDir . '/' . $fileName;
    if (!@move_uploaded_file($f['tmp_name'], $destPath)) {
        throw new RuntimeException('Gagal menyimpan file foto/icon aplikasi ke folder uploads/apps.');
    }
    return 'uploads/apps/' . $fileName;
}

function margo_admin_build_bootstrap_data(PDO $pdo)
{
    // 1. Clients
    $clientsRaw = $pdo->query("SELECT * FROM sso_clients ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
    $clientsOut = [];
    foreach ($clientsRaw as $c) {
        $priv = margo_sso_decrypt_secret($c['private_key_enc']);
        $clientsOut[] = [
            'id'                 => (int)$c['id'],
            'client_name'        => (string)$c['client_name'],
            'client_slug'        => (string)$c['client_slug'],
            'public_key'         => (string)$c['public_key'],
            'private_key'        => $priv,
            'private_key_masked' => strlen($priv) > 16 ? (substr($priv, 0, 12) . str_repeat('•', 16) . substr($priv, -6)) : '••••••••',
            'has_rsa_public'     => !empty($c['rsa_public_pem']),
            'rsa_fingerprint'    => !empty($c['rsa_public_pem']) ? substr(hash('sha256', trim($c['rsa_public_pem'])), 0, 24) : '',
            'sig_mode'           => (string)$c['sig_mode'],
            'app_url'            => (string)$c['app_url'],
            'app_icon'           => (string)$c['app_icon'],
            'app_logo'           => (string)($c['app_logo'] ?? ''),
            'app_logo_url'       => margo_resolve_app_logo_url($c['app_logo'] ?? '', $c['app_icon'] ?? '', (string)$c['client_slug']),
            'redirect_uris'      => (string)$c['redirect_uris'],
            'logout_webhook_url' => (string)($c['logout_webhook_url'] ?? ''),
            'allowed_domains'    => (string)$c['allowed_domains'],
            'allowed_ips'        => (string)($c['allowed_ips'] ?? ''),
            'allowed_scopes'     => (string)$c['allowed_scopes'],
            'allowed_roles'      => (string)$c['allowed_roles'],
            'rate_limit_per_min' => (int)$c['rate_limit_per_min'],
            'is_active'          => (int)$c['is_active'],
            'last_used_at'       => $c['last_used_at'],
            'last_ip'            => $c['last_ip'],
            'request_count'      => (int)$c['request_count'],
        ];
    }

    // 2. Sessions (active + recently revoked)
    $sessions = $pdo->query("
        SELECT s.id, s.user_id, s.active_role, s.ip_address, s.user_agent, s.login_method,
               s.expires_at, s.revoked_at, s.last_activity_at, s.created_at,
               u.username, u.full_name, u.email
        FROM sso_sessions s
        LEFT JOIN sso_users u ON u.id = s.user_id
        ORDER BY (s.revoked_at IS NULL AND s.expires_at > NOW()) DESC, s.last_activity_at DESC
        LIMIT 100
    ")->fetchAll(PDO::FETCH_ASSOC);
    $activeSessionCnt = 0;
    $nowTs = time();
    foreach ($sessions as &$sr) {
        $isAct = ($sr['revoked_at'] === null && strtotime((string)$sr['expires_at']) > $nowTs);
        $sr['is_active_now'] = $isAct;
        if ($isAct) {
            $activeSessionCnt++;
        }
    }
    unset($sr);

    // 3. Users + Roles + Orphan Guru Detection
    $allRoles = $pdo->query("SELECT id, code, name FROM sso_roles ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
    $usersRaw = $pdo->query("
        SELECT u.*
        FROM sso_users u
        ORDER BY u.id ASC
        LIMIT 200
    ")->fetchAll(PDO::FETCH_ASSOC);

    $urMap = [];
    $urRows = $pdo->query("
        SELECT ur.user_id, r.code, r.name, ur.is_primary
        FROM sso_user_roles ur
        JOIN sso_roles r ON ur.role_id = r.id
        ORDER BY ur.is_primary DESC, r.id ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($urRows as $ur) {
        $uid = (int)$ur['user_id'];
        if (!isset($urMap[$uid])) {
            $urMap[$uid] = [];
        }
        $urMap[$uid][] = [
            'code'       => (string)$ur['code'],
            'name'       => (string)$ur['name'],
            'is_primary' => (int)$ur['is_primary'],
        ];
    }

    $orphanGurus = [];
    try {
        if ($pdo->query("SHOW TABLES LIKE 'guru'")->fetch()) {
            $orphanGurus = $pdo->query("SELECT id, nip, nama_guru, email FROM guru WHERE id_user IS NULL ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (Throwable $eG) {
        $orphanGurus = [];
    }

    $usersOut = [];
    foreach ($usersRaw as $u) {
        $uid = (int)$u['id'];
        $uRoles = isset($urMap[$uid]) ? $urMap[$uid] : [];
        $usersOut[] = [
            'id'           => $uid,
            'username'     => (string)$u['username'],
            'full_name'    => (string)$u['full_name'],
            'email'        => (string)($u['email'] ?? ''),
            'email_google' => (string)($u['email_google'] ?? ''),
            'nisn_nip'     => (string)($u['nisn_nip'] ?? ''),
            'allowed_apps' => isset($u['allowed_apps']) ? (string)$u['allowed_apps'] : '',
            'is_active'    => margo_user_is_active($u) ? 1 : 0,
            'last_login'   => $u['last_login'] ?? null,
            'roles'        => $uRoles,
        ];
    }

    // 4. JWKS Keys & 100 Latest Audit Logs
    $jwksKeys = $pdo->query("
        SELECT id, kid, fingerprint, is_primary, is_active, expires_at, created_at
        FROM sso_jwks_keys
        ORDER BY is_primary DESC, is_active DESC, id DESC
        LIMIT 10
    ")->fetchAll(PDO::FETCH_ASSOC);

    $auditLogs = $pdo->query("
        SELECT l.id, l.user_id, l.client_id, l.event_type, l.http_status, l.ip_address, l.user_agent, l.detail_json, l.created_at,
               u.username, c.client_name
        FROM sso_audit_logs l
        LEFT JOIN sso_users u ON u.id = l.user_id
        LEFT JOIN sso_clients c ON c.id = l.client_id
        ORDER BY l.id DESC
        LIMIT 100
    ")->fetchAll(PDO::FETCH_ASSOC);

    // 5. Portal Content (Agenda dari Dedicated Agenda DB + Kaldik Sync, Pengumuman, Layanan Ekosistem Non-SSO)
    $portalAgendas = margo_agenda_fetch_visible($pdo, getCurrentUser(), [
        'include_inactive_admin' => true,
        'limit'                  => 1000,
    ]);
    $agendaDbStatus = margo_agenda_get_status($pdo);

    $portalAnnouncements = $pdo->query("
        SELECT id, title, excerpt, link_url, badge_label, published_at, is_active
        FROM sso_announcements
        ORDER BY published_at DESC, id DESC
        LIMIT 60
    ")->fetchAll(PDO::FETCH_ASSOC);

    $portalExternalApps = $pdo->query("
        SELECT *
        FROM sso_external_apps
        ORDER BY sort_order ASC, id ASC
        LIMIT 60
    ")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($portalExternalApps as &$pea) {
        $pea['app_logo']     = (string)($pea['app_logo'] ?? '');
        $pea['app_logo_url'] = margo_resolve_app_logo_url($pea['app_logo'], $pea['app_icon'] ?? '', (string)($pea['app_name'] ?? ''));
    }
    unset($pea);

    $googleCfg = getGoogleOAuthConfig(true);
    $googleSecRaw = (string)($googleCfg['client_secret'] ?? '');
    $googleOAuthInfo = [
        'client_id'            => (string)($googleCfg['client_id'] ?? ''),
        'has_client_secret'    => ($googleSecRaw !== ''),
        'client_secret_masked' => strlen($googleSecRaw) > 10 ? (substr($googleSecRaw, 0, 8) . str_repeat('•', 14) . substr($googleSecRaw, -4)) : ($googleSecRaw !== '' ? '••••••••' : ''),
        'redirect_uri'         => BASE_URL . '/login.php',
        'origin'               => preg_replace('#^((https?://[^/]+)).*$#i', '$1', BASE_URL),
    ];

    $learnApiInfo = [
        'endpoint_url'       => 'https://learn.ilmuna.my.id/api/sso-broadcast.php',
        'ws_url'             => 'wss://learn.ilmuna.my.id/ws/sso',
        'public_key'         => '',
        'has_private_key'    => false,
        'private_key_masked' => '',
        'sig_mode'           => 'HMAC-SHA256',
        'configured'         => false,
    ];
    if (file_exists(BASE_PATH . '/includes/learn-realtime-bridge.php')) {
        require_once BASE_PATH . '/includes/learn-realtime-bridge.php';
        if (function_exists('margo_learn_api_get_config')) {
            $lCfg = margo_learn_api_get_config($pdo, false);
            $lPriv = (string)($lCfg['private_key'] ?? '');
            $learnApiInfo = [
                'endpoint_url'       => (string)($lCfg['endpoint_url'] ?? ''),
                'ws_url'             => (string)($lCfg['ws_url'] ?? ''),
                'public_key'         => (string)($lCfg['public_key'] ?? ''),
                'has_private_key'    => ($lPriv !== ''),
                'private_key_masked' => $lPriv !== '' ? (substr($lPriv, 0, 10) . str_repeat('*', max(4, strlen($lPriv) - 14)) . substr($lPriv, -4)) : '',
                'sig_mode'           => (string)($lCfg['sig_mode'] ?? 'HMAC-SHA256'),
                'configured'         => !empty($lCfg['configured']),
            ];
        }
    }

    return [
        'endpoints' => [
            'issuer'     => SSO_ISSUER,
            'authorize'  => BASE_URL . '/authorize',
            'token'      => BASE_URL . '/api/sso/token.php',
            'userinfo'   => BASE_URL . '/api/sso/userinfo.php',
            'introspect' => BASE_URL . '/api/sso/introspect.php',
            'jwks'       => BASE_URL . '/api/sso/jwks.php',
            'logout'     => BASE_URL . '/logout',
        ],
        'google_oauth'         => $googleOAuthInfo,
        'learn_api'            => $learnApiInfo,
        'clients'              => $clientsOut,
        'sessions'             => $sessions,
        'active_session_count' => $activeSessionCnt,
        'users'                => $usersOut,
        'all_roles'            => $allRoles,
        'orphan_gurus'         => $orphanGurus,
        'jwks_keys'            => $jwksKeys,
        'audit_logs'           => $auditLogs,
        'portal_agendas'       => $portalAgendas,
        'agenda_db_status'     => $agendaDbStatus,
        'portal_announcements' => $portalAnnouncements,
        'portal_external_apps' => $portalExternalApps,
    ];
}

if (isset($_GET['api']) && $_GET['api'] === '1') {
    while (ob_get_level()) {
        ob_end_clean();
    }
    header('Content-Type: application/json; charset=utf-8');
    $act = trim((string)($_POST['api_action'] ?? $_GET['api_action'] ?? ''));

    try {
        // ---- BOOTSTRAP FULL DASHBOARD DATA ----
        if ($act === 'bootstrap') {
            echo json_encode([
                'success' => true,
                'data'    => margo_admin_build_bootstrap_data($pdo),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }

        // Validasi CSRF untuk semua mutasi POST
        $postedCsrf = (string)($_POST['csrf_token'] ?? '');
        if ($postedCsrf === '' || !hash_equals($csrfAdmin, $postedCsrf)) {
            echo json_encode(['success' => false, 'message' => 'Token CSRF tidak valid. Muat ulang halaman dan coba lagi.']);
            exit;
        }

        // ---- SIMPAN / TAMBAH KLIEN SSO ----
        if ($act === 'save_client') {
            $id             = (int)($_POST['id'] ?? 0);
            $clientName     = trim((string)($_POST['client_name'] ?? ''));
            $clientSlug     = strtolower(trim(preg_replace('/[^a-zA-Z0-9\-_]+/', '-', (string)($_POST['client_slug'] ?? ''))));
            $clientSlug     = trim($clientSlug, '-');
            $appUrl         = trim((string)($_POST['app_url'] ?? ''));
            $appIcon        = trim((string)($_POST['app_icon'] ?? 'solar:widget-5-bold'));
            $sigMode        = trim((string)($_POST['sig_mode'] ?? 'HMAC-SHA256'));
            $redirectUris   = trim((string)($_POST['redirect_uris'] ?? ''));
            $logoutWebhook  = trim((string)($_POST['logout_webhook_url'] ?? ''));
            $allowedDomains = trim((string)($_POST['allowed_domains'] ?? ''));
            $allowedIps     = trim((string)($_POST['allowed_ips'] ?? ''));
            $allowedScopes  = trim((string)($_POST['allowed_scopes'] ?? 'openid,profile,email,roles'));
            $allowedRoles   = trim((string)($_POST['allowed_roles'] ?? 'admin,guru,ketua_kelas,siswa'));
            $rateLimit      = max(10, min(2000, (int)($_POST['rate_limit_per_min'] ?? 180)));
            $isActive       = !empty($_POST['is_active']) ? 1 : 0;
            $removeLogo     = !empty($_POST['remove_app_logo']);

            if ($clientName === '' || $clientSlug === '' || $appUrl === '' || $redirectUris === '') {
                echo json_encode(['success' => false, 'message' => 'Nama klien, slug, URL aplikasi, dan minimal 1 Redirect URI wajib diisi.']);
                exit;
            }
            if (!in_array($sigMode, ['HMAC-SHA256', 'RSA-SHA256', 'DUAL'], true)) {
                $sigMode = 'HMAC-SHA256';
            }
            if ($appIcon === '') {
                $appIcon = 'solar:widget-5-bold';
            }

            $uploadedLogoPath = margo_admin_handle_app_logo_upload('app_logo_file', $clientSlug);

            if ($id > 0) {
                $stExisting = $pdo->prepare("SELECT rsa_public_pem, app_logo FROM sso_clients WHERE id = ? LIMIT 1");
                $stExisting->execute([$id]);
                $exRow = $stExisting->fetch(PDO::FETCH_ASSOC);
                if ($sigMode !== 'HMAC-SHA256' && trim((string)($exRow['rsa_public_pem'] ?? '')) === '') {
                    echo json_encode(['success' => false, 'message' => 'Generate RSA-2048 PEM terlebih dahulu sebelum mengaktifkan mode ' . $sigMode . '.']);
                    exit;
                }
                $finalAppLogo = $exRow['app_logo'] ?? null;
                if ($removeLogo) {
                    $finalAppLogo = null;
                }
                if ($uploadedLogoPath !== null) {
                    $finalAppLogo = $uploadedLogoPath;
                }

                $stmt = $pdo->prepare("
                    UPDATE sso_clients
                    SET client_name = ?, client_slug = ?, sig_mode = ?, app_url = ?, app_icon = ?, app_logo = ?,
                        redirect_uris = ?, logout_webhook_url = ?, allowed_domains = ?, allowed_ips = ?,
                        allowed_scopes = ?, allowed_roles = ?, rate_limit_per_min = ?, is_active = ?
                    WHERE id = ?
                ");
                $stmt->execute([
                    $clientName, $clientSlug, $sigMode, $appUrl, $appIcon, $finalAppLogo,
                    $redirectUris, $logoutWebhook !== '' ? $logoutWebhook : null,
                    $allowedDomains, $allowedIps, $allowedScopes, $allowedRoles, $rateLimit, $isActive, $id,
                ]);
                margo_sso_log_audit($pdo, 'admin_client_updated', [
                    'user_id' => (int)getCurrentUser()['id'], 'client_id' => $id, 'detail' => ['slug' => $clientSlug, 'allowed_roles' => $allowedRoles],
                ]);
                echo json_encode(['success' => true, 'message' => 'Konfigurasi klien "' . $clientName . '" berhasil disimpan.', 'data' => ['id' => $id]]);
                exit;
            } else {
                $pair = margo_sso_generate_keypair();
                $stmt = $pdo->prepare("
                    INSERT INTO sso_clients
                    (client_name, client_slug, public_key, private_key_enc, private_key_hash, sig_mode, app_url, app_icon, app_logo, redirect_uris, logout_webhook_url, allowed_domains, allowed_ips, allowed_scopes, allowed_roles, rate_limit_per_min, is_active)
                    VALUES (?, ?, ?, ?, ?, 'HMAC-SHA256', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $clientName, $clientSlug, $pair['public_key'],
                    margo_sso_encrypt_secret($pair['private_key']),
                    hash('sha256', $pair['private_key']),
                    $appUrl, $appIcon, $uploadedLogoPath, $redirectUris,
                    $logoutWebhook !== '' ? $logoutWebhook : null,
                    $allowedDomains, $allowedIps, $allowedScopes, $allowedRoles, $rateLimit, $isActive,
                ]);
                $newId = (int)$pdo->lastInsertId();
                margo_sso_log_audit($pdo, 'admin_client_created', [
                    'user_id' => (int)getCurrentUser()['id'], 'client_id' => $newId, 'detail' => ['slug' => $clientSlug],
                ]);
                echo json_encode([
                    'success' => true,
                    'message' => 'Aplikasi klien SSO baru "' . $clientName . '" berhasil ditambahkan beserta Public & Private Key!',
                    'data'    => ['id' => $newId, 'public_key' => $pair['public_key'], 'private_key' => $pair['private_key']],
                ]);
                exit;
            }
        }

        // ---- TOGGLE ACTIVE KLIEN ----
        if ($act === 'toggle_client') {
            $id = (int)($_POST['id'] ?? 0);
            $newState = !empty($_POST['is_active']) ? 1 : 0;
            $pdo->prepare("UPDATE sso_clients SET is_active = ? WHERE id = ?")->execute([$newState, $id]);
            margo_sso_log_audit($pdo, 'admin_client_toggled', [
                'user_id' => (int)getCurrentUser()['id'], 'client_id' => $id, 'detail' => ['is_active' => $newState],
            ]);
            echo json_encode(['success' => true, 'message' => $newState ? 'Klien SSO diaktifkan.' : 'Klien SSO dinonaktifkan.']);
            exit;
        }

        // ---- ROTASI PUBLIC & PRIVATE KEY KLIEN ----
        if ($act === 'rotate_client_keys') {
            $id = (int)($_POST['id'] ?? 0);
            $pair = margo_sso_generate_keypair();
            $pdo->prepare("UPDATE sso_clients SET public_key = ?, private_key_enc = ?, private_key_hash = ? WHERE id = ?")
                ->execute([$pair['public_key'], margo_sso_encrypt_secret($pair['private_key']), hash('sha256', $pair['private_key']), $id]);
            margo_sso_log_audit($pdo, 'admin_client_keys_rotated', [
                'user_id' => (int)getCurrentUser()['id'], 'client_id' => $id,
            ]);
            echo json_encode([
                'success' => true,
                'message' => 'Public Key & Private Key baru berhasil dibangkitkan! Perbarui konfigurasi di aplikasi klien terkait.',
                'data'    => $pair,
            ]);
            exit;
        }

        // ---- GENERATE RSA-2048 PEM KLIEN (TAMPIL SATU KALI) ----
        if ($act === 'generate_client_rsa') {
            $id = (int)($_POST['id'] ?? 0);
            $rsa = margo_sso_generate_rsa_pair();
            if (empty($rsa['success'])) {
                echo json_encode(['success' => false, 'message' => $rsa['message'] ?? 'Gagal membangkitkan RSA-2048.']);
                exit;
            }
            $pdo->prepare("UPDATE sso_clients SET rsa_public_pem = ? WHERE id = ?")->execute([$rsa['public_pem'], $id]);
            margo_sso_log_audit($pdo, 'admin_client_rsa_generated', [
                'user_id' => (int)getCurrentUser()['id'], 'client_id' => $id, 'detail' => ['fingerprint' => substr($rsa['fingerprint'], 0, 24)],
            ]);
            echo json_encode([
                'success' => true,
                'message' => 'Pasangan RSA-2048 berhasil dibuat! Salin RSA Private Key PEM sekarang (hanya ditampilkan satu kali).',
                'data'    => ['private_pem' => $rsa['private_pem'], 'fingerprint' => substr($rsa['fingerprint'], 0, 24)],
            ]);
            exit;
        }

        // ---- 1-KLIK UJI KONEKSI SIGNED TOKEN ----
        if ($act === 'test_client_signed') {
            $id = (int)($_POST['id'] ?? 0);
            $st = $pdo->prepare("SELECT * FROM sso_clients WHERE id = ? LIMIT 1");
            $st->execute([$id]);
            $client = $st->fetch(PDO::FETCH_ASSOC);
            if (!$client) {
                echo json_encode(['success' => false, 'message' => 'Klien tidak ditemukan.']);
                exit;
            }

            $privKey = margo_sso_decrypt_secret($client['private_key_enc']);
            $ts      = (string)time();
            $nonce   = bin2hex(random_bytes(16));
            $body    = json_encode(['grant_type' => 'health_check', 'client' => $client['client_slug']]);
            $canon   = margo_sso_canonical_string('POST', '/api/sso/token.php', [], $ts, $nonce, $body);
            $sig     = margo_sso_sign_hmac($canon, $privKey);
            $domains = margo_sso_parse_list($client['allowed_domains']);
            $simSite = !empty($domains) ? $domains[0] : margo_sso_extract_host($client['app_url']);

            if ($client['sig_mode'] === 'HMAC-SHA256') {
                $authRes = margo_sso_authenticate_client($pdo, [
                    'method'    => 'POST',
                    'uri'       => '/api/sso/token.php',
                    'query'     => [],
                    'body'      => $body,
                    'client_ip' => margo_sso_client_ip(),
                    'headers'   => [
                        'x-ilmuna-public-key' => $client['public_key'],
                        'x-ilmuna-timestamp'  => $ts,
                        'x-ilmuna-nonce'      => $nonce,
                        'x-ilmuna-signature'  => $sig,
                        'x-ilmuna-site'       => $simSite,
                    ],
                ]);
                if (!$authRes['ok']) {
                    echo json_encode(['success' => false, 'message' => 'Uji Signed Gagal [' . $authRes['code'] . ']: ' . $authRes['message']]);
                    exit;
                }
            }

            // Uji pula sign & verify JWT RS256 lewat JWKS aktif
            $jwkKey = margo_sso_get_primary_jwk_key($pdo);
            $jwks   = margo_jwt_build_jwks(margo_sso_get_active_jwk_keys($pdo));
            $testJwt = margo_jwt_sign_rs256([
                'iss' => SSO_ISSUER,
                'sub' => (string)getCurrentUser()['id'],
                'aud' => $client['public_key'],
                'iat' => time(),
                'exp' => time() + 60,
            ], $jwkKey['private_pem'], $jwkKey['kid']);
            $jwtVer = margo_jwt_verify_rs256($testJwt, $jwks, SSO_ISSUER, $client['public_key']);

            if (!$jwtVer['valid']) {
                echo json_encode(['success' => false, 'message' => 'Uji JWT RS256 gagal: ' . $jwtVer['code']]);
                exit;
            }

            echo json_encode([
                'success' => true,
                'message' => 'Verifikasi Kriptografis & JWT RS256 SUKSES untuk "' . $client['client_name'] . '" (Site: ' . $simSite . ', Mode: ' . $client['sig_mode'] . ', JWKS kid: ' . $jwkKey['kid'] . ').',
            ]);
            exit;
        }

        // ---- FORCE LOGOUT SATU SESI ----
        if ($act === 'revoke_session') {
            $sid = (int)($_POST['session_id'] ?? 0);
            margo_revoke_sso_session($pdo, $sid, null, true);
            echo json_encode(['success' => true, 'message' => 'Sesi #' . $sid . ' berhasil dipaksa keluar (SLO).']);
            exit;
        }

        // ---- CABUT SEMUA SESI USER ----
        if ($act === 'revoke_user_sessions') {
            $uid = (int)($_POST['user_id'] ?? 0);
            $cnt = margo_revoke_all_user_sessions($pdo, $uid, 0, true);
            echo json_encode(['success' => true, 'message' => $cnt . ' sesi aktif milik pengguna #' . $uid . ' berhasil dicabut secara global.']);
            exit;
        }

        // ---- UPDATE MULTI-ROLE & PERMISSION APLIKASI USER ----
        if ($act === 'update_user_roles') {
            $uid            = (int)($_POST['user_id'] ?? 0);
            $roleIdsRaw     = isset($_POST['role_ids']) ? $_POST['role_ids'] : [];
            $primaryCode    = trim((string)($_POST['primary_role'] ?? ''));
            $allowedAppsRaw = isset($_POST['allowed_apps']) ? trim((string)$_POST['allowed_apps']) : '';

            if (!is_array($roleIdsRaw)) {
                $roleIdsRaw = explode(',', (string)$roleIdsRaw);
            }
            $roleCodes = array_values(array_unique(array_filter(array_map('trim', $roleIdsRaw))));
            if ($uid <= 0 || empty($roleCodes)) {
                echo json_encode(['success' => false, 'message' => 'Pilih minimal 1 peran aktif untuk pengguna.']);
                exit;
            }

            $allRolesRows = $pdo->query("SELECT id, code FROM sso_roles")->fetchAll(PDO::FETCH_ASSOC);
            $codeToId = [];
            foreach ($allRolesRows as $rr) {
                $codeToId[(string)$rr['code']] = (int)$rr['id'];
            }

            $validCodes = [];
            foreach ($roleCodes as $rc) {
                if (isset($codeToId[$rc])) {
                    $validCodes[] = $rc;
                }
            }
            if (empty($validCodes)) {
                echo json_encode(['success' => false, 'message' => 'Kode peran tidak valid.']);
                exit;
            }
            if (!in_array($primaryCode, $validCodes, true)) {
                $primaryCode = $validCodes[0];
            }

            $pdo->beginTransaction();
            try {
                $pdo->prepare("DELETE FROM sso_user_roles WHERE user_id = ?")->execute([$uid]);
                $insUr = $pdo->prepare("INSERT INTO sso_user_roles (user_id, role_id, is_primary) VALUES (?, ?, ?)");
                foreach ($validCodes as $vc) {
                    $insUr->execute([$uid, $codeToId[$vc], ($vc === $primaryCode ? 1 : 0)]);
                }
                if (isset($_POST['allowed_apps'])) {
                    $pdo->prepare("UPDATE sso_users SET allowed_apps = ? WHERE id = ?")
                        ->execute([$allowedAppsRaw !== '' ? $allowedAppsRaw : null, $uid]);
                }
                $pdo->commit();
            } catch (Throwable $eTx) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $eTx;
            }

            if ($uid === (int)getCurrentUser()['id']) {
                $_SESSION['user_allowed_apps'] = ($allowedAppsRaw !== '' ? $allowedAppsRaw : null);
            }

            margo_sso_log_audit($pdo, 'admin_roles_updated', [
                'user_id' => $uid,
                'detail'  => ['roles' => $validCodes, 'primary' => $primaryCode, 'allowed_apps' => $allowedAppsRaw, 'by' => (int)getCurrentUser()['id']],
            ]);

            echo json_encode(['success' => true, 'message' => 'Peran & izin akses aplikasi (permission) pengguna berhasil diperbarui.']);
            exit;
        }

        // ---- SIMPAN MATRIKS PERMISSION ROLE KE APLIKASI SSO ----
        if ($act === 'save_role_app_permissions') {
            $matrixJson = (string)($_POST['client_roles_json'] ?? '');
            $map = json_decode($matrixJson, true);
            if (!is_array($map)) {
                echo json_encode(['success' => false, 'message' => 'Data matriks izin role tidak valid.']);
                exit;
            }
            $updSt = $pdo->prepare("UPDATE sso_clients SET allowed_roles = ? WHERE id = ?");
            foreach ($map as $cid => $rolesCsv) {
                $cidInt = (int)$cid;
                if ($cidInt > 0) {
                    $cleanCsv = implode(',', margo_sso_parse_list((string)$rolesCsv));
                    $updSt->execute([$cleanCsv, $cidInt]);
                }
            }
            margo_sso_log_audit($pdo, 'admin_role_app_matrix_updated', [
                'user_id' => (int)getCurrentUser()['id'],
                'detail'  => $map,
            ]);
            echo json_encode(['success' => true, 'message' => 'Permission login Role ke seluruh aplikasi SSO berhasil disimpan!']);
            exit;
        }

        // ---- RESET PASSWORD USER AMAN ----
        if ($act === 'reset_user_password') {
            $uid        = (int)($_POST['user_id'] ?? 0);
            $newPass    = (string)($_POST['new_password'] ?? '');
            $forceOut   = !empty($_POST['revoke_sessions']);

            if ($uid <= 0 || strlen($newPass) < 8) {
                echo json_encode(['success' => false, 'message' => 'Kata sandi reset minimal 8 karakter.']);
                exit;
            }
            $pdo->prepare("UPDATE sso_users SET password_hash = ? WHERE id = ?")->execute([password_hash($newPass, PASSWORD_DEFAULT), $uid]);
            $revoked = $forceOut ? margo_revoke_all_user_sessions($pdo, $uid, 0, true) : 0;

            margo_sso_log_audit($pdo, 'admin_password_reset', [
                'user_id' => $uid,
                'detail'  => ['revoked_sessions' => $revoked, 'by' => (int)getCurrentUser()['id']],
            ]);

            echo json_encode(['success' => true, 'message' => 'Kata sandi berhasil direset' . ($revoked > 0 ? " & {$revoked} sesi aktif dicabut." : '.')]);
            exit;
        }

        // ---- PUTUS TAUTAN GOOGLE USER OLEH ADMIN ----
        if ($act === 'admin_unlink_google') {
            $uid = (int)($_POST['user_id'] ?? 0);
            $pdo->prepare("UPDATE sso_users SET email_google = NULL, google_id = NULL WHERE id = ?")->execute([$uid]);
            try {
                $pdo->prepare("DELETE FROM sso_google_link_verifications WHERE user_id = ?")->execute([$uid]);
            } catch (Throwable $eG) {}
            margo_sso_log_audit($pdo, 'admin_google_unlinked', ['user_id' => $uid]);
            echo json_encode(['success' => true, 'message' => 'Tautan Google SSO pengguna berhasil diputuskan.']);
            exit;
        }

        // ---- HAPUS 1 AKUN PENGGUNA SSO (TERISOLASI HANYA DI TABEL sso_users) ----
        if ($act === 'delete_user') {
            $uid     = (int)($_POST['user_id'] ?? 0);
            $adminId = (int)getCurrentUser()['id'];

            if ($uid <= 0) {
                echo json_encode(['success' => false, 'message' => 'ID pengguna tidak valid.']);
                exit;
            }
            if ($uid === $adminId) {
                echo json_encode(['success' => false, 'message' => 'Anda tidak dapat menghapus akun Administrator yang sedang digunakan untuk login.']);
                exit;
            }

            $stTarget = $pdo->prepare("SELECT id, username, full_name FROM sso_users WHERE id = ? LIMIT 1");
            $stTarget->execute([$uid]);
            $targetUser = $stTarget->fetch(PDO::FETCH_ASSOC);
            if (!$targetUser) {
                echo json_encode(['success' => false, 'message' => 'Akun pengguna tidak ditemukan di Margo One SSO.']);
                exit;
            }

            // Cegah penghapusan admin aktif terakhir
            $stIsAdm = $pdo->prepare("
                SELECT COUNT(*) FROM sso_user_roles ur
                JOIN sso_roles r ON r.id = ur.role_id
                WHERE ur.user_id = ? AND r.code = 'admin'
            ");
            $stIsAdm->execute([$uid]);
            if ((int)$stIsAdm->fetchColumn() > 0) {
                $totalAdmins = (int)$pdo->query("
                    SELECT COUNT(DISTINCT ur.user_id)
                    FROM sso_user_roles ur
                    JOIN sso_roles r ON r.id = ur.role_id
                    JOIN sso_users u ON u.id = ur.user_id
                    WHERE r.code = 'admin' AND (u.is_active = 1 OR u.is_active IS NULL)
                ")->fetchColumn();
                if ($totalAdmins <= 1) {
                    echo json_encode(['success' => false, 'message' => 'Tidak dapat menghapus satu-satunya akun Administrator aktif di Margo One SSO.']);
                    exit;
                }
            }

            // Cabut seluruh sesi global terlebih dahulu (SLO webhook ke semua SP)
            margo_revoke_all_user_sessions($pdo, $uid, 0, true);

            $pdo->beginTransaction();
            try {
                foreach (['sso_user_roles', 'sso_google_link_verifications', 'sso_refresh_tokens', 'sso_auth_codes', 'sso_sessions'] as $relTbl) {
                    try {
                        $pdo->prepare("DELETE FROM `{$relTbl}` WHERE user_id = ?")->execute([$uid]);
                    } catch (Throwable $eRel) {}
                }
                $pdo->prepare("DELETE FROM sso_users WHERE id = ?")->execute([$uid]);
                $pdo->commit();
            } catch (Throwable $eDel) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $eDel;
            }

            margo_sso_log_audit($pdo, 'admin_user_deleted', [
                'user_id' => $adminId,
                'detail'  => ['deleted_user_id' => $uid, 'username' => (string)$targetUser['username']],
            ]);

            echo json_encode([
                'success' => true,
                'message' => 'Akun @' . $targetUser['username'] . ' berhasil dihapus permanen dari Margo One SSO.',
            ]);
            exit;
        }

        // ---- BULK ACTION PENGGUNA SSO (DELETE / REVOKE SESSIONS / UNLINK GOOGLE) ----
        if ($act === 'bulk_users_action') {
            $bulkAction = trim((string)($_POST['bulk_action'] ?? ''));
            $rawIds     = isset($_POST['user_ids']) ? $_POST['user_ids'] : [];
            if (!is_array($rawIds)) {
                $rawIds = explode(',', (string)$rawIds);
            }
            $userIds = [];
            foreach ($rawIds as $rid) {
                $i = (int)$rid;
                if ($i > 0 && !in_array($i, $userIds, true)) {
                    $userIds[] = $i;
                }
            }

            if (empty($userIds)) {
                echo json_encode(['success' => false, 'message' => 'Pilih minimal 1 akun pengguna terlebih dahulu.']);
                exit;
            }
            if (!in_array($bulkAction, ['delete', 'revoke_sessions', 'unlink_google'], true)) {
                echo json_encode(['success' => false, 'message' => 'Jenis aksi massal tidak dikenali.']);
                exit;
            }

            $adminId    = (int)getCurrentUser()['id'];
            $currentSid = (int)(getCurrentUser()['sso_session_id'] ?? 0);
            $affected   = 0;
            $skipped    = 0;

            if ($bulkAction === 'revoke_sessions') {
                $totalRevoked = 0;
                foreach ($userIds as $uid) {
                    $excludeSid = ($uid === $adminId) ? $currentSid : 0;
                    $totalRevoked += margo_revoke_all_user_sessions($pdo, $uid, $excludeSid, true);
                    $affected++;
                }
                margo_sso_log_audit($pdo, 'admin_users_bulk_slo', [
                    'user_id' => $adminId,
                    'detail'  => ['user_ids' => $userIds, 'revoked_sessions' => $totalRevoked],
                ]);
                echo json_encode([
                    'success'  => true,
                    'affected' => $affected,
                    'message'  => "Global SLO dijalankan pada {$affected} akun ({$totalRevoked} sesi perangkat aktif dicabut).",
                ]);
                exit;
            }

            if ($bulkAction === 'unlink_google') {
                $stUpd = $pdo->prepare("UPDATE sso_users SET email_google = NULL, google_id = NULL WHERE id = ?");
                foreach ($userIds as $uid) {
                    $stUpd->execute([$uid]);
                    try {
                        $pdo->prepare("DELETE FROM sso_google_link_verifications WHERE user_id = ?")->execute([$uid]);
                    } catch (Throwable $eG) {}
                    $affected++;
                }
                margo_sso_log_audit($pdo, 'admin_users_bulk_unlink_google', [
                    'user_id' => $adminId,
                    'detail'  => ['user_ids' => $userIds],
                ]);
                echo json_encode([
                    'success'  => true,
                    'affected' => $affected,
                    'message'  => "Tautan Google SSO pada {$affected} akun berhasil diputuskan.",
                ]);
                exit;
            }

            // Bulk DELETE dengan proteksi akun sendiri & admin aktif terakhir
            $remainingAdmins = (int)$pdo->query("
                SELECT COUNT(DISTINCT ur.user_id)
                FROM sso_user_roles ur
                JOIN sso_roles r ON r.id = ur.role_id
                JOIN sso_users u ON u.id = ur.user_id
                WHERE r.code = 'admin' AND (u.is_active = 1 OR u.is_active IS NULL)
            ")->fetchColumn();
            $stIsAdm = $pdo->prepare("
                SELECT COUNT(*) FROM sso_user_roles ur
                JOIN sso_roles r ON r.id = ur.role_id
                WHERE ur.user_id = ? AND r.code = 'admin'
            ");

            $deletedIds = [];
            foreach ($userIds as $uid) {
                if ($uid === $adminId) {
                    $skipped++;
                    continue;
                }
                $stIsAdm->execute([$uid]);
                $isTargetAdmin = ((int)$stIsAdm->fetchColumn() > 0);
                if ($isTargetAdmin && $remainingAdmins <= 1) {
                    $skipped++;
                    continue;
                }

                margo_revoke_all_user_sessions($pdo, $uid, 0, true);
                $pdo->beginTransaction();
                try {
                    foreach (['sso_user_roles', 'sso_google_link_verifications', 'sso_refresh_tokens', 'sso_auth_codes', 'sso_sessions'] as $relTbl) {
                        try {
                            $pdo->prepare("DELETE FROM `{$relTbl}` WHERE user_id = ?")->execute([$uid]);
                        } catch (Throwable $eRel) {}
                    }
                    $stDelU = $pdo->prepare("DELETE FROM sso_users WHERE id = ?");
                    $stDelU->execute([$uid]);
                    $pdo->commit();
                    if ($stDelU->rowCount() > 0) {
                        $affected++;
                        $deletedIds[] = $uid;
                        if ($isTargetAdmin) {
                            $remainingAdmins--;
                        }
                    }
                } catch (Throwable $eDelB) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    $skipped++;
                }
            }

            if ($affected === 0) {
                echo json_encode([
                    'success' => false,
                    'message' => 'Tidak ada akun yang dihapus (akun yang sedang login atau admin terakhir dilindungi sistem).',
                ]);
                exit;
            }

            margo_sso_log_audit($pdo, 'admin_users_bulk_deleted', [
                'user_id' => $adminId,
                'detail'  => ['deleted_ids' => $deletedIds, 'skipped' => $skipped],
            ]);

            echo json_encode([
                'success'     => true,
                'affected'    => $affected,
                'skipped'     => $skipped,
                'deleted_ids' => $deletedIds,
                'message'     => "{$affected} akun berhasil dihapus dari Margo One SSO" . ($skipped > 0 ? " ({$skipped} akun dilindungi/dilewati)." : '.'),
            ]);
            exit;
        }

        // ---- PREVIEW KANDIDAT SINKRON USER DARI ILMUNA LEARN (READ-ONLY: HANYA GMAIL & NO REF) ----
        if ($act === 'preview_learn_sync') {
            require_once BASE_PATH . '/includes/sso-learn-sync.php';
            $candidates = margo_learn_sync_fetch_candidates($pdo);
            $counts = ['total' => count($candidates), 'ready_new' => 0, 'already_in_sso' => 0, 'no_email' => 0];
            foreach ($candidates as $c) {
                if (empty($c['has_email'])) {
                    $counts['no_email']++;
                } elseif (!empty($c['already_in_sso'])) {
                    $counts['already_in_sso']++;
                } else {
                    $counts['ready_new']++;
                }
            }
            echo json_encode([
                'success'    => true,
                'counts'     => $counts,
                'candidates' => $candidates,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }

        // ---- EKSEKUSI SINKRON 1 USER DARI LEARN -> BUAT USERNAME & PASSWORD ACAK SSO + KIRIM GMAIL ----
        if ($act === 'sync_learn_user_one') {
            require_once BASE_PATH . '/includes/sso-learn-sync.php';
            $learnId        = (int)($_POST['learn_id'] ?? 0);
            $resendIfExists = !empty($_POST['resend_if_exists']) && (string)$_POST['resend_if_exists'] === '1';
            if ($learnId <= 0) {
                echo json_encode(['success' => false, 'message' => 'ID pengguna Learn tidak valid.']);
                exit;
            }
            $allCand = margo_learn_sync_fetch_candidates($pdo);
            $target  = null;
            foreach ($allCand as $c) {
                if ((int)$c['learn_id'] === $learnId) {
                    $target = $c;
                    break;
                }
            }
            if (!$target) {
                echo json_encode(['success' => false, 'message' => 'Pengguna Learn #' . $learnId . ' tidak ditemukan atau tidak aktif.']);
                exit;
            }
            $resSync = margo_learn_sync_execute_one($pdo, $target, $resendIfExists, (int)getCurrentUser()['id']);
            echo json_encode($resSync, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }

        // ---- AUTO-HEAL ORPHAN GURU.ID_USER ----
        if ($act === 'heal_orphan_guru') {
            $healed = 0;
            $hasGuruTbl = (bool)$pdo->query("SHOW TABLES LIKE 'guru'")->fetch();
            if ($hasGuruTbl) {
                $uRows = $pdo->query("SELECT * FROM sso_users")->fetchAll(PDO::FETCH_ASSOC);
                foreach ($uRows as $uRow) {
                    $rCodes = array_column(margo_fetch_user_roles($pdo, (int)$uRow['id']), 'code');
                    if (in_array('guru', $rCodes, true) || in_array('admin', $rCodes, true)) {
                        $before = $pdo->query("SELECT COUNT(*) FROM guru WHERE id_user IS NULL")->fetchColumn();
                        margo_resolve_logged_guru($pdo, $uRow);
                        $after  = $pdo->query("SELECT COUNT(*) FROM guru WHERE id_user IS NULL")->fetchColumn();
                        if ((int)$after < (int)$before) {
                            $healed += ((int)$before - (int)$after);
                        }
                    }
                }
            }
            margo_sso_log_audit($pdo, 'admin_heal_orphan_guru', ['user_id' => (int)getCurrentUser()['id'], 'detail' => ['healed' => $healed]]);
            echo json_encode([
                'success' => true,
                'message' => $healed > 0
                    ? ($healed . ' profil guru orphan berhasil ditautkan kembali ke users.id!')
                    : 'Pemindaian selesai. Tidak ada kecocokan baru untuk guru orphan yang tersisa.',
            ]);
            exit;
        }

        // ---- SIMPAN KONFIGURASI GOOGLE OAUTH 2.0 (TERENKRIPSI DI DB + CONFIG.LOCAL.PHP) ----
        if ($act === 'save_google_oauth_settings') {
            $gCid = trim((string)($_POST['google_client_id'] ?? ''));
            $gSec = trim((string)($_POST['google_client_secret'] ?? ''));

            if ($gCid === '') {
                echo json_encode(['success' => false, 'message' => 'Google Client ID wajib diisi.']);
                exit;
            }
            margo_sso_set_setting($pdo, 'google_client_id', $gCid, false);
            if ($gSec !== '') {
                margo_sso_set_setting($pdo, 'google_client_secret', $gSec, true);
            } else {
                $existingCfg = getGoogleOAuthConfig(true);
                $gSec = (string)($existingCfg['client_secret'] ?? '');
            }

            // Opsional: sinkronkan juga ke config.local.php (gitignored) bila dapat ditulis tanpa menimpa konstanta lain
            $localWritten = false;
            $localPath = BASE_PATH . '/config.local.php';
            if ((file_exists($localPath) && is_writable($localPath)) || (!file_exists($localPath) && is_writable(BASE_PATH))) {
                $existingLocal = file_exists($localPath) ? (string)@file_get_contents($localPath) : '';
                $hasOtherDefines = $existingLocal !== ''
                    && preg_match('/define\s*\(\s*[\'"](?!GOOGLE_CLIENT_(?:ID|SECRET)[\'"])[A-Z0-9_]+[\'"]/', $existingLocal);
                if (!$hasOtherDefines) {
                    $phpLocal = "<?php\n"
                        . "// Auto-generated by Margo One Admin Panel (GITIGNORED)\n"
                        . "if (!defined('GOOGLE_CLIENT_ID')) {\n"
                        . "    define('GOOGLE_CLIENT_ID', " . var_export($gCid, true) . ");\n"
                        . "}\n"
                        . "if (!defined('GOOGLE_CLIENT_SECRET')) {\n"
                        . "    define('GOOGLE_CLIENT_SECRET', " . var_export($gSec, true) . ");\n"
                        . "}\n";
                    $localWritten = (@file_put_contents($localPath, $phpLocal) !== false);
                }
            }

            margo_sso_log_audit($pdo, 'admin_google_oauth_updated', [
                'user_id' => (int)getCurrentUser()['id'],
                'detail'  => ['client_id_prefix' => substr($gCid, 0, 24), 'local_written' => $localWritten],
            ]);

            echo json_encode([
                'success' => true,
                'message' => 'Konfigurasi Google OAuth 2.0 berhasil disimpan terenkripsi ke database' . ($localWritten ? ' & config.local.php.' : '.'),
            ]);
            exit;
        }

        // ---- SIMPAN & UJI KONFIGURASI ILMUNA LEARN REAL-TIME API ----
        if ($act === 'save_learn_api_settings') {
            $lEp   = trim((string)($_POST['learn_api_endpoint'] ?? 'https://learn.ilmuna.my.id/api/sso-broadcast.php'));
            $lWs   = trim((string)($_POST['learn_ws_public_url'] ?? 'wss://learn.ilmuna.my.id/ws/sso'));
            $lPub  = trim((string)($_POST['learn_api_public_key'] ?? ''));
            $lPriv = trim((string)($_POST['learn_api_private_key'] ?? ''));
            $lMode = strtoupper(trim((string)($_POST['learn_api_sig_mode'] ?? 'HMAC-SHA256')));
            if (!in_array($lMode, ['HMAC-SHA256', 'RSA-SHA256', 'DUAL'], true)) {
                $lMode = 'HMAC-SHA256';
            }

            if ($lEp === '' || $lPub === '') {
                echo json_encode(['success' => false, 'message' => 'Endpoint URL dan Public Key (pk_sso_*) wajib diisi.']);
                exit;
            }
            margo_sso_set_setting($pdo, 'learn_api_endpoint', $lEp, false);
            margo_sso_set_setting($pdo, 'learn_ws_public_url', $lWs, false);
            margo_sso_set_setting($pdo, 'learn_api_public_key', $lPub, false);
            margo_sso_set_setting($pdo, 'learn_api_sig_mode', $lMode, false);
            if ($lPriv !== '') {
                margo_sso_set_setting($pdo, 'learn_api_private_key', $lPriv, true);
            }

            echo json_encode([
                'success' => true,
                'message' => 'Konfigurasi API Ilmuna Learn berhasil disimpan terenkripsi ke database SSO.',
            ]);
            exit;
        }

        if ($act === 'test_learn_api_connection') {
            require_once BASE_PATH . '/includes/learn-realtime-bridge.php';
            $client = margo_learn_api_client($pdo);
            $health = $client->request('health');
            echo json_encode([
                'success' => true,
                'message' => 'Koneksi Zero-Trust ke Ilmuna Learn AKTIF! Klien: ' . ($health['client_name'] ?? 'OK') . ' (' . ($health['sig_mode'] ?? 'HMAC-SHA256') . ').',
                'data'    => $health,
            ]);
            exit;
        }

        // ---- ROTASI KUNCI RSA-2048 JWKS ----
        if ($act === 'rotate_jwks') {
            $graceHours = max(1, min(168, (int)($_POST['grace_hours'] ?? 24)));
            $newKey = margo_sso_rotate_jwk_key($pdo, $graceHours);
            margo_sso_log_audit($pdo, 'admin_jwks_rotated', [
                'user_id' => (int)getCurrentUser()['id'],
                'detail'  => ['new_kid' => $newKey['kid'], 'grace_hours' => $graceHours],
            ]);
            echo json_encode([
                'success' => true,
                'message' => 'Kunci JWT RS256 baru (' . $newKey['kid'] . ') aktif! Kunci lama tetap valid selama ' . $graceHours . ' jam grace period.',
                'data'    => ['kid' => $newKey['kid']],
            ]);
            exit;
        }

        // ---- PORTAL CMS: SIMPAN AGENDA EKOSISTEM (DEDICATED AGENDA DB + AUDIENS + LOCK KALDIK) ----
        if ($act === 'save_agenda') {
            $resAg = margo_agenda_save($pdo, getCurrentUser(), [
                'id'               => (int)($_POST['id'] ?? 0),
                'title'            => (string)($_POST['title'] ?? ''),
                'description'      => (string)($_POST['description'] ?? ''),
                'start_date'       => (string)($_POST['start_date'] ?? ''),
                'end_date'         => (string)($_POST['end_date'] ?? ''),
                'start_time'       => (string)($_POST['start_time'] ?? ''),
                'location'         => (string)($_POST['location'] ?? ''),
                'category'         => (string)($_POST['category'] ?? 'akademik'),
                'color_hex'        => (string)($_POST['color_hex'] ?? ''),
                'link_url'         => (string)($_POST['link_url'] ?? ''),
                'visibility_scope' => (string)($_POST['visibility_scope'] ?? 'all'),
                'target_kelas_id'  => (int)($_POST['target_kelas_id'] ?? 0),
                'is_active'        => !empty($_POST['is_active']) ? 1 : 0,
            ]);
            echo json_encode($resAg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }

        if ($act === 'delete_agenda') {
            $id = (int)($_POST['id'] ?? 0);
            $resDel = margo_agenda_delete($pdo, getCurrentUser(), $id);
            echo json_encode($resDel, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }

        if ($act === 'sync_kaldik_agendas') {
            $trgRes  = margo_agenda_install_kaldik_triggers($pdo);
            $syncRes = margo_agenda_backfill_from_kaldik($pdo);
            $status  = margo_agenda_get_status($pdo);
            echo json_encode([
                'success' => !empty($syncRes['ok']),
                'message' => $syncRes['message'] . (!empty($trgRes['kaldik_triggers_ok']) ? ' (MySQL Trigger Real-Time Aktif)' : ''),
                'data'    => ['status' => $status, 'triggers' => $trgRes],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }

        // ---- PORTAL CMS: SIMPAN PENGUMUMAN / BERITA ----
        if ($act === 'save_announcement') {
            $id       = (int)($_POST['id'] ?? 0);
            $title    = trim((string)($_POST['title'] ?? ''));
            $excerpt  = trim((string)($_POST['excerpt'] ?? ''));
            $linkUrl  = trim((string)($_POST['link_url'] ?? ''));
            $badge    = strtoupper(trim((string)($_POST['badge_label'] ?? 'INFO')));
            $isActive = !empty($_POST['is_active']) ? 1 : 0;

            if ($title === '') {
                echo json_encode(['success' => false, 'message' => 'Judul pengumuman wajib diisi.']);
                exit;
            }
            if ($badge === '') {
                $badge = 'INFO';
            }

            if ($id > 0) {
                $pdo->prepare("UPDATE sso_announcements SET title = ?, excerpt = ?, link_url = ?, badge_label = ?, is_active = ? WHERE id = ?")
                    ->execute([$title, $excerpt !== '' ? $excerpt : null, $linkUrl !== '' ? $linkUrl : null, $badge, $isActive, $id]);
                echo json_encode(['success' => true, 'message' => 'Pengumuman berhasil diperbarui.', 'data' => ['id' => $id]]);
                exit;
            } else {
                $pdo->prepare("INSERT INTO sso_announcements (title, excerpt, link_url, badge_label, is_active, created_by) VALUES (?, ?, ?, ?, ?, ?)")
                    ->execute([$title, $excerpt !== '' ? $excerpt : null, $linkUrl !== '' ? $linkUrl : null, $badge, $isActive, (int)getCurrentUser()['id']]);
                echo json_encode(['success' => true, 'message' => 'Pengumuman baru berhasil diterbitkan.', 'data' => ['id' => (int)$pdo->lastInsertId()]]);
                exit;
            }
        }

        if ($act === 'delete_announcement') {
            $id = (int)($_POST['id'] ?? 0);
            $pdo->prepare("DELETE FROM sso_announcements WHERE id = ?")->execute([$id]);
            echo json_encode(['success' => true, 'message' => 'Pengumuman berhasil dihapus.']);
            exit;
        }

        // ---- PORTAL CMS: SIMPAN LAYANAN EKOSISTEM NON-SSO ----
        if ($act === 'save_external_app') {
            $id         = (int)($_POST['id'] ?? 0);
            $appName    = trim((string)($_POST['app_name'] ?? ''));
            $desc       = trim((string)($_POST['description'] ?? ''));
            $appUrl     = trim((string)($_POST['app_url'] ?? ''));
            $appIcon    = trim((string)($_POST['app_icon'] ?? 'solar:widget-4-bold'));
            $badge      = strtoupper(trim((string)($_POST['badge_label'] ?? 'EKOSISTEM')));
            $sortOrder  = (int)($_POST['sort_order'] ?? 10);
            $isActive   = !empty($_POST['is_active']) ? 1 : 0;
            $removeLogo = !empty($_POST['remove_app_logo']);

            if ($appName === '' || $appUrl === '') {
                echo json_encode(['success' => false, 'message' => 'Nama layanan dan URL tujuan wajib diisi.']);
                exit;
            }
            if ($appIcon === '') {
                $appIcon = 'solar:widget-4-bold';
            }

            $uploadedLogoPath = margo_admin_handle_app_logo_upload('app_logo_file', $appName);

            if ($id > 0) {
                $stEx = $pdo->prepare("SELECT app_logo FROM sso_external_apps WHERE id = ? LIMIT 1");
                $stEx->execute([$id]);
                $finalLogo = $stEx->fetchColumn() ?: null;
                if ($removeLogo) {
                    $finalLogo = null;
                }
                if ($uploadedLogoPath !== null) {
                    $finalLogo = $uploadedLogoPath;
                }
                $pdo->prepare("UPDATE sso_external_apps SET app_name = ?, description = ?, app_url = ?, app_icon = ?, app_logo = ?, badge_label = ?, sort_order = ?, is_active = ? WHERE id = ?")
                    ->execute([$appName, $desc !== '' ? $desc : null, $appUrl, $appIcon, $finalLogo, $badge ?: 'EKOSISTEM', $sortOrder, $isActive, $id]);
                echo json_encode(['success' => true, 'message' => 'Layanan ekosistem berhasil diperbarui.', 'data' => ['id' => $id]]);
                exit;
            } else {
                $pdo->prepare("INSERT INTO sso_external_apps (app_name, description, app_url, app_icon, app_logo, badge_label, sort_order, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?)")
                    ->execute([$appName, $desc !== '' ? $desc : null, $appUrl, $appIcon, $uploadedLogoPath, $badge ?: 'EKOSISTEM', $sortOrder, $isActive]);
                echo json_encode(['success' => true, 'message' => 'Layanan ekosistem baru berhasil ditambahkan.', 'data' => ['id' => (int)$pdo->lastInsertId()]]);
                exit;
            }
        }

        if ($act === 'delete_external_app') {
            $id = (int)($_POST['id'] ?? 0);
            $pdo->prepare("DELETE FROM sso_external_apps WHERE id = ?")->execute([$id]);
            echo json_encode(['success' => true, 'message' => 'Layanan ekosistem berhasil dihapus.']);
            exit;
        }

        echo json_encode(['success' => false, 'message' => 'Aksi API tidak dikenali.']);
        exit;
    } catch (Throwable $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }
}

$initialTab = isset($_GET['tab']) ? strtolower(trim((string)$_GET['tab'])) : 'clients';
if (!in_array($initialTab, ['clients', 'sessions', 'users', 'jwks', 'portal'], true)) {
    $initialTab = 'clients';
}

$adminPageMeta = [
    'clients'  => ['title' => 'Aplikasi Klien & Kunci SSO', 'icon' => 'solar:key-minimalistic-square-2-bold'],
    'sessions' => ['title' => 'Sesi Aktif Global',          'icon' => 'solar:devices-bold'],
    'users'    => ['title' => 'Identitas & Multi-Role',     'icon' => 'solar:users-group-rounded-bold'],
    'jwks'     => ['title' => 'JWKS, SDK & OAuth',          'icon' => 'solar:key-square-2-bold'],
    'portal'   => ['title' => 'Kelola Portal & Agenda',     'icon' => 'solar:calendar-add-bold'],
];
$pageTitle = $adminPageMeta[$initialTab]['title'];
$pageIcon  = $adminPageMeta[$initialTab]['icon'];
$margoDefaultRightClosed = true;

$extraHead = '<style>
.ssoadm-app{display:flex;flex-direction:column;gap:14px}
.ssoadm-pane{display:none}.ssoadm-pane.is-active{display:flex;flex-direction:column;gap:14px}
.ssoadm-card{background:#fff;border:1px solid #E2E8F0;border-radius:16px;padding:16px 18px;box-shadow:0 4px 16px rgba(15,23,42,.04)}
.ssoadm-card-head{display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;margin-bottom:14px;padding-bottom:10px;border-bottom:1px solid #F1F5F9}
.ssoadm-card-title{margin:0;font-family:"Outfit",sans-serif;font-size:15px;font-weight:800;color:#1D3B5E;display:inline-flex;align-items:center;gap:8px}
.ssoadm-grid-2{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}
@media(max-width:768px){.ssoadm-grid-2{grid-template-columns:1fr}}
.ssoadm-key-box{background:#F8FAFC;border:1px solid #CBD5E1;border-radius:12px;padding:11px 13px;display:flex;flex-direction:column;gap:6px}
.ssoadm-key-lbl{font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.04em;color:#475569;display:flex;align-items:center;justify-content:space-between;gap:8px}
.ssoadm-key-row{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
.ssoadm-code{font-family:var(--font-mono);font-size:12px;background:#0F172A;color:#E2E8F0;padding:8px 10px;border-radius:9px;word-break:break-all;flex:1;min-width:160px}
.ssoadm-badge{display:inline-flex;align-items:center;gap:4px;padding:3px 9px;border-radius:999px;font-size:11px;font-weight:700;white-space:nowrap}
.ssoadm-badge--ok{background:rgba(13,122,135,0.12);color:#0D7A87}
.ssoadm-badge--warn{background:rgba(82,178,220,0.16);color:#1D3B5E}
.ssoadm-badge--err{background:rgba(29,59,94,0.10);color:#1D3B5E}
.ssoadm-badge--teal{background:rgba(13,122,135,0.12);color:#0D7A87}
.ssoadm-badge--navy{background:rgba(29,59,94,0.12);color:#1D3B5E}
.ssoadm-btn{appearance:none;border:1px solid transparent;border-radius:10px;padding:8px 13px;min-height:40px;font-size:12.5px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;gap:6px;transition:all .18s;text-decoration:none;font-family:inherit}
.ssoadm-btn--sm{padding:6px 10px;min-height:36px;font-size:12px}
.ssoadm-btn--primary{background:linear-gradient(135deg,#1D3B5E,#0D7A87);color:#fff}
.ssoadm-btn--primary:hover{opacity:.93}
.ssoadm-btn--gold{background:linear-gradient(135deg,#0D7A87,#52B2DC);color:#fff}
.ssoadm-btn--ghost{background:#F8FAFC;color:#1D3B5E;border-color:#CBD5E1}
.ssoadm-btn--ghost:hover{background:#F1F5F9}
.ssoadm-btn--danger{background:rgba(29,59,94,0.08);color:#1D3B5E;border-color:rgba(29,59,94,0.24)}
.ssoadm-btn--danger:hover{background:#1D3B5E;color:#fff}
.ssoadm-field{display:flex;flex-direction:column;gap:4px}
.ssoadm-field label{font-size:12px;font-weight:700;color:#334155}
.ssoadm-input,.ssoadm-textarea{width:100%;padding:9px 11px;min-height:42px;border:1.5px solid #CBD5E1;border-radius:10px;font-size:13.5px;background:#fff;color:#0F172A;font-family:inherit}
.ssoadm-textarea{min-height:78px;resize:vertical;font-family:var(--font-mono);font-size:12px}
.ssoadm-input:focus,.ssoadm-textarea:focus{outline:none;border-color:#0D7A87;box-shadow:0 0 0 3px rgba(13,122,135,.14)}
.ssoadm-client-tabs{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:4px}
.ssoadm-client-pill{display:inline-flex;align-items:center;gap:7px;padding:8px 13px;min-height:42px;border-radius:11px;border:1.5px solid #E2E8F0;background:#fff;color:#334155;font-size:12.5px;font-weight:700;cursor:pointer;transition:all .18s}
.ssoadm-client-pill.is-active{border-color:#0D7A87;background:rgba(13,122,135,.08);color:#0D7A87;box-shadow:0 4px 12px rgba(13,122,135,.12)}
.ssoadm-test-banner{display:none;padding:11px 14px;border-radius:11px;font-size:13px;font-weight:600;margin-bottom:10px}
</style>';

require_once BASE_PATH . '/includes/header.php';
?>

<div class="ssoadm-app">
    <!-- ======================================================================= -->
    <!-- HALAMAN 1: APLIKASI KLIEN (sso_clients — TABEL BARIS + MODAL DETAIL)    -->
    <!-- ======================================================================= -->
    <section class="ssoadm-pane <?= $initialTab === 'clients' ? 'is-active' : '' ?>" id="ssoPane_clients">
        <div class="ilmuna-filter-box">
            <div class="ilmuna-filter-topline">
                <div class="ilmuna-pill-group" id="clientFilterPills">
                    <button type="button" class="ilmuna-pill-btn is-active" data-cfilter="all">Semua</button>
                    <button type="button" class="ilmuna-pill-btn" data-cfilter="active">Aktif</button>
                    <button type="button" class="ilmuna-pill-btn" data-cfilter="inactive">Nonaktif</button>
                </div>
                <div class="ilmuna-search-wrap">
                    <iconify-icon icon="solar:magnifer-linear"></iconify-icon>
                    <input type="text" id="clientSearchInput" class="ilmuna-search-input" placeholder="Cari aplikasi klien, slug, domain, public key...">
                </div>
                <div class="ilmuna-filter-actions" style="margin-left:auto;">
                    <button type="button" class="ilmuna-nav-btn ilmuna-nav-btn--primary" onclick="SSOADM.openNewClientModal()">
                        <iconify-icon icon="solar:add-circle-bold" width="17"></iconify-icon>
                        <span>Tambah Klien SSO</span>
                    </button>
                </div>
            </div>
        </div>

        <div class="ssoadm-card" style="padding:0;overflow:hidden;">
            <div class="table-responsive">
                <table class="lms-table ilmuna-table-stack">
                    <thead>
                        <tr>
                            <th>Aplikasi Klien</th>
                            <th>Public Key (client_id)</th>
                            <th>Mode &amp; Rate Limit</th>
                            <th>Trafik &amp; Pemakaian</th>
                            <th>Status</th>
                            <th style="text-align:right;">Aksi</th>
                        </tr>
                    </thead>
                    <tbody id="clientsTableBody">
                        <tr><td colspan="6" style="text-align:center;padding:24px;color:#64748B;">Memuat daftar aplikasi klien...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </section>

    <!-- ======================================================================= -->
    <!-- TAB 2: SESI AKTIF GLOBAL (sso_sessions)                                 -->
    <!-- ======================================================================= -->
    <section class="ssoadm-pane <?= $initialTab === 'sessions' ? 'is-active' : '' ?>" id="ssoPane_sessions">
        <div class="ilmuna-filter-box">
            <div class="ilmuna-filter-topline">
                <div class="ilmuna-pill-group" id="sessFilterPills">
                    <button type="button" class="ilmuna-pill-btn is-active" data-sfilter="active">Aktif</button>
                    <button type="button" class="ilmuna-pill-btn" data-sfilter="all">Semua</button>
                    <button type="button" class="ilmuna-pill-btn" data-sfilter="revoked">Dicabut</button>
                </div>
                <div class="ilmuna-search-wrap">
                    <iconify-icon icon="solar:magnifer-linear"></iconify-icon>
                    <input type="text" id="sessSearchInput" class="ilmuna-search-input" placeholder="Cari pengguna, IP, perangkat...">
                </div>
                <div class="ilmuna-filter-actions">
                    <button type="button" class="ilmuna-nav-btn ilmuna-nav-btn--ghost" onclick="SSOADM.load()">
                        <iconify-icon icon="solar:refresh-bold" width="16"></iconify-icon>
                        <span>Segarkan</span>
                    </button>
                </div>
            </div>
        </div>

        <div class="ssoadm-card" style="padding:0;overflow:hidden;">
            <div class="table-responsive">
                <table class="lms-table ilmuna-table-stack">
                    <thead>
                        <tr>
                            <th>Pengguna</th>
                            <th>Metode &amp; Peran</th>
                            <th>IP &amp; Perangkat</th>
                            <th>Aktivitas Terakhir</th>
                            <th>Status</th>
                            <th style="text-align:right;">Aksi SLO</th>
                        </tr>
                    </thead>
                    <tbody id="sessTableBody">
                        <tr><td colspan="6" style="text-align:center;padding:24px;color:#64748B;">Memuat sesi global...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </section>

    <!-- ======================================================================= -->
    <!-- TAB 3: IDENTITAS & MULTI-ROLE (users, roles, user_roles)                -->
    <!-- ======================================================================= -->
    <section class="ssoadm-pane <?= $initialTab === 'users' ? 'is-active' : '' ?>" id="ssoPane_users">
        <div class="ilmuna-filter-box">
            <div class="ilmuna-filter-topline">
                <div class="ilmuna-pill-group" id="userRolePills">
                    <button type="button" class="ilmuna-pill-btn is-active" data-ufilter="all">Semua</button>
                    <button type="button" class="ilmuna-pill-btn" data-ufilter="google">Google Tertaut</button>
                    <button type="button" class="ilmuna-pill-btn" data-ufilter="admin">Admin</button>
                    <button type="button" class="ilmuna-pill-btn" data-ufilter="guru">Guru</button>
                    <button type="button" class="ilmuna-pill-btn" data-ufilter="siswa">Siswa</button>
                </div>
                <div class="ilmuna-search-wrap">
                    <iconify-icon icon="solar:magnifer-linear"></iconify-icon>
                    <input type="text" id="userSearchInput" class="ilmuna-search-input" placeholder="Cari nama, username, email, NISN...">
                </div>
                <div class="ilmuna-filter-actions" style="display:flex;gap:8px;flex-wrap:wrap;">
                    <button type="button" class="ilmuna-nav-btn ilmuna-nav-btn--primary" onclick="SSOADM.openLearnSyncModal()">
                        <iconify-icon icon="solar:cloud-download-bold" width="16"></iconify-icon>
                        <span>Sinkron Gmail dari Learn</span>
                    </button>
                    <button type="button" class="ilmuna-nav-btn ilmuna-nav-btn--ghost" onclick="SSOADM.openRoleMatrixModal()">
                        <iconify-icon icon="solar:shield-user-bold" width="16" style="color:#0D7A87;"></iconify-icon>
                        <span>Izin Role ke Aplikasi</span>
                    </button>
                </div>
            </div>
        </div>

        <div id="usersBulkBar" class="ssoadm-card" style="display:none;margin-bottom:12px;padding:10px 14px;background:linear-gradient(135deg,rgba(29,59,94,0.06),rgba(13,122,135,0.09));border-color:rgba(13,122,135,0.32);">
            <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;">
                <div style="display:inline-flex;align-items:center;gap:8px;font-size:13px;font-weight:700;color:#1D3B5E;">
                    <iconify-icon icon="solar:checklist-minimalistic-bold" width="18" style="color:#0D7A87;"></iconify-icon>
                    <span><strong id="lblBulkUsersCount">0</strong> akun dipilih</span>
                </div>
                <div style="display:flex;gap:7px;flex-wrap:wrap;align-items:center;">
                    <button type="button" class="ssoadm-btn ssoadm-btn--ghost ssoadm-btn--sm" onclick="SSOADM.bulkUsersAction('unlink_google')">
                        <iconify-icon icon="solar:link-broken-bold" width="15"></iconify-icon>
                        <span>Putus Google</span>
                    </button>
                    <button type="button" class="ssoadm-btn ssoadm-btn--ghost ssoadm-btn--sm" onclick="SSOADM.bulkUsersAction('revoke_sessions')">
                        <iconify-icon icon="solar:logout-2-bold" width="15"></iconify-icon>
                        <span>Paksa Keluar (SLO)</span>
                    </button>
                    <button type="button" class="ssoadm-btn ssoadm-btn--danger ssoadm-btn--sm" onclick="SSOADM.bulkUsersAction('delete')">
                        <iconify-icon icon="solar:trash-bin-minimalistic-bold" width="15"></iconify-icon>
                        <span>Hapus Terpilih</span>
                    </button>
                    <button type="button" class="ssoadm-btn ssoadm-btn--ghost ssoadm-btn--sm" onclick="SSOADM.clearUserSelection()" title="Batalkan pilihan">
                        <iconify-icon icon="solar:close-circle-linear" width="15"></iconify-icon>
                        <span>Batal</span>
                    </button>
                </div>
            </div>
        </div>

        <div class="ssoadm-card" style="padding:0;overflow:hidden;">
            <div class="table-responsive">
                <table class="lms-table ilmuna-table-stack">
                    <thead>
                        <tr>
                            <th style="width:42px;text-align:center;">
                                <input type="checkbox" id="chkAllUsers" onchange="SSOADM.toggleAllUsers(this.checked)" aria-label="Pilih semua pengguna" style="width:16px;height:16px;accent-color:#0D7A87;cursor:pointer;">
                            </th>
                            <th>Akun Pengguna</th>
                            <th>Peran Lintas Aplikasi</th>
                            <th>Tautan Google SSO</th>
                            <th>Login Terakhir</th>
                            <th style="text-align:right;">Kelola</th>
                        </tr>
                    </thead>
                    <tbody id="usersTableBody">
                        <tr><td colspan="6" style="text-align:center;padding:24px;color:#64748B;">Memuat daftar identitas...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </section>

    <!-- ======================================================================= -->
    <!-- TAB 4: JWKS RSA-2048, SDK & AUDIT LOG KEAMANAN                          -->
    <!-- ======================================================================= -->
    <section class="ssoadm-pane <?= $initialTab === 'jwks' ? 'is-active' : '' ?>" id="ssoPane_jwks">
        <div class="ssoadm-grid-2">
            <div class="ssoadm-card">
                <div class="ssoadm-card-head">
                    <h3 class="ssoadm-card-title">
                        <iconify-icon icon="solar:key-bold" style="color:#0D7A87;font-size:20px;"></iconify-icon>
                        Kunci JWT RS256 (JWKS IdP)
                    </h3>
                    <button type="button" class="ssoadm-btn ssoadm-btn--gold ssoadm-btn--sm" onclick="SSOADM.rotateJwks()">
                        <iconify-icon icon="solar:refresh-circle-bold" width="16"></iconify-icon>
                        <span>Rotasi Kunci JWT (Grace 24j)</span>
                    </button>
                </div>
                <div class="ssoadm-key-box" style="margin-bottom:10px;">
                    <div class="ssoadm-key-lbl"><span>JWKS Public Endpoint (RFC 7517)</span><span class="ssoadm-badge ssoadm-badge--teal">Public JSON</span></div>
                    <div class="ssoadm-key-row">
                        <div class="ssoadm-code" id="txtJwksUrl">-</div>
                        <button type="button" class="ssoadm-btn ssoadm-btn--ghost ssoadm-btn--sm" onclick="MargoUI.copyText(document.getElementById('txtJwksUrl').textContent,'JWKS URL')">
                            <iconify-icon icon="solar:copy-bold"></iconify-icon> Salin
                        </button>
                    </div>
                </div>
                <div id="jwksKeysList" style="display:flex;flex-direction:column;gap:8px;"></div>
            </div>

            <div class="ssoadm-card">
                <div class="ssoadm-card-head">
                    <h3 class="ssoadm-card-title">
                        <iconify-icon icon="solar:box-bold" style="color:#0D7A87;font-size:20px;"></iconify-icon>
                        Unduh Client SDK &amp; Integrasi Siap Pasang
                    </h3>
                </div>
                <div style="display:flex;flex-direction:column;gap:10px;">
                    <a class="ssoadm-btn ssoadm-btn--primary" href="<?= htmlspecialchars(BASE_URL . '/dashboard/admin/?download_sdk=client', ENT_QUOTES, 'UTF-8') ?>">
                        <iconify-icon icon="solar:code-file-bold" width="18"></iconify-icon>
                        <span>Unduh PHP SDK (ilmuna-sso-client.php)</span>
                    </a>
                    <a class="ssoadm-btn ssoadm-btn--ghost" href="<?= htmlspecialchars(BASE_URL . '/dashboard/admin/?download_sdk=learn', ENT_QUOTES, 'UTF-8') ?>">
                        <iconify-icon icon="solar:square-academic-cap-2-bold" width="18" style="color:#0D7A87;"></iconify-icon>
                        <span>Unduh Integrasi learn.ilmuna.my.id (.zip)</span>
                    </a>
                    <a class="ssoadm-btn ssoadm-btn--gold" href="<?= htmlspecialchars(BASE_URL . '/dashboard/admin/?download_sdk=wp', ENT_QUOTES, 'UTF-8') ?>">
                        <iconify-icon icon="solar:globe-bold" width="18"></iconify-icon>
                        <span>Unduh Plugin WordPress Margo One SSO (.zip)</span>
                    </a>
                </div>
            </div>
        </div>

        <div class="ssoadm-card" style="margin-bottom:16px;">
            <div class="ssoadm-card-head">
                <h3 class="ssoadm-card-title">
                    <iconify-icon icon="solar:shield-user-bold" style="color:#0D7A87;font-size:20px;"></iconify-icon>
                    Konfigurasi Google OAuth 2.0 (Login &amp; Penautan Akun)
                </h3>
                <span class="ssoadm-badge ssoadm-badge--teal" id="lblGoogleOAuthStatus">Memuat...</span>
            </div>
            <form id="formGoogleOAuth" onsubmit="SSOADM.saveGoogleOAuth(event)">
                <div class="ssoadm-grid-2" style="margin-bottom:10px;">
                    <div class="ssoadm-field">
                        <label for="cfgGoogleClientId">Google Client ID</label>
                        <input type="text" id="cfgGoogleClientId" class="ssoadm-input" placeholder="xxxx.apps.googleusercontent.com" required>
                    </div>
                    <div class="ssoadm-field">
                        <label for="cfgGoogleClientSecret">Google Client Secret <span id="lblGoogleSecretHint" style="font-weight:500;color:#64748B;"></span></label>
                        <input type="password" id="cfgGoogleClientSecret" class="ssoadm-input" placeholder="Kosongkan jika tidak ingin mengubah Client Secret">
                    </div>
                </div>
                <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;">
                    <div style="font-size:11.5px;color:#475569;">
                        Authorized Origin: <code id="txtGoogleOrigin" style="color:#1D3B5E;font-weight:700;">-</code> ·
                        Redirect URI: <code id="txtGoogleRedirect" style="color:#0D7A87;font-weight:700;">-</code>
                    </div>
                    <button type="submit" class="ssoadm-btn ssoadm-btn--primary ssoadm-btn--sm">
                        <iconify-icon icon="solar:diskette-bold"></iconify-icon>
                        <span>Simpan Google OAuth</span>
                    </button>
                </div>
            </form>
        </div>

        <div class="ssoadm-card" style="margin-top:16px;">
            <div class="ssoadm-card-head">
                <h3 class="ssoadm-card-title">
                    <iconify-icon icon="solar:bell-bing-bold" style="color:#0D7A87;font-size:20px;"></iconify-icon>
                    Integrasi Real-Time Ilmuna Learn (Agenda Kelas &amp; Notifikasi Push)
                </h3>
                <span class="ssoadm-badge ssoadm-badge--teal" id="lblLearnApiStatus">Memuat...</span>
            </div>
            <form id="formLearnApiCfg" onsubmit="SSOADM.saveLearnApi(event)">
                <div class="ssoadm-grid-2" style="margin-bottom:10px;">
                    <div class="ssoadm-field">
                        <label for="cfgLearnEndpoint">Endpoint REST API Learn</label>
                        <input type="url" id="cfgLearnEndpoint" class="ssoadm-input" placeholder="https://learn.ilmuna.my.id/api/sso-broadcast.php" required>
                    </div>
                    <div class="ssoadm-field">
                        <label for="cfgLearnWsUrl">URL WebSocket (Opsional — Otomatis Fallback SSE)</label>
                        <input type="text" id="cfgLearnWsUrl" class="ssoadm-input" placeholder="wss://learn.ilmuna.my.id/ws/sso">
                    </div>
                    <div class="ssoadm-field">
                        <label for="cfgLearnPubKey">Public Key (<code>pk_sso_*</code>)</label>
                        <input type="text" id="cfgLearnPubKey" class="ssoadm-input" placeholder="pk_sso_..." required>
                    </div>
                    <div class="ssoadm-field">
                        <label for="cfgLearnPrivKey">Private Key (<code>sk_sso_*</code>) <span id="lblLearnPrivHint" style="font-weight:500;color:#64748B;"></span></label>
                        <input type="password" id="cfgLearnPrivKey" class="ssoadm-input" placeholder="Kosongkan jika tidak ingin mengubah Private Key">
                    </div>
                </div>
                <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;">
                    <div style="font-size:11.5px;color:#475569;">
                        Sinkron otomatis Tugas (2 tanggal), Proyek (2 tanggal), Kelompok &amp; Asesmen per Kelas secara read-only + Push Real-Time (WS / Zero-CLI SSE).
                    </div>
                    <div style="display:flex;gap:8px;flex-wrap:wrap;">
                        <button type="button" class="ssoadm-btn ssoadm-btn--ghost ssoadm-btn--sm" onclick="SSOADM.testLearnApi()">
                            <iconify-icon icon="solar:bolt-bold"></iconify-icon>
                            <span>Uji Koneksi API</span>
                        </button>
                        <button type="submit" class="ssoadm-btn ssoadm-btn--primary ssoadm-btn--sm">
                            <iconify-icon icon="solar:diskette-bold"></iconify-icon>
                            <span>Simpan Kredensial Learn</span>
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </section>

    <!-- ======================================================================= -->
    <!-- TAB 5: KELOLA PORTAL (AGENDA, PENGUMUMAN & LAYANAN EKOSISTEM NON-SSO)   -->
    <!-- ======================================================================= -->
    <section class="ssoadm-pane <?= $initialTab === 'portal' ? 'is-active' : '' ?>" id="ssoPane_portal">
        <div class="ilmuna-filter-box">
            <div class="ilmuna-filter-topline">
                <div class="ilmuna-pill-group" id="portalSubPills">
                    <button type="button" class="ilmuna-pill-btn is-active" data-psub="agendas">Agenda Ekosistem</button>
                    <button type="button" class="ilmuna-pill-btn" data-psub="announcements">Info &amp; Pengumuman</button>
                    <button type="button" class="ilmuna-pill-btn" data-psub="external_apps">Layanan Non-SSO</button>
                </div>
                <div class="ilmuna-filter-actions" style="margin-left:auto;display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
                    <a href="<?= htmlspecialchars(BASE_URL . '/agenda', ENT_QUOTES, 'UTF-8') ?>" class="ilmuna-nav-btn ilmuna-nav-btn--ghost" id="btnPortalOpenCal" style="text-decoration:none;">
                        <iconify-icon icon="solar:calendar-mark-bold" width="16"></iconify-icon>
                        <span>Buka Kalender Drag</span>
                    </a>
                    <button type="button" class="ilmuna-nav-btn ilmuna-nav-btn--ghost" id="btnPortalSyncKaldik" onclick="SSOADM.syncKaldikAgendas()">
                        <iconify-icon icon="solar:refresh-circle-bold" width="16"></iconify-icon>
                        <span>Sinkron Kaldik</span>
                    </button>
                    <button type="button" class="ilmuna-nav-btn ilmuna-nav-btn--primary" id="btnPortalAdd" onclick="SSOADM.openPortalAdd()">
                        <iconify-icon icon="solar:add-circle-bold" width="17"></iconify-icon>
                        <span id="lblPortalAdd">Tambah Agenda</span>
                    </button>
                </div>
            </div>
        </div>

        <!-- Sub-panel 5A: Agenda -->
        <div class="ssoadm-card" id="portalBox_agendas" style="padding:0;overflow:hidden;">
            <div id="portalAgendaDbBanner" style="padding:10px 16px;background:rgba(13,122,135,0.06);border-bottom:1px solid #E2E8F0;display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;font-size:12px;color:#1D3B5E;">
                <span><iconify-icon icon="solar:database-bold" style="vertical-align:middle;color:#0D7A87;"></iconify-icon> <strong id="portalAgendaDbLabel">Memuat status database agenda...</strong></span>
                <span style="color:#475569;">Agenda dari <strong>Kaldik Ilmuna Learn</strong> tersinkron otomatis &amp; terkunci permanen (read-only).</span>
            </div>
            <div class="table-responsive">
                <table class="lms-table ilmuna-table-stack">
                    <thead>
                        <tr>
                            <th>Tanggal &amp; Jam</th>
                            <th>Judul &amp; Lokasi</th>
                            <th>Audiens &amp; Sumber</th>
                            <th>Status</th>
                            <th style="text-align:right;">Aksi</th>
                        </tr>
                    </thead>
                    <tbody id="portalAgendasBody">
                        <tr><td colspan="5" style="text-align:center;padding:24px;color:#64748B;">Memuat agenda...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Sub-panel 5B: Pengumuman -->
        <div class="ssoadm-card" id="portalBox_announcements" style="display:none;padding:0;overflow:hidden;">
            <div class="table-responsive">
                <table class="lms-table ilmuna-table-stack">
                    <thead>
                        <tr>
                            <th>Label</th>
                            <th>Judul &amp; Ringkasan</th>
                            <th>Tanggal Terbit</th>
                            <th>Status</th>
                            <th style="text-align:right;">Aksi</th>
                        </tr>
                    </thead>
                    <tbody id="portalAnnsBody">
                        <tr><td colspan="5" style="text-align:center;padding:24px;color:#64748B;">Memuat pengumuman...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Sub-panel 5C: Layanan Ekosistem Non-SSO -->
        <div class="ssoadm-card" id="portalBox_external_apps" style="display:none;padding:0;overflow:hidden;">
            <div class="table-responsive">
                <table class="lms-table ilmuna-table-stack">
                    <thead>
                        <tr>
                            <th>Layanan</th>
                            <th>URL Tujuan</th>
                            <th>Badge</th>
                            <th>Status</th>
                            <th style="text-align:right;">Aksi</th>
                        </tr>
                    </thead>
                    <tbody id="portalExtAppsBody">
                        <tr><td colspan="5" style="text-align:center;padding:24px;color:#64748B;">Memuat layanan...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </section>
</div>

<!-- ======================================================================= -->
<!-- UNIVERSAL MODAL 0: KELOLA KREDENSIAL & KONFIGURASI KLIEN SSO            -->
<!-- ======================================================================= -->
<div class="ilm-modal-backdrop" id="modalManageClient">
    <div class="ilm-modal-dialog" style="max-width:780px;">
        <div class="ilm-modal-header">
            <div class="ilm-modal-header__brand">
                <div class="ilm-modal-header__icon"><iconify-icon icon="solar:key-minimalistic-square-2-bold"></iconify-icon></div>
                <div>
                    <h3 class="ilm-modal-header__title" id="activeClientHeading">Kredensial Klien SSO</h3>
                    <p class="ilm-modal-header__subtitle" id="edClientStats">Total request: 0</p>
                </div>
            </div>
            <button type="button" class="ilm-modal-header__close" onclick="closeIlmunaModal('modalManageClient')">
                <iconify-icon icon="solar:close-circle-bold"></iconify-icon>
            </button>
        </div>
        <form id="formEditClient" onsubmit="SSOADM.saveClient(event)">
            <input type="hidden" id="edClientId" value="0">
            <div class="ilm-modal-body" style="max-height:calc(86vh - 150px);">
                <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px;">
                    <button type="button" class="ssoadm-btn ssoadm-btn--ghost ssoadm-btn--sm" onclick="SSOADM.testSigned()">
                        <iconify-icon icon="solar:shield-check-bold" width="16"></iconify-icon>
                        <span>Uji Koneksi Signed Token</span>
                    </button>
                    <button type="button" class="ssoadm-btn ssoadm-btn--gold ssoadm-btn--sm" onclick="SSOADM.generateRsa()">
                        <iconify-icon icon="solar:lock-keyhole-bold" width="16"></iconify-icon>
                        <span>Generate RSA-2048 PEM</span>
                    </button>
                    <button type="button" class="ssoadm-btn ssoadm-btn--danger ssoadm-btn--sm" onclick="SSOADM.rotateClientKeys()">
                        <iconify-icon icon="solar:refresh-circle-bold" width="16"></iconify-icon>
                        <span>Rotasi Kunci</span>
                    </button>
                </div>

                <div id="ssoTestBanner" class="ssoadm-test-banner"></div>

                <div class="ssoadm-grid-2" style="margin-bottom:12px;">
                    <div class="ssoadm-key-box">
                        <div class="ssoadm-key-lbl">
                            <span>Public Key (client_id / X-Ilmuna-Public-Key)</span>
                            <span class="ssoadm-badge ssoadm-badge--navy" id="lblClientSlug">-</span>
                        </div>
                        <div class="ssoadm-key-row">
                            <div class="ssoadm-code" id="txtClientPubKey">Memuat...</div>
                            <button type="button" class="ssoadm-btn ssoadm-btn--ghost ssoadm-btn--sm" onclick="MargoUI.copyText(document.getElementById('txtClientPubKey').textContent,'Public Key')">
                                <iconify-icon icon="solar:copy-bold"></iconify-icon> Salin
                            </button>
                        </div>
                    </div>
                    <div class="ssoadm-key-box">
                        <div class="ssoadm-key-lbl">
                            <span>Private Key (AES-256-CBC+HMAC at-rest)</span>
                            <span class="ssoadm-badge ssoadm-badge--warn" id="lblClientSigMode">HMAC-SHA256</span>
                        </div>
                        <div class="ssoadm-key-row">
                            <div class="ssoadm-code" id="txtClientPrivKey">••••••••••••••••••••••••</div>
                            <button type="button" class="ssoadm-btn ssoadm-btn--ghost ssoadm-btn--sm" id="btnTogglePriv" onclick="SSOADM.togglePriv()">
                                <iconify-icon icon="solar:eye-linear"></iconify-icon> Lihat
                            </button>
                            <button type="button" class="ssoadm-btn ssoadm-btn--ghost ssoadm-btn--sm" onclick="SSOADM.copyPriv()">
                                <iconify-icon icon="solar:copy-bold"></iconify-icon> Salin
                            </button>
                        </div>
                    </div>
                </div>

                <div id="rsaOnceBox" style="display:none;background:rgba(82,178,220,0.10);border:1px solid rgba(82,178,220,0.35);border-radius:12px;padding:13px;margin-bottom:12px;">
                    <div style="display:flex;justify-content:space-between;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:8px;">
                        <strong style="color:#1D3B5E;font-size:12.5px;">
                            <iconify-icon icon="solar:danger-triangle-bold"></iconify-icon>
                            RSA-2048 Private Key PEM (Ditampilkan SATU KALI — Salin ke Aplikasi Klien sekarang!)
                        </strong>
                        <button type="button" class="ssoadm-btn ssoadm-btn--gold ssoadm-btn--sm" onclick="MargoUI.copyText(document.getElementById('rsaPemOut').value,'RSA Private PEM')">
                            <iconify-icon icon="solar:copy-bold"></iconify-icon> Salin PEM
                        </button>
                    </div>
                    <textarea id="rsaPemOut" class="ssoadm-textarea" rows="5" readonly style="background:#1D3B5E;color:#52B2DC;"></textarea>
                </div>

                <div class="ssoadm-grid-2" style="margin-bottom:12px;">
                    <div class="ssoadm-field">
                        <label for="edClientName">Nama Aplikasi Klien</label>
                        <input type="text" id="edClientName" class="ssoadm-input" required>
                    </div>
                    <div class="ssoadm-field">
                        <label for="edClientSlug">Slug Klien</label>
                        <input type="text" id="edClientSlug" class="ssoadm-input" required>
                    </div>
                    <div class="ssoadm-field">
                        <label for="edAppUrl">URL Aplikasi (App Launcher)</label>
                        <input type="url" id="edAppUrl" class="ssoadm-input" required>
                    </div>
                    <div class="ssoadm-field">
                        <label>Foto / Icon Aplikasi &amp; Ikon Solar Fallback</label>
                        <input type="hidden" id="edRemoveAppLogo" value="0">
                        <input type="file" id="edAppLogoFile" accept="image/png,image/jpeg,image/webp,image/svg+xml,image/x-icon" style="display:none;" onchange="SSOADM.previewEditClientLogo(this)">
                        <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                            <div id="edAppLogoPreview" style="width:44px;height:44px;border-radius:11px;background:#FFFFFF;color:#0D7A87;padding:5px;display:flex;align-items:center;justify-content:center;flex-shrink:0;overflow:hidden;border:1.5px solid #E2E8F0;">
                                <iconify-icon icon="solar:widget-5-bold" width="20"></iconify-icon>
                            </div>
                            <button type="button" class="ssoadm-btn ssoadm-btn--ghost ssoadm-btn--sm" onclick="document.getElementById('edAppLogoFile').click()">
                                <iconify-icon icon="solar:gallery-send-bold"></iconify-icon> Unggah Foto/Icon
                            </button>
                            <button type="button" class="ssoadm-btn ssoadm-btn--danger ssoadm-btn--sm" id="btnEdClearLogo" style="display:none;" onclick="SSOADM.clearEditClientLogo()">
                                <iconify-icon icon="solar:trash-bin-minimalistic-bold"></iconify-icon> Hapus Foto
                            </button>
                            <input type="text" id="edAppIcon" class="ssoadm-input" value="solar:widget-5-bold" placeholder="solar:widget-5-bold" style="flex:1;min-width:150px;">
                        </div>
                    </div>
                    <div class="ssoadm-field">
                        <label for="edSigMode">Mode Tanda Tangan Server-to-Server</label>
                        <select id="edSigMode" class="ssoadm-input">
                            <option value="HMAC-SHA256">HMAC-SHA256 (Public + Private Key)</option>
                            <option value="RSA-SHA256">RSA-SHA256 (Asimetris RSA-2048 PEM)</option>
                            <option value="DUAL">DUAL (Wajib HMAC-SHA256 + RSA-2048)</option>
                        </select>
                    </div>
                    <div class="ssoadm-field">
                        <label for="edRateLimit">Rate Limit (req / menit / IP)</label>
                        <input type="number" id="edRateLimit" class="ssoadm-input" min="10" max="2000" value="180">
                    </div>
                    <div class="ssoadm-field">
                        <label for="edAllowedDomains">Whitelist Domain (pisahkan koma)</label>
                        <input type="text" id="edAllowedDomains" class="ssoadm-input">
                    </div>
                    <div class="ssoadm-field">
                        <label for="edAllowedIps">Whitelist IP / CIDR (kosong = bebas IP bertanda tangan)</label>
                        <input type="text" id="edAllowedIps" class="ssoadm-input">
                    </div>
                    <div class="ssoadm-field">
                        <label for="edLogoutWebhook">URL Back-Channel Logout Webhook (SLO)</label>
                        <input type="url" id="edLogoutWebhook" class="ssoadm-input" placeholder="https://.../sso-logout-webhook.php">
                    </div>
                    <div class="ssoadm-field">
                        <label>Permission Role yang Diizinkan Login ke Aplikasi Ini</label>
                        <input type="hidden" id="edAllowedRoles" value="admin,guru,ketua_kelas,siswa">
                        <div id="edRolePermsCheckboxes" style="display:flex;gap:8px;flex-wrap:wrap;padding:6px 0;">
                            <label style="display:inline-flex;align-items:center;gap:6px;padding:6px 10px;border:1px solid #CBD5E1;border-radius:9px;font-size:12.5px;font-weight:700;cursor:pointer;background:#F8FAFC;"><input type="checkbox" name="edClientRolePerm" value="admin" checked onchange="SSOADM.syncEdAllowedRoles()"> Admin</label>
                            <label style="display:inline-flex;align-items:center;gap:6px;padding:6px 10px;border:1px solid #CBD5E1;border-radius:9px;font-size:12.5px;font-weight:700;cursor:pointer;background:#F8FAFC;"><input type="checkbox" name="edClientRolePerm" value="guru" checked onchange="SSOADM.syncEdAllowedRoles()"> Guru</label>
                            <label style="display:inline-flex;align-items:center;gap:6px;padding:6px 10px;border:1px solid #CBD5E1;border-radius:9px;font-size:12.5px;font-weight:700;cursor:pointer;background:#F8FAFC;"><input type="checkbox" name="edClientRolePerm" value="ketua_kelas" checked onchange="SSOADM.syncEdAllowedRoles()"> Ketua Kelas</label>
                            <label style="display:inline-flex;align-items:center;gap:6px;padding:6px 10px;border:1px solid #CBD5E1;border-radius:9px;font-size:12.5px;font-weight:700;cursor:pointer;background:#F8FAFC;"><input type="checkbox" name="edClientRolePerm" value="siswa" checked onchange="SSOADM.syncEdAllowedRoles()"> Siswa</label>
                        </div>
                    </div>
                </div>
                <div class="ssoadm-grid-2">
                    <div class="ssoadm-field">
                        <label for="edRedirectUris">Whitelist Redirect URIs (1 per baris — Exact Match!)</label>
                        <textarea id="edRedirectUris" class="ssoadm-textarea" required></textarea>
                    </div>
                    <div class="ssoadm-field">
                        <label for="edAllowedScopes">Allowed Scopes (koma)</label>
                        <input type="text" id="edAllowedScopes" class="ssoadm-input" value="openid,profile,email,roles">
                        <div style="margin-top:12px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;">
                            <label style="display:inline-flex;align-items:center;gap:8px;font-size:13px;font-weight:700;cursor:pointer;">
                                <input type="checkbox" id="edIsActive" checked>
                                Klien Aktif
                            </label>
                            <span class="ssoadm-badge ssoadm-badge--teal" id="edRsaStatus">RSA Public PEM: Belum diatur</span>
                        </div>
                    </div>
                </div>
            </div>
            <div class="ilm-modal-footer">
                <button type="button" class="ilm-modal-btn ilm-modal-btn--ghost" onclick="closeIlmunaModal('modalManageClient')">Tutup</button>
                <button type="submit" class="ilm-modal-btn ilm-modal-btn--primary">
                    <iconify-icon icon="solar:diskette-bold"></iconify-icon>
                    <span>Simpan Perubahan Klien</span>
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ======================================================================= -->
<!-- UNIVERSAL MODAL 1: TAMBAH KLIEN SSO BARU                                -->
<!-- ======================================================================= -->
<div class="ilm-modal-backdrop" id="modalNewClient">
    <div class="ilm-modal-dialog" style="max-width:640px;">
        <div class="ilm-modal-header">
            <div class="ilm-modal-header__brand">
                <div class="ilm-modal-header__icon"><iconify-icon icon="solar:shield-plus-bold"></iconify-icon></div>
                <div>
                    <h3 class="ilm-modal-header__title">Tambah Aplikasi Klien SSO</h3>
                    <p class="ilm-modal-header__subtitle">Public Key &amp; Private Key akan dibuat otomatis</p>
                </div>
            </div>
            <button type="button" class="ilm-modal-header__close" onclick="closeIlmunaModal('modalNewClient')">
                <iconify-icon icon="solar:close-circle-bold"></iconify-icon>
            </button>
        </div>
        <form id="formNewClient" onsubmit="SSOADM.createClient(event)">
            <div class="ilm-modal-body" style="max-height:calc(85vh - 150px);">
                <div class="ssoadm-grid-2" style="margin-bottom:10px;">
                    <div class="ssoadm-field"><label>Nama Aplikasi</label><input type="text" id="ncName" class="ssoadm-input" placeholder="Perpustakaan Ilmuna" required></div>
                    <div class="ssoadm-field"><label>Slug Unik</label><input type="text" id="ncSlug" class="ssoadm-input" placeholder="ilmuna-perpus" required></div>
                    <div class="ssoadm-field"><label>URL Aplikasi</label><input type="url" id="ncAppUrl" class="ssoadm-input" placeholder="https://perpus.ilmuna.my.id" required></div>
                    <div class="ssoadm-field">
                        <label>Foto / Icon Aplikasi (PNG/JPG/WEBP/SVG/ICO)</label>
                        <input type="file" id="ncLogoFile" class="ssoadm-input" accept="image/png,image/jpeg,image/webp,image/svg+xml,image/x-icon" style="padding:6px;">
                        <input type="hidden" id="ncIcon" value="solar:widget-5-bold">
                    </div>
                    <div class="ssoadm-field"><label>Whitelist Domain (koma)</label><input type="text" id="ncDomains" class="ssoadm-input" placeholder="perpus.ilmuna.my.id" required></div>
                    <div class="ssoadm-field"><label>Webhook SLO (opsional)</label><input type="url" id="ncWebhook" class="ssoadm-input" placeholder="https://perpus.ilmuna.my.id/sso-logout.php"></div>
                </div>
                <div class="ssoadm-field" style="margin-bottom:10px;">
                    <label>Permission Role yang Diizinkan Login</label>
                    <div style="display:flex;gap:8px;flex-wrap:wrap;padding:4px 0;">
                        <label style="display:inline-flex;align-items:center;gap:6px;padding:6px 10px;border:1px solid #CBD5E1;border-radius:9px;font-size:12.5px;font-weight:700;cursor:pointer;background:#F8FAFC;"><input type="checkbox" name="ncClientRolePerm" value="admin" checked> Admin</label>
                        <label style="display:inline-flex;align-items:center;gap:6px;padding:6px 10px;border:1px solid #CBD5E1;border-radius:9px;font-size:12.5px;font-weight:700;cursor:pointer;background:#F8FAFC;"><input type="checkbox" name="ncClientRolePerm" value="guru" checked> Guru</label>
                        <label style="display:inline-flex;align-items:center;gap:6px;padding:6px 10px;border:1px solid #CBD5E1;border-radius:9px;font-size:12.5px;font-weight:700;cursor:pointer;background:#F8FAFC;"><input type="checkbox" name="ncClientRolePerm" value="ketua_kelas" checked> Ketua Kelas</label>
                        <label style="display:inline-flex;align-items:center;gap:6px;padding:6px 10px;border:1px solid #CBD5E1;border-radius:9px;font-size:12.5px;font-weight:700;cursor:pointer;background:#F8FAFC;"><input type="checkbox" name="ncClientRolePerm" value="siswa" checked> Siswa</label>
                    </div>
                </div>
                <div class="ssoadm-field">
                    <label>Whitelist Redirect URIs (1 per baris)</label>
                    <textarea id="ncRedirects" class="ssoadm-textarea" placeholder="https://perpus.ilmuna.my.id/sso-callback" required></textarea>
                </div>
            </div>
            <div class="ilm-modal-footer">
                <button type="button" class="ilm-modal-btn ilm-modal-btn--ghost" onclick="closeIlmunaModal('modalNewClient')">Batal</button>
                <button type="submit" class="ilm-modal-btn ilm-modal-btn--primary">
                    <iconify-icon icon="solar:diskette-bold"></iconify-icon><span>Buat Klien SSO</span>
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ======================================================================= -->
<!-- UNIVERSAL MODAL 2: KELOLA MULTI-ROLE & PERMISSION APLIKASI PENGGUNA     -->
<!-- ======================================================================= -->
<div class="ilm-modal-backdrop" id="modalEditRoles">
    <div class="ilm-modal-dialog" style="max-width:560px;">
        <div class="ilm-modal-header">
            <div class="ilm-modal-header__brand">
                <div class="ilm-modal-header__icon"><iconify-icon icon="solar:users-group-two-rounded-bold"></iconify-icon></div>
                <div>
                    <h3 class="ilm-modal-header__title" id="mdlRoleTitle">Kelola Multi-Role &amp; Permission Aplikasi</h3>
                    <p class="ilm-modal-header__subtitle">Atur hak peran serta aplikasi mana saja yang diizinkan untuk login</p>
                </div>
            </div>
            <button type="button" class="ilm-modal-header__close" onclick="closeIlmunaModal('modalEditRoles')">
                <iconify-icon icon="solar:close-circle-bold"></iconify-icon>
            </button>
        </div>
        <form onsubmit="SSOADM.saveUserRoles(event)">
            <input type="hidden" id="mdlRoleUserId" value="0">
            <div class="ilm-modal-body" style="max-height:calc(85vh - 150px);">
                <div style="font-size:12px;font-weight:800;color:#1D3B5E;text-transform:uppercase;letter-spacing:0.04em;margin-bottom:8px;">
                    1. Hak Peran Akun (Multi-Role)
                </div>
                <div id="mdlRoleCheckboxes" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:8px;margin-bottom:12px;"></div>
                <div class="ssoadm-field" style="margin-bottom:16px;">
                    <label for="mdlPrimaryRole">Peran Utama (Primary Role)</label>
                    <select id="mdlPrimaryRole" class="ssoadm-input"></select>
                </div>

                <div style="border-top:1px solid #E2E8F0;padding-top:14px;">
                    <div style="display:flex;align-items:center;justify-content:space-between;gap:8px;margin-bottom:8px;">
                        <div style="font-size:12px;font-weight:800;color:#0D7A87;text-transform:uppercase;letter-spacing:0.04em;">
                            2. Permission Login Aplikasi SSO (Bisa Login ke Aplikasi Mana Saja)
                        </div>
                        <span class="ssoadm-badge ssoadm-badge--teal">Akses Klien</span>
                    </div>
                    <p style="font-size:12px;color:#64748B;margin-bottom:10px;">
                        Centang aplikasi klien SSO yang boleh diakses oleh akun ini saat login melalui Margo One SSO:
                    </p>
                    <div id="mdlAppPermsCheckboxes" style="display:flex;flex-direction:column;gap:8px;"></div>
                </div>
            </div>
            <div class="ilm-modal-footer">
                <button type="button" class="ilm-modal-btn ilm-modal-btn--ghost" onclick="closeIlmunaModal('modalEditRoles')">Batal</button>
                <button type="submit" class="ilm-modal-btn ilm-modal-btn--primary">
                    <iconify-icon icon="solar:diskette-bold"></iconify-icon><span>Simpan Peran &amp; Permission</span>
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ======================================================================= -->
<!-- UNIVERSAL MODAL 2B: MATRIKS PERMISSION ROLE KE APLIKASI SSO             -->
<!-- ======================================================================= -->
<div class="ilm-modal-backdrop" id="modalRoleAppMatrix">
    <div class="ilm-modal-dialog" style="max-width:720px;">
        <div class="ilm-modal-header">
            <div class="ilm-modal-header__brand">
                <div class="ilm-modal-header__icon"><iconify-icon icon="solar:shield-user-bold"></iconify-icon></div>
                <div>
                    <h3 class="ilm-modal-header__title">Permission Role Login ke Aplikasi SSO</h3>
                    <p class="ilm-modal-header__subtitle">Atur setiap Role (Admin, Guru, Ketua Kelas, Siswa) bisa login ke aplikasi mana saja</p>
                </div>
            </div>
            <button type="button" class="ilm-modal-header__close" onclick="closeIlmunaModal('modalRoleAppMatrix')">
                <iconify-icon icon="solar:close-circle-bold"></iconify-icon>
            </button>
        </div>
        <form onsubmit="SSOADM.saveRoleMatrix(event)">
            <div class="ilm-modal-body" style="max-height:calc(85vh - 150px);">
                <div id="roleMatrixContainer" style="display:flex;flex-direction:column;gap:12px;"></div>
            </div>
            <div class="ilm-modal-footer">
                <button type="button" class="ilm-modal-btn ilm-modal-btn--ghost" onclick="closeIlmunaModal('modalRoleAppMatrix')">Batal</button>
                <button type="submit" class="ilm-modal-btn ilm-modal-btn--primary">
                    <iconify-icon icon="solar:diskette-bold"></iconify-icon><span>Simpan Permission Role</span>
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ======================================================================= -->
<!-- UNIVERSAL MODAL 3: RESET KATA SANDI PENGGUNA                            -->
<!-- ======================================================================= -->
<div class="ilm-modal-backdrop" id="modalResetPass">
    <div class="ilm-modal-dialog" style="max-width:460px;">
        <div class="ilm-modal-header">
            <div class="ilm-modal-header__brand">
                <div class="ilm-modal-header__icon"><iconify-icon icon="solar:lock-password-bold"></iconify-icon></div>
                <div>
                    <h3 class="ilm-modal-header__title" id="mdlPassTitle">Reset Kata Sandi</h3>
                    <p class="ilm-modal-header__subtitle">Kata sandi baru terenkripsi bcrypt/argon2</p>
                </div>
            </div>
            <button type="button" class="ilm-modal-header__close" onclick="closeIlmunaModal('modalResetPass')">
                <iconify-icon icon="solar:close-circle-bold"></iconify-icon>
            </button>
        </div>
        <form onsubmit="SSOADM.submitResetPass(event)">
            <input type="hidden" id="mdlPassUserId" value="0">
            <div class="ilm-modal-body">
                <div class="ssoadm-field" style="margin-bottom:10px;">
                    <label for="mdlNewPass">Kata Sandi Baru (min. 8 karakter)</label>
                    <div style="display:flex;gap:8px;">
                        <input type="text" id="mdlNewPass" class="ssoadm-input" minlength="8" required>
                        <button type="button" class="ssoadm-btn ssoadm-btn--ghost" onclick="SSOADM.genRandomPass()">Acak</button>
                    </div>
                </div>
                <label style="display:inline-flex;align-items:center;gap:8px;font-size:13px;font-weight:600;cursor:pointer;">
                    <input type="checkbox" id="mdlRevokeAfterReset" checked>
                    Paksa keluar semua sesi aktif pengguna ini (SLO)
                </label>
            </div>
            <div class="ilm-modal-footer">
                <button type="button" class="ilm-modal-btn ilm-modal-btn--ghost" onclick="closeIlmunaModal('modalResetPass')">Batal</button>
                <button type="submit" class="ilm-modal-btn ilm-modal-btn--primary">
                    <iconify-icon icon="solar:key-bold"></iconify-icon><span>Simpan Sandi Baru</span>
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ======================================================================= -->
<!-- UNIVERSAL MODAL 3B: SINKRON USER DARI LEARN (TERPISAH + KIRIM GMAIL)    -->
<!-- ======================================================================= -->
<div class="ilm-modal-backdrop" id="modalLearnSync">
    <div class="ilm-modal-dialog" style="max-width:860px;">
        <div class="ilm-modal-header">
            <div class="ilm-modal-header__brand">
                <div class="ilm-modal-header__icon"><iconify-icon icon="solar:cloud-download-bold"></iconify-icon></div>
                <div>
                    <h3 class="ilm-modal-header__title">Sinkron Gmail dari Ilmuna Learn (Akun SSO Terpisah)</h3>
                    <p class="ilm-modal-header__subtitle">Hanya mengambil Gmail &amp; nomor ID/NISN/NIS/NIP. Username &amp; sandi acak SSO dibuat baru lalu dikirim ke Gmail user.</p>
                </div>
            </div>
            <button type="button" class="ilm-modal-header__close" onclick="closeIlmunaModal('modalLearnSync')">
                <iconify-icon icon="solar:close-circle-bold"></iconify-icon>
            </button>
        </div>
        <div class="ilm-modal-body" style="max-height:calc(84vh - 165px);display:flex;flex-direction:column;gap:12px;">
            <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;">
                <div class="ilmuna-pill-group" id="learnSyncFilterPills">
                    <button type="button" class="ilmuna-pill-btn is-active" data-lsfilter="new">Belum di SSO (<span id="lsCntNew">0</span>)</button>
                    <button type="button" class="ilmuna-pill-btn" data-lsfilter="all_email">Semua Ada Gmail (<span id="lsCntEmail">0</span>)</button>
                    <button type="button" class="ilmuna-pill-btn" data-lsfilter="no_email">Tanpa Gmail (<span id="lsCntNoEmail">0</span>)</button>
                </div>
                <div style="display:flex;align-items:center;gap:8px;flex:1;min-width:200px;max-width:300px;">
                    <input type="text" id="learnSyncSearch" class="ssoadm-input" style="min-height:36px;padding:6px 10px;" placeholder="Cari nama, Gmail, NISN/NIP...">
                </div>
            </div>

            <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;padding:8px 12px;border-radius:10px;background:#F8FAFC;border:1px solid #E2E8F0;font-size:12.5px;">
                <label style="display:inline-flex;align-items:center;gap:7px;font-weight:700;color:#1D3B5E;cursor:pointer;">
                    <input type="checkbox" id="learnSyncCheckAll" onchange="SSOADM.toggleLearnSyncAll(this.checked)">
                    <span>Pilih Semua yang Ditampilkan</span>
                </label>
                <label style="display:inline-flex;align-items:center;gap:7px;font-weight:600;color:#475569;cursor:pointer;">
                    <input type="checkbox" id="learnSyncResendExisting">
                    <span>Reset &amp; kirim ulang sandi acak jika akun sudah ada di SSO</span>
                </label>
            </div>

            <div id="learnSyncProgressWrap" style="display:none;padding:10px 12px;border-radius:10px;background:#F0F9FF;border:1px solid #BAE6FD;">
                <div style="display:flex;justify-content:space-between;font-size:12px;font-weight:700;color:#1D3B5E;margin-bottom:6px;">
                    <span id="learnSyncProgressText">Menyiapkan pengiriman...</span>
                    <span id="learnSyncProgressPct">0%</span>
                </div>
                <div style="height:8px;border-radius:999px;background:#E2E8F0;overflow:hidden;">
                    <div id="learnSyncProgressBar" style="height:100%;width:0%;background:linear-gradient(90deg,#0D7A87,#52B2DC);transition:width .25s ease;"></div>
                </div>
            </div>

            <div class="table-responsive" style="border:1px solid #E2E8F0;border-radius:12px;overflow:auto;max-height:360px;">
                <table class="lms-table" style="margin:0;">
                    <thead>
                        <tr>
                            <th style="width:36px;"></th>
                            <th>Nama &amp; Peran</th>
                            <th>No. Ref (ID/NISN/NIP)</th>
                            <th>Gmail Tujuan</th>
                            <th>Status SSO</th>
                        </tr>
                    </thead>
                    <tbody id="learnSyncTableBody">
                        <tr><td colspan="5" style="text-align:center;padding:24px;color:#64748B;">Klik Sinkron Gmail dari Learn untuk memuat daftar...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="ilm-modal-footer" style="justify-content:space-between;flex-wrap:wrap;gap:10px;">
            <span id="learnSyncSelectedInfo" style="font-size:12.5px;font-weight:700;color:#475569;">0 pengguna dipilih</span>
            <div style="display:flex;gap:8px;">
                <button type="button" class="ilm-modal-btn ilm-modal-btn--ghost" onclick="closeIlmunaModal('modalLearnSync')">Tutup</button>
                <button type="button" class="ilm-modal-btn ilm-modal-btn--primary" id="btnRunLearnSync" onclick="SSOADM.runLearnSyncSelected()">
                    <iconify-icon icon="solar:letter-opened-bold"></iconify-icon>
                    <span>Buat Akun Acak &amp; Kirim ke Gmail</span>
                </button>
            </div>
        </div>
    </div>
</div>

<!-- ======================================================================= -->
<!-- UNIVERSAL MODAL 4: AGENDA EKOSISTEM                                     -->
<!-- ======================================================================= -->
<div class="ilm-modal-backdrop" id="modalPortalAgenda">
    <div class="ilm-modal-dialog" style="max-width:560px;">
        <div class="ilm-modal-header">
            <div class="ilm-modal-header__brand">
                <div class="ilm-modal-header__icon"><iconify-icon icon="solar:calendar-add-bold"></iconify-icon></div>
                <div>
                    <h3 class="ilm-modal-header__title" id="mdlPAgTitle">Tambah Agenda Ekosistem</h3>
                    <p class="ilm-modal-header__subtitle">Tampil di Kalender /agenda &amp; Panel Kanan Portal</p>
                </div>
            </div>
            <button type="button" class="ilm-modal-header__close" onclick="closeIlmunaModal('modalPortalAgenda')">
                <iconify-icon icon="solar:close-circle-bold"></iconify-icon>
            </button>
        </div>
        <form id="formPortalAgenda" onsubmit="SSOADM.savePortalAgenda(event)">
            <input type="hidden" id="pAgId" value="0">
            <div class="ilm-modal-body" style="max-height:calc(85vh - 150px);">
                <div class="ssoadm-field" style="margin-bottom:10px;"><label>Judul Agenda</label><input type="text" id="pAgTitle" class="ssoadm-input" required></div>
                <div class="ssoadm-grid-2" style="margin-bottom:10px;">
                    <div class="ssoadm-field"><label>Tanggal Mulai</label><input type="date" id="pAgStart" class="ssoadm-input" required></div>
                    <div class="ssoadm-field"><label>Tanggal Selesai (opsional)</label><input type="date" id="pAgEnd" class="ssoadm-input"></div>
                    <div class="ssoadm-field"><label>Jam WIB (opsional)</label><input type="text" id="pAgTime" class="ssoadm-input" placeholder="08:00"></div>
                    <div class="ssoadm-field"><label>Kategori &amp; Warna</label>
                        <select id="pAgCat" class="ssoadm-input">
                            <option value="akademik">Akademik (Teal #0D7A87)</option>
                            <option value="rapat">Rapat / Koordinasi (Indigo #4F46E5)</option>
                            <option value="ujian">Ujian / Asesmen (Kuning Emas #F59E0B)</option>
                            <option value="kegiatan">Kegiatan Sekolah (Oranye #EA580C)</option>
                            <option value="sistem">Sistem SSO (Navy #1D3B5E)</option>
                            <option value="pengumuman">Pengumuman (Biru #0284C7)</option>
                            <option value="pribadi">Pribadi / Pengingat (Magenta #DB2777)</option>
                        </select>
                    </div>
                    <div class="ssoadm-field"><label>Target Audiens (Siapa yang Dapat Melihat)</label>
                        <select id="pAgScope" class="ssoadm-input">
                            <option value="all">Semua Orang (Admin, Guru &amp; Siswa)</option>
                            <option value="guru">Khusus Guru Saja</option>
                            <option value="siswa">Khusus Siswa Saja</option>
                            <option value="private">Pribadi (Admin Pembuat Saja)</option>
                        </select>
                    </div>
                    <div class="ssoadm-field"><label>Lokasi / Media</label><input type="text" id="pAgLoc" class="ssoadm-input" placeholder="Aula / Zoom"></div>
                    <div class="ssoadm-field"><label>Tautan Terkait (opsional)</label><input type="text" id="pAgLink" class="ssoadm-input" placeholder="/profile atau https://..."></div>
                </div>
                <div class="ssoadm-field" style="margin-bottom:10px;"><label>Deskripsi</label><textarea id="pAgDesc" class="ssoadm-textarea" style="font-family:inherit;font-size:13px;"></textarea></div>
                <label style="display:inline-flex;align-items:center;gap:8px;font-size:13px;font-weight:700;cursor:pointer;">
                    <input type="checkbox" id="pAgActive" checked> Tampilkan ke Pengguna
                </label>
            </div>
            <div class="ilm-modal-footer">
                <button type="button" class="ilm-modal-btn ilm-modal-btn--ghost" onclick="closeIlmunaModal('modalPortalAgenda')">Batal</button>
                <button type="submit" class="ilm-modal-btn ilm-modal-btn--primary"><iconify-icon icon="solar:diskette-bold"></iconify-icon><span>Simpan Agenda</span></button>
            </div>
        </form>
    </div>
</div>

<!-- ======================================================================= -->
<!-- UNIVERSAL MODAL 5: INFO & PENGUMUMAN                                    -->
<!-- ======================================================================= -->
<div class="ilm-modal-backdrop" id="modalPortalAnn">
    <div class="ilm-modal-dialog" style="max-width:520px;">
        <div class="ilm-modal-header">
            <div class="ilm-modal-header__brand">
                <div class="ilm-modal-header__icon"><iconify-icon icon="solar:bell-bing-bold"></iconify-icon></div>
                <div>
                    <h3 class="ilm-modal-header__title" id="mdlPAnnTitle">Tambah Info Ekosistem</h3>
                    <p class="ilm-modal-header__subtitle">Tampil di Widget Info Ekosistem Panel Kanan</p>
                </div>
            </div>
            <button type="button" class="ilm-modal-header__close" onclick="closeIlmunaModal('modalPortalAnn')">
                <iconify-icon icon="solar:close-circle-bold"></iconify-icon>
            </button>
        </div>
        <form id="formPortalAnn" onsubmit="SSOADM.savePortalAnn(event)">
            <input type="hidden" id="pAnnId" value="0">
            <div class="ilm-modal-body">
                <div class="ssoadm-field" style="margin-bottom:10px;"><label>Judul Pengumuman</label><input type="text" id="pAnnTitle" class="ssoadm-input" required></div>
                <div class="ssoadm-grid-2" style="margin-bottom:10px;">
                    <div class="ssoadm-field"><label>Label Badge</label><input type="text" id="pAnnBadge" class="ssoadm-input" value="INFO" placeholder="SSO / KEAMANAN / TIPS"></div>
                    <div class="ssoadm-field"><label>Tautan (opsional)</label><input type="text" id="pAnnLink" class="ssoadm-input" placeholder="/gateway atau https://..."></div>
                </div>
                <div class="ssoadm-field" style="margin-bottom:10px;"><label>Ringkasan Singkat</label><textarea id="pAnnExcerpt" class="ssoadm-textarea" style="font-family:inherit;font-size:13px;"></textarea></div>
                <label style="display:inline-flex;align-items:center;gap:8px;font-size:13px;font-weight:700;cursor:pointer;">
                    <input type="checkbox" id="pAnnActive" checked> Aktif Ditampilkan
                </label>
            </div>
            <div class="ilm-modal-footer">
                <button type="button" class="ilm-modal-btn ilm-modal-btn--ghost" onclick="closeIlmunaModal('modalPortalAnn')">Batal</button>
                <button type="submit" class="ilm-modal-btn ilm-modal-btn--primary"><iconify-icon icon="solar:diskette-bold"></iconify-icon><span>Simpan Info</span></button>
            </div>
        </form>
    </div>
</div>

<!-- ======================================================================= -->
<!-- UNIVERSAL MODAL 6: LAYANAN EKOSISTEM NON-SSO                            -->
<!-- ======================================================================= -->
<div class="ilm-modal-backdrop" id="modalPortalExtApp">
    <div class="ilm-modal-dialog" style="max-width:520px;">
        <div class="ilm-modal-header">
            <div class="ilm-modal-header__brand">
                <div class="ilm-modal-header__icon"><iconify-icon icon="solar:widget-4-bold"></iconify-icon></div>
                <div>
                    <h3 class="ilm-modal-header__title" id="mdlPExtTitle">Tambah Layanan Ekosistem</h3>
                    <p class="ilm-modal-header__subtitle">Tampil di Beranda Portal bagian Layanan Lainnya</p>
                </div>
            </div>
            <button type="button" class="ilm-modal-header__close" onclick="closeIlmunaModal('modalPortalExtApp')">
                <iconify-icon icon="solar:close-circle-bold"></iconify-icon>
            </button>
        </div>
        <form id="formPortalExtApp" onsubmit="SSOADM.savePortalExtApp(event)">
            <input type="hidden" id="pExtId" value="0">
            <div class="ilm-modal-body">
                <div class="ssoadm-grid-2" style="margin-bottom:10px;">
                    <div class="ssoadm-field"><label>Nama Layanan</label><input type="text" id="pExtName" class="ssoadm-input" required></div>
                    <div class="ssoadm-field"><label>Label Badge</label><input type="text" id="pExtBadge" class="ssoadm-input" value="EKOSISTEM"></div>
                    <div class="ssoadm-field"><label>URL Tujuan</label><input type="text" id="pExtUrl" class="ssoadm-input" placeholder="https://... atau /agenda" required></div>
                    <div class="ssoadm-field">
                        <label>Foto / Icon Layanan &amp; Ikon Solar Fallback</label>
                        <input type="file" id="pExtLogoFile" class="ssoadm-input" accept="image/png,image/jpeg,image/webp,image/svg+xml,image/x-icon" style="padding:5px;margin-bottom:4px;">
                        <input type="text" id="pExtIcon" class="ssoadm-input" value="solar:widget-4-bold" placeholder="solar:widget-4-bold">
                    </div>
                </div>
                <div class="ssoadm-grid-2" style="margin-bottom:10px;">
                    <div class="ssoadm-field"><label>Deskripsi Singkat</label><input type="text" id="pExtDesc" class="ssoadm-input"></div>
                    <div class="ssoadm-field"><label>Urutan Tampil</label><input type="number" id="pExtSort" class="ssoadm-input" value="10"></div>
                </div>
                <label style="display:inline-flex;align-items:center;gap:8px;font-size:13px;font-weight:700;cursor:pointer;">
                    <input type="checkbox" id="pExtActive" checked> Tampilkan di Beranda
                </label>
            </div>
            <div class="ilm-modal-footer">
                <button type="button" class="ilm-modal-btn ilm-modal-btn--ghost" onclick="closeIlmunaModal('modalPortalExtApp')">Batal</button>
                <button type="submit" class="ilm-modal-btn ilm-modal-btn--primary"><iconify-icon icon="solar:diskette-bold"></iconify-icon><span>Simpan Layanan</span></button>
            </div>
        </form>
    </div>
</div>

<script>
(function () {
    var ENDPOINT         = <?= json_encode(BASE_URL . '/dashboard/admin/index.php?api=1') ?>;
    var CSRF             = <?= json_encode($csrfAdmin) ?>;
    var CURRENT_ADMIN_ID = <?= (int)getCurrentUser()['id'] ?>;
    var INITIAL_ADMIN_DATA = <?= json_encode(margo_admin_build_bootstrap_data($pdo), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

    var STATE = {
        data: INITIAL_ADMIN_DATA,
        selectedClientId: 0,
        showPriv: false,
        clientFilter: 'all',
        sessFilter: 'active',
        userFilter: 'all',
        selectedUserIds: [],
        portalSub: 'agendas'
    };

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c];
        });
    }

    async function apiPost(action, payload) {
        var fd = new FormData();
        fd.append('api_action', action);
        fd.append('csrf_token', CSRF);
        if (payload) {
            Object.keys(payload).forEach(function (k) {
                if (Array.isArray(payload[k])) {
                    payload[k].forEach(function (v) { fd.append(k + '[]', v); });
                } else {
                    fd.append(k, payload[k]);
                }
            });
        }
        var r = await fetch(ENDPOINT, {
            method: 'POST',
            body: fd,
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
        });
        return await r.json();
    }

    function activateTab(tab) {
        document.querySelectorAll('#ssoAdminTabs .curriculum-tab-btn').forEach(function (b) {
            b.classList.toggle('is-active', b.getAttribute('data-tab') === tab);
        });
        document.querySelectorAll('.ssoadm-pane').forEach(function (p) {
            p.classList.toggle('is-active', p.id === 'ssoPane_' + tab);
        });
        document.querySelectorAll('.margo-nav-link[data-sidebar-route]').forEach(function (a) {
            a.classList.toggle('is-active', a.getAttribute('data-sidebar-route') === tab);
        });
    }

    function populateClientModal(c) {
        if (!c) return;
        document.getElementById('activeClientHeading').textContent = c.client_name + ' — Kredensial Zero-Trust';
        document.getElementById('lblClientSlug').textContent = c.client_slug;
        document.getElementById('lblClientSigMode').textContent = c.sig_mode;
        document.getElementById('txtClientPubKey').textContent = c.public_key;
        document.getElementById('txtClientPrivKey').textContent = STATE.showPriv ? c.private_key : c.private_key_masked;
        document.getElementById('edClientId').value = c.id;
        document.getElementById('edClientName').value = c.client_name;
        document.getElementById('edClientSlug').value = c.client_slug;
        document.getElementById('edAppUrl').value = c.app_url;
        document.getElementById('edAppIcon').value = c.app_icon;
        document.getElementById('edRemoveAppLogo').value = '0';
        var logoFileInp = document.getElementById('edAppLogoFile');
        if (logoFileInp) logoFileInp.value = '';
        var prevBox = document.getElementById('edAppLogoPreview');
        var btnClearLogo = document.getElementById('btnEdClearLogo');
        if (prevBox) {
            if (c.app_logo_url) {
                prevBox.innerHTML = '<img src="' + esc(c.app_logo_url) + '" alt="" style="width:100%;height:100%;object-fit:contain;border-radius:10px;background:#FFF;">';
                if (btnClearLogo) btnClearLogo.style.display = '';
            } else {
                prevBox.innerHTML = '<iconify-icon icon="' + esc(c.app_icon || 'solar:widget-5-bold') + '" width="20"></iconify-icon>';
                if (btnClearLogo) btnClearLogo.style.display = 'none';
            }
        }
        document.getElementById('edSigMode').value = c.sig_mode;
        document.getElementById('edRateLimit').value = c.rate_limit_per_min;
        document.getElementById('edAllowedDomains').value = c.allowed_domains;
        document.getElementById('edAllowedIps').value = c.allowed_ips;
        document.getElementById('edLogoutWebhook').value = c.logout_webhook_url;
        document.getElementById('edAllowedRoles').value = c.allowed_roles;
        var allowedRolesArr = String(c.allowed_roles || '').toLowerCase().split(/[\s,]+/).filter(Boolean);
        document.querySelectorAll('input[name="edClientRolePerm"]').forEach(function (cb) {
            cb.checked = allowedRolesArr.length === 0 || allowedRolesArr.indexOf(cb.value) !== -1;
        });
        document.getElementById('edRedirectUris').value = c.redirect_uris;
        document.getElementById('edAllowedScopes').value = c.allowed_scopes;
        document.getElementById('edIsActive').checked = Number(c.is_active) === 1;
        document.getElementById('edRsaStatus').textContent = c.has_rsa_public
            ? ('RSA Public PEM Aktif · ' + c.rsa_fingerprint.slice(0, 12) + '…')
            : 'RSA Public PEM: Belum diatur';
        document.getElementById('edClientStats').textContent =
            'Total request API: ' + c.request_count + ' · Terakhir dipakai: ' + (c.last_used_at || 'Belum pernah') + (c.last_ip ? ' (' + c.last_ip + ')' : '');
    }

    function renderClients() {
        var list = (STATE.data && STATE.data.clients) || [];
        var cntEl = document.getElementById('cntTabClients');
        if (cntEl) cntEl.textContent = list.length;

        var searchEl = document.getElementById('clientSearchInput');
        var q = searchEl ? (searchEl.value || '').toLowerCase().trim() : '';
        var filtered = list.filter(function (c) {
            if (STATE.clientFilter === 'active' && Number(c.is_active) !== 1) return false;
            if (STATE.clientFilter === 'inactive' && Number(c.is_active) === 1) return false;
            if (q !== '') {
                var hay = ((c.client_name || '') + ' ' + (c.client_slug || '') + ' ' + (c.app_url || '') + ' ' + (c.public_key || '') + ' ' + (c.allowed_domains || '')).toLowerCase();
                if (hay.indexOf(q) === -1) return false;
            }
            return true;
        });

        var tb = document.getElementById('clientsTableBody');
        if (tb) {
            if (!filtered.length) {
                tb.innerHTML = '<tr><td colspan="6" style="text-align:center;padding:24px;color:#64748B;">Tidak ada aplikasi klien SSO ditemukan.</td></tr>';
            } else {
                tb.innerHTML = filtered.map(function (c) {
                    var stBadge = Number(c.is_active) === 1
                        ? '<span class="ssoadm-badge ssoadm-badge--ok">Aktif</span>'
                        : '<span class="ssoadm-badge ssoadm-badge--err">Nonaktif</span>';
                    var rsaInfo = c.has_rsa_public
                        ? ' <span class="ssoadm-badge ssoadm-badge--teal">RSA-2048</span>'
                        : '';
                    var iconBoxInner = c.app_logo_url
                        ? '<img src="' + esc(c.app_logo_url) + '" alt="" style="width:100%;height:100%;object-fit:contain;border-radius:9px;background:#FFF;">'
                        : '<iconify-icon icon="' + esc(c.app_icon || 'solar:widget-5-bold') + '" width="18"></iconify-icon>';
                    var rolePills = String(c.allowed_roles || '').split(/[\s,]+/).filter(Boolean).map(function (rc) {
                        return '<span class="ssoadm-badge ssoadm-badge--teal" style="font-size:10px;padding:1px 6px;">' + esc(rc) + '</span>';
                    }).join(' ');
                    return '<tr id="adm-client-' + c.id + '">'
                        + '<td data-label="Aplikasi Klien">'
                        + '<div style="display:flex;align-items:center;gap:10px;">'
                        + '<div style="width:40px;height:40px;border-radius:10px;background:#FFFFFF;color:#0D7A87;padding:5px;display:flex;align-items:center;justify-content:center;flex-shrink:0;overflow:hidden;border:1.5px solid #E2E8F0;">' + iconBoxInner + '</div>'
                        + '<div><strong>' + esc(c.client_name) + '</strong> <span class="ssoadm-badge ssoadm-badge--navy">' + esc(c.client_slug) + '</span>'
                        + '<div style="font-size:11.5px;color:#64748B;">' + esc(c.app_url) + '</div>'
                        + (rolePills ? '<div style="margin-top:3px;display:flex;gap:4px;flex-wrap:wrap;">' + rolePills + '</div>' : '')
                        + '</div>'
                        + '</div></td>'
                        + '<td data-label="Public Key">'
                        + '<div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;">'
                        + '<code style="font-family:var(--font-mono);font-size:11.5px;background:#F1F5F9;color:#0F172A;padding:4px 8px;border-radius:7px;">' + esc(c.public_key) + '</code>'
                        + '<button type="button" class="ssoadm-btn ssoadm-btn--ghost ssoadm-btn--sm" style="padding:4px 8px;min-height:28px;" onclick="MargoUI.copyText(\'' + esc(c.public_key) + '\',\'Public Key\')" title="Salin Public Key"><iconify-icon icon="solar:copy-bold"></iconify-icon></button>'
                        + '</div></td>'
                        + '<td data-label="Mode & Limit"><span class="ssoadm-badge ssoadm-badge--warn">' + esc(c.sig_mode) + '</span>' + rsaInfo + '<div style="font-size:11px;color:#64748B;margin-top:3px;">Limit: ' + esc(c.rate_limit_per_min) + ' req/menit</div></td>'
                        + '<td data-label="Trafik"><strong>' + esc(c.request_count) + ' req</strong><div style="font-size:11px;color:#64748B;">' + esc(c.last_used_at || 'Belum pernah') + (c.last_ip ? ' (' + esc(c.last_ip) + ')' : '') + '</div></td>'
                        + '<td data-label="Status">' + stBadge + '</td>'
                        + '<td data-label="Aksi" style="text-align:right;">'
                        + '<div style="display:flex;gap:6px;justify-content:flex-end;flex-wrap:wrap;">'
                        + '<button type="button" class="ssoadm-btn ssoadm-btn--primary ssoadm-btn--sm" onclick="SSOADM.openManageClient(' + c.id + ')"><iconify-icon icon="solar:key-minimalistic-square-2-bold"></iconify-icon> Kelola &amp; Kunci</button>'
                        + '<button type="button" class="ssoadm-btn ssoadm-btn--ghost ssoadm-btn--sm" onclick="SSOADM.testSigned(' + c.id + ')" title="Uji Koneksi Signed Token"><iconify-icon icon="solar:shield-check-bold"></iconify-icon> Uji</button>'
                        + '</div></td>'
                        + '</tr>';
                }).join('');
            }
        }

        if (STATE.selectedClientId) {
            var activeObj = list.find(function (x) { return x.id === STATE.selectedClientId; });
            if (activeObj) populateClientModal(activeObj);
        }
    }

    function renderSessions() {
        var list = (STATE.data && STATE.data.sessions) || [];
        var cntEl = document.getElementById('cntTabSessions');
        if (cntEl) cntEl.textContent = STATE.data ? STATE.data.active_session_count : 0;
        var q = (document.getElementById('sessSearchInput').value || '').toLowerCase().trim();
        var filtered = list.filter(function (s) {
            if (STATE.sessFilter === 'active' && !s.is_active_now) return false;
            if (STATE.sessFilter === 'revoked' && s.is_active_now) return false;
            if (q !== '') {
                var hay = ((s.full_name || '') + ' ' + (s.username || '') + ' ' + (s.ip_address || '') + ' ' + (s.user_agent || '')).toLowerCase();
                if (hay.indexOf(q) === -1) return false;
            }
            return true;
        });

        var tb = document.getElementById('sessTableBody');
        if (!filtered.length) {
            tb.innerHTML = '<tr><td colspan="6" style="text-align:center;padding:24px;color:#64748B;">Tidak ada data sesi sesuai filter.</td></tr>';
            return;
        }
        tb.innerHTML = filtered.map(function (s) {
            var stBadge = s.is_active_now
                ? '<span class="ssoadm-badge ssoadm-badge--ok">Aktif</span>'
                : '<span class="ssoadm-badge ssoadm-badge--err">Dicabut / Expired</span>';
            var mBadge = s.login_method === 'google_oauth'
                ? '<span class="ssoadm-badge ssoadm-badge--navy">Google OAuth</span>'
                : '<span class="ssoadm-badge ssoadm-badge--teal">Password</span>';
            var actions = s.is_active_now
                ? '<div style="display:flex;gap:6px;justify-content:flex-end;flex-wrap:wrap;">'
                  + '<button type="button" class="ssoadm-btn ssoadm-btn--danger ssoadm-btn--sm" onclick="SSOADM.revokeSession(' + s.id + ')"><iconify-icon icon="solar:logout-2-bold"></iconify-icon> Paksa Keluar</button>'
                  + '<button type="button" class="ssoadm-btn ssoadm-btn--ghost ssoadm-btn--sm" onclick="SSOADM.revokeUserAll(' + s.user_id + ',\'' + esc(s.username || ('#' + s.user_id)) + '\')">Cabut Semua</button>'
                  + '</div>'
                : '<span style="font-size:11.5px;color:#94A3B8;">' + esc(s.revoked_at || s.expires_at) + '</span>';
            return '<tr id="adm-sess-' + s.id + '">'
                + '<td data-label="Pengguna"><strong>' + esc(s.full_name || s.username || ('User #' + s.user_id)) + '</strong><div style="font-size:11.5px;color:#64748B;">@' + esc(s.username || '-') + '</div></td>'
                + '<td data-label="Metode">' + mBadge + ' <span class="ssoadm-badge ssoadm-badge--warn">' + esc(s.active_role || '-') + '</span></td>'
                + '<td data-label="IP & Perangkat"><strong>' + esc(s.ip_address) + '</strong><div style="font-size:11px;color:#64748B;max-width:260px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">' + esc(s.user_agent || '-') + '</div></td>'
                + '<td data-label="Aktivitas">' + esc(s.last_activity_at) + '</td>'
                + '<td data-label="Status">' + stBadge + '</td>'
                + '<td data-label="Aksi" style="text-align:right;">' + actions + '</td>'
                + '</tr>';
        }).join('');
    }

    function renderUsers() {
        var list = (STATE.data && STATE.data.users) || [];
        var orphans = (STATE.data && STATE.data.orphan_gurus) || [];
        var cntEl = document.getElementById('cntTabUsers');
        if (cntEl) cntEl.textContent = list.length;
        var orphanEl = document.getElementById('lblOrphanGuru');
        if (orphanEl) {
            orphanEl.textContent = orphans.length > 0
                ? ('Perbaiki ' + orphans.length + ' Orphan Guru')
                : 'Guru Tersinkron (0 Orphan)';
        }

        var searchInp = document.getElementById('userSearchInput');
        var q = ((searchInp && searchInp.value) || '').toLowerCase().trim();
        var filtered = list.filter(function (u) {
            var codes = (u.roles || []).map(function (r) { return r.code; });
            if (STATE.userFilter === 'google' && !u.email_google) return false;
            if (['admin', 'guru', 'siswa'].indexOf(STATE.userFilter) !== -1 && codes.indexOf(STATE.userFilter) === -1) return false;
            if (q !== '') {
                var hay = ((u.full_name || '') + ' ' + (u.username || '') + ' ' + (u.email || '') + ' ' + (u.email_google || '') + ' ' + (u.nisn_nip || '')).toLowerCase();
                if (hay.indexOf(q) === -1) return false;
            }
            return true;
        });

        var tb = document.getElementById('usersTableBody');
        if (!tb) return;

        // Sinkronkan STATE.selectedUserIds hanya untuk ID yang masih ada di list
        var validIdMap = {};
        list.forEach(function (x) { validIdMap[Number(x.id)] = true; });
        STATE.selectedUserIds = (STATE.selectedUserIds || []).filter(function (id) { return !!validIdMap[Number(id)]; });

        if (!filtered.length) {
            tb.innerHTML = '<tr><td colspan="6" style="text-align:center;padding:24px;color:#64748B;">Tidak ada akun ditemukan.</td></tr>';
            syncUsersBulkBar(filtered);
            return;
        }
        var allClients = (STATE.data && STATE.data.clients) || [];
        function userCanAccessClientJS(u, c) {
            var uRoleCodes = (u.roles || []).map(function (r) { return String(r.code || '').toLowerCase(); });
            var cRoles = String(c.allowed_roles || '').toLowerCase().split(/[\s,]+/).filter(Boolean);
            var roleOk = cRoles.length === 0 || uRoleCodes.some(function (rc) { return cRoles.indexOf(rc) !== -1; });
            if (!roleOk) return false;
            var rawApps = String(u.allowed_apps || '').trim();
            if (rawApps !== '' && rawApps !== '*') {
                if (rawApps === '__none__') return false;
                var appList = rawApps.toLowerCase().split(/[\s,]+/).filter(Boolean);
                if (appList.indexOf(String(c.client_slug || '').toLowerCase()) === -1 && appList.indexOf(String(c.id)) === -1) {
                    return false;
                }
            }
            return true;
        }

        tb.innerHTML = filtered.map(function (u) {
            var isSelf = Number(u.id) === Number(CURRENT_ADMIN_ID);
            var isChecked = STATE.selectedUserIds.indexOf(Number(u.id)) !== -1;
            var roleBadges = (u.roles || []).map(function (r) {
                var cls = r.code === 'admin' ? 'ssoadm-badge--navy' : (r.code === 'guru' ? 'ssoadm-badge--teal' : 'ssoadm-badge--warn');
                return '<span class="ssoadm-badge ' + cls + '">' + esc(r.code) + (r.is_primary ? ' ★' : '') + '</span>';
            }).join(' ');
            var permittedClients = allClients.filter(function (c) { return Number(c.is_active) === 1 && userCanAccessClientJS(u, c); });
            var appPermBadges = permittedClients.length
                ? permittedClients.map(function (c) {
                    var miniIcon = c.app_logo_url
                        ? '<img src="' + esc(c.app_logo_url) + '" alt="" style="width:12px;height:12px;object-fit:contain;border-radius:3px;">'
                        : '<iconify-icon icon="' + esc(c.app_icon || 'solar:widget-5-bold') + '" width="12"></iconify-icon>';
                    return '<span class="ssoadm-badge ssoadm-badge--ok" style="font-size:10.5px;display:inline-flex;align-items:center;gap:4px;">' + miniIcon + ' ' + esc(c.client_name) + '</span>';
                }).join(' ')
                : '<span class="ssoadm-badge ssoadm-badge--err" style="font-size:10.5px;">Tidak Ada Akses Apk</span>';
            var gBadge = u.email_google
                ? '<span class="ssoadm-badge ssoadm-badge--ok"><iconify-icon icon="solar:check-circle-bold"></iconify-icon> ' + esc(u.email_google) + '</span> '
                  + '<button type="button" class="ssoadm-btn ssoadm-btn--ghost ssoadm-btn--sm" style="padding:3px 7px;min-height:26px;" onclick="SSOADM.adminUnlinkGoogle(' + u.id + ',\'' + esc(u.username) + '\')" title="Putus Google"><iconify-icon icon="solar:link-broken-bold"></iconify-icon></button>'
                : '<span style="font-size:12px;color:#94A3B8;">Belum tertaut</span>';
            var delBtn = isSelf
                ? '<button type="button" class="ssoadm-btn ssoadm-btn--ghost ssoadm-btn--sm" disabled title="Akun aktif Anda tidak dapat dihapus" style="opacity:0.45;cursor:not-allowed;"><iconify-icon icon="solar:trash-bin-minimalistic-bold"></iconify-icon></button>'
                : '<button type="button" class="ssoadm-btn ssoadm-btn--danger ssoadm-btn--sm" onclick="SSOADM.deleteUser(' + u.id + ',\'' + esc(u.username) + '\')" title="Hapus Akun SSO"><iconify-icon icon="solar:trash-bin-minimalistic-bold"></iconify-icon></button>';
            var selfTag = isSelf ? ' <span class="ssoadm-badge ssoadm-badge--teal" style="font-size:10px;">Anda</span>' : '';
            return '<tr id="adm-user-' + u.id + '">'
                + '<td data-label="Pilih" style="text-align:center;">'
                + '<input type="checkbox" class="user-row-chk" data-uid="' + u.id + '" ' + (isChecked ? 'checked' : '') + ' onchange="SSOADM.toggleUserSelect(' + u.id + ', this.checked)" aria-label="Pilih @' + esc(u.username) + '" style="width:16px;height:16px;accent-color:#0D7A87;cursor:pointer;">'
                + '</td>'
                + '<td data-label="Akun"><strong>' + esc(u.full_name) + '</strong>' + selfTag + '<div style="font-size:11.5px;color:#64748B;">@' + esc(u.username) + (u.email ? ' · ' + esc(u.email) : '') + '</div></td>'
                + '<td data-label="Peran & Permission"><div>' + (roleBadges || '<span class="ssoadm-badge ssoadm-badge--err">Tanpa Role</span>') + '</div><div style="margin-top:4px;display:flex;gap:4px;flex-wrap:wrap;">' + appPermBadges + '</div></td>'
                + '<td data-label="Google SSO">' + gBadge + '</td>'
                + '<td data-label="Login Terakhir">' + esc(u.last_login || 'Belum pernah') + '</td>'
                + '<td data-label="Kelola" style="text-align:right;"><div style="display:flex;gap:6px;justify-content:flex-end;flex-wrap:wrap;">'
                + '<button type="button" class="ssoadm-btn ssoadm-btn--primary ssoadm-btn--sm" onclick="SSOADM.openRolesModal(' + u.id + ')"><iconify-icon icon="solar:users-group-two-rounded-bold"></iconify-icon> Role &amp; Akses Apk</button>'
                + '<button type="button" class="ssoadm-btn ssoadm-btn--ghost ssoadm-btn--sm" onclick="SSOADM.openResetPassModal(' + u.id + ')"><iconify-icon icon="solar:key-bold"></iconify-icon> Sandi</button>'
                + delBtn
                + '</div></td>'
                + '</tr>';
        }).join('');

        syncUsersBulkBar(filtered);
    }

    function syncUsersBulkBar(visibleFiltered) {
        var bar = document.getElementById('usersBulkBar');
        var lbl = document.getElementById('lblBulkUsersCount');
        var chkAll = document.getElementById('chkAllUsers');
        var cnt = (STATE.selectedUserIds || []).length;
        if (lbl) lbl.textContent = cnt;
        if (bar) bar.style.display = cnt > 0 ? '' : 'none';
        if (chkAll) {
            var vis = Array.isArray(visibleFiltered) ? visibleFiltered : [];
            if (!vis.length) {
                chkAll.checked = false;
                chkAll.indeterminate = false;
            } else {
                var visChecked = vis.filter(function (u) { return STATE.selectedUserIds.indexOf(Number(u.id)) !== -1; }).length;
                chkAll.checked = (visChecked === vis.length);
                chkAll.indeterminate = (visChecked > 0 && visChecked < vis.length);
            }
        }
    }

    function renderJwksAndLogs() {
        var keys = (STATE.data && STATE.data.jwks_keys) || [];
        var logs = (STATE.data && STATE.data.audit_logs) || [];
        var cntEl = document.getElementById('cntTabLogs');
        if (cntEl) cntEl.textContent = logs.length;
        var goa = (STATE.data && STATE.data.google_oauth) || {};
        var gCidEl = document.getElementById('cfgGoogleClientId');
        if (gCidEl && document.activeElement !== gCidEl) gCidEl.value = goa.client_id || '';
        var gStEl = document.getElementById('lblGoogleOAuthStatus');
        if (gStEl) {
            gStEl.textContent = goa.client_id
                ? (goa.has_client_secret ? 'Google OAuth Aktif (Full)' : 'Google OAuth Aktif (Client ID)')
                : 'Belum Diatur';
        }
        var gHint = document.getElementById('lblGoogleSecretHint');
        if (gHint) gHint.textContent = goa.has_client_secret ? ('(Tersimpan: ' + goa.client_secret_masked + ')') : '(Belum tersimpan)';
        var gOrig = document.getElementById('txtGoogleOrigin');
        if (gOrig) gOrig.textContent = goa.origin || '';
        var gRed = document.getElementById('txtGoogleRedirect');
        if (gRed) gRed.textContent = goa.redirect_uri || '';

        var la = (STATE.data && STATE.data.learn_api) || {};
        var lEpEl = document.getElementById('cfgLearnEndpoint');
        if (lEpEl && document.activeElement !== lEpEl) lEpEl.value = la.endpoint_url || 'https://learn.ilmuna.my.id/api/sso-broadcast.php';
        var lWsEl = document.getElementById('cfgLearnWsUrl');
        if (lWsEl && document.activeElement !== lWsEl) lWsEl.value = la.ws_url || 'wss://learn.ilmuna.my.id/ws/sso';
        var lPubEl = document.getElementById('cfgLearnPubKey');
        if (lPubEl && document.activeElement !== lPubEl) lPubEl.value = la.public_key || '';
        var lStEl = document.getElementById('lblLearnApiStatus');
        if (lStEl) lStEl.textContent = la.configured ? ('Terkonfigurasi (' + (la.sig_mode || 'HMAC-SHA256') + ')') : 'Belum Diatur';
        var lHint = document.getElementById('lblLearnPrivHint');
        if (lHint) lHint.textContent = la.has_private_key ? ('(Tersimpan: ' + la.private_key_masked + ')') : '(Belum tersimpan)';

        document.getElementById('txtJwksUrl').textContent = (STATE.data && STATE.data.endpoints && STATE.data.endpoints.jwks) || '';

        var jwksListEl = document.getElementById('jwksKeysList');
        if (jwksListEl) {
            jwksListEl.innerHTML = keys.map(function (k) {
                var badge = Number(k.is_primary) === 1
                    ? '<span class="ssoadm-badge ssoadm-badge--ok">PRIMARY SIGNING</span>'
                    : (Number(k.is_active) === 1 ? '<span class="ssoadm-badge ssoadm-badge--warn">GRACE PERIOD</span>' : '<span class="ssoadm-badge ssoadm-badge--err">NONAKTIF</span>');
                return '<div style="padding:10px 12px;border:1px solid #E2E8F0;border-radius:11px;background:#F8FAFC;display:flex;justify-content:space-between;align-items:center;gap:8px;flex-wrap:wrap;">'
                    + '<div><strong style="font-family:var(--font-mono);font-size:12.5px;color:#0F172A;">' + esc(k.kid) + '</strong>'
                    + '<div style="font-size:11px;color:#64748B;">SHA256: ' + esc((k.fingerprint || '').slice(0, 24)) + '… · Dibuat: ' + esc(k.created_at) + '</div></div>'
                    + badge + '</div>';
            }).join('');
        }
    }

    function renderPortal() {
        var agendas = (STATE.data && STATE.data.portal_agendas) || [];
        var anns    = (STATE.data && STATE.data.portal_announcements) || [];
        var exts    = (STATE.data && STATE.data.portal_external_apps) || [];
        var cntEl   = document.getElementById('cntTabPortal');
        if (cntEl) cntEl.textContent = agendas.length + anns.length + exts.length;

        var dbSt = STATE.data && STATE.data.agenda_db_status;
        var dbLbl = document.getElementById('portalAgendaDbLabel');
        if (dbLbl && dbSt) {
            dbLbl.textContent = 'Database Khusus: ' + dbSt.db_name
                + ' · Trigger Kaldik: ' + (dbSt.kaldik_triggers_ok ? 'AKTIF (Real-Time)' : 'Mode Backfill')
                + ' · Total: ' + (dbSt.counts ? dbSt.counts.total : agendas.length) + ' agenda (' + (dbSt.counts ? dbSt.counts.kaldik : 0) + ' Kaldik)';
        }

        var tbAg = document.getElementById('portalAgendasBody');
        if (tbAg) {
            tbAg.innerHTML = agendas.length ? agendas.map(function (a) {
                var isKaldik = (a.source === 'kaldik' || Number(a.is_locked) === 1);
                var cHex = (a.color_hex && /^#[0-9A-Fa-f]{6}$/.test(a.color_hex)) ? a.color_hex : '#0D7A87';
                var colorDot = '<span style="display:inline-block;width:9px;height:9px;border-radius:50%;background:' + cHex + ';margin-right:5px;vertical-align:middle;"></span>';
                var stB = Number(a.is_active) === 1 ? '<span class="ssoadm-badge ssoadm-badge--ok">Aktif</span>' : '<span class="ssoadm-badge ssoadm-badge--err">Draft</span>';
                var srcBadge = isKaldik
                    ? '<span class="ssoadm-badge" style="background:' + cHex + '1F;color:' + cHex + ';border:1px solid ' + cHex + '55;"><iconify-icon icon="solar:lock-keyhole-bold"></iconify-icon> KALDIK · ' + esc(a.status_hari || 'Terkunci') + '</span>'
                    : '<span class="ssoadm-badge ssoadm-badge--teal">' + esc(a.visibility_label || 'Semua Orang') + '</span> <span class="ssoadm-badge" style="background:' + cHex + '1F;color:' + cHex + ';border:1px solid ' + cHex + '55;">' + colorDot + esc(a.category || 'akademik') + '</span>';
                var actBtns = isKaldik
                    ? '<span style="font-size:11.5px;font-weight:700;color:#64748B;display:inline-flex;align-items:center;gap:4px;"><iconify-icon icon="solar:lock-keyhole-bold"></iconify-icon> Read-Only (Kaldik)</span>'
                    : '<div style="display:flex;gap:6px;justify-content:flex-end;">'
                      + '<button type="button" class="ssoadm-btn ssoadm-btn--ghost ssoadm-btn--sm" onclick="SSOADM.editPortalAgenda(' + a.id + ')"><iconify-icon icon="solar:pen-bold"></iconify-icon> Edit</button>'
                      + '<button type="button" class="ssoadm-btn ssoadm-btn--danger ssoadm-btn--sm" onclick="SSOADM.deletePortalAgenda(' + a.id + ')"><iconify-icon icon="solar:trash-bin-minimalistic-bold"></iconify-icon></button>'
                      + '</div>';
                return '<tr id="p-ag-' + a.id + '">'
                    + '<td data-label="Tanggal"><strong>' + esc(a.start_date) + '</strong>' + (a.end_date && a.end_date !== a.start_date ? '<div style="font-size:11px;color:#64748B;">s/d ' + esc(a.end_date) + '</div>' : '') + (a.start_time ? '<div style="font-size:11px;color:#0D7A87;">' + esc(a.start_time) + ' WIB</div>' : '') + '</td>'
                    + '<td data-label="Agenda"><strong>' + esc(a.title) + '</strong>' + (a.location ? '<div style="font-size:11.5px;color:#64748B;">' + esc(a.location) + '</div>' : '') + '</td>'
                    + '<td data-label="Audiens">' + srcBadge + '</td>'
                    + '<td data-label="Status">' + stB + '</td>'
                    + '<td data-label="Aksi" style="text-align:right;">' + actBtns + '</td></tr>';
            }).join('') : '<tr><td colspan="5" style="text-align:center;padding:24px;color:#64748B;">Belum ada agenda.</td></tr>';
        }

        var tbAn = document.getElementById('portalAnnsBody');
        if (tbAn) {
            tbAn.innerHTML = anns.length ? anns.map(function (n) {
                var stB = Number(n.is_active) === 1 ? '<span class="ssoadm-badge ssoadm-badge--ok">Aktif</span>' : '<span class="ssoadm-badge ssoadm-badge--err">Nonaktif</span>';
                return '<tr id="p-an-' + n.id + '">'
                    + '<td data-label="Label"><span class="ssoadm-badge ssoadm-badge--warn">' + esc(n.badge_label || 'INFO') + '</span></td>'
                    + '<td data-label="Pengumuman"><strong>' + esc(n.title) + '</strong>' + (n.excerpt ? '<div style="font-size:11.5px;color:#64748B;max-width:340px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">' + esc(n.excerpt) + '</div>' : '') + '</td>'
                    + '<td data-label="Terbit" style="font-size:12px;">' + esc(n.published_at) + '</td>'
                    + '<td data-label="Status">' + stB + '</td>'
                    + '<td data-label="Aksi" style="text-align:right;"><div style="display:flex;gap:6px;justify-content:flex-end;">'
                    + '<button type="button" class="ssoadm-btn ssoadm-btn--ghost ssoadm-btn--sm" onclick="SSOADM.editPortalAnn(' + n.id + ')"><iconify-icon icon="solar:pen-bold"></iconify-icon> Edit</button>'
                    + '<button type="button" class="ssoadm-btn ssoadm-btn--danger ssoadm-btn--sm" onclick="SSOADM.deletePortalAnn(' + n.id + ')"><iconify-icon icon="solar:trash-bin-minimalistic-bold"></iconify-icon></button>'
                    + '</div></td></tr>';
            }).join('') : '<tr><td colspan="5" style="text-align:center;padding:24px;color:#64748B;">Belum ada pengumuman.</td></tr>';
        }

        var tbEx = document.getElementById('portalExtAppsBody');
        if (tbEx) {
            tbEx.innerHTML = exts.length ? exts.map(function (e) {
                var stB = Number(e.is_active) === 1 ? '<span class="ssoadm-badge ssoadm-badge--ok">Aktif</span>' : '<span class="ssoadm-badge ssoadm-badge--err">Nonaktif</span>';
                var exIconHtml = e.app_logo_url
                    ? '<img src="' + esc(e.app_logo_url) + '" alt="" style="width:28px;height:28px;object-fit:contain;border-radius:7px;border:1px solid #E2E8F0;background:#FFF;">'
                    : '<iconify-icon icon="' + esc(e.app_icon || 'solar:widget-4-bold') + '" width="18" style="color:#0D7A87;"></iconify-icon>';
                return '<tr id="p-ex-' + e.id + '">'
                    + '<td data-label="Layanan"><div style="display:flex;align-items:center;gap:8px;">' + exIconHtml + '<div><strong>' + esc(e.app_name) + '</strong><div style="font-size:11.5px;color:#64748B;">' + esc(e.description || '') + '</div></div></div></td>'
                    + '<td data-label="URL" style="font-family:var(--font-mono);font-size:11.5px;">' + esc(e.app_url) + '</td>'
                    + '<td data-label="Badge"><span class="ssoadm-badge ssoadm-badge--navy">' + esc(e.badge_label || 'EKOSISTEM') + '</span></td>'
                    + '<td data-label="Status">' + stB + '</td>'
                    + '<td data-label="Aksi" style="text-align:right;"><div style="display:flex;gap:6px;justify-content:flex-end;">'
                    + '<button type="button" class="ssoadm-btn ssoadm-btn--ghost ssoadm-btn--sm" onclick="SSOADM.editPortalExtApp(' + e.id + ')"><iconify-icon icon="solar:pen-bold"></iconify-icon> Edit</button>'
                    + '<button type="button" class="ssoadm-btn ssoadm-btn--danger ssoadm-btn--sm" onclick="SSOADM.deletePortalExtApp(' + e.id + ')"><iconify-icon icon="solar:trash-bin-minimalistic-bold"></iconify-icon></button>'
                    + '</div></td></tr>';
            }).join('') : '<tr><td colspan="5" style="text-align:center;padding:24px;color:#64748B;">Belum ada layanan non-SSO.</td></tr>';
        }
    }

    window.SSOADM = {
        load: async function (useInitial) {
            if (useInitial && STATE.data) {
                renderClients();
                renderSessions();
                renderUsers();
                renderJwksAndLogs();
                renderPortal();
                return;
            }
            try {
                var r = await fetch(ENDPOINT + '&api_action=bootstrap', {
                    credentials: 'same-origin',
                    headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
                });
                var res = await r.json();
                if (!res || !res.success) { Ilmuna.error('Gagal', (res && res.message) || 'Gagal memuat data SSO.'); return; }
                STATE.data = res.data;
                renderClients();
                renderSessions();
                renderUsers();
                renderJwksAndLogs();
                renderPortal();
            } catch (e) {
                if (!STATE.data) {
                    Ilmuna.error('Kesalahan', 'Gagal menghubungi backend Admin Margo One.');
                }
            }
        },
        openManageClient: function (id) {
            STATE.selectedClientId = id;
            STATE.showPriv = false;
            document.getElementById('rsaOnceBox').style.display = 'none';
            document.getElementById('ssoTestBanner').style.display = 'none';
            var list = (STATE.data && STATE.data.clients) || [];
            var c = list.find(function (x) { return x.id === id; });
            if (!c) return;
            populateClientModal(c);
            openIlmunaModal('modalManageClient');
        },
        selectClient: function (id) {
            SSOADM.openManageClient(id);
        },
        togglePriv: function () {
            STATE.showPriv = !STATE.showPriv;
            var list = (STATE.data && STATE.data.clients) || [];
            var c = list.find(function (x) { return x.id === STATE.selectedClientId; });
            if (c) populateClientModal(c);
        },
        copyPriv: function () {
            var list = (STATE.data && STATE.data.clients) || [];
            var c = list.find(function (x) { return x.id === STATE.selectedClientId; });
            if (c) MargoUI.copyText(c.private_key, 'Private Key');
        },
        syncEdAllowedRoles: function () {
            var checked = Array.from(document.querySelectorAll('input[name="edClientRolePerm"]:checked')).map(function (el) { return el.value; });
            document.getElementById('edAllowedRoles').value = checked.join(',');
        },
        previewEditClientLogo: function (input) {
            if (!input || !input.files || !input.files[0]) return;
            var file = input.files[0];
            var reader = new FileReader();
            reader.onload = function (ev) {
                var prevBox = document.getElementById('edAppLogoPreview');
                if (prevBox) prevBox.innerHTML = '<img src="' + esc(ev.target.result) + '" alt="" style="width:100%;height:100%;object-fit:contain;border-radius:10px;background:#FFF;">';
                document.getElementById('edRemoveAppLogo').value = '0';
                var btnClear = document.getElementById('btnEdClearLogo');
                if (btnClear) btnClear.style.display = '';
            };
            reader.readAsDataURL(file);
        },
        clearEditClientLogo: function () {
            document.getElementById('edRemoveAppLogo').value = '1';
            var logoFileInp = document.getElementById('edAppLogoFile');
            if (logoFileInp) logoFileInp.value = '';
            var prevBox = document.getElementById('edAppLogoPreview');
            var ic = document.getElementById('edAppIcon').value || 'solar:widget-5-bold';
            if (prevBox) prevBox.innerHTML = '<iconify-icon icon="' + esc(ic) + '" width="20"></iconify-icon>';
            var btnClear = document.getElementById('btnEdClearLogo');
            if (btnClear) btnClear.style.display = 'none';
        },
        saveClient: async function (e) {
            e.preventDefault();
            SSOADM.syncEdAllowedRoles();
            var payload = {
                id: document.getElementById('edClientId').value,
                client_name: document.getElementById('edClientName').value.trim(),
                client_slug: document.getElementById('edClientSlug').value.trim(),
                app_url: document.getElementById('edAppUrl').value.trim(),
                app_icon: document.getElementById('edAppIcon').value.trim(),
                remove_app_logo: document.getElementById('edRemoveAppLogo').value === '1' ? '1' : '',
                sig_mode: document.getElementById('edSigMode').value,
                rate_limit_per_min: document.getElementById('edRateLimit').value,
                allowed_domains: document.getElementById('edAllowedDomains').value.trim(),
                allowed_ips: document.getElementById('edAllowedIps').value.trim(),
                logout_webhook_url: document.getElementById('edLogoutWebhook').value.trim(),
                allowed_roles: document.getElementById('edAllowedRoles').value.trim(),
                redirect_uris: document.getElementById('edRedirectUris').value.trim(),
                allowed_scopes: document.getElementById('edAllowedScopes').value.trim(),
                is_active: document.getElementById('edIsActive').checked ? '1' : '0'
            };
            var logoFileInp = document.getElementById('edAppLogoFile');
            if (logoFileInp && logoFileInp.files && logoFileInp.files[0]) {
                payload.app_logo_file = logoFileInp.files[0];
            }
            var res = await apiPost('save_client', payload);
            if (res.success) { Ilmuna.success('Tersimpan', res.message); SSOADM.load(); }
            else Ilmuna.error('Gagal', res.message || 'Gagal menyimpan klien.');
        },
        openNewClientModal: function () {
            document.getElementById('formNewClient').reset();
            openIlmunaModal('modalNewClient');
        },
        createClient: async function (e) {
            e.preventDefault();
            var ncRoles = Array.from(document.querySelectorAll('input[name="ncClientRolePerm"]:checked')).map(function (el) { return el.value; }).join(',');
            var payload = {
                id: 0,
                client_name: document.getElementById('ncName').value.trim(),
                client_slug: document.getElementById('ncSlug').value.trim(),
                app_url: document.getElementById('ncAppUrl').value.trim(),
                app_icon: document.getElementById('ncIcon').value.trim(),
                allowed_domains: document.getElementById('ncDomains').value.trim(),
                logout_webhook_url: document.getElementById('ncWebhook').value.trim(),
                allowed_roles: ncRoles || 'admin,guru,ketua_kelas,siswa',
                redirect_uris: document.getElementById('ncRedirects').value.trim(),
                is_active: '1'
            };
            var ncLogoInp = document.getElementById('ncLogoFile');
            if (ncLogoInp && ncLogoInp.files && ncLogoInp.files[0]) {
                payload.app_logo_file = ncLogoInp.files[0];
            }
            var res = await apiPost('save_client', payload);
            if (res.success) {
                closeIlmunaModal('modalNewClient');
                if (res.data && res.data.id) STATE.selectedClientId = res.data.id;
                Ilmuna.success('Klien Dibuat', res.message);
                SSOADM.load();
            } else {
                Ilmuna.error('Gagal', res.message || 'Gagal menambah klien.');
            }
        },
        rotateClientKeys: async function () {
            var ok = await Ilmuna.confirm('warning', 'Rotasi Public & Private Key?', 'Kunci lama akan langsung tidak berlaku. Anda wajib menyalin kunci baru ke aplikasi klien.', 'Ya, Rotasi Kunci', 'Batal');
            if (!ok) return;
            var res = await apiPost('rotate_client_keys', { id: STATE.selectedClientId });
            if (res.success) { Ilmuna.success('Kunci Dirotasi', res.message); STATE.showPriv = true; SSOADM.load(); }
            else Ilmuna.error('Gagal', res.message || 'Gagal rotasi kunci.');
        },
        generateRsa: async function () {
            var ok = await Ilmuna.confirm('warning', 'Generate RSA-2048 PEM Baru?', 'RSA Private PEM hanya akan ditampilkan SATU KALI untuk disalin ke server aplikasi klien.', 'Generate RSA-2048', 'Batal');
            if (!ok) return;
            var res = await apiPost('generate_client_rsa', { id: STATE.selectedClientId });
            if (res.success && res.data) {
                document.getElementById('rsaPemOut').value = res.data.private_pem;
                document.getElementById('rsaOnceBox').style.display = 'block';
                Ilmuna.success('RSA-2048 Siap', res.message);
                SSOADM.load();
            } else {
                Ilmuna.error('Gagal', res.message || 'Gagal membangkitkan RSA-2048.');
            }
        },
        testSigned: async function (optId) {
            var targetId = optId || STATE.selectedClientId;
            var res = await apiPost('test_client_signed', { id: targetId });
            var b = document.getElementById('ssoTestBanner');
            if (b) {
                b.style.display = 'block';
                if (res.success) {
                    b.style.background = 'rgba(13,122,135,0.10)'; b.style.border = '1px solid rgba(13,122,135,0.30)'; b.style.color = '#0D7A87';
                    b.textContent = res.message;
                } else {
                    b.style.background = 'rgba(29,59,94,0.08)'; b.style.border = '1px solid rgba(29,59,94,0.25)'; b.style.color = '#1D3B5E';
                    b.textContent = res.message || 'Uji koneksi gagal.';
                }
            }
            if (res.success) {
                Ilmuna.success('Uji Koneksi Sukses', res.message);
            } else {
                Ilmuna.error('Uji Koneksi Gagal', res.message || 'Periksa konfigurasi klien.');
            }
        },
        revokeSession: async function (sid) {
            var ok = await Ilmuna.confirm('error', 'Paksa Keluar Sesi #' + sid + '?', 'Pengguna akan segera dikeluarkan dari seluruh aplikasi yang menggunakan sesi ini.', 'Ya, Paksa Keluar', 'Batal');
            if (!ok) return;
            var res = await apiPost('revoke_session', { session_id: sid });
            if (res.success) {
                var tr = document.getElementById('adm-sess-' + sid);
                if (tr) tr.classList.add('row-flash');
                Ilmuna.success('SLO Berhasil', res.message);
                SSOADM.load();
            } else Ilmuna.error('Gagal', res.message);
        },
        revokeUserAll: async function (uid, uname) {
            var ok = await Ilmuna.confirm('error', 'Cabut Semua Sesi @' + uname + '?', 'Seluruh perangkat aktif milik pengguna ini akan langsung keluar (Global SLO).', 'Ya, Cabut Semua', 'Batal');
            if (!ok) return;
            var res = await apiPost('revoke_user_sessions', { user_id: uid });
            if (res.success) { Ilmuna.success('Semua Sesi Dicabut', res.message); SSOADM.load(); }
            else Ilmuna.error('Gagal', res.message);
        },
        toggleUserSelect: function (uid, checked) {
            var idNum = Number(uid);
            var idx = STATE.selectedUserIds.indexOf(idNum);
            if (checked && idx === -1) STATE.selectedUserIds.push(idNum);
            if (!checked && idx !== -1) STATE.selectedUserIds.splice(idx, 1);
            var vis = Array.from(document.querySelectorAll('.user-row-chk')).map(function (el) { return { id: Number(el.getAttribute('data-uid')) }; });
            syncUsersBulkBar(vis);
        },
        toggleAllUsers: function (checked) {
            var cbs = document.querySelectorAll('.user-row-chk');
            cbs.forEach(function (cb) {
                var idNum = Number(cb.getAttribute('data-uid'));
                cb.checked = !!checked;
                var idx = STATE.selectedUserIds.indexOf(idNum);
                if (checked && idx === -1) STATE.selectedUserIds.push(idNum);
                if (!checked && idx !== -1) STATE.selectedUserIds.splice(idx, 1);
            });
            var vis = Array.from(cbs).map(function (el) { return { id: Number(el.getAttribute('data-uid')) }; });
            syncUsersBulkBar(vis);
        },
        clearUserSelection: function () {
            STATE.selectedUserIds = [];
            document.querySelectorAll('.user-row-chk').forEach(function (cb) { cb.checked = false; });
            syncUsersBulkBar([]);
        },
        deleteUser: async function (uid, uname) {
            var ok = await Ilmuna.confirm(
                'error',
                'Hapus Akun @' + uname + '?',
                'Akun ini beserta seluruh sesi SSO dan perannya akan dihapus permanen dari Margo One SSO (tidak memengaruhi database Ilmuna Learn).',
                'Ya, Hapus Permanen',
                'Batal'
            );
            if (!ok) return;
            var res = await apiPost('delete_user', { user_id: uid });
            if (res && res.success) {
                var tr = document.getElementById('adm-user-' + uid);
                if (tr) tr.classList.add('row-smooth-out');
                STATE.selectedUserIds = STATE.selectedUserIds.filter(function (x) { return Number(x) !== Number(uid); });
                Ilmuna.success('Akun Dihapus', res.message);
                setTimeout(function () { SSOADM.load(); }, 260);
            } else {
                Ilmuna.error('Gagal Menghapus', (res && res.message) || 'Tidak dapat menghapus akun.');
            }
        },
        bulkUsersAction: async function (bulkAct) {
            var ids = (STATE.selectedUserIds || []).slice();
            if (!ids.length) {
                Ilmuna.warning('Belum Ada Pilihan', 'Centang minimal 1 akun terlebih dahulu.');
                return;
            }
            var cfgMap = {
                delete: {
                    type: 'error',
                    title: 'Hapus ' + ids.length + ' Akun Terpilih?',
                    text: 'Seluruh akun terpilih (kecuali akun aktif Anda & admin terakhir) akan dihapus permanen dari Margo One SSO beserta sesi aktifnya.',
                    btn: 'Ya, Hapus ' + ids.length + ' Akun'
                },
                revoke_sessions: {
                    type: 'warning',
                    title: 'Paksa Keluar ' + ids.length + ' Akun (SLO)?',
                    text: 'Seluruh sesi perangkat aktif milik akun terpilih akan segera dicabut di semua aplikasi ekosistem Ilmuna.',
                    btn: 'Ya, Paksa Keluar'
                },
                unlink_google: {
                    type: 'warning',
                    title: 'Putus Google SSO (' + ids.length + ' Akun)?',
                    text: 'Tautan Gmail Google SSO pada akun terpilih akan dilepas. Pengguna perlu menautkan ulang di menu Profil.',
                    btn: 'Ya, Putus Tautan'
                }
            };
            var c = cfgMap[bulkAct];
            if (!c) return;
            var ok = await Ilmuna.confirm(c.type, c.title, c.text, c.btn, 'Batal');
            if (!ok) return;

            var res = await apiPost('bulk_users_action', { bulk_action: bulkAct, user_ids: ids });
            if (res && res.success) {
                if (bulkAct === 'delete' && Array.isArray(res.deleted_ids)) {
                    res.deleted_ids.forEach(function (did) {
                        var tr = document.getElementById('adm-user-' + did);
                        if (tr) tr.classList.add('row-smooth-out');
                    });
                }
                STATE.selectedUserIds = [];
                Ilmuna.success('Aksi Massal Selesai', res.message);
                setTimeout(function () { SSOADM.load(); }, bulkAct === 'delete' ? 260 : 50);
            } else {
                Ilmuna.error('Aksi Massal Gagal', (res && res.message) || 'Gagal menjalankan aksi massal.');
            }
        },
        openRolesModal: function (uid) {
            var u = (STATE.data.users || []).find(function (x) { return x.id === uid; });
            if (!u) return;
            document.getElementById('mdlRoleUserId').value = uid;
            document.getElementById('mdlRoleTitle').textContent = 'Peran & Permission: ' + u.full_name;
            var userCodes = (u.roles || []).map(function (r) { return r.code; });
            var primary = (u.roles || []).find(function (r) { return r.is_primary === 1; });
            var primCode = primary ? primary.code : (userCodes[0] || 'siswa');

            document.getElementById('mdlRoleCheckboxes').innerHTML = (STATE.data.all_roles || []).map(function (rl) {
                var chk = userCodes.indexOf(rl.code) !== -1 ? 'checked' : '';
                return '<label style="display:flex;align-items:center;gap:10px;padding:9px 12px;border:1px solid #E2E8F0;border-radius:10px;cursor:pointer;font-size:13px;font-weight:700;background:#F8FAFC;">'
                    + '<input type="checkbox" name="mdlRoleItem" value="' + esc(rl.code) + '" ' + chk + '> '
                    + '<span>' + esc(rl.name) + ' <code style="font-size:11px;color:#0D7A87;">(' + esc(rl.code) + ')</code></span>'
                    + '</label>';
            }).join('');

            document.getElementById('mdlPrimaryRole').innerHTML = (STATE.data.all_roles || []).map(function (rl) {
                return '<option value="' + esc(rl.code) + '" ' + (rl.code === primCode ? 'selected' : '') + '>' + esc(rl.name) + ' (' + esc(rl.code) + ')</option>';
            }).join('');

            var clients = (STATE.data.clients || []);
            var rawApps = String(u.allowed_apps || '').trim();
            var explicitSlugs = (rawApps !== '' && rawApps !== '*')
                ? rawApps.toLowerCase().split(/[\s,]+/).filter(Boolean)
                : null;

            document.getElementById('mdlAppPermsCheckboxes').innerHTML = clients.map(function (c) {
                var slugLower = String(c.client_slug || '').toLowerCase();
                var cRoles = String(c.allowed_roles || '').toLowerCase().split(/[\s,]+/).filter(Boolean);
                var roleMatches = cRoles.length === 0 || userCodes.some(function (rc) { return cRoles.indexOf(String(rc).toLowerCase()) !== -1; });
                var isChecked = explicitSlugs !== null
                    ? (rawApps !== '__none__' && (explicitSlugs.indexOf(slugLower) !== -1 || explicitSlugs.indexOf(String(c.id)) !== -1))
                    : roleMatches;
                var appIconHtml = c.app_logo_url
                    ? '<img src="' + esc(c.app_logo_url) + '" alt="" style="width:100%;height:100%;object-fit:contain;border-radius:8px;background:#FFF;">'
                    : '<iconify-icon icon="' + esc(c.app_icon || 'solar:widget-5-bold') + '" width="18"></iconify-icon>';
                return '<label style="display:flex;align-items:center;gap:11px;padding:10px 12px;border:1px solid #E2E8F0;border-radius:12px;cursor:pointer;background:#FFFFFF;">'
                    + '<input type="checkbox" name="mdlUserAppPerm" value="' + esc(c.client_slug) + '" ' + (isChecked ? 'checked' : '') + '>'
                    + '<div style="width:34px;height:34px;border-radius:9px;background:linear-gradient(135deg,#1D3B5E,#0D7A87);color:#FFF;display:flex;align-items:center;justify-content:center;flex-shrink:0;overflow:hidden;border:1px solid #E2E8F0;">' + appIconHtml + '</div>'
                    + '<div style="flex:1;min-width:0;">'
                    + '<div style="font-size:13px;font-weight:700;color:#1D3B5E;">' + esc(c.client_name) + ' <span class="ssoadm-badge ssoadm-badge--navy" style="font-size:10px;">' + esc(c.client_slug) + '</span></div>'
                    + '<div style="font-size:11.5px;color:#64748B;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">' + esc(c.app_url) + ' · Role Diizinkan: ' + esc(c.allowed_roles || 'semua') + '</div>'
                    + '</div>'
                    + '</label>';
            }).join('');

            openIlmunaModal('modalEditRoles');
        },
        saveUserRoles: async function (e) {
            e.preventDefault();
            var uid = document.getElementById('mdlRoleUserId').value;
            var checked = Array.from(document.querySelectorAll('input[name="mdlRoleItem"]:checked')).map(function (el) { return el.value; });
            var checkedApps = Array.from(document.querySelectorAll('input[name="mdlUserAppPerm"]:checked')).map(function (el) { return el.value; });
            var allowedAppsVal = checkedApps.length > 0 ? checkedApps.join(',') : '__none__';
            var res = await apiPost('update_user_roles', {
                user_id: uid,
                role_ids: checked,
                primary_role: document.getElementById('mdlPrimaryRole').value,
                allowed_apps: allowedAppsVal
            });
            if (res.success) {
                closeIlmunaModal('modalEditRoles');
                Ilmuna.success('Peran & Permission Diperbarui', res.message);
                await SSOADM.load();
                var tr = document.getElementById('adm-user-' + uid);
                if (tr) tr.classList.add('row-flash');
            } else Ilmuna.error('Gagal', res.message);
        },
        openRoleMatrixModal: function () {
            var roles = (STATE.data && STATE.data.all_roles) || [];
            var clients = (STATE.data && STATE.data.clients) || [];
            var box = document.getElementById('roleMatrixContainer');
            if (!box) return;
            box.innerHTML = roles.map(function (rl) {
                var rCode = String(rl.code || '').toLowerCase();
                var appsHtml = clients.map(function (c) {
                    var cRoles = String(c.allowed_roles || '').toLowerCase().split(/[\s,]+/).filter(Boolean);
                    var isAllowed = cRoles.length === 0 || cRoles.indexOf(rCode) !== -1;
                    var appIconHtml = c.app_logo_url
                        ? '<img src="' + esc(c.app_logo_url) + '" alt="" style="width:100%;height:100%;object-fit:contain;border-radius:7px;background:#FFF;">'
                        : '<iconify-icon icon="' + esc(c.app_icon || 'solar:widget-5-bold') + '" width="16"></iconify-icon>';
                    return '<label style="display:flex;align-items:center;gap:9px;padding:8px 11px;border:1px solid #E2E8F0;border-radius:10px;cursor:pointer;background:#FFFFFF;">'
                        + '<input type="checkbox" class="role-matrix-cb" data-role="' + esc(rCode) + '" data-client-id="' + esc(c.id) + '" ' + (isAllowed ? 'checked' : '') + '>'
                        + '<div style="width:28px;height:28px;border-radius:7px;background:linear-gradient(135deg,#1D3B5E,#0D7A87);color:#FFF;display:flex;align-items:center;justify-content:center;flex-shrink:0;overflow:hidden;">' + appIconHtml + '</div>'
                        + '<span style="font-size:12.5px;font-weight:700;color:#1D3B5E;">' + esc(c.client_name) + '</span>'
                        + '</label>';
                }).join('');
                return '<div style="padding:12px 14px;border:1px solid #E2E8F0;border-radius:14px;background:#F8FAFC;">'
                    + '<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;">'
                    + '<strong style="font-size:13.5px;color:#1D3B5E;">' + esc(rl.name) + ' <code style="font-size:11px;color:#0D7A87;">(' + esc(rl.code) + ')</code></strong>'
                    + '<span class="ssoadm-badge ssoadm-badge--navy">Permission Login Aplikasi</span>'
                    + '</div>'
                    + '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:8px;">' + appsHtml + '</div>'
                    + '</div>';
            }).join('');
            openIlmunaModal('modalRoleAppMatrix');
        },
        saveRoleMatrix: async function (e) {
            e.preventDefault();
            var clients = (STATE.data && STATE.data.clients) || [];
            var map = {};
            clients.forEach(function (c) { map[c.id] = []; });
            document.querySelectorAll('.role-matrix-cb:checked').forEach(function (cb) {
                var cid = cb.getAttribute('data-client-id');
                var rCode = cb.getAttribute('data-role');
                if (cid && rCode) {
                    if (!map[cid]) map[cid] = [];
                    if (map[cid].indexOf(rCode) === -1) map[cid].push(rCode);
                }
            });
            var out = {};
            Object.keys(map).forEach(function (cid) { out[cid] = map[cid].join(','); });
            var res = await apiPost('save_role_app_permissions', { client_roles_json: JSON.stringify(out) });
            if (res.success) {
                closeIlmunaModal('modalRoleAppMatrix');
                Ilmuna.success('Permission Role Tersimpan', res.message);
                await SSOADM.load();
            } else {
                Ilmuna.error('Gagal', res.message || 'Gagal menyimpan permission role.');
            }
        },
        openResetPassModal: function (uid) {
            var u = (STATE.data.users || []).find(function (x) { return x.id === uid; });
            if (!u) return;
            document.getElementById('mdlPassUserId').value = uid;
            document.getElementById('mdlPassTitle').textContent = 'Reset Sandi: @' + u.username;
            SSOADM.genRandomPass();
            openIlmunaModal('modalResetPass');
        },
        genRandomPass: function () {
            var chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789@#';
            var out = '';
            for (var i = 0; i < 12; i++) out += chars.charAt(Math.floor(Math.random() * chars.length));
            document.getElementById('mdlNewPass').value = out;
        },
        submitResetPass: async function (e) {
            e.preventDefault();
            var uid = document.getElementById('mdlPassUserId').value;
            var pass = document.getElementById('mdlNewPass').value;
            var res = await apiPost('reset_user_password', {
                user_id: uid,
                new_password: pass,
                revoke_sessions: document.getElementById('mdlRevokeAfterReset').checked ? '1' : '0'
            });
            if (res.success) {
                closeIlmunaModal('modalResetPass');
                MargoUI.copyText(pass, 'Kata Sandi Baru');
                Ilmuna.success('Berhasil Direset', res.message);
                SSOADM.load();
            } else Ilmuna.error('Gagal', res.message);
        },
        adminUnlinkGoogle: async function (uid, uname) {
            var ok = await Ilmuna.confirm('warning', 'Putus Tautan Google @' + uname + '?', 'Pengguna tidak lagi dapat login via Google sebelum menautkan ulang di /profile.', 'Ya, Putuskan', 'Batal');
            if (!ok) return;
            var res = await apiPost('admin_unlink_google', { user_id: uid });
            if (res.success) { Ilmuna.success('Diputuskan', res.message); SSOADM.load(); }
            else Ilmuna.error('Gagal', res.message);
        },
        _learnSyncCandidates: [],
        _learnSyncFilter: 'new',
        _learnSyncBound: false,
        openLearnSyncModal: async function () {
            openIlmunaModal('modalLearnSync');
            var tb = document.getElementById('learnSyncTableBody');
            var progWrap = document.getElementById('learnSyncProgressWrap');
            if (progWrap) progWrap.style.display = 'none';
            if (tb) tb.innerHTML = '<tr><td colspan="5" style="text-align:center;padding:24px;color:#64748B;">Memuat daftar Gmail dari Ilmuna Learn (Read-Only)...</td></tr>';

            if (!SSOADM._learnSyncBound) {
                SSOADM._learnSyncBound = true;
                var pills = document.getElementById('learnSyncFilterPills');
                if (pills) {
                    pills.addEventListener('click', function (ev) {
                        var b = ev.target.closest('[data-lsfilter]');
                        if (!b) return;
                        SSOADM._learnSyncFilter = b.getAttribute('data-lsfilter') || 'new';
                        pills.querySelectorAll('[data-lsfilter]').forEach(function (x) {
                            x.classList.toggle('is-active', x === b);
                        });
                        SSOADM.renderLearnSyncTable();
                    });
                }
                var srch = document.getElementById('learnSyncSearch');
                if (srch) {
                    srch.addEventListener('input', function () { SSOADM.renderLearnSyncTable(); });
                }
            }

            try {
                var res = await apiPost('preview_learn_sync', {});
                if (!res || !res.success) {
                    if (tb) tb.innerHTML = '<tr><td colspan="5" style="text-align:center;padding:24px;color:#B91C1C;">' + esc((res && res.message) || 'Gagal membaca database Ilmuna Learn.') + '</td></tr>';
                    return;
                }
                SSOADM._learnSyncCandidates = res.candidates || [];
                var cnts = res.counts || {};
                var elNew = document.getElementById('lsCntNew');
                var elEm = document.getElementById('lsCntEmail');
                var elNo = document.getElementById('lsCntNoEmail');
                if (elNew) elNew.textContent = cnts.ready_new || 0;
                if (elEm) elEm.textContent = (cnts.ready_new || 0) + (cnts.already_in_sso || 0);
                if (elNo) elNo.textContent = cnts.no_email || 0;
                SSOADM.renderLearnSyncTable();
            } catch (err) {
                if (tb) tb.innerHTML = '<tr><td colspan="5" style="text-align:center;padding:24px;color:#B91C1C;">Kesalahan koneksi saat memuat kandidat Learn.</td></tr>';
            }
        },
        renderLearnSyncTable: function () {
            var tb = document.getElementById('learnSyncTableBody');
            if (!tb) return;
            var flt = SSOADM._learnSyncFilter || 'new';
            var q = ((document.getElementById('learnSyncSearch') && document.getElementById('learnSyncSearch').value) || '').trim().toLowerCase();
            var list = (SSOADM._learnSyncCandidates || []).filter(function (c) {
                if (flt === 'new' && (!c.has_email || c.already_in_sso)) return false;
                if (flt === 'all_email' && !c.has_email) return false;
                if (flt === 'no_email' && c.has_email) return false;
                if (q) {
                    var hay = (String(c.full_name || '') + ' ' + String(c.gmail || '') + ' ' + String(c.ref_number || '') + ' ' + String(c.sso_username || '') + ' ' + (c.roles || []).join(' ')).toLowerCase();
                    if (hay.indexOf(q) === -1) return false;
                }
                return true;
            });
            var chkAll = document.getElementById('learnSyncCheckAll');
            if (chkAll) chkAll.checked = false;

            if (!list.length) {
                tb.innerHTML = '<tr><td colspan="5" style="text-align:center;padding:24px;color:#64748B;">Tidak ada pengguna pada filter ini.</td></tr>';
                SSOADM.updateLearnSyncSelectedCount();
                return;
            }
            tb.innerHTML = list.map(function (c) {
                var canCheck = !!c.has_email;
                var roleBadges = (c.roles || []).map(function (r) {
                    return '<span class="ssoadm-badge ssoadm-badge--navy" style="font-size:10px;padding:2px 6px;">' + esc(String(r).toUpperCase()) + '</span>';
                }).join(' ');
                var statusHtml = !c.has_email
                    ? '<span class="ssoadm-badge" style="background:#FEE2E2;color:#B91C1C;">Tanpa Gmail</span>'
                    : (c.already_in_sso
                        ? '<span class="ssoadm-badge ssoadm-badge--teal">Ada di SSO (@' + esc(c.sso_username) + ')</span>'
                        : '<span class="ssoadm-badge" style="background:#FEF3C7;color:#B45309;">Baru (Siap Kirim)</span>');
                return '<tr id="ls-row-' + c.learn_id + '">'
                    + '<td><input type="checkbox" class="learn-sync-cb" data-lid="' + c.learn_id + '" ' + (canCheck ? '' : 'disabled') + ' onchange="SSOADM.updateLearnSyncSelectedCount()"></td>'
                    + '<td><strong>' + esc(c.full_name) + '</strong><div style="margin-top:3px;display:flex;gap:4px;flex-wrap:wrap;">' + roleBadges + '</div></td>'
                    + '<td><code style="font-size:12px;color:#1D3B5E;font-weight:700;">' + esc(c.ref_type) + ': ' + esc(c.ref_number) + '</code></td>'
                    + '<td>' + (c.gmail ? ('<span style="font-weight:600;color:#0D7A87;">' + esc(c.gmail) + '</span>') : '<span style="color:#94A3B8;">-</span>') + '</td>'
                    + '<td class="ls-status-cell">' + statusHtml + '</td>'
                    + '</tr>';
            }).join('');
            SSOADM.updateLearnSyncSelectedCount();
        },
        toggleLearnSyncAll: function (checked) {
            document.querySelectorAll('.learn-sync-cb:not(:disabled)').forEach(function (cb) {
                cb.checked = !!checked;
            });
            SSOADM.updateLearnSyncSelectedCount();
        },
        updateLearnSyncSelectedCount: function () {
            var n = document.querySelectorAll('.learn-sync-cb:checked').length;
            var el = document.getElementById('learnSyncSelectedInfo');
            if (el) el.textContent = n + ' pengguna dipilih';
        },
        runLearnSyncSelected: async function () {
            var cbs = Array.prototype.slice.call(document.querySelectorAll('.learn-sync-cb:checked'));
            if (!cbs.length) {
                Ilmuna.warning('Belum Ada Pilihan', 'Centang minimal 1 pengguna yang memiliki Gmail untuk disinkronkan.');
                return;
            }
            var resend = !!(document.getElementById('learnSyncResendExisting') && document.getElementById('learnSyncResendExisting').checked);
            var ok = await Ilmuna.confirm(
                'info',
                'Sinkron & Kirim ' + cbs.length + ' Akun SSO?',
                'Sistem akan membuatkan username acak & kata sandi acak terpisah di Margo One SSO berdasarkan ID/NISN/NIS/NIP lalu mengirimkannya ke Gmail masing-masing via EmailRelay.',
                'Ya, Buat & Kirim Sekarang',
                'Batal'
            );
            if (!ok) return;

            var btn = document.getElementById('btnRunLearnSync');
            var progWrap = document.getElementById('learnSyncProgressWrap');
            var progText = document.getElementById('learnSyncProgressText');
            var progPct  = document.getElementById('learnSyncProgressPct');
            var progBar  = document.getElementById('learnSyncProgressBar');
            if (btn) btn.disabled = true;
            if (progWrap) progWrap.style.display = 'block';

            var sent = 0, skipped = 0, failed = 0;
            for (var i = 0; i < cbs.length; i++) {
                var lid = cbs[i].getAttribute('data-lid');
                var row = document.getElementById('ls-row-' + lid);
                var stCell = row ? row.querySelector('.ls-status-cell') : null;
                if (stCell) stCell.innerHTML = '<span class="ssoadm-badge ssoadm-badge--navy">Membuat &amp; Mengirim...</span>';

                var pct = Math.round(((i) / cbs.length) * 100);
                if (progText) progText.textContent = 'Memproses ' + (i + 1) + ' / ' + cbs.length + ' pengguna...';
                if (progPct) progPct.textContent = pct + '%';
                if (progBar) progBar.style.width = pct + '%';

                try {
                    var res = await apiPost('sync_learn_user_one', {
                        learn_id: lid,
                        resend_if_exists: resend ? '1' : '0'
                    });
                    if (res && res.status === 'already_exists') {
                        skipped++;
                        if (stCell) stCell.innerHTML = '<span class="ssoadm-badge ssoadm-badge--teal">Sudah Ada (@' + esc(res.sso_username) + ')</span>';
                    } else if (res && res.success) {
                        sent++;
                        if (stCell) stCell.innerHTML = '<span class="ssoadm-badge" style="background:#D1FAE5;color:#065F46;">Terkirim (@' + esc(res.sso_username) + ')</span>';
                    } else {
                        failed++;
                        if (stCell) stCell.innerHTML = '<span class="ssoadm-badge" style="background:#FEE2E2;color:#B91C1C;" title="' + esc((res && res.message) || 'Gagal') + '">Gagal Kirim</span>';
                    }
                } catch (eOne) {
                    failed++;
                    if (stCell) stCell.innerHTML = '<span class="ssoadm-badge" style="background:#FEE2E2;color:#B91C1C;">Error Koneksi</span>';
                }
            }

            if (progText) progText.textContent = 'Selesai: ' + sent + ' terkirim, ' + skipped + ' sudah ada, ' + failed + ' gagal.';
            if (progPct) progPct.textContent = '100%';
            if (progBar) progBar.style.width = '100%';
            if (btn) btn.disabled = false;

            await SSOADM.load();
            if (failed === 0) {
                Ilmuna.success('Sinkronisasi Selesai', sent + ' akun acak berhasil dibuat & dikirim ke Gmail (' + skipped + ' dilewati karena sudah ada).');
            } else {
                Ilmuna.warning('Sinkronisasi Selesai Sebagian', sent + ' terkirim, ' + skipped + ' sudah ada, ' + failed + ' gagal dikirim.');
            }
        },
        healOrphanGuru: async function () {
            var res = await apiPost('heal_orphan_guru', {});
            if (res.success) { Ilmuna.success('Auto-Heal Selesai', res.message); SSOADM.load(); }
            else Ilmuna.error('Gagal', res.message);
        },
        rotateJwks: async function () {
            var ok = await Ilmuna.confirm('warning', 'Rotasi Kunci JWT RS256?', 'Kunci RSA-2048 baru akan menjadi Primary Signing Key, sedangkan kunci lama tetap aktif 24 jam agar token aktif tidak terputus.', 'Ya, Rotasi JWKS', 'Batal');
            if (!ok) return;
            var res = await apiPost('rotate_jwks', { grace_hours: 24 });
            if (res.success) { Ilmuna.success('JWKS Dirotasi', res.message); SSOADM.load(); }
            else Ilmuna.error('Gagal', res.message);
        },
        saveGoogleOAuth: async function (e) {
            e.preventDefault();
            var cid = (document.getElementById('cfgGoogleClientId').value || '').trim();
            var sec = (document.getElementById('cfgGoogleClientSecret').value || '').trim();
            var res = await apiPost('save_google_oauth_settings', {
                google_client_id: cid,
                google_client_secret: sec
            });
            if (res.success) {
                document.getElementById('cfgGoogleClientSecret').value = '';
                Ilmuna.success('Google OAuth Tersimpan', res.message);
                await SSOADM.load();
            } else {
                Ilmuna.error('Gagal', res.message || 'Gagal menyimpan konfigurasi Google OAuth.');
            }
        },
        saveLearnApi: async function (e) {
            e.preventDefault();
            var res = await apiPost('save_learn_api_settings', {
                learn_api_endpoint: (document.getElementById('cfgLearnEndpoint').value || '').trim(),
                learn_ws_public_url: (document.getElementById('cfgLearnWsUrl').value || '').trim(),
                learn_api_public_key: (document.getElementById('cfgLearnPubKey').value || '').trim(),
                learn_api_private_key: (document.getElementById('cfgLearnPrivKey').value || '').trim()
            });
            if (res.success) {
                document.getElementById('cfgLearnPrivKey').value = '';
                Ilmuna.success('Kredensial Learn Tersimpan', res.message);
                await SSOADM.load();
            } else {
                Ilmuna.error('Gagal', res.message || 'Gagal menyimpan kredensial Learn.');
            }
        },
        testLearnApi: async function () {
            var res = await apiPost('test_learn_api_connection', {});
            if (res.success) {
                Ilmuna.success('Koneksi API Learn Valid', res.message);
            } else {
                Ilmuna.error('Uji Koneksi Gagal', res.message || 'Pastikan Public Key dan Private Key sudah disimpan dengan benar.');
            }
        },
        openPortalAdd: function () {
            if (STATE.portalSub === 'agendas') {
                document.getElementById('formPortalAgenda').reset();
                document.getElementById('pAgId').value = '0';
                var scEl = document.getElementById('pAgScope');
                if (scEl) scEl.value = 'all';
                document.getElementById('pAgActive').checked = true;
                document.getElementById('mdlPAgTitle').textContent = 'Tambah Agenda Ekosistem';
                openIlmunaModal('modalPortalAgenda');
            } else if (STATE.portalSub === 'announcements') {
                document.getElementById('formPortalAnn').reset();
                document.getElementById('pAnnId').value = '0';
                document.getElementById('pAnnBadge').value = 'INFO';
                document.getElementById('pAnnActive').checked = true;
                document.getElementById('mdlPAnnTitle').textContent = 'Tambah Info Ekosistem';
                openIlmunaModal('modalPortalAnn');
            } else {
                document.getElementById('formPortalExtApp').reset();
                document.getElementById('pExtId').value = '0';
                document.getElementById('pExtIcon').value = 'solar:widget-4-bold';
                document.getElementById('pExtBadge').value = 'EKOSISTEM';
                document.getElementById('pExtSort').value = '10';
                document.getElementById('pExtActive').checked = true;
                document.getElementById('mdlPExtTitle').textContent = 'Tambah Layanan Ekosistem';
                openIlmunaModal('modalPortalExtApp');
            }
        },
        editPortalAgenda: function (id) {
            var a = (STATE.data.portal_agendas || []).find(function (x) { return Number(x.id) === Number(id); });
            if (!a) return;
            if (a.source === 'kaldik' || Number(a.is_locked) === 1) {
                Ilmuna.warning('Agenda Kaldik Terkunci', 'Agenda resmi dari Kalender Pendidikan (Kaldik) tidak dapat diubah dari portal SSO.');
                return;
            }
            document.getElementById('mdlPAgTitle').textContent = 'Edit Agenda Ekosistem';
            document.getElementById('pAgId').value = a.id;
            document.getElementById('pAgTitle').value = a.title || '';
            document.getElementById('pAgStart').value = a.start_date || '';
            document.getElementById('pAgEnd').value = a.end_date || '';
            document.getElementById('pAgTime').value = a.start_time || '';
            document.getElementById('pAgCat').value = a.category || 'akademik';
            var scEl = document.getElementById('pAgScope');
            if (scEl) scEl.value = a.visibility_scope || 'all';
            document.getElementById('pAgLoc').value = a.location || '';
            document.getElementById('pAgLink').value = a.link_url_raw || a.link_url || '';
            document.getElementById('pAgDesc').value = a.description || '';
            document.getElementById('pAgActive').checked = Number(a.is_active) === 1;
            openIlmunaModal('modalPortalAgenda');
        },
        savePortalAgenda: async function (e) {
            e.preventDefault();
            var scEl = document.getElementById('pAgScope');
            var res = await apiPost('save_agenda', {
                id: document.getElementById('pAgId').value,
                title: document.getElementById('pAgTitle').value.trim(),
                start_date: document.getElementById('pAgStart').value,
                end_date: document.getElementById('pAgEnd').value,
                start_time: document.getElementById('pAgTime').value.trim(),
                category: document.getElementById('pAgCat').value,
                visibility_scope: scEl ? scEl.value : 'all',
                location: document.getElementById('pAgLoc').value.trim(),
                link_url: document.getElementById('pAgLink').value.trim(),
                description: document.getElementById('pAgDesc').value.trim(),
                is_active: document.getElementById('pAgActive').checked ? '1' : '0'
            });
            if (res.success) {
                closeIlmunaModal('modalPortalAgenda');
                Ilmuna.success('Tersimpan', res.message);
                await SSOADM.load();
                if (window.MargoShell) window.MargoShell.refreshRightWidgets();
            } else Ilmuna.error('Gagal', res.message);
        },
        syncKaldikAgendas: async function () {
            var res = await apiPost('sync_kaldik_agendas', {});
            if (res.success) {
                Ilmuna.success('Sinkronisasi Kaldik Selesai', res.message);
                await SSOADM.load();
                if (window.MargoShell) window.MargoShell.refreshRightWidgets();
            } else {
                Ilmuna.error('Gagal Sinkron Kaldik', res.message || 'Gagal menghubungi database Kaldik.');
            }
        },
        deletePortalAgenda: async function (id) {
            var ok = await Ilmuna.confirm('error', 'Hapus Agenda?', 'Agenda akan dihapus dari seluruh kalender & widget portal.', 'Ya, Hapus', 'Batal');
            if (!ok) return;
            var res = await apiPost('delete_agenda', { id: id });
            if (res.success) {
                var tr = document.getElementById('p-ag-' + id);
                if (tr) tr.classList.add('row-smooth-out');
                setTimeout(async function () {
                    await SSOADM.load();
                    if (window.MargoShell) window.MargoShell.refreshRightWidgets();
                }, 260);
                Ilmuna.success('Terhapus', res.message);
            } else Ilmuna.error('Gagal', res.message);
        },
        editPortalAnn: function (id) {
            var n = (STATE.data.portal_announcements || []).find(function (x) { return Number(x.id) === Number(id); });
            if (!n) return;
            document.getElementById('mdlPAnnTitle').textContent = 'Edit Info Ekosistem';
            document.getElementById('pAnnId').value = n.id;
            document.getElementById('pAnnTitle').value = n.title || '';
            document.getElementById('pAnnBadge').value = n.badge_label || 'INFO';
            document.getElementById('pAnnLink').value = n.link_url || '';
            document.getElementById('pAnnExcerpt').value = n.excerpt || '';
            document.getElementById('pAnnActive').checked = Number(n.is_active) === 1;
            openIlmunaModal('modalPortalAnn');
        },
        savePortalAnn: async function (e) {
            e.preventDefault();
            var res = await apiPost('save_announcement', {
                id: document.getElementById('pAnnId').value,
                title: document.getElementById('pAnnTitle').value.trim(),
                badge_label: document.getElementById('pAnnBadge').value.trim(),
                link_url: document.getElementById('pAnnLink').value.trim(),
                excerpt: document.getElementById('pAnnExcerpt').value.trim(),
                is_active: document.getElementById('pAnnActive').checked ? '1' : '0'
            });
            if (res.success) {
                closeIlmunaModal('modalPortalAnn');
                Ilmuna.success('Tersimpan', res.message);
                await SSOADM.load();
                if (window.MargoShell) window.MargoShell.refreshRightWidgets();
            } else Ilmuna.error('Gagal', res.message);
        },
        deletePortalAnn: async function (id) {
            var ok = await Ilmuna.confirm('error', 'Hapus Pengumuman?', 'Pengumuman akan dihapus dari panel info ekosistem.', 'Ya, Hapus', 'Batal');
            if (!ok) return;
            var res = await apiPost('delete_announcement', { id: id });
            if (res.success) {
                var tr = document.getElementById('p-an-' + id);
                if (tr) tr.classList.add('row-smooth-out');
                setTimeout(async function () {
                    await SSOADM.load();
                    if (window.MargoShell) window.MargoShell.refreshRightWidgets();
                }, 260);
                Ilmuna.success('Terhapus', res.message);
            } else Ilmuna.error('Gagal', res.message);
        },
        editPortalExtApp: function (id) {
            var e = (STATE.data.portal_external_apps || []).find(function (x) { return Number(x.id) === Number(id); });
            if (!e) return;
            document.getElementById('mdlPExtTitle').textContent = 'Edit Layanan Ekosistem';
            document.getElementById('pExtId').value = e.id;
            document.getElementById('pExtName').value = e.app_name || '';
            document.getElementById('pExtBadge').value = e.badge_label || 'EKOSISTEM';
            document.getElementById('pExtUrl').value = e.app_url || '';
            document.getElementById('pExtIcon').value = e.app_icon || 'solar:widget-4-bold';
            document.getElementById('pExtDesc').value = e.description || '';
            document.getElementById('pExtSort').value = e.sort_order || 10;
            document.getElementById('pExtActive').checked = Number(e.is_active) === 1;
            openIlmunaModal('modalPortalExtApp');
        },
        savePortalExtApp: async function (ev) {
            ev.preventDefault();
            var payload = {
                id: document.getElementById('pExtId').value,
                app_name: document.getElementById('pExtName').value.trim(),
                badge_label: document.getElementById('pExtBadge').value.trim(),
                app_url: document.getElementById('pExtUrl').value.trim(),
                app_icon: document.getElementById('pExtIcon').value.trim(),
                description: document.getElementById('pExtDesc').value.trim(),
                sort_order: document.getElementById('pExtSort').value,
                is_active: document.getElementById('pExtActive').checked ? '1' : '0'
            };
            var extLogoInp = document.getElementById('pExtLogoFile');
            if (extLogoInp && extLogoInp.files && extLogoInp.files[0]) {
                payload.app_logo_file = extLogoInp.files[0];
            }
            var res = await apiPost('save_external_app', payload);
            if (res.success) {
                closeIlmunaModal('modalPortalExtApp');
                Ilmuna.success('Tersimpan', res.message);
                await SSOADM.load();
            } else Ilmuna.error('Gagal', res.message);
        },
        deletePortalExtApp: async function (id) {
            var ok = await Ilmuna.confirm('error', 'Hapus Layanan Non-SSO?', 'Layanan akan dihapus dari Beranda Portal.', 'Ya, Hapus', 'Batal');
            if (!ok) return;
            var res = await apiPost('delete_external_app', { id: id });
            if (res.success) {
                var tr = document.getElementById('p-ex-' + id);
                if (tr) tr.classList.add('row-smooth-out');
                setTimeout(function () { SSOADM.load(); }, 260);
                Ilmuna.success('Terhapus', res.message);
            } else Ilmuna.error('Gagal', res.message);
        }
    };

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('#ssoAdminTabs .curriculum-tab-btn').forEach(function (btn) {
            btn.addEventListener('click', function () { activateTab(btn.getAttribute('data-tab')); });
        });
        document.querySelectorAll('#portalSubPills .ilmuna-pill-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                STATE.portalSub = btn.getAttribute('data-psub');
                document.querySelectorAll('#portalSubPills .ilmuna-pill-btn').forEach(function (b) { b.classList.toggle('is-active', b === btn); });
                ['agendas', 'announcements', 'external_apps'].forEach(function (k) {
                    var box = document.getElementById('portalBox_' + k);
                    if (box) box.style.display = (k === STATE.portalSub) ? '' : 'none';
                });
                var lblMap = { agendas: 'Tambah Agenda', announcements: 'Tambah Pengumuman', external_apps: 'Tambah Layanan' };
                document.getElementById('lblPortalAdd').textContent = lblMap[STATE.portalSub] || 'Tambah Data';
                var btnCal = document.getElementById('btnPortalOpenCal');
                var btnSync = document.getElementById('btnPortalSyncKaldik');
                if (btnCal) btnCal.style.display = (STATE.portalSub === 'agendas') ? '' : 'none';
                if (btnSync) btnSync.style.display = (STATE.portalSub === 'agendas') ? '' : 'none';
            });
        });
        document.querySelectorAll('#sessFilterPills .ilmuna-pill-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                STATE.sessFilter = btn.getAttribute('data-sfilter');
                document.querySelectorAll('#sessFilterPills .ilmuna-pill-btn').forEach(function (b) { b.classList.toggle('is-active', b === btn); });
                renderSessions();
            });
        });
        document.querySelectorAll('#userRolePills .ilmuna-pill-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                STATE.userFilter = btn.getAttribute('data-ufilter');
                document.querySelectorAll('#userRolePills .ilmuna-pill-btn').forEach(function (b) { b.classList.toggle('is-active', b === btn); });
                renderUsers();
            });
        });
        document.querySelectorAll('#clientFilterPills .ilmuna-pill-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                STATE.clientFilter = btn.getAttribute('data-cfilter');
                document.querySelectorAll('#clientFilterPills .ilmuna-pill-btn').forEach(function (b) { b.classList.toggle('is-active', b === btn); });
                renderClients();
            });
        });
        var cSearch = document.getElementById('clientSearchInput');
        if (cSearch) cSearch.addEventListener('input', renderClients);
        document.getElementById('sessSearchInput').addEventListener('input', renderSessions);
        document.getElementById('userSearchInput').addEventListener('input', renderUsers);
        var auditSearchEl = document.getElementById('auditSearchInput');
        if (auditSearchEl) auditSearchEl.addEventListener('input', renderJwksAndLogs);
        SSOADM.load(true);
    });
})();
</script>

<?php require_once BASE_PATH . '/includes/footer.php'; ?>
