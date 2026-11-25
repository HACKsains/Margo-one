<?php
/**
 * ==============================================================================
 * MARGO ONE — ILMUNA SSO · REAL-TIME LEARN REST + WEBSOCKET BRIDGE ENGINE
 * ==============================================================================
 * Tugas modul ini:
 *   1. Mengelola kredensial Zero-Trust API (Public Key `pk_sso_*` + Private Key `sk_sso_*`
 *      atau RSA-2048 PEM) antara Margo One SSO dan Ilmuna Learn (`api/sso-broadcast.php`).
 *   2. Menyinkronkan data sesuai kelas pengguna ke Database Agenda (`agendas`) secara READ-ONLY (`is_locked = 1`):
 *        - Tugas   -> 2 agenda terpisah: (a) Tanggal Buat / Mulai Tugas, (b) Deadline Tugas
 *        - Proyek  -> 2 agenda terpisah: (a) Tanggal Buat / Mulai Proyek, (b) Deadline Proyek
 *        - Asesmen -> Jadwal Asesmen dari Bank & Master Asesmen (Sumatif multi-kelas, Formatif, Diagnostik, Pengayaan)
 *        - Informasi Kelompok (Tugas & Proyek) disematkan di deskripsi/notifikasi kelas.
 *   3. Menerbitkan Single-Use WebSocket Ticket (`wst_*`) server-side agar browser SSO menerima
 *      notifikasi real-time bergaya WhatsApp langsung sesuai kelas tanpa refresh / polling berkala!
 *
 * 100% kompatibel PHP 7.4 (ea-php74) hingga PHP 8.3.
 * ==============================================================================
 */

if (!defined('BASE_PATH')) {
    require_once dirname(__DIR__) . '/config.php';
}
require_once BASE_PATH . '/sdk/IlmunaLearnSsoNotifierClient.php';
require_once BASE_PATH . '/includes/agenda-engine.php';

/**
 * Deteksi URL endpoint REST API & URL publik WebSocket Ilmuna Learn (lokal vs produksi)
 */
function margo_learn_default_urls()
{
    $isLocal = margo_agenda_is_local_env();
    $host    = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
    $scheme  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';

    if ($isLocal) {
        $apiHost = (preg_match('/^(localhost|127\.0\.0\.1)(:\d+)?$/i', $host)) ? $host : '127.0.0.1';
        return [
            'endpoint_url' => $scheme . '://' . $apiHost . '/learn/api/sso-broadcast.php',
            'ws_url'       => 'ws://127.0.0.1:8090',
        ];
    }

    return [
        'endpoint_url' => 'https://learn.ilmuna.my.id/api/sso-broadcast.php',
        'ws_url'       => 'wss://learn.ilmuna.my.id/ws/sso',
    ];
}

/**
 * Cari direktori instalasi Ilmuna Learn jika berada pada server / host yang sama (auto-bootstrap lokal/cPanel)
 */
function margo_learn_sibling_base_dir()
{
    $candidates = [
        dirname(BASE_PATH) . '/learn',
        '/home/uofruaju/learn.ilmuna.my.id',
        'C:/laragon/www/learn',
    ];
    foreach ($candidates as $dir) {
        if (is_dir($dir) && file_exists($dir . '/includes/sso-api-security.php')) {
            return $dir;
        }
    }
    return null;
}

/**
 * Auto-provision sepasang kunci pk_sso_* / sk_sso_* jika belum dikonfigurasi dan Learn DB satu server
 */
function margo_learn_auto_provision_credentials(PDO $ssoPdo)
{
    static $tried = false;
    if ($tried) {
        return null;
    }
    $tried = true;

    $learnDir = margo_learn_sibling_base_dir();
    if ($learnDir === null) {
        return null;
    }

    try {
        $lpdo = margo_get_learn_db($ssoPdo);
        if (!$lpdo) {
            return null;
        }
        require_once $learnDir . '/includes/sso-api-security.php';
        if (!function_exists('ilmuna_sso_api_ensure_schema') || !function_exists('ilmuna_sso_api_create_client')) {
            return null;
        }
        ilmuna_sso_api_ensure_schema($lpdo);

        // Cek apakah ada klien eksisting bernama "Margo One SSO Portal" yang bisa dibaca rahasianya (jika belum disimpan di sso_settings)
        $created = ilmuna_sso_api_create_client(
            $lpdo,
            'Margo One SSO Portal (Auto)',
            ['margo-one.ilmuna.my.id', 'localhost', '127.0.0.1'],
            'HMAC-SHA256',
            'all:read,ws:connect,events:read',
            180
        );

        $defaults = margo_learn_default_urls();
        if (!empty($created['public_key']) && !empty($created['private_key']) && function_exists('margo_sso_set_setting')) {
            margo_sso_set_setting($ssoPdo, 'learn_api_endpoint', $defaults['endpoint_url'], false);
            margo_sso_set_setting($ssoPdo, 'learn_api_public_key', $created['public_key'], false);
            margo_sso_set_setting($ssoPdo, 'learn_api_private_key', $created['private_key'], true);
            margo_sso_set_setting($ssoPdo, 'learn_api_sig_mode', 'HMAC-SHA256', false);
            return [
                'endpoint_url'    => $defaults['endpoint_url'],
                'ws_url'          => $defaults['ws_url'],
                'public_key'      => $created['public_key'],
                'private_key'     => $created['private_key'],
                'sig_mode'        => 'HMAC-SHA256',
                'rsa_private_pem' => '',
            ];
        }
    } catch (Throwable $e) {
        error_log('[MargoOne] Learn API auto-provision skip: ' . $e->getMessage());
    }

    return null;
}

/**
 * Ambil konfigurasi Learn REST & WebSocket API aktif
 */
function margo_learn_api_get_config(PDO $ssoPdo, $allowAutoProvision = true)
{
    static $cache = null;
    if ($cache !== null && !$allowAutoProvision) {
        return $cache;
    }

    $defaults = margo_learn_default_urls();
    $endpoint = defined('LEARN_API_ENDPOINT') && trim((string)LEARN_API_ENDPOINT) !== ''
        ? trim((string)LEARN_API_ENDPOINT) : $defaults['endpoint_url'];
    $wsUrl    = defined('LEARN_WS_URL') && trim((string)LEARN_WS_URL) !== ''
        ? trim((string)LEARN_WS_URL) : $defaults['ws_url'];
    $pubKey   = defined('LEARN_API_PUBLIC_KEY') ? trim((string)LEARN_API_PUBLIC_KEY) : '';
    $privKey  = defined('LEARN_API_PRIVATE_KEY') ? trim((string)LEARN_API_PRIVATE_KEY) : '';
    $sigMode  = defined('LEARN_API_SIG_MODE') ? strtoupper(trim((string)LEARN_API_SIG_MODE)) : 'HMAC-SHA256';
    $rsaPem   = defined('LEARN_API_RSA_PRIVATE_PEM') ? trim((string)LEARN_API_RSA_PRIVATE_PEM) : '';

    if (function_exists('margo_sso_get_setting')) {
        $dbEp   = trim((string)margo_sso_get_setting($ssoPdo, 'learn_api_endpoint', ''));
        $dbWs   = trim((string)margo_sso_get_setting($ssoPdo, 'learn_ws_public_url', ''));
        $dbPub  = trim((string)margo_sso_get_setting($ssoPdo, 'learn_api_public_key', ''));
        $dbPriv = trim((string)margo_sso_get_setting($ssoPdo, 'learn_api_private_key', ''));
        $dbMode = strtoupper(trim((string)margo_sso_get_setting($ssoPdo, 'learn_api_sig_mode', '')));
        $dbRsa  = trim((string)margo_sso_get_setting($ssoPdo, 'learn_api_rsa_private_pem', ''));

        if ($dbEp !== '' && (!defined('LEARN_API_ENDPOINT') || trim((string)LEARN_API_ENDPOINT) === '')) {
            $endpoint = $dbEp;
        }
        if ($dbWs !== '' && (!defined('LEARN_WS_URL') || trim((string)LEARN_WS_URL) === '')) {
            $wsUrl = $dbWs;
        }
        if ($pubKey === '' && $dbPub !== '') {
            $pubKey = $dbPub;
        }
        if ($privKey === '' && $dbPriv !== '') {
            $privKey = $dbPriv;
        }
        if ($dbMode !== '' && in_array($dbMode, ['HMAC-SHA256', 'RSA-SHA256', 'DUAL'], true)) {
            $sigMode = $dbMode;
        }
        if ($rsaPem === '' && $dbRsa !== '') {
            $rsaPem = $dbRsa;
        }
    }

    // Jika lokal dan kunci belum terisi sama sekali, coba auto-provision sepasang kunci sekali saja
    if (($pubKey === '' || $privKey === '') && $allowAutoProvision) {
        $prov = margo_learn_auto_provision_credentials($ssoPdo);
        if (is_array($prov)) {
            $pubKey  = $prov['public_key'];
            $privKey = $prov['private_key'];
            $sigMode = $prov['sig_mode'];
        }
    }

    $cfg = [
        'endpoint_url'    => $endpoint,
        'ws_url'          => $wsUrl,
        'public_key'      => $pubKey,
        'private_key'     => $privKey,
        'sig_mode'        => $sigMode,
        'rsa_private_pem' => $rsaPem,
        'configured'      => ($pubKey !== '' && $privKey !== ''),
        'ssl_verify'      => !margo_agenda_is_local_env(),
    ];
    $cache = $cfg;
    return $cfg;
}

/**
 * Buat instance klien API Learn siap pakai
 */
function margo_learn_api_client(PDO $ssoPdo)
{
    $cfg = margo_learn_api_get_config($ssoPdo, true);
    if (empty($cfg['configured'])) {
        throw new RuntimeException('Kunci Public/Private API Ilmuna Learn belum dikonfigurasi.');
    }

    return new IlmunaLearnSsoNotifierClient([
        'endpoint_url'    => $cfg['endpoint_url'],
        'public_key'      => $cfg['public_key'],
        'private_key'     => $cfg['private_key'],
        'sig_mode'        => $cfg['sig_mode'],
        'rsa_private_pem' => $cfg['rsa_private_pem'],
        'site_host'       => (string)($_SERVER['HTTP_HOST'] ?? 'margo-one.ilmuna.my.id'),
        'verify_response' => true,
        'ssl_verify'      => $cfg['ssl_verify'],
        'timeout'         => margo_agenda_is_local_env() ? 6 : 10,
    ]);
}

/**
 * Bangun payload identitas terstruktur (kelas_id, role, nis, nisn, nip, email) untuk filter kelas Learn
 */
function margo_learn_user_identity_payload(PDO $ssoPdo, array $userSession)
{
    $ctx = margo_agenda_build_user_context($ssoPdo, $userSession);

    $uid = (int)($userSession['id'] ?? 0);
    $uRow = [];
    if ($uid > 0) {
        try {
            $stU = $ssoPdo->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
            $stU->execute([$uid]);
            $uRow = $stU->fetch(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $eU) {}
    }

    $kelasIds = [];
    $roleTag  = 'admin';

    if (!empty($ctx['is_admin'])) {
        $roleTag = 'admin';
        // Admin tidak memfilter kelas kecuali ingin melihat semua
    } elseif (!empty($ctx['is_guru'])) {
        $roleTag = 'guru';
        foreach (($ctx['guru_classes'] ?? []) as $kc) {
            if (!empty($kc['id'])) {
                $kelasIds[] = (int)$kc['id'];
            }
        }
        $kelasIds = array_values(array_unique($kelasIds));
    } else {
        $roleTag = 'siswa';
        if (!empty($ctx['siswa_kelas_id'])) {
            $kelasIds[] = (int)$ctx['siswa_kelas_id'];
        }
    }

    $payload = [
        'role'     => $roleTag,
        'email'    => trim((string)(($uRow['email_google'] ?? '') !== '' ? $uRow['email_google'] : ($uRow['email'] ?? ($userSession['email'] ?? '')))),
        'nis'      => trim((string)($uRow['nis'] ?? ($userSession['nis'] ?? ''))),
        'nisn'     => trim((string)($uRow['nisn'] ?? ($userSession['nisn'] ?? ''))),
        'nip'      => trim((string)($uRow['nip'] ?? ($userSession['nip'] ?? ''))),
        'kelas_id' => !empty($kelasIds) ? implode(',', $kelasIds) : '',
    ];

    return [
        'identity'  => $payload,
        'kelas_ids' => $kelasIds,
        'context'   => $ctx,
    ];
}

/**
 * Ambil feed kelas pengguna via Signed REST API (dan fallback aman baca DB Learn jika HTTP loopback lokal terhambat)
 */
function margo_learn_fetch_class_feed(PDO $ssoPdo, array $userSession, $includeWsTicket = false, $eventLimit = 18)
{
    $idInfo   = margo_learn_user_identity_payload($ssoPdo, $userSession);
    $identity = $idInfo['identity'];

    // Jika non-admin dan sama sekali tidak punya kelas_id serta nis/nisn/nip/email kosong, jangan tarik data kelas acak
    if ($identity['role'] !== 'admin' && $identity['kelas_id'] === '' && $identity['nis'] === '' && $identity['nisn'] === '' && $identity['nip'] === '') {
        return [
            'ok'        => true,
            'via'       => 'empty_class_binding',
            'kelas_ids' => [],
            'feed'      => [
                'tugas_broadcast'    => [],
                'proyek_broadcast'   => [],
                'kelompok_broadcast' => [],
                'jadwal_asesmen'     => [],
                'recent_events'      => [],
            ],
            'ws_ticket' => null,
        ];
    }

    $params = $identity;
    $params['event_limit'] = max(5, min(40, (int)$eventLimit));
    if ($includeWsTicket) {
        $params['include_ws_ticket'] = '1';
    }

    // 1. Jalur Utama: Zero-Trust Signed REST API (`pk_sso_*` + `sk_sso_*`)
    try {
        $client = margo_learn_api_client($ssoPdo);
        $data   = $client->request('student_feed', $params, 'GET');

        // Normalisasi URL WebSocket bila berjalan di localhost/127.0.0.1 agar browser lokal selalu pakai ws://127.0.0.1:8090
        $wsTicket = $data['ws_ticket'] ?? null;
        if (is_array($wsTicket)) {
            $cfg = margo_learn_api_get_config($ssoPdo, false);
            if (!empty($wsTicket['ticket']) && !empty($cfg['ws_url']) && margo_agenda_is_local_env()) {
                $wsTicket['ws_url']         = $cfg['ws_url'];
                $wsTicket['ws_connect_url'] = rtrim($cfg['ws_url'], '/') . '/?ticket=' . rawurlencode((string)$wsTicket['ticket']);
            }
        }

        return [
            'ok'        => true,
            'via'       => 'signed_rest_api',
            'kelas_ids' => $idInfo['kelas_ids'],
            'feed'      => $data,
            'ws_ticket' => $wsTicket,
        ];
    } catch (Throwable $eApi) {
        // 2. Fallback Aman Local/Same-Server: pakai helper data & issuer Learn langsung bila file lokal tersedia
        $learnDir = margo_learn_sibling_base_dir();
        if ($learnDir !== null) {
            try {
                $lpdoRaw = margo_get_learn_db($ssoPdo);
                $lpdo = is_array($lpdoRaw) ? ($lpdoRaw['pdo'] ?? null) : $lpdoRaw;
                if ($lpdo instanceof PDO && file_exists($learnDir . '/includes/sso-api-data.php') && file_exists($learnDir . '/includes/sso-api-security.php')) {
                    require_once $learnDir . '/includes/sso-api-security.php';
                    require_once $learnDir . '/includes/sso-api-data.php';

                    $learnCtx = ilmuna_sso_resolve_identity_context($lpdo, $identity);
                    $data     = ilmuna_sso_build_unified_feed($lpdo, $identity, $learnCtx);

                    $wsTicket = null;
                    if ($includeWsTicket && function_exists('ilmuna_sso_api_issue_ws_ticket')) {
                        $cfg = margo_learn_api_get_config($ssoPdo, false);
                        $clientRow = ['id' => 0, 'public_key' => $cfg['public_key'] ?: 'pk_local_bridge'];
                        if (!empty($cfg['public_key'])) {
                            $stCl = $lpdo->prepare("SELECT id, public_key FROM sso_api_clients WHERE public_key = ? LIMIT 1");
                            $stCl->execute([$cfg['public_key']]);
                            $foundCl = $stCl->fetch(PDO::FETCH_ASSOC);
                            if ($foundCl) {
                                $clientRow = $foundCl;
                            }
                        }
                        if (!empty($clientRow['id'])) {
                            $issued   = ilmuna_sso_api_issue_ws_ticket($lpdo, $clientRow, $learnCtx, 300);
                            $wsBase   = $cfg['ws_url'];
                            $wsTicket = array_merge($issued, [
                                'ws_url'         => $wsBase,
                                'ws_connect_url' => rtrim($wsBase, '/') . '/?ticket=' . rawurlencode((string)$issued['ticket']),
                            ]);
                        }
                    }

                    return [
                        'ok'        => true,
                        'via'       => 'local_bridge_fallback',
                        'api_error' => $eApi->getMessage(),
                        'kelas_ids' => !empty($learnCtx['kelas_ids']) ? $learnCtx['kelas_ids'] : $idInfo['kelas_ids'],
                        'feed'      => $data,
                        'ws_ticket' => $wsTicket,
                    ];
                }
            } catch (Throwable $eLocal) {
                error_log('[MargoOne] Learn feed local fallback failed: ' . $eLocal->getMessage());
            }
        }

        return [
            'ok'        => false,
            'via'       => 'error',
            'error'     => $eApi->getMessage(),
            'kelas_ids' => $idInfo['kelas_ids'],
            'feed'      => [],
            'ws_ticket' => null,
        ];
    }
}

/**
 * Helper: pecah datetime SQL / ISO menjadi ['date' => 'YYYY-MM-DD', 'time' => 'HH:MM'|null]
 */
function margo_learn_split_date_time($rawDateTime, $fallbackDateTime = null)
{
    $candidates = [$rawDateTime, $fallbackDateTime];
    foreach ($candidates as $cand) {
        $s = trim((string)$cand);
        if ($s === '' || $s === '0000-00-00' || $s === '0000-00-00 00:00:00') {
            continue;
        }
        $ts = strtotime($s);
        if ($ts !== false) {
            $hasClock = (bool)preg_match('/\d{2}:\d{2}/', $s) && date('H:i', $ts) !== '00:00';
            return [
                'date' => date('Y-m-d', $ts),
                'time' => $hasClock ? date('H:i', $ts) : null,
            ];
        }
    }
    return ['date' => date('Y-m-d'), 'time' => null];
}

/**
 * Hitung source_ref_id unik untuk Jadwal Asesmen (karena Asesmen Sumatif bisa multi-kelas)
 */
function margo_learn_asesmen_ref_id($kategori, $asesmenId, $kelasId)
{
    $catMap = [
        'sumatif'    => 1,
        'formatif'   => 2,
        'diagnostik' => 3,
        'pengayaan'  => 4,
    ];
    $c = isset($catMap[strtolower((string)$kategori)]) ? $catMap[strtolower((string)$kategori)] : 9;
    return ($c * 1000000000) + ((int)$asesmenId * 100000) + (int)$kelasId;
}

/**
 * Helper format teks kelompok ringkas untuk disisipkan pada deskripsi Agenda
 */
function margo_learn_format_kelompok_note(array $item)
{
    if (!empty($item['kelompok_saya']) && is_array($item['kelompok_saya'])) {
        $ks   = $item['kelompok_saya'];
        $name = trim((string)($ks['nama_kelompok'] ?? ('Kelompok ' . ($ks['nomor_kelompok'] ?? ''))));
        $role = trim((string)($ks['peran_saya'] ?? 'Anggota'));
        $members = [];
        foreach (($ks['anggota'] ?? []) as $ag) {
            $n = trim((string)($ag['nama'] ?? ''));
            if ($n !== '') {
                $members[] = $n . (!empty($ag['peran']) && $ag['peran'] === 'Ketua' ? ' (Ketua)' : '');
            }
        }
        $line = 'Kelompok Saya: ' . $name . ' [' . $role . ']';
        if (!empty($members)) {
            $line .= ' · Anggota: ' . implode(', ', $members);
        }
        return $line;
    }

    $cnt = (int)($item['jumlah_kelompok'] ?? 0);
    if ($cnt > 0) {
        return 'Pembagian Kelompok: ' . $cnt . ' kelompok terbentuk di kelas ini.';
    }
    return '';
}

/**
 * Sinkronkan Tugas (2 tanggal: Buat & Deadline), Proyek (2 tanggal: Mulai/Buat & Deadline),
 * dan Jadwal Asesmen dari Ilmuna Learn ke tabel `agendas` dengan `is_locked = 1` (Read-Only!).
 *
 * @return array ['ok' => bool, 'synced' => int, 'removed' => int, 'via' => string]
 */
function margo_learn_sync_class_agendas(PDO $ssoPdo, array $userSession, $force = false, array $prefetchedFeed = null)
{
    static $doneInRequest = false;
    if ($doneInRequest && !$force && $prefetchedFeed === null) {
        return ['ok' => true, 'skipped' => true, 'reason' => 'already_synced_request'];
    }

    $idInfo    = margo_learn_user_identity_payload($ssoPdo, $userSession);
    $isAdmin   = !empty($idInfo['context']['is_admin']);
    $kelasIds  = $idInfo['kelas_ids'];

    // Rate-limit ringan sinkronisasi otomatis per scope kelas (12 detik) agar tab reload cepat tetap gesit,
    // kecuali dipaksa ($force = true) misal saat menerima notifikasi WebSocket real-time atau klik sinkron!
    $scopeSig  = $isAdmin ? 'admin_all' : ('k_' . implode('_', $kelasIds));
    $flagFile  = sys_get_temp_dir() . '/margo_learn_ag_sync_' . md5($scopeSig) . '.stamp';
    if (!$force && $prefetchedFeed === null && file_exists($flagFile) && (time() - (int)filemtime($flagFile)) < 12) {
        $doneInRequest = true;
        return ['ok' => true, 'skipped' => true, 'reason' => 'cooldown'];
    }

    $feedWrap = $prefetchedFeed !== null
        ? ['ok' => true, 'via' => 'prefetched', 'kelas_ids' => $kelasIds, 'feed' => $prefetchedFeed]
        : margo_learn_fetch_class_feed($ssoPdo, $userSession, false, 15);

    if (empty($feedWrap['ok'])) {
        return ['ok' => false, 'synced' => 0, 'removed' => 0, 'error' => $feedWrap['error'] ?? 'API unavailable'];
    }

    $feed    = $feedWrap['feed'] ?? [];
    $agInfo  = margo_agenda_ensure_schema($ssoPdo);
    /** @var PDO $apdo */
    $apdo    = $agInfo['pdo'];

    $upsertSql = "
        INSERT INTO `agendas`
            (`source`, `source_ref_id`, `is_locked`, `title`, `description`, `start_date`, `end_date`,
             `start_time`, `location`, `category`, `color_hex`, `status_hari`, `tahun_pelajaran`, `semester`,
             `link_url`, `visibility_scope`, `target_kelas_id`, `target_kelas_name`,
             `created_by`, `created_by_name`, `created_by_role`, `is_active`)
        VALUES
            (?, ?, 1, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'kelas', ?, ?, NULL, ?, 'guru', 1)
        ON DUPLICATE KEY UPDATE
            `is_locked`         = 1,
            `title`             = VALUES(`title`),
            `description`       = VALUES(`description`),
            `start_date`        = VALUES(`start_date`),
            `end_date`          = VALUES(`end_date`),
            `start_time`        = VALUES(`start_time`),
            `location`          = VALUES(`location`),
            `category`          = VALUES(`category`),
            `color_hex`         = VALUES(`color_hex`),
            `status_hari`       = VALUES(`status_hari`),
            `link_url`          = VALUES(`link_url`),
            `visibility_scope`  = 'kelas',
            `target_kelas_id`   = VALUES(`target_kelas_id`),
            `target_kelas_name` = VALUES(`target_kelas_name`),
            `created_by_name`   = VALUES(`created_by_name`),
            `is_active`         = 1
    ";

    $syncedCount  = 0;
    $removedCount = 0;
    $activeKeys   = []; // ['source:ref_id' => true]
    $touchedKelas = [];

    try {
        $apdo->exec("SET @margo_kaldik_sync_bypass = 1");
        $stUp = $apdo->prepare($upsertSql);

        // ------------------------------------------------------------------
        // A. TUGAS -> 2 ENTRI AGENDA PER TUGAS (TANGGAL BUAT & DEADLINE)
        // ------------------------------------------------------------------
        foreach (($feed['tugas_broadcast'] ?? []) as $t) {
            $tid       = (int)($t['id'] ?? 0);
            $kId       = (int)($t['kelas']['id'] ?? 0);
            $kName     = (string)($t['kelas']['nama'] ?? 'Kelas');
            if ($tid <= 0 || $kId <= 0) {
                continue;
            }
            $touchedKelas[$kId] = true;

            $judul     = trim((string)($t['judul_tugas'] ?? 'Tugas Pembelajaran'));
            $mapel     = trim((string)($t['mata_pelajaran']['nama'] ?? 'Mata Pelajaran'));
            $guru      = trim((string)($t['guru_pengampu'] ?? 'Guru Pengampu'));
            $jenis     = trim((string)($t['jenis_penugasan'] ?? 'Individu'));
            $portalUrl = trim((string)($t['portal_url'] ?? 'https://learn.ilmuna.my.id/dashboard/siswa/tugas'));
            $kelNote   = margo_learn_format_kelompok_note($t);

            $dtBuat     = margo_learn_split_date_time($t['created_at'] ?? '', $t['tanggal_mulai'] ?? '');
            $dtDeadline = margo_learn_split_date_time($t['tenggat_waktu'] ?? '', $t['tanggal_mulai'] ?? ($t['created_at'] ?? ''));

            $descLines = [
                'Mata Pelajaran: ' . $mapel . ' · Kelas ' . $kName . ' (' . $jenis . ')',
                'Dibuat: ' . ($t['created_at_formatted'] ?? ($t['tanggal_mulai_formatted'] ?? $dtBuat['date'])),
                'Deadline: ' . ($t['tenggat_waktu_formatted'] ?? $dtDeadline['date']),
            ];
            if ($kelNote !== '') {
                $descLines[] = $kelNote;
            }
            if (!empty($t['deskripsi_ringkas'])) {
                $descLines[] = $t['deskripsi_ringkas'];
            }
            $descFull = implode("\n", $descLines);

            // 1) Entri Tanggal Buat Tugas
            $stUp->execute([
                'learn_tugas_buat',
                $tid,
                margo_mb_substr('Buat Tugas: ' . $judul, 0, 200),
                "📌 Tanggal Pembuatan / Penugasan Baru dari Ilmuna Learn\n" . $descFull,
                $dtBuat['date'],
                $dtBuat['date'],
                $dtBuat['time'],
                $mapel . ' · Kelas ' . $kName,
                'akademik',
                '#0D7A87',
                'Tugas Baru',
                null,
                null,
                $portalUrl,
                $kId,
                $kName,
                $guru,
            ]);
            $activeKeys['learn_tugas_buat:' . $tid] = true;
            $syncedCount++;

            // 2) Entri Deadline Tugas
            $stUp->execute([
                'learn_tugas_deadline',
                $tid,
                margo_mb_substr('Deadline Tugas: ' . $judul, 0, 200),
                "⏰ Batas Akhir Pengumpulan Tugas\n" . $descFull,
                $dtDeadline['date'],
                $dtDeadline['date'],
                $dtDeadline['time'],
                $mapel . ' · Kelas ' . $kName,
                'akademik',
                '#EF4444',
                'Deadline Tugas',
                null,
                null,
                $portalUrl,
                $kId,
                $kName,
                $guru,
            ]);
            $activeKeys['learn_tugas_deadline:' . $tid] = true;
            $syncedCount++;
        }

        // ------------------------------------------------------------------
        // B. PROYEK -> 2 ENTRI AGENDA PER PROYEK (TANGGAL BUAT & DEADLINE)
        // ------------------------------------------------------------------
        foreach (($feed['proyek_broadcast'] ?? []) as $p) {
            $pid       = (int)($p['id'] ?? 0);
            $kId       = (int)($p['kelas']['id'] ?? 0);
            $kName     = (string)($p['kelas']['nama'] ?? 'Kelas');
            if ($pid <= 0 || $kId <= 0) {
                continue;
            }
            $touchedKelas[$kId] = true;

            $judul     = trim((string)($p['nama_proyek'] ?? 'Proyek P5 / PjBL'));
            $tema      = trim((string)($p['tema_proyek'] ?? ''));
            $mapel     = trim((string)($p['mata_pelajaran']['nama'] ?? 'Mata Pelajaran'));
            $guru      = trim((string)($p['guru_pembimbing'] ?? 'Guru Pembimbing'));
            $portalUrl = trim((string)($p['portal_url'] ?? 'https://learn.ilmuna.my.id/dashboard/siswa/proyek'));
            $kelNote   = margo_learn_format_kelompok_note($p);

            $dtBuat     = margo_learn_split_date_time($p['created_at'] ?? '', $p['jadwal_mulai'] ?? '');
            $dtDeadline = margo_learn_split_date_time($p['jadwal_selesai'] ?? '', $p['jadwal_mulai'] ?? ($p['created_at'] ?? ''));

            $descLines = [
                'Mata Pelajaran: ' . $mapel . ' · Kelas ' . $kName . ($tema !== '' ? ' · Tema: ' . $tema : ''),
                'Dibuat / Mulai: ' . ($p['created_at_formatted'] ?? ($p['jadwal_mulai_formatted'] ?? $dtBuat['date'])),
                'Deadline Proyek: ' . ($p['jadwal_selesai_formatted'] ?? $dtDeadline['date']),
            ];
            if ($kelNote !== '') {
                $descLines[] = $kelNote;
            }
            if (!empty($p['deskripsi_ringkas'])) {
                $descLines[] = $p['deskripsi_ringkas'];
            }
            $descFull = implode("\n", $descLines);

            // 1) Entri Tanggal Buat Proyek
            $stUp->execute([
                'learn_proyek_buat',
                $pid,
                margo_mb_substr('Buat Proyek: ' . $judul, 0, 200),
                "🚀 Tanggal Pembuatan / Peluncuran Proyek Belajar\n" . $descFull,
                $dtBuat['date'],
                $dtBuat['date'],
                $dtBuat['time'],
                $mapel . ' · Kelas ' . $kName,
                'kegiatan',
                '#7C3AED',
                'Proyek Baru',
                null,
                null,
                $portalUrl,
                $kId,
                $kName,
                $guru,
            ]);
            $activeKeys['learn_proyek_buat:' . $pid] = true;
            $syncedCount++;

            // 2) Entri Deadline Proyek
            $stUp->execute([
                'learn_proyek_deadline',
                $pid,
                margo_mb_substr('Deadline Proyek: ' . $judul, 0, 200),
                "⏳ Batas Akhir Pengumpulan Karya Proyek\n" . $descFull,
                $dtDeadline['date'],
                $dtDeadline['date'],
                null,
                $mapel . ' · Kelas ' . $kName,
                'kegiatan',
                '#D97706',
                'Deadline Proyek',
                null,
                null,
                $portalUrl,
                $kId,
                $kName,
                $guru,
            ]);
            $activeKeys['learn_proyek_deadline:' . $pid] = true;
            $syncedCount++;
        }

        // ------------------------------------------------------------------
        // C. JADWAL ASESMEN (BANK & MASTER ASESMEN SESUAI KELAS)
        // ------------------------------------------------------------------
        foreach (($feed['jadwal_asesmen'] ?? []) as $a) {
            $aid       = (int)($a['id'] ?? 0);
            $kat       = strtolower(trim((string)($a['kategori_asesmen'] ?? 'sumatif')));
            if ($aid <= 0) {
                continue;
            }

            $judul     = trim((string)($a['judul_asesmen'] ?? 'Asesmen'));
            $jenis     = trim((string)($a['jenis_asesmen'] ?? ucfirst($kat)));
            $mapel     = trim((string)($a['mata_pelajaran']['nama'] ?? 'Mata Pelajaran'));
            $guru      = trim((string)($a['guru_pengampu'] ?? 'Guru Pengampu'));
            $durasi    = (int)($a['durasi_menit'] ?? 60);
            $soalCnt   = (int)($a['total_soal'] ?? 0);
            $jamMulai  = trim((string)($a['jam_mulai'] ?? ''));
            $jamSel    = trim((string)($a['jam_selesai'] ?? ''));
            $tglPel    = trim((string)($a['tanggal_pelaksanaan'] ?? ''));
            $portalUrl = trim((string)($a['portal_url'] ?? 'https://learn.ilmuna.my.id/dashboard/siswa/ujian'));
            if ($tglPel === '' || strtotime($tglPel) === false) {
                $tglPel = date('Y-m-d');
            }

            $daftarKelas = (!empty($a['daftar_kelas']) && is_array($a['daftar_kelas']))
                ? $a['daftar_kelas']
                : (!empty($a['kelas']['id']) ? [$a['kelas']] : []);

            foreach ($daftarKelas as $dk) {
                $kId   = (int)($dk['id'] ?? 0);
                $kName = trim((string)($dk['nama'] ?? 'Kelas'));
                if ($kId <= 0) {
                    continue;
                }
                if (!$isAdmin && !empty($kelasIds) && !in_array($kId, $kelasIds, true)) {
                    continue;
                }
                $touchedKelas[$kId] = true;

                $refId = margo_learn_asesmen_ref_id($kat, $aid, $kId);
                $desc  = "📝 Jadwal Asesmen Resmi (" . strtoupper($kat) . " · {$jenis})\n"
                       . "Mata Pelajaran: {$mapel} · Kelas {$kName}\n"
                       . "Pelaksanaan: " . ($a['jadwal_formatted'] ?? $tglPel) . ($jamSel !== '' ? " s/d {$jamSel} WIB" : '') . "\n"
                       . "Durasi: {$durasi} menit" . ($soalCnt > 0 ? " · {$soalCnt} butir soal" : '');

                $stUp->execute([
                    'learn_asesmen',
                    $refId,
                    margo_mb_substr('Asesmen ' . ucfirst($kat) . ': ' . $judul, 0, 200),
                    $desc,
                    $tglPel,
                    $tglPel,
                    $jamMulai !== '' ? substr($jamMulai, 0, 5) : null,
                    $mapel . ' · Kelas ' . $kName,
                    'ujian',
                    '#EA580C',
                    'Asesmen ' . ucfirst($kat),
                    !empty($a['tahun_pelajaran']) ? (string)$a['tahun_pelajaran'] : null,
                    !empty($a['semester']) ? (string)$a['semester'] : null,
                    $portalUrl,
                    $kId,
                    $kName,
                    $guru,
                ]);
                $activeKeys['learn_asesmen:' . $refId] = true;
                $syncedCount++;
            }
        }

        // ------------------------------------------------------------------
        // D. PEMBERSIHAN ITEM USANG PADA KELAS YANG DISINKRONKAN
        // ------------------------------------------------------------------
        $cleanupKelas = !empty($kelasIds) ? $kelasIds : array_keys($touchedKelas);
        if (!empty($cleanupKelas)) {
            $inK = implode(',', array_fill(0, count($cleanupKelas), '?'));
            $stCur = $apdo->prepare("
                SELECT id, source, source_ref_id
                FROM `agendas`
                WHERE `source` IN ('learn_tugas_buat','learn_tugas_deadline','learn_proyek_buat','learn_proyek_deadline','learn_asesmen')
                  AND `target_kelas_id` IN ({$inK})
            ");
            $stCur->execute($cleanupKelas);
            $staleIds = [];
            foreach ($stCur->fetchAll(PDO::FETCH_ASSOC) as $cur) {
                $kStr = $cur['source'] . ':' . (string)$cur['source_ref_id'];
                if (!isset($activeKeys[$kStr])) {
                    $staleIds[] = (int)$cur['id'];
                }
            }
            if (!empty($staleIds)) {
                $inDel = implode(',', array_fill(0, count($staleIds), '?'));
                $apdo->prepare("DELETE FROM `agendas` WHERE `id` IN ({$inDel})")->execute($staleIds);
                $removedCount = count($staleIds);
            }
        }

        $apdo->exec("SET @margo_kaldik_sync_bypass = 0");
    } catch (Throwable $eSync) {
        try { $apdo->exec("SET @margo_kaldik_sync_bypass = 0"); } catch (Throwable $ignore) {}
        error_log('[MargoOne] Learn agenda sync error: ' . $eSync->getMessage());
        return ['ok' => false, 'synced' => $syncedCount, 'removed' => $removedCount, 'error' => $eSync->getMessage()];
    }

    @file_put_contents($flagFile, (string)time());
    $doneInRequest = true;

    return [
        'ok'      => true,
        'via'     => $feedWrap['via'] ?? 'signed_rest_api',
        'synced'  => $syncedCount,
        'removed' => $removedCount,
    ];
}

/**
 * Bangun bootstrap WebSocket + riwayat notifikasi awal kelas untuk header/footer SSO
 */
function margo_learn_issue_user_ws_bootstrap(PDO $ssoPdo, array $userSession)
{
    $wrap = margo_learn_fetch_class_feed($ssoPdo, $userSession, true, 16);
    if (empty($wrap['ok'])) {
        return [
            'ok'             => false,
            'error'          => $wrap['error'] ?? 'Learn API belum tersedia',
            'ws_ticket'      => null,
            'recent_events'  => [],
            'kelas_ids'      => $wrap['kelas_ids'] ?? [],
        ];
    }

    // Sinkronkan juga agenda kelas secara langsung dari payload yang baru ditarik (zero extra HTTP!)
    @margo_learn_sync_class_agendas($ssoPdo, $userSession, false, $wrap['feed'] ?? []);

    $events = [];
    if (!empty($wrap['feed']['recent_events']) && is_array($wrap['feed']['recent_events'])) {
        $events = $wrap['feed']['recent_events'];
    }

    return [
        'ok'            => true,
        'via'           => $wrap['via'] ?? 'signed_rest_api',
        'ws_ticket'     => $wrap['ws_ticket'] ?? null,
        'recent_events' => $events,
        'kelas_ids'     => $wrap['kelas_ids'] ?? [],
    ];
}
