<?php
$sizeLabel = $totalSize > 0 ? \BBS\Services\ServerStats::formatBytes((int) $totalSize) : '0';
?>

<!-- Breadcrumb -->
<nav aria-label="breadcrumb" class="mb-3">
    <ol class="breadcrumb mb-0">
        <li class="breadcrumb-item"><a href="/clients" class="text-decoration-none">Clients</a></li>
        <li class="breadcrumb-item"><a href="/clients/<?= $agentId ?>" class="text-decoration-none"><?= htmlspecialchars($repo['agent_name']) ?></a></li>
        <li class="breadcrumb-item"><a href="/clients/<?= $agentId ?>?tab=repos" class="text-decoration-none">Repos</a></li>
        <li class="breadcrumb-item active"><?= htmlspecialchars($repo['name']) ?></li>
    </ol>
</nav>

<!-- Header -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-body">
        <div class="d-flex justify-content-between align-items-start">
            <div>
                <h4 class="mb-0">
                    <i class="bi bi-archive text-primary me-2"></i><span id="repoNameDisplay"><?= htmlspecialchars($repo['name']) ?></span>
                    <?php if (($repo['storage_type'] ?? 'local') === 'remote_ssh'): ?>
                    <span class="badge text-bg-info ms-2" style="font-size: 0.6em; vertical-align: middle;"><i class="bi bi-hdd-network me-1"></i>Remote SSH</span>
                    <?php else: ?>
                    <span class="badge text-bg-secondary ms-2" style="font-size: 0.6em; vertical-align: middle;"><i class="bi bi-hdd me-1"></i>Local</span>
                    <button type="button" class="btn btn-sm btn-link text-muted p-0 ms-2" id="renameToggle" title="Rename repository"><i class="bi bi-pencil"></i></button>
                    <?php endif; ?>
                </h4>
                <?php if (($repo['storage_type'] ?? 'local') !== 'remote_ssh'): ?>
                <form method="POST" action="/repositories/<?= $repo['id'] ?>/rename" class="mt-2 d-none" id="renameForm">
                    <input type="hidden" name="csrf_token" value="<?= $this->csrfToken() ?>">
                    <div class="d-flex align-items-center gap-2">
                        <input type="text" name="name" class="form-control form-control-sm" style="max-width: 300px;" value="<?= htmlspecialchars($repo['name']) ?>" required>
                        <button type="submit" class="btn btn-sm btn-primary"<?= $activeJob ? ' disabled title="Wait for active jobs to finish"' : '' ?>>Rename</button>
                        <button type="button" class="btn btn-sm btn-outline-secondary" id="renameCancel">Cancel</button>
                    </div>
                    <?php if ($activeJob): ?>
                    <div class="form-text text-warning"><i class="bi bi-exclamation-triangle me-1"></i>Cannot rename while jobs are running.</div>
                    <?php endif; ?>
                </form>
                <?php endif; ?>
            </div>
            <?php if ($activeJob): ?>
            <span class="badge text-bg-info"><i class="bi bi-hourglass-split me-1"></i>Active: <?= $activeJob['task_type'] ?></span>
            <?php endif; ?>
        </div>

        <!-- Stats Row -->
        <div class="row g-3 mt-3">
            <div class="col-6 col-md-3">
                <div class="d-flex align-items-center p-2 rounded bg-body-secondary">
                    <div class="stat-icon-sm bg-primary bg-opacity-10 text-primary rounded-2 p-2 me-2">
                        <i class="bi bi-hdd"></i>
                    </div>
                    <div>
                        <div class="fw-bold"><?= $sizeLabel ?></div>
                        <div class="text-muted" style="font-size: 0.7rem;">Size</div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <a href="#archives-section" class="text-decoration-none">
                <div class="d-flex align-items-center p-2 rounded bg-body-secondary" style="cursor:pointer;">
                    <div class="stat-icon-sm bg-info bg-opacity-10 text-info rounded-2 p-2 me-2">
                        <i class="bi bi-stack"></i>
                    </div>
                    <div>
                        <div class="fw-bold"><?= $archiveCount ?></div>
                        <div class="text-muted" style="font-size: 0.7rem;">Archives</div>
                    </div>
                </div>
                </a>
            </div>
            <div class="col-6 col-md-3">
                <div class="d-flex align-items-center p-2 rounded bg-body-secondary">
                    <div class="stat-icon-sm bg-success bg-opacity-10 text-success rounded-2 p-2 me-2">
                        <i class="bi bi-calendar-check"></i>
                    </div>
                    <div>
                        <div class="fw-bold"><?= $oldestArchive ? \BBS\Core\TimeHelper::format($oldestArchive, 'M j, Y') : '--' ?></div>
                        <div class="text-muted" style="font-size: 0.7rem;">Oldest</div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="d-flex align-items-center p-2 rounded bg-body-secondary">
                    <div class="stat-icon-sm bg-warning bg-opacity-10 text-warning rounded-2 p-2 me-2">
                        <i class="bi bi-clock-history"></i>
                    </div>
                    <div>
                        <div class="fw-bold"><?= $newestArchive ? \BBS\Core\TimeHelper::ago($newestArchive) : '--' ?></div>
                        <div class="text-muted" style="font-size: 0.7rem;">Latest</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row g-4">
    <!-- Repository Info & Recent Jobs (Left) -->
    <div class="col-lg-6">
        <?php if (($repo['storage_type'] ?? 'local') !== 'remote_ssh' && $s3SyncInfo): ?>
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-body fw-semibold d-flex justify-content-between align-items-center">
                <span><i class="bi bi-cloud text-info me-1"></i> S3 Offsite Mirror</span>
                <form method="POST" action="/clients/<?= $agentId ?>/repo/<?= $repo['id'] ?>/s3-config/delete" class="d-inline" data-confirm="Disable S3 sync?&#10;&#10;The repository will no longer sync to S3 after backups. Data already in S3 will remain.">
                    <input type="hidden" name="csrf_token" value="<?= $this->csrfToken() ?>">
                    <button type="submit" class="btn btn-sm btn-outline-secondary">
                        <i class="bi bi-cloud-slash me-1"></i>Disable
                    </button>
                </form>
            </div>
            <div class="card-body">
                <div class="d-flex align-items-start gap-3 p-3 bg-body-secondary rounded mb-3">
                    <div class="text-info" style="font-size: 1.5rem;">
                        <i class="bi bi-cloud-check"></i>
                    </div>
                    <div>
                        <h6 class="mb-1">Replicated to S3</h6>
                        <p class="text-muted small mb-0">
                            <?php if ($s3SyncInfo['config_name']): ?>
                            Config: <strong><?= htmlspecialchars($s3SyncInfo['config_name']) ?></strong><br>
                            <?php endif; ?>
                            Last sync: <strong><?= $s3SyncInfo['last_s3_sync'] ? \BBS\Core\TimeHelper::ago($s3SyncInfo['last_s3_sync']) : 'Never' ?></strong>
                        </p>
                    </div>
                </div>

                <div class="d-flex align-items-start gap-3 p-3 bg-body-secondary rounded">
                    <div class="text-primary" style="font-size: 1.5rem;">
                        <i class="bi bi-cloud-download"></i>
                    </div>
                    <div class="flex-grow-1">
                        <h6 class="mb-1">Restore from S3</h6>
                        <p class="text-muted small mb-2">Download repository data from S3 back to the server. Use this to recover from local data loss or sync issues.</p>
                        <div class="d-flex gap-2 flex-wrap align-items-end">
                            <form method="POST" action="/clients/<?= $agentId ?>/repo/<?= $repo['id'] ?>/s3-restore" class="d-inline" data-confirm="Restore (replace) from S3?&#10;&#10;This will download the repository data from S3 and OVERWRITE local files.">
                                <input type="hidden" name="csrf_token" value="<?= $this->csrfToken() ?>">
                                <input type="hidden" name="mode" value="replace">
                                <button type="submit" class="btn btn-sm btn-outline-primary" <?= $activeJob ? 'disabled' : '' ?>>
                                    <i class="bi bi-arrow-repeat me-1"></i>Restore (replace)
                                </button>
                            </form>
                            <form method="POST" action="/clients/<?= $agentId ?>/repo/<?= $repo['id'] ?>/s3-restore" class="d-inline" data-confirm="Restore (copy) from S3?&#10;&#10;This will create a NEW repository and download data from S3.">
                                <input type="hidden" name="csrf_token" value="<?= $this->csrfToken() ?>">
                                <input type="hidden" name="mode" value="copy">
                                <div class="input-group input-group-sm" style="width: auto;">
                                    <input type="text" name="copy_name" class="form-control form-control-sm" placeholder="New repo name" value="<?= htmlspecialchars($repo['name']) ?>-copy" style="width: 140px;" required <?= $activeJob ? 'disabled' : '' ?>>
                                    <button type="submit" class="btn btn-outline-secondary" <?= $activeJob ? 'disabled' : '' ?>>
                                        <i class="bi bi-files me-1"></i>Restore (copy)
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php elseif (($repo['storage_type'] ?? 'local') !== 'remote_ssh' && !empty($s3PluginConfigs)): ?>
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-body fw-semibold">
                <i class="bi bi-cloud text-muted me-1"></i> S3 Offsite Mirror
            </div>
            <div class="card-body">
                <p class="text-muted small mb-3">Enable S3 sync to automatically replicate this repository to S3 storage after each backup prune.</p>
                <form method="POST" action="/clients/<?= $agentId ?>/repo/<?= $repo['id'] ?>/s3-config">
                    <input type="hidden" name="csrf_token" value="<?= $this->csrfToken() ?>">
                    <div class="row g-2 align-items-end">
                        <div class="col-auto">
                            <label class="form-label small">S3 Configuration</label>
                            <select name="plugin_config_id" class="form-select form-select-sm" required>
                                <?php foreach ($s3PluginConfigs as $cfg): ?>
                                <option value="<?= $cfg['id'] ?>"><?= htmlspecialchars($cfg['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-auto">
                            <button type="submit" class="btn btn-sm btn-info">
                                <i class="bi bi-cloud-plus me-1"></i>Enable S3 Sync
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
        <?php endif; ?>

        <!-- Repository Info -->
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-body fw-semibold">
                <i class="bi bi-info-circle me-1"></i> Repository Info
            </div>
            <div class="card-body">
                <!-- Storage Location -->
                <div class="d-flex align-items-start gap-3 p-3 bg-body-secondary rounded mb-3">
                    <?php if (($repo['storage_type'] ?? 'local') === 'remote_ssh'): ?>
                    <div class="text-info" style="font-size: 1.5rem;"><i class="bi bi-hdd-network"></i></div>
                    <div>
                        <h6 class="mb-1">Remote SSH</h6>
                        <div class="small text-muted">
                            <?php if (!empty($repo['remote_config_name'])): ?>
                            <div><strong><?= htmlspecialchars($repo['remote_config_name']) ?></strong></div>
                            <?php endif; ?>
                            <div>
                                <code class="text-muted"><?= htmlspecialchars(($repo['remote_user'] ?? '') . '@' . ($repo['remote_host'] ?? '')) ?><?= ((int)($repo['remote_port'] ?? 22)) !== 22 ? ':' . (int)$repo['remote_port'] : '' ?></code>
                            </div>
                            <div class="mt-1"><i class="bi bi-folder2 me-1"></i><?= htmlspecialchars($repo['path']) ?></div>
                        </div>
                    </div>
                    <?php else: ?>
                    <div class="text-primary" style="font-size: 1.5rem;"><i class="bi bi-hdd"></i></div>
                    <div>
                        <h6 class="mb-1">Local Storage<?php if (!empty($storageLocationLabel)): ?> <span class="text-muted fw-normal">&mdash; <?= htmlspecialchars($storageLocationLabel) ?></span><?php endif; ?></h6>
                        <div class="small"><code class="text-muted"><?= htmlspecialchars($localPath ?? $repo['path']) ?></code></div>
                    </div>
                    <?php endif; ?>
                </div>

                <?php
                // Dedup ratio
                $dedupRatio = '--';
                $dedupSaved = '';
                if ($totalOriginal > 0 && $totalDedup > 0) {
                    $ratio = $totalOriginal / $totalDedup;
                    $dedupRatio = number_format($ratio, 1) . 'x';
                    $saved = $totalOriginal - $totalDedup;
                    if ($saved > 0) {
                        $fmtBytes = function(int $b) {
                            return \BBS\Services\ServerStats::formatBytes($b);
                        };
                        $dedupSaved = $fmtBytes($saved) . ' saved';
                    }
                }
                ?>

                <!-- Dedup & Encryption Stats -->
                <div class="row g-2 mb-3">
                    <div class="col-6">
                        <div class="p-3 bg-body-secondary rounded text-center h-100">
                            <div class="fw-bold fs-5 text-success"><?= $dedupRatio ?></div>
                            <div class="text-muted" style="font-size: 0.7rem;">Dedup Ratio</div>
                            <?php if ($dedupSaved): ?>
                            <div class="text-success" style="font-size: 0.7rem;"><?= $dedupSaved ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="p-3 bg-body-secondary rounded text-center h-100">
                            <div class="fw-bold fs-5"><i class="bi bi-shield-lock text-warning me-1" style="font-size: 0.9rem;"></i><?= htmlspecialchars($repo['encryption'] ?: 'none') ?></div>
                            <div class="text-muted" style="font-size: 0.7rem;">Encryption</div>
                        </div>
                    </div>
                </div>

                <!-- Details -->
                <table class="table table-sm small mb-0">
                    <?php
                        $borgVer = $repo['borg_version_last'] ?: ($agentBorgVersion ?? null);
                    ?>
                    <?php if ($borgVer): ?>
                    <tr>
                        <td class="text-muted" style="width: 40%;"><i class="bi bi-box-seam me-1"></i>Borg Version</td>
                        <td><code><?= htmlspecialchars($borgVer) ?></code></td>
                    </tr>
                    <?php endif; ?>
                    <tr>
                        <td class="text-muted"><i class="bi bi-calendar-plus me-1"></i>Created</td>
                        <td><?= \BBS\Core\TimeHelper::format($repo['created_at'], 'M j, Y g:i A') ?></td>
                    </tr>
                    <?php if ($repo['encryption'] !== 'none' && !empty($repoPassphrase)): ?>
                    <tr>
                        <td class="text-muted"><i class="bi bi-key me-1"></i>Passphrase</td>
                        <td>
                            <code id="repoPassphrase" data-passphrase="<?= htmlspecialchars($repoPassphrase) ?>">*****</code>
                            <button type="button" class="btn btn-sm btn-link p-0 ms-2" id="togglePassphrase" title="Show/Hide passphrase">
                                <i class="bi bi-eye"></i>
                            </button>
                            <button type="button" class="btn btn-sm btn-link p-0 ms-1" id="copyPassphrase" title="Copy passphrase">
                                <i class="bi bi-clipboard"></i>
                            </button>
                        </td>
                    </tr>
                    <?php endif; ?>
                    <tr>
                        <td class="text-muted"><i class="bi bi-journal-text me-1"></i>Backup Plans</td>
                        <td>
                            <?php if (!empty($plans)): ?>
                                <?php foreach ($plans as $plan): ?>
                                <a href="/clients/<?= $agentId ?>?tab=schedules" class="text-decoration-none"><?= htmlspecialchars($plan['name']) ?></a><?php if ($plan !== end($plans)) echo ', '; ?>
                                <?php endforeach; ?>
                            <?php else: ?>
                            <span class="text-muted">None</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                </table>
            </div>
        </div>

        <!-- Recent Jobs -->
        <?php if (!empty($recentJobs)): ?>
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-body fw-semibold">
                <i class="bi bi-clock-history me-1"></i> Recent Jobs
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover table-sm small mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Time</th>
                                <th>Type</th>
                                <th>Status</th>
                                <th>Duration</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach (array_slice($recentJobs, 0, 10) as $job): ?>
                            <tr style="cursor: pointer;" onclick="window.location='/queue/<?= $job['id'] ?>'">
                                <td class="small" style="white-space: nowrap;">
                                    <?= $job['completed_at'] ? \BBS\Core\TimeHelper::ago($job['completed_at']) : \BBS\Core\TimeHelper::ago($job['queued_at']) ?>
                                </td>
                                <td><?= $job['task_type'] ?></td>
                                <td>
                                    <?php
                                    // Full class tokens with text-color pairings so warning/info
                                    // badges stay readable on light fills (#169).
                                    $statusBadge = match($job['status']) {
                                        'completed' => 'bg-success',
                                        'failed'    => 'bg-danger',
                                        'running'   => 'bg-info text-dark',
                                        'sent'      => 'bg-primary',
                                        'queued'    => 'bg-warning text-dark',
                                        default     => 'bg-secondary',
                                    };
                                    ?>
                                    <span class="badge <?= $statusBadge ?>"><?= $job['status'] ?></span>
                                </td>
                                <td>
                                    <?php
                                    $d = $job['duration_seconds'] ?? 0;
                                    echo $d > 0 ? ($d >= 60 ? floor($d / 60) . 'm ' . ($d % 60) . 's' : $d . 's') : '--';
                                    ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Maintenance Actions (Right) -->
    <div class="col-lg-6">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-body fw-semibold">
                <i class="bi bi-tools me-1"></i> Maintenance
            </div>
            <div class="card-body">
                <div class="d-flex flex-column gap-3">
                    <!-- Check -->
                    <div class="d-flex align-items-start gap-3 p-3 bg-body-secondary rounded">
                        <div class="text-primary" style="font-size: 1.5rem;">
                            <i class="bi bi-shield-check"></i>
                        </div>
                        <div class="flex-grow-1">
                            <h6 class="mb-1">Check Repository</h6>
                            <p class="text-muted small mb-2">Verify repository integrity by checking all archives for corruption or data errors. Safe to run anytime.</p>
                            <form method="POST" action="/repositories/<?= $repo['id'] ?>/maintenance" class="d-inline">
                                <input type="hidden" name="csrf_token" value="<?= $this->csrfToken() ?>">
                                <input type="hidden" name="action" value="check">
                                <button type="submit" class="btn btn-sm btn-outline-primary">
                                    <i class="bi bi-play-fill me-1"></i>Run Check
                                </button>
                            </form>
                        </div>
                    </div>

                    <!-- Compact -->
                    <div class="d-flex align-items-start gap-3 p-3 bg-body-secondary rounded">
                        <div class="text-success" style="font-size: 1.5rem;">
                            <i class="bi bi-arrows-collapse"></i>
                        </div>
                        <div class="flex-grow-1">
                            <h6 class="mb-1">Compact Repository</h6>
                            <p class="text-muted small mb-2">Reclaim disk space by removing unused data chunks after pruning archives. Runs automatically every Saturday at 2 AM.</p>
                            <form method="POST" action="/repositories/<?= $repo['id'] ?>/maintenance" class="d-inline">
                                <input type="hidden" name="csrf_token" value="<?= $this->csrfToken() ?>">
                                <input type="hidden" name="action" value="compact">
                                <button type="submit" class="btn btn-sm btn-outline-success">
                                    <i class="bi bi-play-fill me-1"></i>Run Compact
                                </button>
                            </form>
                        </div>
                    </div>

                    <!-- Rebuild Catalog -->
                    <div class="d-flex align-items-start gap-3 p-3 bg-body-secondary rounded">
                        <div class="text-info" style="font-size: 1.5rem;">
                            <i class="bi bi-database-gear"></i>
                        </div>
                        <div class="flex-grow-1">
                            <h6 class="mb-1">Rebuild Catalog</h6>
                            <p class="text-muted small mb-2">Re-scan recovery points to rebuild the browse catalog. <strong>Missing</strong> only indexes recovery points not yet in the catalog. <strong>Full</strong> drops all catalog data and re-indexes everything from scratch.</p>
                            <form method="POST" action="/repositories/<?= $repo['id'] ?>/maintenance" class="d-inline" data-confirm="Rebuild missing catalog entries for this repository?&#10;&#10;This will scan for recovery points not yet indexed and add them to the catalog. Already-indexed data is preserved.">
                                <input type="hidden" name="csrf_token" value="<?= $this->csrfToken() ?>">
                                <input type="hidden" name="action" value="catalog_rebuild">
                                <button type="submit" class="btn btn-sm btn-outline-info">
                                    <i class="bi bi-play-fill me-1"></i>Rebuild Missing
                                </button>
                            </form>
                            <form method="POST" action="/repositories/<?= $repo['id'] ?>/maintenance" class="d-inline ms-2" data-confirm="FULL catalog rebuild for this repository?&#10;&#10;This will DROP all existing catalog data for this client and re-scan every recovery point from scratch.&#10;&#10;This may take a long time for large repositories.">
                                <input type="hidden" name="csrf_token" value="<?= $this->csrfToken() ?>">
                                <input type="hidden" name="action" value="catalog_rebuild_full">
                                <button type="submit" class="btn btn-sm btn-outline-warning">
                                    <i class="bi bi-arrow-clockwise me-1"></i>Rebuild Full
                                </button>
                            </form>
                        </div>
                    </div>

                    <hr class="my-1">

                    <!-- Repair -->
                    <div class="d-flex align-items-start gap-3 p-3 bg-body-secondary rounded">
                        <div class="text-warning" style="font-size: 1.5rem;">
                            <i class="bi bi-bandaid"></i>
                        </div>
                        <div class="flex-grow-1">
                            <h6 class="mb-1">Repair Repository</h6>
                            <p class="text-muted small mb-2">Attempt to fix repository errors. <strong class="text-danger">Warning:</strong> May delete damaged data to restore consistency. Only use if Check reports errors.</p>
                            <form method="POST" action="/repositories/<?= $repo['id'] ?>/maintenance" class="d-inline" data-confirm="Run REPAIR on this repository?&#10;&#10;This may delete damaged data to restore consistency. Only proceed if Check reported errors." data-confirm-danger>
                                <input type="hidden" name="csrf_token" value="<?= $this->csrfToken() ?>">
                                <input type="hidden" name="action" value="repair">
                                <button type="submit" class="btn btn-sm btn-outline-warning">
                                    <i class="bi bi-play-fill me-1"></i>Run Repair
                                </button>
                            </form>
                        </div>
                    </div>

                    <!-- Break Lock -->
                    <div class="d-flex align-items-start gap-3 p-3 bg-body-secondary rounded">
                        <div class="text-danger" style="font-size: 1.5rem;">
                            <i class="bi bi-unlock"></i>
                        </div>
                        <div class="flex-grow-1">
                            <h6 class="mb-1">Break Lock</h6>
                            <p class="text-muted small mb-2">Forcibly remove stale locks from interrupted operations. <strong class="text-danger">Warning:</strong> Only use if you're certain no backup operations are currently running.</p>
                            <form method="POST" action="/repositories/<?= $repo['id'] ?>/maintenance" class="d-inline" data-confirm="BREAK LOCK on this repository?&#10;&#10;Only proceed if you're CERTAIN no backup operations are running." data-confirm-danger>
                                <input type="hidden" name="csrf_token" value="<?= $this->csrfToken() ?>">
                                <input type="hidden" name="action" value="break_lock">
                                <button type="submit" class="btn btn-sm btn-outline-danger">
                                    <i class="bi bi-play-fill me-1"></i>Break Lock
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Archives -->
<div class="card border-0 shadow-sm mt-4" id="archives-section">
    <div class="card-header bg-body fw-semibold d-flex justify-content-between align-items-center">
        <span><i class="bi bi-stack me-1"></i> Recovery Points (<?= $archiveCount ?>)</span>
    </div>
    <div class="card-body p-0">
        <?php if (empty($archives)): ?>
        <div class="p-3 text-muted small">No archives yet. Run a backup to create the first recovery point.</div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover mb-0 small">
                <thead class="table-light">
                    <tr>
                        <th>Archive</th>
                        <th>Date</th>
                        <th>Files</th>
                        <th>Original Size</th>
                        <th>Dedup Size</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($archives as $ar):
                        $origLabel = \BBS\Services\ServerStats::formatBytes((int) $ar['original_size']);
                        $dedupLabel = \BBS\Services\ServerStats::formatBytes((int) $ar['deduplicated_size']);
                    ?>
                    <tr style="cursor:pointer" onclick="window.location='/clients/<?= $repo['agent_id'] ?>/repo/<?= $repo['id'] ?>/archive/<?= $ar['id'] ?>'">
                        <td>
                            <a href="/clients/<?= $repo['agent_id'] ?>/repo/<?= $repo['id'] ?>/archive/<?= $ar['id'] ?>" class="text-decoration-none">
                            <?php if (!empty($ar['plan_name'])): ?>
                                <span class="fw-semibold"><?= htmlspecialchars($ar['plan_name']) ?></span>
                                <br><code class="small text-muted"><?= htmlspecialchars($ar['archive_name']) ?></code>
                            <?php else: ?>
                                <code class="small"><?= htmlspecialchars($ar['archive_name']) ?></code>
                            <?php endif; ?>
                            </a>
                            <?php if (!empty($ar['databases_backed_up'])): ?>
                            <i class="bi bi-database text-info ms-1" title="Contains database backup"></i>
                            <?php endif; ?>
                        </td>
                        <td class="text-nowrap"><?= \BBS\Core\TimeHelper::format($ar['created_at'], 'M j, Y g:i A') ?></td>
                        <td><?= number_format($ar['file_count']) ?></td>
                        <td><?= $origLabel ?></td>
                        <td><?= $dedupLabel ?></td>
                        <td class="text-end" onclick="event.stopPropagation()">
                            <button type="button" class="btn btn-sm btn-outline-danger"
                                    data-bs-toggle="modal" data-bs-target="#deleteArchiveModal<?= $ar['id'] ?>">
                                <i class="bi bi-trash"></i>
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php foreach ($archives as $ar): ?>
<div class="modal fade" id="deleteArchiveModal<?= $ar['id'] ?>" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header border-danger">
                <h5 class="modal-title text-danger"><i class="bi bi-exclamation-triangle me-2"></i>Delete Archive</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to permanently delete this archive? This action cannot be undone.</p>
                <table class="table table-sm small mb-0">
                    <tr><td class="text-muted" style="width:35%;">Client</td><td class="fw-semibold"><?= htmlspecialchars($repo['agent_name'] ?? '') ?></td></tr>
                    <tr><td class="text-muted">Repository</td><td class="fw-semibold"><?= htmlspecialchars($repo['name']) ?></td></tr>
                    <tr><td class="text-muted">Archive</td><td><code><?= htmlspecialchars($ar['archive_name']) ?></code></td></tr>
                    <tr><td class="text-muted">Created</td><td><?= \BBS\Core\TimeHelper::format($ar['created_at'], 'M j, Y g:i A') ?></td></tr>
                    <tr><td class="text-muted">Files</td><td><?= number_format($ar['file_count']) ?></td></tr>
                </table>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <form method="POST" action="/clients/<?= $agentId ?>/repo/<?= $repo['id'] ?>/archive/<?= $ar['id'] ?>/delete" class="d-inline">
                    <input type="hidden" name="csrf_token" value="<?= $this->csrfToken() ?>">
                    <button type="submit" class="btn btn-sm btn-danger"><i class="bi bi-trash me-1"></i>Delete Archive</button>
                </form>
            </div>
        </div>
    </div>
</div>
<?php endforeach; ?>

<!-- Danger Zone -->
<?php if ($this->isAdmin()): ?>
<hr class="mt-4 mb-0">
<div class="card border-0 shadow-sm mt-4 border-danger">
    <div class="card-header bg-body fw-semibold text-danger">
        <i class="bi bi-exclamation-triangle me-1"></i> Danger Zone
    </div>
    <div class="card-body">
        <div class="d-flex justify-content-between align-items-center">
            <div>
                <h6 class="mb-1">Delete Repository</h6>
                <p class="text-muted small mb-0">Permanently delete this repository and all its data. This cannot be undone.</p>
            </div>
            <?php
            $deleteBlocked = count($plans) > 0 || $activeJob;
            $blockReason = count($plans) > 0
                ? "Delete the " . count($plans) . " backup plan(s) using this repo first"
                : "Wait for active jobs to finish first";
            ?>
            <?php if ($deleteBlocked): ?>
            <button class="btn btn-outline-danger" disabled title="<?= htmlspecialchars($blockReason) ?>">
                <i class="bi bi-trash me-1"></i>Delete
            </button>
            <?php else: ?>
            <form method="POST" action="/repositories/<?= $repo['id'] ?>/delete" id="deleteRepoForm">
                <input type="hidden" name="csrf_token" value="<?= $this->csrfToken() ?>">
                <?php if (($repo['storage_type'] ?? 'local') !== 'remote_ssh' && $s3SyncInfo): ?>
                <div class="form-check mb-2">
                    <input class="form-check-input" type="checkbox" name="delete_from_s3" id="deleteFromS3" value="1">
                    <label class="form-check-label small" for="deleteFromS3">
                        <i class="bi bi-cloud text-info me-1"></i>Also delete from S3 offsite storage
                    </label>
                    <input type="hidden" name="plugin_config_id" value="<?= $s3SyncInfo['plugin_config_id'] ?>">
                </div>
                <?php endif; ?>
                <button type="submit" class="btn btn-outline-danger">
                    <i class="bi bi-trash me-1"></i>Delete Repository
                </button>
            </form>
            <script>
            document.getElementById('deleteRepoForm').addEventListener('submit', function(e) {
                var deleteS3 = document.getElementById('deleteFromS3');
                var msg = 'PERMANENTLY delete repository "<?= htmlspecialchars($repo['name'], ENT_QUOTES) ?>", all its archives, and the data on disk?';
                if (deleteS3 && deleteS3.checked) {
                    msg += '\n\nThis will ALSO delete the offsite copy from S3!';
                }
                msg += '\n\nThis action is NOT reversible.';
                if (!confirm(msg)) {
                    e.preventDefault();
                }
            });
            </script>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if (!empty($repoPassphrase)): ?>
<script>
(function() {
    var el = document.getElementById('repoPassphrase');
    var toggleBtn = document.getElementById('togglePassphrase');
    var copyBtn = document.getElementById('copyPassphrase');
    if (!el || !toggleBtn) return;
    var visible = false;

    toggleBtn.addEventListener('click', function() {
        visible = !visible;
        el.textContent = visible ? el.dataset.passphrase : '*****';
        toggleBtn.querySelector('i').className = visible ? 'bi bi-eye-slash' : 'bi bi-eye';
    });

    if (copyBtn) {
        copyBtn.addEventListener('click', function() {
            navigator.clipboard.writeText(el.dataset.passphrase).then(function() {
                var icon = copyBtn.querySelector('i');
                icon.className = 'bi bi-check';
                setTimeout(function() { icon.className = 'bi bi-clipboard'; }, 1500);
            });
        });
    }
})();

// Rename toggle
var renameToggle = document.getElementById('renameToggle');
var renameForm = document.getElementById('renameForm');
var renameCancel = document.getElementById('renameCancel');
if (renameToggle && renameForm) {
    renameToggle.addEventListener('click', function() {
        renameForm.classList.remove('d-none');
        renameToggle.classList.add('d-none');
        renameForm.querySelector('input[name="name"]').focus();
    });
    if (renameCancel) {
        renameCancel.addEventListener('click', function() {
            renameForm.classList.add('d-none');
            renameToggle.classList.remove('d-none');
        });
    }
}
</script>
<?php endif; ?>
