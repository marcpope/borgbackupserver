
<?php if (!empty($agents)): ?>
<!-- Stat Cards -->
<div class="row g-3 mb-4">
    <div class="col-xl-3 col-md-6">
        <div class="card border-0 shadow-sm h-100 metric-card-blue">
            <div class="card-body d-flex align-items-center">
                <div class="stat-icon bg-primary bg-opacity-10 text-primary rounded-3 p-3 me-3">
                    <i class="bi bi-display fs-3"></i>
                </div>
                <div>
                    <div class="text-muted small">Client Agents</div>
                    <div class="fs-4 fw-bold"><?= $totalClients ?></div>
                    <div class="text-muted small"><?= $onlineCount ?> online<?php if ($offlineCount): ?>, <?= $offlineCount ?> offline<?php endif; ?><?php if ($errorCount): ?>, <?= $errorCount ?> error<?php endif; ?></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-xl-3 col-md-6">
        <div class="card border-0 shadow-sm h-100 metric-card-success">
            <div class="card-body d-flex align-items-center">
                <div class="stat-icon bg-success bg-opacity-10 text-success rounded-3 p-3 me-3">
                    <i class="bi bi-archive fs-3"></i>
                </div>
                <div>
                    <div class="text-muted small">Repositories</div>
                    <div class="fs-4 fw-bold"><?= $totalRepos ?></div>
                    <div class="text-muted small"><?= $totalSizeFormatted ?> total</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-xl-3 col-md-6">
        <div class="card border-0 shadow-sm h-100 metric-card-warning">
            <div class="card-body d-flex align-items-center">
                <div class="stat-icon bg-warning bg-opacity-10 text-warning rounded-3 p-3 me-3">
                    <i class="bi bi-calendar-check fs-3"></i>
                </div>
                <div>
                    <div class="text-muted small">Active Schedules</div>
                    <div class="fs-4 fw-bold"><?= $activeSchedules ?></div>
                    <div class="text-muted small"><?= $planCount ?> backup plan<?= $planCount !== 1 ? 's' : '' ?></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-xl-3 col-md-6">
        <?php $outdatedBs = $outdatedCount > 0 ? 'danger' : 'success'; ?>
        <div class="card border-0 shadow-sm h-100 metric-card-<?= $outdatedBs ?>">
            <div class="card-body d-flex align-items-center">
                <div class="stat-icon bg-<?= $outdatedBs ?> bg-opacity-10 text-<?= $outdatedBs ?> rounded-3 p-3 me-3">
                    <i class="bi bi-<?= $outdatedCount > 0 ? 'exclamation-triangle' : 'check-circle' ?> fs-3"></i>
                </div>
                <div>
                    <div class="text-muted small">Out of Date</div>
                    <div class="fs-4 fw-bold"><?= $outdatedCount ?></div>
                    <div class="text-muted small"><?= $latestVersion ? 'latest: v' . htmlspecialchars($latestVersion) : 'no agents reporting' ?></div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Charts -->
<div class="row g-3 mb-4">
    <div class="col-md-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header fw-semibold border-0">
                <i class="bi bi-bar-chart me-1"></i> Activity (7 days)
            </div>
            <div class="card-body py-2">
                <canvas id="activityChart" height="160"></canvas>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header fw-semibold border-0">
                <i class="bi bi-pie-chart me-1"></i> Storage by Client
            </div>
            <div class="card-body py-2 d-flex align-items-center justify-content-center">
                <?php if (empty($storageByClient)): ?>
                    <span class="text-muted">No storage data yet</span>
                <?php else: ?>
                    <canvas id="storageChart" height="160"></canvas>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <?php if (!empty($agents)): ?>
    <div class="input-group input-group-sm" style="max-width: 280px;">
        <span class="input-group-text bg-body border-end-0"><i class="bi bi-search text-muted"></i></span>
        <input type="text" id="clientSearch" class="form-control border-start-0 ps-0" placeholder="Search clients...">
    </div>
    <?php else: ?>
    <div></div>
    <?php endif; ?>
    <?php if (($_SESSION['user_role'] ?? '') === 'admin'): ?>
    <a href="/clients/add" class="btn btn-sm btn-success">
        <i class="bi bi-plus-circle me-1"></i><span class="d-none d-sm-inline"> Add Client</span>
    </a>
    <?php endif; ?>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <!-- Desktop table view -->
        <div class="table-responsive d-none d-md-block">
            <table class="table table-hover mb-0 small" id="clientsTable">
                <thead class="table-light">
                    <tr>
                        <th data-sortable>Name</th>
                        <th data-sortable>Agent<br>Version</th>
                        <th data-sortable>Last<br>Successful</th>
                        <th data-sortable title="Backup attempts that failed since the last successful one">Missed Since<br>Success</th>
                        <th data-sortable>Restore<br>Points</th>
                        <th data-sortable>Size</th>
                        <th data-sortable class="text-center" title="Schedules"><i class="bi bi-calendar-event"></i><span class="visually-hidden">Schedules</span></th>
                        <th data-sortable class="text-center" title="Repositories"><i class="bi bi-hdd"></i><span class="visually-hidden">Repositories</span></th>
                        <th data-sortable>Profile</th>
                        <th data-sortable>Owner</th>
                        <th data-sortable>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($agents)): ?>
                    <tr>
                        <td colspan="11" class="text-center text-muted py-4">No clients configured. Click "Add Client" to get started.</td>
                    </tr>
                    <?php endif; ?>
                    <?php foreach ($agents as $agent): ?>
                    <tr style="cursor:pointer" onclick="window.location='/clients/<?= $agent['id'] ?>'">
                        <td>
                            <i class="bi bi-pc-display me-1 text-muted"></i><strong><?= htmlspecialchars($agent['name']) ?></strong>
                            <?php if ($agent['hostname']): ?>
                                <br><small class="text-muted ms-4"><?= htmlspecialchars($agent['hostname']) ?></small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?= !empty($agent['agent_version']) ? 'v' . htmlspecialchars($agent['agent_version']) : '--' ?>
                            <?php if ($latestVersion && !empty($agent['agent_version']) && $agent['agent_version'] !== $latestVersion): ?>
                                <form method="POST" action="/clients/<?= $agent['id'] ?>/update-agent" class="d-inline" onclick="event.stopPropagation()">
                                    <input type="hidden" name="csrf_token" value="<?= $this->csrfToken() ?>">
                                    <button type="submit" class="badge border-0 ms-1 bg-body-secondary text-muted" style="font-size:.65rem;cursor:pointer;" title="Queue agent upgrade to v<?= htmlspecialchars($latestVersion) ?>" data-confirm="Queue agent upgrade for <?= htmlspecialchars($agent['name']) ?>?"><i class="bi bi-arrow-up-circle me-1"></i>upgrade</button>
                                </form>
                            <?php endif; ?>
                        </td>
                        <?php
                        $lastSuccess = $agent['last_success_at'] ?? null;
                        $missed = (int) ($agent['missed_since_success'] ?? 0);
                        ?>
                        <td data-sort="<?= $lastSuccess ? strtotime($lastSuccess . ' UTC') : 0 ?>">
                            <?php if ($lastSuccess): ?>
                                <span title="<?= htmlspecialchars(\BBS\Core\TimeHelper::format($lastSuccess)) ?>"><?= \BBS\Core\TimeHelper::ago($lastSuccess) ?></span>
                            <?php else: ?>
                                <span class="text-muted">Never</span>
                            <?php endif; ?>
                        </td>
                        <td data-sort="<?= $missed ?>">
                            <?php if ($missed === 0): ?>
                                <span class="text-muted">--</span>
                            <?php else: ?>
                                <span class="badge bg-<?= $missed >= 3 ? 'danger' : 'warning' ?>"><?= $missed ?></span>
                            <?php endif; ?>
                        </td>
                        <td data-sort="<?= (int) $agent['restore_points'] ?>"><?= number_format($agent['restore_points']) ?></td>
                        <td data-sort="<?= (int) $agent['total_size'] ?>"><?php $sz = (int) $agent['total_size']; echo $sz > 0 ? \BBS\Services\ServerStats::formatBytes($sz) : '--'; ?></td>
                        <td class="text-center" data-sort="<?= (int) $agent['schedule_count'] ?>"><?= $agent['schedule_count'] ?></td>
                        <td class="text-center" data-sort="<?= (int) $agent['repo_count'] ?>" title="<?= htmlspecialchars($agent['storage_names'] ?? '') ?>"><?= $agent['repo_count'] ?><?php if (!empty($agent['storage_names'])): ?><div class="small text-muted text-truncate" style="max-width: 11rem;"><?= htmlspecialchars($agent['storage_names']) ?></div><?php endif; ?></td>
                        <td>
                            <?php if (!empty($agent['profile_name'])): ?>
                                <span class="badge bg-body-secondary text-body border fw-normal"><?= htmlspecialchars($agent['profile_name']) ?></span>
                            <?php else: ?>
                                <span class="text-muted">--</span>
                            <?php endif; ?>
                        </td>
                        <td><?= htmlspecialchars($agent['owner_name'] ?? '--') ?></td>
                        <td>
                            <?php
                            $statusClass = match($agent['status']) {
                                'online' => 'success',
                                'offline' => 'secondary',
                                'error' => 'danger',
                                default => 'warning',
                            };
                            ?>
                            <span class="badge bg-<?= $statusClass ?>"><?= ucfirst($agent['status']) ?></span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 px-3 py-2 border-top">
                <div class="d-flex align-items-center gap-2">
                    <label class="form-label small text-muted mb-0" for="clientsPageSize">Show</label>
                    <select id="clientsPageSize" class="form-select form-select-sm" style="width:auto;">
                        <option value="25" selected>25</option>
                        <option value="50">50</option>
                        <option value="100">100</option>
                        <option value="0">All</option>
                    </select>
                    <span id="clientsPageInfo" class="small text-muted"></span>
                </div>
                <nav><ul class="pagination pagination-sm mb-0" id="clientsPager"></ul></nav>
            </div>
        </div>

        <!-- Mobile card/list view -->
        <div class="d-md-none">
            <?php if (empty($agents)): ?>
            <div class="text-center text-muted py-4">No clients configured. Click "Add Client" to get started.</div>
            <?php endif; ?>
            <div class="list-group list-group-flush" id="clientsList">
                <?php foreach ($agents as $agent):
                    $statusClass = match($agent['status']) {
                        'online' => 'success',
                        'offline' => 'secondary',
                        'error' => 'danger',
                        default => 'warning',
                    };
                ?>
                <a href="/clients/<?= $agent['id'] ?>" class="list-group-item list-group-item-action py-3">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="fw-bold">
                                <i class="bi bi-pc-display me-1 text-muted"></i>
                                <?= htmlspecialchars($agent['name']) ?>
                            </div>
                            <?php if ($agent['hostname']): ?>
                            <small class="text-muted"><?= htmlspecialchars($agent['hostname']) ?></small>
                            <?php endif; ?>
                        </div>
                        <span class="badge bg-<?= $statusClass ?>"><?= ucfirst($agent['status']) ?></span>
                    </div>
                    <div class="d-flex gap-3 mt-2 small text-muted">
                        <span><i class="bi bi-stack me-1"></i><?= number_format($agent['restore_points']) ?> pts</span>
                        <span><i class="bi bi-archive me-1"></i><?= $agent['repo_count'] ?> repos</span>
                    </div>
                </a>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<?php if (!empty($agents)): ?>
<script>
// Sort / search / paginate for the clients table. Hand-rolled rather than
// pulled in: the whole table is already rendered server-side, this is a
// hundred lines, and a self-hosted install shouldn't need to reach a CDN.
(function () {
    var table = document.getElementById('clientsTable');
    if (!table) return;
    var tbody = table.tBodies[0];
    var allRows = Array.prototype.slice.call(tbody.rows);
    var pager = document.getElementById('clientsPager');
    var info = document.getElementById('clientsPageInfo');
    var sizeSel = document.getElementById('clientsPageSize');
    var search = document.getElementById('clientSearch');

    var state = { term: '', sortCol: null, sortAsc: true, page: 1, size: parseInt(sizeSel.value, 10) };

    // Sort on the cell's data-sort when it has one. Relative times ("38m ago")
    // and formatted sizes ("1.2 GB") do not sort as text, so those cells carry
    // the underlying number and this uses that instead.
    function keyFor(row, col) {
        var cell = row.cells[col];
        if (!cell) return '';
        if (cell.hasAttribute('data-sort')) return parseFloat(cell.getAttribute('data-sort')) || 0;
        return cell.textContent.trim().toLowerCase();
    }

    function visibleRows() {
        if (!state.term) return allRows;
        return allRows.filter(function (r) {
            return r.textContent.toLowerCase().indexOf(state.term) !== -1;
        });
    }

    function render() {
        var rows = visibleRows();

        if (state.sortCol !== null) {
            rows = rows.slice().sort(function (a, b) {
                var ka = keyFor(a, state.sortCol), kb = keyFor(b, state.sortCol);
                var cmp = (typeof ka === 'number' && typeof kb === 'number')
                    ? ka - kb
                    : String(ka).localeCompare(String(kb), undefined, { numeric: true });
                return state.sortAsc ? cmp : -cmp;
            });
        }

        var total = rows.length;
        var pages = state.size === 0 ? 1 : Math.max(1, Math.ceil(total / state.size));
        if (state.page > pages) state.page = pages;
        var start = state.size === 0 ? 0 : (state.page - 1) * state.size;
        var end = state.size === 0 ? total : Math.min(start + state.size, total);

        tbody.textContent = '';
        if (total === 0) {
            var tr = document.createElement('tr');
            var td = document.createElement('td');
            td.colSpan = table.tHead.rows[0].cells.length;
            td.className = 'text-center text-muted py-4';
            td.textContent = 'No clients match "' + state.term + '".';
            tr.appendChild(td); tbody.appendChild(tr);
        } else {
            rows.slice(start, end).forEach(function (r) { tbody.appendChild(r); });
        }

        info.textContent = total === 0 ? 'No matches'
            : 'Showing ' + (start + 1) + '\u2013' + end + ' of ' + total
              + (total === allRows.length ? '' : ' (filtered from ' + allRows.length + ')');

        pager.textContent = '';
        if (pages > 1) {
            var mk = function (label, page, disabled, active) {
                var li = document.createElement('li');
                li.className = 'page-item' + (disabled ? ' disabled' : '') + (active ? ' active' : '');
                var a = document.createElement('button');
                a.type = 'button'; a.className = 'page-link'; a.textContent = label;
                a.addEventListener('click', function () { state.page = page; render(); });
                li.appendChild(a); return li;
            };
            pager.appendChild(mk('\u2039', state.page - 1, state.page === 1, false));
            // Window the page numbers so 40 clients don't produce 40 buttons.
            var from = Math.max(1, state.page - 2), to = Math.min(pages, from + 4);
            from = Math.max(1, to - 4);
            for (var i = from; i <= to; i++) pager.appendChild(mk(String(i), i, false, i === state.page));
            pager.appendChild(mk('\u203a', state.page + 1, state.page === pages, false));
        }
    }

    table.querySelectorAll('th[data-sortable]').forEach(function (th, i) {
        th.style.cursor = 'pointer';
        th.title = th.title || 'Sort by ' + th.textContent.replace(/\s+/g, ' ').trim();
        var caret = document.createElement('i');
        caret.className = 'bi bi-arrow-down-up ms-1 text-muted';
        caret.style.fontSize = '0.7rem';
        caret.style.opacity = '0.4';
        th.appendChild(caret);
        th.addEventListener('click', function () {
            var col = Array.prototype.indexOf.call(th.parentNode.cells, th);
            state.sortAsc = (state.sortCol === col) ? !state.sortAsc : true;
            state.sortCol = col;
            state.page = 1;
            table.querySelectorAll('th[data-sortable] i').forEach(function (ic) {
                ic.className = 'bi bi-arrow-down-up ms-1 text-muted';
                ic.style.opacity = '0.4';
            });
            caret.className = 'bi ms-1 ' + (state.sortAsc ? 'bi-sort-down-alt' : 'bi-sort-up-alt');
            caret.style.opacity = '1';
            render();
        });
    });

    sizeSel.addEventListener('change', function () {
        state.size = parseInt(this.value, 10);
        state.page = 1;
        render();
    });

    search.addEventListener('input', function () {
        state.term = this.value.toLowerCase();
        state.page = 1;
        render();
        // The mobile list is a plain list, so it just filters.
        document.querySelectorAll('#clientsList .list-group-item').forEach(function (item) {
            item.style.display = item.textContent.toLowerCase().indexOf(state.term) !== -1 ? '' : 'none';
        });
    });

    render();
})();
</script>
<script src="/assets/chartjs/chart.umd.min.js"></script>
<script>
(function() {
    const _dk = document.documentElement.getAttribute('data-bs-theme') === 'dark';
    const _tc = _dk ? '#8b929a' : '#6c757d';
    const _gc = _dk ? 'rgba(255,255,255,0.08)' : 'rgba(0,0,0,0.05)';

    // Activity Chart (7 days). Failed jobs are split into "Backup Failed"
    // (red — actual data risk) and "Other Failed" (amber — updates that
    // couldn't run because the client was asleep, plugin tests, etc.) per
    // #141 so a single red bar no longer implies a backup disaster.
    const activityData = <?= json_encode($chartActivity) ?>;
    const actCtx = document.getElementById('activityChart');
    if (actCtx) {
        new Chart(actCtx, {
            type: 'bar',
            data: {
                labels: activityData.map(d => d.label),
                datasets: [
                    {
                        label: 'Backups',
                        data: activityData.map(d => d.backups),
                        backgroundColor: 'rgba(54, 162, 235, 0.75)',
                        borderRadius: 3,
                    },
                    {
                        label: 'S3 Sync',
                        data: activityData.map(d => d.s3_sync),
                        backgroundColor: 'rgba(75, 192, 192, 0.75)',
                        borderRadius: 3,
                    },
                    {
                        label: 'Backup Failed',
                        data: activityData.map(d => d.backup_failed),
                        backgroundColor: '#c0392b',
                        borderRadius: 3,
                    },
                    {
                        label: 'Other Failed',
                        data: activityData.map(d => d.other_failed),
                        backgroundColor: 'rgba(241, 196, 15, 0.85)',
                        borderRadius: 3,
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { position: 'bottom', labels: { boxWidth: 12, padding: 15, font: { size: 11 }, color: _tc } } },
                scales: {
                    x: { stacked: true, grid: { display: false }, ticks: { color: _tc } },
                    y: { stacked: true, beginAtZero: true, ticks: { stepSize: 1, font: { size: 11 }, color: _tc }, grid: { color: _gc } }
                }
            }
        });
    }

    // Storage by Client Chart
    const storageData = <?= json_encode($storageByClient) ?>;
    const storCtx = document.getElementById('storageChart');
    if (storCtx && storageData.length > 0) {
        const colors = ['#4a90d9', '#48bb78', '#e67e22', '#c0392b', '#9b59b6', '#95a5a6'];
        new Chart(storCtx, {
            type: 'doughnut',
            data: {
                labels: storageData.map(d => d.name),
                datasets: [{
                    data: storageData.map(d => d.size),
                    backgroundColor: colors.slice(0, storageData.length),
                    borderWidth: 2,
                    borderColor: _dk ? '#212529' : '#fff',
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'right', labels: { boxWidth: 12, padding: 10, font: { size: 11 }, color: _tc } },
                    tooltip: {
                        callbacks: {
                            label: function(ctx) {
                                let bytes = ctx.raw;
                                let label = ctx.label || '';
                                const s = '\u00A0';
                                if (bytes >= 1099511627776) return label + ': ' + (bytes / 1099511627776).toFixed(1) + s + 'TB';
                                if (bytes >= 1073741824) return label + ': ' + (bytes / 1073741824).toFixed(1) + s + 'GB';
                                if (bytes >= 1048576) return label + ': ' + (bytes / 1048576).toFixed(1) + s + 'MB';
                                return label + ': ' + (bytes / 1024).toFixed(1) + s + 'KB';
                            }
                        }
                    }
                }
            }
        });
    }
})();
</script>
<?php endif; ?>
