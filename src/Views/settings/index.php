<?php
// The 'remote' / 'offsite' / 'storage' tab redirect to /storage-locations
// is handled in SettingsController::index() before any output starts.
// Anything reaching this view falls through to a normal settings tab.
$activeTab = $_GET['tab'] ?? 'general';
if ($activeTab === 'borg') { $activeTab = 'updates'; $updatesSection = 'borg'; }
if ($activeTab === 'updates') { $updatesSection = $updatesSection ?? ($_GET['section'] ?? 'software'); }
?>




<!-- Agent Tab -->
<?php if ($activeTab === 'agent'): ?>
<div class="settings-page-head">
    <h1 class="settings-page-title">Agent</h1>
    <p class="settings-page-lede mb-0">How clients check in, how their backups are watched, and what happens when one drops out.</p>
</div>

<?php
$dayNames = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
$compactDay  = (int) ($settings['auto_compact_day'] ?? 6);
$compactHour = (int) ($settings['auto_compact_hour'] ?? 2);
?>
<form method="POST" action="/settings">
    <input type="hidden" name="csrf_token" value="<?= $this->csrfToken() ?>">
    <input type="hidden" name="_tab" value="agent">
    <input type="hidden" name="_checkboxes" value="auto_retry_failed_backups,auto_update_agents,precount_files">

    <h5 class="settings-group">Agent Communication</h5>

    <div class="settings-row">
        <div>
            <div class="settings-row-label">Agent Poll Interval</div>
            <p class="settings-row-help">How often clients check in for new work.</p>
        </div>
        <div class="settings-row-control">
            <input type="number" class="form-control form-control-narrow" name="agent_poll_interval"
                   value="<?= (int) ($settings['agent_poll_interval'] ?? 30) ?>" min="10" max="300">
            <span class="settings-row-unit">seconds</span>
        </div>
        <div class="settings-row-default">Default: 30 seconds</div>
    </div>

    <div class="settings-row">
        <div>
            <div class="settings-row-label">Notify When Agent Offline</div>
            <p class="settings-row-help">How long a client must be gone before an offline notification is sent. Brief network blips and short laptop suspends never become alerts; the client still shows offline immediately.</p>
        </div>
        <div class="settings-row-control">
            <input type="number" class="form-control form-control-narrow" name="agent_offline_notify_minutes"
                   value="<?= htmlspecialchars($settings['agent_offline_notify_minutes'] ?? '5') ?>" min="1" max="60">
            <span class="settings-row-unit">minutes</span>
        </div>
        <div class="settings-row-default">Default: 5 minutes</div>
    </div>

    <div class="settings-row">
        <div>
            <div class="settings-row-label">Auto-update agents when BBS updates</div>
            <p class="settings-row-help">Queue an agent update for every outdated, online client after BBS updates, so agents stay in step with the server. Turning this off may have unexpected results.</p>
        </div>
        <div class="settings-row-control">
            <div class="form-check form-switch mb-0">
                <input class="form-check-input" type="checkbox" role="switch" name="auto_update_agents" value="1"
                       id="autoUpdateAgents" <?= (($settings['auto_update_agents'] ?? '1') === '1') ? 'checked' : '' ?>>
                <label class="form-check-label" for="autoUpdateAgents"
                       data-on="On" data-off="Off"><?= (($settings['auto_update_agents'] ?? '1') === '1') ? 'On' : 'Off' ?></label>
            </div>
        </div>
        <div class="settings-row-default">Default: On</div>
    </div>

    <h5 class="settings-group">Backup Monitoring</h5>

    <div class="settings-row">
        <div>
            <div class="settings-row-label">Stall Detection Timeout</div>
            <p class="settings-row-help">Kill a backup that has reported no progress for this long. Raise it for very large files.</p>
        </div>
        <div class="settings-row-control">
            <input type="number" class="form-control form-control-narrow" name="stall_timeout_minutes"
                   value="<?= htmlspecialchars($settings['stall_timeout_minutes'] ?? '120') ?>" min="10" max="1440">
            <span class="settings-row-unit">minutes</span>
        </div>
        <div class="settings-row-default">Default: 120 minutes</div>
    </div>

    <div class="settings-row">
        <div>
            <div class="settings-row-label">Count files before a backup starts</div>
            <p class="settings-row-help">Only affects a plan's first backup — after that the progress bar uses what the previous backup stored, which is exact. The first-run walk ignores the plan's exclude patterns, so on a plan that excludes a large directory it over-counts.</p>
        </div>
        <div class="settings-row-control">
            <div class="form-check form-switch mb-0">
                <input class="form-check-input" type="checkbox" role="switch" name="precount_files" value="1"
                       id="precountFiles" <?= (($settings['precount_files'] ?? '1') === '1') ? 'checked' : '' ?>>
                <label class="form-check-label" for="precountFiles"
                       data-on="On" data-off="Off"><?= (($settings['precount_files'] ?? '1') === '1') ? 'On' : 'Off' ?></label>
            </div>
        </div>
        <div class="settings-row-default">Default: On</div>
    </div>

    <h5 class="settings-group">Failure Recovery</h5>

    <div class="settings-row">
        <div>
            <div class="settings-row-label">Auto-retry backups when a client goes offline</div>
            <p class="settings-row-help">Re-queue a backup that failed because the client disconnected mid-run. Real errors — a missing borg, a locked repository — are never retried.</p>
        </div>
        <div class="settings-row-control">
            <div class="form-check form-switch mb-0">
                <input class="form-check-input" type="checkbox" role="switch" name="auto_retry_failed_backups" value="1"
                       id="autoRetryFailed" <?= (($settings['auto_retry_failed_backups'] ?? '1') === '1') ? 'checked' : '' ?>>
                <label class="form-check-label" for="autoRetryFailed"
                       data-on="On" data-off="Off"><?= (($settings['auto_retry_failed_backups'] ?? '1') === '1') ? 'On' : 'Off' ?></label>
            </div>
        </div>
        <div class="settings-row-default">Default: On</div>
    </div>

    <div class="settings-row">
        <div>
            <div class="settings-row-label">Max Retry Attempts</div>
            <p class="settings-row-help">Cap on those retries per plan. Once exhausted a final email is sent, bypassing dedup, so a persistent failure is never hidden.</p>
        </div>
        <div class="settings-row-control">
            <input type="number" class="form-control form-control-narrow" name="auto_retry_max_attempts"
                   value="<?= htmlspecialchars($settings['auto_retry_max_attempts'] ?? '3') ?>" min="1" max="10">
            <span class="settings-row-unit">attempts</span>
        </div>
        <div class="settings-row-default">Default: 3 attempts</div>
    </div>

    <div class="settings-row">
        <div>
            <div class="settings-row-label">Give Up On Running Jobs After</div>
            <p class="settings-row-help">How long a client must be silent <em>and</em> report no progress before its running backup is treated as dead. A busy client is hard to tell from a disconnected one, and a backup killed at the wrong moment restarts from the beginning.</p>
        </div>
        <div class="settings-row-control">
            <input type="number" class="form-control form-control-narrow" name="job_offline_grace_minutes"
                   value="<?= htmlspecialchars($settings['job_offline_grace_minutes'] ?? '5') ?>" min="1" max="120">
            <span class="settings-row-unit">minutes</span>
        </div>
        <div class="settings-row-default">Default: 5 minutes</div>
    </div>

    <div class="settings-row">
        <div>
            <div class="settings-row-label">Retry Backoff</div>
            <p class="settings-row-help">Wait before the first retry, doubling each attempt and capped at an hour, so retries don't stack up while the client is still struggling.</p>
        </div>
        <div class="settings-row-control">
            <input type="number" class="form-control form-control-narrow" name="auto_retry_backoff_minutes"
                   value="<?= htmlspecialchars($settings['auto_retry_backoff_minutes'] ?? '5') ?>" min="1" max="60">
            <span class="settings-row-unit">minutes</span>
        </div>
        <div class="settings-row-default">Default: 5 minutes</div>
    </div>

    <div class="settings-row">
        <div>
            <div class="settings-row-label">Backup Overdue After</div>
            <p class="settings-row-help">How long a client may go without a successful backup before health monitoring reports it as overdue. A client profile can override this per kind of machine &mdash; a laptop that is off for the weekend is not a fault, a database server silent for a day is.</p>
        </div>
        <div class="settings-row-control">
            <input type="number" class="form-control form-control-narrow" name="backup_overdue_hours"
                   value="<?= htmlspecialchars($settings['backup_overdue_hours'] ?? '48') ?>" min="1" max="8760">
            <span class="settings-row-unit">hours</span>
        </div>
        <div class="settings-row-default">Default: 48 hours</div>
    </div>

    <h5 class="settings-group">Repository Maintenance</h5>

    <div class="settings-row">
        <div>
            <div class="settings-row-label">Weekly Compact Schedule</div>
            <p class="settings-row-help">When repositories are auto-compacted to reclaim freed space. Runs at or after this time on the chosen day, so storage that isn't powered on around the clock still gets compacted.</p>
        </div>
        <div class="settings-row-control">
            <select class="form-select" name="auto_compact_day" style="max-width:150px;">
                <?php foreach ($dayNames as $i => $dn): ?>
                <option value="<?= $i ?>" <?= $compactDay === $i ? 'selected' : '' ?>><?= $dn ?></option>
                <?php endforeach; ?>
            </select>
            <select class="form-select" name="auto_compact_hour" style="max-width:120px;">
                <?php for ($h = 0; $h < 24; $h++): ?>
                <option value="<?= $h ?>" <?= $compactHour === $h ? 'selected' : '' ?>><?= sprintf('%02d:00', $h) ?></option>
                <?php endfor; ?>
            </select>
        </div>
        <div class="settings-row-default">Default: Saturday 02:00</div>
    </div>

    <div class="settings-actions">
        <a href="/settings?tab=agent" class="btn btn-sm btn-outline-secondary">Cancel</a>
        <button type="submit" class="btn btn-sm btn-primary">
            <i class="bi bi-save me-1"></i> Save Changes
        </button>
    </div>
</form>
<?php endif; ?>

<!-- General Tab -->
<?php if ($activeTab === 'general'): ?>
<div class="settings-page-head">
    <h1 class="settings-page-title">General</h1>
    <p class="settings-page-lede mb-0">Core configuration for this Borg Backup Server instance.</p>
</div>

<?php
$mmOn = ($settings['maintenance_mode'] ?? '0') === '1';
$sslEnabled = str_starts_with(\BBS\Core\Config::get('APP_URL', 'https://'), 'https://');
?>
<form method="POST" action="/settings">
    <input type="hidden" name="csrf_token" value="<?= $this->csrfToken() ?>">
    <input type="hidden" name="_tab" value="general">
    <?php
    // Declared dynamically: the debug row only renders with ?debug=1, and
    // naming a toggle that isn't on screen is exactly the bug this mechanism
    // exists to prevent — saving General would switch debug mode off.
    $generalToggles = ['force_2fa', 'maintenance_mode', 'self_backup_catalogs', 'self_backup_enabled', 'telemetry_opt_out'];
    $showDebugRow = isset($_GET['debug']) && $_GET['debug'] === '1';
    if ($showDebugRow) { $generalToggles[] = 'debug_mode'; }
    ?>
    <input type="hidden" name="_checkboxes" value="<?= implode(',', $generalToggles) ?>">

    <h5 class="settings-group">Server</h5>

    <div class="settings-row">
        <div>
            <div class="settings-row-label">
                Maintenance Mode
                <?php if ($mmOn): ?>
                    <span class="badge bg-warning text-dark ms-1"><i class="bi bi-exclamation-triangle-fill me-1"></i>Active</span>
                <?php endif; ?>
            </div>
            <p class="settings-row-help">Pauses all new backup jobs. Jobs already running finish normally.</p>
        </div>
        <div class="settings-row-control">
            <div class="form-check form-switch mb-0">
                <input class="form-check-input" type="checkbox" role="switch" name="maintenance_mode" value="1"
                       id="maintenance_mode" <?= $mmOn ? 'checked' : '' ?>>
                <label class="form-check-label" for="maintenance_mode"
                       data-on="On" data-off="Off"><?= $mmOn ? 'On' : 'Off' ?></label>
            </div>
        </div>
        <div class="settings-row-default">Default: Off</div>
    </div>

    <div class="settings-row">
        <div>
            <div class="settings-row-label">Server Host / IP</div>
            <p class="settings-row-help">The address clients use to reach this server. Use https:// for public servers, http:// for a LAN or internal install. Changing it rewrites the repository paths of every client without an override.</p>
        </div>
        <div class="settings-row-control">
            <select class="form-select" name="url_protocol" style="max-width:110px;">
                <option value="https" <?= $sslEnabled ? 'selected' : '' ?>>https://</option>
                <option value="http" <?= !$sslEnabled ? 'selected' : '' ?>>http://</option>
            </select>
            <input type="text" class="form-control" name="server_host" style="max-width:260px;"
                   value="<?= htmlspecialchars($settings['server_host'] ?? '') ?>" placeholder="backup.example.com">
        </div>
        <div class="settings-row-default">&nbsp;</div>
    </div>

    <div class="settings-row">
        <div>
            <div class="settings-row-label">SSH Port</div>
            <p class="settings-row-help">The port clients connect to for backups. Docker installs usually map this to something else.</p>
        </div>
        <div class="settings-row-control">
            <input type="number" class="form-control form-control-narrow" name="ssh_port"
                   value="<?= (int) ($settings['ssh_port'] ?? 22) ?>" min="1" max="65535">
        </div>
        <div class="settings-row-default">Default: 22</div>
    </div>

    <div class="settings-row">
        <div>
            <div class="settings-row-label">Max Concurrent Jobs</div>
            <p class="settings-row-help">How many backups may run at once across all clients.</p>
        </div>
        <div class="settings-row-control">
            <input type="number" class="form-control form-control-narrow" name="max_queue"
                   value="<?= htmlspecialchars($settings['max_queue'] ?? '4') ?>" min="1" max="20">
            <span class="settings-row-unit">jobs</span>
        </div>
        <div class="settings-row-default">Default: 4 jobs</div>
    </div>

    <div class="settings-row">
        <div>
            <div class="settings-row-label">Low-Storage Alert At</div>
            <p class="settings-row-help">How full a storage location may get before it raises a low-space alert. The same figure decides when <code>/api/v1/health</code> reports a storage warning.</p>
        </div>
        <div class="settings-row-control">
            <input type="number" class="form-control form-control-narrow" name="storage_alert_threshold"
                   value="<?= htmlspecialchars($settings['storage_alert_threshold'] ?? '90') ?>" min="50" max="99">
            <span class="settings-row-unit">% full</span>
        </div>
        <div class="settings-row-default">Default: 90% full</div>
    </div>

    <div class="settings-row">
        <div>
            <div class="settings-row-label">Default Theme</div>
            <p class="settings-row-help">Used for the login page and new users. Anyone can override it in their profile.</p>
        </div>
        <div class="settings-row-control">
            <select class="form-select" name="default_theme" style="max-width:150px;">
                <option value="dark" <?= ($settings['default_theme'] ?? 'dark') === 'dark' ? 'selected' : '' ?>>Dark</option>
                <option value="light" <?= ($settings['default_theme'] ?? 'dark') === 'light' ? 'selected' : '' ?>>Light</option>
            </select>
        </div>
        <div class="settings-row-default">Default: Dark</div>
    </div>

    <h5 class="settings-group">Security</h5>

    <div class="settings-row">
        <div>
            <div class="settings-row-label">Require Two-Factor Authentication</div>
            <p class="settings-row-help">Users without 2FA are sent to set it up on their next page load.</p>
        </div>
        <div class="settings-row-control">
            <div class="form-check form-switch mb-0">
                <input class="form-check-input" type="checkbox" role="switch" name="force_2fa" value="1"
                       id="force2fa" <?= ($settings['force_2fa'] ?? '0') === '1' ? 'checked' : '' ?>>
                <label class="form-check-label" for="force2fa"
                       data-on="On" data-off="Off"><?= ($settings['force_2fa'] ?? '0') === '1' ? 'On' : 'Off' ?></label>
            </div>
        </div>
        <div class="settings-row-default">Default: Off</div>
    </div>

    <div class="settings-row">
        <div>
            <div class="settings-row-label">Session Timeout</div>
            <p class="settings-row-help">Log out after this long without activity.</p>
        </div>
        <div class="settings-row-control">
            <input type="number" class="form-control form-control-narrow" name="session_timeout_hours"
                   value="<?= htmlspecialchars($settings['session_timeout_hours'] ?? '8') ?>" min="1" max="720">
            <span class="settings-row-unit">hours</span>
        </div>
        <div class="settings-row-default">Default: 8 hours</div>
    </div>

    <?php if ($showDebugRow): ?>
    <div class="settings-row">
        <div>
            <div class="settings-row-label">Debug Mode</div>
            <p class="settings-row-help">Shows detailed error pages with stack traces. Leave off in production.</p>
        </div>
        <div class="settings-row-control">
            <div class="form-check form-switch mb-0">
                <input class="form-check-input" type="checkbox" role="switch" name="debug_mode" value="1"
                       id="debugMode" <?= ($settings['debug_mode'] ?? '0') === '1' ? 'checked' : '' ?>>
                <label class="form-check-label" for="debugMode"
                       data-on="On" data-off="Off"><?= ($settings['debug_mode'] ?? '0') === '1' ? 'On' : 'Off' ?></label>
            </div>
        </div>
        <div class="settings-row-default">Default: Off</div>
    </div>
    <?php endif; ?>

    <h5 class="settings-group">Server Backups</h5>
    <p class="settings-group-note">
        <?php if (\BBS\Core\Config::isHosted()): ?>
        These cover the database, configuration and SSH host keys &mdash; <strong>not repository data</strong>. To keep them off-site, add S3 storage to your hosted account.
        <?php else: ?>
        These cover the database, configuration and SSH host keys &mdash; <strong>not repository data</strong>. To protect the repositories themselves, configure <a href="/storage-locations?section=s3">S3 offsite sync</a>.
        <?php endif; ?>
    </p>

    <div class="settings-row">
        <div>
            <div class="settings-row-label">Daily Server Backup</div>
            <p class="settings-row-help">Backs up the database, configuration and SSH keys to <code>/var/bbs/backups</code> once a day.</p>
        </div>
        <div class="settings-row-control">
            <div class="form-check form-switch mb-0">
                <input class="form-check-input" type="checkbox" role="switch" name="self_backup_enabled" value="1"
                       id="selfBackupEnabled" <?= ($settings['self_backup_enabled'] ?? '1') === '1' ? 'checked' : '' ?>>
                <label class="form-check-label" for="selfBackupEnabled"
                       data-on="On" data-off="Off"><?= ($settings['self_backup_enabled'] ?? '1') === '1' ? 'On' : 'Off' ?></label>
            </div>
        </div>
        <div class="settings-row-default">Default: On</div>
    </div>

    <div class="settings-row">
        <div>
            <div class="settings-row-label">Keep Last</div>
            <p class="settings-row-help">Older server backups are deleted automatically.</p>
        </div>
        <div class="settings-row-control">
            <input type="number" class="form-control form-control-narrow" name="self_backup_retention"
                   value="<?= htmlspecialchars($settings['self_backup_retention'] ?? '7') ?>" min="1" max="90">
            <span class="settings-row-unit">backups</span>
        </div>
        <div class="settings-row-default">Default: 7 backups</div>
    </div>

    <div class="settings-row">
        <div>
            <div class="settings-row-label">Include File Catalog Data</div>
            <p class="settings-row-help">Catalogs can be large but save time on a restore. Left out, they are rebuilt from the repositories.</p>
        </div>
        <div class="settings-row-control">
            <div class="form-check form-switch mb-0">
                <input class="form-check-input" type="checkbox" role="switch" name="self_backup_catalogs" value="1"
                       id="selfBackupCatalogs" <?= ($settings['self_backup_catalogs'] ?? '0') === '1' ? 'checked' : '' ?>>
                <label class="form-check-label" for="selfBackupCatalogs"
                       data-on="On" data-off="Off"><?= ($settings['self_backup_catalogs'] ?? '0') === '1' ? 'On' : 'Off' ?></label>
            </div>
        </div>
        <div class="settings-row-default">Default: Off</div>
    </div>

    <h5 class="settings-group">Usage Statistics</h5>

    <div class="settings-row">
        <div>
            <div class="settings-row-label">Disable Anonymous Usage Statistics</div>
            <p class="settings-row-help">BBS reports its version and OS once per version, so the developer knows which versions and platforms are in use. Nothing identifying is collected.</p>
        </div>
        <div class="settings-row-control">
            <div class="form-check form-switch mb-0">
                <input class="form-check-input" type="checkbox" role="switch" name="telemetry_opt_out" value="1"
                       id="telemetryOptOut" <?= ($settings['telemetry_opt_out'] ?? '0') === '1' ? 'checked' : '' ?>>
                <label class="form-check-label" for="telemetryOptOut"
                       data-on="On" data-off="Off"><?= ($settings['telemetry_opt_out'] ?? '0') === '1' ? 'On' : 'Off' ?></label>
            </div>
        </div>
        <div class="settings-row-default">Default: Off</div>
    </div>

    <div class="settings-actions">
        <a href="/settings?tab=general" class="btn btn-sm btn-outline-secondary">Cancel</a>
        <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-save me-1"></i> Save Changes</button>
    </div>
</form>
<?php endif; ?>

<!-- Email Settings Tab -->
<?php if ($activeTab === 'notifications'): ?>
<div class="settings-page-head">
    <h1 class="settings-page-title">Email Settings</h1>
    <p class="settings-page-lede mb-0">Outgoing mail for password resets, upgrade notices and other system messages.</p>
</div>

<?php
$smtpPortForDefault = (int) ($settings['smtp_port'] ?? 587);
$smtpSecure = $settings['smtp_secure'] ?? match ($smtpPortForDefault) {
    465 => 'ssl',
    25 => 'none',
    default => 'starttls',
};
?>
<?php if (!empty($smtpWarning)): ?>
<div class="alert alert-warning d-flex align-items-start mb-4" role="alert">
    <i class="bi bi-exclamation-triangle-fill me-2 mt-1"></i>
    <div>
        <strong>Email notifications won't actually send.</strong>
        Something is set to notify by email, but SMTP isn't configured — those events fire the in-app notification only and the email is skipped. Fill in the fields below and use <em>Send Test Email</em> to check.
    </div>
</div>
<?php endif; ?>

<div class="alert alert-info small d-flex align-items-center mt-4" role="note">
    <i class="bi bi-info-circle me-2 flex-shrink-0"></i>
    <div>For backup alerts &mdash; failures, offline clients, storage warnings &mdash; see <a href="/settings?tab=push" class="alert-link">Apprise</a>, which covers Discord, Slack, Telegram and many others.</div>
</div>

<form method="POST" action="/settings">
    <input type="hidden" name="csrf_token" value="<?= $this->csrfToken() ?>">
    <input type="hidden" name="_tab" value="notifications">
    <input type="hidden" name="_checkboxes" value="inapp_notify_success_events">

    <h5 class="settings-group">Outgoing Mail</h5>

    <div class="settings-row">
        <div>
            <div class="settings-row-label">SMTP Server</div>
            <p class="settings-row-help">Host and port of the mail server to send through.</p>
        </div>
        <div class="settings-row-control">
            <input type="text" class="form-control" name="smtp_host" style="max-width:250px;"
                   value="<?= htmlspecialchars($settings['smtp_host'] ?? '') ?>" placeholder="smtp.example.com">
            <input type="number" class="form-control form-control-narrow" name="smtp_port"
                   value="<?= htmlspecialchars($settings['smtp_port'] ?? '587') ?>" placeholder="587">
        </div>
        <div class="settings-row-default">Default port: 587</div>
    </div>

    <div class="settings-row">
        <div>
            <div class="settings-row-label">Encryption</div>
            <p class="settings-row-help">Match this to the port — the wrong pairing is the usual reason mail silently fails.</p>
        </div>
        <div class="settings-row-control">
            <select class="form-select" name="smtp_secure" style="max-width:280px;">
                <option value="starttls" <?= $smtpSecure === 'starttls' ? 'selected' : '' ?>>STARTTLS (typically port 587)</option>
                <option value="ssl" <?= $smtpSecure === 'ssl' ? 'selected' : '' ?>>SSL/TLS (typically port 465)</option>
                <option value="none" <?= $smtpSecure === 'none' ? 'selected' : '' ?>>None (plaintext)</option>
            </select>
        </div>
        <div class="settings-row-default">Default: STARTTLS</div>
    </div>

    <div class="settings-row">
        <div>
            <div class="settings-row-label">Credentials</div>
            <p class="settings-row-help">Leave the password blank to keep the stored one. Leave both blank if the server accepts unauthenticated mail from this host.</p>
        </div>
        <div class="settings-row-control">
            <input type="text" class="form-control" name="smtp_user" style="max-width:220px;"
                   value="<?= htmlspecialchars($settings['smtp_user'] ?? '') ?>" placeholder="username">
            <input type="password" class="form-control" name="smtp_pass" style="max-width:220px;"
                   autocomplete="new-password"
                   placeholder="<?= !empty($settings['smtp_pass']) ? '(unchanged if empty)' : 'password' ?>">
        </div>
        <div class="settings-row-default">&nbsp;</div>
    </div>

    <div class="settings-row">
        <div>
            <div class="settings-row-label">From Address</div>
            <p class="settings-row-help">Who the mail appears to come from. Some providers require this to match the account.</p>
        </div>
        <div class="settings-row-control">
            <input type="email" class="form-control" name="smtp_from" style="max-width:280px;"
                   value="<?= htmlspecialchars($settings['smtp_from'] ?? '') ?>" placeholder="backups@example.com">
        </div>
        <div class="settings-row-default">&nbsp;</div>
    </div>

    <h5 class="settings-group">In-App Notifications</h5>

    <div class="settings-row">
        <div>
            <div class="settings-row-label">Show successful backups in the bell</div>
            <p class="settings-row-help">Off by default, so the bell holds failures, offline clients, low storage and other things worth acting on rather than burying them under routine successes. Email and push are unaffected.</p>
        </div>
        <div class="settings-row-control">
            <div class="form-check form-switch mb-0">
                <input class="form-check-input" type="checkbox" role="switch" name="inapp_notify_success_events" value="1"
                       id="inapp_notify_success_events" <?= ($settings['inapp_notify_success_events'] ?? '0') === '1' ? 'checked' : '' ?>>
                <label class="form-check-label" for="inapp_notify_success_events"
                       data-on="On" data-off="Off"><?= ($settings['inapp_notify_success_events'] ?? '0') === '1' ? 'On' : 'Off' ?></label>
            </div>
        </div>
        <div class="settings-row-default">Default: Off</div>
    </div>

    <div class="settings-actions">
        <span id="smtpTestResult" class="small me-auto"></span>
        <button type="button" class="btn btn-sm btn-outline-secondary" id="btnTestSmtp">
            <i class="bi bi-envelope-check me-1"></i> Send Test Email
        </button>
        <a href="/settings?tab=notifications" class="btn btn-sm btn-outline-secondary">Cancel</a>
        <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-save me-1"></i> Save Changes</button>
    </div>
</form>
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

<div class="settings-page-head d-flex justify-content-between align-items-start">
    <div>
        <h1 class="settings-page-title">Apprise Notifications</h1>
        <p class="settings-page-lede mb-0">Send alerts to Discord, Telegram, Slack, Pushover and <a href="https://github.com/caronc/apprise/wiki#notification-services" target="_blank">100+ other services</a> using Apprise. Each service chooses which events it wants.</p>
    </div>
    <button class="btn btn-sm btn-success flex-shrink-0 ms-3" data-bs-toggle="collapse" data-bs-target="#addServiceForm">
        <i class="bi bi-plus-circle me-1"></i> Add Service
    </button>
</div>


<!-- Add Service Form (Collapse) -->
<div class="collapse mb-4" id="addServiceForm">
    <div class="card border-0 shadow-sm">
        <div class="card-header fw-semibold">
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
                    <button type="submit" class="btn btn-sm btn-success">
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
<h5 class="settings-group">Connected Services</h5>
<p class="settings-group-note">Manage your Apprise services and the events they subscribe to.</p>
<div>
    <div class="table-responsive d-none d-md-block">
        <table class="table table-hover align-middle mb-0 settings-table">
            <thead>
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
        <button class="btn btn-sm btn-success" data-bs-toggle="collapse" data-bs-target="#addServiceForm">
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
<h5 class="settings-group">Service URL Examples</h5>
<p class="settings-group-note">Apprise addresses each service with a URL. These are the common ones.</p>
<?php
$appriseExamples = [
    ['Discord',  'discord://webhook_id/webhook_token'],
    ['Telegram', 'tgram://bot_token/chat_id[:thread]'],
    ['Slack',    'slack://tokenA/tokenB/tokenC'],
    ['Pushover', 'pover://user@token'],
    ['ntfy',     'ntfy://topic'],
    ['Gotify',   'gotify://hostname/token'],
    ['Email',    'mailto://user:pass@smtp.example.com'],
    ['Webhook',  'json://your-webhook-url'],
];
$half = (int) ceil(count($appriseExamples) / 2);
?>
<div class="url-example-panel">
    <div class="row g-0">
        <?php foreach ([array_slice($appriseExamples, 0, $half), array_slice($appriseExamples, $half)] as $column): ?>
        <div class="col-md-6">
            <?php foreach ($column as [$label, $url]): ?>
            <div class="url-example">
                <span class="url-example-label"><?= htmlspecialchars($label) ?>:</span>
                <code class="url-example-value"><?= htmlspecialchars($url) ?></code>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<p class="mt-3 mb-4" style="font-size:13px;">
    <a href="https://github.com/caronc/apprise/wiki#notification-services" target="_blank" class="text-decoration-none">
        View all supported services <i class="bi bi-box-arrow-up-right ms-1" style="font-size:.85em;"></i>
    </a>
</p>

<?php endif; ?>


<!-- Push Service Tab -->
<?php if ($activeTab === 'push_service'): ?>
<?php
// Push notification service. Its own tab rather than sharing one with the
// Apprise services: those deliver to chat and webhook targets and contact
// those providers directly, which is a different thing entirely, and putting
// unfinished groundwork next to them invited confusion.
$pushSvc = new \BBS\Services\PushService();
$pushOn = $pushSvc->isEnabled();
$pushRegistered = (bool) $pushSvc->serverId();
?>
<div class="settings-page-head">
    <h1 class="settings-page-title">Push Service</h1>
    <p class="settings-page-lede mb-0">Delivers alerts to registered devices through an external notification service. Separate from <a href="/settings?tab=push">Apprise</a>, which contacts chat and webhook providers directly.</p>
</div>

<div class="alert alert-info small d-flex align-items-start">
    <i class="bi bi-cone-striped me-2 mt-1"></i>
    <div>
        These settings are for a new feature that is in development. Safe to leave off.
    </div>
</div>

<form method="POST" action="/settings/push">
    <input type="hidden" name="csrf_token" value="<?= $this->csrfToken() ?>">

    <h5 class="settings-group">Registration</h5>

    <div class="settings-row">
        <div>
            <div class="settings-row-label">Register with the Push Notification Service</div>
            <p class="settings-row-help">Registers this server with the service so devices can receive alerts. Nothing is sent to it until this is on.</p>
        </div>
        <div class="settings-row-control">
            <div class="form-check form-switch mb-0">
                <input class="form-check-input" type="checkbox" role="switch"
                       id="push_enabled" name="push_enabled" value="1" <?= $pushOn ? 'checked' : '' ?>>
                <label class="form-check-label" for="push_enabled"
                       data-on="On" data-off="Off"><?= $pushOn ? 'On' : 'Off' ?></label>
            </div>
        </div>
        <div class="settings-row-default">Default: Off</div>
    </div>

    <div class="settings-row">
        <div>
            <div class="settings-row-label">Service URL</div>
            <p class="settings-row-help">Where this server registers. Leave it as it is unless you have been told otherwise.</p>
        </div>
        <div class="settings-row-control">
            <input type="text" class="form-control" name="push_relay_url" style="max-width:340px;"
                   value="<?= htmlspecialchars($settings['push_relay_url'] ?? 'https://push.borgbackupserver.com') ?>">
        </div>
        <div class="settings-row-default">&nbsp;</div>
    </div>

    <div class="settings-row">
        <div>
            <div class="settings-row-label">Status</div>
            <p class="settings-row-help">Whether this server currently holds credentials for the service.</p>
        </div>
        <div class="settings-row-control">
            <?php if ($pushOn && $pushRegistered): ?>
                <span class="badge bg-success">Registered</span>
            <?php elseif ($pushOn): ?>
                <span class="badge bg-warning text-dark">Enabled, not registered</span>
                <span class="settings-row-unit">Save to retry registration.</span>
            <?php else: ?>
                <span class="badge bg-secondary">Disabled</span>
            <?php endif; ?>
        </div>
        <div class="settings-row-default">&nbsp;</div>
    </div>

    <div class="settings-actions">
        <a href="/settings?tab=push_service" class="btn btn-sm btn-outline-secondary">Cancel</a>
        <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-save me-1"></i> Save Changes</button>
    </div>
</form>
<?php endif; ?>


<!-- Templates Tab -->
<?php if ($activeTab === 'profiles'): ?>
<?php
$freqLabels = ['hourly' => 'Hourly', 'daily' => 'Daily', 'weekly' => 'Weekly', 'monthly' => 'Monthly'];
$dowLabels = [0 => 'Sunday', 1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday'];
// The form is identical for create and edit, so it is written once and the
// modal is retargeted rather than duplicated — the two drifting apart is how a
// field ends up saving on one screen and not the other.
$renderProfileForm = function (?array $p) use ($templates, $freqLabels, $dowLabels) {
    $v = fn($k, $d = '') => htmlspecialchars((string) ($p[$k] ?? $d));
    ?>
    <div class="row g-3">
        <div class="col-md-6">
            <label class="form-label fw-semibold">Profile Name</label>
            <input type="text" name="name" class="form-control" required value="<?= $v('name') ?>" placeholder="e.g. Laptops, DB Servers, Registers">
        </div>
        <div class="col-md-6">
            <label class="form-label fw-semibold">Description</label>
            <input type="text" name="description" class="form-control" value="<?= $v('description') ?>" placeholder="What kind of machine this is for">
        </div>
    </div>

    <hr class="my-3">
    <div class="row g-3">
        <div class="col-md-6">
            <label class="form-label fw-semibold">Backup Template</label>
            <select name="template_id" class="form-select">
                <option value="">None — author directories per client</option>
                <?php foreach ($templates as $t): ?>
                <option value="<?= (int) $t['id'] ?>" <?= (int) ($p['template_id'] ?? 0) === (int) $t['id'] ? 'selected' : '' ?>><?= htmlspecialchars($t['name']) ?></option>
                <?php endforeach; ?>
            </select>
            <div class="form-text">Supplies the directories, exclude patterns and options a new plan starts with.</div>
        </div>
        <div class="col-md-3">
            <label class="form-label fw-semibold">Frequency</label>
            <select name="frequency" class="form-select profile-frequency">
                <?php foreach ($freqLabels as $fk => $fl): ?>
                <option value="<?= $fk ?>" <?= ($p['frequency'] ?? 'daily') === $fk ? 'selected' : '' ?>><?= $fl ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3">
            <label class="form-label fw-semibold">Run Hours</label>
            <input type="text" name="times" class="form-control" value="<?= $v('times', '02:00') ?>" placeholder="02:00">
            <div class="form-text">24-hour, comma-separated for several runs a day.</div>
        </div>
        <div class="col-md-6">
            <label class="form-label fw-semibold">Timezone</label>
            <select name="timezone" class="form-select">
                <option value="">Server timezone (<?= htmlspecialchars((new \BBS\Services\ClientProfileService())->timezoneFor([])) ?>)</option>
                <?php foreach (\DateTimeZone::listIdentifiers() as $tzId): ?>
                <option value="<?= htmlspecialchars($tzId) ?>" <?= ($p['timezone'] ?? '') === $tzId ? 'selected' : '' ?>><?= htmlspecialchars($tzId) ?></option>
                <?php endforeach; ?>
            </select>
            <div class="form-text">The zone those run hours are in. Applying the profile sets it on every client's schedule, so they all run at the same moment.</div>
        </div>
        <div class="col-md-3 profile-dow" style="display:none;">
            <label class="form-label fw-semibold">Day of Week</label>
            <select name="day_of_week" class="form-select">
                <?php foreach ($dowLabels as $dk => $dl): ?>
                <option value="<?= $dk ?>" <?= (string) ($p['day_of_week'] ?? '0') === (string) $dk ? 'selected' : '' ?>><?= $dl ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3 profile-dom" style="display:none;">
            <label class="form-label fw-semibold">Day of Month</label>
            <input type="text" name="day_of_month" class="form-control" value="<?= $v('day_of_month', '1') ?>" placeholder="1">
        </div>
    </div>

    <hr class="my-3">
    <label class="form-label fw-semibold mb-1">Retention</label>
    <div class="form-text mb-2">How many backups to keep at each interval. 0 keeps none; -1 keeps every one.</div>
    <div class="row g-2">
        <?php foreach (['minutes' => 'Minutely', 'hours' => 'Hourly', 'days' => 'Daily', 'weeks' => 'Weekly', 'months' => 'Monthly', 'years' => 'Yearly'] as $unit => $label): ?>
        <div class="col-4 col-md-2">
            <label class="form-label small mb-1"><?= $label ?></label>
            <input type="number" name="prune_<?= $unit ?>" class="form-control form-control-sm" min="-1"
                   value="<?= $v('prune_' . $unit, in_array($unit, ['days','weeks','months']) ? ['days'=>7,'weeks'=>4,'months'=>6][$unit] : 0) ?>">
        </div>
        <?php endforeach; ?>
    </div>

    <hr class="my-3">
    <label class="form-label fw-semibold mb-1">Failure Handling</label>
    <div class="form-text mb-2">
        Leave any of these blank to follow the server-wide setting. A fleet of laptops that sleep mid-backup
        wants more patience than a database server on a wired LAN, which is the reason these live here at all.
    </div>
    <div class="row g-3">
        <div class="col-md-4">
            <label class="form-label small fw-semibold mb-1">Max Retry Attempts</label>
            <input type="number" name="auto_retry_max_attempts" class="form-control form-control-sm" min="0" max="10"
                   value="<?= $v('auto_retry_max_attempts') ?>" placeholder="Server default">
        </div>
        <div class="col-md-4">
            <label class="form-label small fw-semibold mb-1">Give Up On Running Jobs After</label>
            <div class="input-group input-group-sm">
                <input type="number" name="job_offline_grace_minutes" class="form-control" min="1" max="120"
                       value="<?= $v('job_offline_grace_minutes') ?>" placeholder="Server default">
                <span class="input-group-text">min</span>
            </div>
        </div>
        <div class="col-md-4">
            <label class="form-label small fw-semibold mb-1">Retry Backoff</label>
            <div class="input-group input-group-sm">
                <input type="number" name="auto_retry_backoff_minutes" class="form-control" min="1" max="60"
                       value="<?= $v('auto_retry_backoff_minutes') ?>" placeholder="Server default">
                <span class="input-group-text">min</span>
            </div>
        </div>
    </div>

    <hr class="my-3">
    <label class="form-label fw-semibold mb-1">Backup Freshness</label>
    <div class="form-text mb-2">
        How long a machine of this kind may go without a successful backup before health monitoring
        calls it overdue. A laptop that spends the weekend switched off is not a fault; a database
        server silent for a day is. Blank follows the server-wide setting.
    </div>
    <div class="row g-3">
        <div class="col-md-4">
            <label class="form-label small fw-semibold mb-1">Overdue After</label>
            <div class="input-group input-group-sm">
                <input type="number" name="backup_overdue_hours" class="form-control" min="1" max="8760"
                       value="<?= $v('backup_overdue_hours') ?>" placeholder="Server default">
                <span class="input-group-text">hours</span>
            </div>
            <div class="form-text">24 = a day, 168 = a week, 336 = two weeks.</div>
        </div>
    </div>
    <?php
};
?>

<div class="settings-page-head d-flex justify-content-between align-items-start">
    <div>
        <h1 class="settings-page-title">Client Profiles</h1>
        <p class="settings-page-lede mb-0">A profile describes a kind of machine — laptops, database servers, point-of-sale registers — and the settings a new client of that kind starts with.</p>
    </div>
    <button type="button" class="btn btn-sm btn-success flex-shrink-0 ms-3" onclick="openProfileModal(null)">
        <i class="bi bi-plus-circle me-1"></i> New Profile
    </button>
</div>

<div>
    <div>
        <h5 class="settings-group">Profiles</h5>
        <p class="settings-group-note">
            Assign one when you add a client and its first backup plan is filled in for you.
            <strong>Editing a profile does not touch clients that already exist</strong> — that is what
            <em>Apply to Clients</em> does, and it overwrites their settings.
        </p>

        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 settings-table">
                <thead>
                    <tr>
                        <th>Profile</th>
                        <th>Template</th>
                        <th>Schedule</th>
                        <th>Retention</th>
                        <th class="text-center">Clients</th>
                        <th class="text-end">&nbsp;</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($clientProfiles as $p):
                        $retention = [];
                        foreach (['minutes' => 'm', 'hours' => 'H', 'days' => 'd', 'weeks' => 'w', 'months' => 'M', 'years' => 'y'] as $unit => $abbr) {
                            $n = (int) $p['prune_' . $unit];
                            if ($n !== 0) $retention[] = ($n < 0 ? 'all' : $n) . $abbr;
                        }
                    ?>
                    <tr>
                        <td>
                            <span class="fw-semibold"><?= htmlspecialchars($p['name']) ?></span>
                            <?php if (!empty($p['is_default'])): ?>
                                <span class="badge bg-body-secondary text-muted border ms-1">default</span>
                            <?php endif; ?>
                            <?php if (!empty($p['description'])): ?>
                                <div class="small text-muted"><?= htmlspecialchars($p['description']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="small"><?= $p['template_name'] ? htmlspecialchars($p['template_name']) : '<span class="text-muted">None</span>' ?></td>
                        <td class="small">
                            <?= htmlspecialchars($freqLabels[$p['frequency']] ?? $p['frequency']) ?>
                            <span class="text-muted">at <?= htmlspecialchars($p['times'] ?: '--') ?></span>
                        </td>
                        <td class="small text-muted"><?= $retention ? htmlspecialchars(implode(' ', $retention)) : 'Keep nothing' ?></td>
                        <td class="text-center"><span class="badge bg-body-secondary text-body border"><?= (int) $p['client_count'] ?></span></td>
                        <td class="text-end text-nowrap">
                            <button type="button" class="btn btn-sm btn-outline-secondary"
                                    onclick='openProfileModal(<?= json_encode($p, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>
                                <i class="bi bi-pencil"></i>
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-warning"
                                    onclick="confirmApplyProfile(<?= (int) $p['id'] ?>, <?= htmlspecialchars(json_encode($p['name']), ENT_QUOTES) ?>)"
                                    <?= (int) $p['client_count'] === 0 ? 'disabled title="No clients are in this profile"' : 'title="Overwrite the settings of every client in this profile"' ?>>
                                <i class="bi bi-arrow-down-square me-1"></i>Apply to Clients
                            </button>
                            <?php if (empty($p['is_default'])): ?>
                            <form method="POST" action="/settings/profiles/<?= (int) $p['id'] ?>/delete" class="d-inline"
                                  data-confirm="Delete the profile &quot;<?= htmlspecialchars($p['name']) ?>&quot;? Its clients move to the default profile and keep the settings they have.">
                                <input type="hidden" name="csrf_token" value="<?= $this->csrfToken() ?>">
                                <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                            </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Create / edit -->
<div class="modal fade" id="profileModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <form method="POST" id="profileForm" class="modal-content">
            <input type="hidden" name="csrf_token" value="<?= $this->csrfToken() ?>">
            <div class="modal-header">
                <h5 class="modal-title" id="profileModalTitle">New Client Profile</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <?php $renderProfileForm(null); ?>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary" id="profileSubmit">Create Profile</button>
            </div>
        </form>
    </div>
</div>

<!-- Apply confirmation. Deliberately not a data-confirm one-liner: this
     overwrites work someone may have tuned by hand, and the dialog says what
     it will touch and what it will leave alone. -->
<div class="modal fade" id="applyProfileModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" id="applyProfileForm" class="modal-content">
            <input type="hidden" name="csrf_token" value="<?= $this->csrfToken() ?>">
            <div class="modal-header bg-warning-subtle">
                <h5 class="modal-title"><i class="bi bi-exclamation-triangle me-2"></i>Overwrite existing client settings?</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p>Applying <strong id="applyProfileName"></strong> replaces settings on every client already assigned to it:</p>
                <ul class="small mb-3" id="applyImpact"><li class="text-muted">Checking…</li></ul>
                <p class="small mb-2"><strong>Replaced:</strong> backup directories, exclude patterns, options, retention, and the schedule's frequency and run times.</p>
                <p class="small mb-3"><strong>Left alone:</strong> which repository each plan writes to, which plugins it runs, and any archives already taken.</p>
                <div class="alert alert-warning small mb-0">
                    Anything tuned by hand on those clients is lost. There is no undo.
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-warning">Yes, overwrite these clients</button>
            </div>
        </form>
    </div>
</div>

<script>
function syncProfileFreqFields(form) {
    var freq = form.querySelector('.profile-frequency').value;
    form.querySelector('.profile-dow').style.display = (freq === 'weekly') ? '' : 'none';
    form.querySelector('.profile-dom').style.display = (freq === 'monthly') ? '' : 'none';
}

function openProfileModal(profile) {
    var form = document.getElementById('profileForm');
    form.action = profile ? '/settings/profiles/' + profile.id + '/edit' : '/settings/profiles/add';
    document.getElementById('profileModalTitle').textContent = profile ? 'Edit Profile — ' + profile.name : 'New Client Profile';
    document.getElementById('profileSubmit').textContent = profile ? 'Save Profile' : 'Create Profile';

    // Blank for a new profile, the stored values for an existing one. Nulls
    // stay empty so the "follow the server setting" placeholder shows through.
    var fields = ['name','description','template_id','frequency','times','day_of_week','day_of_month',
                  'timezone','prune_minutes','prune_hours','prune_days','prune_weeks','prune_months','prune_years',
                  'auto_retry_max_attempts','job_offline_grace_minutes','auto_retry_backoff_minutes','backup_overdue_hours'];
    var blanks = {frequency:'daily', times:'02:00', prune_days:'7', prune_weeks:'4', prune_months:'6',
                  prune_minutes:'0', prune_hours:'0', prune_years:'0'};
    fields.forEach(function (f) {
        var el = form.querySelector('[name="' + f + '"]');
        if (!el) return;
        var val = profile ? profile[f] : blanks[f];
        el.value = (val === null || val === undefined) ? '' : val;
    });

    syncProfileFreqFields(form);
    new bootstrap.Modal(document.getElementById('profileModal')).show();
}

function confirmApplyProfile(id, name) {
    var form = document.getElementById('applyProfileForm');
    form.action = '/settings/profiles/' + id + '/apply';
    document.getElementById('applyProfileName').textContent = name;
    var list = document.getElementById('applyImpact');
    list.innerHTML = '<li class="text-muted">Checking…</li>';
    new bootstrap.Modal(document.getElementById('applyProfileModal')).show();

    // Count what is about to be overwritten, so the warning is specific
    // rather than a generic "are you sure".
    fetch('/api/client-profiles/' + id, {credentials: 'same-origin'})
        .then(function (r) { return r.json(); })
        .then(function (d) {
            var i = d.impact || {};
            list.innerHTML =
                '<li><strong>' + (i.clients || 0) + '</strong> client(s)</li>' +
                '<li><strong>' + (i.plans || 0) + '</strong> backup plan(s)</li>' +
                '<li><strong>' + (i.schedules || 0) + '</strong> schedule(s)</li>';
        })
        .catch(function () { list.innerHTML = '<li class="text-muted">Could not count what would change.</li>'; });
}

document.addEventListener('change', function (e) {
    if (e.target.classList && e.target.classList.contains('profile-frequency')) {
        syncProfileFreqFields(e.target.closest('form'));
    }
});
</script>
<?php endif; ?>

<?php if ($activeTab === 'templates'): ?>
<div class="settings-page-head">
    <h1 class="settings-page-title">Backup Templates</h1>
    <p class="settings-page-lede mb-0">A template pre-fills the directories, excludes and borg options of a new backup plan, so a kind of machine is described once rather than typed out per client.</p>
</div>
<div>
    <div>
        <?php if (!empty($templates)): ?>
        <h5 class="settings-group">Templates</h5>
        <p class="settings-group-note">Pick one while creating a plan and the form fills itself in. Client profiles can name one as their default.</p>
        <div class="table-responsive mb-4">
            <table class="table table-hover settings-table">
                <thead>
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

        <h5 class="settings-group">Add Template</h5>
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
                    <button type="submit" class="btn btn-sm btn-success w-100">Add Template</button>
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
<?php $policy = $settings['oidc_new_user_policy'] ?? 'deny'; ?>
<div class="settings-page-head">
    <h1 class="settings-page-title">Authentication</h1>
    <p class="settings-page-lede mb-0">Let people sign in with an external identity provider — Keycloak, Authentik, Entra ID, Google, Okta and anything else that speaks OpenID Connect.</p>
</div>

<form method="POST" action="/settings/oidc">
    <input type="hidden" name="csrf_token" value="<?= $this->csrfToken() ?>">

    <h5 class="settings-group">Single Sign-On</h5>

    <div class="settings-row">
        <div>
            <div class="settings-row-label">Enable OIDC Single Sign-On</div>
            <p class="settings-row-help">Adds a sign-in button to the login page. Local passwords keep working.</p>
        </div>
        <div class="settings-row-control">
            <div class="form-check form-switch mb-0">
                <input class="form-check-input" type="checkbox" role="switch" name="oidc_enabled" value="1"
                       id="oidcEnabled" <?= ($settings['oidc_enabled'] ?? '0') === '1' ? 'checked' : '' ?>>
                <label class="form-check-label" for="oidcEnabled"
                       data-on="On" data-off="Off"><?= ($settings['oidc_enabled'] ?? '0') === '1' ? 'On' : 'Off' ?></label>
            </div>
        </div>
        <div class="settings-row-default">Default: Off</div>
    </div>

    <div class="settings-row">
        <div>
            <div class="settings-row-label">Provider URL</div>
            <p class="settings-row-help">The base URL for discovery. BBS appends <code>/.well-known/openid-configuration</code> itself.</p>
        </div>
        <div class="settings-row-control">
            <input type="url" class="form-control" name="oidc_provider_url" style="max-width:380px;"
                   value="<?= htmlspecialchars($settings['oidc_provider_url'] ?? '') ?>"
                   placeholder="https://idp.example.com/realms/myrealm">
        </div>
        <div class="settings-row-default">&nbsp;</div>
    </div>

    <div class="settings-row">
        <div>
            <div class="settings-row-label">Client Credentials</div>
            <p class="settings-row-help">From the application you registered with the provider. Leave the secret blank to keep the stored one.</p>
        </div>
        <div class="settings-row-control">
            <input type="text" class="form-control" name="oidc_client_id" style="max-width:180px;"
                   value="<?= htmlspecialchars($settings['oidc_client_id'] ?? '') ?>" placeholder="client id">
            <input type="password" class="form-control" name="oidc_client_secret" style="max-width:200px;"
                   placeholder="<?= !empty($settings['oidc_client_secret']) ? '(unchanged if empty)' : 'client secret' ?>">
        </div>
        <div class="settings-row-default"><?= !empty($settings['oidc_client_secret']) ? 'A secret is saved' : '&nbsp;' ?></div>
    </div>

    <div class="settings-row">
        <div>
            <div class="settings-row-label">Button Label</div>
            <p class="settings-row-help">What the sign-in button says on the login page.</p>
        </div>
        <div class="settings-row-control">
            <input type="text" class="form-control" name="oidc_button_label" style="max-width:260px;"
                   value="<?= htmlspecialchars($settings['oidc_button_label'] ?? 'Login with SSO') ?>">
        </div>
        <div class="settings-row-default">Default: Login with SSO</div>
    </div>

    <div class="settings-row">
        <div>
            <div class="settings-row-label">Scopes</div>
            <p class="settings-row-help">Space-separated. Must include <code>openid</code> and <code>email</code>.</p>
        </div>
        <div class="settings-row-control">
            <input type="text" class="form-control" name="oidc_scopes" style="max-width:280px;"
                   value="<?= htmlspecialchars($settings['oidc_scopes'] ?? 'openid email profile') ?>">
        </div>
        <div class="settings-row-default">Default: openid email profile</div>
    </div>

    <div class="settings-row">
        <div>
            <div class="settings-row-label">Redirect URL Override</div>
            <p class="settings-row-help">Blank auto-detects from the request. Set it when BBS is behind a proxy and the provider needs a different URL than the request headers show — clients on an internal address, SSO on the public hostname.</p>
        </div>
        <div class="settings-row-control">
            <input type="url" class="form-control" name="oidc_redirect_url" style="max-width:380px;"
                   value="<?= htmlspecialchars($settings['oidc_redirect_url'] ?? '') ?>"
                   placeholder="https://bbs.example.com/login/oidc/callback">
        </div>
        <div class="settings-row-default">Optional</div>
    </div>

    <div class="settings-row">
        <div>
            <div class="settings-row-label">Log out of the provider too</div>
            <p class="settings-row-help">Signing out of BBS also ends the session at the identity provider.</p>
        </div>
        <div class="settings-row-control">
            <div class="form-check form-switch mb-0">
                <input class="form-check-input" type="checkbox" role="switch" name="oidc_logout_enabled" value="1"
                       id="oidcLogout" <?= ($settings['oidc_logout_enabled'] ?? '0') === '1' ? 'checked' : '' ?>>
                <label class="form-check-label" for="oidcLogout"
                       data-on="On" data-off="Off"><?= ($settings['oidc_logout_enabled'] ?? '0') === '1' ? 'On' : 'Off' ?></label>
            </div>
        </div>
        <div class="settings-row-default">Default: Off</div>
    </div>

    <h5 class="settings-group">New Users</h5>
    <p class="settings-group-note">What happens the first time someone unknown authenticates through SSO.</p>

    <div class="settings-row">
        <div>
            <div class="settings-row-label">First Sign-In Policy</div>
            <p class="settings-row-help">Denying is the safe default — it means SSO can authenticate people you have already created, and cannot create anyone.</p>
        </div>
        <div class="settings-row-control">
            <select class="form-select" name="oidc_new_user_policy" id="oidcNewUserPolicy" style="max-width:380px;">
                <option value="deny" <?= $policy === 'deny' ? 'selected' : '' ?>>Deny — the user must already exist</option>
                <option value="pending" <?= $policy === 'pending' ? 'selected' : '' ?>>Create, pending admin approval</option>
                <option value="copy" <?= $policy === 'copy' ? 'selected' : '' ?>>Create, copying a template user's access</option>
            </select>
        </div>
        <div class="settings-row-default">Default: Deny</div>
    </div>

    <div class="settings-row" id="oidcTemplateUserWrap" style="<?= $policy === 'copy' ? '' : 'display:none;' ?>">
        <div>
            <div class="settings-row-label">Template User</div>
            <p class="settings-row-help">New SSO users get the same client access and permissions as this one.</p>
        </div>
        <div class="settings-row-control">
            <select class="form-select" name="oidc_template_user_id" style="max-width:380px;">
                <option value="">-- Select a user --</option>
                <?php foreach ($oidcUsers ?? [] as $u): ?>
                <option value="<?= $u['id'] ?>" <?= ($settings['oidc_template_user_id'] ?? '') == $u['id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($u['username']) ?> (<?= htmlspecialchars($u['email']) ?>) — <?= $u['role'] ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="settings-row-default">&nbsp;</div>
    </div>

    <div class="settings-actions">
        <a href="/settings?tab=auth" class="btn btn-sm btn-outline-secondary">Cancel</a>
        <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-save me-1"></i> Save Changes</button>
    </div>
</form>
<script>
document.getElementById('oidcNewUserPolicy').addEventListener('change', function () {
    // grid, not block — restoring display:block would collapse the three columns
    document.getElementById('oidcTemplateUserWrap').style.display = this.value === 'copy' ? 'grid' : 'none';
});
</script>
<?php endif; ?>

<!-- Branding Tab -->
<?php if ($activeTab === 'branding'): ?>
<div class="settings-page-head">
    <h1 class="settings-page-title">Branding</h1>
    <p class="settings-page-lede mb-0">Replace the logos with your own. Images are stored in the database, so they survive updates.</p>
</div>
<div>
    <div>

        <form method="POST" action="/settings/branding" enctype="multipart/form-data" id="brandingForm">
            <input type="hidden" name="csrf_token" value="<?= $this->csrfToken() ?>">
            <input type="hidden" name="branding_icon_data" id="brandingIconData">
            <input type="hidden" name="branding_login_logo_data" id="brandingLoginLogoData">
            <input type="hidden" name="branding_app_icon_data" id="brandingAppIconData">

            <!-- Navbar Icon -->
            <div class="row mb-4">
                <div class="col-md-7">
                    <h6><i class="bi bi-image me-1"></i> Navbar Icon</h6>
                    <p class="text-muted small">
                        Transparent PNG shown in the top-left corner of every page, in a
                        <strong>192&times;64 pixel</strong> slot above the menu. Wide landscape artwork suits it
                        best &mdash; around <strong>3:1</strong> fills the slot edge to edge, and anything taller
                        than that is scaled to the 64px height with space left either side. Also reused as the
                        small header logo on the <strong>mobile login screen</strong>, since the wider Login Page
                        Logo would crowd a phone-width pane. Uploads are resized to fit within 360&times;200
                        pixels.
                    </p>
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

            <hr>

            <!-- App Icon / Favicon — single source for browser tab, Apple
                 home-screen, and PWA. Resized on the fly by BrandingController. -->
            <div class="row mb-4">
                <div class="col-md-7">
                    <h6><i class="bi bi-app-indicator me-1"></i> App Icon / Favorite Icon</h6>
                    <p class="text-muted small">
                        Square <strong>transparent PNG</strong> used as the app icon everywhere a browser or
                        OS asks for one — browser tab favicon, Apple home-screen icon, Android / PWA install
                        tile. Upload one high-resolution image; BBS resizes it on demand to every required
                        size (16, 32, 48, 96, 180, 192, 512). <strong>Recommended: 512×512 transparent PNG.</strong>
                        Anything smaller will look soft on retina screens.
                    </p>
                    <input type="file" class="form-control form-control-sm" id="appIconFileInput" accept="image/png">
                    <div class="small text-muted mt-1" id="appIconDimensions"></div>
                    <?php if (!empty($settings['branding_app_icon'])): ?>
                    <div class="form-check mt-2">
                        <input class="form-check-input" type="checkbox" name="remove_branding_app_icon" value="1" id="removeAppIcon">
                        <label class="form-check-label small" for="removeAppIcon">Remove custom app icon (revert to default)</label>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="col-md-5 text-center">
                    <label class="form-label small text-muted">Preview</label>
                    <div class="p-3 rounded bg-dark d-flex align-items-center justify-content-center" style="min-height: 200px;">
                        <img id="appIconPreview"
                             src="<?= !empty($settings['branding_app_icon']) ? 'data:image/png;base64,' . $settings['branding_app_icon'] : '/branding/icon/192' ?>"
                             alt="App icon preview"
                             style="max-width: 160px; max-height: 160px; width: auto; height: auto;">
                    </div>
                    <?php if (empty($settings['branding_app_icon'])): ?>
                    <div class="text-muted small mt-1" id="appIconDefaultLabel">Default — Borg Backup Server mascot</div>
                    <?php endif; ?>
                </div>
            </div>

            <hr>

            <div class="mb-4">
                <h6><i class="bi bi-moon-stars me-1"></i> Login Page Theme</h6>
                <p class="text-muted small">
                    Override the login page theme independently. Useful when your branding only reads well on
                    a specific background. The default ("Use Default Theme") follows the system theme.
                </p>
                <select class="form-select" name="branding_login_theme" style="max-width: 300px;">
                    <?php $loginTheme = $settings['branding_login_theme'] ?? 'default'; ?>
                    <option value="default" <?= $loginTheme === 'default' ? 'selected' : '' ?>>Use Default Theme</option>
                    <option value="dark" <?= $loginTheme === 'dark' ? 'selected' : '' ?>>Always Dark</option>
                    <option value="light" <?= $loginTheme === 'light' ? 'selected' : '' ?>>Always Light</option>
                </select>
            </div>

            <button type="submit" class="btn btn-sm btn-primary">
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

document.getElementById('appIconFileInput').addEventListener('change', function() {
    if (!this.files[0]) return;
    // 512×512 cap matches our biggest derived size (PWA icon-512). Anything
    // larger than that just bloats the DB row without improving rendering.
    resizeImage(this.files[0], 512, 512, function(dataUrl, w, h) {
        var preview = document.getElementById('appIconPreview');
        preview.src = dataUrl;
        document.getElementById('brandingAppIconData').value = dataUrl.split(',')[1];
        document.getElementById('appIconDimensions').textContent = 'Resized to ' + w + 'x' + h + 'px';
        var lbl = document.getElementById('appIconDefaultLabel');
        if (lbl) lbl.style.display = 'none';
    });
});
</script>
<?php endif; ?>

<!-- API Tab -->
<?php if ($activeTab === 'ssl'): ?>
<?php $c = $certificate ?? null; ?>
<div class="settings-page-head">
    <h1 class="settings-page-title">SSL Certificate</h1>
    <p class="settings-page-lede mb-0">The certificate this server presents to browsers and agents. Renewal is automatic; this page is for checking it worked and stepping in when it didn't.</p>
</div>
<div>
    <h5 class="settings-group">Status</h5>

    <?php if (!$c || empty($c['installed'])): ?>
    <div class="alert alert-secondary d-flex align-items-start">
        <i class="bi bi-info-circle me-2 fs-5"></i>
        <div>
            <strong>No certificate found on this server.</strong>
            <div class="small mt-1">
                That is expected if TLS is terminated somewhere else — a reverse proxy, a load balancer, or a Docker setup that handles certificates outside the container. If this server is meant to hold its own certificate, run
                <code>certbot --apache -d <?= htmlspecialchars($settings['server_host'] ?? 'your.hostname') ?></code> and reload this page.
            </div>
        </div>
    </div>
    <?php else: ?>

    <?php
        $days = $c['days_remaining'];
        if (!empty($c['expired']))          { $band = 'danger';  $note = 'This certificate has expired.'; }
        elseif (!empty($c['expiring_soon'])) { $band = 'warning'; $note = 'Renewal has not happened yet — check the schedule below.'; }
        else                                 { $band = 'success'; $note = 'Renewal runs automatically before this date.'; }
    ?>
    <div class="alert alert-<?= $band ?> d-flex align-items-start">
        <i class="bi bi-<?= $band === 'success' ? 'shield-check' : 'exclamation-triangle' ?> me-2 fs-5"></i>
        <div>
            <strong>
                <?php if ($days === null): ?>Certificate installed
                <?php elseif ($days < 0): ?>Expired <?= abs($days) ?> day<?= abs($days) === 1 ? '' : 's' ?> ago
                <?php else: ?><?= $days ?> day<?= $days === 1 ? '' : 's' ?> remaining
                <?php endif; ?>
            </strong>
            <div class="small mt-1"><?= htmlspecialchars($note) ?></div>
        </div>
    </div>

    <div class="settings-row">
        <div>
            <div class="settings-row-label">Covers</div>
            <p class="settings-row-help">The names this certificate is valid for. A browser reaching the server by any other name will warn.</p>
        </div>
        <div class="settings-row-control">
            <span class="small"><?= $c['domains'] ? htmlspecialchars(implode(', ', $c['domains'])) : '—' ?></span>
        </div>
        <div class="settings-row-default"></div>
    </div>

    <div class="settings-row">
        <div>
            <div class="settings-row-label">Issued by</div>
            <p class="settings-row-help"><?= !empty($c['self_signed']) ? 'Self-signed. Browsers will warn, and this cannot be renewed automatically.' : 'The certificate authority that signed it.' ?></p>
        </div>
        <div class="settings-row-control">
            <span class="small"><?= htmlspecialchars($c['issuer'] ?? '—') ?></span>
        </div>
        <div class="settings-row-default"></div>
    </div>

    <div class="settings-row">
        <div>
            <div class="settings-row-label">Expires</div>
            <p class="settings-row-help">Let's Encrypt certificates last 90 days and are renewed at 30 days remaining.</p>
        </div>
        <div class="settings-row-control">
            <span class="small"><?= $c['expires_at'] ? \BBS\Core\TimeHelper::format($c['expires_at'], 'M j, Y g:ia') : '—' ?></span>
        </div>
        <div class="settings-row-default"></div>
    </div>

    <div class="settings-row">
        <div>
            <div class="settings-row-label">Automatic renewal</div>
            <p class="settings-row-help">Certbot's own timer. If this says Not scheduled, nothing will renew the certificate and it will expire.</p>
        </div>
        <div class="settings-row-control">
            <?php $timer = $c['auto_renewal'] ?? 'unknown'; ?>
            <?php if ($timer === 'active' || $timer === 'cron'): ?>
                <span class="badge bg-success-subtle text-success-emphasis">Scheduled<?= $timer === 'cron' ? ' (cron)' : '' ?></span>
            <?php elseif ($timer === 'none'): ?>
                <span class="badge bg-danger-subtle text-danger-emphasis">Not scheduled</span>
            <?php else: ?>
                <span class="badge bg-secondary-subtle text-secondary-emphasis">Unknown</span>
            <?php endif; ?>
        </div>
        <div class="settings-row-default"></div>
    </div>
    <?php endif; ?>

    <h5 class="settings-group mt-4">Expiry warnings</h5>
    <form method="POST" action="/settings/ssl/email">
        <input type="hidden" name="csrf_token" value="<?= $this->csrfToken() ?>">
        <div class="settings-row">
            <div>
                <div class="settings-row-label">Contact address</div>
                <p class="settings-row-help">The contact address on the Let's Encrypt account, used for account and technical notices. Let's Encrypt stopped sending certificate expiry warnings in June 2025, so this address will not warn you about an expiring certificate — BBS does that itself. Leave blank to set none.</p>
            </div>
            <div class="settings-row-control">
                <input type="email" class="form-control" name="ssl_email" style="max-width:260px;"
                       placeholder="you@example.com"
                       value="<?= htmlspecialchars($settings['ssl_contact_email'] ?? '') ?>">
            </div>
            <div class="settings-row-default">
                <button type="submit" class="btn btn-sm btn-primary">Save</button>
            </div>
        </div>
    </form>

    <?php if ($c && !empty($c['installed']) && empty($c['self_signed'])): ?>
    <h5 class="settings-group mt-4">Renew now</h5>
    <div class="settings-row">
        <div>
            <div class="settings-row-label">Renew the certificate</div>
            <p class="settings-row-help">Certbot declines while more than 30 days remain and will say so. Use Force only after fixing something that broke renewal — Let's Encrypt limits how many certificates a domain may be issued per week.</p>
        </div>
        <div class="settings-row-control d-flex gap-2">
            <button type="button" class="btn btn-sm btn-primary" id="sslRenewBtn">Renew</button>
            <button type="button" class="btn btn-sm btn-outline-secondary" id="sslForceBtn">Force renew</button>
        </div>
        <div class="settings-row-default"></div>
    </div>
    <pre id="sslRenewOut" class="small bg-body-tertiary border rounded p-2 mt-2 d-none" style="max-height:320px;overflow:auto;white-space:pre-wrap;"></pre>
    <?php endif; ?>
</div>

<script>
(function () {
    const out = document.getElementById('sslRenewOut');
    if (!out) return;
    const csrf = '<?= $this->csrfToken() ?>';

    async function renew(force, btn) {
        const label = btn.textContent;
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Working…';
        out.classList.remove('d-none', 'text-danger');
        out.textContent = 'Asking certbot…';
        try {
            const body = new URLSearchParams({ csrf_token: csrf });
            if (force) body.set('force', '1');
            const r = await fetch('/settings/ssl/renew', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': csrf },
                credentials: 'same-origin',
                body,
            });
            const d = await r.json();
            out.textContent = d.output || '(no output)';
            if (d.status !== 'ok') out.classList.add('text-danger');
        } catch (e) {
            out.textContent = 'Request failed: ' + e.message;
            out.classList.add('text-danger');
        } finally {
            btn.disabled = false;
            btn.textContent = label;
        }
    }

    document.getElementById('sslRenewBtn').addEventListener('click', function () { renew(false, this); });
    document.getElementById('sslForceBtn').addEventListener('click', function () {
        if (!confirm('Force a new certificate even though the current one is still valid?\n\nLet\'s Encrypt limits certificates per domain per week.')) return;
        renew(true, this);
    });
})();
</script>
<?php endif; ?>

<?php if ($activeTab === 'api'): ?>
<div class="settings-page-head">
    <h1 class="settings-page-title">API</h1>
    <p class="settings-page-lede mb-0">Tokens for automated access to the provisioning API. A token carries full admin access, so treat one like a password.</p>
</div>
<div>
    <div>
        <h5 class="settings-group">Tokens</h5>

        <?php if (!empty($_SESSION['new_api_token'])): ?>
        <div class="alert alert-success d-flex align-items-center mb-3">
            <i class="bi bi-shield-check me-2 fs-5"></i>
            <div class="flex-grow-1">
                <strong>New API Token</strong> — copy it now, it will not be shown again:
                <div class="mt-1">
                    <code id="newTokenValue" class="user-select-all fs-6"><?= htmlspecialchars($_SESSION['new_api_token']) ?></code>
                    <button type="button" class="btn btn-sm btn-outline-success ms-2" onclick="BBS.copyText(document.getElementById('newTokenValue').textContent).then(() => { this.innerHTML = '<i class=\'bi bi-check\'></i> Copied'; })">
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
                <thead>
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
                        <td class="fw-semibold">
                            <?php if (($token['kind'] ?? 'user') === 'mobile'): ?>
                            <i class="bi bi-phone me-1 text-muted"></i><?= htmlspecialchars($token['device_name'] ?: $token['name']) ?>
                            <span class="badge bg-info-subtle text-info-emphasis ms-2" title="Signed in from a mobile session<?= !empty($token['last_seen_ip']) ? ' — last seen from ' . htmlspecialchars($token['last_seen_ip']) : '' ?>">mobile</span>
                            <?php else: ?>
                            <i class="bi bi-key me-1 text-muted"></i><?= htmlspecialchars($token['name']) ?>
                            <?php endif; ?>
                            <?php if (!empty($token['can_read_secrets'])): ?>
                            <span class="badge bg-warning text-dark ms-2" title="This token can read repository passphrases and S3 credentials"><i class="bi bi-eye me-1"></i>secrets</span>
                            <?php endif; ?>
                        </td>
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
                <div class="col-md-6">
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="checkbox" name="can_read_secrets" value="1" id="canReadSecrets">
                        <label class="form-check-label fw-semibold" for="canReadSecrets">Display Secrets</label>
                    </div>
                    <div class="form-text small">Allows this token to return repository passphrases and S3 credentials in API responses (e.g. <code>?include_secrets=1</code> on <code>GET&nbsp;/api/v1/repositories</code>). Leave unchecked unless you specifically need an escrow / disaster-recovery export.</div>
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-sm btn-success w-100"><i class="bi bi-plus-circle me-1"></i>Create Token</button>
                </div>
            </div>
        </form>
    </div>
</div>

<div class="card border-0 shadow-sm mt-4">
    <div class="card-header fw-semibold">
        <i class="bi bi-book me-1"></i> API Reference
    </div>
    <div class="card-body">
        <p class="text-muted small mb-3">Use your API token with the <code>Authorization: Bearer &lt;token&gt;</code> header. All endpoints accept and return JSON.</p>

        <table class="table table-sm small mb-0">
            <thead>
                <tr><th>Method</th><th>Endpoint</th><th>Description</th></tr>
            </thead>
            <tbody>
                <tr><td><span class="badge bg-success">GET</span></td><td><code>/api/v1/summary</code></td><td>Summary of each client's backup plans and latest backup result</td></tr>
                <tr><td><span class="badge bg-success">GET</span></td><td><code>/api/v1/metrics</code></td><td>Monitoring snapshot: client/queue counts, per-plan last run &amp; last success, job totals, repo sizes</td></tr>
                <tr><td><span class="badge bg-success">GET</span></td><td><code>/api/v1/clients</code></td><td>List all clients</td></tr>
                <tr><td><span class="badge bg-primary">POST</span></td><td><code>/api/v1/clients</code></td><td>Create a client (returns api_key for agent install)</td></tr>
                <tr><td><span class="badge bg-success">GET</span></td><td><code>/api/v1/clients/{id}</code></td><td>Get client details with repos &amp; plans</td></tr>
                <tr><td><span class="badge bg-warning text-dark">PUT</span></td><td><code>/api/v1/clients/{id}</code></td><td>Rename a client: <code>{"name": "new-name"}</code></td></tr>
                <tr><td><span class="badge bg-danger">DELETE</span></td><td><code>/api/v1/clients/{id}</code></td><td>Delete a client</td></tr>
                <tr><td><span class="badge bg-success">GET</span></td><td><code>/api/v1/clients/{id}/repositories</code></td><td>List repositories</td></tr>
                <tr><td><span class="badge bg-primary">POST</span></td><td><code>/api/v1/clients/{id}/repositories</code></td><td>Create a repository</td></tr>
                <tr><td><span class="badge bg-success">GET</span></td><td><code>/api/v1/clients/{id}/plans</code></td><td>List backup plans</td></tr>
                <tr><td><span class="badge bg-primary">POST</span></td><td><code>/api/v1/clients/{id}/plans</code></td><td>Create a backup plan (with optional plugin configs)</td></tr>
                <tr><td><span class="badge bg-warning text-dark">PUT</span></td><td><code>/api/v1/clients/{id}/repositories/{repo_id}</code></td><td>Rename a repository</td></tr>
                <tr><td><span class="badge bg-danger">DELETE</span></td><td><code>/api/v1/clients/{id}/repositories/{repo_id}</code></td><td>Delete a repository (<code>?keep_data=1</code> unlinks only, keeping borg data on disk)</td></tr>
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
                <tr><td><span class="badge bg-primary">POST</span></td><td><code>/api/v1/storage</code></td><td>Register a new local storage location: <code>{"label": "...", "path": "/abs/path", "is_default": false}</code></td></tr>
                <tr><td><span class="badge bg-success">GET</span></td><td><code>/api/v1/storage/capacity</code></td><td>Provisioned / used / free bytes for the default storage location</td></tr>
                <tr><td colspan="3" class="text-muted small fw-semibold pt-2">Repositories (cross-client)</td></tr>
                <tr><td><span class="badge bg-success">GET</span></td><td><code>/api/v1/repositories</code></td><td>List every repository across every client. Add <code>?include_secrets=1</code> to also return the decrypted passphrase (requires Display Secrets token).</td></tr>
                <tr><td><span class="badge bg-warning text-dark">PUT</span></td><td><code>/api/v1/repositories/{repo_id}/s3-sync</code></td><td>Toggle per-repository S3 off-site sync: <code>{"enabled": true|false}</code></td></tr>
                <tr><td colspan="3" class="text-muted small fw-semibold pt-2">S3 Off-site Sync</td></tr>
                <tr><td><span class="badge bg-success">GET</span></td><td><code>/api/v1/s3-credentials</code></td><td>Read the global S3 configuration (endpoint / region / bucket / path_prefix / configured). Add <code>?include_secrets=1</code> to also return <code>access_key</code> + <code>secret_key</code> (requires Display Secrets token).</td></tr>
                <tr><td><span class="badge bg-primary">POST</span></td><td><code>/api/v1/s3-credentials</code></td><td>Set the global S3 configuration: <code>{"endpoint": "...", "region": "...", "bucket": "...", "access_key": "...", "secret_key": "...", "path_prefix": ""}</code></td></tr>
                <tr><td><span class="badge bg-danger">DELETE</span></td><td><code>/api/v1/s3-credentials</code></td><td>Clear the global S3 configuration and disable per-repo S3 sync on every repository</td></tr>
                <tr><td colspan="3" class="text-muted small fw-semibold pt-2">Users</td></tr>
                <tr><td><span class="badge bg-success">GET</span></td><td><code>/api/v1/users</code></td><td>List all BBS users</td></tr>
                <tr><td><span class="badge bg-primary">POST</span></td><td><code>/api/v1/users</code></td><td>Create a user: <code>{"username", "email", "password", "role": "admin|user"}</code></td></tr>
                <tr><td><span class="badge bg-success">GET</span></td><td><code>/api/v1/users/{id}</code></td><td>Get one user</td></tr>
                <tr><td><span class="badge bg-warning text-dark">PUT</span></td><td><code>/api/v1/users/{id}</code></td><td>Update email / password / role / all_clients / timezone / time_format. Pass <code>reset_totp:true</code> to clear 2FA.</td></tr>
                <tr><td><span class="badge bg-danger">DELETE</span></td><td><code>/api/v1/users/{id}</code></td><td>Delete a user (refuses to delete the last admin or the token's own user)</td></tr>
                <tr><td colspan="3" class="text-muted small fw-semibold pt-2">Server Log</td></tr>
                <tr><td><span class="badge bg-success">GET</span></td><td><code>/api/v1/log</code></td><td>Read server_log entries. Filters: <code>?level=info|warning|error</code>, <code>?agent_id=N</code>, <code>?since=YYYY-MM-DD HH:MM:SS</code>, <code>?limit=N&amp;offset=N</code> (limit max 500, default 100).</td></tr>
                <tr><td colspan="3" class="text-muted small fw-semibold pt-2">Schedules</td></tr>
                <tr><td><span class="badge bg-success">GET</span></td><td><code>/api/v1/schedules</code></td><td>Flat list of every plan with its schedule, next_run, last_run, last_status, and last_completed_at across all clients</td></tr>
                <tr><td colspan="3" class="text-muted small fw-semibold pt-2">Maintenance</td></tr>
                <tr><td><span class="badge bg-success">GET</span></td><td><code>/api/v1/maintenance</code></td><td>Read the maintenance-mode flag</td></tr>
                <tr><td><span class="badge bg-primary">POST</span></td><td><code>/api/v1/maintenance</code></td><td>Toggle maintenance mode: <code>{"enabled": true|false}</code> (pauses agent dispatch)</td></tr>
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
            <p class="small text-muted mb-1"><strong>Example: Backup summary</strong></p>
            <pre class="bg-body-secondary p-2 rounded small mb-0"><code>curl https://your-server/api/v1/summary \
  -H "Authorization: Bearer bbs_tok_..."</code></pre>
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
<div class="settings-page-head">
    <h1 class="settings-page-title">Updates</h1>
    <p class="settings-page-lede mb-0">The version of BBS on this server, and the borg build its clients run.</p>
</div>


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
            <div class="card-header fw-semibold">
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
            <div class="card-header fw-semibold d-flex justify-content-between align-items-center">
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
                        <thead>
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
            <div class="card-header fw-semibold">
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
<div class="row g-4" id="updates-row">
    <div class="col-lg-6" id="updates-left-col">
        <div class="card border-0 shadow-sm">
            <div class="card-header fw-semibold">
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
            <div class="card-header fw-semibold d-flex justify-content-between align-items-center">
                <span><i class="bi bi-pc-display me-1"></i> Borg Backup Server Agents</span>
                <span class="badge bg-success" id="agent-bundled-ver">v<?= htmlspecialchars($bundledAgentVersion) ?></span>
            </div>
            <div class="card-body" id="agent-updates-body">
                <p class="text-muted small mb-3">The BBS Agents receive commands from this server to start backups, run restores, and update their own software and Borg.</p>
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
<?php if (!$isDocker): ?>
        <div class="card border-0 shadow-sm mt-4">
            <div class="card-header fw-semibold">
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
<?php endif; ?>
    </div>

    <?php if (!empty($latest['notes'])): ?>
    <div class="col-lg-6" id="updates-notes-col">
        <div class="card border-0 shadow-sm h-100 d-flex flex-column">
            <div class="card-header fw-semibold flex-shrink-0">
                <i class="bi bi-journal-text me-1"></i> Release Notes
                <?php if (!empty($latest['url'])): ?>
                    <a href="<?= htmlspecialchars($latest['url']) ?>" target="_blank" class="float-end small text-decoration-none">
                        View on GitHub <i class="bi bi-box-arrow-up-right"></i>
                    </a>
                <?php endif; ?>
            </div>
            <div class="card-body small release-notes-md" id="release-notes-body">
                <?php echo (new \BBS\Services\ReleaseNotes())->render((string) $latest['notes']); ?>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<script>
(function () {
    const left  = document.getElementById('updates-left-col');
    const notes = document.getElementById('release-notes-body');
    if (!left || !notes) return;

    // Release notes grow with however much was written; the column beside them
    // does not. Left alone the notes set the height of the row and the page
    // runs on past everything else, so cap them at the height of that column
    // and scroll the rest.
    function fit() {
        // Single column on small screens — a cap there would be a scrollbar
        // inside a scrollbar for no gain.
        if (window.innerWidth < 992) {
            notes.style.maxHeight = '';
            notes.style.overflowY = '';
            return;
        }
        const card = notes.closest('.card');
        const header = card ? card.querySelector('.card-header') : null;
        const headerH = header ? header.offsetHeight : 0;

        // Two limits, and the smaller wins. Matching the column beside it was
        // the request, but that column is often taller than the window, so on
        // its own it left the page scrolling past everything.
        const besideCol = left.offsetHeight - headerH;

        // Measured from the notes element rather than its card. The card's
        // position was read before the layout settled, so this came out too
        // generous and the column limit won — the panel then hung below the
        // fold, which is the thing it exists to prevent.
        notes.style.maxHeight = '';
        const inWindow = window.innerHeight - notes.getBoundingClientRect().top - 24;

        notes.style.maxHeight = Math.max(Math.min(besideCol, inWindow), 240) + 'px';
        notes.style.overflowY = 'auto';
    }

    fit();
    // Again once fonts and images have settled — the first run measures a
    // layout that is still moving.
    window.addEventListener('load', fit);
    window.addEventListener('resize', fit);
    // The left column carries badges and counts that arrive after the update
    // check returns, and its height changes when they do.
    if (window.ResizeObserver) new ResizeObserver(fit).observe(left);
})();
</script>

<?php if ($upgradeResult): ?>
<div class="card border-0 shadow-sm mt-4">
    <div class="card-header fw-semibold">
        <i class="bi bi-terminal me-1"></i> Upgrade Log
    </div>
    <div class="card-body">
        <pre class="mb-0 bg-dark text-light p-3 rounded small" style="max-height: 400px; overflow-y: auto;"><?= htmlspecialchars(implode("\n", $upgradeResult['log'])) ?></pre>
    </div>
</div>
<?php endif; ?>
<?php endif; ?>

<?php endif; ?>

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
                result.textContent = data.message || 'Success — test email sent';
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
                var descHtml = '<p class="text-muted small mb-3">The BBS Agents receive commands from this server to start backups, run restores, and update their own software and Borg.</p>';
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

<script>
// Every settings switch says what it is, not just which way it points. Lives
// outside the per-tab blocks because three pages use it now.
document.querySelectorAll('.settings-row .form-switch input[type=checkbox]').forEach(function (box) {
    box.addEventListener('change', function () {
        var label = document.querySelector('label[for="' + box.id + '"]');
        if (label && label.dataset.on) {
            label.textContent = box.checked ? label.dataset.on : label.dataset.off;
        }
    });
});
</script>
