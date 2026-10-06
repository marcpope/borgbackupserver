<?php $clientParam = $currentClient ? "&client={$currentClient}" : ''; ?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <select class="form-select form-select-sm d-inline-block" style="width: auto;" onchange="window.location='/log?client='+this.value<?= $currentLevel ? "+'&level={$currentLevel}'" : '' ?>">
            <option value="">All Clients</option>
            <?php foreach ($agents as $a): ?>
            <option value="<?= $a['id'] ?>" <?= $currentClient == $a['id'] ? 'selected' : '' ?>><?= htmlspecialchars($a['name']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div>
        <a href="/log<?= $clientParam ? '?' . ltrim($clientParam, '&') : '' ?>" class="btn btn-sm <?= empty($currentLevel) ? 'btn-primary' : 'btn-outline-secondary' ?>">All</a>
        <a href="/log?level=info<?= $clientParam ?>" class="btn btn-sm <?= $currentLevel === 'info' ? 'btn-info' : 'btn-outline-secondary' ?>">Info</a>
        <a href="/log?level=warning<?= $clientParam ?>" class="btn btn-sm <?= $currentLevel === 'warning' ? 'btn-warning' : 'btn-outline-secondary' ?>">Warning</a>
        <a href="/log?level=error<?= $clientParam ?>" class="btn btn-sm <?= $currentLevel === 'error' ? 'btn-danger' : 'btn-outline-secondary' ?>">Error</a>
        <?php $qsBase = '/log?' . http_build_query(array_filter(['level' => $currentLevel, 'client' => $currentClient ?: null, 'hours' => $currentHours ?: null])); ?>
        <a href="<?= $qsBase . ($showResolved ? '' : (str_contains($qsBase, '=') ? '&' : '') . 'resolved=1') ?>"
           class="btn btn-sm <?= $showResolved ? 'btn-secondary' : 'btn-outline-secondary' ?>" title="Include errors already marked resolved">
            <i class="bi bi-check2-circle"></i> <?= $showResolved ? 'Hide' : 'Show' ?> Resolved
        </a>
        <?php if ($this->isAdmin()): ?>
        <form method="POST" action="/log/resolve-all" class="d-inline" data-confirm="Mark ALL current errors as resolved? They will disappear from the dashboard and the default log view.">
            <input type="hidden" name="csrf_token" value="<?= $this->csrfToken() ?>">
            <input type="hidden" name="return" value="<?= htmlspecialchars($_SERVER['REQUEST_URI'] ?? '/log') ?>">
            <button type="submit" class="btn btn-sm btn-outline-success"><i class="bi bi-check2-all"></i> Resolve All Errors</button>
        </form>
        <?php endif; ?>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <?php if (empty($logs)): ?>
        <div class="p-4 text-muted text-center">No log entries.</div>
        <?php else: ?>
        <!-- Desktop table view -->
        <div class="table-responsive d-none d-md-block">
            <table class="table table-hover mb-0 small">
                <thead class="table-light">
                    <tr>
                        <th style="white-space: nowrap;">Time</th>
                        <th style="white-space: nowrap;">Job</th>
                        <th style="white-space: nowrap;">Client</th>
                        <th>Level</th>
                        <th>Message</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($logs as $log): ?>
                    <tr<?= $log['backup_job_id'] ? ' style="cursor:pointer" onclick="window.location=\'/queue/' . $log['backup_job_id'] . '\'"' : '' ?>>
                        <td class="small" style="white-space: nowrap;"><?= \BBS\Core\TimeHelper::format($log['created_at'], 'M j, g:i A') ?></td>
                        <td style="white-space: nowrap;">
                            <?php if ($log['backup_job_id']): ?>
                                <a href="/queue/<?= $log['backup_job_id'] ?>" class="text-decoration-none">#<?= $log['backup_job_id'] ?></a>
                            <?php else: ?>
                                --
                            <?php endif; ?>
                        </td>
                        <td style="white-space: nowrap;">
                            <?php if ($log['agent_id']): ?>
                                <a href="/clients/<?= $log['agent_id'] ?>" class="text-decoration-none"><?= htmlspecialchars($log['agent_name']) ?></a>
                            <?php else: ?>
                                --
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php
                            $lc = match($log['level']) {
                                'error' => 'danger',
                                'warning' => 'warning',
                                default => 'primary',
                            };
                            ?>
                            <span class="badge text-bg-<?= $lc ?>"><?= htmlspecialchars($log['level']) ?></span>
                        </td>
                        <td>
                            <?= htmlspecialchars($log['message']) ?>
                            <?php if (!empty($log['resolved_at'])): ?>
                                <span class="badge text-bg-success ms-1" title="Resolved <?= \BBS\Core\TimeHelper::format($log['resolved_at'], 'M j, g:i A') ?><?= !empty($log['resolved_by_name']) ? ' by ' . htmlspecialchars($log['resolved_by_name']) : '' ?>">
                                    <i class="bi bi-check2"></i> resolved
                                </span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end" style="white-space: nowrap;" onclick="event.stopPropagation()">
                            <?php if ($log['level'] === 'error' && $this->isAdmin()): ?>
                            <form method="POST" action="/log/<?= $log['id'] ?>/resolve" class="d-inline">
                                <input type="hidden" name="csrf_token" value="<?= $this->csrfToken() ?>">
                                <input type="hidden" name="return" value="<?= htmlspecialchars($_SERVER['REQUEST_URI'] ?? '/log') ?>">
                                <?php if (!empty($log['resolved_at'])): ?>
                                    <input type="hidden" name="unresolve" value="1">
                                    <button type="submit" class="btn btn-sm btn-outline-secondary py-0" title="Mark as unresolved">Unresolve</button>
                                <?php else: ?>
                                    <button type="submit" class="btn btn-sm btn-outline-success py-0" title="Mark as resolved — hides it from the dashboard and default log view">Resolve</button>
                                <?php endif; ?>
                            </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <!-- Mobile card view -->
        <div class="d-md-none">
            <?php foreach ($logs as $i => $log): ?>
            <?php
            $lc = match($log['level']) {
                'error' => 'danger',
                'warning' => 'warning',
                default => 'primary',
            };
            ?>
            <div class="p-3 <?= $i > 0 ? 'border-top' : '' ?>">
                <div class="d-flex justify-content-between align-items-start mb-1">
                    <span class="badge text-bg-<?= $lc ?>"><?= htmlspecialchars($log['level']) ?></span>
                    <small class="text-muted"><?= \BBS\Core\TimeHelper::format($log['created_at'], 'M j, g:i A') ?></small>
                </div>
                <?php if ($log['agent_id']): ?>
                <div class="small mb-1"><a href="/clients/<?= $log['agent_id'] ?>" class="text-decoration-none text-muted"><?= htmlspecialchars($log['agent_name']) ?></a></div>
                <?php endif; ?>
                <div class="small"><?= htmlspecialchars($log['message']) ?></div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php if ($pages > 1): ?>
<nav class="mt-3">
    <ul class="pagination pagination-sm justify-content-center mb-0">
        <?php
        $filterParams = ($currentLevel ? "&level={$currentLevel}" : '') . ($currentClient ? "&client={$currentClient}" : '');
        $maxVisible = 5;
        $start = max(1, $page - 2);
        $end = min($pages, $start + $maxVisible - 1);
        if ($end - $start < $maxVisible - 1) {
            $start = max(1, $end - $maxVisible + 1);
        }
        ?>
        <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
            <a class="page-link" href="/log?page=<?= $page - 1 ?><?= $filterParams ?>">«</a>
        </li>
        <?php if ($start > 1): ?>
        <li class="page-item"><a class="page-link" href="/log?page=1<?= $filterParams ?>">1</a></li>
        <?php if ($start > 2): ?><li class="page-item disabled"><span class="page-link">…</span></li><?php endif; ?>
        <?php endif; ?>
        <?php for ($p = $start; $p <= $end; $p++): ?>
        <li class="page-item <?= $p === $page ? 'active' : '' ?>">
            <a class="page-link" href="/log?page=<?= $p ?><?= $filterParams ?>"><?= $p ?></a>
        </li>
        <?php endfor; ?>
        <?php if ($end < $pages): ?>
        <?php if ($end < $pages - 1): ?><li class="page-item disabled"><span class="page-link">…</span></li><?php endif; ?>
        <li class="page-item"><a class="page-link" href="/log?page=<?= $pages ?><?= $filterParams ?>"><?= $pages ?></a></li>
        <?php endif; ?>
        <li class="page-item <?= $page >= $pages ? 'disabled' : '' ?>">
            <a class="page-link" href="/log?page=<?= $page + 1 ?><?= $filterParams ?>">»</a>
        </li>
    </ul>
</nav>
<div class="text-center text-muted small mt-2">
    Showing <?= number_format(($page - 1) * 50 + 1) ?>–<?= number_format(min($page * 50, $total)) ?> of <?= number_format($total) ?> entries
</div>
<?php endif; ?>
