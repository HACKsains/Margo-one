<?php
/**
 * ==============================================================================
 * MARGO ONE — ILMUNA SSO · AUTHENTICATION, GLOBAL SESSION & MULTI-ROLE RBAC
 * ==============================================================================
 * Adaptasi langsung learn/auth.php untuk Identity Provider terpusat:
 *   - Login Username / Email / Email Google / NISN-NIP (anti-HY093 unique placeholders)
 *   - Google OAuth 2.0 KETAT: HANYA izinkan jika users.email_google sudah tertaut
 *     di halaman /profile (DILARANG auto-register akun baru!)
 *   - Manajemen sesi global terpusat di database (sso_sessions)
 *   - Unified Identity Resolver + Auto-Heal Anti-Orphan guru.id_user & siswa.nama_lengkap
 *   - Kompatibel 100% PHP 7.4 (ea-php74) hingga PHP 8.3
 * ==============================================================================
 */

require_once __DIR__ . '/config.php';
require_once BASE_PATH . '/includes/sso-security.php';

/**
 * Normalisasi URL foto profil / avatar (relatif -> BASE_URL penuh)
 */
if (!function_exists('margo_resolve_avatar_url')) {
    function margo_resolve_avatar_url($raw)
    {
        $s = trim((string)$raw);
        if ($s === '') {
            return null;
        }
        if (preg_match('#^https?://#i', $s) || strpos($s, 'data:image/') === 0) {
            return $s;
        }
        return rtrim(BASE_URL, '/') . '/' . ltrim($s, '/');
    }
}

/**
 * Helper normalisasi nomor HP Indonesia (08... / 628...)
 */
if (!function_exists('margo_norm_phone_id')) {
    function margo_norm_phone_id($raw)
    {
        $d = preg_replace('/\D+/', '', (string)$raw);
        if (strlen($d) < 9) {
            return '';
        }
        if (substr($d, 0, 2) === '62') {
            $d = '0' . substr($d, 2);
        }
        return $d;
    }
}

/**
 * Helper normalisasi nama orang (hapus gelar / teks kurung) untuk anti-orphan guru
 */
if (!function_exists('margo_norm_person_name')) {
    function margo_norm_person_name($raw)
    {
        $s = (string)$raw;
        $s = preg_replace('/\(.*?\)/', ' ', $s);
        $s = preg_replace('/,.*$/', '', $s);
        $s = preg_replace('/\b(ustadzah|ustadz|ustz|ust|kh|dr|drs|dra|prof|ir|hj|h)\b\.?/i', ' ', $s);
        $s = strtolower(trim(preg_replace('/[^a-zA-Z0-9\s]/', ' ', $s)));
        $s = preg_replace('/\s+/', ' ', $s);
        return $s;
    }
}

/**
 * Peta ikon Solar Icons baku untuk setiap role (tidak pernah memakai FontAwesome)
 */
function margo_role_icon($roleCode)
{
    switch ((string)$roleCode) {
        case 'admin':
            return 'solar:shield-user-bold';
        case 'guru':
            return 'solar:user-speak-bold';
        case 'ketua_kelas':
            return 'solar:crown-bold';
        case 'siswa':
            return 'solar:square-academic-cap-2-bold';
        default:
            return 'solar:user-id-bold';
    }
}

/**
 * Deteksi apakah HTTPS aktif
 */
function margo_is_https()
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443)
        || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string)$_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https');
}

/**
 * Buat sesi global SSO di tabel sso_sessions dan simpan cookie/session PHP
 */
function margo_create_sso_session(PDO $pdo, $userId, $activeRole = null, $loginMethod = 'password')
{
    margo_sso_ensure_schema($pdo);

    if (!in_array($loginMethod, ['password', 'google_oauth', 'magic_qr'], true)) {
        $loginMethod = 'password';
    }

    $rawToken  = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $rawToken);
    $ip        = margo_sso_client_ip();
    $ua        = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? 'CLI'), 0, 255);
    $expTs     = time() + (int)SSO_SESSION_TTL;
    $expiresAt = date('Y-m-d H:i:s', $expTs);

    $stmt = $pdo->prepare("
        INSERT INTO sso_sessions (session_token_hash, user_id, active_role, ip_address, user_agent, login_method, expires_at)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([$tokenHash, (int)$userId, $activeRole, $ip, $ua, $loginMethod, $expiresAt]);
    $sessionId = (int)$pdo->lastInsertId();

    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION['sso_session_id']    = $sessionId;
        $_SESSION['sso_session_token'] = $rawToken;
        $_SESSION['sso_login_method']  = $loginMethod;
    }

    if (php_sapi_name() !== 'cli' && !headers_sent()) {
        setcookie(SSO_SESSION_COOKIE, $rawToken, [
            'expires'  => $expTs,
            'path'     => '/',
            'secure'   => margo_is_https(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    return [
        'id'         => $sessionId,
        'token'      => $rawToken,
        'token_hash' => $tokenHash,
        'expires_at' => $expiresAt,
    ];
}

/**
 * Validasi sesi global SSO terhadap tabel sso_sessions (memastikan belum revoked/expired)
 */
function margo_validate_sso_session(PDO $pdo, $sessionId = null, $rawToken = null)
{
    margo_sso_ensure_schema($pdo);

    $row = null;
    if ($rawToken !== null && $rawToken !== '') {
        $hash = hash('sha256', (string)$rawToken);
        $st = $pdo->prepare("
            SELECT * FROM sso_sessions
            WHERE session_token_hash = ? AND revoked_at IS NULL AND expires_at > NOW()
            LIMIT 1
        ");
        $st->execute([$hash]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
    } elseif ($sessionId !== null && (int)$sessionId > 0) {
        $st = $pdo->prepare("
            SELECT * FROM sso_sessions
            WHERE id = ? AND revoked_at IS NULL AND expires_at > NOW()
            LIMIT 1
        ");
        $st->execute([(int)$sessionId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
    }

    if (!$row) {
        return null;
    }

    // Perbarui last_activity_at secara ringan
    try {
        $upd = $pdo->prepare("UPDATE sso_sessions SET last_activity_at = NOW() WHERE id = ?");
        $upd->execute([(int)$row['id']]);
    } catch (Throwable $e) {
        // Abaikan kegagalan minor update timestamp
    }

    return $row;
}

/**
 * Cabut satu sesi SSO + seluruh refresh token terkait (+ opsional webhook SLO)
 */
function margo_revoke_sso_session(PDO $pdo, $ssoSessionId, $userId = null, $dispatchWebhooks = true)
{
    margo_sso_ensure_schema($pdo);
    $ssoSessionId = (int)$ssoSessionId;
    if ($ssoSessionId <= 0) {
        return false;
    }

    if ($userId === null) {
        $st = $pdo->prepare("SELECT user_id FROM sso_sessions WHERE id = ? LIMIT 1");
        $st->execute([$ssoSessionId]);
        $uid = $st->fetchColumn();
        $userId = $uid ? (int)$uid : null;
    }

    $pdo->prepare("UPDATE sso_sessions SET revoked_at = NOW() WHERE id = ? AND revoked_at IS NULL")
        ->execute([$ssoSessionId]);

    $pdo->prepare("UPDATE sso_refresh_tokens SET revoked_at = NOW() WHERE sso_session_id = ? AND revoked_at IS NULL")
        ->execute([$ssoSessionId]);

    $webhookResults = [];
    if ($dispatchWebhooks && $userId) {
        $webhookResults = margo_sso_dispatch_slo_webhooks($pdo, $userId, $ssoSessionId);
    }

    margo_sso_log_audit($pdo, 'slo_logout', [
        'user_id'     => $userId,
        'http_status' => 200,
        'detail'      => [
            'sso_session_id' => $ssoSessionId,
            'webhooks'       => $webhookResults,
        ],
    ]);

    return true;
}

/**
 * Cabut seluruh sesi aktif milik user tertentu (Force Logout Semua Perangkat)
 */
function margo_revoke_all_user_sessions(PDO $pdo, $userId, $excludeSessionId = 0, $dispatchWebhooks = true)
{
    margo_sso_ensure_schema($pdo);
    $userId = (int)$userId;
    $excludeSessionId = (int)$excludeSessionId;
    if ($userId <= 0) {
        return 0;
    }

    if ($excludeSessionId > 0) {
        $st = $pdo->prepare("UPDATE sso_sessions SET revoked_at = NOW() WHERE user_id = ? AND id != ? AND revoked_at IS NULL");
        $st->execute([$userId, $excludeSessionId]);
        $affected = $st->rowCount();

        $pdo->prepare("UPDATE sso_refresh_tokens SET revoked_at = NOW() WHERE user_id = ? AND sso_session_id != ? AND revoked_at IS NULL")
            ->execute([$userId, $excludeSessionId]);
    } else {
        $st = $pdo->prepare("UPDATE sso_sessions SET revoked_at = NOW() WHERE user_id = ? AND revoked_at IS NULL");
        $st->execute([$userId]);
        $affected = $st->rowCount();

        $pdo->prepare("UPDATE sso_refresh_tokens SET revoked_at = NOW() WHERE user_id = ? AND revoked_at IS NULL")
            ->execute([$userId]);
    }

    $webhookResults = [];
    if ($dispatchWebhooks) {
        $webhookResults = margo_sso_dispatch_slo_webhooks($pdo, $userId, null);
    }

    margo_sso_log_audit($pdo, 'slo_revoke_all', [
        'user_id'     => $userId,
        'http_status' => 200,
        'detail'      => [
            'revoked_count' => $affected,
            'excluded_sid'  => $excludeSessionId,
            'webhooks'      => $webhookResults,
        ],
    ]);

    return $affected;
}

/**
 * Cek apakah pengguna saat ini sedang login & sesi SSO globalnya belum di-revoke
 */
function isLoggedIn()
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return false;
    }

    // Jika sesi PHP kosong tetapi cookie SSO global ada, coba hidrasi otomatis dari DB
    if (empty($_SESSION['user_id']) && !empty($_COOKIE[SSO_SESSION_COOKIE])) {
        try {
            $pdo = getDBConnection();
            $sess = margo_validate_sso_session($pdo, null, (string)$_COOKIE[SSO_SESSION_COOKIE]);
            if ($sess) {
                $uStmt = $pdo->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
                $uStmt->execute([(int)$sess['user_id']]);
                $uRow = $uStmt->fetch(PDO::FETCH_ASSOC);
                if ($uRow && margo_user_is_active($uRow)) {
                    margo_hydrate_php_session($pdo, $uRow, (int)$sess['id'], (string)$_COOKIE[SSO_SESSION_COOKIE], (string)$sess['login_method'], $sess['active_role']);
                    return true;
                }
            }
        } catch (Throwable $e) {
            return false;
        }
        return false;
    }

    if (empty($_SESSION['user_id'])) {
        return false;
    }

    // Validasi real-time terhadap sso_sessions (cache per request agar cepat)
    static $validatedSid = null;
    $sid = isset($_SESSION['sso_session_id']) ? (int)$_SESSION['sso_session_id'] : 0;
    if ($sid > 0 && $validatedSid !== $sid) {
        try {
            $pdo = getDBConnection();
            $tok = isset($_SESSION['sso_session_token']) ? (string)$_SESSION['sso_session_token'] : null;
            $sess = margo_validate_sso_session($pdo, $sid, $tok);
            if (!$sess) {
                logoutUserLocalOnly();
                return false;
            }
            $validatedSid = $sid;
        } catch (Throwable $e) {
            // Jangan gagalkan secara tidak perlu bila DB sementara bermasalah
        }
    }

    return true;
}

/**
 * Helper: cek apakah akun user berstatus aktif
 */
function margo_user_is_active(array $user)
{
    if (array_key_exists('status', $user) && $user['status'] !== null && $user['status'] !== '') {
        if (!in_array((string)$user['status'], ['active', '1'], true)) {
            return false;
        }
    }
    if (array_key_exists('is_active', $user) && $user['is_active'] !== null) {
        if ((int)$user['is_active'] !== 1) {
            return false;
        }
    }
    return true;
}

function getCurrentUser()
{
    if (!isLoggedIn()) {
        return null;
    }
    return [
        'id'             => (int)$_SESSION['user_id'],
        'username'       => (string)($_SESSION['user_username'] ?? ''),
        'full_name'      => (string)($_SESSION['user_name'] ?? ''),
        'email'          => (string)($_SESSION['user_email'] ?? ''),
        'email_google'   => $_SESSION['user_email_google'] ?? null,
        'avatar'         => margo_resolve_avatar_url($_SESSION['user_avatar'] ?? ''),
        'foto_profil'    => margo_resolve_avatar_url($_SESSION['user_avatar'] ?? ''),
        'roles'          => $_SESSION['user_roles'] ?? [],
        'roles_detail'   => $_SESSION['user_roles_detail'] ?? [],
        'active_role'    => $_SESSION['active_role'] ?? null,
        'primary_role'   => $_SESSION['primary_role'] ?? null,
        'sso_session_id' => (int)($_SESSION['sso_session_id'] ?? 0),
        'login_method'   => (string)($_SESSION['sso_login_method'] ?? 'password'),
    ];
}

function getUserRoles()
{
    return isset($_SESSION['user_roles']) && is_array($_SESSION['user_roles']) ? $_SESSION['user_roles'] : [];
}

function getActiveRole()
{
    if (!empty($GLOBALS['activeRole'])) {
        return $GLOBALS['activeRole'];
    }
    $roles = getUserRoles();
    if (!empty($_SESSION['active_role']) && in_array($_SESSION['active_role'], $roles, true)) {
        return $_SESSION['active_role'];
    }
    if (!empty($_SESSION['primary_role']) && in_array($_SESSION['primary_role'], $roles, true)) {
        return $_SESSION['primary_role'];
    }
    return isset($roles[0]) ? $roles[0] : null;
}

function hasRole($roleCode)
{
    return in_array((string)$roleCode, getUserRoles(), true);
}

function setActiveRole($roleCode)
{
    $roleCode = (string)$roleCode;
    if (!hasRole($roleCode)) {
        return false;
    }
    $_SESSION['active_role'] = $roleCode;
    if (!empty($_SESSION['sso_session_id'])) {
        try {
            $pdo = getDBConnection();
            $pdo->prepare("UPDATE sso_sessions SET active_role = ? WHERE id = ?")
                ->execute([$roleCode, (int)$_SESSION['sso_session_id']]);
        } catch (Throwable $e) {
            // Non-fatal
        }
    }
    return true;
}

/**
 * Ambil daftar role user dari tabel user_roles & roles (kompatibel skema learn)
 */
function margo_fetch_user_roles(PDO $pdo, $userId)
{
    $urCols = [];
    try {
        $urCols = $pdo->query("SHOW COLUMNS FROM user_roles")->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) {
        $urCols = ['id', 'user_id', 'role_id', 'is_primary'];
    }
    $userIdCol    = in_array('user_id', $urCols, true) ? 'user_id' : (in_array('id_user', $urCols, true) ? 'id_user' : 'user_id');
    $roleIdCol    = in_array('role_id', $urCols, true) ? 'role_id' : (in_array('id_roles', $urCols, true) ? 'id_roles' : 'role_id');
    $hasIsPrimary = in_array('is_primary', $urCols, true);

    $rolesCols = [];
    try {
        $rolesCols = $pdo->query("SHOW COLUMNS FROM roles")->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) {
        $rolesCols = ['id', 'code', 'name', 'description'];
    }
    $roleCodeCol = in_array('code', $rolesCols, true) ? 'code' : (in_array('slug', $rolesCols, true) ? 'slug' : 'code');
    $roleNameCol = in_array('name', $rolesCols, true) ? 'name' : (in_array('nama_roles', $rolesCols, true) ? 'nama_roles' : 'name');
    $roleDescCol = in_array('description', $rolesCols, true) ? 'description' : (in_array('deskripsi', $rolesCols, true) ? 'deskripsi' : 'description');

    $selectPrimary = $hasIsPrimary ? 'ur.is_primary' : '1 AS is_primary';
    $orderClause   = $hasIsPrimary ? 'ur.is_primary DESC, r.id ASC' : 'r.id ASC';

    $stmt = $pdo->prepare("
        SELECT r.id, r.{$roleCodeCol} AS code, r.{$roleNameCol} AS name, r.{$roleDescCol} AS description, {$selectPrimary}
        FROM user_roles ur
        JOIN roles r ON ur.{$roleIdCol} = r.id
        WHERE ur.{$userIdCol} = :user_id
        ORDER BY {$orderClause}
    ");
    $stmt->execute(['user_id' => (int)$userId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as &$r) {
        $r['icon'] = margo_role_icon($r['code']);
    }
    unset($r);

    return $rows;
}

/**
 * Resolver Guru Anti-Orphan (adaptasi langsung ilmuna_resolve_logged_guru())
 */
function margo_resolve_logged_guru(PDO $pdo, array $userRow)
{
    $empty = ['guru' => null, 'id_guru' => null];
    $userId = isset($userRow['id']) ? (int)$userRow['id'] : 0;
    if ($userId <= 0) {
        return $empty;
    }

    try {
        $hasGuru = (bool)$pdo->query("SHOW TABLES LIKE 'guru'")->fetch();
        if (!$hasGuru) {
            return $empty;
        }
        $allGuru = $pdo->query("SELECT * FROM guru ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return $empty;
    }

    $uEmails = [];
    foreach ([$userRow['email'] ?? '', $userRow['email_google'] ?? ''] as $em) {
        $emNorm = strtolower(trim((string)$em));
        if ($emNorm !== '' && strpos($emNorm, '@') !== false && !in_array($emNorm, $uEmails, true)) {
            $uEmails[] = $emNorm;
        }
    }
    $uPhone    = margo_norm_phone_id($userRow['phone'] ?? '');
    $uNip      = trim((string)($userRow['nisn_nip'] ?? ''));
    if ($uNip === '-' || strlen($uNip) < 5) {
        $uNip = '';
    }
    $uNameRaw  = strtolower(trim((string)($userRow['full_name'] ?? '')));
    $uNameNorm = margo_norm_person_name($userRow['full_name'] ?? '');
    $uUserNorm = strtolower(trim((string)($userRow['username'] ?? '')));

    $candidates = [];
    foreach ($allGuru as $g) {
        $score = 0;
        if ((int)($g['id_user'] ?? 0) === $userId) {
            $score = max($score, 100);
        }
        $gEmail = strtolower(trim((string)($g['email'] ?? '')));
        if ($gEmail !== '' && in_array($gEmail, $uEmails, true)) {
            $score = max($score, 90);
        }
        $gPhone = margo_norm_phone_id($g['no_hp'] ?? '');
        if ($uPhone !== '' && $gPhone !== '' && $gPhone === $uPhone) {
            $score = max($score, 85);
        }
        $gNip = trim((string)($g['nip'] ?? ''));
        if ($uNip !== '' && $gNip !== '' && $gNip !== '-' && $gNip === $uNip) {
            $score = max($score, 80);
        }
        $gNameRaw  = strtolower(trim((string)($g['nama_guru'] ?? '')));
        $gNameNorm = margo_norm_person_name($g['nama_guru'] ?? '');
        if ($gNameRaw !== '' && $uNameRaw !== '' && $gNameRaw === $uNameRaw) {
            $score = max($score, 78);
        } elseif ($gNameNorm !== '' && $uNameNorm !== '' && strlen($gNameNorm) >= 4 && $gNameNorm === $uNameNorm) {
            $score = max($score, 72);
        } elseif ($gNameNorm !== '' && $uUserNorm !== '' && strlen($uUserNorm) >= 4 && str_replace(' ', '', $gNameNorm) === str_replace([' ', '_', '.'], '', $uUserNorm)) {
            $score = max($score, 68);
        }

        if ($score > 0) {
            $g['_score'] = $score;
            $candidates[] = $g;
        }
    }

    if (empty($candidates)) {
        return $empty;
    }

    usort($candidates, function ($a, $b) {
        if ($a['_score'] !== $b['_score']) {
            return $b['_score'] <=> $a['_score'];
        }
        return ((int)$a['id']) <=> ((int)$b['id']);
    });

    $primary = $candidates[0];

    // Auto-heal guru.id_user bila masih NULL (anti-orphan)
    if (empty($primary['id_user']) && $userId > 0) {
        try {
            $pdo->prepare("UPDATE guru SET id_user = ? WHERE id = ? AND id_user IS NULL")
                ->execute([$userId, (int)$primary['id']]);
            $primary['id_user'] = $userId;
        } catch (Throwable $eHeal) {
            // Non-fatal
        }
    }

    return [
        'guru'    => $primary,
        'id_guru' => (int)$primary['id'],
    ];
}

/**
 * Resolver Siswa (menggunakan kolom resmi siswa.nama_lengkap, siswa.id_kelas, siswa.gmail)
 */
function margo_resolve_logged_siswa(PDO $pdo, array $userRow)
{
    $empty = ['siswa' => null, 'siswa_id' => null, 'kelas_id' => null, 'nama_kelas' => null, 'nama_lengkap' => null];
    try {
        $hasSiswa = (bool)$pdo->query("SHOW TABLES LIKE 'siswa'")->fetch();
        if (!$hasSiswa) {
            return $empty;
        }
        $siswaCols = $pdo->query("SHOW COLUMNS FROM siswa")->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) {
        return $empty;
    }

    $row = null;
    $userId   = (int)($userRow['id'] ?? 0);
    $username = trim((string)($userRow['username'] ?? ''));
    $nisnNip  = trim((string)($userRow['nisn_nip'] ?? ''));
    $email    = strtolower(trim((string)($userRow['email'] ?? '')));
    $emailG   = strtolower(trim((string)($userRow['email_google'] ?? '')));

    $hasKelas = false;
    try {
        $hasKelas = (bool)$pdo->query("SHOW TABLES LIKE 'kelas'")->fetch();
    } catch (Throwable $e) {
        $hasKelas = false;
    }

    $selectSql = $hasKelas
        ? "SELECT s.id, s.id_kelas, s.nisn, s.nis, s.nama_lengkap, s.gmail, k.nama_kelas FROM siswa s LEFT JOIN kelas k ON s.id_kelas = k.id"
        : "SELECT s.id, s.id_kelas, s.nisn, s.nis, s.nama_lengkap, s.gmail, NULL AS nama_kelas FROM siswa s";

    if (!$row && in_array('id_user', $siswaCols, true) && $userId > 0) {
        $st = $pdo->prepare($selectSql . " WHERE s.id_user = ? LIMIT 1");
        $st->execute([$userId]);
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    if (!$row && $nisnNip !== '' && in_array('nisn', $siswaCols, true)) {
        $st = $pdo->prepare($selectSql . " WHERE s.nisn = ? LIMIT 1");
        $st->execute([$nisnNip]);
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    if (!$row && $username !== '' && in_array('nisn', $siswaCols, true)) {
        $st = $pdo->prepare($selectSql . " WHERE s.nisn = ? OR s.nis = ? LIMIT 1");
        $st->execute([$username, $username]);
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    if (!$row && in_array('gmail', $siswaCols, true)) {
        foreach ([$emailG, $email] as $em) {
            if ($em !== '') {
                $st = $pdo->prepare($selectSql . " WHERE LOWER(s.gmail) = ? LIMIT 1");
                $st->execute([$em]);
                $row = $st->fetch(PDO::FETCH_ASSOC) ?: null;
                if ($row) {
                    break;
                }
            }
        }
    }

    if (!$row) {
        return $empty;
    }

    return [
        'siswa'        => $row,
        'siswa_id'     => (int)$row['id'],
        'kelas_id'     => !empty($row['id_kelas']) ? (int)$row['id_kelas'] : null,
        'nama_kelas'   => !empty($row['nama_kelas']) ? (string)$row['nama_kelas'] : null,
        'nama_lengkap' => !empty($row['nama_lengkap']) ? (string)$row['nama_lengkap'] : null,
    ];
}

/**
 * Resolver Identitas Terpadu (AMAN — BEBAS PII SENSITIF!)
 * Tidak pernah mengembalikan password_hash, alamat, no_hp, phone, atau private_key.
 */
function margo_resolve_user_identity(PDO $pdo, $userId, $activeRoleOverride = null)
{
    $st = $pdo->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
    $st->execute([(int)$userId]);
    $user = $st->fetch(PDO::FETCH_ASSOC);
    if (!$user || !margo_user_is_active($user)) {
        return null;
    }

    $rolesDetail = margo_fetch_user_roles($pdo, (int)$user['id']);
    $roleCodes   = array_values(array_column($rolesDetail, 'code'));
    $primaryRole = isset($roleCodes[0]) ? $roleCodes[0] : null;
    $activeRole  = ($activeRoleOverride && in_array($activeRoleOverride, $roleCodes, true))
        ? $activeRoleOverride
        : $primaryRole;

    $guruInfo  = ['guru' => null, 'id_guru' => null];
    $siswaInfo = ['siswa' => null, 'siswa_id' => null, 'kelas_id' => null, 'nama_kelas' => null, 'nama_lengkap' => null];

    if (in_array('guru', $roleCodes, true) || in_array('admin', $roleCodes, true)) {
        $guruInfo = margo_resolve_logged_guru($pdo, $user);
    }
    if (in_array('siswa', $roleCodes, true) || in_array('ketua_kelas', $roleCodes, true)) {
        $siswaInfo = margo_resolve_logged_siswa($pdo, $user);
    }

    $fullName = !empty($user['full_name'])
        ? (string)$user['full_name']
        : (!empty($siswaInfo['nama_lengkap']) ? (string)$siswaInfo['nama_lengkap'] : (string)$user['username']);

    $email = !empty($user['email']) ? (string)$user['email'] : (string)($user['email_google'] ?? '');
    $googleLinked = !empty($user['email_google']) && trim((string)$user['email_google']) !== '';

    $rawPic = !empty($user['foto_profil'])
        ? (string)$user['foto_profil']
        : (!empty($user['avatar'])
            ? (string)$user['avatar']
            : (!empty($guruInfo['guru']['foto'])
                ? (string)$guruInfo['guru']['foto']
                : (!empty($siswaInfo['siswa']['foto']) ? (string)$siswaInfo['siswa']['foto'] : '')));

    return [
        'sub'                 => (string)$user['id'],
        'user_id'             => (int)$user['id'],
        'username'            => (string)$user['username'],
        'name'                => $fullName,
        'full_name'           => $fullName,
        'email'               => $email,
        'email_verified'      => $googleLinked,
        'google_linked'       => $googleLinked,
        'picture'             => margo_resolve_avatar_url($rawPic),
        'roles'               => $roleCodes,
        'primary_role'        => $primaryRole,
        'active_role'         => $activeRole,
        'guru_id'             => $guruInfo['id_guru'],
        'siswa_id'            => $siswaInfo['siswa_id'],
        'kelas_id'            => $siswaInfo['kelas_id'],
        'nama_kelas'          => $siswaInfo['nama_kelas'],
    ];
}

/**
 * Hidrasi variabel $_SESSION PHP dari data user + sesi SSO DB
 */
function margo_hydrate_php_session(PDO $pdo, array $user, $ssoSessionId, $rawToken, $loginMethod = 'password', $activeRoleOverride = null)
{
    $rolesDetail = margo_fetch_user_roles($pdo, (int)$user['id']);
    if (empty($rolesDetail)) {
        return false;
    }
    $roleCodes = array_values(array_column($rolesDetail, 'code'));

    $fullName = !empty($user['full_name']) ? (string)$user['full_name'] : (string)$user['username'];
    if (in_array('siswa', $roleCodes, true) || in_array('ketua_kelas', $roleCodes, true)) {
        $sInfo = margo_resolve_logged_siswa($pdo, $user);
        if (!empty($sInfo['nama_lengkap'])) {
            $fullName = $sInfo['nama_lengkap'];
        }
    }

    $primaryRole = $roleCodes[0];
    $activeRole  = ($activeRoleOverride && in_array($activeRoleOverride, $roleCodes, true))
        ? $activeRoleOverride
        : $primaryRole;

    $_SESSION['user_id']           = (int)$user['id'];
    $_SESSION['user_username']     = (string)$user['username'];
    $_SESSION['user_name']         = $fullName;
    $_SESSION['user_email']        = (string)($user['email'] ?? $user['email_google'] ?? '');
    $_SESSION['user_email_google'] = $user['email_google'] ?? null;
    $_SESSION['user_avatar']       = margo_resolve_avatar_url($user['foto_profil'] ?? $user['avatar'] ?? '');
    $_SESSION['user_roles']        = $roleCodes;
    $_SESSION['user_roles_detail'] = $rolesDetail;
    $_SESSION['primary_role']      = $primaryRole;
    $_SESSION['active_role']       = $activeRole;
    $_SESSION['sso_session_id']    = (int)$ssoSessionId;
    $_SESSION['sso_session_token'] = (string)$rawToken;
    $_SESSION['sso_login_method']  = (string)$loginMethod;

    return true;
}

/**
 * Inisialisasi sesi penuh (PHP session + DB sso_sessions + audit log + last_login)
 */
function establishUserSession(PDO $pdo, array $user, $loginMethod = 'password')
{
    $rolesDetail = margo_fetch_user_roles($pdo, (int)$user['id']);
    if (empty($rolesDetail)) {
        return ['success' => false, 'message' => 'Akun Anda belum memiliki role aktif. Hubungi Administrator.'];
    }
    $roleCodes   = array_values(array_column($rolesDetail, 'code'));
    $primaryRole = $roleCodes[0];

    if (session_status() === PHP_SESSION_ACTIVE && !headers_sent()) {
        session_regenerate_id(true);
    }

    $ssoSess = margo_create_sso_session($pdo, (int)$user['id'], $primaryRole, $loginMethod);
    margo_hydrate_php_session($pdo, $user, $ssoSess['id'], $ssoSess['token'], $loginMethod, $primaryRole);

    // Update users.last_login
    try {
        $pdo->prepare("UPDATE users SET last_login = NOW() WHERE id = ?")->execute([(int)$user['id']]);
    } catch (Throwable $e) {
        // Non-fatal
    }

    // Auto-heal guru.id_user bila relevan
    if (in_array('guru', $roleCodes, true) || in_array('admin', $roleCodes, true)) {
        margo_resolve_logged_guru($pdo, $user);
    }

    margo_sso_log_audit($pdo, 'login_ok', [
        'user_id'     => (int)$user['id'],
        'http_status' => 200,
        'detail'      => [
            'method'         => $loginMethod,
            'sso_session_id' => $ssoSess['id'],
            'roles'          => $roleCodes,
        ],
    ]);

    return [
        'success'        => true,
        'user_id'        => (int)$user['id'],
        'roles_count'    => count($roleCodes),
        'roles'          => $roleCodes,
        'primary_role'   => $primaryRole,
        'sso_session_id' => $ssoSess['id'],
    ];
}

/**
 * Login dengan Username / Email / Email Google / NISN-NIP + Password
 * Menggunakan placeholder unik (:uname, :uemail, :uemail_g, :unisn) — BEBAS BUG HY093!
 */
function loginUser($identifier, $password)
{
    $pdo = getDBConnection();
    margo_sso_ensure_schema($pdo);

    $ip = margo_sso_client_ip();
    $rl = margo_sso_check_rate_limit($pdo, 'login_local', $ip, 25);
    if (!$rl['allowed']) {
        margo_sso_log_audit($pdo, 'login_rate_limited', [
            'http_status' => 429,
            'ip_address'  => $ip,
            'detail'      => ['identifier' => substr(trim((string)$identifier), 0, 60)],
        ]);
        return ['success' => false, 'message' => 'Terlalu banyak percobaan login. Silakan coba lagi dalam ' . $rl['reset_in'] . ' detik.'];
    }

    $cleanId = trim((string)$identifier);
    if ($cleanId === '' || (string)$password === '') {
        return ['success' => false, 'message' => 'Silakan masukkan username/email dan kata sandi Anda.'];
    }

    $userCols = [];
    try {
        $userCols = $pdo->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) {
        $userCols = ['id', 'username', 'email', 'email_google', 'nisn_nip', 'password_hash', 'status', 'is_active', 'full_name'];
    }

    $hasEmail       = in_array('email', $userCols, true);
    $hasEmailGoogle = in_array('email_google', $userCols, true);
    $hasNisnNip     = in_array('nisn_nip', $userCols, true);

    $whereParts  = ["username = :uname"];
    $loginParams = ['uname' => $cleanId];

    if ($hasEmail) {
        $whereParts[] = "email = :uemail";
        $loginParams['uemail'] = $cleanId;
    }
    if ($hasEmailGoogle) {
        $whereParts[] = "email_google = :uemail_g";
        $loginParams['uemail_g'] = $cleanId;
    }
    if ($hasNisnNip) {
        $whereParts[] = "nisn_nip = :unisn";
        $loginParams['unisn'] = $cleanId;
    }

    $statusConditions = [];
    if (in_array('status', $userCols, true)) {
        $statusConditions[] = "(status = 'active' OR status = '1' OR status IS NULL)";
    }
    if (in_array('is_active', $userCols, true)) {
        $statusConditions[] = "(is_active = 1 OR is_active IS NULL)";
    }
    $statusSql = !empty($statusConditions) ? " AND (" . implode(" AND ", $statusConditions) . ")" : "";

    $sql = "SELECT * FROM users WHERE (" . implode(" OR ", $whereParts) . "){$statusSql} LIMIT 1";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($loginParams);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    $passHash = $user ? (string)($user['password_hash'] ?? $user['password'] ?? '') : '';
    if (!$user || $passHash === '' || !password_verify((string)$password, $passHash)) {
        margo_sso_log_audit($pdo, 'login_fail', [
            'user_id'     => $user ? (int)$user['id'] : null,
            'http_status' => 401,
            'ip_address'  => $ip,
            'detail'      => ['identifier' => substr($cleanId, 0, 80), 'method' => 'password'],
        ]);
        return ['success' => false, 'message' => 'Username, email, NISN/NIP, atau kata sandi yang Anda masukkan salah.'];
    }

    return establishUserSession($pdo, $user, 'password');
}

/**
 * Login Google OAuth 2.0 — ATURAN KETAT IDENTIK learn/auth.php:
 * - HANYA izinkan jika users.email_google sudah terisi / linked di halaman /profile!
 * - DILARANG auto-register akun baru dari sisi login!
 */
function loginUserByGoogle($emailGoogle, $googleId = '')
{
    $pdo = getDBConnection();
    margo_sso_ensure_schema($pdo);

    $ip = margo_sso_client_ip();
    $cleanEmail = strtolower(trim((string)$emailGoogle));
    $cleanGid   = trim((string)$googleId);

    if ($cleanEmail === '' || !filter_var($cleanEmail, FILTER_VALIDATE_EMAIL)) {
        return ['success' => false, 'message' => 'Format alamat email Google tidak valid.'];
    }

    $userCols = [];
    try {
        $userCols = $pdo->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) {
        $userCols = ['id', 'username', 'email', 'email_google', 'google_id', 'status', 'is_active', 'full_name'];
    }

    if (!in_array('email_google', $userCols, true)) {
        return ['success' => false, 'message' => 'Kolom tautan Google SSO (email_google) tidak tersedia pada tabel pengguna.'];
    }

    $statusConditions = [];
    if (in_array('status', $userCols, true)) {
        $statusConditions[] = "(status = 'active' OR status = '1' OR status IS NULL)";
    }
    if (in_array('is_active', $userCols, true)) {
        $statusConditions[] = "(is_active = 1 OR is_active IS NULL)";
    }
    $statusSql = !empty($statusConditions) ? " AND (" . implode(" AND ", $statusConditions) . ")" : "";

    // STRICT: HANYA cocokkan kolom users.email_google yang sudah tertaut!
    $stmt = $pdo->prepare("
        SELECT * FROM users
        WHERE email_google IS NOT NULL AND TRIM(email_google) != '' AND LOWER(email_google) = :email_g{$statusSql}
        LIMIT 1
    ");
    $stmt->execute(['email_g' => $cleanEmail]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        $hasUnlinkedEmail = false;
        try {
            if (in_array('email', $userCols, true)) {
                $stU = $pdo->prepare("SELECT id FROM users WHERE LOWER(email) = ? LIMIT 1");
                $stU->execute([$cleanEmail]);
                if ($stU->fetchColumn()) {
                    $hasUnlinkedEmail = true;
                }
            }
            if (!$hasUnlinkedEmail) {
                $stS = $pdo->prepare("SELECT id FROM siswa WHERE LOWER(gmail) = ? LIMIT 1");
                $stS->execute([$cleanEmail]);
                if ($stS->fetchColumn()) {
                    $hasUnlinkedEmail = true;
                }
            }
            if (!$hasUnlinkedEmail) {
                $stG = $pdo->prepare("SELECT id FROM guru WHERE LOWER(email) = ? LIMIT 1");
                $stG->execute([$cleanEmail]);
                if ($stG->fetchColumn()) {
                    $hasUnlinkedEmail = true;
                }
            }
        } catch (Throwable $eChk) {
            // Abaikan
        }

        margo_sso_log_audit($pdo, 'login_google_rejected', [
            'http_status' => 403,
            'ip_address'  => $ip,
            'detail'      => ['email' => $cleanEmail, 'unlinked_match' => $hasUnlinkedEmail],
        ]);

        if ($hasUnlinkedEmail) {
            return [
                'success' => false,
                'message' => 'Email (' . $cleanEmail . ') belum ditautkan ke Google SSO. Silakan masuk dengan Username & Password terlebih dahulu, lalu tautkan akun Google Anda di menu Profil Margo One.',
            ];
        }

        return [
            'success' => false,
            'message' => 'Akun Google (' . $cleanEmail . ') belum terdaftar atau belum ditautkan di menu Profil. Pendaftaran otomatis tidak diizinkan demi keamanan.',
        ];
    }

    // Simpan google_id bila belum terisi (tanpa mengubah email_google)
    if ($cleanGid !== '' && in_array('google_id', $userCols, true) && empty($user['google_id'])) {
        try {
            $pdo->prepare("UPDATE users SET google_id = ? WHERE id = ?")->execute([$cleanGid, (int)$user['id']]);
            $user['google_id'] = $cleanGid;
        } catch (Throwable $eGid) {
            // Non-fatal
        }
    }

    return establishUserSession($pdo, $user, 'google_oauth');
}

/**
 * Terbitkan Authorization Code sekali pakai (TTL 60 detik, PKCE S256)
 */
function margo_issue_auth_code(PDO $pdo, $clientId, $userId, $ssoSessionId, $redirectUri, $scope, $codeChallenge, $codeChallengeMethod = 'S256', $nonce = null)
{
    margo_sso_ensure_schema($pdo);

    if (mt_rand(1, 20) === 1) {
        $pdo->exec("DELETE FROM sso_auth_codes WHERE expires_at < DATE_SUB(NOW(), INTERVAL 10 MINUTE)");
    }

    $rawCode   = 'mc_' . bin2hex(random_bytes(32));
    $codeHash  = hash('sha256', $rawCode);
    $expiresAt = date('Y-m-d H:i:s', time() + (int)SSO_AUTH_CODE_TTL);

    $stmt = $pdo->prepare("
        INSERT INTO sso_auth_codes
        (code_hash, client_id, user_id, sso_session_id, redirect_uri, scope, code_challenge, code_challenge_method, nonce, expires_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $codeHash,
        (int)$clientId,
        (int)$userId,
        (int)$ssoSessionId,
        (string)$redirectUri,
        (string)$scope,
        (string)$codeChallenge,
        strtoupper((string)$codeChallengeMethod),
        $nonce !== '' ? $nonce : null,
        $expiresAt,
    ]);

    margo_sso_log_audit($pdo, 'code_issued', [
        'user_id'     => (int)$userId,
        'client_id'   => (int)$clientId,
        'http_status' => 302,
        'detail'      => [
            'sso_session_id' => (int)$ssoSessionId,
            'redirect_uri'   => (string)$redirectUri,
            'scope'          => (string)$scope,
        ],
    ]);

    return $rawCode;
}

/**
 * Simpan & baca parameter OAuth /authorize terenkripsi di session PHP saat redirect ke /login
 */
function margo_set_pending_oauth(array $params)
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return;
    }
    $params['created_at'] = time();
    $_SESSION['_margo_pending_oauth'] = margo_sso_encrypt_secret(json_encode($params, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
}

function margo_get_pending_oauth()
{
    if (session_status() !== PHP_SESSION_ACTIVE || empty($_SESSION['_margo_pending_oauth'])) {
        return null;
    }
    $plain = margo_sso_decrypt_secret((string)$_SESSION['_margo_pending_oauth']);
    if ($plain === '') {
        unset($_SESSION['_margo_pending_oauth']);
        return null;
    }
    $data = json_decode($plain, true);
    if (!is_array($data) || empty($data['client_id']) || (time() - (int)($data['created_at'] ?? 0)) > 900) {
        unset($_SESSION['_margo_pending_oauth']);
        return null;
    }
    return $data;
}

function margo_clear_pending_oauth()
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        unset($_SESSION['_margo_pending_oauth']);
    }
}

/**
 * Bangun payload JWT id_token & access_token bebas PII sensitif
 */
function margo_issue_token_bundle(PDO $pdo, array $client, array $identity, $ssoSessionId, $scope, $nonce = null, $familyId = null)
{
    $primaryKey = margo_sso_get_primary_jwk_key($pdo);
    $now        = time();
    $idExp      = $now + (int)SSO_ID_TOKEN_TTL;
    $accExp     = $now + (int)SSO_ACCESS_TOKEN_TTL;

    $idPayload = [
        'iss'           => SSO_ISSUER,
        'sub'           => (string)$identity['sub'],
        'aud'           => (string)$client['public_key'],
        'iat'           => $now,
        'nbf'           => $now,
        'exp'           => $idExp,
        'jti'           => margo_sso_uuid_v4(),
        'sid'           => (string)$ssoSessionId,
        'username'      => $identity['username'],
        'name'          => $identity['full_name'],
        'email'         => $identity['email'],
        'email_verified'=> (bool)$identity['email_verified'],
        'roles'         => $identity['roles'],
        'primary_role'  => $identity['primary_role'],
        'active_role'   => $identity['active_role'],
        'guru_id'       => $identity['guru_id'],
        'siswa_id'      => $identity['siswa_id'],
        'kelas_id'      => $identity['kelas_id'],
    ];
    if ($nonce !== null && $nonce !== '') {
        $idPayload['nonce'] = (string)$nonce;
    }

    $accessPayload = [
        'iss'          => SSO_ISSUER,
        'sub'          => (string)$identity['sub'],
        'aud'          => (string)$client['public_key'],
        'iat'          => $now,
        'nbf'          => $now,
        'exp'          => $accExp,
        'jti'          => margo_sso_uuid_v4(),
        'sid'          => (string)$ssoSessionId,
        'typ'          => 'access_token',
        'scope'        => (string)$scope,
        'roles'        => $identity['roles'],
        'primary_role' => $identity['primary_role'],
    ];

    $idToken     = margo_jwt_sign_rs256($idPayload, $primaryKey['private_pem'], $primaryKey['kid']);
    $accessToken = margo_jwt_sign_rs256($accessPayload, $primaryKey['private_pem'], $primaryKey['kid']);

    // Refresh Token dengan Family Rotation
    $rawRefresh   = 'mrt_' . bin2hex(random_bytes(32));
    $refreshHash  = hash('sha256', $rawRefresh);
    $family       = ($familyId !== null && $familyId !== '') ? (string)$familyId : margo_sso_uuid_v4();
    $refreshExpAt = date('Y-m-d H:i:s', $now + (int)SSO_REFRESH_TOKEN_TTL);

    $insRt = $pdo->prepare("
        INSERT INTO sso_refresh_tokens (token_hash, family_id, client_id, user_id, sso_session_id, scope, expires_at)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    $insRt->execute([
        $refreshHash,
        $family,
        (int)$client['id'],
        (int)$identity['user_id'],
        (int)$ssoSessionId,
        (string)$scope,
        $refreshExpAt,
    ]);

    return [
        'token_type'    => 'Bearer',
        'expires_in'    => (int)SSO_ACCESS_TOKEN_TTL,
        'access_token'  => $accessToken,
        'id_token'      => $idToken,
        'refresh_token' => $rawRefresh,
        'scope'         => (string)$scope,
        'sid'           => (string)$ssoSessionId,
        'user'          => $identity,
    ];
}

/**
 * Hapus sesi lokal PHP & cookie saja (tanpa menyentuh DB bila sudah revoked)
 */
function logoutUserLocalOnly()
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            if (!headers_sent()) {
                setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
            }
        }
        session_destroy();
    }
    if (php_sapi_name() !== 'cli' && !headers_sent()) {
        setcookie(SSO_SESSION_COOKIE, '', [
            'expires'  => time() - 42000,
            'path'     => '/',
            'secure'   => margo_is_https(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
}

/**
 * Logout penuh (Global Single Logout: cabut DB + SLO webhooks + bersihkan sesi lokal)
 */
function logoutUser($revokeAllDevices = false)
{
    $sid = isset($_SESSION['sso_session_id']) ? (int)$_SESSION['sso_session_id'] : 0;
    $uid = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;

    try {
        $pdo = getDBConnection();
        if ($revokeAllDevices && $uid > 0) {
            margo_revoke_all_user_sessions($pdo, $uid, 0, true);
        } elseif ($sid > 0) {
            margo_revoke_sso_session($pdo, $sid, $uid > 0 ? $uid : null, true);
        }
    } catch (Throwable $e) {
        // Tetap bersihkan sesi lokal
    }

    logoutUserLocalOnly();
}

function getRoleDashboardUrl($roleCode)
{
    if ((string)$roleCode === 'admin') {
        return BASE_URL . '/dashboard/admin/';
    }
    return BASE_URL . '/gateway';
}

function requireAuth()
{
    if (!isLoggedIn()) {
        header('Location: ' . BASE_URL . '/login?msg=auth_required');
        exit;
    }
}

function requireRole($requiredRole)
{
    requireAuth();
    $allowedRoles = is_array($requiredRole) ? $requiredRole : [$requiredRole];
    $matchedRole = null;
    foreach ($allowedRoles as $r) {
        if (hasRole($r)) {
            $matchedRole = $r;
            break;
        }
    }
    if ($matchedRole === null) {
        header('Location: ' . BASE_URL . '/gateway?error=forbidden');
        exit;
    }
    $GLOBALS['activeRole'] = $matchedRole;
}
