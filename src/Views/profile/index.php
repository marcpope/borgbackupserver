<h5 class="mb-4">My Profile</h5>

<!-- Tab Navigation -->
<ul class="nav nav-pills client-tabs mb-0 flex-wrap">
    <li class="nav-item">
        <a class="nav-link <?= $tab === 'account' ? 'active' : '' ?>" href="/profile?tab=account">
            <i class="bi bi-person me-1"></i><span class="tab-label">Account</span>
        </a>
    </li>
    <?php if (($user['auth_provider'] ?? 'local') === 'local'): ?>
    <li class="nav-item">
        <a class="nav-link <?= $tab === 'password' ? 'active' : '' ?>" href="/profile?tab=password">
            <i class="bi bi-key me-1"></i><span class="tab-label">Password</span>
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $tab === '2fa' ? 'active' : '' ?>" href="/profile?tab=2fa">
            <i class="bi bi-shield-check me-1"></i><span class="tab-label">Two-Factor Auth</span>
            <?php if ($twoFactorEnabled): ?>
                <span class="badge bg-success ms-1">On</span>
            <?php endif; ?>
        </a>
    </li>
    <?php endif; ?>
    <li class="nav-item">
        <a class="nav-link <?= $tab === 'reports' ? 'active' : '' ?>" href="/profile?tab=reports">
            <i class="bi bi-file-earmark-bar-graph me-1"></i><span class="tab-label">Reports</span>
        </a>
    </li>
</ul>
<div class="client-tab-content border rounded-bottom p-4 mb-4 shadow-sm">

<?php if ($tab === 'account'): ?>
<!-- Account Tab -->
<div class="row justify-content-center">
    <div class="col-lg-8">
        <div class="card border-0 shadow-sm">
            <div class="card-header fw-semibold">
                <i class="bi bi-person me-1"></i> Account Information
            </div>
            <div class="card-body">
                <form method="POST" action="/profile">
                    <input type="hidden" name="csrf_token" value="<?= $this->csrfToken() ?>">
                    <input type="hidden" name="_tab" value="account">

                    <div class="mb-3">
                        <label class="form-label">Username</label>
                        <input type="text" class="form-control" value="<?= htmlspecialchars($user['username']) ?>" disabled>
                        <div class="form-text">Username cannot be changed.</div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Email</label>
                        <input type="email" class="form-control" name="email" value="<?= htmlspecialchars($user['email']) ?>">
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Timezone</label>
                        <select class="form-select" name="timezone">
                            <?php
                            $commonZones = [
                                'America/New_York', 'America/Chicago', 'America/Denver', 'America/Los_Angeles',
                                'America/Anchorage', 'Pacific/Honolulu', 'America/Phoenix',
                                'America/Toronto', 'America/Vancouver',
                                'Europe/London', 'Europe/Berlin', 'Europe/Paris', 'Europe/Amsterdam',
                                'Europe/Moscow', 'Asia/Tokyo', 'Asia/Shanghai', 'Asia/Kolkata',
                                'Australia/Sydney', 'Pacific/Auckland', 'UTC',
                            ];
                            $allZones = timezone_identifiers_list();
                            $userTz = $user['timezone'] ?? 'America/New_York';
                            ?>
                            <optgroup label="Common">
                                <?php foreach ($commonZones as $tz): ?>
                                <option value="<?= $tz ?>" <?= $userTz === $tz ? 'selected' : '' ?>><?= str_replace(['/', '_'], [' / ', ' '], $tz) ?></option>
                                <?php endforeach; ?>
                            </optgroup>
                            <optgroup label="All Timezones">
                                <?php foreach ($allZones as $tz): ?>
                                <option value="<?= $tz ?>" <?= $userTz === $tz ? 'selected' : '' ?>><?= $tz ?></option>
                                <?php endforeach; ?>
                            </optgroup>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Time Format</label>
                        <select class="form-select" name="time_format">
                            <option value="12h" <?= ($user['time_format'] ?? '12h') === '12h' ? 'selected' : '' ?>>12-hour (1:30 PM)</option>
                            <option value="24h" <?= ($user['time_format'] ?? '12h') === '24h' ? 'selected' : '' ?>>24-hour (13:30)</option>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Role</label>
                        <input type="text" class="form-control" value="<?= ucfirst($user['role']) ?>" disabled>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Member Since</label>
                        <input type="text" class="form-control" value="<?= \BBS\Core\TimeHelper::format($user['created_at'], 'M j, Y') ?>" disabled>
                    </div>

                    <button type="submit" class="btn btn-primary">Save Changes</button>
                </form>
            </div>
        </div>

        <!-- Per-user storage alert threshold (#156) -->
        <div class="card border-0 shadow-sm mt-4">
            <div class="card-header fw-semibold">
                <i class="bi bi-hdd-stack me-1"></i> Low-Storage Alerts
            </div>
            <div class="card-body">
                <form method="POST" action="/profile">
                    <input type="hidden" name="csrf_token" value="<?= $this->csrfToken() ?>">
                    <input type="hidden" name="_tab" value="storage_alerts">
                    <?php
                        $salMode  = $user['storage_alert_mode']  ?? 'percent';
                        $salValue = (int) ($user['storage_alert_value'] ?? 90);
                    ?>
                    <div class="mb-3">
                        <label class="form-label">Trigger</label>
                        <select class="form-select" name="storage_alert_mode" id="salMode" onchange="salUpdate()">
                            <option value="percent"  <?= $salMode === 'percent'  ? 'selected' : '' ?>>Percentage used</option>
                            <option value="gb_free"  <?= $salMode === 'gb_free'  ? 'selected' : '' ?>>Free space (GB)</option>
                            <option value="disabled" <?= $salMode === 'disabled' ? 'selected' : '' ?>>Disabled</option>
                        </select>
                        <div class="form-text">
                            Notify when any storage location passes your threshold. Every user sets their own — admins no longer share one server-wide number.
                        </div>
                    </div>
                    <div class="mb-3" id="salValueRow">
                        <label class="form-label"><span id="salValueLabel">Alert when usage is at or above</span></label>
                        <div class="input-group" style="max-width: 220px;">
                            <input type="number" class="form-control" name="storage_alert_value" id="salValue" value="<?= $salValue ?>" min="1">
                            <span class="input-group-text" id="salValueUnit"><?= $salMode === 'gb_free' ? 'GB free' : '% used' ?></span>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary">Save Alert Preferences</button>
                </form>
            </div>
        </div>
        <script>
        function salUpdate() {
            var mode = document.getElementById('salMode').value;
            var row  = document.getElementById('salValueRow');
            var unit = document.getElementById('salValueUnit');
            var lbl  = document.getElementById('salValueLabel');
            if (mode === 'disabled') { row.style.display = 'none'; return; }
            row.style.display = '';
            if (mode === 'gb_free') {
                unit.textContent = 'GB free';
                lbl.textContent  = 'Alert when free space is at or below';
            } else {
                unit.textContent = '% used';
                lbl.textContent  = 'Alert when usage is at or above';
            }
        }
        salUpdate();
        </script>
    </div>
</div>

<?php elseif ($tab === 'password'): ?>
<!-- Password Tab -->
<div class="row justify-content-center">
    <div class="col-lg-8">
        <div class="card border-0 shadow-sm">
            <div class="card-header fw-semibold">
                <i class="bi bi-key me-1"></i> Change Password
            </div>
            <div class="card-body">
                <form method="POST" action="/profile">
                    <input type="hidden" name="csrf_token" value="<?= $this->csrfToken() ?>">
                    <input type="hidden" name="_tab" value="password">

                    <div class="mb-3">
                        <label class="form-label">Current Password</label>
                        <input type="password" class="form-control" name="current_password" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">New Password</label>
                        <input type="password" class="form-control" name="new_password" required minlength="6">
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Confirm New Password</label>
                        <input type="password" class="form-control" name="confirm_password" required minlength="6">
                    </div>

                    <button type="submit" class="btn btn-warning">Change Password</button>
                </form>
            </div>
        </div>
    </div>
</div>

<?php elseif ($tab === '2fa'): ?>
<!-- Two-Factor Auth Tab -->
<div class="row justify-content-center">
    <div class="col-lg-8">

<?php if ($step === 'main'): ?>
    <?php if (!$twoFactorEnabled): ?>
        <!-- 2FA Disabled -->
        <div class="card border-0 shadow-sm">
            <div class="card-header fw-semibold">
                <i class="bi bi-shield-x me-1"></i> Two-Factor Authentication
            </div>
            <div class="card-body">
                <p class="mb-3">
                    Two-factor authentication adds an extra layer of security to your account.
                    When enabled, you'll need to enter a code from your authenticator app in addition
                    to your password when logging in.
                </p>
                <p class="text-muted small mb-4">
                    Compatible with Google Authenticator, Authy, 1Password, and other TOTP authenticator apps.
                </p>
                <form method="POST" action="/profile/2fa/setup">
                    <input type="hidden" name="csrf_token" value="<?= $this->csrfToken() ?>">
                    <button type="submit" class="btn btn-success">
                        <i class="bi bi-shield-check me-1"></i> Enable Two-Factor Authentication
                    </button>
                </form>
            </div>
        </div>
    <?php else: ?>
        <!-- 2FA Enabled -->
        <div class="card border-0 shadow-sm">
            <div class="card-header fw-semibold">
                <i class="bi bi-shield-check me-1 text-success"></i> Two-Factor Authentication
            </div>
            <div class="card-body">
                <div class="alert alert-success mb-4">
                    <i class="bi bi-check-circle me-1"></i> Your account is protected with two-factor authentication.
                </div>

                <div class="mb-4">
                    <h6 class="fw-semibold">Recovery Codes</h6>
                    <p class="text-muted small">
                        <?= $remainingCodes ?> of 8 codes remaining.
                        <?php if ($remainingCodes <= 2): ?>
                        <span class="badge bg-warning text-dark">Low</span>
                        <?php endif; ?>
                    </p>
                    <form method="POST" action="/profile/2fa/regenerate-codes" class="d-inline">
                        <input type="hidden" name="csrf_token" value="<?= $this->csrfToken() ?>">
                        <button type="submit" class="btn btn-sm btn-outline-primary"
                                onclick="return confirm('This will invalidate all existing recovery codes. Continue?')">
                            <i class="bi bi-arrow-clockwise me-1"></i> Regenerate Recovery Codes
                        </button>
                    </form>
                </div>

                <hr>

                <h6 class="text-danger mb-3">Disable Two-Factor Authentication</h6>
                <p class="text-muted small">Disabling 2FA will make your account less secure.</p>

                <button type="button" class="btn btn-outline-danger btn-sm" data-bs-toggle="collapse" data-bs-target="#disable2fa">
                    <i class="bi bi-shield-x me-1"></i> Disable 2FA
                </button>

                <div class="collapse mt-3" id="disable2fa">
                    <div class="card card-body bg-body-secondary">
                        <form method="POST" action="/profile/2fa/disable">
                            <input type="hidden" name="csrf_token" value="<?= $this->csrfToken() ?>">
                            <div class="mb-3">
                                <label class="form-label fw-semibold">Confirm Your Password</label>
                                <input type="password" class="form-control" name="password" required>
                            </div>
                            <button type="submit" class="btn btn-danger"
                                    onclick="return confirm('Are you sure you want to disable 2FA?')">
                                Disable 2FA
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

<?php elseif ($step === 'verify' && $setupSecret): ?>
    <!-- Step 2: Scan QR and Verify -->
    <?php
    $twoFactorSvc = new \BBS\Services\TwoFactorService();
    $qrSvg = $twoFactorSvc->generateQrCode($user['username'], $setupSecret);
    ?>
    <div class="card border-0 shadow-sm">
        <div class="card-header fw-semibold">
            <i class="bi bi-qr-code me-1"></i> Set Up Two-Factor Authentication
        </div>
        <div class="card-body">
            <p class="mb-3">
                Scan this QR code with your authenticator app, then enter the 6-digit code to verify.
            </p>

            <div class="text-center mb-4">
                <?= $qrSvg ?>
            </div>

            <div class="alert alert-light border small mb-4">
                <strong>Can't scan?</strong> Enter this secret manually:<br>
                <code class="user-select-all"><?= htmlspecialchars($setupSecret) ?></code>
            </div>

            <form method="POST" action="/profile/2fa/enable">
                <input type="hidden" name="csrf_token" value="<?= $this->csrfToken() ?>">
                <div class="mb-3">
                    <label class="form-label fw-semibold">Verification Code</label>
                    <input
                        type="text"
                        class="form-control"
                        name="code"
                        placeholder="000000"
                        maxlength="6"
                        pattern="[0-9]{6}"
                        required
                        autofocus
                        autocomplete="one-time-code"
                        style="max-width: 200px;"
                    >
                </div>
                <button type="submit" class="btn btn-success">
                    <i class="bi bi-check-lg me-1"></i> Verify and Enable
                </button>
                <a href="/profile?tab=2fa" class="btn btn-outline-secondary ms-2">Cancel</a>
            </form>
        </div>
    </div>

<?php elseif ($step === 'codes' && $recoveryCodes): ?>
    <!-- Step 3: Recovery Codes -->
    <div class="card border-0 shadow-sm border-warning">
        <div class="card-header bg-warning text-dark fw-semibold">
            <i class="bi bi-exclamation-triangle me-1"></i> Save Your Recovery Codes
        </div>
        <div class="card-body">
            <div class="alert alert-warning">
                <strong>Important:</strong> Save these recovery codes in a safe place.
                Each code can only be used once. If you lose access to your authenticator app,
                these codes are your only way to log in.
            </div>

            <div class="bg-body-secondary p-3 mb-3 rounded font-monospace" id="recovery-codes">
                <?php foreach ($recoveryCodes as $code): ?>
                <div><?= htmlspecialchars($code) ?></div>
                <?php endforeach; ?>
            </div>

            <button type="button" class="btn btn-primary btn-sm" onclick="copyRecoveryCodes()">
                <i class="bi bi-clipboard me-1"></i> Copy to Clipboard
            </button>
            <a href="/profile?tab=2fa" class="btn btn-success btn-sm ms-2">
                <i class="bi bi-check-lg me-1"></i> I've Saved My Codes
            </a>
        </div>
    </div>

    <script>
    function copyRecoveryCodes() {
        var codes = <?= json_encode($recoveryCodes) ?>;
        BBS.copyText(codes.join('\n')).then(function() {
            alert('Recovery codes copied to clipboard.');
        });
    }
    </script>
    <?php unset($_SESSION['2fa_recovery_codes']); ?>

<?php else: ?>
    <script>window.location.href = '/profile?tab=2fa';</script>
<?php endif; ?>

    </div>
</div>
<?php endif; ?>

<?php if ($tab === 'reports'): ?>
<!-- Reports Tab -->
<div class="row justify-content-center">
    <div class="col-lg-10">

        <?php if (!($smtpEnabled ?? false)): ?>
        <div class="alert alert-warning small">
            <i class="bi bi-exclamation-triangle me-1"></i>
            Email delivery requires SMTP to be configured.
            <?php if ($this->isAdmin()): ?>
                <a href="/settings?tab=smtp">Configure SMTP</a>
            <?php else: ?>
                Contact your administrator to set up SMTP.
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <div class="row g-4 mb-4">
            <!-- Preferences -->
            <div class="col-md-6">
                <div class="card border-0 shadow-sm">
                    <div class="card-header fw-semibold">
                        <i class="bi bi-gear me-1"></i> Report Settings
                    </div>
                    <div class="card-body">
                        <form method="POST" action="/profile/reports/preferences">
                            <input type="hidden" name="csrf_token" value="<?= $this->csrfToken() ?>">
                            <div class="form-check mb-2">
                                <input class="form-check-input" type="checkbox" name="daily_report_email" id="dailyReportEmail" value="1"
                                    <?= ($user['daily_report_email'] ?? 0) ? 'checked' : '' ?>>
                                <label class="form-check-label" for="dailyReportEmail">
                                    Email me a backup report
                                </label>
                            </div>
                            <div class="mb-2 ms-4">
                                <label class="form-label small mb-1" for="reportFrequency">Frequency</label>
                                <select class="form-select form-select-sm" name="report_frequency" id="reportFrequency" style="max-width:160px;" onchange="document.getElementById('reportDayRow').style.display = this.value === 'weekly' ? '' : 'none'">
                                    <option value="daily" <?= ($user['report_frequency'] ?? 'daily') === 'daily' ? 'selected' : '' ?>>Daily</option>
                                    <option value="weekly" <?= ($user['report_frequency'] ?? 'daily') === 'weekly' ? 'selected' : '' ?>>Weekly</option>
                                </select>
                            </div>
                            <div class="mb-2 ms-4" id="reportDayRow" style="<?= ($user['report_frequency'] ?? 'daily') === 'weekly' ? '' : 'display:none' ?>">
                                <label class="form-label small mb-1" for="reportDay">Day of week</label>
                                <select class="form-select form-select-sm" name="report_day" id="reportDay" style="max-width:160px;">
                                    <?php
                                    $days = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
                                    $currentDay = (int) ($user['report_day'] ?? 1);
                                    for ($d = 0; $d < 7; $d++):
                                    ?>
                                    <option value="<?= $d ?>" <?= $currentDay === $d ? 'selected' : '' ?>><?= $days[$d] ?></option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                            <div class="mb-3 ms-4">
                                <label class="form-label small mb-1" for="dailyReportHour">Delivery time (your timezone)</label>
                                <select class="form-select form-select-sm" name="daily_report_hour" id="dailyReportHour" style="max-width:160px;">
                                    <?php for ($h = 0; $h < 24; $h++):
                                        $label = date('g:00 A', mktime($h, 0));
                                        $selected = ((int)($user['daily_report_hour'] ?? 6)) === $h ? 'selected' : '';
                                    ?>
                                    <option value="<?= $h ?>" <?= $selected ?>><?= $label ?></option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                            <button type="submit" class="btn btn-sm btn-primary">Save</button>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Actions -->
            <div class="col-md-6">
                <div class="card border-0 shadow-sm">
                    <div class="card-header fw-semibold">
                        <i class="bi bi-lightning me-1"></i> Actions
                    </div>
                    <div class="card-body">
                        <form method="POST" action="/profile/reports/generate" class="mb-3">
                            <input type="hidden" name="csrf_token" value="<?= $this->csrfToken() ?>">
                            <button type="submit" class="btn btn-sm btn-outline-primary">
                                <i class="bi bi-arrow-clockwise me-1"></i>Generate Report Now
                            </button>
                        </form>

                        <?php if ($selectedReport ?? null): ?>
                        <form method="POST" action="/profile/reports/email" class="d-flex gap-2 align-items-end">
                            <input type="hidden" name="csrf_token" value="<?= $this->csrfToken() ?>">
                            <input type="hidden" name="report_id" value="<?= $selectedReport['id'] ?>">
                            <div class="flex-grow-1">
                                <label class="form-label small mb-1">Email report to</label>
                                <input type="email" class="form-control form-control-sm" name="email"
                                    placeholder="<?= htmlspecialchars($user['email']) ?>"
                                    value="">
                            </div>
                            <button type="submit" class="btn btn-sm btn-outline-secondary">
                                <i class="bi bi-envelope me-1"></i>Send
                            </button>
                        </form>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Past Reports -->
        <?php if (!empty($recentReports ?? [])): ?>
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header fw-semibold d-flex justify-content-between align-items-center">
                <span><i class="bi bi-clock-history me-1"></i> Recent Reports</span>
            </div>
            <div class="card-body p-0">
                <div class="list-group list-group-flush">
                    <?php foreach ($recentReports as $r): ?>
                    <?php
                        $isSelected = ($selectedReport ?? null) && $selectedReport['id'] == $r['id'];
                        $dateLabel = date('l, M j, Y', strtotime($r['report_date']));
                        $timeLabel = \BBS\Core\TimeHelper::format($r['created_at'], 'g:i A');
                    ?>
                    <a href="/profile?tab=reports&report_id=<?= $r['id'] ?>"
                       class="list-group-item list-group-item-action d-flex justify-content-between align-items-center <?= $isSelected ? 'active' : '' ?>">
                        <span>
                            <i class="bi bi-file-earmark-text me-1"></i>
                            <?= $dateLabel ?>
                        </span>
                        <small class="<?= $isSelected ? 'text-white-50' : 'text-muted' ?>">Generated <?= $timeLabel ?></small>
                    </a>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Report Viewer -->
        <?php if ($selectedReport ?? null): ?>
        <div class="card border-0 shadow-sm">
            <div class="card-header fw-semibold">
                <i class="bi bi-file-earmark-bar-graph me-1"></i>
                Report: <?= date('M j, Y', strtotime($selectedReport['report_date'])) ?> — <?= \BBS\Core\TimeHelper::format($selectedReport['created_at'], 'g:i A T') ?>
            </div>
            <div class="card-body p-3">
                <?php
                    $reportService = new \BBS\Services\ReportService();
                    echo $reportService->renderHtml($selectedReport['data'], $_SESSION['user_id']);
                ?>
            </div>
        </div>
        <?php elseif (empty($recentReports ?? [])): ?>
        <div class="card border-0 shadow-sm">
            <div class="card-body text-center py-5">
                <i class="bi bi-file-earmark-bar-graph" style="font-size:3rem;color:var(--bs-secondary);"></i>
                <p class="text-muted mt-3 mb-0">No reports yet. Click "Generate Report Now" to create your first report.</p>
            </div>
        </div>
        <?php endif; ?>

    </div>
</div>
<?php endif; ?>

</div><!-- /client-tab-content -->
