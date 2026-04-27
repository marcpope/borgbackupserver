<?php
$statusClass = match($job['status']) {
    'completed' => !empty($job['had_warnings']) ? 'warning' : 'success',
    'failed', 'cancelled' => 'danger',
    'running' => 'primary',
    'sent' => 'primary',
    default => 'warning',
};
$statusLabel = ($job['status'] === 'completed' && !empty($job['had_warnings']))
    ? 'Completed with warnings'
    : ucfirst($job['status']);

$d = $job['duration_seconds'] ?? 0;
$durLabel = $d >= 3600 ? floor($d / 3600) . 'h ' . floor(($d % 3600) / 60) . 'm'
    : ($d >= 60 ? floor($d / 60) . 'm ' . ($d % 60) . 's' : ($d > 0 ? $d . 's' : '--'));

$pct = 0;
if (($job['files_total'] ?? 0) > 0 && $job['files_processed'] > 0) {
    $pct = round(($job['files_processed'] / $job['files_total']) * 100);
}
$bytesPct = 0;
if (($job['bytes_total'] ?? 0) > 0 && $job['bytes_processed'] > 0) {
    $bytesPct = round(($job['bytes_processed'] / $job['bytes_total']) * 100);
}

function formatBytes($bytes) {
    if (!$bytes || $bytes == 0) return '--';
    return \BBS\Services\ServerStats::formatBytes((int) $bytes);
}

$isActive = in_array($job['status'], ['queued', 'sent', 'running']);
$isServerSide = in_array($job['task_type'], ['prune', 'compact', 's3_sync', 's3_restore', 'catalog_sync', 'catalog_rebuild', 'catalog_rebuild_full']);
$taskLabel = ucfirst(str_replace('_', ' ', $job['task_type']));
?>

<style>
    /* Force long paths and command lines to wrap inside table cells / log entries */
    .job-detail-wrap {
        word-break: break-all;
        overflow-wrap: anywhere;
        white-space: normal;
        display: inline-block;
        max-width: 100%;
    }
    /* Whole progress card: status_message can carry a full file path from
       borg. Without overflow protection a long path stretches the card
       past the viewport and the page scrolls horizontally (#209,
       regression of #108). overflow-wrap:anywhere only breaks when needed,
       so normal sentences stay unbroken. Excluding .text-truncate so the
       single-line ellipsis on currentFile still works. */
    #progress-section .card-body {
        min-width: 0;
    }
    #progress-section .card-body :not(.text-truncate):not(.progress):not(.progress-bar) {
        overflow-wrap: anywhere;
        word-break: break-word;
        min-width: 0;
    }
    #log-section .list-group-item .small {
        word-break: break-all;
        overflow-wrap: anywhere;
        min-width: 0;
    }
    #log-section .list-group-item .flex-grow-1,
    #log-section .list-group-item .flex-grow-1 > .d-flex {
        min-width: 0;
    }
</style>

<div class="d-flex align-items-center mb-4">
    <a href="/queue" class="btn btn-sm btn-outline-secondary me-3"><i class="bi bi-arrow-left"></i> Queue</a>
    <h4 class="mb-0">
        Job #<?= $job['id'] ?>
        <span class="badge text-bg-<?= $statusClass ?> fs-6 ms-2"><?= htmlspecialchars($statusLabel) ?></span>
    </h4>
</div>

<div id="progress-section">
<!-- Progress Bar (for active jobs) -->
<?php if ($isActive): ?>
<div class="card border-0 shadow-sm mb-4" style="background-color: #2c3e50;">
    <div class="card-body py-3">
        <?php if ($isServerSide && $job['status'] === 'running'): ?>
            <div class="text-white fw-semibold mb-1"><i class="bi bi-hdd me-1"></i> <?= ucfirst($job['task_type']) ?> running on server...</div>
            <div class="progress mb-1" style="height: 22px; background-color: rgba(255,255,255,0.15);">
                <div class="progress-bar progress-bar-striped progress-bar-animated" role="progressbar"
                     style="width: 100%; background-color: #5b9bd5;">
                    Server-side <?= $job['task_type'] ?>
                </div>
            </div>
            <div class="text-white-50 small">This task runs directly on the backup server — no agent involved</div>
        <?php elseif ($isServerSide && $job['status'] === 'sent'): ?>
            <div class="text-white fw-semibold mb-1"><i class="bi bi-hdd me-1"></i> Waiting for scheduler</div>
            <div class="progress mb-1" style="height: 22px; background-color: rgba(255,255,255,0.15);">
                <div class="progress-bar progress-bar-striped progress-bar-animated" role="progressbar"
                     style="width: 100%; background-color: #e67e22;">
                    Queued for server
                </div>
            </div>
            <div class="text-white-50 small">This <?= $job['task_type'] ?> job runs server-side and will be picked up by the scheduler within 60 seconds</div>
        <?php elseif ($job['status'] === 'running' && $pct > 0): ?>
            <div class="d-flex justify-content-between text-white mb-1">
                <span class="fw-semibold"><?= $taskLabel ?>... <?= $pct ?>%</span>
                <span class="small text-white-50"><?= formatBytes($job['bytes_processed']) ?><?= ($job['bytes_total'] ?? 0) > 0 ? ' of ' . formatBytes($job['bytes_total']) : '' ?> processed</span>
            </div>
            <div class="progress mb-1" style="height: 22px; background-color: rgba(255,255,255,0.15);">
                <div class="progress-bar progress-bar-striped progress-bar-animated" role="progressbar"
                     style="width: <?= $pct ?>%; background-color: #5b9bd5;" aria-valuenow="<?= $pct ?>" aria-valuemin="0" aria-valuemax="100">
                    <?= number_format($job['files_processed']) ?> / <?= number_format($job['files_total']) ?> files
                </div>
            </div>
            <div class="text-white-50 small text-truncate" id="currentFile" style="max-width: 100%;"></div>
        <?php elseif ($job['status'] === 'running'): ?>
            <div class="text-white fw-semibold mb-1"><?= !empty($job['status_message']) ? htmlspecialchars($job['status_message']) : $taskLabel . ' in progress...' ?></div>
            <div class="progress mb-1" style="height: 22px; background-color: rgba(255,255,255,0.15);">
                <div class="progress-bar progress-bar-striped progress-bar-animated" role="progressbar"
                     style="width: 100%; background-color: #5b9bd5;">
                    Running
                </div>
            </div>
            <div class="text-white-50 small"><?= $isServerSide ? 'Running on server...' : 'Waiting for progress data from agent...' ?></div>
        <?php elseif ($job['status'] === 'sent'): ?>
            <div class="text-white fw-semibold mb-1">Waiting for Agent</div>
            <div class="progress mb-1" style="height: 22px; background-color: rgba(255,255,255,0.15);">
                <div class="progress-bar progress-bar-striped progress-bar-animated" role="progressbar"
                     style="width: 100%; background-color: #5b9bd5;">
                    Sent to agent
                </div>
            </div>
            <div class="text-white-50 small">
                <?php if ($job['agent_status'] === 'online'): ?>
                    <i class="bi bi-check-circle-fill text-success me-1"></i>
                    Agent "<strong><?= htmlspecialchars($job['agent_name']) ?></strong>" is online — waiting for next poll (every <?= $pollInterval ?>s)
                <?php elseif ($job['agent_status'] === 'offline'): ?>
                    <i class="bi bi-exclamation-triangle-fill text-warning me-1"></i>
                    Agent "<strong><?= htmlspecialchars($job['agent_name']) ?></strong>" is offline — job will be failed by scheduler if agent doesn't reconnect
                <?php else: ?>
                    <i class="bi bi-clock text-info me-1"></i>
                    Agent "<strong><?= htmlspecialchars($job['agent_name']) ?></strong>" status: <?= $job['agent_status'] ?> — waiting for agent to pick up task
                <?php endif; ?>
                <?php if ($job['last_heartbeat']): ?>
                    <br>Last heartbeat: <?= \BBS\Core\TimeHelper::format($job['last_heartbeat'], 'M j, g:i:s A') ?>
                <?php else: ?>
                    <br>No heartbeat received yet — agent may not be installed
                <?php endif; ?>
            </div>
        <?php elseif ($isServerSide): ?>
            <div class="text-white fw-semibold mb-1"><i class="bi bi-hdd me-1"></i> Queued for server<?= $queuePosition ? " — Position #{$queuePosition}" : '' ?></div>
            <div class="progress mb-1" style="height: 22px; background-color: rgba(255,255,255,0.15);">
                <div class="progress-bar progress-bar-striped progress-bar-animated" role="progressbar"
                     style="width: 100%; background-color: #e67e22;">
                    Waiting
                </div>
            </div>
            <div class="text-white-50 small">
                <i class="bi bi-clock text-info me-1"></i>
                This <?= $job['task_type'] ?> job will run server-side when a queue slot opens
            </div>
        <?php else: ?>
            <?php
            $queueFull = $activeCount >= $maxQueue;
            $agentOffline = $job['agent_status'] === 'offline';
            ?>
            <div class="text-white fw-semibold mb-1">Queued<?= $queuePosition ? " — Position #{$queuePosition}" : '' ?></div>
            <div class="progress mb-1" style="height: 22px; background-color: rgba(255,255,255,0.15);">
                <div class="progress-bar progress-bar-striped progress-bar-animated" role="progressbar"
                     style="width: 100%; background-color: #e67e22;">
                    Waiting
                </div>
            </div>
            <div class="text-white-50 small">
                <?php if ($agentOffline && $queueFull): ?>
                    <i class="bi bi-exclamation-triangle-fill text-warning me-1"></i>
                    Agent "<strong><?= htmlspecialchars($job['agent_name']) ?></strong>" is offline AND queue is full (<?= $activeCount ?>/<?= $maxQueue ?> slots used)
                <?php elseif ($agentOffline): ?>
                    <i class="bi bi-wifi-off text-warning me-1"></i>
                    Waiting for agent "<strong><?= htmlspecialchars($job['agent_name']) ?></strong>" to come online
                    <?php if ($job['last_heartbeat']): ?>
                        — last seen <?= \BBS\Core\TimeHelper::format($job['last_heartbeat'], 'M j, g:i:s A') ?>
                    <?php else: ?>
                        — never connected
                    <?php endif; ?>
                <?php elseif ($queueFull): ?>
                    <i class="bi bi-hourglass-split text-info me-1"></i>
                    Queue full — <?= $activeCount ?>/<?= $maxQueue ?> concurrent job slots in use. This job will start when a slot opens.
                <?php else: ?>
                    <i class="bi bi-clock text-info me-1"></i>
                    Waiting to be promoted to active — agent is <?= $job['agent_status'] ?>, <?= $activeCount ?>/<?= $maxQueue ?> slots used
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</div>
<?php elseif ($job['status'] === 'completed' && $isServerSide): ?>
<div class="card border-0 shadow-sm mb-4 bg-success-subtle">
    <div class="card-body py-3">
        <div class="fw-semibold text-success mb-1"><i class="bi bi-hdd me-1"></i> <?= ucfirst($job['task_type']) ?> Completed</div>
        <div class="progress mb-1" style="height: 22px;">
            <div class="progress-bar bg-success" role="progressbar" style="width: 100%;">
                Server-side <?= $job['task_type'] ?> finished
            </div>
        </div>
        <div class="text-muted small">Duration: <?= $durLabel ?> &middot; See activity log below for details</div>
    </div>
</div>
<?php elseif ($job['status'] === 'completed' && !empty($job['had_warnings'])): ?>
<div class="card border-0 shadow-sm mb-4 bg-warning-subtle">
    <div class="card-body py-3">
        <div class="fw-semibold text-warning-emphasis mb-1"><i class="bi bi-exclamation-triangle me-1"></i> Completed with warnings</div>
        <div class="progress mb-1" style="height: 22px;">
            <div class="progress-bar bg-warning" role="progressbar" style="width: 100%;">
                <?= number_format($job['files_total'] ?? 0) ?> files processed
            </div>
        </div>
        <div class="text-warning-emphasis small mt-1"><i class="bi bi-info-circle me-1"></i>borg created the archive but reported one or more warnings — see Warning Log below</div>
    </div>
</div>
<?php elseif ($job['status'] === 'completed' && ($job['files_total'] ?? 0) > 0): ?>
<div class="card border-0 shadow-sm mb-4 bg-success-subtle">
    <div class="card-body py-3">
        <div class="fw-semibold text-success mb-1">Completed</div>
        <div class="progress mb-1" style="height: 22px;">
            <div class="progress-bar bg-success" role="progressbar" style="width: 100%;">
                <?= number_format($job['files_total']) ?> files processed
            </div>
        </div>
        <div class="text-muted small"><?= formatBytes($job['bytes_total']) ?> total &middot; <?= $durLabel ?></div>
    </div>
</div>
<?php elseif ($job['status'] === 'failed'): ?>
<div class="card border-0 shadow-sm mb-4 bg-danger-subtle">
    <div class="card-body py-3">
        <div class="fw-semibold text-danger mb-1">Failed</div>
        <div class="progress mb-1" style="height: 22px;">
            <div class="progress-bar bg-danger" role="progressbar" style="width: <?= max($pct, 100) ?>%;">
                Failed
            </div>
        </div>
        <?php if ($job['error_log']): ?>
        <div class="text-danger small mt-1"><i class="bi bi-exclamation-triangle me-1"></i><?= htmlspecialchars(substr($job['error_log'], 0, 200)) ?></div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>
</div><!-- /progress-section -->

<!-- Job Details -->
<div class="row g-4 mb-4">
    <div class="col-lg-7">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-body fw-semibold">
                <i class="bi bi-info-circle me-1"></i> Job Details
            </div>
            <div class="card-body p-0">
                <table class="table table-borderless mb-0">
                    <tbody>
                        <tr>
                            <td class="text-muted fw-semibold ps-3" style="width: 160px;">Client</td>
                            <td>
                                <a href="/clients/<?= $job['agent_id'] ?>"><?= htmlspecialchars($job['agent_name']) ?></a>
                                <?php
                                $agentBadge = match($job['agent_status']) {
                                    'online' => 'success',
                                    'offline' => 'secondary',
                                    'error' => 'danger',
                                    default => 'warning',
                                };
                                ?>
                                <span class="badge text-bg-<?= $agentBadge ?> ms-1"><?= ucfirst($job['agent_status']) ?></span>
                            </td>
                        </tr>
                        <tr>
                            <td class="text-muted fw-semibold ps-3">Task Type</td>
                            <td><i class="bi bi-<?= match($job['task_type']) {
                                'backup' => 'archive',
                                'prune' => 'scissors',
                                'restore' => 'arrow-counterclockwise',
                                'check' => 'shield-check',
                                'compact' => 'arrows-collapse',
                                'update_borg' => 'arrow-up-circle',
                                's3_sync' => 'cloud-upload',
                                default => 'gear',
                            } ?> me-1"></i><?= ucfirst(str_replace('_', ' ', $job['task_type'])) ?></td>
                        </tr>
                        <tr>
                            <td class="text-muted fw-semibold ps-3">Repository</td>
                            <td><?= htmlspecialchars($job['repo_name'] ?? '--') ?></td>
                        </tr>
                        <?php if ($job['plan_name']): ?>
                        <tr>
                            <td class="text-muted fw-semibold ps-3">Backup Plan</td>
                            <td><?= htmlspecialchars($job['plan_name']) ?></td>
                        </tr>
                        <?php endif; ?>
                        <tr>
                            <td class="text-muted fw-semibold ps-3">Queued At</td>
                            <td><?= $job['queued_at'] ? \BBS\Core\TimeHelper::format($job['queued_at'], 'M j, Y g:i:s A') : '--' ?></td>
                        </tr>
                        <tr>
                            <td class="text-muted fw-semibold ps-3">Started At</td>
                            <td><?= $job['started_at'] ? \BBS\Core\TimeHelper::format($job['started_at'], 'M j, Y g:i:s A') : '--' ?></td>
                        </tr>
                        <tr>
                            <td class="text-muted fw-semibold ps-3">Completed At</td>
                            <td><?= $job['completed_at'] ? \BBS\Core\TimeHelper::format($job['completed_at'], 'M j, Y g:i:s A') : '--' ?></td>
                        </tr>
                        <tr>
                            <td class="text-muted fw-semibold ps-3">Duration</td>
                            <td><?= $durLabel ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="card border-0 shadow-sm h-100">
            <?php if ($job['task_type'] === 'prune' && !empty($pruneStats)): ?>
            <div class="card-header bg-body fw-semibold">
                <i class="bi bi-scissors me-1"></i> Prune Stats
            </div>
            <div class="card-body p-0">
                <table class="table table-borderless mb-0">
                    <tbody>
                        <tr>
                            <td class="text-muted fw-semibold ps-3" style="width: 160px;">Recovery Points Before</td>
                            <td><?= $pruneStats['existing'] !== null ? number_format($pruneStats['existing']) : '--' ?></td>
                        </tr>
                        <tr>
                            <td class="text-muted fw-semibold ps-3">Kept</td>
                            <td class="text-success fw-semibold"><?= $pruneStats['kept'] !== null ? number_format($pruneStats['kept']) : '--' ?></td>
                        </tr>
                        <tr>
                            <td class="text-muted fw-semibold ps-3">Deleted</td>
                            <td class="<?= ($pruneStats['deleted'] ?? 0) > 0 ? 'text-danger fw-semibold' : '' ?>">
                                <?= $pruneStats['deleted'] !== null ? number_format($pruneStats['deleted']) : '--' ?>
                            </td>
                        </tr>
                        <?php if (!empty($pruneStats['keep_rules'])): ?>
                        <tr>
                            <td class="text-muted fw-semibold ps-3">Keep Rules</td>
                            <td>
                                <?php foreach ($pruneStats['keep_rules'] as $rule => $n): ?>
                                    <?php $short = ['hourly'=>'h','daily'=>'d','weekly'=>'w','monthly'=>'m','yearly'=>'y','minutely'=>'min','secondly'=>'s'][$rule] ?? $rule; ?>
                                    <span class="badge bg-body-secondary text-body border me-1" title="<?= $n ?> <?= $rule ?>"><?= $n ?><?= $short ?></span>
                                <?php endforeach; ?>
                            </td>
                        </tr>
                        <?php endif; ?>
                        <?php if (!empty($pruneStats['deleted_names'])): ?>
                        <tr>
                            <td class="text-muted fw-semibold ps-3 align-top pt-3">Pruned Archives</td>
                            <td class="small">
                                <?php foreach (array_slice($pruneStats['deleted_names'], 0, 20) as $name): ?>
                                <div><code class="small text-danger"><?= htmlspecialchars($name) ?></code></div>
                                <?php endforeach; ?>
                                <?php if (count($pruneStats['deleted_names']) > 20): ?>
                                <div class="text-muted">(and <?= count($pruneStats['deleted_names']) - 20 ?> more)</div>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
            <div class="card-header bg-body fw-semibold">
                <i class="bi bi-bar-chart me-1"></i> Stats
            </div>
            <div class="card-body p-0">
                <table class="table table-borderless mb-0">
                    <tbody>
                        <tr>
                            <td class="text-muted fw-semibold ps-3" style="width: 160px;">Files Total</td>
                            <td><?= $job['files_total'] ? number_format($job['files_total']) : '--' ?></td>
                        </tr>
                        <tr>
                            <td class="text-muted fw-semibold ps-3">Files Processed</td>
                            <td><?= $job['files_processed'] ? number_format($job['files_processed']) : '--' ?></td>
                        </tr>
                        <tr>
                            <td class="text-muted fw-semibold ps-3">Bytes Total</td>
                            <td><?= formatBytes($job['bytes_total']) ?></td>
                        </tr>
                        <tr>
                            <td class="text-muted fw-semibold ps-3">Bytes Processed</td>
                            <td><?= formatBytes($job['bytes_processed']) ?></td>
                        </tr>
                        <?php if ($job['directories']): ?>
                        <tr>
                            <td class="text-muted fw-semibold ps-3">Directories</td>
                            <td><code class="small job-detail-wrap"><?= htmlspecialchars(str_replace("\n", ', ', $job['directories'])) ?></code></td>
                        </tr>
                        <?php endif; ?>
                        <?php if ($job['advanced_options']): ?>
                        <tr>
                            <td class="text-muted fw-semibold ps-3">Borg Options</td>
                            <td><code class="small job-detail-wrap"><?= htmlspecialchars($job['advanced_options']) ?></code></td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Error Log (if failed) -->
<?php if ($job['status'] === 'failed' && $job['error_log']): ?>
<div id="error-section" class="card border-0 shadow-sm mb-4 border-danger">
    <div class="card-header bg-body fw-semibold text-danger">
        <i class="bi bi-exclamation-triangle me-1"></i> Error Log
    </div>
    <div class="card-body">
        <pre class="mb-0 small text-danger" style="white-space: pre-wrap; word-break: break-all; overflow-wrap: anywhere;"><?= htmlspecialchars($job['error_log']) ?></pre>
    </div>
</div>
<?php elseif ($job['status'] === 'completed' && !empty($job['had_warnings'])): ?>
<!-- Warning Log (completed with warnings — e.g. a configured source path didn't exist, #203) -->
<div id="warning-section" class="card border-0 shadow-sm mb-4 border-warning">
    <div class="card-header bg-body fw-semibold text-warning">
        <i class="bi bi-exclamation-triangle me-1"></i> Backup Completed with Warnings
    </div>
    <div class="card-body">
        <p class="small text-muted mb-2">borg created the archive but reported one or more warnings — check that every source path actually exists on the client.</p>
        <?php if (!empty($job['error_log'])): ?>
        <pre class="mb-0 small text-warning-emphasis" style="white-space: pre-wrap; word-break: break-all; overflow-wrap: anywhere;"><?= htmlspecialchars($job['error_log']) ?></pre>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<!-- Server Log -->
<div id="log-section" <?= empty($logs) ? 'style="display:none"' : '' ?>>
<?php if (!empty($logs)): ?>
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-body fw-semibold">
        <i class="bi bi-journal-text me-1"></i> Activity Log
    </div>
    <div class="card-body p-0">
        <div class="list-group list-group-flush">
            <?php foreach ($logs as $log): ?>
            <?php
            $logIcon = match($log['level']) {
                'error' => 'x-circle-fill text-danger',
                'warning' => 'exclamation-triangle-fill text-warning',
                'info' => 'info-circle-fill text-info',
                default => 'circle text-secondary',
            };
            ?>
            <div class="list-group-item d-flex align-items-start py-2 px-3">
                <i class="bi bi-<?= $logIcon ?> me-2 mt-1"></i>
                <div class="flex-grow-1">
                    <div class="d-flex justify-content-between">
                        <span class="small"><?= htmlspecialchars($log['message']) ?></span>
                        <small class="text-muted ms-3 text-nowrap"><?= \BBS\Core\TimeHelper::format($log['created_at'], 'M j g:i:s A') ?></small>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
<?php endif; ?>
</div><!-- /log-section -->

<!-- Actions -->
<div id="actions-section" class="d-flex gap-2">
    <?php if (in_array($job['status'], ['queued', 'sent', 'running'])): ?>
    <form method="POST" action="/queue/<?= $job['id'] ?>/cancel" data-confirm="Cancel this job?">
        <input type="hidden" name="csrf_token" value="<?= $this->csrfToken() ?>">
        <button class="btn btn-danger"><i class="bi bi-x-circle me-1"></i> Cancel Job</button>
    </form>
    <?php endif; ?>
    <?php if ($job['status'] === 'failed'): ?>
    <form method="POST" action="/queue/<?= $job['id'] ?>/retry" data-confirm="Retry this job?">
        <input type="hidden" name="csrf_token" value="<?= $this->csrfToken() ?>">
        <button class="btn btn-warning"><i class="bi bi-arrow-repeat me-1"></i> Retry Job</button>
    </form>
    <?php endif; ?>
    <?php if ($isActive): ?>
    <div class="text-muted small align-self-center ms-2">
        Job Stalled? Cancel and retry, or check the agent status on the <a href="/clients/<?= $job['agent_id'] ?>">client page</a>.
    </div>
    <?php endif; ?>
</div>

<script>
(function() {
    const jobId = <?= $job['id'] ?>;
    const pollInterval = <?= $isActive ? 5000 : 5000 ?>;
    const csrfToken = '<?= $this->csrfToken() ?>';
    let isActive = <?= $isActive ? 'true' : 'false' ?>;
    let completedAt = <?= $job['completed_at'] ? "new Date('" . $job['completed_at'] . "Z').getTime()" : 'null' ?>;
    let lastLogCount = <?= count($logs) ?>;
    let previousStatus = '<?= $job['status'] ?>';

    function esc(s) { return s ? String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;') : ''; }

    function fmtDate(d) {
        if (!d) return '--';
        const dt = new Date(d.replace(' ','T')+'Z');
        const tz = window.BBS_TIMEZONE || 'UTC';
        const tOpts = window.BBS_TIME_24H ? {hour:'2-digit',minute:'2-digit',second:'2-digit',hour12:false,timeZone:tz} : {hour:'numeric',minute:'2-digit',second:'2-digit',timeZone:tz};
        return dt.toLocaleDateString('en-US', {month:'short',day:'numeric',year:'numeric',timeZone:tz}) + ' ' +
               dt.toLocaleTimeString('en-US', tOpts);
    }

    function fmtDur(s) {
        if (!s || s <= 0) return '--';
        if (s >= 3600) return Math.floor(s/3600) + 'h ' + Math.floor((s%3600)/60) + 'm';
        if (s >= 60) return Math.floor(s/60) + 'm ' + (s%60) + 's';
        return s + 's';
    }

    function fmtBytes(b) {
        if (!b || b == 0) return '--';
        const units = ['B','KB','MB','GB','TB'];
        let i = 0, s = b;
        while (s >= 1024 && i < units.length-1) { s /= 1024; i++; }
        return (i > 0 ? s.toFixed(1) : s) + '\u00A0' + units[i];
    }

    function updateProgressBar(job, data) {
        const container = document.getElementById('progress-section');
        if (!container) return;

        const isServerSide = ['prune','compact','s3_sync','s3_restore','repo_check','repo_repair','break_lock','catalog_sync','catalog_rebuild','catalog_rebuild_full'].includes(job.task_type);
        const pct = (job.files_total > 0 && job.files_processed > 0) ? Math.round((job.files_processed / job.files_total) * 100) : 0;
        const isJobActive = ['queued','sent','running'].includes(job.status);

        if (job.status === 'completed') {
            if (isServerSide) {
                container.innerHTML = '<div class="card border-0 shadow-sm mb-4 bg-success-subtle"><div class="card-body py-3">' +
                    '<div class="fw-semibold text-success mb-1"><i class="bi bi-hdd me-1"></i> ' + esc(job.task_type[0].toUpperCase()+job.task_type.slice(1)) + ' Completed</div>' +
                    '<div class="progress mb-1" style="height:22px"><div class="progress-bar bg-success" style="width:100%">Server-side ' + esc(job.task_type) + ' finished</div></div>' +
                    '<div class="text-muted small">Duration: ' + fmtDur(job.duration_seconds) + ' &middot; See activity log below for details</div></div></div>';
            } else {
                container.innerHTML = '<div class="card border-0 shadow-sm mb-4 bg-success-subtle"><div class="card-body py-3">' +
                    '<div class="fw-semibold text-success mb-1">Completed</div>' +
                    '<div class="progress mb-1" style="height:22px"><div class="progress-bar bg-success" style="width:100%">' + (job.files_total ? Number(job.files_total).toLocaleString() + ' files processed' : 'Done') + '</div></div>' +
                    '<div class="text-muted small">' + fmtBytes(job.bytes_total) + ' total &middot; ' + fmtDur(job.duration_seconds) + '</div></div></div>';
            }
        } else if (job.status === 'failed') {
            container.innerHTML = '<div class="card border-0 shadow-sm mb-4 bg-danger-subtle"><div class="card-body py-3">' +
                '<div class="fw-semibold text-danger mb-1">Failed</div>' +
                '<div class="progress mb-1" style="height:22px"><div class="progress-bar bg-danger" style="width:100%">Failed</div></div>' +
                (job.error_log ? '<div class="text-danger small mt-1"><i class="bi bi-exclamation-triangle me-1"></i>' + esc(job.error_log.substring(0,200)) + '</div>' : '') +
                '</div></div>';
        } else if (isJobActive && job.status === 'running' && pct > 0) {
            var taskLabel = (job.task_type || 'backup').replace('_',' ').replace(/^\w/, c => c.toUpperCase());
            var bytesText = fmtBytes(job.bytes_processed) + (job.bytes_total > 0 ? ' of ' + fmtBytes(job.bytes_total) : '') + ' processed';
            var currentFile = data.currentFile ? data.currentFile.replace(/\/+$/, '') : '';
            var fileHtml = currentFile ? '<div class="text-white-50 small text-truncate" style="max-width:100%;" title="' + esc(currentFile) + '">' + esc(currentFile) + '</div>' : '';
            var headerRow = container.querySelector('.d-flex.justify-content-between');
            if (headerRow) {
                // In-place update
                const bar = container.querySelector('.progress-bar');
                const label = headerRow.querySelector('.fw-semibold');
                const bytesEl = headerRow.querySelector('.text-white-50');
                if (bar) { bar.style.width = pct + '%'; bar.textContent = Number(job.files_processed).toLocaleString() + ' / ' + Number(job.files_total).toLocaleString() + ' files'; }
                if (label) label.textContent = taskLabel + '... ' + pct + '%';
                if (bytesEl) bytesEl.textContent = bytesText;
                var fileEl = container.querySelector('.text-truncate');
                if (fileEl && currentFile) { fileEl.textContent = currentFile; fileEl.title = currentFile; }
                else if (fileEl && !currentFile) { fileEl.textContent = ''; }
            } else {
                // Full replace (transitioning from pre-progress state)
                container.innerHTML = '<div class="card border-0 shadow-sm mb-4" style="background-color:#2c3e50"><div class="card-body py-3">' +
                    '<div class="d-flex justify-content-between text-white mb-1"><span class="fw-semibold">' + esc(taskLabel) + '... ' + pct + '%</span><span class="small text-white-50">' + bytesText + '</span></div>' +
                    '<div class="progress mb-1" style="height:22px;background-color:rgba(255,255,255,0.15)"><div class="progress-bar progress-bar-striped progress-bar-animated" role="progressbar" style="width:' + pct + '%;background-color:#5b9bd5">' + Number(job.files_processed).toLocaleString() + ' / ' + Number(job.files_total).toLocaleString() + ' files</div></div>' +
                    fileHtml + '</div></div>';
            }
        } else if (isJobActive && job.status === 'running') {
            // Full replace when transitioning from queued/sent to running (pre-progress phase)
            var taskLabel2 = (job.task_type || 'backup').replace('_',' ').replace(/^\w/, c => c.toUpperCase());
            var msg = job.status_message ? esc(job.status_message) : taskLabel2 + ' in progress...';
            var sub = job.status_message ? '' : '<div class="text-white-50 small">' + (isServerSide ? 'Running on server...' : 'Waiting for progress data from agent...') + '</div>';
            container.innerHTML = '<div class="card border-0 shadow-sm mb-4" style="background-color:#2c3e50"><div class="card-body py-3">' +
                '<div class="text-white fw-semibold mb-1">' + msg + '</div>' +
                '<div class="progress mb-1" style="height:22px;background-color:rgba(255,255,255,0.15)"><div class="progress-bar progress-bar-striped progress-bar-animated" role="progressbar" style="width:100%;background-color:#5b9bd5">Running</div></div>' +
                sub + '</div></div>';
        }

        // Update status badge in header
        const badge = document.querySelector('h4 .badge');
        if (badge) {
            let cls = {completed:'success',failed:'danger',cancelled:'danger',running:'primary',sent:'primary',queued:'warning'}[job.status] || 'secondary';
            let label = job.status[0].toUpperCase() + job.status.slice(1);
            if (job.status === 'completed' && job.had_warnings) {
                cls = 'warning';
                label = 'Completed with warnings';
            }
            badge.className = 'badge text-bg-' + cls + ' fs-6 ms-2';
            badge.textContent = label;
        }
    }

    function updateDetails(job) {
        // Update stats
        const statsMap = {
            'Files Total': job.files_total ? Number(job.files_total).toLocaleString() : '--',
            'Files Processed': job.files_processed ? Number(job.files_processed).toLocaleString() : '--',
            'Bytes Total': fmtBytes(job.bytes_total),
            'Bytes Processed': fmtBytes(job.bytes_processed),
        };
        document.querySelectorAll('table.table-borderless td.text-muted').forEach(td => {
            const key = td.textContent.trim();
            if (statsMap[key] !== undefined) {
                td.nextElementSibling.textContent = statsMap[key];
            }
            if (key === 'Started At' && job.started_at) td.nextElementSibling.textContent = fmtDate(job.started_at);
            if (key === 'Completed At' && job.completed_at) td.nextElementSibling.textContent = fmtDate(job.completed_at);
            if (key === 'Duration') td.nextElementSibling.textContent = fmtDur(job.duration_seconds);
        });
    }

    function updateLogs(logs) {
        if (logs.length <= lastLogCount) return;
        lastLogCount = logs.length;
        const logSection = document.getElementById('log-section');
        if (!logSection) return;
        logSection.style.display = '';

        const list = logSection.querySelector('.list-group');
        if (!list) return;
        list.innerHTML = '';
        logs.forEach(function(log) {
            const iconMap = {error:'x-circle-fill text-danger',warning:'exclamation-triangle-fill text-warning',info:'info-circle-fill text-info'};
            const icon = iconMap[log.level] || 'circle text-secondary';
            const item = document.createElement('div');
            item.className = 'list-group-item d-flex align-items-start py-2 px-3';
            item.innerHTML = '<i class="bi bi-' + icon + ' me-2 mt-1"></i><div class="flex-grow-1"><div class="d-flex justify-content-between"><span class="small">' + esc(log.message) + '</span><small class="text-muted ms-3 text-nowrap">' + fmtDate(log.created_at) + '</small></div></div>';
            list.appendChild(item);
        });
    }

    function updateActions(job) {
        const actions = document.getElementById('actions-section');
        if (!actions) return;
        let html = '';
        if (job.status === 'queued' || job.status === 'sent' || job.status === 'running') {
            html += '<form method="POST" action="/queue/' + jobId + '/cancel" data-confirm="Cancel this job?"><input type="hidden" name="csrf_token" value="' + csrfToken + '"><button class="btn btn-danger"><i class="bi bi-x-circle me-1"></i> Cancel Job</button></form>';
        }
        if (job.status === 'failed') {
            html += '<form method="POST" action="/queue/' + jobId + '/retry" data-confirm="Retry this job?"><input type="hidden" name="csrf_token" value="' + csrfToken + '"><button class="btn btn-warning"><i class="bi bi-arrow-repeat me-1"></i> Retry Job</button></form>';
        }
        if (['queued','sent','running'].includes(job.status)) {
            html += '<div class="text-muted small align-self-center ms-2">Job Stalled? Cancel and retry, or check the agent status on the <a href="/clients/' + job.agent_id + '">client page</a>.</div>';
        }
        actions.innerHTML = html;
    }

    function poll() {
        fetch('/queue/' + jobId + '/json', { credentials: 'same-origin' })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                const job = data.job;
                updateProgressBar(job, data);
                updateDetails(job);
                updateLogs(data.logs);
                if (job.status !== previousStatus) {
                    updateActions(job);
                    previousStatus = job.status;
                }

                // Update error / warning log section
                if (job.status === 'failed' && job.error_log) {
                    let errSection = document.getElementById('error-section') || document.getElementById('warning-section');
                    if (!errSection) {
                        errSection = document.createElement('div');
                        const logEl = document.getElementById('log-section');
                        if (logEl) logEl.parentNode.insertBefore(errSection, logEl);
                    }
                    errSection.id = 'error-section';
                    errSection.innerHTML = '<div class="card border-0 shadow-sm mb-4 border-danger"><div class="card-header bg-body fw-semibold text-danger"><i class="bi bi-exclamation-triangle me-1"></i> Error Log</div><div class="card-body"><pre class="mb-0 small text-danger" style="white-space:pre-wrap;word-break:break-all;overflow-wrap:anywhere;">' + esc(job.error_log) + '</pre></div></div>';
                } else if (job.status === 'completed' && job.had_warnings) {
                    let warnSection = document.getElementById('warning-section') || document.getElementById('error-section');
                    if (!warnSection) {
                        warnSection = document.createElement('div');
                        const logEl = document.getElementById('log-section');
                        if (logEl) logEl.parentNode.insertBefore(warnSection, logEl);
                    }
                    warnSection.id = 'warning-section';
                    const body = job.error_log
                        ? '<pre class="mb-0 small text-warning-emphasis" style="white-space:pre-wrap;word-break:break-all;overflow-wrap:anywhere;">' + esc(job.error_log) + '</pre>'
                        : '';
                    warnSection.innerHTML = '<div class="card border-0 shadow-sm mb-4 border-warning"><div class="card-header bg-body fw-semibold text-warning"><i class="bi bi-exclamation-triangle me-1"></i> Backup Completed with Warnings</div><div class="card-body"><p class="small text-muted mb-2">borg created the archive but reported one or more warnings — check that every source path actually exists on the client.</p>' + body + '</div></div>';
                }

                // Decide whether to keep polling
                const jobActive = ['queued','sent','running'].includes(job.status);
                if (jobActive) {
                    isActive = true;
                    setTimeout(poll, 1000);
                } else if (job.completed_at) {
                    isActive = false;
                    completedAt = new Date(job.completed_at.replace(' ','T')+'Z').getTime();
                    if (Date.now() - completedAt < 120000) {
                        setTimeout(poll, 5000);
                    }
                }
            })
            .catch(function() {
                // On error, retry after longer delay
                if (isActive) setTimeout(poll, 5000);
            });
    }

    // Start polling
    if (isActive) {
        setTimeout(poll, 1000);
    } else if (completedAt && (Date.now() - completedAt < 120000)) {
        setTimeout(poll, 5000);
    }
})();
</script>
