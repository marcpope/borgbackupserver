<div class="d-flex justify-content-end mb-3">
    <form method="POST" action="/notifications/read-all">
        <input type="hidden" name="csrf_token" value="<?= $this->csrfToken() ?>">
        <button type="submit" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-check2-all me-1"></i> Mark All as Read
        </button>
    </form>
</div>

<?php if (empty($notifications)): ?>
    <div class="text-muted text-center py-5">No notifications.</div>
<?php else: ?>
<div class="table-responsive">
    <table class="table table-sm table-hover align-middle small mb-0">
        <thead>
            <tr>
                <th style="width:32px"></th>
                <th>Message</th>
                <th>Client</th>
                <th>Severity</th>
                <th>Last Occurred</th>
                <th>Status</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($notifications as $n):
                $resolved = $n['resolved_at'] !== null;
                $unread = $n['read_at'] === null;
                $rowClass = $resolved ? 'text-muted text-decoration-line-through' : ($unread ? 'fw-semibold' : '');

                $iconMap = [
                    'backup_failed' => 'bi-x-circle-fill text-danger',
                    'agent_offline' => 'bi-wifi-off text-warning',
                    'storage_low' => 'bi-hdd text-warning',
                    'missed_schedule' => 'bi-clock-history text-warning',
                ];
                $icon = $iconMap[$n['type']] ?? 'bi-bell text-secondary';
            ?>
            <tr class="<?= $rowClass ?>">
                <td><i class="bi <?= $icon ?>"></i></td>
                <td>
                    <?= htmlspecialchars($n['message']) ?>
                    <?php if ($n['occurrence_count'] > 1): ?>
                        <span class="badge bg-secondary ms-1"><?= (int)$n['occurrence_count'] ?> occurrences</span>
                    <?php endif; ?>
                </td>
                <td><?= htmlspecialchars($n['agent_name'] ?? '—') ?></td>
                <td>
                    <?php if ($n['severity'] === 'critical'): ?>
                        <span class="badge text-bg-danger">Critical</span>
                    <?php elseif ($n['severity'] === 'info'): ?>
                        <span class="badge text-bg-primary">Info</span>
                    <?php else: ?>
                        <span class="badge text-bg-warning">Warning</span>
                    <?php endif; ?>
                </td>
                <td class="small"><?= \BBS\Core\TimeHelper::format($n['last_occurred_at'], 'M j, g:i A') ?></td>
                <td>
                    <?php if ($resolved): ?>
                        <span class="badge text-bg-success">Resolved</span>
                    <?php elseif (!$unread): ?>
                        <span class="badge text-bg-secondary">Read</span>
                    <?php else: ?>
                        <span class="badge text-bg-primary">New</span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ($unread && !$resolved): ?>
                    <form method="POST" action="/notifications/<?= (int)$n['id'] ?>/read" class="d-inline">
                        <input type="hidden" name="csrf_token" value="<?= $this->csrfToken() ?>">
                        <button type="submit" class="btn btn-sm btn-outline-secondary" title="Mark as read">
                            <i class="bi bi-check2"></i>
                        </button>
                    </form>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>
