<?php
use BBS\Services\ServerStats;

function formatStorageBytes(int $bytes): string {
    return ServerStats::formatBytes($bytes);
}

function formatStorageBytesDecimal(int $bytes): string {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = 0;
    $size = (float) $bytes;
    while ($size >= 1000 && $i < count($units) - 1) {
        $size /= 1000;
        $i++;
    }
    return round($size, 1) . "\u{00A0}" . $units[$i];
}

$section = $_GET['section'] ?? '';
?>

<script>
function escapeHtml(value) {
    return String(value).replace(/[&<>"']/g, function(ch) {
        return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[ch];
    });
}
</script>

<?php if ($section === 's3'): ?>
<!-- ==================== S3 Sync Settings ==================== -->
<nav class="mb-4">
    <ol class="breadcrumb mb-0">
        <li class="breadcrumb-item"><a href="/storage-locations">Storage</a></li>
        <li class="breadcrumb-item active">S3 Sync Settings</li>
    </ol>
</nav>

<form method="POST" action="/storage-locations/s3">
    <input type="hidden" name="csrf_token" value="<?= $this->csrfToken() ?>">

    <div class="row g-4">
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm">
                <div class="card-header fw-semibold">
                    <i class="bi bi-cloud-arrow-up me-1"></i> Global S3 Settings
                </div>
                <div class="card-body">
                    <p class="text-muted small mb-3">Configure S3-compatible storage for offsite repository sync. These credentials can be shared by all backup plans using the S3 Sync plugin with "Use Global S3 Settings".</p>

                    <?php
                    $s3Service = new \BBS\Services\S3SyncService();
                    $rcloneInstalled = $s3Service->isRcloneInstalled();
                    ?>
                    <?php if (!$rcloneInstalled): ?>
                    <div class="alert alert-warning py-2 px-3 small mb-3">
                        <i class="bi bi-exclamation-triangle me-1"></i>
                        <strong>rclone not installed.</strong> S3 sync requires rclone. Install with: <code>apt install rclone</code>
                    </div>
                    <?php endif; ?>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">S3 Endpoint URL</label>
                        <input type="text" class="form-control" name="s3_endpoint" value="<?= htmlspecialchars($settings['s3_endpoint'] ?? '') ?>" placeholder="e.g. s3.amazonaws.com">
                        <div class="form-text">The S3 API endpoint for your provider and region. Check your provider's documentation for the correct URL.</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Region</label>
                        <input type="text" class="form-control" name="s3_region" value="<?= htmlspecialchars($settings['s3_region'] ?? '') ?>" placeholder="us-east-1">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Bucket Name</label>
                        <input type="text" class="form-control" name="s3_bucket" value="<?= htmlspecialchars($settings['s3_bucket'] ?? '') ?>" placeholder="my-backup-bucket">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Access Key ID</label>
                        <input type="text" class="form-control" name="s3_access_key" id="s3_access_key" value="" autocomplete="new-password" placeholder="<?= !empty($settings['s3_access_key']) ? '(unchanged if empty)' : '' ?>">
                        <?php if (!empty($settings['s3_access_key'])): ?>
                            <div class="form-text">A value is saved. Leave empty to keep it unchanged.</div>
                        <?php endif; ?>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Secret Access Key</label>
                        <input type="text" class="form-control" name="s3_secret_key" id="s3_secret_key" value="" autocomplete="new-password" placeholder="<?= !empty($settings['s3_secret_key']) ? '(unchanged if empty)' : '' ?>">
                        <?php if (!empty($settings['s3_secret_key'])): ?>
                            <div class="form-text">A value is saved. Leave empty to keep it unchanged.</div>
                        <?php endif; ?>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Path Prefix</label>
                        <input type="text" class="form-control" name="s3_path_prefix" value="<?= htmlspecialchars($settings['s3_path_prefix'] ?? '') ?>" placeholder="Optional subfolder in bucket">
                        <div class="form-text">Repos sync to: <code>bucket/prefix/agent-name/repo-name/</code></div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Storage Class</label>
                        <select class="form-select" name="s3_storage_class">
                            <?php $sc = $settings['s3_storage_class'] ?? ''; ?>
                            <option value="" <?= $sc === '' ? 'selected' : '' ?>>Default (provider default)</option>
                            <option value="STANDARD" <?= $sc === 'STANDARD' ? 'selected' : '' ?>>Standard</option>
                            <option value="STANDARD_IA" <?= $sc === 'STANDARD_IA' ? 'selected' : '' ?>>Standard-IA (Infrequent Access)</option>
                            <option value="ONEZONE_IA" <?= $sc === 'ONEZONE_IA' ? 'selected' : '' ?>>One Zone-IA</option>
                            <option value="INTELLIGENT_TIERING" <?= $sc === 'INTELLIGENT_TIERING' ? 'selected' : '' ?>>Intelligent-Tiering</option>
                            <option value="GLACIER_IR" <?= $sc === 'GLACIER_IR' ? 'selected' : '' ?>>Glacier Instant Retrieval</option>
                            <option value="DEEP_ARCHIVE" <?= $sc === 'DEEP_ARCHIVE' ? 'selected' : '' ?>>Glacier Deep Archive</option>
                        </select>
                        <div class="form-text">Not all providers support all classes. Wasabi and B2 ignore this setting.</div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Server-Side Encryption</label>
                        <select class="form-select" name="s3_sse_mode" id="s3SseMode">
                            <?php $sse = $settings['s3_sse_mode'] ?? ''; ?>
                            <option value="" <?= $sse === '' ? 'selected' : '' ?>>None</option>
                            <option value="AES256" <?= $sse === 'AES256' ? 'selected' : '' ?>>AES-256 (SSE-S3)</option>
                            <option value="aws:kms" <?= $sse === 'aws:kms' ? 'selected' : '' ?>>AWS KMS (SSE-KMS)</option>
                        </select>
                    </div>

                    <div class="mb-3" id="s3KmsKeyWrap" style="<?= $sse === 'aws:kms' ? '' : 'display:none;' ?>">
                        <label class="form-label fw-semibold">KMS Key ID</label>
                        <input type="text" class="form-control" name="s3_sse_kms_key_id" value="<?= htmlspecialchars($settings['s3_sse_kms_key_id'] ?? '') ?>" placeholder="arn:aws:kms:region:account:key/key-id">
                        <div class="form-text">Required when using AWS KMS encryption. Leave empty for the default KMS key.</div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Bandwidth Limit</label>
                        <input type="text" class="form-control" name="s3_bandwidth_limit" value="<?= htmlspecialchars($settings['s3_bandwidth_limit'] ?? '') ?>" placeholder="e.g. 50M for 50 MB/s">
                        <div class="form-text">Limits upload speed. Examples: 50M (50 MB/s), 10M, 1G. Leave empty for unlimited.</div>
                    </div>

                    <hr>
                    <div class="form-check mb-3">
                        <input type="hidden" name="s3_sync_server_backups" value="0">
                        <input class="form-check-input" type="checkbox" name="s3_sync_server_backups" id="s3_sync_server_backups" value="1" <?= ($settings['s3_sync_server_backups'] ?? '0') === '1' ? 'checked' : '' ?>>
                        <label class="form-check-label" for="s3_sync_server_backups">
                            Sync server backups to off-site storage daily
                        </label>
                        <div class="form-text">Uploads the server backups from <code>/var/bbs/backups/</code> and removes deleted ones from S3.</div>
                    </div>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-sm btn-warning">
                            <i class="bi bi-check-lg me-1"></i> Save S3 Settings
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-secondary" id="btnTestS3">
                            <i class="bi bi-plug me-1"></i> Test Connection
                        </button>
                        <span id="s3TestResult" class="d-flex align-items-center ms-2 small"></span>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="card border-0 shadow-sm">
                <div class="card-header fw-semibold">
                    <i class="bi bi-info-circle me-1"></i> How It Works
                </div>
                <div class="card-body">
                    <ol class="small mb-0">
                        <li class="mb-2">Configure your S3 credentials here (or use custom credentials per-config on the Plugins tab).</li>
                        <li class="mb-2">Enable the <strong>S3 Offsite Sync</strong> plugin on your client's Plugins tab.</li>
                        <li class="mb-2">Create a named config (e.g. "Production S3") and choose "Use Global S3 Settings" or enter custom credentials.</li>
                        <li class="mb-2">Attach the config to a backup plan.</li>
                        <li class="mb-2">After each prune/compact cycle, the server automatically syncs the borg repository to S3 using <code>rclone sync</code>.</li>
                        <li class="mb-2">Only changed segments are uploaded — borg's append-only data format makes this naturally efficient.</li>
                    </ol>
                    <hr>
                    <div class="small text-muted">
                        <strong>Supported providers:</strong> AWS S3, Backblaze B2, Wasabi, MinIO, DigitalOcean Spaces, and any S3-compatible endpoint.
                    </div>
                </div>
            </div>
        </div>
    </div>
</form>

<script>
// KMS key field visibility
document.getElementById('s3SseMode')?.addEventListener('change', function() {
    document.getElementById('s3KmsKeyWrap').style.display = this.value === 'aws:kms' ? '' : 'none';
});

document.getElementById('btnTestS3')?.addEventListener('click', function() {
    var btn = this;
    var result = document.getElementById('s3TestResult');
    btn.disabled = true;
    result.textContent = 'Testing...';
    result.className = 'd-flex align-items-center ms-2 small text-muted';
    fetch('/storage-locations/s3/test', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'csrf_token=' + encodeURIComponent(document.querySelector('input[name=csrf_token]').value)
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        btn.disabled = false;
        if (data.success) {
            result.textContent = 'Success';
            result.className = 'd-flex align-items-center ms-2 small text-success fw-semibold';
        } else {
            result.textContent = 'Failed: ' + data.error;
            result.className = 'd-flex align-items-center ms-2 small text-danger fw-semibold';
        }
    })
    .catch(function() {
        btn.disabled = false;
        result.textContent = 'Request failed.';
        result.className = 'd-flex align-items-center ms-2 small text-danger fw-semibold';
    });
});
</script>

<?php elseif ($section === 'wizard'): ?>
<!-- ==================== Add Remote Storage Host Wizard ==================== -->
<nav class="mb-4">
    <ol class="breadcrumb mb-0">
        <li class="breadcrumb-item"><a href="/storage-locations">Storage</a></li>
        <li class="breadcrumb-item active">Add SSH Host</li>
    </ol>
</nav>

<h5 class="mb-1">Add Remote Storage Host</h5>
<p class="text-muted small mb-4">Choose your provider to get started, or use Custom for any SSH-accessible server with borg client.</p>

<div id="wizardProviders" class="row g-3 mb-4">
    <!-- BorgBase -->
    <div class="col-md-6 col-lg-3">
        <div class="card border-0 shadow-sm h-100 text-center" style="cursor:pointer;background:rgba(200,170,50,0.10)" onclick="showWizardForm('borgbase')">
            <div class="card-body py-4">
                <img src="/images/borgbase.svg" alt="BorgBase" class="mb-3" style="width:48px;height:48px;border-radius:50%">
                <h6 class="mb-1">BorgBase</h6>
                <p class="text-muted small mb-2">Simple and Secure</p>
                <span class="btn btn-sm btn-dark">Setup</span>
            </div>
        </div>
    </div>
    <!-- Hetzner -->
    <div class="col-md-6 col-lg-3">
        <div class="card border-0 shadow-sm h-100 text-center" style="cursor:pointer;background:rgba(220,50,50,0.08)" onclick="showWizardForm('hetzner')">
            <div class="card-body py-4">
                <img src="/images/hetzner-h.png" alt="Hetzner" class="mb-3" style="width:48px;height:48px;border-radius:50%">
                <h6 class="mb-1">Hetzner</h6>
                <p class="text-muted small mb-2">Affordable Storage Boxes</p>
                <span class="btn btn-sm btn-danger">Setup</span>
            </div>
        </div>
    </div>
    <!-- rsync.net -->
    <div class="col-md-6 col-lg-3">
        <div class="card border-0 shadow-sm h-100 text-center" style="cursor:pointer;background:rgba(60,80,120,0.08)" onclick="showWizardForm('rsyncnet')">
            <div class="card-body py-4">
                <img src="/images/rsyncnet-logo.png" alt="rsync.net" class="mb-3" style="height:48px;border-radius:8px">
                <h6 class="mb-1">rsync.net</h6>
                <p class="text-muted small mb-2">Cloud Storage for Borg</p>
                <span class="btn btn-sm" style="background:#3c5078;color:#fff">Setup</span>
            </div>
        </div>
    </div>
    <!-- Custom -->
    <div class="col-md-6 col-lg-3">
        <div class="card border-0 shadow-sm h-100 text-center" style="cursor:pointer" data-bs-toggle="modal" data-bs-target="#addRemoteSshModal">
            <div class="card-body py-4">
                <div class="rounded-circle bg-secondary bg-opacity-10 d-inline-flex align-items-center justify-content-center mb-3" style="width:48px;height:48px">
                    <i class="bi bi-gear fs-4 text-secondary"></i>
                </div>
                <h6 class="mb-1">Custom</h6>
                <p class="text-muted small mb-2">Any SSH server with borg</p>
                <span class="btn btn-sm btn-outline-secondary">Setup</span>
            </div>
        </div>
    </div>
</div>

<!-- BorgBase Wizard Form -->
<div id="wizardBorgbase" style="display:none">
    <div class="card border-0 shadow-sm">
        <div class="card-header fw-semibold" style="background:rgba(200,170,50,0.12)">
            <img src="/images/borgbase.svg" alt="" style="width:18px;height:18px;border-radius:50%;vertical-align:text-bottom" class="me-1"> BorgBase Setup
        </div>
        <div class="card-body">
            <p class="text-muted small mb-3">Paste the SSH connection string from your <a href="https://www.borgbase.com" target="_blank">BorgBase</a> repository page, then paste your SSH private key below.</p>

            <form method="POST" action="/remote-ssh-configs/create" id="borgbaseWizardForm">
                <input type="hidden" name="csrf_token" value="<?= $this->csrfToken() ?>">
                <input type="hidden" name="provider" value="borgbase">
                <input type="hidden" name="borg_remote_path" value="">
                <!-- append_repo_name intentionally omitted = 0 for BorgBase -->

                <div class="mb-3">
                    <label class="form-label fw-semibold">Connection String</label>
                    <input type="text" class="form-control" id="bbConnString" placeholder="ssh://username@username.repo.borgbase.com/./repo">
                    <div class="form-text">Find this on your BorgBase repo page under "Repository".</div>
                </div>

                <!-- Parsed details -->
                <div id="bbParsedDetails" class="alert alert-light border small py-2 px-3 mb-3" style="display:none">
                    <div class="row g-2">
                        <div class="col-sm-6"><strong>Host:</strong> <span id="bbParsedHost"></span></div>
                        <div class="col-sm-6"><strong>User:</strong> <span id="bbParsedUser"></span></div>
                        <div class="col-sm-6"><strong>Port:</strong> <span id="bbParsedPort"></span></div>
                        <div class="col-sm-6"><strong>Path:</strong> <span id="bbParsedPath"></span></div>
                    </div>
                </div>

                <div id="bbParseError" class="alert alert-danger small py-2 px-3 mb-3" style="display:none">
                    <i class="bi bi-exclamation-triangle me-1"></i> Could not parse connection string. Expected format: <code>ssh://user@host/path</code>
                </div>

                <!-- Hidden fields populated by JS -->
                <input type="hidden" name="remote_host" id="bbFieldHost">
                <input type="hidden" name="remote_port" id="bbFieldPort" value="22">
                <input type="hidden" name="remote_user" id="bbFieldUser">
                <input type="hidden" name="remote_base_path" id="bbFieldPath" value="./repo">

                <div class="mb-3">
                    <label class="form-label fw-semibold">SSH Private Key</label>
                    <textarea class="form-control font-monospace" name="ssh_private_key" id="bbSshKey" rows="4" placeholder="-----BEGIN OPENSSH PRIVATE KEY-----&#10;..." required></textarea>
                    <div class="form-text">Paste the private key that matches the public key you added to BorgBase.</div>
                </div>

                <div class="border rounded p-3 mb-3">
                    <div class="fw-semibold mb-2">Manual Quota</div>
                    <label class="form-label fw-semibold">Quota <span class="text-muted fw-normal">(GB, optional)</span></label>
                    <input type="number" class="form-control" name="borgbase_manual_quota_gb" id="bbManualQuota" min="0" step="0.001" placeholder="e.g., 10">
                    <div class="form-text">Use this when you do not want to connect the BorgBase API.</div>
                </div>

                <div class="border rounded p-3 mb-3">
                    <div class="fw-semibold mb-2">BorgBase API</div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold" for="bbApiKey">API Key <span class="text-muted fw-normal">(optional)</span></label>
                        <input type="text" class="form-control" name="borgbase_api_key" id="bbApiKey" value="" autocomplete="new-password" data-lpignore="true" data-1p-ignore="true" spellcheck="false" placeholder="Used only to sync quota and current usage">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold" for="bbRepoName">BorgBase Repo Name <span class="text-danger d-none" id="bbRepoNameRequired">*</span></label>
                        <input type="text" class="form-control" name="borgbase_repo_name" id="bbRepoName">
                        <div class="invalid-feedback">Repository name is required when an API key is provided.</div>
                    </div>
                    <button type="button" class="btn btn-sm btn-outline-primary" id="bbApiTestBtn" onclick="testBorgbaseApiFromWizard()" disabled>
                        <i class="bi bi-shield-check me-1"></i> Verify API
                    </button>
                    <div class="form-text mt-2">The API key is encrypted before storage. The API repository id is matched to the SSH username from the connection string, and the API name must match the repo name above.</div>
                    <div id="bbApiTestResult" style="display:none" class="mt-2"></div>
                </div>

                <div class="mb-3">
                    <label class="form-label fw-semibold">Name</label>
                    <input type="text" class="form-control" name="name" id="bbName" placeholder="e.g., BorgBase - my-repo" required>
                    <div class="form-text">A friendly name to identify this host in BBS.</div>
                </div>

                <div id="bbTestResult" style="display:none" class="mb-3"></div>

                <div class="d-flex gap-2 align-items-center">
                    <button type="button" class="btn btn-sm btn-outline-primary" id="bbTestBtn" disabled onclick="testBorgbaseConnection()">
                        <i class="bi bi-plug me-1"></i> Test Connection
                    </button>
                    <button type="submit" class="btn btn-sm btn-primary" id="bbSubmitBtn" style="display:none">
                        <i class="bi bi-plus-lg me-1"></i> Add Host
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="hideWizardForm()">Cancel</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Hetzner Storage Box Wizard Form -->
<div id="wizardHetzner" style="display:none">
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-danger bg-opacity-10 fw-semibold">
            <img src="/images/hetzner-h.png" alt="" style="width:18px;height:18px;border-radius:50%;vertical-align:text-bottom" class="me-1"> Hetzner Storage Box Setup
        </div>
        <div class="card-body">
            <p class="text-muted small mb-3">Enter the connection details from your <a href="https://www.hetzner.com/storage/storage-box" target="_blank">Hetzner Storage Box</a> control panel.</p>

            <form method="POST" action="/remote-ssh-configs/create" id="hetznerWizardForm">
                <input type="hidden" name="csrf_token" value="<?= $this->csrfToken() ?>">
                <input type="hidden" name="provider" value="hetzner">
                <input type="hidden" name="remote_port" value="23">
                <input type="hidden" name="remote_base_path" value="./">
                <input type="hidden" name="append_repo_name" value="1">

                <div class="row g-3 mb-3">
                    <div class="col-sm-6">
                        <label class="form-label fw-semibold">Hostname</label>
                        <input type="text" class="form-control" id="hzHostname" name="remote_host" placeholder="uXXXXXX.your-storagebox.de" required>
                        <div class="form-text">Found in your Hetzner Robot panel.</div>
                    </div>
                    <div class="col-sm-6">
                        <label class="form-label fw-semibold">Username</label>
                        <input type="text" class="form-control" id="hzUsername" name="remote_user" placeholder="uXXXXXX" required>
                        <div class="form-text">Your Storage Box username.</div>
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label fw-semibold">SSH Private Key</label>
                    <textarea class="form-control font-monospace" name="ssh_private_key" id="hzSshKey" rows="4" placeholder="-----BEGIN OPENSSH PRIVATE KEY-----&#10;..." required></textarea>
                    <div class="form-text">Paste the private key that matches the public key you added to your Storage Box.</div>
                </div>

                <div class="mb-3">
                    <label class="form-label fw-semibold">Borg Version</label>
                    <select class="form-select" id="hzBorgVersion" name="borg_remote_path">
                        <option value="borg-1.4">borg 1.4 (Recommended)</option>
                        <option value="borg-1.2">borg 1.2</option>
                        <option value="borg-1.1">borg 1.1</option>
                    </select>
                    <div class="form-text">Hetzner provides multiple borg versions. This is passed as <code>--remote-path</code> in all borg commands.</div>
                </div>

                <div class="mb-3">
                    <label class="form-label fw-semibold">Name</label>
                    <input type="text" class="form-control" name="name" id="hzName" placeholder="e.g., Hetzner - uXXXXXX" required>
                    <div class="form-text">A friendly name to identify this host in BBS.</div>
                </div>

                <!-- Parsed summary -->
                <div id="hzParsedDetails" class="alert alert-light border small py-2 px-3 mb-3" style="display:none">
                    <div class="row g-2">
                        <div class="col-sm-6"><strong>Host:</strong> <span id="hzParsedHost"></span></div>
                        <div class="col-sm-6"><strong>User:</strong> <span id="hzParsedUser"></span></div>
                        <div class="col-sm-6"><strong>Port:</strong> 23</div>
                        <div class="col-sm-6"><strong>Path:</strong> ./<em>&lt;repo-name&gt;</em></div>
                        <div class="col-sm-6"><strong>Borg:</strong> <span id="hzParsedBorg"></span></div>
                    </div>
                </div>

                <div id="hzTestResult" style="display:none" class="mb-3"></div>

                <div class="d-flex gap-2 align-items-center">
                    <button type="button" class="btn btn-sm btn-outline-danger" id="hzTestBtn" disabled onclick="testHetznerConnection()">
                        <i class="bi bi-plug me-1"></i> Test Connection
                    </button>
                    <button type="submit" class="btn btn-sm btn-danger" id="hzSubmitBtn" style="display:none">
                        <i class="bi bi-plus-lg me-1"></i> Add Host
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="hideWizardForm()">Cancel</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- rsync.net Wizard Form -->
<div id="wizardRsyncnet" style="display:none">
    <div class="card border-0 shadow-sm">
        <div class="card-header fw-semibold" style="background:rgba(60,80,120,0.10)">
            <i class="bi bi-hdd-rack me-1"></i> rsync.net Setup
        </div>
        <div class="card-body">
            <p class="text-muted small mb-3">Enter the connection details from your <a href="https://www.rsync.net/products/borg.html" target="_blank">rsync.net</a> account.</p>

            <form method="POST" action="/remote-ssh-configs/create" id="rsyncnetWizardForm">
                <input type="hidden" name="csrf_token" value="<?= $this->csrfToken() ?>">
                <input type="hidden" name="provider" value="rsync.net">
                <input type="hidden" name="remote_port" value="22">
                <input type="hidden" name="remote_base_path" value="./">
                <input type="hidden" name="append_repo_name" value="1">

                <div class="row g-3 mb-3">
                    <div class="col-sm-6">
                        <label class="form-label fw-semibold">Username</label>
                        <input type="text" class="form-control" id="rsnUsername" name="remote_user" placeholder="deXXXX" required>
                        <div class="form-text">Your rsync.net account ID.</div>
                    </div>
                    <div class="col-sm-6">
                        <label class="form-label fw-semibold">Hostname</label>
                        <input type="text" class="form-control" id="rsnHostname" name="remote_host" placeholder="deXXXX.rsync.net" required>
                        <div class="form-text">Your rsync.net SSH hostname.</div>
                    </div>
                </div>

                <div class="alert alert-warning small py-2 px-3 mb-3">
                    <i class="bi bi-key me-1"></i> <strong>SSH Key Setup</strong> &mdash; <code>ssh-copy-id</code> does not work with rsync.net. Create and copy your key manually:
                    <pre class="mt-2 mb-0 bg-dark text-light p-2 rounded small" style="white-space:pre-wrap"><code># Generate a new key pair
ssh-keygen -t ed25519 -C "rsync.net" -f ~/.ssh/rsyncnet

# Copy the public key to rsync.net
scp ~/.ssh/rsyncnet.pub <span class="text-warning" id="rsnScpUser">USERNAME</span>@<span class="text-warning" id="rsnScpHost">USERNAME.rsync.net</span>:.ssh/authorized_keys</code></pre>
                    <div class="mt-2">Then paste your <strong>private</strong> key (<code>cat ~/.ssh/rsyncnet</code>) into the box below.</div>
                </div>

                <div class="mb-3">
                    <label class="form-label fw-semibold">SSH Private Key</label>
                    <textarea class="form-control font-monospace" name="ssh_private_key" id="rsnSshKey" rows="4" placeholder="-----BEGIN OPENSSH PRIVATE KEY-----&#10;..." required></textarea>
                </div>

                <div class="mb-3">
                    <label class="form-label fw-semibold">Remote Borg Version</label>
                    <select class="form-select" id="rsnBorgVersion" name="borg_remote_path">
                        <option value="borg12">Borg 1.2.x &mdash; remote command: borg12</option>
                        <option value="borg14">Borg 1.4.x &mdash; remote command: borg14</option>
                    </select>
                    <div class="form-text">rsync.net provides multiple borg versions. This is passed as <code>--remote-path</code> in all borg commands.</div>
                </div>

                <div class="mb-3">
                    <label class="form-label fw-semibold">Name</label>
                    <input type="text" class="form-control" name="name" id="rsnName" placeholder="e.g., rsync.net - deXXXX" required>
                    <div class="form-text">A friendly name to identify this host in BBS.</div>
                </div>

                <!-- Parsed summary -->
                <div id="rsnParsedDetails" class="alert alert-light border small py-2 px-3 mb-3" style="display:none">
                    <div class="row g-2">
                        <div class="col-sm-6"><strong>Host:</strong> <span id="rsnParsedHost"></span></div>
                        <div class="col-sm-6"><strong>User:</strong> <span id="rsnParsedUser"></span></div>
                        <div class="col-sm-6"><strong>Port:</strong> 22</div>
                        <div class="col-sm-6"><strong>Path:</strong> ./<em>&lt;repo-name&gt;</em></div>
                        <div class="col-sm-6"><strong>Borg:</strong> <span id="rsnParsedBorg"></span></div>
                    </div>
                </div>

                <div id="rsnTestResult" style="display:none" class="mb-3"></div>

                <div class="d-flex gap-2 align-items-center">
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="rsnTestBtn" disabled onclick="testRsyncnetConnection()" style="border-color:#3c5078;color:#3c5078">
                        <i class="bi bi-plug me-1"></i> Test Connection
                    </button>
                    <button type="submit" class="btn btn-sm" id="rsnSubmitBtn" style="display:none;background:#3c5078;color:#fff">
                        <i class="bi bi-plus-lg me-1"></i> Add Host
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="hideWizardForm()">Cancel</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Add Remote SSH Host Modal (Custom) -->
<div class="modal fade" id="addRemoteSshModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="/remote-ssh-configs/create">
                <input type="hidden" name="csrf_token" value="<?= $this->csrfToken() ?>">
                <input type="hidden" name="provider" value="">
                <div class="modal-header">
                    <h5 class="modal-title">Add Remote SSH Host</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Name</label>
                        <input type="text" class="form-control" name="name" placeholder="e.g., rsync.net Production" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Provider Preset</label>
                        <select class="form-select" onchange="applyRemotePreset(this, this.closest('form'))">
                            <option value="">Custom</option>
                            <option value="rsync.net">rsync.net</option>
                            <option value="borgbase">BorgBase</option>
                            <option value="hetzner">Hetzner Storage Box</option>
                        </select>
                    </div>
                    <div class="row g-3 mb-3">
                        <div class="col-8">
                            <label class="form-label fw-semibold">Host</label>
                            <input type="text" class="form-control" name="remote_host" placeholder="ch-s010.rsync.net" required>
                        </div>
                        <div class="col-4">
                            <label class="form-label fw-semibold">Port</label>
                            <input type="number" class="form-control" name="remote_port" value="22" min="1" max="65535">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Username</label>
                        <input type="text" class="form-control" name="remote_user" placeholder="12345" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Base Path</label>
                        <input type="text" class="form-control" name="remote_base_path" value="./">
                        <div class="form-text">Base directory on the remote host. Use <code>./</code> for relative paths (rsync.net default).</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">SSH Private Key</label>
                        <textarea class="form-control font-monospace" name="ssh_private_key" rows="4" placeholder="-----BEGIN OPENSSH PRIVATE KEY-----&#10;..." required></textarea>
                        <div class="form-text">Paste the private key (PEM format). The corresponding public key must be authorized on the remote host.</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Remote Borg Path <span class="text-muted fw-normal">(optional)</span></label>
                        <input type="text" class="form-control" name="borg_remote_path" placeholder="">
                        <div class="form-text">Custom borg binary on the remote host (e.g., <code>borg1</code> for rsync.net). Leave blank for default <code>borg</code>.</div>
                    </div>
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" name="append_repo_name" value="1" id="addAppendRepoName" checked>
                        <label class="form-check-label" for="addAppendRepoName">Append repository name to base path</label>
                        <div class="form-text">Uncheck for providers like BorgBase where each SSH user maps to a single fixed repo path.</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Add Host</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function showWizardForm(provider) {
    document.getElementById('wizardProviders').style.display = 'none';
    document.getElementById('wizard' + provider.charAt(0).toUpperCase() + provider.slice(1)).style.display = 'block';
    if (provider === 'borgbase') {
        setTimeout(function() {
            document.getElementById('bbApiKey').value = '';
            updateBbSubmit();
        }, 0);
    }
}
function hideWizardForm() {
    document.querySelectorAll('[id^="wizard"]').forEach(function(el) {
        if (el.id === 'wizardProviders') { el.style.display = ''; }
        else if (el.id.startsWith('wizard') && el.id !== 'wizardProviders') { el.style.display = 'none'; }
    });
    // Reset BorgBase form
    document.getElementById('borgbaseWizardForm').reset();
    bbTestPassed = false;
    document.getElementById('bbParsedDetails').style.display = 'none';
    document.getElementById('bbParseError').style.display = 'none';
    document.getElementById('bbTestBtn').disabled = true;
    document.getElementById('bbApiKey').value = '';
    document.getElementById('bbApiTestBtn').disabled = true;
    document.getElementById('bbSubmitBtn').style.display = 'none';
    document.getElementById('bbTestResult').style.display = 'none';
    document.getElementById('bbApiTestResult').style.display = 'none';
    // Reset Hetzner form
    document.getElementById('hetznerWizardForm').reset();
    hzTestPassed = false;
    document.getElementById('hzParsedDetails').style.display = 'none';
    document.getElementById('hzTestBtn').disabled = true;
    document.getElementById('hzSubmitBtn').style.display = 'none';
    document.getElementById('hzTestResult').style.display = 'none';
    hzNameUserEdited = false;
    // Reset rsync.net form
    document.getElementById('rsyncnetWizardForm').reset();
    rsnTestPassed = false;
    document.getElementById('rsnParsedDetails').style.display = 'none';
    document.getElementById('rsnTestBtn').disabled = true;
    document.getElementById('rsnSubmitBtn').style.display = 'none';
    document.getElementById('rsnTestResult').style.display = 'none';
    document.getElementById('rsnScpUser').textContent = 'USERNAME';
    document.getElementById('rsnScpHost').textContent = 'USERNAME.rsync.net';
    rsnNameUserEdited = false;
}

// BorgBase connection string parser
document.getElementById('bbConnString').addEventListener('input', function() {
    bbTestPassed = false;
    var value = this.value.trim();
    var match = value.match(/^ssh:\/\/([^@]+)@([^:\/]+)(?::(\d+))?(\/.*)?$/);
    var detailsEl = document.getElementById('bbParsedDetails');
    var errorEl = document.getElementById('bbParseError');

    if (match) {
        var user = match[1];
        var host = match[2];
        var port = match[3] || '22';
        var path = match[4] || '/./repo';
        if (path.startsWith('/./')) path = '.' + path.slice(2);
        else if (path.startsWith('/')) path = '.' + path;

        document.getElementById('bbParsedHost').textContent = host;
        document.getElementById('bbParsedUser').textContent = user;
        document.getElementById('bbParsedPort').textContent = port;
        document.getElementById('bbParsedPath').textContent = path;

        document.getElementById('bbFieldHost').value = host;
        document.getElementById('bbFieldPort').value = port;
        document.getElementById('bbFieldUser').value = user;
        document.getElementById('bbFieldPath').value = path;

        var nameField = document.getElementById('bbName');
        if (!nameField.dataset.userEdited) {
            nameField.value = 'BorgBase - ' + user;
        }

        detailsEl.style.display = 'block';
        errorEl.style.display = 'none';
        updateBbSubmit();
    } else if (value.length > 5) {
        detailsEl.style.display = 'none';
        errorEl.style.display = 'block';
        document.getElementById('bbFieldHost').value = '';
        document.getElementById('bbSubmitBtn').disabled = true;
    } else {
        detailsEl.style.display = 'none';
        errorEl.style.display = 'none';
        document.getElementById('bbSubmitBtn').disabled = true;
    }
});

document.getElementById('bbName').addEventListener('input', function() {
    this.dataset.userEdited = '1';
});

var bbTestPassed = false;

function updateBbSubmit() {
    var host = document.getElementById('bbFieldHost').value;
    var key = document.getElementById('bbSshKey').value.trim();
    var name = document.getElementById('bbName').value.trim();
    var apiKey = document.getElementById('bbApiKey').value.trim();
    var repoName = document.getElementById('bbRepoName').value.trim();
    var repoInput = document.getElementById('bbRepoName');
    var repoRequired = document.getElementById('bbRepoNameRequired');
    var canTest = !!(host && key);
    document.getElementById('bbTestBtn').disabled = !canTest;
    document.getElementById('bbApiTestBtn').disabled = !(apiKey && repoName && document.getElementById('bbFieldUser').value);
    repoInput.required = !!apiKey;
    repoInput.classList.toggle('is-invalid', !!apiKey && !repoName);
    repoRequired.classList.toggle('d-none', !apiKey);
    if (!bbTestPassed) {
        document.getElementById('bbSubmitBtn').style.display = 'none';
        document.getElementById('bbTestResult').style.display = 'none';
    } else {
        document.getElementById('bbSubmitBtn').style.display = name ? '' : 'none';
    }
}
document.getElementById('bbSshKey').addEventListener('input', function() { bbTestPassed = false; updateBbSubmit(); });
document.getElementById('bbName').addEventListener('input', updateBbSubmit);
document.getElementById('bbApiKey').addEventListener('input', updateBbSubmit);
document.getElementById('bbRepoName').addEventListener('input', updateBbSubmit);

function testBorgbaseConnection() {
    var btn = document.getElementById('bbTestBtn');
    var resultDiv = document.getElementById('bbTestResult');
    var submitBtn = document.getElementById('bbSubmitBtn');
    var nameField = document.getElementById('bbName');

    bbTestPassed = false;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Testing...';
    resultDiv.style.display = 'none';
    submitBtn.style.display = 'none';

    var formData = new URLSearchParams();
    formData.append('csrf_token', document.querySelector('#borgbaseWizardForm [name=csrf_token]').value);
    formData.append('remote_host', document.getElementById('bbFieldHost').value);
    formData.append('remote_port', document.getElementById('bbFieldPort').value);
    formData.append('remote_user', document.getElementById('bbFieldUser').value);
    formData.append('ssh_private_key', document.getElementById('bbSshKey').value);
    formData.append('borg_remote_path', '');

    fetch('/remote-ssh-configs/test-new', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: formData.toString()
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        resultDiv.style.display = 'block';
        if (data.status === 'ok') {
            bbTestPassed = true;
            resultDiv.innerHTML = '<div class="alert alert-success small py-2 px-3 mb-0"><i class="bi bi-check-circle me-1"></i> Connected — ' + (data.version || 'borg detected').replace(/</g, '&lt;') + '</div>';
            if (nameField.value.trim()) {
                submitBtn.style.display = '';
            }
        } else {
            resultDiv.innerHTML = '<div class="alert alert-danger small py-2 px-3 mb-0"><i class="bi bi-x-circle me-1"></i> ' + (data.error || 'Connection failed').replace(/</g, '&lt;') + '</div>';
        }
    })
    .catch(function() {
        resultDiv.style.display = 'block';
        resultDiv.innerHTML = '<div class="alert alert-danger small py-2 px-3 mb-0"><i class="bi bi-x-circle me-1"></i> Request failed</div>';
    })
    .finally(function() {
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-plug me-1"></i> Test Connection';
    });
}

function testBorgbaseApiFromWizard() {
    var btn = document.getElementById('bbApiTestBtn');
    var resultDiv = document.getElementById('bbApiTestResult');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Checking...';
    resultDiv.style.display = 'none';

    var formData = new URLSearchParams();
    formData.append('csrf_token', document.querySelector('#borgbaseWizardForm [name=csrf_token]').value);
    formData.append('remote_user', document.getElementById('bbFieldUser').value);
    formData.append('borgbase_repo_name', document.getElementById('bbRepoName').value.trim());
    formData.append('borgbase_api_key', document.getElementById('bbApiKey').value);

    fetch('/remote-ssh-configs/borgbase-api-test', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: formData.toString()
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        resultDiv.style.display = 'block';
        if (data.status === 'ok') {
            var repo = data.repo || {};
            resultDiv.innerHTML = '<div class="alert alert-success small py-2 px-3 mb-0"><i class="bi bi-check-circle me-1"></i> Verified — quota ' + escapeHtml(String(repo.quota_gb)) + ' GB, current usage ' + escapeHtml(String(repo.current_usage_mb)) + ' MB</div>';
        } else {
            resultDiv.innerHTML = '<div class="alert alert-danger small py-2 px-3 mb-0"><i class="bi bi-x-circle me-1"></i> ' + escapeHtml(data.error || 'BorgBase API check failed') + '</div>';
        }
    })
    .catch(function() {
        resultDiv.style.display = 'block';
        resultDiv.innerHTML = '<div class="alert alert-danger small py-2 px-3 mb-0"><i class="bi bi-x-circle me-1"></i> Request failed</div>';
    })
    .finally(function() {
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-shield-check me-1"></i> Verify API';
        updateBbSubmit();
    });
}

// ---- Hetzner Storage Box wizard ----
var hzTestPassed = false;
var hzNameUserEdited = false;

function updateHzParsedDetails() {
    var host = document.getElementById('hzHostname').value.trim();
    var user = document.getElementById('hzUsername').value.trim();
    var borg = document.getElementById('hzBorgVersion').value;
    var detailsEl = document.getElementById('hzParsedDetails');

    if (host && user) {
        document.getElementById('hzParsedHost').textContent = host;
        document.getElementById('hzParsedUser').textContent = user;
        document.getElementById('hzParsedBorg').textContent = borg;
        detailsEl.style.display = 'block';
    } else {
        detailsEl.style.display = 'none';
    }
}

function updateHzSubmit() {
    var host = document.getElementById('hzHostname').value.trim();
    var user = document.getElementById('hzUsername').value.trim();
    var key = document.getElementById('hzSshKey').value.trim();
    var name = document.getElementById('hzName').value.trim();
    var canTest = !!(host && user && key);
    document.getElementById('hzTestBtn').disabled = !canTest;
    if (!hzTestPassed) {
        document.getElementById('hzSubmitBtn').style.display = 'none';
        document.getElementById('hzTestResult').style.display = 'none';
    } else {
        document.getElementById('hzSubmitBtn').style.display = name ? '' : 'none';
    }
}

function updateHzName() {
    if (!hzNameUserEdited) {
        var user = document.getElementById('hzUsername').value.trim();
        if (user) {
            document.getElementById('hzName').value = 'Hetzner - ' + user;
        }
    }
}

document.getElementById('hzHostname').addEventListener('input', function() {
    hzTestPassed = false;
    updateHzParsedDetails();
    updateHzName();
    updateHzSubmit();
});
document.getElementById('hzUsername').addEventListener('input', function() {
    hzTestPassed = false;
    updateHzParsedDetails();
    updateHzName();
    updateHzSubmit();
});
document.getElementById('hzSshKey').addEventListener('input', function() {
    hzTestPassed = false;
    updateHzSubmit();
});
document.getElementById('hzBorgVersion').addEventListener('change', function() {
    hzTestPassed = false;
    updateHzParsedDetails();
    updateHzSubmit();
});
document.getElementById('hzName').addEventListener('input', function() {
    hzNameUserEdited = true;
    updateHzSubmit();
});

function testHetznerConnection() {
    var btn = document.getElementById('hzTestBtn');
    var resultDiv = document.getElementById('hzTestResult');
    var submitBtn = document.getElementById('hzSubmitBtn');
    var nameField = document.getElementById('hzName');

    hzTestPassed = false;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Testing...';
    resultDiv.style.display = 'none';
    submitBtn.style.display = 'none';

    var formData = new URLSearchParams();
    formData.append('csrf_token', document.querySelector('#hetznerWizardForm [name=csrf_token]').value);
    formData.append('remote_host', document.getElementById('hzHostname').value.trim());
    formData.append('remote_port', '23');
    formData.append('remote_user', document.getElementById('hzUsername').value.trim());
    formData.append('ssh_private_key', document.getElementById('hzSshKey').value);
    formData.append('borg_remote_path', document.getElementById('hzBorgVersion').value);

    fetch('/remote-ssh-configs/test-new', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: formData.toString()
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        resultDiv.style.display = 'block';
        if (data.status === 'ok') {
            hzTestPassed = true;
            resultDiv.innerHTML = '<div class="alert alert-success small py-2 px-3 mb-0"><i class="bi bi-check-circle me-1"></i> Connected — ' + (data.version || 'borg detected').replace(/</g, '&lt;') + '</div>';
            if (nameField.value.trim()) {
                submitBtn.style.display = '';
            }
        } else {
            resultDiv.innerHTML = '<div class="alert alert-danger small py-2 px-3 mb-0"><i class="bi bi-x-circle me-1"></i> ' + (data.error || 'Connection failed').replace(/</g, '&lt;') + '</div>';
        }
    })
    .catch(function() {
        resultDiv.style.display = 'block';
        resultDiv.innerHTML = '<div class="alert alert-danger small py-2 px-3 mb-0"><i class="bi bi-x-circle me-1"></i> Request failed</div>';
    })
    .finally(function() {
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-plug me-1"></i> Test Connection';
    });
}

// ---- rsync.net wizard ----
var rsnTestPassed = false;
var rsnNameUserEdited = false;

function updateRsnParsedDetails() {
    var host = document.getElementById('rsnHostname').value.trim();
    var user = document.getElementById('rsnUsername').value.trim();
    var borg = document.getElementById('rsnBorgVersion').value;
    var detailsEl = document.getElementById('rsnParsedDetails');

    if (host && user) {
        document.getElementById('rsnParsedHost').textContent = host;
        document.getElementById('rsnParsedUser').textContent = user;
        document.getElementById('rsnParsedBorg').textContent = borg;
        detailsEl.style.display = 'block';
    } else {
        detailsEl.style.display = 'none';
    }
}

function updateRsnScpExample() {
    var user = document.getElementById('rsnUsername').value.trim();
    var host = document.getElementById('rsnHostname').value.trim();
    document.getElementById('rsnScpUser').textContent = user || 'USERNAME';
    document.getElementById('rsnScpHost').textContent = host || 'USERNAME.rsync.net';
}

function updateRsnSubmit() {
    var host = document.getElementById('rsnHostname').value.trim();
    var user = document.getElementById('rsnUsername').value.trim();
    var key = document.getElementById('rsnSshKey').value.trim();
    var name = document.getElementById('rsnName').value.trim();
    var canTest = !!(host && user && key);
    document.getElementById('rsnTestBtn').disabled = !canTest;
    if (!rsnTestPassed) {
        document.getElementById('rsnSubmitBtn').style.display = 'none';
        document.getElementById('rsnTestResult').style.display = 'none';
    } else {
        document.getElementById('rsnSubmitBtn').style.display = name ? '' : 'none';
    }
}

function updateRsnName() {
    if (!rsnNameUserEdited) {
        var user = document.getElementById('rsnUsername').value.trim();
        if (user) {
            document.getElementById('rsnName').value = 'rsync.net - ' + user;
        }
    }
}

document.getElementById('rsnUsername').addEventListener('input', function() {
    rsnTestPassed = false;
    updateRsnParsedDetails();
    updateRsnScpExample();
    updateRsnName();
    updateRsnSubmit();
});
document.getElementById('rsnHostname').addEventListener('input', function() {
    rsnTestPassed = false;
    updateRsnParsedDetails();
    updateRsnScpExample();
    updateRsnSubmit();
});
document.getElementById('rsnSshKey').addEventListener('input', function() {
    rsnTestPassed = false;
    updateRsnSubmit();
});
document.getElementById('rsnBorgVersion').addEventListener('change', function() {
    rsnTestPassed = false;
    updateRsnParsedDetails();
    updateRsnSubmit();
});
document.getElementById('rsnName').addEventListener('input', function() {
    rsnNameUserEdited = true;
    updateRsnSubmit();
});

function testRsyncnetConnection() {
    var btn = document.getElementById('rsnTestBtn');
    var resultDiv = document.getElementById('rsnTestResult');
    var submitBtn = document.getElementById('rsnSubmitBtn');
    var nameField = document.getElementById('rsnName');

    rsnTestPassed = false;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Testing...';
    resultDiv.style.display = 'none';
    submitBtn.style.display = 'none';

    var formData = new URLSearchParams();
    formData.append('csrf_token', document.querySelector('#rsyncnetWizardForm [name=csrf_token]').value);
    formData.append('remote_host', document.getElementById('rsnHostname').value.trim());
    formData.append('remote_port', '22');
    formData.append('remote_user', document.getElementById('rsnUsername').value.trim());
    formData.append('ssh_private_key', document.getElementById('rsnSshKey').value);
    formData.append('borg_remote_path', document.getElementById('rsnBorgVersion').value);

    fetch('/remote-ssh-configs/test-new', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: formData.toString()
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        resultDiv.style.display = 'block';
        if (data.status === 'ok') {
            rsnTestPassed = true;
            resultDiv.innerHTML = '<div class="alert alert-success small py-2 px-3 mb-0"><i class="bi bi-check-circle me-1"></i> Connected — ' + (data.version || 'borg detected').replace(/</g, '&lt;') + '</div>';
            if (nameField.value.trim()) {
                submitBtn.style.display = '';
            }
        } else {
            resultDiv.innerHTML = '<div class="alert alert-danger small py-2 px-3 mb-0"><i class="bi bi-x-circle me-1"></i> ' + (data.error || 'Connection failed').replace(/</g, '&lt;') + '</div>';
        }
    })
    .catch(function() {
        resultDiv.style.display = 'block';
        resultDiv.innerHTML = '<div class="alert alert-danger small py-2 px-3 mb-0"><i class="bi bi-x-circle me-1"></i> Request failed</div>';
    })
    .finally(function() {
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-plug me-1"></i> Test Connection';
    });
}

function applyRemotePreset(select, form) {
    var preset = select.value;
    var portField = form.querySelector('[name=remote_port]');
    var basePathField = form.querySelector('[name=remote_base_path]');
    var borgPathField = form.querySelector('[name=borg_remote_path]');
    var appendField = form.querySelector('[name=append_repo_name]');
    var providerField = form.querySelector('[name=provider]');
    if (providerField) providerField.value = preset;

    switch (preset) {
        case 'rsync.net':
            if (portField) portField.value = '22';
            if (basePathField) basePathField.value = './';
            if (borgPathField) borgPathField.value = 'borg1';
            if (appendField) appendField.checked = true;
            break;
        case 'borgbase':
            if (portField) portField.value = '22';
            if (basePathField) basePathField.value = './repo';
            if (borgPathField) borgPathField.value = '';
            if (appendField) appendField.checked = false;
            break;
        case 'hetzner':
            if (portField) portField.value = '23';
            if (basePathField) basePathField.value = './';
            if (borgPathField) borgPathField.value = 'borg-1.4';
            if (appendField) appendField.checked = true;
            break;
    }
}
</script>

<?php else: ?>
<!-- ==================== Storage Overview (main page) ==================== -->

<!-- Local Storage Locations -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header fw-semibold d-flex justify-content-between align-items-center">
        <span><i class="bi bi-hdd me-2"></i>Local Storage</span>
        <button class="btn btn-sm btn-success" data-bs-toggle="collapse" data-bs-target="#addLocationForm">
            <i class="bi bi-plus-circle me-1"></i> Add Location
        </button>
    </div>
    <div class="card-body">
        <!-- Add Location Form -->
        <div class="collapse mb-3" id="addLocationForm">
            <div class="card border bg-body-tertiary">
                <div class="card-body">
                    <form method="POST" action="/storage-locations">
                        <input type="hidden" name="csrf_token" value="<?= $this->csrfToken() ?>">
                        <div class="row g-3">
                            <div class="col-md-3">
                                <label class="form-label fw-semibold">Label</label>
                                <input type="text" class="form-control" name="label" placeholder="e.g. Secondary Disk" required>
                            </div>
                            <div class="col-md-5">
                                <label class="form-label fw-semibold">Path</label>
                                <input type="text" class="form-control" name="path" placeholder="/mnt/storage2" required>
                                <div class="form-text">Absolute path to the storage directory. Must exist and be writable.</div>
                            </div>
                            <div class="col-md-2 d-flex align-items-center pt-4">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="is_default" id="newIsDefault">
                                    <label class="form-check-label" for="newIsDefault">Default</label>
                                </div>
                            </div>
                            <div class="col-md-2 d-flex align-items-end">
                                <button type="submit" class="btn btn-sm btn-success w-100">Create</button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <?php if (empty($locations)): ?>
        <div class="alert alert-info mb-0">No storage locations configured. Click "Add Location" above to get started.</div>
        <?php else: ?>
        <div class="row g-3">
    <?php foreach ($locations as $loc): ?>
    <div class="col-xl-4 col-lg-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-start mb-2">
                    <div>
                        <h6 class="mb-1">
                            <?= htmlspecialchars($loc['label']) ?>
                            <?php if ($loc['is_default']): ?>
                            <span class="badge bg-primary ms-1">Default</span>
                            <?php endif; ?>
                        </h6>
                        <code class="small text-muted"><?= htmlspecialchars($loc['path']) ?></code>
                    </div>
                    <?php if (!$loc['is_default'] && $loc['repo_count'] === 0): ?>
                    <form method="POST" action="/storage-locations/<?= $loc['id'] ?>/delete"
                          onsubmit="return confirm('Delete storage location \'<?= htmlspecialchars($loc['label'], ENT_QUOTES) ?>\'?')">
                        <input type="hidden" name="csrf_token" value="<?= $this->csrfToken() ?>">
                        <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete">
                            <i class="bi bi-trash"></i>
                        </button>
                    </form>
                    <?php endif; ?>
                </div>

                <!-- Disk Usage -->
                <?php if ($loc['disk_total'] > 0): ?>
                <div class="mb-2">
                    <div class="d-flex justify-content-between small text-muted mb-1">
                        <span><?= formatStorageBytes($loc['disk_used']) ?> used</span>
                        <span><?= formatStorageBytes($loc['disk_free']) ?> free</span>
                    </div>
                    <div class="progress" style="height: 6px;">
                        <?php
                        $pct = $loc['disk_percent'];
                        $barColor = $pct >= 90 ? 'danger' : ($pct >= 75 ? 'warning' : 'success');
                        ?>
                        <div class="progress-bar bg-<?= $barColor ?>" style="width: <?= $pct ?>%"></div>
                    </div>
                    <div class="text-muted small mt-1">
                        <?= formatStorageBytes($loc['disk_total']) ?> total &middot; <?= $pct ?>% used
                    </div>
                </div>
                <?php else: ?>
                <div class="text-muted small mb-2"><i class="bi bi-exclamation-triangle me-1"></i>Path not accessible</div>
                <?php endif; ?>

                <!-- Stats -->
                <div class="d-flex gap-3 small text-muted">
                    <span><i class="bi bi-archive me-1"></i><?= $loc['repo_count'] ?> repo<?= $loc['repo_count'] !== 1 ? 's' : '' ?></span>
                    <span><i class="bi bi-database me-1"></i><?= formatStorageBytes($loc['total_size']) ?></span>
                </div>
            </div>

        </div>
    </div>
    <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Remote Storage (SSH) -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header fw-semibold d-flex justify-content-between align-items-center">
        <span><i class="bi bi-hdd-network me-2"></i>Remote Storage (SSH)</span>
        <a href="/storage-locations?section=wizard" class="btn btn-sm btn-success">
            <i class="bi bi-plus-circle me-1"></i> Add SSH Host
        </a>
    </div>
    <div class="card-body">

<?php if (empty($remoteSshConfigs)): ?>
<div class="alert alert-info mb-0">No remote SSH hosts configured. <a href="/storage-locations?section=wizard">Add one</a> to get started.</div>
<?php else: ?>
<div class="row g-3">
    <?php foreach ($remoteSshConfigs as $rsc): ?>
    <?php $isBorgBase = (($rsc['provider'] ?? '') === 'borgbase') || str_contains((string)($rsc['remote_host'] ?? ''), '.repo.borgbase.com'); ?>
    <div class="col-xl-4 col-lg-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="d-flex align-items-start gap-2 mb-2">
                    <div class="flex-shrink-0 mt-1" style="font-size: 1.4rem;">
                        <?php if ($isBorgBase): ?>
                        <img src="/images/borgbase.svg" alt="" style="width:24px;height:24px;border-radius:50%">
                        <?php elseif (($rsc['provider'] ?? '') === 'hetzner'): ?>
                        <img src="/images/hetzner-h.png" alt="" style="width:24px;height:24px;border-radius:50%">
                        <?php elseif (($rsc['provider'] ?? '') === 'rsyncnet'): ?>
                        <img src="/images/rsyncnet-logo.png" alt="" style="width:24px;height:24px;border-radius:50%">
                        <?php else: ?>
                        <i class="bi bi-server text-primary opacity-75"></i>
                        <?php endif; ?>
                    </div>
                    <div class="flex-grow-1" style="min-width: 0;">
                        <h6 class="mb-0"><?= htmlspecialchars($rsc['name']) ?></h6>
                        <code class="small text-muted text-truncate d-block" style="max-width: 100%;"><?= htmlspecialchars($rsc['remote_user']) ?>@<?= htmlspecialchars($rsc['remote_host']) ?><?= (int)$rsc['remote_port'] !== 22 ? ':' . (int)$rsc['remote_port'] : '' ?></code>
                    </div>
                    <div class="dropdown">
                        <button class="btn btn-sm btn-outline-secondary" data-bs-toggle="dropdown">
                            <i class="bi bi-three-dots-vertical"></i>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li>
                                <a class="dropdown-item" href="#" data-bs-toggle="modal" data-bs-target="#editRemoteSshModal<?= $rsc['id'] ?>" onclick="event.preventDefault();">
                                    <i class="bi bi-pencil me-1"></i> Edit
                                </a>
                            </li>
                            <li>
                                <a class="dropdown-item" href="#" onclick="event.preventDefault(); testRemoteSsh(<?= $rsc['id'] ?>, this);">
                                    <i class="bi bi-plug me-1"></i> Test Connection
                                </a>
                            </li>
                            <?php if (($rsc['repo_count'] ?? 0) === 0): ?>
                            <li><hr class="dropdown-divider"></li>
                            <li>
                                <a class="dropdown-item text-danger" href="#" onclick="event.preventDefault(); deleteRemoteSsh(<?= $rsc['id'] ?>, '<?= htmlspecialchars(addslashes($rsc['name'])) ?>');">
                                    <i class="bi bi-trash me-1"></i> Delete
                                </a>
                            </li>
                            <?php endif; ?>
                        </ul>
                    </div>
                </div>
                <div class="d-flex gap-3 small text-muted">
                    <span><i class="bi bi-archive me-1"></i><?= $rsc['repo_count'] ?> repo<?= $rsc['repo_count'] !== 1 ? 's' : '' ?></span>
                    <span class="badge bg-success"><i class="bi bi-check-circle me-1"></i>Active</span>
                </div>
                <?php
                $hasDiskData = !empty($rsc['disk_total_bytes']) && (int)$rsc['disk_total_bytes'] > 0;
                $wasChecked = !empty($rsc['disk_checked_at']);
                ?>
                <?php if ($hasDiskData): ?>
                <div class="mt-2">
                    <?php
                    $rscTotal = (int)$rsc['disk_total_bytes'];
                    $rscUsed = (int)$rsc['disk_used_bytes'];
                    $rscFree = (int)$rsc['disk_free_bytes'];
                    $rscPct = $rscTotal > 0 ? round(($rscUsed / $rscTotal) * 100, 1) : 0;
                    $rscBarColor = $rscPct >= 90 ? 'danger' : ($rscPct >= 75 ? 'warning' : 'success');
                    $useDecimalRemoteBytes = $isBorgBase && ($rsc['borgbase_usage_source'] ?? '') === 'borgbase_api';
                    $formatRemoteBytes = fn(int $bytes): string => $useDecimalRemoteBytes
                        ? formatStorageBytesDecimal($bytes)
                        : \BBS\Services\ServerStats::formatBytes($bytes);
                    ?>
                    <div class="d-flex justify-content-between small text-muted mb-1">
                        <span><?= $formatRemoteBytes($rscUsed) ?> used</span>
                        <span><?= $formatRemoteBytes($rscFree) ?> free</span>
                    </div>
                    <div class="progress" style="height: 6px;">
                        <div class="progress-bar bg-<?= $rscBarColor ?>" style="width: <?= $rscPct ?>%"></div>
                    </div>
                    <div class="text-muted small mt-1"><?= $formatRemoteBytes($rscTotal) ?> total &middot; <?= $rscPct ?>% used &middot; checked <?= \BBS\Core\TimeHelper::ago($rsc['disk_checked_at']) ?></div>
                    <?php if ($isBorgBase && !empty($rsc['borgbase_usage_source'])): ?>
                    <div class="text-muted small mt-1">Source: <?= $rsc['borgbase_usage_source'] === 'borgbase_api' ? 'BorgBase API' : 'manual quota' ?></div>
                    <?php endif; ?>
                </div>
                <?php elseif ($wasChecked): ?>
                <div class="mt-2 small text-muted"><i class="bi bi-exclamation-triangle me-1"></i><?= $isBorgBase ? 'Set quota manually or use API' : 'Quota unavailable — provider does not support disk usage queries' ?></div>
                <?php endif; ?>
                <div id="remoteSshTestResult<?= $rsc['id'] ?>" class="mt-2"></div>
            </div>
        </div>
    </div>

    <!-- Edit Modal -->
    <div class="modal fade" id="editRemoteSshModal<?= $rsc['id'] ?>" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST" action="/remote-ssh-configs/<?= $rsc['id'] ?>/update">
                    <input type="hidden" name="csrf_token" value="<?= $this->csrfToken() ?>">
                    <div class="modal-header">
                        <h5 class="modal-title">Edit Remote SSH Host</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Name</label>
                            <input type="text" class="form-control" name="name" value="<?= htmlspecialchars($rsc['name']) ?>" required>
                        </div>
                        <div class="row g-3 mb-3">
                            <div class="col-8">
                                <label class="form-label fw-semibold">Host</label>
                                <input type="text" class="form-control" name="remote_host" value="<?= htmlspecialchars($rsc['remote_host']) ?>" required>
                            </div>
                            <div class="col-4">
                                <label class="form-label fw-semibold">Port</label>
                                <input type="number" class="form-control" name="remote_port" value="<?= (int)$rsc['remote_port'] ?>" min="1" max="65535">
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Username</label>
                            <input type="text" class="form-control" name="remote_user" value="<?= htmlspecialchars($rsc['remote_user']) ?>" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Base Path</label>
                            <input type="text" class="form-control" name="remote_base_path" value="<?= htmlspecialchars($rsc['remote_base_path']) ?>">
                            <div class="form-text">Base directory on the remote host. Use <code>./</code> for relative paths (rsync.net default).</div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">SSH Private Key</label>
                            <textarea class="form-control font-monospace" name="ssh_private_key" rows="4" placeholder="Leave blank to keep existing key"></textarea>
                            <div class="form-text">Paste the private key (PEM format). Leave blank to keep the current key.</div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Remote Borg Path <span class="text-muted fw-normal">(optional)</span></label>
                            <input type="text" class="form-control" name="borg_remote_path" value="<?= htmlspecialchars($rsc['borg_remote_path'] ?? '') ?>">
                            <div class="form-text">Custom borg binary on the remote host (e.g., <code>borg1</code> for rsync.net). Leave blank for default <code>borg</code>.</div>
                        </div>
                        <div class="form-check mb-3">
                            <input class="form-check-input" type="checkbox" name="append_repo_name" value="1" id="editAppendRepoName<?= $rsc['id'] ?>" <?= ($rsc['append_repo_name'] ?? 1) ? 'checked' : '' ?>>
                            <label class="form-check-label" for="editAppendRepoName<?= $rsc['id'] ?>">Append repository name to base path</label>
                            <div class="form-text">Uncheck for providers like BorgBase where each SSH user maps to a single fixed repo path.</div>
                        </div>
                        <?php if ($isBorgBase): ?>
                        <hr>
                        <div class="border rounded p-3 mb-3">
                            <div class="fw-semibold mb-2">Manual Quota</div>
                            <label class="form-label fw-semibold">Quota <span class="text-muted fw-normal">(GB, optional)</span></label>
                            <input type="number" class="form-control" name="borgbase_manual_quota_gb" min="0" step="0.001" value="<?= htmlspecialchars((string)($rsc['borgbase_manual_quota_gb'] ?? '')) ?>">
                            <div class="form-text">Use this when you do not want to connect the BorgBase API.</div>
                        </div>
                        <div class="border rounded p-3 mb-3">
                            <div class="fw-semibold mb-2">BorgBase API</div>
                            <div class="mb-3">
                                <label class="form-label fw-semibold" for="editBbApiKey<?= $rsc['id'] ?>">API Key <span class="text-muted fw-normal">(optional)</span></label>
                                <input type="password" class="form-control" name="borgbase_api_key" id="editBbApiKey<?= $rsc['id'] ?>" autocomplete="off" data-has-saved-key="<?= !empty($rsc['borgbase_api_key_encrypted']) ? '1' : '0' ?>" placeholder="<?= !empty($rsc['borgbase_api_key_encrypted']) ? 'Leave blank to keep existing key' : 'Paste API key' ?>" oninput="updateEditBorgbaseApiRequirement(<?= $rsc['id'] ?>)">
                            </div>
                            <div class="mb-3">
                                <label class="form-label fw-semibold" for="editBbRepoName<?= $rsc['id'] ?>">BorgBase Repo Name <span class="text-danger d-none" id="editBbRepoNameRequired<?= $rsc['id'] ?>">*</span></label>
                                <input type="text" class="form-control" name="borgbase_repo_name" id="editBbRepoName<?= $rsc['id'] ?>" value="<?= htmlspecialchars($rsc['borgbase_repo_name'] ?? '') ?>" oninput="updateEditBorgbaseApiRequirement(<?= $rsc['id'] ?>)">
                                <div class="invalid-feedback">Repository name is required when an API key is provided.</div>
                            </div>
                            <button type="button" class="btn btn-sm btn-outline-primary" onclick="testBorgbaseApiExisting(<?= $rsc['id'] ?>)">
                                <i class="bi bi-shield-check me-1"></i> Verify API
                            </button>
                            <?php if (!empty($rsc['borgbase_api_key_encrypted'])): ?>
                            <div class="form-check mt-2">
                                <input class="form-check-input" type="checkbox" name="borgbase_clear_api_key" value="1" id="clearBbApi<?= $rsc['id'] ?>">
                                <label class="form-check-label" for="clearBbApi<?= $rsc['id'] ?>">Remove saved API key</label>
                            </div>
                            <?php endif; ?>
                            <div class="form-text mt-2">The key is encrypted before storage and never shown again.</div>
                            <div id="editBbApiTestResult<?= $rsc['id'] ?>" style="display:none" class="mt-2"></div>
                        </div>
                        <?php endif; ?>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Save Changes</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>
    </div>
</div>

<!-- S3 Offsite Sync -->
<?php
$s3Configured = !empty($settings['s3_endpoint']) && !empty($settings['s3_bucket']);
$s3SyncServerBackups = ($settings['s3_sync_server_backups'] ?? '0') === '1';
?>
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header fw-semibold">
        <i class="bi bi-bucket me-2"></i>S3 Offsite Sync
    </div>
    <div class="card-body">

<?php if (!$s3Configured): ?>
<div class="alert alert-info mb-0">S3 offsite sync is not configured. <a href="/storage-locations?section=s3">Configure it</a> to replicate local repos to S3-compatible storage.</div>
<?php else: ?>
<div class="row g-3">
    <div class="col-xl-4 col-lg-6">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-start mb-2">
                    <h6 class="mb-0"><i class="bi bi-cloud-arrow-up me-1 text-primary"></i> Global S3</h6>
                    <div class="dropdown">
                        <button class="btn btn-sm btn-outline-secondary" data-bs-toggle="dropdown">
                            <i class="bi bi-three-dots-vertical"></i>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li>
                                <a class="dropdown-item" href="/storage-locations?section=s3">
                                    <i class="bi bi-gear me-1"></i> Settings
                                </a>
                            </li>
                        </ul>
                    </div>
                </div>
                <div class="row g-1 small mb-2">
                    <div class="col-5 text-muted">Endpoint</div>
                    <div class="col-7 text-truncate"><?= htmlspecialchars($settings['s3_endpoint'] ?? '') ?></div>
                    <div class="col-5 text-muted">Bucket</div>
                    <div class="col-7"><?= htmlspecialchars($settings['s3_bucket'] ?? '') ?></div>
                    <?php if (!empty($settings['s3_region'])): ?>
                    <div class="col-5 text-muted">Region</div>
                    <div class="col-7"><?= htmlspecialchars($settings['s3_region']) ?></div>
                    <?php endif; ?>
                    <div class="col-5 text-muted">Server Sync</div>
                    <div class="col-7">
                        <?php if ($s3SyncServerBackups): ?>
                        <span class="badge bg-success">Enabled</span>
                        <?php else: ?>
                        <span class="badge bg-secondary">Disabled</span>
                        <?php endif; ?>
                    </div>
                </div>
                <span class="badge bg-success"><i class="bi bi-check-circle me-1"></i>Configured</span>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>
    </div>
</div>

<script>
function testRemoteSsh(id, triggerEl) {
    var resultDiv = document.getElementById('remoteSshTestResult' + id);
    resultDiv.innerHTML = '<span class="badge bg-secondary"><span class="spinner-border spinner-border-sm me-1" style="width:.7rem;height:.7rem"></span>Testing...</span>';

    var csrfToken = document.querySelector('input[name=csrf_token]').value;
    fetch('/remote-ssh-configs/' + id + '/test', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'csrf_token=' + encodeURIComponent(csrfToken)
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (data.status === 'ok') {
            resultDiv.innerHTML = '<span class="badge bg-success"><i class="bi bi-check-circle me-1"></i>Connected' + (data.version ? ' — ' + data.version.replace(/</g, '&lt;') : '') + '</span>';
        } else {
            resultDiv.innerHTML = '<span class="badge bg-danger"><i class="bi bi-x-circle me-1"></i>' + (data.error || 'Failed').replace(/</g, '&lt;') + '</span>';
        }
    })
    .catch(function() {
        resultDiv.innerHTML = '<span class="badge bg-danger"><i class="bi bi-x-circle me-1"></i>Request failed</span>';
    });
}

function testBorgbaseApiExisting(id) {
    var resultDiv = document.getElementById('editBbApiTestResult' + id);
    var repoInput = document.getElementById('editBbRepoName' + id);
    var apiInput = document.getElementById('editBbApiKey' + id);
    var modal = document.getElementById('editRemoteSshModal' + id);
    var userInput = modal ? modal.querySelector('[name=remote_user]') : null;
    var csrfInput = modal ? modal.querySelector('[name=csrf_token]') : null;
    var fallbackCsrfInput = document.querySelector('input[name=csrf_token]');
    var apiKey = apiInput ? apiInput.value.trim() : '';
    var repoName = repoInput ? repoInput.value.trim() : '';
    var hasSavedKey = apiInput && apiInput.dataset.hasSavedKey === '1';

    if (!apiKey && !hasSavedKey) {
        resultDiv.style.display = 'block';
        resultDiv.innerHTML = '<div class="alert alert-danger small py-2 px-3 mb-0"><i class="bi bi-x-circle me-1"></i> Enter an API key to verify it before saving.</div>';
        return;
    }
    if (!repoName) {
        resultDiv.style.display = 'block';
        resultDiv.innerHTML = '<div class="alert alert-danger small py-2 px-3 mb-0"><i class="bi bi-x-circle me-1"></i> Enter a BorgBase repository name to verify the API key.</div>';
        updateEditBorgbaseApiRequirement(id);
        return;
    }

    resultDiv.style.display = 'block';
    resultDiv.innerHTML = '<div class="alert alert-secondary small py-2 px-3 mb-0"><span class="spinner-border spinner-border-sm me-1"></span>Checking BorgBase API...</div>';

    var formData = new URLSearchParams();
    formData.append('csrf_token', csrfInput ? csrfInput.value : (fallbackCsrfInput ? fallbackCsrfInput.value : ''));
    formData.append('config_id', String(id));
    formData.append('remote_user', userInput ? userInput.value.trim() : '');
    formData.append('borgbase_repo_name', repoName);
    formData.append('borgbase_api_key', apiKey);

    fetch('/remote-ssh-configs/borgbase-api-test', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: formData.toString()
    })
    .then(function(r) {
        return r.text().then(function(text) {
            try {
                return JSON.parse(text || '{}');
            } catch (e) {
                throw new Error(text ? text.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 180) : 'Invalid server response');
            }
        });
    })
    .then(function(data) {
        if (data.status === 'ok') {
            var repo = data.repo || {};
            resultDiv.innerHTML = '<div class="alert alert-success small py-2 px-3 mb-0"><i class="bi bi-check-circle me-1"></i>Verified - quota ' + escapeHtml(String(repo.quota_gb)) + ' GB, current usage ' + escapeHtml(String(repo.current_usage_mb)) + ' MB</div>';
        } else {
            resultDiv.innerHTML = '<div class="alert alert-danger small py-2 px-3 mb-0"><i class="bi bi-x-circle me-1"></i> ' + escapeHtml(data.error || 'BorgBase API check failed') + '</div>';
        }
    })
    .catch(function(error) {
        resultDiv.innerHTML = '<div class="alert alert-danger small py-2 px-3 mb-0"><i class="bi bi-x-circle me-1"></i> ' + escapeHtml(error && error.message ? error.message : 'Request failed') + '</div>';
    });
}

function updateEditBorgbaseApiRequirement(id) {
    var repoInput = document.getElementById('editBbRepoName' + id);
    var apiInput = document.getElementById('editBbApiKey' + id);
    var requiredMark = document.getElementById('editBbRepoNameRequired' + id);
    if (!repoInput || !apiInput || !requiredMark) return;
    var apiPresent = apiInput.value.trim() !== '';
    var repoMissing = repoInput.value.trim() === '';
    repoInput.required = apiPresent;
    repoInput.classList.toggle('is-invalid', apiPresent && repoMissing);
    requiredMark.classList.toggle('d-none', !apiPresent);
}

function deleteRemoteSsh(id, name) {
    if (!confirm('Delete remote SSH host "' + name + '"?')) return;
    var form = document.createElement('form');
    form.method = 'POST';
    form.action = '/remote-ssh-configs/' + id + '/delete';
    var csrf = document.createElement('input');
    csrf.type = 'hidden';
    csrf.name = 'csrf_token';
    csrf.value = document.querySelector('input[name=csrf_token]').value;
    form.appendChild(csrf);
    document.body.appendChild(form);
    form.submit();
}
</script>

<?php endif; ?>
