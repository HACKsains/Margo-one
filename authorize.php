<?php
/**
 * ==============================================================================
 * MARGO ONE — ILMUNA SSO · OAUTH 2.0 AUTHORIZATION & ACCOUNT CONFIRMATION (/authorize)
 * ==============================================================================
 * Menampilkan layar konfirmasi akun ("Anda akan login sebagai: ... [Lanjutkan]
 * / [Login dengan akun lain]") dengan desain Split-Panel Overlap Lumina Gold
 * sebelum menerbitkan authorization_code ke aplikasi klien.
 *
 * 100% kompatibel PHP 7.4 (ea-php74) hingga PHP 8.3.
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
        .icon-box { width: 60px; height: 60px; margin: 0 auto 14px; border-radius: 16px; background: rgba(29, 59, 94, 0.10); border: 1px solid rgba(29, 59, 94, 0.25); color: #1D3B5E; display: flex; align-items: center; justify-content: center; }
        .code-pill { display: inline-block; margin-bottom: 10px; font-family: ui-monospace, Consolas, monospace; font-size: 11px; font-weight: 700; background: rgba(13, 122, 135, 0.10); color: #0D7A87; border: 1px solid rgba(13, 122, 135, 0.25); padding: 3px 10px; border-radius: 999px; }
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

/**
 * Render Layar Konfirmasi Akun SSO (Split-Panel Overlap Lumina Gold)
 * "Margo One x [Aplikasi Klien] — Anda akan login sebagai: [Nama / Email]"
 * Tombol: [Lanjutkan] & [Login dengan akun lain]
 */
function margo_authorize_render_confirm(array $client, array $user, array $pending)
{
    http_response_code(200);
    header('Content-Type: text/html; charset=utf-8');
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store, no-cache, must-revalidate');

    $clientName = (string)($client['client_name'] ?? 'Aplikasi Ekosistem');
    $clientIcon = !empty($client['app_icon']) ? (string)$client['app_icon'] : 'solar:widget-5-bold';
    $clientLogo = margo_resolve_app_logo_url($client['app_logo'] ?? '', $client['app_icon'] ?? '');
    $clientHost = parse_url((string)($client['app_url'] ?? ''), PHP_URL_HOST) ?: (string)($client['app_url'] ?? '');

    $fullName   = margo_mb_strtoupper(trim((string)($user['full_name'] ?: $user['username'] ?: 'PENGGUNA')));
    $subIdent   = !empty($user['email']) ? (string)$user['email'] : ('@' . (string)($user['username'] ?? ''));
    $activeRole = (string)($user['active_role'] ?? 'siswa');
    $roleLabels = [
        'admin'       => 'Administrator',
        'guru'        => 'Guru Pengampu',
        'ketua_kelas' => 'Ketua Kelas',
        'siswa'       => 'Siswa',
    ];
    $roleLabel = isset($roleLabels[$activeRole]) ? $roleLabels[$activeRole] : ucfirst($activeRole);

    $parts = preg_split('/\s+/', trim((string)($user['full_name'] ?: $user['username'])));
    $inisial = '';
    if (!empty($parts)) {
        $inisial .= margo_mb_substr($parts[0], 0, 1);
        if (isset($parts[1])) {
            $inisial .= margo_mb_substr($parts[1], 0, 1);
        }
    }
    $inisial = margo_mb_strtoupper($inisial ?: 'MO');

    $queryParams = [
        'client_id'             => $pending['client_id'],
        'redirect_uri'          => $pending['redirect_uri'],
        'response_type'         => 'code',
        'scope'                 => $pending['scope'],
        'state'                 => $pending['state'],
        'nonce'                 => $pending['nonce'],
        'code_challenge'        => $pending['code_challenge'],
        'code_challenge_method' => $pending['code_challenge_method'],
    ];
    $continueUrl = BASE_URL . '/authorize?' . http_build_query(array_merge($queryParams, ['continue' => '1']), '', '&', PHP_QUERY_RFC3986);
    $switchUrl   = BASE_URL . '/authorize?' . http_build_query(array_merge($queryParams, ['switch_account' => '1']), '', '&', PHP_QUERY_RFC3986);
    ?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="robots" content="noindex, nofollow">
    <title>Margo One × <?= htmlspecialchars($clientName, ENT_QUOTES, 'UTF-8') ?></title>
    <?= renderFavicon() ?>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@500;600;700;800;900&family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <?= renderIconifyScript() ?>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        :root {
            --navy: #1D3B5E;
            --navy-dark: #1D3B5E;
            --teal: #0D7A87;
            --sky: #52B2DC;
            --gold: #52B2DC;
            --emerald: #0D7A87;
            --font-h: 'Outfit', sans-serif;
            --font-b: 'Plus Jakarta Sans', sans-serif;
        }
        iconify-icon { display: inline-block; width: 1em; height: 1em; vertical-align: -0.125em; }
        html, body {
            height: 100%;
            overflow: hidden;
            font-family: var(--font-b);
            background: #F4F7FA;
            color: #334155;
            -webkit-font-smoothing: antialiased;
        }
        body {
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 14px;
            position: relative;
        }
        .page-transition {
            position: fixed; top: 50%; left: 50%; width: 300vmax; height: 300vmax;
            margin-top: -150vmax; margin-left: -150vmax; background: #E8EFF7; z-index: 9999;
            transform-origin: center; transform: rotate(-225deg) translateY(85%);
            transition: transform 0.6s cubic-bezier(0.4, 0, 0.2, 1); pointer-events: none;
            box-shadow: 0 -20px 60px rgba(0,0,0,0.1); will-change: transform;
        }
        .page-transition::before { content: ''; position: absolute; top: 10vh; left: 0; width: 100%; height: 100%; background: #52B2DC; box-shadow: 0 -15px 35px rgba(0,0,0,0.08); }
        .page-transition::after  { content: ''; position: absolute; top: 20vh; left: 0; width: 100%; height: 100%; background: #0D7A87; box-shadow: 0 -15px 35px rgba(0,0,0,0.1); }
        .page-transition.active  { transform: rotate(135deg) translateY(-10%); }

        #particleCanvas { position: fixed; inset: 0; width: 100%; height: 100%; pointer-events: none; z-index: 2; }
        .glow-orb {
            position: absolute; border-radius: 50%; background: rgba(13, 122, 135, 0.11);
            filter: blur(100px); pointer-events: none; z-index: 1; width: 320px; height: 320px;
        }
        @media (min-width: 768px) { .glow-orb { width: 560px; height: 560px; } }
        .glow-orb-1 { top: -10%; left: 8%; }
        .glow-orb-2 { bottom: -10%; right: 8%; }

        .auth-wrapper {
            position: relative; z-index: 10;
            width: 94%; max-width: 920px;
            margin: 0 auto;
            display: flex; flex-direction: column;
        }
        @media (min-width: 768px) {
            .auth-wrapper { flex-direction: row; align-items: stretch; justify-content: center; }
        }

        /* LEFT SLIDE PANEL (1:1 LOGIN.PHP DIAGONAL SLIDE-IN FROM BEHIND) */
        .slide-panel {
            width: 100%;
            background: linear-gradient(135deg, #1D3B5E 0%, #0D7A87 55%, #152C47 100%);
            border-radius: 28px;
            border-bottom-right-radius: 56px;
            padding: 20px 22px 44px;
            position: relative;
            z-index: 1;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            justify-content: center;
            box-shadow: 0 20px 45px -15px rgba(29, 59, 94, 0.45);
            transform: translate(-100%, 100%);
            animation: slideInFromBehind 1.2s cubic-bezier(0.22, 1, 0.36, 1) forwards;
            will-change: transform;
        }
        @media (min-width: 768px) {
            .slide-panel {
                width: 53%;
                padding: 40px 44px 40px 36px;
                border-radius: 30px 0 90px 30px;
            }
        }
        @keyframes slideInFromBehind {
            0% { transform: translate(-100%, 100%); opacity: 0; }
            100% { transform: translate(0, 0); opacity: 1; }
        }
        .slide-out-behind { animation: slideOutBehind 0.6s cubic-bezier(0.4, 0, 0.2, 1) forwards; }
        @keyframes slideOutBehind {
            0% { transform: translate(0, 0); opacity: 1; }
            100% { transform: translate(-100%, 100%); opacity: 0; }
        }
        .pixel-pattern {
            position: absolute; inset: 0; pointer-events: none;
            background-image: radial-gradient(circle, rgba(255, 255, 255, 0.22) 1.5px, transparent 1.5px);
            background-size: 22px 22px; opacity: 0.55;
        }
        .wave-lines {
            position: absolute; bottom: -30px; right: -30px; width: 420px; height: 260px;
            opacity: 0.16; pointer-events: none;
        }
        .slide-content { position: relative; z-index: 5; }
        .slide-greet {
            color: #FFFFFF; font-family: var(--font-h); font-weight: 800;
            font-size: 1.35rem; letter-spacing: -0.01em; margin-bottom: 12px;
        }
        @media (min-width: 768px) { .slide-greet { font-size: 1.65rem; margin-bottom: 16px; } }

        .logo-card {
            display: inline-flex; align-items: center; justify-content: center;
            background: rgba(255, 255, 255, 0.96);
            padding: 10px 18px; border-radius: 18px;
            box-shadow: 0 12px 28px rgba(0, 0, 0, 0.22);
            margin-bottom: 14px;
        }
        .logo-card img { width: 135px; height: auto; display: block; object-fit: contain; }
        @media (min-width: 768px) { .logo-card img { width: 185px; } }

        .slide-caption {
            color: rgba(255, 255, 255, 0.92);
            font-size: 12.5px; line-height: 1.6;
            padding: 12px 16px;
            background: rgba(15, 29, 47, 0.28);
            border-left: 3px solid var(--gold);
            border-radius: 12px;
            backdrop-filter: blur(8px);
            max-width: 340px;
        }
        @media (max-width: 767px) { .slide-caption { display: none; } }

        /* RIGHT CONFIRMATION PANEL (1:1 LOGIN.PHP FADE-IN OVERLAP) */
        .form-panel {
            width: 100%;
            background: rgba(255, 255, 255, 0.97);
            backdrop-filter: blur(16px);
            border: 1px solid rgba(255, 255, 255, 0.85);
            border-radius: 28px;
            border-top-left-radius: 56px;
            padding: 26px 22px 22px;
            margin-top: -28px;
            position: relative;
            z-index: 20;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            text-align: center;
            box-shadow: 0 16px 40px rgba(29, 59, 94, 0.16), -16px 10px 36px -12px rgba(29, 59, 94, 0.18);
            opacity: 0;
            animation: fadeIn 0.8s cubic-bezier(0.22, 1, 0.36, 1) forwards;
            animation-delay: 0.35s;
        }
        @media (min-width: 768px) {
            .form-panel {
                width: 47%;
                margin-top: 0;
                margin-left: -34px;
                padding: 36px 32px;
                border-radius: 28px;
                border-top-left-radius: 84px;
            }
        }
        @keyframes fadeIn {
            0% { opacity: 0; }
            100% { opacity: 1; }
        }

        .collab-badge {
            display: inline-flex; align-items: center; gap: 7px;
            padding: 5px 12px; border-radius: 999px;
            background: rgba(13, 122, 135, 0.09);
            border: 1px solid rgba(13, 122, 135, 0.22);
            color: var(--teal); font-size: 11px; font-weight: 800;
            letter-spacing: 0.04em; text-transform: uppercase;
            margin-bottom: 10px;
        }
        .collab-title {
            font-family: var(--font-h);
            font-size: 1.2rem;
            font-weight: 800;
            color: var(--navy);
            margin-bottom: 4px;
            line-height: 1.3;
        }
        @media (min-width: 768px) { .collab-title { font-size: 1.32rem; } }
        .collab-title .x-sep { color: var(--gold); margin: 0 4px; font-weight: 900; }
        .collab-host { font-size: 12px; color: #64748B; margin-bottom: 18px; }

        .account-box {
            width: 100%;
            background: #F8FAFC;
            border: 1.5px solid #E2E8F0;
            border-radius: 16px;
            padding: 14px 16px;
            text-align: left;
            margin-bottom: 18px;
        }
        .account-box-label {
            font-size: 12px;
            font-weight: 700;
            color: #475569;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .account-role-pill {
            font-size: 10px;
            font-weight: 800;
            padding: 2px 8px;
            border-radius: 999px;
            background: rgba(13, 122, 135, 0.12);
            color: var(--teal);
            text-transform: uppercase;
        }
        .account-user-row {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .account-avatar {
            width: 42px; height: 42px; border-radius: 50%;
            background: linear-gradient(135deg, #1D3B5E, #0D7A87);
            color: #FFFFFF; font-family: var(--font-h);
            font-size: 14px; font-weight: 800;
            display: flex; align-items: center; justify-content: center;
            flex-shrink: 0;
            box-shadow: 0 4px 12px rgba(13, 122, 135, 0.25);
        }
        .account-meta { min-width: 0; flex: 1; }
        .account-name {
            font-family: var(--font-h);
            font-size: 14px;
            font-weight: 800;
            color: #0F1D2F;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .account-email {
            font-size: 12px;
            color: #64748B;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            margin-top: 1px;
        }

        .btn-stack {
            width: 100%;
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        .btn-continue {
            width: 100%;
            min-height: 46px;
            padding: 12px 18px;
            border-radius: 999px;
            background: linear-gradient(135deg, #1D3B5E 0%, #0D7A87 100%);
            color: #FFFFFF !important;
            font-family: var(--font-h);
            font-size: 14px;
            font-weight: 700;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            box-shadow: 0 10px 22px -6px rgba(13, 122, 135, 0.45);
            transition: all 0.22s ease;
        }
        .btn-continue:hover {
            transform: translateY(-2px);
            filter: brightness(1.06);
            box-shadow: 0 14px 26px -6px rgba(13, 122, 135, 0.55);
        }
        .btn-switch {
            width: 100%;
            min-height: 46px;
            padding: 12px 18px;
            border-radius: 999px;
            background: #FFFFFF;
            border: 1.5px solid #CBD5E1;
            color: var(--navy) !important;
            font-family: var(--font-h);
            font-size: 13.5px;
            font-weight: 700;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            transition: all 0.2s ease;
        }
        .btn-switch:hover {
            background: #F8FAFC;
            border-color: var(--teal);
            color: var(--teal) !important;
        }

        .footer-links {
            margin-top: 16px;
            padding-top: 12px;
            border-top: 1px solid #F1F5F9;
            width: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-wrap: wrap;
            gap: 12px;
            font-size: 11.5px;
            color: #64748B;
        }
        .footer-links a {
            color: #64748B;
            text-decoration: none;
            font-weight: 600;
        }
        .footer-links a:hover {
            color: var(--teal);
            text-decoration: underline;
        }

        .version-badge {
            position: fixed;
            bottom: 14px;
            right: 16px;
            z-index: 30;
            font-size: 10.5px;
            font-weight: 700;
            color: #64748B;
            background: rgba(255, 255, 255, 0.85);
            backdrop-filter: blur(8px);
            border: 1px solid #E2E8F0;
            padding: 4px 10px;
            border-radius: 8px;
            pointer-events: none;
        }
    </style>
</head>
<body>
    <div id="pageTransition" class="page-transition active"></div>
    <canvas id="particleCanvas"></canvas>
    <div class="glow-orb glow-orb-1"></div>
    <div class="glow-orb glow-orb-2"></div>

    <div class="auth-wrapper">
        <!-- LEFT SLIDE PANEL -->
        <div id="slidePanel" class="slide-panel">
            <div class="pixel-pattern"></div>
            <svg class="wave-lines" viewBox="0 0 520 380" fill="none" xmlns="http://www.w3.org/2000/svg">
                <path d="M0 260 C120 210, 160 320, 280 270 C390 224, 410 300, 520 248" stroke="white" stroke-width="2" stroke-linecap="round"/>
                <path d="M0 300 C120 250, 160 360, 280 310 C390 264, 410 340, 520 288" stroke="white" stroke-width="2" stroke-linecap="round"/>
                <path d="M0 220 C120 170, 160 280, 280 230 C390 184, 410 260, 520 208" stroke="white" stroke-width="2" stroke-linecap="round"/>
            </svg>
            <div class="slide-content">
                <p class="slide-greet">Selamat Datang!</p>
                <div class="logo-card">
                    <img src="<?= htmlspecialchars(APP_LOGO_URL, ENT_QUOTES, 'UTF-8') ?>" alt="Margo One SSO">
                </div>
                <p class="slide-caption">
                    Gerbang masuk tunggal (Single Sign-On), manajemen identitas, dan keamanan Zero-Trust terpusat ekosistem Ilmuna.
                </p>
            </div>
        </div>

        <!-- RIGHT ACCOUNT CONFIRMATION PANEL -->
        <div class="form-panel" id="formPanel">
            <span class="collab-badge">
                <?php if (!empty($clientLogo)): ?>
                    <img src="<?= htmlspecialchars($clientLogo, ENT_QUOTES, 'UTF-8') ?>" alt="" style="width:16px;height:16px;object-fit:contain;border-radius:4px;">
                <?php else: ?>
                    <iconify-icon icon="<?= htmlspecialchars($clientIcon, ENT_QUOTES, 'UTF-8') ?>" width="14"></iconify-icon>
                <?php endif; ?>
                <span>Konfirmasi Akses SSO</span>
            </span>

            <?php if (!empty($clientLogo)): ?>
                <div style="width:52px;height:52px;border-radius:14px;background:#FFFFFF;border:1px solid #E2E8F0;box-shadow:0 8px 20px rgba(29,59,94,0.10);display:flex;align-items:center;justify-content:center;padding:6px;margin-bottom:10px;">
                    <img src="<?= htmlspecialchars($clientLogo, ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($clientName, ENT_QUOTES, 'UTF-8') ?>" style="width:100%;height:100%;object-fit:contain;border-radius:9px;">
                </div>
            <?php endif; ?>

            <h2 class="collab-title">
                Margo One <span class="x-sep">×</span> <?= htmlspecialchars($clientName, ENT_QUOTES, 'UTF-8') ?>
            </h2>
            <div class="collab-host"><?= htmlspecialchars($clientHost, ENT_QUOTES, 'UTF-8') ?></div>

            <div class="account-box">
                <div class="account-box-label">
                    <span>Anda akan login sebagai:</span>
                    <span class="account-role-pill"><?= htmlspecialchars($roleLabel, ENT_QUOTES, 'UTF-8') ?></span>
                </div>
                <div class="account-user-row">
                    <div class="account-avatar"><?= htmlspecialchars($inisial, ENT_QUOTES, 'UTF-8') ?></div>
                    <div class="account-meta">
                        <div class="account-name"><?= htmlspecialchars($fullName, ENT_QUOTES, 'UTF-8') ?></div>
                        <div class="account-email"><?= htmlspecialchars($subIdent, ENT_QUOTES, 'UTF-8') ?></div>
                    </div>
                </div>
            </div>

            <div class="btn-stack">
                <a href="<?= htmlspecialchars($continueUrl, ENT_QUOTES, 'UTF-8') ?>" class="btn-continue" data-transition>
                    <span>Lanjutkan</span>
                    <iconify-icon icon="solar:arrow-right-bold" width="17"></iconify-icon>
                </a>
                <a href="<?= htmlspecialchars($switchUrl, ENT_QUOTES, 'UTF-8') ?>" class="btn-switch" data-transition>
                    <iconify-icon icon="solar:users-group-rounded-bold" width="17"></iconify-icon>
                    <span>Login dengan akun lain</span>
                </a>
            </div>

            <div class="footer-links">
                <a href="<?= htmlspecialchars(BASE_URL . '/gateway', ENT_QUOTES, 'UTF-8') ?>">Portal Margo One</a>
                <span>•</span>
                <a href="<?= htmlspecialchars(BASE_URL . '/profile', ENT_QUOTES, 'UTF-8') ?>">Profil &amp; Keamanan</a>
                <span>•</span>
                <a href="<?= htmlspecialchars(BASE_URL . '/agenda', ENT_QUOTES, 'UTF-8') ?>">Agenda</a>
            </div>
        </div>
    </div>

    <div class="version-badge">
        v-<?= htmlspecialchars(APP_VERSION, ENT_QUOTES, 'UTF-8') ?> <span style="opacity:0.7;">(<?= date('Ymd') ?>)</span>
    </div>

    <script>
    (function () {
        'use strict';
        var pt = document.getElementById('pageTransition');
        setTimeout(function () { if (pt) pt.classList.remove('active'); }, 90);

        document.querySelectorAll('a[data-transition]').forEach(function (link) {
            link.addEventListener('click', function (e) {
                var href = this.getAttribute('href');
                if (!href) return;
                e.preventDefault();
                var sp = document.getElementById('slidePanel');
                var fp = document.getElementById('formPanel');
                if (sp) sp.classList.add('slide-out-behind');
                if (fp) { fp.style.opacity = '0'; fp.style.transform = 'translateX(90px)'; fp.style.transition = 'all 0.45s ease-in'; }
                if (pt) pt.classList.add('active');
                setTimeout(function () { window.location.href = href; }, 450);
            });
        });

        var canvas = document.getElementById('particleCanvas');
        if (canvas) {
            var ctx = canvas.getContext('2d');
            function resize() { canvas.width = window.innerWidth; canvas.height = window.innerHeight; }
            resize();
            window.addEventListener('resize', resize);
            var colors = ['#1D3B5E', '#0D7A87', '#52B2DC'];
            var pts = [];
            for (var i = 0; i < 36; i++) {
                pts.push({
                    x: Math.random() * canvas.width, y: Math.random() * canvas.height,
                    vx: (Math.random() - 0.5) * 0.4, vy: (Math.random() - 0.5) * 0.4,
                    r: Math.random() * 2.4 + 1, c: colors[i % colors.length], o: Math.random() * 0.4 + 0.18
                });
            }
            function loop() {
                ctx.clearRect(0, 0, canvas.width, canvas.height);
                for (var i = 0; i < pts.length; i++) {
                    var p = pts[i];
                    p.x += p.vx; p.y += p.vy;
                    if (p.x < 0 || p.x > canvas.width) p.vx *= -1;
                    if (p.y < 0 || p.y > canvas.height) p.vy *= -1;
                    ctx.beginPath(); ctx.arc(p.x, p.y, p.r, 0, Math.PI * 2);
                    ctx.fillStyle = p.c; ctx.globalAlpha = p.o; ctx.fill();
                }
                ctx.globalAlpha = 1;
                requestAnimationFrame(loop);
            }
            loop();
        }
    })();
    </script>
</body>
</html>
    <?php
    exit;
}

try {
    $pdo = getDBConnection();
    $isResume = isset($_GET['resume']) && $_GET['resume'] === '1';
    $isSwitch = isset($_GET['switch_account']) && $_GET['switch_account'] === '1';

    if ($isResume) {
        $params = margo_get_pending_oauth();
        if (!$params) {
            header('Location: ' . BASE_URL . '/gateway');
            exit;
        }
        $params['prompt'] = '';
        if (isset($_GET['continue']) && $_GET['continue'] === '1') {
            $params['continue'] = '1';
        }
    } else {
        $params = $_GET;
    }

    // Jika user memilih "Login dengan akun lain" (switch_account=1), paksa validasi parameter & arahkan ke /login
    $loggedUser = (!$isSwitch && isLoggedIn()) ? getCurrentUser() : null;
    $outcome = margo_process_authorize_request($pdo, $params, $loggedUser);

    if ($isSwitch && $outcome['action'] === 'require_login') {
        if (isLoggedIn()) {
            logoutUser(false);
        }
        if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
            session_start();
        }
        margo_set_pending_oauth($outcome['pending']);
        header('Location: ' . BASE_URL . '/login?oauth=1&msg=switch_account');
        exit;
    }
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
    $loginUrl = BASE_URL . '/login?oauth=1' . (($outcome['prompt'] ?? '') === 'login' ? '&prompt=login' : '');
    header('Location: ' . $loginUrl);
    exit;
}

if ($outcome['action'] === 'confirm_account') {
    margo_clear_pending_oauth();
    margo_authorize_render_confirm($outcome['client'], $outcome['user'], $outcome['pending']);
}

if ($outcome['action'] === 'redirect_error' || $outcome['action'] === 'redirect_code') {
    margo_clear_pending_oauth();
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');
    header('Location: ' . $outcome['url']);
    exit;
}
