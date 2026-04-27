<?php
// Event types grouped by category
$eventGroups = [
    'Backups' => [
        'backup_completed' => 'Backup Completed',
        'backup_warning' => 'Backup Completed with Warnings',
        'backup_failed' => 'Backup Failed',
    ],
    'Restores' => [
        'restore_completed' => 'Restore Completed',
        'restore_failed' => 'Restore Failed',
    ],
    'Clients' => [
        'agent_offline' => 'Client Offline',
        'agent_online' => 'Client Online',
    ],
    'Repositories' => [
        'repo_check_failed' => 'Check Failed',
        'repo_compact_done' => 'Compact Done',
    ],
    'Storage' => [
        'storage_low' => 'Storage Low',
        's3_sync_failed' => 'S3 Sync Failed',
        's3_sync_done' => 'S3 Sync Done',
    ],
    'Schedules' => [
        'missed_schedule' => 'Missed Schedule',
    ],
];
// Flatten for easy lookup
$eventTypes = [];
foreach ($eventGroups as $events) {
    $eventTypes = array_merge($eventTypes, $events);
}
// Colors by event type
$eventColors = [
    // Success events - green
    'backup_completed' => 'success',
    'restore_completed' => 'success',
    'agent_online' => 'success',
    'repo_compact_done' => 'success',
    's3_sync_done' => 'success',
    // Failure events - red
    'backup_failed' => 'danger',
    'restore_failed' => 'danger',
    'repo_check_failed' => 'danger',
    's3_sync_failed' => 'danger',
    // Warning events - orange/warning
    'backup_warning' => 'warning',
    'agent_offline' => 'warning',
    'storage_low' => 'warning',
    'missed_schedule' => 'warning',
];
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h4 class="mb-1">Notification Services</h4>
        <p class="text-muted mb-0 small">Configure notification services for backup and restore alerts</p>
    </div>
    <button class="btn btn-success" data-bs-toggle="collapse" data-bs-target="#addServiceForm">
        <i class="bi bi-plus-circle me-1"></i> Add Service
    </button>
</div>

<!-- Info banner -->
<div class="alert alert-light border mb-4">
    <div class="d-flex align-items-start">
        <i class="bi bi-info-circle text-primary me-2 mt-1"></i>
        <div>
            <span>Get notified about backup failures, restore completions, and scheduled job issues via 100+ services including Email, Slack, Discord, Telegram, Pushover, and more.</span>
            <a class="d-block mt-1 small" data-bs-toggle="collapse" href="#urlExamples" role="button">
                <i class="bi bi-chevron-down me-1"></i>Show Service URL Examples
            </a>
            <div class="collapse mt-2" id="urlExamples">
                <div class="bg-body-secondary rounded p-3 font-monospace small">
                    <div class="row">
                        <div class="col-md-6">
                            <strong>Discord:</strong> discord://webhook_id/webhook_token<br>
                            <strong>Telegram:</strong> tgram://bot_token/chat_id[:thread]<br>
                            <strong>Slack:</strong> slack://tokenA/tokenB/tokenC<br>
                            <strong>Pushover:</strong> pover://user@token
                        </div>
                        <div class="col-md-6">
                            <strong>ntfy:</strong> ntfy://topic<br>
                            <strong>Gotify:</strong> gotify://hostname/token<br>
                            <strong>Email:</strong> mailto://user:pass@smtp.example.com<br>
                            <strong>Webhook:</strong> json://your-webhook-url
                        </div>
                    </div>
                    <div class="mt-2">
                        <a href="https://github.com/caronc/apprise/wiki#notification-services" target="_blank" class="text-decoration-none">
                            View all supported services <i class="bi bi-box-arrow-up-right"></i>
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Add Service Form (Collapse) -->
<div class="collapse mb-4" id="addServiceForm">
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-body fw-semibold">
            <i class="bi bi-plus-circle me-1"></i> Add Notification Service
        </div>
        <div class="card-body">
            <form method="POST" action="/notification-services" id="addServiceFormEl">
                <input type="hidden" name="csrf_token" value="<?= $this->csrfToken() ?>">

                <div class="row g-3 mb-3">
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Service Type</label>
                        <select class="form-select" id="addServiceType" name="service_type">
                            <option value="">-- Select a service --</option>
                            <option value="email">Email (SMTP)</option>
                            <option value="discord">Discord</option>
                            <option value="slack">Slack</option>
                            <option value="tgram">Telegram</option>
                            <option value="pover">Pushover</option>
                            <option value="ntfy">ntfy</option>
                            <option value="gotify">Gotify</option>
                            <option value="msteams">Microsoft Teams</option>
                            <option value="custom">Other / Custom URL</option>
                        </select>
                        <div class="form-text">Choose a service or select "Other" for custom URLs</div>
                    </div>
                    <div class="col-md-8">
                        <label class="form-label fw-semibold">Service Name</label>
                        <input type="text" class="form-control" name="name" id="addServiceName" placeholder="e.g., Discord Alerts" required>
                        <div class="form-text">A friendly name to identify this service</div>
                    </div>
                </div>

                <!-- Dynamic form fields container -->
                <div id="addServiceFields" class="mb-3" style="display:none;"></div>

                <!-- Raw URL field (shown for custom or as toggle) -->
                <div id="addUrlContainer" class="mb-3" style="display:none;">
                    <div class="d-flex align-items-center mb-2">
                        <label class="form-label fw-semibold mb-0">Apprise URL</label>
                        <button type="button" class="btn btn-sm btn-link text-decoration-none ms-auto" id="toggleAddUrlMode" style="display:none;">
                            <i class="bi bi-code-slash me-1"></i>Edit Raw URL
                        </button>
                    </div>
                    <input type="text" class="form-control font-monospace" name="apprise_url" id="addAppriseUrl"
                           placeholder="Enter your Apprise URL" required>
                    <div class="form-text" id="addUrlHelp">
                        See <a href="https://github.com/caronc/apprise/wiki#notification-services" target="_blank">
                        Apprise documentation</a> for URL formats
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label fw-semibold">Notify on:</label>
                    <div class="row">
                        <?php foreach ($eventGroups as $groupName => $events): ?>
                        <div class="col-lg-4 col-md-6 mb-3">
                            <div class="small text-muted fw-semibold mb-1"><?= htmlspecialchars($groupName) ?></div>
                            <?php foreach ($events as $event => $label): ?>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="events[<?= $event ?>]"
                                       value="1" id="addEvent_<?= $event ?>"
                                       <?= str_contains($event, 'failed') || $event === 'agent_offline' || $event === 'storage_low' || $event === 'missed_schedule' ? 'checked' : '' ?>>
                                <label class="form-check-label" for="addEvent_<?= $event ?>">
                                    <?= htmlspecialchars($label) ?>
                                </label>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="form-text">Failure and warning events are selected by default. Enable success events if you want confirmation of successful operations.</div>
                </div>

                <div>
                    <button type="submit" class="btn btn-success">
                        <i class="bi bi-plus-circle me-1"></i> Create Service
                    </button>
                    <button type="button" class="btn btn-outline-secondary ms-2" data-bs-toggle="collapse" data-bs-target="#addServiceForm">
                        Cancel
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Services Table -->
<?php if (!empty($services)): ?>
<div class="card border-0 shadow-sm">
    <div class="table-responsive d-none d-md-block">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>Service</th>
                    <th class="text-center" style="width: 100px;">Status</th>
                    <th>Events</th>
                    <th style="width: 150px;">Last Used</th>
                    <th class="text-end" style="width: 150px;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($services as $service): ?>
                <tr>
                    <td>
                        <div class="fw-semibold"><?= htmlspecialchars($service['name']) ?></div>
                        <div class="text-muted small font-monospace text-truncate" style="max-width: 300px;" title="<?= htmlspecialchars($service['apprise_url']) ?>">
                            <?= htmlspecialchars($service['apprise_url']) ?>
                        </div>
                    </td>
                    <td class="text-center">
                        <?php if ($service['enabled']): ?>
                        <span class="badge bg-success-subtle text-success border border-success-subtle">
                            <i class="bi bi-check-circle me-1"></i>Enabled
                        </span>
                        <?php else: ?>
                        <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle">
                            <i class="bi bi-pause-circle me-1"></i>Disabled
                        </span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php
                        $enabledEvents = array_keys(array_filter($service['events']));
                        $maxShow = 3;
                        $shown = 0;
                        ?>
                        <div class="d-flex flex-wrap gap-1">
                            <?php foreach ($enabledEvents as $event): ?>
                                <?php if ($shown < $maxShow): ?>
                                <?php $color = $eventColors[$event] ?? 'secondary'; ?>
                                <span class="badge bg-<?= $color ?>-subtle text-<?= $color ?> border border-<?= $color ?>-subtle">
                                    <?= htmlspecialchars($eventTypes[$event] ?? ucfirst(str_replace('_', ' ', $event))) ?>
                                </span>
                                <?php $shown++; endif; ?>
                            <?php endforeach; ?>
                            <?php if (count($enabledEvents) > $maxShow): ?>
                            <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle" title="<?= htmlspecialchars(implode(', ', array_map(fn($e) => $eventTypes[$e] ?? $e, array_slice($enabledEvents, $maxShow)))) ?>">
                                +<?= count($enabledEvents) - $maxShow ?> more
                            </span>
                            <?php elseif (empty($enabledEvents)): ?>
                            <span class="text-muted small">No events selected</span>
                            <?php endif; ?>
                        </div>
                    </td>
                    <td>
                        <?php if ($service['last_used_at']): ?>
                        <span class="small text-muted" title="<?= htmlspecialchars($service['last_used_at']) ?>">
                            <?= \BBS\Core\TimeHelper::format($service['last_used_at'], 'M j, Y') ?><br>
                            <?= \BBS\Core\TimeHelper::format($service['last_used_at'], 'g:i A') ?>
                        </span>
                        <?php else: ?>
                        <span class="text-muted small">Never</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-end text-nowrap">
                        <button class="btn btn-sm btn-outline-primary border-0" onclick="testService(<?= $service['id'] ?>, this)" title="Test">
                            <i class="bi bi-lightning"></i>
                        </button>
                        <form method="POST" action="/notification-services/<?= $service['id'] ?>/duplicate" class="d-inline">
                            <input type="hidden" name="csrf_token" value="<?= $this->csrfToken() ?>">
                            <button type="submit" class="btn btn-sm btn-outline-secondary border-0" title="Duplicate">
                                <i class="bi bi-copy"></i>
                            </button>
                        </form>
                        <button class="btn btn-sm btn-outline-secondary border-0" type="button"
                                data-bs-toggle="collapse" data-bs-target="#edit_<?= $service['id'] ?>" title="Edit">
                            <i class="bi bi-pencil"></i>
                        </button>
                        <form method="POST" action="/notification-services/<?= $service['id'] ?>/delete" class="d-inline"
                              data-confirm="Delete this notification service?" data-confirm-danger>
                            <input type="hidden" name="csrf_token" value="<?= $this->csrfToken() ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger border-0" title="Delete">
                                <i class="bi bi-trash"></i>
                            </button>
                        </form>
                    </td>
                </tr>
                <!-- Edit form (collapsed row) -->
                <tr class="collapse" id="edit_<?= $service['id'] ?>">
                    <td colspan="5" class="bg-body-secondary">
                        <form method="POST" action="/notification-services/<?= $service['id'] ?>/update" class="p-3">
                            <input type="hidden" name="csrf_token" value="<?= $this->csrfToken() ?>">

                            <div class="row g-3 mb-3">
                                <div class="col-md-4">
                                    <label class="form-label fw-semibold">Service Name</label>
                                    <input type="text" class="form-control form-control-sm" name="name"
                                           value="<?= htmlspecialchars($service['name']) ?>" required>
                                </div>
                                <div class="col-md-8">
                                    <label class="form-label fw-semibold">Apprise URL</label>
                                    <input type="text" class="form-control form-control-sm font-monospace" name="apprise_url"
                                           value="<?= htmlspecialchars($service['apprise_url']) ?>" required>
                                </div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label fw-semibold">Notify on:</label>
                                <div class="row">
                                    <?php foreach ($eventGroups as $groupName => $events): ?>
                                    <div class="col-lg-4 col-md-6 mb-2">
                                        <div class="small text-muted fw-semibold mb-1"><?= htmlspecialchars($groupName) ?></div>
                                        <?php foreach ($events as $event => $label): ?>
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" name="events[<?= $event ?>]"
                                                   value="1" id="editEvent_<?= $service['id'] ?>_<?= $event ?>"
                                                   <?= ($service['events'][$event] ?? false) ? 'checked' : '' ?>>
                                            <label class="form-check-label" for="editEvent_<?= $service['id'] ?>_<?= $event ?>">
                                                <?= htmlspecialchars($label) ?>
                                            </label>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>

                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <button type="submit" class="btn btn-sm btn-primary">
                                        <i class="bi bi-check-circle me-1"></i> Save Changes
                                    </button>
                                    <button type="button" class="btn btn-sm btn-outline-secondary ms-2"
                                            data-bs-toggle="collapse" data-bs-target="#edit_<?= $service['id'] ?>">
                                        Cancel
                                    </button>
                                </div>
                                <div>
                                    <form method="POST" action="/notification-services/<?= $service['id'] ?>/toggle" class="d-inline toggle-form">
                                        <input type="hidden" name="csrf_token" value="<?= $this->csrfToken() ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-<?= $service['enabled'] ? 'warning' : 'success' ?>">
                                            <i class="bi bi-<?= $service['enabled'] ? 'pause-circle' : 'play-circle' ?> me-1"></i>
                                            <?= $service['enabled'] ? 'Disable' : 'Enable' ?>
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <!-- Mobile card view -->
    <div class="d-md-none">
        <?php foreach ($services as $service): ?>
        <div class="p-3 <?= $service !== end($services) ? 'border-bottom' : '' ?>">
            <div class="d-flex justify-content-between align-items-start mb-2">
                <div>
                    <span class="fw-semibold"><?= htmlspecialchars($service['name']) ?></span>
                    <?php if ($service['enabled']): ?>
                    <span class="badge bg-success-subtle text-success border border-success-subtle ms-2">Enabled</span>
                    <?php else: ?>
                    <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle ms-2">Disabled</span>
                    <?php endif; ?>
                </div>
                <div class="text-nowrap">
                    <button class="btn btn-sm btn-outline-primary border-0" onclick="testService(<?= $service['id'] ?>, this)" title="Test">
                        <i class="bi bi-lightning"></i>
                    </button>
                    <button class="btn btn-sm btn-outline-secondary border-0" type="button"
                            data-bs-toggle="collapse" data-bs-target="#editMobile_<?= $service['id'] ?>" title="Edit">
                        <i class="bi bi-pencil"></i>
                    </button>
                    <form method="POST" action="/notification-services/<?= $service['id'] ?>/delete" class="d-inline"
                          data-confirm="Delete this notification service?" data-confirm-danger>
                        <input type="hidden" name="csrf_token" value="<?= $this->csrfToken() ?>">
                        <button type="submit" class="btn btn-sm btn-outline-danger border-0" title="Delete">
                            <i class="bi bi-trash"></i>
                        </button>
                    </form>
                </div>
            </div>
            <?php
            $enabledEvents = array_keys(array_filter($service['events']));
            $maxShow = 3;
            $shown = 0;
            ?>
            <div class="d-flex flex-wrap gap-1 mb-1">
                <?php foreach ($enabledEvents as $event): ?>
                    <?php if ($shown < $maxShow): ?>
                    <?php $color = $eventColors[$event] ?? 'secondary'; ?>
                    <span class="badge bg-<?= $color ?>-subtle text-<?= $color ?> border border-<?= $color ?>-subtle">
                        <?= htmlspecialchars($eventTypes[$event] ?? ucfirst(str_replace('_', ' ', $event))) ?>
                    </span>
                    <?php $shown++; endif; ?>
                <?php endforeach; ?>
                <?php if (count($enabledEvents) > $maxShow): ?>
                <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle">
                    +<?= count($enabledEvents) - $maxShow ?> more
                </span>
                <?php elseif (empty($enabledEvents)): ?>
                <span class="text-muted small">No events selected</span>
                <?php endif; ?>
            </div>
            <?php if ($service['last_used_at']): ?>
            <div class="text-muted small"><i class="bi bi-clock me-1"></i><?= \BBS\Core\TimeHelper::format($service['last_used_at'], 'M j, Y g:i A') ?></div>
            <?php endif; ?>
            <!-- Mobile edit form -->
            <div class="collapse mt-3" id="editMobile_<?= $service['id'] ?>">
                <form method="POST" action="/notification-services/<?= $service['id'] ?>/update">
                    <input type="hidden" name="csrf_token" value="<?= $this->csrfToken() ?>">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Service Name</label>
                        <input type="text" class="form-control form-control-sm" name="name"
                               value="<?= htmlspecialchars($service['name']) ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Apprise URL</label>
                        <input type="text" class="form-control form-control-sm font-monospace" name="apprise_url"
                               value="<?= htmlspecialchars($service['apprise_url']) ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Notify on:</label>
                        <?php foreach ($eventGroups as $groupName => $events): ?>
                        <div class="small text-muted fw-semibold mb-1 mt-2"><?= htmlspecialchars($groupName) ?></div>
                        <?php foreach ($events as $event => $label): ?>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="events[<?= $event ?>]"
                                   value="1" id="editMobileEvent_<?= $service['id'] ?>_<?= $event ?>"
                                   <?= ($service['events'][$event] ?? false) ? 'checked' : '' ?>>
                            <label class="form-check-label" for="editMobileEvent_<?= $service['id'] ?>_<?= $event ?>">
                                <?= htmlspecialchars($label) ?>
                            </label>
                        </div>
                        <?php endforeach; ?>
                        <?php endforeach; ?>
                    </div>
                    <div class="d-flex justify-content-between">
                        <div>
                            <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-check-circle me-1"></i>Save</button>
                            <button type="button" class="btn btn-sm btn-outline-secondary ms-2"
                                    data-bs-toggle="collapse" data-bs-target="#editMobile_<?= $service['id'] ?>">Cancel</button>
                        </div>
                        <form method="POST" action="/notification-services/<?= $service['id'] ?>/toggle" class="d-inline toggle-form">
                            <input type="hidden" name="csrf_token" value="<?= $this->csrfToken() ?>">
                            <button type="submit" class="btn btn-sm btn-outline-<?= $service['enabled'] ? 'warning' : 'success' ?>">
                                <?= $service['enabled'] ? 'Disable' : 'Enable' ?>
                            </button>
                        </form>
                    </div>
                </form>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php else: ?>
<div class="card border-0 shadow-sm">
    <div class="card-body p-5 text-center">
        <i class="bi bi-megaphone text-muted" style="font-size: 3rem;"></i>
        <h5 class="mt-3">No Notification Services</h5>
        <p class="text-muted mb-3">Add a notification service to receive alerts about backup failures, client status changes, and more.</p>
        <button class="btn btn-success" data-bs-toggle="collapse" data-bs-target="#addServiceForm">
            <i class="bi bi-plus-circle me-1"></i> Add Your First Service
        </button>
    </div>
</div>
<?php endif; ?>

<!-- Test Result Toast -->
<div class="position-fixed bottom-0 end-0 p-3" style="z-index: 1070;">
    <div id="testToast" class="toast align-items-center border-0" role="alert">
        <div class="d-flex">
            <div class="toast-body" id="testToastBody"></div>
            <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
        </div>
    </div>
</div>

<script>
function testService(id, btn) {
    const originalHtml = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';

    fetch(`/notification-services/${id}/test`, {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'csrf_token=' + encodeURIComponent(document.querySelector('input[name=csrf_token]').value)
    })
    .then(r => r.json())
    .then(data => {
        btn.disabled = false;
        btn.innerHTML = originalHtml;

        const toast = document.getElementById('testToast');
        const toastBody = document.getElementById('testToastBody');

        toast.classList.remove('bg-success', 'bg-danger', 'text-white');

        if (data.success) {
            toast.classList.add('bg-success', 'text-white');
            toastBody.textContent = 'Test notification sent successfully!';
        } else {
            toast.classList.add('bg-danger', 'text-white');
            toastBody.textContent = 'Failed: ' + (data.error || 'Unknown error');
        }

        const bsToast = new bootstrap.Toast(toast, {delay: 4000});
        bsToast.show();
    })
    .catch(err => {
        btn.disabled = false;
        btn.innerHTML = originalHtml;
        console.error(err);
    });
}

// Handle toggle form submission with page reload
document.querySelectorAll('.toggle-form').forEach(form => {
    form.addEventListener('submit', function(e) {
        // Let it submit normally and reload
    });
});

// Service schemas for form builder
const serviceSchemas = {
    email: {
        label: 'Email (SMTP)',
        fields: [
            { name: 'smtp_host', label: 'SMTP Server', type: 'text', required: true, placeholder: 'smtp.gmail.com', width: 'col-md-4' },
            { name: 'smtp_port', label: 'Port', type: 'number', placeholder: '587', width: 'col-md-2', default: '587' },
            { name: 'smtp_user', label: 'Username', type: 'text', placeholder: 'user@gmail.com (optional)', width: 'col-md-6' },
            { name: 'smtp_pass', label: 'Password', type: 'password', placeholder: 'App password (optional)', width: 'col-md-6' },
            { name: 'smtp_to', label: 'Send To', type: 'email', required: true, placeholder: 'recipient@example.com', width: 'col-md-6' },
            { name: 'smtp_from', label: 'From Address', type: 'email', placeholder: 'Same as username', width: 'col-md-6', help: 'Leave blank to use username' },
            { name: 'smtp_secure', label: 'Security', type: 'select', width: 'col-md-3', default: 'starttls', options: [
                { value: 'starttls', label: 'STARTTLS (587)' },
                { value: 'ssl', label: 'SSL/TLS (465)' },
                { value: 'none', label: 'None (25)' }
            ]}
        ],
        build: function(f) {
            const user = encodeURIComponent(f.smtp_user || '');
            const pass = encodeURIComponent(f.smtp_pass || '');
            const host = f.smtp_host || '';
            const port = f.smtp_port || '587';
            const to = encodeURIComponent(f.smtp_to || '');
            const from = encodeURIComponent(f.smtp_from || f.smtp_user || f.smtp_to || '');
            let mode = '';
            if (f.smtp_secure === 'ssl') mode = 'mailtos';
            else if (f.smtp_secure === 'none') mode = 'mailto';
            else mode = 'mailto';
            const auth = (f.smtp_user || f.smtp_pass) ? `${user}:${pass}@` : '';
            return `${mode}://${auth}${host}:${port}?to=${to}&from=${from}`;
        }
    },
    discord: {
        label: 'Discord',
        fields: [
            { name: 'webhook_id', label: 'Webhook ID', type: 'text', required: true, placeholder: '123456789012345678', width: 'col-md-4' },
            { name: 'webhook_token', label: 'Webhook Token', type: 'text', required: true, placeholder: 'abcdefg...', width: 'col-md-8' }
        ],
        help: 'Get these from Discord: Server Settings → Integrations → Webhooks → Copy Webhook URL, then extract the ID and token from: discord.com/api/webhooks/<strong>ID</strong>/<strong>TOKEN</strong>',
        build: function(f) {
            return `discord://${f.webhook_id || ''}/${f.webhook_token || ''}`;
        }
    },
    slack: {
        label: 'Slack',
        fields: [
            { name: 'token_a', label: 'Token A', type: 'text', required: true, placeholder: 'T1234567', width: 'col-md-4' },
            { name: 'token_b', label: 'Token B', type: 'text', required: true, placeholder: 'B1234567', width: 'col-md-4' },
            { name: 'token_c', label: 'Token C', type: 'text', required: true, placeholder: 'AbCdEf123...', width: 'col-md-4' },
            { name: 'channel', label: 'Channel (optional)', type: 'text', placeholder: '#alerts', width: 'col-md-4' }
        ],
        help: 'Get tokens from your Slack Incoming Webhook URL: hooks.slack.com/services/<strong>A</strong>/<strong>B</strong>/<strong>C</strong>',
        build: function(f) {
            let url = `slack://${f.token_a || ''}/${f.token_b || ''}/${f.token_c || ''}`;
            if (f.channel) url += `/${encodeURIComponent(f.channel.replace(/^#/, ''))}`;
            return url;
        }
    },
    tgram: {
        label: 'Telegram',
        fields: [
            { name: 'bot_token', label: 'Bot Token', type: 'text', required: true, placeholder: '123456789:ABCdefGHI...', width: 'col-md-6' },
            { name: 'chat_id', label: 'Chat ID', type: 'text', required: true, placeholder: '-1001234567890', width: 'col-md-4' },
            { name: 'thread', label: 'Thread (optional)', type: 'text', placeholder: '1234567', width: 'col-md-2' }
        ],
        help: 'Create a bot via @BotFather, then get chat ID by messaging @userinfobot or from group info. Use Thread for a Telegram topic ID.',
        build: function(f) {
            const chatId = f.chat_id || '';
            const thread = (f.thread || '').trim();
            const target = thread ? `${chatId}:${encodeURIComponent(thread)}` : chatId;
            return `tgram://${f.bot_token || ''}/${target}`;
        }
    },
    pover: {
        label: 'Pushover',
        fields: [
            { name: 'user_key', label: 'User Key', type: 'text', required: true, placeholder: 'Your user key', width: 'col-md-6' },
            { name: 'api_token', label: 'API Token', type: 'text', required: true, placeholder: 'Your app API token', width: 'col-md-6' }
        ],
        help: 'Find these in your <a href="https://pushover.net/" target="_blank">Pushover dashboard</a>',
        build: function(f) {
            return `pover://${f.user_key || ''}@${f.api_token || ''}`;
        }
    },
    ntfy: {
        label: 'ntfy',
        fields: [
            { name: 'topic', label: 'Topic', type: 'text', required: true, placeholder: 'my-backup-alerts', width: 'col-md-6' },
            { name: 'server', label: 'Server (optional)', type: 'text', placeholder: 'ntfy.sh', width: 'col-md-6' }
        ],
        help: 'Subscribe to the topic in your ntfy app. Default server is ntfy.sh',
        build: function(f) {
            if (f.server && f.server !== 'ntfy.sh') {
                return `ntfy://${f.server}/${f.topic || ''}`;
            }
            return `ntfy://${f.topic || ''}`;
        }
    },
    gotify: {
        label: 'Gotify',
        fields: [
            { name: 'hostname', label: 'Server', type: 'text', required: true, placeholder: 'gotify.example.com', width: 'col-md-6' },
            { name: 'token', label: 'App Token', type: 'text', required: true, placeholder: 'AbCdEf123...', width: 'col-md-6' }
        ],
        build: function(f) {
            return `gotify://${f.hostname || ''}/${f.token || ''}`;
        }
    },
    msteams: {
        label: 'Microsoft Teams',
        fields: [
            { name: 'webhook_url', label: 'Webhook URL', type: 'text', required: true, placeholder: 'https://outlook.office.com/webhook/...', width: 'col-12' }
        ],
        help: 'Get the Incoming Webhook URL from your Teams channel connector settings',
        build: function(f) {
            const url = f.webhook_url || '';
            if (url.startsWith('https://')) {
                return `msteams://${url.replace('https://', '')}`;
            }
            return url;
        }
    }
};

// Form builder function
function buildServiceForm(containerId, schema, prefix, existingValues) {
    const container = document.getElementById(containerId);
    if (!container || !schema) return;

    existingValues = existingValues || {};
    let html = '<div class="row g-3">';

    schema.fields.forEach(function(field) {
        const fieldId = prefix + '_' + field.name;
        const value = existingValues[field.name] || field.default || '';
        const required = field.required ? 'required' : '';
        const width = field.width || 'col-md-6';

        html += `<div class="${width}">`;
        html += `<label class="form-label small fw-semibold" for="${fieldId}">${field.label}${field.required ? ' <span class="text-danger">*</span>' : ''}</label>`;

        if (field.type === 'select') {
            html += `<select class="form-select form-select-sm" id="${fieldId}" name="${field.name}" ${required}>`;
            field.options.forEach(function(opt) {
                const selected = opt.value === value ? 'selected' : '';
                html += `<option value="${opt.value}" ${selected}>${opt.label}</option>`;
            });
            html += '</select>';
        } else {
            html += `<input type="${field.type}" class="form-control form-control-sm" id="${fieldId}" name="${field.name}" `;
            html += `placeholder="${field.placeholder || ''}" value="${value.replace(/"/g, '&quot;')}" ${required}>`;
        }

        html += '</div>';
    });

    html += '</div>';

    if (schema.help) {
        html += `<div class="form-text mt-2">${schema.help}</div>`;
    }

    container.innerHTML = html;
    container.style.display = 'block';

    // Add event listeners to rebuild URL on field change
    container.querySelectorAll('input, select').forEach(function(el) {
        el.addEventListener('input', function() {
            updateBuiltUrl(containerId, schema, prefix);
        });
        el.addEventListener('change', function() {
            updateBuiltUrl(containerId, schema, prefix);
        });
    });
}

// Update the hidden URL field based on form values
function updateBuiltUrl(containerId, schema, prefix) {
    const container = document.getElementById(containerId);
    const urlField = document.getElementById(prefix === 'add' ? 'addAppriseUrl' : 'editAppriseUrl');
    if (!container || !urlField || !schema.build) return;

    const values = {};
    container.querySelectorAll('input, select').forEach(function(el) {
        values[el.name] = el.value;
    });

    urlField.value = schema.build(values);
}

// Service type dropdown handler
(function() {
    const serviceType = document.getElementById('addServiceType');
    const fieldsContainer = document.getElementById('addServiceFields');
    const urlContainer = document.getElementById('addUrlContainer');
    const appriseUrl = document.getElementById('addAppriseUrl');
    const serviceName = document.getElementById('addServiceName');
    const toggleBtn = document.getElementById('toggleAddUrlMode');

    if (!serviceType) return;

    let rawUrlMode = false;

    serviceType.addEventListener('change', function() {
        const type = this.value;
        const schema = serviceSchemas[type];

        // Auto-fill service name
        if (serviceName) {
            const currentName = serviceName.value.trim();
            const defaultNames = Object.values(serviceSchemas).map(s => s.label);
            if (!currentName || defaultNames.includes(currentName)) {
                serviceName.value = schema ? schema.label : '';
            }
        }

        if (type === 'custom') {
            // Show raw URL field only
            fieldsContainer.style.display = 'none';
            fieldsContainer.innerHTML = '';
            urlContainer.style.display = 'block';
            appriseUrl.value = '';
            appriseUrl.readOnly = false;
            appriseUrl.required = true;
            if (toggleBtn) toggleBtn.style.display = 'none';
            rawUrlMode = true;
        } else if (schema) {
            // Show form builder
            buildServiceForm('addServiceFields', schema, 'add', {});
            urlContainer.style.display = 'none';
            appriseUrl.required = true;
            if (toggleBtn) toggleBtn.style.display = 'inline-block';
            rawUrlMode = false;

            // Initial URL build
            updateBuiltUrl('addServiceFields', schema, 'add');
        } else {
            // No selection
            fieldsContainer.style.display = 'none';
            fieldsContainer.innerHTML = '';
            urlContainer.style.display = 'none';
            if (toggleBtn) toggleBtn.style.display = 'none';
        }
    });

    // Toggle between form and raw URL mode
    if (toggleBtn) {
        toggleBtn.addEventListener('click', function() {
            rawUrlMode = !rawUrlMode;
            if (rawUrlMode) {
                urlContainer.style.display = 'block';
                appriseUrl.readOnly = false;
                this.innerHTML = '<i class="bi bi-ui-checks me-1"></i>Use Form';
            } else {
                urlContainer.style.display = 'none';
                this.innerHTML = '<i class="bi bi-code-slash me-1"></i>Edit Raw URL';
                // Rebuild URL from form
                const type = serviceType.value;
                const schema = serviceSchemas[type];
                if (schema) {
                    updateBuiltUrl('addServiceFields', schema, 'add');
                }
            }
        });
    }

    // Before form submit, ensure URL is populated
    const form = document.getElementById('addServiceFormEl');
    if (form) {
        form.addEventListener('submit', function(e) {
            const type = serviceType.value;
            const schema = serviceSchemas[type];
            if (schema && !rawUrlMode) {
                updateBuiltUrl('addServiceFields', schema, 'add');
            }
            if (!appriseUrl.value.trim()) {
                e.preventDefault();
                alert('Please fill in the required fields.');
            }
        });
    }
})();
</script>
