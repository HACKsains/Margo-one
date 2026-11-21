<?php
/**
 * ==============================================================================
 * MARGO ONE — ILMUNA SSO · WEB DEPLOYMENT & GIT SYNC TOOL
 * ==============================================================================
 * Penggunaan di cPanel:
 *   https://margo-one.ilmuna.my.id/deploy.php?secret=margo_one_deploy_2026
 * ==============================================================================
 */

if (!defined('DEPLOY_SECRET')) {
    define('DEPLOY_SECRET', 'margo_one_deploy_2026');
}

$secret = isset($_GET['secret']) ? (string)$_GET['secret'] : '';
if ($secret === '' || !hash_equals(DEPLOY_SECRET, $secret)) {
    http_response_code(403);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="id"><body style="font-family:sans-serif;padding:40px;text-align:center;background:#F8FAFC;color:#1D3B5E;">';
    echo '<h2 style="color:#DC2626;">Akses Ditolak (403 Forbidden)</h2>';
    echo '<p>Gunakan parameter secret yang valid: <code>?secret=...</code></p>';
    echo '</body></html>';
    exit;
}

header('Content-Type: text/html; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Margo One SSO — Auto Deployer</title>
    <style>
        body { font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; background: #0B1523; color: #F8FAFC; padding: 28px 16px; margin: 0; }
        .card { max-width: 860px; margin: 0 auto; background: #102035; border: 1px solid rgba(82,178,220,.25); border-radius: 18px; padding: 24px; box-shadow: 0 20px 45px rgba(0,0,0,.45); }
        h1 { margin: 0 0 8px; font-size: 20px; color: #52B2DC; }
        p { color: #94A3B8; font-size: 13.5px; margin: 0 0 18px; }
        pre { background: #070E18; color: #A5F3FC; padding: 16px; border-radius: 12px; overflow-x: auto; font-size: 12.5px; line-height: 1.55; border: 1px solid rgba(82,178,220,.18); }
        .btn { display: inline-block; padding: 10px 18px; background: linear-gradient(135deg, #1D3B5E, #0D7A87); color: #fff; text-decoration: none; border-radius: 10px; font-weight: 700; margin-top: 14px; font-size: 13px; }
    </style>
</head>
<body>
<div class="card">
    <h1>Margo One — Ilmuna SSO Auto Deployer</h1>
    <p>Sinkronisasi repositori Git &amp; verifikasi skema database otomatis untuk <code>margo-one.ilmuna.my.id</code>.</p>
    <pre><?php
    $repoPath = __DIR__;
    $disabled = array_map('trim', explode(',', (string)ini_get('disable_functions')));

    if (in_array('shell_exec', $disabled, true)) {
        echo "[WARN] shell_exec dinonaktifkan di konfigurasi PHP server.\nGunakan cPanel Git Version Control atau Cron Job untuk menjalankan git pull.\n";
    } else {
        $commands = [
            'Git Reset Hard' => 'git reset --hard HEAD 2>&1',
            'Git Pull Main'  => 'git pull origin main 2>&1',
            'Git Status'     => 'git status -sb 2>&1',
            'Last Commit'    => 'git log -1 --stat 2>&1',
        ];
        foreach ($commands as $label => $cmd) {
            echo "=== {$label} ===\n";
            $out = @shell_exec('cd ' . escapeshellarg($repoPath) . ' && ' . $cmd);
            echo htmlspecialchars($out ?: '(selesai)', ENT_QUOTES, 'UTF-8') . "\n\n";
        }
    }

    echo "=== Verifikasi Auto-Migrasi Skema SSO ===\n";
    try {
        require_once __DIR__ . '/database.php';
        $pdo = getDBConnection();
        $res = runSsoDatabaseMigration($pdo);
        echo htmlspecialchars(json_encode($res, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') . "\n";
    } catch (Throwable $e) {
        echo "[ERROR] " . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . "\n";
    }
    ?></pre>
    <a class="btn" href="?secret=<?= htmlspecialchars($secret, ENT_QUOTES, 'UTF-8') ?>">Jalankan Ulang</a>
</div>
</body>
</html>
