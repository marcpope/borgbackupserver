<?php

namespace BBS\Services;

use BBS\Core\Database;

/**
 * The server's backup of itself — database, configuration and SSH keys.
 *
 * Not repository data: that is protected separately. This is what makes a
 * fresh install recoverable after the original machine is gone.
 *
 * Lives here so the daily scheduler run and an on-demand request share one
 * implementation, rather than the button drifting from the schedule.
 */
class ServerBackupService
{
    private const HELPER = '/usr/local/bin/bbs-ssh-helper';
    private const BACKUP_DIR = '/var/bbs/backups';

    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    private function setting(string $key, ?string $default = null): ?string
    {
        $row = $this->db->fetchOne("SELECT `value` FROM settings WHERE `key` = ?", [$key]);
        return $row['value'] ?? $default;
    }

    public function isEnabled(): bool
    {
        return $this->setting('self_backup_enabled', '1') === '1';
    }

    public function lastRunAt(): ?string
    {
        return $this->setting('last_self_backup');
    }

    /** True when the daily run is due — more than a day since the last one. */
    public function isDue(): bool
    {
        $last = $this->lastRunAt();
        return !$last || strtotime($last) < time() - 86400;
    }

    /**
     * Take a backup now, using the configured options.
     *
     * @return array{success: bool, message: string, filename: ?string}
     */
    public function run(): array
    {
        if (!is_file(self::HELPER)) {
            return ['success' => false, 'message' => 'Backup helper is not installed on this server', 'filename' => null];
        }

        $args = '';
        if ($this->setting('self_backup_catalogs', '0') === '1') {
            $args .= ' --with-catalogs';
        }
        $keep = max(1, (int) $this->setting('self_backup_retention', '7'));
        $args .= ' --keep ' . $keep;

        $before = $this->newestBackupFile();
        $output = shell_exec('sudo ' . self::HELPER . ' server-backup' . $args . ' 2>&1');
        $ok = str_contains($output ?? '', 'OK');

        // Stamp the run either way: a failing backup that retried every minute
        // would be worse than one that waits for the next daily window.
        $this->db->query(
            "INSERT INTO settings (`key`, `value`) VALUES ('last_self_backup', ?)
             ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)",
            [date('Y-m-d H:i:s')]
        );

        if (!$ok) {
            return [
                'success' => false,
                'message' => trim($output ?? '') ?: 'Backup failed with no output',
                'filename' => null,
            ];
        }

        $after = $this->newestBackupFile();
        return [
            'success' => true,
            'message' => 'Server backup completed',
            // Null when retention swept the new file away immediately, or the
            // directory is somehow empty — the caller shouldn't name a file
            // that isn't there.
            'filename' => ($after && $after !== $before) ? $after : null,
        ];
    }

    /** Newest file in the backup directory, by name (they are timestamped). */
    private function newestBackupFile(): ?string
    {
        $files = glob(self::BACKUP_DIR . '/bbs-backup-*.tar.gz') ?: [];
        if (empty($files)) {
            return null;
        }
        rsort($files);
        return basename($files[0]);
    }

    /**
     * Push the local server backups to off-site storage, if that is enabled.
     *
     * @return array{success: bool, message: string, skipped: bool}
     */
    public function syncToS3(): array
    {
        if ($this->setting('s3_sync_server_backups', '0') !== '1') {
            return ['success' => true, 'message' => 'Off-site sync is not enabled', 'skipped' => true];
        }

        $s3 = new S3SyncService();
        $creds = $s3->resolveCredentials(['credential_source' => 'global']);
        if (empty($creds['bucket']) || !$s3->isRcloneInstalled()) {
            return ['success' => false, 'message' => 'Off-site storage is not configured', 'skipped' => false];
        }

        $prefix = trim($creds['path_prefix'], '/');
        $remotePath = $prefix ? "{$prefix}/_server-backups" : '_server-backups';
        $remote = "S3:{$creds['bucket']}/{$remotePath}/";

        $cmd = sprintf(
            'sudo %s rclone-server-sync %s %s %s %s %s %s 2>&1',
            escapeshellarg(self::HELPER),
            escapeshellarg(self::BACKUP_DIR),
            escapeshellarg($remote),
            escapeshellarg($creds['endpoint']),
            escapeshellarg($creds['region']),
            escapeshellarg($creds['access_key']),
            escapeshellarg($creds['secret_key'])
        );
        $output = shell_exec($cmd);
        // Same test the scheduler has always used: rclone chatters "ERROR" for
        // recoverable things, so a run is only a failure when it says ERROR and
        // never reaches OK.
        $failed = str_contains($output ?? '', 'ERROR') && !str_contains($output ?? '', 'OK');

        return [
            'success' => !$failed,
            'message' => $failed ? (trim($output ?? '') ?: 'Sync failed') : 'Synced to off-site storage',
            'skipped' => false,
        ];
    }

    /**
     * The server backups held in off-site storage, newest first.
     *
     * @return array{success: bool, error?: string, backups?: array<int, array{filename: string, size: int, modified: string}>}
     */
    public function listInS3(): array
    {
        $s3 = new S3SyncService();
        $creds = $s3->resolveCredentials(['credential_source' => 'global']);
        if (empty($creds['bucket']) || empty($creds['access_key'])) {
            return ['success' => false, 'error' => 'S3 credentials not configured'];
        }

        $cmd = sprintf(
            'sudo %s rclone-server-list %s %s %s %s %s %s 2>&1',
            escapeshellarg(self::HELPER),
            escapeshellarg($creds['endpoint']),
            escapeshellarg($creds['region']),
            escapeshellarg($creds['bucket']),
            escapeshellarg($creds['access_key']),
            escapeshellarg($creds['secret_key']),
            escapeshellarg($creds['path_prefix'] ?? '')
        );
        $output = shell_exec($cmd);
        $json = json_decode($output ?? '', true);
        if (!is_array($json)) {
            return ['success' => false, 'error' => 'Failed to list backups: ' . trim((string) $output)];
        }

        $backups = [];
        foreach ($json as $item) {
            if (!isset($item['Name'])) continue;
            $backups[] = [
                'filename' => $item['Name'],
                'size' => (int) ($item['Size'] ?? 0),
                'modified' => $item['ModTime'] ?? '',
            ];
        }
        usort($backups, static fn($a, $b) => strcmp($b['modified'], $a['modified']));

        return ['success' => true, 'backups' => $backups];
    }

    /**
     * Download one server backup from off-site storage and restore it over
     * this install — database, configuration and SSH keys. Maintenance mode
     * is switched on afterwards; the admin password is reset and returned.
     *
     * @return array{success: bool, error?: string, output?: string, username?: string, password?: string}
     */
    public function restoreFromS3(string $filename): array
    {
        if ($filename === '' || !preg_match('/^bbs-backup-.*\.tar\.gz$/', $filename)) {
            return ['success' => false, 'error' => 'Invalid backup filename'];
        }

        $s3 = new S3SyncService();
        $creds = $s3->resolveCredentials(['credential_source' => 'global']);
        if (empty($creds['bucket']) || empty($creds['access_key'])) {
            return ['success' => false, 'error' => 'S3 credentials not configured'];
        }

        // Stage under the data volume, not the OS disk (#344)
        $stagingBase = '/var/bbs/tmp';
        if (!is_dir($stagingBase) && !@mkdir($stagingBase, 0770, true)) {
            $stagingBase = '/tmp';
        }
        $tmpDir = $stagingBase . '/bbs-restore-' . bin2hex(random_bytes(8));
        mkdir($tmpDir, 0700, true);

        $dlCmd = sprintf(
            'sudo %s rclone-server-download %s %s %s %s %s %s %s %s 2>&1',
            escapeshellarg(self::HELPER),
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
            shell_exec('rm -rf ' . escapeshellarg($tmpDir));
            return ['success' => false, 'error' => 'Failed to download backup: ' . trim((string) $dlOutput)];
        }

        $restoreCmd = sprintf(
            'sudo %s server-restore %s 2>&1',
            escapeshellarg(self::HELPER),
            escapeshellarg($backupFile)
        );
        $restoreOutput = (string) shell_exec($restoreCmd);
        shell_exec('rm -rf ' . escapeshellarg($tmpDir));

        $newPassword = '';
        if (preg_match('/NEW_ADMIN_PASSWORD=(.+)/', $restoreOutput, $m)) {
            $newPassword = trim($m[1]);
        }

        // Maintenance mode on: the restored DB is fresh. bbs-restore sets
        // this too, but through CLI credentials — make sure it stuck.
        $existing = $this->db->fetchOne("SELECT `key` FROM settings WHERE `key` = 'maintenance_mode'");
        if ($existing) {
            $this->db->update('settings', ['value' => '1'], "`key` = ?", ['maintenance_mode']);
        } else {
            $this->db->insert('settings', ['key' => 'maintenance_mode', 'value' => '1']);
        }

        if ($newPassword === '') {
            return [
                'success' => false,
                'error' => 'Restore may have failed — no new password generated',
                'output' => $restoreOutput,
            ];
        }
        return ['success' => true, 'username' => 'admin', 'password' => $newPassword];
    }
}
