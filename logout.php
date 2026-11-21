<?php
/**
 * ==============================================================================
 * MARGO ONE — ILMUNA SSO · GLOBAL SINGLE LOGOUT (SLO) ENDPOINT (/logout)
 * ==============================================================================
 * 1. Tandai sso_sessions.revoked_at = NOW()
 * 2. Cabut seluruh sso_refresh_tokens sesi terkait
 * 3. Kirim Back-Channel Signed SLO Webhook ke semua sso_clients.logout_webhook_url
 * 4. Hapus cookie & session PHP lokal
 * 5. Redirect aman ke post_logout_redirect_uri (wajib cocok whitelist domain/URI)
 *    atau kembali ke /login?msg=logged_out
 * ==============================================================================
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';

$pdo = getDBConnection();
margo_sso_ensure_schema($pdo);

$revokeAll = isset($_GET['all']) && $_GET['all'] === '1';
$postLogoutUri = trim((string)($_GET['post_logout_redirect_uri'] ?? $_POST['post_logout_redirect_uri'] ?? ''));

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

// Jalankan Global Single Logout (SLO)
logoutUser($revokeAll);

header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
header('Location: ' . $safeRedirect);
exit;
