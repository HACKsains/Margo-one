<?php
/**
 * ==============================================================================
 * MARGO ONE — ILMUNA SSO · TOKEN & SESSION INTROSPECTION (POST /api/sso/introspect.php)
 * ==============================================================================
 * Memungkinkan aplikasi klien (learn.ilmuna.my.id / ilmuna.my.id) memeriksa
 * real-time apakah access_token / id_token / session_id masih aktif atau sudah
 * dicabut (SLO / Force Logout).
 * ==============================================================================
 */

if (!defined('ILMUNA_SKIP_GD_WAF_INTERSTITIAL')) {
    define('ILMUNA_SKIP_GD_WAF_INTERSTITIAL', true);
}

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../auth.php';

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'POST'));
if ($method !== 'POST') {
    margo_sso_emit_json(405, [
        'success' => false,
        'error'   => ['code' => 'METHOD_NOT_ALLOWED', 'message' => 'Endpoint introspect hanya menerima POST.'],
    ], ['Allow' => 'POST']);
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

$rawBody = (string)file_get_contents('php://input');
$auth = margo_sso_authenticate_client($pdo, [
    'method' => 'POST',
    'uri'    => $_SERVER['REQUEST_URI'] ?? '/api/sso/introspect.php',
    'query'  => $_GET,
    'body'   => $rawBody,
]);

if (!$auth['ok']) {
    margo_sso_emit_json((int)$auth['status'], [
        'success' => false,
        'error'   => ['code' => (string)$auth['code'], 'message' => (string)$auth['message']],
    ]);
}

$client     = $auth['client'];
$privateKey = $auth['private_key'];

$input = [];
$trimmed = ltrim($rawBody);
if ($trimmed !== '' && $trimmed[0] === '{') {
    $dec = json_decode($rawBody, true);
    if (is_array($dec)) {
        $input = $dec;
    }
} elseif ($rawBody !== '') {
    parse_str($rawBody, $input);
}
if (empty($input) && !empty($_POST)) {
    $input = $_POST;
}

$token = trim((string)($input['token'] ?? ''));
$sidIn = (int)($input['sid'] ?? $input['session_id'] ?? 0);

// Mode A: Introspeksi JWT (access_token / id_token)
if ($token !== '') {
    $activeKeys = margo_sso_get_active_jwk_keys($pdo);
    $ver = margo_jwt_verify_rs256($token, $activeKeys, SSO_ISSUER, null);
    if (!$ver['valid']) {
        margo_sso_emit_json(200, [
            'success' => true,
            'active'  => false,
            'reason'  => (string)$ver['code'],
        ], [], $privateKey);
    }

    $claims = $ver['payload'];
    $sid    = (int)($claims['sid'] ?? 0);
    $userId = (int)($claims['sub'] ?? 0);
    $sess   = ($sid > 0) ? margo_validate_sso_session($pdo, $sid, null) : null;

    if (!$sess || (int)$sess['user_id'] !== $userId) {
        margo_sso_emit_json(200, [
            'success' => true,
            'active'  => false,
            'reason'  => 'SESSION_REVOKED',
        ], [], $privateKey);
    }

    $identity = margo_resolve_user_identity($pdo, $userId, $sess['active_role']);
    if (!$identity) {
        margo_sso_emit_json(200, [
            'success' => true,
            'active'  => false,
            'reason'  => 'USER_INACTIVE',
        ], [], $privateKey);
    }

    margo_sso_emit_json(200, [
        'success'      => true,
        'active'       => true,
        'sub'          => $identity['sub'],
        'sid'          => (string)$sid,
        'username'     => $identity['username'],
        'roles'        => $identity['roles'],
        'active_role'  => $identity['active_role'],
        'exp'          => (int)($claims['exp'] ?? 0),
        'iat'          => (int)($claims['iat'] ?? 0),
        'iss'          => (string)($claims['iss'] ?? SSO_ISSUER),
        'aud'          => (string)($claims['aud'] ?? ''),
    ], [], $privateKey);
}

// Mode B: Introspeksi langsung session_id (sid)
if ($sidIn > 0) {
    $sess = margo_validate_sso_session($pdo, $sidIn, null);
    if (!$sess) {
        margo_sso_emit_json(200, [
            'success' => true,
            'active'  => false,
            'reason'  => 'SESSION_REVOKED_OR_EXPIRED',
        ], [], $privateKey);
    }
    $identity = margo_resolve_user_identity($pdo, (int)$sess['user_id'], $sess['active_role']);
    if (!$identity) {
        margo_sso_emit_json(200, [
            'success' => true,
            'active'  => false,
            'reason'  => 'USER_INACTIVE',
        ], [], $privateKey);
    }
    margo_sso_emit_json(200, [
        'success'     => true,
        'active'      => true,
        'sub'         => $identity['sub'],
        'sid'         => (string)$sidIn,
        'username'    => $identity['username'],
        'roles'       => $identity['roles'],
        'active_role' => $identity['active_role'],
        'expires_at'  => (string)$sess['expires_at'],
    ], [], $privateKey);
}

margo_sso_emit_json(400, [
    'success' => false,
    'error'   => ['code' => 'INVALID_REQUEST', 'message' => 'Sertakan parameter token atau sid/session_id.'],
], [], $privateKey);
