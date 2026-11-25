<?php
/**
 * ==============================================================================
 * MARGO ONE — ILMUNA SSO · WEB PUSH SUBSCRIPTION & DISPATCH API (/api/push.php)
 * ==============================================================================
 * Endpoints:
 *   - GET  ?action=vapid_key   : Ambil VAPID Public Key untuk PushManager.subscribe()
 *   - POST ?action=subscribe   : Simpan langganan Push perangkat user/admin
 *   - POST ?action=unsubscribe : Nonaktifkan langganan Push perangkat
 *   - POST ?action=test        : Kirim notifikasi uji latar belakang ke perangkat user
 *   - GET  ?action=latest      : Fallback payload terbaru untuk Service Worker
 *   - GET  ?action=dispatch    : Trigger pengiriman agenda hari ini/besok & info ekosistem (Admin)
 * ==============================================================================
 */

if (!defined('ILMUNA_SKIP_GD_WAF_INTERSTITIAL')) {
    define('ILMUNA_SKIP_GD_WAF_INTERSTITIAL', true);
}

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/auth.php';
require_once BASE_PATH . '/includes/webpush-engine.php';

while (ob_get_level()) {
    ob_end_clean();
}
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store, max-age=0');

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Sesi login diperlukan.']);
    exit;
}

$pdo         = getDBConnection();
$currentUser = getCurrentUser();
$userId      = (int)$currentUser['id'];
$isAdmin     = hasRole('admin');
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

$action = strtolower(trim((string)($_GET['action'] ?? $_POST['action'] ?? 'vapid_key')));

try {
    margo_push_ensure_schema($pdo);

    if ($action === 'vapid_key') {
        $vapid = margo_push_get_vapid_keys($pdo);
        if (!$vapid) {
            echo json_encode(['success' => false, 'message' => 'OpenSSL EC prime256v1 tidak tersedia di server.']);
            exit;
        }
        $stCnt = $pdo->prepare("SELECT COUNT(*) FROM sso_push_subscriptions WHERE user_id = ? AND is_active = 1");
        $stCnt->execute([$userId]);
        echo json_encode([
            'success'      => true,
            'public_key'   => $vapid['public_key'],
            'active_count' => (int)$stCnt->fetchColumn(),
        ]);
        exit;
    }

    if ($action === 'subscribe') {
        $raw  = file_get_contents('php://input');
        $body = json_decode((string)$raw, true);
        if (!is_array($body)) {
            $body = $_POST;
        }
        $endpoint = trim((string)($body['endpoint'] ?? ''));
        $p256dh   = trim((string)($body['keys']['p256dh'] ?? ($body['p256dh'] ?? '')));
        $authSec  = trim((string)($body['keys']['auth'] ?? ($body['auth'] ?? '')));

        if ($endpoint === '' || !preg_match('#^https://#i', $endpoint) || $p256dh === '' || $authSec === '') {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Data subscription Web Push tidak lengkap.']);
            exit;
        }

        $hash = hash('sha256', $endpoint);
        $ua   = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 250);
        $stmt = $pdo->prepare("
            INSERT INTO sso_push_subscriptions (user_id, endpoint, endpoint_hash, p256dh, auth_secret, user_agent, is_active)
            VALUES (?, ?, ?, ?, ?, ?, 1)
            ON DUPLICATE KEY UPDATE
                user_id     = VALUES(user_id),
                p256dh      = VALUES(p256dh),
                auth_secret = VALUES(auth_secret),
                user_agent  = VALUES(user_agent),
                is_active   = 1,
                updated_at  = NOW()
        ");
        $stmt->execute([$userId, $endpoint, $hash, $p256dh, $authSec, $ua]);

        echo json_encode(['success' => true, 'message' => 'Notifikasi latar belakang aktif di perangkat ini.']);
        exit;
    }

    if ($action === 'unsubscribe') {
        $raw  = file_get_contents('php://input');
        $body = json_decode((string)$raw, true);
        $endpoint = trim((string)($body['endpoint'] ?? ''));
        if ($endpoint !== '') {
            $pdo->prepare("UPDATE sso_push_subscriptions SET is_active = 0 WHERE user_id = ? AND endpoint_hash = ?")
                ->execute([$userId, hash('sha256', $endpoint)]);
        } else {
            $pdo->prepare("UPDATE sso_push_subscriptions SET is_active = 0 WHERE user_id = ?")
                ->execute([$userId]);
        }
        echo json_encode(['success' => true, 'message' => 'Notifikasi latar belakang dinonaktifkan.']);
        exit;
    }

    if ($action === 'test') {
        $res = margo_push_send_to_user($pdo, $userId, [
            'title'    => '🔔 Notifikasi Margo One Aktif!',
            'body'     => 'Anda akan tetap menerima notifikasi Agenda, Info Ekosistem, dan Kelas meski web tidak dibuka.',
            'category' => 'ecosystem_info',
            'tag'      => 'margo-test-' . time(),
            'url'      => BASE_URL . '/gateway',
        ]);
        echo json_encode(['success' => ($res['sent'] > 0), 'data' => $res]);
        exit;
    }

    if ($action === 'latest') {
        $today = date('Y-m-d');
        $agList = margo_agenda_fetch_visible($pdo, $currentUser, ['upcoming' => true, 'limit' => 5, 'skip_learn_sync' => true]);
        foreach ($agList as $ag) {
            if ((string)$ag['start_date'] === $today) {
                echo json_encode([
                    'success' => true,
                    'data'    => [
                        'title'    => '📅 Agenda Hari Ini: ' . $ag['title'],
                        'body'     => (string)($ag['description'] ?: 'Buka Margo One untuk melihat detail agenda.'),
                        'category' => 'agenda_today',
                        'url'      => BASE_URL . '/agenda',
                    ],
                ]);
                exit;
            }
        }
        $nw = $pdo->query("SELECT id, title, excerpt, link_url FROM sso_announcements WHERE is_active = 1 ORDER BY published_at DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        if ($nw) {
            echo json_encode([
                'success' => true,
                'data'    => [
                    'title'    => '📢 ' . $nw['title'],
                    'body'     => (string)($nw['excerpt'] ?: 'Info terbaru ekosistem Ilmuna.'),
                    'category' => 'ecosystem_info',
                    'url'      => !empty($nw['link_url']) ? $nw['link_url'] : (BASE_URL . '/gateway'),
                ],
            ]);
            exit;
        }
        echo json_encode(['success' => true, 'data' => ['title' => 'Margo One — Ilmuna', 'body' => 'Ada pembaruan terbaru di ekosistem.', 'url' => BASE_URL . '/gateway']]);
        exit;
    }

    if ($action === 'dispatch') {
        if (!$isAdmin) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Hanya Administrator yang dapat menjalankan dispatch.']);
            exit;
        }
        $res = margo_push_dispatch_daily_agendas($pdo);
        echo json_encode(['success' => true, 'data' => $res]);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Aksi push tidak dikenali.']);
} catch (Throwable $e) {
    error_log('[MargoPush] Error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Terjadi kesalahan pada layanan Web Push.' . ($isAdmin ? (' (' . $e->getMessage() . ')') : ''),
    ]);
}
