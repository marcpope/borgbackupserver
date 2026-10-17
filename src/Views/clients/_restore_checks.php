<?php
/**
 * Restore checks section of the client's Restore tab.
 *
 * @var array      $agent
 * @var array|null $restoreChecks  RestoreCheckPlanService::summaryForAgent()
 */
use BBS\Core\TimeHelper;
use BBS\Services\PermissionService;
use BBS\Services\RestoreCheckReport;

$rcAgentId = (int) $agent['id'];
$rcCanView = $this->hasPermission(PermissionService::RESTORE, $rcAgentId);
$rcCanManage = $rcCanView && $this->hasPermission(PermissionService::MANAGE_PLANS, $rcAgentId);
$rcFmt = fn(?string $utc) => $utc ? TimeHelper::format($utc, 'M j, Y g:i A') : '—';
?>
<div class="card border-0 shadow-sm mt-4" id="restore-checks">
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
        <span class="fw-semibold"><i class="bi bi-clipboard-check me-1"></i> Restore checks</span>
        <?php if ($rcCanManage): ?>
        <a href="/clients/<?= $rcAgentId ?>/restore-checks/new" class="btn btn-sm btn-outline-primary"><i class="bi bi-plus-lg me-1"></i>New restore check</a>
        <?php endif; ?>
    </div>
    <div class="card-body">
        <p class="text-muted small mb-3">
            Scheduled, unattended extraction of an archive on the BBS server into temporary staging, validated and then deleted.
            It proves the backup can be read back; it does not restore onto this client, start a database or service, or measure a recovery time objective.
        </p>

        <?php if (!$rcCanView): ?>
            <div class="text-muted small"><i class="bi bi-lock me-1"></i>Restore check results need the Perform Restores permission for this client.</div>
        <?php elseif (empty($restoreChecks)): ?>
            <div class="text-muted small">No restore checks yet.<?= $rcCanManage ? ' Create one to verify this client\'s backups on a schedule.' : '' ?></div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead>
                    <tr class="small text-muted">
                        <th>Check</th>
                        <th>Status</th>
                        <th>Latest backup</th>
                        <th>Last verified restore</th>
                        <th>Next run</th>
                        <th class="text-end"></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($restoreChecks as $rc): ?>
                    <?php [$rcLabel, $rcColor] = RestoreCheckReport::badge($rc['status']); ?>
                    <tr>
                        <td>
                            <a href="/clients/<?= $rcAgentId ?>/restore-checks/<?= (int) $rc['id'] ?>" class="fw-semibold"><?= htmlspecialchars($rc['name']) ?></a>
                            <div class="small text-muted">
                                <?= $rc['mode'] === 'full' ? 'Full archive' : 'Selected paths' ?>
                                · <?= htmlspecialchars($rc['repository_name'] ?? 'repository deleted') ?>
                                <?php if ($rc['scope'] === 'plan'): ?>
                                    · plan <?= htmlspecialchars($rc['source_plan_name'] ?? 'deleted') ?>
                                <?php else: ?>
                                    · repository-wide
                                <?php endif; ?>
                            </div>
                        </td>
                        <td>
                            <span class="badge text-bg-<?= $rcColor ?>"><?= htmlspecialchars($rcLabel) ?></span>
                            <?php if (!(int) $rc['enabled']): ?><span class="badge text-bg-light border">Disabled</span><?php endif; ?>
                            <?php if (!empty($rc['latest_run']['reason_code']) && in_array($rc['status'], ['failed', 'warning', 'overdue'], true)): ?>
                                <div class="small text-muted text-truncate" style="max-width:16rem" title="<?= htmlspecialchars((string) $rc['latest_run']['reason_detail']) ?>"><?= htmlspecialchars($rc['latest_run']['reason_code']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="small"><?= $rcFmt($rc['latest_backup_at']) ?></td>
                        <td class="small"><?= $rcFmt($rc['last_strict_success_at']) ?></td>
                        <td class="small">
                            <?php if ((int) $rc['enabled'] && $rc['next_due_at']): ?>
                                <?php $rcNext = (new \DateTimeImmutable($rc['next_due_at'], new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone($rc['timezone'])); ?>
                                <?= htmlspecialchars($rcNext->format('M j, Y H:i')) ?> <span class="text-muted"><?= htmlspecialchars($rc['timezone']) ?></span>
                            <?php else: ?>
                                <span class="text-muted">Not scheduled</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end text-nowrap">
                            <form method="POST" action="/clients/<?= $rcAgentId ?>/restore-checks/<?= (int) $rc['id'] ?>/run" class="d-inline">
                                <input type="hidden" name="csrf_token" value="<?= $this->csrfToken() ?>">
                                <input type="hidden" name="idempotency_key" value="<?= bin2hex(random_bytes(16)) ?>">
                                <button type="submit" class="btn btn-sm btn-outline-primary" <?= in_array($rc['status'], ['queued', 'running'], true) ? 'disabled' : '' ?>><i class="bi bi-play-fill me-1"></i>Run now</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>
