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

if (isset($_GET['api']) && $_GET['api'] === '1') {
    while (ob_get_level()) {
        ob_end_clean();
    }
    header('Content-Type: application/json; charset=utf-8');
    $act = trim((string)($_POST['api_action'] ?? $_GET['api_action'] ?? ''));

    try {
        // ---- BOOTSTRAP FULL DASHBOARD DATA ----
        if ($act === 'bootstrap') {
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
                    'app_logo_url'       => margo_resolve_app_logo_url($c['app_logo'] ?? '', $c['app_icon'] ?? ''),
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
                LEFT JOIN users u ON u.id = s.user_id
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
            $allRoles = $pdo->query("SELECT id, code, name FROM roles ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
            $usersRaw = $pdo->query("
                SELECT u.*
                FROM users u
                ORDER BY u.id ASC
                LIMIT 200
            ")->fetchAll(PDO::FETCH_ASSOC);

            $urMap = [];
            $urRows = $pdo->query("
                SELECT ur.user_id, r.code, r.name, ur.is_primary
                FROM user_roles ur
                JOIN roles r ON ur.role_id = r.id
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
                LEFT JOIN users u ON u.id = l.user_id
                LEFT JOIN sso_clients c ON c.id = l.client_id
                ORDER BY l.id DESC
                LIMIT 100
            ")->fetchAll(PDO::FETCH_ASSOC);

            // 5. Portal Content (Agenda, Pengumuman, Layanan Ekosistem Non-SSO)
            $portalAgendas = $pdo->query("
                SELECT id, title, description, start_date, end_date, start_time, location, category, link_url, is_active, created_at
                FROM sso_agendas
                ORDER BY start_date DESC, id DESC
                LIMIT 120
            ")->fetchAll(PDO::FETCH_ASSOC);

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
                $pea['app_logo_url'] = margo_resolve_app_logo_url($pea['app_logo'], $pea['app_icon'] ?? '');
            }
            unset($pea);

            echo json_encode([
                'success' => true,
                'data'    => [
                    'endpoints' => [
                        'issuer'     => SSO_ISSUER,
                        'authorize'  => BASE_URL . '/authorize',
                        'token'      => BASE_URL . '/api/sso/token.php',
                        'userinfo'   => BASE_URL . '/api/sso/userinfo.php',
                        'introspect' => BASE_URL . '/api/sso/introspect.php',
                        'jwks'       => BASE_URL . '/api/sso/jwks.php',
                        'logout'     => BASE_URL . '/logout',
                    ],
                    'clients'              => $clientsOut,
                    'sessions'             => $sessions,
                    'active_session_count' => $activeSessionCnt,
                    'users'                => $usersOut,
                    'all_roles'            => $allRoles,
                    'orphan_gurus'         => $orphanGurus,
                    'jwks_keys'            => $jwksKeys,
                    'audit_logs'           => $auditLogs,
                    'portal_agendas'       => $portalAgendas,
                    'portal_announcements' => $portalAnnouncements,
                    'portal_external_apps' => $portalExternalApps,
                ],
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

            $allRolesRows = $pdo->query("SELECT id, code FROM roles")->fetchAll(PDO::FETCH_ASSOC);
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
                $pdo->prepare("DELETE FROM user_roles WHERE user_id = ?")->execute([$uid]);
                $insUr = $pdo->prepare("INSERT INTO user_roles (user_id, role_id, is_primary) VALUES (?, ?, ?)");
                foreach ($validCodes as $vc) {
                    $insUr->execute([$uid, $codeToId[$vc], ($vc === $primaryCode ? 1 : 0)]);
                }
                if (isset($_POST['allowed_apps'])) {
                    $pdo->prepare("UPDATE users SET allowed_apps = ? WHERE id = ?")
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
            $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?")->execute([password_hash($newPass, PASSWORD_DEFAULT), $uid]);
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
            $pdo->prepare("UPDATE users SET email_google = NULL, google_id = NULL WHERE id = ?")->execute([$uid]);
            margo_sso_log_audit($pdo, 'admin_google_unlinked', ['user_id' => $uid]);
            echo json_encode(['success' => true, 'message' => 'Tautan Google SSO pengguna berhasil diputuskan.']);
            exit;
        }

        // ---- AUTO-HEAL ORPHAN GURU.ID_USER ----
        if ($act === 'heal_orphan_guru') {
            $healed = 0;
            $hasGuruTbl = (bool)$pdo->query("SHOW TABLES LIKE 'guru'")->fetch();
            if ($hasGuruTbl) {
                $uRows = $pdo->query("SELECT * FROM users")->fetchAll(PDO::FETCH_ASSOC);
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

        // ---- PORTAL CMS: SIMPAN AGENDA EKOSISTEM ----
        if ($act === 'save_agenda') {
            $id        = (int)($_POST['id'] ?? 0);
            $title     = trim((string)($_POST['title'] ?? ''));
            $desc      = trim((string)($_POST['description'] ?? ''));
            $startDate = trim((string)($_POST['start_date'] ?? ''));
            $endDate   = trim((string)($_POST['end_date'] ?? ''));
            $startTime = trim((string)($_POST['start_time'] ?? ''));
            $location  = trim((string)($_POST['location'] ?? ''));
            $category  = strtolower(trim((string)($_POST['category'] ?? 'akademik')));
            $linkUrl   = trim((string)($_POST['link_url'] ?? ''));
            $isActive  = !empty($_POST['is_active']) ? 1 : 0;

            if ($title === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate)) {
                echo json_encode(['success' => false, 'message' => 'Judul agenda dan tanggal mulai (YYYY-MM-DD) wajib diisi.']);
                exit;
            }
            if ($endDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) {
                $endDate = '';
            }
            if (!in_array($category, ['akademik', 'sistem', 'rapat', 'kegiatan', 'pengumuman'], true)) {
                $category = 'akademik';
            }

            if ($id > 0) {
                $st = $pdo->prepare("
                    UPDATE sso_agendas
                    SET title = ?, description = ?, start_date = ?, end_date = ?, start_time = ?, location = ?, category = ?, link_url = ?, is_active = ?
                    WHERE id = ?
                ");
                $st->execute([
                    $title, $desc !== '' ? $desc : null, $startDate,
                    $endDate !== '' ? $endDate : null,
                    $startTime !== '' ? $startTime : null,
                    $location !== '' ? $location : null,
                    $category, $linkUrl !== '' ? $linkUrl : null, $isActive, $id,
                ]);
                echo json_encode(['success' => true, 'message' => 'Agenda "' . $title . '" berhasil diperbarui.', 'data' => ['id' => $id]]);
                exit;
            } else {
                $st = $pdo->prepare("
                    INSERT INTO sso_agendas (title, description, start_date, end_date, start_time, location, category, link_url, is_active, created_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $st->execute([
                    $title, $desc !== '' ? $desc : null, $startDate,
                    $endDate !== '' ? $endDate : null,
                    $startTime !== '' ? $startTime : null,
                    $location !== '' ? $location : null,
                    $category, $linkUrl !== '' ? $linkUrl : null, $isActive, (int)getCurrentUser()['id'],
                ]);
                echo json_encode(['success' => true, 'message' => 'Agenda baru "' . $title . '" berhasil ditambahkan.', 'data' => ['id' => (int)$pdo->lastInsertId()]]);
                exit;
            }
        }

        if ($act === 'delete_agenda') {
            $id = (int)($_POST['id'] ?? 0);
            $pdo->prepare("DELETE FROM sso_agendas WHERE id = ?")->execute([$id]);
            echo json_encode(['success' => true, 'message' => 'Agenda berhasil dihapus.']);
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
    'jwks'     => ['title' => 'JWKS, SDK & Audit Log',      'icon' => 'solar:history-bold'],
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
                    <button type="button" class="ilmuna-nav-btn ilmuna-nav-btn--primary" onclick="SSOADM.openRoleMatrixModal()">
                        <iconify-icon icon="solar:shield-user-bold" width="16"></iconify-icon>
                        <span>Izin Role ke Aplikasi</span>
                    </button>
                    <button type="button" class="ilmuna-nav-btn ilmuna-nav-btn--ghost" id="btnHealOrphan" onclick="SSOADM.healOrphanGuru()">
                        <iconify-icon icon="solar:health-bold" width="16" style="color:#0D7A87;"></iconify-icon>
                        <span id="lblOrphanGuru">Cek Orphan Guru</span>
                    </button>
                </div>
            </div>
        </div>

        <div class="ssoadm-card" style="padding:0;overflow:hidden;">
            <div class="table-responsive">
                <table class="lms-table ilmuna-table-stack">
                    <thead>
                        <tr>
                            <th>Akun Pengguna</th>
                            <th>Peran Lintas Aplikasi</th>
                            <th>Tautan Google SSO</th>
                            <th>Login Terakhir</th>
                            <th style="text-align:right;">Kelola</th>
                        </tr>
                    </thead>
                    <tbody id="usersTableBody">
                        <tr><td colspan="5" style="text-align:center;padding:24px;color:#64748B;">Memuat daftar identitas...</td></tr>
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

        <div class="ssoadm-card" style="padding:0;overflow:hidden;">
            <div class="ssoadm-card-head" style="padding:14px 18px;margin-bottom:0;">
                <h3 class="ssoadm-card-title">
                    <iconify-icon icon="solar:history-bold" style="color:#0D7A87;font-size:20px;"></iconify-icon>
                    Audit Log Keamanan SSO (100 Event Terakhir)
                </h3>
                <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                    <input type="text" id="auditSearchInput" class="ssoadm-input" style="width:220px;min-height:36px;padding:6px 10px;" placeholder="Filter event / user / IP...">
                    <button type="button" class="ssoadm-btn ssoadm-btn--ghost ssoadm-btn--sm" onclick="SSOADM.load()">
                        <iconify-icon icon="solar:refresh-bold"></iconify-icon> Segarkan
                    </button>
                </div>
            </div>
            <div class="table-responsive">
                <table class="lms-table ilmuna-table-stack">
                    <thead>
                        <tr>
                            <th>Waktu</th>
                            <th>Event</th>
                            <th>Status</th>
                            <th>Pengguna / Klien</th>
                            <th>IP</th>
                            <th>Detail</th>
                        </tr>
                    </thead>
                    <tbody id="auditLogsBody">
                        <tr><td colspan="6" style="text-align:center;padding:24px;color:#64748B;">Belum ada log audit.</td></tr>
                    </tbody>
                </table>
            </div>
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
                <div class="ilmuna-filter-actions" style="margin-left:auto;">
                    <button type="button" class="ilmuna-nav-btn ilmuna-nav-btn--primary" id="btnPortalAdd" onclick="SSOADM.openPortalAdd()">
                        <iconify-icon icon="solar:add-circle-bold" width="17"></iconify-icon>
                        <span id="lblPortalAdd">Tambah Agenda</span>
                    </button>
                </div>
            </div>
        </div>

        <!-- Sub-panel 5A: Agenda -->
        <div class="ssoadm-card" id="portalBox_agendas" style="padding:0;overflow:hidden;">
            <div class="table-responsive">
                <table class="lms-table ilmuna-table-stack">
                    <thead>
                        <tr>
                            <th>Tanggal &amp; Jam</th>
                            <th>Judul &amp; Lokasi</th>
                            <th>Kategori</th>
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
                            <div id="edAppLogoPreview" style="width:42px;height:42px;border-radius:11px;background:linear-gradient(135deg,#1D3B5E,#0D7A87);color:#FFFFFF;display:flex;align-items:center;justify-content:center;flex-shrink:0;overflow:hidden;border:1px solid #E2E8F0;">
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
                    <div class="ssoadm-field"><label>Kategori</label>
                        <select id="pAgCat" class="ssoadm-input">
                            <option value="akademik">Akademik</option>
                            <option value="sistem">Sistem SSO</option>
                            <option value="rapat">Rapat</option>
                            <option value="kegiatan">Kegiatan</option>
                            <option value="pengumuman">Pengumuman</option>
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
    var ENDPOINT = <?= json_encode(BASE_URL . '/dashboard/admin/index.php?api=1') ?>;
    var CSRF     = <?= json_encode($csrfAdmin) ?>;

    var STATE = {
        data: null,
        selectedClientId: 0,
        showPriv: false,
        clientFilter: 'all',
        sessFilter: 'active',
        userFilter: 'all',
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
        var r = await fetch(ENDPOINT, { method: 'POST', body: fd, credentials: 'same-origin' });
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
                        + '<div style="width:38px;height:38px;border-radius:10px;background:linear-gradient(135deg,#1D3B5E,#0D7A87);color:#fff;display:flex;align-items:center;justify-content:center;flex-shrink:0;overflow:hidden;border:1px solid #E2E8F0;">' + iconBoxInner + '</div>'
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
        document.getElementById('lblOrphanGuru').textContent = orphans.length > 0
            ? ('Perbaiki ' + orphans.length + ' Orphan Guru')
            : 'Guru Tersinkron (0 Orphan)';

        var q = (document.getElementById('userSearchInput').value || '').toLowerCase().trim();
        var filtered = list.filter(function (u) {
            var codes = (u.roles || []).map(function (r) { return r.code; });
            if (STATE.userFilter === 'google' && !u.email_google) return false;
            if (['admin', 'guru', 'siswa'].indexOf(STATE.userFilter) !== -1 && codes.indexOf(STATE.userFilter) === -1) return false;
            if (q !== '') {
                var hay = (u.full_name + ' ' + u.username + ' ' + u.email + ' ' + u.email_google + ' ' + u.nisn_nip).toLowerCase();
                if (hay.indexOf(q) === -1) return false;
            }
            return true;
        });

        var tb = document.getElementById('usersTableBody');
        if (!filtered.length) {
            tb.innerHTML = '<tr><td colspan="5" style="text-align:center;padding:24px;color:#64748B;">Tidak ada akun ditemukan.</td></tr>';
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
            return '<tr id="adm-user-' + u.id + '">'
                + '<td data-label="Akun"><strong>' + esc(u.full_name) + '</strong><div style="font-size:11.5px;color:#64748B;">@' + esc(u.username) + (u.email ? ' · ' + esc(u.email) : '') + '</div></td>'
                + '<td data-label="Peran & Permission"><div>' + (roleBadges || '<span class="ssoadm-badge ssoadm-badge--err">Tanpa Role</span>') + '</div><div style="margin-top:4px;display:flex;gap:4px;flex-wrap:wrap;">' + appPermBadges + '</div></td>'
                + '<td data-label="Google SSO">' + gBadge + '</td>'
                + '<td data-label="Login Terakhir">' + esc(u.last_login || 'Belum pernah') + '</td>'
                + '<td data-label="Kelola" style="text-align:right;"><div style="display:flex;gap:6px;justify-content:flex-end;flex-wrap:wrap;">'
                + '<button type="button" class="ssoadm-btn ssoadm-btn--primary ssoadm-btn--sm" onclick="SSOADM.openRolesModal(' + u.id + ')"><iconify-icon icon="solar:users-group-two-rounded-bold"></iconify-icon> Role &amp; Akses Apk</button>'
                + '<button type="button" class="ssoadm-btn ssoadm-btn--ghost ssoadm-btn--sm" onclick="SSOADM.openResetPassModal(' + u.id + ')"><iconify-icon icon="solar:key-bold"></iconify-icon> Sandi</button>'
                + '</div></td>'
                + '</tr>';
        }).join('');
    }

    function renderJwksAndLogs() {
        var keys = (STATE.data && STATE.data.jwks_keys) || [];
        var logs = (STATE.data && STATE.data.audit_logs) || [];
        var cntEl = document.getElementById('cntTabLogs');
        if (cntEl) cntEl.textContent = logs.length;
        document.getElementById('txtJwksUrl').textContent = (STATE.data && STATE.data.endpoints && STATE.data.endpoints.jwks) || '';

        document.getElementById('jwksKeysList').innerHTML = keys.map(function (k) {
            var badge = Number(k.is_primary) === 1
                ? '<span class="ssoadm-badge ssoadm-badge--ok">PRIMARY SIGNING</span>'
                : (Number(k.is_active) === 1 ? '<span class="ssoadm-badge ssoadm-badge--warn">GRACE PERIOD</span>' : '<span class="ssoadm-badge ssoadm-badge--err">NONAKTIF</span>');
            return '<div style="padding:10px 12px;border:1px solid #E2E8F0;border-radius:11px;background:#F8FAFC;display:flex;justify-content:space-between;align-items:center;gap:8px;flex-wrap:wrap;">'
                + '<div><strong style="font-family:var(--font-mono);font-size:12.5px;color:#0F172A;">' + esc(k.kid) + '</strong>'
                + '<div style="font-size:11px;color:#64748B;">SHA256: ' + esc((k.fingerprint || '').slice(0, 24)) + '… · Dibuat: ' + esc(k.created_at) + '</div></div>'
                + badge + '</div>';
        }).join('');

        var q = (document.getElementById('auditSearchInput').value || '').toLowerCase().trim();
        var filtered = logs.filter(function (l) {
            if (q === '') return true;
            var hay = (l.event_type + ' ' + (l.username || '') + ' ' + (l.client_name || '') + ' ' + (l.ip_address || '') + ' ' + (l.detail_json || '')).toLowerCase();
            return hay.indexOf(q) !== -1;
        });

        var tb = document.getElementById('auditLogsBody');
        if (!filtered.length) {
            tb.innerHTML = '<tr><td colspan="6" style="text-align:center;padding:24px;color:#64748B;">Belum ada catatan audit log.</td></tr>';
            return;
        }
        tb.innerHTML = filtered.map(function (l) {
            var st = Number(l.http_status);
            var stCls = st < 300 ? 'ssoadm-badge--ok' : (st < 400 ? 'ssoadm-badge--teal' : 'ssoadm-badge--err');
            return '<tr>'
                + '<td data-label="Waktu" style="white-space:nowrap;font-size:12px;">' + esc(l.created_at) + '</td>'
                + '<td data-label="Event"><span class="ssoadm-badge ssoadm-badge--navy">' + esc(l.event_type) + '</span></td>'
                + '<td data-label="Status"><span class="ssoadm-badge ' + stCls + '">' + esc(l.http_status) + '</span></td>'
                + '<td data-label="Aktor">' + esc(l.username ? '@' + l.username : '-') + (l.client_name ? '<div style="font-size:11px;color:#64748B;">' + esc(l.client_name) + '</div>' : '') + '</td>'
                + '<td data-label="IP" style="font-family:var(--font-mono);font-size:12px;">' + esc(l.ip_address) + '</td>'
                + '<td data-label="Detail" style="font-family:var(--font-mono);font-size:11px;color:#475569;max-width:260px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="' + esc(l.detail_json || '') + '">' + esc(l.detail_json || '-') + '</td>'
                + '</tr>';
        }).join('');
    }

    function renderPortal() {
        var agendas = (STATE.data && STATE.data.portal_agendas) || [];
        var anns    = (STATE.data && STATE.data.portal_announcements) || [];
        var exts    = (STATE.data && STATE.data.portal_external_apps) || [];
        var cntEl   = document.getElementById('cntTabPortal');
        if (cntEl) cntEl.textContent = agendas.length + anns.length + exts.length;

        var tbAg = document.getElementById('portalAgendasBody');
        if (tbAg) {
            tbAg.innerHTML = agendas.length ? agendas.map(function (a) {
                var stB = Number(a.is_active) === 1 ? '<span class="ssoadm-badge ssoadm-badge--ok">Aktif</span>' : '<span class="ssoadm-badge ssoadm-badge--err">Draft</span>';
                return '<tr id="p-ag-' + a.id + '">'
                    + '<td data-label="Tanggal"><strong>' + esc(a.start_date) + '</strong>' + (a.end_date ? '<div style="font-size:11px;color:#64748B;">s/d ' + esc(a.end_date) + '</div>' : '') + (a.start_time ? '<div style="font-size:11px;color:#0D7A87;">' + esc(a.start_time) + ' WIB</div>' : '') + '</td>'
                    + '<td data-label="Agenda"><strong>' + esc(a.title) + '</strong>' + (a.location ? '<div style="font-size:11.5px;color:#64748B;">' + esc(a.location) + '</div>' : '') + '</td>'
                    + '<td data-label="Kategori"><span class="ssoadm-badge ssoadm-badge--teal">' + esc(a.category) + '</span></td>'
                    + '<td data-label="Status">' + stB + '</td>'
                    + '<td data-label="Aksi" style="text-align:right;"><div style="display:flex;gap:6px;justify-content:flex-end;">'
                    + '<button type="button" class="ssoadm-btn ssoadm-btn--ghost ssoadm-btn--sm" onclick="SSOADM.editPortalAgenda(' + a.id + ')"><iconify-icon icon="solar:pen-bold"></iconify-icon> Edit</button>'
                    + '<button type="button" class="ssoadm-btn ssoadm-btn--danger ssoadm-btn--sm" onclick="SSOADM.deletePortalAgenda(' + a.id + ')"><iconify-icon icon="solar:trash-bin-minimalistic-bold"></iconify-icon></button>'
                    + '</div></td></tr>';
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
        load: async function () {
            try {
                var r = await fetch(ENDPOINT + '&api_action=bootstrap', { credentials: 'same-origin' });
                var res = await r.json();
                if (!res.success) { Ilmuna.error('Gagal', res.message || 'Gagal memuat data SSO.'); return; }
                STATE.data = res.data;
                renderClients();
                renderSessions();
                renderUsers();
                renderJwksAndLogs();
                renderPortal();
            } catch (e) {
                Ilmuna.error('Kesalahan', 'Gagal menghubungi backend Admin Margo One.');
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
        openPortalAdd: function () {
            if (STATE.portalSub === 'agendas') {
                document.getElementById('formPortalAgenda').reset();
                document.getElementById('pAgId').value = '0';
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
            document.getElementById('mdlPAgTitle').textContent = 'Edit Agenda Ekosistem';
            document.getElementById('pAgId').value = a.id;
            document.getElementById('pAgTitle').value = a.title || '';
            document.getElementById('pAgStart').value = a.start_date || '';
            document.getElementById('pAgEnd').value = a.end_date || '';
            document.getElementById('pAgTime').value = a.start_time || '';
            document.getElementById('pAgCat').value = a.category || 'akademik';
            document.getElementById('pAgLoc').value = a.location || '';
            document.getElementById('pAgLink').value = a.link_url || '';
            document.getElementById('pAgDesc').value = a.description || '';
            document.getElementById('pAgActive').checked = Number(a.is_active) === 1;
            openIlmunaModal('modalPortalAgenda');
        },
        savePortalAgenda: async function (e) {
            e.preventDefault();
            var res = await apiPost('save_agenda', {
                id: document.getElementById('pAgId').value,
                title: document.getElementById('pAgTitle').value.trim(),
                start_date: document.getElementById('pAgStart').value,
                end_date: document.getElementById('pAgEnd').value,
                start_time: document.getElementById('pAgTime').value.trim(),
                category: document.getElementById('pAgCat').value,
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
        document.getElementById('auditSearchInput').addEventListener('input', renderJwksAndLogs);
        SSOADM.load();
    });
})();
</script>

<?php require_once BASE_PATH . '/includes/footer.php'; ?>
