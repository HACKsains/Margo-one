<?php
/**
 * ==============================================================================
 * MARGO ONE — ILMUNA SSO · TEMPLATE EMAIL VERIFIKASI PENAUTAN AKUN GOOGLE
 * ==============================================================================
 * Lokasi: includes/email-verify-google-template.php
 * Identik dengan C:\laragon\www\learn\includes\email-verify-google-template.php:
 *   - Helper fetchGoogleApi() (cURL + stream fallback)
 *   - Auto-schema tabel google_link_verifications
 *   - Template Email Lumina Gold & Nusantara (#1D3B5E, #0D7A87, #52B2DC)
 *   - Pengiriman via Google Apps Script Relay HTTPS + Dual-Port Socket SMTP + mail()
 * ==============================================================================
 */

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}

require_once BASE_PATH . '/config.php';

/**
 * Helper HTTP request ke Google OAuth API (mendukung cURL & stream fallback)
 */
if (!function_exists('fetchGoogleApi')) {
    function fetchGoogleApi($url, $postFields = null, $bearerToken = null)
    {
        $headers = [];
        if (is_string($bearerToken) && $bearerToken !== '') {
            $headers[] = 'Authorization: Bearer ' . $bearerToken;
        }
        if (is_array($postFields)) {
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        }
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT        => 10,
                CURLOPT_CONNECTTIMEOUT => 6,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => 0,
            ]);
            if (is_array($postFields)) {
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postFields));
            }
            if (!empty($headers)) {
                curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            }
            $body = curl_exec($ch);
            curl_close($ch);
            if (is_string($body) && $body !== '') {
                $dec = json_decode($body, true);
                if (is_array($dec)) {
                    return $dec;
                }
            }
        }
        $opts = [
            'http' => [
                'method'        => is_array($postFields) ? 'POST' : 'GET',
                'timeout'       => 10,
                'ignore_errors' => true,
            ],
            'ssl' => [
                'verify_peer'      => false,
                'verify_peer_name' => false,
            ],
        ];
        if (!empty($headers)) {
            $opts['http']['header'] = implode("\r\n", $headers) . "\r\n";
        }
        if (is_array($postFields)) {
            $opts['http']['content'] = http_build_query($postFields);
        }
        $resp = @file_get_contents($url, false, stream_context_create($opts));
        return $resp ? json_decode($resp, true) : null;
    }
}

/**
 * Pastikan tabel google_link_verifications tersedia di database
 */
if (!function_exists('ilmuna_ensure_google_verification_table')) {
    function ilmuna_ensure_google_verification_table($pdo)
    {
        static $ensured = false;
        if ($ensured || !$pdo) {
            return;
        }
        try {
            $sql = "CREATE TABLE IF NOT EXISTS `google_link_verifications` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `user_id` INT NOT NULL,
                `email_google` VARCHAR(150) NOT NULL,
                `google_id` VARCHAR(100) NULL,
                `token` VARCHAR(64) NOT NULL UNIQUE,
                `expires_at` DATETIME NOT NULL,
                `created_at` DATETIME NOT NULL,
                `verified_at` DATETIME NULL,
                `ip_address` VARCHAR(45) NULL,
                INDEX `idx_token` (`token`),
                INDEX `idx_user_id` (`user_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
            $pdo->exec($sql);
            $ensured = true;
        } catch (Exception $e) {
            // Abaikan jika sudah ada
        }
    }
}

if (!function_exists('ilmuna_smtp_config')) {
    function ilmuna_smtp_config()
    {
        $file = BASE_PATH . '/config/smtp_config.json';
        $defaults = [
            'google_relay_url' => defined('ILMUNA_GOOGLE_RELAY_URL') ? ILMUNA_GOOGLE_RELAY_URL : '',
            'host'             => defined('ILMUNA_SMTP_HOST') ? ILMUNA_SMTP_HOST : 'mail.ilmuna.my.id',
            'port'             => defined('ILMUNA_SMTP_PORT') ? (int)ILMUNA_SMTP_PORT : 465,
            'secure'           => defined('ILMUNA_SMTP_SECURE') ? ILMUNA_SMTP_SECURE : 'ssl',
            'user'             => defined('ILMUNA_SMTP_USER') ? ILMUNA_SMTP_USER : 'noreply@ilmuna.my.id',
            'pass'             => defined('ILMUNA_SMTP_PASS') ? ILMUNA_SMTP_PASS : 'Ilmuna28@',
            'from'             => defined('ILMUNA_SMTP_FROM') ? ILMUNA_SMTP_FROM : 'noreply@ilmuna.my.id',
            'from_name'        => defined('ILMUNA_SMTP_FROM_NAME') ? ILMUNA_SMTP_FROM_NAME : 'Margo One — Ilmuna SSO',
            'is_enabled'       => defined('ILMUNA_SMTP_ENABLED') ? (ILMUNA_SMTP_ENABLED ? 1 : 0) : 1,
        ];
        if (file_exists($file)) {
            $json = @file_get_contents($file);
            if ($json) {
                $saved = json_decode($json, true);
                if (is_array($saved)) {
                    return array_merge($defaults, $saved);
                }
            }
        }
        return $defaults;
    }
}

if (!function_exists('ilmuna_build_google_relay_auth_payload')) {
    function ilmuna_build_google_relay_auth_payload($action, $to = '', $subject = '', $pdfFilename = '')
    {
        $token      = defined('ILMUNA_GOOGLE_RELAY_TOKEN') ? trim((string)ILMUNA_GOOGLE_RELAY_TOKEN) : '1LmuN@28';
        $publicKey  = defined('ILMUNA_GOOGLE_RELAY_PUBLIC_KEY') ? trim((string)ILMUNA_GOOGLE_RELAY_PUBLIC_KEY) : '';
        $privateKey = defined('ILMUNA_GOOGLE_RELAY_PRIVATE_KEY') ? trim((string)ILMUNA_GOOGLE_RELAY_PRIVATE_KEY) : '';

        $actionNorm = strtolower(trim((string)$action));
        $toNorm     = trim((string)$to);
        $subjNorm   = trim((string)$subject);
        $pdfNorm    = trim((string)$pdfFilename);

        $timestamp = (string)time();
        $nonce     = bin2hex(random_bytes(16));
        $bodyHash  = hash('sha256', $actionNorm . '|' . $toNorm . '|' . $subjNorm . '|' . $pdfNorm);
        $canonical = $publicKey . '|' . $token . '|' . $timestamp . '|' . $nonce . '|' . $bodyHash;
        $signature = hash_hmac('sha256', $canonical, $privateKey);

        return [
            'action'     => $actionNorm,
            'token'      => $token,
            'public_key' => $publicKey,
            'timestamp'  => $timestamp,
            'nonce'      => $nonce,
            'body_hash'  => $bodyHash,
            'signature'  => $signature,
        ];
    }
}

if (!function_exists('ilmuna_post_json_google_relay')) {
    function ilmuna_post_json_google_relay($relayUrl, $payloadArr, $timeoutSec = 25)
    {
        $payload = json_encode($payloadArr, JSON_UNESCAPED_SLASHES);
        if ($payload === false) {
            return ['ok' => false, 'json' => null, 'error' => 'Gagal menyusun payload JSON.'];
        }
        $lastError = '';
        if (function_exists('curl_init')) {
            $ch = curl_init($relayUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json; charset=utf-8']);
            curl_setopt($ch, CURLOPT_TIMEOUT, $timeoutSec);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 12);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            $res     = curl_exec($ch);
            $code    = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlErr = curl_error($ch);
            curl_close($ch);

            if ($code >= 200 && $code < 400 && !empty($res)) {
                $json = @json_decode($res, true);
                if (is_array($json)) {
                    return ['ok' => true, 'json' => $json, 'error' => ''];
                }
            }
            $lastError = $curlErr ?: ('HTTP ' . $code);
        }

        $opts = [
            'http' => [
                'method'          => 'POST',
                'header'          => "Content-Type: application/json; charset=utf-8\r\nContent-Length: " . strlen($payload) . "\r\n",
                'content'         => $payload,
                'follow_location' => 1,
                'timeout'         => $timeoutSec,
                'ignore_errors'   => true,
            ],
            'ssl' => [
                'verify_peer'      => false,
                'verify_peer_name' => false,
            ],
        ];
        $res = @file_get_contents($relayUrl, false, stream_context_create($opts));
        if (!empty($res)) {
            $json = @json_decode($res, true);
            if (is_array($json)) {
                return ['ok' => true, 'json' => $json, 'error' => ''];
            }
        }
        return ['ok' => false, 'json' => null, 'error' => $lastError ?: 'null/forbidden'];
    }
}

if (!function_exists('ilmuna_send_via_google_relay')) {
    function ilmuna_send_via_google_relay($relayUrl, $params)
    {
        $to       = trim($params['to'] ?? '');
        $subject  = trim($params['subject'] ?? '');
        $bodyHtml = $params['body'] ?? ($params['body_html'] ?? '');
        $fromName = $params['from_name'] ?? 'Margo One — Ilmuna SSO';

        $auth = ilmuna_build_google_relay_auth_payload('send_email', $to, $subject, '');
        $payloadArr = array_merge($auth, [
            'to'        => $to,
            'subject'   => $subject,
            'html'      => $bodyHtml,
            'from_name' => $fromName,
        ]);

        $encodedInline = [];
        if (!empty($params['attachments']) && is_array($params['attachments'])) {
            foreach ($params['attachments'] as $att) {
                if (!is_array($att)) {
                    continue;
                }
                if (!empty($att['cid']) && !empty($att['path']) && file_exists($att['path'])) {
                    $rawInline = @file_get_contents($att['path']);
                    if ($rawInline !== false) {
                        $encodedInline[(string)$att['cid']] = [
                            'mimeType'      => $att['mime'] ?? 'image/png',
                            'contentBase64' => base64_encode($rawInline),
                        ];
                    }
                }
            }
        }
        if (!empty($encodedInline)) {
            $payloadArr['inline_images'] = $encodedInline;
        }

        $httpRes = ilmuna_post_json_google_relay($relayUrl, $payloadArr, 25);
        if (!empty($httpRes['ok']) && is_array($httpRes['json']) && !empty($httpRes['json']['success'])) {
            return [
                'success' => true,
                'message' => $httpRes['json']['message'] ?? 'Email berhasil dikirim via Google Relay HTTPS!',
            ];
        }
        return [
            'success' => false,
            'message' => 'Google Relay gagal: ' . ($httpRes['error'] ?? 'error'),
        ];
    }
}

if (!function_exists('_ilmuna_single_socket_smtp')) {
    function _ilmuna_single_socket_smtp($params)
    {
        $host     = $params['host'];
        $port     = (int)$params['port'];
        $secure   = strtolower((string)$params['secure']);
        $user     = trim($params['user'] ?? '');
        $pass     = trim(str_replace(' ', '', $params['pass'] ?? ''));
        $from     = trim($params['from'] ?? '') ?: ($user ?: 'noreply@ilmuna.my.id');
        $fromName = !empty($params['from_name']) ? $params['from_name'] : 'Margo One — Ilmuna SSO';
        $to       = $params['to'] ?? '';
        $subject  = $params['subject'] ?? '';
        $body     = $params['body'] ?? ($params['body_html'] ?? '');

        $timeout    = 12;
        $errno      = 0;
        $errstr     = '';
        $targetHost = ($secure === 'ssl') ? ('ssl://' . $host) : ('tcp://' . $host);

        $context = stream_context_create([
            'ssl' => [
                'verify_peer'       => false,
                'verify_peer_name'  => false,
                'allow_self_signed' => true,
            ],
        ]);

        $socket = @stream_socket_client($targetHost . ':' . $port, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $context);
        if (!$socket) {
            return ['success' => false, 'message' => "Gagal terhubung ke host SMTP ($host:$port): $errstr ($errno)"];
        }
        stream_set_timeout($socket, $timeout);

        $readResponse = function () use ($socket) {
            $data = '';
            while (!feof($socket)) {
                $line = fgets($socket, 1024);
                $data .= $line;
                if (substr((string)$line, 3, 1) === ' ' || empty($line)) {
                    break;
                }
            }
            return $data;
        };

        $sendCommand = function ($cmd) use ($socket, $readResponse) {
            fputs($socket, $cmd . "\r\n");
            return $readResponse();
        };

        $initial = $readResponse();
        if (substr($initial, 0, 3) !== '220') {
            fclose($socket);
            return ['success' => false, 'message' => 'Handshake SMTP gagal: ' . trim($initial)];
        }

        $sendCommand('EHLO ' . ($_SERVER['SERVER_NAME'] ?? 'localhost'));

        if ($secure === 'tls') {
            $tlsRes = $sendCommand('STARTTLS');
            if (substr($tlsRes, 0, 3) !== '220') {
                fclose($socket);
                return ['success' => false, 'message' => 'STARTTLS ditolak: ' . trim($tlsRes)];
            }
            $cryptoMethod = STREAM_CRYPTO_METHOD_TLS_CLIENT;
            if (defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT')) {
                $cryptoMethod |= STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
            }
            if (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT')) {
                $cryptoMethod |= STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;
            }
            if (!@stream_socket_enable_crypto($socket, true, $cryptoMethod)) {
                fclose($socket);
                return ['success' => false, 'message' => 'Gagal negosiasi TLS SMTP.'];
            }
            $sendCommand('EHLO ' . ($_SERVER['SERVER_NAME'] ?? 'localhost'));
        }

        if ($user !== '' && $pass !== '') {
            if (substr($sendCommand('AUTH LOGIN'), 0, 3) !== '334') {
                fclose($socket);
                return ['success' => false, 'message' => 'AUTH LOGIN tidak didukung.'];
            }
            if (substr($sendCommand(base64_encode($user)), 0, 3) !== '334') {
                fclose($socket);
                return ['success' => false, 'message' => 'Username SMTP ditolak.'];
            }
            if (substr($sendCommand(base64_encode($pass)), 0, 3) !== '235') {
                fclose($socket);
                return ['success' => false, 'message' => 'Password SMTP ditolak.'];
            }
        }

        if (substr($sendCommand('MAIL FROM:<' . $from . '>'), 0, 3) !== '250') {
            fclose($socket);
            return ['success' => false, 'message' => 'MAIL FROM ditolak.'];
        }
        $rcpt = $sendCommand('RCPT TO:<' . $to . '>');
        if (substr($rcpt, 0, 3) !== '250' && substr($rcpt, 0, 3) !== '251') {
            fclose($socket);
            return ['success' => false, 'message' => 'RCPT TO ditolak.'];
        }
        if (substr($sendCommand('DATA'), 0, 3) !== '354') {
            fclose($socket);
            return ['success' => false, 'message' => 'DATA ditolak.'];
        }

        $encodedFromName = '=?UTF-8?B?' . base64_encode($fromName) . '?=';
        $encodedSubject  = '=?UTF-8?B?' . base64_encode($subject) . '?=';
        $headers = [
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . $encodedFromName . ' <' . $from . '>',
            'To: <' . $to . '>',
            'Reply-To: ' . $encodedFromName . ' <' . $from . '>',
            'Subject: ' . $encodedSubject,
            'Date: ' . date('r'),
            'X-Mailer: Margo-One-SSO-SMTP/1.0',
        ];
        $payload = implode("\r\n", $headers) . "\r\n\r\n" . $body . "\r\n.";
        $sendRes = $sendCommand($payload);
        $sendCommand('QUIT');
        fclose($socket);

        if (substr($sendRes, 0, 3) === '250') {
            return ['success' => true, 'message' => 'Email verifikasi berhasil terkirim ke ' . $to];
        }
        return ['success' => false, 'message' => 'Pengiriman email gagal: ' . trim($sendRes)];
    }
}

if (!function_exists('ilmuna_raw_smtp_send')) {
    function ilmuna_raw_smtp_send($params)
    {
        $cfg = ilmuna_smtp_config();
        $relayUrl = trim((string)($cfg['google_relay_url'] ?? ''));
        if ($relayUrl !== '') {
            $relayRes = ilmuna_send_via_google_relay($relayUrl, $params);
            if (!empty($relayRes['success'])) {
                return $relayRes;
            }
        }

        $res = _ilmuna_single_socket_smtp($params);
        if (!empty($res['success'])) {
            return $res;
        }

        $origPort   = (int)($params['port'] ?? 465);
        $origSecure = strtolower((string)($params['secure'] ?? 'ssl'));
        $altParams  = $params;
        if ($origPort === 587 || $origSecure === 'tls') {
            $altParams['port']   = 465;
            $altParams['secure'] = 'ssl';
        } else {
            $altParams['port']   = 587;
            $altParams['secure'] = 'tls';
        }
        $altRes = _ilmuna_single_socket_smtp($altParams);
        if (!empty($altRes['success'])) {
            return $altRes;
        }

        if (function_exists('mail') && filter_var($params['to'] ?? '', FILTER_VALIDATE_EMAIL)) {
            $from     = $params['from'] ?? 'noreply@ilmuna.my.id';
            $fromName = '=?UTF-8?B?' . base64_encode($params['from_name'] ?? 'Margo One — Ilmuna SSO') . '?=';
            $subj     = '=?UTF-8?B?' . base64_encode($params['subject'] ?? '') . '?=';
            $hdr      = "MIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\nFrom: {$fromName} <{$from}>\r\n";
            if (@mail($params['to'], $subj, $params['body'] ?? '', $hdr)) {
                return ['success' => true, 'message' => 'Email berhasil dikirim via mail server hosting.'];
            }
        }

        return $res;
    }
}

/**
 * Render template HTML email verifikasi penautan Google SSO
 */
if (!function_exists('ilmuna_get_google_verify_email_template')) {
    function ilmuna_get_google_verify_email_template($data)
    {
        $c_navy  = '#1D3B5E';
        $c_teal  = '#0D7A87';
        $c_sky   = '#52B2DC';

        $nama           = htmlspecialchars($data['nama'] ?? 'Pengguna', ENT_QUOTES, 'UTF-8');
        $emailGoogle    = htmlspecialchars($data['email_google'] ?? '', ENT_QUOTES, 'UTF-8');
        $verifyUrl      = htmlspecialchars($data['verify_url'] ?? '#', ENT_QUOTES, 'UTF-8');
        $expiresMinutes = (int)($data['expires_minutes'] ?? 60);

        $siteName = defined('APP_NAME') ? APP_NAME : 'Margo One — Ilmuna SSO';
        $siteTag  = defined('APP_TAGLINE') ? APP_TAGLINE : 'Identity Provider & Single Sign-On Ekosistem Ilmuna';
        $baseUrl  = defined('BASE_URL') ? rtrim(BASE_URL, '/') : 'https://margo-one.ilmuna.my.id';
        $tahun    = date('Y');

        $logoFile = BASE_PATH . '/assets/images/logo-margo-one.png';
        if (!file_exists($logoFile)) {
            $logoFile = BASE_PATH . '/assets/images/logo.png';
        }
        $attachments = [];
        $logoSrc     = $baseUrl . '/assets/images/logo-margo-one.png';

        if (file_exists($logoFile)) {
            $cidName = !empty($data['logo_cid']) ? $data['logo_cid'] : 'ilmuna_logo';
            $attachments[] = [
                'path'        => $logoFile,
                'name'        => 'logo-margo-one.png',
                'mime'        => 'image/png',
                'cid'         => $cidName,
                'disposition' => 'inline',
            ];
            $logoSrc = 'cid:' . $cidName;
        }

        $subject = "Verifikasi Penautan Akun Google - {$siteName}";

        $body = <<<HTML
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$subject}</title>
</head>
<body style="margin:0;padding:0;background-color:#F1F5F9;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#1D3B5E;">
    <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="background-color:#F1F5F9;padding:28px 0 36px;">
        <tr>
            <td align="center" style="padding:0 12px;">
                <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="600" style="max-width:600px;width:100%;background-color:#FFFFFF;border-radius:16px;overflow:hidden;box-shadow:0 8px 30px rgba(29,59,94,0.08);border:1px solid #E2E8F0;">
                    <tr>
                        <td style="background:linear-gradient(135deg, {$c_navy} 0%, #152C47 50%, {$c_teal} 100%);padding:32px 30px 26px;text-align:center;">
                            <table role="presentation" cellspacing="0" cellpadding="0" border="0" align="center" style="margin:0 auto 14px;">
                                <tr>
                                    <td align="center" style="width:74px;height:74px;background-color:#FFFFFF;border-radius:18px;box-shadow:0 6px 20px rgba(0,0,0,0.18);border:2px solid {$c_sky};text-align:center;vertical-align:middle;">
                                        <img src="{$logoSrc}" alt="{$siteName}" width="52" height="52" style="width:52px;height:52px;max-width:52px;display:block;margin:0 auto;object-fit:contain;" />
                                    </td>
                                </tr>
                            </table>
                            <h1 style="margin:0 0 6px;color:#FFFFFF;font-size:21px;font-weight:800;line-height:1.3;">Verifikasi Penautan Akun Google</h1>
                            <p style="margin:0;color:{$c_sky};font-size:13px;font-weight:700;letter-spacing:0.8px;text-transform:uppercase;">{$siteName}</p>
                            <p style="margin:4px 0 0;color:#E2E8F0;font-size:11.5px;">{$siteTag}</p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:30px 32px 20px;">
                            <p style="margin:0 0 12px;font-size:14.5px;color:{$c_navy};line-height:1.6;">
                                <strong>Assalamu'alaikum Warahmatullahi Wabarakatuh,</strong><br>
                                Yth. <strong style="color:{$c_teal};">{$nama}</strong>
                            </p>
                            <p style="margin:0 0 18px;font-size:13.5px;color:#475569;line-height:1.7;">
                                Kami menerima permintaan untuk menautkan akun Google Anda ke profil identitas terpusat <strong>{$siteName}</strong>.
                                Silakan konfirmasi kepemilikan akun Google ini agar tombol <em>Masuk dengan Google</em> aktif untuk akun Anda.
                            </p>
                            <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="background-color:#F8FAFC;border:1.5px solid #CBD5E1;border-left:4.5px solid {$c_teal};border-radius:12px;margin-bottom:22px;">
                                <tr>
                                    <td style="padding:16px 20px;">
                                        <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:{$c_teal};margin-bottom:5px;">Alamat Akun Google yang Ditautkan</div>
                                        <div style="font-family:Consolas,monospace;font-size:17px;font-weight:800;color:{$c_navy};word-break:break-all;">{$emailGoogle}</div>
                                    </td>
                                </tr>
                            </table>
                            <table role="presentation" cellspacing="0" cellpadding="0" border="0" align="center" style="margin:0 auto 24px;">
                                <tr>
                                    <td align="center" style="border-radius:10px;background:linear-gradient(135deg, {$c_teal} 0%, {$c_navy} 100%);box-shadow:0 6px 18px rgba(13,122,135,0.35);">
                                        <a href="{$verifyUrl}" target="_blank" style="display:inline-block;padding:14px 32px;font-size:15px;font-weight:800;color:#FFFFFF;text-decoration:none;border-radius:10px;">
                                            Konfirmasi &amp; Tautkan Akun Google
                                        </a>
                                    </td>
                                </tr>
                            </table>
                            <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="background-color:#F0F9FF;border-left:4px solid {$c_sky};border-radius:8px;margin-bottom:20px;">
                                <tr>
                                    <td style="padding:14px 16px;font-size:12.5px;color:{$c_navy};line-height:1.6;">
                                        <strong>Batas Waktu Tautan:</strong><br>
                                        &bull; Tautan verifikasi ini berlaku selama <strong>{$expiresMinutes} menit</strong>.<br>
                                        &bull; Setelah diverifikasi, Anda dapat langsung masuk ke Margo One SSO dan seluruh aplikasi ekosistem Ilmuna menggunakan tombol <em>Masuk dengan Google</em>.
                                    </td>
                                </tr>
                            </table>
                            <p style="margin:0 0 5px;font-size:11.5px;color:#64748B;">Jika tombol di atas tidak dapat diklik, buka URL berikut di browser Anda:</p>
                            <p style="margin:0 0 10px;font-size:12px;color:{$c_teal};word-break:break-all;">
                                <a href="{$verifyUrl}" target="_blank" style="color:{$c_teal};text-decoration:underline;font-weight:600;">{$verifyUrl}</a>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:20px 30px 22px;background-color:#F8FAFC;border-top:1px solid #E2E8F0;text-align:center;">
                            <p style="margin:0 0 4px;font-size:13px;font-weight:700;color:{$c_navy};">{$siteName}</p>
                            <p style="margin:0;font-size:11px;color:#94A3B8;">&copy; {$tahun} {$siteName}. Hak Cipta Dilindungi.</p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
HTML;

        return [
            'subject'     => $subject,
            'body'        => $body,
            'attachments' => $attachments,
        ];
    }
}

/**
 * Kirim email verifikasi Google SSO ke alamat Gmail target
 */
if (!function_exists('ilmuna_send_google_verify_email')) {
    function ilmuna_send_google_verify_email($toEmail, $userName, $verifyUrl, $expiresInMinutes = 60)
    {
        if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
            return [
                'success' => false,
                'message' => 'Format alamat email tidak valid: ' . $toEmail,
            ];
        }

        $cfg = ilmuna_smtp_config();
        if (empty($cfg['is_enabled'])) {
            return [
                'success' => false,
                'message' => 'Pengiriman email dinonaktifkan di pengaturan SMTP server.',
            ];
        }

        $tpl = ilmuna_get_google_verify_email_template([
            'nama'            => $userName,
            'email_google'    => $toEmail,
            'verify_url'      => $verifyUrl,
            'expires_minutes' => $expiresInMinutes,
        ]);

        return ilmuna_raw_smtp_send([
            'host'        => $cfg['host'] ?: 'mail.ilmuna.my.id',
            'port'        => (int)($cfg['port'] ?: 465),
            'secure'      => strtolower($cfg['secure'] ?: 'ssl'),
            'user'        => $cfg['user'] ?: '',
            'pass'        => $cfg['pass'] ?: '',
            'from'        => $cfg['from'] ?: ($cfg['user'] ?: 'noreply@ilmuna.my.id'),
            'from_name'   => $cfg['from_name'] ?: 'Margo One — Ilmuna SSO',
            'to'          => $toEmail,
            'subject'     => $tpl['subject'],
            'body'        => $tpl['body'],
            'attachments' => $tpl['attachments'] ?? [],
        ]);
    }
}
