<?php
use BBS\Services\BorgBaseService;

$fmt = fn(int $b): string => BorgBaseService::formatBytes($b);
$planBytes = $account['plan_max_size_gb'] !== null ? (int) $account['plan_max_size_gb'] * 1000 * 1000 * 1000 : 0;
$usedBytes = (int) ($account['usage_bytes'] ?? 0);
$usagePct = $planBytes > 0 ? min(100, round($usedBytes / $planBytes * 100, 1)) : null;
$barColor = $usagePct === null ? 'secondary' : ($usagePct >= 90 ? 'danger' : ($usagePct >= 75 ? 'warning' : 'success'));
$hasKey = !empty($account['borgbase_ssh_key_id']);
$csrf = $this->csrfToken();
?>

<nav class="mb-3">
    <ol class="breadcrumb mb-0">
        <li class="breadcrumb-item"><a href="/storage-locations">Storage</a></li>
        <li class="breadcrumb-item active"><?= htmlspecialchars($account['name']) ?></li>
    </ol>
</nav>

<?php if ($refreshError): ?>
<div class="alert alert-warning py-2"><i class="bi bi-exclamation-triangle me-1"></i> Could not reach BorgBase: <?= htmlspecialchars($refreshError) ?>. Figures below are from the last successful check<?= $account['checked_at'] ? ' ' . \BBS\Core\TimeHelper::ago($account['checked_at']) : '' ?>.</div>
<?php endif; ?>

<?php if (!$hasKey): ?>
<div class="alert alert-info py-2">
    <i class="bi bi-key me-1"></i> BBS has no SSH key registered on this account, so it can't create repositories here.
    Save a <strong>Full Access</strong> token (Edit below) and BBS will register one.
</div>
<?php elseif (($account['can_write'] ?? null) === 0 || ($account['can_write'] ?? null) === '0'): ?>
<div class="alert alert-info py-2">
    <i class="bi bi-lock me-1"></i> BorgBase refused a change with this token, so it is probably Read Only. Creating and deleting repositories needs a <strong>Full Access</strong> token.
</div>
<?php endif; ?>

<!-- Account header -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-body">
        <div class="d-flex align-items-start gap-3 flex-wrap">
            <img src="/images/borgbase.svg" alt="" style="width:40px;height:40px;border-radius:50%" class="flex-shrink-0">
            <div class="flex-grow-1" style="min-width:240px">
                <h5 class="mb-0"><?= htmlspecialchars($account['name']) ?></h5>
                <div class="text-muted small">
                    <?= $account['username'] ? htmlspecialchars($account['username']) . ' &middot; ' : '' ?>
                    <?= $account['plan_name'] ? htmlspecialchars($account['plan_name']) : 'Plan unknown' ?>
                    <?php if ($account['checked_at']): ?> &middot; checked <?= \BBS\Core\TimeHelper::ago($account['checked_at']) ?><?php endif; ?>
                </div>
                <div class="mt-3" style="max-width:520px">
                    <div class="d-flex justify-content-between small text-muted mb-1">
                        <span><?= $fmt($usedBytes) ?> used</span>
                        <span><?= $planBytes > 0 ? $fmt($planBytes) . ' plan' : 'plan size unknown' ?></span>
                    </div>
                    <div class="progress" style="height:8px">
                        <div class="progress-bar bg-<?= $barColor ?>" style="width:<?= $usagePct ?? 0 ?>%"></div>
                    </div>
                    <div class="small text-muted mt-1">
                        <?= $usagePct !== null ? $usagePct . '% of plan storage' : 'Usage from BorgBase' ?>
                        &middot; <?= (int) $repoCount ?><?= $repoLimit > 0 ? ' of ' . $repoLimit : '' ?> repositories on BorgBase
                        <?php if ($repoLimit > 0 && $repoCount >= $repoLimit): ?><span class="badge bg-warning text-dark ms-1">plan limit reached</span><?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="d-flex gap-2 flex-wrap align-items-start">
                <?php if ($canCreate): ?>
                <button class="btn btn-sm btn-success" data-bs-toggle="modal" data-bs-target="#createRepoModal"><i class="bi bi-plus-circle me-1"></i> Add Repository</button>
                <?php else: ?>
                <button class="btn btn-sm btn-success" disabled title="<?= $repoLimit > 0 && $repoCount >= $repoLimit ? 'Plan limit reached' : 'Needs a Full Access token' ?>"><i class="bi bi-plus-circle me-1"></i> Add Repository</button>
                <?php endif; ?>
                <form method="POST" action="/borgbase-accounts/<?= $account['id'] ?>/refresh" class="d-inline">
                    <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                    <button class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-repeat me-1"></i> Refresh</button>
                </form>
                <button class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#editAccountModal"><i class="bi bi-pencil me-1"></i> Edit</button>
                <form method="POST" action="/borgbase-accounts/<?= $account['id'] ?>/delete" class="d-inline" data-confirm="Remove this BorgBase account from BBS? Its storage locations and repositories are kept; only the grouping and the API token are removed.">
                    <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                    <button class="btn btn-sm btn-outline-danger"><i class="bi bi-x-circle me-1"></i> Remove</button>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Repositories -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header fw-semibold d-flex justify-content-between align-items-center">
        <span><i class="bi bi-archive me-2"></i>Repositories in BBS</span>
        <span class="text-muted small"><?= count($locations) ?> configured</span>
    </div>
    <?php if (empty($locations)): ?>
    <div class="card-body text-muted small">No repositories yet. Add one above, or add an existing BorgBase repository from the list below.</div>
    <?php else: ?>
    <div class="table-responsive">
        <table class="table table-sm table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>Repository</th>
                    <th>Client</th>
                    <th style="min-width:180px">Usage</th>
                    <th class="d-none d-md-table-cell">Last modified</th>
                    <th class="text-end" style="width:170px"></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($locations as $loc): ?>
                <?php
                $remote = $remoteRepos[$loc['remote_user']] ?? null;
                $total = (int) ($loc['disk_total_bytes'] ?? 0);
                $used = (int) ($loc['disk_used_bytes'] ?? 0);
                $pct = $total > 0 ? min(100, round($used / $total * 100, 1)) : null;
                $rowColor = $pct === null ? 'secondary' : ($pct >= 90 ? 'danger' : ($pct >= 75 ? 'warning' : 'success'));
                $quotaOwn = $remote && !empty($remote['quotaEnabled']);
                ?>
                <tr>
                    <td>
                        <div class="fw-semibold"><?= htmlspecialchars($loc['borgbase_repo_name'] ?: $loc['name']) ?></div>
                        <code class="small text-muted"><?= htmlspecialchars($loc['remote_user']) ?></code>
                        <?php if ($remote && !empty($remote['region'])): ?><span class="badge bg-light text-dark border ms-1"><?= htmlspecialchars(strtoupper($remote['region'])) ?></span><?php endif; ?>
                        <?php if (!empty($loc['disk_check_error'])): ?><div class="small text-danger"><?= htmlspecialchars($loc['disk_check_error']) ?></div><?php endif; ?>
                    </td>
                    <td>
                        <?php if (!empty($loc['agent_id'])): ?>
                        <a href="/clients/<?= (int) $loc['agent_id'] ?>?tab=repos"><?= htmlspecialchars($loc['agent_name']) ?></a>
                        <div class="small text-muted"><?= htmlspecialchars($loc['repository_name']) ?></div>
                        <?php else: ?>
                        <span class="text-muted small">Not assigned &middot; <a href="/clients">import from a client's Repos tab</a></span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="small"><?= $fmt($used) ?><?= $total > 0 ? ' of ' . $fmt($total) . ($quotaOwn ? ' quota' : '') : '' ?></div>
                        <?php if ($pct !== null): ?>
                        <div class="progress" style="height:5px"><div class="progress-bar bg-<?= $rowColor ?>" style="width:<?= $pct ?>%"></div></div>
                        <?php endif; ?>
                    </td>
                    <td class="d-none d-md-table-cell small text-muted">
                        <?= !empty($remote['lastModified']) ? \BBS\Core\TimeHelper::ago(date('Y-m-d H:i:s', strtotime($remote['lastModified']))) : '--' ?>
                    </td>
                    <td class="text-end text-nowrap">
                        <button class="btn btn-sm btn-outline-secondary" onclick="testLocation(<?= $loc['id'] ?>, this)" title="Test SSH connection"><i class="bi bi-plug"></i></button>
                        <?php if ((int) $loc['repo_count'] === 0): ?>
                        <button class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#deleteLoc<?= $loc['id'] ?>" title="Delete"><i class="bi bi-trash"></i></button>
                        <?php else: ?>
                        <button class="btn btn-sm btn-outline-danger" disabled title="Delete the repository from the client's Repos tab first"><i class="bi bi-trash"></i></button>
                        <?php endif; ?>
                        <div id="locTest<?= $loc['id'] ?>" class="small text-start"></div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<?php foreach ($locations as $loc): if ((int) $loc['repo_count'] !== 0) continue; ?>
<div class="modal fade" id="deleteLoc<?= $loc['id'] ?>" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" action="/borgbase-accounts/<?= $account['id'] ?>/locations/<?= $loc['id'] ?>/delete" class="modal-content">
            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
            <div class="modal-header"><h5 class="modal-title">Delete <?= htmlspecialchars($loc['borgbase_repo_name'] ?: $loc['name']) ?></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <p class="mb-2">This removes the storage location from BBS.</p>
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="delete_remote" value="1" id="deleteRemote<?= $loc['id'] ?>">
                    <label class="form-check-label" for="deleteRemote<?= $loc['id'] ?>">
                        Also delete repository <code><?= htmlspecialchars($loc['remote_user']) ?></code> on BorgBase, with all its data
                    </label>
                </div>
                <div class="form-text">Leave this unticked to keep the data on BorgBase. Deleting there cannot be undone.</div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-sm btn-danger"><i class="bi bi-trash me-1"></i> Delete</button>
            </div>
        </form>
    </div>
</div>
<?php endforeach; ?>

<!-- Repos on BorgBase that BBS doesn't have -->
<?php if (!empty($unconfigured)): ?>
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header fw-semibold"><i class="bi bi-cloud me-2"></i>On BorgBase, not in BBS</div>
    <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
            <tbody>
            <?php foreach ($unconfigured as $r): ?>
                <tr>
                    <td>
                        <div class="fw-semibold"><?= htmlspecialchars($r['name']) ?></div>
                        <code class="small text-muted"><?= htmlspecialchars($r['id']) ?></code>
                        <?php if (!empty($r['region'])): ?><span class="badge bg-light text-dark border ms-1"><?= htmlspecialchars(strtoupper($r['region'])) ?></span><?php endif; ?>
                    </td>
                    <td class="small text-muted"><?= $fmt((int) round(((float) ($r['currentUsage'] ?? 0)) * 1000 * 1000)) ?> used</td>
                    <td class="small text-muted d-none d-md-table-cell"><?= !empty($r['lastModified']) ? 'modified ' . \BBS\Core\TimeHelper::ago(date('Y-m-d H:i:s', strtotime($r['lastModified']))) : 'never used' ?></td>
                    <td class="text-end">
                        <form method="POST" action="/borgbase-accounts/<?= $account['id'] ?>/repos/import" class="d-inline">
                            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                            <input type="hidden" name="repo_id" value="<?= htmlspecialchars($r['id']) ?>">
                            <button class="btn btn-sm btn-outline-primary" <?= $hasKey ? '' : 'disabled title="Needs a Full Access token"' ?>><i class="bi bi-plus-circle me-1"></i> Add to BBS</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="card-footer small text-muted">Adding grants the BBS key access to the repository and creates a storage location. Then import the repository from the client's Repos tab, which asks for its passphrase.</div>
</div>
<?php endif; ?>

<?php if (!empty($unattached)): ?>
<div class="alert alert-light border small">
    <i class="bi bi-info-circle me-1"></i> <?= count($unattached) ?> BorgBase storage location<?= count($unattached) === 1 ? '' : 's' ?> on the <a href="/storage-locations">Storage page</a> belong to a different BorgBase account. Add that account with its own token to group them.
</div>
<?php endif; ?>

<!-- Create repository modal -->
<div class="modal fade" id="createRepoModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" action="/borgbase-accounts/<?= $account['id'] ?>/repos/create" class="modal-content">
            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
            <div class="modal-header"><h5 class="modal-title">Add Repository on BorgBase</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label fw-semibold">Client</label>
                    <select class="form-select" name="agent_id" required>
                        <option value="">Choose the client this repository is for...</option>
                        <?php foreach ($agents as $a): ?>
                        <option value="<?= $a['id'] ?>"><?= htmlspecialchars($a['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-text">A repository stays with the client it is created for.</div>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Name</label>
                    <input type="text" class="form-control" name="name" required placeholder="e.g., webserver-daily">
                    <div class="form-text">Used on BorgBase and in BBS.</div>
                </div>
                <div class="row g-2 mb-3">
                    <div class="col-6">
                        <label class="form-label fw-semibold">Region</label>
                        <select class="form-select" name="region">
                            <?php foreach ($regions as $code => $label): ?>
                            <option value="<?= $code ?>"><?= htmlspecialchars($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-6">
                        <label class="form-label fw-semibold">Quota <span class="text-muted fw-normal">(GB, optional)</span></label>
                        <input type="number" class="form-control" name="quota_gb" min="1" step="1" placeholder="none">
                    </div>
                </div>
                <div class="mb-1">
                    <label class="form-label fw-semibold">Encryption</label>
                    <select class="form-select" name="encryption">
                        <option value="repokey-blake2">repokey-blake2 (recommended)</option>
                        <option value="repokey">repokey</option>
                        <option value="none">none</option>
                    </select>
                    <div class="form-text">A passphrase is generated and stored with the repository.</div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-sm btn-success"><i class="bi bi-plus-circle me-1"></i> Create</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit account modal -->
<div class="modal fade" id="editAccountModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" action="/borgbase-accounts/<?= $account['id'] ?>/update" class="modal-content" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
            <div class="modal-header"><h5 class="modal-title">Edit BorgBase Account</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label fw-semibold">Name</label>
                    <input type="text" class="form-control" name="name" value="<?= htmlspecialchars($account['name']) ?>">
                </div>
                <div class="mb-1">
                    <label class="form-label fw-semibold">API Token <span class="text-muted fw-normal">(leave blank to keep the current one)</span></label>
                    <input type="text" class="form-control font-monospace" name="api_token" autocomplete="new-password" data-lpignore="true" data-1p-ignore="true" spellcheck="false">
                    <div class="form-text">BorgBase → Account → API → New Token. Name it (for example "BBS") and choose <strong>Full Access</strong> so BBS can create and delete repositories.</div>
                </div>
                <?php if (!empty($account['ssh_public_key'])): ?>
                <div class="mt-3">
                    <label class="form-label fw-semibold small">SSH key registered on BorgBase</label>
                    <textarea class="form-control font-monospace small" rows="2" readonly><?= htmlspecialchars($account['ssh_public_key']) ?></textarea>
                </div>
                <?php endif; ?>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-save me-1"></i> Save</button>
            </div>
        </form>
    </div>
</div>

<script>
function testLocation(id, btn) {
    var out = document.getElementById('locTest' + id);
    out.innerHTML = '<span class="text-muted"><span class="spinner-border spinner-border-sm me-1" style="width:.7rem;height:.7rem"></span>Testing...</span>';
    btn.disabled = true;
    fetch('/remote-ssh-configs/' + id + '/test', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'csrf_token=' + encodeURIComponent(<?= json_encode($csrf) ?>)
    })
    .then(function (r) { return r.json(); })
    .then(function (data) {
        btn.disabled = false;
        if (data.status === 'ok') {
            out.innerHTML = '<span class="text-success"><i class="bi bi-check-circle me-1"></i>' + (data.version ? data.version.replace(/[<>&]/g, '') : 'OK') + '</span>';
        } else {
            out.innerHTML = '<span class="text-danger"><i class="bi bi-x-circle me-1"></i>' + String(data.error || 'Failed').replace(/[<>&]/g, '') + '</span>';
        }
    })
    .catch(function () { btn.disabled = false; out.innerHTML = '<span class="text-danger">Request failed</span>'; });
}
</script>
