<div class="d-flex justify-content-between align-items-center mb-4">
    <h5 class="mb-0">Edit User: <?= htmlspecialchars($user['username']) ?></h5>
    <a href="/users" class="btn btn-sm btn-outline-secondary">
        <i class="bi bi-arrow-left me-1"></i> Back to Users
    </a>
</div>

<?php
// Custom column labels for the permission table
$columnLabels = [
    'trigger_backup' => 'Run Backups',
    'manage_repos' => 'Manage Repos',
    'manage_plans' => 'Manage Plans',
    'restore' => 'Perform Restores',
    'repo_maintenance' => 'Repo Maint',
];
?>

<form method="POST" action="/users/<?= $user['id'] ?>/edit">
    <input type="hidden" name="csrf_token" value="<?= $this->csrfToken() ?>">

    <!-- Basic Info -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-body border-0">
            <h6 class="mb-0"><i class="bi bi-person me-2"></i>Account Information</h6>
        </div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Username</label>
                    <input type="text" class="form-control" value="<?= htmlspecialchars($user['username']) ?>" disabled>
                    <div class="form-text">Username cannot be changed</div>
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Email</label>
                    <input type="email" class="form-control" name="email" value="<?= htmlspecialchars($user['email']) ?>" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Role</label>
                    <select class="form-select" name="role" id="roleSelect">
                        <option value="user" <?= $user['role'] === 'user' ? 'selected' : '' ?>>User</option>
                        <option value="admin" <?= $user['role'] === 'admin' ? 'selected' : '' ?>>Admin</option>
                    </select>
                </div>
                <div class="col-md-6">
                    <?php if (($user['auth_provider'] ?? 'local') === 'oidc'): ?>
                    <label class="form-label fw-semibold">Authentication</label>
                    <div><span class="badge text-bg-info"><i class="bi bi-box-arrow-in-right me-1"></i>SSO (OIDC)</span></div>
                    <div class="form-text">This user authenticates via Single Sign-On. No password required.</div>
                    <?php else: ?>
                    <label class="form-label fw-semibold">New Password</label>
                    <input type="password" class="form-control" name="password" placeholder="Leave blank to keep current">
                    <div class="form-text">Only fill this if you want to change the password</div>
                    <?php endif; ?>
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Two-Factor Authentication</label>
                    <?php if ($user['totp_enabled']): ?>
                    <div class="d-flex align-items-center gap-2">
                        <span class="badge bg-success"><i class="bi bi-shield-check me-1"></i>Enabled</span>
                        <button type="button" class="btn btn-sm btn-outline-warning" data-bs-toggle="modal" data-bs-target="#reset2faModal">
                            <i class="bi bi-shield-x me-1"></i>Reset 2FA
                        </button>
                    </div>
                    <?php else: ?>
                    <div><span class="badge bg-secondary">Disabled</span></div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Client Access & Permissions (hidden for admins) -->
    <div class="card border-0 shadow-sm mb-4" id="clientAccessCard" style="<?= $user['role'] === 'admin' ? 'display:none' : '' ?>">
        <div class="card-header bg-body border-0">
            <h6 class="mb-0"><i class="bi bi-pc-display me-2"></i>Client Access & Permissions</h6>
        </div>
        <div class="card-body">
            <div class="rounded-3 p-3 mb-3 d-flex align-items-center <?= $user['all_clients'] ? 'bg-info bg-opacity-10 border border-info border-opacity-25' : 'bg-body-secondary' ?>" id="allClientsCallout">
                <div class="form-check mb-0">
                    <input class="form-check-input" type="checkbox" name="all_clients" id="allClientsCheck" value="1" <?= $user['all_clients'] ? 'checked' : '' ?>>
                    <label class="form-check-label fw-semibold" for="allClientsCheck">
                        Access All Clients
                    </label>
                    <div class="text-muted small">User will have access to all current and future clients</div>
                </div>
            </div>

            <!-- All Clients Permissions (shown when all_clients is checked) -->
            <div id="allClientsPermsDiv" style="<?= $user['all_clients'] ? '' : 'display:none' ?>">
                <p class="text-muted small mb-2">Grant permissions for all clients:</p>
                <div class="d-flex flex-wrap gap-3">
                    <?php foreach ($allPermissions as $perm): ?>
                    <?php $data = $permissionData[$perm]; ?>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="perm_global_<?= $perm ?>" id="perm_global_<?= $perm ?>" value="1"
                            <?= $data['global'] ? 'checked' : '' ?>>
                        <label class="form-check-label" for="perm_global_<?= $perm ?>">
                            <?= htmlspecialchars($columnLabels[$perm] ?? $permissionLabels[$perm]) ?>
                        </label>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Specific Clients Table (shown when all_clients is unchecked) -->
            <div id="specificClientsDiv" style="<?= $user['all_clients'] ? 'display:none' : '' ?>">
                <?php if (empty($allAgents)): ?>
                <p class="text-muted">No clients available</p>
                <?php else: ?>
                <p class="text-muted small mb-2">Select clients and their permissions:</p>
                <div class="table-responsive">
                    <table class="table table-sm table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Client</th>
                                <th class="text-center" style="width: 70px;"><small>View</small></th>
                                <?php foreach ($allPermissions as $perm): ?>
                                <th class="text-center" style="width: 95px;">
                                    <small><?= htmlspecialchars($columnLabels[$perm] ?? $permissionLabels[$perm]) ?></small>
                                </th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($allAgents as $agent): ?>
                            <?php $isAssigned = in_array($agent['id'], $userAgentIds); ?>
                            <tr class="<?= $isAssigned ? '' : 'table-light' ?>" data-agent-id="<?= $agent['id'] ?>">
                                <td>
                                    <span class="client-name <?= $isAssigned ? 'fw-semibold' : 'text-muted' ?>">
                                        <?= htmlspecialchars($agent['name']) ?>
                                    </span>
                                </td>
                                <td class="text-center">
                                    <input class="form-check-input client-checkbox" type="checkbox" name="agents[]"
                                        value="<?= $agent['id'] ?>" id="agent_<?= $agent['id'] ?>"
                                        data-agent-id="<?= $agent['id'] ?>"
                                        <?= $isAssigned ? 'checked' : '' ?>>
                                </td>
                                <?php foreach ($allPermissions as $perm): ?>
                                <?php
                                    $data = $permissionData[$perm];
                                    $hasPermForAgent = $data['global'] || in_array($agent['id'], $data['agent_ids']);
                                ?>
                                <td class="text-center perm-cell" data-agent-id="<?= $agent['id'] ?>">
                                    <input class="form-check-input perm-checkbox" type="checkbox"
                                        name="perm_<?= $perm ?>_<?= $agent['id'] ?>" value="1"
                                        data-agent-id="<?= $agent['id'] ?>" data-perm="<?= $perm ?>"
                                        <?= $hasPermForAgent && $isAssigned ? 'checked' : '' ?>
                                        <?= $isAssigned ? '' : 'disabled' ?>>
                                </td>
                                <?php endforeach; ?>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot class="table-light">
                            <tr>
                                <td class="text-end small text-muted">Select all:</td>
                                <td class="text-center">
                                    <input type="checkbox" class="form-check-input" id="selectAllClients" title="Select/Deselect All">
                                </td>
                                <?php foreach ($allPermissions as $perm): ?>
                                <td class="text-center">
                                    <input type="checkbox" class="form-check-input select-all-perm"
                                        data-perm="<?= $perm ?>" title="Select all <?= htmlspecialchars($columnLabels[$perm] ?? $permissionLabels[$perm]) ?>">
                                </td>
                                <?php endforeach; ?>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Actions -->
    <div class="d-flex gap-2">
        <button type="submit" class="btn btn-sm btn-primary">
            <i class="bi bi-check-lg me-1"></i> Save Changes
        </button>
        <a href="/users" class="btn btn-sm btn-outline-secondary">Cancel</a>
    </div>
</form>

<!-- Reset 2FA Modal -->
<?php if ($user['totp_enabled']): ?>
<div class="modal fade" id="reset2faModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Reset Two-Factor Authentication</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to reset 2FA for <strong><?= htmlspecialchars($user['username']) ?></strong>?</p>
                <p class="text-muted small">This will disable their 2FA and delete all recovery codes. They will need to set up 2FA again.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <form method="POST" action="/users/<?= $user['id'] ?>/reset-2fa" class="d-inline">
                    <input type="hidden" name="csrf_token" value="<?= $this->csrfToken() ?>">
                    <button type="submit" class="btn btn-warning">
                        <i class="bi bi-shield-x me-1"></i> Reset 2FA
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const roleSelect = document.getElementById('roleSelect');
    const clientAccessCard = document.getElementById('clientAccessCard');
    const allClientsCheck = document.getElementById('allClientsCheck');
    const allClientsPermsDiv = document.getElementById('allClientsPermsDiv');
    const specificClientsDiv = document.getElementById('specificClientsDiv');
    const selectAllClients = document.getElementById('selectAllClients');

    // Toggle client access based on role
    roleSelect.addEventListener('change', function() {
        const isAdmin = this.value === 'admin';
        clientAccessCard.style.display = isAdmin ? 'none' : '';
    });

    // Toggle between all clients and specific clients
    const allClientsCallout = document.getElementById('allClientsCallout');
    allClientsCheck.addEventListener('change', function() {
        allClientsPermsDiv.style.display = this.checked ? '' : 'none';
        specificClientsDiv.style.display = this.checked ? 'none' : '';
        if (this.checked) {
            allClientsCallout.className = 'rounded-3 p-3 mb-3 d-flex align-items-center bg-info bg-opacity-10 border border-info border-opacity-25';
        } else {
            allClientsCallout.className = 'rounded-3 p-3 mb-3 d-flex align-items-center bg-body-secondary';
        }
    });

    // Handle client checkbox changes - enable/disable permission checkboxes
    document.querySelectorAll('.client-checkbox').forEach(function(checkbox) {
        checkbox.addEventListener('change', function() {
            const agentId = this.dataset.agentId;
            const row = this.closest('tr');
            const permCheckboxes = row.querySelectorAll('.perm-checkbox');
            const clientName = row.querySelector('.client-name');

            if (this.checked) {
                row.classList.remove('table-light');
                clientName.classList.add('fw-semibold');
                clientName.classList.remove('text-muted');
                permCheckboxes.forEach(cb => cb.disabled = false);
            } else {
                row.classList.add('table-light');
                clientName.classList.remove('fw-semibold');
                clientName.classList.add('text-muted');
                permCheckboxes.forEach(cb => {
                    cb.disabled = true;
                    cb.checked = false;
                });
            }
            updateSelectAllState();
        });
    });

    // Select all clients checkbox
    if (selectAllClients) {
        selectAllClients.addEventListener('change', function() {
            document.querySelectorAll('.client-checkbox').forEach(cb => {
                if (cb.checked !== this.checked) {
                    cb.checked = this.checked;
                    cb.dispatchEvent(new Event('change'));
                }
            });
        });
    }

    // Select all for a specific permission column
    document.querySelectorAll('.select-all-perm').forEach(function(checkbox) {
        checkbox.addEventListener('change', function() {
            const perm = this.dataset.perm;
            const isChecked = this.checked;
            document.querySelectorAll(`.perm-checkbox[data-perm="${perm}"]`).forEach(cb => {
                if (!cb.disabled) {
                    cb.checked = isChecked;
                }
            });
        });
    });

    // Update select-all checkbox state based on individual checkboxes
    function updateSelectAllState() {
        if (!selectAllClients) return;
        const allCheckboxes = document.querySelectorAll('.client-checkbox');
        const checkedCount = document.querySelectorAll('.client-checkbox:checked').length;
        selectAllClients.checked = checkedCount === allCheckboxes.length;
        selectAllClients.indeterminate = checkedCount > 0 && checkedCount < allCheckboxes.length;

        // Update permission column select-all states
        document.querySelectorAll('.select-all-perm').forEach(function(selectAll) {
            const perm = selectAll.dataset.perm;
            const enabledPerms = document.querySelectorAll(`.perm-checkbox[data-perm="${perm}"]:not(:disabled)`);
            const checkedPerms = document.querySelectorAll(`.perm-checkbox[data-perm="${perm}"]:not(:disabled):checked`);
            selectAll.checked = enabledPerms.length > 0 && checkedPerms.length === enabledPerms.length;
            selectAll.indeterminate = checkedPerms.length > 0 && checkedPerms.length < enabledPerms.length;
        });
    }

    // Initial state update
    updateSelectAllState();
});
</script>
