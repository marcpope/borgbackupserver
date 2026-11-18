<?php
/**
 * Backups tab of a hypervisor: VM backup plans, recent VM jobs, new plan form.
 * Included from hypervisors/detail.php; $backups comes from
 * HypervisorController::backupsTabData().
 */
$hid = (int) $hypervisor['id'];
$days = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
$scheduleLabel = function (array $p) use ($days) {
    switch ($p['frequency'] ?? 'manual') {
        case 'hourly': return 'Every hour';
        case 'daily':  return 'Daily at ' . ($p['times'] ?: '?');
        case 'weekly': return 'Weekly, ' . ($days[(int) ($p['day_of_week'] ?? 0)] ?? '?') . ' at ' . ($p['times'] ?: '?');
        default:       return 'Manual only';
    }
};
$statusClass = fn($s) => match ($s) {
    'completed' => 'success', 'failed' => 'danger', 'running', 'sent' => 'primary', 'queued' => 'secondary', default => 'warning',
};
$vmLabel = fn($ref) => $ref === null || $ref === '' ? '--'
    : htmlspecialchars($ref . (isset($backups['vmNames'][$ref]) ? ' · ' . $backups['vmNames'][$ref] : ''));
$backupVms = array_values(array_filter($vms, fn($v) => !$v['is_template']));
?>

<?php if (empty($backups['repositories'])): ?>
<div class="alert alert-warning">
    VM backups need a repository on this hypervisor's agent.
    <a href="/clients/<?= (int) $hypervisor['agent_id'] ?>?tab=repos" class="alert-link">Create a repository</a>, then come back here.
</div>
<?php endif; ?>

<div class="card border-0 shadow-sm mb-3">
    <div class="card-header fw-semibold"><i class="bi bi-calendar-check me-1"></i>VM backup plans</div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table mb-0 small align-middle">
                <thead class="table-light">
                    <tr><th>Plan</th><th>Repository</th><th>VMs</th><th>Schedule</th><th>Retention</th><th class="text-end">Actions</th></tr>
                </thead>
                <tbody>
                    <?php if (empty($backups['plans'])): ?>
                    <tr><td colspan="6" class="text-center text-muted py-3">No VM backup plan yet. Create one below.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($backups['plans'] as $p): ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($p['name']) ?></strong></td>
                        <td><?= htmlspecialchars($p['repo_name']) ?></td>
                        <td>
                            <?php if ($p['vm_selection'] === 'all'): ?><span class="badge bg-info-subtle text-info-emphasis fw-normal me-1">all VMs</span><?php endif; ?>
                            <?php foreach ($p['vms'] as $v): ?>
                                <?php $ex = $p['vm_rows'][$v['vm_ref']]['exclude_disks'] ?? []; ?>
                                <span class="badge bg-body-secondary text-body border fw-normal me-1 mb-1"
                                      title="<?= $ex ? htmlspecialchars('Excluded disks: ' . implode(', ', $ex)) : 'All supported disks' ?>">
                                    <?= htmlspecialchars($v['vm_ref'] . ' · ' . $v['name']) ?><?= $ex ? ' <i class="bi bi-dash-circle"></i>' : '' ?>
                                </span>
                            <?php endforeach; ?>
                            <?php if (empty($p['vms'])): ?><span class="text-muted">none</span><?php endif; ?>
                        </td>
                        <td>
                            <?= htmlspecialchars($scheduleLabel($p)) ?>
                            <?php if (!empty($p['next_run']) && !empty($p['schedule_enabled'])): ?>
                                <div class="text-muted">next: <?= htmlspecialchars(\BBS\Core\TimeHelper::format($p['next_run'], 'Y-m-d H:i')) ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="text-nowrap"><?= (int) $p['prune_days'] ?> d · <?= (int) $p['prune_weeks'] ?> w · <?= (int) $p['prune_months'] ?> m <span class="text-muted">per VM</span></td>
                        <td class="text-end text-nowrap">
                            <?php if ($backups['canTrigger']): ?>
                            <form method="POST" action="/plans/<?= (int) $p['id'] ?>/trigger" class="d-inline">
                                <input type="hidden" name="csrf_token" value="<?= $this->csrfToken() ?>">
                                <button class="btn btn-sm btn-success"><i class="bi bi-play-fill me-1"></i>Run now</button>
                            </form>
                            <?php endif; ?>
                            <?php if ($backups['canManage']): ?>
                            <form method="POST" action="/plans/<?= (int) $p['id'] ?>/delete" class="d-inline">
                                <input type="hidden" name="csrf_token" value="<?= $this->csrfToken() ?>">
                                <button class="btn btn-sm btn-outline-danger" data-confirm="Delete the plan &quot;<?= htmlspecialchars($p['name']) ?>&quot;? Its archives stay in the repository.">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="card border-0 shadow-sm mb-3">
    <div class="card-header fw-semibold"><i class="bi bi-clock-history me-1"></i>Recent VM jobs</div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0 small align-middle">
                <thead class="table-light">
                    <tr><th>Job</th><th>VM</th><th>Plan</th><th>Type</th><th>Status</th><th>Size <span class="fw-normal text-muted">(original / new)</span></th><th>Duration</th><th>Finished</th></tr>
                </thead>
                <tbody>
                    <?php if (empty($backups['jobs'])): ?>
                    <tr><td colspan="8" class="text-center text-muted py-3">No VM job yet.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($backups['jobs'] as $j): ?>
                    <tr style="cursor:pointer" onclick="window.location='/queue/<?= (int) $j['id'] ?>'">
                        <td>#<?= (int) $j['id'] ?></td>
                        <td><?= $vmLabel($j['vm_ref']) ?></td>
                        <td><?= htmlspecialchars($j['plan_name']) ?></td>
                        <td><?= htmlspecialchars($j['task_type']) ?></td>
                        <td>
                            <span class="badge bg-<?= $statusClass($j['status']) ?>"><?= htmlspecialchars($j['status']) ?></span>
                            <?php if (!empty($j['error_log']) && $j['status'] === 'failed'): ?>
                                <div class="text-danger text-truncate" style="max-width: 22rem;" title="<?= htmlspecialchars($j['error_log']) ?>"><?= htmlspecialchars($j['error_log']) ?></div>
                            <?php elseif (!empty($j['status_message']) && in_array($j['status'], ['sent', 'running'], true)): ?>
                                <div class="text-muted"><?= htmlspecialchars($j['status_message']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="text-nowrap">
                            <?= $j['original_size'] !== null ? \BBS\Services\ServerStats::formatBytes((int) $j['original_size']) . ' / ' . \BBS\Services\ServerStats::formatBytes((int) $j['deduplicated_size']) : '--' ?>
                        </td>
                        <td><?= $j['duration_seconds'] !== null ? gmdate($j['duration_seconds'] >= 3600 ? 'G\h i\m' : 'i\m s\s', (int) $j['duration_seconds']) : '--' ?></td>
                        <td><?= !empty($j['completed_at']) ? \BBS\Core\TimeHelper::ago($j['completed_at']) : '--' ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php if ($backups['canManage'] && !empty($backups['repositories'])): ?>
<div class="card border-0 shadow-sm">
    <div class="card-header fw-semibold"><i class="bi bi-plus-circle me-1"></i>New VM backup plan</div>
    <div class="card-body">
        <form method="POST" action="/hypervisors/<?= $hid ?>/plans">
            <input type="hidden" name="csrf_token" value="<?= $this->csrfToken() ?>">
            <div class="row g-3 mb-3">
                <div class="col-md-5">
                    <label class="form-label fw-semibold small" for="vmPlanName">Name</label>
                    <input type="text" class="form-control form-control-sm" id="vmPlanName" name="name" required maxlength="100" placeholder="e.g. Nightly VMs">
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold small" for="vmPlanRepo">Repository</label>
                    <select class="form-select form-select-sm" id="vmPlanRepo" name="repository_id" required>
                        <?php foreach ($backups['repositories'] as $r): ?>
                            <option value="<?= (int) $r['id'] ?>"><?= htmlspecialchars($r['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="mb-2 small fw-semibold">Virtual machines</div>
            <div class="mb-2 small">
                <div class="form-check form-check-inline">
                    <input class="form-check-input" type="radio" name="vm_selection" id="selSelected" value="selected" checked>
                    <label class="form-check-label" for="selSelected">Only the ticked VMs</label>
                </div>
                <div class="form-check form-check-inline">
                    <input class="form-check-input" type="radio" name="vm_selection" id="selAll" value="all">
                    <label class="form-check-label" for="selAll">All VMs except the unticked ones <span class="text-muted">(VMs created later are included)</span></label>
                </div>
            </div>
            <div class="border rounded mb-3">
                <?php if (empty($backupVms)): ?>
                    <div class="text-muted small p-2">No VM in the inventory yet.</div>
                <?php endif; ?>
                <?php foreach ($backupVms as $vm): ?>
                    <?php $ref = htmlspecialchars($vm['vm_ref']); ?>
                    <div class="d-flex flex-wrap align-items-center gap-2 px-2 py-1 border-bottom small">
                        <div class="form-check mb-0" style="min-width: 14rem;">
                            <input class="form-check-input" type="checkbox" name="vms[<?= $ref ?>]" value="1" id="vm<?= $ref ?>" checked>
                            <label class="form-check-label fw-semibold" for="vm<?= $ref ?>"><?= $ref ?> · <?= htmlspecialchars($vm['name']) ?></label>
                            <span class="badge bg-<?= $vm['status'] === 'running' ? 'success' : 'secondary' ?> ms-1"><?= htmlspecialchars($vm['status']) ?></span>
                        </div>
                        <?php foreach ($vm['disks'] as $d): ?>
                            <?php if (in_array($d['kind'], ['cdrom', 'cloudinit', 'unused'], true)) continue; ?>
                            <?php $dk = htmlspecialchars($d['key']); $size = !empty($d['size_bytes']) ? ' · ' . \BBS\Services\ServerStats::formatBytes((int) $d['size_bytes']) : ''; ?>
                            <?php if (!empty($d['supported'])): ?>
                                <div class="form-check form-check-inline mb-0">
                                    <input class="form-check-input" type="checkbox" name="disks[<?= $ref ?>][]" value="<?= $dk ?>" id="d<?= $ref ?>_<?= $dk ?>"
                                           <?= empty($d['default_excluded']) ? 'checked' : '' ?> <?= in_array($d['kind'], ['efi', 'tpm'], true) ? 'title="Needed to boot the VM: keep it"' : '' ?>>
                                    <label class="form-check-label" for="d<?= $ref ?>_<?= $dk ?>"><?= $dk ?><?= $d['kind'] !== 'disk' ? ' (' . htmlspecialchars(strtoupper($d['kind'])) . ')' : '' ?><?= $size ?></label>
                                </div>
                            <?php else: ?>
                                <span class="text-muted" title="<?= htmlspecialchars($d['unsupported_reason'] ?? 'not supported') ?>">
                                    <i class="bi bi-exclamation-triangle text-warning"></i> <?= $dk ?><?= $size ?> — <?= htmlspecialchars($d['unsupported_reason'] ?? 'not supported') ?>
                                </span>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="row g-3 mb-3">
                <div class="col-md-3">
                    <label class="form-label fw-semibold small" for="vmFreq">Schedule</label>
                    <select class="form-select form-select-sm" id="vmFreq" name="frequency">
                        <option value="daily" selected>Daily</option>
                        <option value="weekly">Weekly</option>
                        <option value="hourly">Hourly</option>
                        <option value="manual">Manual only</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-semibold small" for="vmTime">At</label>
                    <input type="time" class="form-control form-control-sm" id="vmTime" name="time" value="01:00">
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold small" for="vmDow">Day (weekly)</label>
                    <select class="form-select form-select-sm" id="vmDow" name="day_of_week">
                        <?php foreach ($days as $i => $dn): ?><option value="<?= $i ?>"><?= $dn ?></option><?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="row g-3 mb-3">
                <div class="col-auto">
                    <label class="form-label fw-semibold small">Keep, per VM</label>
                    <div class="input-group input-group-sm">
                        <input type="number" class="form-control" name="prune_days" value="7" min="0" max="1000" style="width: 5rem;">
                        <span class="input-group-text">daily</span>
                        <input type="number" class="form-control" name="prune_weeks" value="4" min="0" max="1000" style="width: 5rem;">
                        <span class="input-group-text">weekly</span>
                        <input type="number" class="form-control" name="prune_months" value="6" min="0" max="1000" style="width: 5rem;">
                        <span class="input-group-text">monthly</span>
                    </div>
                </div>
            </div>
            <div class="small text-muted mb-3">
                Each VM is backed up as its own job and archive. With the QEMU guest agent enabled and answering,
                the guest filesystems are frozen while the disks are snapshotted (a second or two); without it the
                backup is crash-consistent.
            </div>
            <button type="submit" class="btn btn-sm btn-success"><i class="bi bi-plus-circle me-1"></i>Create plan</button>
        </form>
    </div>
</div>
<?php endif; ?>
