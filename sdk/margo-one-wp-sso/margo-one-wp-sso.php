<?php
/**
 * Plugin Name: Margo One — Ilmuna SSO Connector (Lumina Gold Edition)
 * Plugin URI:  https://margo-one.ilmuna.my.id
 * Description: Single Sign-On (OAuth 2.0 + PKCE S256 + JWT RS256 + Public/Private Key + Back-Channel SLO) resmi untuk menghubungkan WordPress (ilmuna.my.id) ke Margo One SSO. 100% 1 tema Lumina Gold (z-index: 60 terisolasi, solid opaque, Light & Dark Mode).
 * Version:     1.0.0
 * Author:      Ilmuna Digital Ecosystem
 * License:     GPL-2.0-or-later
 * Requires PHP: 7.4
 */

if (!defined('ABSPATH')) {
    exit;
}

define('MARGO_WP_SSO_VERSION', '1.0.0');
define('MARGO_WP_SSO_OPTION', 'margo_one_wp_sso_settings');

/**
 * Ambil pengaturan plugin (mendukung override wp-config.php untuk keamanan ekstra)
 */
function margo_wp_sso_get_settings()
{
    $defaults = [
        'sso_url'         => 'https://margo-one.ilmuna.my.id',
        'public_key'      => '',
        'private_key'     => '',
        'sig_mode'        => 'HMAC-SHA256',
        'rsa_private_pem' => '',
        'verify_response' => 1,
        'auto_provision'  => 1,
        'role_admin'      => 'administrator',
        'role_guru'       => 'editor',
        'role_ketua'      => 'subscriber',
        'role_siswa'      => 'subscriber',
        'redirect_after'  => home_url('/'),
    ];
    $saved = get_option(MARGO_WP_SSO_OPTION, []);
    if (!is_array($saved)) {
        $saved = [];
    }
    $cfg = array_merge($defaults, $saved);

    if (defined('MARGO_SSO_URL') && MARGO_SSO_URL !== '') {
        $cfg['sso_url'] = MARGO_SSO_URL;
    }
    if (defined('MARGO_SSO_PUBLIC_KEY') && MARGO_SSO_PUBLIC_KEY !== '') {
        $cfg['public_key'] = MARGO_SSO_PUBLIC_KEY;
    }
    if (defined('MARGO_SSO_PRIVATE_KEY') && MARGO_SSO_PRIVATE_KEY !== '') {
        $cfg['private_key'] = MARGO_SSO_PRIVATE_KEY;
    }
    if (defined('MARGO_SSO_RSA_PRIVATE_PEM') && MARGO_SSO_RSA_PRIVATE_PEM !== '') {
        $cfg['rsa_private_pem'] = MARGO_SSO_RSA_PRIVATE_PEM;
    }

    $cfg['sso_url'] = rtrim((string)$cfg['sso_url'], '/');
    return $cfg;
}

function margo_wp_b64u_enc($data)
{
    return rtrim(strtr(base64_encode((string)$data), '+/', '-_'), '=');
}

function margo_wp_b64u_dec($data)
{
    $s = strtr((string)$data, '-_', '+/');
    $rem = strlen($s) % 4;
    if ($rem > 0) {
        $s .= str_repeat('=', 4 - $rem);
    }
    return base64_decode($s, true);
}

function margo_wp_asn1_len($len)
{
    $len = (int)$len;
    if ($len <= 0x7F) {
        return chr($len);
    }
    $tmp = ltrim(pack('N', $len), "\x00");
    return chr(0x80 | strlen($tmp)) . $tmp;
}

function margo_wp_asn1_int($bytes)
{
    $bytes = ltrim((string)$bytes, "\x00");
    if ($bytes === '') {
        $bytes = "\x00";
    }
    if ((ord($bytes[0]) & 0x80) !== 0) {
        $bytes = "\x00" . $bytes;
    }
    return "\x02" . margo_wp_asn1_len(strlen($bytes)) . $bytes;
}

function margo_wp_jwk_to_pem(array $jwk)
{
    if (($jwk['kty'] ?? '') !== 'RSA' || empty($jwk['n']) || empty($jwk['e'])) {
        return '';
    }
    $mod = margo_wp_b64u_dec($jwk['n']);
    $exp = margo_wp_b64u_dec($jwk['e']);
    if ($mod === false || $exp === false) {
        return '';
    }
    $rsaSeq = margo_wp_asn1_int($mod) . margo_wp_asn1_int($exp);
    $rsaPub = "\x30" . margo_wp_asn1_len(strlen($rsaSeq)) . $rsaSeq;
    $bitStr = "\x03" . margo_wp_asn1_len(strlen($rsaPub) + 1) . "\x00" . $rsaPub;
    $algoId = "\x30\x0d\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00";
    $spki   = "\x30" . margo_wp_asn1_len(strlen($algoId . $bitStr)) . $algoId . $bitStr;
    return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($spki), 64, "\n") . "-----END PUBLIC KEY-----\n";
}

function margo_wp_get_jwks($ssoUrl, $force = false)
{
    $tKey = 'margo_wp_jwks_' . md5($ssoUrl);
    if (!$force) {
        $cached = get_transient($tKey);
        if (is_array($cached) && !empty($cached['keys'])) {
            return $cached;
        }
    }
    $res = wp_remote_get($ssoUrl . '/api/sso/jwks.php', [
        'timeout' => 10,
        'headers' => ['Accept' => 'application/json'],
    ]);
    if (is_wp_error($res)) {
        return $res;
    }
    $jwks = json_decode((string)wp_remote_retrieve_body($res), true);
    if ((int)wp_remote_retrieve_response_code($res) !== 200 || !is_array($jwks) || empty($jwks['keys'])) {
        return new WP_Error('margo_jwks_err', 'Gagal memuat JWKS publik dari Margo One SSO.');
    }
    set_transient($tKey, $jwks, 3600);
    return $jwks;
}

function margo_wp_verify_jwt($jwt, array $cfg)
{
    $parts = explode('.', trim((string)$jwt));
    if (count($parts) !== 3) {
        return new WP_Error('margo_jwt_fmt', 'Format JWT tidak valid.');
    }
    $header  = json_decode((string)margo_wp_b64u_dec($parts[0]), true);
    $payload = json_decode((string)margo_wp_b64u_dec($parts[1]), true);
    $sig     = margo_wp_b64u_dec($parts[2]);
    if (!is_array($header) || !is_array($payload) || $sig === false || ($header['alg'] ?? '') !== 'RS256') {
        return new WP_Error('margo_jwt_alg', 'Algoritma JWT harus RS256.');
    }

    $kid = (string)($header['kid'] ?? '');
    $jwks = margo_wp_get_jwks($cfg['sso_url'], false);
    if (is_wp_error($jwks)) {
        return $jwks;
    }

    $findPem = function ($set) use ($kid) {
        foreach ((array)($set['keys'] ?? []) as $k) {
            if (is_array($k) && ($kid === '' || (string)($k['kid'] ?? '') === $kid)) {
                return margo_wp_jwk_to_pem($k);
            }
        }
        return '';
    };

    $pem = $findPem($jwks);
    if ($pem === '') {
        $jwks = margo_wp_get_jwks($cfg['sso_url'], true);
        if (!is_wp_error($jwks)) {
            $pem = $findPem($jwks);
        }
    }
    if ($pem === '') {
        return new WP_Error('margo_jwt_kid', 'Kunci publik JWKS untuk kid tidak ditemukan.');
    }

    if (@openssl_verify($parts[0] . '.' . $parts[1], $sig, $pem, OPENSSL_ALGO_SHA256) !== 1) {
        return new WP_Error('margo_jwt_sig', 'Tanda tangan RS256 JWT tidak valid.');
    }

    $now = time();
    if (isset($payload['exp']) && ($now - 60) >= (int)$payload['exp']) {
        return new WP_Error('margo_jwt_exp', 'Token JWT kedaluwarsa.');
    }
    if (rtrim((string)($payload['iss'] ?? ''), '/') !== $cfg['sso_url']) {
        return new WP_Error('margo_jwt_iss', 'Issuer JWT tidak cocok.');
    }
    if ((string)($payload['aud'] ?? '') !== (string)$cfg['public_key']) {
        return new WP_Error('margo_jwt_aud', 'Audience JWT tidak cocok dengan Public Key WordPress.');
    }
    return $payload;
}

/**
 * Signed Server-to-Server POST ke Margo One SSO
 */
function margo_wp_signed_post($endpointPath, array $bodyArray, array $cfg)
{
    $rawBody = wp_json_encode($bodyArray);
    $ts      = (string)time();
    $nonce   = bin2hex(random_bytes(16));
    $base    = preg_replace('/\.php$/i', '', basename($endpointPath));
    $cPath   = '/api/sso/' . strtolower($base);
    $canon   = implode("\n", ['POST', $cPath, '', $ts, $nonce, hash('sha256', (string)$rawBody)]);

    $siteHost = parse_url(home_url(), PHP_URL_HOST);
    if (!is_string($siteHost) || $siteHost === '') {
        $siteHost = 'ilmuna.my.id';
    }

    $headers = [
        'Accept'              => 'application/json',
        'Content-Type'        => 'application/json',
        'X-Ilmuna-Public-Key' => $cfg['public_key'],
        'X-Ilmuna-Timestamp'  => $ts,
        'X-Ilmuna-Nonce'      => $nonce,
        'X-Ilmuna-Site'       => $siteHost,
    ];

    if ($cfg['sig_mode'] === 'HMAC-SHA256' || $cfg['sig_mode'] === 'DUAL') {
        $headers['X-Ilmuna-Signature'] = hash_hmac('sha256', $canon, $cfg['private_key']);
    }
    if ($cfg['sig_mode'] === 'RSA-SHA256' || $cfg['sig_mode'] === 'DUAL') {
        $rawSig = '';
        if (empty($cfg['rsa_private_pem']) || !@openssl_sign($canon, $rawSig, $cfg['rsa_private_pem'], OPENSSL_ALGO_SHA256)) {
            return new WP_Error('margo_rsa_err', 'Gagal menandatangani request dengan RSA Private PEM.');
        }
        $headers['X-Ilmuna-RSA-Signature'] = base64_encode($rawSig);
    }

    $resp = wp_remote_post($cfg['sso_url'] . '/' . ltrim($endpointPath, '/'), [
        'timeout' => 15,
        'headers' => $headers,
        'body'    => $rawBody,
    ]);
    if (is_wp_error($resp)) {
        return $resp;
    }

    $code    = (int)wp_remote_retrieve_response_code($resp);
    $resBody = (string)wp_remote_retrieve_body($resp);
    $decoded = json_decode($resBody, true);

    if ($code !== 200 || !is_array($decoded) || empty($decoded['success'])) {
        $msg = is_array($decoded) && isset($decoded['error']['message'])
            ? $decoded['error']['message'] . ' [' . ($decoded['error']['code'] ?? $code) . ']'
            : ('Respon HTTP ' . $code . ' dari Margo One SSO tidak valid.');
        return new WP_Error('margo_http_err', $msg);
    }

    if (!empty($cfg['verify_response'])) {
        $rTs  = (string)wp_remote_retrieve_header($resp, 'x-ilmuna-response-timestamp');
        $rSig = strtolower(trim((string)wp_remote_retrieve_header($resp, 'x-ilmuna-response-signature')));
        if ($rTs === '' || $rSig === '' || abs(time() - (int)$rTs) > 300) {
            return new WP_Error('margo_resp_ts', 'Header tanda tangan respon Margo One tidak valid.');
        }
        $expSig = hash_hmac('sha256', $rTs . "\n" . $resBody, $cfg['private_key']);
        if (!hash_equals($expSig, $rSig)) {
            return new WP_Error('margo_resp_sig', 'Verifikasi X-Ilmuna-Response-Signature gagal.');
        }
    }

    return $decoded;
}

/**
 * Pemetaan Role Margo One -> Role WordPress
 */
function margo_wp_map_role(array $ssoRoles, array $cfg)
{
    if (in_array('admin', $ssoRoles, true)) {
        return sanitize_key($cfg['role_admin'] ?: 'administrator');
    }
    if (in_array('guru', $ssoRoles, true)) {
        return sanitize_key($cfg['role_guru'] ?: 'editor');
    }
    if (in_array('ketua_kelas', $ssoRoles, true)) {
        return sanitize_key($cfg['role_ketua'] ?: 'subscriber');
    }
    return sanitize_key($cfg['role_siswa'] ?: 'subscriber');
}

/**
 * Router Frontend:
 *   - /?margo_one_login=1    -> Mulai OAuth2 + PKCE S256
 *   - /?margo_one_callback=1 -> Callback OAuth2 + Login WordPress
 *   - /?margo_one_slo=1      -> Back-Channel Single Logout Webhook
 */
add_action('init', function () {
    $cfg = margo_wp_sso_get_settings();

    // 1. Inisiasi Login Margo One
    if (!empty($_GET['margo_one_login'])) {
        if (empty($cfg['public_key']) || empty($cfg['private_key'])) {
            wp_die('Konfigurasi Margo One SSO belum diisi di Settings -> Margo One SSO.');
        }
        $state    = bin2hex(random_bytes(20));
        $nonce    = bin2hex(random_bytes(16));
        $verifier = margo_wp_b64u_enc(random_bytes(48));
        $chall    = margo_wp_b64u_enc(hash('sha256', $verifier, true));

        $redirectAfter = !empty($_GET['redirect_to']) ? esc_url_raw(wp_unslash($_GET['redirect_to'])) : $cfg['redirect_after'];
        set_transient('margo_pkce_' . $state, [
            'verifier'    => $verifier,
            'nonce'       => $nonce,
            'redirect_to' => $redirectAfter,
        ], 600);

        $callbackUri = add_query_arg('margo_one_callback', '1', home_url('/'));
        $authUrl = $cfg['sso_url'] . '/authorize?' . http_build_query([
            'client_id'             => $cfg['public_key'],
            'redirect_uri'          => $callbackUri,
            'response_type'         => 'code',
            'scope'                 => 'openid profile email roles',
            'state'                 => $state,
            'nonce'                 => $nonce,
            'code_challenge'        => $chall,
            'code_challenge_method' => 'S256',
        ], '', '&', PHP_QUERY_RFC3986);

        wp_redirect($authUrl);
        exit;
    }

    // 2. Callback OAuth2 + PKCE
    if (!empty($_GET['margo_one_callback'])) {
        if (!empty($_GET['error'])) {
            wp_die('Autentikasi Margo One dibatalkan: ' . esc_html(sanitize_text_field(wp_unslash($_GET['error_description'] ?? $_GET['error']))));
        }

        $code  = sanitize_text_field(wp_unslash($_GET['code'] ?? ''));
        $state = sanitize_text_field(wp_unslash($_GET['state'] ?? ''));
        $flow  = ($state !== '') ? get_transient('margo_pkce_' . $state) : false;
        if ($state !== '') {
            delete_transient('margo_pkce_' . $state);
        }

        if ($code === '' || !is_array($flow) || empty($flow['verifier'])) {
            wp_die('Sesi PKCE Margo One tidak valid atau kedaluwarsa. Silakan ulangi login.');
        }

        $callbackUri = add_query_arg('margo_one_callback', '1', home_url('/'));
        $tokenRes = margo_wp_signed_post('/api/sso/token.php', [
            'grant_type'    => 'authorization_code',
            'code'          => $code,
            'redirect_uri'  => $callbackUri,
            'code_verifier' => $flow['verifier'],
        ], $cfg);

        if (is_wp_error($tokenRes)) {
            wp_die('Penukaran token Margo One gagal: ' . esc_html($tokenRes->get_error_message()));
        }

        $claims = margo_wp_verify_jwt($tokenRes['id_token'] ?? '', $cfg);
        if (is_wp_error($claims)) {
            wp_die('Verifikasi JWT RS256 gagal: ' . esc_html($claims->get_error_message()));
        }
        if (!empty($flow['nonce']) && (!isset($claims['nonce']) || !hash_equals((string)$flow['nonce'], (string)$claims['nonce']))) {
            wp_die('Verifikasi nonce id_token gagal.');
        }

        $ssoSub   = (string)($claims['sub'] ?? '');
        $username = sanitize_user((string)($claims['username'] ?? ('margo_' . $ssoSub)), true);
        $email    = sanitize_email((string)($claims['email'] ?? ''));
        $fullName = sanitize_text_field((string)($claims['name'] ?? $username));
        $ssoRoles = is_array($claims['roles'] ?? null) ? $claims['roles'] : ['siswa'];
        $wpRole   = margo_wp_map_role($ssoRoles, $cfg);

        // Cari WP User berdasarkan _margo_one_sub -> email -> username
        $wpUser = null;
        $bySub = get_users(['meta_key' => '_margo_one_sub', 'meta_value' => $ssoSub, 'number' => 1]);
        if (!empty($bySub)) {
            $wpUser = $bySub[0];
        }
        if (!$wpUser && $email !== '') {
            $wpUser = get_user_by('email', $email);
        }
        if (!$wpUser && $username !== '') {
            $wpUser = get_user_by('login', $username);
        }

        if (!$wpUser) {
            if (empty($cfg['auto_provision'])) {
                wp_die('Akun WordPress belum tersedia dan auto-provisioning dinonaktifkan.');
            }
            $newEmail = $email !== '' ? $email : ($username . '@sso.ilmuna.my.id');
            $userId = wp_insert_user([
                'user_login'   => $username,
                'user_pass'    => wp_generate_password(24, true, true),
                'user_email'   => $newEmail,
                'display_name' => $fullName,
                'first_name'   => $fullName,
                'role'         => $wpRole,
            ]);
            if (is_wp_error($userId)) {
                wp_die('Gagal membuat akun WordPress lokal: ' . esc_html($userId->get_error_message()));
            }
            $wpUser = get_user_by('id', $userId);
        } else {
            $wpUser->set_role($wpRole);
        }

        update_user_meta($wpUser->ID, '_margo_one_sub', $ssoSub);
        update_user_meta($wpUser->ID, '_margo_one_sid', (string)($tokenRes['sid'] ?? ($claims['sid'] ?? '')));

        wp_clear_auth_cookie();
        wp_set_current_user($wpUser->ID);
        wp_set_auth_cookie($wpUser->ID, true, is_ssl());

        $target = !empty($flow['redirect_to']) ? $flow['redirect_to'] : home_url('/');
        wp_safe_redirect($target);
        exit;
    }

    // 3. Back-Channel Single Logout (SLO) Webhook
    if (!empty($_GET['margo_one_slo']) && strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? '')) === 'POST') {
        header('Content-Type: application/json; charset=utf-8');
        $rawBody = (string)file_get_contents('php://input');
        $pubKey  = (string)($_SERVER['HTTP_X_ILMUNA_PUBLIC_KEY'] ?? '');
        $ts      = (string)($_SERVER['HTTP_X_ILMUNA_TIMESTAMP'] ?? '');
        $nonce   = (string)($_SERVER['HTTP_X_ILMUNA_NONCE'] ?? '');
        $sig     = strtolower((string)($_SERVER['HTTP_X_ILMUNA_SIGNATURE'] ?? ''));

        if ($pubKey === '' || !hash_equals($cfg['public_key'], $pubKey) || !ctype_digit($ts) || abs(time() - (int)$ts) > 300) {
            status_header(401);
            echo wp_json_encode(['success' => false, 'error' => 'UNAUTHORIZED_SLO']);
            exit;
        }
        $expSig = hash_hmac('sha256', $ts . "\n" . $nonce . "\n" . $rawBody, $cfg['private_key']);
        if (!hash_equals($expSig, $sig)) {
            status_header(401);
            echo wp_json_encode(['success' => false, 'error' => 'INVALID_SLO_SIGNATURE']);
            exit;
        }

        $body = json_decode($rawBody, true);
        $ssoSub = isset($body['user_id']) ? (string)$body['user_id'] : '';
        if ($ssoSub !== '') {
            $users = get_users(['meta_key' => '_margo_one_sub', 'meta_value' => $ssoSub, 'fields' => 'ID']);
            foreach ($users as $uid) {
                if (class_exists('WP_Session_Tokens')) {
                    WP_Session_Tokens::get_instance((int)$uid)->destroy_all();
                }
            }
        }
        echo wp_json_encode(['success' => true, 'message' => 'WP session destroyed via Margo One SLO.']);
        exit;
    }
});

/**
 * CSS Tema 100% Lumina Gold (WAJIB z-index: 60 !important; isolation: isolate !important;
 * solid opaque + Light & Dark Mode sesuai standar tema WordPress lumina-gold)
 */
function margo_wp_sso_render_styles()
{
    static $printed = false;
    if ($printed) {
        return;
    }
    $printed = true;
    ?>
    <style id="margo-one-lumina-gold-css">
    .margo-one-sso-wrap {
        position: relative !important;
        z-index: 60 !important;
        isolation: isolate !important;
        background: #FFFFFF !important;
        border: 1.5px solid #E2E8F0 !important;
        border-radius: 16px !important;
        padding: 14px 16px !important;
        margin: 14px 0 !important;
        box-shadow: 0 12px 28px -12px rgba(29, 59, 94, 0.18) !important;
        font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif !important;
    }
    .margo-one-sso-btn {
        position: relative !important;
        z-index: 60 !important;
        isolation: isolate !important;
        display: inline-flex !important;
        align-items: center !important;
        justify-content: center !important;
        gap: 10px !important;
        width: 100% !important;
        min-height: 46px !important;
        padding: 11px 18px !important;
        border-radius: 12px !important;
        border: none !important;
        background: linear-gradient(135deg, #1D3B5E 0%, #0D7A87 55%, #52B2DC 100%) !important;
        color: #FFFFFF !important;
        font-family: 'Outfit', 'Plus Jakarta Sans', sans-serif !important;
        font-size: 14.5px !important;
        font-weight: 700 !important;
        text-decoration: none !important;
        box-shadow: 0 10px 22px -6px rgba(13, 122, 135, 0.42) !important;
        transition: transform 0.2s ease, box-shadow 0.2s ease !important;
        box-sizing: border-box !important;
    }
    .margo-one-sso-btn:hover {
        transform: translateY(-2px) !important;
        box-shadow: 0 14px 28px -6px rgba(13, 122, 135, 0.55) !important;
        color: #FFFFFF !important;
    }
    .margo-one-sso-note {
        margin-top: 8px !important;
        font-size: 11.5px !important;
        color: #64748B !important;
        text-align: center !important;
    }
    html[data-ilmuna-theme="dark"] .margo-one-sso-wrap,
    body.dark-mode .margo-one-sso-wrap {
        background: #102035 !important;
        border-color: rgba(82, 178, 220, 0.30) !important;
        color: #F8FAFC !important;
    }
    html[data-ilmuna-theme="dark"] .margo-one-sso-note,
    body.dark-mode .margo-one-sso-note {
        color: #94A3B8 !important;
    }
    </style>
    <?php
}

/**
 * Tombol "Masuk dengan Margo One" di wp-login.php
 */
add_action('login_form', function () {
    $cfg = margo_wp_sso_get_settings();
    if (empty($cfg['public_key']) || empty($cfg['private_key'])) {
        return;
    }
    margo_wp_sso_render_styles();
    $loginUrl = add_query_arg('margo_one_login', '1', home_url('/'));
    echo '<div class="margo-one-sso-wrap">'
        . '<a href="' . esc_url($loginUrl) . '" class="margo-one-sso-btn">'
        . '<span>Masuk dengan Margo One</span>'
        . '</a>'
        . '<div class="margo-one-sso-note">Single Sign-On resmi ekosistem Ilmuna (OAuth 2.0 + PKCE)</div>'
        . '</div>';
});

/**
 * Shortcode [margo_one_login_button label="Masuk dengan Margo One"]
 */
add_shortcode('margo_one_login_button', function ($atts) {
    $a = shortcode_atts([
        'label'       => 'Masuk dengan Margo One',
        'redirect_to' => '',
    ], $atts);

    margo_wp_sso_render_styles();

    if (is_user_logged_in()) {
        $u = wp_get_current_user();
        return '<div class="margo-one-sso-wrap">'
            . '<div style="font-weight:700;font-size:13.5px;margin-bottom:8px;">Masuk sebagai: ' . esc_html($u->display_name) . '</div>'
            . '<a href="' . esc_url(wp_logout_url(home_url('/'))) . '" class="margo-one-sso-btn">Keluar dari Akun</a>'
            . '</div>';
    }

    $args = ['margo_one_login' => '1'];
    if (!empty($a['redirect_to'])) {
        $args['redirect_to'] = $a['redirect_to'];
    }
    $url = add_query_arg($args, home_url('/'));

    return '<div class="margo-one-sso-wrap">'
        . '<a href="' . esc_url($url) . '" class="margo-one-sso-btn">' . esc_html($a['label']) . '</a>'
        . '<div class="margo-one-sso-note">Satu akun resmi ekosistem Ilmuna · Margo One SSO</div>'
        . '</div>';
});

/**
 * Halaman Pengaturan WP Admin: Settings -> Margo One SSO
 */
add_action('admin_menu', function () {
    add_options_page(
        'Margo One SSO',
        'Margo One SSO',
        'manage_options',
        'margo-one-wp-sso',
        'margo_wp_sso_settings_page'
    );
});

function margo_wp_sso_settings_page()
{
    if (!current_user_can('manage_options')) {
        return;
    }

    $notice = '';
    if (isset($_POST['margo_wp_sso_save']) && check_admin_referer('margo_wp_sso_save_action')) {
        $newCfg = [
            'sso_url'         => esc_url_raw(rtrim(wp_unslash($_POST['sso_url'] ?? ''), '/')),
            'public_key'      => sanitize_text_field(wp_unslash($_POST['public_key'] ?? '')),
            'private_key'     => sanitize_text_field(wp_unslash($_POST['private_key'] ?? '')),
            'sig_mode'        => sanitize_text_field(wp_unslash($_POST['sig_mode'] ?? 'HMAC-SHA256')),
            'rsa_private_pem' => trim((string)wp_unslash($_POST['rsa_private_pem'] ?? '')),
            'verify_response' => !empty($_POST['verify_response']) ? 1 : 0,
            'auto_provision'  => !empty($_POST['auto_provision']) ? 1 : 0,
            'role_admin'      => sanitize_key(wp_unslash($_POST['role_admin'] ?? 'administrator')),
            'role_guru'       => sanitize_key(wp_unslash($_POST['role_guru'] ?? 'editor')),
            'role_ketua'      => sanitize_key(wp_unslash($_POST['role_ketua'] ?? 'subscriber')),
            'role_siswa'      => sanitize_key(wp_unslash($_POST['role_siswa'] ?? 'subscriber')),
            'redirect_after'  => esc_url_raw(wp_unslash($_POST['redirect_after'] ?? home_url('/'))),
        ];
        update_option(MARGO_WP_SSO_OPTION, $newCfg, false);
        $notice = '<div class="notice notice-success"><p>Pengaturan Margo One SSO berhasil disimpan.</p></div>';
    }

    $cfg = margo_wp_sso_get_settings();
    $callbackUri = add_query_arg('margo_one_callback', '1', home_url('/'));
    $sloUri      = add_query_arg('margo_one_slo', '1', home_url('/'));
    ?>
    <div class="wrap">
        <h1>Margo One — Ilmuna SSO Settings</h1>
        <?= $notice ?>
        <p><strong>Callback URI (salin ke Admin Margo One):</strong> <code><?= esc_html($callbackUri) ?></code><br>
           <strong>SLO Webhook URI:</strong> <code><?= esc_html($sloUri) ?></code><br>
           <strong>Shortcode Tombol Login:</strong> <code>[margo_one_login_button]</code></p>
        <form method="post">
            <?php wp_nonce_field('margo_wp_sso_save_action'); ?>
            <table class="form-table">
                <tr><th>SSO Base URL</th><td><input type="url" name="sso_url" class="regular-text" value="<?= esc_attr($cfg['sso_url']) ?>" required></td></tr>
                <tr><th>Public Key (pk_sso_...)</th><td><input type="text" name="public_key" class="regular-text" value="<?= esc_attr($cfg['public_key']) ?>" required></td></tr>
                <tr><th>Private Key (sk_sso_...)</th><td><input type="password" name="private_key" class="regular-text" value="<?= esc_attr($cfg['private_key']) ?>" required></td></tr>
                <tr><th>Signature Mode</th><td>
                    <select name="sig_mode">
                        <option value="HMAC-SHA256" <?php selected($cfg['sig_mode'], 'HMAC-SHA256'); ?>>HMAC-SHA256</option>
                        <option value="RSA-SHA256" <?php selected($cfg['sig_mode'], 'RSA-SHA256'); ?>>RSA-SHA256</option>
                        <option value="DUAL" <?php selected($cfg['sig_mode'], 'DUAL'); ?>>DUAL (HMAC + RSA)</option>
                    </select>
                </td></tr>
                <tr><th>RSA Private Key PEM (Opsional)</th><td><textarea name="rsa_private_pem" rows="4" class="large-text code"><?= esc_textarea($cfg['rsa_private_pem']) ?></textarea></td></tr>
                <tr><th>Pemetaan Role (admin / guru / siswa)</th><td>
                    Admin &rarr; <input type="text" name="role_admin" value="<?= esc_attr($cfg['role_admin']) ?>" size="14">
                    Guru &rarr; <input type="text" name="role_guru" value="<?= esc_attr($cfg['role_guru']) ?>" size="14">
                    Ketua Kelas &rarr; <input type="text" name="role_ketua" value="<?= esc_attr($cfg['role_ketua']) ?>" size="14">
                    Siswa &rarr; <input type="text" name="role_siswa" value="<?= esc_attr($cfg['role_siswa']) ?>" size="14">
                </td></tr>
                <tr><th>Redirect Setelah Login</th><td><input type="url" name="redirect_after" class="regular-text" value="<?= esc_attr($cfg['redirect_after']) ?>"></td></tr>
                <tr><th>Keamanan &amp; Provisioning</th><td>
                    <label><input type="checkbox" name="verify_response" value="1" <?php checked($cfg['verify_response'], 1); ?>> Verifikasi X-Ilmuna-Response-Signature</label><br>
                    <label><input type="checkbox" name="auto_provision" value="1" <?php checked($cfg['auto_provision'], 1); ?>> Buat akun WP otomatis untuk user SSO terverifikasi</label>
                </td></tr>
            </table>
            <p class="submit"><button type="submit" name="margo_wp_sso_save" class="button button-primary">Simpan Pengaturan SSO</button></p>
        </form>
    </div>
    <?php
}
