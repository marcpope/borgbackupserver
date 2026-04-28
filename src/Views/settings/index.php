<?php
$activeTab = $_GET['tab'] ?? 'general';
// Backwards compat: map old tab names to new consolidated tabs
if (in_array($activeTab, ['remote', 'offsite', 'storage'])) {
    // Storage moved to /storage-locations
    $section = $_GET['section'] ?? '';
    if ($activeTab === 'offsite') $section = 's3';
    header('Location: /storage-locations' . ($section === 's3' ? '?section=s3' : ''));
    exit;
}
if ($activeTab === 'borg') { $activeTab = 'updates'; $updatesSection = 'borg'; }
if ($activeTab === 'updates') { $updatesSection = $updatesSection ?? ($_GET['section'] ?? 'software'); }
?>

<!-- Tab Navigation -->
<?php
$updateService = new \BBS\Services\UpdateService();
$updateAvailable = $updateService->isUpdateAvailable();
?>
<ul class="nav nav-pills client-tabs mb-0 flex-wrap">
    <li class="nav-item">
        <a class="nav-link <?= $activeTab === 'general' ? 'active' : '' ?>" href="/settings?tab=general">
            <i class="bi bi-gear me-1"></i><span class="tab-label">General</span>
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $activeTab === 'notifications' ? 'active' : '' ?>" href="/settings?tab=notifications">
            <i class="bi bi-envelope me-1"></i><span class="tab-label"><span class="d-none d-sm-inline">Email Settings</span><span class="d-sm-none">Email</span></span>
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $activeTab === 'push' ? 'active' : '' ?>" href="/settings?tab=push">
            <i class="bi bi-megaphone me-1"></i><span class="tab-label"><span class="d-none d-sm-inline">Push Notifications</span><span class="d-sm-none">Push</span></span>
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $activeTab === 'templates' ? 'active' : '' ?>" href="/settings?tab=templates">
            <i class="bi bi-clipboard-check me-1"></i><span class="tab-label">Templates</span>
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $activeTab === 'auth' ? 'active' : '' ?>" href="/settings?tab=auth">
            <i class="bi bi-shield-lock me-1"></i><span class="tab-label">Authentication</span>
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $activeTab === 'branding' ? 'active' : '' ?>" href="/settings?tab=branding">
            <i class="bi bi-palette me-1"></i><span class="tab-label">Branding</span>
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $activeTab === 'api' ? 'active' : '' ?>" href="/settings?tab=api">
            <i class="bi bi-key me-1"></i><span class="tab-label">API</span>
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $activeTab === 'updates' ? 'active' : '' ?>" href="/settings?tab=updates">
            <i class="bi bi-cloud-arrow-down me-1"></i><span class="tab-label">Updates</span>
            <?php if ($updateAvailable): ?>
                <span class="badge bg-warning text-dark ms-1">New</span>
            <?php endif; ?>
        </a>
    </li>
    <li class="nav-item d-sm-none">
        <a class="nav-link" href="/storage-locations">
            <i class="bi bi-hdd-stack me-1"></i><span class="tab-label">Storage</span>
        </a>
    </li>
</ul>
<div class="client-tab-content border rounded-bottom p-4 mb-4 shadow-sm">

<!-- General Tab -->
<?php if ($activeTab === 'general'): ?>
<form method="POST" action="/settings">
    <input type="hidden" name="csrf_token" value="<?= $this->csrfToken() ?>">
    <input type="hidden" name="_tab" value="general">

    <div class="row g-4">
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-primary bg-opacity-10 fw-semibold">
                    <i class="bi bi-server me-1"></i> Server
                </div>
                <div class="card-body">
                    <?php $mmOn = ($settings['maintenance_mode'] ?? '0') === '1'; ?>
                    <div class="rounded p-3 mb-3 d-flex align-items-center justify-content-between <?= $mmOn ? 'bg-warning bg-opacity-10 border border-warning' : 'bg-body-tertiary' ?>">
                        <div>
                            <div class="form-check form-switch mb-0">
                                <input class="form-check-input" type="checkbox" role="switch" name="maintenance_mode" id="maintenance_mode" value="1" <?= $mmOn ? 'checked' : '' ?>>
                                <label class="form-check-label fw-semibold" for="maintenance_mode">Maintenance Mode</label>
                            </div>
                            <div class="form-text mb-0">Pauses all new backup jobs. Existing running jobs will complete.</div>
                        </div>
                        <?php if ($mmOn): ?>
                        <span class="badge bg-warning text-dark"><i class="bi bi-exclamation-triangle-fill me-1"></i>Active</span>
                        <?php endif; ?>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Max Concurrent Jobs</label>
                        <input type="number" class="form-control" name="max_queue" value="<?= htmlspecialchars($settings['max_queue'] ?? '4') ?>" min="1" max="20">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Server Host / IP</label>
                        <?php $currentUrl = \BBS\Core\Config::get('APP_URL', 'https://'); $sslEnabled = str_starts_with($currentUrl, 'https://'); ?>
                        <div class="input-group">
                            <select class="form-select" name="url_protocol" style="max-width: 110px;">
                                <option value="https" <?= $sslEnabled ? 'selected' : '' ?>>https://</option>
                                <option value="http" <?= !$sslEnabled ? 'selected' : '' ?>>http://</option>
                            </select>
                            <input type="text" class="form-control" name="server_host" value="<?= htmlspecialchars($settings['server_host'] ?? '') ?>">
                        </div>
                        <div class="form-text">The address agents use to reach this server. Use https:// for public servers, http:// for LAN/internal installs.
                            <?php if (!$sslEnabled): ?>
                                <br>To enable SSL, first obtain a certificate: <code>sudo certbot --apache -d <?= htmlspecialchars($settings['server_host'] ?? 'your-hostname') ?></code>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">SSH Port</label>
                        <input type="number" class="form-control" name="ssh_port" value="<?= (int) ($settings['ssh_port'] ?? 22) ?>" min="1" max="65535" style="max-width: 120px;">
                        <div class="form-text">The SSH port agents use to connect for backups. Default is 22. Docker setups typically map to a different port (e.g. 2222).</div>
                    </div>
                </div>
            </div>

            <div class="card border-0 shadow-sm mt-4">
                <div class="card-header bg-primary bg-opacity-10 fw-semibold">
                    <i class="bi bi-incognito me-1"></i> Agent
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Agent Poll Interval (seconds)</label>
                        <input type="number" class="form-control" name="agent_poll_interval" value="<?= htmlspecialchars($settings['agent_poll_interval'] ?? '30') ?>" min="5" max="300">
                        <div class="form-text">How often agents check for new tasks.</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Stall Detection Timeout (minutes)</label>
                        <input type="number" class="form-control" name="stall_timeout_minutes" value="<?= htmlspecialchars($settings['stall_timeout_minutes'] ?? '120') ?>" min="10" max="1440">
                        <div class="form-text">Kill backup jobs with no progress after this many minutes. Set higher for large files. Default: 120 (2 hours).</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-primary bg-opacity-10 fw-semibold">
                    <i class="bi bi-shield-lock me-1"></i> Security
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="force_2fa" value="1"
                                   id="force2fa" <?= ($settings['force_2fa'] ?? '0') === '1' ? 'checked' : '' ?>>
                            <label class="form-check-label fw-semibold" for="force2fa">
                                Require Two-Factor Authentication for All Users
                            </label>
                        </div>
                        <div class="form-text">
                            When enabled, users without 2FA will be redirected to set it up on their next page load.
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Session Timeout (hours)</label>
                        <input type="number" class="form-control" name="session_timeout_hours" value="<?= htmlspecialchars($settings['session_timeout_hours'] ?? '8') ?>" min="1" max="720">
                        <div class="form-text">Log out after this many hours of inactivity.</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Default Theme</label>
                        <select class="form-select" name="default_theme">
                            <option value="dark" <?= ($settings['default_theme'] ?? 'dark') === 'dark' ? 'selected' : '' ?>>Dark</option>
                            <option value="light" <?= ($settings['default_theme'] ?? 'dark') === 'light' ? 'selected' : '' ?>>Light</option>
                        </select>
                        <div class="form-text">Default theme for the login page and new users. Users can override this in their profile.</div>
                    </div>
                    <?php if (isset($_GET['debug']) && $_GET['debug'] === '1'): ?>
                    <div class="mb-0">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="debug_mode" value="1"
                                   id="debugMode" <?= ($settings['debug_mode'] ?? '0') === '1' ? 'checked' : '' ?>>
                            <label class="form-check-label fw-semibold" for="debugMode">
                                Enable Debug Mode
                            </label>
                        </div>
                        <div class="form-text">
                            Shows detailed error pages with stack traces. Disable in production for security.
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="card border-0 shadow-sm mt-4">
                <div class="card-header bg-primary bg-opacity-10 fw-semibold">
                    <i class="bi bi-shield-check me-1"></i> Server Backups
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="self_backup_enabled" value="1"
                                   id="selfBackupEnabled" <?= ($settings['self_backup_enabled'] ?? '1') === '1' ? 'checked' : '' ?>>
                            <label class="form-check-label fw-semibold" for="selfBackupEnabled">
                                Enable Daily Server Backups
                            </label>
                        </div>
                        <div class="form-text">
                            Automatically backs up the database, configuration, and SSH keys to <code>/var/bbs/backups</code> once per day.
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Keep Last</label>
                        <div class="input-group" style="max-width: 200px;">
                            <input type="number" class="form-control" name="self_backup_retention"
                                   value="<?= htmlspecialchars($settings['self_backup_retention'] ?? '7') ?>"
                                   min="1" max="90">
                            <span class="input-group-text">backups</span>
                        </div>
                        <div class="form-text">Older backups are automatically deleted.</div>
                    </div>
                    <div class="mb-3">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="self_backup_catalogs" value="1"
                                   id="selfBackupCatalogs" <?= ($settings['self_backup_catalogs'] ?? '0') === '1' ? 'checked' : '' ?>>
                            <label class="form-check-label fw-semibold" for="selfBackupCatalogs">
                                Include File Catalog Data
                            </label>
                        </div>
                        <div class="form-text">
                            Catalogs can be large but save time on restore. If excluded, they will be rebuilt automatically from your repositories.
                        </div>
                    </div>
                    <div class="alert alert-info mb-0 small">
                        <i class="bi bi-info-circle me-1"></i>
                        Server backups include the database, configuration, and SSH host keys &mdash; <strong>not repository data</strong>.
                        To protect your backup repositories, configure <a href="/settings?tab=offsite" class="alert-link">S3 Offsite Sync</a>.
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm mt-4">
        <div class="card-header bg-body fw-semibold">
            <i class="bi bi-bar-chart me-1"></i> Usage Statistics
        </div>
        <div class="card-body">
            <div class="form-check">
                <input class="form-check-input" type="checkbox" name="telemetry_opt_out" value="1"
                       id="telemetryOptOut" <?= ($settings['telemetry_opt_out'] ?? '0') === '1' ? 'checked' : '' ?>>
                <label class="form-check-label fw-semibold" for="telemetryOptOut">
                    Disable Anonymous Usage Statistics
                </label>
            </div>
            <div class="form-text">
                BBS sends anonymous version and OS information once per version to help the developer understand what versions and platforms are in use. No identifying data is collected.
            </div>
        </div>
    </div>

    <div class="mt-4">
        <button type="submit" class="btn btn-warning">
            <i class="bi bi-check-lg me-1"></i> Save General Settings
        </button>
    </div>
</form>
<?php endif; ?>

<!-- Email Settings Tab -->
<?php if ($activeTab === 'notifications'): ?>
<div class="row g-4">
    <div class="col-lg-7">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-primary bg-opacity-10 fw-semibold">
                <i class="bi bi-envelope me-1"></i> SMTP Configuration
            </div>
            <div class="card-body">
                <form method="POST" action="/settings">
                    <input type="hidden" name="csrf_token" value="<?= $this->csrfToken() ?>">
                    <input type="hidden" name="_tab" value="notifications">

                    <div class="row g-3 mb-3">
                        <div class="col-md-8">
                            <label class="form-label fw-semibold">SMTP Host</label>
                            <input type="text" class="form-control" name="smtp_host" value="<?= htmlspecialchars($settings['smtp_host'] ?? '') ?>" placeholder="smtp.example.com">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Port</label>
                            <input type="number" class="form-control" name="smtp_port" value="<?= htmlspecialchars($settings['smtp_port'] ?? '587') ?>">
                        </div>
                    </div>
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">SMTP User</label>
                            <input type="text" class="form-control" name="smtp_user" value="<?= htmlspecialchars($settings['smtp_user'] ?? '') ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">SMTP Password</label>
                            <input type="password" class="form-control" name="smtp_pass"
                                   autocomplete="new-password"
                                   placeholder="<?= !empty($settings['smtp_pass']) ? '(unchanged if empty)' : '' ?>">
                        </div>
                    </div>
                    <?php
                    // Default encryption choice based on port when smtp_secure is unset.
                    $smtpPortForDefault = (int) ($settings['smtp_port'] ?? 587);
                    $smtpSecureDefault = match ($smtpPortForDefault) {
                        465 => 'ssl',
                        25 => 'none',
                        default => 'starttls',
                    };
                    $smtpSecure = $settings['smtp_secure'] ?? $smtpSecureDefault;
                    ?>
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Encryption</label>
                            <select class="form-select" name="smtp_secure">
                                <option value="starttls" <?= $smtpSecure === 'starttls' ? 'selected' : '' ?>>STARTTLS (typically port 587)</option>
                                <option value="ssl" <?= $smtpSecure === 'ssl' ? 'selected' : '' ?>>SSL/TLS (typically port 465)</option>
                                <option value="none" <?= $smtpSecure === 'none' ? 'selected' : '' ?>>None (plaintext)</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">From Address</label>
                            <input type="email" class="form-control" name="smtp_from" value="<?= htmlspecialchars($settings['smtp_from'] ?? '') ?>" placeholder="backups@example.com">
                        </div>
                    </div>

                    <div class="d-flex align-items-center gap-3">
                        <button type="submit" class="btn btn-warning">
                            <i class="bi bi-check-lg me-1"></i> Save Email Settings
                        </button>
                        <button type="button" class="btn btn-outline-secondary" id="btnTestSmtp">
                            <i class="bi bi-envelope-check me-1"></i> Test SMTP
                        </button>
                        <span id="smtpTestResult" class="small"></span>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-primary bg-opacity-10 fw-semibold">
                <i class="bi bi-bell me-1"></i> In-App Notifications
            </div>
            <div class="card-body">
                <form method="POST" action="/settings">
                    <input type="hidden" name="csrf_token" value="<?= $this->csrfToken() ?>">
                    <input type="hidden" name="_tab" value="notifications">
                    <div class="form-check form-switch mb-2">
                        <input class="form-check-input" type="checkbox" role="switch"
                               id="inapp_notify_success_events" name="inapp_notify_success_events" value="1"
                               <?= ($settings['inapp_notify_success_events'] ?? '0') === '1' ? 'checked' : '' ?>>
                        <label class="form-check-label" for="inapp_notify_success_events">
                            Show successful backups / restores in the notification bell
                        </label>
                    </div>
                    <p class="small text-muted mb-3">
                        Off by default. When off, only failures, agent-offline, storage-low, and other
                        alert-worthy events show up in the bell menu — so routine success messages
                        don't pile up and bury real issues. Email and push notifications stay
                        independent of this setting.
                    </p>
                    <button type="submit" class="btn btn-sm btn-warning">
                        <i class="bi bi-check-lg me-1"></i> Save
                    </button>
                </form>
            </div>
        </div>
        <div class="card border-0 bg-body-secondary">
            <div class="card-body">
                <h6 class="card-title"><i class="bi bi-lightbulb me-1 text-warning"></i> Tip</h6>
                <p class="card-text small text-muted mb-2">
                    Email settings are used for password resets, upgrade notices, and other system messages.
                </p>
                <p class="card-text small text-muted mb-0">
                    For backup alerts (failures, offline clients, storage warnings), configure
                    <a href="/settings?tab=push">Push Notifications</a> using Discord, Slack, Telegram, or 100+ other services.
                </p>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>


<!-- Push Notifications Tab -->
<?php if ($activeTab === 'push'): ?>
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
$notifServices = $this->db->fetchAll("SELECT * FROM notification_services ORDER BY name ASC");
foreach ($notifServices as &$ns) {
    $ns['events'] = json_decode($ns['events'] ?? '{}', true) ?: [];
}
unset($ns);
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <p class="text-muted mb-0 small">Send alerts to Discord, Telegram, Slack, Pushover, and <a href="https://github.com/caronc/apprise/wiki#notification-services" target="_blank">100+ other services</a> using Apprise.</p>
    </div>
    <button class="btn btn-success" data-bs-toggle="collapse" data-bs-target="#addServiceForm">
        <i class="bi bi-plus-circle me-1"></i> Add Service
    </button>
</div>

<!-- Info banner with URL examples -->
<div class="alert alert-light border mb-4">
    <div class="d-flex align-items-start">
        <i class="bi bi-info-circle text-primary me-2 mt-1"></i>
        <div class="w-100">
            <a class="small text-decoration-none" data-bs-toggle="collapse" href="#urlExamples" role="button">
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
        <div class="card-header bg-primary bg-opacity-10 fw-semibold">
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
<?php if (!empty($notifServices)): ?>
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
                <?php foreach ($notifServices as $service): ?>
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
                        <button class="btn btn-sm btn-outline-primary border-0" onclick="testPushService(<?= $service['id'] ?>, this)" title="Test">
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
                                    <form method="POST" action="/notification-services/<?= $service['id'] ?>/toggle" class="d-inline">
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
        <?php foreach ($notifServices as $service): ?>
        <div class="p-3 <?= $service !== end($notifServices) ? 'border-bottom' : '' ?>">
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
                    <button class="btn btn-sm btn-outline-primary border-0" onclick="testPushService(<?= $service['id'] ?>, this)" title="Test">
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
                        <form method="POST" action="/notification-services/<?= $service['id'] ?>/toggle" class="d-inline">
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
<div class="card border-0 shadow-sm" id="noServicesCard">
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
    <div id="pushTestToast" class="toast align-items-center border-0" role="alert">
        <div class="d-flex">
            <div class="toast-body" id="pushTestToastBody"></div>
            <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
        </div>
    </div>
</div>

<script>
function testPushService(id, btn) {
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

        const toast = document.getElementById('pushTestToast');
        const toastBody = document.getElementById('pushTestToastBody');

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

// Hide empty state card when add form is shown
(function() {
    const addForm = document.getElementById('addServiceForm');
    const emptyCard = document.getElementById('noServicesCard');
    if (addForm && emptyCard) {
        addForm.addEventListener('show.bs.collapse', function() {
            emptyCard.style.display = 'none';
        });
        addForm.addEventListener('hide.bs.collapse', function() {
            emptyCard.style.display = '';
        });
    }
})();

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
            else mode = 'mailto'; // starttls is default
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
            // Parse webhook URL to extract tokens or use json:// format
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
<?php endif; ?>


<!-- Templates Tab -->
<?php if ($activeTab === 'templates'): ?>
<div class="card border-0 shadow-sm">
    <div class="card-header bg-primary bg-opacity-10 fw-semibold">
        <i class="bi bi-clipboard-check me-1"></i> Backup Templates
    </div>
    <div class="card-body">
        <p class="text-muted small mb-3">Templates pre-fill directories, excludes, and borg options when creating backup plans. Select a template to auto-populate the form.</p>

        <?php if (!empty($templates)): ?>
        <div class="table-responsive mb-4">
            <table class="table table-hover">
                <thead class="table-light">
                    <tr>
                        <th>Name</th>
                        <th>Description</th>
                        <th>Directories</th>
                        <th>Excludes</th>
                        <th>Options</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($templates as $tpl): ?>
                    <tr>
                        <td class="fw-semibold"><?= htmlspecialchars($tpl['name']) ?></td>
                        <td class="small text-muted"><?= htmlspecialchars($tpl['description'] ?? '') ?></td>
                        <td><code class="small"><?= htmlspecialchars(str_replace("\n", ', ', $tpl['directories'])) ?></code></td>
                        <td><code class="small"><?= htmlspecialchars(str_replace("\n", ', ', $tpl['excludes'] ?? '')) ?></code></td>
                        <td><code class="small"><?= htmlspecialchars($tpl['advanced_options'] ?? '') ?></code></td>
                        <td class="text-nowrap">
                            <button class="btn btn-sm btn-outline-primary" type="button" data-bs-toggle="collapse" data-bs-target="#edit-tpl-<?= $tpl['id'] ?>">
                                <i class="bi bi-pencil"></i>
                            </button>
                            <form method="POST" action="/settings/templates/<?= $tpl['id'] ?>/delete" class="d-inline" data-confirm="Delete this template?" data-confirm-danger>
                                <input type="hidden" name="csrf_token" value="<?= $this->csrfToken() ?>">
                                <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                            </form>
                        </td>
                    </tr>
                    <?php
                        $editAdv = $tpl['advanced_options'] ?? '';
                        $editHasComp = (bool)preg_match('/--compression\s+(\S+)/', $editAdv, $editCompMatch);
                        $editCompSpec = $editHasComp ? $editCompMatch[1] : 'lz4';
                    ?>
                    <tr class="collapse" id="edit-tpl-<?= $tpl['id'] ?>">
                        <td colspan="6">
                            <form method="POST" action="/settings/templates/<?= $tpl['id'] ?>/edit" class="p-2 tpl-form">
                                <input type="hidden" name="csrf_token" value="<?= $this->csrfToken() ?>">
                                <div class="row g-2 mb-2">
                                    <div class="col-md-3">
                                        <label class="form-label small text-muted mb-0">Name</label>
                                        <input type="text" class="form-control form-control-sm" name="name" value="<?= htmlspecialchars($tpl['name']) ?>" required>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label small text-muted mb-0">Description</label>
                                        <input type="text" class="form-control form-control-sm" name="description" value="<?= htmlspecialchars($tpl['description'] ?? '') ?>" placeholder="Description">
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label small text-muted mb-0">Directories</label>
                                        <textarea class="form-control form-control-sm" name="directories" rows="3" required placeholder="One per line"><?= htmlspecialchars($tpl['directories']) ?></textarea>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label small text-muted mb-0">Excludes</label>
                                        <textarea class="form-control form-control-sm" name="excludes" rows="3" placeholder="One per line"><?= htmlspecialchars($tpl['excludes'] ?? '') ?></textarea>
                                    </div>
                                </div>
                                <div class="row g-2 mb-2">
                                    <div class="col-md-6">
                                        <label class="form-label small text-muted mb-0">Borg Options</label>
                                        <div class="d-flex flex-wrap gap-3">
                                            <div class="form-check form-check-inline">
                                                <input class="form-check-input tpl-borg-opt" type="checkbox" data-flag="compression" <?= $editHasComp ? 'checked' : '' ?>>
                                                <label class="form-check-label small">Compression</label>
                                            </div>
                                            <div class="form-check form-check-inline">
                                                <input class="form-check-input tpl-borg-opt" type="checkbox" data-flag="--exclude-caches" <?= str_contains($editAdv, '--exclude-caches') ? 'checked' : '' ?>>
                                                <label class="form-check-label small">Exclude caches</label>
                                            </div>
                                            <div class="form-check form-check-inline">
                                                <input class="form-check-input tpl-borg-opt" type="checkbox" data-flag="--one-file-system" <?= str_contains($editAdv, '--one-file-system') ? 'checked' : '' ?>>
                                                <label class="form-check-label small">One file system</label>
                                            </div>
                                            <div class="form-check form-check-inline">
                                                <input class="form-check-input tpl-borg-opt" type="checkbox" data-flag="--noatime" <?= str_contains($editAdv, '--noatime') ? 'checked' : '' ?>>
                                                <label class="form-check-label small">No atime</label>
                                            </div>
                                            <div class="form-check form-check-inline">
                                                <input class="form-check-input tpl-borg-opt" type="checkbox" data-flag="--numeric-ids" <?= str_contains($editAdv, '--numeric-ids') ? 'checked' : '' ?>>
                                                <label class="form-check-label small">Numeric IDs</label>
                                            </div>
                                            <div class="form-check form-check-inline">
                                                <input class="form-check-input tpl-borg-opt" type="checkbox" data-flag="--noxattrs" <?= str_contains($editAdv, '--noxattrs') ? 'checked' : '' ?>>
                                                <label class="form-check-label small">Skip xattrs</label>
                                            </div>
                                            <div class="form-check form-check-inline">
                                                <input class="form-check-input tpl-borg-opt" type="checkbox" data-flag="--noacls" <?= str_contains($editAdv, '--noacls') ? 'checked' : '' ?>>
                                                <label class="form-check-label small">Skip ACLs</label>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label small text-muted mb-0">Compression spec</label>
                                        <input type="text" class="form-control form-control-sm tpl-comp-type" value="<?= htmlspecialchars($editCompSpec) ?>" placeholder="lz4">
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label small text-muted mb-0">Custom options</label>
                                        <input type="text" class="form-control form-control-sm font-monospace tpl-adv-field" name="advanced_options" value="<?= htmlspecialchars($editAdv) ?>">
                                    </div>
                                    <div class="col-md-2 d-flex align-items-end">
                                        <button type="submit" class="btn btn-sm btn-primary w-100">Save</button>
                                    </div>
                                </div>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <h6>Add Template</h6>
        <form method="POST" action="/settings/templates/add" class="tpl-form" id="addTplForm">
            <input type="hidden" name="csrf_token" value="<?= $this->csrfToken() ?>">
            <div class="row g-3 mb-2">
                <div class="col-md-3">
                    <label class="form-label fw-semibold">Name</label>
                    <input type="text" class="form-control" name="name" required placeholder="e.g. cPanel Server">
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold">Description</label>
                    <input type="text" class="form-control" name="description" placeholder="Short description">
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold">Directories</label>
                    <textarea class="form-control" name="directories" rows="3" required placeholder="/home&#10;/etc&#10;/var/www"></textarea>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold">Excludes</label>
                    <textarea class="form-control" name="excludes" rows="3" placeholder="*.tmp&#10;*.log"></textarea>
                </div>
            </div>
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Borg Options</label>
                    <div class="d-flex flex-wrap gap-3">
                        <div class="form-check form-check-inline">
                            <input class="form-check-input tpl-borg-opt" type="checkbox" data-flag="compression" checked>
                            <label class="form-check-label small">Compression</label>
                        </div>
                        <div class="form-check form-check-inline">
                            <input class="form-check-input tpl-borg-opt" type="checkbox" data-flag="--exclude-caches" checked>
                            <label class="form-check-label small">Exclude caches</label>
                        </div>
                        <div class="form-check form-check-inline">
                            <input class="form-check-input tpl-borg-opt" type="checkbox" data-flag="--one-file-system">
                            <label class="form-check-label small">One file system</label>
                        </div>
                        <div class="form-check form-check-inline">
                            <input class="form-check-input tpl-borg-opt" type="checkbox" data-flag="--noatime" checked>
                            <label class="form-check-label small">No atime</label>
                        </div>
                        <div class="form-check form-check-inline">
                            <input class="form-check-input tpl-borg-opt" type="checkbox" data-flag="--numeric-ids">
                            <label class="form-check-label small">Numeric IDs</label>
                        </div>
                        <div class="form-check form-check-inline">
                            <input class="form-check-input tpl-borg-opt" type="checkbox" data-flag="--noxattrs">
                            <label class="form-check-label small">Skip xattrs</label>
                        </div>
                        <div class="form-check form-check-inline">
                            <input class="form-check-input tpl-borg-opt" type="checkbox" data-flag="--noacls">
                            <label class="form-check-label small">Skip ACLs</label>
                        </div>
                    </div>
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-semibold">Compression spec</label>
                    <input type="text" class="form-control form-control-sm tpl-comp-type" value="lz4" placeholder="lz4">
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-semibold">Custom options</label>
                    <input type="text" class="form-control form-control-sm font-monospace tpl-adv-field" name="advanced_options" placeholder="e.g. --pattern ...">
                </div>
                <div class="col-md-2 d-flex align-items-end">
                    <button type="submit" class="btn btn-success w-100">Add Template</button>
                </div>
            </div>
        </form>
    </div>
</div>
<script>
(function() {
    const managedFlags = ['--compression\\s+\\S+', '--exclude-caches', '--one-file-system', '--noatime', '--numeric-ids', '--noxattrs', '--noacls'];
    function stripManaged(val) {
        managedFlags.forEach(f => { val = val.replace(new RegExp(f, 'g'), ''); });
        return val.replace(/\s+/g, ' ').trim();
    }
    function syncTplForm(form) {
        const advField = form.querySelector('.tpl-adv-field');
        const compType = form.querySelector('.tpl-comp-type');
        const checks = form.querySelectorAll('.tpl-borg-opt');
        const custom = stripManaged(advField.value);
        const opts = [];
        checks.forEach(cb => {
            if (!cb.checked) return;
            if (cb.dataset.flag === 'compression') {
                if (compType.value.trim()) opts.push('--compression ' + compType.value.trim());
            } else {
                opts.push(cb.dataset.flag);
            }
        });
        advField.value = [opts.join(' '), custom].filter(Boolean).join(' ');
    }
    document.querySelectorAll('.tpl-form').forEach(form => {
        form.querySelectorAll('.tpl-borg-opt').forEach(cb => cb.addEventListener('change', () => syncTplForm(form)));
        const compType = form.querySelector('.tpl-comp-type');
        if (compType) compType.addEventListener('input', () => syncTplForm(form));
        form.addEventListener('submit', () => syncTplForm(form));
        syncTplForm(form);
    });
})();
</script>
<?php endif; ?>

<!-- Authentication Tab -->
<?php if ($activeTab === 'auth'): ?>
<div class="card border-0 shadow-sm">
    <div class="card-header bg-primary bg-opacity-10 fw-semibold">
        <i class="bi bi-shield-lock me-1"></i> Single Sign-On (OIDC)
    </div>
    <div class="card-body">
        <p class="text-muted small mb-3">Configure OpenID Connect (OIDC) to allow users to sign in with an external identity provider (Keycloak, Authentik, Azure AD, Google, Okta, etc.).</p>

        <form method="POST" action="/settings/oidc">
            <input type="hidden" name="csrf_token" value="<?= $this->csrfToken() ?>">

            <div class="form-check form-switch mb-4">
                <input class="form-check-input" type="checkbox" name="oidc_enabled" id="oidcEnabled" value="1" <?= ($settings['oidc_enabled'] ?? '0') === '1' ? 'checked' : '' ?>>
                <label class="form-check-label fw-semibold" for="oidcEnabled">Enable OIDC Single Sign-On</label>
            </div>

            <div class="row g-3 mb-3">
                <div class="col-md-12">
                    <label class="form-label fw-semibold">Provider URL</label>
                    <input type="url" class="form-control" name="oidc_provider_url" value="<?= htmlspecialchars($settings['oidc_provider_url'] ?? '') ?>" placeholder="https://idp.example.com/realms/myrealm">
                    <div class="form-text">The base URL for OpenID Connect discovery. BBS appends <code>/.well-known/openid-configuration</code> automatically.</div>
                </div>
            </div>

            <div class="row g-3 mb-3">
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Client ID</label>
                    <input type="text" class="form-control" name="oidc_client_id" value="<?= htmlspecialchars($settings['oidc_client_id'] ?? '') ?>" placeholder="bbs">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Client Secret</label>
                    <input type="password" class="form-control" name="oidc_client_secret" placeholder="<?= !empty($settings['oidc_client_secret']) ? '(unchanged if empty)' : '' ?>">
                    <?php if (!empty($settings['oidc_client_secret'])): ?>
                    <div class="form-text">A value is saved. Leave empty to keep unchanged.</div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="row g-3 mb-3">
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Button Label</label>
                    <input type="text" class="form-control" name="oidc_button_label" value="<?= htmlspecialchars($settings['oidc_button_label'] ?? 'Login with SSO') ?>">
                    <div class="form-text">Text shown on the SSO button on the login page.</div>
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Scopes</label>
                    <input type="text" class="form-control" name="oidc_scopes" value="<?= htmlspecialchars($settings['oidc_scopes'] ?? 'openid email profile') ?>">
                    <div class="form-text">Space-separated OIDC scopes. Must include <code>openid</code> and <code>email</code>.</div>
                </div>
            </div>

            <div class="row g-3 mb-3">
                <div class="col-12">
                    <label class="form-label fw-semibold">Redirect URL Override <span class="text-muted fw-normal small">(optional)</span></label>
                    <input type="url" class="form-control" name="oidc_redirect_url" value="<?= htmlspecialchars($settings['oidc_redirect_url'] ?? '') ?>" placeholder="https://bbs.example.com/login/oidc/callback">
                    <div class="form-text">Leave blank to auto-detect from the request. Set this if BBS sits behind a reverse proxy but your OIDC provider needs a different URL than what the request headers show (e.g. your agents use an internal URL but SSO must use the public hostname).</div>
                </div>
            </div>

            <hr>

            <h6 class="mb-3">New User Handling</h6>
            <p class="text-muted small">When an unknown user authenticates via SSO for the first time:</p>

            <div class="mb-3">
                <select class="form-select" name="oidc_new_user_policy" id="oidcNewUserPolicy">
                    <?php $policy = $settings['oidc_new_user_policy'] ?? 'deny'; ?>
                    <option value="deny" <?= $policy === 'deny' ? 'selected' : '' ?>>Deny access (user must already exist with matching email)</option>
                    <option value="pending" <?= $policy === 'pending' ? 'selected' : '' ?>>Create user, pending admin approval</option>
                    <option value="copy" <?= $policy === 'copy' ? 'selected' : '' ?>>Create user with permissions copied from a template user</option>
                </select>
            </div>

            <div class="mb-3" id="oidcTemplateUserWrap" style="<?= $policy === 'copy' ? '' : 'display:none;' ?>">
                <label class="form-label fw-semibold">Template User</label>
                <select class="form-select" name="oidc_template_user_id">
                    <option value="">-- Select a user --</option>
                    <?php foreach ($oidcUsers ?? [] as $u): ?>
                    <option value="<?= $u['id'] ?>" <?= ($settings['oidc_template_user_id'] ?? '') == $u['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($u['username']) ?> (<?= htmlspecialchars($u['email']) ?>) — <?= $u['role'] ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <div class="form-text">New SSO users will receive the same client access and permissions as this user.</div>
            </div>

            <hr>

            <div class="form-check form-switch mb-3">
                <input class="form-check-input" type="checkbox" name="oidc_logout_enabled" id="oidcLogout" value="1" <?= ($settings['oidc_logout_enabled'] ?? '0') === '1' ? 'checked' : '' ?>>
                <label class="form-check-label" for="oidcLogout">Enable OIDC Logout</label>
                <div class="form-text">When enabled, logging out of BBS also logs the user out of the identity provider.</div>
            </div>

            <button type="submit" class="btn btn-primary">
                <i class="bi bi-check-lg me-1"></i> Save Authentication Settings
            </button>
        </form>
    </div>
</div>
<script>
document.getElementById('oidcNewUserPolicy').addEventListener('change', function() {
    document.getElementById('oidcTemplateUserWrap').style.display = this.value === 'copy' ? '' : 'none';
});
</script>
<?php endif; ?>

<!-- Branding Tab -->
<?php if ($activeTab === 'branding'): ?>
<div class="card border-0 shadow-sm">
    <div class="card-header bg-primary bg-opacity-10 fw-semibold">
        <i class="bi bi-palette me-1"></i> Branding
    </div>
    <div class="card-body">
        <p class="text-muted small mb-4">Customize the BBS interface with your own logos. Images are stored in the database and persist across updates.</p>

        <form method="POST" action="/settings/branding" enctype="multipart/form-data" id="brandingForm">
            <input type="hidden" name="csrf_token" value="<?= $this->csrfToken() ?>">
            <input type="hidden" name="branding_icon_data" id="brandingIconData">
            <input type="hidden" name="branding_login_logo_data" id="brandingLoginLogoData">

            <!-- Navbar Icon -->
            <div class="row mb-4">
                <div class="col-md-7">
                    <h6><i class="bi bi-image me-1"></i> Navbar Icon</h6>
                    <p class="text-muted small">Transparent PNG shown in the top-left corner of every page. Wide landscape artwork works best — it sits in a ~115×100px slot and overflows slightly into the page header. Will be resized to fit within 360×200 pixels max.</p>
                    <input type="file" class="form-control form-control-sm" id="iconFileInput" accept="image/png">
                    <div class="small text-muted mt-1" id="iconDimensions"></div>
                    <?php if (!empty($settings['branding_icon'])): ?>
                    <div class="form-check mt-2">
                        <input class="form-check-input" type="checkbox" name="remove_branding_icon" value="1" id="removeBrandingIcon">
                        <label class="form-check-label small" for="removeBrandingIcon">Remove custom icon (revert to default)</label>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="col-md-5 text-center">
                    <label class="form-label small text-muted">Preview</label>
                    <div class="p-3 rounded bg-dark d-flex align-items-center justify-content-center" style="min-height: 130px;">
                        <img id="iconPreview"
                             src="<?= !empty($settings['branding_icon']) ? 'data:image/png;base64,' . $settings['branding_icon'] : '/images/bbs-logo-mascot.png' ?>"
                             alt="Icon preview"
                             style="max-width: 180px; max-height: 110px; width: auto; height: auto;">
                    </div>
                    <?php if (empty($settings['branding_icon'])): ?>
                    <div class="text-muted small mt-1" id="iconDefaultLabel">Default — Borg Backup Server mascot</div>
                    <?php endif; ?>
                </div>
            </div>

            <hr>

            <!-- Login Logo -->
            <div class="row mb-4">
                <div class="col-md-7">
                    <h6><i class="bi bi-card-image me-1"></i> Login Page Logo</h6>
                    <p class="text-muted small">Transparent PNG displayed on the left half of the login screen. Square or near-square artwork works best — fills a column up to about 500px wide. Will be resized to fit within 800×800 pixels max.</p>
                    <input type="file" class="form-control form-control-sm" id="loginLogoFileInput" accept="image/png">
                    <div class="small text-muted mt-1" id="loginLogoDimensions"></div>
                    <?php if (!empty($settings['branding_login_logo'])): ?>
                    <div class="form-check mt-2">
                        <input class="form-check-input" type="checkbox" name="remove_branding_login_logo" value="1" id="removeLoginLogo">
                        <label class="form-check-label small" for="removeLoginLogo">Remove custom logo (revert to default)</label>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="col-md-5 text-center">
                    <label class="form-label small text-muted">Preview</label>
                    <div class="p-3 rounded bg-dark d-flex align-items-center justify-content-center" style="min-height: 280px;">
                        <img id="loginLogoPreview"
                             src="<?= !empty($settings['branding_login_logo']) ? 'data:image/png;base64,' . $settings['branding_login_logo'] : '/images/login-logo.png' ?>"
                             alt="Login logo preview"
                             style="max-width: 100%; max-height: 260px; width: auto; height: auto;">
                    </div>
                    <?php if (empty($settings['branding_login_logo'])): ?>
                    <div class="text-muted small mt-1" id="loginLogoDefaultLabel">Default — Borg Backup Server mascot</div>
                    <?php endif; ?>
                </div>
            </div>

            <?php /*
            Login Page Theme override commented out — the new login design is
            built specifically for dark and only works in dark. Re-enable
            this block once light styling exists.

            <hr>
            <div class="mb-4">
                <h6><i class="bi bi-moon-stars me-1"></i> Login Page Theme</h6>
                <p class="text-muted small">Override the login page theme independently. Useful when your logo only works well on a specific background.</p>
                <select class="form-select" name="branding_login_theme" style="max-width: 300px;">
                    <?php $loginTheme = $settings['branding_login_theme'] ?? 'default'; ?>
                    <option value="default" <?= $loginTheme === 'default' ? 'selected' : '' ?>>Use Default Theme</option>
                    <option value="dark" <?= $loginTheme === 'dark' ? 'selected' : '' ?>>Always Dark</option>
                    <option value="light" <?= $loginTheme === 'light' ? 'selected' : '' ?>>Always Light</option>
                </select>
            </div>
            */ ?>

            <button type="submit" class="btn btn-primary">
                <i class="bi bi-check-lg me-1"></i> Save Branding
            </button>
        </form>
    </div>
</div>
<script>
function resizeImage(file, maxW, maxH, callback) {
    var reader = new FileReader();
    reader.onload = function(e) {
        var img = new Image();
        img.onload = function() {
            var w = img.width, h = img.height;
            if (w <= maxW && h <= maxH) {
                // Already within limits, use original
                callback(e.target.result, w, h);
                return;
            }
            var ratio = Math.min(maxW / w, maxH / h);
            var nw = Math.round(w * ratio), nh = Math.round(h * ratio);
            var canvas = document.createElement('canvas');
            canvas.width = nw;
            canvas.height = nh;
            var ctx = canvas.getContext('2d');
            ctx.drawImage(img, 0, 0, nw, nh);
            callback(canvas.toDataURL('image/png'), nw, nh);
        };
        img.src = e.target.result;
    };
    reader.readAsDataURL(file);
}

document.getElementById('iconFileInput').addEventListener('change', function() {
    if (!this.files[0]) return;
    // Bumped from 120x120 to 360x200 — the new topbar slot is wider and
    // benefits from higher-res source artwork, especially on retina screens.
    resizeImage(this.files[0], 360, 200, function(dataUrl, w, h) {
        var preview = document.getElementById('iconPreview');
        preview.src = dataUrl;
        // Let CSS sizing take over — preview's max-width/height already set inline.
        document.getElementById('brandingIconData').value = dataUrl.split(',')[1];
        document.getElementById('iconDimensions').textContent = 'Resized to ' + w + 'x' + h + 'px';
        var lbl = document.getElementById('iconDefaultLabel');
        if (lbl) lbl.style.display = 'none';
    });
});

document.getElementById('loginLogoFileInput').addEventListener('change', function() {
    if (!this.files[0]) return;
    // Bumped from 475x100 to 800x800 — the new login layout uses square /
    // near-square artwork that fills its column, not a wide banner.
    resizeImage(this.files[0], 800, 800, function(dataUrl, w, h) {
        var preview = document.getElementById('loginLogoPreview');
        preview.src = dataUrl;
        document.getElementById('brandingLoginLogoData').value = dataUrl.split(',')[1];
        document.getElementById('loginLogoDimensions').textContent = 'Resized to ' + w + 'x' + h + 'px';
        var lbl = document.getElementById('loginLogoDefaultLabel');
        if (lbl) lbl.style.display = 'none';
    });
});
</script>
<?php endif; ?>

<!-- API Tab -->
<?php if ($activeTab === 'api'): ?>
<div class="card border-0 shadow-sm">
    <div class="card-header bg-primary bg-opacity-10 fw-semibold">
        <i class="bi bi-key me-1"></i> API Tokens
    </div>
    <div class="card-body">
        <p class="text-muted small mb-3">API tokens allow automated access to the BBS provisioning API. Tokens have full admin access. Keep them secret.</p>

        <?php if (!empty($_SESSION['new_api_token'])): ?>
        <div class="alert alert-success d-flex align-items-center mb-3">
            <i class="bi bi-shield-check me-2 fs-5"></i>
            <div class="flex-grow-1">
                <strong>New API Token</strong> — copy it now, it will not be shown again:
                <div class="mt-1">
                    <code id="newTokenValue" class="user-select-all fs-6"><?= htmlspecialchars($_SESSION['new_api_token']) ?></code>
                    <button type="button" class="btn btn-sm btn-outline-success ms-2" onclick="navigator.clipboard.writeText(document.getElementById('newTokenValue').textContent).then(() => { this.innerHTML = '<i class=\'bi bi-check\'></i> Copied'; })">
                        <i class="bi bi-clipboard"></i> Copy
                    </button>
                </div>
            </div>
        </div>
        <?php unset($_SESSION['new_api_token']); ?>
        <?php endif; ?>

        <?php if (!empty($apiTokens)): ?>
        <div class="table-responsive mb-4">
            <table class="table table-hover">
                <thead class="table-light">
                    <tr>
                        <th>Name</th>
                        <th>User</th>
                        <th>Created</th>
                        <th>Last Used</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($apiTokens as $token): ?>
                    <tr>
                        <td class="fw-semibold"><i class="bi bi-key me-1 text-muted"></i><?= htmlspecialchars($token['name']) ?></td>
                        <td class="small text-muted"><?= htmlspecialchars($token['username']) ?></td>
                        <td class="small text-muted"><?= \BBS\Core\TimeHelper::format($token['created_at'], 'M j, Y') ?></td>
                        <td class="small text-muted"><?= $token['last_used_at'] ? \BBS\Core\TimeHelper::format($token['last_used_at'], 'M j, Y g:i A') : '<span class="text-muted">never</span>' ?></td>
                        <td>
                            <form method="POST" action="/settings/api/tokens/<?= $token['id'] ?>/revoke" class="d-inline" data-confirm="Revoke API token &quot;<?= htmlspecialchars($token['name']) ?>&quot;? Any automation using this token will stop working." data-confirm-danger>
                                <input type="hidden" name="csrf_token" value="<?= $this->csrfToken() ?>">
                                <button class="btn btn-sm btn-outline-danger"><i class="bi bi-x-circle me-1"></i>Revoke</button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <h6>Create Token</h6>
        <form method="POST" action="/settings/api/tokens/create">
            <input type="hidden" name="csrf_token" value="<?= $this->csrfToken() ?>">
            <div class="row g-3 align-items-end">
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Token Name</label>
                    <input type="text" class="form-control" name="name" required placeholder="e.g. ansible-provisioner">
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-success w-100"><i class="bi bi-plus-circle me-1"></i>Create Token</button>
                </div>
            </div>
        </form>
    </div>
</div>

<div class="card border-0 shadow-sm mt-4">
    <div class="card-header bg-body fw-semibold">
        <i class="bi bi-book me-1"></i> API Reference
    </div>
    <div class="card-body">
        <p class="text-muted small mb-3">Use your API token with the <code>Authorization: Bearer &lt;token&gt;</code> header. All endpoints accept and return JSON.</p>

        <table class="table table-sm small mb-0">
            <thead class="table-light">
                <tr><th>Method</th><th>Endpoint</th><th>Description</th></tr>
            </thead>
            <tbody>
                <tr><td><span class="badge bg-success">GET</span></td><td><code>/api/v1/clients</code></td><td>List all clients</td></tr>
                <tr><td><span class="badge bg-primary">POST</span></td><td><code>/api/v1/clients</code></td><td>Create a client (returns api_key for agent install)</td></tr>
                <tr><td><span class="badge bg-success">GET</span></td><td><code>/api/v1/clients/{id}</code></td><td>Get client details with repos &amp; plans</td></tr>
                <tr><td><span class="badge bg-danger">DELETE</span></td><td><code>/api/v1/clients/{id}</code></td><td>Delete a client</td></tr>
                <tr><td><span class="badge bg-success">GET</span></td><td><code>/api/v1/clients/{id}/repositories</code></td><td>List repositories</td></tr>
                <tr><td><span class="badge bg-primary">POST</span></td><td><code>/api/v1/clients/{id}/repositories</code></td><td>Create a repository</td></tr>
                <tr><td><span class="badge bg-success">GET</span></td><td><code>/api/v1/clients/{id}/plans</code></td><td>List backup plans</td></tr>
                <tr><td><span class="badge bg-primary">POST</span></td><td><code>/api/v1/clients/{id}/plans</code></td><td>Create a backup plan (with optional plugin configs)</td></tr>
                <tr><td><span class="badge bg-warning text-dark">PUT</span></td><td><code>/api/v1/clients/{id}/repositories/{repo_id}</code></td><td>Rename a repository</td></tr>
                <tr><td><span class="badge bg-danger">DELETE</span></td><td><code>/api/v1/clients/{id}/repositories/{repo_id}</code></td><td>Delete a repository</td></tr>
                <tr><td colspan="3" class="text-muted small fw-semibold pt-2">Backup Plans</td></tr>
                <tr><td><span class="badge bg-success">GET</span></td><td><code>/api/v1/clients/{id}/plans</code></td><td>List backup plans</td></tr>
                <tr><td><span class="badge bg-primary">POST</span></td><td><code>/api/v1/clients/{id}/plans</code></td><td>Create a backup plan</td></tr>
                <tr><td><span class="badge bg-warning text-dark">PUT</span></td><td><code>/api/v1/clients/{id}/plans/{plan_id}</code></td><td>Edit plan, schedule, plugins</td></tr>
                <tr><td><span class="badge bg-danger">DELETE</span></td><td><code>/api/v1/clients/{id}/plans/{plan_id}</code></td><td>Delete a plan</td></tr>
                <tr><td><span class="badge bg-primary">POST</span></td><td><code>/api/v1/clients/{id}/plans/{plan_id}/pause</code></td><td>Pause schedule</td></tr>
                <tr><td><span class="badge bg-primary">POST</span></td><td><code>/api/v1/clients/{id}/plans/{plan_id}/resume</code></td><td>Resume schedule</td></tr>
                <tr><td><span class="badge bg-primary">POST</span></td><td><code>/api/v1/clients/{id}/plans/{plan_id}/trigger</code></td><td>Run backup now</td></tr>
                <tr><td colspan="3" class="text-muted small fw-semibold pt-2">Jobs &amp; Queue</td></tr>
                <tr><td><span class="badge bg-success">GET</span></td><td><code>/api/v1/clients/{id}/jobs</code></td><td>Backup history (paginated)</td></tr>
                <tr><td><span class="badge bg-success">GET</span></td><td><code>/api/v1/clients/{id}/jobs/{job_id}</code></td><td>Job detail with output</td></tr>
                <tr><td><span class="badge bg-success">GET</span></td><td><code>/api/v1/queue</code></td><td>Global queue (all active jobs)</td></tr>
                <tr><td colspan="3" class="text-muted small fw-semibold pt-2">Plugins</td></tr>
                <tr><td><span class="badge bg-success">GET</span></td><td><code>/api/v1/plugins</code></td><td>List available plugins</td></tr>
                <tr><td><span class="badge bg-success">GET</span></td><td><code>/api/v1/plugins/schema</code></td><td>Get plugin field schemas</td></tr>
                <tr><td><span class="badge bg-success">GET</span></td><td><code>/api/v1/clients/{id}/plugin-configs</code></td><td>List plugin configs for a client</td></tr>
                <tr><td><span class="badge bg-primary">POST</span></td><td><code>/api/v1/clients/{id}/plugin-configs</code></td><td>Create a plugin config</td></tr>
                <tr><td colspan="3" class="text-muted small fw-semibold pt-2">Storage</td></tr>
                <tr><td><span class="badge bg-success">GET</span></td><td><code>/api/v1/storage</code></td><td>List local &amp; remote SSH storage locations</td></tr>
            </tbody>
        </table>

        <div class="mt-3">
            <p class="small text-muted mb-1"><strong>Example: Create a client</strong></p>
            <pre class="bg-body-secondary p-2 rounded small mb-0"><code>curl -X POST https://your-server/api/v1/clients \
  -H "Authorization: Bearer bbs_tok_..." \
  -H "Content-Type: application/json" \
  -d '{"name": "web-server-01"}'</code></pre>
        </div>

        <div class="mt-3">
            <p class="small text-muted mb-1"><strong>CLI token management</strong></p>
            <pre class="bg-body-secondary p-2 rounded small mb-0"><code>sudo /var/www/bbs/bin/bbs-token create --name "ansible"
sudo /var/www/bbs/bin/bbs-token list
sudo /var/www/bbs/bin/bbs-token revoke "ansible"</code></pre>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Updates Tab -->
<?php if ($activeTab === 'updates'): ?>

<!-- Updates Sub-Navigation -->
<ul class="nav storage-subnav mb-4">
    <li class="nav-item">
        <a class="nav-link <?= $updatesSection === 'software' ? 'active' : '' ?>" href="/settings?tab=updates">
            <i class="bi bi-cloud-arrow-down me-1"></i> Software Updates
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $updatesSection === 'borg' ? 'active' : '' ?>" href="/settings?tab=updates&section=borg">
            <i class="bi bi-box-seam me-1"></i> Borg Clients
        </a>
    </li>
</ul>

<?php if ($updatesSection === 'borg'):
    $borgService = new \BBS\Services\BorgVersionService();
    $updateMode = $borgService->getUpdateMode();
    $serverVersion = $borgService->getServerVersion();
    $autoUpdate = $borgService->isAutoUpdateEnabled();
    // Use cached version for fast page load (AJAX will refresh it)
    $serverBorgVersion = $borgService->getServerBorgVersionCached();
    $lastBorgCheck = $borgService->getLastCheckTime();
    $serverVersions = $borgService->getServerVersions();
    $allAgents = $borgService->getAllAgentVersions();

    // Check compatibility for each agent with selected server version
    $agentCompatibility = [];
    if ($updateMode === 'server' && !empty($serverVersion)) {
        foreach ($allAgents as $agent) {
            $agentCompatibility[$agent['id']] = $borgService->isAgentCompatibleWithServerVersion($agent, $serverVersion);
        }
    }
?>
<div class="row g-4">
    <div class="col-lg-6">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-primary bg-opacity-10 fw-semibold">
                <i class="bi bi-box-seam me-1"></i> Borg Version Updater
            </div>
            <div class="card-body">
                <!-- Server borg version -->
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <div>
                        <i class="bi bi-server me-1"></i> Server Borg:
                        <span id="server-borg-version">
                        <?php if ($serverBorgVersion): ?>
                            <span class="badge bg-success">v<?= htmlspecialchars($serverBorgVersion) ?></span>
                            <span class="badge bg-body-secondary text-body border small"><?= $updateMode === 'server' ? 'Server' : 'Official' ?></span>
                        <?php else: ?>
                            <span class="badge bg-secondary"><i class="bi bi-hourglass-split"></i> checking...</span>
                        <?php endif; ?>
                        </span>
                    </div>
                    <form method="POST" action="/settings/borg/update-server">
                        <input type="hidden" name="csrf_token" value="<?= $this->csrfToken() ?>">
                        <button type="submit" class="btn btn-sm btn-outline-primary">
                            <i class="bi bi-arrow-up-circle me-1"></i> Update Server
                        </button>
                    </form>
                </div>

                <form method="POST" action="/settings/borg/save">
                    <input type="hidden" name="csrf_token" value="<?= $this->csrfToken() ?>">

                    <!-- Official Binaries option -->
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="radio" name="borg_update_mode" id="modeOfficial"
                               value="official" <?= $updateMode === 'official' ? 'checked' : '' ?>
                               onchange="document.getElementById('serverOptions').style.display='none'">
                        <label class="form-check-label fw-semibold" for="modeOfficial">
                            Use Official Binaries
                        </label>
                        <div class="form-text ms-4">
                            Download and install the most up-to-date and compatible Borg Version for each Agent & Server.
                            This may cause mis-matched Borg versions depending on client operating systems, but should
                            still work without issue.
                        </div>
                    </div>

                    <!-- Server Binaries option -->
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="radio" name="borg_update_mode" id="modeServer"
                               value="server" <?= $updateMode === 'server' ? 'checked' : '' ?>
                               onchange="document.getElementById('serverOptions').style.display='block'">
                        <label class="form-check-label fw-semibold" for="modeServer">
                            Use Server Binaries
                        </label>
                        <div class="form-text ms-4">
                            These newer binaries work with older operating systems that can't use the official ones.
                            Compiled and signed by BBS Authors. See <a href="https://github.com/borgbackup/borg/issues/9285" target="_blank">Borg Issue 9285</a>.
                        </div>
                    </div>

                    <!-- Server version selector (shown when server mode selected) -->
                    <div id="serverOptions" class="ms-4 mb-3" style="display: <?= $updateMode === 'server' ? 'block' : 'none' ?>">
                        <?php if (empty($serverVersions)): ?>
                            <div class="alert alert-info py-2 px-3 small">
                                <i class="bi bi-info-circle me-1"></i> No server-hosted binaries found in <code>/public/borg/</code>
                            </div>
                        <?php else: ?>
                            <label class="form-label small fw-semibold">Select Version</label>
                            <select name="borg_server_version" class="form-select form-select-sm" style="max-width: 200px;">
                                <?php foreach ($serverVersions as $v): ?>
                                <option value="<?= htmlspecialchars($v) ?>" <?= $v === $serverVersion ? 'selected' : '' ?>>
                                    v<?= htmlspecialchars($v) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        <?php endif; ?>
                    </div>

                    <!-- Auto-update checkbox -->
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" name="borg_auto_update" id="autoUpdate"
                               value="1" <?= $autoUpdate ? 'checked' : '' ?>>
                        <label class="form-check-label" for="autoUpdate">
                            Enable auto-updates (check daily)
                        </label>
                    </div>

                    <button type="submit" class="btn btn-primary btn-sm">
                        <i class="bi bi-check-lg me-1"></i> Save Settings
                    </button>
                </form>

                <!-- GitHub sync for official mode -->
                <?php if ($updateMode === 'official'): ?>
                <hr>
                <div class="d-flex justify-content-between align-items-center">
                    <div class="small text-muted">
                        <?php if (!empty($lastBorgCheck)): ?>
                            Last synced: <?= \BBS\Core\TimeHelper::format($lastBorgCheck, 'M j, Y g:i A') ?>
                        <?php else: ?>
                            GitHub versions not synced yet
                        <?php endif; ?>
                    </div>
                    <form method="POST" action="/settings/borg/sync">
                        <input type="hidden" name="csrf_token" value="<?= $this->csrfToken() ?>">
                        <button type="submit" class="btn btn-sm btn-outline-secondary">
                            <i class="bi bi-arrow-clockwise me-1"></i> Sync from GitHub
                        </button>
                    </form>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-primary bg-opacity-10 fw-semibold d-flex justify-content-between align-items-center">
                <span><i class="bi bi-pc-display me-1"></i> Client Borg Versions</span>
                <?php if (!empty($allAgents)): ?>
                <form method="POST" action="/settings/borg/update-all"
                      data-confirm="Update server and queue borg updates for all compatible clients?">
                    <input type="hidden" name="csrf_token" value="<?= $this->csrfToken() ?>">
                    <button type="submit" class="btn btn-sm btn-warning">
                        <i class="bi bi-arrow-up-circle me-1"></i> Update All
                    </button>
                </form>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <?php if (empty($allAgents)): ?>
                    <p class="text-muted small mb-0">No agents connected yet.</p>
                <?php else: ?>
                    <div class="table-responsive">
                    <table class="table table-sm small mb-0" id="borg-clients-table">
                        <thead class="table-light">
                            <tr>
                                <th>Client</th>
                                <th>OS</th>
                                <th>glibc</th>
                                <th>Borg Version</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody id="borg-clients-tbody">
                    <?php foreach ($allAgents as $agent):
                        $borgVer = $agent['borg_version'] ?? 'unknown';
                        $installMethod = $agent['borg_install_method'] ?? 'unknown';
                        $borgSource = $agent['borg_source'] ?? 'unknown';
                        $isCompatible = $agentCompatibility[$agent['id']] ?? true;
                        $osInfo = $agent['os_info'] ?? '';
                        $glibcVer = $agent['glibc_version'] ?? '';
                        // Format glibc version: glibc217 -> 2.17
                        $glibcDisplay = '';
                        if ($glibcVer && preg_match('/^glibc(\d)(\d+)$/', $glibcVer, $m)) {
                            $glibcDisplay = $m[1] . '.' . $m[2];
                        } elseif ($glibcVer) {
                            $glibcDisplay = $glibcVer;
                        }
                        // Shorten os_info: "Rocky Linux 8.10 (Green Obsidian) x86_64" -> "Rocky Linux 8.10"
                        $osDisplay = $osInfo;
                        if ($osInfo && preg_match('/^(.+?)\s*\(/', $osInfo, $m)) {
                            $osDisplay = trim($m[1]);
                        } elseif ($osInfo) {
                            // Remove trailing architecture like "x86_64"
                            $osDisplay = preg_replace('/\s+(x86_64|aarch64|arm64|i686)$/i', '', $osInfo);
                        }
                    ?>
                            <tr>
                                <td>
                                    <i class="bi bi-pc-display me-1 text-muted"></i>
                                    <a href="/clients/<?= $agent['id'] ?>" class="text-decoration-none fw-semibold">
                                        <?= htmlspecialchars($agent['name']) ?>
                                    </a>
                                    <?php if ($updateMode === 'server' && !$isCompatible): ?>
                                        <small class="text-muted ms-1" title="No server binary available for this agent's platform. The current borg version works fine — this agent just can't be updated via server push."><i class="bi bi-info-circle"></i></small>
                                    <?php endif; ?>
                                </td>
                                <td class="text-muted"><?= htmlspecialchars($osDisplay ?: '-') ?></td>
                                <td class="text-muted"><?= htmlspecialchars($glibcDisplay ?: '-') ?></td>
                                <td>
                                    <span class="badge bg-secondary"><?= htmlspecialchars($borgVer) ?></span>
                                    <span class="badge bg-body-secondary text-body border"><?= htmlspecialchars($installMethod) ?></span>
                                    <?php if ($borgSource !== 'unknown'): ?>
                                    <span class="badge bg-body-secondary text-body border"><?= ucfirst(htmlspecialchars($borgSource)) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end">
                                    <form method="POST" action="/settings/borg/update-agent/<?= $agent['id'] ?>" class="d-inline">
                                        <input type="hidden" name="csrf_token" value="<?= $this->csrfToken() ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-primary py-0 px-1" title="Update this client"
                                            <?= ($updateMode === 'server' && !$isCompatible) ? 'disabled' : '' ?>>
                                            <i class="bi bi-arrow-up-circle"></i>
                                        </button>
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

        <?php if ($updateMode === 'server' && !empty($serverVersions)): ?>
        <!-- Server-hosted binaries info -->
        <div class="card border-0 shadow-sm mt-4">
            <div class="card-header bg-primary bg-opacity-10 fw-semibold">
                <i class="bi bi-hdd me-1"></i> Available Server Binaries
            </div>
            <div class="card-body">
                <p class="text-muted small mb-2">
                    These binaries are compiled for older glibc versions to support a wider range of Linux distributions.
                </p>
                <?php
                $serverHostedBinaries = $borgService->getServerHostedBinaries();
                foreach ($serverHostedBinaries as $version => $binaries):
                ?>
                    <div class="mb-2">
                        <strong class="small">v<?= htmlspecialchars($version) ?></strong>
                    </div>
                    <?php foreach ($binaries as $bin): ?>
                    <div class="d-flex justify-content-between align-items-center small py-1 ps-3">
                        <span>
                            <i class="bi bi-file-earmark-binary me-1 text-muted"></i>
                            <?= htmlspecialchars($bin['filename']) ?>
                        </span>
                        <span class="badge bg-body-secondary text-body border">
                            glibc &ge; <?= htmlspecialchars(substr($bin['glibc'], 0, 1) . '.' . substr($bin['glibc'], 1)) ?>
                        </span>
                    </div>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
(function() {
    var csrfToken = <?= json_encode($this->csrfToken()) ?>;
    var updateMode = <?= json_encode($updateMode) ?>;

    function updateBorgStatus() {
        fetch('/api/borg-status')
            .then(function(r) { return r.json(); })
            .then(function(data) {
                // Update server borg version
                var serverEl = document.getElementById('server-borg-version');
                if (serverEl && data.server_borg_version) {
                    var modeLabel = data.update_mode === 'server' ? 'Server' : 'Official';
                    serverEl.innerHTML = '<span class="badge bg-success">v' + data.server_borg_version.replace(/</g, '&lt;') + '</span> '
                        + '<span class="badge bg-body-secondary text-body border small">' + modeLabel + '</span>';
                } else if (serverEl && !data.server_borg_version) {
                    serverEl.innerHTML = '<span class="badge bg-danger">not installed</span>';
                }

                // Update client table
                var tbody = document.getElementById('borg-clients-tbody');
                if (tbody && data.agents) {
                    var html = '';
                    data.agents.forEach(function(agent) {
                        html += '<tr>';
                        html += '<td><i class="bi bi-pc-display me-1 text-muted"></i>';
                        html += '<a href="/clients/' + agent.id + '" class="text-decoration-none fw-semibold">' + agent.name.replace(/</g, '&lt;') + '</a>';
                        if (data.update_mode === 'server' && !agent.is_compatible) {
                            html += ' <small class="text-muted ms-1" title="No server binary available for this agent\'s platform. The current borg version works fine — this agent just can\'t be updated via server push."><i class="bi bi-info-circle"></i></small>';
                        }
                        html += '</td>';
                        html += '<td class="text-muted">' + agent.os_display.replace(/</g, '&lt;') + '</td>';
                        html += '<td class="text-muted">' + agent.glibc_display.replace(/</g, '&lt;') + '</td>';
                        html += '<td>';
                        html += '<span class="badge bg-secondary">' + agent.borg_version.replace(/</g, '&lt;') + '</span> ';
                        html += '<span class="badge bg-body-secondary text-body border">' + agent.install_method.replace(/</g, '&lt;') + '</span>';
                        if (agent.borg_source !== 'unknown') {
                            html += ' <span class="badge bg-body-secondary text-body border">' + agent.borg_source.charAt(0).toUpperCase() + agent.borg_source.slice(1) + '</span>';
                        }
                        html += '</td>';
                        html += '<td class="text-end">';
                        html += '<form method="POST" action="/settings/borg/update-agent/' + agent.id + '" class="d-inline">';
                        html += '<input type="hidden" name="csrf_token" value="' + csrfToken + '">';
                        html += '<button type="submit" class="btn btn-sm btn-outline-primary py-0 px-1" title="Update this client"';
                        if (data.update_mode === 'server' && !agent.is_compatible) {
                            html += ' disabled';
                        }
                        html += '><i class="bi bi-arrow-up-circle"></i></button></form>';
                        html += '</td></tr>';
                    });
                    tbody.innerHTML = html;
                }
            })
            .catch(function() {});
    }

    // Initial fetch to get fresh server version (replaces "checking...")
    setTimeout(updateBorgStatus, 500);

    // Refresh every 30 seconds
    setInterval(updateBorgStatus, 30000);
})();
</script>
<?php endif; ?>

<?php endif; ?><!-- /updates tab -->

<!-- Updates Tab (Software Updates section) -->
<?php if ($activeTab === 'updates'): ?>

<?php if ($updatesSection === 'software'):
    $updateSvc = new \BBS\Services\UpdateService();
    $currentVersion = $updateSvc->getCurrentVersion();
    $latest = $updateSvc->getLatestRelease();
    $hasUpdate = $updateSvc->isUpdateAvailable();
    $includePrereleases = $updateSvc->getIncludePrereleases();
    $isDocker = \BBS\Services\UpdateService::isRunningInDocker();
    $upgradeResult = $_SESSION['upgrade_result'] ?? null;
    unset($_SESSION['upgrade_result']);
?>
<?php
// Agent version check
$bundledAgentVersion = null;
$agentPyFile = dirname(__DIR__, 3) . '/agent/bbs-agent.py';
if (file_exists($agentPyFile)) {
    $fh = fopen($agentPyFile, 'r');
    if ($fh) {
        for ($i = 0; $i < 50 && ($ln = fgets($fh)) !== false; $i++) {
            if (preg_match('/^AGENT_VERSION\s*=\s*["\']([^"\']+)["\']/m', $ln, $mv)) {
                $bundledAgentVersion = $mv[1];
                break;
            }
        }
        fclose($fh);
    }
}
$allAgents = $bundledAgentVersion ? $this->db->fetchAll("SELECT id, name, agent_version FROM agents WHERE agent_version IS NOT NULL") : [];
$outdatedAgents = $bundledAgentVersion ? array_filter($allAgents, fn($a) => $a['agent_version'] !== $bundledAgentVersion) : [];
$totalAgents = count($allAgents);
$outdatedCount = count($outdatedAgents);
?>
<div class="row g-4">
    <div class="col-lg-6">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-primary bg-opacity-10 fw-semibold">
                Borg Backup Server Version
            </div>
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="text-muted small">Installed</div>
                        <div class="fs-4 fw-bold">v<?= htmlspecialchars($currentVersion) ?></div>
                    </div>
                    <div class="text-end">
                        <div class="text-muted small">Latest Release</div>
                        <?php if (!empty($latest['version'])): ?>
                            <div class="fs-4 fw-bold">v<?= htmlspecialchars($latest['version']) ?></div>
                        <?php else: ?>
                            <div class="text-muted">Not checked yet</div>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if (!empty($latest['version'])): ?>
                <div class="mt-2 mb-3">
                    <?php if ($hasUpdate): ?>
                        <span class="badge rounded-pill text-dark" style="background-color: #fff3cd;"><i class="bi bi-arrow-up-circle me-1"></i>Update available</span>
                    <?php else: ?>
                        <span class="badge rounded-pill" style="background-color: #e8f5e9; color: #2e7d32;"><i class="bi bi-check-circle me-1"></i>Up to date</span>
                    <?php endif; ?>
                    <?php if (!empty($latest['checked_at'])): ?>
                        <span class="text-muted small ms-2">Checked <?= \BBS\Core\TimeHelper::ago($latest['checked_at']) ?></span>
                    <?php endif; ?>
                </div>
                <?php else: ?>
                <div class="mb-3"></div>
                <?php endif; ?>

                <div class="d-flex gap-2 flex-wrap align-items-start">
                    <form method="POST" action="/settings/check-update">
                        <input type="hidden" name="csrf_token" value="<?= $this->csrfToken() ?>">
                        <div class="d-flex align-items-center gap-2">
                            <button type="submit" class="btn btn-sm btn-outline-secondary">
                                <i class="bi bi-arrow-clockwise me-1"></i> Check for Updates
                            </button>
                            <div class="form-check form-check-inline mb-0 ms-1">
                                <input class="form-check-input" type="checkbox" name="include_prereleases" id="include-prereleases" value="1" <?= $includePrereleases ? 'checked' : '' ?>>
                                <label class="form-check-label small text-muted" for="include-prereleases">Include beta versions</label>
                            </div>
                        </div>
                    </form>
                    <?php if ($hasUpdate && !$isDocker): ?>
                    <form method="POST" action="/settings/upgrade" data-confirm="This will start a background upgrade to v<?= htmlspecialchars($latest['version']) ?>. You'll be redirected to a progress page.&#10;&#10;Active backups must complete first. New backups will be paused during the upgrade.&#10;&#10;Proceed?">
                        <input type="hidden" name="csrf_token" value="<?= $this->csrfToken() ?>">
                        <button type="submit" class="btn btn-sm btn-primary">
                            <i class="bi bi-cloud-arrow-down me-1"></i> Upgrade to v<?= htmlspecialchars($latest['version']) ?>
                        </button>
                    </form>
                    <?php endif; ?>
                </div>

                <?php if ($isDocker && $hasUpdate): ?>
                <div class="alert alert-info small mt-3 mb-0">
                    <i class="bi bi-box me-1"></i> <strong>Docker Upgrade Instructions</strong>
                    <p class="mt-2 mb-2">To upgrade to v<?= htmlspecialchars($latest['version']) ?>, pull the latest image and recreate the container:</p>
                    <pre class="bg-dark text-light p-2 rounded mb-2" style="font-size: 0.85em;">docker compose pull
docker compose up -d</pre>
                    <p class="mb-2">Or pin a specific version in your <code>docker-compose.yml</code>:</p>
                    <pre class="bg-dark text-light p-2 rounded mb-2" style="font-size: 0.85em;">image: marcpope/borgbackupserver:v<?= htmlspecialchars($latest['version']) ?></pre>
                    <p class="mb-0">Your data is stored on the Docker volume and will be preserved. See the <a href="https://github.com/marcpope/borgbackupserver/wiki/Docker-Installation" target="_blank">Docker Installation wiki</a> for full instructions.</p>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <div id="agent-updates-card">
        <?php if ($bundledAgentVersion): ?>
        <div class="card border-0 shadow-sm mt-4">
            <div class="card-header bg-primary bg-opacity-10 fw-semibold d-flex justify-content-between align-items-center">
                <span><i class="bi bi-pc-display me-1"></i> BBS Client</span>
                <span class="badge bg-success" id="agent-bundled-ver">v<?= htmlspecialchars($bundledAgentVersion) ?></span>
            </div>
            <div class="card-body" id="agent-updates-body">
                <p class="text-muted small mb-3">The BBS Client receives commands from the server to initiate backups, perform restores, and update Borg software.</p>
                <?php if ($totalAgents === 0): ?>
                    <p class="text-muted small mb-0">No clients connected yet.</p>
                <?php elseif ($outdatedCount === 0): ?>
                    <div class="d-flex align-items-center small">
                        <span class="badge rounded-pill me-2" style="background-color: #e8f5e9; color: #2e7d32;"><i class="bi bi-check-circle me-1"></i>Up to date</span>
                        All <?= $totalAgents ?> client(s) running v<?= htmlspecialchars($bundledAgentVersion) ?>
                    </div>
                <?php else: ?>
                    <div class="d-flex align-items-center justify-content-between mb-3">
                        <div class="small">
                            <span class="badge rounded-pill text-dark me-1" style="background-color: #fff3cd;"><?= $outdatedCount ?> outdated</span>
                            of <?= $totalAgents ?> client(s)
                        </div>
                        <form method="POST" action="/settings/upgrade-agents" data-confirm="Queue agent updates for <?= $outdatedCount ?> client(s)?">
                            <input type="hidden" name="csrf_token" value="<?= $this->csrfToken() ?>">
                            <button type="submit" class="btn btn-sm btn-primary">
                                <i class="bi bi-arrow-up-circle me-1"></i> Update All
                            </button>
                        </form>
                    </div>
                    <?php foreach ($outdatedAgents as $oa): ?>
                    <div class="d-flex justify-content-between align-items-center small py-1">
                        <span><i class="bi bi-pc-display me-1 text-muted"></i><?= htmlspecialchars($oa['name']) ?></span>
                        <span class="text-muted">v<?= htmlspecialchars($oa['agent_version']) ?></span>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
        </div>
    </div>

    <?php if (!empty($latest['notes'])): ?>
    <div class="col-lg-6">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-primary bg-opacity-10 fw-semibold">
                <i class="bi bi-journal-text me-1"></i> Release Notes
                <?php if (!empty($latest['url'])): ?>
                    <a href="<?= htmlspecialchars($latest['url']) ?>" target="_blank" class="float-end small text-decoration-none">
                        View on GitHub <i class="bi bi-box-arrow-up-right"></i>
                    </a>
                <?php endif; ?>
            </div>
            <div class="card-body small release-notes-md">
                <?php $converter = new \League\CommonMark\GithubFlavoredMarkdownConverter(['html_input' => 'strip']); echo $converter->convert($latest['notes']); ?>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php if (!$isDocker): ?>
<hr>

<div class="row g-4">
    <div class="col-lg-6">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-primary bg-opacity-10 fw-semibold">
                <i class="bi bi-git me-1"></i> Developer Sync
            </div>
            <div class="card-body">
                <div class="alert alert-secondary small py-2 px-3 mb-3">
                    <i class="bi bi-exclamation-triangle me-1"></i>
                    <strong>Developer Use Only:</strong> Syncs unpublished development code from the main branch. This may include incomplete features and untested changes. Only use if directed by a developer for troubleshooting purposes.
                </div>
                <form method="POST" action="/settings/sync" data-confirm="This will sync to the latest code from the main branch (may include unreleased changes). You'll be redirected to a progress page.&#10;&#10;Active backups must complete first. New backups will be paused during the sync.&#10;&#10;Proceed?">
                    <input type="hidden" name="csrf_token" value="<?= $this->csrfToken() ?>">
                    <button type="submit" class="btn btn-outline-secondary btn-sm" title="Pulls latest from main branch (may include unreleased changes)">
                        <i class="bi bi-git me-1"></i> Sync Dev Code
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($upgradeResult): ?>
<div class="card border-0 shadow-sm mt-4">
    <div class="card-header bg-primary bg-opacity-10 fw-semibold">
        <i class="bi bi-terminal me-1"></i> Upgrade Log
    </div>
    <div class="card-body">
        <pre class="mb-0 bg-dark text-light p-3 rounded small" style="max-height: 400px; overflow-y: auto;"><?= htmlspecialchars(implode("\n", $upgradeResult['log'])) ?></pre>
    </div>
</div>
<?php endif; ?>
<?php endif; ?>

<?php endif; ?>
</div><!-- /client-tab-content -->

<script>
// Remote SSH host management
function testRemoteSsh(id, btn) {
    var resultDiv = document.getElementById('remoteSshTestResult' + id);
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';
    resultDiv.innerHTML = '<div class="text-muted small"><span class="spinner-border spinner-border-sm me-1"></span> Testing connection...</div>';

    fetch('/remote-ssh-configs/' + id + '/test', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'csrf_token=' + encodeURIComponent(document.querySelector('[name=csrf_token]')?.value || '')
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (data.status === 'ok') {
            resultDiv.innerHTML = '<div class="alert alert-success alert-sm py-1 px-2 mb-0 small"><i class="bi bi-check-circle me-1"></i> Connected — ' + (data.version || 'borg detected').replace(/</g, '&lt;') + '</div>';
        } else {
            resultDiv.innerHTML = '<div class="alert alert-danger alert-sm py-1 px-2 mb-0 small"><i class="bi bi-x-circle me-1"></i> ' + (data.error || 'Connection failed').replace(/</g, '&lt;') + '</div>';
        }
    })
    .catch(function() {
        resultDiv.innerHTML = '<div class="alert alert-danger alert-sm py-1 px-2 mb-0 small">Request failed</div>';
    })
    .finally(function() {
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-plug"></i>';
    });
}

function deleteRemoteSsh(id, name) {
    if (!confirm('Delete remote SSH host "' + name + '"?\n\nThis will fail if any repositories use this host.')) return;
    var form = document.createElement('form');
    form.method = 'POST';
    form.action = '/remote-ssh-configs/' + id + '/delete';
    var csrf = document.createElement('input');
    csrf.type = 'hidden';
    csrf.name = 'csrf_token';
    csrf.value = document.querySelector('[name=csrf_token]')?.value || '';
    form.appendChild(csrf);
    document.body.appendChild(form);
    form.submit();
}

function applyRemotePreset(select, form) {
    var presets = {
        'rsync.net': { port: 22, base_path: './', borg_remote_path: 'borg1', append_repo_name: true },
        'borgbase': { port: 22, base_path: './repo', borg_remote_path: '', append_repo_name: false },
        'hetzner': { port: 23, base_path: './backups', borg_remote_path: '', append_repo_name: true }
    };
    var preset = presets[select.value];
    if (!preset) return;
    var portInput = form.querySelector('[name=remote_port]');
    var baseInput = form.querySelector('[name=remote_base_path]');
    var borgInput = form.querySelector('[name=borg_remote_path]');
    var appendInput = form.querySelector('[name=append_repo_name]');
    if (portInput) portInput.value = preset.port;
    if (baseInput) baseInput.value = preset.base_path;
    if (borgInput) borgInput.value = preset.borg_remote_path;
    if (appendInput) appendInput.checked = preset.append_repo_name;
}
</script>

<script>
document.getElementById('btnTestSmtp')?.addEventListener('click', function() {
    var btn = this;
    var result = document.getElementById('smtpTestResult');
    btn.disabled = true;
    result.textContent = 'Testing...';
    result.className = 'ms-2 small text-muted';
    fetch('/settings/test-smtp', {method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded'}, body: 'csrf_token=' + encodeURIComponent(document.querySelector('input[name=csrf_token]').value)})
        .then(function(r) { return r.json(); })
        .then(function(data) {
            btn.disabled = false;
            if (data.success) {
                result.textContent = 'Success';
                result.className = 'ms-2 small text-success fw-semibold';
            } else {
                result.textContent = 'Failed: ' + data.error;
                result.className = 'ms-2 small text-danger fw-semibold';
            }
        })
        .catch(function() {
            btn.disabled = false;
            result.textContent = 'Request failed.';
            result.className = 'ms-2 small text-danger fw-semibold';
        });
});

// AJAX refresh for Agent Updates section
(function() {
    var container = document.getElementById('agent-updates-body');
    if (!container) return;
    var csrfToken = '<?= $this->csrfToken() ?>';
    setInterval(function() {
        // Only refresh if updates tab is active
        if (!document.getElementById('agent-updates-card') || document.getElementById('agent-updates-card').offsetParent === null) return;
        fetch('/api/agent-updates')
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (!data.bundled_version) return;
                var verEl = document.getElementById('agent-bundled-ver');
                if (verEl) verEl.textContent = 'v' + data.bundled_version;
                var html = '';
                var descHtml = '<p class="text-muted small mb-3">The BBS Client receives commands from the server to initiate backups, perform restores, and update Borg software.</p>';
                if (data.total === 0) {
                    html = descHtml + '<p class="text-muted small mb-0">No clients connected yet.</p>';
                } else if (data.outdated.length === 0) {
                    html = descHtml + '<div class="d-flex align-items-center small">'
                         + '<span class="badge rounded-pill me-2" style="background-color: #e8f5e9; color: #2e7d32;"><i class="bi bi-check-circle me-1"></i>Up to date</span>'
                         + 'All ' + data.total + ' client(s) running v' + data.bundled_version
                         + '</div>';
                } else {
                    html = descHtml + '<div class="d-flex align-items-center justify-content-between mb-3">'
                         + '<div class="small"><span class="badge rounded-pill text-dark me-1" style="background-color: #fff3cd;">' + data.outdated.length + ' outdated</span> of ' + data.total + ' client(s)</div>'
                         + '<form method="POST" action="/settings/upgrade-agents" data-confirm="Queue agent updates for ' + data.outdated.length + ' client(s)?">'
                         + '<input type="hidden" name="csrf_token" value="' + csrfToken + '">'
                         + '<button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-arrow-up-circle me-1"></i> Update All</button>'
                         + '</form></div>';
                    data.outdated.forEach(function(a) {
                        html += '<div class="d-flex justify-content-between align-items-center small py-1">'
                              + '<span><i class="bi bi-pc-display me-1 text-muted"></i>' + a.name.replace(/</g, '&lt;') + '</span>'
                              + '<span class="text-muted">v' + a.agent_version.replace(/</g, '&lt;') + '</span></div>';
                    });
                }
                container.innerHTML = html;
            })
            .catch(function() {});
    }, 10000);
})();
</script>
