<?php
/**
 * ==============================================================================
 * MARGO ONE — ILMUNA SSO · BACK-CHANNEL SINGLE LOGOUT API (POST /api/sso/logout-callback.php)
 * ==============================================================================
 * Memungkinkan aplikasi klien terpercaya (learn / WordPress) memicu Global SLO
 * secara server-to-server bertanda tangan saat pengguna logout di aplikasi klien.
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
        'error'   => ['code' => 'METHOD_NOT_ALLOWED', 'message' => 'Hanya metode POST yang diizinkan.'],
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
    'uri'    => $_SERVER['REQUEST_URI'] ?? '/api/sso/logout-callback.php',
    'query'  => $_GET,
    'body'   => $rawBody,
]);

if (!$auth['ok']) {
    margo_sso_emit_json((int)$auth['status'], [
        'success' => false,
        'error'   => ['code' => (string)$auth['code'], 'message' => (string)$auth['message']],
    ]);
}

$privateKey = $auth['private_key'];
$input = json_decode($rawBody, true);
if (!is_array($input)) {
    parse_str($rawBody, $input);
}

$sid    = (int)($input['sid'] ?? $input['session_id'] ?? 0);
$userId = (int)($input['user_id'] ?? 0);
$all    = !empty($input['revoke_all']);

if ($all && $userId > 0) {
    $cnt = margo_revoke_all_user_sessions($pdo, $userId, 0, true);
    margo_sso_emit_json(200, [
        'success'       => true,
        'revoked_count' => $cnt,
        'message'       => 'Seluruh sesi pengguna berhasil dicabut secara global.',
    ], [], $privateKey);
}

if ($sid > 0) {
    margo_revoke_sso_session($pdo, $sid, $userId > 0 ? $userId : null, true);
    margo_sso_emit_json(200, [
        'success' => true,
        'sid'     => (string)$sid,
        'message' => 'Sesi global SSO berhasil dicabut (SLO).',
    ], [], $privateKey);
}

margo_sso_emit_json(400, [
    'success' => false,
    'error'   => ['code' => 'INVALID_REQUEST', 'message' => 'Sertakan parameter sid atau user_id + revoke_all=1.'],
], [], $privateKey);
