<?php
/**
 * ==============================================================================
 * MARGO ONE — ILMUNA SSO · OAUTH 2.0 + PKCE AUTHORIZE CORE ENGINE
 * ==============================================================================
 * Memisahkan logika validasi & penerbitan authorization_code agar bersih,
 * mudah diuji, dan dipakai langsung oleh authorize.php.
 * ==============================================================================
 */

if (!function_exists('margo_append_query')) {
    function margo_append_query($baseUrl, array $params)
    {
        $sep = (strpos((string)$baseUrl, '?') === false) ? '?' : '&';
        return (string)$baseUrl . $sep . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }
}

/**
 * Validasi penuh permintaan OAuth 2.0 /authorize + PKCE S256
 * Mengembalikan salah satu:
 *   ['action' => 'error_page',     'http_status' => 400/403, 'code' => '...', 'title' => '...', 'message' => '...']
 *   ['action' => 'redirect_error', 'http_status' => 302,     'url' => '...']
 *   ['action' => 'require_login',  'http_status' => 302,     'pending' => [...], 'prompt' => '...']
 *   ['action' => 'redirect_code',  'http_status' => 302,     'url' => '...', 'code' => '...', 'state' => '...']
 */
function margo_process_authorize_request(PDO $pdo, array $params, array $loggedInUser = null)
{
    margo_sso_ensure_schema($pdo);

    $clientKey     = trim((string)($params['client_id'] ?? ''));
    $redirectUri   = trim((string)($params['redirect_uri'] ?? ''));
    $responseType  = trim((string)($params['response_type'] ?? ''));
    $scopeRaw      = trim((string)($params['scope'] ?? 'openid profile email roles'));
    $state         = trim((string)($params['state'] ?? ''));
    $codeChallenge = trim((string)($params['code_challenge'] ?? ''));
    $codeMethod    = strtoupper(trim((string)($params['code_challenge_method'] ?? '')));
    $nonce         = trim((string)($params['nonce'] ?? ''));
    $prompt        = trim((string)($params['prompt'] ?? ''));

    // 1. Validasi client_id
    if ($clientKey === '') {
        return [
            'action'      => 'error_page',
            'http_status' => 400,
            'code'        => 'INVALID_CLIENT',
            'title'       => 'Parameter client_id Hilang',
            'message'     => 'Permintaan otorisasi OAuth 2.0 wajib menyertakan parameter client_id (Public Key) yang valid.',
        ];
    }

    $stmt = $pdo->prepare("SELECT * FROM sso_clients WHERE public_key = ? LIMIT 1");
    $stmt->execute([$clientKey]);
    $client = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$client) {
        margo_sso_log_audit($pdo, 'authorize_invalid_client', ['http_status' => 403, 'detail' => ['client_id' => $clientKey]]);
        return [
            'action'      => 'error_page',
            'http_status' => 403,
            'code'        => 'INVALID_CLIENT',
            'title'       => 'Aplikasi Klien Tidak Dikenali',
            'message'     => 'Public Key aplikasi (client_id) tidak terdaftar di Margo One SSO.',
        ];
    }

    if ((int)$client['is_active'] !== 1) {
        margo_sso_log_audit($pdo, 'authorize_client_disabled', ['client_id' => (int)$client['id'], 'http_status' => 403]);
        return [
            'action'      => 'error_page',
            'http_status' => 403,
            'code'        => 'CLIENT_DISABLED',
            'title'       => 'Aplikasi Klien Dinonaktifkan',
            'message'     => 'Akses SSO untuk aplikasi "' . $client['client_name'] . '" sedang dinonaktifkan oleh Administrator.',
        ];
    }

    // 2. Validasi EXACT-MATCH redirect_uri (Anti Open Redirect: JANGAN redirect bila gagal!)
    if (!margo_sso_redirect_uri_allowed($redirectUri, $client['redirect_uris'])) {
        margo_sso_log_audit($pdo, 'authorize_invalid_redirect_uri', [
            'client_id'   => (int)$client['id'],
            'http_status' => 403,
            'detail'      => ['redirect_uri' => $redirectUri],
        ]);
        return [
            'action'      => 'error_page',
            'http_status' => 403,
            'code'        => 'INVALID_REDIRECT_URI',
            'title'       => 'Redirect URI Tidak Terdaftar',
            'message'     => 'Alamat redirect_uri tidak cocok dengan daftar whitelist resmi aplikasi "' . $client['client_name'] . '".',
        ];
    }

    // 3. Validasi response_type, state & PKCE S256
    if ($responseType !== 'code') {
        return [
            'action'      => 'redirect_error',
            'http_status' => 302,
            'url'         => margo_append_query($redirectUri, [
                'error'             => 'unsupported_response_type',
                'error_description' => 'Margo One SSO hanya mendukung response_type=code.',
                'state'             => $state,
            ]),
        ];
    }

    if ($state === '' || strlen($state) < 8) {
        return [
            'action'      => 'redirect_error',
            'http_status' => 302,
            'url'         => margo_append_query($redirectUri, [
                'error'             => 'invalid_request',
                'error_description' => 'Parameter state (min 8 karakter) wajib disertakan untuk proteksi CSRF.',
                'state'             => $state,
            ]),
        ];
    }

    if ($codeMethod !== 'S256' || strlen($codeChallenge) < 43 || strlen($codeChallenge) > 128 || !preg_match('/^[A-Za-z0-9\-_]+$/', $codeChallenge)) {
        return [
            'action'      => 'redirect_error',
            'http_status' => 302,
            'url'         => margo_append_query($redirectUri, [
                'error'             => 'invalid_request',
                'error_description' => 'PKCE wajib menggunakan code_challenge_method=S256 dan code_challenge Base64URL valid.',
                'state'             => $state,
            ]),
        ];
    }

    // 4. Filter scope terhadap allowed_scopes klien
    $allowedScopes   = margo_sso_parse_list($client['allowed_scopes']);
    $requestedScopes = preg_split('/[\s,]+/', strtolower($scopeRaw));
    $grantedScopes   = [];
    if (is_array($requestedScopes)) {
        foreach ($requestedScopes as $sc) {
            $sc = trim($sc);
            if ($sc !== '' && (empty($allowedScopes) || in_array($sc, $allowedScopes, true))) {
                $grantedScopes[] = $sc;
            }
        }
    }
    if (empty($grantedScopes)) {
        $grantedScopes = ['openid', 'profile', 'email', 'roles'];
    }
    $finalScope = implode(' ', array_values(array_unique($grantedScopes)));

    // 5. Cek autentikasi user
    if ($loggedInUser === null || empty($loggedInUser['id']) || $prompt === 'login') {
        return [
            'action'      => 'require_login',
            'http_status' => 302,
            'prompt'      => $prompt,
            'pending'     => [
                'client_db_id'          => (int)$client['id'],
                'client_id'             => $clientKey,
                'redirect_uri'          => $redirectUri,
                'response_type'         => 'code',
                'scope'                 => $finalScope,
                'state'                 => $state,
                'code_challenge'        => $codeChallenge,
                'code_challenge_method' => 'S256',
                'nonce'                 => $nonce,
            ],
        ];
    }

    // 6. Verifikasi hak role pengguna terhadap client.allowed_roles
    $userRoles    = isset($loggedInUser['roles']) && is_array($loggedInUser['roles']) ? $loggedInUser['roles'] : [];
    $allowedRoles = margo_sso_parse_list($client['allowed_roles']);
    $roleAllowed  = empty($allowedRoles);
    if (!$roleAllowed) {
        foreach ($userRoles as $ur) {
            if (in_array(strtolower((string)$ur), $allowedRoles, true)) {
                $roleAllowed = true;
                break;
            }
        }
    }

    if (!$roleAllowed) {
        margo_sso_log_audit($pdo, 'authorize_role_denied', [
            'user_id'     => (int)$loggedInUser['id'],
            'client_id'   => (int)$client['id'],
            'http_status' => 403,
            'detail'      => ['user_roles' => $userRoles, 'allowed_roles' => $allowedRoles],
        ]);
        return [
            'action'      => 'error_page',
            'http_status' => 403,
            'code'        => 'ROLE_FORBIDDEN',
            'title'       => 'Peran Akun Tidak Diizinkan',
            'message'     => 'Akun Anda tidak memiliki hak peran yang diizinkan untuk mengakses aplikasi "' . $client['client_name'] . '".',
        ];
    }

    $pendingPayload = [
        'client_db_id'          => (int)$client['id'],
        'client_id'             => $clientKey,
        'redirect_uri'          => $redirectUri,
        'response_type'         => 'code',
        'scope'                 => $finalScope,
        'state'                 => $state,
        'code_challenge'        => $codeChallenge,
        'code_challenge_method' => 'S256',
        'nonce'                 => $nonce,
    ];

    // 7. Layar Konfirmasi Akun hanya ditampilkan bila diminta eksplisit (prompt=consent / select_account / confirm=1)
    //    Secara default (ketika masuk dari SSO / sudah punya sesi SSO aktif), langsung teruskan (seamless auto-login)!
    $requireConfirmScreen = in_array($prompt, ['consent', 'select_account'], true)
        || (isset($params['confirm']) && (string)$params['confirm'] === '1');
    $isConfirmedContinue = isset($params['continue']) && ((string)$params['continue'] === '1' || $params['continue'] === true);
    if ($requireConfirmScreen && !$isConfirmedContinue) {
        return [
            'action'      => 'confirm_account',
            'http_status' => 200,
            'client'      => $client,
            'user'        => $loggedInUser,
            'pending'     => $pendingPayload,
        ];
    }

    // 8. Pastikan sesi SSO global valid, lalu terbitkan authorization_code sekali pakai (TTL 60s)
    $ssoSessionId = (int)($loggedInUser['sso_session_id'] ?? 0);
    if ($ssoSessionId <= 0) {
        $newSess = margo_create_sso_session($pdo, (int)$loggedInUser['id'], $loggedInUser['active_role'] ?? null, (string)($loggedInUser['login_method'] ?? 'password'));
        $ssoSessionId = (int)$newSess['id'];
    }

    $authCode = margo_issue_auth_code(
        $pdo,
        (int)$client['id'],
        (int)$loggedInUser['id'],
        $ssoSessionId,
        $redirectUri,
        $finalScope,
        $codeChallenge,
        'S256',
        $nonce
    );

    return [
        'action'      => 'redirect_code',
        'http_status' => 302,
        'code'        => $authCode,
        'state'       => $state,
        'url'         => margo_append_query($redirectUri, [
            'code'  => $authCode,
            'state' => $state,
        ]),
    ];
}
