<?php
$fmtBytes = fn($b) => $b ? \BBS\Services\ServerStats::formatBytes((int) $b) : '--';
$fmtUptime = function ($s) {
    $s = (int) $s;
    if ($s <= 0) return '--';
    $d = intdiv($s, 86400);
    $h = intdiv($s % 86400, 3600);
    $m = intdiv($s % 3600, 60);
    return $d > 0 ? "{$d}d {$h}h" : ($h > 0 ? "{$h}h {$m}m" : "{$m}m");
};
$kindLabels = [
    'disk' => 'disk', 'efi' => 'EFI', 'tpm' => 'TPM', 'cdrom' => 'CD-ROM',
    'cloudinit' => 'cloud-init', 'passthrough' => 'passthrough', 'unused' => 'unused',
];
$running = count(array_filter($vms, fn($v) => $v['status'] === 'running'));
$idLabel = $connector ? $connector->vmIdLabel() : 'ID';
$hostLabel = $connector ? $connector->hostLabel() : 'Host';
$hosts = array_unique(array_filter(array_column($vms, 'host')));
$showHost = count($hosts) > 1; // a single-node hypervisor needs no host column
?>
<div class="mb-3">
    <a href="/hypervisors" class="text-decoration-none small"><i class="bi bi-arrow-left me-1"></i>Hypervisors</a>
</div>

<div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
    <div>
        <h5 class="mb-1">
            <i class="bi bi-<?= htmlspecialchars($connector ? $connector->icon() : 'hdd-rack') ?> me-2"></i><?= htmlspecialchars($hypervisor['name']) ?>
            <span class="badge bg-body-secondary text-body border fw-normal fs-6 ms-1"><?= htmlspecialchars($connector ? $connector->label() : $hypervisor['type']) ?></span>
        </h5>
        <div class="text-muted small">
            <?php foreach (($connector ? $connector->describeInfo($info) : []) as $label => $value): ?>
                <?= htmlspecialchars($label) ?> <strong><?= htmlspecialchars($value) ?></strong> ·
            <?php endforeach; ?>
            <?= $running ?> / <?= count($vms) ?> VMs running ·
            inventory <?= !empty($hypervisor['inventory_at']) ? \BBS\Core\TimeHelper::ago($hypervisor['inventory_at']) : 'never' ?>
            <?php if (!empty($hypervisor['refresh_requested'])): ?>(refresh pending)<?php endif; ?>
        </div>
    </div>
    <div class="d-flex gap-2">
        <form method="POST" action="/hypervisors/<?= (int) $hypervisor['id'] ?>/refresh">
            <input type="hidden" name="csrf_token" value="<?= $this->csrfToken() ?>">
            <button type="submit" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-clockwise me-1"></i>Refresh</button>
        </form>
        <a href="/clients/<?= (int) $hypervisor['agent_id'] ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-gear me-1"></i>Agent, repositories &amp; settings</a>
    </div>
</div>

<ul class="nav nav-tabs mb-3">
    <li class="nav-item">
        <a class="nav-link <?= $tab === 'vms' ? 'active' : '' ?>" href="/hypervisors/<?= (int) $hypervisor['id'] ?>"><i class="bi bi-pc-display me-1"></i>Virtual machines</a>
    </li>
    <?php if ($connector && $connector->can('backup')): ?>
    <li class="nav-item">
        <a class="nav-link <?= $tab === 'backups' ? 'active' : '' ?>" href="/hypervisors/<?= (int) $hypervisor['id'] ?>?tab=backups"><i class="bi bi-archive me-1"></i>Backups</a>
    </li>
    <?php endif; ?>
    <?php if ($isOwnerOrAdmin): ?>
    <li class="nav-item">
        <a class="nav-link <?= $tab === 'install' ? 'active' : '' ?>" href="/hypervisors/<?= (int) $hypervisor['id'] ?>?tab=install"><i class="bi bi-download me-1"></i>Install agent</a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $tab === 'settings' ? 'active' : '' ?>" href="/hypervisors/<?= (int) $hypervisor['id'] ?>?tab=settings"><i class="bi bi-sliders me-1"></i>Settings</a>
    </li>
    <?php endif; ?>
</ul>

<?php if ($tab === 'backups'): ?>
<?php require __DIR__ . '/backups.php'; ?>
<?php elseif ($tab === 'settings'): ?>
<div class="card border-0 shadow-sm" style="max-width: 40rem;">
    <div class="card-body">
        <form method="POST" action="/hypervisors/<?= (int) $hypervisor['id'] ?>/settings">
            <input type="hidden" name="csrf_token" value="<?= $this->csrfToken() ?>">
            <div class="mb-3">
                <label class="form-label fw-semibold" for="hvName">Name</label>
                <input type="text" class="form-control" id="hvName" name="name" required maxlength="100"
                       value="<?= htmlspecialchars($hypervisor['name']) ?>">
            </div>
            <div class="mb-3">
                <label class="form-label fw-semibold" for="hvInterval">VM inventory interval</label>
                <div class="input-group" style="max-width: 14rem;">
                    <input type="number" class="form-control" id="hvInterval" name="inventory_interval_minutes"
                           min="1" max="60" required value="<?= (int) $hypervisor['inventory_interval_minutes'] ?>">
                    <span class="input-group-text">minutes</span>
                </div>
                <div class="form-text">
                    How often the agent reports the virtual machines (1 to 60 minutes). The inventory keeps
                    updating while backups run; Refresh asks for one within 30 seconds.
                </div>
            </div>
            <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Save</button>
        </form>
    </div>
</div>
<?php elseif ($tab === 'install'): ?>
<div class="card border-0 shadow-sm">
    <div class="card-body">
        <?php if ($installCmd): ?>
            <div class="d-flex justify-content-between align-items-center mb-2">
                <p class="mb-0">Run this command in a root shell on the hypervisor (the node's Shell in the Proxmox web UI is one) to install the BBS hypervisor agent:</p>
                <button class="btn btn-sm btn-outline-secondary" type="button"
                        onclick="BBS.copyText(document.getElementById('installCmdHv').textContent.trim()); this.innerHTML='<i class=\'bi bi-check\'></i> Copied'; setTimeout(() => this.innerHTML='<i class=\'bi bi-clipboard\'></i> Copy', 2000)">
                    <i class="bi bi-clipboard"></i> Copy
                </button>
            </div>
            <div class="bg-dark text-white p-3 rounded" style="font-family: monospace; font-size: 0.9rem; word-break: break-all;" id="installCmdHv">
                <?= htmlspecialchars($installCmd) ?>
            </div>
            <div class="small text-muted mt-3">
                This installs the BBS agent with its hypervisor module (<code>--hypervisor</code>).
                It shows up as online here within a minute, then lists the virtual machines.
                Behind a self-signed certificate, add <code>--cacert /path/to/ca.pem</code> as for a client.
            </div>
        <?php else: ?>
            <div class="text-muted">The API key of this hypervisor's agent cannot be read. Replace it from the agent's settings page.</div>
        <?php endif; ?>
    </div>
</div>
<?php else: ?>
<div class="card border-0 shadow-sm">
    <div class="card-header fw-semibold"><i class="bi bi-pc-display me-1"></i>Virtual machines</div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0 small align-middle">
                <thead class="table-light">
                    <tr>
                        <th><?= htmlspecialchars($idLabel) ?></th>
                        <th>Name</th>
                        <?php if ($showHost): ?><th><?= htmlspecialchars($hostLabel) ?></th><?php endif; ?>
                        <th>Status</th>
                        <th>CPU / RAM</th>
                        <th>Disks</th>
                        <th title="Guest tools / QEMU guest agent: filesystems can be frozen for a consistent backup">Guest tools</th>
                        <th>Uptime</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($vms)): ?>
                    <tr><td colspan="<?= $showHost ? 8 : 7 ?>" class="text-center text-muted py-4">
                        No virtual machine reported yet<?= empty($hypervisor['agent_version']) ? ' — install the agent first (Install agent tab)' : '' ?>.
                    </td></tr>
                    <?php endif; ?>
                    <?php foreach ($vms as $vm): ?>
                    <tr class="<?= $vm['is_template'] ? 'text-muted' : '' ?>">
                        <td><?= htmlspecialchars($vm['vm_ref']) ?></td>
                        <td>
                            <strong><?= htmlspecialchars($vm['name']) ?></strong>
                            <?php if ($vm['is_template']): ?><span class="badge bg-body-secondary text-body border fw-normal ms-1">template</span><?php endif; ?>
                            <?php if (!empty($vm['tags'])): foreach (preg_split('/[;,\s]+/', $vm['tags'], -1, PREG_SPLIT_NO_EMPTY) as $tag): ?>
                                <span class="badge bg-info-subtle text-info-emphasis fw-normal ms-1"><?= htmlspecialchars($tag) ?></span>
                            <?php endforeach; endif; ?>
                        </td>
                        <?php if ($showHost): ?><td><?= htmlspecialchars($vm['host']) ?></td><?php endif; ?>
                        <td>
                            <?php $sc = match($vm['status']) { 'running' => 'success', 'stopped' => 'secondary', 'paused', 'suspended' => 'warning', default => 'light text-dark' }; ?>
                            <span class="badge bg-<?= $sc ?>"><?= htmlspecialchars($vm['status']) ?></span>
                        </td>
                        <td class="text-nowrap"><?= $vm['cpus'] ? (int) $vm['cpus'] . ' vCPU' : '--' ?> / <?= $fmtBytes($vm['memory_bytes']) ?></td>
                        <td>
                            <?php foreach ($vm['disks'] as $d): ?>
                                <?php
                                $ignored = in_array($d['kind'], ['cdrom', 'cloudinit', 'unused'], true);
                                $title = $d['path'] . (!empty($d['datastore_type']) ? ' (' . $d['datastore_type'] . ')' : '');
                                if (!$ignored && empty($d['supported'])) {
                                    $title .= ' — not backed up: ' . ($d['unsupported_reason'] ?? 'not supported');
                                }
                                if (!empty($d['default_excluded'])) $title .= ' — excluded from backup on the hypervisor';
                                $cls = $ignored ? 'bg-body-secondary text-muted' : (!empty($d['supported']) ? 'bg-primary-subtle text-primary-emphasis' : 'bg-warning-subtle text-warning-emphasis');
                                ?>
                                <span class="badge fw-normal <?= $cls ?> me-1 mb-1" title="<?= htmlspecialchars($title) ?>">
                                    <?= htmlspecialchars($d['key']) ?>
                                    <?php if ($d['kind'] !== 'disk'): ?>· <?= htmlspecialchars($kindLabels[$d['kind']] ?? $d['kind']) ?><?php endif; ?>
                                    <?php if (!empty($d['size_bytes'])): ?>· <?= $fmtBytes($d['size_bytes']) ?><?php endif; ?>
                                    <?php if (!$ignored && empty($d['supported'])): ?><i class="bi bi-exclamation-triangle ms-1"></i><?php endif; ?>
                                </span>
                            <?php endforeach; ?>
                            <?php if (empty($vm['disks'])): ?><span class="text-muted">--</span><?php endif; ?>
                        </td>
                        <td>
                            <?php if ($vm['guest_tools']): ?>
                                <i class="bi bi-check-circle text-success" title="Enabled: filesystems are frozen during the snapshot"></i>
                            <?php else: ?>
                                <i class="bi bi-dash-circle text-muted" title="Not enabled: the backup is crash-consistent"></i>
                            <?php endif; ?>
                        </td>
                        <td><?= $vm['status'] === 'running' ? $fmtUptime($vm['uptime_seconds']) : '--' ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <div class="card-footer small text-muted">
        <span class="badge bg-primary-subtle text-primary-emphasis fw-normal">disk</span> can be backed up ·
        <span class="badge bg-warning-subtle text-warning-emphasis fw-normal">disk <i class="bi bi-exclamation-triangle"></i></span> not supported yet (hover for the reason) ·
        <span class="badge bg-body-secondary text-muted fw-normal">CD-ROM</span> ignored.
    </div>
</div>
<?php endif; ?>
