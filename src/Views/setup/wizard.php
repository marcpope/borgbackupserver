<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Setup - Borg Backup Server</title>
    <link href="/assets/bootstrap/bootstrap.min.css" rel="stylesheet">
    <link href="/assets/bootstrap-icons/bootstrap-icons.min.css" rel="stylesheet">
    <link href="/css/style.css" rel="stylesheet">
</head>
<body class="bg-body-secondary">
    <div class="container">
        <div class="row justify-content-center mt-5">
            <div class="col-md-7 col-lg-6">
                <div class="text-center mb-4">
                    <img src="/images/bbs-logo.png" alt="Borg Backup Server" class="img-fluid" style="max-width: 350px;">
                </div>

                <!-- Progress indicator -->
                <?php if ($step >= 1 && $step <= 5): ?>
                <div class="mb-4">
                    <div class="d-flex justify-content-between small text-muted mb-1">
                        <span>Step <?= $step ?> of 5</span>
                        <span><?= ['Welcome', 'Database', 'Admin Account', 'Storage & Server', 'Install'][$step - 1] ?></span>
                    </div>
                    <div class="progress" style="height: 6px;">
                        <div class="progress-bar bg-success" style="width: <?= ($step / 5) * 100 ?>%"></div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Error/success messages -->
                <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="bi bi-exclamation-triangle me-1"></i>
                    <?= htmlspecialchars($error) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>

                <!-- Step 1: Welcome & Requirements -->
                <?php if ($step === 1): ?>
                <div class="card shadow-sm">
                    <div class="card-body p-4">
                        <h4 class="mb-3"><i class="bi bi-box-seam me-2"></i>Welcome</h4>
                        <p class="text-muted">This wizard will configure Borg Backup Server. It only takes a minute.</p>

                        <h6 class="mt-4 mb-3">System Requirements</h6>
                        <table class="table table-sm mb-4">
                            <tbody>
                            <?php foreach ($requirements as $req): ?>
                                <tr>
                                    <td>
                                        <?php if ($req['ok']): ?>
                                            <i class="bi bi-check-circle-fill text-success"></i>
                                        <?php else: ?>
                                            <i class="bi bi-x-circle-fill text-danger"></i>
                                        <?php endif; ?>
                                        <?= htmlspecialchars($req['label']) ?>
                                    </td>
                                    <td class="text-end text-muted small"><?= htmlspecialchars($req['value']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>

                        <?php if ($allRequirementsMet): ?>
                            <a href="?step=2" class="btn btn-success btn-lg w-100">
                                Begin Setup <i class="bi bi-arrow-right ms-1"></i>
                            </a>
                        <?php else: ?>
                            <div class="alert alert-warning mb-0">
                                <i class="bi bi-exclamation-triangle me-1"></i>
                                Please resolve the requirements above before continuing.
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Step 2: Database -->
                <?php elseif ($step === 2): ?>
                <div class="card shadow-sm">
                    <div class="card-body p-4">
                        <h4 class="mb-3"><i class="bi bi-database me-2"></i>Database</h4>
                        <p class="text-muted">Enter your MySQL/MariaDB connection details. The database will be created if it doesn't exist.</p>

                        <form method="POST">
                            <input type="hidden" name="step" value="2">

                            <div class="mb-3">
                                <label class="form-label fw-semibold">Database Host</label>
                                <input type="text" class="form-control" name="db_host"
                                       value="<?= htmlspecialchars($_SESSION['setup']['db_host'] ?? 'localhost') ?>" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label fw-semibold">Database Name</label>
                                <input type="text" class="form-control" name="db_name"
                                       value="<?= htmlspecialchars($_SESSION['setup']['db_name'] ?? 'bbs') ?>" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label fw-semibold">Database User</label>
                                <input type="text" class="form-control" name="db_user"
                                       value="<?= htmlspecialchars($_SESSION['setup']['db_user'] ?? 'bbs') ?>" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label fw-semibold">Database Password</label>
                                <input type="password" class="form-control" name="db_pass"
                                       value="<?= htmlspecialchars($_SESSION['setup']['db_pass'] ?? '') ?>">
                            </div>

                            <div class="d-flex justify-content-between">
                                <a href="?step=1" class="btn btn-outline-secondary">
                                    <i class="bi bi-arrow-left me-1"></i> Back
                                </a>
                                <button type="submit" class="btn btn-success">
                                    Test Connection & Continue <i class="bi bi-arrow-right ms-1"></i>
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Step 3: Admin Account -->
                <?php elseif ($step === 3): ?>
                <div class="card shadow-sm">
                    <div class="card-body p-4">
                        <h4 class="mb-3"><i class="bi bi-person-badge me-2"></i>Admin Account</h4>
                        <p class="text-muted">Create your administrator account.</p>

                        <form method="POST">
                            <input type="hidden" name="step" value="3">

                            <div class="mb-3">
                                <label class="form-label fw-semibold">Email</label>
                                <input type="email" class="form-control" name="email" autocomplete="email"
                                       value="<?= htmlspecialchars($_SESSION['setup']['admin_email'] ?? '') ?>" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label fw-semibold">Username</label>
                                <input type="text" class="form-control" name="username" autocomplete="username"
                                       value="<?= htmlspecialchars($_SESSION['setup']['admin_username'] ?? 'admin') ?>" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label fw-semibold">Password</label>
                                <input type="password" class="form-control" name="password" autocomplete="new-password" minlength="8" required>
                                <div class="form-text">Minimum 8 characters.</div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label fw-semibold">Confirm Password</label>
                                <input type="password" class="form-control" name="password_confirm" minlength="8" required>
                            </div>

                            <div class="d-flex justify-content-between">
                                <a href="?step=2" class="btn btn-outline-secondary">
                                    <i class="bi bi-arrow-left me-1"></i> Back
                                </a>
                                <button type="submit" class="btn btn-success">
                                    Continue <i class="bi bi-arrow-right ms-1"></i>
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Step 4: Storage & Server -->
                <?php elseif ($step === 4): ?>
                <div class="card shadow-sm">
                    <div class="card-body p-4">
                        <h4 class="mb-3"><i class="bi bi-hdd me-2"></i>Storage & Server</h4>
                        <p class="text-muted">Configure where backups are stored and how agents connect.</p>

                        <form method="POST">
                            <input type="hidden" name="step" value="4">

                            <h6 class="mt-3 mb-2">Storage</h6>
                            <div class="mb-3">
                                <input type="hidden" name="storage_path" value="/var/bbs/home">
                                <div class="p-3 bg-body-secondary rounded small">
                                    <i class="bi bi-info-circle me-1"></i>
                                    <strong>Default storage:</strong> <code>/var/bbs/home</code> — SSH keys, agent home directories, and repositories are stored here by default.
                                    On Docker, the MySQL database and ClickHouse data are also under <code>/var/bbs</code>.
                                    On bare metal installs, MySQL uses its default location (<code>/var/lib/mysql</code>).
                                    <br><br>
                                    Ensure this partition has enough space for your backup data. To store repositories on external storage (NFS, dedicated drives, etc.), use the <strong>Storage Locations</strong> page after setup.
                                </div>
                            </div>

                            <h6 class="mt-4 mb-2">Server Connection</h6>
                            <div class="mb-3">
                                <label class="form-label fw-semibold">Server Hostname / IP</label>
                                <input type="text" class="form-control" name="server_host"
                                       value="<?= htmlspecialchars($_SESSION['setup']['server_host'] ?? ($_SERVER['HTTP_HOST'] ?? '')) ?>" required>
                                <div class="form-text">The address agents will use to connect (SSH and web UI).</div>
                            </div>
                            <div class="mb-3">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="enable_ssl" value="1" id="enableSsl"
                                           <?= ($_SESSION['setup']['enable_ssl'] ?? true) ? 'checked' : '' ?>>
                                    <label class="form-check-label fw-semibold" for="enableSsl">
                                        Enable SSL (HTTPS)
                                    </label>
                                </div>
                                <div class="form-text">Recommended for public servers. Uncheck for LAN/internal installs without a certificate.</div>
                            </div>

                            <div class="d-flex justify-content-between">
                                <a href="?step=3" class="btn btn-outline-secondary">
                                    <i class="bi bi-arrow-left me-1"></i> Back
                                </a>
                                <button type="submit" class="btn btn-success">
                                    Continue <i class="bi bi-arrow-right ms-1"></i>
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Step 5: Review & Install -->
                <?php elseif ($step === 5): ?>
                <?php $s = $_SESSION['setup'] ?? []; ?>
                <div class="card shadow-sm">
                    <div class="card-body p-4">
                        <h4 class="mb-3"><i class="bi bi-clipboard-check me-2"></i>Review & Install</h4>
                        <p class="text-muted">Confirm your settings, then install.</p>

                        <table class="table table-sm">
                            <tbody>
                                <tr><td class="fw-semibold text-muted" style="width:40%">Database Host</td><td><?= htmlspecialchars($s['db_host'] ?? '') ?></td></tr>
                                <tr><td class="fw-semibold text-muted">Database Name</td><td><?= htmlspecialchars($s['db_name'] ?? '') ?></td></tr>
                                <tr><td class="fw-semibold text-muted">Database User</td><td><?= htmlspecialchars($s['db_user'] ?? '') ?></td></tr>
                                <tr><td class="fw-semibold text-muted">Admin Username</td><td><?= htmlspecialchars($s['admin_username'] ?? '') ?></td></tr>
                                <tr><td class="fw-semibold text-muted">Admin Email</td><td><?= htmlspecialchars($s['admin_email'] ?? '') ?></td></tr>
                                <tr><td class="fw-semibold text-muted">Storage Path</td><td><?= htmlspecialchars($s['storage_path'] ?? '') ?></td></tr>
                                <tr><td class="fw-semibold text-muted">Server Host</td><td><?= htmlspecialchars($s['server_host'] ?? '') ?></td></tr>
                                <tr>
                                    <td class="fw-semibold text-muted">SSH Helper</td>
                                    <td>
                                        <?php if ($sshHelperInstalled): ?>
                                            <i class="bi bi-check-circle-fill text-success"></i> Installed
                                        <?php else: ?>
                                            <i class="bi bi-info-circle text-warning"></i> Not found <span class="text-muted small">(install later for SSH backups)</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            </tbody>
                        </table>

                        <form method="POST">
                            <input type="hidden" name="step" value="5">
                            <div class="d-flex justify-content-between">
                                <a href="?step=4" class="btn btn-outline-secondary">
                                    <i class="bi bi-arrow-left me-1"></i> Back
                                </a>
                                <button type="submit" class="btn btn-success btn-lg">
                                    <i class="bi bi-download me-1"></i> Install
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Step 6: Complete -->
                <?php elseif ($step === 6): ?>
                <div class="card shadow-sm border-success">
                    <div class="card-body p-4 text-center">
                        <div class="mb-3">
                            <i class="bi bi-check-circle-fill text-success" style="font-size: 3rem;"></i>
                        </div>
                        <h4 class="mb-3">Setup Complete</h4>
                        <p class="text-muted mb-4">Borg Backup Server is installed and ready to use. Add your first client to start backing up.</p>

                        <a href="/clients/add" class="btn btn-success btn-lg w-100 mb-2">
                            <i class="bi bi-plus-circle me-1"></i> Add Your First Client
                        </a>
                        <a href="/" class="btn btn-outline-secondary w-100">
                            Go to Dashboard
                        </a>
                    </div>
                </div>
                <?php endif; ?>

            </div>
        </div>
    </div>
    <script src="/assets/bootstrap/bootstrap.bundle.min.js"></script>
</body>
</html>
