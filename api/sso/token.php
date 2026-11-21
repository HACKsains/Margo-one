<?php
/**
 * ==============================================================================
 * MARGO ONE — ILMUNA SSO · OAUTH 2.0 TOKEN ENDPOINT (POST /api/sso/token.php)
 * ==============================================================================
 * Proteksi Zero-Trust:
 *   - Public Key + Private Key (HMAC-SHA256 / RSA-2048 / DUAL)
 *   - Timestamp +/- 300s + Single-Use Nonce (Anti-Replay)
 *   - Domain/IP Allowlist + Sliding-Window Rate Limiter
 *   - Signed JSON Response (X-Ilmuna-Response-Timestamp & X-Ilmuna-Response-Signature)
 *
 * Grant Types:
 *   1. authorization_code (PKCE S256 wajib, atomic single-use code burn)
 *   2. refresh_token (Family Rotation + Reuse Detection -> revoke seluruh family)
 * ==============================================================================
 */

if (!defined('ILMUNA_SKIP_GD_WAF_INTERSTITIAL')) {
    define('ILMUNA_SKIP_GD_WAF_INTERSTITIAL', true);
}

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../auth.php';

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'POST'));
if ($method === 'OPTIONS') {
    http_response_code(204);
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, X-Ilmuna-Public-Key, X-Ilmuna-Timestamp, X-Ilmuna-Nonce, X-Ilmuna-Signature, X-Ilmuna-RSA-Signature, X-Ilmuna-Site');
    exit;
}

if ($method !== 'POST') {
    margo_sso_emit_json(405, [
        'success' => false,
        'error'   => [
            'code'    => 'METHOD_NOT_ALLOWED',
            'message' => 'Endpoint /api/sso/token.php hanya menerima metode POST.',
        ],
    ], ['Allow' => 'POST, OPTIONS']);
}

try {
    $pdo = getDBConnection();
    $pdo->exec("SET time_zone = '+07:00'");
} catch (Throwable $e) {
    margo_sso_emit_json(503, [
        'success' => false,
        'error'   => ['code' => 'DATABASE_UNAVAILABLE', 'message' => 'Koneksi basis data sementara tidak tersedia.'],
    ]);
}

$rawBody = (string)file_get_contents('php://input');

// 1. Autentikasi Klien Zero-Trust (Public Key + Private Key + Nonce + Timestamp + Domain/IP + Rate Limit)
$auth = margo_sso_authenticate_client($pdo, [
    'method' => 'POST',
    'uri'    => $_SERVER['REQUEST_URI'] ?? '/api/sso/token.php',
    'query'  => $_GET,
    'body'   => $rawBody,
]);

$extraHeaders = [];
if (isset($auth['rate_limit']) && is_array($auth['rate_limit'])) {
    $extraHeaders['X-RateLimit-Limit']     = (string)$auth['rate_limit']['limit'];
    $extraHeaders['X-RateLimit-Remaining'] = (string)$auth['rate_limit']['remaining'];
    $extraHeaders['X-RateLimit-Reset']     = (string)$auth['rate_limit']['reset_in'];
    if (!$auth['rate_limit']['allowed']) {
        $extraHeaders['Retry-After'] = (string)$auth['rate_limit']['reset_in'];
    }
}

if (!$auth['ok']) {
    $meta = isset($auth['meta']) && is_array($auth['meta']) ? $auth['meta'] : [];
    $evt = ($auth['code'] === 'REPLAY_DETECTED') ? 'replay_blocked' : 'token_auth_failed';
    margo_sso_log_audit($pdo, $evt, [
        'client_id'   => $meta['client_id'] ?? null,
        'http_status' => (int)$auth['status'],
        'ip_address'  => $meta['client_ip'] ?? margo_sso_client_ip(),
        'detail'      => ['code' => $auth['code'], 'public_key' => $meta['public_key'] ?? null],
    ]);

    margo_sso_emit_json((int)$auth['status'], [
        'success' => false,
        'error'   => [
            'code'    => (string)$auth['code'],
            'message' => (string)$auth['message'],
        ],
        'server_time' => time(),
    ], $extraHeaders);
}

$client     = $auth['client'];
$privateKey = $auth['private_key'];

// 2. Parse body (mendukung JSON & application/x-www-form-urlencoded)
$input = [];
$trimmedBody = ltrim($rawBody);
if ($trimmedBody !== '' && ($trimmedBody[0] === '{' || $trimmedBody[0] === '[')) {
    $decoded = json_decode($rawBody, true);
    if (is_array($decoded)) {
        $input = $decoded;
    }
} elseif ($rawBody !== '') {
    parse_str($rawBody, $input);
}
if (empty($input) && !empty($_POST)) {
    $input = $_POST;
}

$grantType = trim((string)($input['grant_type'] ?? ''));

// ==============================================================================
// GRANT TYPE 1: authorization_code (+ PKCE S256 + Single-Use Atomic Burn)
// ==============================================================================
if ($grantType === 'authorization_code') {
    $code         = trim((string)($input['code'] ?? ''));
    $redirectUri  = trim((string)($input['redirect_uri'] ?? ''));
    $codeVerifier = trim((string)($input['code_verifier'] ?? ''));

    if ($code === '' || $redirectUri === '' || $codeVerifier === '') {
        margo_sso_emit_json(400, [
            'success' => false,
            'error'   => [
                'code'    => 'INVALID_REQUEST',
                'message' => 'Parameter code, redirect_uri, dan code_verifier wajib disertakan.',
            ],
        ], $extraHeaders, $privateKey);
    }

    $codeHash = hash('sha256', $code);
    $stCode = $pdo->prepare("SELECT * FROM sso_auth_codes WHERE code_hash = ? LIMIT 1");
    $stCode->execute([$codeHash]);
    $authCodeRow = $stCode->fetch(PDO::FETCH_ASSOC);

    if (!$authCodeRow) {
        margo_sso_log_audit($pdo, 'token_invalid_code', [
            'client_id'   => (int)$client['id'],
            'http_status' => 400,
        ]);
        margo_sso_emit_json(400, [
            'success' => false,
            'error'   => ['code' => 'INVALID_GRANT', 'message' => 'Authorization code tidak ditemukan atau tidak valid.'],
        ], $extraHeaders, $privateKey);
    }

    if ((int)$authCodeRow['client_id'] !== (int)$client['id']) {
        margo_sso_log_audit($pdo, 'token_client_mismatch', [
            'client_id'   => (int)$client['id'],
            'user_id'     => (int)$authCodeRow['user_id'],
            'http_status' => 403,
        ]);
        margo_sso_emit_json(403, [
            'success' => false,
            'error'   => ['code' => 'CLIENT_MISMATCH', 'message' => 'Authorization code bukan milik klien ini.'],
        ], $extraHeaders, $privateKey);
    }

    // Anti-Replay Authorization Code (dipakai ulang)
    if ($authCodeRow['used_at'] !== null) {
        margo_sso_log_audit($pdo, 'code_replay_blocked', [
            'client_id'   => (int)$client['id'],
            'user_id'     => (int)$authCodeRow['user_id'],
            'http_status' => 400,
            'detail'      => ['code_id' => (int)$authCodeRow['id']],
        ]);
        margo_sso_emit_json(400, [
            'success' => false,
            'error'   => ['code' => 'CODE_ALREADY_USED', 'message' => 'Authorization code sudah pernah digunakan dan tidak dapat dipakai ulang.'],
        ], $extraHeaders, $privateKey);
    }

    // Cek expired (TTL 60 detik)
    if (strtotime((string)$authCodeRow['expires_at']) < time()) {
        margo_sso_emit_json(400, [
            'success' => false,
            'error'   => ['code' => 'CODE_EXPIRED', 'message' => 'Authorization code telah kedaluwarsa.'],
        ], $extraHeaders, $privateKey);
    }

    // Validasi exact redirect_uri
    if (!hash_equals((string)$authCodeRow['redirect_uri'], $redirectUri)) {
        margo_sso_emit_json(400, [
            'success' => false,
            'error'   => ['code' => 'REDIRECT_URI_MISMATCH', 'message' => 'Parameter redirect_uri tidak sesuai dengan saat otorisasi.'],
        ], $extraHeaders, $privateKey);
    }

    // Validasi PKCE S256
    if (!margo_pkce_verify($codeVerifier, (string)$authCodeRow['code_challenge'])) {
        margo_sso_log_audit($pdo, 'pkce_verification_failed', [
            'client_id'   => (int)$client['id'],
            'user_id'     => (int)$authCodeRow['user_id'],
            'http_status' => 400,
        ]);
        margo_sso_emit_json(400, [
            'success' => false,
            'error'   => ['code' => 'INVALID_PKCE', 'message' => 'Verifikasi PKCE S256 gagal (code_verifier tidak cocok dengan code_challenge).'],
        ], $extraHeaders, $privateKey);
    }

    // Pastikan sesi SSO global masih aktif
    $sess = margo_validate_sso_session($pdo, (int)$authCodeRow['sso_session_id'], null);
    if (!$sess) {
        margo_sso_emit_json(401, [
            'success' => false,
            'error'   => ['code' => 'SESSION_REVOKED', 'message' => 'Sesi SSO pengguna telah berakhir atau dicabut.'],
        ], $extraHeaders, $privateKey);
    }

    // Atomic Burn authorization_code agar tidak bisa terjadi race condition replay
    $burnStmt = $pdo->prepare("UPDATE sso_auth_codes SET used_at = NOW() WHERE id = ? AND used_at IS NULL");
    $burnStmt->execute([(int)$authCodeRow['id']]);
    if ($burnStmt->rowCount() !== 1) {
        margo_sso_emit_json(400, [
            'success' => false,
            'error'   => ['code' => 'CODE_ALREADY_USED', 'message' => 'Authorization code sudah pernah digunakan.'],
        ], $extraHeaders, $privateKey);
    }

    $identity = margo_resolve_user_identity($pdo, (int)$authCodeRow['user_id'], $sess['active_role']);
    if (!$identity) {
        margo_sso_emit_json(403, [
            'success' => false,
            'error'   => ['code' => 'USER_INACTIVE', 'message' => 'Akun pengguna tidak aktif atau tidak ditemukan.'],
        ], $extraHeaders, $privateKey);
    }

    $bundle = margo_issue_token_bundle(
        $pdo,
        $client,
        $identity,
        (int)$authCodeRow['sso_session_id'],
        (string)$authCodeRow['scope'],
        $authCodeRow['nonce']
    );

    margo_sso_log_audit($pdo, 'token_issued', [
        'client_id'   => (int)$client['id'],
        'user_id'     => (int)$identity['user_id'],
        'http_status' => 200,
        'detail'      => [
            'grant_type' => 'authorization_code',
            'sid'        => (int)$authCodeRow['sso_session_id'],
            'sig_mode'   => $auth['sig_mode'],
        ],
    ]);

    margo_sso_emit_json(200, array_merge(['success' => true], $bundle), $extraHeaders, $privateKey);
}

// ==============================================================================
// GRANT TYPE 2: refresh_token (+ Family Rotation & Reuse Detection)
// ==============================================================================
if ($grantType === 'refresh_token') {
    $rawRefresh = trim((string)($input['refresh_token'] ?? ''));
    if ($rawRefresh === '') {
        margo_sso_emit_json(400, [
            'success' => false,
            'error'   => ['code' => 'INVALID_REQUEST', 'message' => 'Parameter refresh_token wajib disertakan.'],
        ], $extraHeaders, $privateKey);
    }

    $rtHash = hash('sha256', $rawRefresh);
    $stRt = $pdo->prepare("SELECT * FROM sso_refresh_tokens WHERE token_hash = ? LIMIT 1");
    $stRt->execute([$rtHash]);
    $rtRow = $stRt->fetch(PDO::FETCH_ASSOC);

    if (!$rtRow) {
        margo_sso_emit_json(401, [
            'success' => false,
            'error'   => ['code' => 'INVALID_REFRESH_TOKEN', 'message' => 'Refresh token tidak dikenali.'],
        ], $extraHeaders, $privateKey);
    }

    if ((int)$rtRow['client_id'] !== (int)$client['id']) {
        margo_sso_emit_json(403, [
            'success' => false,
            'error'   => ['code' => 'CLIENT_MISMATCH', 'message' => 'Refresh token bukan milik klien ini.'],
        ], $extraHeaders, $privateKey);
    }

    // DETEKSI REUSE! Jika token sudah pernah dirotasi -> CABUT SELURUH FAMILY!
    if ($rtRow['rotated_at'] !== null) {
        $pdo->prepare("UPDATE sso_refresh_tokens SET revoked_at = NOW() WHERE family_id = ? AND revoked_at IS NULL")
            ->execute([(string)$rtRow['family_id']]);

        margo_sso_log_audit($pdo, 'refresh_token_reuse_detected', [
            'client_id'   => (int)$client['id'],
            'user_id'     => (int)$rtRow['user_id'],
            'http_status' => 401,
            'detail'      => [
                'family_id'      => (string)$rtRow['family_id'],
                'sso_session_id' => (int)$rtRow['sso_session_id'],
                'action'         => 'entire_family_revoked',
            ],
        ]);

        margo_sso_emit_json(401, [
            'success' => false,
            'error'   => [
                'code'    => 'TOKEN_REUSE_DETECTED',
                'message' => 'Pemakaian ulang refresh token lama terdeteksi! Seluruh rantai token (family) otomatis dicabut demi keamanan.',
            ],
        ], $extraHeaders, $privateKey);
    }

    if ($rtRow['revoked_at'] !== null) {
        margo_sso_emit_json(401, [
            'success' => false,
            'error'   => ['code' => 'REFRESH_TOKEN_REVOKED', 'message' => 'Refresh token telah dicabut.'],
        ], $extraHeaders, $privateKey);
    }

    if (strtotime((string)$rtRow['expires_at']) < time()) {
        margo_sso_emit_json(401, [
            'success' => false,
            'error'   => ['code' => 'REFRESH_TOKEN_EXPIRED', 'message' => 'Refresh token telah kedaluwarsa.'],
        ], $extraHeaders, $privateKey);
    }

    $sess = margo_validate_sso_session($pdo, (int)$rtRow['sso_session_id'], null);
    if (!$sess) {
        margo_sso_emit_json(401, [
            'success' => false,
            'error'   => ['code' => 'SESSION_REVOKED', 'message' => 'Sesi global SSO telah berakhir atau dicabut.'],
        ], $extraHeaders, $privateKey);
    }

    // Tandai token lama sudah dirotasi secara atomik
    $rotStmt = $pdo->prepare("UPDATE sso_refresh_tokens SET rotated_at = NOW() WHERE id = ? AND rotated_at IS NULL AND revoked_at IS NULL");
    $rotStmt->execute([(int)$rtRow['id']]);
    if ($rotStmt->rowCount() !== 1) {
        $pdo->prepare("UPDATE sso_refresh_tokens SET revoked_at = NOW() WHERE family_id = ? AND revoked_at IS NULL")
            ->execute([(string)$rtRow['family_id']]);
        margo_sso_emit_json(401, [
            'success' => false,
            'error'   => ['code' => 'TOKEN_REUSE_DETECTED', 'message' => 'Konflik rotasi refresh token terdeteksi. Seluruh family dicabut.'],
        ], $extraHeaders, $privateKey);
    }

    $identity = margo_resolve_user_identity($pdo, (int)$rtRow['user_id'], $sess['active_role']);
    if (!$identity) {
        margo_sso_emit_json(403, [
            'success' => false,
            'error'   => ['code' => 'USER_INACTIVE', 'message' => 'Akun pengguna tidak aktif.'],
        ], $extraHeaders, $privateKey);
    }

    $bundle = margo_issue_token_bundle(
        $pdo,
        $client,
        $identity,
        (int)$rtRow['sso_session_id'],
        (string)($rtRow['scope'] ?? 'openid profile email roles'),
        null,
        (string)$rtRow['family_id']
    );

    margo_sso_log_audit($pdo, 'token_refreshed', [
        'client_id'   => (int)$client['id'],
        'user_id'     => (int)$identity['user_id'],
        'http_status' => 200,
        'detail'      => ['family_id' => (string)$rtRow['family_id'], 'sid' => (int)$rtRow['sso_session_id']],
    ]);

    margo_sso_emit_json(200, array_merge(['success' => true], $bundle), $extraHeaders, $privateKey);
}

margo_sso_emit_json(400, [
    'success' => false,
    'error'   => [
        'code'    => 'UNSUPPORTED_GRANT_TYPE',
        'message' => 'Grant type tidak didukung. Gunakan authorization_code atau refresh_token.',
    ],
], $extraHeaders, $privateKey);
