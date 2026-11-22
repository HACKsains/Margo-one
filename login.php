<?php
/**
 * ==============================================================================
 * MARGO ONE — ILMUNA SSO · PORTAL LOGIN TERPUSAT (LUMINA GOLD 1:1 PARITY)
 * ==============================================================================
 * Paritas Visual 100% dengan C:\laragon\www\learn\login.php:
 *   - Viewport Lock (height: 100dvh; overflow: hidden; no vertical scroll)
 *   - Split-Panel Overlap (.slide-panel belakang z:1, .form-panel depan z:20)
 *   - #particleCanvas, .glow-orb, .page-transition radial wave, wave SVG parallax
 *   - Logo Resmi Margo One (assets/images/logo-margo-one.png)
 *   - Eksklusif Iconify Solar Icons (solar:*) + window.Ilmuna (assets/js/alert.js)
 *   - Konteks Aplikasi Dinamis saat dipanggil dari /authorize?client_id=...
 *   - Google OAuth 2.0 KETAT (Hanya users.email_google yang sudah tertaut)
 * ==============================================================================
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';

$googleOAuthCfg           = getGoogleOAuthConfig();
$activeGoogleClientId     = (string)($googleOAuthCfg['client_id'] ?? '');
$activeGoogleClientSecret = (string)($googleOAuthCfg['client_secret'] ?? '');

/* ==============================================================================
 * 1. SECURITY HEADERS
 * ============================================================================== */
if (!headers_sent()) {
    header('X-Frame-Options: SAMEORIGIN');
    header('X-Content-Type-Options: nosniff');
    header('X-XSS-Protection: 1; mode=block');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=(), payment=()');
    header('Cross-Origin-Opener-Policy: same-origin-allow-popups');

    $csp = implode('; ', [
        "default-src 'self'",
        "script-src 'self' 'unsafe-inline' https://code.iconify.design https://accounts.google.com",
        "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://accounts.google.com",
        "font-src 'self' data: https://fonts.gstatic.com",
        "img-src 'self' data: https://*.googleusercontent.com",
        "connect-src 'self' https://api.iconify.design https://api.simplesvg.com https://api.unisvg.com https://accounts.google.com https://oauth2.googleapis.com https://www.googleapis.com",
        "frame-src 'self' https://accounts.google.com",
        "frame-ancestors 'self'",
        "base-uri 'self'",
        "form-action 'self' https://accounts.google.com",
        "object-src 'none'",
    ]);
    header('Content-Security-Policy: ' . $csp);
}

/* ==============================================================================
 * 2. CSRF TOKEN & KONTEKS APLIKASI TUJUAN (/authorize)
 * ============================================================================== */
if (empty($_SESSION['csrf_login_token']) || !is_string($_SESSION['csrf_login_token'])) {
    $_SESSION['csrf_login_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_login_token'];

$pendingOAuth = margo_get_pending_oauth();
$targetClient = null;
if ($pendingOAuth && !empty($pendingOAuth['client_db_id'])) {
    try {
        $pdo = getDBConnection();
        $stC = $pdo->prepare("SELECT * FROM sso_clients WHERE id = ? AND is_active = 1 LIMIT 1");
        $stC->execute([(int)$pendingOAuth['client_db_id']]);
        $targetClient = $stC->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) {
        $targetClient = null;
    }
}

$postLoginRedirect = $pendingOAuth ? (BASE_URL . '/authorize?resume=1&continue=1') : (BASE_URL . '/gateway');
$postLoginRedirect = $pendingOAuth ? (BASE_URL . '/authorize?resume=1') : (BASE_URL . '/gateway');

/* ==============================================================================
 * 3. REDIRECT JIKA SUDAH LOGIN (kecuali prompt=login)
 * ============================================================================== */
$forceReauth = isset($_GET['prompt']) && $_GET['prompt'] === 'login';
if (isLoggedIn() && !$forceReauth) {
    header('Location: ' . $postLoginRedirect);
    exit;
}

/* ==============================================================================
 * 4. HELPER GOOGLE OAUTH API & PROSES LOGIN
 * ============================================================================== */
$error = null;
$infoMsg = null;
if (isset($_GET['msg'])) {
    if ($_GET['msg'] === 'logged_out') {
        $infoMsg = 'Anda telah berhasil keluar dari seluruh sesi Margo One SSO.';
    } elseif ($_GET['msg'] === 'auth_required') {
        $infoMsg = 'Silakan masuk terlebih dahulu menggunakan akun Ilmuna Anda.';
    } elseif ($_GET['msg'] === 'switch_account') {
        $infoMsg = 'Silakan masuk menggunakan akun Margo One lainnya.';
    }
}

function margoFetchGoogleApi($url, $postFields = null)
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
        ]);
        if (is_array($postFields)) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postFields));
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
        }
        $body = curl_exec($ch);
        curl_close($ch);
        if (is_string($body) && $body !== '') {
            return json_decode($body, true);
        }
    }
    $opts = [
        'http' => [
            'method'        => is_array($postFields) ? 'POST' : 'GET',
            'timeout'       => 8,
            'ignore_errors' => true,
        ],
        'ssl' => [
            'verify_peer'      => false,
            'verify_peer_name' => false,
        ],
    ];
    if (is_array($postFields)) {
        $opts['http']['header']  = "Content-Type: application/x-www-form-urlencoded\r\n";
        $opts['http']['content'] = http_build_query($postFields);
    }
    $resp = @file_get_contents($url, false, stream_context_create($opts));
    return $resp ? json_decode($resp, true) : null;
}

// Callback redirect Google OAuth 2.0
if ($_SERVER['REQUEST_METHOD'] === 'GET' && !empty($_GET['code']) && $activeGoogleClientId !== '' && $activeGoogleClientSecret !== '') {
    $stateOk = !empty($_GET['state']) && hash_equals($csrfToken, (string)$_GET['state']);
    if (!$stateOk) {
        $error = 'Sesi autentikasi Google tidak valid. Silakan coba lagi.';
    } else {
        $tokenData = margoFetchGoogleApi('https://oauth2.googleapis.com/token', [
            'code'          => (string)$_GET['code'],
            'client_id'     => $activeGoogleClientId,
            'client_secret' => $activeGoogleClientSecret,
            'redirect_uri'  => BASE_URL . '/login.php',
            'grant_type'    => 'authorization_code',
        ]);
        if (!empty($tokenData['access_token'])) {
            $uInfo = margoFetchGoogleApi('https://www.googleapis.com/oauth2/v3/userinfo?access_token=' . urlencode($tokenData['access_token']));
            if (!empty($uInfo['email']) && !empty($uInfo['email_verified'])) {
                $resG = loginUserByGoogle($uInfo['email'], (string)($uInfo['sub'] ?? ''));
                if ($resG['success']) {
                    unset($_SESSION['csrf_login_token']);
                    header('Location: ' . $postLoginRedirect);
                    exit;
                }
                $error = $resG['message'] ?? 'Akun Google tidak terdaftar atau belum ditautkan.';
            } else {
                $error = 'Gagal mengambil informasi email terverifikasi dari akun Google.';
            }
        } else {
            $error = 'Gagal memverifikasi kode otorisasi Google.';
        }
    }
}

// Proses POST Form Login Lokal / Google OAuth
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower((string)$_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
           || (isset($_POST['ajax']) && $_POST['ajax'] === '1');

    $postedToken = isset($_POST['csrf_token']) && is_string($_POST['csrf_token']) ? $_POST['csrf_token'] : '';

    if ($postedToken === '' || !hash_equals($csrfToken, $postedToken)) {
        $error = 'Sesi formulir tidak valid atau sudah kedaluwarsa. Muat ulang halaman dan coba lagi.';
    } elseif (!empty($_POST['website'])) {
        $error = 'Permintaan tidak dapat diproses. Silakan muat ulang halaman.';
    } elseif (($_POST['login_mode'] ?? '') === 'google') {
        $googleEmail = '';
        $googleSub   = '';

        if (!empty($_POST['google_access_token']) && is_string($_POST['google_access_token'])) {
            $info = margoFetchGoogleApi('https://www.googleapis.com/oauth2/v3/userinfo?access_token=' . urlencode(trim($_POST['google_access_token'])));
            if (is_array($info) && !empty($info['email']) && !empty($info['email_verified'])) {
                $googleEmail = strtolower(trim((string)$info['email']));
                $googleSub   = trim((string)($info['sub'] ?? ''));
            }
            if ($googleEmail === '') {
                $error = 'Verifikasi token Google gagal atau telah kedaluwarsa.';
            }
        } elseif (!empty($_POST['google_credential']) && is_string($_POST['google_credential'])) {
            $info = margoFetchGoogleApi('https://oauth2.googleapis.com/tokeninfo?id_token=' . urlencode(trim($_POST['google_credential'])));
            if (
                is_array($info)
                && !empty($info['email'])
                && ($info['email_verified'] === true || $info['email_verified'] === 'true')
                && ($activeGoogleClientId === '' || ($info['aud'] ?? '') === $activeGoogleClientId)
            ) {
                $googleEmail = strtolower(trim((string)$info['email']));
                $googleSub   = trim((string)($info['sub'] ?? ''));
            }
            if ($googleEmail === '') {
                $error = 'Verifikasi kredensial Google tidak valid.';
            }
        } else {
            $rawEmail = isset($_POST['email_google']) && is_string($_POST['email_google']) ? $_POST['email_google'] : '';
            $googleEmail = strtolower(trim(strip_tags($rawEmail)));
            $googleEmail = preg_replace('/[\x00-\x1F\x7F]/u', '', $googleEmail);
            $googleEmail = margo_mb_substr($googleEmail, 0, 150);
            if ($googleEmail === '' || !filter_var($googleEmail, FILTER_VALIDATE_EMAIL)) {
                $error = 'Silakan masukkan alamat email Google yang valid.';
            }
        }

        if (!$error) {
            // STRICT: hanya cek users.email_google yang sudah tertaut!
            $result = loginUserByGoogle($googleEmail, $googleSub);
            if ($result['success']) {
                unset($_SESSION['csrf_login_token']);
                if ($isAjax) {
                    header('Content-Type: application/json; charset=UTF-8');
                    echo json_encode(['success' => true, 'redirect' => $postLoginRedirect]);
                    exit;
                }
                header('Location: ' . $postLoginRedirect);
                exit;
            }
            $error = is_string($result['message'] ?? null) ? $result['message'] : 'Akun Google belum tertaut di database.';
        }

        if ($isAjax) {
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode(['success' => false, 'message' => $error]);
            exit;
        }
    } else {
        $rawIdentifier = isset($_POST['identifier']) && is_string($_POST['identifier']) ? $_POST['identifier'] : '';
        $identifier = trim(strip_tags($rawIdentifier));
        $identifier = preg_replace('/[\x00-\x1F\x7F]/u', '', $identifier);
        $identifier = margo_mb_substr($identifier, 0, 120);

        if ($identifier !== '' && !preg_match('/^[A-Za-z0-9@._+\-]+$/', $identifier)) {
            $error = 'Format username, NISN/NIP, atau email tidak valid.';
        }

        $password = isset($_POST['password']) && is_string($_POST['password']) ? $_POST['password'] : '';
        if (!$error && strlen($password) > 200) {
            $error = 'Kata sandi terlalu panjang.';
        }
        if (!$error && ($identifier === '' || $password === '')) {
            $error = 'Silakan masukkan username/email/NISN dan kata sandi Anda.';
        }

        if (!$error) {
            $result = loginUser($identifier, $password);
            if ($result['success']) {
                unset($_SESSION['csrf_login_token']);
                header('Location: ' . $postLoginRedirect);
                exit;
            }
            $error = is_string($result['message'] ?? null) ? $result['message'] : 'Username atau kata sandi salah.';
        }
    }
}

$ctxBadgeText = $targetClient ? 'OAuth 2.0 · PKCE S256' : 'Identity Provider Resmi';
$ctxTitleLine1 = $targetClient ? 'Masuk ke' : 'Margo One';
$ctxTitleLine2 = $targetClient ? (string)$targetClient['client_name'] : 'Ilmuna SSO';
$ctxDesc = $targetClient
    ? ('Autentikasi terpusat menuju ' . $targetClient['client_name'] . ' (' . parse_url($targetClient['app_url'], PHP_URL_HOST) . ') dilindungi enkripsi RS256 & PKCE.')
    : 'Satu akun terpadu untuk seluruh ekosistem digital Ilmuna — Ilmuna Learn LMS, Portal ilmuna.my.id, dan layanan akademik lainnya.';
$ctxIcon = $targetClient && !empty($targetClient['app_icon']) ? (string)$targetClient['app_icon'] : 'solar:shield-keyhole-bold';
$ctxLogo = $targetClient ? margo_resolve_app_logo_url($targetClient['app_logo'] ?? '', $targetClient['app_icon'] ?? '') : null;
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="referrer" content="strict-origin-when-cross-origin">
    <meta name="robots" content="noindex, nofollow, noarchive, nosnippet, noimageindex">

    <title>Masuk — <?= htmlspecialchars(APP_NAME, ENT_QUOTES, 'UTF-8') ?></title>
    <?= renderFavicon() ?>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800;900&family=Plus+Jakarta+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <?= renderIconifyScript() ?>

    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        :root {
            --navy: #1D3B5E;
            --navy-dark: #1D3B5E;
            --teal: #0D7A87;
            --teal-dark: #0D7A87;
            --sky: #52B2DC;
            --gold: #52B2DC;
            --mint: #0D7A87;
            --emerald: #0D7A87;
            --font-h: 'Outfit', sans-serif;
            --font-b: 'Plus Jakarta Sans', sans-serif;
        }

        iconify-icon {
            display: inline-block;
            width: 1em;
            height: 1em;
            vertical-align: -0.125em;
        }

        html { height: 100%; overflow: hidden; }

        body {
            font-family: var(--font-b);
            background: #F8FAFC;
            color: #334155;
            height: 100vh;
            height: 100dvh;
            overflow: hidden;
            overscroll-behavior: none;
            -webkit-font-smoothing: antialiased;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 12px 0;
            position: relative;
            user-select: none;
            -webkit-user-select: none;
        }
        @media (min-width: 768px) { body { padding: 24px 0; } }

        input, textarea { user-select: text !important; -webkit-user-select: text !important; }
        img { -webkit-user-drag: none; user-drag: none; pointer-events: none; }

        /* PAGE TRANSITION (RADIAL WAVE — 1:1 learn/login.php) */
        .page-transition {
            position: fixed;
            top: 50%; left: 50%;
            width: 300vmax; height: 300vmax;
            margin-top: -150vmax; margin-left: -150vmax;
            background: #E8EFF7;
            z-index: 9999;
            transform-origin: center;
            transform: rotate(-225deg) translateY(85%);
            transition: transform 0.6s cubic-bezier(0.4, 0, 0.2, 1);
            pointer-events: none;
            box-shadow: 0 -20px 60px rgba(0,0,0,0.1);
            will-change: transform;
        }
        .page-transition::before {
            content: ''; position: absolute; top: 10vh; left: 0;
            width: 100%; height: 100%; background: #52B2DC;
            box-shadow: 0 -15px 35px rgba(0,0,0,0.08);
        }
        .page-transition::after {
            content: ''; position: absolute; top: 20vh; left: 0;
            width: 100%; height: 100%; background: #0D7A87;
            box-shadow: 0 -15px 35px rgba(0,0,0,0.1);
        }
        .page-transition.active { transform: rotate(135deg) translateY(-10%); }

        /* CANVAS + GLOW ORBS */
        #particleCanvas { position: fixed; inset: 0; width: 100%; height: 100%; pointer-events: none; z-index: 2; }
        .glow-orb {
            position: absolute; border-radius: 50%;
            background: rgba(13, 122, 135, 0.10);
            filter: blur(100px); pointer-events: none; z-index: 1;
            width: 300px; height: 300px;
        }
        @media (min-width: 768px) { .glow-orb { width: 600px; height: 600px; } }
        .glow-orb-1 { top: -10%; left: 10%; }
        .glow-orb-2 { bottom: -10%; right: 10%; }

        /* WRAPPER */
        .login-wrapper {
            position: relative; z-index: 10;
            width: 90%; max-width: 1200px; max-height: 100%; min-height: 0;
            margin: 0 auto; display: flex; flex-direction: column; overflow: visible;
        }
        @media (min-width: 768px) {
            .login-wrapper { flex-direction: row; align-items: stretch; justify-content: center; gap: 0; width: 85%; }
        }
        @media (min-width: 1024px) { .login-wrapper { width: 75%; } }
        @media (min-width: 1280px) { .login-wrapper { width: 70%; } }

        /* LEFT SLIDE PANEL */
        .slide-panel {
            width: 100%; height: auto;
            background: linear-gradient(135deg, #1D3B5E 0%, #0D7A87 50%, #152C47 100%);
            border-radius: 28px; border-bottom-right-radius: 60px;
            display: flex; flex-direction: column; justify-content: flex-start;
            padding: 8px; position: relative; z-index: 1; overflow: hidden;
            transform: translate(-100%, 100%);
            animation: slideInFromBehind 1.2s cubic-bezier(0.22, 1, 0.36, 1) forwards;
            will-change: transform; flex-shrink: 0;
        }
        @media (min-width: 768px) {
            .slide-panel {
                width: 45%; padding: 32px; border-radius: 0;
                border-top-left-radius: 32px; border-bottom-left-radius: 32px;
                border-bottom-right-radius: 100px;
            }
        }
        @media (min-width: 1024px) { .slide-panel { width: 48%; } }
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
            position: absolute; inset: 0; z-index: 0; pointer-events: none;
            background-image: radial-gradient(circle, rgba(82, 178, 220, 0.30) 1.5px, transparent 1.5px);
            background-size: 20px 20px;
            animation: pixelPulse 8s ease-in-out infinite; opacity: 0.55;
        }
        @keyframes pixelPulse { 0%, 100% { opacity: 0.40; transform: scale(1); } 50% { opacity: 0.70; transform: scale(1.02); } }

        .wave-wrap {
            position: absolute; bottom: 0; left: 0; width: 100%;
            overflow: hidden; line-height: 0; z-index: 10; pointer-events: none; opacity: 0.40;
        }
        .wave-wrap svg { position: relative; display: block; width: calc(100% + 800px); height: 30px; }
        @media (min-width: 768px) { .wave-wrap svg { height: 60px; } }
        .parallax > use { animation: wave-move 25s cubic-bezier(.55,.5,.45,.5) infinite; will-change: transform; }
        .parallax > use:nth-child(1) { animation-delay: -2s; animation-duration: 7s; }
        .parallax > use:nth-child(2) { animation-delay: -3s; animation-duration: 10s; }
        .parallax > use:nth-child(3) { animation-delay: -4s; animation-duration: 13s; }
        .parallax > use:nth-child(4) { animation-delay: -5s; animation-duration: 20s; }
        @keyframes wave-move { 0% { transform: translate3d(-90px, 0, 0); } 100% { transform: translate3d(85px, 0, 0); } }

        .slide-inner {
            position: relative; z-index: 10; display: flex; flex-direction: column;
            align-items: flex-start; justify-content: center; text-align: left;
            width: 100%; max-width: 360px; margin: 0 auto; padding: 4px 0 8px;
        }
        @media (min-width: 768px) { .slide-inner { margin: 0; padding: 16px 0 0; } }

        .slide-glass-card {
            background: rgba(255, 255, 255, 0.10);
            backdrop-filter: blur(12px); -webkit-backdrop-filter: blur(12px);
            border: 1px solid rgba(255, 255, 255, 0.20);
            border-radius: 14px; padding: 10px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.15); width: 100%;
        }
        @media (min-width: 768px) { .slide-glass-card { padding: 28px; } }

        .logo-line { display: flex; align-items: center; justify-content: flex-start; gap: 10px; margin-bottom: 8px; }
        @media (min-width: 768px) { .logo-line { margin-bottom: 20px; } }

        .logo-wrap {
            position: relative; width: fit-content; padding: 8px 14px;
            background: linear-gradient(135deg, rgba(255, 255, 255, 0.98) 0%, rgba(248, 250, 252, 0.95) 100%);
            border-radius: 12px;
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.20), 0 0 28px rgba(255, 255, 255, 0.30), inset 0 1px 0 rgba(255, 255, 255, 1);
            border: 1px solid rgba(255, 255, 255, 0.85); overflow: hidden;
        }
        @media (min-width: 768px) { .logo-wrap { padding: 11px 18px; border-radius: 16px; } }
        .logo-wrap::after {
            content: ''; position: absolute; top: 0; left: 0; width: 50%; height: 100%;
            background: linear-gradient(to right, rgba(255,255,255,0) 0%, rgba(255,255,255,0.75) 50%, rgba(255,255,255,0) 100%);
            transform: translateX(-150%) skewX(-25deg); animation: logoShine 6s infinite ease-in-out;
            pointer-events: none; z-index: 3;
        }
        @keyframes logoShine { 0% { transform: translateX(-150%) skewX(-25deg); } 100% { transform: translateX(250%) skewX(-25deg); } }
        .logo-wrap img {
            width: 82px; height: auto; object-fit: contain;
            filter: drop-shadow(0 2px 6px rgba(29, 59, 94, 0.15));
            animation: logoGlow 8s ease-in-out infinite; display: block;
        }
        @media (min-width: 768px) { .logo-wrap img { width: 170px; } }
        @keyframes logoGlow {
            0%, 100% { filter: drop-shadow(0 2px 6px rgba(29,59,94,0.15)); transform: translate3d(0,0,0) scale(1); }
            50% { filter: drop-shadow(0 4px 10px rgba(29,59,94,0.20)) drop-shadow(0 0 16px rgba(82,178,220,0.45)); transform: translate3d(0,-1px,0) scale(1.005); }
        }

        .mobile-side-text { flex: 1; text-align: left; }
        .mobile-side-text h2 { color: #FFFFFF; font-size: 11.5px; font-weight: 700; line-height: 1.2; text-shadow: 0 2px 4px rgba(0,0,0,0.3); }
        .mobile-side-text p { color: rgba(255,255,255,0.85); font-size: 9.5px; font-weight: 500; margin-top: 2px; }
        @media (min-width: 768px) { .mobile-side-text { display: none; } }

        .badge {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 3px 9px; border-radius: 999px;
            background: rgba(255, 255, 255, 0.20); border: 1px solid rgba(255, 255, 255, 0.30);
            backdrop-filter: blur(8px); margin-bottom: 6px; width: fit-content;
        }
        @media (min-width: 768px) { .badge { padding: 6px 14px; gap: 10px; margin-bottom: 14px; } }
        .badge .dot {
            width: 7px; height: 7px; border-radius: 50%; background: var(--sky);
            animation: pulseDot 2s ease-in-out infinite; box-shadow: 0 0 8px var(--sky);
        }
        @keyframes pulseDot { 0%, 100% { opacity: 1; transform: scale(1); } 50% { opacity: 0.5; transform: scale(0.85); } }
        .badge span { color: #FFFFFF; font-size: 8px; font-weight: 700; letter-spacing: 0.12em; text-transform: uppercase; }
        @media (min-width: 768px) { .badge span { font-size: 11px; } }

        .slide-h1 {
            display: none; color: #FFFFFF; font-family: var(--font-h); font-weight: 900;
            font-size: 1.5rem; letter-spacing: -0.02em; line-height: 1.15; margin-bottom: 8px;
            text-shadow: 0 4px 12px rgba(0,0,0,0.30);
        }
        @media (min-width: 768px) { .slide-h1 { display: block; font-size: 1.9rem; margin-bottom: 10px; } }
        @media (min-width: 1024px) { .slide-h1 { font-size: 2.15rem; } }
        .slide-h1 .gradient-text {
            background: linear-gradient(to right, #FFFFFF 0%, #52B2DC 100%);
            -webkit-background-clip: text; background-clip: text; -webkit-text-fill-color: transparent;
        }

        .slide-desc {
            display: none; color: rgba(255, 255, 255, 0.90); font-size: 13px;
            line-height: 1.55; font-weight: 500; max-width: 320px; text-shadow: 0 1px 4px rgba(0,0,0,0.2);
        }
        @media (min-width: 768px) { .slide-desc { display: block; } }

        .client-ctx-pill {
            display: none;
            margin-top: 14px;
            padding: 8px 12px;
            border-radius: 12px;
            background: rgba(15, 29, 47, 0.35);
            border: 1px solid rgba(255, 255, 255, 0.22);
            color: #E0F2FE;
            font-size: 11.5px;
            font-weight: 600;
            align-items: center;
            gap: 8px;
        }
        @media (min-width: 768px) { .client-ctx-pill { display: inline-flex; } }

        /* RIGHT FORM PANEL */
        .form-panel {
            width: 100%; height: auto;
            border-radius: 28px; border-top-left-radius: 60px;
            padding: 16px; padding-top: 34px; padding-bottom: 16px; margin-top: -28px;
            position: relative; z-index: 20;
            display: flex; flex-direction: column; align-items: center; justify-content: center;
            background: rgba(255, 255, 255, 0.92);
            backdrop-filter: blur(16px); -webkit-backdrop-filter: blur(16px);
            border: 1px solid rgba(255, 255, 255, 0.70);
            box-shadow: 0 8px 32px 0 rgba(29, 59, 94, 0.15), -20px 12px 40px -12px rgba(29, 59, 94, 0.20);
            overflow-y: auto; overflow-x: hidden; -webkit-overflow-scrolling: touch;
            scrollbar-width: none; max-height: 100%;
            opacity: 0; animation: fadeIn 0.8s cubic-bezier(0.22, 1, 0.36, 1) forwards;
            animation-delay: 0.4s;
        }
        .form-panel::-webkit-scrollbar { display: none; }
        @media (min-width: 768px) {
            .form-panel {
                width: 55%; border-radius: 0; border-top-right-radius: 40px; border-bottom-right-radius: 40px;
                border-top-left-radius: 100px; padding: 32px; margin-top: 0; margin-left: -28px;
            }
        }
        @media (min-width: 1024px) { .form-panel { width: 52%; } }
        @keyframes fadeIn { 0% { opacity: 0; } 100% { opacity: 1; } }

        .form-inner { width: 100%; max-width: 380px; }

        .form-head-row { display: flex; align-items: center; justify-content: space-between; gap: 10px; margin-bottom: 2px; }
        .form-heading { color: var(--navy); font-family: var(--font-h); font-size: 1.35rem; font-weight: 800; letter-spacing: -0.02em; }
        @media (min-width: 768px) { .form-heading { font-size: 1.6rem; } }

        .sso-trust-chip {
            display: inline-flex; align-items: center; gap: 5px;
            padding: 5px 10px; border-radius: 999px;
            background: rgba(13, 122, 135, 0.09); border: 1px solid rgba(13, 122, 135, 0.24);
            color: var(--teal); font-family: var(--font-h); font-size: 0.72rem; font-weight: 700;
        }

        .form-subheading { color: #64748B; font-size: 0.78rem; margin-bottom: 14px; font-weight: 500; }
        @media (min-width: 768px) { .form-subheading { font-size: 0.85rem; margin-bottom: 20px; } }

        .alert-box {
            display: flex; align-items: flex-start; gap: 8px;
            padding: 9px 12px; border-radius: 10px; margin-bottom: 12px;
            font-size: 0.78rem; line-height: 1.4;
            animation: alertSlide 0.35s cubic-bezier(0.16, 1, 0.3, 1) both;
        }
        .alert-error { background: rgba(29, 59, 94, 0.08); border: 1px solid rgba(29, 59, 94, 0.25); border-left: 4px solid #1D3B5E; color: #1D3B5E; }
        .alert-info  { background: rgba(82, 178, 220, 0.14); border: 1px solid rgba(82, 178, 220, 0.35); border-left: 4px solid #0D7A87; color: #1D3B5E; }
        @keyframes alertSlide { from { opacity: 0; transform: translateY(-8px); } to { opacity: 1; transform: translateY(0); } }
        .alert-close { background: none; border: none; color: inherit; cursor: pointer; margin-left: auto; padding: 0; display: inline-flex; }

        .field { margin-bottom: 10px; }
        @media (min-width: 768px) { .field { margin-bottom: 14px; } }
        .field-label { display: block; font-size: 0.75rem; font-weight: 600; color: #334155; margin-bottom: 4px; }
        @media (min-width: 768px) { .field-label { font-size: 0.8rem; margin-bottom: 6px; } }

        .input-wrap { position: relative; display: flex; align-items: center; }
        .input-wrap > .input-icon {
            position: absolute; left: 14px; color: #94A3B8; font-size: 1.05rem;
            pointer-events: none; transition: color 0.2s;
        }
        .input-wrap:focus-within > .input-icon { color: var(--teal); }

        .form-input {
            width: 100%; padding: 11px 42px 11px 42px; min-height: 44px;
            border-radius: 12px; border: 1.5px solid #E2E8F0; background: #FFFFFF;
            color: #0F1D2F; font-family: var(--font-b); font-size: 0.92rem; font-weight: 500;
            transition: all 0.25s ease;
        }
        @media (min-width: 768px) { .form-input { padding: 13px 46px 13px 44px; } }
        .form-input::placeholder { color: #94A3B8; font-weight: 400; }
        .form-input:focus { outline: none; border-color: var(--teal); box-shadow: 0 0 0 4px rgba(13, 122, 135, 0.10); }

        .toggle-password {
            position: absolute; right: 8px; background: none; border: none;
            color: #94A3B8; cursor: pointer; font-size: 1.05rem; width: 36px; height: 36px;
            display: inline-flex; align-items: center; justify-content: center; border-radius: 8px;
            transition: color 0.2s;
        }
        .toggle-password:hover { color: var(--navy); }

        .hp-field { position: absolute !important; left: -9999px !important; width: 1px !important; height: 1px !important; opacity: 0 !important; pointer-events: none !important; }

        .btn-submit {
            width: 100%; padding: 12px; min-height: 46px; border-radius: 12px; border: none; margin-top: 6px;
            background: linear-gradient(135deg, #1D3B5E 0%, #0D7A87 50%, #52B2DC 100%);
            color: #FFFFFF; font-family: var(--font-h); font-size: 0.95rem; font-weight: 700;
            letter-spacing: 0.02em; cursor: pointer;
            display: inline-flex; align-items: center; justify-content: center; gap: 8px;
            box-shadow: 0 10px 24px rgba(13, 122, 135, 0.32);
            transition: all 0.32s cubic-bezier(0.4, 0, 0.2, 1); position: relative; overflow: hidden;
        }
        @media (min-width: 768px) { .btn-submit { padding: 14px; font-size: 1rem; border-radius: 14px; } }
        .btn-submit::after {
            content: ''; position: absolute; top: 0; left: -100%; width: 50%; height: 100%;
            background: linear-gradient(to right, rgba(255,255,255,0) 0%, rgba(255,255,255,0.55) 50%, rgba(255,255,255,0) 100%);
            transform: skewX(-20deg); animation: sweepShine 3s cubic-bezier(0.4, 0, 0.2, 1) infinite;
        }
        @keyframes sweepShine { 0% { left: -100%; } 20% { left: 200%; } 100% { left: 200%; } }
        .btn-submit:hover { transform: translateY(-2px); box-shadow: 0 14px 30px rgba(13, 122, 135, 0.44); }
        .btn-submit:disabled { opacity: 0.7; cursor: not-allowed; }

        .sso-divider {
            display: flex; align-items: center; gap: 10px; margin: 12px 0;
            color: #94A3B8; font-size: 0.7rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.08em;
        }
        .sso-divider::before, .sso-divider::after { content: ''; flex: 1; height: 1px; background: linear-gradient(to right, transparent, #CBD5E1, transparent); }

        .btn-google-login {
            width: 100%; padding: 10px 14px; min-height: 44px; border-radius: 12px;
            border: 1.5px solid #E2E8F0; background: #FFFFFF; color: #1E293B;
            font-family: var(--font-h); font-size: 0.88rem; font-weight: 700; cursor: pointer;
            display: inline-flex; align-items: center; justify-content: center; gap: 10px;
            box-shadow: 0 4px 12px rgba(15, 23, 42, 0.05); transition: all 0.25s ease;
        }
        @media (min-width: 768px) { .btn-google-login { padding: 12px 16px; font-size: 0.92rem; border-radius: 14px; } }
        .btn-google-login svg { width: 18px; height: 18px; flex-shrink: 0; }
        .btn-google-login:hover { border-color: var(--teal); color: var(--navy); transform: translateY(-1px); box-shadow: 0 8px 20px rgba(13, 122, 135, 0.14); }

        /* Modal Google Fallback */
        .google-modal-overlay {
            position: fixed; inset: 0; z-index: 100050;
            background: rgba(21, 44, 71, 0.55); backdrop-filter: blur(6px);
            display: none; align-items: center; justify-content: center; padding: 16px;
        }
        .google-modal-overlay.active { display: flex; }
        .google-modal-card {
            width: 100%; max-width: 400px; background: #FFFFFF; border-radius: 24px; padding: 24px 22px;
            box-shadow: 0 24px 48px rgba(15, 29, 47, 0.25); border: 1px solid #E2E8F0;
        }
        .google-modal-head { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 12px; }
        .google-modal-title-wrap { display: flex; align-items: center; gap: 11px; }
        .google-modal-badge { width: 40px; height: 40px; border-radius: 12px; background: #F8FAFC; border: 1px solid #E2E8F0; display: flex; align-items: center; justify-content: center; }
        .google-modal-badge svg { width: 20px; height: 20px; }
        .google-modal-title { font-family: var(--font-h); font-size: 1.05rem; font-weight: 800; color: var(--navy); }
        .google-modal-sub { font-size: 0.75rem; color: #64748B; font-weight: 500; }
        .google-modal-close { background: #F1F5F9; border: none; width: 32px; height: 32px; border-radius: 9px; color: #64748B; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; }
        .google-modal-desc { font-size: 0.78rem; color: #475569; line-height: 1.5; margin-bottom: 14px; background: #F8FAFC; border: 1px solid #E2E8F0; border-radius: 10px; padding: 9px 11px; }
        .google-modal-actions { display: flex; gap: 10px; margin-top: 16px; }
        .btn-google-cancel { flex: 1; padding: 11px; border-radius: 11px; border: 1.5px solid #E2E8F0; background: #F8FAFC; color: #475569; font-family: var(--font-h); font-size: 0.88rem; font-weight: 700; cursor: pointer; }
        .btn-google-submit { flex: 1.4; padding: 11px; border-radius: 11px; border: none; background: linear-gradient(135deg, #1D3B5E 0%, #0D7A87 100%); color: #FFFFFF; font-family: var(--font-h); font-size: 0.88rem; font-weight: 700; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; gap: 8px; }

        .form-footer { margin-top: 14px; text-align: center; }
        @media (min-width: 768px) { .form-footer { margin-top: 22px; } }
        .form-footer p { color: #64748B; font-size: 9.5px; font-weight: 700; letter-spacing: 0.10em; text-transform: uppercase; }
        @media (min-width: 768px) { .form-footer p { font-size: 11px; } }

        @media (max-width: 380px) {
            .form-subheading { display: none; }
            .slide-glass-card { padding: 8px; }
            .form-panel { padding-top: 28px; }
        }
    </style>
</head>
<body>
    <div id="pageTransition" class="page-transition active"></div>
    <canvas id="particleCanvas"></canvas>
    <div class="glow-orb glow-orb-1"></div>
    <div class="glow-orb glow-orb-2"></div>

    <div class="login-wrapper">
        <!-- LEFT SLIDE PANEL -->
        <div class="slide-panel" id="slidePanel">
            <div class="pixel-pattern"></div>
            <div class="wave-wrap">
                <svg viewBox="0 24 150 28" preserveAspectRatio="none" shape-rendering="auto">
                    <defs>
                        <path id="gentle-wave" d="M-160 44c30 0 58-18 88-18s 58 18 88 18 58-18 88-18 58 18 88 18 v44h-352z"/>
                    </defs>
                    <g class="parallax">
                        <use href="#gentle-wave" x="48" y="0" fill="rgba(255,255,255,0.30)"/>
                        <use href="#gentle-wave" x="48" y="3" fill="rgba(255,255,255,0.20)"/>
                        <use href="#gentle-wave" x="48" y="5" fill="rgba(255,255,255,0.15)"/>
                        <use href="#gentle-wave" x="48" y="7" fill="rgba(255,255,255,0.40)"/>
                    </g>
                </svg>
            </div>

            <div class="slide-inner">
                <div class="slide-glass-card">
                    <div class="logo-line">
                        <div class="logo-wrap">
                            <img src="<?= htmlspecialchars(APP_LOGO_URL, ENT_QUOTES, 'UTF-8') ?>"
                                 alt="Margo One — Ilmuna SSO"
                                 draggable="false">
                        </div>
                        <div class="mobile-side-text">
                            <h2><?= htmlspecialchars($targetClient ? $targetClient['client_name'] : 'Margo One SSO', ENT_QUOTES, 'UTF-8') ?></h2>
                            <p><?= htmlspecialchars($ctxBadgeText, ENT_QUOTES, 'UTF-8') ?></p>
                        </div>
                    </div>

                    <div class="badge">
                        <span class="dot"></span>
                        <span><?= htmlspecialchars($ctxBadgeText, ENT_QUOTES, 'UTF-8') ?></span>
                    </div>

                    <h1 class="slide-h1">
                        <?= htmlspecialchars($ctxTitleLine1, ENT_QUOTES, 'UTF-8') ?><br>
                        <span class="gradient-text"><?= htmlspecialchars($ctxTitleLine2, ENT_QUOTES, 'UTF-8') ?></span>
                    </h1>

                    <p class="slide-desc"><?= htmlspecialchars($ctxDesc, ENT_QUOTES, 'UTF-8') ?></p>

                    <div class="client-ctx-pill">
                        <?php if (!empty($ctxLogo)): ?>
                            <img src="<?= htmlspecialchars($ctxLogo, ENT_QUOTES, 'UTF-8') ?>" alt="" style="width:18px;height:18px;object-fit:contain;border-radius:4px;">
                        <?php else: ?>
                            <iconify-icon icon="<?= htmlspecialchars($ctxIcon, ENT_QUOTES, 'UTF-8') ?>" width="16"></iconify-icon>
                        <?php endif; ?>
                        <span><?= htmlspecialchars($targetClient ? ('Tujuan: ' . $targetClient['client_name']) : 'Zero-Trust RS256 · Single Sign-On', ENT_QUOTES, 'UTF-8') ?></span>
                    </div>
                </div>
            </div>
        </div>

        <!-- RIGHT FORM PANEL -->
        <div class="form-panel" id="formPanel">
            <div class="form-inner">
                <div class="form-head-row">
                    <h2 class="form-heading">Masuk</h2>
                    <span class="sso-trust-chip">
                        <iconify-icon icon="solar:shield-check-bold" width="14"></iconify-icon>
                        <span>Margo One ID</span>
                    </span>
                </div>
                <p class="form-subheading">
                    <?= $targetClient
                        ? 'Masuk sekali untuk melanjutkan ke ' . htmlspecialchars($targetClient['client_name'], ENT_QUOTES, 'UTF-8')
                        : 'Satu identitas untuk seluruh ekosistem Ilmuna' ?>
                </p>

                <?php if ($error): ?>
                    <div class="alert-box alert-error" role="alert">
                        <iconify-icon icon="solar:danger-circle-bold" width="18" style="flex-shrink:0;margin-top:1px;"></iconify-icon>
                        <div><?= htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                        <button type="button" class="alert-close" onclick="this.parentElement.remove()" aria-label="Tutup">
                            <iconify-icon icon="solar:close-circle-bold" width="16"></iconify-icon>
                        </button>
                    </div>
                <?php elseif ($infoMsg): ?>
                    <div class="alert-box alert-info" role="status">
                        <iconify-icon icon="solar:info-circle-bold" width="18" style="flex-shrink:0;margin-top:1px;"></iconify-icon>
                        <div><?= htmlspecialchars($infoMsg, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                        <button type="button" class="alert-close" onclick="this.parentElement.remove()" aria-label="Tutup">
                            <iconify-icon icon="solar:close-circle-bold" width="16"></iconify-icon>
                        </button>
                    </div>
                <?php endif; ?>

                <form method="POST" action="" id="loginForm" autocomplete="on" novalidate>
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">

                    <div class="hp-field" aria-hidden="true">
                        <label for="website">Website</label>
                        <input type="text" id="website" name="website" tabindex="-1" autocomplete="off">
                    </div>

                    <div class="field">
                        <label class="field-label" for="identifier">Username, Email, atau NISN/NIP</label>
                        <div class="input-wrap">
                            <iconify-icon icon="solar:user-bold" class="input-icon"></iconify-icon>
                            <input type="text" id="identifier" name="identifier" class="form-input"
                                   placeholder="Username / Email / NISN" maxlength="120" required autofocus
                                   autocomplete="username" spellcheck="false">
                        </div>
                    </div>

                    <div class="field">
                        <label class="field-label" for="password">Kata Sandi</label>
                        <div class="input-wrap">
                            <iconify-icon icon="solar:lock-password-bold" class="input-icon"></iconify-icon>
                            <input type="password" id="password" name="password" class="form-input"
                                   placeholder="Masukkan kata sandi" maxlength="200" required
                                   autocomplete="current-password">
                            <button type="button" class="toggle-password" id="togglePasswordBtn" aria-label="Tampilkan sandi">
                                <iconify-icon icon="solar:eye-linear" id="togglePasswordIcon"></iconify-icon>
                            </button>
                        </div>
                    </div>

                    <button type="submit" class="btn-submit" id="btnSubmit">
                        <span>Masuk dengan Margo One</span>
                        <iconify-icon icon="solar:arrow-right-bold" width="18"></iconify-icon>
                    </button>
                </form>

                <div class="sso-divider"><span>atau</span></div>
                <button type="button" class="btn-google-login" id="btnGoogleLogin">
                    <svg viewBox="0 0 24 24" aria-hidden="true">
                        <path fill="#EA4335" d="M12 10.2v3.9h5.5c-.2 1.3-1.6 3.8-5.5 3.8-3.3 0-6-2.7-6-6s2.7-6 6-6c1.9 0 3.1.8 3.8 1.5l2.6-2.5C16.8 3.4 14.6 2.5 12 2.5 6.8 2.5 2.5 6.8 2.5 12s4.3 9.5 9.5 9.5c5.5 0 9.1-3.9 9.1-9.3 0-.6-.1-1.1-.2-1.6H12z"/>
                        <path fill="#34A853" d="M3.6 7.4l3.2 2.4C7.7 7.7 9.7 6 12 6c1.9 0 3.1.8 3.8 1.5l2.6-2.5C16.8 3.4 14.6 2.5 12 2.5 8.3 2.5 5.1 4.6 3.6 7.4z"/>
                        <path fill="#FBBC05" d="M12 21.5c2.5 0 4.7-.8 6.3-2.3l-2.9-2.4c-.8.6-1.9 1-3.4 1-3.8 0-5.2-2.5-5.5-3.8l-3.2 2.5c1.5 3 4.8 5 8.7 5z"/>
                        <path fill="#4285F4" d="M21.1 12.2c0-.6-.1-1.1-.2-1.6H12v3.9h5.5c-.3 1.3-1.1 2.4-2.1 3.1l2.9 2.4c1.7-1.6 2.8-4 2.8-7.8z"/>
                    </svg>
                    <span>Masuk dengan Akun Google</span>
                </button>
            </div>

            <div class="form-footer">
                <p>Margo One · Ilmuna SSO © <?= date('Y') ?></p>
            </div>
        </div>
    </div>

    <!-- MODAL FALLBACK GOOGLE (HANYA CEK users.email_google TERTAUT) -->
    <div class="google-modal-overlay" id="googleLoginModal" role="dialog" aria-modal="true" aria-labelledby="googleModalTitle">
        <div class="google-modal-card">
            <div class="google-modal-head">
                <div class="google-modal-title-wrap">
                    <div class="google-modal-badge">
                        <svg viewBox="0 0 24 24" aria-hidden="true">
                            <path fill="#EA4335" d="M12 10.2v3.9h5.5c-.2 1.3-1.6 3.8-5.5 3.8-3.3 0-6-2.7-6-6s2.7-6 6-6c1.9 0 3.1.8 3.8 1.5l2.6-2.5C16.8 3.4 14.6 2.5 12 2.5 6.8 2.5 2.5 6.8 2.5 12s4.3 9.5 9.5 9.5c5.5 0 9.1-3.9 9.1-9.3 0-.6-.1-1.1-.2-1.6H12z"/>
                            <path fill="#34A853" d="M3.6 7.4l3.2 2.4C7.7 7.7 9.7 6 12 6c1.9 0 3.1.8 3.8 1.5l2.6-2.5C16.8 3.4 14.6 2.5 12 2.5 8.3 2.5 5.1 4.6 3.6 7.4z"/>
                            <path fill="#FBBC05" d="M12 21.5c2.5 0 4.7-.8 6.3-2.3l-2.9-2.4c-.8.6-1.9 1-3.4 1-3.8 0-5.2-2.5-5.5-3.8l-3.2 2.5c1.5 3 4.8 5 8.7 5z"/>
                            <path fill="#4285F4" d="M21.1 12.2c0-.6-.1-1.1-.2-1.6H12v3.9h5.5c-.3 1.3-1.1 2.4-2.1 3.1l2.9 2.4c1.7-1.6 2.8-4 2.8-7.8z"/>
                        </svg>
                    </div>
                    <div>
                        <div class="google-modal-title" id="googleModalTitle">Masuk dengan Google</div>
                        <div class="google-modal-sub">Khusus akun yang sudah ditautkan</div>
                    </div>
                </div>
                <button type="button" class="google-modal-close" id="btnCloseGoogleModal" aria-label="Tutup">
                    <iconify-icon icon="solar:close-circle-bold" width="18"></iconify-icon>
                </button>
            </div>
            <p class="google-modal-desc">
                Hanya alamat <strong>Email Google</strong> yang sudah ditautkan pada menu Profil Margo One yang diizinkan masuk.
            </p>
            <form method="POST" action="" id="googleLoginForm" novalidate>
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="login_mode" value="google">
                <div class="hp-field" aria-hidden="true"><input type="text" name="website" tabindex="-1" autocomplete="off"></div>
                <div class="field" style="margin-bottom: 0;">
                    <label class="field-label" for="googleEmailInput">Email Google Tertaut</label>
                    <div class="input-wrap">
                        <iconify-icon icon="solar:letter-bold" class="input-icon"></iconify-icon>
                        <input type="email" id="googleEmailInput" name="email_google" class="form-input"
                               placeholder="nama@gmail.com" maxlength="150" required autocomplete="email" spellcheck="false">
                    </div>
                </div>
                <div class="google-modal-actions">
                    <button type="button" class="btn-google-cancel" id="btnCancelGoogleModal">Batal</button>
                    <button type="submit" class="btn-google-submit" id="btnSubmitGoogleLogin">
                        <span>Verifikasi &amp; Masuk</span>
                        <iconify-icon icon="solar:arrow-right-bold" width="15"></iconify-icon>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <?php if ($activeGoogleClientId !== ''): ?>
        <script src="https://accounts.google.com/gsi/client" async defer></script>
    <?php endif; ?>
    <script src="<?= htmlspecialchars(BASE_URL . '/assets/js/alert.js', ENT_QUOTES, 'UTF-8') ?>"></script>

    <script>
    (function () {
        'use strict';

        // 1. Page transition sweep-out
        var pageTransition = document.getElementById('pageTransition');
        setTimeout(function () {
            if (pageTransition) pageTransition.classList.remove('active');
        }, 100);

        // 2. Canvas particle system (1:1 Lumina Gold)
        var canvas = document.getElementById('particleCanvas');
        if (canvas) {
            var ctx = canvas.getContext('2d');
            function resizeCanvas() {
                canvas.width  = window.innerWidth;
                canvas.height = window.innerHeight;
            }
            resizeCanvas();
            window.addEventListener('resize', resizeCanvas);

            var particleColors = ['#1D3B5E', '#0D7A87', '#52B2DC', '#10B981'];
            var particles = [];
            for (var i = 0; i < 48; i++) {
                particles.push({
                    x: Math.random() * canvas.width,
                    y: Math.random() * canvas.height,
                    vx: (Math.random() - 0.5) * 0.5,
                    vy: (Math.random() - 0.5) * 0.5,
                    radius: Math.random() * 3 + 1,
                    color: particleColors[Math.floor(Math.random() * particleColors.length)],
                    opacity: Math.random() * 0.5 + 0.2
                });
            }
            function animate() {
                ctx.clearRect(0, 0, canvas.width, canvas.height);
                for (var i = 0; i < particles.length; i++) {
                    var p1 = particles[i];
                    p1.x += p1.vx;
                    p1.y += p1.vy;
                    if (p1.x < 0 || p1.x > canvas.width)  p1.vx *= -1;
                    if (p1.y < 0 || p1.y > canvas.height) p1.vy *= -1;
                    ctx.beginPath();
                    ctx.arc(p1.x, p1.y, p1.radius, 0, Math.PI * 2);
                    ctx.fillStyle = p1.color;
                    ctx.globalAlpha = p1.opacity;
                    ctx.fill();
                    for (var j = i + 1; j < particles.length; j++) {
                        var p2 = particles[j];
                        var dist = Math.hypot(p1.x - p2.x, p1.y - p2.y);
                        if (dist < 100) {
                            ctx.beginPath();
                            ctx.moveTo(p1.x, p1.y);
                            ctx.lineTo(p2.x, p2.y);
                            ctx.strokeStyle = p1.color;
                            ctx.globalAlpha = (1 - dist / 100) * 0.2;
                            ctx.stroke();
                        }
                    }
                }
                ctx.globalAlpha = 1;
                requestAnimationFrame(animate);
            }
            animate();
        }

        // 3. Toggle password visibility (Solar Icons)
        var btnToggle = document.getElementById('togglePasswordBtn');
        var passInput = document.getElementById('password');
        var passIcon  = document.getElementById('togglePasswordIcon');
        if (btnToggle && passInput && passIcon) {
            btnToggle.addEventListener('click', function () {
                if (passInput.type === 'password') {
                    passInput.type = 'text';
                    passIcon.setAttribute('icon', 'solar:eye-closed-linear');
                } else {
                    passInput.type = 'password';
                    passIcon.setAttribute('icon', 'solar:eye-linear');
                }
            });
        }

        // 4. Form submit loading
        var loginForm = document.getElementById('loginForm');
        var btnSubmit = document.getElementById('btnSubmit');
        if (loginForm && btnSubmit) {
            loginForm.addEventListener('submit', function () {
                btnSubmit.disabled = true;
                btnSubmit.innerHTML = '<iconify-icon icon="solar:refresh-bold" width="18"></iconify-icon><span>Memverifikasi...</span>';
            });
        }

        // 5. Google OAuth 2.0 Handler (strict linked-only via window.Ilmuna)
        var GOOGLE_CLIENT_ID = <?= json_encode($activeGoogleClientId) ?>;
        var btnGoogleLogin   = document.getElementById('btnGoogleLogin');
        var googleModal      = document.getElementById('googleLoginModal');
        var btnCloseGoogle   = document.getElementById('btnCloseGoogleModal');
        var btnCancelGoogle  = document.getElementById('btnCancelGoogleModal');
        var googleForm       = document.getElementById('googleLoginForm');
        var googleEmailInput = document.getElementById('googleEmailInput');
        var btnSubmitGoogle  = document.getElementById('btnSubmitGoogleLogin');

        function openGoogleModal() {
            if (!googleModal) return;
            googleModal.classList.add('active');
            setTimeout(function () { if (googleEmailInput) googleEmailInput.focus(); }, 80);
        }
        function closeGoogleModal() {
            if (googleModal) googleModal.classList.remove('active');
        }

        function submitGoogleVerify(payload, triggerBtn, defaultHtml) {
            if (triggerBtn) {
                triggerBtn.disabled = true;
                triggerBtn.innerHTML = '<iconify-icon icon="solar:refresh-bold" width="16"></iconify-icon><span>Memeriksa...</span>';
            }
            var fd = new FormData();
            fd.append('csrf_token', <?= json_encode($csrfToken) ?>);
            fd.append('login_mode', 'google');
            fd.append('ajax', '1');
            if (payload.accessToken) fd.append('google_access_token', payload.accessToken);
            else if (payload.email)  fd.append('email_google', payload.email);

            fetch(window.location.href, {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body: fd
            })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (res && res.success && res.redirect) {
                    closeGoogleModal();
                    if (window.Ilmuna) window.Ilmuna.toast('success', 'Berhasil Masuk', 'Akun Google terverifikasi. Mengalihkan...');
                    if (pageTransition) pageTransition.classList.add('active');
                    setTimeout(function () { window.location.href = res.redirect; }, 420);
                    return;
                }
                var msg = (res && res.message) ? res.message : 'Akun Google belum tertaut di database.';
                if (window.Ilmuna) window.Ilmuna.alert('error', 'Tidak Dapat Masuk', window.Ilmuna.escape(msg), 'Mengerti');
                if (triggerBtn) { triggerBtn.disabled = false; triggerBtn.innerHTML = defaultHtml; }
            })
            .catch(function () {
                if (triggerBtn) { triggerBtn.disabled = false; triggerBtn.innerHTML = defaultHtml; }
                if (window.Ilmuna) window.Ilmuna.error('Kesalahan Jaringan', 'Gagal menghubungi server autentikasi Margo One.');
            });
        }

        if (btnGoogleLogin) {
            var defaultGoogleHtml = btnGoogleLogin.innerHTML;
            btnGoogleLogin.addEventListener('click', function () {
                if (GOOGLE_CLIENT_ID && window.google && window.google.accounts && window.google.accounts.oauth2) {
                    var tokenClient = window.google.accounts.oauth2.initTokenClient({
                        client_id: GOOGLE_CLIENT_ID,
                        scope: 'openid email profile',
                        prompt: 'select_account',
                        callback: function (resp) {
                            if (resp && resp.access_token) {
                                submitGoogleVerify({ accessToken: resp.access_token }, btnGoogleLogin, defaultGoogleHtml);
                            } else if (resp && resp.error && window.Ilmuna) {
                                window.Ilmuna.alert('error', 'Autentikasi Google Gagal', window.Ilmuna.escape(resp.error_description || resp.error), 'Mengerti');
                            }
                        },
                        error_callback: function (err) {
                            if (err && err.type === 'popup_failed_to_open' && window.Ilmuna) {
                                window.Ilmuna.alert('warning', 'Pop-up Diblokir', 'Izinkan pop-up browser untuk masuk menggunakan akun Google.', 'Mengerti');
                            }
                        }
                    });
                    tokenClient.requestAccessToken();
                } else {
                    openGoogleModal();
                }
            });
        }

        if (btnCloseGoogle) btnCloseGoogle.addEventListener('click', closeGoogleModal);
        if (btnCancelGoogle) btnCancelGoogle.addEventListener('click', closeGoogleModal);
        if (googleModal) {
            googleModal.addEventListener('click', function (e) { if (e.target === googleModal) closeGoogleModal(); });
        }
        if (googleForm && btnSubmitGoogle) {
            var defaultModalHtml = btnSubmitGoogle.innerHTML;
            googleForm.addEventListener('submit', function (e) {
                e.preventDefault();
                var val = googleEmailInput ? googleEmailInput.value.trim() : '';
                if (!val) {
                    if (window.Ilmuna) window.Ilmuna.warning('Perhatian', 'Masukkan alamat email Google Anda.');
                    if (googleEmailInput) googleEmailInput.focus();
                    return;
                }
                submitGoogleVerify({ email: val }, btnSubmitGoogle, defaultModalHtml);
            });
        }
    })();
    </script>
</body>
</html>
