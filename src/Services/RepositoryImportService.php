<?php

namespace BBS\Services;

use BBS\Core\Database;

/**
 * Importing a borg repository that already exists: verify it opens with the
 * given passphrase, then register it for a client and queue a catalog sync.
 * Shared by the web Repos tab and the API so both follow the same rules.
 *
 * Names keep their case: for a local repo the name is the directory on disk
 * and has to match it (#360).
 */
class RepositoryImportService
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    public static function sanitizeName(string $name): string
    {
        $slug = preg_replace('/[^A-Za-z0-9_-]+/', '-', $name);
        $slug = preg_replace('/-{2,}/', '-', $slug);
        return trim($slug, '-');
    }

    /**
     * Open the repository with the passphrase and report what's in it.
     *
     * @return array{success: bool, error?: string, encryption?: string, archive_count?: int}
     */
    public function verify(array $agent, string $storageType, string $name, string $passphrase, ?int $storageLocationId, ?int $remoteSshConfigId): array
    {
        $agentId = (int) $agent['id'];
        if ($name === '') {
            return ['success' => false, 'error' => 'Repository name is required. Names can only contain letters, numbers, hyphens, and underscores.'];
        }
        if ($this->nameTaken($agentId, $name)) {
            return ['success' => false, 'error' => "A repository named \"{$name}\" already exists for this client."];
        }

        if ($storageType === 'remote_ssh') {
            if (!$remoteSshConfigId) {
                return ['success' => false, 'error' => 'Please select a remote SSH host.'];
            }
            $remoteSshService = new RemoteSshService();
            $config = $remoteSshService->getDecrypted($remoteSshConfigId);
            if (!$config) {
                return ['success' => false, 'error' => 'Remote SSH host not found.'];
            }
            $repoPath = $remoteSshService->buildRepoPath($config, $name);
            $result = $remoteSshService->runBorgCommand($config, $repoPath, ['list', '--json', $repoPath], $passphrase);
            if (!$result['success']) {
                $errorMsg = trim($result['stderr'] ?? $result['output'] ?? 'Unknown error');
                return ['success' => false, 'error' => "Cannot access repository: {$errorMsg}"];
            }
            $infoData = json_decode($result['output'], true);
        } else {
            $location = $this->resolveLocalLocation($storageLocationId);
            $localPath = rtrim($location['path'], '/') . '/' . $agentId . '/' . $name;

            $helperCmd = ['sudo', '/usr/local/bin/bbs-ssh-helper', 'verify-repo', '-', $localPath];
            $proc = proc_open($helperCmd, [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ], $pipes);

            $output = '';
            $stderr = '';
            $exitCode = -1;
            if (is_resource($proc)) {
                fwrite($pipes[0], $passphrase . "\n");
                fclose($pipes[0]);
                $output = stream_get_contents($pipes[1]);
                $stderr = stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                $exitCode = proc_close($proc);
            }

            if ($exitCode !== 0) {
                // Prefer stderr: borg writes errors there and only info/cache
                // messages on stdout.
                $errorMsg = trim($stderr ?: $output);
                if (str_contains($errorMsg, 'passphrase') || str_contains($errorMsg, 'Passphrase')) {
                    $errorMsg = 'Incorrect passphrase for this repository.';
                } elseif (str_contains($errorMsg, 'not a valid repository') || str_contains($errorMsg, 'does not exist') || str_contains($errorMsg, 'Failed to create/acquire')) {
                    $errorMsg = "No valid borg repository found at: {$localPath}";
                }
                return ['success' => false, 'error' => $errorMsg ?: 'Failed to verify repository.'];
            }
            $infoData = json_decode($output, true);
        }

        if (!$infoData) {
            return ['success' => false, 'error' => 'Failed to parse repository info. Is this a valid borg repository?'];
        }

        return [
            'success' => true,
            'encryption' => $infoData['encryption']['mode'] ?? 'unknown',
            'archive_count' => count($infoData['archives'] ?? []),
        ];
    }

    /**
     * Register the repository and queue a catalog sync to discover archives.
     *
     * @return array{success: bool, error?: string, repository_id?: int, path?: string, job_id?: int, message?: string}
     */
    public function import(array $agent, string $storageType, string $name, string $encryption, string $passphrase, ?int $storageLocationId, ?int $remoteSshConfigId): array
    {
        $agentId = (int) $agent['id'];
        if ($name === '') {
            return ['success' => false, 'error' => 'Repository name must contain at least one alphanumeric character.'];
        }
        if ($this->nameTaken($agentId, $name)) {
            return ['success' => false, 'error' => "Repository \"{$name}\" already exists."];
        }

        $passphraseEncrypted = ($encryption !== 'none' && $passphrase !== '') ? Encryption::encrypt($passphrase) : null;

        if ($storageType === 'remote_ssh') {
            if (!$remoteSshConfigId) {
                return ['success' => false, 'error' => 'Please select a remote SSH host.'];
            }
            $remoteSshService = new RemoteSshService();
            $config = $remoteSshService->getById($remoteSshConfigId);
            if (!$config) {
                return ['success' => false, 'error' => 'Remote SSH host not found.'];
            }
            $path = $remoteSshService->buildRepoPath($config, $name);
            $repoId = $this->db->insert('repositories', [
                'agent_id' => $agentId,
                'storage_type' => 'remote_ssh',
                'remote_ssh_config_id' => $remoteSshConfigId,
                'name' => $name,
                'path' => $path,
                'encryption' => $encryption,
                'passphrase_encrypted' => $passphraseEncrypted,
            ]);
            $logMessage = "Remote repository \"{$name}\" imported ({$encryption}) from {$config['remote_user']}@{$config['remote_host']}";
            $message = "Repository \"{$name}\" imported from {$config['remote_host']}. A catalog sync has been queued.";
        } else {
            $location = $this->resolveLocalLocation($storageLocationId);
            $serverHost = $this->db->fetchOne("SELECT `value` FROM settings WHERE `key` = 'server_host'");
            $host = !empty($agent['server_host_override']) ? $agent['server_host_override'] : ($serverHost['value'] ?? '');

            // A location outside the SSH user's home needs an absolute path so
            // borg finds it regardless of the home directory (same as create).
            $locationPath = rtrim($location['path'], '/');
            $sshHomeDir = $agent['ssh_home_dir'] ?? null;
            $sshHomePath = $sshHomeDir ? rtrim(dirname($sshHomeDir), '/') : null;
            $isNonDefault = !$sshHomePath || $locationPath !== $sshHomePath;
            $localPath = $locationPath . '/' . $agentId . '/' . $name;

            if ($isNonDefault) {
                if (!empty($agent['ssh_unix_user']) && !empty($host)) {
                    $sshHost = SshKeyManager::stripHostPort($host);
                    $path = "ssh://{$agent['ssh_unix_user']}@{$sshHost}//{$localPath}";
                } else {
                    $path = $localPath;
                }
            } elseif (!empty($agent['ssh_unix_user']) && !empty($host)) {
                $path = SshKeyManager::buildSshRepoPath($agent['ssh_unix_user'], $host, $name);
            } else {
                $path = $localPath;
            }

            $repoId = $this->db->insert('repositories', [
                'agent_id' => $agentId,
                'storage_type' => 'local',
                'storage_location_id' => $location['id'] ?? null,
                'name' => $name,
                'path' => $path,
                'encryption' => $encryption,
                'passphrase_encrypted' => $passphraseEncrypted,
            ]);

            if (!empty($agent['ssh_unix_user'])) {
                // Ownership for the SSH user, and .storage-paths so bbs-ssh-gate
                // lets borg into a location outside the home directory.
                $fixCmd = ['sudo', '/usr/local/bin/bbs-ssh-helper', 'fix-repo-perms', $localPath, $agent['ssh_unix_user']];
                exec(implode(' ', array_map('escapeshellarg', $fixCmd)) . ' 2>&1', $fixOutput, $fixRet);
                if ($fixRet !== 0) {
                    $this->db->insert('server_log', [
                        'agent_id' => $agentId,
                        'level' => 'warning',
                        'message' => "fix-repo-perms failed during import: " . implode(' ', $fixOutput),
                    ]);
                }
                SshKeyManager::updateAgentStoragePaths($this->db, $agentId, $agent);
            }
            $logMessage = "Repository \"{$name}\" imported ({$encryption}) from {$localPath}";
            $message = "Repository \"{$name}\" imported successfully. A catalog sync has been queued.";
        }

        $jobId = $this->db->insert('backup_jobs', [
            'agent_id' => $agentId,
            'repository_id' => $repoId,
            'backup_plan_id' => null,
            'task_type' => 'catalog_sync',
            'status' => 'queued',
        ]);
        $this->db->insert('server_log', [
            'agent_id' => $agentId,
            'level' => 'info',
            'message' => $logMessage,
        ]);

        return [
            'success' => true,
            'repository_id' => $repoId,
            'path' => $path,
            'job_id' => $jobId,
            'message' => $message,
        ];
    }

    private function nameTaken(int $agentId, string $name): bool
    {
        return (bool) $this->db->fetchOne(
            "SELECT id FROM repositories WHERE agent_id = ? AND name = ?",
            [$agentId, $name]
        );
    }

    /** Explicit id → default row → storage_path setting, as repo creation does. */
    private function resolveLocalLocation(?int $storageLocationId): array
    {
        $location = null;
        if ($storageLocationId) {
            $location = $this->db->fetchOne("SELECT * FROM storage_locations WHERE id = ?", [$storageLocationId]);
        }
        if (!$location) {
            $location = $this->db->fetchOne("SELECT * FROM storage_locations WHERE is_default = 1");
        }
        if (!$location) {
            $storageSetting = $this->db->fetchOne("SELECT `value` FROM settings WHERE `key` = 'storage_path'");
            $location = ['id' => null, 'path' => $storageSetting['value'] ?? '/var/bbs', 'is_default' => 1];
        }
        return $location;
    }
}
