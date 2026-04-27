<?php
    $avgDur = '--';
    if ($avgSec > 0) {
        if ($avgSec < 60) $avgDur = $avgSec . 's';
        elseif ($avgSec < 3600) $avgDur = round($avgSec / 60) . 'm';
        else $avgDur = round($avgSec / 3600, 1) . 'h';
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
            's3_sync' => '<i class="bi bi-cloud-upload text-info me-1"></i>',
            's3_restore' => '<i class="bi bi-cloud-download text-info me-1"></i>',
            'catalog_sync' => '<i class="bi bi-list-ul text-success me-1"></i>',
            default => '<i class="bi bi-gear text-muted me-1"></i>',
        };
    }
?>
<div class="row g-3 mb-4">
    <div class="col-xl-3 col-md-6">
        <div class="card border-0 shadow-sm h-100 metric-card-blue">
            <div class="card-body d-flex align-items-center">
                <div class="stat-icon bg-primary bg-opacity-10 text-primary rounded-3 p-3 me-3">
                    <i class="bi bi-hourglass-split fs-3"></i>
                </div>
                <div>
                    <div class="text-muted small">In Queue</div>
                    <div class="fs-4 fw-bold"><?= $queuedCount ?></div>
                    <div class="text-muted small"><?= $runningCount ?> running</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-xl-3 col-md-6">
        <div class="card border-0 shadow-sm h-100 metric-card-success">
            <div class="card-body d-flex align-items-center">
                <div class="stat-icon bg-success bg-opacity-10 text-success rounded-3 p-3 me-3">
                    <i class="bi bi-check-circle fs-3"></i>
                </div>
                <div>
                    <div class="text-muted small">Completed (24h)</div>
                    <div class="fs-4 fw-bold"><?= $completed24h ?></div>
                    <div class="text-muted small">avg: <?= $avgDur ?></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-xl-3 col-md-6">
        <?php $failBs = $failed24h > 0 ? 'danger' : 'success'; ?>
        <div class="card border-0 shadow-sm h-100 metric-card-<?= $failBs ?>">
            <div class="card-body d-flex align-items-center">
                <div class="stat-icon bg-<?= $failBs ?> bg-opacity-10 text-<?= $failBs ?> rounded-3 p-3 me-3">
                    <i class="bi bi-<?= $failed24h > 0 ? 'x-circle' : 'check-circle' ?> fs-3"></i>
                </div>
                <div>
                    <div class="text-muted small">Failed (24h)</div>
                    <div class="fs-4 fw-bold"><?= $failed24h ?></div>
                    <div class="text-muted small"><?= $failed24h > 0 ? 'check logs' : 'no failures' ?></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-xl-3 col-md-6">
        <div class="card border-0 shadow-sm h-100 metric-card-cyan">
            <div class="card-body d-flex align-items-center">
                <div class="stat-icon bg-info bg-opacity-10 text-info rounded-3 p-3 me-3">
                    <i class="bi bi-speedometer2 fs-3"></i>
                </div>
                <div>
                    <div class="text-muted small">Avg Duration</div>
                    <div class="fs-4 fw-bold"><?= $avgDur ?></div>
                    <div class="text-muted small">last 24 hours</div>
                </div>
            </div>
        </div>
    </div>
</div>

<h6 class="mb-3">In Progress <span class="text-muted fw-normal small">(Max: <?= $maxQueue ?> Concurrent)</span></h6>
<div class="card border-0 shadow-sm mb-4">
    <div class="card-body p-0" id="queue-in-progress">
        <?php if (empty($inProgress)): ?>
        <div class="p-4 text-muted text-center">No jobs in progress.</div>
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
                        <td class="text-nowrap"><?= jobTypeIcon($job['task_type']) ?><?= $job['task_type'] ?></td>
                        <td class="d-none d-md-table-cell"><?= number_format($job['files_total'] ?? 0) ?></td>
                        <td>
                            <?php if ($job['status'] === 'queued'): ?>
                                <span class="text-muted">Waiting</span>
                            <?php elseif (($job['files_total'] ?? 0) > 0): ?>
                                <?php $pct = round(($job['files_processed'] / $job['files_total']) * 100); ?>
                                <div class="progress" style="height: 18px; min-width: 80px;">
                                    <div class="progress-bar progress-bar-striped progress-bar-animated bg-success" style="width: <?= $pct ?>%">
                                        <?= $pct ?>%
                                    </div>
                                </div>
                            <?php elseif (!empty($job['status_message'])): ?>
                                <span class="text-info small"><?= htmlspecialchars($job['status_message']) ?></span>
                            <?php else: ?>
                                <div class="progress" style="height: 18px; min-width: 80px;">
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

<h6 class="mb-3">Recently Completed</h6>
<div class="card border-0 shadow-sm">
    <div class="card-body p-0" id="queue-completed">
        <?php if (empty($completed)): ?>
        <div class="p-4 text-muted text-center">No completed jobs yet.</div>
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
                        <td class="text-nowrap"><?= jobTypeIcon($job['task_type']) ?><?= $job['task_type'] ?></td>
                        <td class="d-none d-md-table-cell"><?= number_format($job['files_total'] ?? 0) ?></td>
                        <td class="d-none d-md-table-cell"><?= htmlspecialchars($job['repo_name'] ?? '--') ?></td>
                        <td class="d-none d-md-table-cell">
                            <?php
                            $d = $job['duration_seconds'] ?? 0;
                            echo $d >= 60 ? floor($d / 60) . 'm ' . ($d % 60) . 's' : $d . 's';
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

    function formatDuration(s) {
        s = parseInt(s) || 0;
        return s >= 60 ? Math.floor(s / 60) + 'm ' + (s % 60) + 's' : s + 's';
    }

    function statusBadge(status) {
        const colors = { running: 'primary', sent: 'primary', queued: 'warning', completed: 'success', failed: 'danger' };
        return '<span class="badge text-bg-' + (colors[status] || 'secondary') + '">' + status + '</span>';
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
            's3_sync': '<i class="bi bi-cloud-upload text-info me-1"></i>',
            's3_restore': '<i class="bi bi-cloud-download text-info me-1"></i>',
            'catalog_sync': '<i class="bi bi-list-ul text-success me-1"></i>'
        };
        return icons[type] || '<i class="bi bi-gear text-muted me-1"></i>';
    }

    function buildInProgressRow(job) {
        let progress;
        if (job.status === 'queued') {
            progress = '<span class="text-muted">Waiting</span>';
        } else if ((job.files_total || 0) > 0) {
            const pct = Math.round((job.files_processed / job.files_total) * 100);
            progress = '<div class="progress" style="height:18px;min-width:80px;">' +
                '<div class="progress-bar progress-bar-striped progress-bar-animated bg-success" style="width:' + pct + '%">' + pct + '%</div></div>';
        } else if (job.status_message) {
            progress = '<span class="text-info small">' + esc(job.status_message) + '</span>';
        } else {
            progress = '<div class="progress" style="height:18px;min-width:80px;">' +
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
            '<td class="text-nowrap">' + jobTypeIcon(job.task_type) + esc(job.task_type) + '</td>' +
            '<td class="d-none d-md-table-cell">' + Number(job.files_total || 0).toLocaleString() + '</td>' +
            '<td>' + progress + '</td>' +
            '<td class="d-none d-md-table-cell">' + esc(job.repo_name || '--') + '</td>' +
            '<td>' + statusBadge(job.status) + '</td>' +
            '<td class="text-end" onclick="event.stopPropagation()">' + actions + '</td></tr>';
    }

    function buildCompletedRow(job) {
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
            '<td class="text-nowrap">' + jobTypeIcon(job.task_type) + esc(job.task_type) + '</td>' +
            '<td class="d-none d-md-table-cell">' + Number(job.files_total || 0).toLocaleString() + '</td>' +
            '<td class="d-none d-md-table-cell">' + esc(job.repo_name || '--') + '</td>' +
            '<td class="d-none d-md-table-cell">' + formatDuration(job.duration_seconds) + '</td>' +
            '<td class="text-center">' + statusHtml + '</td>' +
            '<td class="text-end" onclick="event.stopPropagation()">' + actions + '</td></tr>';
    }

    function refreshQueue() {
        fetch('/queue/json', { credentials: 'same-origin' })
            .then(r => r.json())
            .then(data => {
                // Update In Progress section
                const ipCard = document.getElementById('queue-in-progress');
                if (data.inProgress.length === 0) {
                    ipCard.innerHTML = '<div class="p-4 text-muted text-center">No jobs in progress.</div>';
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
                    cCard.innerHTML = '<div class="p-4 text-muted text-center">No completed jobs yet.</div>';
                } else {
                    let html = '<div class="table-responsive"><table class="table table-hover align-middle mb-0"><thead class="table-light"><tr>' +
                        '<th>Date</th><th>Client</th><th>Task</th><th class="d-none d-md-table-cell">Files</th><th class="d-none d-md-table-cell">Repo</th><th class="d-none d-md-table-cell">Duration</th><th class="text-center">Status</th><th style="width:80px;"></th>' +
                        '</tr></thead><tbody>';
                    data.completed.forEach(j => html += buildCompletedRow(j));
                    html += '</tbody></table></div>';
                    cCard.innerHTML = html;
                }

                // Re-init tooltips
                document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(el => new bootstrap.Tooltip(el));
            })
            .catch(() => {});
    }

    setInterval(refreshQueue, 10000);
})();
</script>
