<?php
/**
 * ==============================================================================
 * MARGO ONE — ILMUNA SSO · GLOBAL SINGLE LOGOUT (SLO) ENDPOINT (/logout)
 * ==============================================================================
 * 1. Tandai seluruh sso_sessions.revoked_at = NOW() milik pengguna terkait
 * 2. Cabut seluruh sso_refresh_tokens sesi terkait
 * 3. Catat margo_slo_revocations & kirim Back-Channel Signed SLO Webhook ke semua
 *    aplikasi klien aktif (learn.ilmuna.my.id, WordPress, dll.)
 * 4. Mendukung parameter Front-Channel SLO bertanda tangan HMAC (client_id, uid,
 *    sid, ts, sig) dari aplikasi klien agar meskipun cookie SSO di browser
 *    berbeda/kosong, seluruh sesi pengguna tetap dicabut secara global!
 * 5. Hapus cookie & session PHP lokal, lalu arahkan ke post_logout_redirect_uri
 *    atau kembali ke /login?msg=logged_out
 * ==============================================================================
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';

$pdo = getDBConnection();
margo_sso_ensure_schema($pdo);

$revokeAll     = (!isset($_GET['all']) || $_GET['all'] !== '0');
$postLogoutUri = trim((string)($_GET['post_logout_redirect_uri'] ?? $_POST['post_logout_redirect_uri'] ?? ''));

// Dukungan Front-Channel SLO bertanda tangan dari aplikasi klien (learn / WordPress)
$reqClientId = trim((string)($_GET['client_id'] ?? $_POST['client_id'] ?? ''));
$reqUid      = (int)($_GET['uid'] ?? $_POST['uid'] ?? 0);
$reqSid      = (int)($_GET['sid'] ?? $_POST['sid'] ?? 0);
$reqTs       = trim((string)($_GET['ts'] ?? $_POST['ts'] ?? ''));
$reqSig      = strtolower(trim((string)($_GET['sig'] ?? $_POST['sig'] ?? '')));

if ($reqClientId !== '' && ($reqUid > 0 || $reqSid > 0) && ctype_digit($reqTs) && abs(time() - (int)$reqTs) <= 300 && $reqSig !== '') {
    try {
        $stClient = $pdo->prepare("SELECT id, public_key, private_key_enc FROM sso_clients WHERE public_key = ? AND is_active = 1 LIMIT 1");
        $stClient->execute([$reqClientId]);
        $clientRow = $stClient->fetch(PDO::FETCH_ASSOC);
        if ($clientRow) {
            $privKey = margo_sso_decrypt_secret($clientRow['private_key_enc']);
            $canon   = 'SLO|' . $reqClientId . '|' . $reqUid . '|' . $reqSid . '|' . $reqTs;
            $expSig  = hash_hmac('sha256', $canon, $privKey);
            if ($privKey !== '' && hash_equals($expSig, $reqSig)) {
                if ($reqUid > 0) {
                    margo_revoke_all_user_sessions($pdo, $reqUid, 0, true);
                }
                if ($reqSid > 0) {
                    margo_revoke_sso_session($pdo, $reqSid, $reqUid > 0 ? $reqUid : null, true);
                }
            }
        }
    } catch (Throwable $e) {
        // Lanjutkan pencabutan sesi lokal
    }
}

// Validasi ketat post_logout_redirect_uri terhadap whitelist seluruh klien aktif
$safeRedirect = BASE_URL . '/login?msg=logged_out';
if ($postLogoutUri !== '') {
    $parsed = parse_url($postLogoutUri);
    if (is_array($parsed) && !empty($parsed['scheme']) && in_array(strtolower((string)$parsed['scheme']), ['http', 'https'], true) && !empty($parsed['host'])) {
        $targetHost = strtolower((string)$parsed['host']);
        $selfHost   = strtolower((string)parse_url(BASE_URL, PHP_URL_HOST));
        $allowed    = ($targetHost === $selfHost);

        if (!$allowed) {
            try {
                $clients = $pdo->query("SELECT allowed_domains, app_url, redirect_uris FROM sso_clients WHERE is_active = 1")->fetchAll(PDO::FETCH_ASSOC);
                foreach ($clients as $c) {
                    $domains = margo_sso_parse_list($c['allowed_domains']);
                    $appHost = margo_sso_extract_host($c['app_url']);
                    if ($appHost !== '') {
                        $domains[] = $appHost;
                    }
                    if (margo_sso_domain_allowed($targetHost, $domains)) {
                        $allowed = true;
                        break;
                    }
                }
            } catch (Throwable $e) {
                $allowed = false;
            }
        }

        if ($allowed) {
            $safeRedirect = $postLogoutUri;
        }
    }
}

// Jalankan Global Single Logout (SLO) untuk sesi browser Margo One saat ini
logoutUser($revokeAll);

header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
header('Location: ' . $safeRedirect);
exit;
