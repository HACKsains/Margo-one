<?php
/**
 * ==============================================================================
 * MARGO ONE — ILMUNA SSO · OAUTH 2.0 AUTHORIZATION ENDPOINT (/authorize)
 * ==============================================================================
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once BASE_PATH . '/includes/sso-authorize.php';

function margo_authorize_render_error($httpCode, $errorCode, $title, $message)
{
    http_response_code((int)$httpCode);
    header('Content-Type: text/html; charset=utf-8');
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    ?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?> — Margo One SSO</title>
    <?= renderFavicon() ?>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@600;700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <?= renderIconifyScript() ?>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Plus Jakarta Sans', sans-serif; background: #F8FAFC; color: #334155; min-height: 100dvh; display: flex; align-items: center; justify-content: center; padding: 20px; }
        .card { width: 100%; max-width: 480px; background: #FFFFFF; border: 1px solid #E2E8F0; border-radius: 28px 0 20px 0; padding: 28px 26px; box-shadow: 0 20px 45px -14px rgba(29, 59, 94, 0.16); text-align: center; }
        .icon-box { width: 60px; height: 60px; margin: 0 auto 14px; border-radius: 16px; background: rgba(239, 68, 68, 0.10); border: 1px solid rgba(239, 68, 68, 0.25); color: #DC2626; display: flex; align-items: center; justify-content: center; }
        .code-pill { display: inline-block; margin-bottom: 10px; font-family: ui-monospace, Consolas, monospace; font-size: 11px; font-weight: 700; background: #FEF2F2; color: #B91C1C; border: 1px solid #FECACA; padding: 3px 10px; border-radius: 999px; }
        h1 { font-family: 'Outfit', sans-serif; font-size: 1.3rem; color: #1D3B5E; margin-bottom: 8px; }
        p { font-size: 0.9rem; color: #64748B; line-height: 1.6; margin-bottom: 20px; }
        .btn { display: inline-flex; align-items: center; gap: 8px; padding: 11px 18px; border-radius: 12px; background: linear-gradient(135deg, #1D3B5E, #0D7A87); color: #FFFFFF; text-decoration: none; font-family: 'Outfit', sans-serif; font-weight: 700; font-size: 0.9rem; }
    </style>
</head>
<body>
    <div class="card">
        <div class="icon-box"><iconify-icon icon="solar:shield-warning-bold" width="30"></iconify-icon></div>
        <div class="code-pill"><?= htmlspecialchars($errorCode, ENT_QUOTES, 'UTF-8') ?></div>
        <h1><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></h1>
        <p><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></p>
        <a href="<?= htmlspecialchars(BASE_URL . '/gateway', ENT_QUOTES, 'UTF-8') ?>" class="btn">
            <iconify-icon icon="solar:home-2-bold" width="18"></iconify-icon>
            <span>Kembali ke Portal Margo One</span>
        </a>
    </div>
</body>
</html>
    <?php
    exit;
}

try {
    $pdo = getDBConnection();
    $isResume = isset($_GET['resume']) && $_GET['resume'] === '1';

    if ($isResume) {
        $params = margo_get_pending_oauth();
        if (!$params) {
            header('Location: ' . BASE_URL . '/gateway');
            exit;
        }
        $params['prompt'] = '';
    } else {
        $params = $_GET;
    }

    $loggedUser = isLoggedIn() ? getCurrentUser() : null;
    $outcome = margo_process_authorize_request($pdo, $params, $loggedUser);
} catch (Throwable $e) {
    error_log('[MargoOne] /authorize fatal error: ' . $e->getMessage());
    margo_authorize_render_error(
        503,
        'SSO_BACKEND_ERROR',
        'Layanan Otorisasi Sementara Tidak Tersedia',
        'Server Margo One SSO gagal terhubung ke basis data atau layanan keamanan. Pastikan database telah aktif dan konfigurasi config.local.php sudah benar.'
    );
}

if ($outcome['action'] === 'error_page') {
    margo_clear_pending_oauth();
    margo_authorize_render_error($outcome['http_status'], $outcome['code'], $outcome['title'], $outcome['message']);
}

if ($outcome['action'] === 'require_login') {
    margo_set_pending_oauth($outcome['pending']);
    $loginUrl = BASE_URL . '/login' . (($outcome['prompt'] ?? '') === 'login' ? '?prompt=login' : '');
    header('Location: ' . $loginUrl);
    exit;
}

if ($outcome['action'] === 'redirect_error' || $outcome['action'] === 'redirect_code') {
    margo_clear_pending_oauth();
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');
    header('Location: ' . $outcome['url']);
    exit;
}
