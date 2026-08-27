<?php

namespace BBS\Controllers;

use BBS\Core\Controller;
use BBS\Services\ServerStats;

class StorageLocationController extends Controller
{
    public function index(): void
    {
        $this->denyIfHosted();
        $this->requireAdmin();

        $locations = $this->db->fetchAll("SELECT * FROM storage_locations ORDER BY is_default DESC, label");

        // Attach repo counts and disk usage to each location
        foreach ($locations as &$loc) {
            $loc['repo_count'] = (int) ($this->db->fetchOne(
                "SELECT COUNT(*) as cnt FROM repositories WHERE storage_location_id = ?",
                [$loc['id']]
            )['cnt'] ?? 0);

            $loc['total_size'] = (int) ($this->db->fetchOne(
                "SELECT COALESCE(SUM(size_bytes), 0) as total FROM repositories WHERE storage_location_id = ?",
                [$loc['id']]
            )['total'] ?? 0);

            // capacityForLocation() prefers a stated capacity and refuses to
            // repeat the local cache disk's figures for a mount that cannot
            // report its own size (#415).
            $capacity = ServerStats::capacityForLocation($loc);
            $loc['disk_total'] = $capacity['total'] ?? 0;
            $loc['disk_used'] = $capacity['used'] ?? 0;
            $loc['disk_free'] = $capacity['free'] ?? 0;
            $loc['disk_percent'] = $capacity['percent'] ?? 0;
            $loc['capacity_source'] = $capacity['source'] ?? null;
            $loc['capacity_unknown_reason'] = $capacity === null
                ? ServerStats::capacityUnknownReason($loc)
                : null;
        }
        unset($loc);

        // Remote SSH configs
        $remoteSshService = new \BBS\Services\RemoteSshService();
        $remoteSshConfigs = $remoteSshService->getAll();
        $remoteRepoCount = (int) ($this->db->fetchOne("SELECT COUNT(*) as cnt FROM repositories WHERE storage_type = 'remote_ssh'")['cnt'] ?? 0);

        // Attach repo counts to each remote SSH config
        foreach ($remoteSshConfigs as &$rsc) {
            $rsc['repo_count'] = (int) ($this->db->fetchOne(
                "SELECT COUNT(*) as cnt FROM repositories WHERE remote_ssh_config_id = ?",
                [$rsc['id']]
            )['cnt'] ?? 0);
        }
        unset($rsc);

        // All settings (for S3 config form, storage_path, etc.)
        $settingsRows = $this->db->fetchAll("SELECT `key`, `value` FROM settings");
        $settings = [];
        foreach ($settingsRows as $row) {
            $settings[$row['key']] = $row['value'];
        }

        // Local repo count
        $localRepoCount = (int) ($this->db->fetchOne("SELECT COUNT(*) as cnt FROM repositories WHERE storage_type = 'local' OR storage_type IS NULL")['cnt'] ?? 0);

        // Named S3 destination configs across all clients — repos can
        // replicate to several of these (#263). Listed on the S3 Offsite
        // Sync card so multi-destination setups are visible in one place.
        $s3Destinations = $this->db->fetchAll("
            SELECT pc.id, pc.name, pc.config, pc.agent_id, a.name AS agent_name,
                   (SELECT COUNT(*) FROM repository_s3_configs rsc WHERE rsc.plugin_config_id = pc.id) AS repo_count
            FROM plugin_configs pc
            JOIN plugins p ON p.id = pc.plugin_id
            JOIN agents a ON a.id = pc.agent_id
            WHERE p.slug = 's3_sync'
            ORDER BY a.name, pc.name
        ");

        $this->view('storage-locations/index', [
            'pageTitle' => 'Storage',
            'locations' => $locations,
            'remoteSshConfigs' => $remoteSshConfigs,
            'remoteRepoCount' => $remoteRepoCount,
            'localRepoCount' => $localRepoCount,
            'settings' => $settings,
            's3Destinations' => $s3Destinations,
        ]);
    }

    public function store(): void
    {
        $this->denyIfHosted();
        $this->requireAdmin();
        $this->verifyCsrf();

        $label = trim($_POST['label'] ?? '');
        $path = rtrim(trim($_POST['path'] ?? ''), '/');
        $isDefault = !empty($_POST['is_default']) ? 1 : 0;
        $capacityBytes = $this->capacityFromPost();

        if (empty($label) || empty($path)) {
            $this->flash('danger', 'Label and path are required.');
            $this->redirect('/storage-locations');
        }

        if (!preg_match('#^/#', $path)) {
            $this->flash('danger', 'Path must be an absolute path.');
            $this->redirect('/storage-locations');
        }

        // If marking as default, unset current default
        if ($isDefault) {
            $this->db->query("UPDATE storage_locations SET is_default = 0");
        }

        $this->db->insert('storage_locations', [
            'label' => $label,
            'path' => $path,
            'capacity_bytes' => $capacityBytes,
            'is_default' => $isDefault,
        ]);

        // Update the allowed-storage-paths config file for bbs-ssh-helper
        $this->updateAllowedPaths();

        $this->flash('success', "Storage location \"{$label}\" created.");
        $this->redirect('/storage-locations');
    }

    public function update(int $id): void
    {
        $this->denyIfHosted();
        $this->requireAdmin();
        $this->verifyCsrf();

        $location = $this->db->fetchOne("SELECT * FROM storage_locations WHERE id = ?", [$id]);
        if (!$location) {
            $this->flash('danger', 'Storage location not found.');
            $this->redirect('/storage-locations');
        }

        $label = trim($_POST['label'] ?? '');
        // The edit form doesn't offer the path — repositories live under it, so
        // moving one is a filesystem operation rather than a settings change.
        $path = rtrim(trim($_POST['path'] ?? $location['path']), '/');
        $isDefault = !empty($_POST['is_default']) ? 1 : 0;

        if (empty($label) || empty($path)) {
            $this->flash('danger', 'Label and path are required.');
            $this->redirect('/storage-locations');
        }

        // Don't allow changing path if repos exist (would break them)
        $repoCount = (int) ($this->db->fetchOne(
            "SELECT COUNT(*) as cnt FROM repositories WHERE storage_location_id = ?",
            [$id]
        )['cnt'] ?? 0);

        if ($repoCount > 0 && $path !== $location['path']) {
            $this->flash('danger', 'Cannot change path while repositories exist on this location.');
            $this->redirect('/storage-locations');
        }

        if ($isDefault) {
            $this->db->query("UPDATE storage_locations SET is_default = 0");
        }

        $this->db->update('storage_locations', [
            'label' => $label,
            'path' => $path,
            'capacity_bytes' => $this->capacityFromPost(),
            'is_default' => $isDefault,
        ], 'id = ?', [$id]);

        $this->updateAllowedPaths();

        $this->flash('success', "Storage location \"{$label}\" updated.");
        $this->redirect('/storage-locations');
    }

    public function destroy(int $id): void
    {
        $this->denyIfHosted();
        $this->requireAdmin();
        $this->verifyCsrf();

        $location = $this->db->fetchOne("SELECT * FROM storage_locations WHERE id = ?", [$id]);
        if (!$location) {
            $this->flash('danger', 'Storage location not found.');
            $this->redirect('/storage-locations');
        }

        if ($location['is_default']) {
            $this->flash('danger', 'Cannot delete the default storage location.');
            $this->redirect('/storage-locations');
        }

        $repoCount = (int) ($this->db->fetchOne(
            "SELECT COUNT(*) as cnt FROM repositories WHERE storage_location_id = ?",
            [$id]
        )['cnt'] ?? 0);

        if ($repoCount > 0) {
            $this->flash('danger', 'Cannot delete a storage location that has repositories. Delete or move the repositories first.');
            $this->redirect('/storage-locations');
        }

        $this->db->delete('storage_locations', 'id = ?', [$id]);
        $this->updateAllowedPaths();

        $this->flash('success', "Storage location \"{$location['label']}\" deleted.");
        $this->redirect('/storage-locations');
    }

    /**
     * POST /storage-locations/s3 — save global S3 settings.
     */
    public function saveS3(): void
    {
        $this->denyIfHosted();
        $this->requireAdmin();
        $this->verifyCsrf();

        // Validate endpoint URL if provided
        $endpoint = trim($_POST['s3_endpoint'] ?? '');
        if (!empty($endpoint)) {
            if (!preg_match('#^https?://#i', $endpoint)) {
                $endpoint = 'https://' . $endpoint;
                $_POST['s3_endpoint'] = $endpoint;
            }
            $parsed = parse_url($endpoint);
            if (empty($parsed['host']) || !preg_match('/\.[a-z]{2,}$/i', $parsed['host'])) {
                $this->flash('danger', 'S3 endpoint must be a valid URL (e.g. https://s3.us-east-1.amazonaws.com).');
                $this->redirect('/storage-locations?section=s3');
            }
        }

        $fields = ['s3_endpoint', 's3_region', 's3_bucket', 's3_path_prefix', 's3_storage_class', 's3_sse_mode', 's3_sse_kms_key_id', 's3_bandwidth_limit', 's3_sync_server_backups'];
        foreach ($fields as $key) {
            if (isset($_POST[$key])) {
                $existing = $this->db->fetchOne("SELECT `key` FROM settings WHERE `key` = ?", [$key]);
                if ($existing) {
                    $this->db->update('settings', ['value' => $_POST[$key]], "`key` = ?", [$key]);
                } else {
                    $this->db->insert('settings', ['key' => $key, 'value' => $_POST[$key]]);
                }
            }
        }

        // Encrypt and save sensitive fields only if non-empty (preserve existing otherwise)
        $sensitiveFields = ['s3_access_key', 's3_secret_key'];
        foreach ($sensitiveFields as $key) {
            $value = $_POST[$key] ?? '';
            if (!empty($value)) {
                $encrypted = \BBS\Services\Encryption::encrypt($value);
                $existing = $this->db->fetchOne("SELECT `key` FROM settings WHERE `key` = ?", [$key]);
                if ($existing) {
                    $this->db->update('settings', ['value' => $encrypted], "`key` = ?", [$key]);
                } else {
                    $this->db->insert('settings', ['key' => $key, 'value' => $encrypted]);
                }
            }
        }

        $this->flash('success', 'S3 settings saved.');
        $this->redirect('/storage-locations?section=s3');
    }

    /**
     * POST /storage-locations/s3/test — test S3 connection with saved credentials.
     */
    public function testS3(): void
    {
        $this->denyIfHosted();
        $this->requireAdmin();
        $this->verifyCsrf();

        $s3Service = new \BBS\Services\S3SyncService();
        $creds = $s3Service->resolveCredentials(['credential_source' => 'global']);
        $result = $s3Service->testConnection($creds);

        $this->json($result);
    }

    /**
     * POST /storage-locations/s3/list-backups — list available server backups in S3.
     */
    public function listS3Backups(): void
    {
        $this->denyIfHosted();
        $this->requireAdmin();
        $this->verifyCsrf();

        $this->json((new \BBS\Services\ServerBackupService())->listInS3());
    }

    /**
     * POST /storage-locations/s3/download-backup — stream one server backup
     * from off-site storage to the browser.
     *
     * The counterpart to Restore: keeping a copy somewhere of your own is a
     * reasonable thing to want, and until now it meant shelling into the
     * server to fetch the file by hand.
     */
    public function downloadS3Backup(): void
    {
        $this->denyIfHosted();
        $this->requireAdmin();
        $this->verifyCsrf();

        $filename = trim($_POST['filename'] ?? '');
        // Same guard as Restore: the name reaches a shell command, and only
        // the backups this server writes are ever a valid target.
        if ($filename === '' || !preg_match('/^bbs-backup-[A-Za-z0-9_\-]+\.tar\.gz$/', $filename)) {
            $this->json(['success' => false, 'error' => 'Invalid backup filename'], 400);
        }

        $s3Service = new \BBS\Services\S3SyncService();
        $creds = $s3Service->resolveCredentials(['credential_source' => 'global']);
        if (empty($creds['bucket']) || empty($creds['access_key'])) {
            $this->json(['success' => false, 'error' => 'S3 credentials not configured'], 400);
        }

        // Staged on the data volume rather than /tmp: these land on the root
        // filesystem otherwise, which is the small one (#344).
        $stagingBase = '/var/bbs/tmp';
        if (!is_dir($stagingBase) && !@mkdir($stagingBase, 0770, true)) {
            $stagingBase = sys_get_temp_dir();
        }
        // The helper only writes into a bbs-restore-<hex> directory, so the
        // name follows that contract rather than inventing one it would reject.
        $tmpDir = $stagingBase . '/bbs-restore-' . bin2hex(random_bytes(8));
        if (!@mkdir($tmpDir, 0700, true)) {
            $this->json(['success' => false, 'error' => 'Could not create a staging directory'], 500);
        }

        // However the request ends — including the browser hanging up
        // mid-transfer — the staged copy goes away.
        ignore_user_abort(true);
        register_shutdown_function(function () use ($tmpDir) {
            if (is_dir($tmpDir)) {
                exec('rm -rf ' . escapeshellarg($tmpDir) . ' 2>/dev/null');
            }
        });

        $helper = '/usr/local/bin/bbs-ssh-helper';
        $cmd = sprintf(
            'sudo %s rclone-server-download %s %s %s %s %s %s %s %s 2>&1',
            escapeshellarg($helper),
            escapeshellarg($filename),
            escapeshellarg($tmpDir),
            escapeshellarg($creds['endpoint']),
            escapeshellarg($creds['region']),
            escapeshellarg($creds['bucket']),
            escapeshellarg($creds['access_key']),
            escapeshellarg($creds['secret_key']),
            escapeshellarg($creds['path_prefix'] ?? '')
        );
        $output = shell_exec($cmd);

        $local = $tmpDir . '/' . $filename;
        if (!is_file($local)) {
            $this->json([
                'success' => false,
                'error' => 'Could not fetch that backup: ' . trim((string) $output),
            ], 502);
        }

        $this->db->insert('server_log', [
            'level' => 'info',
            'message' => "Server backup \"{$filename}\" downloaded from off-site storage",
        ]);

        if (ob_get_level()) {
            ob_end_clean();
        }
        header('Content-Type: application/gzip');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($local));
        header('Cache-Control: no-store');
        readfile($local);
        exit;
    }

    /**
     * POST /storage-locations/s3/backup-now — take a server backup immediately.
     *
     * Worth having before an upgrade or a configuration change, rather than
     * waiting for the daily run. Uses the same options and the same code as
     * that run, and pushes it off-site afterwards when that is enabled.
     */
    public function backupNow(): void
    {
        $this->denyIfHosted();
        $this->requireAdmin();
        $this->verifyCsrf();

        // A server backup takes minutes on a large install, and the browser is
        // waiting on the response — don't let PHP's own clock end it half-way.
        set_time_limit(0);
        ignore_user_abort(true);

        $service = new \BBS\Services\ServerBackupService();
        $result = $service->run();

        if (!$result['success']) {
            $this->db->insert('server_log', [
                'level' => 'error',
                'message' => 'On-demand server backup failed: ' . $result['message'],
            ]);
            $this->json(['success' => false, 'error' => $result['message']], 500);
        }

        $sync = $service->syncToS3();

        $this->db->insert('server_log', [
            'level' => 'info',
            'message' => 'Server backup created on demand'
                . ($result['filename'] ? ": {$result['filename']}" : '')
                . ($sync['skipped'] ? '' : ' — ' . $sync['message']),
        ]);

        $this->json([
            'success' => true,
            'filename' => $result['filename'],
            'synced' => !$sync['skipped'] && $sync['success'],
            'sync_message' => $sync['skipped'] ? null : $sync['message'],
            'message' => $result['message'],
        ]);
    }

    /**
     * POST /storage-locations/s3/restore-backup — download and restore a server backup from S3.
     */
    public function restoreS3Backup(): void
    {
        $this->denyIfHosted();
        $this->requireAdmin();
        $this->verifyCsrf();

        $this->json((new \BBS\Services\ServerBackupService())->restoreFromS3(trim($_POST['filename'] ?? '')));
    }

    /**
     * Write all storage location paths to /etc/bbs/allowed-storage-paths
     * so bbs-ssh-helper can validate repo directory creation on those paths.
     * Public because the admin API also creates storage locations and needs
     * to refresh the allow-list.
     */
    /**
     * A stated capacity in GB from the form, as bytes. Blank or zero means
     * "measure it" (#415) — only mounts that cannot report their own size
     * need this, so an empty field is the normal case.
     */
    private function capacityFromPost(): ?int
    {
        $raw = trim((string) ($_POST['capacity_gb'] ?? ''));
        if ($raw === '' || !is_numeric($raw) || (float) $raw <= 0) {
            return null;
        }
        return (int) round((float) $raw * 1073741824);
    }

    public function updateAllowedPaths(): void
    {
        $locations = $this->db->fetchAll("SELECT path FROM storage_locations");
        $paths = array_column($locations, 'path');

        $cmd = ['sudo', '/usr/local/bin/bbs-ssh-helper', 'update-allowed-paths'];
        foreach ($paths as $p) {
            $cmd[] = $p;
        }
        exec(implode(' ', array_map('escapeshellarg', $cmd)) . ' 2>&1', $output, $ret);
    }
}
