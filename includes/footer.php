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
        $agListF = margo_agenda_fetch_visible($pdoFooter, $footUser, ['upcoming' => true, 'limit' => 12]);
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
                var stAttr = (idx > 0 && accent) ? (' style="border-top:3px solid ' + accent + ';"') : '';
                return '<a class="' + cls + '"' + stAttr + ' href="' + escHtml(BASE_URL + '/agenda?id=' + ag.id) + '" title="' + escHtml(ag.title) + '">'
                    + '<span class="margo-agenda-year">' + escHtml(ag.year) + '</span>'
                    + '<span class="margo-agenda-month">' + escHtml(ag.month_short) + '</span>'
                    + '<span class="margo-agenda-day">' + escHtml(ag.day_num) + '</span>'
                    + '<span class="margo-agenda-short">' + escHtml(ag.title) + '</span>'
                    + '</a>';
            }).join('');
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
            refreshRightWidgets: function () {
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

        // Hydrate instantly from server-rendered PHP data (zero extra network calls needed on page load)
        var INIT_RIGHT_AGENDA = <?= json_encode($margoInitRightAgenda, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
        var INIT_RIGHT_NEWS   = <?= json_encode($margoInitRightNews, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
        renderRightAgenda(INIT_RIGHT_AGENDA);
        renderRightNews(INIT_RIGHT_NEWS);
    })();
    </script>
</body>
</html>
