<?php

namespace BBS\Controllers;

use BBS\Core\Controller;
use BBS\Services\ServerStats;

class StorageLocationController extends Controller
{
    public function index(): void
    {
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

            $diskUsage = ServerStats::getDiskUsage($loc['path']);
            $loc['disk_total'] = $diskUsage['total'] ?? 0;
            $loc['disk_used'] = $diskUsage['used'] ?? 0;
            $loc['disk_free'] = $diskUsage['free'] ?? 0;
            $loc['disk_percent'] = $diskUsage['percent'] ?? 0;
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

        $this->view('storage-locations/index', [
            'pageTitle' => 'Storage',
            'locations' => $locations,
            'remoteSshConfigs' => $remoteSshConfigs,
            'remoteRepoCount' => $remoteRepoCount,
            'localRepoCount' => $localRepoCount,
            'settings' => $settings,
        ]);
    }

    public function store(): void
    {
        $this->requireAdmin();
        $this->verifyCsrf();

        $label = trim($_POST['label'] ?? '');
        $path = rtrim(trim($_POST['path'] ?? ''), '/');
        $isDefault = !empty($_POST['is_default']) ? 1 : 0;

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
            'is_default' => $isDefault,
        ]);

        // Update the allowed-storage-paths config file for bbs-ssh-helper
        $this->updateAllowedPaths();

        $this->flash('success', "Storage location \"{$label}\" created.");
        $this->redirect('/storage-locations');
    }

    public function update(int $id): void
    {
        $this->requireAdmin();
        $this->verifyCsrf();

        $location = $this->db->fetchOne("SELECT * FROM storage_locations WHERE id = ?", [$id]);
        if (!$location) {
            $this->flash('danger', 'Storage location not found.');
            $this->redirect('/storage-locations');
        }

        $label = trim($_POST['label'] ?? '');
        $path = rtrim(trim($_POST['path'] ?? ''), '/');
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
            'is_default' => $isDefault,
        ], 'id = ?', [$id]);

        $this->updateAllowedPaths();

        $this->flash('success', "Storage location \"{$label}\" updated.");
        $this->redirect('/storage-locations');
    }

    public function destroy(int $id): void
    {
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
        $this->requireAdmin();
        $this->verifyCsrf();

        $s3Service = new \BBS\Services\S3SyncService();
        $creds = $s3Service->resolveCredentials(['credential_source' => 'global']);

        if (empty($creds['bucket']) || empty($creds['access_key'])) {
            $this->json(['success' => false, 'error' => 'S3 credentials not configured']);
            return;
        }

        $helper = '/usr/local/bin/bbs-ssh-helper';
        $cmd = sprintf(
            'sudo %s rclone-server-list %s %s %s %s %s %s 2>&1',
            escapeshellarg($helper),
            escapeshellarg($creds['endpoint']),
            escapeshellarg($creds['region']),
            escapeshellarg($creds['bucket']),
            escapeshellarg($creds['access_key']),
            escapeshellarg($creds['secret_key']),
            escapeshellarg($creds['path_prefix'] ?? '')
        );

        $output = shell_exec($cmd);
        $json = json_decode($output, true);

        if (!is_array($json)) {
            $this->json(['success' => false, 'error' => 'Failed to list backups: ' . trim($output)]);
            return;
        }

        $backups = [];
        foreach ($json as $item) {
            if (!isset($item['Name'])) continue;
            $backups[] = [
                'filename' => $item['Name'],
                'size' => $item['Size'] ?? 0,
                'modified' => $item['ModTime'] ?? '',
            ];
        }

        // Sort newest first
        usort($backups, function ($a, $b) {
            return strcmp($b['modified'], $a['modified']);
        });

        $this->json(['success' => true, 'backups' => $backups]);
    }

    /**
     * POST /storage-locations/s3/restore-backup — download and restore a server backup from S3.
     */
    public function restoreS3Backup(): void
    {
        $this->requireAdmin();
        $this->verifyCsrf();

        $filename = trim($_POST['filename'] ?? '');

        if (empty($filename) || !preg_match('/^bbs-backup-.*\.tar\.gz$/', $filename)) {
            $this->json(['success' => false, 'error' => 'Invalid backup filename']);
            return;
        }

        $s3Service = new \BBS\Services\S3SyncService();
        $creds = $s3Service->resolveCredentials(['credential_source' => 'global']);

        if (empty($creds['bucket']) || empty($creds['access_key'])) {
            $this->json(['success' => false, 'error' => 'S3 credentials not configured']);
            return;
        }

        // Create temp directory
        $tmpDir = '/tmp/bbs-restore-' . bin2hex(random_bytes(8));
        mkdir($tmpDir, 0700, true);

        $helper = '/usr/local/bin/bbs-ssh-helper';

        // Step 1: Download the backup file
        $dlCmd = sprintf(
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

        $dlOutput = shell_exec($dlCmd);
        $backupFile = $tmpDir . '/' . $filename;

        if (!file_exists($backupFile)) {
            // Cleanup
            shell_exec('rm -rf ' . escapeshellarg($tmpDir));
            $this->json(['success' => false, 'error' => 'Failed to download backup: ' . trim($dlOutput)]);
            return;
        }

        // Step 2: Run bbs-restore --yes
        $restoreCmd = sprintf(
            'sudo %s server-restore %s 2>&1',
            escapeshellarg($helper),
            escapeshellarg($backupFile)
        );

        $restoreOutput = shell_exec($restoreCmd);

        // Cleanup temp dir
        shell_exec('rm -rf ' . escapeshellarg($tmpDir));

        // Parse the new admin password from output
        $newPassword = '';
        if (preg_match('/NEW_ADMIN_PASSWORD=(.+)/', $restoreOutput, $matches)) {
            $newPassword = trim($matches[1]);
        }

        // Enable maintenance mode — the restored DB is fresh, so insert the setting
        // (bbs-restore also sets this, but the DB connection there uses CLI creds;
        // this ensures it's set even if the restore script's insert was lost)
        $db = \BBS\Core\Database::getInstance();
        $existing = $db->fetchOne("SELECT `key` FROM settings WHERE `key` = 'maintenance_mode'");
        if ($existing) {
            $db->update('settings', ['value' => '1'], "`key` = ?", ['maintenance_mode']);
        } else {
            $db->insert('settings', ['key' => 'maintenance_mode', 'value' => '1']);
        }

        if (empty($newPassword)) {
            $this->json([
                'success' => false,
                'error' => 'Restore may have failed — no new password generated',
                'output' => $restoreOutput,
            ]);
            return;
        }

        $this->json([
            'success' => true,
            'username' => 'admin',
            'password' => $newPassword,
        ]);
    }

    /**
     * Write all storage location paths to /etc/bbs/allowed-storage-paths
     * so bbs-ssh-helper can validate repo directory creation on those paths.
     */
    private function updateAllowedPaths(): void
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
