<?php
    $avgDur = $avgSec > 0 ? \BBS\Core\TimeHelper::duration((int) $avgSec) : '--';
    $maxCompletedDuration = max(array_map(fn($j) => (int) ($j['duration_seconds'] ?? 0), $completed ?: [['duration_seconds' => 0]]));

    function durationBarHtml(int $duration, int $maxDuration, string $status = ''): string {
        $pct = $maxDuration > 0 ? min(100, round(($duration / $maxDuration) * 100)) : 0;
        $label = \BBS\Core\TimeHelper::duration($duration);
        $toneClass = $status === 'failed' ? ' queue-duration-danger' : '';
        return '<div class="progress queue-duration-progress' . $toneClass . ' position-relative" title="' . htmlspecialchars($label) . '" style="--duration-pct:' . $pct . '%;">'
            . '<div class="progress-bar queue-duration-bar" style="width:' . $pct . '%;"></div>'
            . '<span class="progress-label">' . htmlspecialchars($label) . '</span>'
            . '</div>';
    }

    // Job type icons mapping
    function jobTypeIcon(string $type): string {
        return match($type) {
            'backup' => '<i class="bi bi-box-seam text-warning me-1"></i>',
            'prune' => '<i class="bi bi-scissors text-secondary me-1"></i>',
            'compact' => '<i class="bi bi-arrows-collapse text-info me-1"></i>',
            'restore' => '<i class="bi bi-cloud-download text-primary me-1"></i>',
            'restore_mysql' => '<i class="bi bi-database text-primary me-1"></i>',
            'restore_pg' => '<i class="bi bi-database text-primary me-1"></i>',
            'check' => '<i class="bi bi-shield-check text-success me-1"></i>',
            'update_borg' => '<i class="bi bi-arrow-up-square text-info me-1"></i>',
            'update_agent' => '<i class="bi bi-arrow-up-square text-info me-1"></i>',
            'plugin_test' => '<i class="bi bi-pencil text-secondary me-1"></i>',
            'plugin_post' => '<i class="bi bi-terminal text-secondary me-1"></i>',
            's3_sync' => '<i class="bi bi-cloud-upload text-info me-1"></i>',
            's3_restore' => '<i class="bi bi-cloud-download text-info me-1"></i>',
            'catalog_sync' => '<i class="bi bi-list-ul text-success me-1"></i>',
            'archive_lock' => '<i class="bi bi-lock text-warning me-1"></i>',
            default => '<i class="bi bi-gear text-muted me-1"></i>',
        };
    }
?>
<style>
:root {
    --queue-laser: #0d6efd;
    --queue-laser-hot: #36a2eb;
    --queue-flow-trail: rgba(13, 110, 253, 0.12);
    --queue-row-accent: #2f73c9;
}
[data-bs-theme="dark"] {
    --queue-laser: #36a2ff;
    --queue-laser-hot: #79e7ff;
    --queue-flow-trail: rgba(54, 162, 255, 0.12);
    --queue-row-accent: #1e63ad;
}
.queue-shell { color-scheme: light dark; }
.queue-topline {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    flex-wrap: wrap;
}
.queue-pills {
    display: flex;
    gap: 4px;
    flex-wrap: wrap;
}
.queue-pill {
    padding: 5px 14px;
    border-radius: 999px;
    border: 1px solid var(--bs-border-color);
    background: var(--bs-body-bg);
    color: var(--bs-body-color);
    font-size: 0.85rem;
    font-weight: 500;
    text-decoration: none;
}
.queue-pill.active {
    background: linear-gradient(135deg, #243a6b, var(--queue-laser));
    color: #fff;
    border-color: rgba(54, 162, 235, 0.55);
    box-shadow: 0 0 18px rgba(54, 162, 255, 0.18);
}
.queue-table-card .card-header {
    min-height: 48px;
}
.queue-table-card .table {
    --bs-table-hover-bg: rgba(54, 162, 255, 0.06);
}
.queue-table-card tbody tr {
    border-left: 3px solid transparent;
}
.queue-table-card tbody tr:hover {
    border-left-color: var(--queue-laser-hot);
}
.queue-progress {
    background: var(--bs-tertiary-bg);
}
.queue-duration-progress {
    --queue-duration-laser: var(--queue-laser);
    --queue-duration-hot: var(--queue-laser-hot);
    --queue-duration-core: #eaf6ff;
    --queue-duration-fill-start: rgba(54, 162, 255, 0.12);
    --queue-duration-fill-glow: rgba(54, 162, 255, 0.38);
    height: 20px;
    min-width: 120px;
    overflow: hidden;
    border: 1px solid rgba(54, 162, 255, 0.28);
    background:
        linear-gradient(90deg, rgba(54, 162, 255, 0), var(--queue-flow-trail), rgba(54, 162, 255, 0.06)),
        repeating-linear-gradient(90deg, transparent 0 18px, rgba(255, 255, 255, 0.055) 18px 19px),
        var(--bs-tertiary-bg);
    box-shadow: inset 0 0 18px rgba(54, 162, 255, 0.08);
}
.queue-duration-progress.queue-duration-danger {
    --queue-duration-laser: #dc3545;
    --queue-duration-hot: #ff6b7a;
    --queue-duration-core: #fff0f2;
    --queue-duration-fill-start: rgba(220, 53, 69, 0.14);
    --queue-duration-fill-glow: rgba(220, 53, 69, 0.42);
    border-color: rgba(220, 53, 69, 0.36);
    background:
        linear-gradient(90deg, rgba(220, 53, 69, 0), rgba(220, 53, 69, 0.13), rgba(220, 53, 69, 0.06)),
        repeating-linear-gradient(90deg, transparent 0 18px, rgba(255, 255, 255, 0.055) 18px 19px),
        var(--bs-tertiary-bg);
    box-shadow: inset 0 0 18px rgba(220, 53, 69, 0.1);
}
.queue-duration-progress::after {
    content: "";
    position: absolute;
    top: 0;
    bottom: 0;
    left: var(--duration-pct, 0%);
    width: 2px;
    transform: translateX(-1px);
    pointer-events: none;
    background: linear-gradient(180deg, transparent, var(--queue-duration-core), var(--queue-duration-hot), transparent);
    box-shadow:
        0 0 8px var(--queue-duration-hot),
        0 0 22px var(--queue-duration-fill-glow),
        0 0 42px var(--queue-duration-fill-glow);
}
.queue-duration-bar {
    background:
        linear-gradient(90deg, var(--queue-duration-fill-start), var(--queue-duration-hot), var(--queue-duration-laser));
    box-shadow:
        inset 0 1px 0 rgba(255, 255, 255, 0.26),
        0 0 16px var(--queue-duration-fill-glow);
}
.queue-duration-progress .progress-label {
    position: absolute;
    inset: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #fff;
    font-size: 0.66rem;
    font-weight: 600;
    font-variant-numeric: tabular-nums;
    text-shadow: 0 2px 3px rgba(0,0,0,0.9), 0 2px 6px rgba(0,0,0,0.9);
    pointer-events: none;
}
.queue-empty {
    background:
        linear-gradient(180deg, rgba(54, 162, 255, 0.05), transparent),
        var(--bs-body-bg);
}
@media (max-width: 767.98px) {
    .queue-topline { align-items: stretch; }
    .queue-pills { width: 100%; }
    .queue-pill { flex: 1 1 auto; text-align: center; }
}
</style>

<div class="queue-shell container-fluid px-0">
<div class="queue-topline mb-3">
    <div class="text-muted small">Live queue refreshes every 10 seconds</div>
    <div class="queue-pills">
        <a class="queue-pill" href="#queue-in-progress"><i class="bi bi-activity me-1"></i>Active <span id="qm-pill-active"><?= count($inProgress) ?></span></a>
        <a class="queue-pill" href="#queue-completed"><i class="bi bi-check2-circle me-1"></i>Completed <span id="qm-pill-completed"><?= count($completed) ?></span></a>
        <a class="queue-pill" href="/schedules"><i class="bi bi-calendar-week me-1"></i>Schedules</a>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-xl-3 col-md-6">
        <div class="card border-0 shadow-sm h-100 metric-card-blue">
            <div class="card-body d-flex align-items-center">
                <div class="stat-icon bg-primary text-primary bg-opacity-10 rounded-3 p-3 me-3">
                    <i class="bi bi-hourglass-split fs-3"></i>
                </div>
                <div>
                    <div class="text-muted small">In Queue</div>
                    <div class="fs-4 fw-bold" id="qm-queued"><?= $queuedCount ?></div>
                    <div class="text-muted small"><span id="qm-running"><?= $runningCount ?></span> running</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-xl-3 col-md-6">
        <div class="card border-0 shadow-sm h-100 metric-card-success">
            <div class="card-body d-flex align-items-center">
                <div class="stat-icon bg-success text-success bg-opacity-10 rounded-3 p-3 me-3">
                    <i class="bi bi-check-circle fs-3"></i>
                </div>
                <div>
                    <div class="text-muted small">Completed (24h)</div>
                    <div class="fs-4 fw-bold" id="qm-completed24h"><?= $completed24h ?></div>
                    <div class="text-muted small">avg: <span id="qm-avg"><?= $avgDur ?></span></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-xl-3 col-md-6">
        <?php $failBs = $failed24h > 0 ? 'danger' : 'success'; ?>
        <div class="card border-0 shadow-sm h-100 metric-card-<?= $failBs ?>" id="qm-failed-card">
            <div class="card-body d-flex align-items-center">
                <div class="stat-icon bg-<?= $failBs ?> text-<?= $failBs ?> bg-opacity-10 rounded-3 p-3 me-3" id="qm-failed-icon-wrap">
                    <i class="bi bi-<?= $failed24h > 0 ? 'x-circle' : 'check-circle' ?> fs-3" id="qm-failed-icon"></i>
                </div>
                <div>
                    <div class="text-muted small">Failed (24h)</div>
                    <div class="fs-4 fw-bold" id="qm-failed24h"><?= $failed24h ?></div>
                    <div class="text-muted small" id="qm-failed-label"><?= $failed24h > 0 ? 'check logs' : 'no failures' ?></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-xl-3 col-md-6">
        <div class="card border-0 shadow-sm h-100 metric-card-cyan">
            <div class="card-body d-flex align-items-center">
                <div class="stat-icon bg-info text-info bg-opacity-10 rounded-3 p-3 me-3">
                    <i class="bi bi-speedometer2 fs-3"></i>
                </div>
                <div>
                    <div class="text-muted small">Avg Duration</div>
                    <div class="fs-4 fw-bold" id="qm-avg-dur"><?= $avgDur ?></div>
                    <div class="text-muted small">last 24 hours</div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="card border-0 shadow-sm mb-4 queue-table-card">
    <div class="card-header card-head-gradient fw-semibold d-flex justify-content-between align-items-center">
        <span><i class="bi bi-activity me-2"></i>In Progress</span>
        <span class="text-muted small"><span id="qm-running-slots"><?= $runningCount ?></span>/<span id="qm-max-queue"><?= $maxQueue ?></span> slots used</span>
    </div>
    <div class="card-body p-0" id="queue-in-progress">
        <?php if (empty($inProgress)): ?>
        <div class="p-5 text-muted text-center queue-empty">
            <i class="bi bi-hourglass-bottom fs-1 d-block mb-2"></i>
            No jobs in progress.
        </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Date</th>
                        <th>Client</th>
                        <th>Task</th>
                        <th class="d-none d-md-table-cell">Files</th>
                        <th>Progress</th>
                        <th class="d-none d-md-table-cell">Repo</th>
                        <th>Status</th>
                        <th style="width: 80px;"></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($inProgress as $job): ?>
                    <tr style="cursor: pointer;" onclick="window.location='/queue/<?= $job['id'] ?>'">
                        <td class="small text-nowrap"><?= \BBS\Core\TimeHelper::format($job['queued_at'], 'M j, g:i A') ?></td>
                        <td><?= htmlspecialchars($job['agent_name']) ?></td>
                        <td class="text-nowrap"><?= jobTypeIcon($job['task_type']) ?><?= htmlspecialchars(ucfirst(str_replace('_', ' ', $job['task_type']))) ?></td>
                        <td class="d-none d-md-table-cell"><?= in_array($job['task_type'], ['repo_check', 'repo_repair'], true) ? '--' : number_format($job['files_total'] ?? 0) ?></td>
                        <td>
                            <?php
                            // Percent from file counts when the total is known
                            // (backups pre-count; restores get it from the
                            // catalog at queue time), else from byte totals
                            // (borg extract reports byte offsets). Clamped —
                            // extract's item counter includes directories, so
                            // it can slightly overshoot a file-only total.
                            $pct = null;
                            if (($job['files_total'] ?? 0) > 0) {
                                $pct = min(100, round(($job['files_processed'] / $job['files_total']) * 100));
                            } elseif (($job['bytes_total'] ?? 0) > 0 && ($job['bytes_processed'] ?? 0) > 0) {
                                $pct = min(100, round(($job['bytes_processed'] / $job['bytes_total']) * 100));
                            }
                            ?>
                            <?php if ($job['status'] === 'queued'): ?>
                                <span class="text-muted">Waiting</span>
                            <?php elseif ($pct !== null): ?>
                                <div class="progress queue-progress" style="height: 18px; min-width: 80px;">
                                    <div class="progress-bar progress-bar-striped progress-bar-animated bg-success" style="width: <?= $pct ?>%">
                                        <?= $pct ?>%
                                    </div>
                                </div>
                            <?php elseif (!empty($job['status_message'])): ?>
                                <span class="text-info small"><?= htmlspecialchars($job['status_message']) ?></span>
                            <?php else: ?>
                                <div class="progress queue-progress" style="height: 18px; min-width: 80px;">
                                    <div class="progress-bar progress-bar-striped progress-bar-animated bg-info" style="width: 100%">
                                        Preparing...
                                    </div>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td class="d-none d-md-table-cell"><?= htmlspecialchars($job['repo_name'] ?? '--') ?></td>
                        <td>
                            <?php
                            $sc = match($job['status']) {
                                'running' => 'primary',
                                'sent' => 'primary',
                                default => 'warning',
                            };
                            ?>
                            <span class="badge text-bg-<?= $sc ?>"><?= $job['status'] ?></span>
                        </td>
                        <td class="text-end" onclick="event.stopPropagation()">
                            <a href="/queue/<?= $job['id'] ?>" class="btn btn-sm btn-outline-secondary" title="View Details"><i class="bi bi-eye"></i></a>
                            <?php if (in_array($job['status'], ['queued', 'sent', 'running'])): ?>
                            <form method="POST" action="/queue/<?= $job['id'] ?>/cancel" class="d-inline"
                                  data-confirm="Cancel this job?">
                                <input type="hidden" name="csrf_token" value="<?= $this->csrfToken() ?>">
                                <button class="btn btn-sm btn-outline-danger" title="Cancel">
                                    <i class="bi bi-x-circle"></i>
                                </button>
                            </form>
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

<div class="card border-0 shadow-sm queue-table-card">
    <div class="card-header card-head-gradient fw-semibold d-flex justify-content-between align-items-center">
        <span><i class="bi bi-clock-history me-2"></i>Recently Completed</span>
        <span class="text-muted small">Last 24 hours: <span id="qm-completed24h-hdr"><?= $completed24h ?></span> completed, <span id="qm-failed24h-hdr"><?= $failed24h ?></span> failed</span>
    </div>
    <div class="card-body p-0" id="queue-completed">
        <?php if (empty($completed)): ?>
        <div class="p-5 text-muted text-center queue-empty">
            <i class="bi bi-check2-circle fs-1 d-block mb-2"></i>
            No completed jobs yet.
        </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Date</th>
                        <th>Client</th>
                        <th>Task</th>
                        <th class="d-none d-md-table-cell">Files</th>
                        <th class="d-none d-md-table-cell">Repo</th>
                        <th class="d-none d-md-table-cell">Duration</th>
                        <th class="text-center">Status</th>
                        <th style="width: 80px;"></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($completed as $job): ?>
                    <tr style="cursor: pointer;" onclick="window.location='/queue/<?= $job['id'] ?>'">
                        <td class="small text-nowrap" title="<?= \BBS\Core\TimeHelper::format($job['completed_at'], 'M j, Y g:i A') ?>"><?= \BBS\Core\TimeHelper::ago($job['completed_at']) ?></td>
                        <td><?= htmlspecialchars($job['agent_name']) ?></td>
                        <td class="text-nowrap"><?= jobTypeIcon($job['task_type']) ?><?= htmlspecialchars(ucfirst(str_replace('_', ' ', $job['task_type']))) ?></td>
                        <td class="d-none d-md-table-cell"><?= in_array($job['task_type'], ['repo_check', 'repo_repair'], true) ? '--' : number_format($job['files_total'] ?? 0) ?></td>
                        <td class="d-none d-md-table-cell"><?= htmlspecialchars($job['repo_name'] ?? '--') ?></td>
                        <td class="d-none d-md-table-cell">
                            <?php
                            $d = $job['duration_seconds'] ?? 0;
                            echo durationBarHtml((int) $d, $maxCompletedDuration, $job['status'] ?? '');
                            ?>
                        </td>
                        <td class="text-center">
                            <?php if ($job['status'] === 'completed' && !empty($job['had_warnings'])): ?>
                                <i class="bi bi-exclamation-triangle-fill text-warning" data-bs-toggle="tooltip" title="<?= htmlspecialchars('Completed with warnings: ' . substr($job['error_log'] ?? '', 0, 200)) ?>"></i>
                            <?php elseif ($job['status'] === 'completed'): ?>
                                <i class="bi bi-check-circle-fill text-success"></i>
                            <?php else: ?>
                                <i class="bi bi-x-circle-fill text-danger"></i>
                                <?php if (!empty($job['error_log'])): ?>
                                    <i class="bi bi-info-circle text-danger ms-1" data-bs-toggle="tooltip" title="<?= htmlspecialchars(substr($job['error_log'], 0, 200)) ?>"></i>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                        <td class="text-end" onclick="event.stopPropagation()">
                            <a href="/queue/<?= $job['id'] ?>" class="btn btn-sm btn-outline-secondary" title="View Details"><i class="bi bi-eye"></i></a>
                            <?php if ($job['status'] === 'failed'): ?>
                            <form method="POST" action="/queue/<?= $job['id'] ?>/retry" class="d-inline"
                                  data-confirm="Retry this job?">
                                <input type="hidden" name="csrf_token" value="<?= $this->csrfToken() ?>">
                                <button class="btn btn-sm btn-outline-warning" title="Retry">
                                    <i class="bi bi-arrow-repeat"></i>
                                </button>
                            </form>
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

<script>
// Enable tooltips for error messages
document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(el => new bootstrap.Tooltip(el));

// AJAX auto-refresh
(function() {
    const csrfToken = '<?= $this->csrfToken() ?>';

    function esc(s) { return s ? String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;') : ''; }

    function formatDate(d) {
        if (!d) return '';
        const dt = new Date(d.replace(' ', 'T') + 'Z');
        const tz = window.BBS_TIMEZONE || 'UTC';
        const tOpts = window.BBS_TIME_24H ? { hour: '2-digit', minute: '2-digit', hour12: false, timeZone: tz } : { hour: 'numeric', minute: '2-digit', timeZone: tz };
        return dt.toLocaleDateString('en-US', { month: 'short', day: 'numeric', timeZone: tz }) +
               ', ' + dt.toLocaleTimeString('en-US', tOpts);
    }

    function durationBar(s, maxS, status) {
        s = parseInt(s) || 0;
        maxS = parseInt(maxS) || 0;
        const pct = maxS > 0 ? Math.min(100, Math.round((s / maxS) * 100)) : 0;
        const label = window.BBS.formatDuration(s);
        const tone = status === 'failed' ? ' queue-duration-danger' : '';
        return '<div class="progress queue-duration-progress' + tone + ' position-relative" title="' + esc(label) + '" style="--duration-pct:' + pct + '%;">' +
            '<div class="progress-bar queue-duration-bar" style="width:' + pct + '%;"></div>' +
            '<span class="progress-label">' + esc(label) + '</span></div>';
    }

    function statusBadge(status) {
        const colors = { running: 'primary', sent: 'primary', queued: 'warning', completed: 'success', failed: 'danger' };
        return '<span class="badge text-bg-' + (colors[status] || 'secondary') + '">' + esc(status) + '</span>';
    }

    function taskTypeLabel(type) {
        const s = String(type || '').replace(/_/g, ' ');
        return s.charAt(0).toUpperCase() + s.slice(1);
    }

    function jobTypeIcon(type) {
        const icons = {
            'backup': '<i class="bi bi-box-seam text-warning me-1"></i>',
            'prune': '<i class="bi bi-scissors text-secondary me-1"></i>',
            'compact': '<i class="bi bi-arrows-collapse text-info me-1"></i>',
            'restore': '<i class="bi bi-cloud-download text-primary me-1"></i>',
            'restore_mysql': '<i class="bi bi-database text-primary me-1"></i>',
            'restore_pg': '<i class="bi bi-database text-primary me-1"></i>',
            'check': '<i class="bi bi-shield-check text-success me-1"></i>',
            'update_borg': '<i class="bi bi-arrow-up-square text-info me-1"></i>',
            'update_agent': '<i class="bi bi-arrow-up-square text-info me-1"></i>',
            'plugin_test': '<i class="bi bi-pencil text-secondary me-1"></i>',
            'plugin_post': '<i class="bi bi-terminal text-secondary me-1"></i>',
            's3_sync': '<i class="bi bi-cloud-upload text-info me-1"></i>',
            's3_restore': '<i class="bi bi-cloud-download text-info me-1"></i>',
            'catalog_sync': '<i class="bi bi-list-ul text-success me-1"></i>',
            'archive_lock': '<i class="bi bi-lock text-warning me-1"></i>'
        };
        return icons[type] || '<i class="bi bi-gear text-muted me-1"></i>';
    }

    function buildInProgressRow(job) {
        let progress;
        // Same fallback chain as the PHP render: file counts, then byte
        // totals (restores without a catalog count), clamped at 100.
        let pct = null;
        if ((job.files_total || 0) > 0) {
            pct = Math.min(100, Math.round((job.files_processed / job.files_total) * 100));
        } else if ((job.bytes_total || 0) > 0 && (job.bytes_processed || 0) > 0) {
            pct = Math.min(100, Math.round((job.bytes_processed / job.bytes_total) * 100));
        }
        if (job.status === 'queued') {
            progress = '<span class="text-muted">Waiting</span>';
        } else if (pct !== null) {
            progress = '<div class="progress queue-progress" style="height:18px;min-width:80px;">' +
                '<div class="progress-bar progress-bar-striped progress-bar-animated bg-success" style="width:' + pct + '%">' + pct + '%</div></div>';
        } else if (job.status_message) {
            progress = '<span class="text-info small">' + esc(job.status_message) + '</span>';
        } else {
            progress = '<div class="progress queue-progress" style="height:18px;min-width:80px;">' +
                '<div class="progress-bar progress-bar-striped progress-bar-animated bg-info" style="width:100%">Preparing...</div></div>';
        }

        let actions = '<a href="/queue/' + job.id + '" class="btn btn-sm btn-outline-secondary" title="View Details"><i class="bi bi-eye"></i></a>';
        if (job.status === 'queued' || job.status === 'sent' || job.status === 'running') {
            actions += ' <form method="POST" action="/queue/' + job.id + '/cancel" class="d-inline" data-confirm="Cancel this job?">' +
                '<input type="hidden" name="csrf_token" value="' + csrfToken + '">' +
                '<button class="btn btn-sm btn-outline-danger" title="Cancel"><i class="bi bi-x-circle"></i></button></form>';
        }

        return '<tr style="cursor:pointer;" onclick="window.location=\'/queue/' + job.id + '\'">' +
            '<td class="small text-nowrap">' + formatDate(job.queued_at) + '</td>' +
            '<td>' + esc(job.agent_name) + '</td>' +
            '<td class="text-nowrap">' + jobTypeIcon(job.task_type) + esc(taskTypeLabel(job.task_type)) + '</td>' +
            '<td class="d-none d-md-table-cell">' + (['repo_check','repo_repair'].includes(job.task_type) ? '--' : Number(job.files_total || 0).toLocaleString()) + '</td>' +
            '<td>' + progress + '</td>' +
            '<td class="d-none d-md-table-cell">' + esc(job.repo_name || '--') + '</td>' +
            '<td>' + statusBadge(job.status) + '</td>' +
            '<td class="text-end" onclick="event.stopPropagation()">' + actions + '</td></tr>';
    }

    function buildCompletedRow(job, maxDuration) {
        let statusHtml;
        if (job.status === 'completed' && job.had_warnings) {
            statusHtml = '<i class="bi bi-exclamation-triangle-fill text-warning" data-bs-toggle="tooltip" title="' + esc('Completed with warnings: ' + String(job.error_log || '').substring(0, 200)) + '"></i>';
        } else if (job.status === 'completed') {
            statusHtml = '<i class="bi bi-check-circle-fill text-success"></i>';
        } else {
            statusHtml = '<i class="bi bi-x-circle-fill text-danger"></i>';
            if (job.error_log) {
                statusHtml += ' <i class="bi bi-info-circle text-danger ms-1" data-bs-toggle="tooltip" title="' + esc(String(job.error_log).substring(0, 200)) + '"></i>';
            }
        }

        let actions = '<a href="/queue/' + job.id + '" class="btn btn-sm btn-outline-secondary" title="View Details"><i class="bi bi-eye"></i></a>';
        if (job.status === 'failed') {
            actions += ' <form method="POST" action="/queue/' + job.id + '/retry" class="d-inline" data-confirm="Retry this job?">' +
                '<input type="hidden" name="csrf_token" value="' + csrfToken + '">' +
                '<button class="btn btn-sm btn-outline-warning" title="Retry"><i class="bi bi-arrow-repeat"></i></button></form>';
        }

        return '<tr style="cursor:pointer;" onclick="window.location=\'/queue/' + job.id + '\'">' +
            '<td class="small text-nowrap">' + formatDate(job.completed_at) + '</td>' +
            '<td>' + esc(job.agent_name) + '</td>' +
            '<td class="text-nowrap">' + jobTypeIcon(job.task_type) + esc(taskTypeLabel(job.task_type)) + '</td>' +
            '<td class="d-none d-md-table-cell">' + (['repo_check','repo_repair'].includes(job.task_type) ? '--' : Number(job.files_total || 0).toLocaleString()) + '</td>' +
            '<td class="d-none d-md-table-cell">' + esc(job.repo_name || '--') + '</td>' +
            '<td class="d-none d-md-table-cell">' + durationBar(job.duration_seconds, maxDuration, job.status) + '</td>' +
            '<td class="text-center">' + statusHtml + '</td>' +
            '<td class="text-end" onclick="event.stopPropagation()">' + actions + '</td></tr>';
    }

    function setText(id, value) {
        const el = document.getElementById(id);
        if (el) el.textContent = value;
    }

    function refreshMetrics(data) {
        setText('qm-pill-active', data.inProgress.length);
        setText('qm-pill-completed', data.completed.length);
        if (typeof data.queuedCount !== 'undefined') setText('qm-queued', data.queuedCount);
        if (typeof data.runningCount !== 'undefined') {
            setText('qm-running', data.runningCount);
            setText('qm-running-slots', data.runningCount);
        }
        if (typeof data.maxQueue !== 'undefined') setText('qm-max-queue', data.maxQueue);
        if (typeof data.completed24h !== 'undefined') {
            setText('qm-completed24h', data.completed24h);
            setText('qm-completed24h-hdr', data.completed24h);
        }
        if (typeof data.failed24h !== 'undefined') {
            setText('qm-failed24h', data.failed24h);
            setText('qm-failed24h-hdr', data.failed24h);
            setText('qm-failed-label', data.failed24h > 0 ? 'check logs' : 'no failures');
            // Swap card accent / icon between danger/success
            const card = document.getElementById('qm-failed-card');
            const iconWrap = document.getElementById('qm-failed-icon-wrap');
            const icon = document.getElementById('qm-failed-icon');
            const isFail = data.failed24h > 0;
            if (card) {
                card.classList.toggle('metric-card-danger', isFail);
                card.classList.toggle('metric-card-success', !isFail);
            }
            if (iconWrap) {
                iconWrap.classList.toggle('bg-danger', isFail);
                iconWrap.classList.toggle('text-danger', isFail);
                iconWrap.classList.toggle('bg-success', !isFail);
                iconWrap.classList.toggle('text-success', !isFail);
            }
            if (icon) {
                icon.classList.toggle('bi-x-circle', isFail);
                icon.classList.toggle('bi-check-circle', !isFail);
            }
        }
        if (typeof data.avgSec !== 'undefined') {
            const avgLabel = data.avgSec > 0 ? window.BBS.formatDuration(data.avgSec) : '--';
            setText('qm-avg', avgLabel);
            setText('qm-avg-dur', avgLabel);
        }
    }

    function refreshQueue() {
        fetch('/queue/json', { credentials: 'same-origin' })
            .then(r => r.json())
            .then(data => {
                // Update In Progress section
                const ipCard = document.getElementById('queue-in-progress');
                if (data.inProgress.length === 0) {
                    ipCard.innerHTML = '<div class="p-5 text-muted text-center queue-empty"><i class="bi bi-hourglass-bottom fs-1 d-block mb-2"></i>No jobs in progress.</div>';
                } else {
                    let html = '<div class="table-responsive"><table class="table table-hover align-middle mb-0"><thead class="table-light"><tr>' +
                        '<th>Date</th><th>Client</th><th>Task</th><th class="d-none d-md-table-cell">Files</th><th>Progress</th><th class="d-none d-md-table-cell">Repo</th><th>Status</th><th style="width:80px;"></th>' +
                        '</tr></thead><tbody>';
                    data.inProgress.forEach(j => html += buildInProgressRow(j));
                    html += '</tbody></table></div>';
                    ipCard.innerHTML = html;
                }

                // Update Completed section
                const cCard = document.getElementById('queue-completed');
                if (data.completed.length === 0) {
                    cCard.innerHTML = '<div class="p-5 text-muted text-center queue-empty"><i class="bi bi-check2-circle fs-1 d-block mb-2"></i>No completed jobs yet.</div>';
                } else {
                    let html = '<div class="table-responsive"><table class="table table-hover align-middle mb-0"><thead class="table-light"><tr>' +
                        '<th>Date</th><th>Client</th><th>Task</th><th class="d-none d-md-table-cell">Files</th><th class="d-none d-md-table-cell">Repo</th><th class="d-none d-md-table-cell">Duration</th><th class="text-center">Status</th><th style="width:80px;"></th>' +
                        '</tr></thead><tbody>';
                    const maxDuration = Math.max(0, ...data.completed.map(j => parseInt(j.duration_seconds) || 0));
                    data.completed.forEach(j => html += buildCompletedRow(j, maxDuration));
                    html += '</tbody></table></div>';
                    cCard.innerHTML = html;
                }

                refreshMetrics(data);

                // Re-init tooltips
                document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(el => new bootstrap.Tooltip(el));
            })
            .catch(() => {});
    }

    setInterval(refreshQueue, 10000);
})();
</script>
