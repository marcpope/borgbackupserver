<?php
/**
 * Add Hypervisor: the available connectors (cards with their capabilities)
 * and, for the chosen one, its setup steps and the creation form (posted to
 * HypervisorController::store()). $connectors comes from
 * HypervisorController::add().
 */
use BBS\Services\Hypervisors\HypervisorConnector;

$capLabels = ['inventory' => 'VM inventory', 'backup' => 'VM backup', 'restore' => 'Full VM restore', 'file_restore' => 'File-level restore'];
$selected = $_GET['type'] ?? null;
?>
<div class="mb-3">
    <a href="/hypervisors" class="text-decoration-none small"><i class="bi bi-arrow-left me-1"></i>Hypervisors</a>
</div>
<h4 class="mb-3">Add Hypervisor</h4>

<div class="row g-3">
    <?php foreach ($connectors as $c): ?>
    <?php $canAdd = $c->isAvailable() && $c->deployment() === HypervisorConnector::DEPLOY_AGENT_ON_HOST; ?>
    <div class="col-lg-4 col-md-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="d-flex align-items-center mb-2">
                    <i class="bi bi-<?= htmlspecialchars($c->icon()) ?> fs-3 me-2 text-primary"></i>
                    <div>
                        <div class="fw-semibold"><?= htmlspecialchars($c->label()) ?></div>
                        <span class="badge bg-body-secondary text-body border fw-normal">
                            <?= $c->deployment() === HypervisorConnector::DEPLOY_AGENT_ON_HOST ? 'Agent on the host' : 'Proxy agent + API' ?>
                        </span>
                    </div>
                </div>
                <p class="small text-muted mb-2"><?= htmlspecialchars($c->description()) ?></p>

                <?php if ($c->isAvailable()): ?>
                    <div class="small mb-2">
                        <?php foreach ($c->capabilities() as $cap => $on): ?>
                            <span class="me-2 text-nowrap <?= $on ? '' : 'text-muted' ?>">
                                <i class="bi bi-<?= $on ? 'check-circle text-success' : 'hourglass-split' ?> me-1"></i><?= htmlspecialchars($capLabels[$cap] ?? $cap) ?>
                            </span>
                        <?php endforeach; ?>
                    </div>
                    <?php if ($c->setupSteps()): ?>
                        <ol class="small mb-3 ps-3">
                            <?php foreach ($c->setupSteps() as $step): ?>
                                <li><?= htmlspecialchars($step) ?></li>
                            <?php endforeach; ?>
                        </ol>
                    <?php endif; ?>
                <?php endif; ?>

                <?php if ($canAdd): ?>
                    <form method="POST" action="/hypervisors/add" class="border-top pt-3">
                        <input type="hidden" name="csrf_token" value="<?= $this->csrfToken() ?>">
                        <input type="hidden" name="type" value="<?= htmlspecialchars($c->slug()) ?>">
                        <div class="mb-2">
                            <label class="form-label small fw-semibold mb-1">Name</label>
                            <input type="text" class="form-control form-control-sm" name="name" required maxlength="100"
                                   placeholder="e.g. srv-proxmox" <?= ($selected === $c->slug() || count($connectors) === 1) ? 'autofocus' : '' ?>>
                        </div>
                        <div class="mb-2">
                            <label class="form-label small fw-semibold mb-1">Location <span class="text-muted fw-normal">(optional)</span></label>
                            <input type="text" class="form-control form-control-sm" name="location" maxlength="100" placeholder="e.g. Home lab">
                        </div>
                        <?php if (($_SESSION['user_role'] ?? '') === 'admin'): ?>
                        <div class="mb-3">
                            <label class="form-label small fw-semibold mb-1">Assign to User</label>
                            <select class="form-select form-select-sm" name="user_id">
                                <option value="">-- No owner (admin only) --</option>
                                <?php foreach ($users as $user): ?>
                                <option value="<?= (int) $user['id'] ?>"><?= htmlspecialchars($user['username']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php endif; ?>
                        <button type="submit" class="btn btn-sm btn-success"><i class="bi bi-plus-circle me-1"></i>Create</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
