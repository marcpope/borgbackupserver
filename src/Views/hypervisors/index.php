<div class="d-flex justify-content-end mb-3">
    <a href="/hypervisors/add" class="btn btn-success"><i class="bi bi-plus-circle me-1"></i>Add Hypervisor</a>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0 small">
                <thead class="table-light">
                    <tr>
                        <th>Name</th>
                        <th>Type</th>
                        <th>Version</th>
                        <th>VMs<br><span class="fw-normal text-muted">running / total</span></th>
                        <th>Agent</th>
                        <th>Inventory</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($hypervisors)): ?>
                    <tr>
                        <td colspan="7" class="text-center text-muted py-4">
                            No hypervisor yet. Click "Add Hypervisor" to choose a hypervisor type and get its install command.
                        </td>
                    </tr>
                    <?php endif; ?>
                    <?php foreach ($hypervisors as $h): ?>
                    <?php $c = $h['connector']; ?>
                    <tr style="cursor:pointer" onclick="window.location='/hypervisors/<?= (int) $h['id'] ?>'">
                        <td>
                            <i class="bi bi-<?= htmlspecialchars($c ? $c->icon() : 'hdd-rack') ?> me-1 text-muted"></i><strong><?= htmlspecialchars($h['name']) ?></strong>
                        </td>
                        <td><?= htmlspecialchars($c ? $c->label() : $h['type']) ?></td>
                        <td><?= htmlspecialchars($h['info']['version'] ?? '--') ?></td>
                        <td><?= (int) $h['running_count'] ?> / <?= (int) $h['vm_count'] ?></td>
                        <td>
                            <?= !empty($h['agent_version']) ? 'v' . htmlspecialchars($h['agent_version']) : '<span class="text-muted">not installed</span>' ?>
                            <span class="text-muted">(<?= $h['agent_role'] === 'proxy' ? 'proxy' : 'on host' ?>)</span>
                        </td>
                        <td>
                            <?php if (!empty($h['inventory_at'])): ?>
                                <?= \BBS\Core\TimeHelper::ago($h['inventory_at']) ?>
                            <?php else: ?>
                                <span class="text-muted">never</span>
                            <?php endif; ?>
                            <?php if (!empty($h['refresh_requested'])): ?><span class="text-muted">· refresh pending</span><?php endif; ?>
                        </td>
                        <td>
                            <?php $st = $h['agent_status'] ?? 'setup'; $sc = match($st) { 'online' => 'success', 'offline' => 'secondary', 'error' => 'danger', default => 'warning' }; ?>
                            <span class="badge bg-<?= $sc ?>"><?= ucfirst($st) ?></span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
