<?php
/**
 * ==============================================================================
 * MARGO ONE — ILMUNA SSO · CRON DISPATCHER NOTIFIKASI BACKGROUND (CLI ONLY)
 * ==============================================================================
 * Pasang di cPanel Cron Jobs (misal tiap 15 atau 30 menit):
 *   * /15 * * * * /usr/local/bin/php -q /home/uofruaju/margo-one.ilmuna.my.id/cron-push-notif.php >/dev/null 2>&1
 *
 * Mengirim Web Push latar belakang untuk:
 *   - Agenda Hari Ini & Agenda Besok (terfilter sesuai role & kelas masing-masing)
 *   - Info Ekosistem / Pengumuman terbaru
 * ==============================================================================
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

require_once __DIR__ . '/config.php';
require_once BASE_PATH . '/includes/webpush-engine.php';

try {
    $pdo = getDBConnection();
    $res = margo_push_dispatch_daily_agendas($pdo);
    echo '[MargoPush] ' . date('Y-m-d H:i:s') . ' ' . json_encode($res) . PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, '[MargoPush] ERROR: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
