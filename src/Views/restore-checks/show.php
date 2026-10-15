<?php
/**
 * @var array $agent
 * @var array $plan   summaryForAgent() row
 * @var array $runs
 * @var bool  $canManage
 * @var string $runNowKey
 */
use BBS\Core\TimeHelper;
use BBS\Services\RestoreCheckReport;
use BBS\Services\RestoreCheckRunner;
use BBS\Services\RestoreCheckSchedule;

$agentId = (int) $agent['id'];
$planId = (int) $plan['id'];
$e = fn($v) => htmlspecialchars((string) $v);
$fmt = fn(?string $utc) => $utc ? TimeHelper::format($utc, 'M j, Y g:i A') : '—';
[$statusLabel, $statusColor] = RestoreCheckReport::badge($plan['status'] ?? null);
$probes = json_decode((string) ($plan['paths'] ?? '[]'), true) ?: [];
?>
<nav class="mb-3">
    <ol class="breadcrumb mb-0">
        <li class="breadcrumb-item"><a href="/clients">Clients</a></li>
        <li class="breadcrumb-item"><a href="/clients/<?= $agentId ?>?tab=restore"><?= $e($agent['name']) ?></a></li>
        <li class="breadcrumb-item"><a href="/clients/<?= $agentId ?>?tab=restore#restore-checks">Restore checks</a></li>
        <li class="breadcrumb-item active"><?= $e($plan['name']) ?></li>
    </ol>
</nav>

<div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
    <div>
        <h4 class="mb-1"><?= $e($plan['name']) ?>
            <span class="badge text-bg-<?= $statusColor ?> align-middle fs-6"><?= $e($statusLabel) ?></span>
            <?php if (!(int) $plan['enabled']): ?><span class="badge text-bg-light border align-middle fs-6">Disabled</span><?php endif; ?>
        </h4>
        <div class="text-muted small"><?= $e(RestoreCheckSchedule::describe($plan)) ?></div>
        <?php if (!empty($plan['disabled_reason'])): ?>
        <div class="text-danger small mt-1"><i class="bi bi-exclamation-triangle me-1"></i><?= $e($plan['disabled_reason']) ?></div>
        <?php endif; ?>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <form method="POST" action="/clients/<?= $agentId ?>/restore-checks/<?= $planId ?>/run">
            <input type="hidden" name="csrf_token" value="<?= $this->csrfToken() ?>">
            <input type="hidden" name="idempotency_key" value="<?= $e($runNowKey) ?>">
            <button class="btn btn-primary btn-sm" <?= in_array($plan['status'] ?? '', ['queued', 'running'], true) ? 'disabled' : '' ?>><i class="bi bi-play-fill me-1"></i>Run now</button>
        </form>
        <?php if ($canManage): ?>
            <?php if ((int) $plan['enabled']): ?>
            <form method="POST" action="/clients/<?= $agentId ?>/restore-checks/<?= $planId ?>/disable">
                <input type="hidden" name="csrf_token" value="<?= $this->csrfToken() ?>">
                <button class="btn btn-outline-secondary btn-sm"><i class="bi bi-pause me-1"></i>Disable schedule</button>
            </form>
            <?php else: ?>
            <form method="POST" action="/clients/<?= $agentId ?>/restore-checks/<?= $planId ?>/enable">
                <input type="hidden" name="csrf_token" value="<?= $this->csrfToken() ?>">
                <button class="btn btn-outline-success btn-sm"><i class="bi bi-calendar-check me-1"></i>Enable schedule</button>
            </form>
            <?php endif; ?>
            <a href="/clients/<?= $agentId ?>/restore-checks/<?= $planId ?>/edit" class="btn btn-outline-secondary btn-sm"><i class="bi bi-pencil me-1"></i>Edit</a>
            <form method="POST" action="/clients/<?= $agentId ?>/restore-checks/<?= $planId ?>/delete" id="rc-delete-form">
                <input type="hidden" name="csrf_token" value="<?= $this->csrfToken() ?>">
                <button type="button" class="btn btn-outline-danger btn-sm" id="rc-delete-btn"><i class="bi bi-trash me-1"></i>Delete</button>
                <button type="submit" class="btn btn-danger btn-sm" id="rc-delete-confirm" hidden>Confirm delete (history is kept)</button>
            </form>
        <?php endif; ?>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-md-3"><div class="card border-0 shadow-sm h-100"><div class="card-body">
        <div class="text-muted small">Latest backup in scope</div>
        <div class="fw-semibold"><?= $fmt($plan['latest_backup_at'] ?? null) ?></div>
    </div></div></div>
    <div class="col-md-3"><div class="card border-0 shadow-sm h-100"><div class="card-body">
        <div class="text-muted small">Last verified restore (strict pass)</div>
        <div class="fw-semibold"><?= $fmt($plan['last_strict_success_at'] ?? null) ?></div>
    </div></div></div>
    <div class="col-md-3"><div class="card border-0 shadow-sm h-100"><div class="card-body">
        <div class="text-muted small">Next run</div>
        <div class="fw-semibold">
            <?php if ((int) $plan['enabled'] && $plan['next_due_at']): ?>
                <?= $e((new \DateTimeImmutable($plan['next_due_at'], new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone($plan['timezone']))->format('M j, Y H:i')) ?>
                <span class="text-muted small"><?= $e($plan['timezone']) ?></span>
            <?php else: ?>Not scheduled<?php endif; ?>
        </div>
    </div></div></div>
    <div class="col-md-3"><div class="card border-0 shadow-sm h-100"><div class="card-body">
        <div class="text-muted small">Scope</div>
        <div class="fw-semibold"><?= $plan['mode'] === 'full' ? 'Full archive' : count($probes) . ' selected path(s)' ?></div>
        <div class="small text-muted">
            <?= $e($plan['repository_name'] ?? 'repository deleted') ?> ·
            <?= $plan['scope'] === 'plan' ? 'plan ' . $e($plan['source_plan_name'] ?? 'deleted') : 'repository-wide' ?>
        </div>
    </div></div></div>
</div>

<div class="row g-4">
    <div class="col-lg-4">
        <div class="card border-0 shadow-sm">
            <div class="card-header fw-semibold">Settings (revision <?= (int) $plan['config_revision'] ?>)</div>
            <div class="card-body small">
                <?php if ($plan['mode'] === 'paths'): ?>
                <div class="fw-semibold mb-1">Paths</div>
                <ul class="list-unstyled font-monospace mb-3">
                    <?php foreach ($probes as $p): ?>
                    <li class="text-break"><?= $e($p['path']) ?><?php if (!empty($p['sha256'])): ?> <span class="badge text-bg-light border" title="<?= $e($p['sha256']) ?>">sha256</span><?php endif; ?></li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>
                <dl class="row mb-0">
                    <dt class="col-7 fw-normal text-muted">Maximum archive age</dt><dd class="col-5"><?= (int) $plan['max_archive_age_hours'] ?> h</dd>
                    <dt class="col-7 fw-normal text-muted">Overdue after</dt><dd class="col-5"><?= (int) $plan['overdue_hours'] ?> h</dd>
                    <dt class="col-7 fw-normal text-muted">Maximum queue wait</dt><dd class="col-5"><?= (int) $plan['queue_deadline_minutes'] ?> min</dd>
                    <dt class="col-7 fw-normal text-muted">Maximum run time</dt><dd class="col-5"><?= (int) $plan['run_timeout_minutes'] ?> min</dd>
                    <dt class="col-7 fw-normal text-muted">Size budget</dt><dd class="col-5"><?= $e(RestoreCheckRunner::bytes((int) $plan['max_bytes'])) ?></dd>
                    <dt class="col-7 fw-normal text-muted">Maximum items</dt><dd class="col-5"><?= number_format((int) $plan['max_files']) ?></dd>
                    <dt class="col-7 fw-normal text-muted">Free space kept</dt><dd class="col-5"><?= $e(RestoreCheckRunner::bytes((int) $plan['min_free_bytes'])) ?></dd>
                    <dt class="col-7 fw-normal text-muted">Warning backups eligible</dt><dd class="col-5"><?= (int) $plan['allow_warning_archives'] ? 'Yes' : 'No' ?></dd>
                    <dt class="col-7 fw-normal text-muted">Runs as</dt><dd class="col-5"><?= $e($plan['owner_name'] ?? '—') ?></dd>
                    <dt class="col-7 fw-normal text-muted">Execution</dt><dd class="col-5">BBS server staging</dd>
                </dl>
            </div>
        </div>
    </div>
    <div class="col-lg-8">
        <div class="card border-0 shadow-sm">
            <div class="card-header fw-semibold">History</div>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead><tr class="small text-muted"><th class="ps-3">Run</th><th>Result</th><th>Archive</th><th>Cleanup</th><th>Duration</th><th>Trigger</th></tr></thead>
                    <tbody>
                    <?php if (!$runs): ?>
                        <tr><td colspan="6" class="text-muted small ps-3 py-3">Never run.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($runs as $r): ?>
                        <?php [$l, $c] = RestoreCheckReport::badge($r['result'] ?? ($r['phase'] === 'queued' ? 'queued' : 'running')); ?>
                        <tr>
                            <td class="ps-3 small"><a href="/clients/<?= $agentId ?>/restore-checks/runs/<?= (int) $r['id'] ?>">#<?= (int) $r['id'] ?></a>
                                <div class="text-muted"><?= $fmt($r['finished_at'] ?? $r['queued_at']) ?></div></td>
                            <td><span class="badge text-bg-<?= $c ?>"><?= $e($l) ?></span>
                                <?php if ($r['reason_code']): ?><div class="small text-muted"><?= $e($r['reason_code']) ?></div><?php endif; ?></td>
                            <td class="small font-monospace text-break"><?= $e($r['archive_name'] ?? '—') ?></td>
                            <td class="small"><?= $e(['not_needed' => '—', 'pending' => 'Pending', 'succeeded' => 'Removed', 'failed' => 'FAILED'][$r['cleanup_status']] ?? $r['cleanup_status']) ?></td>
                            <td class="small"><?= $r['total_seconds'] !== null ? $e(TimeHelper::duration((int) round((float) $r['total_seconds']))) : '—' ?></td>
                            <td class="small"><?= $e($r['trigger_type']) ?><?= $r['requested_by_name'] ? ' · ' . $e($r['requested_by_name']) : '' ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    const btn = document.getElementById('rc-delete-btn');
    if (!btn) return;
    btn.addEventListener('click', () => { btn.hidden = true; document.getElementById('rc-delete-confirm').hidden = false; });
})();
</script>
