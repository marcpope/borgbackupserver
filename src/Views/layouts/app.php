<!DOCTYPE html>
<html lang="en" data-bs-theme="<?= htmlspecialchars($_SESSION['theme'] ?? 'dark') ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle ?? 'Dashboard') ?> - Borg Backup Server</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="/css/style.css?v=<?= filemtime(__DIR__ . '/../../../public/css/style.css') ?>" rel="stylesheet">
    <link rel="manifest" href="/manifest.json">
    <link rel="apple-touch-icon" href="/images/apple-touch-icon.png">
    <meta name="theme-color" content="#2c3e50">
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
    <meta http-equiv="Pragma" content="no-cache">
    <meta http-equiv="Expires" content="0">
</head>
<body>
    <!-- Top bar -->
    <nav class="navbar navbar-expand navbar-dark topbar p-0">
        <div class="container-fluid p-0">
            <a href="/" class="navbar-brand d-flex align-items-center justify-content-center m-0 p-0 topbar-logo">
                <?php
                    $brandIcon = \BBS\Core\Database::getInstance()->fetchOne("SELECT `value` FROM settings WHERE `key` = 'branding_icon'");
                    if (!empty($brandIcon['value'])):
                ?>
                <img src="data:image/png;base64,<?= $brandIcon['value'] ?>" alt="Logo" class="topbar-mascot">
                <?php else: ?>
                <img src="/images/bbs-logo-mascot.png" alt="BBS" class="topbar-mascot">
                <?php endif; ?>
            </a>
            <?php
            $navIcons = [
                'Dashboard' => 'bi-speedometer2', 'Clients' => 'bi-display',
                'Queue' => 'bi-clock-history', 'Schedules' => 'bi-calendar-week',
                'Log' => 'bi-journal-text', 'Storage' => 'bi-hdd-stack',
                'Settings' => 'bi-gear', 'Users' => 'bi-people',
                'User Management' => 'bi-people',
                'Notifications' => 'bi-bell', 'Profile' => 'bi-person',
            ];
            $navIcon = $navIcons[$pageTitle ?? ''] ?? '';
            $badge = $pageTitleBadge ?? '';
            ?>
            <span class="navbar-text fw-semibold ms-3 d-none d-sm-inline" style="font-size:1.25rem;color:rgba(255,255,255,0.75);">
                <?php if ($navIcon): ?><i class="bi <?= $navIcon ?> me-2"></i><?php endif; ?>
                <?= htmlspecialchars($pageTitle ?? '') ?>
                <?php if ($badge): ?><span class="badge bg-primary bg-opacity-25 text-primary ms-2" style="font-size:0.55rem;vertical-align:middle;"><?= htmlspecialchars($badge) ?></span><?php endif; ?>
            </span>
            <span class="navbar-text fw-semibold ms-2 d-sm-none" style="font-size:1.1rem;color:rgba(255,255,255,0.75);">
                <?php if ($navIcon): ?><i class="bi <?= $navIcon ?> me-1"></i><?php endif; ?>
                <?= htmlspecialchars($pageTitle ?? '') ?>
            </span>
            <div class="d-flex align-items-center ms-auto me-2 me-md-3">
                <?php
                $notifCount = $notifCount ?? (new \BBS\Services\NotificationService())->unreadCount($_SESSION['user_id'] ?? null);
                ?>
                <a href="/notifications" class="btn btn-link position-relative me-2 me-md-3 text-white p-1">
                    <i class="bi bi-bell fs-5"></i>
                    <?php if ($notifCount > 0): ?>
                    <span class="position-absolute badge rounded-pill bg-danger" style="top: 0; right: -4px; font-size: 0.6em;">
                        <?= $notifCount ?>
                    </span>
                    <?php endif; ?>
                </a>
                <?php
                $isAdmin = (($_SESSION['user_role'] ?? '') === 'admin');
                $upgradeAvailable = $isAdmin ? (new \BBS\Services\UpdateService())->isUpdateAvailable() : false;
                $agentUpgradeCount = 0;
                if ($isAdmin && !$upgradeAvailable) {
                    $bundledAgentVer = null;
                    $agentFile = dirname(__DIR__, 3) . '/agent/bbs-agent.py';
                    if (file_exists($agentFile)) {
                        $h = fopen($agentFile, 'r');
                        if ($h) {
                            for ($i = 0; $i < 50 && ($ln = fgets($h)) !== false; $i++) {
                                if (preg_match('/^AGENT_VERSION\s*=\s*["\']([^"\']+)["\']/m', $ln, $mv)) {
                                    $bundledAgentVer = $mv[1]; break;
                                }
                            }
                            fclose($h);
                        }
                    }
                    if ($bundledAgentVer) {
                        $db = \BBS\Core\Database::getInstance();
                        $agentUpgradeCount = (int)$db->fetchOne(
                            "SELECT COUNT(*) as cnt FROM agents WHERE agent_version IS NOT NULL AND agent_version != ?",
                            [$bundledAgentVer]
                        )['cnt'];
                    }
                }
                if ($upgradeAvailable): ?>
                <a href="/settings?tab=updates" class="badge bg-warning text-dark text-decoration-none me-2 me-md-3 py-2 px-2 d-none d-sm-inline-block">
                    <i class="bi bi-cloud-arrow-down me-1"></i> Upgrade
                </a>
                <?php elseif ($agentUpgradeCount > 0): ?>
                <a href="/settings?tab=updates" class="badge text-bg-primary text-decoration-none me-2 me-md-3 py-2 px-2 d-none d-sm-inline-block">
                    <i class="bi bi-box-seam me-1"></i> Upgrade Agents
                </a>
                <?php endif; ?>
                <?php
                $maintenanceMode = \BBS\Core\Database::getInstance()->fetchOne("SELECT `value` FROM settings WHERE `key` = 'maintenance_mode'");
                if (($maintenanceMode['value'] ?? '0') === '1'): ?>
                <a href="/settings" class="badge bg-warning text-dark text-decoration-none me-2 me-md-3 py-2 px-2">
                    <i class="bi bi-exclamation-triangle-fill me-1"></i> Maintenance Mode: On
                </a>
                <?php endif; ?>
                <div class="dropdown">
                    <a class="btn btn-link text-white dropdown-toggle text-decoration-none p-1" href="#" role="button" data-bs-toggle="dropdown">
                        <i class="bi bi-person-circle"></i>
                        <span class="d-none d-md-inline ms-1"><?= htmlspecialchars($_SESSION['username'] ?? 'User') ?></span>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><span class="dropdown-item-text text-muted small"><?= ucfirst($_SESSION['user_role'] ?? 'user') ?></span></li>
                        <li><a class="dropdown-item" href="/notifications"><i class="bi bi-bell me-1"></i> Notifications<?php if ($notifCount > 0): ?> <span class="badge bg-danger"><?= $notifCount ?></span><?php endif; ?></a></li>
                        <?php if ($upgradeAvailable ?? false): ?>
                        <li><a class="dropdown-item" href="/settings?tab=updates"><i class="bi bi-cloud-arrow-down me-1"></i> Upgrade Available</a></li>
                        <?php elseif (($agentUpgradeCount ?? 0) > 0): ?>
                        <li><a class="dropdown-item" href="/settings?tab=updates"><i class="bi bi-box-seam me-1"></i> Upgrade Agents (<?= $agentUpgradeCount ?>)</a></li>
                        <?php endif; ?>
                        <li><a class="dropdown-item" href="/profile"><i class="bi bi-person me-1"></i> Profile</a></li>
                        <li><a class="dropdown-item" href="/settings"><i class="bi bi-gear me-1"></i> Settings</a></li>
                        <li>
                            <a class="dropdown-item" href="#" onclick="toggleTheme(); return false;">
                                <i class="bi bi-moon me-1" id="themeIcon"></i> <span id="themeLabel">Dark Mode</span>
                            </a>
                        </li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item" href="/logout"><i class="bi bi-box-arrow-right me-1"></i> Logout</a></li>
                    </ul>
                </div>
            </div>
        </div>
    </nav>

    <div class="d-flex">
        <!-- Sidebar (desktop only) -->
        <nav id="sidebar" class="sidebar d-none d-md-flex flex-column flex-shrink-0 text-white">
            <ul class="nav nav-pills flex-column mb-auto text-center">
                <li class="nav-item">
                    <a href="/" class="nav-link sidebar-link <?= ($pageTitle ?? '') === 'Dashboard' ? 'active' : '' ?>">
                        <i class="bi bi-speedometer2 d-block mb-1 fs-4"></i>
                        <span class="small">Dashboard</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="/clients" class="nav-link sidebar-link <?= ($pageTitle ?? '') === 'Clients' ? 'active' : '' ?>">
                        <i class="bi bi-display d-block mb-1 fs-4"></i>
                        <span class="small">Clients</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="/queue" class="nav-link sidebar-link <?= ($pageTitle ?? '') === 'Queue' ? 'active' : '' ?>">
                        <i class="bi bi-clock-history d-block mb-1 fs-4"></i>
                        <span class="small">Queue</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="/schedules" class="nav-link sidebar-link <?= ($pageTitle ?? '') === 'Schedules' ? 'active' : '' ?>">
                        <i class="bi bi-calendar-week d-block mb-1 fs-4"></i>
                        <span class="small">Schedules</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="/log" class="nav-link sidebar-link <?= ($pageTitle ?? '') === 'Log' ? 'active' : '' ?>">
                        <i class="bi bi-journal-text d-block mb-1 fs-4"></i>
                        <span class="small">Log</span>
                    </a>
                </li>
                <?php if (($_SESSION['user_role'] ?? '') === 'admin'): ?>
                <li class="nav-item">
                    <a href="/storage-locations" class="nav-link sidebar-link <?= ($pageTitle ?? '') === 'Storage' ? 'active' : '' ?>">
                        <i class="bi bi-hdd-stack d-block mb-1 fs-4"></i>
                        <span class="small">Storage</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="/settings" class="nav-link sidebar-link <?= ($pageTitle ?? '') === 'Settings' ? 'active' : '' ?>">
                        <i class="bi bi-gear d-block mb-1 fs-4"></i>
                        <span class="small">Settings</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="/users" class="nav-link sidebar-link <?= str_contains($pageTitle ?? '', 'User') ? 'active' : '' ?>">
                        <i class="bi bi-people d-block mb-1 fs-4"></i>
                        <span class="small">Users</span>
                    </a>
                </li>
                <?php endif; ?>
            </ul>
            <?php
                $bbsVersionForSidebar = (new \BBS\Services\UpdateService())->getCurrentVersion();
            ?>
            <div class="text-center pb-2">
                <span class="badge rounded-pill text-body-secondary border"
                      style="font-weight:500;font-size:0.65rem;padding:3px 9px;background:rgba(255,255,255,0.04);border-color:rgba(255,255,255,0.12) !important;">
                    v<?= htmlspecialchars($bbsVersionForSidebar) ?>
                </span>
            </div>
            <div class="border-top p-2 text-center">
                <a href="/logout" class="nav-link sidebar-link">
                    <i class="bi bi-box-arrow-left d-block mb-1 fs-4"></i>
                    <span class="small">Logout</span>
                </a>
            </div>
        </nav>

        <!-- Main content -->
        <div class="flex-grow-1 main-content">

            <?php $flash = $flash ?? $this->getFlash(); ?>

            <!-- Page content -->
            <div class="p-3 p-md-4">
                <?php require $viewPath . $template . '.php'; ?>
            </div>
        </div>
    </div>

    <!-- Bottom nav (mobile only) -->
    <?php
        $pt = $pageTitle ?? '';
        $isAdmin = ($_SESSION['user_role'] ?? '') === 'admin';
        // Active state for the More menu: true if the current page is one of
        // the items inside the More sheet, so the user sees which section of
        // the app they're in even though it's not a top-level tab.
        $moreActive = in_array($pt, ['Schedules', 'Log', 'Storage'], true)
            || ($isAdmin && str_contains($pt, 'User'));
    ?>
    <nav class="mobile-bottom-nav d-md-none">
        <a href="/" class="mobile-nav-item <?= $pt === 'Dashboard' ? 'active' : '' ?>">
            <i class="bi bi-speedometer2"></i>
            <span>Home</span>
        </a>
        <a href="/clients" class="mobile-nav-item <?= $pt === 'Clients' ? 'active' : '' ?>">
            <i class="bi bi-display"></i>
            <span>Clients</span>
        </a>
        <a href="/queue" class="mobile-nav-item <?= $pt === 'Queue' ? 'active' : '' ?>">
            <i class="bi bi-clock-history"></i>
            <span>Queue</span>
        </a>
        <?php if ($isAdmin): ?>
        <a href="/settings" class="mobile-nav-item <?= $pt === 'Settings' ? 'active' : '' ?>">
            <i class="bi bi-gear"></i>
            <span>Settings</span>
        </a>
        <?php endif; ?>
        <button type="button" class="mobile-nav-item <?= $moreActive ? 'active' : '' ?>"
                data-bs-toggle="offcanvas" data-bs-target="#mobileMoreMenu" aria-controls="mobileMoreMenu">
            <i class="bi bi-three-dots"></i>
            <span>More</span>
        </button>
    </nav>

    <!-- "More" offcanvas — pops up from the bottom with secondary nav items -->
    <div class="offcanvas offcanvas-bottom mobile-more-sheet" tabindex="-1" id="mobileMoreMenu" aria-labelledby="mobileMoreMenuLabel">
        <div class="offcanvas-header border-bottom">
            <h5 class="offcanvas-title" id="mobileMoreMenuLabel">More</h5>
            <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
        </div>
        <div class="offcanvas-body p-0">
            <div class="list-group list-group-flush">
                <a href="/schedules" class="list-group-item list-group-item-action d-flex align-items-center <?= $pt === 'Schedules' ? 'active' : '' ?>">
                    <i class="bi bi-calendar-week fs-5 me-3"></i>Schedules
                </a>
                <a href="/log" class="list-group-item list-group-item-action d-flex align-items-center <?= $pt === 'Log' ? 'active' : '' ?>">
                    <i class="bi bi-journal-text fs-5 me-3"></i>Log
                </a>
                <?php if ($isAdmin): ?>
                <a href="/users" class="list-group-item list-group-item-action d-flex align-items-center <?= str_contains($pt, 'User') ? 'active' : '' ?>">
                    <i class="bi bi-people fs-5 me-3"></i>Users
                </a>
                <a href="/storage-locations" class="list-group-item list-group-item-action d-flex align-items-center <?= $pt === 'Storage' ? 'active' : '' ?>">
                    <i class="bi bi-hdd-stack fs-5 me-3"></i>Storage
                </a>
                <?php endif; ?>
                <a href="/logout" class="list-group-item list-group-item-action d-flex align-items-center text-danger">
                    <i class="bi bi-box-arrow-right fs-5 me-3"></i>Logout
                </a>
            </div>
        </div>
    </div>

    <!-- Confirm modal -->
    <div class="modal fade" id="confirmModal" tabindex="-1" data-bs-backdrop="static">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow">
                <div class="modal-body text-center p-4">
                    <i class="bi bi-question-circle text-warning d-block mb-3" style="font-size:2.5rem;"></i>
                    <p class="mb-0 fs-6" id="confirmMessage"></p>
                </div>
                <div class="modal-footer border-0 justify-content-center pb-4 pt-0">
                    <button type="button" class="btn btn-outline-secondary px-4" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-success px-4" id="confirmOk">OK</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Toast container -->
    <div class="toast-container position-fixed bottom-0 end-0 p-3" id="toastContainer" style="z-index:1090;"></div>

    <script>
    window.BBS_TIME_24H = <?= json_encode(($_SESSION['time_format'] ?? '12h') === '24h') ?>;
    window.BBS_TIMEZONE = <?= json_encode($_SESSION['timezone'] ?? 'America/New_York') ?>;
    </script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    // Global dropdown/tooltip escape fix (issue #161 and predecessors).
    // Every dropdown toggle in the app gets data-bs-strategy="fixed" so Popper
    // positions the menu with position:fixed, which escapes parent containers
    // that clip it (cards with overflow:hidden, table wrappers, sticky headers).
    // Tooltips get boundary:'viewport' + container:'body' via a default instance
    // so they also clear parent clipping. This replaces the per-element patches
    // we were adding case-by-case.
    (function() {
        function upgradeDropdown(el) {
            if (el.dataset.bsDropdownFixed === '1') return;
            el.setAttribute('data-bs-strategy', 'fixed');
            el.dataset.bsDropdownFixed = '1';
            // If Bootstrap already instantiated this dropdown, dispose so the
            // next open picks up the new strategy.
            if (window.bootstrap && bootstrap.Dropdown) {
                var existing = bootstrap.Dropdown.getInstance(el);
                if (existing) existing.dispose();
            }
        }
        function upgradeTooltip(el) {
            if (el.dataset.bsTooltipFixed === '1') return;
            el.dataset.bsTooltipFixed = '1';
            // Set container/boundary via data attributes so that any tooltip
            // instance (whether created here or later by a view-level script
            // doing `new bootstrap.Tooltip(el)`) picks up the same config.
            if (!el.hasAttribute('data-bs-container')) el.setAttribute('data-bs-container', 'body');
            if (!el.hasAttribute('data-bs-boundary')) el.setAttribute('data-bs-boundary', 'viewport');
            if (window.bootstrap && bootstrap.Tooltip && !bootstrap.Tooltip.getInstance(el)) {
                new bootstrap.Tooltip(el);
            }
        }
        function upgradeAll(root) {
            root = root || document;
            root.querySelectorAll('[data-bs-toggle="dropdown"]').forEach(upgradeDropdown);
            root.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(upgradeTooltip);
        }
        function init() {
            upgradeAll();
            // Pick up dropdowns/tooltips added to the DOM later (AJAX rows, modals).
            var obs = new MutationObserver(function(muts) {
                muts.forEach(function(m) {
                    m.addedNodes.forEach(function(n) {
                        if (n.nodeType !== 1) return;
                        if (n.matches) {
                            if (n.matches('[data-bs-toggle="dropdown"]')) upgradeDropdown(n);
                            if (n.matches('[data-bs-toggle="tooltip"]')) upgradeTooltip(n);
                        }
                        if (n.querySelectorAll) upgradeAll(n);
                    });
                });
            });
            obs.observe(document.body, { childList: true, subtree: true });
        }
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', init);
        } else {
            init();
        }
    })();
    </script>
    <script>
    function toggleTheme() {
        var html = document.documentElement;
        var newTheme = html.getAttribute('data-bs-theme') === 'dark' ? 'light' : 'dark';
        html.setAttribute('data-bs-theme', newTheme);
        localStorage.setItem('bbs-theme', newTheme);
        updateThemeUI(newTheme);
        fetch('/profile/theme', {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:'theme='+newTheme, credentials:'same-origin'});
    }
    function updateThemeUI(theme) {
        var icon = document.getElementById('themeIcon');
        var label = document.getElementById('themeLabel');
        if (icon) icon.className = 'bi ' + (theme === 'dark' ? 'bi-sun' : 'bi-moon') + ' me-1';
        if (label) label.textContent = theme === 'dark' ? 'Light Mode' : 'Dark Mode';
    }
    updateThemeUI(document.documentElement.getAttribute('data-bs-theme') || 'dark');
    </script>
    <script>
    function confirmAction(message, callback, options) {
        options = options || {};
        var msgEl = document.getElementById('confirmMessage');
        var okBtn = document.getElementById('confirmOk');
        var icon = document.querySelector('#confirmModal .modal-body > i');
        msgEl.innerHTML = message.replace(/\n/g, '<br>');
        // Style the OK button based on type
        okBtn.className = 'btn px-4 btn-' + (options.btnClass || 'success');
        okBtn.textContent = options.okText || 'OK';
        // Icon
        if (options.danger) {
            icon.className = 'bi bi-exclamation-triangle-fill text-danger d-block mb-3';
        } else {
            icon.className = 'bi bi-question-circle text-warning d-block mb-3';
        }
        var modal = bootstrap.Modal.getOrCreateInstance(document.getElementById('confirmModal'));
        // Remove old listener
        var newOk = okBtn.cloneNode(true);
        okBtn.parentNode.replaceChild(newOk, okBtn);
        newOk.id = 'confirmOk';
        newOk.addEventListener('click', function() { modal.hide(); callback(); });
        modal.show();
    }
    // Global handler for data-confirm on forms and buttons
    document.addEventListener('submit', function(e) {
        var form = e.target;
        var msg = form.getAttribute('data-confirm');
        if (!msg) return;
        e.preventDefault();
        e.stopImmediatePropagation();
        var opts = {};
        if (form.hasAttribute('data-confirm-danger')) opts = {danger:true, btnClass:'danger', okText:'Delete'};
        confirmAction(msg, function() {
            form.removeAttribute('data-confirm');
            form.requestSubmit ? form.requestSubmit() : form.submit();
        }, opts);
    });
    document.addEventListener('click', function(e) {
        var btn = e.target.closest('[data-confirm]');
        if (!btn || btn.tagName === 'FORM') return;
        var msg = btn.getAttribute('data-confirm');
        e.preventDefault();
        e.stopImmediatePropagation();
        var opts = {};
        if (btn.hasAttribute('data-confirm-danger')) opts = {danger:true, btnClass:'danger', okText:'Delete'};
        var form = btn.closest('form');
        if (form) {
            confirmAction(msg, function() {
                btn.removeAttribute('data-confirm');
                form.requestSubmit ? form.requestSubmit() : form.submit();
            }, opts);
        } else {
            confirmAction(msg, function() { btn.removeAttribute('data-confirm'); btn.click(); }, opts);
        }
    });
    function showToast(message, type) {
        var iconColors = {success:'#2ecc71',danger:'#e74c3c',warning:'#f39c12',info:'#3498db'};
        var icons = {success:'bi-check-circle-fill',danger:'bi-x-circle-fill',warning:'bi-exclamation-triangle-fill',info:'bi-info-circle-fill'};
        var iconColor = iconColors[type] || iconColors.info;
        var icon = icons[type] || icons.info;
        var el = document.createElement('div');
        el.className = 'toast show';
        el.setAttribute('role', 'alert');
        el.style.cssText = 'max-width:400px;background:#2c3e50;color:#fff;box-shadow:0 8px 32px rgba(0,0,0,.3);border-radius:8px;overflow:hidden;';
        el.innerHTML = '<div class="d-flex align-items-center p-3">' +
            '<i class="bi '+icon+' me-2 flex-shrink-0" style="color:'+iconColor+';font-size:1.25rem;"></i>' +
            '<div class="small flex-grow-1" style="line-height:1.4;">'+message.replace(/</g,'&lt;')+'</div>' +
            '<button type="button" class="btn-close btn-close-white ms-2 flex-shrink-0" style="font-size:.6rem;" onclick="this.closest(\'.toast\').remove()"></button>' +
            '</div>';
        document.getElementById('toastContainer').appendChild(el);
        setTimeout(function(){ if(el.parentNode) el.style.transition='opacity .4s';el.style.opacity='0';setTimeout(function(){if(el.parentNode)el.remove();},400); }, 6000);
    }
    <?php if (!empty($_SESSION['user_id'])): ?>
    (function(){
        var lastCheck = new Date().toISOString().slice(0,19).replace('T',' ');
        var seen = {};
        setInterval(function(){
            fetch('/api/toasts?since='+encodeURIComponent(lastCheck),{credentials:'same-origin'})
                .then(function(r){return r.ok?r.json():null;})
                .then(function(data){
                    if(!data)return;
                    lastCheck=data.server_time;
                    (data.toasts||[]).forEach(function(t){
                        var key=t.type+':'+t.message;
                        if(!seen[key]){seen[key]=1;showToast(t.message,t.type);}
                    });
                    if(Object.keys(seen).length>200)seen={};
                })
                .catch(function(){});
        }, 8000);
    })();
    <?php endif; ?>
    </script>
    <?php if ($flash): ?>
    <script>document.addEventListener('DOMContentLoaded', function(){ showToast(<?= json_encode($flash['message']) ?>, <?= json_encode($flash['type']) ?>); });</script>
    <?php endif; ?>
    <?php if (isset($scripts)): ?>
        <?php foreach ($scripts as $script): ?>
        <script src="<?= $script ?>"></script>
        <?php endforeach; ?>
    <?php endif; ?>
    <?php if (empty($_SESSION['timezone'])): ?>
    <script>
    (function(){
        try {
            var tz = Intl.DateTimeFormat().resolvedOptions().timeZone;
            if (tz) fetch('/profile/detect-timezone', {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:'timezone='+encodeURIComponent(tz)});
        } catch(e){}
    })();
    </script>
    <?php endif; ?>
    <?php
    // Docker first-run setup modal
    $showDockerSetup = false;
    if ((file_exists('/.dockerenv') || file_exists('/run/.containerenv')) && (($_SESSION['user_role'] ?? '') === 'admin')) {
        $dockerSetupRow = \BBS\Core\Database::getInstance()->fetchOne("SELECT `value` FROM settings WHERE `key` = 'docker_setup_complete'");
        $showDockerSetup = empty($dockerSetupRow['value']) || $dockerSetupRow['value'] !== '1';
    }
    if ($showDockerSetup):
        $dbSettings = \BBS\Core\Database::getInstance()->fetchAll("SELECT `key`, `value` FROM settings WHERE `key` IN ('server_host', 'ssh_port')");
        $dockerSettings = [];
        foreach ($dbSettings as $r) $dockerSettings[$r['key']] = $r['value'];
        $currentHost = $dockerSettings['server_host'] ?? 'localhost';
        $currentSshPort = $dockerSettings['ssh_port'] ?? '22';
        // Split host:port
        if (preg_match('/^(.+):(\d+)$/', $currentHost, $m)) {
            $dockerHostname = $m[1];
            $dockerWebPort = $m[2];
        } else {
            $dockerHostname = $currentHost;
            $dockerWebPort = '8080';
        }
    ?>
    <!-- Docker First-Run Setup Modal -->
    <div class="modal fade" id="dockerSetupModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow-lg">
                <div class="modal-header border-0 pb-0">
                    <div>
                        <h5 class="modal-title fw-bold"><i class="bi bi-box-seam me-2"></i>Docker Setup</h5>
                        <p class="text-muted small mb-0 mt-1">Configure your network settings so agents can connect to this server.</p>
                    </div>
                </div>
                <form method="POST" action="/settings/docker-setup">
                    <input type="hidden" name="csrf_token" value="<?= $this->csrfToken() ?>">
                    <div class="modal-body pt-3">
                        <div class="alert alert-info small mb-3">
                            <i class="bi bi-info-circle me-1"></i>
                            Set these to match the ports you mapped when creating this Docker container.
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Server Hostname or IP</label>
                            <input type="text" class="form-control" name="hostname" value="<?= htmlspecialchars($dockerHostname !== 'localhost' ? $dockerHostname : '') ?>" placeholder="e.g. 192.168.1.50 or backup.example.com" required>
                            <div class="form-text">The address your backup clients will use to reach this server. Cannot be <code>localhost</code>.</div>
                        </div>
                        <div class="row g-3">
                            <div class="col-6">
                                <label class="form-label fw-semibold">Web Port</label>
                                <input type="number" class="form-control" name="web_port" value="<?= htmlspecialchars($dockerWebPort) ?>" min="1" max="65535">
                                <div class="form-text">Host port mapped to container port 80.</div>
                            </div>
                            <div class="col-6">
                                <label class="form-label fw-semibold">SSH Port</label>
                                <input type="number" class="form-control" name="ssh_port" value="<?= htmlspecialchars($currentSshPort) ?>" min="1" max="65535">
                                <div class="form-text">Host port mapped to container port 22.</div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer border-0 pt-0">
                        <button type="submit" class="btn btn-primary px-4"><i class="bi bi-check-lg me-1"></i> Save Settings</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <script>document.addEventListener('DOMContentLoaded', function(){ new bootstrap.Modal(document.getElementById('dockerSetupModal')).show(); });</script>
    <?php endif; ?>
    <script>
    (function(){
        if (localStorage.getItem('pwa-dismissed')) return;
        var isStandalone = window.matchMedia('(display-mode: standalone)').matches || navigator.standalone;
        if (isStandalone) return;
        var isMobile = /iPhone|iPad|iPod|Android/i.test(navigator.userAgent);
        if (!isMobile) return;

        var deferredPrompt = null;
        window.addEventListener('beforeinstallprompt', function(e) {
            e.preventDefault();
            deferredPrompt = e;
            showBanner();
        });

        // iOS doesn't fire beforeinstallprompt — show banner with instructions
        if (/iPhone|iPad|iPod/i.test(navigator.userAgent) && !navigator.standalone) {
            setTimeout(showBanner, 2000);
        }

        function showBanner() {
            if (document.getElementById('pwa-banner')) return;
            var b = document.createElement('div');
            b.id = 'pwa-banner';
            b.style.cssText = 'position:fixed;bottom:0;left:0;right:0;z-index:9999;padding:12px 16px;display:flex;align-items:center;gap:12px;background:#2c3e50;color:#fff;box-shadow:0 -2px 12px rgba(0,0,0,.3);font-size:14px;';
            b.innerHTML = '<img src="/images/icon-192.png" style="width:36px;height:36px;border-radius:8px;flex-shrink:0;">' +
                '<div style="flex:1;line-height:1.3;"><strong>Borg Backup Server</strong><br><span style="font-size:12px;opacity:.8;">Add to your home screen for quick access</span></div>' +
                '<button id="pwa-install" style="background:#3498db;color:#fff;border:none;border-radius:6px;padding:6px 14px;font-size:13px;font-weight:600;white-space:nowrap;cursor:pointer;">Add</button>' +
                '<button id="pwa-close" style="background:none;border:none;color:#fff;opacity:.6;font-size:20px;padding:0 4px;cursor:pointer;line-height:1;">&times;</button>';
            document.body.appendChild(b);

            document.getElementById('pwa-close').addEventListener('click', function() {
                b.remove();
                localStorage.setItem('pwa-dismissed', '1');
            });

            document.getElementById('pwa-install').addEventListener('click', function() {
                if (deferredPrompt) {
                    deferredPrompt.prompt();
                    deferredPrompt.userChoice.then(function() { b.remove(); localStorage.setItem('pwa-dismissed', '1'); });
                } else {
                    // iOS: show instructions
                    b.querySelector('div').innerHTML = '<strong>Tap <svg style="display:inline;vertical-align:middle;width:18px;height:18px;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 5v14M5 12l7-7 7 7"/><rect x="4" y="19" width="16" height="1" rx=".5" fill="currentColor" stroke="none"/></svg> then "Add to Home Screen"</strong>';
                    document.getElementById('pwa-install').style.display = 'none';
                }
            });
        }
    })();
    </script>
</body>
</html>
