<?php
/**
 * ==============================================================================
 * MARGO ONE — ILMUNA SSO · AGENDA INTERAKTIF & SINKRONISASI KALDIK (/agenda)
 * ==============================================================================
 * Arsitektur & Fitur Utama:
 *   1. Database Khusus Agenda (`uofruaju_margo_agenda`) dengan sinkronisasi real-time
 *      SEMUA baris `uofruaju_learn_ilmuna.kalender_pendidikan` (termasuk KBM Reguler,
 *      Libur Nasional, Libur Semester, Kegiatan/Ujian & Keagamaan) via MySQL Triggers.
 *   2. Kalender Interaktif bergaya `curriculum.php`:
 *      - Tampilan Bulan (Masehi + Hijriyah), Minggu, Hari, & Daftar Agenda
 *      - Drag-to-Select rentang tanggal langsung di grid kalender + Floating Drag Bar
 *      - Mode Kuas Cepat (Quick Paint) & Pemilih Warna untuk setiap jenis kegiatan
 *      - Kode Warna Lengkap untuk status Kaldik maupun kategori Rapat, Akademik,
 *        Kegiatan, Sistem SSO, Pengumuman, & Pribadi!
 *   3. Aturan Hak Akses & Audiens Berbasis Peran:
 *      - Admin : membuat agenda untuk Semua Orang (`all`), Khusus Guru (`guru`),
 *                Khusus Siswa (`siswa`), atau Pribadi (`private`).
 *      - Guru  : membuat agenda untuk Diri Sendiri (`private`) atau Kelas yang ia ampu (`kelas`).
 *      - Siswa : membuat agenda HANYA untuk Diri Sendiri (`private`).
 *   4. Proteksi Mutlak Kaldik: Agenda bersumber dari Kaldik (`source = 'kaldik'`)
 *      terkunci permanen (read-only) — tidak dapat diubah atau dihapus oleh siapapun.
 *
 * 100% kompatibel PHP 7.4 (ea-php74) hingga PHP 8.3.
 * ==============================================================================
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once BASE_PATH . '/includes/agenda-engine.php';

requireAuth();

$pdo         = getDBConnection();
$currentUser = getCurrentUser();

if (empty($_SESSION['csrf_agenda_sso'])) {
    $_SESSION['csrf_agenda_sso'] = bin2hex(random_bytes(32));
}
$csrfAgenda = $_SESSION['csrf_agenda_sso'];

/* ==============================================================================
 * AJAX JSON API HANDLER (?api=1) — MENDUKUNG ADMIN, GURU & SISWA
 * ============================================================================== */
if (isset($_GET['api']) && $_GET['api'] === '1') {
    while (ob_get_level()) {
        ob_end_clean();
    }
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: private, no-store, max-age=0');

    $act = trim((string)($_POST['api_action'] ?? $_GET['api_action'] ?? 'list'));
    try {
        if ($act === 'list') {
            $items  = margo_agenda_fetch_visible($pdo, $currentUser, ['limit' => 1500]);
            $ctx    = margo_agenda_build_user_context($pdo, $currentUser);
            $status = $ctx['is_admin'] ? margo_agenda_get_status($pdo) : null;
            echo json_encode([
                'success' => true,
                'data'    => $items,
                'context' => $ctx,
                'status'  => $status,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }

        // Validasi CSRF untuk semua mutasi POST
        $postedCsrf = (string)($_POST['csrf_token'] ?? '');
        if ($postedCsrf === '' || !hash_equals($csrfAgenda, $postedCsrf)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Token keamanan tidak valid. Muat ulang halaman dan coba lagi.']);
            exit;
        }

        if ($act === 'save') {
            $res = margo_agenda_save($pdo, $currentUser, $_POST);
            if (!empty($res['success'])) {
                if ((int)($_POST['id'] ?? 0) === 0) {
                    try {
                        require_once BASE_PATH . '/includes/webpush-engine.php';
                        margo_push_notify_agenda_instant($pdo, $_POST, (int)$currentUser['id']);
                    } catch (Throwable $ePushAg) {}
                }
                $res['items'] = margo_agenda_fetch_visible($pdo, $currentUser, ['limit' => 1500]);
            }
            echo json_encode($res, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }

        if ($act === 'save_batch') {
            $datesRaw = $_POST['dates'] ?? [];
            if (is_string($datesRaw)) {
                $decoded = json_decode($datesRaw, true);
                $datesRaw = is_array($decoded) ? $decoded : explode(',', $datesRaw);
            }
            $dates = [];
            foreach ((array)$datesRaw as $d) {
                $dTrim = trim((string)$d);
                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dTrim)) {
                    $dates[$dTrim] = $dTrim;
                }
            }
            $dates = array_values($dates);
            sort($dates);

            if (empty($dates)) {
                $sd = trim((string)($_POST['start_date'] ?? ''));
                $ed = trim((string)($_POST['end_date'] ?? $sd));
                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $sd)) {
                    $dates = [$sd];
                    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $ed) && $ed >= $sd) {
                        $dates[] = $ed;
                    }
                }
            }

            if (empty($dates)) {
                echo json_encode(['success' => false, 'message' => 'Pilih minimal 1 tanggal pada kalender.']);
                exit;
            }

            // Gabungkan tanggal berurutan menjadi blok rentang (start_date..end_date)
            $ranges = [];
            $rStart = $dates[0];
            $rPrev  = $dates[0];
            for ($i = 1; $i < count($dates); $i++) {
                $nextExpected = date('Y-m-d', strtotime($rPrev . ' +1 day'));
                if ($dates[$i] === $nextExpected) {
                    $rPrev = $dates[$i];
                } else {
                    $ranges[] = [$rStart, $rPrev];
                    $rStart = $dates[$i];
                    $rPrev  = $dates[$i];
                }
            }
            $ranges[] = [$rStart, $rPrev];

            $savedCnt = 0;
            $lastErr  = '';
            foreach ($ranges as $rg) {
                $payload = $_POST;
                $payload['id']         = 0;
                $payload['start_date'] = $rg[0];
                $payload['end_date']   = $rg[1];
                $rSave = margo_agenda_save($pdo, $currentUser, $payload);
                if (!empty($rSave['success'])) {
                    $savedCnt++;
                } else {
                    $lastErr = (string)($rSave['message'] ?? 'Gagal menyimpan agenda.');
                }
            }

            if ($savedCnt === 0) {
                echo json_encode(['success' => false, 'message' => $lastErr ?: 'Gagal menyimpan agenda.']);
                exit;
            }

            echo json_encode([
                'success' => true,
                'message' => $savedCnt . ' agenda berhasil diterapkan pada ' . count($dates) . ' tanggal terpilih.',
                'items'   => margo_agenda_fetch_visible($pdo, $currentUser, ['limit' => 1500]),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }

        if ($act === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            $res = margo_agenda_delete($pdo, $currentUser, $id);
            if (!empty($res['success'])) {
                $res['items'] = margo_agenda_fetch_visible($pdo, $currentUser, ['limit' => 1500]);
            }
            echo json_encode($res, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }

        if ($act === 'sync_kaldik') {
            if (!hasRole('admin')) {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'Hanya Administrator yang dapat menjalankan sinkronisasi manual Kaldik.']);
                exit;
            }
            $trgRes  = margo_agenda_install_kaldik_triggers($pdo);
            $syncRes = margo_agenda_backfill_from_kaldik($pdo);
            $status  = margo_agenda_get_status($pdo);
            $items   = margo_agenda_fetch_visible($pdo, $currentUser, ['limit' => 1500]);
            echo json_encode([
                'success' => !empty($syncRes['ok']),
                'message' => $syncRes['message'] . (!empty($trgRes['kaldik_triggers_ok']) ? ' (MySQL Trigger Real-Time Aktif)' : ''),
                'status'  => $status,
                'items'   => $items,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }

        echo json_encode(['success' => false, 'message' => 'Aksi tidak dikenali.']);
        exit;
    } catch (Throwable $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }
}

/* ==============================================================================
 * SERVER-RENDERED INITIAL HYDRATION (ZERO-FLICKER, BEBAS ERROR 401)
 * ============================================================================== */
$userCtx        = margo_agenda_build_user_context($pdo, $currentUser);
$isAdmin        = !empty($userCtx['is_admin']);
$isGuru         = !empty($userCtx['is_guru']);
$isSiswa        = !empty($userCtx['is_siswa']);
$initialAgendas = margo_agenda_fetch_visible($pdo, $currentUser, ['limit' => 1500, 'skip_learn_sync' => true]);
$agendaDbStatus = $isAdmin ? margo_agenda_get_status($pdo) : null;
$focusId        = isset($_GET['id']) ? (int)$_GET['id'] : 0;

$pageTitle = 'Agenda & Kalender Interaktif';
$pageIcon  = 'solar:calendar-mark-bold';

$extraHead = '<style>
/* ===== PALET WARNA KALDIK (PARITAS CURRICULUM.PHP) + KATEGORI KEGIATAN / RAPAT ===== */
.mag-painter-card{background:#fff;border:1px solid #E2E8F0;border-radius:16px;padding:13px 16px;margin-bottom:14px;box-shadow:0 4px 14px rgba(15,23,42,.04);display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap}
.mag-painter-left{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.mag-role-hint{display:inline-flex;align-items:center;gap:7px;padding:6px 12px;border-radius:10px;background:rgba(13,122,135,0.08);color:#1D3B5E;font-size:12px;font-weight:700}
.mag-swatch-group{display:flex;align-items:center;gap:7px;flex-wrap:wrap}
.mag-swatch-btn{display:inline-flex;align-items:center;gap:6px;padding:6px 12px;border-radius:9px;font-size:12px;font-weight:700;cursor:pointer;border:1.5px solid #CBD5E1;background:#F8FAFC;color:#1D3B5E;transition:all .16s ease;font-family:inherit}
.mag-swatch-btn:hover{transform:translateY(-1px);box-shadow:0 3px 8px rgba(15,23,42,.08)}
.mag-swatch-btn.is-selected{box-shadow:0 0 0 2.5px #0F172A,0 4px 10px rgba(15,23,42,.12);transform:scale(1.02)}
.mag-swatch-dot{width:9px;height:9px;border-radius:50%;flex-shrink:0}
.mag-swatch--akademik{color:#0B5D67;background:#ECFEFF;border-color:#99F6E4}
.mag-swatch--rapat{color:#3730A3;background:#EEF2FF;border-color:#C7D2FE}
.mag-swatch--ujian{color:#92400E;background:#FFFBEB;border-color:#FDE68A}
.mag-swatch--kegiatan{color:#9A3412;background:#FFF7ED;border-color:#FED7AA}
.mag-swatch--sistem{color:#1D3B5E;background:#F1F5F9;border-color:#CBD5E1}
.mag-swatch--pengumuman{color:#075985;background:#F0F9FF;border-color:#BAE6FD}
.mag-swatch--pribadi{color:#9D174D;background:#FDF2F8;border-color:#FBCFE8}

/* ===== RBC-STYLE MAIN CALENDAR WRAPPER (PERSIS CURRICULUM.PHP) ===== */
.mag-rbc-card{background:#fff;border:1px solid #E2E8F0;border-radius:20px;box-shadow:0 6px 22px rgba(15,23,42,.05);overflow:hidden;margin-bottom:18px;user-select:none}
.mag-rbc-toolbar{padding:15px 18px;background:#fff;border-bottom:1px solid #E2E8F0;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px}
.mag-rbc-left{display:flex;align-items:center;gap:12px;min-width:220px}
.mag-rbc-icon{width:42px;height:42px;border-radius:12px;background:rgba(13,122,135,0.10);color:#0D7A87;display:flex;align-items:center;justify-content:center;font-size:21px;flex-shrink:0}
.mag-rbc-title{font-family:"Outfit",sans-serif;font-size:18px;font-weight:800;color:#0F172A;line-height:1.2;margin:0}
.mag-rbc-hijri{font-size:11.5px;font-weight:700;color:#0D7A87;background:rgba(13,122,135,0.09);padding:2px 9px;border-radius:999px;display:inline-flex;align-items:center;gap:5px;margin-top:3px;width:fit-content}
.mag-nav-group{display:inline-flex;align-items:center;background:#F8FAFC;border:1px solid #E2E8F0;border-radius:10px;padding:3px;gap:2px}
.mag-nav-btn{border:none;background:transparent;padding:6px 11px;border-radius:7px;font-size:12px;font-weight:700;color:#1D3B5E;cursor:pointer;display:inline-flex;align-items:center;gap:5px;transition:all .15s;font-family:inherit}
.mag-nav-btn:hover{background:#fff;color:#0D7A87;box-shadow:0 1px 3px rgba(15,23,42,.08)}
.mag-view-group{display:inline-flex;align-items:center;background:#F1F5F9;border-radius:10px;padding:3px;gap:2px}
.mag-view-btn{border:none;background:transparent;padding:7px 12px;border-radius:8px;font-size:12px;font-weight:700;color:#475569;cursor:pointer;display:inline-flex;align-items:center;gap:5px;transition:all .15s;font-family:inherit}
.mag-view-btn:hover{color:#0F172A}
.mag-view-btn.is-active{background:#fff;color:#0D7A87;font-weight:800;box-shadow:0 2px 6px rgba(15,23,42,.08)}

/* ===== LEGEND & DB STATUS STRIP ===== */
.mag-subbar{padding:11px 18px;background:#F8FAFC;border-bottom:1px solid #E2E8F0;display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap}
.mag-legend-row{display:flex;align-items:center;gap:10px;flex-wrap:wrap;font-size:11px;font-weight:700;color:#334155}
.mag-legend-grp{display:inline-flex;align-items:center;gap:8px;flex-wrap:wrap;padding:3px 9px;border-radius:9px;background:#fff;border:1px solid #E2E8F0}
.mag-legend-title{font-size:10px;text-transform:uppercase;letter-spacing:.04em;color:#64748B;font-weight:800}
.mag-legend-item{display:inline-flex;align-items:center;gap:4px}
.mag-legend-swatch{width:9px;height:9px;border-radius:3px;display:inline-block;flex-shrink:0}
.mag-db-pill{display:inline-flex;align-items:center;gap:6px;padding:4px 10px;border-radius:999px;background:rgba(29,59,94,0.08);color:#1D3B5E;font-size:11px;font-weight:700}

/* ===== MONTH GRID (DRAG-TO-SELECT + MASEHI & HIJRIYAH) ===== */
.mag-cal-table-wrap{width:100%;overflow-x:auto}
.mag-cal-table{width:100%;border-collapse:collapse;table-layout:fixed;min-width:700px}
.mag-cal-table th{padding:11px 6px;font-size:11.5px;font-weight:800;text-transform:uppercase;letter-spacing:.05em;color:#475569;background:#F8FAFC;border-bottom:1.5px solid #E2E8F0;border-right:1px solid #E2E8F0;text-align:center;width:14.285%}
.mag-cal-table th:last-child{border-right:none}
.mag-cal-table th.th-sun{background:#FEF2F2 !important;color:#DC2626 !important}
.mag-cell{height:122px;min-height:122px;border-right:1px solid #E2E8F0;border-bottom:1px solid #E2E8F0;padding:7px 8px;vertical-align:top;cursor:pointer;position:relative;background:#fff;transition:background .12s,box-shadow .12s}
.mag-cell:last-child{border-right:none}
.mag-cell:hover{box-shadow:inset 0 0 0 2px #0D7A87;z-index:2}
.mag-cell.is-other{background:#F8FAFC;opacity:.52}
.mag-cell.is-sunday{background:#FFF5F5}
.mag-cell.is-sunday .mag-masehi-num{color:#DC2626}
.mag-cell.is-sunday .mag-hijri-num{color:#B91C1C;background:rgba(220,38,38,0.08)}
.mag-cell.is-today{border-top:2.5px solid #0D7A87 !important;background:rgba(13,122,135,0.05) !important}
.mag-cell.is-selecting{outline:2.5px dashed #0284C7 !important;background:rgba(2,132,199,0.16) !important;z-index:5}
.mag-is-kuas .mag-cell{cursor:cell !important}
.mag-is-kuas .mag-cell:hover{box-shadow:inset 0 0 0 2.5px #10B981 !important;background:rgba(16,185,129,0.06) !important}
.mag-cell-top{display:flex;justify-content:space-between;align-items:center;line-height:1;margin-bottom:5px;gap:4px}
.mag-masehi-num{font-family:"Outfit",sans-serif;font-size:15px;font-weight:800;color:#0F172A;display:inline-flex;align-items:center;gap:4px}
.mag-cell.is-today .mag-masehi-num{color:#0D7A87}
.mag-hijri-num{font-size:9.5px;font-weight:700;color:#0D7A87;background:rgba(13,122,135,0.09);padding:2px 5px;border-radius:5px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:76px}
.mag-cell-add{position:absolute;bottom:5px;right:6px;width:20px;height:20px;border-radius:6px;border:1px solid rgba(13,122,135,0.28);background:#fff;color:#0D7A87;display:flex;align-items:center;justify-content:center;font-size:13px;opacity:0;transition:opacity .15s;pointer-events:none}
.mag-cell:hover .mag-cell-add{opacity:1}

/* ===== EVENT CHIPS BERWARNA (KALDIK + KEGIATAN/RAPAT/PRIBADI) ===== */
.mag-chips-wrap{display:flex;flex-direction:column;gap:3.5px;margin-top:3px}
.mag-chip{display:flex;align-items:center;gap:5px;padding:3px 6px;border-radius:6px;font-size:10.5px;font-weight:700;line-height:1.25;cursor:pointer;transition:transform .12s,box-shadow .12s;overflow:hidden;white-space:nowrap;border-left:3px solid #0D7A87}
.mag-chip:hover{transform:translateY(-1px);box-shadow:0 2px 6px rgba(15,23,42,.12)}
.mag-chip-dot{width:6px;height:6px;border-radius:50%;flex-shrink:0}
.mag-chip-title{overflow:hidden;text-overflow:ellipsis;white-space:nowrap;flex:1}
.mag-chip-more{font-size:10px;font-weight:800;color:#0D7A87;padding:1px 4px;cursor:pointer;display:inline-block}
.mag-chip-more:hover{text-decoration:underline}

/* ===== WEEK VIEW ===== */
.mag-week-grid{display:grid;grid-template-columns:repeat(7,minmax(0,1fr));min-height:420px;background:#fff}
@media(max-width:960px){.mag-week-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:540px){.mag-week-grid{grid-template-columns:1fr}}
.mag-week-col{border-right:1px solid #E2E8F0;border-bottom:1px solid #E2E8F0;display:flex;flex-direction:column}
.mag-week-col:last-child{border-right:none}
.mag-week-head{padding:11px 8px;background:#F8FAFC;border-bottom:1.5px solid #E2E8F0;text-align:center}
.mag-week-col.is-today .mag-week-head{background:rgba(13,122,135,0.09);border-bottom-color:#0D7A87}
.mag-week-col.is-sunday .mag-week-head{background:#FEF2F2}
.mag-week-col.is-sunday .mag-week-day,.mag-week-col.is-sunday .mag-week-num{color:#DC2626}
.mag-week-day{font-size:11px;font-weight:800;text-transform:uppercase;color:#475569}
.mag-week-num{font-family:"Outfit",sans-serif;font-size:19px;font-weight:900;color:#1D3B5E;margin:2px 0}
.mag-week-hijri{font-size:10px;font-weight:700;color:#0D7A87}
.mag-week-body{padding:9px;flex:1;display:flex;flex-direction:column;gap:7px}
.mag-week-add{margin-top:auto;padding:6px;border-radius:8px;border:1px dashed #CBD5E1;background:#F8FAFC;color:#1D3B5E;font-size:11px;font-weight:700;cursor:pointer;text-align:center;font-family:inherit}
.mag-week-add:hover{border-color:#0D7A87;color:#0D7A87;background:rgba(13,122,135,0.06)}

/* ===== DAY HERO VIEW ===== */
.mag-day-hero{padding:20px;background:#fff}
.mag-day-card{max-width:740px;margin:0 auto;background:#F8FAFC;border:1px solid #E2E8F0;border-radius:16px;padding:20px}
.mag-day-top{display:flex;justify-content:space-between;align-items:flex-start;gap:12px;flex-wrap:wrap;margin-bottom:16px}
.mag-day-title{font-family:"Outfit",sans-serif;font-size:22px;font-weight:900;color:#1D3B5E;margin:0}
.mag-day-sub{font-size:13px;font-weight:700;color:#0D7A87;margin-top:3px}

/* ===== AGENDA LIST VIEW ===== */
.mag-list-wrap{padding:16px;display:flex;flex-direction:column;gap:10px;background:#fff}
.mag-item{display:flex;align-items:stretch;gap:14px;padding:13px 14px;border-radius:15px;background:#fff;border:1px solid #E2E8F0;transition:all .18s ease;cursor:pointer}
.mag-item:hover{box-shadow:0 10px 22px -10px rgba(15,23,42,.18);transform:translateY(-1px)}
.mag-date-box{width:64px;flex-shrink:0;border-radius:13px;color:#fff;display:flex;flex-direction:column;align-items:center;justify-content:center;padding:8px 6px;text-align:center}
.mag-date-m{font-size:10px;font-weight:800;text-transform:uppercase;opacity:.92}
.mag-date-d{font-family:"Outfit",sans-serif;font-size:22px;font-weight:900;line-height:1.05}
.mag-date-y{font-size:9.5px;opacity:.82}
.mag-item-body{flex:1;min-width:0;display:flex;flex-direction:column;gap:4px}
.mag-item-top{display:flex;align-items:center;justify-content:space-between;gap:8px;flex-wrap:wrap}
.mag-item-title{margin:0;font-family:"Outfit",sans-serif;font-size:14.5px;font-weight:800;color:#0F172A}
.mag-item-desc{font-size:12.5px;color:#475569;line-height:1.45;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
.mag-item-meta{display:flex;flex-wrap:wrap;gap:10px;margin-top:3px;font-size:11.5px;color:#64748B}
.mag-item-meta span{display:inline-flex;align-items:center;gap:4px}
.mag-mini-btn{appearance:none;border:1px solid #CBD5E1;background:#F8FAFC;color:#1D3B5E;border-radius:8px;padding:4px 9px;min-height:30px;font-size:11.5px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:4px;font-family:inherit}
.mag-mini-btn:hover{border-color:#0D7A87;color:#0D7A87;background:#fff}

/* ===== FLOATING DRAG CONFIRMATION BAR (PERSIS CURRICULUM.PHP) ===== */
.mag-drag-bar{position:fixed;bottom:20px;left:50%;transform:translateX(-50%) translateY(30px);background:#0F172A;color:#fff;border:1px solid rgba(255,255,255,0.16);border-radius:16px;padding:12px 16px;box-shadow:0 14px 36px rgba(15,23,42,.45);display:none;align-items:center;gap:9px;z-index:9999;font-size:12.5px;transition:transform .22s ease,opacity .22s ease;opacity:0;max-width:96vw;flex-wrap:wrap}
.mag-drag-bar.show{display:flex;transform:translateX(-50%) translateY(0);opacity:1}
.mag-drag-input{padding:7px 11px;border-radius:9px;border:1px solid rgba(255,255,255,0.22);background:rgba(255,255,255,0.10);color:#fff;font-size:12.5px;font-weight:600;min-width:175px;font-family:inherit;outline:none}
.mag-drag-input::placeholder{color:rgba(255,255,255,0.65)}
.mag-drag-select{padding:7px 10px;border-radius:9px;border:1px solid rgba(255,255,255,0.22);background:#1E293B;color:#fff;font-size:12px;font-weight:700;font-family:inherit;outline:none}
.mag-drag-cat-group{display:inline-flex;align-items:center;gap:5px;flex-wrap:wrap}
.mag-drag-cat-pill{border:none;border-radius:8px;padding:5px 9px;font-size:11px;font-weight:800;color:#fff;cursor:pointer;opacity:.82;transition:all .15s;font-family:inherit}
.mag-drag-cat-pill:hover{opacity:1;transform:translateY(-1px)}
.mag-drag-cat-pill.is-active{opacity:1;outline:2px solid #fff;outline-offset:1.5px;transform:scale(1.05)}
.mag-drag-btn{padding:7px 13px;border-radius:9px;border:none;font-size:12px;font-weight:800;cursor:pointer;display:inline-flex;align-items:center;gap:5px;font-family:inherit;transition:all .15s}
.mag-drag-btn--save{background:#0D7A87;color:#fff}
.mag-drag-btn--save:hover{background:#52B2DC;color:#0F172A}
.mag-drag-btn--detail{background:rgba(82,178,220,0.22);color:#fff;border:1px solid rgba(82,178,220,0.5)}
.mag-drag-btn--cancel{background:rgba(255,255,255,0.12);color:#fff}

/* ===== MODAL FORM FIELDS, SCOPE & COLOR SWATCH PICKER ===== */
.mag-field{display:flex;flex-direction:column;gap:4px;margin-bottom:10px}
.mag-field label{font-size:12px;font-weight:700;color:#1D3B5E}
.mag-input,.mag-textarea{width:100%;padding:9px 11px;min-height:40px;border:1.5px solid #CBD5E1;border-radius:10px;font-size:13px;font-family:inherit;color:#0F172A;background:#fff}
.mag-input:focus,.mag-textarea:focus{outline:none;border-color:#0D7A87;box-shadow:0 0 0 3px rgba(13,122,135,0.12)}
.mag-textarea{min-height:76px;resize:vertical}
.mag-grid-2{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}
.mag-scope-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px}
.mag-scope-card{display:flex;align-items:center;gap:9px;padding:9px 11px;border-radius:11px;border:1.5px solid #E2E8F0;background:#F8FAFC;cursor:pointer;transition:all .15s;text-align:left;font-family:inherit}
.mag-scope-card:hover{border-color:#52B2DC;background:#fff}
.mag-scope-card.is-active{border-color:#0D7A87;background:rgba(13,122,135,0.08);box-shadow:0 0 0 1px #0D7A87}
.mag-scope-card strong{display:block;font-size:12px;color:#1D3B5E;font-weight:800}
.mag-scope-card small{display:block;font-size:10.5px;color:#64748B;margin-top:1px}
.mag-color-picker{display:flex;align-items:center;gap:7px;flex-wrap:wrap;margin-top:3px}
.mag-color-dot-btn{width:26px;height:26px;border-radius:8px;border:2px solid transparent;cursor:pointer;transition:transform .14s,box-shadow .14s;display:inline-flex;align-items:center;justify-content:center;color:#fff;font-size:13px}
.mag-color-dot-btn:hover{transform:scale(1.1)}
.mag-color-dot-btn.is-active{border-color:#0F172A;box-shadow:0 0 0 2px #fff inset;transform:scale(1.12)}
.mag-dur-row{display:flex;gap:6px;flex-wrap:wrap;margin-top:4px}
.mag-dur-btn{border:1px solid #CBD5E1;background:#F8FAFC;color:#1D3B5E;font-size:11px;font-weight:700;padding:3px 9px;border-radius:6px;cursor:pointer;font-family:inherit}
.mag-dur-btn:hover{background:#0D7A87;color:#fff;border-color:#0D7A87}
.mag-lock-banner{display:flex;align-items:flex-start;gap:10px;padding:11px 13px;border-radius:12px;background:rgba(29,59,94,0.07);border:1px solid rgba(29,59,94,0.22);color:#1D3B5E;font-size:12.5px;line-height:1.45;margin-bottom:12px}
.mag-peer-box{border:1.5px solid #CBD5E1;border-radius:11px;background:#F8FAFC;padding:9px;margin-top:6px}
.mag-peer-search{width:100%;padding:7px 10px;border:1px solid #CBD5E1;border-radius:8px;font-size:12.5px;background:#fff;margin-bottom:7px;font-family:inherit;outline:none}
.mag-peer-search:focus{border-color:#0D7A87}
.mag-peer-list{max-height:165px;overflow-y:auto;display:flex;flex-direction:column;gap:4px}
.mag-peer-item{display:flex;align-items:center;gap:8px;padding:6px 8px;border-radius:8px;background:#fff;border:1px solid #E2E8F0;cursor:pointer;font-size:12.5px;color:#0F172A;transition:border-color .14s}
.mag-peer-item:hover{border-color:#0D7A87}
.mag-peer-item input[type=checkbox]{accent-color:#0D7A87;width:15px;height:15px;flex-shrink:0}
.mag-peer-sub{font-size:11px;color:#64748B;margin-left:auto;white-space:nowrap}
.mag-peer-count{font-size:11.5px;font-weight:700;color:#0D7A87;margin-top:6px}
.mag-group-members{margin-top:6px;padding:8px 10px;border-radius:9px;background:#F5F3FF;border:1px solid #DDD6FE;font-size:12px;color:#5B21B6;line-height:1.45}
@media(max-width:600px){.mag-grid-2,.mag-scope-grid{grid-template-columns:1fr}.mag-item{flex-direction:column}.mag-date-box{width:100%;flex-direction:row;gap:8px;justify-content:flex-start;padding:8px 12px}}
</style>';

require_once BASE_PATH . '/includes/header.php';
?>

<!-- TOOLBAR FILTER & PENCARIAN (DATA-FIRST) -->
<div class="ilmuna-filter-box" style="margin-bottom:14px;">
    <div class="ilmuna-filter-topline">
        <div class="ilmuna-pill-group" id="agendaScopePills">
            <button type="button" class="ilmuna-pill-btn is-active" data-scope="all">Semua</button>
            <button type="button" class="ilmuna-pill-btn" data-scope="kaldik">Kaldik (Semua &amp; KBM)</button>
            <button type="button" class="ilmuna-pill-btn" data-scope="admin">Ekosistem / Rapat</button>
            <?php if ($isGuru || !empty($userCtx['siswa_kelas_id'])): ?>
                <button type="button" class="ilmuna-pill-btn" data-scope="kelas">Kelas &amp; Mapel</button>
            <?php endif; ?>
            <?php if ($isGuru || $isAdmin): ?>
                <button type="button" class="ilmuna-pill-btn" data-scope="peer_guru">Sesama Guru</button>
            <?php endif; ?>
            <?php if ($isSiswa): ?>
                <button type="button" class="ilmuna-pill-btn" data-scope="peer_siswa">Sesama Siswa</button>
                <button type="button" class="ilmuna-pill-btn" data-scope="kelompok">Kelompok</button>
            <?php endif; ?>
            <button type="button" class="ilmuna-pill-btn" data-scope="private">Pribadi Saya</button>
        </div>
        <div class="ilmuna-search-wrap">
            <iconify-icon icon="solar:magnifer-linear"></iconify-icon>
            <input type="text" id="agendaSearchInput" class="ilmuna-search-input" placeholder="Cari KBM, rapat, ujian, libur, kelas...">
        </div>
        <div class="ilmuna-filter-actions">
            <?php if ($isAdmin): ?>
                <button type="button" class="ilmuna-nav-btn ilmuna-nav-btn--ghost" id="btnSyncKaldikTop" onclick="MARGO_AGENDA.syncKaldik()">
                    <iconify-icon icon="solar:refresh-circle-bold" width="16"></iconify-icon>
                    <span>Sinkron Kaldik</span>
                </button>
            <?php endif; ?>
            <button type="button" class="ilmuna-nav-btn ilmuna-nav-btn--primary" onclick="MARGO_AGENDA.openFormModal(null)">
                <iconify-icon icon="solar:calendar-add-bold" width="17"></iconify-icon>
                <span>Tambah Agenda</span>
            </button>
        </div>
    </div>
</div>

<!-- QUICK PAINTER & PALET WARNA KEGIATAN (PERSIS CURRICULUM.PHP) -->
<div class="mag-painter-card">
    <div class="mag-painter-left">
        <button type="button" class="mag-swatch-btn" id="btnToggleKuas" onclick="MARGO_AGENDA.toggleKuas()">
            <iconify-icon icon="solar:paint-roller-bold" width="16"></iconify-icon>
            <span id="lblToggleKuas">Mode Kuas: OFF</span>
        </button>
        <div class="mag-swatch-group" id="magCatSwatches">
            <button type="button" class="mag-swatch-btn mag-swatch--akademik is-selected" data-cat="akademik" data-color="#0D7A87" onclick="MARGO_AGENDA.pickBrushCat('akademik', '#0D7A87', this)">
                <span class="mag-swatch-dot" style="background:#0D7A87;"></span> Akademik
            </button>
            <button type="button" class="mag-swatch-btn mag-swatch--rapat" data-cat="rapat" data-color="#4F46E5" onclick="MARGO_AGENDA.pickBrushCat('rapat', '#4F46E5', this)">
                <span class="mag-swatch-dot" style="background:#4F46E5;"></span> Rapat
            </button>
            <button type="button" class="mag-swatch-btn mag-swatch--ujian" data-cat="ujian" data-color="#F59E0B" onclick="MARGO_AGENDA.pickBrushCat('ujian', '#F59E0B', this)">
                <span class="mag-swatch-dot" style="background:#F59E0B;"></span> Ujian
            </button>
            <button type="button" class="mag-swatch-btn mag-swatch--kegiatan" data-cat="kegiatan" data-color="#EA580C" onclick="MARGO_AGENDA.pickBrushCat('kegiatan', '#EA580C', this)">
                <span class="mag-swatch-dot" style="background:#EA580C;"></span> Kegiatan
            </button>
            <button type="button" class="mag-swatch-btn mag-swatch--sistem" data-cat="sistem" data-color="#1D3B5E" onclick="MARGO_AGENDA.pickBrushCat('sistem', '#1D3B5E', this)">
                <span class="mag-swatch-dot" style="background:#1D3B5E;"></span> Sistem SSO
            </button>
            <button type="button" class="mag-swatch-btn mag-swatch--pengumuman" data-cat="pengumuman" data-color="#0284C7" onclick="MARGO_AGENDA.pickBrushCat('pengumuman', '#0284C7', this)">
                <span class="mag-swatch-dot" style="background:#0284C7;"></span> Pengumuman
            </button>
            <button type="button" class="mag-swatch-btn mag-swatch--pribadi" data-cat="pribadi" data-color="#DB2777" onclick="MARGO_AGENDA.pickBrushCat('pribadi', '#DB2777', this)">
                <span class="mag-swatch-dot" style="background:#DB2777;"></span> Pribadi
            </button>
        </div>
    </div>
    <div class="mag-role-hint">
        <iconify-icon icon="solar:hand-stars-bold" width="16" style="color:#0D7A87;"></iconify-icon>
        <?php if ($isAdmin): ?>
            <span><strong>Tip Drag:</strong> Seret tanggal untuk buat agenda <strong>Semua / Guru / Siswa / Sesama Guru</strong>. Kaldik tersinkron utuh &amp; terkunci.</span>
        <?php elseif ($isGuru): ?>
            <span><strong>Tip Drag:</strong> Seret tanggal untuk buat agenda <strong>Kelas &amp; Mapel (atau Semua Kelas)</strong>, <strong>Sesama Guru</strong>, atau <strong>Diri Sendiri</strong>.</span>
        <?php else: ?>
            <span><strong>Tip Drag:</strong> Seret tanggal untuk buat agenda <strong>Pribadi</strong>, <strong>Sesama Siswa</strong><?= !empty($userCtx['ketua_kelompok']) ? ', atau <strong>Kelompok Tugas/Proyek</strong> (Ketua Kelompok)' : '' ?>.</span>
        <?php endif; ?>
    </div>
</div>

<!-- MAIN INTERACTIVE RBC CALENDAR CARD -->
<div class="mag-rbc-card" id="magRbcCard">
    <div class="mag-rbc-toolbar">
        <div class="mag-rbc-left">
            <div class="mag-rbc-icon"><iconify-icon icon="solar:calendar-date-bold"></iconify-icon></div>
            <div>
                <h3 class="mag-rbc-title" id="calMainTitle">Memuat...</h3>
                <div class="mag-rbc-hijri" id="calHijriTitle">
                    <iconify-icon icon="solar:moon-stars-bold" width="13"></iconify-icon>
                    <span>Kalender Hijriyah</span>
                </div>
            </div>
        </div>
        <div class="mag-nav-group">
            <button type="button" class="mag-nav-btn" onclick="MARGO_AGENDA.navigate(-1)">
                <iconify-icon icon="solar:alt-arrow-left-bold"></iconify-icon> Sebelumnya
            </button>
            <button type="button" class="mag-nav-btn" onclick="MARGO_AGENDA.goToday()">Hari Ini</button>
            <button type="button" class="mag-nav-btn" onclick="MARGO_AGENDA.navigate(1)">
                Berikutnya <iconify-icon icon="solar:alt-arrow-right-bold"></iconify-icon>
            </button>
        </div>
        <div class="mag-view-group" id="magViewBtns">
            <button type="button" class="mag-view-btn is-active" data-view="month" onclick="MARGO_AGENDA.setView('month')">
                <iconify-icon icon="solar:calendar-bold"></iconify-icon> Bulan
            </button>
            <button type="button" class="mag-view-btn" data-view="week" onclick="MARGO_AGENDA.setView('week')">
                <iconify-icon icon="solar:calendar-minimalistic-bold"></iconify-icon> Minggu
            </button>
            <button type="button" class="mag-view-btn" data-view="day" onclick="MARGO_AGENDA.setView('day')">
                <iconify-icon icon="solar:sun-2-bold"></iconify-icon> Hari
            </button>
            <button type="button" class="mag-view-btn" data-view="agenda" onclick="MARGO_AGENDA.setView('agenda')">
                <iconify-icon icon="solar:list-bold"></iconify-icon> Daftar
            </button>
        </div>
    </div>

    <!-- LEGEND WARNA LENGKAP (KALDIK + KEGIATAN/RAPAT) & DB STATUS -->
    <div class="mag-subbar">
        <div class="mag-legend-row">
            <div class="mag-legend-grp">
                <span class="mag-legend-title"><iconify-icon icon="solar:lock-keyhole-bold" style="vertical-align:middle;"></iconify-icon> Kaldik:</span>
                <span class="mag-legend-item"><span class="mag-legend-swatch" style="background:#10B981;"></span> KBM Efektif (Hijau)</span>
                <span class="mag-legend-item"><span class="mag-legend-swatch" style="background:#EF4444;"></span> Libur Nasional (Merah)</span>
                <span class="mag-legend-item"><span class="mag-legend-swatch" style="background:#64748B;"></span> Libur Semester/Pekan (Abu)</span>
                <span class="mag-legend-item"><span class="mag-legend-swatch" style="background:#F59E0B;"></span> Ujian/Asesmen (Kuning)</span>
                <span class="mag-legend-item"><span class="mag-legend-swatch" style="background:#EA580C;"></span> Kegiatan Sekolah (Oranye)</span>
                <span class="mag-legend-item"><span class="mag-legend-swatch" style="background:#8B5CF6;"></span> Keagamaan (Ungu)</span>
            </div>
            <div class="mag-legend-grp">
                <span class="mag-legend-title">Agenda:</span>
                <span class="mag-legend-item"><span class="mag-legend-swatch" style="background:#0D7A87;"></span> Akademik</span>
                <span class="mag-legend-item"><span class="mag-legend-swatch" style="background:#4F46E5;"></span> Rapat</span>
                <span class="mag-legend-item"><span class="mag-legend-swatch" style="background:#F59E0B;"></span> Ujian</span>
                <span class="mag-legend-item"><span class="mag-legend-swatch" style="background:#EA580C;"></span> Kegiatan</span>
                <span class="mag-legend-item"><span class="mag-legend-swatch" style="background:#1D3B5E;"></span> Sistem</span>
                <span class="mag-legend-item"><span class="mag-legend-swatch" style="background:#0284C7;"></span> Pengumuman/Kelas</span>
                <span class="mag-legend-item"><span class="mag-legend-swatch" style="background:#DB2777;"></span> Pribadi</span>
            </div>
        </div>
        <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
            <span class="mag-db-pill" id="calCountPill">0 agenda terlihat</span>
            <?php if ($isAdmin && $agendaDbStatus): ?>
                <span class="mag-db-pill" id="calDbStatusPill">
                    <iconify-icon icon="solar:database-bold" style="color:#0D7A87;"></iconify-icon>
                    <span>DB: <?= htmlspecialchars($agendaDbStatus['db_name'], ENT_QUOTES, 'UTF-8') ?> · Trigger Kaldik: <?= !empty($agendaDbStatus['kaldik_triggers_ok']) ? 'AKTIF' : 'SYNC' ?></span>
                </span>
            <?php endif; ?>
        </div>
    </div>

    <!-- DYNAMIC CALENDAR BODY (MONTH / WEEK / DAY / AGENDA LIST) -->
    <div id="magCalBody"></div>
</div>

<!-- FLOATING DRAG CONFIRMATION BAR (MUNCUL SAAT DRAG RENTANG TANGGAL DI KALENDER) -->
<div class="mag-drag-bar" id="magDragBar">
    <div style="display:flex;align-items:center;gap:7px;font-weight:800;">
        <iconify-icon icon="solar:calendar-add-bold" width="18" style="color:#52B2DC;"></iconify-icon>
        <span id="magDragCountLbl">0 tanggal dipilih</span>
    </div>
    <input type="text" id="magDragTitle" class="mag-drag-input" placeholder="Judul agenda / rapat...">
    <div class="mag-drag-cat-group" id="magDragCatGroup">
        <button type="button" class="mag-drag-cat-pill is-active" data-cat="akademik" data-color="#0D7A87" style="background:#0D7A87;" onclick="MARGO_AGENDA.pickDragCat('akademik','#0D7A87',this)">Akademik</button>
        <button type="button" class="mag-drag-cat-pill" data-cat="rapat" data-color="#4F46E5" style="background:#4F46E5;" onclick="MARGO_AGENDA.pickDragCat('rapat','#4F46E5',this)">Rapat</button>
        <button type="button" class="mag-drag-cat-pill" data-cat="ujian" data-color="#F59E0B" style="background:#F59E0B;" onclick="MARGO_AGENDA.pickDragCat('ujian','#F59E0B',this)">Ujian</button>
        <button type="button" class="mag-drag-cat-pill" data-cat="kegiatan" data-color="#EA580C" style="background:#EA580C;" onclick="MARGO_AGENDA.pickDragCat('kegiatan','#EA580C',this)">Kegiatan</button>
        <button type="button" class="mag-drag-cat-pill" data-cat="pengumuman" data-color="#0284C7" style="background:#0284C7;" onclick="MARGO_AGENDA.pickDragCat('pengumuman','#0284C7',this)">Info</button>
        <button type="button" class="mag-drag-cat-pill" data-cat="pribadi" data-color="#DB2777" style="background:#DB2777;" onclick="MARGO_AGENDA.pickDragCat('pribadi','#DB2777',this)">Pribadi</button>
    </div>
    <?php if ($isAdmin): ?>
        <select id="magDragScope" class="mag-drag-select">
            <option value="all">Semua Orang</option>
            <option value="guru">Khusus Guru</option>
            <option value="siswa">Khusus Siswa</option>
            <option value="private">Pribadi Saya</option>
        </select>
    <?php elseif ($isGuru): ?>
        <select id="magDragScope" class="mag-drag-select" onchange="MARGO_AGENDA.onDragScopeChange()">
            <option value="private">Hanya Diri Sendiri</option>
            <?php if (!empty($userCtx['guru_classes'])): ?>
                <option value="kelas">Kelas &amp; Mapel</option>
            <?php endif; ?>
            <option value="peer_guru">Sesama Guru (Form Lengkap)</option>
        </select>
        <?php if (!empty($userCtx['guru_classes'])): ?>
            <select id="magDragKelas" class="mag-drag-select" style="display:none;" onchange="MARGO_AGENDA.onDragKelasChange()">
                <option value="all">Semua Kelas</option>
                <?php foreach ($userCtx['guru_classes'] as $gc): ?>
                    <option value="<?= (int)$gc['id'] ?>"><?= htmlspecialchars($gc['nama_kelas'], ENT_QUOTES, 'UTF-8') ?></option>
                <?php endforeach; ?>
            </select>
            <select id="magDragMapel" class="mag-drag-select" style="display:none;">
                <option value="">-- Pilih Mapel --</option>
            </select>
        <?php endif; ?>
    <?php else: ?>
        <select id="magDragScope" class="mag-drag-select" onchange="MARGO_AGENDA.onDragScopeChange()">
            <option value="private">Pribadi (Saya Saja)</option>
            <option value="peer_siswa">Sesama Siswa (Form Lengkap)</option>
            <?php if (!empty($userCtx['ketua_kelompok'])): ?>
                <option value="kelompok">Kelompok Tugas/Proyek</option>
            <?php endif; ?>
        </select>
        <?php if (!empty($userCtx['ketua_kelompok'])): ?>
            <select id="magDragKelompok" class="mag-drag-select" style="display:none;">
                <?php foreach ($userCtx['ketua_kelompok'] as $kk): ?>
                    <option value="<?= htmlspecialchars($kk['group_key'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($kk['nama_kelompok'] . ' · ' . $kk['judul_induk'], ENT_QUOTES, 'UTF-8') ?></option>
                <?php endforeach; ?>
            </select>
        <?php endif; ?>
    <?php endif; ?>
    <button type="button" class="mag-drag-btn mag-drag-btn--save" onclick="MARGO_AGENDA.submitDragQuick()">
        <iconify-icon icon="solar:diskette-bold"></iconify-icon> Simpan Cepat
    </button>
    <button type="button" class="mag-drag-btn mag-drag-btn--detail" onclick="MARGO_AGENDA.openModalFromDrag()">
        <iconify-icon icon="solar:pen-new-square-bold"></iconify-icon> Form Lengkap
    </button>
    <button type="button" class="mag-drag-btn mag-drag-btn--cancel" onclick="MARGO_AGENDA.cancelDrag()">Batal</button>
</div>

<!-- MODAL DETAIL AGENDA -->
<div class="ilm-modal-backdrop" id="modalAgendaDetail">
    <div class="ilm-modal-dialog" style="max-width:560px;">
        <div class="ilm-modal-header">
            <div class="ilm-modal-header__brand">
                <div class="ilm-modal-header__icon" id="detAgIcon"><iconify-icon icon="solar:calendar-mark-bold"></iconify-icon></div>
                <div>
                    <h3 class="ilm-modal-header__title" id="detAgTitle">Detail Agenda</h3>
                    <p class="ilm-modal-header__subtitle" id="detAgDate">-</p>
                </div>
            </div>
            <button type="button" class="ilm-modal-header__close" onclick="closeIlmunaModal('modalAgendaDetail')">
                <iconify-icon icon="solar:close-circle-bold"></iconify-icon>
            </button>
        </div>
        <div class="ilm-modal-body" id="detAgBody"></div>
        <div class="ilm-modal-footer" id="detAgFooter">
            <button type="button" class="ilm-modal-btn ilm-modal-btn--ghost" onclick="closeIlmunaModal('modalAgendaDetail')">Tutup</button>
        </div>
    </div>
</div>

<!-- MODAL TAMBAH / EDIT AGENDA (ADMIN, GURU & SISWA) -->
<div class="ilm-modal-backdrop" id="modalAgendaForm">
    <div class="ilm-modal-dialog" style="max-width:590px;">
        <div class="ilm-modal-header">
            <div class="ilm-modal-header__brand">
                <div class="ilm-modal-header__icon"><iconify-icon icon="solar:calendar-add-bold"></iconify-icon></div>
                <div>
                    <h3 class="ilm-modal-header__title" id="frmAgHeading">Tambah Agenda Baru</h3>
                    <p class="ilm-modal-header__subtitle" id="frmAgSub">Tersimpan di database agenda &amp; langsung tampil berwarna di kalender</p>
                </div>
            </div>
            <button type="button" class="ilm-modal-header__close" onclick="closeIlmunaModal('modalAgendaForm')">
                <iconify-icon icon="solar:close-circle-bold"></iconify-icon>
            </button>
        </div>
        <form id="formAgendaUniversal" onsubmit="MARGO_AGENDA.saveAgenda(event)">
            <input type="hidden" id="agId" value="0">
            <input type="hidden" id="agScope" value="<?= $isAdmin ? 'all' : 'private' ?>">
            <input type="hidden" id="agColorHex" value="#0D7A87">
            <div class="ilm-modal-body" style="max-height:calc(85vh - 150px);overflow-y:auto;">
                <div class="mag-field">
                    <label for="agTitle">Judul Agenda</label>
                    <input type="text" id="agTitle" class="mag-input" placeholder="Contoh: Rapat Dewan Guru / Persiapan Ujian / Belajar Mandiri" required>
                </div>

                <!-- TARGET AUDIENS BERBASIS PERAN -->
                <div class="mag-field">
                    <label>Siapa yang Dapat Mengetahui Agenda Ini?</label>
                    <?php if ($isAdmin): ?>
                        <div class="mag-scope-grid" id="agScopePicker">
                            <button type="button" class="mag-scope-card is-active" data-val="all" onclick="MARGO_AGENDA.selectModalScope('all')">
                                <iconify-icon icon="solar:users-group-rounded-bold" width="20" style="color:#0D7A87;"></iconify-icon>
                                <div><strong>Semua Orang</strong><small>Admin, Guru &amp; Siswa</small></div>
                            </button>
                            <button type="button" class="mag-scope-card" data-val="guru" onclick="MARGO_AGENDA.selectModalScope('guru')">
                                <iconify-icon icon="solar:user-speak-bold" width="20" style="color:#4F46E5;"></iconify-icon>
                                <div><strong>Khusus Guru</strong><small>Hanya Dewan Guru</small></div>
                            </button>
                            <button type="button" class="mag-scope-card" data-val="siswa" onclick="MARGO_AGENDA.selectModalScope('siswa')">
                                <iconify-icon icon="solar:square-academic-cap-2-bold" width="20" style="color:#0284C7;"></iconify-icon>
                                <div><strong>Khusus Siswa</strong><small>Siswa &amp; Ketua Kelas</small></div>
                            </button>
                            <button type="button" class="mag-scope-card" data-val="peer_guru" onclick="MARGO_AGENDA.selectModalScope('peer_guru')">
                                <iconify-icon icon="solar:users-group-two-rounded-bold" width="20" style="color:#6366F1;"></iconify-icon>
                                <div><strong>Guru Tertentu</strong><small>Pilih guru spesifik</small></div>
                            </button>
                            <?php if (!empty($userCtx['guru_classes'])): ?>
                                <button type="button" class="mag-scope-card" data-val="kelas" onclick="MARGO_AGENDA.selectModalScope('kelas')">
                                    <iconify-icon icon="solar:buildings-2-bold" width="20" style="color:#0284C7;"></iconify-icon>
                                    <div><strong>Kelas &amp; Mapel</strong><small>Pilih kelas &amp; mapel</small></div>
                                </button>
                            <?php endif; ?>
                            <button type="button" class="mag-scope-card" data-val="private" onclick="MARGO_AGENDA.selectModalScope('private')">
                                <iconify-icon icon="solar:lock-keyhole-minimalistic-bold" width="20" style="color:#DB2777;"></iconify-icon>
                                <div><strong>Pribadi Admin</strong><small>Hanya Diri Sendiri</small></div>
                            </button>
                        </div>
                    <?php elseif ($isGuru): ?>
                        <div class="mag-scope-grid" id="agScopePicker">
                            <button type="button" class="mag-scope-card is-active" data-val="private" onclick="MARGO_AGENDA.selectModalScope('private')">
                                <iconify-icon icon="solar:lock-keyhole-minimalistic-bold" width="20" style="color:#DB2777;"></iconify-icon>
                                <div><strong>Diri Sendiri</strong><small>Hanya terlihat oleh Anda</small></div>
                            </button>
                            <button type="button" class="mag-scope-card" data-val="kelas" onclick="MARGO_AGENDA.selectModalScope('kelas')" <?= empty($userCtx['guru_classes']) ? 'disabled style="opacity:.55;cursor:not-allowed;"' : '' ?>>
                                <iconify-icon icon="solar:buildings-2-bold" width="20" style="color:#0284C7;"></iconify-icon>
                                <div><strong>Kelas &amp; Mapel</strong><small><?= !empty($userCtx['guru_classes']) ? (count($userCtx['guru_classes']) . ' rombel / Semua Kelas') : 'Belum ada kelas diampu' ?></small></div>
                            </button>
                            <button type="button" class="mag-scope-card" data-val="peer_guru" onclick="MARGO_AGENDA.selectModalScope('peer_guru')">
                                <iconify-icon icon="solar:user-speak-bold" width="20" style="color:#4F46E5;"></iconify-icon>
                                <div><strong>Sesama Guru</strong><small>Undang guru tertentu + notif</small></div>
                            </button>
                        </div>
                    <?php else: ?>
                        <div class="mag-scope-grid" id="agScopePicker">
                            <button type="button" class="mag-scope-card is-active" data-val="private" onclick="MARGO_AGENDA.selectModalScope('private')">
                                <iconify-icon icon="solar:lock-keyhole-minimalistic-bold" width="20" style="color:#DB2777;"></iconify-icon>
                                <div><strong>Diri Sendiri</strong><small>Hanya terlihat oleh Anda</small></div>
                            </button>
                            <button type="button" class="mag-scope-card" data-val="peer_siswa" onclick="MARGO_AGENDA.selectModalScope('peer_siswa')">
                                <iconify-icon icon="solar:users-group-two-rounded-bold" width="20" style="color:#0D7A87;"></iconify-icon>
                                <div><strong>Sesama Siswa</strong><small>Undang teman + notifikasi</small></div>
                            </button>
                            <?php if (!empty($userCtx['ketua_kelompok'])): ?>
                                <button type="button" class="mag-scope-card" data-val="kelompok" onclick="MARGO_AGENDA.selectModalScope('kelompok')">
                                    <iconify-icon icon="solar:users-group-rounded-bold" width="20" style="color:#8B5CF6;"></iconify-icon>
                                    <div><strong>Kelompok Tugas / Proyek</strong><small>Khusus Ketua (<?= count($userCtx['ketua_kelompok']) ?> kelompok)</small></div>
                                </button>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($isGuru || $isAdmin): ?>
                        <!-- STEP 1 & 2: PILIH KELAS (ATAU SEMUA KELAS) -> PILIH MAPEL -->
                        <div id="agKelasWrap" style="display:none;margin-top:8px;">
                            <div class="mag-grid-2">
                                <div class="mag-field" style="margin-bottom:0;">
                                    <label for="agKelasId">1. Pilih Kelas</label>
                                    <select id="agKelasId" class="mag-input" onchange="MARGO_AGENDA.onModalKelasChange()">
                                        <option value="">-- Pilih Kelas --</option>
                                        <option value="all">Semua Kelas yang Diampu (<?= count($userCtx['guru_classes'] ?? []) ?> Rombel)</option>
                                        <?php foreach (($userCtx['guru_classes'] ?? []) as $gc): ?>
                                            <option value="<?= (int)$gc['id'] ?>"><?= htmlspecialchars($gc['nama_kelas'], ENT_QUOTES, 'UTF-8') ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="mag-field" style="margin-bottom:0;">
                                    <label for="agMapelId">2. Pilih Mata Pelajaran</label>
                                    <select id="agMapelId" class="mag-input">
                                        <option value="">-- Pilih Kelas Dahulu --</option>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <!-- PILIH SESAMA GURU (MULTI-SELECT) -->
                        <div id="agPeerGuruWrap" style="display:none;margin-top:8px;">
                            <label style="font-size:12px;font-weight:700;color:#1D3B5E;">Pilih Guru Penerima Agenda (Akan Mendapat Notifikasi)</label>
                            <div class="mag-peer-box">
                                <input type="text" class="mag-peer-search" id="agPeerGuruSearch" placeholder="Cari nama guru atau NIP..." oninput="MARGO_AGENDA.filterPeerChecks('agPeerGuruList', this.value)">
                                <div class="mag-peer-list" id="agPeerGuruList">
                                    <?php if (empty($userCtx['peers_guru'])): ?>
                                        <div style="font-size:12px;color:#64748B;padding:8px;text-align:center;">Belum ada akun sesama guru yang terdaftar di SSO.</div>
                                    <?php else: ?>
                                        <?php foreach ($userCtx['peers_guru'] as $pg): ?>
                                            <label class="mag-peer-item" data-hay="<?= htmlspecialchars(strtolower($pg['full_name'] . ' ' . ($pg['nip'] ?? '') . ' ' . ($pg['kode_guru'] ?? '')), ENT_QUOTES, 'UTF-8') ?>">
                                                <input type="checkbox" name="peer_guru_ids[]" value="<?= (int)$pg['user_id'] ?>" onchange="MARGO_AGENDA.updatePeerCount('agPeerGuruList','agPeerGuruCount')">
                                                <span><?= htmlspecialchars($pg['full_name'], ENT_QUOTES, 'UTF-8') ?></span>
                                                <?php if (!empty($pg['nip'])): ?>
                                                    <span class="mag-peer-sub">NIP <?= htmlspecialchars($pg['nip'], ENT_QUOTES, 'UTF-8') ?></span>
                                                <?php endif; ?>
                                            </label>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                                <div class="mag-peer-count" id="agPeerGuruCount">0 guru dipilih</div>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if ($isSiswa): ?>
                        <!-- PILIH SESAMA SISWA (MULTI-SELECT) -->
                        <div id="agPeerSiswaWrap" style="display:none;margin-top:8px;">
                            <label style="font-size:12px;font-weight:700;color:#1D3B5E;">Pilih Sesama Siswa Penerima Agenda (Akan Mendapat Notifikasi)</label>
                            <div class="mag-peer-box">
                                <input type="text" class="mag-peer-search" id="agPeerSiswaSearch" placeholder="Cari nama siswa atau kelas..." oninput="MARGO_AGENDA.filterPeerChecks('agPeerSiswaList', this.value)">
                                <div class="mag-peer-list" id="agPeerSiswaList">
                                    <?php if (empty($userCtx['peers_siswa'])): ?>
                                        <div style="font-size:12px;color:#64748B;padding:8px;text-align:center;">Belum ada akun sesama siswa yang terdaftar di SSO.</div>
                                    <?php else: ?>
                                        <?php foreach ($userCtx['peers_siswa'] as $ps): ?>
                                            <label class="mag-peer-item" data-hay="<?= htmlspecialchars(strtolower($ps['full_name'] . ' ' . ($ps['nama_kelas'] ?? '') . ' ' . ($ps['nis'] ?? '')), ENT_QUOTES, 'UTF-8') ?>">
                                                <input type="checkbox" name="peer_siswa_ids[]" value="<?= (int)$ps['user_id'] ?>" onchange="MARGO_AGENDA.updatePeerCount('agPeerSiswaList','agPeerSiswaCount')">
                                                <span><?= htmlspecialchars($ps['full_name'], ENT_QUOTES, 'UTF-8') ?></span>
                                                <span class="mag-peer-sub"><?= htmlspecialchars(($ps['same_class'] ? 'Sekelas · ' : '') . ($ps['nama_kelas'] ?: ($ps['nis'] ?: 'Siswa')), ENT_QUOTES, 'UTF-8') ?></span>
                                            </label>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                                <div class="mag-peer-count" id="agPeerSiswaCount">0 siswa dipilih</div>
                            </div>
                        </div>

                        <?php if (!empty($userCtx['ketua_kelompok'])): ?>
                            <!-- PILIH KELOMPOK TUGAS / PROYEK (KHUSUS KETUA KELOMPOK) -->
                            <div id="agKelompokWrap" style="display:none;margin-top:8px;">
                                <label for="agGroupKey" style="font-size:12px;font-weight:700;color:#1D3B5E;">Pilih Kelompok Tugas / Proyek yang Anda Pimpin</label>
                                <select id="agGroupKey" class="mag-input" onchange="MARGO_AGENDA.onModalGroupChange()">
                                    <option value="">-- Pilih Kelompok --</option>
                                    <?php foreach ($userCtx['ketua_kelompok'] as $kk): ?>
                                        <option value="<?= htmlspecialchars($kk['group_key'], ENT_QUOTES, 'UTF-8') ?>">
                                            <?= htmlspecialchars('[' . strtoupper($kk['sumber']) . '] ' . $kk['nama_kelompok'] . ' — ' . $kk['judul_induk'] . (!empty($kk['nama_mapel']) ? ' (' . $kk['nama_mapel'] . ')' : ''), ENT_QUOTES, 'UTF-8') ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div id="agGroupMembersPreview" class="mag-group-members" style="display:none;"></div>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>

                <div class="mag-grid-2">
                    <div class="mag-field">
                        <label for="agStartDate">Tanggal Mulai</label>
                        <input type="date" id="agStartDate" class="mag-input" required>
                    </div>
                    <div class="mag-field">
                        <label for="agEndDate">Tanggal Selesai</label>
                        <input type="date" id="agEndDate" class="mag-input">
                        <div class="mag-dur-row">
                            <button type="button" class="mag-dur-btn" onclick="MARGO_AGENDA.setQuickDur(0)">1 Hari</button>
                            <button type="button" class="mag-dur-btn" onclick="MARGO_AGENDA.setQuickDur(1)">2 Hari</button>
                            <button type="button" class="mag-dur-btn" onclick="MARGO_AGENDA.setQuickDur(2)">3 Hari</button>
                            <button type="button" class="mag-dur-btn" onclick="MARGO_AGENDA.setQuickDur(6)">1 Pekan</button>
                        </div>
                    </div>
                    <div class="mag-field">
                        <label for="agCategory">Kategori Kegiatan</label>
                        <select id="agCategory" class="mag-input" onchange="MARGO_AGENDA.onModalCatChange()">
                            <option value="akademik">Akademik (Teal)</option>
                            <option value="rapat">Rapat / Koordinasi (Indigo)</option>
                            <option value="ujian">Ujian / Asesmen (Kuning Emas)</option>
                            <option value="kegiatan">Kegiatan Sekolah (Oranye)</option>
                            <option value="sistem">Sistem SSO (Navy)</option>
                            <option value="pengumuman">Pengumuman (Biru)</option>
                            <option value="pribadi">Pribadi / Pengingat (Magenta)</option>
                        </select>
                    </div>
                    <div class="mag-field">
                        <label>Warna Label di Kalender</label>
                        <div class="mag-color-picker" id="agColorSwatches">
                            <button type="button" class="mag-color-dot-btn is-active" data-hex="#0D7A87" style="background:#0D7A87;" title="Teal (Akademik)" onclick="MARGO_AGENDA.pickModalColor('#0D7A87')"></button>
                            <button type="button" class="mag-color-dot-btn" data-hex="#4F46E5" style="background:#4F46E5;" title="Indigo (Rapat)" onclick="MARGO_AGENDA.pickModalColor('#4F46E5')"></button>
                            <button type="button" class="mag-color-dot-btn" data-hex="#EA580C" style="background:#EA580C;" title="Oranye (Kegiatan)" onclick="MARGO_AGENDA.pickModalColor('#EA580C')"></button>
                            <button type="button" class="mag-color-dot-btn" data-hex="#1D3B5E" style="background:#1D3B5E;" title="Navy (Sistem)" onclick="MARGO_AGENDA.pickModalColor('#1D3B5E')"></button>
                            <button type="button" class="mag-color-dot-btn" data-hex="#0284C7" style="background:#0284C7;" title="Sky Blue (Pengumuman / Kelas)" onclick="MARGO_AGENDA.pickModalColor('#0284C7')"></button>
                            <button type="button" class="mag-color-dot-btn" data-hex="#DB2777" style="background:#DB2777;" title="Magenta (Pribadi)" onclick="MARGO_AGENDA.pickModalColor('#DB2777')"></button>
                            <button type="button" class="mag-color-dot-btn" data-hex="#F59E0B" style="background:#F59E0B;" title="Kuning Emas (Ujian)" onclick="MARGO_AGENDA.pickModalColor('#F59E0B')"></button>
                            <button type="button" class="mag-color-dot-btn" data-hex="#059669" style="background:#059669;" title="Hijau Zamrud" onclick="MARGO_AGENDA.pickModalColor('#059669')"></button>
                        </div>
                    </div>
                    <div class="mag-field">
                        <label for="agStartTime">Waktu (WIB, opsional)</label>
                        <input type="text" id="agStartTime" class="mag-input" placeholder="08:00">
                    </div>
                    <div class="mag-field">
                        <label for="agLocation">Lokasi / Media (opsional)</label>
                        <input type="text" id="agLocation" class="mag-input" placeholder="Ruang Kelas / Aula / Zoom">
                    </div>
                </div>
                <div class="mag-field">
                    <label for="agLink">Tautan Terkait (opsional)</label>
                    <input type="text" id="agLink" class="mag-input" placeholder="https://learn.ilmuna.my.id">
                </div>
                <div class="mag-field" style="margin-bottom:0;">
                    <label for="agDesc">Catatan / Deskripsi</label>
                    <textarea id="agDesc" class="mag-textarea" placeholder="Detail agenda, perlengkapan, atau informasi penting..."></textarea>
                </div>
            </div>
            <div class="ilm-modal-footer">
                <button type="button" class="ilm-modal-btn ilm-modal-btn--ghost" onclick="closeIlmunaModal('modalAgendaForm')">Batal</button>
                <button type="submit" class="ilm-modal-btn ilm-modal-btn--primary">
                    <iconify-icon icon="solar:diskette-bold"></iconify-icon>
                    <span>Simpan Agenda</span>
                </button>
            </div>
        </form>
    </div>
</div>

<script>
(function () {
    'use strict';
    var API_URL    = <?= json_encode(BASE_URL . '/agenda.php?api=1') ?>;
    var CSRF_TOKEN = <?= json_encode($csrfAgenda) ?>;
    var USER_CTX   = <?= json_encode($userCtx, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    var FOCUS_ID   = <?= (int)$focusId ?>;

    var MONTHS   = ['Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'];
    var DAYS_MON = ['Senin','Selasa','Rabu','Kamis','Jumat','Sabtu','Minggu'];

    var CAT_COLORS = {
        akademik:   '#0D7A87',
        rapat:      '#4F46E5',
        ujian:      '#F59E0B',
        kegiatan:   '#EA580C',
        sistem:     '#1D3B5E',
        pengumuman: '#0284C7',
        pribadi:    '#DB2777'
    };
    var CAT_LABELS = {
        akademik:       'Akademik',
        rapat:          'Rapat',
        ujian:          'Ujian / Asesmen',
        kegiatan:       'Kegiatan',
        sistem:         'Sistem SSO',
        pengumuman:     'Pengumuman',
        pribadi:        'Pribadi',
        kbm:            'Efektif KBM',
        libur_nasional: 'Libur Nasional',
        libur_semester: 'Libur Semester',
        keagamaan:      'Keagamaan',
        kaldik:         'Kaldik'
    };
    var KALDIK_COLORS = {
        'Efektif KBM':            '#10B981',
        'Libur Nasional':         '#EF4444',
        'Libur Semester':         '#64748B',
        'Kegiatan Sekolah/Ujian': '#EA580C',
        'Libur Khusus Keagamaan': '#8B5CF6'
    };

    var now = new Date();
    var STATE = {
        items: <?= json_encode($initialAgendas, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
        view: 'month',
        cursor: new Date(now.getFullYear(), now.getMonth(), now.getDate()),
        scopeFilter: 'all',
        brushCat: 'akademik',
        brushColor: '#0D7A87',
        dragCat: 'akademik',
        dragColor: '#0D7A87',
        kuasMode: false,
        dragging: false,
        dragStart: '',
        dragEnd: '',
        selectedDates: []
    };

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c];
        });
    }
    function pad2(n) { return n < 10 ? '0' + n : '' + n; }
    function toYmd(dt) { return dt.getFullYear() + '-' + pad2(dt.getMonth() + 1) + '-' + pad2(dt.getDate()); }
    function parseYmd(str) {
        var p = String(str || '').split('-');
        if (p.length !== 3) return new Date(now.getFullYear(), now.getMonth(), now.getDate());
        return new Date(Number(p[0]), Number(p[1]) - 1, Number(p[2]));
    }
    function todayStr() { return toYmd(now); }

    function hexToRgb(hex) {
        var h = String(hex || '#0D7A87').replace('#', '');
        if (h.length !== 6) h = '0D7A87';
        return {
            r: parseInt(h.slice(0, 2), 16),
            g: parseInt(h.slice(2, 4), 16),
            b: parseInt(h.slice(4, 6), 16)
        };
    }

    function getEventColor(ag) {
        if (ag && ag.color_hex && /^#[0-9A-Fa-f]{6}$/.test(ag.color_hex)) {
            return ag.color_hex.toUpperCase();
        }
        if (ag && ag.source === 'kaldik') {
            return KALDIK_COLORS[ag.status_hari] || '#10B981';
        }
        return CAT_COLORS[ag && ag.category] || '#0D7A87';
    }

    function chipInlineStyle(ag) {
        var hex = getEventColor(ag);
        var rgb = hexToRgb(hex);
        var darkR = Math.max(0, Math.floor(rgb.r * 0.62));
        var darkG = Math.max(0, Math.floor(rgb.g * 0.62));
        var darkB = Math.max(0, Math.floor(rgb.b * 0.62));
        var borderStyle = (ag.visibility_scope === 'private') ? 'dashed' : 'solid';
        return 'background:rgba(' + rgb.r + ',' + rgb.g + ',' + rgb.b + ',0.14);'
             + 'color:rgb(' + darkR + ',' + darkG + ',' + darkB + ');'
             + 'border-left:3px ' + borderStyle + ' ' + hex + ';';
    }

    // Format Hijriyah dinamis (Umm Al-Qura) dengan fallback aman
    var hijriFmtShort = null;
    var hijriFmtMonth = null;
    try {
        hijriFmtShort = new Intl.DateTimeFormat('id-ID-u-ca-islamic-umalqura', { day: 'numeric', month: 'short' });
        hijriFmtMonth = new Intl.DateTimeFormat('id-ID-u-ca-islamic-umalqura', { month: 'long', year: 'numeric' });
    } catch (e) {}

    function getHijriShort(dt) {
        if (!hijriFmtShort) return '';
        try { return hijriFmtShort.format(dt); } catch (e) { return ''; }
    }
    function getHijriMonth(dt) {
        if (!hijriFmtMonth) return 'Kalender Hijriyah';
        try { return hijriFmtMonth.format(dt); } catch (e) { return 'Kalender Hijriyah'; }
    }

    function expandDatesRange(startStr, endStr) {
        if (!startStr) return [];
        var a = startStr, b = endStr || startStr;
        if (a > b) { var t = a; a = b; b = t; }
        var cur = parseYmd(a), end = parseYmd(b), out = [];
        var guard = 0;
        while (cur <= end && guard < 120) {
            out.push(toYmd(cur));
            cur.setDate(cur.getDate() + 1);
            guard++;
        }
        return out;
    }

    function getFilteredItems() {
        var q = (document.getElementById('agendaSearchInput').value || '').toLowerCase().trim();
        return (STATE.items || []).filter(function (ag) {
            if (STATE.scopeFilter === 'kaldik' && ag.source !== 'kaldik') return false;
            if (STATE.scopeFilter === 'admin' && ag.source !== 'admin') return false;
            if (STATE.scopeFilter === 'kelas' && ag.visibility_scope !== 'kelas' && ag.visibility_scope !== 'semua_kelas') return false;
            if (STATE.scopeFilter === 'peer_guru' && ag.visibility_scope !== 'peer_guru') return false;
            if (STATE.scopeFilter === 'peer_siswa' && ag.visibility_scope !== 'peer_siswa') return false;
            if (STATE.scopeFilter === 'kelompok' && ag.visibility_scope !== 'kelompok') return false;
            if (STATE.scopeFilter === 'private' && ag.visibility_scope !== 'private') return false;
            if (q) {
                var hay = ((ag.title || '') + ' ' + (ag.description || '') + ' ' + (ag.location || '') + ' ' + (ag.visibility_label || '') + ' ' + (ag.target_mapel_name || '') + ' ' + (ag.target_user_names || '') + ' ' + (ag.target_group_name || '') + ' ' + (ag.status_hari || '') + ' ' + (ag.category || '')).toLowerCase();
                if (hay.indexOf(q) === -1) return false;
            }
            return true;
        });
    }

    function buildDateIndex(items) {
        var map = {};
        items.forEach(function (ag) {
            var days = expandDatesRange(ag.start_date, ag.end_date || ag.start_date);
            days.forEach(function (d) {
                if (!map[d]) map[d] = [];
                map[d].push(ag);
            });
        });
        // Urutkan tiap hari: agenda khusus (rapat/kegiatan/kelas/pribadi/libur/ujian) di atas, KBM Reguler tetap tampil di bawahnya
        Object.keys(map).forEach(function (d) {
            map[d].sort(function (a, b) {
                var aRoutine = (a.source === 'kaldik' && a.status_hari === 'Efektif KBM' && a.title === 'KBM Reguler') ? 1 : 0;
                var bRoutine = (b.source === 'kaldik' && b.status_hari === 'Efektif KBM' && b.title === 'KBM Reguler') ? 1 : 0;
                if (aRoutine !== bRoutine) return aRoutine - bRoutine;
                return Number(a.id) - Number(b.id);
            });
        });
        return map;
    }

    function chipIcon(ag) {
        var hex = getEventColor(ag);
        if (ag.source === 'kaldik' || Number(ag.is_locked) === 1) {
            return '<span class="mag-chip-dot" style="background:' + hex + ';"></span><iconify-icon icon="solar:lock-keyhole-bold" width="10" style="flex-shrink:0;opacity:.75;"></iconify-icon>';
        }
        return '<span class="mag-chip-dot" style="background:' + hex + ';"></span>';
    }

    function colorBadgeHtml(label, hex, withLock) {
        var rgb = hexToRgb(hex);
        var darkR = Math.max(0, Math.floor(rgb.r * 0.65));
        var darkG = Math.max(0, Math.floor(rgb.g * 0.65));
        var darkB = Math.max(0, Math.floor(rgb.b * 0.65));
        return '<span class="ilmuna-badge" style="background:rgba(' + rgb.r + ',' + rgb.g + ',' + rgb.b + ',0.14);color:rgb(' + darkR + ',' + darkG + ',' + darkB + ');border:1px solid rgba(' + rgb.r + ',' + rgb.g + ',' + rgb.b + ',0.35);">'
            + (withLock ? '<iconify-icon icon="solar:lock-keyhole-bold"></iconify-icon> ' : '<span style="display:inline-block;width:7px;height:7px;border-radius:50%;background:' + hex + ';margin-right:4px;"></span>')
            + esc(label) + '</span>';
    }

    function scopeBadgeHtml(ag) {
        var hex = getEventColor(ag);
        if (ag.source === 'kaldik' || Number(ag.is_locked) === 1) {
            var learnBadgeMap = {
                'learn_tugas_buat':      'LEARN · Buat Tugas',
                'learn_tugas_deadline':  'LEARN · Deadline Tugas',
                'learn_proyek_buat':     'LEARN · Buat Proyek',
                'learn_proyek_deadline': 'LEARN · Deadline Proyek',
                'learn_asesmen':         'LEARN · Asesmen',
            };
            var lb = learnBadgeMap[ag.source];
            if (lb) {
                return colorBadgeHtml(lb + (ag.target_kelas_name ? ' · Kelas ' + ag.target_kelas_name : ''), hex, true);
            }
            return colorBadgeHtml('KALDIK · ' + (ag.status_hari || 'Terkunci'), hex, true);
        }
        var catLbl = CAT_LABELS[ag.category] || ag.category || 'Agenda';
        return colorBadgeHtml(catLbl, hex, false) + ' <span class="ilmuna-badge ilmuna-badge--navy">' + esc(ag.visibility_label || 'Semua Orang') + '</span>';
    }

    function renderToolbarTitles() {
        var dt = STATE.cursor;
        var titleEl = document.getElementById('calMainTitle');
        var hijriEl = document.getElementById('calHijriTitle');
        if (STATE.view === 'month' || STATE.view === 'agenda') {
            titleEl.textContent = MONTHS[dt.getMonth()] + ' ' + dt.getFullYear();
        } else if (STATE.view === 'week') {
            var mon = getMonday(dt);
            var sun = new Date(mon.getFullYear(), mon.getMonth(), mon.getDate() + 6);
            titleEl.textContent = mon.getDate() + ' ' + MONTHS[mon.getMonth()].slice(0, 3) + ' – ' + sun.getDate() + ' ' + MONTHS[sun.getMonth()].slice(0, 3) + ' ' + sun.getFullYear();
        } else {
            var dName = DAYS_MON[(dt.getDay() + 6) % 7];
            titleEl.textContent = dName + ', ' + dt.getDate() + ' ' + MONTHS[dt.getMonth()] + ' ' + dt.getFullYear();
        }
        hijriEl.innerHTML = '<iconify-icon icon="solar:moon-stars-bold" width="13"></iconify-icon><span>' + esc(getHijriMonth(dt)) + '</span>';
    }

    function getMonday(dt) {
        var d = new Date(dt.getFullYear(), dt.getMonth(), dt.getDate());
        var diff = (d.getDay() + 6) % 7;
        d.setDate(d.getDate() - diff);
        return d;
    }

    function renderMonthView(items) {
        var y = STATE.cursor.getFullYear();
        var m = STATE.cursor.getMonth();
        var first = new Date(y, m, 1);
        var startOffset = (first.getDay() + 6) % 7;
        var daysInMonth = new Date(y, m + 1, 0).getDate();
        var totalCells = Math.ceil((startOffset + daysInMonth) / 7) * 7;
        var startDate = new Date(y, m, 1 - startOffset);
        var dateIdx = buildDateIndex(items);
        var tStr = todayStr();

        var html = '<div class="mag-cal-table-wrap"><table class="mag-cal-table"><thead><tr>';
        DAYS_MON.forEach(function (dn, idx) {
            html += '<th' + (idx === 6 ? ' class="th-sun"' : '') + '>' + dn + '</th>';
        });
        html += '</tr></thead><tbody>';

        for (var i = 0; i < totalCells; i++) {
            if (i % 7 === 0) html += '<tr>';
            var cellDt = new Date(startDate.getFullYear(), startDate.getMonth(), startDate.getDate() + i);
            var dStr = toYmd(cellDt);
            var isOther = (cellDt.getMonth() !== m);
            var isSun = (cellDt.getDay() === 0);
            var cls = 'mag-cell';
            if (isOther) cls += ' is-other';
            if (isSun) cls += ' is-sunday';
            if (dStr === tStr) cls += ' is-today';
            if (STATE.selectedDates.indexOf(dStr) !== -1) cls += ' is-selecting';

            var dayEvs = dateIdx[dStr] || [];
            var cellBgStyle = '';
            if (!isOther && dayEvs.length) {
                var priEv = dayEvs.find(function (ev) { return ev.source === 'kaldik' && ev.category !== 'kbm'; }) || dayEvs[0];
                if (priEv) {
                    var pHex = getEventColor(priEv);
                    var pRgb = hexToRgb(pHex);
                    var alpha = (priEv.category === 'kbm') ? 0.045 : (priEv.category === 'libur_semester' ? 0.04 : 0.085);
                    cellBgStyle = ' style="background:rgba(' + pRgb.r + ',' + pRgb.g + ',' + pRgb.b + ',' + alpha + ');"';
                }
            }
            var chipsHtml = '';
            var shown = dayEvs.slice(0, 3);
            shown.forEach(function (ag) {
                chipsHtml += '<div class="mag-chip" style="' + chipInlineStyle(ag) + '" data-agenda-id="' + ag.id + '" title="' + esc(ag.title + ' · ' + (CAT_LABELS[ag.category] || ag.status_hari || ag.category) + ' (' + (ag.visibility_label || '') + ')') + '">'
                    + chipIcon(ag)
                    + '<span class="mag-chip-title">' + esc(ag.title) + '</span>'
                    + '</div>';
            });
            if (dayEvs.length > 3) {
                chipsHtml += '<span class="mag-chip-more" data-more-date="' + dStr + '">+' + (dayEvs.length - 3) + ' agenda lagi</span>';
            }

            html += '<td class="' + cls + '" data-date="' + dStr + '"' + cellBgStyle + '>'
                + '<div class="mag-cell-top">'
                + '<span class="mag-masehi-num">' + cellDt.getDate() + '</span>'
                + '<span class="mag-hijri-num">' + esc(getHijriShort(cellDt)) + '</span>'
                + '</div>'
                + '<div class="mag-chips-wrap">' + chipsHtml + '</div>'
                + '<span class="mag-cell-add">+</span>'
                + '</td>';
            if (i % 7 === 6) html += '</tr>';
        }
        html += '</tbody></table></div>';
        return html;
    }

    function renderWeekView(items) {
        var mon = getMonday(STATE.cursor);
        var dateIdx = buildDateIndex(items);
        var tStr = todayStr();
        var html = '<div class="mag-week-grid">';
        for (var i = 0; i < 7; i++) {
            var dt = new Date(mon.getFullYear(), mon.getMonth(), mon.getDate() + i);
            var dStr = toYmd(dt);
            var dayEvs = dateIdx[dStr] || [];
            var colCls = 'mag-week-col' + (dStr === tStr ? ' is-today' : '') + (dt.getDay() === 0 ? ' is-sunday' : '');
            html += '<div class="' + colCls + '">'
                + '<div class="mag-week-head">'
                + '<div class="mag-week-day">' + DAYS_MON[i] + '</div>'
                + '<div class="mag-week-num">' + dt.getDate() + '</div>'
                + '<div class="mag-week-hijri">' + esc(getHijriShort(dt)) + '</div>'
                + '</div>'
                + '<div class="mag-week-body">';
            if (dayEvs.length) {
                dayEvs.forEach(function (ag) {
                    html += '<div class="mag-chip" style="' + chipInlineStyle(ag) + 'white-space:normal;padding:6px 8px;flex-direction:column;align-items:flex-start;gap:2px;" onclick="MARGO_AGENDA.openDetail(' + ag.id + ')">'
                        + '<div style="display:flex;align-items:center;gap:5px;width:100%;">' + chipIcon(ag) + '<span style="font-weight:800;">' + esc(ag.title) + '</span></div>'
                        + '<div style="font-size:10px;opacity:.85;">' + esc(ag.status_hari || CAT_LABELS[ag.category] || ag.visibility_label || '') + (ag.start_time ? ' · ' + esc(ag.start_time) : '') + '</div>'
                        + '</div>';
                });
            } else {
                html += '<div style="font-size:11.5px;color:#94A3B8;text-align:center;padding:18px 4px;">Tidak ada agenda</div>';
            }
            html += '<button type="button" class="mag-week-add" onclick="MARGO_AGENDA.openFormForDate(\'' + dStr + '\')">+ Tambah Agenda</button>'
                + '</div></div>';
        }
        html += '</div>';
        return html;
    }

    function renderDayView(items) {
        var dt = STATE.cursor;
        var dStr = toYmd(dt);
        var dateIdx = buildDateIndex(items);
        var dayEvs = dateIdx[dStr] || [];
        var dName = DAYS_MON[(dt.getDay() + 6) % 7];

        var html = '<div class="mag-day-hero"><div class="mag-day-card">'
            + '<div class="mag-day-top">'
            + '<div><h3 class="mag-day-title">' + dName + ', ' + dt.getDate() + ' ' + MONTHS[dt.getMonth()] + ' ' + dt.getFullYear() + '</h3>'
            + '<div class="mag-day-sub">' + esc(getHijriShort(dt) + ' · ' + getHijriMonth(dt)) + '</div></div>'
            + '<button type="button" class="ilmuna-nav-btn ilmuna-nav-btn--primary" onclick="MARGO_AGENDA.openFormForDate(\'' + dStr + '\')"><iconify-icon icon="solar:calendar-add-bold"></iconify-icon> Tambah di Tanggal Ini</button>'
            + '</div>';

        if (!dayEvs.length) {
            html += '<div style="text-align:center;padding:28px 12px;color:#64748B;background:#fff;border-radius:12px;border:1px dashed #CBD5E1;">'
                + '<iconify-icon icon="solar:calendar-minimalistic-bold" width="32" style="color:#94A3B8;"></iconify-icon>'
                + '<div style="font-weight:700;color:#1D3B5E;margin-top:6px;">Belum ada agenda pada tanggal ini</div></div>';
        } else {
            html += '<div style="display:flex;flex-direction:column;gap:10px;">' + dayEvs.map(renderAgendaCardHtml).join('') + '</div>';
        }
        html += '</div></div>';
        return html;
    }

    function renderAgendaCardHtml(ag) {
        var isKaldik = (ag.source === 'kaldik' || Number(ag.is_locked) === 1);
        var hex = getEventColor(ag);
        var actBtns = '';
        if (ag.can_edit && !isKaldik) {
            actBtns = '<div style="display:flex;gap:5px;" onclick="event.stopPropagation()">'
                + '<button type="button" class="mag-mini-btn" onclick="MARGO_AGENDA.openFormModal(' + ag.id + ')"><iconify-icon icon="solar:pen-bold"></iconify-icon> Edit</button>'
                + '<button type="button" class="mag-mini-btn" onclick="MARGO_AGENDA.deleteAgenda(' + ag.id + ')"><iconify-icon icon="solar:trash-bin-minimalistic-bold"></iconify-icon></button>'
                + '</div>';
        }
        return '<article class="mag-item" style="border-left:4px solid ' + hex + ';" onclick="MARGO_AGENDA.openDetail(' + ag.id + ')">'
            + '<div class="mag-date-box" style="background:' + hex + ';">'
            + '<span class="mag-date-m">' + esc(ag.month_short) + '</span>'
            + '<span class="mag-date-d">' + esc(ag.day_num) + '</span>'
            + '<span class="mag-date-y">' + esc(ag.year) + '</span>'
            + '</div>'
            + '<div class="mag-item-body">'
            + '<div class="mag-item-top">'
            + '<h4 class="mag-item-title">' + (isKaldik ? '<iconify-icon icon="solar:lock-keyhole-bold" style="vertical-align:middle;margin-right:4px;color:' + hex + ';"></iconify-icon>' : '') + esc(ag.title) + '</h4>'
            + '<div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;">' + scopeBadgeHtml(ag) + actBtns + '</div>'
            + '</div>'
            + (ag.description ? '<div class="mag-item-desc">' + esc(ag.description) + '</div>' : '')
            + '<div class="mag-item-meta">'
            + '<span><iconify-icon icon="solar:calendar-linear"></iconify-icon> ' + esc(ag.start_date) + (ag.end_date && ag.end_date !== ag.start_date ? ' s/d ' + esc(ag.end_date) : '') + '</span>'
            + (ag.start_time ? '<span><iconify-icon icon="solar:clock-circle-linear"></iconify-icon> ' + esc(ag.start_time) + ' WIB</span>' : '')
            + (ag.location ? '<span><iconify-icon icon="solar:map-point-linear"></iconify-icon> ' + esc(ag.location) + '</span>' : '')
            + (ag.created_by_name ? '<span><iconify-icon icon="solar:user-circle-linear"></iconify-icon> ' + esc(ag.created_by_name) + '</span>' : '')
            + '</div></div></article>';
    }

    function renderAgendaListView(items) {
        if (!items.length) {
            return '<div style="text-align:center;padding:38px 16px;color:#64748B;background:#fff;">'
                + '<iconify-icon icon="solar:calendar-minimalistic-bold" width="38" style="color:#94A3B8;margin-bottom:6px;"></iconify-icon>'
                + '<div style="font-weight:800;color:#1D3B5E;">Tidak ada agenda pada filter ini</div>'
                + '<div style="font-size:12.5px;margin-top:4px;">Ubah kata kunci pencarian atau tambahkan agenda baru.</div></div>';
        }
        return '<div class="mag-list-wrap">' + items.map(renderAgendaCardHtml).join('') + '</div>';
    }

    function renderAll() {
        renderToolbarTitles();
        var items = getFilteredItems();
        var cntPill = document.getElementById('calCountPill');
        if (cntPill) cntPill.textContent = items.length + ' agenda aktif';

        var body = document.getElementById('magCalBody');
        var card = document.getElementById('magRbcCard');
        if (card) card.classList.toggle('mag-is-kuas', STATE.kuasMode);

        if (STATE.view === 'month') {
            body.innerHTML = renderMonthView(items);
            bindMonthCellDragEvents();
        } else if (STATE.view === 'week') {
            body.innerHTML = renderWeekView(items);
        } else if (STATE.view === 'day') {
            body.innerHTML = renderDayView(items);
        } else {
            body.innerHTML = renderAgendaListView(items);
        }
    }

    function highlightDragCells() {
        var set = {};
        STATE.selectedDates.forEach(function (d) { set[d] = true; });
        document.querySelectorAll('#magCalBody .mag-cell').forEach(function (td) {
            td.classList.toggle('is-selecting', !!set[td.getAttribute('data-date')]);
        });
    }

    function syncDragCatPills() {
        document.querySelectorAll('#magDragCatGroup .mag-drag-cat-pill').forEach(function (b) {
            b.classList.toggle('is-active', b.getAttribute('data-cat') === STATE.dragCat);
        });
    }

    function showDragBar() {
        var bar = document.getElementById('magDragBar');
        var lbl = document.getElementById('magDragCountLbl');
        if (!bar || !STATE.selectedDates.length) return;
        var sorted = STATE.selectedDates.slice().sort();
        var rangeTxt = sorted.length === 1 ? sorted[0] : (sorted[0] + ' s/d ' + sorted[sorted.length - 1]);
        lbl.textContent = sorted.length + ' tanggal (' + rangeTxt + ')';
        STATE.dragCat = STATE.brushCat;
        STATE.dragColor = STATE.brushColor;
        syncDragCatPills();
        bar.classList.add('show');
        var inp = document.getElementById('magDragTitle');
        if (inp) setTimeout(function () { inp.focus(); }, 60);
    }

    function hideDragBar() {
        var bar = document.getElementById('magDragBar');
        if (bar) bar.classList.remove('show');
        STATE.selectedDates = [];
        STATE.dragging = false;
        STATE.dragStart = '';
        STATE.dragEnd = '';
        highlightDragCells();
    }

    function bindMonthCellDragEvents() {
        var cells = document.querySelectorAll('#magCalBody .mag-cell');
        cells.forEach(function (td) {
            td.addEventListener('mousedown', function (e) {
                if (e.button !== 0) return;
                if (e.target.closest('.mag-chip') || e.target.closest('.mag-chip-more')) return;
                e.preventDefault();
                var d = td.getAttribute('data-date');
                if (!d) return;
                STATE.dragging = true;
                STATE.dragStart = d;
                STATE.dragEnd = d;
                STATE.selectedDates = [d];
                highlightDragCells();
            });
            td.addEventListener('mouseenter', function () {
                if (!STATE.dragging) return;
                var d = td.getAttribute('data-date');
                if (!d) return;
                STATE.dragEnd = d;
                STATE.selectedDates = expandDatesRange(STATE.dragStart, STATE.dragEnd);
                highlightDragCells();
            });
        });

        document.querySelectorAll('#magCalBody .mag-chip').forEach(function (ch) {
            ch.addEventListener('click', function (e) {
                e.stopPropagation();
                var id = Number(ch.getAttribute('data-agenda-id') || 0);
                if (id > 0) MARGO_AGENDA.openDetail(id);
            });
        });

        document.querySelectorAll('#magCalBody .mag-chip-more').forEach(function (mBtn) {
            mBtn.addEventListener('click', function (e) {
                e.stopPropagation();
                var d = mBtn.getAttribute('data-more-date');
                if (d) {
                    STATE.cursor = parseYmd(d);
                    MARGO_AGENDA.setView('day');
                }
            });
        });
    }

    document.addEventListener('mouseup', function () {
        if (!STATE.dragging) return;
        STATE.dragging = false;
        if (!STATE.selectedDates.length) return;
        if (STATE.selectedDates.length === 1 && !STATE.kuasMode) {
            var singleDate = STATE.selectedDates[0];
            hideDragBar();
            MARGO_AGENDA.openFormForDate(singleDate);
        } else {
            showDragBar();
        }
    });

    async function apiPost(action, payload) {
        var fd = new FormData();
        fd.append('api_action', action);
        fd.append('csrf_token', CSRF_TOKEN);
        Object.keys(payload || {}).forEach(function (k) {
            var v = payload[k];
            if (Array.isArray(v)) {
                v.forEach(function (item) { fd.append(k + '[]', item); });
            } else if (v !== undefined && v !== null) {
                fd.append(k, v);
            }
        });
        var r = await fetch(API_URL, {
            method: 'POST',
            body: fd,
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
        });
        return await r.json();
    }

    function defaultScopeForRole() {
        if (USER_CTX.is_admin) return 'all';
        return 'private';
    }

    window.MARGO_AGENDA = {
        setView: function (v) {
            STATE.view = v;
            hideDragBar();
            document.querySelectorAll('#magViewBtns .mag-view-btn').forEach(function (b) {
                b.classList.toggle('is-active', b.getAttribute('data-view') === v);
            });
            renderAll();
        },
        navigate: function (delta) {
            var d = STATE.cursor;
            if (STATE.view === 'month' || STATE.view === 'agenda') {
                STATE.cursor = new Date(d.getFullYear(), d.getMonth() + delta, 1);
            } else if (STATE.view === 'week') {
                STATE.cursor = new Date(d.getFullYear(), d.getMonth(), d.getDate() + (delta * 7));
            } else {
                STATE.cursor = new Date(d.getFullYear(), d.getMonth(), d.getDate() + delta);
            }
            hideDragBar();
            renderAll();
        },
        goToday: function () {
            STATE.cursor = new Date(now.getFullYear(), now.getMonth(), now.getDate());
            hideDragBar();
            renderAll();
        },
        toggleKuas: function () {
            STATE.kuasMode = !STATE.kuasMode;
            var btn = document.getElementById('btnToggleKuas');
            var lbl = document.getElementById('lblToggleKuas');
            if (btn) btn.classList.toggle('is-selected', STATE.kuasMode);
            if (lbl) lbl.textContent = STATE.kuasMode ? 'Mode Kuas: AKTIF' : 'Mode Kuas: OFF';
            var card = document.getElementById('magRbcCard');
            if (card) card.classList.toggle('mag-is-kuas', STATE.kuasMode);
            if (STATE.kuasMode && window.Ilmuna) {
                Ilmuna.info('Mode Kuas Aktif', 'Klik atau seret tanggal pada kalender untuk membuat agenda cepat kategori "' + (CAT_LABELS[STATE.brushCat] || STATE.brushCat) + '".');
            }
        },
        pickBrushCat: function (cat, hex, el) {
            STATE.brushCat = cat;
            STATE.brushColor = hex || CAT_COLORS[cat] || '#0D7A87';
            document.querySelectorAll('#magCatSwatches .mag-swatch-btn').forEach(function (b) {
                b.classList.toggle('is-selected', b === el);
            });
        },
        pickDragCat: function (cat, hex, el) {
            STATE.dragCat = cat;
            STATE.dragColor = hex || CAT_COLORS[cat] || '#0D7A87';
            document.querySelectorAll('#magDragCatGroup .mag-drag-cat-pill').forEach(function (b) {
                b.classList.toggle('is-active', b === el);
            });
        },
        onDragScopeChange: function () {
            var sc = document.getElementById('magDragScope');
            var kl = document.getElementById('magDragKelas');
            var mp = document.getElementById('magDragMapel');
            var grp = document.getElementById('magDragKelompok');
            var val = sc ? sc.value : '';
            if (kl) kl.style.display = (val === 'kelas') ? '' : 'none';
            if (mp) mp.style.display = (val === 'kelas') ? '' : 'none';
            if (grp) grp.style.display = (val === 'kelompok') ? '' : 'none';
            if (val === 'kelas') MARGO_AGENDA.onDragKelasChange();
        },
        onDragKelasChange: function () {
            var kl = document.getElementById('magDragKelas');
            var mp = document.getElementById('magDragMapel');
            if (!kl || !mp) return;
            MARGO_AGENDA.populateMapelOptions(mp, kl.value, '');
        },
        populateMapelOptions: function (selectEl, kelasVal, selectedMapelId) {
            if (!selectEl) return;
            var list = [];
            if (kelasVal === 'all') {
                list = USER_CTX.guru_mapel_all || [];
            } else if (kelasVal) {
                var gc = (USER_CTX.guru_classes || []).find(function (c) { return String(c.id) === String(kelasVal); });
                if (gc && Array.isArray(gc.mapel)) {
                    list = gc.mapel;
                }
            }
            if (!kelasVal) {
                selectEl.innerHTML = '<option value="">-- Pilih Kelas Dahulu --</option>';
                return;
            }
            if (!list.length) {
                selectEl.innerHTML = '<option value="">-- Tidak ada mapel --</option>';
                return;
            }
            var html = '<option value="">-- Pilih Mata Pelajaran --</option>';
            list.forEach(function (m) {
                var mid = String(m.id_mapel || '');
                var sel = (selectedMapelId && String(selectedMapelId) === mid) || (list.length === 1 && !selectedMapelId) ? ' selected' : '';
                html += '<option value="' + esc(mid) + '" data-name="' + esc(m.nama_mapel || '') + '"' + sel + '>' + esc(m.nama_mapel || '') + '</option>';
            });
            selectEl.innerHTML = html;
        },
        onModalKelasChange: function (selectedMapelId) {
            var kSel = document.getElementById('agKelasId');
            var mSel = document.getElementById('agMapelId');
            if (kSel && mSel) {
                MARGO_AGENDA.populateMapelOptions(mSel, kSel.value, selectedMapelId || '');
            }
        },
        filterPeerChecks: function (listId, q) {
            var needle = String(q || '').toLowerCase().trim();
            document.querySelectorAll('#' + listId + ' .mag-peer-item').forEach(function (lbl) {
                var hay = lbl.getAttribute('data-hay') || '';
                lbl.style.display = (!needle || hay.indexOf(needle) !== -1) ? '' : 'none';
            });
        },
        updatePeerCount: function (listId, countId) {
            var checked = document.querySelectorAll('#' + listId + ' input[type="checkbox"]:checked').length;
            var el = document.getElementById(countId);
            if (el) {
                var unit = (listId.indexOf('Guru') !== -1) ? 'guru' : 'siswa';
                el.textContent = checked + ' ' + unit + ' dipilih';
            }
        },
        onModalGroupChange: function () {
            var gSel = document.getElementById('agGroupKey');
            var prev = document.getElementById('agGroupMembersPreview');
            if (!gSel || !prev) return;
            var gKey = gSel.value;
            var grp = (USER_CTX.ketua_kelompok || []).find(function (k) { return String(k.group_key) === String(gKey); });
            if (!grp) {
                prev.style.display = 'none';
                prev.innerHTML = '';
                return;
            }
            var memNames = (grp.anggota || []).map(function (a) { return esc(a.nama) + (a.peran === 'Ketua' ? ' (Ketua)' : ''); });
            prev.innerHTML = '<strong>Anggota Kelompok (' + (grp.jumlah_anggota || memNames.length) + '):</strong><br>' + (memNames.join(', ') || '-');
            prev.style.display = '';
        },
        cancelDrag: function () {
            hideDragBar();
        },
        openModalFromDrag: function () {
            if (!STATE.selectedDates.length) return;
            var sorted = STATE.selectedDates.slice().sort();
            var quickTitle = (document.getElementById('magDragTitle').value || '').trim();
            var quickScopeEl = document.getElementById('magDragScope');
            var quickKelasEl = document.getElementById('magDragKelas');
            var quickMapelEl = document.getElementById('magDragMapel');
            var quickGrpEl   = document.getElementById('magDragKelompok');
            var chosenScope = quickScopeEl ? quickScopeEl.value : defaultScopeForRole();
            var chosenKelas = quickKelasEl ? quickKelasEl.value : '';
            var chosenMapel = quickMapelEl ? quickMapelEl.value : '';
            var chosenGrp   = quickGrpEl ? quickGrpEl.value : '';
            var cCat = STATE.dragCat || STATE.brushCat;
            var cCol = STATE.dragColor || STATE.brushColor;
            hideDragBar();
            MARGO_AGENDA.openFormModal(null, {
                start_date: sorted[0],
                end_date: sorted[sorted.length - 1],
                title: quickTitle,
                category: cCat,
                color_hex: cCol,
                visibility_scope: chosenScope,
                target_kelas_id: chosenKelas,
                target_mapel_id: chosenMapel,
                target_group_key: chosenGrp
            });
        },
        submitDragQuick: async function () {
            if (!STATE.selectedDates.length) return;
            var scEl  = document.getElementById('magDragScope');
            var klEl  = document.getElementById('magDragKelas');
            var mpEl  = document.getElementById('magDragMapel');
            var grpEl = document.getElementById('magDragKelompok');
            var scopeVal = scEl ? scEl.value : defaultScopeForRole();

            if (scopeVal === 'peer_guru' || scopeVal === 'peer_siswa') {
                MARGO_AGENDA.openModalFromDrag();
                return;
            }
            var title = (document.getElementById('magDragTitle').value || '').trim();
            if (!title) {
                if (window.Ilmuna) Ilmuna.warning('Judul Diperlukan', 'Isi judul agenda/rapat pada kotak di bar bawah sebelum menyimpan.');
                var inp = document.getElementById('magDragTitle');
                if (inp) inp.focus();
                return;
            }
            var kelasVal = (scopeVal === 'kelas' && klEl) ? klEl.value : '';
            var mapelVal = (scopeVal === 'kelas' && mpEl) ? mpEl.value : '';
            var mapelName = (scopeVal === 'kelas' && mpEl && mpEl.selectedOptions && mpEl.selectedOptions[0]) ? (mpEl.selectedOptions[0].getAttribute('data-name') || '') : '';
            var grpVal   = (scopeVal === 'kelompok' && grpEl) ? grpEl.value : '';

            if (scopeVal === 'kelas' && (!kelasVal || !mapelVal)) {
                if (window.Ilmuna) Ilmuna.warning('Pilih Kelas & Mapel', 'Tentukan kelas (atau Semua Kelas) dan mata pelajaran terlebih dahulu.');
                return;
            }
            if (scopeVal === 'kelompok' && !grpVal) {
                if (window.Ilmuna) Ilmuna.warning('Pilih Kelompok', 'Pilih kelompok tugas/proyek terlebih dahulu.');
                return;
            }

            var res = await apiPost('save_batch', {
                dates: STATE.selectedDates,
                title: title,
                category: STATE.dragCat || STATE.brushCat,
                color_hex: STATE.dragColor || STATE.brushColor,
                visibility_scope: (scopeVal === 'kelas' && kelasVal === 'all') ? 'semua_kelas' : scopeVal,
                target_kelas_id: kelasVal,
                target_mapel_id: mapelVal,
                target_mapel_name: mapelName,
                target_group_key: grpVal
            });
            if (res && res.success) {
                document.getElementById('magDragTitle').value = '';
                hideDragBar();
                if (Array.isArray(res.items)) STATE.items = res.items;
                renderAll();
                if (window.MargoShell) window.MargoShell.refreshRightWidgets();
                if (window.Ilmuna) Ilmuna.success('Agenda Tersimpan', res.message);
            } else {
                if (window.Ilmuna) Ilmuna.error('Gagal Menyimpan', (res && res.message) || 'Terjadi kesalahan.');
            }
        },
        selectModalScope: function (val) {
            var uiVal = (val === 'semua_kelas') ? 'kelas' : val;
            var inp = document.getElementById('agScope');
            if (inp) inp.value = uiVal;
            document.querySelectorAll('#agScopePicker .mag-scope-card').forEach(function (btn) {
                btn.classList.toggle('is-active', btn.getAttribute('data-val') === uiVal);
            });
            var kWrap  = document.getElementById('agKelasWrap');
            var pgWrap = document.getElementById('agPeerGuruWrap');
            var psWrap = document.getElementById('agPeerSiswaWrap');
            var klWrap = document.getElementById('agKelompokWrap');
            if (kWrap)  kWrap.style.display  = (uiVal === 'kelas') ? '' : 'none';
            if (pgWrap) pgWrap.style.display = (uiVal === 'peer_guru') ? '' : 'none';
            if (psWrap) psWrap.style.display = (uiVal === 'peer_siswa') ? '' : 'none';
            if (klWrap) klWrap.style.display = (uiVal === 'kelompok') ? '' : 'none';
        },
        pickModalColor: function (hex) {
            var norm = String(hex || '#0D7A87').toUpperCase();
            var inp = document.getElementById('agColorHex');
            if (inp) inp.value = norm;
            document.querySelectorAll('#agColorSwatches .mag-color-dot-btn').forEach(function (b) {
                var active = (b.getAttribute('data-hex') || '').toUpperCase() === norm;
                b.classList.toggle('is-active', active);
                b.innerHTML = active ? '<iconify-icon icon="solar:check-read-bold" width="13"></iconify-icon>' : '';
            });
        },
        onModalCatChange: function () {
            var cat = document.getElementById('agCategory').value;
            if (CAT_COLORS[cat]) {
                MARGO_AGENDA.pickModalColor(CAT_COLORS[cat]);
            }
        },
        setQuickDur: function (daysToAdd) {
            var sVal = document.getElementById('agStartDate').value;
            if (!sVal) return;
            var dt = parseYmd(sVal);
            dt.setDate(dt.getDate() + Number(daysToAdd || 0));
            document.getElementById('agEndDate').value = toYmd(dt);
        },
        openFormForDate: function (dStr) {
            MARGO_AGENDA.openFormModal(null, {
                start_date: dStr,
                end_date: dStr,
                category: STATE.brushCat,
                color_hex: STATE.brushColor
            });
        },
        focusAgenda: function (id) {
            var found = (STATE.items || []).find(function (x) { return Number(x.id) === Number(id); });
            if (found) {
                STATE.cursor = parseYmd(found.start_date);
                renderAll();
                MARGO_AGENDA.openDetail(found.id);
                return;
            }
            // Fallback lazy-fetch jika item belum ada di daftar lokal
            fetch(API_URL + '&api_action=list', {
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
            }).then(function (r) { return r.json(); }).then(function (res) {
                if (res && res.success && Array.isArray(res.data)) {
                    STATE.items = res.data;
                    var f2 = STATE.items.find(function (x) { return Number(x.id) === Number(id); });
                    if (f2) {
                        STATE.cursor = parseYmd(f2.start_date);
                        renderAll();
                        MARGO_AGENDA.openDetail(f2.id);
                    }
                }
            }).catch(function () {});
        },
        openDetail: function (id) {
            var ag = (STATE.items || []).find(function (x) { return Number(x.id) === Number(id); });
            if (!ag) return;
            var isKaldik = (ag.source === 'kaldik' || Number(ag.is_locked) === 1);
            var hex = getEventColor(ag);

            var iconBox = document.getElementById('detAgIcon');
            if (iconBox) {
                iconBox.style.background = hex;
                iconBox.style.color = '#fff';
                var isLearnSrc = ag.source && ag.source.indexOf('learn_') === 0;
                var learnIconMap = {
                    'learn_tugas_buat':      'solar:document-add-bold',
                    'learn_tugas_deadline':  'solar:clock-circle-bold',
                    'learn_proyek_buat':     'solar:rocket-bold',
                    'learn_proyek_deadline': 'solar:hourglass-bold',
                    'learn_asesmen':         'solar:test-tube-bold',
                };
                iconBox.innerHTML = isLearnSrc
                    ? ('<iconify-icon icon="' + (learnIconMap[ag.source] || 'solar:book-bold') + '"></iconify-icon>')
                    : (isKaldik
                        ? '<iconify-icon icon="solar:lock-keyhole-bold"></iconify-icon>'
                        : '<iconify-icon icon="solar:calendar-mark-bold"></iconify-icon>');
            }
            document.getElementById('detAgTitle').textContent = ag.title;
            document.getElementById('detAgDate').textContent = ag.start_date
                + (ag.end_date && ag.end_date !== ag.start_date ? ' s/d ' + ag.end_date : '')
                + (ag.start_time ? ' · ' + ag.start_time + ' WIB' : '');

            var bodyHtml = '';
            var isLearnSource = ag.source && ag.source.indexOf('learn_') === 0;
            if (isLearnSource) {
                var learnBannerLabels = {
                    'learn_tugas_buat':      '📌 Tanggal Buat Tugas — Ilmuna Learn',
                    'learn_tugas_deadline':  '⏰ Deadline Pengumpulan Tugas — Ilmuna Learn',
                    'learn_proyek_buat':     '🚀 Tanggal Mulai Proyek — Ilmuna Learn',
                    'learn_proyek_deadline': '⏳ Deadline Proyek — Ilmuna Learn',
                    'learn_asesmen':         '📝 Jadwal Asesmen — Ilmuna Learn',
                };
                bodyHtml += '<div class="mag-lock-banner" style="border-left-color:' + hex + ';">'
                    + '<iconify-icon icon="solar:lock-keyhole-bold" width="22" style="color:' + hex + ';flex-shrink:0;"></iconify-icon>'
                    + '<div><strong>' + esc(learnBannerLabels[ag.source] || 'Agenda dari Ilmuna Learn') + '</strong><br>'
                    + 'Tersinkron otomatis sesuai kelas dari Ilmuna Learn dan <strong>tidak dapat diubah atau dihapus</strong> dari Margo One SSO.</div>'
                    + '</div>';
            } else if (isKaldik) {
                bodyHtml += '<div class="mag-lock-banner">'
                    + '<iconify-icon icon="solar:lock-keyhole-bold" width="22" style="color:' + hex + ';flex-shrink:0;"></iconify-icon>'
                    + '<div><strong>Agenda Resmi Kalender Pendidikan (Kaldik) · Terkunci Permanen</strong><br>'
                    + 'Tersinkron otomatis dari Ilmuna Learn melalui Database Trigger dan <strong>tidak dapat diubah atau dihapus</strong> dari Margo One SSO.</div>'
                    + '</div>';
            }

            bodyHtml += '<div style="display:flex;gap:7px;flex-wrap:wrap;margin-bottom:12px;">'
                + scopeBadgeHtml(ag)
                + (ag.target_mapel_name ? '<span class="ilmuna-badge ilmuna-badge--teal"><iconify-icon icon="solar:book-2-bold"></iconify-icon> ' + esc(ag.target_mapel_name) + '</span>' : '')
                + (ag.target_group_name ? '<span class="ilmuna-badge" style="background:#F5F3FF;color:#5B21B6;border:1px solid #DDD6FE;"><iconify-icon icon="solar:users-group-rounded-bold"></iconify-icon> ' + esc(ag.target_group_name) + '</span>' : '')
                + (ag.location ? '<span class="ilmuna-badge ilmuna-badge--navy"><iconify-icon icon="solar:map-point-bold"></iconify-icon> ' + esc(ag.location) + '</span>' : '')
                + '</div>'
                + (ag.target_user_names ? '<div style="font-size:12px;color:#475569;margin-bottom:8px;"><strong>Penerima:</strong> ' + esc(ag.target_user_names) + '</div>' : '')
                + '<p style="margin:0 0 10px;font-size:13.5px;line-height:1.65;color:#334155;white-space:pre-line;">' + esc(ag.description || 'Tidak ada catatan tambahan.') + '</p>'
                + (ag.created_by_name ? '<div style="font-size:12px;color:#64748B;">Dibuat oleh: <strong style="color:#1D3B5E;">' + esc(ag.created_by_name) + '</strong></div>' : '');

            document.getElementById('detAgBody').innerHTML = bodyHtml;

            var footHtml = '<button type="button" class="ilm-modal-btn ilm-modal-btn--ghost" onclick="closeIlmunaModal(\'modalAgendaDetail\')">Tutup</button>';
            if (ag.link_url) {
                var isExt = /^https?:\/\//i.test(ag.link_url) && ag.link_url.indexOf(window.location.origin) !== 0;
                footHtml += '<a href="' + esc(ag.link_url) + '" class="ilm-modal-btn ilm-modal-btn--ghost"' + (isExt ? ' target="_blank" rel="noopener noreferrer"' : '') + '><iconify-icon icon="solar:arrow-right-up-bold"></iconify-icon><span>Buka Tautan</span></a>';
            }
            if (ag.can_edit && !isKaldik) {
                footHtml += '<button type="button" class="ilm-modal-btn ilm-modal-btn--ghost" onclick="closeIlmunaModal(\'modalAgendaDetail\');MARGO_AGENDA.deleteAgenda(' + ag.id + ');"><iconify-icon icon="solar:trash-bin-minimalistic-bold"></iconify-icon><span>Hapus</span></button>';
                footHtml += '<button type="button" class="ilm-modal-btn ilm-modal-btn--primary" onclick="closeIlmunaModal(\'modalAgendaDetail\');MARGO_AGENDA.openFormModal(' + ag.id + ');"><iconify-icon icon="solar:pen-bold"></iconify-icon><span>Edit Agenda</span></button>';
            }
            document.getElementById('detAgFooter').innerHTML = footHtml;
            openIlmunaModal('modalAgendaDetail');
        },
        openFormModal: function (id, preset) {
            document.getElementById('formAgendaUniversal').reset();
            document.querySelectorAll('#agPeerGuruList input[type="checkbox"], #agPeerSiswaList input[type="checkbox"]').forEach(function (cb) { cb.checked = false; });
            MARGO_AGENDA.updatePeerCount('agPeerGuruList', 'agPeerGuruCount');
            MARGO_AGENDA.updatePeerCount('agPeerSiswaList', 'agPeerSiswaCount');
            MARGO_AGENDA.filterPeerChecks('agPeerGuruList', '');
            MARGO_AGENDA.filterPeerChecks('agPeerSiswaList', '');
            var grpPrev = document.getElementById('agGroupMembersPreview');
            if (grpPrev) { grpPrev.style.display = 'none'; grpPrev.innerHTML = ''; }

            if (id) {
                var ag = (STATE.items || []).find(function (x) { return Number(x.id) === Number(id); });
                if (!ag) return;
                if (ag.source === 'kaldik' || Number(ag.is_locked) === 1) {
                    var lockMsgEdit = (ag.source && ag.source.indexOf('learn_') === 0)
                        ? 'Agenda dari Ilmuna Learn bersifat read-only dan tidak dapat diubah secara manual.'
                        : 'Agenda resmi Kaldik tidak dapat diubah.';
                    if (window.Ilmuna) Ilmuna.warning('Agenda Terkunci', lockMsgEdit);
                    return;
                }
                if (!ag.can_edit) {
                    if (window.Ilmuna) Ilmuna.error('Akses Ditolak', 'Anda tidak memiliki izin mengubah agenda ini.');
                    return;
                }
                document.getElementById('frmAgHeading').textContent = 'Edit Agenda';
                document.getElementById('agId').value = ag.id;
                document.getElementById('agTitle').value = ag.title || '';
                document.getElementById('agStartDate').value = ag.start_date || '';
                document.getElementById('agEndDate').value = ag.end_date || ag.start_date || '';
                document.getElementById('agStartTime').value = ag.start_time || '';
                document.getElementById('agCategory').value = ag.category || 'akademik';
                document.getElementById('agLocation').value = ag.location || '';
                document.getElementById('agLink').value = ag.link_url_raw || '';
                document.getElementById('agDesc').value = ag.description || '';
                MARGO_AGENDA.selectModalScope(ag.visibility_scope || defaultScopeForRole());
                MARGO_AGENDA.pickModalColor(getEventColor(ag));

                var kSel = document.getElementById('agKelasId');
                if (kSel) {
                    if (ag.visibility_scope === 'semua_kelas') {
                        kSel.value = 'all';
                    } else if (ag.target_kelas_id) {
                        kSel.value = String(ag.target_kelas_id);
                    }
                    MARGO_AGENDA.onModalKelasChange(ag.target_mapel_id || '');
                }
                if (Array.isArray(ag.target_user_ids) && ag.target_user_ids.length) {
                    var uidSet = {};
                    ag.target_user_ids.forEach(function (u) { uidSet[String(u)] = true; });
                    var targetListId = (ag.visibility_scope === 'peer_guru') ? 'agPeerGuruList' : 'agPeerSiswaList';
                    document.querySelectorAll('#' + targetListId + ' input[type="checkbox"]').forEach(function (cb) {
                        cb.checked = !!uidSet[String(cb.value)];
                    });
                    MARGO_AGENDA.updatePeerCount('agPeerGuruList', 'agPeerGuruCount');
                    MARGO_AGENDA.updatePeerCount('agPeerSiswaList', 'agPeerSiswaCount');
                }
                var gSel = document.getElementById('agGroupKey');
                if (gSel && ag.target_group_key) {
                    gSel.value = String(ag.target_group_key);
                    MARGO_AGENDA.onModalGroupChange();
                }
            } else {
                var p = preset || {};
                var cCat = p.category || STATE.brushCat || 'akademik';
                document.getElementById('frmAgHeading').textContent = 'Tambah Agenda Baru';
                document.getElementById('agId').value = '0';
                document.getElementById('agTitle').value = p.title || '';
                document.getElementById('agStartDate').value = p.start_date || todayStr();
                document.getElementById('agEndDate').value = p.end_date || p.start_date || todayStr();
                document.getElementById('agCategory').value = cCat;
                MARGO_AGENDA.selectModalScope(p.visibility_scope || defaultScopeForRole());
                MARGO_AGENDA.pickModalColor(p.color_hex || CAT_COLORS[cCat] || '#0D7A87');
                var kSel2 = document.getElementById('agKelasId');
                if (kSel2) {
                    kSel2.value = p.target_kelas_id ? String(p.target_kelas_id) : '';
                    MARGO_AGENDA.onModalKelasChange(p.target_mapel_id || '');
                }
                var gSel2 = document.getElementById('agGroupKey');
                if (gSel2 && p.target_group_key) {
                    gSel2.value = String(p.target_group_key);
                    MARGO_AGENDA.onModalGroupChange();
                }
            }
            openIlmunaModal('modalAgendaForm');
        },
        saveAgenda: async function (e) {
            e.preventDefault();
            var scopeVal = document.getElementById('agScope') ? document.getElementById('agScope').value : defaultScopeForRole();
            var kSel = document.getElementById('agKelasId');
            var mSel = document.getElementById('agMapelId');
            var gSel = document.getElementById('agGroupKey');

            var kelasVal  = (scopeVal === 'kelas' && kSel) ? kSel.value : '';
            var mapelVal  = (scopeVal === 'kelas' && mSel) ? mSel.value : '';
            var mapelName = (scopeVal === 'kelas' && mSel && mSel.selectedOptions && mSel.selectedOptions[0])
                ? (mSel.selectedOptions[0].getAttribute('data-name') || '') : '';
            var groupKey  = (scopeVal === 'kelompok' && gSel) ? gSel.value : '';

            var targetUids = [];
            if (scopeVal === 'peer_guru') {
                document.querySelectorAll('#agPeerGuruList input[type="checkbox"]:checked').forEach(function (cb) {
                    targetUids.push(cb.value);
                });
                if (!targetUids.length) {
                    if (window.Ilmuna) Ilmuna.warning('Pilih Guru', 'Pilih minimal 1 sesama guru penerima agenda.');
                    return;
                }
            } else if (scopeVal === 'peer_siswa') {
                document.querySelectorAll('#agPeerSiswaList input[type="checkbox"]:checked').forEach(function (cb) {
                    targetUids.push(cb.value);
                });
                if (!targetUids.length) {
                    if (window.Ilmuna) Ilmuna.warning('Pilih Siswa', 'Pilih minimal 1 sesama siswa penerima agenda.');
                    return;
                }
            } else if (scopeVal === 'kelas') {
                if (!kelasVal) {
                    if (window.Ilmuna) Ilmuna.warning('Pilih Kelas', 'Pilih kelas yang Anda ampu atau Semua Kelas terlebih dahulu.');
                    return;
                }
                if (!mapelVal) {
                    if (window.Ilmuna) Ilmuna.warning('Pilih Mata Pelajaran', 'Pilih mata pelajaran terlebih dahulu sebelum menyimpan agenda.');
                    return;
                }
            } else if (scopeVal === 'kelompok') {
                if (!groupKey) {
                    if (window.Ilmuna) Ilmuna.warning('Pilih Kelompok', 'Pilih kelompok tugas/proyek yang Anda pimpin terlebih dahulu.');
                    return;
                }
            }

            var finalScope = (scopeVal === 'kelas' && kelasVal === 'all') ? 'semua_kelas' : scopeVal;
            var res = await apiPost('save', {
                id: document.getElementById('agId').value,
                title: document.getElementById('agTitle').value.trim(),
                start_date: document.getElementById('agStartDate').value,
                end_date: document.getElementById('agEndDate').value,
                start_time: document.getElementById('agStartTime').value.trim(),
                category: document.getElementById('agCategory').value,
                color_hex: document.getElementById('agColorHex') ? document.getElementById('agColorHex').value : '',
                location: document.getElementById('agLocation').value.trim(),
                link_url: document.getElementById('agLink').value.trim(),
                description: document.getElementById('agDesc').value.trim(),
                visibility_scope: finalScope,
                target_kelas_id: kelasVal,
                target_mapel_id: mapelVal,
                target_mapel_name: mapelName,
                target_user_ids: targetUids,
                target_group_key: groupKey,
                is_active: '1'
            });
            if (res && res.success) {
                closeIlmunaModal('modalAgendaForm');
                if (Array.isArray(res.items)) STATE.items = res.items;
                renderAll();
                if (window.MargoShell) window.MargoShell.refreshRightWidgets();
                if (window.Ilmuna) Ilmuna.success('Agenda Tersimpan', res.message);
            } else {
                if (window.Ilmuna) Ilmuna.error('Gagal', (res && res.message) || 'Gagal menyimpan agenda.');
            }
        },
        deleteAgenda: async function (id) {
            var ag = (STATE.items || []).find(function (x) { return Number(x.id) === Number(id); });
            if (ag && (ag.source === 'kaldik' || Number(ag.is_locked) === 1)) {
                var lockMsgDel = (ag.source && ag.source.indexOf('learn_') === 0)
                    ? 'Agenda dari Ilmuna Learn bersifat read-only dan tidak dapat dihapus secara manual.'
                    : 'Agenda resmi Kaldik tidak dapat dihapus.';
                if (window.Ilmuna) Ilmuna.warning('Agenda Terkunci', lockMsgDel);
                return;
            }
            var ok = window.Ilmuna
                ? await Ilmuna.confirm('error', 'Hapus Agenda?', 'Agenda akan dihapus permanen dari kalender dan panel portal.', 'Ya, Hapus', 'Batal')
                : true;
            if (!ok) return;
            var res = await apiPost('delete', { id: id });
            if (res && res.success) {
                if (Array.isArray(res.items)) STATE.items = res.items;
                renderAll();
                if (window.MargoShell) window.MargoShell.refreshRightWidgets();
                if (window.Ilmuna) Ilmuna.success('Terhapus', res.message);
            } else {
                if (window.Ilmuna) Ilmuna.error('Gagal', (res && res.message) || 'Gagal menghapus agenda.');
            }
        },
        syncKaldik: async function () {
            var res = await apiPost('sync_kaldik', {});
            if (res && res.success) {
                if (Array.isArray(res.items)) STATE.items = res.items;
                var pill = document.getElementById('calDbStatusPill');
                if (pill && res.status) {
                    pill.innerHTML = '<iconify-icon icon="solar:database-bold" style="color:#0D7A87;"></iconify-icon><span>DB: '
                        + esc(res.status.db_name) + ' · Trigger Kaldik: ' + (res.status.kaldik_triggers_ok ? 'AKTIF' : 'SYNC') + '</span>';
                }
                renderAll();
                if (window.MargoShell) window.MargoShell.refreshRightWidgets();
                if (window.Ilmuna) Ilmuna.success('Kaldik Tersinkron', res.message);
            } else {
                if (window.Ilmuna) Ilmuna.error('Gagal Sinkron', (res && res.message) || 'Tidak dapat menyinkronkan Kaldik.');
            }
        },
        syncLearn: async function () {
            /* Dipanggil oleh MargoLearnWS saat menerima event real-time WebSocket dari Ilmuna Learn */
            try {
                var r = await fetch(BASE_URL + '/portal-data.php?resource=sync_learn_agenda&force=1&all=1', {
                    credentials: 'same-origin',
                    headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
                });
                var res = await r.json();
                if (res && res.success && Array.isArray(res.data)) {
                    STATE.items = res.data;
                    renderAll();
                    if (window.MargoShell) window.MargoShell.refreshRightWidgets();
                }
            } catch (e) {}
        }
    };

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('#agendaScopePills .ilmuna-pill-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                STATE.scopeFilter = btn.getAttribute('data-scope');
                document.querySelectorAll('#agendaScopePills .ilmuna-pill-btn').forEach(function (b) {
                    b.classList.toggle('is-active', b === btn);
                });
                renderAll();
            });
        });
        var sInp = document.getElementById('agendaSearchInput');
        if (sInp) sInp.addEventListener('input', renderAll);

        renderAll();
        if (FOCUS_ID > 0) {
            var found = (STATE.items || []).find(function (x) { return Number(x.id) === FOCUS_ID; });
            if (found) {
                STATE.cursor = parseYmd(found.start_date);
                renderAll();
                MARGO_AGENDA.openDetail(found.id);
            }
        }
    });
})();
</script>

<?php require_once BASE_PATH . '/includes/footer.php'; ?>
