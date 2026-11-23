<?php
/**
 * ==============================================================================
 * MARGO ONE — DATABASE MIGRATION & CLEANUP TOOL
 * ==============================================================================
 * Memindahkan seluruh tabel Margo One SSO (`sso_*`, `agendas`, `margo_slo_revocations`)
 * dari database `uofruaju_learn_ilmuna` ke database khusus `uofruaju_margo-one`,
 * memverifikasi jumlah baris 100%, lalu menghapus tabel Margo One dari `uofruaju_learn_ilmuna`
 * tanpa menyentuh tabel milik Ilmuna Learn (`users`, `guru`, `siswa`, `kelas`, `kalender_pendidikan`, dll).
 *
 * Cara jalankan di produksi (cPanel):
 *   Buka: https://margo-one.ilmuna.my.id/migrate-db-to-margo-one.php?key=M@r90On3
 *   (Tambahkan &dry_run=1 untuk simulasi tanpa mengubah data)
 * ==============================================================================
 */

if (!defined('ILMUNA_SKIP_GD_WAF_INTERSTITIAL')) {
    define('ILMUNA_SKIP_GD_WAF_INTERSTITIAL', true);
}

$isCli = (php_sapi_name() === 'cli');
$expectedKey = 'M@r90On3';

if (!$isCli) {
    $providedKey = isset($_GET['key']) ? (string)$_GET['key'] : '';
    if (!hash_equals($expectedKey, $providedKey)) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo "403 Forbidden — Tambahkan ?key=M@r90On3 untuk menjalankan migrasi database Margo One.";
        exit;
    }
    header('Content-Type: text/html; charset=utf-8');
}

$dryRun = $isCli
    ? in_array('--dry-run', (array)($argv ?? []), true)
    : (!empty($_GET['dry_run']));

$host    = 'localhost';
$port    = '3306';
$charset = 'utf8mb4';

$srcDb   = 'uofruaju_learn_ilmuna';
$srcUser = 'uofruaju_Learn_Ilmuna';
$srcPass = 'Ilmuna28@';

$dstDb   = 'uofruaju_margo-one';
$dstUser = 'uofruaju_margo-one-db';
$dstPass = 'M@r90On3';

// Opsional: di lokal Laragon, jika ada --source=uofruaju_margo_one
if ($isCli) {
    foreach ((array)($argv ?? []) as $arg) {
        if (strpos($arg, '--source=') === 0) {
            $srcDb = substr($arg, 9);
        }
    }
} elseif (!empty($_GET['source']) && in_array($_GET['source'], ['uofruaju_learn_ilmuna', 'uofruaju_margo_one'], true)) {
    $srcDb = (string)$_GET['source'];
}

$pdoOpts = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];
if (defined('PDO::MYSQL_ATTR_INIT_COMMAND')) {
    $pdoOpts[PDO::MYSQL_ATTR_INIT_COMMAND] = "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci, time_zone = '+07:00'";
}

function margo_mig_connect($db, $user, $pass, $host, $port, $charset, $opts, $createIfLocal = false)
{
    $hostNow = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
    $isLocal = (php_sapi_name() === 'cli')
        || strpos($hostNow, 'localhost') === 0
        || strpos($hostNow, '127.0.0.1') === 0;

    try {
        $pdo = new PDO("mysql:host={$host};port={$port};dbname={$db};charset={$charset}", $user, $pass, $opts);
        $pdo->exec("SET time_zone = '+07:00'");
        return $pdo;
    } catch (PDOException $e1) {
        if ($isLocal) {
            if ($createIfLocal) {
                $tmp = new PDO("mysql:host=127.0.0.1;port={$port};charset={$charset}", 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
                $tmp->exec("CREATE DATABASE IF NOT EXISTS `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            }
            $pdo = new PDO("mysql:host=127.0.0.1;port={$port};dbname={$db};charset={$charset}", 'root', '', $opts);
            $pdo->exec("SET time_zone = '+07:00'");
            return $pdo;
        }
        throw $e1;
    }
}

$report = [];
$errors = [];

try {
    $srcPdo = margo_mig_connect($srcDb, $srcUser, $srcPass, $host, $port, $charset, $pdoOpts, false);
    $dstPdo = margo_mig_connect($dstDb, $dstUser, $dstPass, $host, $port, $charset, $pdoOpts, true);

    // Ambil seluruh tabel milik Margo One di database sumber (HANYA sso_*, agendas, margo_slo_revocations)
    $allSrcTables = $srcPdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    $margoTables = [];
    foreach ($allSrcTables as $tbl) {
        if (strpos($tbl, 'sso_') === 0 || $tbl === 'agendas' || $tbl === 'margo_slo_revocations') {
            $margoTables[] = $tbl;
        }
    }

    if (!$dryRun) {
        $dstPdo->exec("SET FOREIGN_KEY_CHECKS = 0");
    }

    foreach ($margoTables as $tbl) {
        $srcCount = (int)$srcPdo->query("SELECT COUNT(*) FROM `{$tbl}`")->fetchColumn();
        $dstExists = (bool)$dstPdo->query("SHOW TABLES LIKE " . $dstPdo->quote($tbl))->fetch();
        $dstCountBefore = $dstExists ? (int)$dstPdo->query("SELECT COUNT(*) FROM `{$tbl}`")->fetchColumn() : 0;

        $entry = [
            'table'      => $tbl,
            'src_rows'   => $srcCount,
            'dst_before' => $dstCountBefore,
            'dst_after'  => $dstCountBefore,
            'copied'     => 0,
            'dropped'    => false,
            'status'     => 'PENDING',
        ];

        if ($dryRun) {
            $entry['status'] = 'DRY_RUN';
            $report[] = $entry;
            continue;
        }

        try {
            // 1. Pastikan struktur tabel ada di target
            if (!$dstExists) {
                $createRow = $srcPdo->query("SHOW CREATE TABLE `{$tbl}`")->fetch(PDO::FETCH_ASSOC);
                $createSql = $createRow['Create Table'] ?? '';
                if ($createSql === '') {
                    throw new RuntimeException("Gagal membaca DDL tabel {$tbl}");
                }
                // Hapus referensi schema lama bila ada di constraint
                $createSql = preg_replace('/CREATE TABLE `/i', 'CREATE TABLE IF NOT EXISTS `', $createSql, 1);
                $dstPdo->exec($createSql);
            }

            // 2. Salin seluruh data sumber ke target (jika target terisi seed awal saat diakses sebelum migrasi, kosongkan dulu agar data asli produksi dari Learn utuh 100%)
            if ($srcCount > 0) {
                if ($dstCountBefore > 0 && empty($_GET['keep_target'])) {
                    $dstPdo->exec("TRUNCATE TABLE `{$tbl}`");
                }
                $rows = $srcPdo->query("SELECT * FROM `{$tbl}`")->fetchAll(PDO::FETCH_ASSOC);
                if (!empty($rows)) {
                    $cols = array_keys($rows[0]);
                    $colList = '`' . implode('`, `', $cols) . '`';
                    $placeholders = implode(', ', array_fill(0, count($cols), '?'));
                    $insStmt = $dstPdo->prepare("INSERT IGNORE INTO `{$tbl}` ({$colList}) VALUES ({$placeholders})");
                    foreach ($rows as $r) {
                        $insStmt->execute(array_values($r));
                        $entry['copied']++;
                    }
                }
            }

            $dstCountAfter = (int)$dstPdo->query("SELECT COUNT(*) FROM `{$tbl}`")->fetchColumn();
            $entry['dst_after'] = $dstCountAfter;

            // 3. Verifikasi sebelum hapus dari Learn
            if ($dstCountAfter >= $srcCount) {
                $entry['status'] = 'VERIFIED';
            } else {
                $entry['status'] = 'COUNT_MISMATCH';
                $errors[] = "Tabel {$tbl}: sumber={$srcCount}, target={$dstCountAfter}";
            }
        } catch (Throwable $eTbl) {
            $entry['status'] = 'ERROR: ' . $eTbl->getMessage();
            $errors[] = "Tabel {$tbl}: " . $eTbl->getMessage();
        }

        $report[] = $entry;
    }

    if (!$dryRun) {
        $dstPdo->exec("SET FOREIGN_KEY_CHECKS = 1");
    }

    // 4. Hapus tabel Margo One dari uofruaju_learn_ilmuna HANYA JIKA 100% tabel terverifikasi tanpa error
    if (!$dryRun && empty($errors) && $srcDb === 'uofruaju_learn_ilmuna' && !empty($margoTables)) {
        $srcPdo->exec("SET FOREIGN_KEY_CHECKS = 0");
        foreach ($report as &$entry) {
            if ($entry['status'] === 'VERIFIED') {
                $t = $entry['table'];
                $srcPdo->exec("DROP TABLE IF EXISTS `{$t}`");
                $entry['dropped'] = true;
                $entry['status']  = 'MIGRATED_AND_DROPPED_FROM_LEARN';
            }
        }
        unset($entry);
        $srcPdo->exec("SET FOREIGN_KEY_CHECKS = 1");
    }

    // 5. Re-sync Kaldik ke DB baru (uofruaju_margo-one)
    $kaldikMsg = 'Dilewati (dry-run)';
    if (!$dryRun && empty($errors)) {
        require_once __DIR__ . '/config.php';
        require_once __DIR__ . '/includes/agenda-engine.php';
        if (function_exists('margo_agenda_backfill_from_kaldik')) {
            @margo_agenda_install_kaldik_triggers($dstPdo);
            $kRes = @margo_agenda_backfill_from_kaldik($dstPdo);
            $kaldikMsg = 'Sinkron Kaldik: ' . (int)($kRes['synced'] ?? 0) . ' agenda ke ' . $dstDb;
        }
    }
} catch (Throwable $eFatal) {
    $errors[] = 'FATAL: ' . $eFatal->getMessage();
    $kaldikMsg = '-';
}

if ($isCli) {
    echo "=== MARGO ONE DB MIGRATION ({$srcDb} -> {$dstDb}) ===\n";
    echo "Mode: " . ($dryRun ? "DRY RUN" : "LIVE") . "\n";
    foreach ($report as $r) {
        echo sprintf("  %-32s | src=%-4d | dst_before=%-4d | dst_after=%-4d | dropped=%-3s | %s\n",
            $r['table'], $r['src_rows'], $r['dst_before'], $r['dst_after'], $r['dropped'] ? 'YES' : 'NO', $r['status']);
    }
    echo "Kaldik: {$kaldikMsg}\n";
    if (!empty($errors)) {
        echo "ERRORS:\n  - " . implode("\n  - ", $errors) . "\n";
        exit(1);
    }
    echo "DONE!\n";
    exit(0);
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<title>Margo One · Migrasi Database ke <?= htmlspecialchars($dstDb) ?></title>
<style>
body{font-family:system-ui,-apple-system,sans-serif;background:#0F172A;color:#F8FAFC;margin:0;padding:28px}
.box{max-width:960px;margin:0 auto;background:#1E293B;border:1px solid #334155;border-radius:16px;padding:24px}
h1{margin:0 0 8px;font-size:20px;color:#38BDF8}
.meta{font-size:13px;color:#94A3B8;margin-bottom:18px}
table{width:100%;border-collapse:collapse;font-size:13px;margin-top:12px}
th,td{padding:10px 12px;border-bottom:1px solid #334155;text-align:left}
th{color:#94A3B8;font-weight:600}
.ok{color:#34D399;font-weight:700}.err{color:#F87171;font-weight:700}
.note{margin-top:16px;padding:12px 14px;border-radius:10px;background:#0F172A;border:1px solid #334155;font-size:13px}
</style>
</head>
<body>
<div class="box">
    <h1>Migrasi Database Margo One SSO</h1>
    <div class="meta">Sumber: <strong><?= htmlspecialchars($srcDb) ?></strong> &rarr; Target: <strong><?= htmlspecialchars($dstDb) ?></strong> (User: <code><?= htmlspecialchars($dstUser) ?></code>) · Mode: <strong><?= $dryRun ? 'SIMULASI (DRY RUN)' : 'EKSEKUSI LANGSUNG' ?></strong></div>
    <?php if (!empty($errors)): ?>
        <div class="note err">Terjadi kendala:<br><?= nl2br(htmlspecialchars(implode("\n", $errors))) ?></div>
    <?php endif; ?>
    <table>
        <thead><tr><th>Tabel</th><th>Baris Sumber</th><th>Target Awal</th><th>Target Akhir</th><th>Dihapus dari Learn</th><th>Status</th></tr></thead>
        <tbody>
        <?php if (empty($report)): ?>
            <tr><td colspan="6" style="text-align:center;color:#94A3B8;">Tidak ada tabel Margo One (<code>sso_*</code>) yang tersisa di <code><?= htmlspecialchars($srcDb) ?></code>. Database Learn sudah bersih!</td></tr>
        <?php else: foreach ($report as $r): ?>
            <tr>
                <td><code><?= htmlspecialchars($r['table']) ?></code></td>
                <td><?= (int)$r['src_rows'] ?></td>
                <td><?= (int)$r['dst_before'] ?></td>
                <td><?= (int)$r['dst_after'] ?></td>
                <td><?= $r['dropped'] ? '<span class="ok">YA</span>' : 'TIDAK' ?></td>
                <td class="<?= strpos($r['status'], 'ERROR') === 0 || $r['status'] === 'COUNT_MISMATCH' ? 'err' : 'ok' ?>"><?= htmlspecialchars($r['status']) ?></td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>
    <div class="note">
        <strong>Kaldik:</strong> <?= htmlspecialchars($kaldikMsg) ?><br>
        <strong>Keamanan:</strong> Setelah migrasi selesai dan login Margo One berhasil diuji, Anda dapat menghapus file <code>migrate-db-to-margo-one.php</code> dari server.
    </div>
</div>
</body>
</html>
