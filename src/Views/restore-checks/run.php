<?php
/**
 * @var array $run
 * @var ?array $job
 * @var array $report
 * @var string $base
 * @var bool $canCancel
 */
$e = fn($v) => htmlspecialchars((string) $v);
$active = $run['result'] === null;
$agentId = (int) ($run['agent_id'] ?? 0);
?>
<nav class="mb-3">
    <ol class="breadcrumb mb-0">
        <?php if (str_starts_with($base, '/clients/')): ?>
        <li class="breadcrumb-item"><a href="/clients">Clients</a></li>
        <li class="breadcrumb-item"><a href="/clients/<?= $agentId ?>?tab=restore#restore-checks"><?= $e($run['client_name'] ?? 'Client') ?></a></li>
        <?php if ($run['plan_id']): ?>
        <li class="breadcrumb-item"><a href="/clients/<?= $agentId ?>/restore-checks/<?= (int) $run['plan_id'] ?>"><?= $e($run['plan_name']) ?></a></li>
        <?php endif; ?>
        <?php else: ?>
        <li class="breadcrumb-item"><a href="/restore-checks">Restore check evidence</a></li>
        <?php endif; ?>
        <li class="breadcrumb-item active">Run #<?= (int) $run['id'] ?></li>
    </ol>
</nav>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h4 class="mb-0">Restore check run #<?= (int) $run['id'] ?></h4>
    <div class="d-flex gap-2">
        <?php if ($job): ?><a class="btn btn-sm btn-outline-secondary" href="/queue/<?= (int) $job['id'] ?>"><i class="bi bi-list-task me-1"></i>Job #<?= (int) $job['id'] ?></a><?php endif; ?>
        <?php if (!$active): ?>
        <a class="btn btn-sm btn-outline-secondary" href="<?= $e($base) ?>/report.json"><i class="bi bi-filetype-json me-1"></i>JSON</a>
        <a class="btn btn-sm btn-outline-secondary" href="<?= $e($base) ?>/report" target="_blank" rel="noopener"><i class="bi bi-printer me-1"></i>Printable report</a>
        <?php endif; ?>
        <?php if ($canCancel && $active): ?>
        <form method="POST" action="/queue/<?= (int) $job['id'] ?>/cancel">
            <input type="hidden" name="csrf_token" value="<?= $this->csrfToken() ?>">
            <button class="btn btn-sm btn-outline-danger"><i class="bi bi-x-circle me-1"></i>Cancel</button>
        </form>
        <?php endif; ?>
    </div>
</div>

<?php if ($active): ?>
<div class="card border-0 shadow-sm mb-4">
    <div class="card-body">
        <div class="fw-semibold mb-1"><span class="spinner-border spinner-border-sm me-2"></span><?= $e(ucfirst($run['phase'])) ?>…</div>
        <?php if ($job && $job['files_total']): ?>
        <div class="small text-muted"><?= number_format((int) $job['files_processed']) ?> of <?= number_format((int) $job['files_total']) ?> items</div>
        <?php endif; ?>
        <div class="small text-muted mt-1">Runs on the BBS server; the client does not need to be online. This page refreshes itself.</div>
    </div>
</div>
<script>setTimeout(() => location.reload(), 5000);</script>
<?php else: ?>
<div class="card border-0 shadow-sm"><div class="card-body">
    <?php require __DIR__ . '/_report_body.php'; ?>
    <?php if (!empty($run['report_digest'])): ?>
    <div class="small text-muted mt-3">Report SHA-256 <span class="font-monospace"><?= $e($run['report_digest']) ?></span>. It shows the report is unchanged only when compared with a copy kept elsewhere.</div>
    <?php endif; ?>
</div></div>
<?php endif; ?>
