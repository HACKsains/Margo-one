<?php
/**
 * ==============================================================================
 * MARGO ONE — ILMUNA SSO · PORTAL FEED JSON API (/portal-data.php)
 * ==============================================================================
 * Menyediakan data real-time untuk 3-Column Inner Portal & Right Sidebar:
 *   - ?resource=agenda        : Agenda mendatang (sso_agendas + opsional semua/kalender)
 *   - ?resource=announcements : Pengumuman & berita ekosistem (sso_announcements)
 *   - ?resource=apps          : Aplikasi SSO terintegrasi (sso_clients) + non-SSO (sso_external_apps)
 *   - ?resource=activity      : Sesi login terakhir + riwayat audit pribadi berpaginasi
 *
 * 100% kompatibel PHP 7.4 (ea-php74) hingga PHP 8.3.
 * ==============================================================================
 */

if (!defined('ILMUNA_SKIP_GD_WAF_INTERSTITIAL')) {
    define('ILMUNA_SKIP_GD_WAF_INTERSTITIAL', true);
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once BASE_PATH . '/includes/agenda-engine.php';
require_once BASE_PATH . '/includes/learn-realtime-bridge.php';

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
$userRoles   = getUserRoles();
$isAdminRole = hasRole('admin');
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}
$resource    = strtolower(trim((string)($_GET['resource'] ?? 'agenda')));

/**
 * Resolve link relatif ke BASE_URL agar aman di localhost/SSO maupun produksi
 */
function margo_portal_resolve_url($url)
{
    $url = trim((string)$url);
    if ($url === '') {
        return '';
    }
    if (preg_match('#^https?://#i', $url)) {
        return $url;
    }
    return rtrim(BASE_URL, '/') . '/' . ltrim($url, '/');
}

/**
 * Parse User-Agent ringkas menjadi label perangkat yang mudah dibaca
 */
function margo_portal_device_label($ua)
{
    $ua = (string)$ua;
    if ($ua === '') {
        return 'Perangkat Tidak Dikenal';
    }
    $os = 'Desktop';
    if (stripos($ua, 'Android') !== false) {
        $os = 'Android';
    } elseif (stripos($ua, 'iPhone') !== false || stripos($ua, 'iPad') !== false) {
        $os = 'iOS';
    } elseif (stripos($ua, 'Windows') !== false) {
        $os = 'Windows';
    } elseif (stripos($ua, 'Macintosh') !== false || stripos($ua, 'Mac OS') !== false) {
        $os = 'macOS';
    } elseif (stripos($ua, 'Linux') !== false) {
        $os = 'Linux';
    }

    $br = 'Browser';
    if (stripos($ua, 'Edg/') !== false) {
        $br = 'Microsoft Edge';
    } elseif (stripos($ua, 'OPR/') !== false || stripos($ua, 'Opera') !== false) {
        $br = 'Opera';
    } elseif (stripos($ua, 'Chrome/') !== false) {
        $br = 'Chrome';
    } elseif (stripos($ua, 'Firefox/') !== false) {
        $br = 'Firefox';
    } elseif (stripos($ua, 'Safari/') !== false) {
        $br = 'Safari';
    } elseif (stripos($ua, 'curl') !== false) {
        $br = 'API Client';
    }
    return $br . ' · ' . $os;
}

/**
 * Label bahasa Indonesia ramah pengguna untuk event audit
 */
function margo_portal_event_label($eventType)
{
    $map = [
        'login_success'            => 'Login ke Margo One SSO',
        'login_google_success'     => 'Login via Google OAuth 2.0',
        'login_failed'             => 'Percobaan Login Gagal',
        'authorize_code_issued'    => 'Otorisasi Aplikasi Ekosistem',
        'token_issued'             => 'Token Akses SSO Diterbitkan',
        'token_refreshed'          => 'Pembaruan Sesi Token Otomatis',
        'password_changed'         => 'Perubahan Kata Sandi Terpusat',
        'google_linked'            => 'Penautan Akun Google SSO',
        'google_unlinked'          => 'Pemutusan Tautan Google SSO',
        'logout_global'            => 'Single Logout Global (SLO)',
        'session_revoked'          => 'Pencabutan Sesi Perangkat',
        'admin_client_created'     => 'Admin: Tambah Klien SSO',
        'admin_client_updated'     => 'Admin: Ubah Konfigurasi Klien',
        'admin_jwks_rotated'       => 'Admin: Rotasi Kunci JWT RS256',
        'admin_roles_updated'      => 'Admin: Pembaruan Multi-Role',
        'admin_password_reset'     => 'Admin: Reset Kata Sandi Akun',
    ];
    return isset($map[$eventType]) ? $map[$eventType] : ucwords(str_replace('_', ' ', (string)$eventType));
}

try {
    // ------------------------------------------------------------------
    // 1. AGENDA EKOSISTEM (DEDICATED AGENDA DB + KALDIK SYNC + ROLE SCOPING)
    // ------------------------------------------------------------------
    if ($resource === 'agenda') {
        $showAll     = isset($_GET['all']) && $_GET['all'] === '1';
        $monthFilter = trim((string)($_GET['month'] ?? '')); // format YYYY-MM opsional

        $out = margo_agenda_fetch_visible($pdo, $currentUser, [
            'upcoming' => !$showAll,
            'month'    => $monthFilter,
            'limit'    => $showAll ? 1500 : 12,
        ]);

        echo json_encode(['success' => true, 'data' => $out], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    // ------------------------------------------------------------------
    // 1B. DETAIL 1 AGENDA (LAZY LOAD UNTUK MODAL RIGHT SIDEBAR — ZERO RELOAD)
    // ------------------------------------------------------------------
    if ($resource === 'agenda_detail') {
        $agId = (int)($_GET['id'] ?? 0);
        $item = margo_agenda_fetch_by_id($pdo, $currentUser, $agId);
        if (!$item) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Agenda tidak ditemukan atau Anda tidak memiliki akses.']);
            exit;
        }
        echo json_encode(['success' => true, 'data' => $item], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    // ------------------------------------------------------------------
    // 2. BERITA & PENGUMUMAN EKOSISTEM
    // ------------------------------------------------------------------
    if ($resource === 'announcements') {
        $rows = $pdo->query("
            SELECT id, title, excerpt, link_url, badge_label, published_at
            FROM sso_announcements
            WHERE is_active = 1
            ORDER BY published_at DESC, id DESC
            LIMIT 15
        ")->fetchAll(PDO::FETCH_ASSOC);

        $out = [];
        foreach ($rows as $r) {
            $ts = strtotime((string)$r['published_at']);
            $out[] = [
                'id'           => (int)$r['id'],
                'title'        => (string)$r['title'],
                'excerpt'      => (string)($r['excerpt'] ?? ''),
                'link_url'     => margo_portal_resolve_url($r['link_url'] ?? ''),
                'badge_label'  => (string)($r['badge_label'] ?: 'INFO'),
                'published_at' => (string)$r['published_at'],
                'date_human'   => $ts ? date('d M Y', $ts) : '',
            ];
        }

        echo json_encode(['success' => true, 'data' => $out], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    // ------------------------------------------------------------------
    // 3. APLIKASI TERINTEGRASI & LAYANAN EKOSISTEM NON-SSO
    // ------------------------------------------------------------------
    if ($resource === 'apps') {
        $integrated = [];

        $freshAllowedApps = $currentUser['allowed_apps'] ?? null;
        try {
            $stUa = $pdo->prepare("SELECT allowed_apps FROM sso_users WHERE id = ? LIMIT 1");
            $stUa->execute([$userId]);
            $dbUa = $stUa->fetchColumn();
            if ($dbUa !== false) {
                $freshAllowedApps = $dbUa;
            }
        } catch (Throwable $eUa) {}

        $clients = $pdo->query("SELECT * FROM sso_clients WHERE is_active = 1 ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($clients as $c) {
            if (!margo_user_can_access_client($c, $userRoles, $freshAllowedApps)) {
                continue;
            }
            $rawAppUrl = trim((string)$c['app_url']);
            $hostLbl = parse_url($rawAppUrl, PHP_URL_HOST) ?: $rawAppUrl;
            $hostNowPortal = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
            $isLocalPortal = (strpos($hostNowPortal, 'localhost') === 0 || strpos($hostNowPortal, '127.0.0.1') === 0);
            if ((string)$c['client_slug'] === 'ilmuna-learn') {
                $launchUrl = $isLocalPortal
                    ? 'http://localhost/learn/login.php?sso_login=1'
                    : (rtrim($rawAppUrl, '/') . '/login.php?sso_login=1');
            } else {
                $launchUrl = (strpos($rawAppUrl, 'sso_login=') === false)
                    ? ($rawAppUrl . (strpos($rawAppUrl, '?') === false ? '?' : '&') . 'sso_login=1')
                    : $rawAppUrl;
            }
            $cIconClean = !empty($c['app_icon']) ? (string)$c['app_icon'] : 'solar:widget-5-bold';
            if ($cIconClean === 'solar:globe-bold') {
                $cIconClean = 'solar:global-bold';
            }
            $integrated[] = [
                'id'          => (int)$c['id'],
                'slug'        => (string)$c['client_slug'],
                'name'        => (string)$c['client_name'],
                'description' => 'Masuk otomatis ke ' . $hostLbl . ' dengan sesi Margo One SSO',
                'url'         => $launchUrl,
                'icon'        => $cIconClean,
                'logo'        => margo_resolve_app_logo_url($c['app_logo'] ?? '', $c['app_icon'] ?? '', (string)$c['client_slug']),
                'badge'       => 'SSO AKTIF',
                'is_admin'    => false,
                'external'    => true,
            ];
        }

        $extRows = $pdo->query("
            SELECT *
            FROM sso_external_apps
            WHERE is_active = 1
            ORDER BY sort_order ASC, id ASC
        ")->fetchAll(PDO::FETCH_ASSOC);
        $others = [];
        foreach ($extRows as $ex) {
            $rawExUrl = trim((string)$ex['app_url']);
            $exUrlNorm = strtolower(trim((string)parse_url($rawExUrl, PHP_URL_PATH), '/'));
            if ($exUrlNorm === 'agenda' || substr($exUrlNorm, -7) === '/agenda' ||
                strpos($exUrlNorm, 'api/sso/jwks') !== false) {
                continue;
            }
            $resolved = margo_portal_resolve_url($rawExUrl);
            $isExtUrl = (bool)preg_match('#^https?://#i', (string)$ex['app_url']);
            $exIconClean = !empty($ex['app_icon']) ? (string)$ex['app_icon'] : 'solar:widget-4-bold';
            if ($exIconClean === 'solar:globe-bold') {
                $exIconClean = 'solar:global-bold';
            }
            $others[] = [
                'id'          => (int)$ex['id'],
                'name'        => (string)$ex['app_name'],
                'description' => (string)($ex['description'] ?? ''),
                'url'         => $resolved,
                'icon'        => $exIconClean,
                'logo'        => margo_resolve_app_logo_url($ex['app_logo'] ?? '', $ex['app_icon'] ?? '', (string)$ex['app_name']),
                'badge'       => (string)($ex['badge_label'] ?: 'EKOSISTEM'),
                'external'    => $isExtUrl,
            ];
        }

        echo json_encode([
            'success' => true,
            'data'    => [
                'integrated' => $integrated,
                'others'     => $others,
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    // ------------------------------------------------------------------
    // 4. AKTIVITAS & LOGIN TERAKHIR PENGGUNA (PAGINATED)
    // ------------------------------------------------------------------
    if ($resource === 'activity') {
        $page    = max(1, (int)($_GET['page'] ?? 1));
        $perPage = max(3, min(15, (int)($_GET['per_page'] ?? 6)));
        $offset  = ($page - 1) * $perPage;
        $currSid = (int)($currentUser['sso_session_id'] ?? 0);

        $stSess = $pdo->prepare("
            SELECT id, active_role, ip_address, user_agent, login_method, expires_at, revoked_at, last_activity_at, created_at
            FROM sso_sessions
            WHERE user_id = ?
            ORDER BY created_at DESC
            LIMIT 4
        ");
        $stSess->execute([$userId]);
        $sessRows = $stSess->fetchAll(PDO::FETCH_ASSOC);
        $nowTs = time();
        $recentLogins = [];
        foreach ($sessRows as $s) {
            $isActiveNow = ($s['revoked_at'] === null && strtotime((string)$s['expires_at']) > $nowTs);
            $recentLogins[] = [
                'id'               => (int)$s['id'],
                'device'           => margo_portal_device_label($s['user_agent'] ?? ''),
                'ip_address'       => (string)$s['ip_address'],
                'login_method'     => $s['login_method'] === 'google_oauth' ? 'Google OAuth' : 'Kata Sandi',
                'active_role'      => (string)($s['active_role'] ?? ''),
                'last_activity_at' => (string)$s['last_activity_at'],
                'created_at'       => (string)$s['created_at'],
                'is_current'       => ((int)$s['id'] === $currSid),
                'is_active'        => $isActiveNow,
            ];
        }

        $stCnt = $pdo->prepare("SELECT COUNT(*) FROM sso_audit_logs WHERE user_id = ?");
        $stCnt->execute([$userId]);
        $totalLogs  = (int)$stCnt->fetchColumn();
        $totalPages = max(1, (int)ceil($totalLogs / $perPage));
        if ($page > $totalPages) {
            $page = $totalPages;
            $offset = ($page - 1) * $perPage;
        }

        $stLogs = $pdo->prepare("
            SELECT l.id, l.event_type, l.http_status, l.ip_address, l.created_at, c.client_name
            FROM sso_audit_logs l
            LEFT JOIN sso_clients c ON c.id = l.client_id
            WHERE l.user_id = ?
            ORDER BY l.id DESC
            LIMIT ? OFFSET ?
        ");
        $stLogs->bindValue(1, $userId, PDO::PARAM_INT);
        $stLogs->bindValue(2, $perPage, PDO::PARAM_INT);
        $stLogs->bindValue(3, $offset, PDO::PARAM_INT);
        $stLogs->execute();
        $logRows = $stLogs->fetchAll(PDO::FETCH_ASSOC);

        $logsOut = [];
        foreach ($logRows as $l) {
            $stCode = (int)$l['http_status'];
            $logsOut[] = [
                'id'          => (int)$l['id'],
                'event_type'  => (string)$l['event_type'],
                'label'       => margo_portal_event_label($l['event_type']),
                'http_status' => $stCode,
                'ok'          => ($stCode < 400),
                'client_name' => (string)($l['client_name'] ?? 'Margo One IdP'),
                'ip_address'  => (string)$l['ip_address'],
                'created_at'  => (string)$l['created_at'],
            ];
        }

        echo json_encode([
            'success' => true,
            'data'    => [
                'recent_logins' => $recentLogins,
                'logs'          => $logsOut,
                'pagination'    => [
                    'page'        => $page,
                    'per_page'    => $perPage,
                    'total'       => $totalLogs,
                    'total_pages' => $totalPages,
                ],
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    // ------------------------------------------------------------------
    // 5. WEBSOCKET TICKET (SINGLE-USE wst_* — browser tidak pernah menerima sk_sso_*)
    // ------------------------------------------------------------------
    if ($resource === 'ws_ticket') {
        try {
            $boot = margo_learn_issue_user_ws_bootstrap($pdo, $currentUser);
            if (!empty($boot['ok']) && !empty($boot['ws_ticket'])) {
                echo json_encode([
                    'success'       => true,
                    'data'          => $boot['ws_ticket'],
                    'recent_events' => $boot['recent_events'] ?? [],
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            } else {
                echo json_encode(['success' => false, 'message' => $boot['error'] ?? 'WebSocket ticket tidak tersedia saat ini.']);
            }
        } catch (Throwable $eWs) {
            echo json_encode(['success' => false, 'message' => 'WebSocket ticket gagal diterbitkan.']);
        }
        exit;
    }

    // ------------------------------------------------------------------
    // 6. SINKRONISASI LEARN AGENDA (dipanggil oleh WS event / force refresh, BUKAN timer/polling!)
    // ------------------------------------------------------------------
    if ($resource === 'sync_learn_agenda') {
        $force   = isset($_GET['force']) && $_GET['force'] === '1';
        $showAll = isset($_GET['all']) && $_GET['all'] === '1';
        try {
            $syncResult = margo_learn_sync_class_agendas($pdo, $currentUser, $force);
            $agendaOut  = margo_agenda_fetch_visible($pdo, $currentUser, [
                'upcoming' => !$showAll,
                'limit'    => $showAll ? 1500 : 12,
            ]);
            echo json_encode([
                'success' => true,
                'sync'    => $syncResult,
                'data'    => $agendaOut,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (Throwable $eSyncA) {
            echo json_encode(['success' => false, 'message' => 'Gagal sinkronisasi agenda dari Ilmuna Learn.']);
        }
        exit;
    }

    // ------------------------------------------------------------------
    // 7. PUSAT NOTIFIKASI TERPADU (AGENDA HARI INI/BESOK + INFO EKOSISTEM)
    // ------------------------------------------------------------------
    if ($resource === 'notifications') {
        $items    = [];
        $today    = date('Y-m-d');
        $tomorrow = date('Y-m-d', strtotime('+1 day'));
        $soonEnd  = date('Y-m-d', strtotime('+3 days'));

        // A. Agenda Hari Ini, Besok & Mendatang (terfilter sesuai role & kelas)
        try {
            $agList = margo_agenda_fetch_visible($pdo, $currentUser, [
                'upcoming'        => true,
                'limit'           => 30,
                'skip_learn_sync' => true,
            ]);
            foreach ($agList as $ag) {
                $sd = (string)($ag['start_date'] ?? '');
                $ed = (string)($ag['end_date'] ?: $sd);
                $agId = (int)($ag['id'] ?? 0);
                if ($agId <= 0 || $sd === '') {
                    continue;
                }

                $isToday    = ($sd <= $today && $ed >= $today);
                $isTomorrow = (!$isToday && $sd === $tomorrow);
                $isSoon     = (!$isToday && !$isTomorrow && $sd > $tomorrow && $sd <= $soonEnd);
                if (!$isToday && !$isTomorrow && !$isSoon) {
                    continue;
                }

                $cat   = $isToday ? 'agenda_today' : ($isTomorrow ? 'agenda_tomorrow' : 'agenda_soon');
                $badge = $isToday ? 'AGENDA HARI INI' : ($isTomorrow ? 'AGENDA BESOK' : 'AGENDA MENDATANG');
                $color = $isToday ? '#EF4444' : ($isTomorrow ? '#F59E0B' : '#0D7A87');
                $icon  = $isToday ? 'solar:calendar-date-bold' : 'solar:calendar-mark-bold';
                $timeStr = !empty($ag['start_time']) ? (' · Pukul ' . substr((string)$ag['start_time'], 0, 5) . ' WIB') : '';
                $dateLbl = ($isToday ? 'Hari Ini' : ($isTomorrow ? 'Besok' : date('d M Y', strtotime($sd)))) . $timeStr;
                $loc     = trim((string)($ag['location'] ?? ''));
                $desc    = trim((string)($ag['description'] ?? ''));
                $visLbl  = trim((string)($ag['visibility_label'] ?? ''));
                $subLine = $dateLbl . ($loc !== '' ? ' · ' . $loc : ($visLbl !== '' ? ' · ' . $visLbl : ''));

                $items[] = [
                    'uid'        => $cat . '_' . $agId . '_' . ($isToday ? $today : $sd),
                    'type'       => $cat,
                    'group'      => 'agenda',
                    'badge'      => $badge,
                    'title'      => (string)$ag['title'],
                    'sub'        => $subLine,
                    'full_body'  => $desc !== '' ? $desc : ('Jadwal agenda: ' . $ag['title'] . ' (' . $dateLbl . ').'),
                    'kelas'      => (string)($ag['target_kelas_name'] ?? $visLbl),
                    'location'   => $loc,
                    'date_label' => $dateLbl,
                    'color'      => $color,
                    'icon'       => $icon,
                    'url'        => !empty($ag['link_url']) ? margo_portal_resolve_url($ag['link_url']) : (BASE_URL . '/agenda'),
                    'ts'         => ($isToday ? time() : (strtotime($sd . ' 07:00:00') ?: time())) * 1000,
                    'priority'   => $isToday ? 1 : ($isTomorrow ? 2 : 3),
                ];
            }
        } catch (Throwable $eAgN) {}

        // B. Info Ekosistem (sso_announcements)
        try {
            $nwRows = $pdo->query("
                SELECT id, title, excerpt, link_url, badge_label, published_at
                FROM sso_announcements
                WHERE is_active = 1
                ORDER BY published_at DESC, id DESC
                LIMIT 10
            ")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($nwRows as $nw) {
                $nwId = (int)$nw['id'];
                $tsN  = strtotime((string)$nw['published_at']) ?: time();
                $dateH = date('d M Y, H:i', $tsN) . ' WIB';
                $excerpt = trim((string)($nw['excerpt'] ?? ''));
                $items[] = [
                    'uid'        => 'eco_info_' . $nwId,
                    'type'       => 'ecosystem_info',
                    'group'      => 'info',
                    'badge'      => 'INFO EKOSISTEM · ' . strtoupper((string)($nw['badge_label'] ?: 'INFO')),
                    'title'      => (string)$nw['title'],
                    'sub'        => $excerpt !== '' ? $excerpt : ('Dipublikasikan ' . $dateH),
                    'full_body'  => $excerpt !== '' ? $excerpt : 'Informasi resmi dari ekosistem digital Ilmuna.',
                    'kelas'      => 'Ekosistem Ilmuna',
                    'location'   => '',
                    'date_label' => $dateH,
                    'color'      => '#1D3B5E',
                    'icon'       => 'solar:megaphone-bold',
                    'url'        => !empty($nw['link_url']) ? margo_portal_resolve_url($nw['link_url']) : (BASE_URL . '/gateway'),
                    'ts'         => $tsN * 1000,
                    'priority'   => 2,
                ];
            }
        } catch (Throwable $eNwN) {}

        echo json_encode(['success' => true, 'data' => $items], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Resource tidak dikenali.']);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Gagal memuat data portal.']);
}
