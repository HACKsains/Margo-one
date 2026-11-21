<?php
/**
 * ==============================================================================
 * MARGO ONE — ILMUNA SSO · OIDC USERINFO ENDPOINT (GET /api/sso/userinfo.php)
 * ==============================================================================
 * Memvalidasi Bearer access_token (JWT RS256) + memastikan sso_sessions aktif
 * -> mengembalikan profil terkini & roles[], guru_id, siswa_id, kelas_id
 *    BEBAS PII sensitif (nol password_hash, alamat, no_hp, private_key).
 * ==============================================================================
 */

if (!defined('ILMUNA_SKIP_GD_WAF_INTERSTITIAL')) {
    define('ILMUNA_SKIP_GD_WAF_INTERSTITIAL', true);
}

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../auth.php';

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($method !== 'GET' && $method !== 'POST') {
    margo_sso_emit_json(405, [
        'success' => false,
        'error'   => ['code' => 'METHOD_NOT_ALLOWED', 'message' => 'Gunakan metode GET.'],
    ], ['Allow' => 'GET, POST']);
}

try {
    $pdo = getDBConnection();
    $pdo->exec("SET time_zone = '+07:00'");
} catch (Throwable $e) {
    margo_sso_emit_json(503, [
        'success' => false,
        'error'   => ['code' => 'DATABASE_UNAVAILABLE', 'message' => 'Database tidak tersedia.'],
    ]);
}

$headers = margo_sso_get_headers();
$privateKeyForResp = null;

// Jika klien menyertakan header Zero-Trust X-Ilmuna-Public-Key, verifikasi penuh juga signature klien
if (!empty($headers['x-ilmuna-public-key'])) {
    $rawBody = ($method === 'POST') ? (string)file_get_contents('php://input') : '';
    $clientAuth = margo_sso_authenticate_client($pdo, [
        'method' => $method,
        'uri'    => $_SERVER['REQUEST_URI'] ?? '/api/sso/userinfo.php',
        'query'  => $_GET,
        'body'   => $rawBody,
    ]);
    if (!$clientAuth['ok']) {
        margo_sso_emit_json((int)$clientAuth['status'], [
            'success' => false,
            'error'   => ['code' => (string)$clientAuth['code'], 'message' => (string)$clientAuth['message']],
        ]);
    }
    $privateKeyForResp = $clientAuth['private_key'];
}

// Ambil Bearer token dari Header Authorization
$authHeader = isset($headers['authorization']) ? trim((string)$headers['authorization']) : '';
$bearerToken = '';
if (stripos($authHeader, 'Bearer ') === 0) {
    $bearerToken = trim(substr($authHeader, 7));
}

if ($bearerToken === '') {
    margo_sso_emit_json(401, [
        'success' => false,
        'error'   => ['code' => 'MISSING_BEARER_TOKEN', 'message' => 'Header Authorization: Bearer <access_token> wajib disertakan.'],
    ], ['WWW-Authenticate' => 'Bearer realm="MargoOne"'], $privateKeyForResp);
}

$activeKeys = margo_sso_get_active_jwk_keys($pdo);
$ver = margo_jwt_verify_rs256($bearerToken, $activeKeys, SSO_ISSUER, null);
if (!$ver['valid']) {
    margo_sso_emit_json(401, [
        'success' => false,
        'error'   => ['code' => (string)$ver['code'], 'message' => (string)$ver['message']],
    ], ['WWW-Authenticate' => 'Bearer error="invalid_token"'], $privateKeyForResp);
}

$claims = $ver['payload'];
$userId = (int)($claims['sub'] ?? 0);
$sid    = (int)($claims['sid'] ?? 0);

if ($userId <= 0 || $sid <= 0) {
    margo_sso_emit_json(401, [
        'success' => false,
        'error'   => ['code' => 'INVALID_TOKEN_CLAIMS', 'message' => 'Klaim sub atau sid pada token tidak valid.'],
    ], [], $privateKeyForResp);
}

// Verifikasi real-time sesi global SSO belum di-revoke
$sess = margo_validate_sso_session($pdo, $sid, null);
if (!$sess || (int)$sess['user_id'] !== $userId) {
    margo_sso_emit_json(401, [
        'success' => false,
        'error'   => ['code' => 'SESSION_REVOKED', 'message' => 'Sesi SSO telah dicabut atau kedaluwarsa.'],
    ], [], $privateKeyForResp);
}

$identity = margo_resolve_user_identity($pdo, $userId, $sess['active_role']);
if (!$identity) {
    margo_sso_emit_json(403, [
        'success' => false,
        'error'   => ['code' => 'USER_INACTIVE', 'message' => 'Akun pengguna tidak aktif.'],
    ], [], $privateKeyForResp);
}

$responsePayload = array_merge(['success' => true, 'sid' => (string)$sid], $identity);
margo_sso_emit_json(200, $responsePayload, [], $privateKeyForResp);
