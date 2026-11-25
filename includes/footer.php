<?php
/**
 * ==============================================================================
 * MARGO ONE — ILMUNA SSO · UNIFIED 3-COLUMN INNER SHELL FOOTER & ENGINE
 * ==============================================================================
 */
if (!defined('BASE_PATH')) {
    exit;
}
$margoDefaultRightClosed = !empty($margoDefaultRightClosed);

// Muat data awal Right Sidebar (Agenda Mendatang terfilter sesuai Role + Kaldik & Info Ekosistem) langsung di server
$margoInitRightAgenda = [];
$margoInitRightNews   = [];
try {
    $pdoFooter = getDBConnection();
    $agendaEngPath = BASE_PATH . '/includes/agenda-engine.php';
    if (file_exists($agendaEngPath)) {
        require_once $agendaEngPath;
    }
    $footUser = function_exists('getCurrentUser') ? getCurrentUser() : null;
    if ($footUser && function_exists('margo_agenda_fetch_visible')) {
        $agListF = margo_agenda_fetch_visible($pdoFooter, $footUser, ['upcoming' => true, 'limit' => 12, 'skip_learn_sync' => true]);
        foreach ($agListF as $rF) {
            $margoInitRightAgenda[] = [
                'id'               => (int)$rF['id'],
                'title'            => (string)$rF['title'],
                'start_date'       => (string)$rF['start_date'],
                'year'             => (string)$rF['year'],
                'month_short'      => (string)$rF['month_short'],
                'day_num'          => (string)$rF['day_num'],
                'source'           => (string)$rF['source'],
                'color_hex'        => (string)($rF['color_hex'] ?? '#0D7A87'),
                'visibility_label' => (string)$rF['visibility_label'],
            ];
        }
    }

    $nwRowsF = $pdoFooter->query("
        SELECT id, title, excerpt, link_url, badge_label, published_at
        FROM sso_announcements
        WHERE is_active = 1
        ORDER BY published_at DESC, id DESC
        LIMIT 15
    ")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($nwRowsF as $nF) {
        $tsN = strtotime((string)$nF['published_at']);
        $rawLnk = trim((string)($nF['link_url'] ?? ''));
        $resLnk = ($rawLnk === '' || preg_match('#^https?://#i', $rawLnk))
            ? $rawLnk
            : (rtrim(BASE_URL, '/') . '/' . ltrim($rawLnk, '/'));
        $margoInitRightNews[] = [
            'id'           => (int)$nF['id'],
            'title'        => (string)$nF['title'],
            'excerpt'      => (string)($nF['excerpt'] ?? ''),
            'link_url'     => $resLnk,
            'badge_label'  => (string)($nF['badge_label'] ?: 'INFO'),
            'published_at' => (string)$nF['published_at'],
            'date_human'   => $tsN ? date('d M Y', $tsN) : '',
        ];
    }
} catch (Throwable $eFoot) {
    // Non-fatal
}
?>
            </div><!-- /.margo-main-inner -->

            <button type="button" id="scrollTopBtn" class="margo-scroll-top" aria-label="Kembali ke atas" title="Kembali ke atas">
                <iconify-icon icon="solar:arrow-up-bold"></iconify-icon>
            </button>
            <div class="margo-version-pill">Margo One v<?= htmlspecialchars(APP_VERSION, ENT_QUOTES, 'UTF-8') ?></div>
        </main>

        <?php require_once BASE_PATH . '/includes/right-sidebar.php'; ?>
    </div><!-- /.margo-shell -->

    <!-- GLOBAL LAZY-LOAD MODAL: DETAIL AGENDA MENDATANG (ZERO PAGE RELOAD DARI RIGHT SIDEBAR) -->
    <div class="ilm-modal-backdrop" id="margoRightAgendaModal">
        <div class="ilm-modal-dialog" style="max-width:540px;">
            <div class="ilm-modal-header">
                <div class="ilm-modal-header__brand">
                    <div class="ilm-modal-header__icon" id="mraIcon"><iconify-icon icon="solar:calendar-mark-bold"></iconify-icon></div>
                    <div>
                        <h3 class="ilm-modal-header__title" id="mraTitle">Memuat Agenda...</h3>
                        <p class="ilm-modal-header__subtitle" id="mraDate">Mengambil rincian agenda secara lazy-load</p>
                    </div>
                </div>
                <button type="button" class="ilm-modal-header__close" onclick="closeIlmunaModal('margoRightAgendaModal')">
                    <iconify-icon icon="solar:close-circle-bold"></iconify-icon>
                </button>
            </div>
            <div class="ilm-modal-body" id="mraBody">
                <div class="margo-skel" style="height:24px;width:55%;margin-bottom:12px;"></div>
                <div class="margo-skel" style="height:16px;width:95%;margin-bottom:8px;"></div>
                <div class="margo-skel" style="height:16px;width:80%;"></div>
            </div>
            <div class="ilm-modal-footer" id="mraFooter">
                <button type="button" class="ilm-modal-btn ilm-modal-btn--ghost" onclick="closeIlmunaModal('margoRightAgendaModal')">Tutup</button>
            </div>
        </div>
    </div>

    <!-- GLOBAL POPUP DETAIL NOTIFIKASI (KLIK NOTIFIKASI -> POPUP DETAIL LENGKAP) -->
    <div class="ilm-modal-backdrop" id="margoNotifDetailModal">
        <div class="ilm-modal-dialog" style="max-width:520px;">
            <div class="ilm-modal-header">
                <div class="ilm-modal-header__brand">
                    <div class="ilm-modal-header__icon" id="mndIcon"><iconify-icon icon="solar:bell-bing-bold"></iconify-icon></div>
                    <div style="min-width:0;">
                        <h3 class="ilm-modal-header__title" id="mndTitle">Detail Notifikasi</h3>
                        <p class="ilm-modal-header__subtitle" id="mndSub">Ekosistem Margo One</p>
                    </div>
                </div>
                <button type="button" class="ilm-modal-header__close" onclick="closeIlmunaModal('margoNotifDetailModal')">
                    <iconify-icon icon="solar:close-circle-bold"></iconify-icon>
                </button>
            </div>
            <div class="ilm-modal-body" id="mndBody"></div>
            <div class="ilm-modal-footer" id="mndFooter">
                <button type="button" class="ilm-modal-btn ilm-modal-btn--ghost" onclick="closeIlmunaModal('margoNotifDetailModal')">Tutup</button>
            </div>
        </div>
    </div>

    <script src="<?= htmlspecialchars(BASE_URL . '/assets/js/lumina-lms.js', ENT_QUOTES, 'UTF-8') ?>"></script>
    <script src="<?= htmlspecialchars(BASE_URL . '/assets/js/alert.js', ENT_QUOTES, 'UTF-8') ?>"></script>
    <?php require_once BASE_PATH . '/includes/alert.php'; ilmuna_render_alerts(); ?>

    <script>
    function margoTriggerLogout() {
        var logoutUrl = <?= json_encode(BASE_URL . '/logout') ?>;
        if (window.Ilmuna && typeof window.Ilmuna.logout === 'function') {
            window.Ilmuna.logout({
                url: logoutUrl,
                title: 'Keluar dari Seluruh Ekosistem?',
                message: 'Single Logout (SLO) akan mengakhiri sesi Margo One Anda di seluruh aplikasi terhubung.',
                confirmText: 'Ya, Keluar Semua',
                cancelText: 'Batal',
                type: 'logout'
            });
        } else {
            window.location.href = logoutUrl;
        }
    }

    (function () {
        'use strict';
        var BASE_URL = <?= json_encode(BASE_URL) ?>;
        var DEFAULT_RIGHT_CLOSED = <?= $margoDefaultRightClosed ? 'true' : 'false' ?>;

        // 1. Entry slide smooth sweep
        var entrySlide = document.getElementById('entrySlide');
        if (entrySlide) {
            requestAnimationFrame(function () {
                setTimeout(function () { entrySlide.classList.add('is-done'); }, 60);
            });
        }

        // 2. Persist <details> sidebar accordion toggle changes in localStorage
        document.querySelectorAll('details[data-sidebar-dropdown]').forEach(function (det) {
            var key = 'margo.sidebar.dropdown.' + det.getAttribute('data-sidebar-dropdown');
            det.addEventListener('toggle', function () {
                try { localStorage.setItem(key, det.open ? 'open' : 'closed'); } catch (e) {}
            });
        });

        // 3. Left & Right Collapsible Sidebars (Desktop state + Mobile drawers)
        var leftSidebar  = document.getElementById('leftSidebar');
        var rightSidebar = document.getElementById('rightSidebar');
        var overlay      = document.getElementById('sidebarOverlay');
        var leftToggle   = document.getElementById('leftSidebarToggle');
        var rightToggle  = document.getElementById('rightSidebarToggle');

        function isDesktop() { return window.innerWidth >= 1024; }

        function closeMobileDrawers() {
            if (leftSidebar) leftSidebar.classList.remove('is-open-mobile');
            if (rightSidebar) rightSidebar.classList.remove('is-open-mobile');
            if (overlay) overlay.classList.remove('is-open');
        }

        function applyDesktopSidebarPrefs() {
            if (!isDesktop()) return;
            closeMobileDrawers();
            try {
                var lSaved = localStorage.getItem('margo.leftSidebarClosed');
                if (leftSidebar) leftSidebar.classList.toggle('is-collapsed-desktop', lSaved === 'true');

                var rSaved = localStorage.getItem('margo.rightSidebarClosed');
                var rClosed = (rSaved !== null)
                    ? (rSaved === 'true')
                    : (DEFAULT_RIGHT_CLOSED && window.innerWidth < 1360);
                if (rightSidebar) rightSidebar.classList.toggle('is-collapsed-desktop', rClosed);
            } catch (e) {}
        }

        applyDesktopSidebarPrefs();
        window.addEventListener('resize', function () {
            if (isDesktop()) applyDesktopSidebarPrefs();
        });

        if (leftToggle && leftSidebar) {
            leftToggle.addEventListener('click', function () {
                if (isDesktop()) {
                    var collapsed = leftSidebar.classList.toggle('is-collapsed-desktop');
                    try { localStorage.setItem('margo.leftSidebarClosed', collapsed ? 'true' : 'false'); } catch (e) {}
                } else {
                    var openNow = !leftSidebar.classList.contains('is-open-mobile');
                    closeMobileDrawers();
                    if (openNow) {
                        leftSidebar.classList.add('is-open-mobile');
                        if (overlay) overlay.classList.add('is-open');
                    }
                }
            });
        }

        if (rightToggle && rightSidebar) {
            rightToggle.addEventListener('click', function () {
                if (isDesktop()) {
                    var collapsed = rightSidebar.classList.toggle('is-collapsed-desktop');
                    try { localStorage.setItem('margo.rightSidebarClosed', collapsed ? 'true' : 'false'); } catch (e) {}
                } else {
                    var openNow = !rightSidebar.classList.contains('is-open-mobile');
                    closeMobileDrawers();
                    if (openNow) {
                        rightSidebar.classList.add('is-open-mobile');
                        if (overlay) overlay.classList.add('is-open');
                    }
                }
            });
        }

        if (overlay) {
            overlay.addEventListener('click', closeMobileDrawers);
        }
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') closeMobileDrawers();
        });

        // 4. Right Sidebar Profile Dropdown toggle
        var profWrap = document.getElementById('margoRightProfileWrap');
        var profBtn  = document.getElementById('margoRightProfileBtn');
        if (profWrap && profBtn) {
            profBtn.addEventListener('click', function (e) {
                e.stopPropagation();
                var open = profWrap.classList.toggle('is-open');
                profBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
            });
        }

        // 5. Sticky Header Morph + Scroll-to-Top FAB on #mainContent scroll
        var mainEl     = document.getElementById('mainContent');
        var topHeader  = document.getElementById('topHeader');
        var scrollBtn  = document.getElementById('scrollTopBtn');
        if (mainEl) {
            mainEl.addEventListener('scroll', function () {
                var y = mainEl.scrollTop;
                if (topHeader) topHeader.classList.toggle('is-scrolled', y > 45);
                if (scrollBtn) scrollBtn.classList.toggle('is-visible', y > 240);
            }, { passive: true });
        }
        if (scrollBtn && mainEl) {
            scrollBtn.addEventListener('click', function () {
                mainEl.scrollTo({ top: 0, behavior: 'smooth' });
            });
        }

        // 6. Right Sidebar Live Widgets: Agenda & Info Ekosistem (Stale-While-Revalidate)
        function escHtml(s) {
            return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
                return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c];
            });
        }

        var rightAgendaCache = {};
        function renderRightAgenda(items) {
            var strip = document.getElementById('margoRightAgendaStrip');
            if (!strip) return;
            if (!items || !items.length) {
                strip.innerHTML = '<div style="font-size:12px;color:#64748B;padding:12px 4px;">Belum ada agenda mendatang.</div>';
                return;
            }
            strip.innerHTML = items.map(function (ag, idx) {
                var cls = idx === 0 ? 'margo-agenda-card is-featured' : 'margo-agenda-card';
                var accent = (ag.color_hex && /^#[0-9A-Fa-f]{6}$/.test(ag.color_hex)) ? ag.color_hex : '';
                var stAttr = (idx > 0 && accent) ? (' style="border-top:3.5px solid ' + accent + ';"') : '';
                return '<button type="button" class="' + cls + '"' + stAttr + ' onclick="MargoShell.openRightAgenda(' + Number(ag.id) + ')" title="' + escHtml(ag.title) + '">'
                    + '<span class="margo-agenda-year">' + escHtml(ag.year) + '</span>'
                    + '<span class="margo-agenda-month">' + escHtml(ag.month_short) + '</span>'
                    + '<span class="margo-agenda-day">' + escHtml(ag.day_num) + '</span>'
                    + '<span class="margo-agenda-short">' + escHtml(ag.title) + '</span>'
                    + '</button>';
            }).join('');
        }

        function renderRightAgendaModalContent(ag) {
            var hex = (ag.color_hex && /^#[0-9A-Fa-f]{6}$/.test(ag.color_hex)) ? ag.color_hex : '#0D7A87';
            var isKaldik = (ag.source === 'kaldik' || Number(ag.is_locked) === 1);
            var iconEl = document.getElementById('mraIcon');
            if (iconEl) {
                iconEl.style.background = hex;
                iconEl.style.color = '#FFFFFF';
                iconEl.innerHTML = isKaldik
                    ? '<iconify-icon icon="solar:lock-keyhole-bold"></iconify-icon>'
                    : '<iconify-icon icon="solar:calendar-mark-bold"></iconify-icon>';
            }
            document.getElementById('mraTitle').textContent = ag.title || 'Detail Agenda';
            document.getElementById('mraDate').textContent = (ag.start_date || '-')
                + (ag.end_date && ag.end_date !== ag.start_date ? ' s/d ' + ag.end_date : '')
                + (ag.start_time ? ' · ' + ag.start_time + ' WIB' : '');

            var bodyHtml = '';
            if (isKaldik) {
                bodyHtml += '<div style="display:flex;align-items:flex-start;gap:9px;padding:10px 12px;border-radius:11px;background:rgba(29,59,94,0.06);border:1px solid rgba(29,59,94,0.18);color:#1D3B5E;font-size:12px;line-height:1.45;margin-bottom:12px;">'
                    + '<iconify-icon icon="solar:lock-keyhole-bold" width="18" style="color:' + hex + ';flex-shrink:0;margin-top:1px;"></iconify-icon>'
                    + '<div><strong>Kalender Pendidikan Resmi (Kaldik · Read-Only)</strong><br>Tersinkron otomatis dari Ilmuna Learn dan tidak dapat diubah.</div></div>';
            }
            var catLabel = ag.status_hari || (ag.category ? (ag.category.charAt(0).toUpperCase() + ag.category.slice(1)) : 'Agenda');
            bodyHtml += '<div style="display:flex;gap:7px;flex-wrap:wrap;margin-bottom:12px;">'
                + '<span class="ilmuna-badge" style="background:' + hex + '22;color:' + hex + ';border:1px solid ' + hex + '55;">'
                + '<span style="display:inline-block;width:7px;height:7px;border-radius:50%;background:' + hex + ';margin-right:4px;"></span>' + escHtml(catLabel) + '</span>'
                + '<span class="ilmuna-badge ilmuna-badge--navy">' + escHtml(ag.visibility_label || 'Semua Orang') + '</span>'
                + (ag.location ? '<span class="ilmuna-badge ilmuna-badge--teal"><iconify-icon icon="solar:map-point-bold"></iconify-icon> ' + escHtml(ag.location) + '</span>' : '')
                + '</div>'
                + '<p style="margin:0 0 10px;font-size:13.5px;line-height:1.65;color:#334155;white-space:pre-line;">' + escHtml(ag.description || 'Tidak ada catatan tambahan.') + '</p>'
                + (ag.created_by_name ? '<div style="font-size:12px;color:#64748B;">Dibuat oleh: <strong style="color:#1D3B5E;">' + escHtml(ag.created_by_name) + '</strong></div>' : '');

            document.getElementById('mraBody').innerHTML = bodyHtml;

            var foot = '<button type="button" class="ilm-modal-btn ilm-modal-btn--ghost" onclick="closeIlmunaModal(\'margoRightAgendaModal\')">Tutup</button>';
            if (ag.link_url) {
                var isExt = /^https?:\/\//i.test(ag.link_url) && ag.link_url.indexOf(window.location.origin) !== 0;
                foot += '<a href="' + escHtml(ag.link_url) + '" class="ilm-modal-btn ilm-modal-btn--ghost"' + (isExt ? ' target="_blank" rel="noopener noreferrer"' : '') + '><iconify-icon icon="solar:arrow-right-up-bold"></iconify-icon><span>Buka Tautan</span></a>';
            }
            foot += '<a href="' + escHtml(BASE_URL + '/agenda?id=' + ag.id) + '" class="ilm-modal-btn ilm-modal-btn--primary"><iconify-icon icon="solar:calendar-mark-bold"></iconify-icon><span>Lihat di Kalender</span></a>';
            document.getElementById('mraFooter').innerHTML = foot;
        }

        var allNewsItems = [];
        var newsExpanded = false;
        function renderRightNews(items) {
            allNewsItems = items || [];
            var box = document.getElementById('margoRightNewsList');
            var btn = document.getElementById('margoNewsToggleBtn');
            if (!box) return;
            if (!allNewsItems.length) {
                box.innerHTML = '<div style="font-size:12px;color:#64748B;padding:8px 2px;">Belum ada pengumuman ekosistem.</div>';
                if (btn) btn.style.display = 'none';
                return;
            }
            var shown = newsExpanded ? allNewsItems : allNewsItems.slice(0, 3);
            box.innerHTML = shown.map(function (n) {
                var badgeCls = (n.badge_label === 'KEAMANAN' || n.badge_label === 'PENTING') ? 'margo-news-badge margo-news-badge--gold' : 'margo-news-badge';
                var href = n.link_url || (BASE_URL + '/gateway');
                var isExt = /^https?:\/\//i.test(href) && href.indexOf(window.location.origin) !== 0;
                return '<a class="margo-news-card" href="' + escHtml(href) + '"' + (isExt ? ' target="_blank" rel="noopener noreferrer"' : '') + '>'
                    + '<div class="margo-news-meta">'
                    + '<span class="' + badgeCls + '">' + escHtml(n.badge_label || 'INFO') + '</span>'
                    + '<span class="margo-news-date"><iconify-icon icon="solar:clock-circle-linear" width="12"></iconify-icon>' + escHtml(n.date_human || '') + '</span>'
                    + '</div>'
                    + '<div class="margo-news-title">' + escHtml(n.title) + '</div>'
                    + (n.excerpt ? '<div class="margo-news-excerpt">' + escHtml(n.excerpt) + '</div>' : '')
                    + '</a>';
            }).join('');
            if (btn) {
                if (allNewsItems.length > 3) {
                    btn.style.display = 'block';
                    btn.textContent = newsExpanded ? 'Tampilkan lebih sedikit' : ('Muat lainnya (' + (allNewsItems.length - 3) + ')');
                } else {
                    btn.style.display = 'none';
                }
            }
        }

        var newsBtn = document.getElementById('margoNewsToggleBtn');
        if (newsBtn) {
            newsBtn.addEventListener('click', function () {
                newsExpanded = !newsExpanded;
                renderRightNews(allNewsItems);
            });
        }

        window.MargoShell = {
            openRightAgenda: function (id) {
                id = Number(id || 0);
                if (!id) return;
                // Jika sedang di halaman /agenda, langsung fokus & buka detail modal tanpa reload!
                if (window.MARGO_AGENDA && typeof window.MARGO_AGENDA.focusAgenda === 'function') {
                    window.MARGO_AGENDA.focusAgenda(id);
                    return;
                }
                if (typeof openIlmunaModal === 'function') {
                    openIlmunaModal('margoRightAgendaModal');
                }
                if (rightAgendaCache[id]) {
                    renderRightAgendaModalContent(rightAgendaCache[id]);
                    return;
                }
                document.getElementById('mraTitle').textContent = 'Memuat Detail Agenda...';
                document.getElementById('mraDate').textContent = 'Mengambil data terbaru...';
                document.getElementById('mraBody').innerHTML =
                    '<div class="margo-skel" style="height:24px;width:55%;margin-bottom:12px;"></div>'
                    + '<div class="margo-skel" style="height:16px;width:95%;margin-bottom:8px;"></div>'
                    + '<div class="margo-skel" style="height:16px;width:78%;"></div>';
                document.getElementById('mraFooter').innerHTML =
                    '<button type="button" class="ilm-modal-btn ilm-modal-btn--ghost" onclick="closeIlmunaModal(\'margoRightAgendaModal\')">Tutup</button>';

                fetch(BASE_URL + '/portal-data.php?resource=agenda_detail&id=' + encodeURIComponent(id), {
                    credentials: 'same-origin',
                    headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
                })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    if (res && res.success && res.data) {
                        rightAgendaCache[id] = res.data;
                        renderRightAgendaModalContent(res.data);
                    } else {
                        document.getElementById('mraTitle').textContent = 'Agenda Tidak Tersedia';
                        document.getElementById('mraDate').textContent = '-';
                        document.getElementById('mraBody').innerHTML = '<div style="font-size:13px;color:#64748B;">' + escHtml((res && res.message) || 'Agenda tidak ditemukan.') + '</div>';
                    }
                })
                .catch(function () {
                    document.getElementById('mraTitle').textContent = 'Gagal Memuat';
                    document.getElementById('mraBody').innerHTML = '<div style="font-size:13px;color:#64748B;">Periksa koneksi lalu coba lagi.</div>';
                });
            },
            refreshRightWidgets: function () {
                rightAgendaCache = {};
                var ajaxOpts = {
                    credentials: 'same-origin',
                    headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
                };
                fetch(BASE_URL + '/portal-data.php?resource=agenda', ajaxOpts)
                    .then(function (r) { return r.json(); })
                    .then(function (res) {
                        if (res && res.success) {
                            renderRightAgenda(res.data);
                            try { localStorage.setItem('margo.cache.agenda', JSON.stringify(res.data)); } catch (e) {}
                        }
                    }).catch(function () {});

                fetch(BASE_URL + '/portal-data.php?resource=announcements', ajaxOpts)
                    .then(function (r) { return r.json(); })
                    .then(function (res) {
                        if (res && res.success) {
                            renderRightNews(res.data);
                            try { localStorage.setItem('margo.cache.news', JSON.stringify(res.data)); } catch (e) {}
                        }
                    }).catch(function () {});
            }
        };

        // Anti-download & anti-drag untuk seluruh logo / gambar brand di portal
        document.addEventListener('contextmenu', function (e) {
            if (e.target && (e.target.tagName === 'IMG' || e.target.closest('.margo-hero-banner, .margo-hero-logo-showcase, .margo-left-brand, .margo-app-icon'))) {
                e.preventDefault();
            }
        });
        document.addEventListener('dragstart', function (e) {
            if (e.target && (e.target.tagName === 'IMG' || e.target.closest('.margo-hero-banner, .margo-hero-logo-showcase, .margo-left-brand, .margo-app-icon'))) {
                e.preventDefault();
            }
        });

        // Hydrate instantly from server-rendered PHP data (zero extra network calls needed on page load)
        var INIT_RIGHT_AGENDA = <?= json_encode($margoInitRightAgenda, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
        var INIT_RIGHT_NEWS   = <?= json_encode($margoInitRightNews, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
        renderRightAgenda(INIT_RIGHT_AGENDA);
        renderRightNews(INIT_RIGHT_NEWS);
    })();
    </script>

    <!-- ── ILMUNA LEARN REAL-TIME WEBSOCKET ENGINE (WhatsApp-Style Notifications) ── -->
    <script>
    (function () {
        'use strict';

        /* ── Bootstrap data dari PHP server (zero extra request saat halaman load) ── */
        var WS_BOOT = <?= json_encode($margoLearnWsBootstrap ?? null, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
        var INIT_EVENTS = <?= json_encode($margoLearnNotifEvents ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
        var BASE_URL = <?= json_encode(defined('BASE_URL') ? BASE_URL : '', JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

        /* ── State ── */
        var ws           = null;
        var reconnTimer  = null;
        var reconnDelay  = 2000;
        var maxDelay     = 60000;
        var unreadCount  = 0;
        var drawerOpen   = false;
        var audioCtx     = null;
        var activeFilter = 'all';
        var allNotifs    = [];   // [{uid, id, group, type, badge, title, sub, full_body, kelas, location, date_label, color, icon, url, ts, unread}]
        var swReg        = null;

        /* ── DOM refs ── */
        var bellBtn   = document.getElementById('margoLearnBellBtn');
        var badge     = document.getElementById('margoLearnBadge');
        var liveDot   = document.getElementById('margoLearnLiveDot');
        var drawer    = document.getElementById('margoLearnDrawer');
        var notifList = document.getElementById('margoLearnNotifList');
        var pushBar   = document.getElementById('margoPushBar');
        var pushTxt   = document.getElementById('margoPushStatusText');
        var pushBtn   = document.getElementById('margoPushToggleBtn');

        /* ── Local read/dismissed storage ── */
        function loadIdSet(key) {
            try {
                var arr = JSON.parse(localStorage.getItem(key) || '[]');
                return Array.isArray(arr) ? arr : [];
            } catch (e) { return []; }
        }
        function saveIdSet(key, arr) {
            try { localStorage.setItem(key, JSON.stringify(arr.slice(-120))); } catch (e) {}
        }
        var readIds      = loadIdSet('margo.notif.readIds');
        var dismissedIds = loadIdSet('margo.notif.dismissedIds');

        function isRead(uid) { return readIds.indexOf(String(uid)) !== -1; }
        function markRead(uid) {
            var s = String(uid);
            if (readIds.indexOf(s) === -1) {
                readIds.push(s);
                saveIdSet('margo.notif.readIds', readIds);
            }
        }

        /* ── Helpers ── */
        function esc(s) {
            return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
                return ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' })[c];
            });
        }
        function relTime(ts) {
            if (!ts) return 'Baru saja';
            var diff = Math.floor((Date.now() - ts) / 1000);
            if (diff < 0) return 'Mendatang';
            if (diff < 60) return 'Baru saja';
            if (diff < 3600) return Math.floor(diff/60) + ' mnt lalu';
            if (diff < 86400) return Math.floor(diff/3600) + ' jam lalu';
            return Math.floor(diff/86400) + ' hari lalu';
        }
        function typeIcon(type) {
            var m = {
                'tugas_buat':      'solar:document-add-bold',
                'tugas_deadline':  'solar:clock-circle-bold',
                'proyek_buat':     'solar:rocket-bold',
                'proyek_deadline': 'solar:hourglass-bold',
                'asesmen':         'solar:test-tube-bold',
                'kelompok':        'solar:users-group-rounded-bold',
                'agenda_today':    'solar:calendar-date-bold',
                'agenda_tomorrow': 'solar:calendar-mark-bold',
                'agenda_soon':     'solar:calendar-bold',
                'ecosystem_info':  'solar:megaphone-bold',
            };
            return m[type] || 'solar:bell-bing-bold';
        }
        function typeColor(type) {
            var m = {
                'tugas_buat':      '#0D7A87',
                'tugas_deadline':  '#EF4444',
                'proyek_buat':     '#7C3AED',
                'proyek_deadline': '#D97706',
                'asesmen':         '#EA580C',
                'kelompok':        '#52B2DC',
                'agenda_today':    '#EF4444',
                'agenda_tomorrow': '#F59E0B',
                'agenda_soon':     '#0D7A87',
                'ecosystem_info':  '#1D3B5E',
            };
            return m[type] || '#1D3B5E';
        }
        function typeBadgeLabel(type) {
            var m = {
                'tugas_buat':      'TUGAS KELAS',
                'tugas_deadline':  'DEADLINE TUGAS',
                'proyek_buat':     'PROYEK KELAS',
                'proyek_deadline': 'DEADLINE PROYEK',
                'asesmen':         'ASESMEN KELAS',
                'kelompok':        'KELOMPOK KELAS',
                'agenda_today':    'AGENDA HARI INI',
                'agenda_tomorrow': 'AGENDA BESOK',
                'agenda_soon':     'AGENDA MENDATANG',
                'ecosystem_info':  'INFO EKOSISTEM',
            };
            return m[type] || 'NOTIFIKASI';
        }

        function recalcUnread() {
            unreadCount = 0;
            for (var i = 0; i < allNotifs.length; i++) {
                if (allNotifs[i].unread) unreadCount++;
            }
            updateBadge();
        }

        /* ── Badge update ── */
        function updateBadge() {
            if (!badge) return;
            if (unreadCount > 0) {
                badge.textContent = unreadCount > 99 ? '99+' : String(unreadCount);
                badge.style.display = '';
            } else {
                badge.style.display = 'none';
            }
        }

        /* ── Upsert notifikasi unik ── */
        function upsertNotif(n, showPopupToast) {
            if (!n || !n.uid) return;
            var uid = String(n.uid);
            if (dismissedIds.indexOf(uid) !== -1) return;

            var existsIdx = -1;
            for (var i = 0; i < allNotifs.length; i++) {
                if (String(allNotifs[i].uid) === uid) { existsIdx = i; break; }
            }
            n.uid   = uid;
            n.group = n.group || (n.type && n.type.indexOf('agenda') === 0 ? 'agenda' : (n.type === 'ecosystem_info' ? 'info' : 'kelas'));
            n.badge = n.badge || typeBadgeLabel(n.type);
            n.color = n.color || typeColor(n.type);
            n.icon  = n.icon  || typeIcon(n.type);
            n.unread = !isRead(uid);

            if (existsIdx >= 0) {
                allNotifs[existsIdx] = Object.assign({}, allNotifs[existsIdx], n, { unread: allNotifs[existsIdx].unread && !isRead(uid) });
            } else {
                allNotifs.push(n);
                if (allNotifs.length > 60) allNotifs = allNotifs.slice(-60);
                if (showPopupToast && n.unread) {
                    showToast(n);
                }
            }
            recalcUnread();
        }

        /* ── Render drawer list ── */
        function renderDrawer() {
            if (!notifList) return;
            var filtered = allNotifs.filter(function (n) {
                if (activeFilter === 'all') return true;
                return n.group === activeFilter;
            });
            filtered.sort(function (a, b) {
                if ((a.priority || 5) !== (b.priority || 5) && a.group === 'agenda' && b.group === 'agenda') {
                    return (a.priority || 5) - (b.priority || 5);
                }
                return (b.ts || 0) - (a.ts || 0);
            });

            if (filtered.length === 0) {
                var empLabel = activeFilter === 'kelas' ? 'Belum ada notifikasi kelas'
                    : (activeFilter === 'agenda' ? 'Tidak ada agenda hari ini atau besok'
                    : (activeFilter === 'info' ? 'Belum ada info ekosistem' : 'Belum ada notifikasi'));
                notifList.innerHTML = '<div class="mld-empty" id="margoLearnEmptyState">'
                    + '<iconify-icon icon="solar:bell-off-bold" width="28" style="opacity:.35;color:#0D7A87;"></iconify-icon>'
                    + '<span>' + empLabel + '</span></div>';
                return;
            }
            var html = '';
            filtered.forEach(function (n) {
                var col = n.color || typeColor(n.type);
                var ico = n.icon || typeIcon(n.type);
                var bdg = n.badge || typeBadgeLabel(n.type);
                html += '<div class="mld-item' + (n.unread ? ' is-unread' : '') + '" onclick="MargoLearnWS.openDetail(' + JSON.stringify(String(n.uid)) + ')">'
                    + '<div class="mld-item-icon" style="background:' + col + ';">'
                    + '<iconify-icon icon="' + ico + '" width="18"></iconify-icon></div>'
                    + '<div class="mld-item-body">'
                    + '<span class="mld-item-badge" style="background:' + col + '18;color:' + col + ';">' + esc(bdg) + '</span>'
                    + '<div class="mld-item-title">' + esc(n.title) + '</div>'
                    + '<div class="mld-item-sub">' + esc(n.sub || '') + '</div>'
                    + '<div class="mld-item-time">' + esc(n.date_label || relTime(n.ts)) + (n.kelas ? ' · <strong>' + esc(n.kelas) + '</strong>' : '') + '</div>'
                    + '</div></div>';
            });
            notifList.innerHTML = html;
        }

        /* ── Buka Popup Detail Notifikasi ── */
        function openDetailModal(notif) {
            if (!notif) return;
            markRead(notif.uid);
            notif.unread = false;
            recalcUnread();
            renderDrawer();
            drawerOpen = false;
            if (drawer) drawer.style.display = 'none';

            var col = notif.color || typeColor(notif.type);
            var ico = notif.icon || typeIcon(notif.type);
            var bdg = notif.badge || typeBadgeLabel(notif.type);

            var icoEl   = document.getElementById('mndIcon');
            var titleEl = document.getElementById('mndTitle');
            var subEl   = document.getElementById('mndSub');
            var bodyEl  = document.getElementById('mndBody');
            var footEl  = document.getElementById('mndFooter');

            if (icoEl) {
                icoEl.style.background = col;
                icoEl.innerHTML = '<iconify-icon icon="' + esc(ico) + '"></iconify-icon>';
            }
            if (titleEl) titleEl.textContent = notif.title || 'Detail Notifikasi';
            if (subEl) subEl.textContent = bdg + (notif.date_label ? (' · ' + notif.date_label) : '');

            if (bodyEl) {
                var chips = '<div style="display:flex;flex-wrap:wrap;gap:6px;margin-bottom:14px;">'
                    + '<span style="font-size:11px;font-weight:800;padding:4px 10px;border-radius:999px;background:' + col + '18;color:' + col + ';">' + esc(bdg) + '</span>';
                if (notif.kelas) {
                    chips += '<span style="font-size:11px;font-weight:700;padding:4px 10px;border-radius:999px;background:#F1F5F9;color:#1D3B5E;">' + esc(notif.kelas) + '</span>';
                }
                if (notif.location) {
                    chips += '<span style="font-size:11px;font-weight:600;padding:4px 10px;border-radius:999px;background:#F1F5F9;color:#475569;">📍 ' + esc(notif.location) + '</span>';
                }
                chips += '</div>';

                var detailText = notif.full_body || notif.sub || 'Tidak ada keterangan tambahan.';
                bodyEl.innerHTML = chips
                    + '<div style="font-size:13.5px;line-height:1.68;color:#334155;white-space:pre-line;background:#F8FAFC;border:1px solid #E2E8F0;border-radius:14px;padding:14px 16px;">'
                    + esc(detailText)
                    + '</div>';
            }

            if (footEl) {
                var fHtml = '<button type="button" class="ilm-modal-btn ilm-modal-btn--ghost" onclick="closeIlmunaModal(\'margoNotifDetailModal\')">Tutup</button>';
                if (notif.url && notif.url !== '') {
                    var isExt = notif.url.indexOf(window.location.origin) !== 0 && notif.url.indexOf('http') === 0;
                    fHtml += '<a href="' + esc(notif.url) + '"' + (isExt ? ' target="_blank" rel="noopener noreferrer"' : '') + ' class="ilm-modal-btn ilm-modal-btn--primary" style="text-decoration:none;display:inline-flex;align-items:center;gap:6px;">'
                        + '<span>Buka Halaman Terkait</span><iconify-icon icon="solar:arrow-right-up-bold" width="16"></iconify-icon></a>';
                }
                footEl.innerHTML = fHtml;
            }

            if (typeof openIlmunaModal === 'function') {
                openIlmunaModal('margoNotifDetailModal');
            } else {
                var modalEl = document.getElementById('margoNotifDetailModal');
                if (modalEl) modalEl.classList.add('is-open');
            }
        }

        /* ── Custom Notification Sound (Sunrise Pendopo MP3 — gaya DANA / BRImo) + Web Audio Fallback ── */
        var NOTIF_SOUND_URL = BASE_URL + '/assets/audio/notif-margo-one.mp3';
        var notifAudioEl = null;
        var audioUnlocked = false;

        function initNotifAudio() {
            if (!notifAudioEl && typeof window.Audio !== 'undefined') {
                try {
                    notifAudioEl = new Audio(NOTIF_SOUND_URL);
                    notifAudioEl.preload = 'auto';
                    notifAudioEl.volume = 1.0;
                } catch (eA) {}
            }
        }

        function unlockNotifAudioOnce() {
            if (audioUnlocked) return;
            audioUnlocked = true;
            initNotifAudio();
            if (notifAudioEl) {
                try {
                    notifAudioEl.muted = true;
                    var p = notifAudioEl.play();
                    if (p && typeof p.then === 'function') {
                        p.then(function () {
                            notifAudioEl.pause();
                            notifAudioEl.currentTime = 0;
                            notifAudioEl.muted = false;
                        }).catch(function () {
                            notifAudioEl.muted = false;
                        });
                    } else {
                        notifAudioEl.pause();
                        notifAudioEl.currentTime = 0;
                        notifAudioEl.muted = false;
                    }
                } catch (eU) {
                    notifAudioEl.muted = false;
                }
            }
        }
        document.addEventListener('pointerdown', unlockNotifAudioOnce, { once: true, passive: true });
        document.addEventListener('touchstart', unlockNotifAudioOnce, { once: true, passive: true });
        document.addEventListener('keydown', unlockNotifAudioOnce, { once: true, passive: true });

        function playChime() {
            initNotifAudio();
            if (notifAudioEl) {
                try {
                    notifAudioEl.muted = false;
                    notifAudioEl.volume = 1.0;
                    notifAudioEl.currentTime = 0;
                    var playProm = notifAudioEl.play();
                    if (playProm && typeof playProm.catch === 'function') {
                        playProm.catch(function () { playFallbackSynthChime(); });
                    }
                    return;
                } catch (ePlay) {}
            }
            playFallbackSynthChime();
        }

        function playFallbackSynthChime() {
            try {
                if (!audioCtx) {
                    audioCtx = new (window.AudioContext || window.webkitAudioContext)();
                }
                if (audioCtx.state === 'suspended') audioCtx.resume();
                var osc = audioCtx.createOscillator();
                var gain = audioCtx.createGain();
                osc.connect(gain);
                gain.connect(audioCtx.destination);
                osc.type = 'sine';
                osc.frequency.setValueAtTime(880, audioCtx.currentTime);
                osc.frequency.exponentialRampToValueAtTime(660, audioCtx.currentTime + 0.12);
                osc.frequency.exponentialRampToValueAtTime(880, audioCtx.currentTime + 0.24);
                gain.gain.setValueAtTime(0.08, audioCtx.currentTime);
                gain.gain.exponentialRampToValueAtTime(0.0001, audioCtx.currentTime + 0.4);
                osc.start(audioCtx.currentTime);
                osc.stop(audioCtx.currentTime + 0.4);
            } catch (e) {}
        }

        /* ── WA-style floating toast ── */
        function showToast(notif) {
            var col = typeColor(notif.type);
            var ico = typeIcon(notif.type);
            var el = document.createElement('div');
            el.className = 'margo-learn-toast';
            el.style.borderLeftColor = col;
            el.innerHTML = '<div class="mlt-icon" style="background:' + col + ';">'
                + '<iconify-icon icon="' + ico + '"></iconify-icon></div>'
                + '<div class="mlt-body">'
                + '<div class="mlt-label">Ilmuna Learn' + (notif.kelas ? ' · ' + esc(notif.kelas) : '') + '</div>'
                + '<div class="mlt-title">' + esc(notif.title) + '</div>'
                + (notif.sub ? '<div class="mlt-sub">' + esc(notif.sub) + '</div>' : '')
                + '</div>'
                + '<button class="mlt-close" title="Tutup">&times;</button>';
            el.querySelector('.mlt-close').addEventListener('click', function (e) {
                e.stopPropagation();
                dismissToast(el);
            });
            el.addEventListener('click', function () {
                MargoLearnWS.openDetail(String(notif.uid || notif.id));
                dismissToast(el);
            });
            document.body.appendChild(el);
            playChime();

            /* Browser Web Notification (bila diizinkan) */
            if (window.Notification && Notification.permission === 'granted') {
                try {
                    new Notification(notif.title, {
                        body: notif.sub || '',
                        icon: BASE_URL + '/assets/images/logo-margo-one.png',
                        tag: 'margo-notif-' + (notif.uid || notif.id),
                    });
                } catch (eN) {}
            }

            /* Auto-dismiss setelah 7 detik */
            setTimeout(function () { dismissToast(el); }, 7000);
        }

        function dismissToast(el) {
            if (!el || !el.parentNode) return;
            el.classList.add('is-out');
            setTimeout(function () { if (el.parentNode) el.parentNode.removeChild(el); }, 300);
        }

        /* ── Proses incoming event dari WebSocket atau boot PHP ── */
        function processEvent(evt, silentMode) {
            var payload = null;
            if (typeof evt === 'object' && evt !== null && evt.payload) {
                payload = evt.payload;
            } else if (typeof evt === 'object' && evt !== null && (evt.id || evt.event_id)) {
                payload = evt;
            }
            if (!payload) return;

            var cat   = (payload.kategori_item || payload.type || '').toLowerCase();
            var jenis = (payload.type || '').toLowerCase().replace('tugas_', '').replace('proyek_', '');
            var type  = cat === 'tugas' ? ('tugas_' + jenis)
                      : cat === 'proyek' ? ('proyek_' + jenis)
                      : cat === 'asesmen' ? 'asesmen'
                      : cat === 'kelompok' ? 'kelompok'
                      : (payload.type || 'tugas_buat');

            var eid = evt.event_id || payload.id || Date.now();
            var notif = {
                uid:       'kelas_' + type + '_' + eid,
                id:        eid,
                group:     'kelas',
                type:      type,
                badge:     typeBadgeLabel(type),
                title:     payload.judul_tugas || payload.nama_proyek || payload.judul_asesmen || payload.judul || evt.title || 'Notifikasi Kelas',
                sub:       payload.deskripsi_ringkas || payload.sub || evt.summary || '',
                full_body: payload.deskripsi_lengkap || payload.deskripsi_ringkas || payload.sub || evt.summary || 'Pembaruan terbaru dari kelas Ilmuna Learn.',
                kelas:     (payload.kelas && payload.kelas.nama) || payload.target_kelas || evt.nama_kelas || '',
                color:     typeColor(type),
                icon:      typeIcon(type),
                url:       payload.portal_url || evt.portal_url || (BASE_URL + '/agenda'),
                ts:        Date.now(),
            };

            upsertNotif(notif, !silentMode);
            renderDrawer();

            if (!silentMode) {
                /* Trigger refresh agenda sidebar + calendar langsung (zero reload!) */
                if (window.MargoShell && MargoShell.refreshRightWidgets) {
                    MargoShell.refreshRightWidgets();
                }
                if (typeof window.MARGO_AGENDA !== 'undefined' && typeof MARGO_AGENDA.syncLearn === 'function') {
                    MARGO_AGENDA.syncLearn();
                }
            }

            try { localStorage.setItem('margo.learn.notifs', JSON.stringify(allNotifs.filter(function (x) { return x.group === 'kelas'; }).slice(-30))); } catch (e) {}
        }

        /* ── Koneksi Real-Time: WebSocket (jika daemon port 8090 aktif) -> Auto-Fallback SSE Stream (cPanel tanpa CLI) ── */
        var sseSource  = null;
        var sseLastId  = 0;

        function closeAllStreams() {
            clearTimeout(reconnTimer);
            try { if (ws) { ws.onclose = null; ws.onerror = null; ws.close(); } } catch (e) {}
            try { if (sseSource) { sseSource.close(); } } catch (e) {}
            ws = null;
            sseSource = null;
        }

        function connectSse(streamUrl) {
            if (!streamUrl || typeof window.EventSource === 'undefined') {
                scheduleReconnect(5000);
                return;
            }
            closeAllStreams();
            var urlWithLast = streamUrl + (sseLastId > 0 ? ((streamUrl.indexOf('?') >= 0 ? '&' : '?') + 'last_id=' + sseLastId) : '');
            try {
                sseSource = new EventSource(urlWithLast);
            } catch (eSse) {
                scheduleReconnect(5000);
                return;
            }

            sseSource.addEventListener('ilmuna.realtime.welcome', function (evt) {
                reconnDelay = 2000;
                try {
                    var w = JSON.parse(evt.data);
                    if (w && w.last_id && Number(w.last_id) > sseLastId) sseLastId = Number(w.last_id);
                } catch (e) {}
                if (liveDot) {
                    liveDot.classList.add('is-live');
                    liveDot.title = 'Koneksi real-time aktif (SSE Stream)';
                }
            });

            sseSource.addEventListener('ilmuna.realtime.event', function (evt) {
                try {
                    if (evt.lastEventId) sseLastId = Math.max(sseLastId, Number(evt.lastEventId) || 0);
                    var data = JSON.parse(evt.data);
                    if (data && data.event_id) sseLastId = Math.max(sseLastId, Number(data.event_id) || 0);
                    if (data && data.payload) processEvent(data);
                } catch (e) {}
            });

            sseSource.addEventListener('ilmuna.stream.rotate', function (evt) {
                try {
                    var r = JSON.parse(evt.data);
                    if (r && r.last_id) sseLastId = Math.max(sseLastId, Number(r.last_id) || 0);
                } catch (e) {}
                try { sseSource.close(); } catch (e) {}
                sseSource = null;
                scheduleReconnect(250); // Rotasi mulus tanpa delay panjang
            });

            sseSource.onerror = function () {
                try { sseSource.close(); } catch (e) {}
                sseSource = null;
                if (liveDot) {
                    liveDot.classList.remove('is-live');
                    liveDot.title = 'Menyambung ulang real-time stream…';
                }
                scheduleReconnect();
            };
        }

        function connect() {
            if (!WS_BOOT || !WS_BOOT.ok || !WS_BOOT.ws_ticket) return;
            var t = WS_BOOT.ws_ticket;
            var wsUrl = t.ws_connect_url;
            var streamUrl = t.stream_connect_url || (t.stream_url && t.ticket ? (t.stream_url + '?ticket=' + encodeURIComponent(t.ticket)) : '');

            var preferSse = false;
            try { preferSse = (sessionStorage.getItem('margo.learn.preferSse') === '1'); } catch (e) {}

            // Jika server menginformasikan daemon WS tidak aktif ATAU WS pernah gagal proxy di cPanel, langsung pakai SSE Stream!
            if ((t.daemon_live === false || preferSse) && streamUrl) {
                connectSse(streamUrl);
                return;
            }
            if (!wsUrl) {
                if (streamUrl) connectSse(streamUrl);
                return;
            }

            closeAllStreams();
            var openedOnce = false;
            try {
                ws = new WebSocket(wsUrl);
            } catch (eWs) {
                if (streamUrl) { connectSse(streamUrl); } else { scheduleReconnect(); }
                return;
            }

            ws.onopen = function () {
                openedOnce = true;
                reconnDelay = 2000;
                if (liveDot) {
                    liveDot.classList.add('is-live');
                    liveDot.title = 'Koneksi real-time aktif (WebSocket)';
                }
            };

            ws.onmessage = function (msgEvt) {
                var data = null;
                try { data = JSON.parse(msgEvt.data); } catch (e) { return; }
                if (!data || data.type === 'ilmuna.realtime.welcome' || data.type === 'ilmuna.realtime.ping') return;
                if (data.type === 'ilmuna.realtime.event' && data.payload) {
                    processEvent(data);
                }
            };

            ws.onerror = function () {};
            ws.onclose = function () {
                ws = null;
                if (!openedOnce && streamUrl) {
                    // WebSocket gagal tersambung -> tandai sesi memakai SSE Stream & fallback dengan tiket baru
                    try { sessionStorage.setItem('margo.learn.preferSse', '1'); } catch (e) {}
                    scheduleReconnect(250, true);
                    return;
                }
                if (liveDot) {
                    liveDot.classList.remove('is-live');
                    liveDot.title = 'Mencoba sambungkan ulang…';
                }
                scheduleReconnect();
            };
        }

        function scheduleReconnect(customDelayMs, forceSse) {
            clearTimeout(reconnTimer);
            var waitMs = (typeof customDelayMs === 'number') ? customDelayMs : reconnDelay;
            reconnTimer = setTimeout(function () {
                /* Re-fetch tiket baru dulu via portal-data sebelum reconnect */
                fetch(BASE_URL + '/portal-data.php?resource=ws_ticket', {
                    credentials: 'same-origin',
                    headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
                }).then(function (r) { return r.json(); })
                  .then(function (res) {
                    if (res && res.success && res.data) {
                        if (forceSse) res.data.daemon_live = false;
                        WS_BOOT = { ok: true, ws_ticket: res.data };
                        connect();
                    }
                  }).catch(function () {});
                if (typeof customDelayMs !== 'number') {
                    reconnDelay = Math.min(reconnDelay * 1.8, maxDelay);
                }
            }, waitMs);
        }

        /* ── Muat notifikasi terpadu (Agenda Hari Ini/Besok + Info Ekosistem) dari server ── */
        function loadUnifiedNotifications(allowToastForNew) {
            fetch(BASE_URL + '/portal-data.php?resource=notifications', {
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
            }).then(function (r) { return r.json(); })
              .then(function (res) {
                if (res && res.success && Array.isArray(res.data)) {
                    res.data.forEach(function (item) {
                        upsertNotif(item, !!allowToastForNew);
                    });
                    renderDrawer();
                }
              }).catch(function () {});
        }

        /* ── Background Web Push (Service Worker + PushManager VAPID) ── */
        function urlBase64ToUint8Array(base64String) {
            var padding = '='.repeat((4 - (base64String.length % 4)) % 4);
            var base64  = (base64String + padding).replace(/\-/g, '+').replace(/_/g, '/');
            var rawData = window.atob(base64);
            var output  = new Uint8Array(rawData.length);
            for (var i = 0; i < rawData.length; ++i) {
                output[i] = rawData.charCodeAt(i);
            }
            return output;
        }

        var pushPromptShown = false;
        function showPushPermissionAlert() {
            if (pushPromptShown) return;
            try {
                if (sessionStorage.getItem('margo.push.bannerDismissed') === '1') return;
            } catch (e) {}
            if (!window.Ilmuna || typeof window.Ilmuna.confirm !== 'function') return;
            pushPromptShown = true;
            window.Ilmuna.confirm(
                'info',
                'Aktifkan Notifikasi HP & Suara?',
                'Izinkan Margo One mengirim notifikasi Agenda, Kelas, Kelompok, dan Info Ekosistem beserta suara notifikasi ke perangkat Anda meskipun aplikasi sedang ditutup.',
                'Ya, Aktifkan',
                'Nanti Saja'
            ).then(function (ok) {
                if (ok) {
                    unlockNotifAudioOnce();
                    MargoLearnWS.enableBackgroundPush();
                } else {
                    try { sessionStorage.setItem('margo.push.bannerDismissed', '1'); } catch (e) {}
                }
            });
        }

        function updatePushBarUI(state) {
            if (!pushBar || !pushTxt || !pushBtn) return;
            if (!('serviceWorker' in navigator) || !('PushManager' in window) || !('Notification' in window)) {
                pushBar.style.display = 'none';
                return;
            }
            pushBar.style.display = 'flex';
            if (state === 'subscribed') {
                pushTxt.textContent = 'Notif HP (luar web) & suara aktif';
                pushBtn.textContent = 'Uji Push';
                pushBtn.classList.add('is-active');
                pushBtn.onclick = function () {
                    playChime();
                    fetch(BASE_URL + '/api/push.php?action=test', { method: 'POST', credentials: 'same-origin' })
                        .then(function (r) { return r.json(); })
                        .then(function (d) {
                            if (window.Ilmuna && Ilmuna.toast) {
                                if (d && d.success) {
                                    Ilmuna.toast('Notifikasi push uji & suara berhasil dikirim!', 'success');
                                } else {
                                    var errInfo = (d && d.data && (d.data.last_error || (d.data.no_subscription ? 'Perangkat belum terdaftar' : ('HTTP ' + (d.data.last_http_code || 0))))) || 'Cek izin notifikasi browser/HP.';
                                    Ilmuna.toast('Push gagal: ' + errInfo, 'warning');
                                }
                            }
                        }).catch(function () {});
                };
            } else if (state === 'denied') {
                pushTxt.textContent = 'Izin notifikasi diblokir di browser/HP';
                pushBtn.textContent = 'Info';
                pushBtn.classList.remove('is-active');
                pushBtn.onclick = function () {
                    if (window.Ilmuna && Ilmuna.info) {
                        Ilmuna.info('Aktifkan Notifikasi HP / Browser', 'Buka Pengaturan Aplikasi / Situs di HP atau ikon gembok di address bar browser, lalu ubah izin Notifications menjadi Allow/Izinkan agar notifikasi tetap masuk saat PWA ditutup.');
                    }
                };
            } else {
                pushTxt.textContent = 'Tetap dapat notif di HP saat PWA ditutup';
                pushBtn.textContent = 'Aktifkan';
                pushBtn.classList.remove('is-active');
                pushBtn.onclick = function () {
                    unlockNotifAudioOnce();
                    MargoLearnWS.enableBackgroundPush();
                };
                setTimeout(showPushPermissionAlert, 900);
            }
        }

        async function subscribePushInternal(askPermission) {
            if (!('serviceWorker' in navigator) || !('PushManager' in window) || !('Notification' in window)) return;
            try {
                if (!swReg) {
                    swReg = await navigator.serviceWorker.register(BASE_URL + '/sw.js', { scope: BASE_URL + '/' });
                    await navigator.serviceWorker.ready;
                }
                if (Notification.permission === 'denied') {
                    updatePushBarUI('denied');
                    return;
                }
                if (Notification.permission === 'default') {
                    if (!askPermission) {
                        updatePushBarUI('prompt');
                        return;
                    }
                    var perm = await Notification.requestPermission();
                    if (perm !== 'granted') {
                        updatePushBarUI(perm === 'denied' ? 'denied' : 'prompt');
                        return;
                    }
                }
                var vkResp = await fetch(BASE_URL + '/api/push.php?action=vapid_key', { credentials: 'same-origin' });
                var vkJson = await vkResp.json();
                if (!vkJson || !vkJson.success || !vkJson.public_key) {
                    updatePushBarUI('prompt');
                    return;
                }
                var expectedKeyBytes = urlBase64ToUint8Array(vkJson.public_key);
                var existing = await swReg.pushManager.getSubscription();
                // Jika VAPID key server berubah, unsubscribe key lama dan subscribe ulang otomatis
                if (existing && existing.options && existing.options.applicationServerKey) {
                    var curBytes = new Uint8Array(existing.options.applicationServerKey);
                    var sameKey = (curBytes.length === expectedKeyBytes.length);
                    if (sameKey) {
                        for (var ki = 0; ki < curBytes.length; ki++) {
                            if (curBytes[ki] !== expectedKeyBytes[ki]) { sameKey = false; break; }
                        }
                    }
                    if (!sameKey) {
                        try { await existing.unsubscribe(); } catch (eUns) {}
                        existing = null;
                    }
                }
                if (!existing) {
                    existing = await swReg.pushManager.subscribe({
                        userVisibleOnly: true,
                        applicationServerKey: expectedKeyBytes,
                    });
                }
                if (existing) {
                    var subResp = await fetch(BASE_URL + '/api/push.php?action=subscribe', {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify(existing.toJSON()),
                    });
                    var subJson = await subResp.json();
                    if (subJson && subJson.success) {
                        updatePushBarUI('subscribed');
                        if (askPermission) {
                            playChime();
                            if (window.Ilmuna && Ilmuna.toast) {
                                Ilmuna.toast('Notifikasi HP (latar belakang) & suara berhasil diaktifkan!', 'success');
                            }
                        }
                    }
                }
            } catch (eSw) {
                updatePushBarUI('prompt');
            }
        }

        /* ── Muat notifikasi awal dari INIT_EVENTS (PHP server bootstrap) ── */
        function hydrateInitEvents() {
            try {
                var saved = JSON.parse(localStorage.getItem('margo.learn.notifs') || '[]');
                if (Array.isArray(saved) && saved.length > 0) {
                    saved.slice(-30).forEach(function (s) {
                        if (!s.uid) s.uid = 'kelas_' + (s.type || 'evt') + '_' + (s.id || Date.now());
                        upsertNotif(s, false);
                    });
                }
            } catch (e) {}

            if (Array.isArray(INIT_EVENTS)) {
                INIT_EVENTS.forEach(function (evt) { processEvent(evt, true); });
            }
            renderDrawer();
        }

        /* ── Toggles ── */
        function toggleDrawer() {
            drawerOpen = !drawerOpen;
            if (drawer) drawer.style.display = drawerOpen ? 'flex' : 'none';
            if (drawerOpen) {
                renderDrawer();
            }
        }
        function closeDrawer() {
            drawerOpen = false;
            if (drawer) drawer.style.display = 'none';
        }

        /* ── Close drawer saat klik di luar ── */
        document.addEventListener('click', function (e) {
            if (!drawerOpen) return;
            var mount = document.getElementById('margoLearnNotifMount');
            if (mount && !mount.contains(e.target)) {
                closeDrawer();
            }
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && drawerOpen) closeDrawer();
        });

        /* ── Public API ── */
        window.MargoLearnWS = {
            toggleDrawer: toggleDrawer,
            closeDrawer: closeDrawer,
            setFilter: function (filter) {
                activeFilter = filter || 'all';
                document.querySelectorAll('#margoNotifTabs .mld-tab').forEach(function (btn) {
                    btn.classList.toggle('is-active', btn.getAttribute('data-filter') === activeFilter);
                });
                renderDrawer();
            },
            clearAll: function () {
                allNotifs.forEach(function (n) {
                    if (n.uid && dismissedIds.indexOf(String(n.uid)) === -1) {
                        dismissedIds.push(String(n.uid));
                    }
                });
                saveIdSet('margo.notif.dismissedIds', dismissedIds);
                allNotifs = [];
                unreadCount = 0;
                updateBadge();
                renderDrawer();
                try { localStorage.removeItem('margo.learn.notifs'); } catch (e) {}
            },
            openDetail: function (uid) {
                var found = null;
                for (var i = 0; i < allNotifs.length; i++) {
                    if (String(allNotifs[i].uid) === String(uid) || String(allNotifs[i].id) === String(uid)) {
                        found = allNotifs[i];
                        break;
                    }
                }
                if (found) {
                    openDetailModal(found);
                }
            },
            openNotif: function (url, id) {
                MargoLearnWS.openDetail(id);
            },
            enableBackgroundPush: function () {
                subscribePushInternal(true);
            },
            requestNotifPermission: function () {
                subscribePushInternal(false);
            },
        };

        /* ── Tangkap klik & penerimaan Push dari Service Worker -> putar suara MP3 kustom / buka detail ── */
        if ('serviceWorker' in navigator) {
            navigator.serviceWorker.addEventListener('message', function (ev) {
                if (ev.data && ev.data.type === 'MARGO_PUSH_RECEIVED') {
                    playChime();
                    loadUnifiedNotifications(false);
                    if (window.MargoShell && MargoShell.refreshRightWidgets) {
                        MargoShell.refreshRightWidgets();
                    }
                    return;
                }
                if (ev.data && ev.data.type === 'MARGO_PUSH_CLICK' && ev.data.payload) {
                    var p = ev.data.payload;
                    if (p.notif_id) {
                        MargoLearnWS.openDetail(p.notif_id);
                    } else {
                        openDetailModal({
                            uid: 'push_' + Date.now(),
                            type: p.category || 'ecosystem_info',
                            title: p.title || 'Notifikasi Margo One',
                            sub: p.body || '',
                            full_body: p.body || '',
                            url: p.url || (BASE_URL + '/gateway'),
                            ts: Date.now()
                        });
                    }
                }
            });
        }

        /* ── Init (Async agar tidak memblokir render halaman / #entrySlide) ── */
        document.addEventListener('DOMContentLoaded', function () {
            hydrateInitEvents();
            loadUnifiedNotifications(false);
            subscribePushInternal(false);

            if (WS_BOOT && WS_BOOT.ok && WS_BOOT.ws_ticket) {
                connect();
            } else {
                fetch(BASE_URL + '/portal-data.php?resource=ws_ticket', {
                    credentials: 'same-origin',
                    headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
                }).then(function (r) { return r.json(); })
                  .then(function (res) {
                    if (res && res.success && res.data) {
                        WS_BOOT = { ok: true, ws_ticket: res.data };
                        if (Array.isArray(res.recent_events) && res.recent_events.length > 0) {
                            res.recent_events.forEach(function (evt) {
                                processEvent(evt, true);
                            });
                            renderDrawer();
                        }
                        if (window.MargoShell && MargoShell.refreshRightWidgets) {
                            MargoShell.refreshRightWidgets();
                        }
                        connect();
                    }
                  }).catch(function () {});
            }

            /* Segarkan agenda & info ekosistem secara ringan saat pengguna kembali membuka tab */
            document.addEventListener('visibilitychange', function () {
                if (!document.hidden) {
                    loadUnifiedNotifications(true);
                }
            });
        });
    })();
    </script>
</body>
</html>
