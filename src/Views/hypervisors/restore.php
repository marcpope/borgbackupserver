<?php
/**
 * Restore tab of a hypervisor: restore points per VM and the full VM
 * restore form, plus recent VM restores. Included from hypervisors/detail.php;
 * $restore comes from HypervisorController::restoreTabData().
 */
$hid = (int) $hypervisor['id'];
$statusClass = fn($s) => match ($s) {
    'completed' => 'success', 'failed' => 'danger', 'running', 'sent' => 'primary', 'queued' => 'secondary', default => 'warning',
};
$fmt = fn($b) => \BBS\Services\ServerStats::formatBytes((int) $b);
?>

<div class="alert alert-info small">
    A full restore creates a <strong>new VM</strong> from the restore point: new VMID, new disks on the storage you
    choose, new MAC addresses and, unless you tick "Network connected", network cards unplugged. The original VM is
    never touched. Disks excluded from the backup are left out of the restored VM.
    <strong>Browse files</strong> opens a restore point to pick files or folders inside the VM and download them as a
    tar.gz, without restoring the VM.
</div>

<?php foreach ($restore['browseSessions'] ?? [] as $bs): ?>
<?php $dur = fn($s) => \BBS\Services\Hypervisors\HypervisorBackupService::shortDuration((int) $s); ?>
<div class="alert alert-warning small d-flex flex-wrap align-items-center gap-2">
    <i class="bi bi-lock-fill"></i>
    <span>
        Repository <strong><?= htmlspecialchars($bs['repo_name'] ?? '?') ?></strong> locked for
        <strong><?= $dur($bs['locked_seconds']) ?></strong> by the file browse session of
        VM <?= htmlspecialchars($bs['vm_ref'] ?? '?') ?>
        <?php if (!empty($bs['archive_created_at'])): ?>(restore point <?= htmlspecialchars(\BBS\Core\TimeHelper::format($bs['archive_created_at'], 'Y-m-d H:i')) ?>)<?php endif; ?>.
        Idle for <?= $dur($bs['idle_seconds']) ?>: closes by itself in <?= $dur($bs['closes_in_seconds']) ?>.
        Backups and restores of this hypervisor close it first; prune or compact runs on the server wait for it.
    </span>
    <span class="ms-auto d-flex gap-2">
        <?php if (!empty($bs['archive_name'])): ?>
        <a class="btn btn-sm btn-outline-secondary" href="/hypervisors/<?= $hid ?>/browse/<?= (int) $bs['archive_id'] ?>">Open</a>
        <?php endif; ?>
        <form method="POST" action="/hypervisors/<?= $hid ?>/browse-close/<?= (int) $bs['archive_id'] ?>" class="d-inline">
            <input type="hidden" name="csrf_token" value="<?= $this->csrfToken() ?>">
            <button class="btn btn-sm btn-warning" type="submit"><i class="bi bi-unlock me-1"></i>Close session</button>
        </form>
    </span>
</div>
<?php endforeach; ?>

<?php if (empty($restore['byVm'])): ?>
    <div class="card border-0 shadow-sm"><div class="card-body text-muted">No VM restore point yet. Back up a VM from the Backups tab first.</div></div>
<?php endif; ?>

<?php foreach ($restore['byVm'] as $ref => $points): ?>
<div class="card border-0 shadow-sm mb-3">
    <div class="card-header fw-semibold">
        <i class="bi bi-pc-display me-1"></i>VM <?= htmlspecialchars($ref) ?>
        <?php if (isset($restore['vmNames'][$ref])): ?>· <?= htmlspecialchars($restore['vmNames'][$ref]) ?><?php else: ?><span class="badge bg-secondary ms-1">no longer on the hypervisor</span><?php endif; ?>
        <span class="text-muted fw-normal small ms-2"><?= count($points) ?> restore point<?= count($points) > 1 ? 's' : '' ?></span>
    </div>
    <div class="card-body p-0">
        <table class="table mb-0 small align-middle">
            <thead class="table-light"><tr><th>Restore point</th><th>Repository</th><th>VM size</th><th>New data</th><th class="text-end"></th></tr></thead>
            <tbody>
            <?php foreach ($points as $a): ?>
                <tr>
                    <td><?= htmlspecialchars(\BBS\Core\TimeHelper::format($a['created_at'], 'Y-m-d H:i')) ?> <span class="text-muted">(<?= \BBS\Core\TimeHelper::ago($a['created_at']) ?>)</span></td>
                    <td><?= htmlspecialchars($a['repo_name']) ?></td>
                    <td><?= $fmt($a['original_size']) ?></td>
                    <td><?= $fmt($a['deduplicated_size']) ?></td>
                    <td class="text-end text-nowrap">
                        <?php if ($restore['canBrowse']): ?>
                        <a class="btn btn-sm btn-outline-secondary" href="/hypervisors/<?= $hid ?>/browse/<?= (int) $a['id'] ?>">
                            <i class="bi bi-folder2-open me-1"></i>Browse files
                        </a>
                        <?php endif; ?>
                        <?php if ($restore['canRestore'] && !empty($restore['storages'])): ?>
                        <button class="btn btn-sm btn-outline-primary" type="button" data-bs-toggle="collapse" data-bs-target="#restore<?= (int) $a['id'] ?>">
                            <i class="bi bi-arrow-counterclockwise me-1"></i>Restore…
                        </button>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php if ($restore['canRestore'] && !empty($restore['storages'])): ?>
                <tr class="collapse" id="restore<?= (int) $a['id'] ?>">
                    <td colspan="5" class="bg-body-tertiary">
                        <form method="POST" action="/hypervisors/<?= $hid ?>/restore" class="row g-2 align-items-end">
                            <input type="hidden" name="csrf_token" value="<?= $this->csrfToken() ?>">
                            <input type="hidden" name="archive_id" value="<?= (int) $a['id'] ?>">
                            <div class="col-auto">
                                <label class="form-label small mb-1">New VMID</label>
                                <input type="number" class="form-control form-control-sm" name="target_vmid" min="100" placeholder="next free" style="width: 8rem;">
                            </div>
                            <div class="col-auto">
                                <label class="form-label small mb-1">Name</label>
                                <input type="text" class="form-control form-control-sm" name="name" maxlength="63"
                                       placeholder="<?= htmlspecialchars(($restore['vmNames'][$ref] ?? 'vm' . $ref) . '-restored') ?>">
                            </div>
                            <div class="col-auto">
                                <label class="form-label small mb-1">Storage</label>
                                <select class="form-select form-select-sm" name="storage">
                                    <?php foreach ($restore['storages'] as $s): ?>
                                        <option value="<?= htmlspecialchars($s['id']) ?>"><?= htmlspecialchars($s['id'] . ' (' . $s['type'] . ')') ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-auto">
                                <div class="form-check small mb-1">
                                    <input class="form-check-input" type="checkbox" name="network_up" value="1" id="net<?= (int) $a['id'] ?>">
                                    <label class="form-check-label" for="net<?= (int) $a['id'] ?>">Network connected</label>
                                </div>
                                <div class="form-check small mb-1">
                                    <input class="form-check-input" type="checkbox" name="start" value="1" id="start<?= (int) $a['id'] ?>">
                                    <label class="form-check-label" for="start<?= (int) $a['id'] ?>">Start after restore</label>
                                </div>
                            </div>
                            <div class="col-auto">
                                <button type="submit" class="btn btn-sm btn-primary"
                                        data-confirm="Restore VM <?= htmlspecialchars($ref) ?> from <?= htmlspecialchars(\BBS\Core\TimeHelper::format($a['created_at'], 'Y-m-d H:i')) ?> as a new VM?">
                                    Restore as new VM
                                </button>
                            </div>
                        </form>
                    </td>
                </tr>
                <?php endif; ?>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endforeach; ?>

<?php if (!empty($restore['byVm']) && empty($restore['storages'])): ?>
<div class="alert alert-warning small">This hypervisor reports no storage a VM can be restored to (supported: LVM-thin).</div>
<?php endif; ?>

<div class="card border-0 shadow-sm">
    <div class="card-header fw-semibold"><i class="bi bi-clock-history me-1"></i>Recent VM restores</div>
    <div class="card-body p-0">
        <table class="table table-hover mb-0 small align-middle">
            <thead class="table-light"><tr><th>Job</th><th>Source VM</th><th>Target</th><th>Status</th><th>Duration</th><th>Finished</th></tr></thead>
            <tbody>
            <?php if (empty($restore['jobs'])): ?>
                <tr><td colspan="6" class="text-center text-muted py-3">No VM restore yet.</td></tr>
            <?php endif; ?>
            <?php foreach ($restore['jobs'] as $j): ?>
                <?php $o = json_decode($j['restore_options'] ?? '{}', true) ?: []; ?>
                <tr style="cursor:pointer" onclick="window.location='/queue/<?= (int) $j['id'] ?>'">
                    <td>#<?= (int) $j['id'] ?></td>
                    <td><?= htmlspecialchars($j['vm_ref'] ?? '--') ?></td>
                    <td><?= !empty($o['vmid']) ? 'VMID ' . (int) $o['vmid'] : 'next free VMID' ?> · <?= htmlspecialchars($o['storage'] ?? '?') ?></td>
                    <td>
                        <span class="badge bg-<?= $statusClass($j['status']) ?>"><?= htmlspecialchars($j['status']) ?></span>
                        <?php if ($j['status'] === 'failed' && !empty($j['error_log'])): ?>
                            <div class="text-danger text-truncate" style="max-width: 26rem;" title="<?= htmlspecialchars($j['error_log']) ?>"><?= htmlspecialchars($j['error_log']) ?></div>
                        <?php elseif (in_array($j['status'], ['sent', 'running'], true) && !empty($j['status_message'])): ?>
                            <div class="text-muted"><?= htmlspecialchars($j['status_message']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td><?= $j['duration_seconds'] !== null ? gmdate($j['duration_seconds'] >= 3600 ? 'G\h i\m' : 'i\m s\s', (int) $j['duration_seconds']) : '--' ?></td>
                    <td><?= !empty($j['completed_at']) ? \BBS\Core\TimeHelper::ago($j['completed_at']) : '--' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
