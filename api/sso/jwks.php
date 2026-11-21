<?php
/**
 * ==============================================================================
 * MARGO ONE — ILMUNA SSO · JWK SET PUBLIC KEY ENDPOINT (GET /api/sso/jwks.php)
 * ==============================================================================
 * Publikasikan kunci publik RSA-2048 aktif dalam format standar RFC 7517
 * agar aplikasi klien dapat memverifikasi JWT RS256 secara lokal tanpa rahasia.
 * ==============================================================================
 */

if (!defined('ILMUNA_SKIP_GD_WAF_INTERSTITIAL')) {
    define('ILMUNA_SKIP_GD_WAF_INTERSTITIAL', true);
}

require_once __DIR__ . '/../../config.php';
require_once BASE_PATH . '/includes/sso-security.php';

try {
    $pdo = getDBConnection();
    $pdo->exec("SET time_zone = '+07:00'");
    $activeKeys = margo_sso_get_active_jwk_keys($pdo);
    $jwks = margo_jwt_build_jwks($activeKeys);

    margo_sso_emit_json(
        200,
        $jwks,
        ['Access-Control-Allow-Origin' => '*'],
        null,
        'public, max-age=3600'
    );
} catch (Throwable $e) {
    margo_sso_emit_json(503, [
        'keys'  => [],
        'error' => 'JWKS sementara tidak tersedia.',
    ]);
}
