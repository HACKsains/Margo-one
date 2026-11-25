<?php
/**
 * ==============================================================================
 * MARGO ONE — ILMUNA SSO · NATIVE PHP WEB PUSH ENGINE (VAPID + RFC 8291)
 * ==============================================================================
 * Mengirim Web Push Notification ke browser/perangkat User & Admin meskipun web
 * sedang tidak dibuka (background Service Worker).
 *   - 100% Native PHP 7.4 (ea-php74) s/d PHP 8.3 (OpenSSL + cURL, tanpa Composer)
 *   - Auto-generate VAPID P-256 ECDSA keypair di tabel `sso_settings`
 *   - RFC 8292 VAPID ES256 JWT + RFC 8291 `aes128gcm` payload encryption
 *   - Deduplikasi pengiriman (`sso_push_dispatch_log`) untuk Agenda Hari Ini,
 *     Agenda Besok, Info Ekosistem, dan Notifikasi Kelas.
 * ==============================================================================
 */

if (!defined('BASE_PATH')) {
    require_once dirname(__DIR__) . '/config.php';
}
require_once BASE_PATH . '/includes/sso-security.php';
require_once BASE_PATH . '/includes/agenda-engine.php';

if (!function_exists('margo_push_b64url_encode')) {
    function margo_push_b64url_encode($bin)
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }
}

if (!function_exists('margo_push_b64url_decode')) {
    function margo_push_b64url_decode($str)
    {
        $s = strtr((string)$str, '-_', '+/');
        $pad = strlen($s) % 4;
        if ($pad > 0) {
            $s .= str_repeat('=', 4 - $pad);
        }
        return base64_decode($s);
    }
}

/**
 * Auto-migrasi tabel subscription & log dispatch Web Push
 */
function margo_push_ensure_schema(PDO $pdo)
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS sso_push_subscriptions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            endpoint TEXT NOT NULL,
            endpoint_hash CHAR(64) NOT NULL,
            p256dh VARCHAR(255) NOT NULL,
            auth_secret VARCHAR(120) NOT NULL,
            user_agent VARCHAR(255) NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_push_endpoint_hash (endpoint_hash),
            INDEX idx_push_user_active (user_id, is_active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS sso_push_dispatch_log (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            dedup_key VARCHAR(160) NOT NULL,
            category VARCHAR(40) NOT NULL DEFAULT 'info',
            title VARCHAR(255) NOT NULL,
            sent_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_push_user_dedup (user_id, dedup_key),
            INDEX idx_push_sent_at (sent_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

/**
 * Konversi DER ECDSA signature dari OpenSSL ke format raw 64-byte (R || S) untuk JWT ES256
 */
function margo_push_der_to_raw_ecdsa($der)
{
    $pos = 0;
    if (strlen($der) < 8 || ord($der[$pos++]) !== 0x30) {
        return null;
    }
    $seqLen = ord($der[$pos++]);
    if ($seqLen & 0x80) {
        $lenBytes = $seqLen & 0x7F;
        $pos += $lenBytes;
    }
    if (ord($der[$pos++]) !== 0x02) {
        return null;
    }
    $rLen = ord($der[$pos++]);
    $r = substr($der, $pos, $rLen);
    $pos += $rLen;

    if (ord($der[$pos++]) !== 0x02) {
        return null;
    }
    $sLen = ord($der[$pos++]);
    $s = substr($der, $pos, $sLen);

    $r = ltrim($r, "\x00");
    $s = ltrim($s, "\x00");
    $r = str_pad($r, 32, "\x00", STR_PAD_LEFT);
    $s = str_pad($s, 32, "\x00", STR_PAD_LEFT);

    return substr($r, -32) . substr($s, -32);
}

/**
 * Bungkus 65-byte uncompressed P-256 public key menjadi PEM SPKI agar diterima OpenSSL
 */
function margo_push_raw_p256_to_pem($raw65)
{
    if (strlen($raw65) !== 65 || ord($raw65[0]) !== 0x04) {
        return null;
    }
    // ASN.1 SubjectPublicKeyInfo header untuk EC prime256v1 (26 bytes)
    $spkiHeader = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200');
    $der = $spkiHeader . $raw65;
    return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
}

/**
 * Helper konfigurasi OpenSSL lintas-OS (Linux cPanel & Windows Laragon/XAMPP)
 */
function margo_push_openssl_ec_args()
{
    $args = [
        'curve_name'       => 'prime256v1',
        'private_key_type' => OPENSSL_KEYTYPE_EC,
    ];
    if (stripos(PHP_OS, 'WIN') === 0) {
        $candidates = [
            getenv('OPENSSL_CONF') ?: '',
            dirname(PHP_BINARY) . '/extras/ssl/openssl.cnf',
            'C:/laragon/etc/ssl/openssl.cnf',
            'C:/laragon/bin/php/php-8.4.12-nts-Win32-vs17-x64/extras/ssl/openssl.cnf',
            'C:/xampp/php/extras/ssl/openssl.cnf',
        ];
        foreach ($candidates as $cnf) {
            if ($cnf !== '' && @file_exists($cnf)) {
                $args['config'] = $cnf;
                break;
            }
        }
    }
    return $args;
}

/**
 * Ambil atau auto-generate sepasang kunci VAPID (prime256v1) di tabel sso_settings
 */
function margo_push_get_vapid_keys(PDO $pdo)
{
    margo_sso_ensure_schema($pdo);
    margo_push_ensure_schema($pdo);

    $pub = trim((string)margo_sso_get_setting($pdo, 'webpush_vapid_public', ''));
    $privPem = trim((string)margo_sso_get_setting($pdo, 'webpush_vapid_private_pem', ''));
    $subject = 'mailto:admin@ilmuna.my.id';

    if ($pub !== '' && $privPem !== '') {
        return [
            'public_key'  => $pub,
            'private_pem' => $privPem,
            'subject'     => $subject,
        ];
    }

    if (!function_exists('openssl_pkey_new')) {
        return null;
    }

    $ecArgs = margo_push_openssl_ec_args();
    $res = @openssl_pkey_new($ecArgs);
    if (!$res) {
        return null;
    }

    $exportedPriv = '';
    if (!@openssl_pkey_export($res, $exportedPriv, null, $ecArgs)) {
        return null;
    }
    $details = @openssl_pkey_get_details($res);
    if (empty($details['ec']['x']) || empty($details['ec']['y'])) {
        return null;
    }

    $x = str_pad($details['ec']['x'], 32, "\x00", STR_PAD_LEFT);
    $y = str_pad($details['ec']['y'], 32, "\x00", STR_PAD_LEFT);
    $rawPub = "\x04" . $x . $y;
    $pubB64 = margo_push_b64url_encode($rawPub);

    if (function_exists('margo_sso_set_setting')) {
        margo_sso_set_setting($pdo, 'webpush_vapid_public', $pubB64);
        margo_sso_set_setting($pdo, 'webpush_vapid_private_pem', $exportedPriv);
    }

    return [
        'public_key'  => $pubB64,
        'private_pem' => $exportedPriv,
        'subject'     => $subject,
    ];
}

/**
 * Buat VAPID JWT (ES256) untuk header Authorization
 */
function margo_push_create_vapid_jwt($endpoint, array $vapid)
{
    $parsed = parse_url((string)$endpoint);
    if (empty($parsed['scheme']) || empty($parsed['host'])) {
        return null;
    }
    $aud = $parsed['scheme'] . '://' . $parsed['host'] . (!empty($parsed['port']) ? (':' . $parsed['port']) : '');

    $header  = margo_push_b64url_encode(json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
    $payload = margo_push_b64url_encode(json_encode([
        'aud' => $aud,
        'exp' => time() + 43200,
        'sub' => $vapid['subject'],
    ]));
    $signingInput = $header . '.' . $payload;

    $privKey = @openssl_pkey_get_private($vapid['private_pem']);
    if (!$privKey) {
        return null;
    }
    $derSig = '';
    if (!@openssl_sign($signingInput, $derSig, $privKey, OPENSSL_ALGO_SHA256)) {
        return null;
    }
    $rawSig = margo_push_der_to_raw_ecdsa($derSig);
    if (!$rawSig) {
        return null;
    }
    return $signingInput . '.' . margo_push_b64url_encode($rawSig);
}

/**
 * Enkripsi payload Web Push sesuai standar RFC 8291 (aes128gcm)
 */
function margo_push_encrypt_payload($plaintext, $userPublicKeyB64, $userAuthB64)
{
    if (!function_exists('openssl_pkey_derive') || !function_exists('hash_hkdf')) {
        return null;
    }

    $userPubBin = margo_push_b64url_decode($userPublicKeyB64);
    $userAuth   = margo_push_b64url_decode($userAuthB64);
    if (strlen($userPubBin) !== 65 || strlen($userAuth) < 16) {
        return null;
    }

    $peerPem = margo_push_raw_p256_to_pem($userPubBin);
    if (!$peerPem) {
        return null;
    }
    $peerKey = @openssl_pkey_get_public($peerPem);
    if (!$peerKey) {
        return null;
    }

    // Generate ephemeral local EC P-256 keypair
    $localKey = @openssl_pkey_new(margo_push_openssl_ec_args());
    if (!$localKey) {
        return null;
    }
    $localDetails = @openssl_pkey_get_details($localKey);
    if (empty($localDetails['ec']['x']) || empty($localDetails['ec']['y'])) {
        return null;
    }
    $localPubBin = "\x04"
        . str_pad($localDetails['ec']['x'], 32, "\x00", STR_PAD_LEFT)
        . str_pad($localDetails['ec']['y'], 32, "\x00", STR_PAD_LEFT);

    $sharedSecret = @openssl_pkey_derive($peerKey, $localKey, 32);
    if ($sharedSecret === false || strlen($sharedSecret) !== 32) {
        return null;
    }

    $salt = random_bytes(16);

    // RFC 8291 Section 3.3: IKM = HKDF(salt=auth_secret, ikm=ecdh_secret, info="WebPush: info\0" || ua_public || as_public, L=32)
    $keyInfo = "WebPush: info\x00" . $userPubBin . $localPubBin;
    $ikm     = hash_hkdf('sha256', $sharedSecret, 32, $keyInfo, $userAuth);

    // RFC 8188: CEK & Nonce
    $cek   = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\x00", $salt);
    $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\x00", $salt);

    // Plaintext + delimiter 0x02 (final record)
    $padded = (string)$plaintext . "\x02";
    $tag    = '';
    $cipher = @openssl_encrypt($padded, 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
    if ($cipher === false || strlen($tag) !== 16) {
        return null;
    }

    // Content-Coding Header: salt (16) || rs (4, uint32be=4096) || idlen (1, 65) || keyid (65)
    $header = $salt . pack('N', 4096) . chr(65) . $localPubBin;
    return $header . $cipher . $tag;
}

/**
 * Kirim 1 Web Push ke 1 subscription endpoint
 */
function margo_push_send_raw(PDO $pdo, array $sub, array $payload, array $vapid)
{
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'http_code' => 0, 'error' => 'cURL tidak tersedia'];
    }
    $jwt = margo_push_create_vapid_jwt($sub['endpoint'], $vapid);
    if (!$jwt) {
        return ['ok' => false, 'http_code' => 0, 'error' => 'Gagal membuat VAPID JWT'];
    }

    $jsonPayload = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $encrypted   = margo_push_encrypt_payload($jsonPayload, $sub['p256dh'], $sub['auth_secret']);

    $headers = [
        'Authorization: vapid t=' . $jwt . ', k=' . $vapid['public_key'],
        'TTL: 86400',
        'Urgency: high',
    ];
    $body = '';
    if ($encrypted !== null) {
        $headers[] = 'Content-Encoding: aes128gcm';
        $headers[] = 'Content-Type: application/octet-stream';
        $headers[] = 'Content-Length: ' . strlen($encrypted);
        $body = $encrypted;
    } else {
        $headers[] = 'Content-Length: 0';
    }

    $ch = curl_init($sub['endpoint']);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    // Subscription kadaluwarsa / dicabut browser -> nonaktifkan otomatis
    if ($code === 404 || $code === 410) {
        try {
            $pdo->prepare("UPDATE sso_push_subscriptions SET is_active = 0 WHERE id = ?")->execute([(int)$sub['id']]);
        } catch (Throwable $eDeact) {}
    }

    return [
        'ok'        => ($code >= 200 && $code < 300),
        'http_code' => $code,
        'error'     => $err ?: ($code >= 400 ? substr((string)$resp, 0, 160) : ''),
    ];
}

/**
 * Kirim Web Push ke seluruh perangkat aktif milik 1 user (dengan dedup opsional)
 */
function margo_push_send_to_user(PDO $pdo, $userId, array $payload, $dedupKey = '')
{
    margo_push_ensure_schema($pdo);
    $userId = (int)$userId;
    if ($userId <= 0) {
        return ['sent' => 0, 'skipped' => true];
    }

    if ($dedupKey !== '') {
        $stChk = $pdo->prepare("SELECT id FROM sso_push_dispatch_log WHERE user_id = ? AND dedup_key = ? LIMIT 1");
        $stChk->execute([$userId, $dedupKey]);
        if ($stChk->fetchColumn()) {
            return ['sent' => 0, 'skipped' => true];
        }
    }

    $vapid = margo_push_get_vapid_keys($pdo);
    if (!$vapid) {
        return ['sent' => 0, 'error' => 'VAPID keys unavailable'];
    }

    $stSubs = $pdo->prepare("SELECT * FROM sso_push_subscriptions WHERE user_id = ? AND is_active = 1");
    $stSubs->execute([$userId]);
    $subs = $stSubs->fetchAll(PDO::FETCH_ASSOC);
    if (empty($subs)) {
        return ['sent' => 0, 'no_subscription' => true];
    }

    $sent = 0;
    foreach ($subs as $sub) {
        $res = margo_push_send_raw($pdo, $sub, $payload, $vapid);
        if (!empty($res['ok'])) {
            $sent++;
        }
    }

    if ($sent > 0 && $dedupKey !== '') {
        try {
            $pdo->prepare("INSERT IGNORE INTO sso_push_dispatch_log (user_id, dedup_key, category, title) VALUES (?, ?, ?, ?)")
                ->execute([
                    $userId,
                    $dedupKey,
                    substr((string)($payload['category'] ?? 'info'), 0, 40),
                    substr((string)($payload['title'] ?? 'Notifikasi'), 0, 250),
                ]);
        } catch (Throwable $eLog) {}
    }

    return ['sent' => $sent, 'total_subs' => count($subs)];
}

/**
 * Broadcast Web Push ke seluruh user/admin yang berlangganan (mis. Info Ekosistem baru)
 */
function margo_push_broadcast_all(PDO $pdo, array $payload, $dedupKey = '')
{
    margo_push_ensure_schema($pdo);
    $userIds = $pdo->query("SELECT DISTINCT user_id FROM sso_push_subscriptions WHERE is_active = 1")->fetchAll(PDO::FETCH_COLUMN);
    $totalSent = 0;
    foreach ($userIds as $uid) {
        $r = margo_push_send_to_user($pdo, (int)$uid, $payload, $dedupKey);
        $totalSent += (int)($r['sent'] ?? 0);
    }
    return ['users' => count($userIds), 'sent' => $totalSent];
}

/**
 * Dispatch otomatis Agenda Hari Ini, Agenda Besok, dan Info Ekosistem terbaru ke semua subscriber aktif
 */
function margo_push_dispatch_daily_agendas(PDO $pdo)
{
    margo_push_ensure_schema($pdo);
    $today    = date('Y-m-d');
    $tomorrow = date('Y-m-d', strtotime('+1 day'));

    $userIds = $pdo->query("SELECT DISTINCT user_id FROM sso_push_subscriptions WHERE is_active = 1")->fetchAll(PDO::FETCH_COLUMN);
    if (empty($userIds)) {
        return ['users' => 0, 'sent' => 0];
    }

    // Ambil Info Ekosistem yang terbit 24 jam terakhir
    $recentNews = [];
    try {
        $recentNews = $pdo->query("
            SELECT id, title, excerpt, link_url, badge_label, published_at
            FROM sso_announcements
            WHERE is_active = 1 AND published_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
            ORDER BY published_at DESC LIMIT 5
        ")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $eNw) {}

    $totalSent = 0;
    foreach ($userIds as $uidRaw) {
        $uid = (int)$uidRaw;
        // Bangun minimal userSession untuk filter agenda sesuai role & kelas
        $stU = $pdo->prepare("SELECT id, username, full_name, email, role FROM users WHERE id = ? LIMIT 1");
        $stU->execute([$uid]);
        $uRow = $stU->fetch(PDO::FETCH_ASSOC);
        if (!$uRow) {
            continue;
        }
        $roles = [];
        try {
            $stR = $pdo->prepare("SELECT role_code FROM user_roles WHERE user_id = ?");
            $stR->execute([$uid]);
            $roles = $stR->fetchAll(PDO::FETCH_COLUMN) ?: [];
        } catch (Throwable $eR) {}
        if (empty($roles) && !empty($uRow['role'])) {
            $roles = [(string)$uRow['role']];
        }
        $uSess = [
            'id'        => $uid,
            'username'  => (string)$uRow['username'],
            'full_name' => (string)$uRow['full_name'],
            'email'     => (string)($uRow['email'] ?? ''),
            'roles'     => $roles,
        ];

        // 1. Agenda Hari Ini & Besok
        try {
            $agendas = margo_agenda_fetch_visible($pdo, $uSess, [
                'upcoming'        => true,
                'limit'           => 30,
                'skip_learn_sync' => true,
            ]);
            foreach ($agendas as $ag) {
                $sd = (string)($ag['start_date'] ?? '');
                $ed = (string)($ag['end_date'] ?: $sd);
                $agId = (int)($ag['id'] ?? 0);
                if ($agId <= 0) {
                    continue;
                }

                if ($sd <= $today && $ed >= $today) {
                    $r = margo_push_send_to_user($pdo, $uid, [
                        'title'    => '📅 Agenda Hari Ini: ' . (string)$ag['title'],
                        'body'     => trim((string)($ag['description'] ?: ($ag['location'] ?: 'Jangan lewatkan agenda hari ini di Margo One.'))),
                        'category' => 'agenda_today',
                        'tag'      => 'agenda-today-' . $agId,
                        'url'      => BASE_URL . '/agenda',
                        'notif_id' => 'ag_today_' . $agId,
                    ], 'agenda_today_' . $agId . '_' . $today);
                    $totalSent += (int)($r['sent'] ?? 0);
                } elseif ($sd === $tomorrow) {
                    $r = margo_push_send_to_user($pdo, $uid, [
                        'title'    => '⏰ Agenda Besok: ' . (string)$ag['title'],
                        'body'     => trim((string)($ag['description'] ?: ($ag['location'] ?: 'Persiapkan diri untuk agenda besok.'))),
                        'category' => 'agenda_tomorrow',
                        'tag'      => 'agenda-tomorrow-' . $agId,
                        'url'      => BASE_URL . '/agenda',
                        'notif_id' => 'ag_tomorrow_' . $agId,
                    ], 'agenda_tomorrow_' . $agId . '_' . $tomorrow);
                    $totalSent += (int)($r['sent'] ?? 0);
                }
            }
        } catch (Throwable $eAg) {}

        // 2. Info Ekosistem Terbaru
        foreach ($recentNews as $nw) {
            $nwId = (int)$nw['id'];
            $r = margo_push_send_to_user($pdo, $uid, [
                'title'    => '📢 Info Ekosistem: ' . (string)$nw['title'],
                'body'     => trim((string)($nw['excerpt'] ?: 'Klik untuk membaca pengumuman lengkap.')),
                'category' => 'ecosystem_info',
                'tag'      => 'eco-info-' . $nwId,
                'url'      => !empty($nw['link_url']) ? (string)$nw['link_url'] : (BASE_URL . '/gateway'),
                'notif_id' => 'news_' . $nwId,
            ], 'eco_news_' . $nwId);
            $totalSent += (int)($r['sent'] ?? 0);
        }
    }

    return ['users' => count($userIds), 'sent' => $totalSent, 'date' => $today];
}
