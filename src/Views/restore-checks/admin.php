<?php
/** @var array $runs */
use BBS\Core\TimeHelper;
use BBS\Services\RestoreCheckReport;

$e = fn($v) => htmlspecialchars((string) $v);
?>
<h4 class="mb-1">Restore check evidence</h4>
<p class="text-muted small mb-3">Every finished restore check run on this server, including runs whose client or plan has been deleted. Only administrators see this page.</p>
<div class="card border-0 shadow-sm">
    <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
            <thead><tr class="small text-muted"><th class="ps-3">Run</th><th>Client</th><th>Check</th><th>Result</th><th>Archive</th><th>Cleanup</th></tr></thead>
            <tbody>
            <?php if (!$runs): ?><tr><td colspan="6" class="text-muted small ps-3 py-3">No restore check runs yet.</td></tr><?php endif; ?>
            <?php foreach ($runs as $r): [$l, $c] = RestoreCheckReport::badge($r['result']); ?>
                <tr>
                    <td class="ps-3 small"><a href="/restore-checks/runs/<?= (int) $r['id'] ?>">#<?= (int) $r['id'] ?></a>
                        <div class="text-muted"><?= $e(TimeHelper::format((string) $r['finished_at'], 'M j, Y g:i A')) ?></div></td>
                    <td class="small"><?= $e($r['client_name'] ?? '—') ?><?php if ((int) $r['client_deleted']): ?> <span class="badge text-bg-light border">deleted</span><?php endif; ?></td>
                    <td class="small"><?= $e($r['plan_name'] ?? '—') ?><?php if (!$r['plan_id']): ?> <span class="badge text-bg-light border">plan deleted</span><?php endif; ?></td>
                    <td><span class="badge text-bg-<?= $c ?>"><?= $e($l) ?></span></td>
                    <td class="small font-monospace text-break"><?= $e($r['archive_name'] ?? '—') ?></td>
                    <td class="small"><?= $e($r['cleanup_status']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
