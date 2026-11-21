<?php
/**
 * ==============================================================================
 * MARGO ONE — ILMUNA SSO · AGENDA & JADWAL EKOSISTEM (/agenda)
 * ==============================================================================
 * Fitur:
 *   - Kalender Bulanan Interaktif + Daftar Agenda Ekosistem (Filter Kategori & Pencarian)
 *   - Deep-link ?id=X dari Right Sidebar otomatis membuka Modal Detail Agenda
 *   - Untuk Admin (hasRole('admin')): Kontrol cepat Tambah / Edit / Hapus Agenda
 *     langsung via AJAX tanpa reload halaman & otomatis menyegarkan Right Sidebar!
 *
 * 100% kompatibel PHP 7.4 (ea-php74) hingga PHP 8.3.
 * ==============================================================================
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';

requireAuth();

$isAdmin = hasRole('admin');
if ($isAdmin && empty($_SESSION['csrf_admin_sso'])) {
    $_SESSION['csrf_admin_sso'] = bin2hex(random_bytes(32));
}
$csrfAdmin   = $isAdmin ? $_SESSION['csrf_admin_sso'] : '';
$focusId     = isset($_GET['id']) ? (int)$_GET['id'] : 0;

$pageTitle = 'Agenda & Jadwal Ekosistem';
$pageIcon  = 'solar:calendar-mark-bold';

$extraHead = '<style>
.mag-layout{display:grid;grid-template-columns:340px minmax(0,1fr);gap:18px;align-items:start}
@media(max-width:1180px){.mag-layout{grid-template-columns:1fr}}
.mag-card{background:#fff;border:1px solid #E2E8F0;border-radius:20px;padding:18px;box-shadow:0 6px 20px rgba(29,59,94,.04)}
.mag-cal-head{display:flex;align-items:center;justify-content:space-between;gap:8px;margin-bottom:14px}
.mag-cal-month{font-family:"Outfit",sans-serif;font-size:15.5px;font-weight:800;color:#1D3B5E;margin:0}
.mag-cal-nav{display:inline-flex;gap:5px}
.mag-cal-btn{width:34px;height:34px;border-radius:10px;border:1px solid #E2E8F0;background:#F8FAFC;color:#1D3B5E;display:inline-flex;align-items:center;justify-content:center;cursor:pointer;transition:all .18s;font-family:inherit;font-size:12px;font-weight:700}
.mag-cal-btn:hover{border-color:#0D7A87;color:#0D7A87;background:#fff}
.mag-cal-weekdays{display:grid;grid-template-columns:repeat(7,1fr);gap:4px;margin-bottom:6px;text-align:center;font-size:11px;font-weight:800;color:#64748B}
.mag-cal-grid{display:grid;grid-template-columns:repeat(7,1fr);gap:4px}
.mag-day{position:relative;aspect-ratio:1/1;min-height:38px;border-radius:11px;border:1px solid transparent;background:#F8FAFC;color:#1D3B5E;font-size:12.5px;font-weight:700;display:flex;flex-direction:column;align-items:center;justify-content:center;cursor:pointer;transition:all .18s;font-family:inherit}
.mag-day:hover{border-color:#0D7A87;background:#fff}
.mag-day.is-other{opacity:.35;background:transparent}
.mag-day.is-today{border-color:#F59E0B;background:#FFFBEB;color:#B45309}
.mag-day.is-selected{background:linear-gradient(135deg,#1D3B5E,#0D7A87);color:#fff !important;border-color:transparent;box-shadow:0 6px 14px -4px rgba(13,122,135,.4)}
.mag-day-dots{display:flex;gap:3px;margin-top:2px}
.mag-day-dot{width:5px;height:5px;border-radius:50%;background:#0D7A87}
.mag-day.is-selected .mag-day-dot{background:#FDE68A}

.mag-list{display:flex;flex-direction:column;gap:11px}
.mag-item{display:flex;align-items:stretch;gap:14px;padding:14px;border-radius:16px;background:#fff;border:1px solid #E2E8F0;transition:all .22s ease;cursor:pointer}
.mag-item:hover{border-color:#0D7A87;box-shadow:0 12px 24px -10px rgba(13,122,135,.22);transform:translateY(-2px)}
.mag-date-box{width:64px;flex-shrink:0;border-radius:14px;background:linear-gradient(135deg,#1D3B5E,#0D7A87);color:#fff;display:flex;flex-direction:column;align-items:center;justify-content:center;padding:8px 6px;text-align:center}
.mag-date-box.is-past{background:#E2E8F0;color:#475569}
.mag-date-m{font-size:10px;font-weight:800;text-transform:uppercase;opacity:.9}
.mag-date-d{font-family:"Outfit",sans-serif;font-size:23px;font-weight:900;line-height:1.05}
.mag-date-y{font-size:9.5px;opacity:.75}
.mag-item-body{flex:1;min-width:0;display:flex;flex-direction:column;gap:4px}
.mag-item-top{display:flex;align-items:center;justify-content:space-between;gap:8px;flex-wrap:wrap}
.mag-item-title{margin:0;font-family:"Outfit",sans-serif;font-size:15px;font-weight:800;color:#0F1D2F}
.mag-item-desc{font-size:12.5px;color:#475569;line-height:1.45;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
.mag-item-meta{display:flex;flex-wrap:wrap;gap:10px;margin-top:4px;font-size:11.5px;color:#64748B}
.mag-item-meta span{display:inline-flex;align-items:center;gap:4px}
.mag-admin-btns{display:flex;gap:6px;margin-left:auto}
.mag-mini-btn{appearance:none;border:1px solid #CBD5E1;background:#F8FAFC;color:#1D3B5E;border-radius:9px;padding:5px 9px;min-height:32px;font-size:11.5px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:4px;font-family:inherit}
.mag-mini-btn:hover{border-color:#0D7A87;color:#0D7A87;background:#fff}
.mag-mini-btn.is-danger{color:#DC2626;border-color:#FECACA;background:#FEF2F2}
.mag-field{display:flex;flex-direction:column;gap:4px;margin-bottom:10px}
.mag-field label{font-size:12px;font-weight:700;color:#334155}
.mag-input,.mag-textarea{width:100%;padding:9px 11px;min-height:42px;border:1.5px solid #CBD5E1;border-radius:10px;font-size:13.5px;font-family:inherit;color:#0F172A;background:#fff}
.mag-textarea{min-height:84px;resize:vertical}
.mag-grid-2{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}
@media(max-width:600px){.mag-grid-2{grid-template-columns:1fr}.mag-item{flex-direction:column}.mag-date-box{width:100%;flex-direction:row;gap:8px;justify-content:flex-start;padding:8px 12px}}
</style>';

require_once BASE_PATH . '/includes/header.php';
?>

<!-- FILTER & ACTION TOOLBAR (DATA-FIRST) -->
<div class="ilmuna-filter-box" style="margin-bottom:16px;">
    <div class="ilmuna-filter-topline">
        <div class="ilmuna-pill-group" id="agendaCatPills">
            <button type="button" class="ilmuna-pill-btn is-active" data-cat="all">Semua</button>
            <button type="button" class="ilmuna-pill-btn" data-cat="akademik">Akademik</button>
            <button type="button" class="ilmuna-pill-btn" data-cat="sistem">Sistem SSO</button>
            <button type="button" class="ilmuna-pill-btn" data-cat="rapat">Rapat</button>
            <button type="button" class="ilmuna-pill-btn" data-cat="kegiatan">Kegiatan</button>
        </div>
        <div class="ilmuna-search-wrap">
            <iconify-icon icon="solar:magnifer-linear"></iconify-icon>
            <input type="text" id="agendaSearchInput" class="ilmuna-search-input" placeholder="Cari judul, lokasi, deskripsi...">
        </div>
        <div class="ilmuna-filter-actions">
            <button type="button" class="ilmuna-nav-btn ilmuna-nav-btn--ghost" id="btnResetDateFilter" style="display:none;" onclick="MARGO_AGENDA.clearDateFilter()">
                <iconify-icon icon="solar:close-circle-bold" width="16"></iconify-icon>
                <span>Semua Tanggal</span>
            </button>
            <?php if ($isAdmin): ?>
                <button type="button" class="ilmuna-nav-btn ilmuna-nav-btn--primary" onclick="MARGO_AGENDA.openFormModal(null)">
                    <iconify-icon icon="solar:calendar-add-bold" width="17"></iconify-icon>
                    <span>Tambah Agenda</span>
                </button>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="mag-layout">
    <!-- KALENDER BULANAN INTERAKTIF -->
    <div class="mag-card">
        <div class="mag-cal-head">
            <h3 class="mag-cal-month" id="calMonthTitle">Memuat...</h3>
            <div class="mag-cal-nav">
                <button type="button" class="mag-cal-btn" onclick="MARGO_AGENDA.shiftMonth(-1)" title="Bulan Sebelumnya">
                    <iconify-icon icon="solar:alt-arrow-left-bold"></iconify-icon>
                </button>
                <button type="button" class="mag-cal-btn" style="width:auto;padding:0 10px;" onclick="MARGO_AGENDA.goToday()">Hari Ini</button>
                <button type="button" class="mag-cal-btn" onclick="MARGO_AGENDA.shiftMonth(1)" title="Bulan Berikutnya">
                    <iconify-icon icon="solar:alt-arrow-right-bold"></iconify-icon>
                </button>
            </div>
        </div>
        <div class="mag-cal-weekdays">
            <span>Min</span><span>Sen</span><span>Sel</span><span>Rab</span><span>Kam</span><span>Jum</span><span>Sab</span>
        </div>
        <div class="mag-cal-grid" id="calDaysGrid"></div>
        <div style="margin-top:12px;padding-top:10px;border-top:1px solid #F1F5F9;display:flex;align-items:center;justify-content:space-between;font-size:11.5px;color:#64748B;">
            <span style="display:inline-flex;align-items:center;gap:6px;"><span class="mag-day-dot"></span> Tanggal memiliki agenda</span>
            <span id="calCountInfo">0 agenda</span>
        </div>
    </div>

    <!-- DAFTAR AGENDA -->
    <div>
        <div id="agendaListWrap" class="mag-list">
            <div class="margo-skel" style="height:105px;border-radius:16px;"></div>
            <div class="margo-skel" style="height:105px;border-radius:16px;"></div>
            <div class="margo-skel" style="height:105px;border-radius:16px;"></div>
        </div>
    </div>
</div>

<!-- MODAL DETAIL AGENDA -->
<div class="ilm-modal-backdrop" id="modalAgendaDetail">
    <div class="ilm-modal-dialog" style="max-width:540px;">
        <div class="ilm-modal-header">
            <div class="ilm-modal-header__brand">
                <div class="ilm-modal-header__icon"><iconify-icon icon="solar:calendar-mark-bold"></iconify-icon></div>
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
        <div class="ilm-modal-footer">
            <button type="button" class="ilm-modal-btn ilm-modal-btn--ghost" onclick="closeIlmunaModal('modalAgendaDetail')">Tutup</button>
            <a id="detAgLinkBtn" href="#" class="ilm-modal-btn ilm-modal-btn--primary" style="display:none;">
                <iconify-icon icon="solar:arrow-right-up-bold"></iconify-icon>
                <span>Buka Tautan</span>
            </a>
        </div>
    </div>
</div>

<?php if ($isAdmin): ?>
<!-- MODAL ADMIN: TAMBAH / EDIT AGENDA -->
<div class="ilm-modal-backdrop" id="modalAgendaForm">
    <div class="ilm-modal-dialog" style="max-width:560px;">
        <div class="ilm-modal-header">
            <div class="ilm-modal-header__brand">
                <div class="ilm-modal-header__icon"><iconify-icon icon="solar:calendar-add-bold"></iconify-icon></div>
                <div>
                    <h3 class="ilm-modal-header__title" id="frmAgHeading">Tambah Agenda Baru</h3>
                    <p class="ilm-modal-header__subtitle">Otomatis tampil di Kalender &amp; Widget Right Sidebar</p>
                </div>
            </div>
            <button type="button" class="ilm-modal-header__close" onclick="closeIlmunaModal('modalAgendaForm')">
                <iconify-icon icon="solar:close-circle-bold"></iconify-icon>
            </button>
        </div>
        <form id="formAgendaAdmin" onsubmit="MARGO_AGENDA.saveAgenda(event)">
            <input type="hidden" id="agId" value="0">
            <div class="ilm-modal-body" style="max-height:calc(85vh - 150px);">
                <div class="mag-field">
                    <label for="agTitle">Judul Agenda</label>
                    <input type="text" id="agTitle" class="mag-input" placeholder="Contoh: Rapat Evaluasi Kurikulum PAI" required>
                </div>
                <div class="mag-grid-2">
                    <div class="mag-field">
                        <label for="agStartDate">Tanggal Mulai</label>
                        <input type="date" id="agStartDate" class="mag-input" required>
                    </div>
                    <div class="mag-field">
                        <label for="agEndDate">Tanggal Selesai (opsional)</label>
                        <input type="date" id="agEndDate" class="mag-input">
                    </div>
                    <div class="mag-field">
                        <label for="agStartTime">Waktu (WIB, opsional)</label>
                        <input type="text" id="agStartTime" class="mag-input" placeholder="08:00">
                    </div>
                    <div class="mag-field">
                        <label for="agCategory">Kategori</label>
                        <select id="agCategory" class="mag-input">
                            <option value="akademik">Akademik</option>
                            <option value="sistem">Sistem SSO</option>
                            <option value="rapat">Rapat</option>
                            <option value="kegiatan">Kegiatan</option>
                            <option value="pengumuman">Pengumuman</option>
                        </select>
                    </div>
                </div>
                <div class="mag-grid-2">
                    <div class="mag-field">
                        <label for="agLocation">Lokasi / Media</label>
                        <input type="text" id="agLocation" class="mag-input" placeholder="Aula Sekolah / Zoom">
                    </div>
                    <div class="mag-field">
                        <label for="agLink">Tautan Terkait (opsional)</label>
                        <input type="text" id="agLink" class="mag-input" placeholder="https://learn.ilmuna.my.id atau /profile">
                    </div>
                </div>
                <div class="mag-field">
                    <label for="agDesc">Deskripsi Agenda</label>
                    <textarea id="agDesc" class="mag-textarea" placeholder="Detail agenda, peserta, dan informasi penting..."></textarea>
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
<?php endif; ?>

<script>
(function () {
    'use strict';
    var BASE_URL   = <?= json_encode(BASE_URL) ?>;
    var IS_ADMIN   = <?= $isAdmin ? 'true' : 'false' ?>;
    var ADMIN_API  = <?= json_encode(BASE_URL . '/dashboard/admin/index.php?api=1') ?>;
    var CSRF_ADMIN = <?= json_encode($csrfAdmin) ?>;
    var FOCUS_ID   = <?= (int)$focusId ?>;

    var MONTHS = ['Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'];
    var now = new Date();
    var STATE = {
        items: [],
        year: now.getFullYear(),
        month: now.getMonth(),
        cat: 'all',
        selectedDate: ''
    };

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c];
        });
    }

    function pad2(n) { return n < 10 ? '0' + n : '' + n; }
    function todayStr() { return now.getFullYear() + '-' + pad2(now.getMonth() + 1) + '-' + pad2(now.getDate()); }

    function catBadge(cat) {
        var map = {
            akademik: ['Akademik', 'ilmuna-badge--teal'],
            sistem:   ['Sistem SSO', 'ilmuna-badge--navy'],
            rapat:    ['Rapat', 'ilmuna-badge--gold'],
            kegiatan: ['Kegiatan', 'ilmuna-badge--success'],
            pengumuman: ['Pengumuman', 'ilmuna-badge--info']
        };
        var item = map[cat] || [cat || 'Umum', 'ilmuna-badge--teal'];
        return '<span class="ilmuna-badge ' + item[1] + '">' + esc(item[0]) + '</span>';
    }

    function renderCalendar() {
        document.getElementById('calMonthTitle').textContent = MONTHS[STATE.month] + ' ' + STATE.year;
        var firstDay = new Date(STATE.year, STATE.month, 1).getDay();
        var daysInMonth = new Date(STATE.year, STATE.month + 1, 0).getDate();
        var prevDays = new Date(STATE.year, STATE.month, 0).getDate();

        var eventDates = {};
        STATE.items.forEach(function (ag) {
            eventDates[ag.start_date] = (eventDates[ag.start_date] || 0) + 1;
        });

        var html = '';
        var tStr = todayStr();
        for (var i = firstDay - 1; i >= 0; i--) {
            html += '<div class="mag-day is-other">' + (prevDays - i) + '</div>';
        }
        for (var d = 1; d <= daysInMonth; d++) {
            var dStr = STATE.year + '-' + pad2(STATE.month + 1) + '-' + pad2(d);
            var cnt = eventDates[dStr] || 0;
            var cls = 'mag-day';
            if (dStr === tStr) cls += ' is-today';
            if (dStr === STATE.selectedDate) cls += ' is-selected';
            var dots = '';
            if (cnt > 0) {
                dots = '<span class="mag-day-dots">' + Array(Math.min(cnt, 3)).fill('<span class="mag-day-dot"></span>').join('') + '</span>';
            }
            html += '<button type="button" class="' + cls + '" onclick="MARGO_AGENDA.pickDate(\'' + dStr + '\')"><span>' + d + '</span>' + dots + '</button>';
        }
        document.getElementById('calDaysGrid').innerHTML = html;
        document.getElementById('calCountInfo').textContent = STATE.items.length + ' agenda aktif';
    }

    function renderList() {
        var q = (document.getElementById('agendaSearchInput').value || '').toLowerCase().trim();
        var tStr = todayStr();
        var filtered = STATE.items.filter(function (ag) {
            if (STATE.cat !== 'all' && ag.category !== STATE.cat) return false;
            if (STATE.selectedDate && ag.start_date !== STATE.selectedDate) return false;
            if (q) {
                var hay = ((ag.title || '') + ' ' + (ag.description || '') + ' ' + (ag.location || '')).toLowerCase();
                if (hay.indexOf(q) === -1) return false;
            }
            return true;
        });

        document.getElementById('btnResetDateFilter').style.display = STATE.selectedDate ? '' : 'none';
        var wrap = document.getElementById('agendaListWrap');
        if (!filtered.length) {
            wrap.innerHTML = '<div class="mag-card" style="text-align:center;padding:34px 16px;color:#64748B;">'
                + '<iconify-icon icon="solar:calendar-minimalistic-bold" width="36" style="color:#94A3B8;margin-bottom:6px;"></iconify-icon>'
                + '<div style="font-weight:700;color:#1D3B5E;">Tidak ada agenda pada filter ini</div>'
                + '<div style="font-size:12.5px;margin-top:3px;">Pilih tanggal lain atau tampilkan semua agenda.</div>'
                + '</div>';
            return;
        }

        wrap.innerHTML = filtered.map(function (ag) {
            var isPast = (ag.end_date || ag.start_date) < tStr;
            var adminBtns = IS_ADMIN
                ? '<div class="mag-admin-btns" onclick="event.stopPropagation()">'
                  + '<button type="button" class="mag-mini-btn" onclick="MARGO_AGENDA.openFormModal(' + ag.id + ')"><iconify-icon icon="solar:pen-bold"></iconify-icon> Edit</button>'
                  + '<button type="button" class="mag-mini-btn is-danger" onclick="MARGO_AGENDA.deleteAgenda(' + ag.id + ')"><iconify-icon icon="solar:trash-bin-minimalistic-bold"></iconify-icon></button>'
                  + '</div>'
                : '';
            return '<article class="mag-item row-smooth-in" id="ag-card-' + ag.id + '" onclick="MARGO_AGENDA.openDetail(' + ag.id + ')">'
                + '<div class="mag-date-box' + (isPast ? ' is-past' : '') + '">'
                + '<span class="mag-date-m">' + esc(ag.month_short) + '</span>'
                + '<span class="mag-date-d">' + esc(ag.day_num) + '</span>'
                + '<span class="mag-date-y">' + esc(ag.year) + '</span>'
                + '</div>'
                + '<div class="mag-item-body">'
                + '<div class="mag-item-top">'
                + '<h4 class="mag-item-title">' + esc(ag.title) + '</h4>'
                + '<div style="display:flex;align-items:center;gap:6px;">' + catBadge(ag.category) + adminBtns + '</div>'
                + '</div>'
                + (ag.description ? '<div class="mag-item-desc">' + esc(ag.description) + '</div>' : '')
                + '<div class="mag-item-meta">'
                + '<span><iconify-icon icon="solar:calendar-linear"></iconify-icon> ' + esc(ag.start_date) + (ag.end_date ? ' s/d ' + esc(ag.end_date) : '') + '</span>'
                + (ag.start_time ? '<span><iconify-icon icon="solar:clock-circle-linear"></iconify-icon> ' + esc(ag.start_time) + ' WIB</span>' : '')
                + (ag.location ? '<span><iconify-icon icon="solar:map-point-linear"></iconify-icon> ' + esc(ag.location) + '</span>' : '')
                + '</div>'
                + '</div>'
                + '</article>';
        }).join('');
    }

    async function loadAll(openFocus) {
        try {
            var r = await fetch(BASE_URL + '/portal-data.php?resource=agenda&all=1', { credentials: 'same-origin' });
            var res = await r.json();
            if (res && res.success) {
                STATE.items = res.data || [];
                renderCalendar();
                renderList();
                if (openFocus && FOCUS_ID > 0) {
                    var found = STATE.items.find(function (x) { return x.id === FOCUS_ID; });
                    if (found) MARGO_AGENDA.openDetail(found.id);
                }
            }
        } catch (e) {}
    }

    async function adminPost(action, data) {
        var fd = new FormData();
        fd.append('api_action', action);
        fd.append('csrf_token', CSRF_ADMIN);
        Object.keys(data).forEach(function (k) { fd.append(k, data[k]); });
        var r = await fetch(ADMIN_API, { method: 'POST', body: fd, credentials: 'same-origin' });
        return await r.json();
    }

    window.MARGO_AGENDA = {
        shiftMonth: function (delta) {
            STATE.month += delta;
            if (STATE.month < 0) { STATE.month = 11; STATE.year--; }
            else if (STATE.month > 11) { STATE.month = 0; STATE.year++; }
            renderCalendar();
        },
        goToday: function () {
            STATE.year = now.getFullYear();
            STATE.month = now.getMonth();
            STATE.selectedDate = '';
            renderCalendar();
            renderList();
        },
        pickDate: function (dStr) {
            STATE.selectedDate = (STATE.selectedDate === dStr) ? '' : dStr;
            renderCalendar();
            renderList();
        },
        clearDateFilter: function () {
            STATE.selectedDate = '';
            renderCalendar();
            renderList();
        },
        openDetail: function (id) {
            var ag = STATE.items.find(function (x) { return x.id === id; });
            if (!ag) return;
            document.getElementById('detAgTitle').textContent = ag.title;
            document.getElementById('detAgDate').textContent = ag.start_date + (ag.end_date ? ' s/d ' + ag.end_date : '') + (ag.start_time ? ' · ' + ag.start_time + ' WIB' : '');
            document.getElementById('detAgBody').innerHTML =
                '<div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px;">'
                + catBadge(ag.category)
                + (ag.location ? '<span class="ilmuna-badge ilmuna-badge--navy"><iconify-icon icon="solar:map-point-bold"></iconify-icon> ' + esc(ag.location) + '</span>' : '')
                + '</div>'
                + '<p style="margin:0;font-size:14px;line-height:1.65;color:#334155;white-space:pre-line;">' + esc(ag.description || 'Tidak ada catatan tambahan.') + '</p>';
            var linkBtn = document.getElementById('detAgLinkBtn');
            if (ag.link_url) {
                linkBtn.href = ag.link_url;
                linkBtn.style.display = 'inline-flex';
                if (/^https?:\/\//i.test(ag.link_url) && ag.link_url.indexOf(window.location.origin) !== 0) {
                    linkBtn.target = '_blank'; linkBtn.rel = 'noopener noreferrer';
                } else {
                    linkBtn.removeAttribute('target'); linkBtn.removeAttribute('rel');
                }
            } else {
                linkBtn.style.display = 'none';
            }
            openIlmunaModal('modalAgendaDetail');
        },
        openFormModal: function (id) {
            if (!IS_ADMIN) return;
            var form = document.getElementById('formAgendaAdmin');
            form.reset();
            if (id) {
                var ag = STATE.items.find(function (x) { return x.id === id; });
                if (!ag) return;
                document.getElementById('frmAgHeading').textContent = 'Edit Agenda Ekosistem';
                document.getElementById('agId').value = ag.id;
                document.getElementById('agTitle').value = ag.title;
                document.getElementById('agStartDate').value = ag.start_date;
                document.getElementById('agEndDate').value = ag.end_date || '';
                document.getElementById('agStartTime').value = ag.start_time || '';
                document.getElementById('agCategory').value = ag.category || 'akademik';
                document.getElementById('agLocation').value = ag.location || '';
                document.getElementById('agLink').value = ag.link_url || '';
                document.getElementById('agDesc').value = ag.description || '';
            } else {
                document.getElementById('frmAgHeading').textContent = 'Tambah Agenda Baru';
                document.getElementById('agId').value = '0';
                document.getElementById('agStartDate').value = STATE.selectedDate || todayStr();
            }
            openIlmunaModal('modalAgendaForm');
        },
        saveAgenda: async function (e) {
            e.preventDefault();
            var res = await adminPost('save_agenda', {
                id: document.getElementById('agId').value,
                title: document.getElementById('agTitle').value.trim(),
                start_date: document.getElementById('agStartDate').value,
                end_date: document.getElementById('agEndDate').value,
                start_time: document.getElementById('agStartTime').value.trim(),
                category: document.getElementById('agCategory').value,
                location: document.getElementById('agLocation').value.trim(),
                link_url: document.getElementById('agLink').value.trim(),
                description: document.getElementById('agDesc').value.trim(),
                is_active: '1'
            });
            if (res.success) {
                closeIlmunaModal('modalAgendaForm');
                Ilmuna.success('Tersimpan', res.message);
                await loadAll(false);
                if (window.MargoShell) window.MargoShell.refreshRightWidgets();
            } else {
                Ilmuna.error('Gagal', res.message || 'Gagal menyimpan agenda.');
            }
        },
        deleteAgenda: async function (id) {
            var ok = await Ilmuna.confirm('error', 'Hapus Agenda Ini?', 'Agenda akan dihapus dari kalender dan panel samping seluruh pengguna.', 'Ya, Hapus', 'Batal');
            if (!ok) return;
            var res = await adminPost('delete_agenda', { id: id });
            if (res.success) {
                var card = document.getElementById('ag-card-' + id);
                if (card) card.classList.add('row-smooth-out');
                setTimeout(async function () {
                    await loadAll(false);
                    if (window.MargoShell) window.MargoShell.refreshRightWidgets();
                }, 260);
                Ilmuna.success('Terhapus', res.message);
            } else {
                Ilmuna.error('Gagal', res.message || 'Gagal menghapus agenda.');
            }
        }
    };

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('#agendaCatPills .ilmuna-pill-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                STATE.cat = btn.getAttribute('data-cat');
                document.querySelectorAll('#agendaCatPills .ilmuna-pill-btn').forEach(function (b) { b.classList.toggle('is-active', b === btn); });
                renderList();
            });
        });
        document.getElementById('agendaSearchInput').addEventListener('input', renderList);
        loadAll(true);
    });
})();
</script>

<?php require_once BASE_PATH . '/includes/footer.php'; ?>
