<?php
use BBS\Services\ServerStats;
use BBS\Core\TimeHelper;

$isAdmin = ($_SESSION['user_role'] ?? '') === 'admin';

// ---- Helpers ----
$compact = function (int $n): string {
    if ($n >= 1_000_000) return round($n / 1_000_000, 1) . 'M';
    if ($n >= 10_000)    return round($n / 1_000, 1) . 'K';
    return number_format($n);
};
$fmtUptime = function (?int $s): string {
    if ($s === null) return '—';
    $d = intdiv($s, 86400); $s %= 86400;
    $h = intdiv($s, 3600);  $s %= 3600;
    $m = intdiv($s, 60);
    if ($d > 0) return "{$d}d {$h}h";
    if ($h > 0) return "{$h}h {$m}m";
    return "{$m}m";
};
// Dedup savings % (original data vs actual disk footprint).
// Clamp the displayed value at 99.9% when there's still bytes on disk —
// round() lifts 99.95%+ to 100% which misrepresents a non-empty repo (#191).
$dedupSavingsPct = $totalOriginalBytes > 0
    ? round((1 - $totalDiskBytes / $totalOriginalBytes) * 100, 1)
    : 0;
if ($dedupSavingsPct >= 100 && $totalDiskBytes > 0) {
    $dedupSavingsPct = 99.9;
}

// df output from the OS uses single-letter units ("100G"). Add a non-breaking
// space + "B" suffix so it matches our standard "100 GB" format.
$dfFix = function (string $s): string {
    if (preg_match('/^([\d.]+)([TGMK])$/', $s, $m)) return $m[1] . "\u{00A0}" . $m[2] . 'B';
    return $s;
};
?>

<style>
.v2 .health-row {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 6px;
}
.v2 .health-row:last-child { margin-bottom: 0; }
.v2 .health-row .lbl {
    /* Fixed-width label column so every progress bar starts at the same
       x-coordinate — long mount paths and short labels like "CPU" no
       longer push the bars to different widths between rows. Truncates
       with ellipsis past 95px. */
    flex: 0 0 95px;
    font-size: 0.72rem;
    color: var(--bs-secondary-color);
    font-weight: 400;
    text-align: left;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.v2 .health-row .progress {
    flex: 1;
    height: 0.95rem;
    font-size: 0.66rem;
    font-weight: 600;
    position: relative;
}
.v2 .health-row .progress-bar { transition: width 0.4s, background-color 0.2s; }
/* Overlay label spans the full progress container, so the % is always
   visible and centered regardless of how narrow the filled portion is. */
.v2 .health-row .progress-label {
    position: absolute;
    inset: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #fff;
    text-shadow: 0 2px 3px rgba(0,0,0,0.9), 0 2px 6px rgba(0,0,0,0.9);
    pointer-events: none;
}
/* Right column also fixed-width so the bar's right edge is consistent
   across rows. min-width: 80 was producing dead whitespace between
   the bar and short values like "0.48". */
.v2 .health-row .val {
    flex: 0 0 60px;
    font-size: 0.72rem;
    font-variant-numeric: tabular-nums;
    text-align: right;
    color: var(--bs-body-color);
}

/* File Catalog card — stats table with hairline separators (no striped bg).
   --bs-table-bg override strips the table's own background so the card's
   background shows through instead of a darker block behind the rows. */
.v2 .ch-stats-table {
    --bs-table-bg: transparent;
    background-color: transparent;
}
.v2 .ch-stats-table td {
    background-color: transparent;
    border-top: 0;
    border-bottom: 1px solid var(--bs-border-color-translucent);
}
.v2 .ch-stats-table tr:last-child td { border-bottom: 0; }

/* On mobile / narrow viewports the File Catalog flex container wraps. Let
   the stats table use the full card width instead of staying pinned to
   260px, and give it breathing room before the donut + top-repos block. */
@media (max-width: 767.98px) {
    .v2 .ch-stats-wrap {
        max-width: none !important;
        margin-right: 0 !important;
        margin-bottom: 12px !important;
        width: 100%;
    }
}

/* Recently Completed — smaller column headers per user feedback */
.v2 .recent-jobs-table thead th {
    font-size: 75%;
    font-weight: 600;
    letter-spacing: 0.02em;
}

/* File Catalog card — Top Repositories rows, tighter line-height */
.v2 .top-repo-row {
    padding: 2px 4px;
    line-height: 1.25;
}

.v2 .storage-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
    gap: 12px;
    max-width: 100%;
}
/* On large screens, fit exactly N columns (set via inline CSS var).
   Falls back to auto-fill below 1200px or when cards are > 5. */
@media (min-width: 1200px) {
    .v2 .storage-grid.exact-cols {
        grid-template-columns: repeat(var(--storage-cols), 1fr);
    }
}
/* Cap any single storage card to 50% of the container so a single
   storage location doesn't stretch across the whole row. */
.v2 .storage-grid.single-col .storage-card { max-width: 50%; }
.v2 .storage-card {
    background: var(--bs-body-bg);
    border: 1px solid var(--bs-border-color);
    border-radius: 10px;
    padding: 12px 14px;
    transition: border-color 0.12s;
}
.v2 .storage-card:hover { border-color: var(--bs-primary); }
.v2 .storage-card .sc-head { display: flex; justify-content: space-between; align-items: start; gap: 8px; margin-bottom: 8px; }
.v2 .storage-card .sc-label { font-weight: 600; font-size: 0.9rem; word-break: break-word; }
.v2 .storage-card .sc-kind { font-size: 0.65rem; text-transform: uppercase; letter-spacing: 0.04em; color: var(--bs-secondary-color); }
.v2 .storage-card .sc-kind.remote { color: #9ec5fe; }
.v2 .storage-card .sc-path { font-size: 0.72rem; color: var(--bs-secondary-color); font-family: ui-monospace, Menlo, Consolas, monospace; margin-bottom: 8px; word-break: break-all; }
.v2 .storage-card .sc-bar { height: 8px; background: var(--bs-tertiary-bg); border-radius: 4px; overflow: hidden; margin-bottom: 6px; }
.v2 .storage-card .sc-fill { height: 100%; border-radius: 4px; }
.v2 .storage-card .sc-numbers { display: flex; justify-content: space-between; font-size: 0.78rem; font-variant-numeric: tabular-nums; }
.v2 .storage-card .sc-footer { display: flex; justify-content: space-between; font-size: 0.72rem; color: var(--bs-secondary-color); margin-top: 8px; padding-top: 8px; border-top: 1px solid var(--bs-border-color); }

.v2 .mini-stat { display: flex; justify-content: space-between; padding: 4px 0; font-size: 0.82rem; }
.v2 .mini-stat .k { color: var(--bs-secondary-color); }
.v2 .mini-stat .v { font-weight: 600; font-variant-numeric: tabular-nums; }

.v2 .table thead th {
    font-size: 0.875rem;
    text-transform: none;
    letter-spacing: 0;
    color: var(--bs-secondary-color);
    font-weight: 600;
}
</style>

<div class="v2 container-fluid px-0">
    <!-- Row 1: Hero tiles — same icon-on-left pattern as /clients and /queue -->
    <?php $errCls = $errorCount > 0 ? 'danger' : 'success'; ?>
    <div class="row g-3 mb-3">
        <div class="col-xl-3 col-md-6">
            <a href="/clients" class="text-decoration-none text-reset metric-card-link d-block">
                <div class="card border-0 shadow-sm h-100 metric-card-blue">
                    <div class="card-body d-flex align-items-center">
                        <div class="stat-icon bg-primary bg-opacity-10 text-primary rounded-3 p-3 me-3">
                            <i class="bi bi-display fs-3"></i>
                        </div>
                        <div>
                            <div class="text-muted small">Clients</div>
                            <div class="fs-4 fw-bold" id="tile-agent-count"><?= $agentCount ?></div>
                            <div class="text-muted small">
                                <span class="text-success fw-semibold" id="tile-online-count"><?= $onlineCount ?></span>
                                online · <span id="tile-offline-count"><?= max(0, $agentCount - $onlineCount) ?></span> offline
                            </div>
                        </div>
                    </div>
                </div>
            </a>
        </div>
        <div class="col-xl-3 col-md-6">
            <a href="/queue" class="text-decoration-none text-reset metric-card-link d-block">
                <div class="card border-0 shadow-sm h-100 metric-card-success">
                    <div class="card-body d-flex align-items-center">
                        <div class="stat-icon bg-success bg-opacity-10 text-success rounded-3 p-3 me-3">
                            <i class="bi bi-arrow-repeat fs-3"></i>
                        </div>
                        <div>
                            <div class="text-muted small">Running</div>
                            <div class="fs-4 fw-bold" id="tile-running-count"><?= $runningJobs ?></div>
                            <div class="text-muted small">active · <span id="tile-queued-count"><?= $queuedJobs ?></span> queued</div>
                        </div>
                    </div>
                </div>
            </a>
        </div>
        <div class="col-xl-3 col-md-6">
            <a href="#recovery-points" class="text-decoration-none text-reset metric-card-link d-block">
                <div class="card border-0 shadow-sm h-100 metric-card-warning">
                    <div class="card-body d-flex align-items-center">
                        <div class="stat-icon bg-warning bg-opacity-10 text-warning rounded-3 p-3 me-3">
                            <i class="bi bi-archive fs-3"></i>
                        </div>
                        <div>
                            <div class="text-muted small">Recovery Points</div>
                            <div class="fs-4 fw-bold"><?= $compact($totalArchiveCount) ?></div>
                            <div class="text-muted small"><?= ServerStats::formatBytes($totalOriginalBytes) ?> protected · <?= ServerStats::formatBytes($totalDiskBytes) ?> on disk</div>
                        </div>
                    </div>
                </div>
            </a>
        </div>
        <div class="col-xl-3 col-md-6">
            <a href="/log?level=error&hours=24" id="tile-errors-link" class="text-decoration-none text-reset metric-card-link d-block">
                <div class="card border-0 shadow-sm h-100 metric-card-<?= $errCls ?>" id="tile-errors-card">
                    <div class="card-body d-flex align-items-center">
                        <div class="stat-icon bg-<?= $errCls ?> bg-opacity-10 text-<?= $errCls ?> rounded-3 p-3 me-3" id="tile-errors-icon-wrap">
                            <i class="bi bi-<?= $errorCount > 0 ? 'exclamation-circle' : 'check-circle' ?> fs-3" id="tile-errors-icon"></i>
                        </div>
                        <div>
                            <div class="text-muted small">Errors (24h)</div>
                            <div class="fs-4 fw-bold" id="tile-error-count"><?= $errorCount ?></div>
                            <div class="text-muted small">check logs</div>
                        </div>
                    </div>
                </div>
            </a>
        </div>
    </div>

    <!-- Row 2: Activity chart | Backup summary | Server health (admin only) -->
    <?php
        // Admin: Jobs gets half, Backup Summary + Server Health share the
        // other half equally. Non-admin: Jobs + Backup Summary split 7/5.
        $row2JobsCol = $isAdmin ? 'col-xl-6 col-lg-6' : 'col-lg-7';
        $row2SummaryCol = $isAdmin ? 'col-xl-3 col-lg-6' : 'col-lg-5';
        $row2HealthCol = 'col-xl-3 col-lg-12';
    ?>
    <div class="row g-3 mb-3">
        <div class="<?= $row2JobsCol ?>">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header card-head-gradient fw-semibold">
                    <i class="bi bi-bar-chart me-2"></i>Jobs (Last 24h)
                </div>
                <div class="card-body py-2">
                    <div style="position: relative; height: 160px;">
                        <canvas id="jobsChart"></canvas>
                    </div>
                </div>
            </div>
        </div>

        <div class="<?= $row2SummaryCol ?>" id="recovery-points">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header card-head-gradient fw-semibold">
                    <i class="bi bi-shield-check me-2"></i>Backup Summary
                </div>
                <div class="card-body">
                    <div class="mini-stat"><span class="k"><i class="bi bi-archive me-1"></i>Recovery points</span><span class="v"><?= number_format($totalArchiveCount) ?></span></div>
                    <div class="mini-stat"><span class="k"><i class="bi bi-files me-1"></i>Original data</span><span class="v"><?= ServerStats::formatBytes($totalOriginalBytes) ?></span></div>
                    <div class="mini-stat"><span class="k"><i class="bi bi-hdd me-1"></i>On disk (deduped)</span><span class="v"><?= ServerStats::formatBytes($totalDiskBytes) ?></span></div>
                    <div class="mini-stat"><span class="k"><i class="bi bi-magic me-1"></i>Dedup savings</span><span class="v text-success"><?= $dedupSavingsPct ?>%</span></div>
                    <?php if ($lastBackup): ?>
                    <div class="mini-stat"><span class="k"><i class="bi bi-clock-history me-1"></i>Last backup</span><span class="v"><?= TimeHelper::ago($lastBackup['completed_at']) ?></span></div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <?php if ($isAdmin): ?>
        <div class="<?= $row2HealthCol ?>">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header card-head-gradient fw-semibold">
                    <i class="bi bi-cpu me-2"></i>Server Health
                </div>
                <div class="card-body">
                    <?php
                        $cpuPct = $cpuLoad['percent'] ?? 0;
                        $memPct = $memory['percent'] ?? 0;
                        $cpuColor = $cpuPct > 80 ? '#dc3545' : ($cpuPct > 50 ? '#ffc107' : '#198754');
                        $memColor = $memPct > 85 ? '#dc3545' : ($memPct > 60 ? '#ffc107' : '#0dcaf0');
                    ?>
                    <div class="health-row">
                        <span class="lbl">CPU</span>
                        <div class="progress" role="progressbar" aria-label="CPU usage" aria-valuenow="<?= $cpuPct ?>" aria-valuemin="0" aria-valuemax="100">
                            <div class="progress-bar" id="cpu-fill" style="width: <?= $cpuPct ?>%; background-color: <?= $cpuColor ?>;"></div>
                            <span class="progress-label"><?= $cpuPct ?>%</span>
                        </div>
                        <span class="val" id="cpu-val"><?= $cpuLoad['1min'] ?></span>
                    </div>
                    <div class="health-row">
                        <span class="lbl">Memory</span>
                        <div class="progress" role="progressbar" aria-label="Memory usage" aria-valuenow="<?= $memPct ?>" aria-valuemin="0" aria-valuemax="100">
                            <div class="progress-bar" id="mem-fill" style="width: <?= $memPct ?>%; background-color: <?= $memColor ?>;"></div>
                            <span class="progress-label"><?= $memPct ?>%</span>
                        </div>
                        <span class="val" id="mem-val"><?= ServerStats::formatBytes($memory['used']) ?></span>
                    </div>
                    <?php if (!empty($partitions)): ?>
                    <div id="health-partitions">
                    <?php foreach ($partitions as $part): ?>
                        <?php
                            $pPct = $part['percent'] ?? 0;
                            $pColor = $pPct > 90 ? '#dc3545' : ($pPct > 70 ? '#ffc107' : '#6c757d');
                        ?>
                    <div class="health-row" data-mount="<?= htmlspecialchars($part['mount']) ?>">
                        <span class="lbl text-truncate" title="<?= htmlspecialchars($part['mount']) ?>"><?= htmlspecialchars($part['mount']) ?></span>
                        <div class="progress" role="progressbar" aria-label="<?= htmlspecialchars($part['mount']) ?> usage" aria-valuenow="<?= $pPct ?>" aria-valuemin="0" aria-valuemax="100">
                            <div class="progress-bar part-fill" style="width: <?= $pPct ?>%; background-color: <?= $pColor ?>;"></div>
                            <span class="progress-label"><?= $pPct ?>%</span>
                        </div>
                        <span class="val part-val"><?= $dfFix($part['size']) ?></span>
                    </div>
                    <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Row 3: Storage Locations — admin only, infra detail -->
    <?php if ($isAdmin && !empty($storageLocations)): ?>
    <?php
        $formatStorageWidgetBytes = function (int $bytes, array $loc): string {
            $isBorgBaseApi = ($loc['kind'] ?? '') === 'remote'
                && ((($loc['provider'] ?? '') === 'borgbase') || str_contains((string)($loc['path'] ?? ''), '.repo.borgbase.com'))
                && (($loc['borgbase_usage_source'] ?? '') === 'borgbase_api');
            if (!$isBorgBaseApi) {
                return ServerStats::formatBytes($bytes);
            }
            $units = ['B', 'KB', 'MB', 'GB', 'TB'];
            $i = 0;
            $size = (float) $bytes;
            while ($size >= 1000 && $i < count($units) - 1) {
                $size /= 1000;
                $i++;
            }
            return round($size, 1) . "\u{00A0}" . $units[$i];
        };
    ?>
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-header card-head-gradient fw-semibold d-flex justify-content-between align-items-center">
            <span><i class="bi bi-hdd-stack me-2"></i>Storage Locations</span>
            <span class="text-muted small"><?= count($storageLocations) ?> configured</span>
        </div>
        <div class="card-body">
            <?php
                $locCount = count($storageLocations);
                $gridClasses = 'storage-grid';
                if ($locCount <= 6) $gridClasses .= ' exact-cols';
                if ($locCount === 1) $gridClasses .= ' single-col';
            ?>
            <div class="<?= $gridClasses ?>" style="--storage-cols: <?= min($locCount, 6) ?>">
                <?php foreach ($storageLocations as $loc): ?>
                <?php
                    $pct = $loc['disk_percent'] ?? 0;
                    $fillColor = $pct >= 90 ? '#dc3545' : ($pct >= 75 ? '#ffc107' : '#0dcaf0');
                ?>
                <div class="storage-card">
                    <div class="sc-head">
                        <div>
                            <div class="sc-label"><?= htmlspecialchars($loc['label']) ?></div>
                            <div class="sc-kind <?= $loc['kind'] === 'remote' ? 'remote' : '' ?>">
                                <?= $loc['kind'] === 'remote' ? 'Remote SSH' : ($loc['is_default'] ? 'Default · Local' : 'Local') ?>
                            </div>
                        </div>
                        <?php if ($loc['disk_percent'] !== null): ?>
                        <span class="fw-bold" style="color: <?= $fillColor ?>;"><?= $pct ?>%</span>
                        <?php else: ?>
                        <span class="text-muted small">n/a</span>
                        <?php endif; ?>
                    </div>
                    <div class="sc-path"><?= htmlspecialchars($loc['path']) ?></div>
                    <?php if ($loc['disk_total']): ?>
                    <div class="sc-bar"><div class="sc-fill" style="width: <?= $pct ?>%; background: <?= $fillColor ?>;"></div></div>
                    <div class="sc-numbers">
                        <span><?= $formatStorageWidgetBytes((int) $loc['disk_used'], $loc) ?> used</span>
                        <span><?= $formatStorageWidgetBytes((int) $loc['disk_free'], $loc) ?> free</span>
                    </div>
                    <?php else: ?>
                    <div class="text-muted small fst-italic">
                        <?= ($loc['kind'] === 'remote' && (($loc['provider'] ?? '') === 'borgbase' || str_contains((string)($loc['path'] ?? ''), '.repo.borgbase.com'))) ? 'Set quota manually or use API' : 'Quota unavailable' ?>
                    </div>
                    <?php endif; ?>
                    <div class="sc-footer">
                        <span><i class="bi bi-hdd me-1"></i><?= $loc['repo_count'] ?> repo<?= $loc['repo_count'] === 1 ? '' : 's' ?></span>
                        <span><?= ServerStats::formatBytes((int) $loc['repo_bytes']) ?> disk usage</span>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Row 4: Activity tables -->
    <div class="row g-3 mb-3">
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header card-head-gradient fw-semibold">
                    <i class="bi bi-lightning-charge me-2"></i>Active &amp; Queued
                </div>
                <div class="card-body p-0" id="active-jobs">
                    <?php if (empty($activeJobs)): ?>
                    <div class="p-5 text-muted text-center">
                        <i class="bi bi-hourglass d-block mb-2" style="font-size:1.8rem;opacity:0.4;"></i>
                        <div>No Active Jobs</div>
                    </div>
                    <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover mb-0 small">
                            <thead><tr><th>Client</th><th>Task</th><th class="d-th-md">Repo</th><th>Status</th></tr></thead>
                            <tbody>
                            <?php foreach ($activeJobs as $j): ?>
                                <?php
                                    $pct = ($j['files_total'] ?? 0) > 0 ? round(($j['files_processed'] / $j['files_total']) * 100) : null;
                                    $badgeClass = $j['status'] === 'queued' ? 'text-bg-warning' : 'text-bg-primary';
                                ?>
                                <tr style="cursor:pointer" onclick="window.location='/queue/<?= (int) $j['id'] ?>'">
                                    <td><?= htmlspecialchars($j['agent_name']) ?></td>
                                    <td><?= htmlspecialchars(ucfirst($j['task_type'])) ?></td>
                                    <td class="d-table-cell-md"><?= htmlspecialchars($j['repo_name'] ?? '--') ?></td>
                                    <td>
                                        <?php if ($pct !== null && $j['status'] === 'running'): ?>
                                        <div class="progress" style="height:18px;min-width:60px;"><div class="progress-bar progress-bar-striped progress-bar-animated bg-primary" style="width:<?= $pct ?>%"><?= $pct ?>%</div></div>
                                        <?php else: ?>
                                        <span class="badge <?= $badgeClass ?>"><?= htmlspecialchars(ucfirst($j['status'])) ?></span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header card-head-gradient fw-semibold d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-calendar-event me-2"></i>Upcoming Backups</span>
                    <a href="/schedules" class="small text-decoration-none" style="color: #9ec5fe;">View Schedule <i class="bi bi-arrow-right"></i></a>
                </div>
                <div class="card-body p-0" id="upcoming-backups">
                    <?php if (empty($upcomingSchedules)): ?>
                    <div class="p-4 text-muted text-center">No scheduled backups</div>
                    <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover mb-0 small">
                            <thead><tr><th>Client</th><th>Plan</th><th>Next Run</th><th></th></tr></thead>
                            <tbody>
                            <?php foreach (array_slice($upcomingSchedules, 0, 6) as $s): ?>
                                <?php
                                    $nextTs = strtotime($s['next_run'] ?? '');
                                    $isOverdue = $nextTs && $nextTs < time();
                                ?>
                                <tr style="cursor:pointer" onclick="window.location='/clients/<?= (int) $s['agent_id'] ?>?tab=schedules'">
                                    <td><?= htmlspecialchars($s['agent_name']) ?></td>
                                    <td><?= htmlspecialchars($s['plan_name']) ?></td>
                                    <td class="<?= $isOverdue ? 'text-danger fw-semibold' : '' ?>">
                                        <?php if ($isOverdue): ?><i class="bi bi-exclamation-triangle me-1" title="Agent Offline, Backup Delayed"></i><?php endif; ?>
                                        <?= TimeHelper::format($s['next_run'], 'M j, g:i A') ?>
                                    </td>
                                    <td class="text-nowrap" onclick="event.stopPropagation()">
                                        <form method="POST" action="/plans/<?= (int) $s['plan_id'] ?>/trigger" class="d-inline" data-confirm="Run <?= htmlspecialchars($s['agent_name']) ?> / <?= htmlspecialchars($s['plan_name']) ?> now?">
                                            <input type="hidden" name="csrf_token" value="<?= $this->csrfToken() ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-success py-0 px-2" title="Run now"><i class="bi bi-play-fill"></i></button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Row 5: Recent completed jobs -->
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-header card-head-gradient fw-semibold d-flex justify-content-between align-items-center">
            <span><i class="bi bi-check2-circle me-2"></i>Recently Completed</span>
            <div class="dropdown">
                <button id="recentFilterBtn" class="btn btn-sm btn-link text-white text-decoration-none p-0" type="button" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false" title="Filter by type">
                    <i class="bi bi-funnel"></i>
                    <span id="recentFilterCount" class="badge bg-primary bg-opacity-50 ms-1" style="display:none;font-size:0.6rem;"></span>
                </button>
                <ul class="dropdown-menu dropdown-menu-end p-2" style="min-width: 200px;">
                    <li class="small text-muted fw-semibold px-1 pb-1 border-bottom mb-1">Task Types</li>
                    <?php
                    $filterCats = [
                        ['backup',  'Backup',        'bi-box-arrow-in-down'],
                        ['restore', 'Restore',       'bi-box-arrow-up'],
                        ['prune',   'Prune',         'bi-scissors'],
                        ['compact', 'Compact',       'bi-archive'],
                        ['s3',      'S3 Sync',       'bi-cloud-upload'],
                        ['other',   'Other / Maint', 'bi-tools'],
                    ];
                    foreach ($filterCats as [$key, $label, $icon]): ?>
                    <li>
                        <label class="dropdown-item d-flex align-items-center gap-2 py-1 px-2 rounded" style="cursor:pointer;">
                            <input type="checkbox" class="form-check-input m-0 recent-filter-cb" data-cat="<?= $key ?>" checked>
                            <i class="bi <?= $icon ?> text-muted"></i>
                            <span><?= $label ?></span>
                        </label>
                    </li>
                    <?php endforeach; ?>
                    <li><hr class="dropdown-divider my-1"></li>
                    <li class="d-flex gap-2 px-1">
                        <button type="button" class="btn btn-sm btn-outline-secondary flex-grow-1" id="recentFilterAll">All</button>
                        <button type="button" class="btn btn-sm btn-outline-secondary flex-grow-1" id="recentFilterNone">None</button>
                    </li>
                </ul>
            </div>
        </div>
        <div class="card-body p-0" id="recent-jobs">
            <?php if (empty($recentJobs)): ?>
            <div class="p-4 text-muted text-center">No completed jobs yet</div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover mb-0 small recent-jobs-table">
                    <thead><tr><th>Client</th><th>Task</th><th class="d-th-md">Plan</th><th class="d-th-md">Repo</th><th>Completed</th><th class="d-th-md">Duration</th><th class="text-center">Status</th></tr></thead>
                    <tbody>
                    <?php
                        // Task-type icon map — must stay aligned with src/Views/queue/index.php
                        // so Dashboard "Recently Completed" looks like Queue at a glance (#172).
                        $taskIcons = [
                            'backup'          => 'bi-box-seam text-warning',
                            'prune'           => 'bi-scissors text-secondary',
                            'compact'         => 'bi-arrows-collapse text-info',
                            'restore'         => 'bi-cloud-download text-primary',
                            'restore_mysql'   => 'bi-database text-primary',
                            'restore_pg'      => 'bi-database text-primary',
                            'restore_mongo'   => 'bi-database text-primary',
                            'check'           => 'bi-shield-check text-success',
                            'repo_check'      => 'bi-shield-check text-success',
                            'repo_repair'     => 'bi-tools text-warning',
                            'break_lock'      => 'bi-unlock text-secondary',
                            'update_borg'     => 'bi-arrow-up-square text-info',
                            'update_agent'    => 'bi-arrow-up-square text-info',
                            'plugin_test'     => 'bi-pencil text-secondary',
                            's3_sync'         => 'bi-cloud-upload text-info',
                            's3_restore'      => 'bi-cloud-download text-info',
                            'catalog_sync'    => 'bi-list-ul text-success',
                            'catalog_rebuild' => 'bi-list-ul text-success',
                        ];
                    ?>
                    <?php foreach ($recentJobs as $j): ?>
                        <?php
                            $hadWarn = ($j['status'] === 'completed' && !empty($j['had_warnings']));
                            $statusIcon = $hadWarn ? 'bi-exclamation-triangle-fill text-warning'
                                : ($j['status'] === 'completed' ? 'bi-check-circle-fill text-success'
                                : ($j['status'] === 'failed' ? 'bi-x-circle-fill text-danger' : 'bi-slash-circle-fill text-secondary'));
                            $statusTitle = $hadWarn
                                ? 'Completed with warnings: ' . substr($j['error_log'] ?? '', 0, 200)
                                : '';
                            $taskIcon = $taskIcons[$j['task_type']] ?? 'bi-gear text-muted';
                        ?>
                        <tr style="cursor:pointer" onclick="window.location='/queue/<?= (int) $j['id'] ?>'">
                            <td><?= htmlspecialchars($j['agent_name']) ?></td>
                            <td class="text-nowrap"><i class="bi <?= $taskIcon ?> me-1"></i><?= htmlspecialchars(ucfirst($j['task_type'])) ?></td>
                            <td class="d-table-cell-md"><?= htmlspecialchars($j['plan_name'] ?? '--') ?></td>
                            <td class="d-table-cell-md"><?= htmlspecialchars($j['repo_name'] ?? '--') ?></td>
                            <td><?= TimeHelper::ago($j['completed_at']) ?></td>
                            <td class="d-table-cell-md"><?= TimeHelper::duration((int) ($j['duration_seconds'] ?? 0)) ?></td>
                            <td class="text-center"><i class="bi <?= $statusIcon ?>"<?= $statusTitle ? ' data-bs-toggle="tooltip" title="' . htmlspecialchars($statusTitle) . '"' : '' ?>></i></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($isAdmin): ?>
    <!-- Row 6: MariaDB (2/5) + File Catalog (3/5) -->
    <div class="row g-3 mb-3">
        <?php if (!empty($mysqlStats)): ?>
        <?php
            $msUptime = (int) ($mysqlStats['uptime'] ?? 0);
            $msUptimeStr = $msUptime >= 86400
                ? intdiv($msUptime, 86400) . 'd ' . intdiv($msUptime % 86400, 3600) . 'h'
                : intdiv($msUptime, 3600) . 'h ' . intdiv($msUptime % 3600, 60) . 'm';
        ?>
        <div class="col-lg-5">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header card-head-gradient fw-semibold">
                    <i class="bi bi-database me-2"></i>MariaDB
                </div>
                <div class="card-body py-3">
                    <div class="row g-0 text-center">
                        <div class="col-4 py-2">
                            <div class="fw-bold" style="font-size:1.1rem;"><?= $mysqlStats['qps'] ?? 0 ?></div>
                            <div class="text-muted" style="font-size:0.7rem;">QPS</div>
                        </div>
                        <div class="col-4 py-2">
                            <div class="fw-bold" style="font-size:1.1rem;"><?= $mysqlStats['threads_connected'] ?? 0 ?></div>
                            <div class="text-muted" style="font-size:0.7rem;">Connections</div>
                        </div>
                        <div class="col-4 py-2">
                            <div class="fw-bold" style="font-size:1.1rem;"><?= $mysqlStats['hit_rate'] ?? 0 ?>%</div>
                            <div class="text-muted" style="font-size:0.7rem;">Hit Rate</div>
                        </div>
                        <div class="col-4 py-2">
                            <div class="fw-bold" style="font-size:1.1rem;"><?= $msUptimeStr ?></div>
                            <div class="text-muted" style="font-size:0.7rem;">Uptime</div>
                        </div>
                        <div class="col-4 py-2">
                            <div class="fw-bold" style="font-size:1.1rem;"><?= $mysqlStats['buffer_pool_used_pct'] ?? 0 ?>%</div>
                            <div class="text-muted" style="font-size:0.7rem;">Buffer Pool</div>
                        </div>
                        <div class="col-4 py-2">
                            <div class="fw-bold" style="font-size:1.1rem;"><?= $compact((int) ($mysqlStats['slow_queries'] ?? 0)) ?></div>
                            <div class="text-muted" style="font-size:0.7rem;">Slow Queries</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php if (!empty($clickhouseStats ?? null)): ?>
        <?php
            $chTopRepos = $clickhouseStats['top_repos'] ?? [];
            $chDiskBytes = (int) ($clickhouseStats['disk_bytes'] ?? 0);
            $pieColors = ['#36a2eb','#ff6384','#ffce56','#4bc0c0','#9966ff','#6c757d'];
        ?>
        <div class="col-lg-7">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header card-head-gradient fw-semibold">
                    <i class="bi bi-list-columns-reverse me-2"></i>File Catalog (ClickHouse)
                </div>
                <div class="card-body py-2">
                    <div class="d-flex flex-wrap" style="gap: 0;">
                        <!-- Stats (left) — expands full-width when wrapped on mobile -->
                        <div class="ch-stats-wrap" style="min-width:220px;max-width:260px;margin-right:60px;">
                            <?php
                            $chStatRows = [
                                ['Catalog rows', $compact((int) ($clickhouseStats['total_rows'] ?? 0))],
                                ['Index size',   ServerStats::formatBytes($chDiskBytes)],
                                ['Compression',  ($clickhouseStats['compression_ratio'] ?? 0) . '×'],
                                ['Indexed clients', (int) ($clickhouseStats['agent_count'] ?? 0)],
                            ];
                            ?>
                            <table class="table table-sm mb-0 ch-stats-table" style="font-size:0.82rem;">
                                <tbody>
                                <?php foreach ($chStatRows as $ri => $row): ?>
                                <tr>
                                    <td class="text-muted py-1 ps-2"><?= $row[0] ?></td>
                                    <td class="fw-bold py-1 pe-2 text-end"><?= $row[1] ?></td>
                                </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <!-- Donut + Top repos (right, aligned at top) -->
                        <?php if (!empty($chTopRepos)): ?>
                        <div class="d-flex align-items-start flex-grow-1" style="gap: 16px; min-width: 240px;">
                            <div class="flex-shrink-0 pt-1">
                                <canvas id="catalogPieChart" width="110" height="110"></canvas>
                            </div>
                            <div class="flex-grow-1">
                                <div class="small fw-semibold text-uppercase mb-1" style="font-size:0.65rem;letter-spacing:0.03em;color:var(--bs-secondary-color);"><i class="bi bi-trophy me-1"></i>Top Repositories</div>
                                <?php foreach ($chTopRepos as $i => $repo): ?>
                                <div class="d-flex align-items-center justify-content-between top-repo-row" style="font-size:0.8rem;<?= $i % 2 === 1 ? 'background:var(--bs-tertiary-bg);border-radius:3px;' : '' ?>">
                                    <span class="text-truncate me-2"><span style="display:inline-block;width:8px;height:8px;border-radius:2px;background:<?= $pieColors[$i % 6] ?>;margin-right:6px;"></span><?= htmlspecialchars($repo['name']) ?></span>
                                    <span class="text-muted text-nowrap" style="font-size:0.75rem;font-variant-numeric:tabular-nums;"><?= $compact((int) $repo['rows']) ?> rows</span>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php else: ?>
                        <div class="text-muted small fst-italic align-self-center">No catalog data yet</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- Server identity footer -->
    <div class="text-center text-muted mt-4 pb-2" style="font-size: 0.8125rem;">
        <span title="Server version"><i class="bi bi-box-seam me-1"></i>Borg Backup Server <?= htmlspecialchars($bbsVersion) ?></span>
        <span class="mx-2">·</span>
        <span title="Hostname"><i class="bi bi-hdd-network me-1"></i><?= htmlspecialchars($serverHost) ?></span>
        <span class="mx-2">·</span>
        <span title="OS"><i class="bi bi-terminal me-1"></i><?= htmlspecialchars($osName) ?></span>
        <span class="mx-2">·</span>
        <span title="Uptime"><i class="bi bi-clock-history me-1"></i><?= $fmtUptime($uptimeSec) ?></span>
        <span class="mx-2">·</span>
        <span>Open Source &amp; Made with <i class="bi bi-heart-fill text-danger"></i> by Marc Pope</span>
        <a href="https://github.com/sponsors/marcpope" target="_blank" rel="noopener" class="text-decoration-none ms-2" title="Support this project on GitHub Sponsors">Sponsor</a>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const chartData = <?= json_encode($chartData) ?>;
    const isDark = document.documentElement.getAttribute('data-bs-theme') === 'dark';
    const tc = isDark ? '#8b929a' : '#6c757d';
    const gc = isDark ? 'rgba(255,255,255,0.06)' : 'rgba(0,0,0,0.04)';

    // Chart.js by default renders tooltips onto the canvas, so they get
    // clipped to the canvas rectangle. The ClickHouse doughnut canvas is
    // only 110×110, so any label beyond a few characters is cut off
    // (issue #164). This external tooltip handler renders an HTML div
    // appended to <body> instead, escaping the canvas entirely.
    const chartTooltipEl = (function () {
        let el = document.getElementById('chartjs-ext-tooltip');
        if (!el) {
            el = document.createElement('div');
            el.id = 'chartjs-ext-tooltip';
            el.style.cssText =
                'position:absolute;pointer-events:none;z-index:2147483647;' +
                'background:rgba(0,0,0,0.85);color:#fff;border-radius:4px;' +
                'padding:6px 10px;font-size:0.78rem;white-space:nowrap;' +
                'transform:translate(-50%,-100%);transition:opacity 0.1s;opacity:0;';
            document.body.appendChild(el);
        }
        return el;
    })();
    function externalTooltip(ctx) {
        const { chart, tooltip } = ctx;
        if (tooltip.opacity === 0) { chartTooltipEl.style.opacity = 0; return; }
        if (tooltip.body) {
            const titleLines = tooltip.title || [];
            const bodyLines = tooltip.body.map(b => b.lines);
            let html = '';
            titleLines.forEach(t => { html += '<div style="font-weight:600;margin-bottom:2px;">' + t.replace(/[&<>]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;'}[c])) + '</div>'; });
            bodyLines.forEach(lines => { html += '<div>' + lines.join(' ').replace(/[&<>]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;'}[c])) + '</div>'; });
            chartTooltipEl.innerHTML = html;
        }
        const rect = chart.canvas.getBoundingClientRect();
        chartTooltipEl.style.opacity = 1;
        chartTooltipEl.style.left = (window.scrollX + rect.left + tooltip.caretX) + 'px';
        chartTooltipEl.style.top  = (window.scrollY + rect.top + tooltip.caretY - 8) + 'px';
    }

    new Chart(document.getElementById('jobsChart').getContext('2d'), {
        type: 'bar',
        data: {
            labels: chartData.map(d => d.label),
            datasets: [
                { label: 'Backups', data: chartData.map(d => d.backups), backgroundColor: 'rgba(54, 162, 235, 0.7)', borderRadius: 2 },
                { label: 'Restores', data: chartData.map(d => d.restores), backgroundColor: 'rgba(255, 159, 64, 0.7)', borderRadius: 2 },
                { label: 'S3 Sync', data: chartData.map(d => d.s3_sync), backgroundColor: 'rgba(75, 192, 192, 0.7)', borderRadius: 2 },
                { label: 'Errors', data: chartData.map(d => d.errors), backgroundColor: 'rgba(220, 53, 69, 0.8)', borderRadius: 2 },
            ]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            plugins: { legend: { position: 'bottom', labels: { boxWidth: 10, font: { size: 9 }, padding: 8, color: tc } } },
            scales: {
                y: { beginAtZero: true, stacked: true, ticks: { stepSize: 1, color: tc, font: { size: 10 } }, grid: { color: gc } },
                x: { stacked: true, ticks: { color: tc, font: { size: 9 }, maxRotation: 45, callback: function (v, i) { return i % 3 === 0 ? this.getLabelForValue(v) : ''; } }, grid: { display: false } },
            }
        }
    });

    // --- ClickHouse pie: top clients by catalog disk usage ---
    <?php if ($isAdmin && !empty($chTopRepos)): ?>
    (function () {
        const el = document.getElementById('catalogPieChart');
        if (!el) return;
        const colors = ['#36a2eb','#ff6384','#ffce56','#4bc0c0','#9966ff','#6c757d'];
        const repos = <?= json_encode($chTopRepos) ?>;
        const diskTotal = <?= $chDiskBytes ?>;
        const top5Disk = repos.reduce((s, r) => s + Number(r.disk_bytes), 0);
        const otherDisk = Math.max(diskTotal - top5Disk, 0);
        const labels = repos.map(r => r.name);
        const data = repos.map(r => Number(r.disk_bytes));
        const rowCounts = repos.map(r => Number(r.rows));
        if (otherDisk > 0) { labels.push('Other'); data.push(otherDisk); rowCounts.push(null); }
        const fmtN = n => (n == null ? null : Number(n).toLocaleString());
        const fmtB = b => { b = Number(b); const s = '\u00A0'; if (b >= 1099511627776) return (b/1099511627776).toFixed(1)+s+'TB'; if (b >= 1073741824) return (b/1073741824).toFixed(1)+s+'GB'; if (b >= 1048576) return (b/1048576).toFixed(1)+s+'MB'; return (b/1024).toFixed(0)+s+'KB'; };
        new Chart(el.getContext('2d'), {
            type: 'doughnut',
            data: {
                labels: labels,
                datasets: [{
                    data: data,
                    backgroundColor: colors.slice(0, data.length),
                    borderWidth: 0,
                }]
            },
            options: {
                responsive: false,
                cutout: '55%',
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        enabled: false,
                        external: externalTooltip,
                        callbacks: {
                            // Name is already shown as the bold title; body shows
                            // size and row count.
                            label: ctx => {
                                const lines = ['Size: ' + fmtB(ctx.raw)];
                                const rc = rowCounts[ctx.dataIndex];
                                if (rc != null) lines.push('Rows: ' + fmtN(rc));
                                return lines;
                            }
                        }
                    }
                }
            }
        });
        // Legend is rendered server-side as a list, no JS needed.
    })();
    <?php endif; ?>

    // --- Server Health: live refresh every 15s ---------------------------
    <?php if ($isAdmin): ?>
    (function () {
        const cpuFill = document.getElementById('cpu-fill');
        const cpuVal = document.getElementById('cpu-val');
        const memFill = document.getElementById('mem-fill');
        const memVal = document.getElementById('mem-val');
        const partsEl = document.getElementById('health-partitions');
        if (!cpuFill && !memFill && !partsEl) return;

        function color(pct, high, mid, low) {
            return pct > high ? '#dc3545' : (pct > mid ? '#ffc107' : low);
        }

        async function poll() {
            try {
                const resp = await fetch('/dashboard/health-json', { credentials: 'same-origin' });
                if (!resp.ok) return;
                const d = await resp.json();
                function paintBar(fillEl, pct, bg) {
                    if (!fillEl) return;
                    fillEl.style.width = pct + '%';
                    fillEl.style.backgroundColor = bg;
                    const parent = fillEl.parentElement;
                    if (parent) {
                        parent.setAttribute('aria-valuenow', pct);
                        const label = parent.querySelector('.progress-label');
                        if (label) label.textContent = pct + '%';
                    }
                }
                if (d.cpu && cpuFill && cpuVal) {
                    const p = Number(d.cpu.percent) || 0;
                    paintBar(cpuFill, p, color(p, 80, 50, '#198754'));
                    cpuVal.textContent = d.cpu['1min'];
                }
                if (d.memory && memFill && memVal) {
                    const p = Number(d.memory.percent) || 0;
                    paintBar(memFill, p, color(p, 85, 60, '#0dcaf0'));
                    memVal.textContent = d.memory.used_label;
                }
                if (d.partitions && partsEl) {
                    d.partitions.forEach(p => {
                        const row = partsEl.querySelector('[data-mount="' + CSS.escape(p.mount) + '"]');
                        if (!row) return;
                        const fill = row.querySelector('.part-fill');
                        const val = row.querySelector('.part-val');
                        const pct = Number(p.percent) || 0;
                        paintBar(fill, pct, color(pct, 90, 70, '#6c757d'));
                        if (val) val.textContent = p.size_label;
                    });
                }
            } catch (e) { /* silent */ }
        }

        setInterval(poll, 15000);
    })();
    <?php endif; ?>

    // --- Active & Queued + top-tile counts: 10s poll ---------------------
    // The Active/Queued table and the four hero tiles change in real time
    // as jobs queue, run, and complete. Was previously a snapshot at page
    // render and never updated. Polls a small JSON endpoint every 10s.
    (function () {
        const tileAgentCount  = document.getElementById('tile-agent-count');
        const tileOnlineCount = document.getElementById('tile-online-count');
        const tileOfflineCount = document.getElementById('tile-offline-count');
        const tileRunning     = document.getElementById('tile-running-count');
        const tileQueued      = document.getElementById('tile-queued-count');
        const tileErrorCount  = document.getElementById('tile-error-count');
        const tileErrorsLink  = document.getElementById('tile-errors-link');
        const activeContainer = document.getElementById('active-jobs');
        if (!activeContainer) return;

        function escHtml(s) { const d = document.createElement('div'); d.textContent = s ?? ''; return d.innerHTML; }
        function ucfirst(s) { return (s || '').charAt(0).toUpperCase() + (s || '').slice(1); }

        function renderActive(jobs) {
            if (!jobs || !jobs.length) {
                activeContainer.innerHTML = ''
                    + '<div class="p-5 text-muted text-center">'
                    + '<i class="bi bi-hourglass d-block mb-2" style="font-size:1.8rem;opacity:0.4;"></i>'
                    + '<div>No Active Jobs</div></div>';
                return;
            }
            let html = '<div class="table-responsive"><table class="table table-hover mb-0 small">'
                + '<thead><tr><th>Client</th><th>Task</th><th class="d-th-md">Repo</th><th>Status</th></tr></thead><tbody>';
            jobs.forEach(j => {
                const badgeClass = j.status === 'queued' ? 'text-bg-warning' : 'text-bg-primary';
                let statusHtml;
                if (j.percent !== null && j.percent !== undefined && j.status === 'running') {
                    statusHtml = '<div class="progress" style="height:18px;min-width:60px;">'
                        + '<div class="progress-bar progress-bar-striped progress-bar-animated bg-primary" style="width:'
                        + j.percent + '%">' + j.percent + '%</div></div>';
                } else {
                    statusHtml = '<span class="badge ' + badgeClass + '">' + escHtml(ucfirst(j.status)) + '</span>';
                }
                html += '<tr style="cursor:pointer" onclick="window.location=\'/queue/' + j.id + '\'">'
                    + '<td>' + escHtml(j.agent_name) + '</td>'
                    + '<td>' + escHtml(ucfirst(j.task_type)) + '</td>'
                    + '<td class="d-table-cell-md">' + escHtml(j.repo_name || '--') + '</td>'
                    + '<td>' + statusHtml + '</td></tr>';
            });
            html += '</tbody></table></div>';
            activeContainer.innerHTML = html;
        }

        async function pollActive() {
            try {
                const resp = await fetch('/dashboard/active-json', { credentials: 'same-origin' });
                if (!resp.ok) return;
                const d = await resp.json();
                if (tileAgentCount)  tileAgentCount.textContent  = d.agentCount;
                if (tileOnlineCount) tileOnlineCount.textContent = d.onlineCount;
                if (tileOfflineCount) tileOfflineCount.textContent = Math.max(0, d.agentCount - d.onlineCount);
                if (tileRunning)     tileRunning.textContent     = d.runningJobs;
                if (tileQueued)      tileQueued.textContent      = d.queuedJobs;
                if (tileErrorCount)  tileErrorCount.textContent  = d.errorCount;
                // Repaint the errors tile (card bg + icon wrap colour + icon
                // glyph) when the count crosses zero — the server picks the
                // initial classes; the JS keeps them in sync after that.
                {
                    const isErr = d.errorCount > 0;
                    const card = document.getElementById('tile-errors-card');
                    const iconWrap = document.getElementById('tile-errors-icon-wrap');
                    const icon = document.getElementById('tile-errors-icon');
                    if (card) {
                        card.classList.toggle('metric-card-danger', isErr);
                        card.classList.toggle('metric-card-success', !isErr);
                    }
                    if (iconWrap) {
                        iconWrap.classList.toggle('bg-danger', isErr);
                        iconWrap.classList.toggle('text-danger', isErr);
                        iconWrap.classList.toggle('bg-success', !isErr);
                        iconWrap.classList.toggle('text-success', !isErr);
                    }
                    if (icon) {
                        icon.classList.toggle('bi-exclamation-circle', isErr);
                        icon.classList.toggle('bi-check-circle', !isErr);
                    }
                }
                renderActive(d.activeJobs || []);
            } catch (e) { /* silent */ }
        }

        setInterval(pollActive, 10000);
    })();

    // --- Recently Completed: task-type filter ----------------------------
    (function () {
        const STORAGE_KEY = 'bbs_recent_jobs_filter';
        const ALL = ['backup','restore','prune','compact','s3','other'];
        const checkboxes = document.querySelectorAll('.recent-filter-cb');
        const btn = document.getElementById('recentFilterBtn');
        const badge = document.getElementById('recentFilterCount');
        const btnAll = document.getElementById('recentFilterAll');
        const btnNone = document.getElementById('recentFilterNone');
        const container = document.getElementById('recent-jobs');
        if (!checkboxes.length || !container) return;

        // Load saved selection (defaults to all checked)
        const saved = JSON.parse(localStorage.getItem(STORAGE_KEY) || 'null');
        const active = saved && Array.isArray(saved) ? new Set(saved) : new Set(ALL);
        checkboxes.forEach(cb => { cb.checked = active.has(cb.dataset.cat); });
        updateBadge();

        function updateBadge() {
            const count = ALL.length - active.size;
            if (count === 0 || active.size === 0) {
                badge.style.display = 'none';
            } else {
                badge.textContent = active.size;
                badge.style.display = '';
            }
        }

        function save() {
            localStorage.setItem(STORAGE_KEY, JSON.stringify([...active]));
        }

        function esc(s) { const d = document.createElement('div'); d.textContent = s ?? ''; return d.innerHTML; }
        function timeAgo(str) {
            if (!str) return '--';
            const diff = Math.floor((Date.now() - new Date((str).replace(' ','T')+'Z').getTime()) / 1000);
            if (diff < 60) return diff + 's ago';
            if (diff < 3600) return Math.floor(diff/60) + 'm ago';
            if (diff < 86400) return Math.floor(diff/3600) + 'h ago';
            return Math.floor(diff/86400) + 'd ago';
        }
        // Task-type icon map (mirrors the PHP one above and queue/index.php)
        const TASK_ICONS = {
            'backup':          'bi-box-seam text-warning',
            'prune':           'bi-scissors text-secondary',
            'compact':         'bi-arrows-collapse text-info',
            'restore':         'bi-cloud-download text-primary',
            'restore_mysql':   'bi-database text-primary',
            'restore_pg':      'bi-database text-primary',
            'restore_mongo':   'bi-database text-primary',
            'check':           'bi-shield-check text-success',
            'repo_check':      'bi-shield-check text-success',
            'repo_repair':     'bi-tools text-warning',
            'break_lock':      'bi-unlock text-secondary',
            'update_borg':     'bi-arrow-up-square text-info',
            'update_agent':    'bi-arrow-up-square text-info',
            'plugin_test':     'bi-pencil text-secondary',
            's3_sync':         'bi-cloud-upload text-info',
            's3_restore':      'bi-cloud-download text-info',
            'catalog_sync':    'bi-list-ul text-success',
            'catalog_rebuild': 'bi-list-ul text-success',
        };

        function renderRows(jobs) {
            if (!jobs || !jobs.length) {
                container.innerHTML = '<div class="p-5 text-muted text-center"><i class="bi bi-filter d-block mb-2" style="font-size:1.8rem;opacity:0.4;"></i><div>No Jobs Match This Filter</div></div>';
                return;
            }
            let html = '<div class="table-responsive"><table class="table table-hover mb-0 small recent-jobs-table"><thead><tr><th>Client</th><th>Task</th><th class="d-th-md">Plan</th><th class="d-th-md">Repo</th><th>Completed</th><th class="d-th-md">Duration</th><th class="text-center">Status</th></tr></thead><tbody>';
            jobs.forEach(j => {
                const icon = j.status === 'completed' ? 'bi-check-circle-fill text-success'
                    : j.status === 'failed' ? 'bi-x-circle-fill text-danger'
                    : 'bi-slash-circle-fill text-secondary';
                const taskIcon = TASK_ICONS[j.task_type] || 'bi-gear text-muted';
                const taskLabel = (j.task_type||'').charAt(0).toUpperCase() + (j.task_type||'').slice(1);
                html += '<tr style="cursor:pointer" onclick="window.location=\'/queue/'+j.id+'\'">'
                    + '<td>' + esc(j.agent_name) + '</td>'
                    + '<td class="text-nowrap"><i class="bi ' + taskIcon + ' me-1"></i>' + esc(taskLabel) + '</td>'
                    + '<td class="d-table-cell-md">' + esc(j.plan_name || '--') + '</td>'
                    + '<td class="d-table-cell-md">' + esc(j.repo_name || '--') + '</td>'
                    + '<td>' + timeAgo(j.completed_at) + '</td>'
                    + '<td class="d-table-cell-md">' + window.BBS.formatDuration(j.duration_seconds) + '</td>'
                    + '<td class="text-center"><i class="bi ' + icon + '"></i></td>'
                    + '</tr>';
            });
            html += '</tbody></table></div>';
            container.innerHTML = html;
        }

        async function refresh() {
            const types = [...active].join(',');
            const url = '/dashboard/json' + (types && active.size < ALL.length ? '?types=' + encodeURIComponent(types) : '');
            try {
                const resp = await fetch(url, { credentials: 'same-origin' });
                if (!resp.ok) return;
                const data = await resp.json();
                renderRows(data.recentJobs || []);
            } catch (e) { /* silent */ }
        }

        checkboxes.forEach(cb => cb.addEventListener('change', () => {
            if (cb.checked) active.add(cb.dataset.cat);
            else active.delete(cb.dataset.cat);
            save();
            updateBadge();
            refresh();
        }));
        btnAll.addEventListener('click', () => {
            ALL.forEach(c => active.add(c));
            checkboxes.forEach(cb => cb.checked = true);
            save(); updateBadge(); refresh();
        });
        btnNone.addEventListener('click', () => {
            active.clear();
            checkboxes.forEach(cb => cb.checked = false);
            save(); updateBadge(); refresh();
        });

        // Initial fetch if saved filter differs from the server-rendered default
        if (saved && active.size < ALL.length) refresh();
    })();
});
</script>
