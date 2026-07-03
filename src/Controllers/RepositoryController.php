<?php

namespace BBS\Controllers;

use BBS\Core\Controller;
use BBS\Services\BorgCommandBuilder;
use BBS\Services\Encryption;
use BBS\Services\PermissionService;
use BBS\Services\RemoteSshService;
use BBS\Services\S3SyncService;
use BBS\Services\SshKeyManager;

class RepositoryController extends Controller
{
    /**
     * Sanitize a repo name for use as a filesystem directory name.
     * Keeps the original name in the DB as a vanity/display name.
     */
    private function sanitizePathName(string $name): string
    {
        // Transliterate to ASCII, lowercase
        $slug = mb_strtolower($name, 'UTF-8');
        // Replace any non-alphanumeric characters (except hyphens and underscores) with hyphens
        $slug = preg_replace('/[^a-z0-9_-]+/', '-', $slug);
        // Collapse multiple hyphens
        $slug = preg_replace('/-{2,}/', '-', $slug);
        // Trim hyphens from ends
        $slug = trim($slug, '-');
        // Fallback if empty after sanitization
        return $slug ?: 'repo';
    }

    public function store(): void
    {
        $this->requireAuth();
        $this->verifyCsrf();

        $agentId = (int) ($_POST['agent_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $encryption = $_POST['encryption'] ?? 'repokey-blake2';
        $passphrase = $_POST['passphrase'] ?? '';
        $storageType = $_POST['storage_type'] ?? 'local';
        $remoteSshConfigId = !empty($_POST['remote_ssh_config_id']) ? (int) $_POST['remote_ssh_config_id'] : null;

        if (empty($name) || empty($agentId)) {
            $this->flash('danger', 'Repository name and agent are required.');
            $this->redirect("/clients/{$agentId}");
        }

        // Verify agent access and manage_repos permission
        $agent = $this->db->fetchOne("SELECT * FROM agents WHERE id = ?", [$agentId]);
        if (!$agent || !$this->canAccessAgent($agentId)) {
            $this->flash('danger', 'Access denied.');
            $this->redirect('/clients');
        }
        $this->requirePermission(PermissionService::MANAGE_REPOS, $agentId);

        // Auto-generate passphrase if not provided and encryption is enabled
        if (empty($passphrase) && $encryption !== 'none') {
            $passphrase = $this->generatePassphrase();
        }

        $storageLocationId = !empty($_POST['storage_location_id']) ? (int) $_POST['storage_location_id'] : null;

        // Branch based on storage type
        if ($storageType === 'remote_ssh') {
            $this->storeRemoteSsh($agentId, $name, $encryption, $passphrase, $remoteSshConfigId);
        } else {
            $this->storeLocal($agentId, $agent, $name, $encryption, $passphrase, $storageLocationId);
        }
    }

    /**
     * Create a local repository on the BBS server.
     */
    private function storeLocal(int $agentId, array $agent, string $name, string $encryption, string $passphrase, ?int $storageLocationId = null): void
    {
        // Resolve storage location
        $location = null;
        if ($storageLocationId) {
            $location = $this->db->fetchOne("SELECT * FROM storage_locations WHERE id = ?", [$storageLocationId]);
        }
        if (!$location) {
            $location = $this->db->fetchOne("SELECT * FROM storage_locations WHERE is_default = 1");
        }
        if (!$location) {
            // Fallback for pre-migration installs
            $storageSetting = $this->db->fetchOne("SELECT `value` FROM settings WHERE `key` = 'storage_path'");
            $location = ['id' => null, 'path' => $storageSetting['value'] ?? '/var/bbs', 'is_default' => 1];
        }

        $serverHost = $this->db->fetchOne("SELECT `value` FROM settings WHERE `key` = 'server_host'");
        $host = !empty($agent['server_host_override']) ? $agent['server_host_override'] : ($serverHost['value'] ?? '');

        // Determine if this storage location differs from the SSH user's home directory.
        // Compare the location path against the parent of the agent's actual ssh_home_dir
        // (stored at provisioning time) rather than settings.storage_path which can change.
        $locationPath = rtrim($location['path'], '/');
        $sshHomeDir = $agent['ssh_home_dir'] ?? null;
        $sshHomePath = $sshHomeDir ? rtrim(dirname($sshHomeDir), '/') : null;
        $isNonDefault = !$sshHomePath || $locationPath !== $sshHomePath;

        // Sanitize the name for filesystem use (vanity name stays in DB)
        $safeName = $this->sanitizePathName($name);

        if ($isNonDefault) {
            // Non-default storage location: use absolute path so borg finds the repo
            // regardless of the SSH user's home directory
            $localPath = $locationPath . '/' . $agentId . '/' . $safeName;
            if (!empty($agent['ssh_unix_user']) && !empty($host)) {
                // Absolute SSH path (double slash after host); strip web port from host
                $sshHost = SshKeyManager::stripHostPort($host);
                $path = "ssh://{$agent['ssh_unix_user']}@{$sshHost}//{$localPath}";
            } else {
                $path = $localPath;
            }
        } else {
            // Default location: use relative path (resolves to SSH user's home dir)
            if (!empty($agent['ssh_unix_user']) && !empty($host)) {
                $path = SshKeyManager::buildSshRepoPath($agent['ssh_unix_user'], $host, $safeName);
            } else {
                $path = rtrim($location['path'], '/') . '/' . $agentId . '/' . $safeName;
            }
        }

        // Check for duplicate path (two vanity names could sanitize to the same slug)
        $existing = $this->db->fetchOne("SELECT id FROM repositories WHERE path = ?", [$path]);
        if ($existing) {
            $this->flash('danger', "A repository already exists at that path. Try a different name.");
            $this->redirect("/clients/{$agentId}?tab=repos");
        }

        $repoId = $this->db->insert('repositories', [
            'agent_id' => $agentId,
            'storage_type' => 'local',
            'storage_location_id' => $location['id'] ?? null,
            'name' => $safeName,
            'path' => $path,
            'encryption' => $encryption,
            'passphrase_encrypted' => $encryption !== 'none' ? Encryption::encrypt($passphrase) : null,
        ]);

        // Run borg init server-side (repos are local to server)
        $repo = $this->db->fetchOne("SELECT * FROM repositories WHERE id = ?", [$repoId]);
        $localPath = BorgCommandBuilder::getLocalRepoPath($repo);

        // Create repo directory via SSH helper (sets correct ownership for borg + sshd)
        $helperCmd = ['sudo', '/usr/local/bin/bbs-ssh-helper', 'create-repo-dir', $localPath];
        exec(implode(' ', array_map('escapeshellarg', $helperCmd)) . ' 2>&1', $helperOutput, $helperRet);
        if ($helperRet !== 0) {
            $this->db->insert('server_log', [
                'agent_id' => $agentId,
                'level' => 'warning',
                'message' => "create-repo-dir helper failed: " . implode(' ', $helperOutput),
            ]);
            // Fallback: create parent directory manually
            $parentDir = dirname($localPath);
            if (!is_dir($parentDir)) {
                mkdir($parentDir, 0755, true);
            }
        }

        // Update .storage-paths BEFORE borg init so SSH access works even if init fails
        if (!empty($agent['ssh_unix_user'])) {
            $this->updateAgentStoragePaths($agentId, $agent);
        }

        // Run borg init via bbs-ssh-helper (runs as root, works on NFS and other
        // filesystems where www-data may lack write access despite POSIX permissions).
        // Passphrase is piped on stdin ("-" marker) so it's not visible in `ps`.
        $initCmd = ['sudo', '/usr/local/bin/bbs-ssh-helper', 'borg-init', $localPath, $encryption];
        $passphraseToPipe = '';
        if ($encryption !== 'none' && !empty($passphrase)) {
            $initCmd[] = '-';
            $passphraseToPipe = $passphrase;
        }
        $initOutput = [];
        $initRet = 1;
        $initProc = proc_open($initCmd, [
            0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
        ], $initPipes);
        if (is_resource($initProc)) {
            if ($passphraseToPipe !== '') fwrite($initPipes[0], $passphraseToPipe . "\n");
            fclose($initPipes[0]);
            $stdout = stream_get_contents($initPipes[1]);
            $stderr = stream_get_contents($initPipes[2]);
            fclose($initPipes[1]);
            fclose($initPipes[2]);
            $initRet = proc_close($initProc);
            $initOutput = array_values(array_filter(explode("\n", trim($stdout . "\n" . $stderr))));
        }

        if ($initRet !== 0) {
            $errorMsg = implode("\n", $initOutput);
            $this->db->insert('server_log', [
                'agent_id' => $agentId,
                'level' => 'error',
                'message' => "borg init failed for repo \"{$name}\": {$errorMsg}",
            ]);
            $this->flash('warning', "Repository \"{$name}\" created in database but borg init failed: {$errorMsg}");
            $this->redirect("/clients/{$agentId}?tab=repos");
        }

        // Fix ownership: borg init creates files as root, but the bbs-user needs to own them for SSH access
        if (!empty($agent['ssh_unix_user'])) {
            $fixCmd = ['sudo', '/usr/local/bin/bbs-ssh-helper', 'fix-repo-perms', $localPath, $agent['ssh_unix_user']];
            exec(implode(' ', array_map('escapeshellarg', $fixCmd)) . ' 2>&1', $fixOutput, $fixRet);
            if ($fixRet !== 0) {
                $this->db->insert('server_log', [
                    'agent_id' => $agentId,
                    'level' => 'warning',
                    'message' => "fix-repo-perms failed: " . implode(' ', $fixOutput),
                ]);
            }
        }

        $this->db->insert('server_log', [
            'agent_id' => $agentId,
            'level' => 'info',
            'message' => "Repository \"{$name}\" initialized ({$encryption}) at {$localPath}",
        ]);

        $this->flash('success', "Repository \"{$name}\" created and initialized.");
        $this->redirect("/clients/{$agentId}?tab=repos");
    }

    /**
     * Create a repository on a remote SSH host (rsync.net, BorgBase, etc.)
     */
    private function storeRemoteSsh(int $agentId, string $name, string $encryption, string $passphrase, ?int $remoteSshConfigId): void
    {
        if (!$remoteSshConfigId) {
            $this->flash('danger', 'Please select a remote SSH host.');
            $this->redirect("/clients/{$agentId}?tab=repos");
        }

        $remoteSshService = new RemoteSshService();
        $config = $remoteSshService->getById($remoteSshConfigId);
        if (!$config) {
            $this->flash('danger', 'Remote SSH host not found.');
            $this->redirect("/clients/{$agentId}?tab=repos");
        }

        // Build the SSH repo path (sanitize name for filesystem)
        $safeName = $this->sanitizePathName($name);
        $repoPath = $remoteSshService->buildRepoPath($config, $safeName);

        // Run borg init over SSH first — only save to DB if it succeeds
        $result = $remoteSshService->initRepo($config, $repoPath, $encryption, $passphrase);

        if (!$result['success']) {
            $errorMsg = $result['stderr'] ?? $result['output'] ?? 'Unknown error';
            $this->db->insert('server_log', [
                'agent_id' => $agentId,
                'level' => 'error',
                'message' => "borg init failed for remote repo \"{$name}\" on {$config['remote_host']}: {$errorMsg}",
            ]);
            $this->flash('danger', "Failed to initialize repository \"{$name}\" on {$config['remote_host']}: {$errorMsg}");
            $this->redirect("/clients/{$agentId}?tab=repos");
        }

        $repoId = $this->db->insert('repositories', [
            'agent_id' => $agentId,
            'storage_type' => 'remote_ssh',
            'remote_ssh_config_id' => $remoteSshConfigId,
            'name' => $safeName,
            'path' => $repoPath,
            'encryption' => $encryption,
            'passphrase_encrypted' => $encryption !== 'none' ? Encryption::encrypt($passphrase) : null,
        ]);

        $this->db->insert('server_log', [
            'agent_id' => $agentId,
            'level' => 'info',
            'message' => "Remote repository \"{$safeName}\" initialized ({$encryption}) on {$config['remote_user']}@{$config['remote_host']}",
        ]);

        $this->flash('success', "Repository \"{$name}\" created on {$config['remote_host']} and initialized.");
        $this->redirect("/clients/{$agentId}?tab=repos");
    }

    public function delete(int $id): void
    {
        $this->requireAuth();
        $this->verifyCsrf();

        $repo = $this->db->fetchOne("
            SELECT r.*, a.id as agent_id
            FROM repositories r
            JOIN agents a ON a.id = r.agent_id
            WHERE r.id = ?
        ", [$id]);

        if (!$repo || !$this->canAccessAgent($repo['agent_id'])) {
            $this->flash('danger', 'Repository not found.');
            $this->redirect('/clients');
        }

        // Require manage_repos permission to delete
        $this->requirePermission(PermissionService::MANAGE_REPOS, $repo['agent_id']);

        $agentId = $repo['agent_id'];

        // Block if backup plans reference this repo
        $planCount = $this->db->fetchOne(
            "SELECT COUNT(*) as cnt FROM backup_plans WHERE repository_id = ?", [$id]
        );
        if ((int) ($planCount['cnt'] ?? 0) > 0) {
            $this->flash('danger', 'Cannot delete repository — it has backup plans attached. Delete the plans first.');
            $this->redirect("/clients/{$agentId}?tab=repos");
        }

        // Block if any jobs are currently in progress
        $activeJobs = $this->db->fetchOne(
            "SELECT COUNT(*) as cnt FROM backup_jobs WHERE repository_id = ? AND status IN ('queued', 'sent', 'running')", [$id]
        );
        if ((int) ($activeJobs['cnt'] ?? 0) > 0) {
            $this->flash('danger', 'Cannot delete repository — it has active jobs. Wait for them to finish first.');
            $this->redirect("/clients/{$agentId}?tab=repos");
        }

        // Delete borg repository from disk
        $localPath = BorgCommandBuilder::getLocalRepoPath($repo);
        $diskDeleted = false;
        if (!empty($localPath) && is_dir($localPath)) {
            // Safety: only delete paths within a known storage location
            $allowedPaths = array_column(
                $this->db->fetchAll("SELECT path FROM storage_locations"),
                'path'
            );
            // Also include legacy storage_path setting
            $storageSetting = $this->db->fetchOne("SELECT `value` FROM settings WHERE `key` = 'storage_path'");
            if (!empty($storageSetting['value'])) {
                $allowedPaths[] = $storageSetting['value'];
            }
            $pathAllowed = false;
            $realLocal = realpath($localPath);
            foreach ($allowedPaths as $ap) {
                if (!empty($ap) && $realLocal && str_starts_with($realLocal, realpath($ap) ?: '')) {
                    $pathAllowed = true;
                    break;
                }
            }

            if ($pathAllowed) {
                $output = [];
                $retval = 0;
                exec('sudo /usr/local/bin/bbs-ssh-helper delete-storage ' . escapeshellarg($localPath) . ' 2>&1', $output, $retval);
                $diskDeleted = ($retval === 0);
                if (!$diskDeleted) {
                    $this->db->insert('server_log', [
                        'agent_id' => $agentId,
                        'level' => 'warning',
                        'message' => "Failed to delete repo directory on disk: {$localPath} — " . implode(' ', $output),
                    ]);
                }
            } else {
                $this->db->insert('server_log', [
                    'agent_id' => $agentId,
                    'level' => 'warning',
                    'message' => "Skipped disk deletion for repo \"{$repo['name']}\" — path outside known storage location.",
                ]);
            }
        }

        // Handle S3 deletion if requested — remove the offsite copy from
        // every destination this repo replicates to
        $s3Deleted = false;
        $deleteFromS3 = !empty($_POST['delete_from_s3']);

        if ($deleteFromS3) {
            $linkedConfigs = $this->db->fetchAll("
                SELECT pc.id, pc.name, pc.config
                FROM repository_s3_configs rsc
                JOIN plugin_configs pc ON pc.id = rsc.plugin_config_id
                WHERE rsc.repository_id = ?
            ", [$id]);
            // Legacy form fallback: an explicit plugin_config_id with no link rows
            if (empty($linkedConfigs) && !empty($_POST['plugin_config_id'])) {
                $legacy = $this->db->fetchOne("SELECT id, name, config FROM plugin_configs WHERE id = ?", [(int) $_POST['plugin_config_id']]);
                if ($legacy) $linkedConfigs = [$legacy];
            }
            $agent = $this->db->fetchOne("SELECT * FROM agents WHERE id = ?", [$agentId]);

            if (!empty($linkedConfigs) && $agent) {
                $s3Service = new S3SyncService();
                $s3Deleted = true;
                foreach ($linkedConfigs as $pluginConfig) {
                    $config = json_decode($pluginConfig['config'], true) ?: [];
                    $creds = $s3Service->resolveCredentials($config);

                    $result = $s3Service->deleteFromS3($repo, $agent, $creds);
                    if (!$result['success']) $s3Deleted = false;

                    $this->db->insert('server_log', [
                        'agent_id' => $agentId,
                        'level' => $result['success'] ? 'info' : 'warning',
                        'message' => $result['success']
                            ? "S3 data deleted for repository \"{$repo['name']}\" at destination \"{$pluginConfig['name']}\""
                            : "Failed to delete S3 data for repository \"{$repo['name']}\" at destination \"{$pluginConfig['name']}\": " . ($result['output'] ?? 'Unknown error'),
                    ]);
                }
            }
        }

        $this->db->delete('repositories', 'id = ?', [$id]);

        // Refresh .storage-paths after deletion (may remove a path if last repo on that location)
        $agent = $this->db->fetchOne("SELECT * FROM agents WHERE id = ?", [$agentId]);
        if ($agent && !empty($agent['ssh_unix_user'])) {
            $this->updateAgentStoragePaths($agentId, $agent);
        }

        $msg = "Repository \"{$repo['name']}\" deleted.";
        if ($diskDeleted) {
            $msg .= " Data removed from disk.";
        } elseif (!empty($localPath) && is_dir($localPath)) {
            $msg .= " Warning: disk data at {$localPath} could not be removed — clean up manually.";
        }
        if ($deleteFromS3) {
            if ($s3Deleted) {
                $msg .= " S3 offsite copy removed.";
            } else {
                $msg .= " Warning: S3 data could not be removed — clean up manually.";
            }
        }

        $this->db->insert('server_log', [
            'agent_id' => $agentId,
            'level' => 'info',
            'message' => "Repository \"{$repo['name']}\" deleted" . ($diskDeleted ? " (disk data removed)" : "") . ($s3Deleted ? " (S3 data removed)" : ""),
        ]);

        $this->flash('success', $msg);
        $this->redirect("/clients/{$agentId}?tab=repos");
    }

    public function rename(int $id): void
    {
        $this->requireAuth();
        $this->verifyCsrf();

        $newName = trim($_POST['name'] ?? '');

        $repo = $this->db->fetchOne("
            SELECT r.*, a.id as agent_id
            FROM repositories r
            JOIN agents a ON a.id = r.agent_id
            WHERE r.id = ?
        ", [$id]);

        if (!$repo || !$this->canAccessAgent($repo['agent_id'])) {
            $this->flash('danger', 'Repository not found.');
            $this->redirect('/clients');
        }

        $agentId = $repo['agent_id'];
        $this->requirePermission(PermissionService::MANAGE_REPOS, $agentId);

        if (empty($newName)) {
            $this->flash('danger', 'Repository name cannot be empty.');
            $this->redirect("/clients/{$agentId}/repo/{$id}");
        }

        // Only allow rename on local repos
        if (($repo['storage_type'] ?? 'local') === 'remote_ssh') {
            $this->flash('danger', 'Rename is not supported for remote SSH repositories.');
            $this->redirect("/clients/{$agentId}/repo/{$id}");
        }

        // Block if any jobs are in progress
        $activeJobs = $this->db->fetchOne(
            "SELECT COUNT(*) as cnt FROM backup_jobs WHERE repository_id = ? AND status IN ('queued', 'sent', 'running')", [$id]
        );
        if ((int) ($activeJobs['cnt'] ?? 0) > 0) {
            $this->flash('danger', 'Cannot rename repository while jobs are active. Wait for them to finish first.');
            $this->redirect("/clients/{$agentId}/repo/{$id}");
        }

        // Build new path by replacing the last path component with the sanitized name
        $safeName = $this->sanitizePathName($newName);
        $lastSlash = strrpos($repo['path'], '/');
        $newPath = substr($repo['path'], 0, $lastSlash + 1) . $safeName;

        if (empty($safeName)) {
            $this->flash('danger', 'Repository name must contain at least one alphanumeric character.');
            $this->redirect("/clients/{$agentId}/repo/{$id}");
        }

        // Check for duplicate path
        $existing = $this->db->fetchOne("SELECT id FROM repositories WHERE path = ? AND id != ?", [$newPath, $id]);
        if ($existing) {
            $this->flash('danger', 'A repository already exists at that path. Try a different name.');
            $this->redirect("/clients/{$agentId}/repo/{$id}");
        }

        // Rename on disk (skip if paths resolve to the same directory, e.g.
        // fixing a display name like "/home" → "home" where the filesystem
        // path is already correct)
        $oldLocalPath = BorgCommandBuilder::getLocalRepoPath($repo);
        if (!empty($oldLocalPath) && is_dir($oldLocalPath)) {
            $newLocalPath = dirname($oldLocalPath) . '/' . $safeName;

            if (realpath($oldLocalPath) === realpath($newLocalPath) || $oldLocalPath === $newLocalPath) {
                // Same directory — just update the DB name below, no disk rename needed
            } else {
                // Safety: validate paths are within allowed storage locations
                $allowedPaths = array_column(
                    $this->db->fetchAll("SELECT path FROM storage_locations"),
                    'path'
                );
                $storageSetting = $this->db->fetchOne("SELECT `value` FROM settings WHERE `key` = 'storage_path'");
                if (!empty($storageSetting['value'])) {
                    $allowedPaths[] = $storageSetting['value'];
                }

                $pathAllowed = false;
                $realLocal = realpath($oldLocalPath);
                foreach ($allowedPaths as $ap) {
                    if (!empty($ap) && $realLocal && str_starts_with($realLocal, realpath($ap) ?: '')) {
                        $pathAllowed = true;
                        break;
                    }
                }

                if (!$pathAllowed) {
                    $this->db->insert('server_log', [
                        'agent_id' => $agentId,
                        'level' => 'warning',
                        'message' => "Rename blocked for repo \"{$repo['name']}\" — path outside known storage location.",
                    ]);
                    $this->flash('danger', 'Cannot rename — repository path is outside known storage locations.');
                    $this->redirect("/clients/{$agentId}/repo/{$id}");
                }

                $output = [];
                $retval = 0;
                $cmd = 'sudo /usr/local/bin/bbs-ssh-helper rename-repo-dir '
                     . escapeshellarg($oldLocalPath) . ' '
                     . escapeshellarg($newLocalPath) . ' 2>&1';
                exec($cmd, $output, $retval);

                if ($retval !== 0) {
                    $this->db->insert('server_log', [
                        'agent_id' => $agentId,
                        'level' => 'error',
                        'message' => "Failed to rename repo directory: " . implode(' ', $output),
                    ]);
                    $this->flash('danger', 'Rename failed: ' . implode(' ', $output));
                    $this->redirect("/clients/{$agentId}/repo/{$id}");
                }
            }
        }

        // Update database (name must match directory for getLocalRepoPath)
        $this->db->update('repositories', [
            'name' => $safeName,
            'path' => $newPath,
        ], 'id = ?', [$id]);

        // Refresh storage paths for the agent
        $agent = $this->db->fetchOne("SELECT * FROM agents WHERE id = ?", [$agentId]);
        if ($agent && !empty($agent['ssh_unix_user'])) {
            $this->updateAgentStoragePaths($agentId, $agent);
        }

        $this->db->insert('server_log', [
            'agent_id' => $agentId,
            'level' => 'info',
            'message' => "Repository renamed from \"{$repo['name']}\" to \"{$safeName}\"",
        ]);

        $this->flash('success', "Repository renamed to \"{$safeName}\".");
        $this->redirect("/clients/{$agentId}/repo/{$id}");
    }

    private function generatePassphrase(): string
    {
        $segments = [];
        for ($i = 0; $i < 5; $i++) {
            $segments[] = strtoupper(substr(bin2hex(random_bytes(3)), 0, 4));
        }
        return implode('-', $segments);
    }

    /**
     * Update .storage-paths file for an agent (used by bbs-ssh-gate to allow borg access
     * to storage locations outside the agent's SSH home directory). Gathers all unique
     * storage location agent directories and writes them via bbs-ssh-helper.
     */
    private function updateAgentStoragePaths(int $agentId, array $agent): void
    {
        // Get agent's home directory from stored ssh_home_dir
        $homeDir = $agent['ssh_home_dir'] ?? null;
        if (!$homeDir) {
            return; // No SSH provisioned — can't update storage paths
        }

        // The parent of the home dir (e.g., /var/bbs/home from /var/bbs/home/3)
        // bbs-ssh-gate already allows access to $homeDir, so any storage location
        // under the same parent is already accessible. We only need to add paths
        // for locations on different base paths.
        $homeParent = rtrim(dirname($homeDir), '/');

        // Find all storage locations that have local repos for this agent
        $locations = $this->db->fetchAll(
            "SELECT DISTINCT sl.path FROM repositories r
             JOIN storage_locations sl ON sl.id = r.storage_location_id
             WHERE r.agent_id = ? AND r.storage_type = 'local'",
            [$agentId]
        );

        // Build agent-specific paths for locations outside the home dir's parent
        $paths = [];
        foreach ($locations as $loc) {
            $locPath = rtrim($loc['path'], '/');
            if ($locPath === $homeParent) continue; // Already allowed via home dir
            $paths[] = $locPath . '/' . $agentId;
        }

        // Call bbs-ssh-helper to write the paths file
        $cmd = ['sudo', '/usr/local/bin/bbs-ssh-helper', 'update-storage-paths', $homeDir];
        foreach ($paths as $p) {
            $cmd[] = $p;
        }
        exec(implode(' ', array_map('escapeshellarg', $cmd)) . ' 2>&1', $output, $ret);
        if ($ret !== 0) {
            $this->db->insert('server_log', [
                'agent_id' => $agentId,
                'level' => 'warning',
                'message' => "update-storage-paths failed: " . implode(' ', $output),
            ]);
        }
    }

    /**
     * Queue a repository maintenance task (check, compact, repair, break_lock).
     */
    /**
     * GET /clients/{agentId}/repo/{id}/archive/{archiveId}
     * Show detailed stats for a single recovery point.
     */
    public function archiveDetail(int $agentId, int $id, int $archiveId): void
    {
        $this->requireAuth();

        $repo = $this->db->fetchOne("SELECT r.* FROM repositories r WHERE r.id = ? AND r.agent_id = ?", [$id, $agentId]);
        if (!$repo || !$this->canAccessAgent($agentId)) {
            $this->flash('danger', 'Repository not found.');
            $this->redirect('/clients');
        }

        $agent = $this->db->fetchOne("SELECT * FROM agents WHERE id = ?", [$agentId]);

        $archive = $this->db->fetchOne("SELECT * FROM archives WHERE id = ? AND repository_id = ?", [$archiveId, $id]);
        if (!$archive) {
            $this->flash('danger', 'Archive not found.');
            $this->redirect("/clients/{$agentId}/repo/{$id}");
        }

        // Resolve plan name and job info
        $planName = null;
        $jobInfo = null;
        if (!empty($archive['backup_job_id'])) {
            $jobInfo = $this->db->fetchOne("
                SELECT bj.started_at, bj.completed_at, bj.duration_seconds, bp.directories, bp.name AS plan_name
                FROM backup_jobs bj
                LEFT JOIN backup_plans bp ON bp.id = bj.backup_plan_id
                WHERE bj.id = ?
            ", [$archive['backup_job_id']]);
            $planName = $jobInfo['plan_name'] ?? null;
        }

        // Previous archive for deleted-files comparison
        $prevArchive = $this->db->fetchOne("
            SELECT id, archive_name, created_at FROM archives
            WHERE repository_id = ? AND created_at < ?
            ORDER BY created_at DESC LIMIT 1
        ", [$id, $archive['created_at']]);

        // ClickHouse stats — only the queries that finish quickly run inline.
        // The "deleted vs previous archive" summary used to run a large
        // anti-join here and could add multiple seconds on archives with
        // millions of files, blocking initial page render. It's now served
        // by a separate AJAX endpoint (archiveDeletedSummary).
        $statusBreakdown = [];
        $largestFiles = [];
        $clickhouseAvailable = false;

        try {
            $ch = \BBS\Core\ClickHouse::getInstance();
            $clickhouseAvailable = $ch->isAvailable();
        } catch (\Exception $e) {
            // ClickHouse unreachable — both stat panels just stay empty.
        }

        if ($clickhouseAvailable && isset($ch)) {
            $aid = (int) $agentId;
            $arid = (int) $archiveId;

            // Files by status — cheap: scans only this archive, groups by
            // status. Wrapped in its own try so a failure here doesn't also
            // sink the Largest Files panel (or vice versa).
            try {
                $statusBreakdown = $ch->fetchAll(
                    "SELECT status, count() as cnt, sum(file_size) as total_size
                     FROM file_catalog
                     WHERE agent_id = {$aid} AND archive_id = {$arid} AND path != ''
                     GROUP BY status ORDER BY cnt DESC"
                );
            } catch (\Exception $e) {
                error_log("archiveDetail statusBreakdown query failed (agent_id={$aid}, archive_id={$arid}): " . $e->getMessage());
            }

            // Largest files. Exclude status='X' (entries borg saw but skipped
            // via exclude patterns) so they don't dominate the list — their
            // size is irrelevant to the archive (#132). Cast to FixedString
            // to keep strict ClickHouse versions happy with the comparison
            // against the FixedString(1) status column. Routed through
            // fetchAllOrdered to dodge the CH 26.5 ORDER BY..LIMIT bug (#301).
            try {
                $largestFiles = $ch->fetchAllOrdered(
                    "SELECT path, file_name, file_size, status
                     FROM file_catalog
                     WHERE agent_id = {$aid} AND archive_id = {$arid} AND path != ''
                       AND status != toFixedString('X', 1)
                     ORDER BY file_size DESC LIMIT 20"
                );
            } catch (\Exception $e) {
                error_log("archiveDetail largestFiles query failed (agent_id={$aid}, archive_id={$arid}): " . $e->getMessage());
            }
        }

        $this->view('repositories/archive_detail', [
            'pageTitle' => $planName ? $planName . ' — ' . $archive['archive_name'] : $archive['archive_name'],
            'repo' => $repo,
            'agent' => $agent,
            'agentId' => $agentId,
            'archive' => $archive,
            'archiveId' => $archiveId,
            'planName' => $planName,
            'jobInfo' => $jobInfo,
            'prevArchive' => $prevArchive,
            'statusBreakdown' => $statusBreakdown,
            'largestFiles' => $largestFiles,
            'clickhouseAvailable' => $clickhouseAvailable,
        ]);
    }

    /**
     * GET /clients/{agentId}/repo/{id}/archive/{archiveId}/deleted-summary
     * Returns {count, size} of files that existed in the previous archive
     * but not this one. Deferred from the initial page render because the
     * anti-join can be slow on large archives.
     */
    public function archiveDeletedSummary(int $agentId, int $id, int $archiveId): void
    {
        $this->requireAuth();
        if (!$this->canAccessAgent($agentId)) {
            $this->json(['error' => 'forbidden'], 403);
        }

        $archive = $this->db->fetchOne(
            "SELECT id, created_at FROM archives WHERE id = ? AND repository_id = ?",
            [$archiveId, $id]
        );
        if (!$archive) {
            $this->json(['error' => 'not_found'], 404);
        }

        $prevArchive = $this->db->fetchOne("
            SELECT id FROM archives
            WHERE repository_id = ? AND created_at < ?
            ORDER BY created_at DESC LIMIT 1
        ", [$id, $archive['created_at']]);

        if (!$prevArchive) {
            $this->json(['count' => 0, 'size' => 0, 'has_prev' => false]);
        }

        try {
            $ch = \BBS\Core\ClickHouse::getInstance();
            if (!$ch->isAvailable()) {
                $this->json(['count' => 0, 'size' => 0, 'has_prev' => true, 'error' => 'clickhouse_unavailable']);
            }
            $aid  = (int) $agentId;
            $curr = (int) $archiveId;
            $prev = (int) $prevArchive['id'];

            // LEFT ANTI JOIN is what ClickHouse wants here — scans both archives
            // once and streams paths that don't appear in the current archive.
            // Much cheaper than NOT IN with a subquery on millions of rows.
            $row = $ch->fetchOne(
                "SELECT count() AS cnt, sum(file_size) AS total_size
                 FROM (
                     SELECT path, file_size
                     FROM file_catalog
                     WHERE agent_id = {$aid} AND archive_id = {$prev} AND path != ''
                 ) AS prev
                 LEFT ANTI JOIN (
                     SELECT path
                     FROM file_catalog
                     WHERE agent_id = {$aid} AND archive_id = {$curr} AND path != ''
                 ) AS curr USING (path)"
            );
            $this->json([
                'count'    => (int) ($row['cnt'] ?? 0),
                'size'     => (int) ($row['total_size'] ?? 0),
                'has_prev' => true,
            ]);
        } catch (\Exception $e) {
            $this->json(['count' => 0, 'size' => 0, 'has_prev' => true, 'error' => 'clickhouse_error']);
        }
    }

    /**
     * GET /clients/{agentId}/repo/{id}/archive/{archiveId}/files
     * AJAX endpoint: paginated file list from ClickHouse with status filter + search.
     */
    public function archiveFiles(int $agentId, int $id, int $archiveId): void
    {
        $this->requireAuth();

        $repo = $this->db->fetchOne("SELECT r.* FROM repositories r WHERE r.id = ? AND r.agent_id = ?", [$id, $agentId]);
        if (!$repo || !$this->canAccessAgent($agentId)) {
            $this->json(['error' => 'Not found'], 404);
            return;
        }

        $archive = $this->db->fetchOne("SELECT id FROM archives WHERE id = ? AND repository_id = ?", [$archiveId, $id]);
        if (!$archive) {
            $this->json(['error' => 'Archive not found'], 404);
            return;
        }

        $status = $_GET['status'] ?? '';
        $search = trim($_GET['search'] ?? '');
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $perPage = min(100, max(10, (int) ($_GET['per_page'] ?? 50)));
        $offset = ($page - 1) * $perPage;
        $prevArchiveId = !empty($_GET['prev_archive_id']) ? (int) $_GET['prev_archive_id'] : 0;

        $aid = (int) $agentId;
        $arid = (int) $archiveId;

        try {
            $ch = \BBS\Core\ClickHouse::getInstance();
            if (!$ch->isAvailable()) {
                $this->json(['files' => [], 'total' => 0]);
            }

            // All variable values are bound via the ClickHouse adapter's `?`
            // parameter binding to prevent injection. Integer IDs are still
            // interpolated directly (ints are cast above, safe by design).
            $searchPattern = $search !== '' ? '%' . $search . '%' : null;

            // Deleted files = present in previous archive but not in current
            if ($status === 'deleted' && $prevArchiveId > 0) {
                $where = "agent_id = {$aid} AND archive_id = {$prevArchiveId} AND path != ''
                          AND path NOT IN (SELECT path FROM file_catalog WHERE agent_id = {$aid} AND archive_id = {$arid})";
                $params = [];
                if ($searchPattern !== null) {
                    $where .= " AND path LIKE ?";
                    $params[] = $searchPattern;
                }

                $countRow = $ch->fetchOne("SELECT count() as cnt FROM file_catalog WHERE {$where}", $params);
                $total = (int) ($countRow['cnt'] ?? 0);

                $files = $ch->fetchAllOrdered("SELECT path, file_name, file_size, 'deleted' as status FROM file_catalog WHERE {$where} ORDER BY path LIMIT {$perPage} OFFSET {$offset}", $params);
            } else {
                $where = "agent_id = {$aid} AND archive_id = {$arid} AND path != ''";
                $params = [];
                // Filter out non-file statuses unless specifically requested
                $nonFileStatuses = ['D', 'S', 'H', 'X', 'B', 'F', 'E'];
                if ($status !== '' && !in_array($status, $nonFileStatuses)) {
                    $where .= " AND status = ?";
                    $params[] = $status;
                } elseif ($status === '') {
                    // "All" tab: only show real files
                    $where .= " AND status NOT IN ('D','S','H','X','B','F','E')";
                }
                if ($searchPattern !== null) {
                    $where .= " AND path LIKE ?";
                    $params[] = $searchPattern;
                }

                $countRow = $ch->fetchOne("SELECT count() as cnt FROM file_catalog WHERE {$where}", $params);
                $total = (int) ($countRow['cnt'] ?? 0);

                $files = $ch->fetchAllOrdered("SELECT path, file_name, file_size, status FROM file_catalog WHERE {$where} ORDER BY path LIMIT {$perPage} OFFSET {$offset}", $params);
            }

            $this->json(['files' => $files, 'total' => $total, 'page' => $page]);
        } catch (\Exception $e) {
            $this->json(['files' => [], 'total' => 0, 'error' => $e->getMessage()]);
        }
    }

    public function deleteArchive(int $agentId, int $id, int $archiveId): void
    {
        $this->requireAuth();
        $this->verifyCsrf();

        $repo = $this->db->fetchOne("SELECT r.* FROM repositories r WHERE r.id = ? AND r.agent_id = ?", [$id, $agentId]);
        if (!$repo || !$this->canAccessAgent($agentId)) {
            $this->flash('danger', 'Repository not found.');
            $this->redirect('/clients');
        }

        $this->requirePermission(PermissionService::MANAGE_REPOS, $agentId);

        $archive = $this->db->fetchOne("SELECT * FROM archives WHERE id = ? AND repository_id = ?", [$archiveId, $id]);
        if (!$archive) {
            $this->flash('danger', 'Archive not found.');
            $this->redirect("/clients/{$agentId}/repo/{$id}");
        }

        // Check for existing delete job for this archive
        $existing = $this->db->fetchOne(
            "SELECT id FROM backup_jobs WHERE repository_id = ? AND task_type = 'archive_delete' AND status IN ('queued', 'sent', 'running') AND status_message = ?",
            [$id, $archive['archive_name']]
        );
        if ($existing) {
            $this->flash('warning', 'A delete job is already queued for this archive.');
            $this->redirect("/clients/{$agentId}/repo/{$id}");
        }

        $jobId = $this->db->insert('backup_jobs', [
            'agent_id' => $agentId,
            'repository_id' => $id,
            'task_type' => 'archive_delete',
            'status' => 'queued',
            'status_message' => $archive['archive_name'],
        ]);

        $this->db->insert('server_log', [
            'agent_id' => $agentId,
            'backup_job_id' => $jobId,
            'level' => 'info',
            'message' => "Archive delete queued: {$archive['archive_name']} from repo \"{$repo['name']}\"",
        ]);

        $this->flash('success', "Archive deletion queued for \"{$archive['archive_name']}\". It will run when a slot is available.");
        $this->redirect("/clients/{$agentId}/repo/{$id}");
    }

    public function maintenance(int $id): void
    {
        $this->requireAuth();
        $this->verifyCsrf();

        $action = $_POST['action'] ?? '';
        $validActions = ['check', 'compact', 'repair', 'break_lock', 'catalog_rebuild', 'catalog_rebuild_full'];
        if (!in_array($action, $validActions)) {
            $this->flash('danger', 'Invalid maintenance action.');
            $this->redirect('/clients');
        }

        $repo = $this->db->fetchOne("
            SELECT r.*, a.id as agent_id
            FROM repositories r
            JOIN agents a ON a.id = r.agent_id
            WHERE r.id = ?
        ", [$id]);

        if (!$repo || !$this->canAccessAgent($repo['agent_id'])) {
            $this->flash('danger', 'Repository not found.');
            $this->redirect('/clients');
        }

        // Require repo_maintenance permission
        $this->requirePermission(PermissionService::REPO_MAINTENANCE, $repo['agent_id']);

        // Map action to task_type
        // "Rebuild Full" dispatches as catalog_sync which wipes archives,
        // re-reads them from borg (with sizes), then auto-queues catalog_rebuild
        $taskType = match($action) {
            'check' => 'repo_check',
            'compact' => 'compact',
            'repair' => 'repo_repair',
            'break_lock' => 'break_lock',
            'catalog_rebuild' => 'catalog_rebuild',
            'catalog_rebuild_full' => 'catalog_sync',
            default => null,
        };

        $actionLabel = match($action) {
            'check' => 'Check',
            'compact' => 'Compact',
            'repair' => 'Repair',
            'break_lock' => 'Break Lock',
            'catalog_rebuild' => 'Rebuild Catalog (Missing)',
            'catalog_rebuild_full' => 'Rebuild Catalog (Full)',
            default => $action,
        };

        // Prevent queuing duplicate maintenance of the same type (different types can queue)
        $duplicateJob = $this->db->fetchOne(
            "SELECT id FROM backup_jobs WHERE repository_id = ? AND task_type = ? AND status IN ('queued', 'sent', 'running')",
            [$id, $taskType]
        );
        if ($duplicateJob) {
            $this->flash('warning', "A {$actionLabel} job is already queued or running for this repository (#" . $duplicateJob['id'] . ').');
            $this->redirect("/clients/{$repo['agent_id']}?tab=repos");
        }

        // Queue the job
        $jobId = $this->db->insert('backup_jobs', [
            'agent_id' => $repo['agent_id'],
            'repository_id' => $id,
            'task_type' => $taskType,
            'status' => 'queued',
        ]);

        $this->db->insert('server_log', [
            'agent_id' => $repo['agent_id'],
            'backup_job_id' => $jobId,
            'level' => 'info',
            'message' => "{$actionLabel} job #{$jobId} queued for repository \"{$repo['name']}\"",
        ]);

        $this->flash('success', "{$actionLabel} job queued for repository \"{$repo['name']}\".");
        $this->redirect("/clients/{$repo['agent_id']}?tab=repos");
    }

    /**
     * Repository detail page.
     */
    public function detail(int $agentId, int $id): void
    {
        $this->requireAuth();

        $repo = $this->db->fetchOne("
            SELECT r.*, a.name as agent_name, a.ssh_unix_user,
                   rsc.name as remote_config_name, rsc.remote_host, rsc.remote_user, rsc.remote_port
            FROM repositories r
            JOIN agents a ON a.id = r.agent_id
            LEFT JOIN remote_ssh_configs rsc ON rsc.id = r.remote_ssh_config_id
            WHERE r.id = ? AND r.agent_id = ?
        ", [$id, $agentId]);

        if (!$repo || !$this->canAccessAgent($agentId)) {
            $this->flash('danger', 'Repository not found.');
            $this->redirect('/clients');
        }

        // Get archives for this repo, with plan name resolved through backup_jobs
        $archives = $this->db->fetchAll("
            SELECT ar.*, bp.name AS plan_name
            FROM archives ar
            LEFT JOIN backup_jobs bj ON bj.id = ar.backup_job_id
            LEFT JOIN backup_plans bp ON bp.id = bj.backup_plan_id
            WHERE ar.repository_id = ?
            ORDER BY ar.created_at DESC
        ", [$id]);

        // Backfill file_count from ClickHouse for archives that show 0
        // (imported repos before the nfiles fix)
        $zeroCountIds = array_filter(array_column($archives, 'id', 'id'), function ($aid) use ($archives) {
            foreach ($archives as $a) {
                if ($a['id'] == $aid && (int) $a['file_count'] === 0) return true;
            }
            return false;
        });
        if (!empty($zeroCountIds)) {
            try {
                $ch = \BBS\Core\ClickHouse::getInstance();
                if ($ch->isAvailable()) {
                    $idList = implode(',', array_map('intval', array_keys($zeroCountIds)));
                    $chCounts = $ch->fetchAll("SELECT archive_id, count() as cnt FROM file_catalog WHERE archive_id IN ({$idList}) AND path != '' GROUP BY archive_id");
                    $countMap = [];
                    foreach ($chCounts as $row) {
                        $countMap[(int) $row['archive_id']] = (int) $row['cnt'];
                    }
                    foreach ($archives as &$ar) {
                        if ((int) $ar['file_count'] === 0 && isset($countMap[(int) $ar['id']]) && $countMap[(int) $ar['id']] > 0) {
                            $ar['file_count'] = $countMap[(int) $ar['id']];
                            $this->db->update('archives', ['file_count' => $ar['file_count']], 'id = ?', [$ar['id']]);
                        }
                    }
                    unset($ar);
                }
            } catch (\Exception $e) { /* ClickHouse unavailable — leave as 0 */ }
        }

        // Get plans using this repo
        $plans = $this->db->fetchAll("
            SELECT bp.*, s.enabled as schedule_enabled
            FROM backup_plans bp
            LEFT JOIN schedules s ON s.backup_plan_id = bp.id
            WHERE bp.repository_id = ?
        ", [$id]);

        // Get recent jobs for this repo
        $recentJobs = $this->db->fetchAll("
            SELECT * FROM backup_jobs
            WHERE repository_id = ?
            ORDER BY queued_at DESC LIMIT 20
        ", [$id]);

        // S3 destinations linked to this repo (a repo can replicate to
        // several, #263) — only for local repos
        $s3SyncConfigs = [];
        $s3PluginConfigs = [];
        if (($repo['storage_type'] ?? 'local') === 'local') {
            $s3SyncConfigs = $this->db->fetchAll("
                SELECT rsc.plugin_config_id, pc.name as config_name,
                       rsc.last_sync_at as last_s3_sync, rsc.enabled
                FROM repository_s3_configs rsc
                JOIN plugin_configs pc ON pc.id = rsc.plugin_config_id
                WHERE rsc.repository_id = ?
                ORDER BY pc.name
            ", [$id]);

            // S3 plugin configs for this agent not yet linked to this repo
            // (candidates for "Add destination")
            $s3PluginConfigs = $this->db->fetchAll("
                SELECT pc.id, pc.name
                FROM plugin_configs pc
                JOIN plugins p ON p.id = pc.plugin_id
                WHERE p.slug = 's3_sync' AND pc.agent_id = ?
                  AND pc.id NOT IN (SELECT plugin_config_id FROM repository_s3_configs WHERE repository_id = ?)
                ORDER BY pc.name
            ", [$agentId, $id]);
        }

        // Check for active jobs on this repo
        $activeJob = $this->db->fetchOne(
            "SELECT id, task_type, status FROM backup_jobs WHERE repository_id = ? AND status IN ('queued', 'sent', 'running')",
            [$id]
        );

        // Get local path for display (null for remote repos)
        $localPath = BorgCommandBuilder::getLocalRepoPath($repo);

        // Calculate stats
        $totalSize = (int) $repo['size_bytes'];
        $archiveCount = (int) $repo['archive_count'];
        $oldestArchive = $this->db->fetchOne("SELECT MIN(created_at) as oldest FROM archives WHERE repository_id = ?", [$id]);
        $newestArchive = $this->db->fetchOne("SELECT MAX(created_at) as newest FROM archives WHERE repository_id = ?", [$id]);

        // Dedup stats from archives
        $dedupStats = $this->db->fetchOne(
            "SELECT SUM(original_size) as total_original, SUM(deduplicated_size) as total_dedup FROM archives WHERE repository_id = ?",
            [$id]
        );

        // Get agent's borg_version for display (repo columns may not be populated yet)
        $agentInfo = $this->db->fetchOne("SELECT borg_version FROM agents WHERE id = ?", [$agentId]);

        $repoPassphrase = $repo['passphrase_encrypted'] ? Encryption::decrypt($repo['passphrase_encrypted']) : null;

        // Get storage location label for display
        $storageLocationLabel = null;
        if (!empty($repo['storage_location_id'])) {
            $sloc = $this->db->fetchOne("SELECT label FROM storage_locations WHERE id = ?", [$repo['storage_location_id']]);
            $storageLocationLabel = $sloc['label'] ?? null;
        }

        $this->view('repositories/detail', [
            'pageTitle' => $repo['name'],
            'repo' => $repo,
            'repoPassphrase' => $repoPassphrase,
            'storageLocationLabel' => $storageLocationLabel,
            'agentId' => $agentId,
            'localPath' => $localPath,
            'archives' => $archives,
            'plans' => $plans,
            'recentJobs' => $recentJobs,
            's3SyncConfigs' => $s3SyncConfigs,
            's3PluginConfigs' => $s3PluginConfigs,
            'activeJob' => $activeJob,
            'totalSize' => $totalSize,
            'archiveCount' => $archiveCount,
            'oldestArchive' => $oldestArchive['oldest'] ?? null,
            'newestArchive' => $newestArchive['newest'] ?? null,
            'totalOriginal' => (int) ($dedupStats['total_original'] ?? 0),
            'totalDedup' => (int) ($dedupStats['total_dedup'] ?? 0),
            'agentBorgVersion' => $agentInfo['borg_version'] ?? null,
        ]);
    }

    /**
     * Queue S3 restore job.
     * Modes: 'replace' (default) - overwrites existing local data
     *        'copy' - creates a new repository with the S3 data
     */
    public function s3Restore(int $agentId, int $id): void
    {
        $this->requireAuth();
        $this->verifyCsrf();

        $mode = $_POST['mode'] ?? 'replace';

        $repo = $this->db->fetchOne("
            SELECT r.*, a.name as agent_name, a.ssh_unix_user, a.server_host_override
            FROM repositories r
            JOIN agents a ON a.id = r.agent_id
            WHERE r.id = ? AND r.agent_id = ?
        ", [$id, $agentId]);

        if (!$repo || !$this->canAccessAgent($agentId)) {
            $this->flash('danger', 'Repository not found.');
            $this->redirect('/clients');
        }

        // Require repo_maintenance permission for S3 restore
        $this->requirePermission(PermissionService::REPO_MAINTENANCE, $agentId);

        // Which destination to restore from: the posted one, validated
        // against this repo's links — or the repo's only destination
        $requestedConfigId = (int) ($_POST['plugin_config_id'] ?? 0);
        if ($requestedConfigId > 0) {
            $s3Config = $this->db->fetchOne("
                SELECT plugin_config_id
                FROM repository_s3_configs
                WHERE repository_id = ? AND plugin_config_id = ?
            ", [$id, $requestedConfigId]);
        } else {
            $links = $this->db->fetchAll("
                SELECT plugin_config_id
                FROM repository_s3_configs
                WHERE repository_id = ?
            ", [$id]);
            if (count($links) > 1) {
                $this->flash('danger', 'This repository syncs to multiple S3 destinations — pick which one to restore from.');
                $this->redirect("/clients/{$agentId}/repo/{$id}");
            }
            $s3Config = $links[0] ?? null;
        }

        if (!$s3Config) {
            $this->flash('danger', 'This repository does not have S3 sync configured.');
            $this->redirect("/clients/{$agentId}/repo/{$id}");
        }

        // For 'copy' mode, create a new repository first
        $targetRepoId = $id;
        $targetRepoName = $repo['name'];
        if ($mode === 'copy') {
            // Use provided name or generate unique name for the copy
            $copyName = trim($_POST['copy_name'] ?? '');
            if (empty($copyName)) {
                $copyName = $repo['name'] . '-copy';
            }

            // Check if name already exists
            if ($this->db->fetchOne("SELECT id FROM repositories WHERE agent_id = ? AND name = ?", [$agentId, $copyName])) {
                $this->flash('danger', "Repository \"{$copyName}\" already exists. Choose a different name.");
                $this->redirect("/clients/{$agentId}/repo/{$id}");
            }

            // Build path for the copy (use same storage location as source repo)
            $copyLocId = $repo['storage_location_id'] ?? null;
            $copyLoc = $copyLocId ? $this->db->fetchOne("SELECT * FROM storage_locations WHERE id = ?", [$copyLocId]) : null;
            if (!$copyLoc) {
                $copyLoc = $this->db->fetchOne("SELECT * FROM storage_locations WHERE is_default = 1");
            }
            $copyStoragePath = $copyLoc['path'] ?? '';
            if (empty($copyStoragePath)) {
                $storageSetting = $this->db->fetchOne("SELECT `value` FROM settings WHERE `key` = 'storage_path'");
                $copyStoragePath = $storageSetting['value'] ?? '';
            }
            $serverHost = $this->db->fetchOne("SELECT `value` FROM settings WHERE `key` = 'server_host'");
            $host = !empty($repo['server_host_override']) ? $repo['server_host_override'] : ($serverHost['value'] ?? '');

            $storageSetting2 = $this->db->fetchOne("SELECT `value` FROM settings WHERE `key` = 'storage_path'");
            $sshHomePath2 = rtrim($storageSetting2['value'] ?? '/var/bbs/home', '/');
            $copyIsNonDefault = rtrim($copyStoragePath, '/') !== $sshHomePath2;

            if ($copyIsNonDefault) {
                $localCopyPath = rtrim($copyStoragePath, '/') . '/' . $agentId . '/' . $copyName;
                if (!empty($repo['ssh_unix_user']) && !empty($host)) {
                    $sshHost2 = SshKeyManager::stripHostPort($host);
                    $copyPath = "ssh://{$repo['ssh_unix_user']}@{$sshHost2}//{$localCopyPath}";
                } else {
                    $copyPath = $localCopyPath;
                }
            } else {
                if (!empty($repo['ssh_unix_user']) && !empty($host)) {
                    $copyPath = SshKeyManager::buildSshRepoPath($repo['ssh_unix_user'], $host, $copyName);
                } else {
                    $copyPath = rtrim($copyStoragePath, '/') . '/' . $agentId . '/' . $copyName;
                }
            }

            // Create the new repository record
            $targetRepoId = $this->db->insert('repositories', [
                'agent_id' => $agentId,
                'storage_location_id' => $copyLoc['id'] ?? null,
                'name' => $copyName,
                'path' => $copyPath,
                'encryption' => $repo['encryption'],
                'passphrase_encrypted' => $repo['passphrase_encrypted'],
            ]);
            $targetRepoName = $copyName;

            // Create local directory via SSH helper
            $localPath = BorgCommandBuilder::getLocalRepoPath(['path' => $copyPath, 'agent_id' => $agentId, 'name' => $copyName, 'storage_location_id' => $copyLoc['id'] ?? null]);
            $helperCmd = ['sudo', '/usr/local/bin/bbs-ssh-helper', 'create-repo-dir', $localPath];
            exec(implode(' ', array_map('escapeshellarg', $helperCmd)) . ' 2>&1', $helperOutput, $helperRet);
            if ($helperRet !== 0) {
                $this->db->insert('server_log', [
                    'agent_id' => $agentId,
                    'level' => 'warning',
                    'message' => "create-repo-dir helper failed for S3 copy restore: " . implode(' ', $helperOutput),
                ]);
            }

            $this->db->insert('server_log', [
                'agent_id' => $agentId,
                'level' => 'info',
                'message' => "Created repository \"{$copyName}\" as copy target for S3 restore",
            ]);
        } else {
            // For 'replace' mode, check for active jobs on this repo
            $activeJob = $this->db->fetchOne(
                "SELECT id, task_type FROM backup_jobs WHERE repository_id = ? AND status IN ('queued', 'sent', 'running')",
                [$id]
            );
            if ($activeJob) {
                $this->flash('warning', "Cannot restore from S3 — repository has an active {$activeJob['task_type']} job (#" . $activeJob['id'] . ').');
                $this->redirect("/clients/{$agentId}/repo/{$id}");
            }
        }

        // Queue the S3 restore job on the target repo
        // For copy mode, source_repository_id tells the restore where to pull S3 data from
        $jobData = [
            'agent_id' => $agentId,
            'repository_id' => $targetRepoId,
            'task_type' => 's3_restore',
            'plugin_config_id' => $s3Config['plugin_config_id'],
            'status' => 'queued',
        ];
        if ($mode === 'copy') {
            $jobData['source_repository_id'] = $id;  // Original repo
        }
        $jobId = $this->db->insert('backup_jobs', $jobData);

        $modeLabel = $mode === 'copy' ? 'copy' : 'replace';
        $this->db->insert('server_log', [
            'agent_id' => $agentId,
            'backup_job_id' => $jobId,
            'level' => 'info',
            'message' => "S3 restore ({$modeLabel}) job #{$jobId} queued for repository \"{$targetRepoName}\"",
        ]);

        $this->flash('success', "S3 restore ({$modeLabel}) job queued for repository \"{$targetRepoName}\".");
        $this->redirect("/clients/{$agentId}/repo/{$targetRepoId}");
    }

    /**
     * Restore an orphaned repository from S3 (exists in S3 but not locally).
     */
    public function restoreOrphan(int $id): void
    {
        $this->requireAuth();
        $this->verifyCsrf();

        $repoName = trim($_POST['repo_name'] ?? '');
        $pluginConfigId = (int) ($_POST['plugin_config_id'] ?? 0);

        if (empty($repoName) || $pluginConfigId === 0) {
            $this->flash('danger', 'Invalid restore request.');
            $this->redirect("/clients/{$id}?tab=repos");
        }

        // Verify agent access
        $agent = $this->db->fetchOne("SELECT * FROM agents WHERE id = ?", [$id]);
        if (!$agent || !$this->canAccessAgent($id)) {
            $this->flash('danger', 'Access denied.');
            $this->redirect('/clients');
        }

        // Require manage_repos permission
        $this->requirePermission(PermissionService::MANAGE_REPOS, $id);

        // Check if repo already exists
        $existing = $this->db->fetchOne(
            "SELECT id FROM repositories WHERE agent_id = ? AND name = ?",
            [$id, $repoName]
        );
        if ($existing) {
            $this->flash('warning', "Repository \"{$repoName}\" already exists.");
            $this->redirect("/clients/{$id}?tab=repos");
        }

        // Get plugin config and resolve credentials
        $pluginConfig = $this->db->fetchOne("SELECT config FROM plugin_configs WHERE id = ?", [$pluginConfigId]);
        if (!$pluginConfig) {
            $this->flash('danger', 'S3 configuration not found.');
            $this->redirect("/clients/{$id}?tab=repos");
        }

        // Build repo path using default storage location
        $defaultLoc = $this->db->fetchOne("SELECT * FROM storage_locations WHERE is_default = 1");
        $storagePath = $defaultLoc['path'] ?? '';
        if (empty($storagePath)) {
            $storageSetting = $this->db->fetchOne("SELECT `value` FROM settings WHERE `key` = 'storage_path'");
            $storagePath = $storageSetting['value'] ?? '';
        }
        $serverHost = $this->db->fetchOne("SELECT `value` FROM settings WHERE `key` = 'server_host'");
        $host = !empty($agent['server_host_override']) ? $agent['server_host_override'] : ($serverHost['value'] ?? '');

        if (!empty($agent['ssh_unix_user']) && !empty($host)) {
            $path = SshKeyManager::buildSshRepoPath($agent['ssh_unix_user'], $host, $repoName);
        } else {
            $path = rtrim($storagePath, '/') . '/' . $id . '/' . $repoName;
        }

        // Create the repository record (encryption unknown, will be detected after restore)
        $repoId = $this->db->insert('repositories', [
            'agent_id' => $id,
            'storage_location_id' => $defaultLoc['id'] ?? null,
            'name' => $repoName,
            'path' => $path,
            'encryption' => 'unknown',  // Will be detected by borg after restore
            'passphrase_encrypted' => null,  // Unknown for orphan repos
        ]);

        // Create local directory via SSH helper
        $localPath = BorgCommandBuilder::getLocalRepoPath(['path' => $path, 'agent_id' => $id, 'name' => $repoName, 'storage_location_id' => $defaultLoc['id'] ?? null]);
        $helperCmd = ['sudo', '/usr/local/bin/bbs-ssh-helper', 'create-repo-dir', $localPath];
        exec(implode(' ', array_map('escapeshellarg', $helperCmd)) . ' 2>&1', $helperOutput, $helperRet);
        if ($helperRet !== 0) {
            $this->db->insert('server_log', [
                'agent_id' => $id,
                'level' => 'warning',
                'message' => "create-repo-dir helper failed for orphan restore: " . implode(' ', $helperOutput),
            ]);
        }

        // Queue the S3 restore job
        $jobId = $this->db->insert('backup_jobs', [
            'agent_id' => $id,
            'repository_id' => $repoId,
            'task_type' => 's3_restore',
            'plugin_config_id' => $pluginConfigId,
            'status' => 'queued',
        ]);

        $this->db->insert('server_log', [
            'agent_id' => $id,
            'backup_job_id' => $jobId,
            'level' => 'info',
            'message' => "Restoring orphan repository \"{$repoName}\" from S3 — job #{$jobId} queued",
        ]);

        $this->flash('success', "Repository \"{$repoName}\" created and S3 restore queued.");
        $this->redirect("/clients/{$id}?tab=repos");
    }

    /**
     * Enable or update S3 sync configuration for a repository.
     */
    public function s3Config(int $agentId, int $id): void
    {
        $this->requireAuth();
        $this->verifyCsrf();

        $repo = $this->db->fetchOne("SELECT * FROM repositories WHERE id = ? AND agent_id = ?", [$id, $agentId]);
        if (!$repo || !$this->canAccessAgent($agentId)) {
            $this->flash('danger', 'Repository not found.');
            $this->redirect('/clients');
        }

        $this->requirePermission(PermissionService::MANAGE_REPOS, $agentId);

        $pluginConfigId = (int) ($_POST['plugin_config_id'] ?? 0);
        if ($pluginConfigId === 0) {
            $this->flash('danger', 'Please select an S3 configuration.');
            $this->redirect("/clients/{$agentId}/repo/{$id}");
        }

        // Verify the plugin config exists and belongs to this agent
        $pluginConfig = $this->db->fetchOne(
            "SELECT pc.id, pc.name FROM plugin_configs pc
             JOIN plugins p ON p.id = pc.plugin_id
             WHERE pc.id = ? AND pc.agent_id = ? AND p.slug = 's3_sync'",
            [$pluginConfigId, $agentId]
        );
        if (!$pluginConfig) {
            $this->flash('danger', 'Invalid S3 configuration.');
            $this->redirect("/clients/{$agentId}/repo/{$id}");
        }

        // A repo can replicate to several destinations — add this one if
        // it isn't linked yet, re-enable it if it is
        $existing = $this->db->fetchOne(
            "SELECT id, enabled FROM repository_s3_configs WHERE repository_id = ? AND plugin_config_id = ?",
            [$id, $pluginConfigId]
        );

        if ($existing) {
            $this->db->update('repository_s3_configs', [
                'enabled' => 1,
            ], 'id = ?', [$existing['id']]);
        } else {
            $this->db->insert('repository_s3_configs', [
                'repository_id' => $id,
                'plugin_config_id' => $pluginConfigId,
                'enabled' => 1,
            ]);
        }

        $this->db->insert('server_log', [
            'agent_id' => $agentId,
            'level' => 'info',
            'message' => "S3 sync enabled for repository \"{$repo['name']}\" to destination \"{$pluginConfig['name']}\"",
        ]);

        $this->flash('success', "S3 destination \"{$pluginConfig['name']}\" added for repository \"{$repo['name']}\".");
        $this->redirect("/clients/{$agentId}/repo/{$id}");
    }

    /**
     * Disable S3 sync for a repository (data remains in S3).
     */
    public function s3ConfigDelete(int $agentId, int $id): void
    {
        $this->requireAuth();
        $this->verifyCsrf();

        $repo = $this->db->fetchOne("SELECT * FROM repositories WHERE id = ? AND agent_id = ?", [$id, $agentId]);
        if (!$repo || !$this->canAccessAgent($agentId)) {
            $this->flash('danger', 'Repository not found.');
            $this->redirect('/clients');
        }

        $this->requirePermission(PermissionService::MANAGE_REPOS, $agentId);

        // Remove one destination when specified, all of them otherwise
        // (data remains in the S3 bucket either way)
        $pluginConfigId = (int) ($_POST['plugin_config_id'] ?? 0);
        $destLabel = '';
        if ($pluginConfigId > 0) {
            $dest = $this->db->fetchOne("SELECT name FROM plugin_configs WHERE id = ?", [$pluginConfigId]);
            $destLabel = $dest ? " to \"{$dest['name']}\"" : '';
            $this->db->delete('repository_s3_configs', 'repository_id = ? AND plugin_config_id = ?', [$id, $pluginConfigId]);
        } else {
            $this->db->delete('repository_s3_configs', 'repository_id = ?', [$id]);
        }

        $this->db->insert('server_log', [
            'agent_id' => $agentId,
            'level' => 'info',
            'message' => "S3 sync{$destLabel} disabled for repository \"{$repo['name']}\" (data remains in S3)",
        ]);

        $this->flash('success', "S3 sync{$destLabel} disabled for repository \"{$repo['name']}\". Data remains in S3.");
        $this->redirect("/clients/{$agentId}/repo/{$id}");
    }

    /**
     * AJAX: Verify an existing repository can be imported.
     * POST /repositories/import/verify
     */
    public function verifyImport(): void
    {
        $this->requireAuth();
        // Skip CSRF for AJAX — session auth is sufficient for same-origin POST

        $agentId = (int) ($_POST['agent_id'] ?? 0);
        $storageType = $_POST['storage_type'] ?? 'local';
        $name = $this->sanitizePathName(trim($_POST['name'] ?? ''));
        $passphrase = $_POST['passphrase'] ?? '';
        $storageLocationId = !empty($_POST['storage_location_id']) ? (int) $_POST['storage_location_id'] : null;
        $remoteSshConfigId = !empty($_POST['remote_ssh_config_id']) ? (int) $_POST['remote_ssh_config_id'] : null;

        if (empty($name) || empty($agentId)) {
            $this->json(['status' => 'error', 'error' => 'Repository name and client are required. Names can only contain letters, numbers, hyphens, and underscores.']);
            return;
        }

        $agent = $this->db->fetchOne("SELECT * FROM agents WHERE id = ?", [$agentId]);
        if (!$agent || !$this->canAccessAgent($agentId)) {
            $this->json(['status' => 'error', 'error' => 'Access denied.']);
            return;
        }

        // Check for duplicate name
        $existing = $this->db->fetchOne(
            "SELECT id FROM repositories WHERE agent_id = ? AND name = ?",
            [$agentId, $name]
        );
        if ($existing) {
            $this->json(['status' => 'error', 'error' => "A repository named \"{$name}\" already exists for this client."]);
            return;
        }

        if ($storageType === 'remote_ssh') {
            if (!$remoteSshConfigId) {
                $this->json(['status' => 'error', 'error' => 'Please select a remote SSH host.']);
                return;
            }

            $remoteSshService = new RemoteSshService();
            $config = $remoteSshService->getDecrypted($remoteSshConfigId);
            if (!$config) {
                $this->json(['status' => 'error', 'error' => 'Remote SSH host not found.']);
                return;
            }

            $repoPath = $remoteSshService->buildRepoPath($config, $name);

            $env = [];
            if (!empty($passphrase)) {
                $env['BORG_PASSPHRASE'] = $passphrase;
            }

            $result = $remoteSshService->runBorgCommand($config, $repoPath, ['list', '--json', $repoPath], $passphrase);

            if (!$result['success']) {
                $errorMsg = trim($result['stderr'] ?? $result['output'] ?? 'Unknown error');
                $this->json(['status' => 'error', 'error' => "Cannot access repository: {$errorMsg}"]);
                return;
            }

            $infoData = json_decode($result['output'], true);
        } else {
            // Resolve local path
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
                fwrite($pipes[0], ($passphrase ?? '') . "\n");
                fclose($pipes[0]);
                $output = stream_get_contents($pipes[1]);
                $stderr = stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                $exitCode = proc_close($proc);
            }

            if ($exitCode !== 0) {
                // Prefer stderr — borg writes real errors there and only emits
                // info/cache messages on stdout. Falling back to stdout keeps
                // the path reasonable if stderr happens to be empty.
                $errorMsg = trim($stderr ?: $output);
                if (str_contains($errorMsg, 'passphrase') || str_contains($errorMsg, 'Passphrase')) {
                    $errorMsg = 'Incorrect passphrase for this repository.';
                } elseif (str_contains($errorMsg, 'not a valid repository') || str_contains($errorMsg, 'does not exist') || str_contains($errorMsg, 'Failed to create/acquire')) {
                    $errorMsg = "No valid borg repository found at: {$localPath}";
                }
                $this->json(['status' => 'error', 'error' => $errorMsg ?: 'Failed to verify repository.']);
                return;
            }

            $infoData = json_decode($output, true);
        }

        if (!$infoData) {
            $this->json(['status' => 'error', 'error' => 'Failed to parse repository info. Is this a valid borg repository?']);
            return;
        }

        $encryption = $infoData['encryption']['mode'] ?? 'unknown';
        $archiveCount = count($infoData['archives'] ?? []);

        $this->json([
            'status' => 'ok',
            'encryption' => $encryption,
            'archive_count' => $archiveCount,
        ]);
    }

    /**
     * Import an existing repository.
     * POST /repositories/import
     */
    public function import(): void
    {
        $this->requireAuth();
        $this->verifyCsrf();

        $agentId = (int) ($_POST['agent_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $encryption = $_POST['encryption'] ?? 'unknown';
        $passphrase = $_POST['passphrase'] ?? '';
        $storageType = $_POST['storage_type'] ?? 'local';
        $storageLocationId = !empty($_POST['storage_location_id']) ? (int) $_POST['storage_location_id'] : null;
        $remoteSshConfigId = !empty($_POST['remote_ssh_config_id']) ? (int) $_POST['remote_ssh_config_id'] : null;

        if (empty($name) || empty($agentId)) {
            $this->flash('danger', 'Repository name and client are required.');
            $this->redirect("/clients/{$agentId}");
            return;
        }

        $agent = $this->db->fetchOne("SELECT * FROM agents WHERE id = ?", [$agentId]);
        if (!$agent || !$this->canAccessAgent($agentId)) {
            $this->flash('danger', 'Access denied.');
            $this->redirect('/clients');
            return;
        }
        $this->requirePermission(PermissionService::MANAGE_REPOS, $agentId);

        // Check for duplicate name
        $existing = $this->db->fetchOne(
            "SELECT id FROM repositories WHERE agent_id = ? AND name = ?",
            [$agentId, $name]
        );
        if ($existing) {
            $this->flash('warning', "Repository \"{$name}\" already exists.");
            $this->redirect("/clients/{$agentId}?tab=repos");
            return;
        }

        // Sanitize the name — import uses this for both the directory lookup
        // and the DB record. Leading slashes, special characters, etc. get
        // stripped to match how new repos are created.
        $name = $this->sanitizePathName($name);
        if (empty($name)) {
            $this->flash('danger', 'Repository name must contain at least one alphanumeric character.');
            $this->redirect("/clients/{$agentId}?tab=repos");
            return;
        }

        if ($storageType === 'remote_ssh') {
            $this->importRemoteSsh($agentId, $name, $encryption, $passphrase, $remoteSshConfigId);
        } else {
            $this->importLocal($agentId, $agent, $name, $encryption, $passphrase, $storageLocationId);
        }
    }

    /**
     * Import a local repository that already exists on disk.
     */
    private function importLocal(int $agentId, array $agent, string $name, string $encryption, string $passphrase, ?int $storageLocationId): void
    {
        // Resolve storage location (same logic as storeLocal)
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

        $serverHost = $this->db->fetchOne("SELECT `value` FROM settings WHERE `key` = 'server_host'");
        $host = !empty($agent['server_host_override']) ? $agent['server_host_override'] : ($serverHost['value'] ?? '');

        // Determine if this is a non-default storage location (same logic as storeLocal)
        $locationPath = rtrim($location['path'], '/');
        $sshHomeDir = $agent['ssh_home_dir'] ?? null;
        $sshHomePath = $sshHomeDir ? rtrim(dirname($sshHomeDir), '/') : null;
        $isNonDefault = !$sshHomePath || $locationPath !== $sshHomePath;

        if ($isNonDefault) {
            $localPath = $locationPath . '/' . $agentId . '/' . $name;
            if (!empty($agent['ssh_unix_user']) && !empty($host)) {
                $sshHost = SshKeyManager::stripHostPort($host);
                $path = "ssh://{$agent['ssh_unix_user']}@{$sshHost}//{$localPath}";
            } else {
                $path = $localPath;
            }
        } else {
            if (!empty($agent['ssh_unix_user']) && !empty($host)) {
                $path = SshKeyManager::buildSshRepoPath($agent['ssh_unix_user'], $host, $name);
            } else {
                $path = rtrim($location['path'], '/') . '/' . $agentId . '/' . $name;
            }
            $localPath = rtrim($location['path'], '/') . '/' . $agentId . '/' . $name;
        }

        $repoId = $this->db->insert('repositories', [
            'agent_id' => $agentId,
            'storage_type' => 'local',
            'storage_location_id' => $location['id'] ?? null,
            'name' => $name,
            'path' => $path,
            'encryption' => $encryption,
            'passphrase_encrypted' => ($encryption !== 'none' && !empty($passphrase)) ? Encryption::encrypt($passphrase) : null,
        ]);

        // Fix ownership so the SSH user can access the repo
        if (!empty($agent['ssh_unix_user'])) {
            $fixCmd = ['sudo', '/usr/local/bin/bbs-ssh-helper', 'fix-repo-perms', $localPath, $agent['ssh_unix_user']];
            exec(implode(' ', array_map('escapeshellarg', $fixCmd)) . ' 2>&1', $fixOutput, $fixRet);
            if ($fixRet !== 0) {
                $this->db->insert('server_log', [
                    'agent_id' => $agentId,
                    'level' => 'warning',
                    'message' => "fix-repo-perms failed during import: " . implode(' ', $fixOutput),
                ]);
            }
        }

        // Update .storage-paths so bbs-ssh-gate allows borg access to this location
        if (!empty($agent['ssh_unix_user'])) {
            $this->updateAgentStoragePaths($agentId, $agent);
        }

        // Queue catalog_sync to discover archives and populate file catalog
        $this->db->insert('backup_jobs', [
            'agent_id' => $agentId,
            'repository_id' => $repoId,
            'backup_plan_id' => null,
            'task_type' => 'catalog_sync',
            'status' => 'queued',
        ]);

        $this->db->insert('server_log', [
            'agent_id' => $agentId,
            'level' => 'info',
            'message' => "Repository \"{$name}\" imported ({$encryption}) from {$localPath}",
        ]);

        $this->flash('success', "Repository \"{$name}\" imported successfully. A catalog sync has been queued.");
        $this->redirect("/clients/{$agentId}?tab=repos");
    }

    /**
     * Import a repository from a remote SSH host.
     */
    private function importRemoteSsh(int $agentId, string $name, string $encryption, string $passphrase, ?int $remoteSshConfigId): void
    {
        if (!$remoteSshConfigId) {
            $this->flash('danger', 'Please select a remote SSH host.');
            $this->redirect("/clients/{$agentId}?tab=repos");
            return;
        }

        $remoteSshService = new RemoteSshService();
        $config = $remoteSshService->getById($remoteSshConfigId);
        if (!$config) {
            $this->flash('danger', 'Remote SSH host not found.');
            $this->redirect("/clients/{$agentId}?tab=repos");
            return;
        }

        $repoPath = $remoteSshService->buildRepoPath($config, $name);

        $repoId = $this->db->insert('repositories', [
            'agent_id' => $agentId,
            'storage_type' => 'remote_ssh',
            'remote_ssh_config_id' => $remoteSshConfigId,
            'name' => $name,
            'path' => $repoPath,
            'encryption' => $encryption,
            'passphrase_encrypted' => ($encryption !== 'none' && !empty($passphrase)) ? Encryption::encrypt($passphrase) : null,
        ]);

        // Queue catalog_sync to discover archives
        $this->db->insert('backup_jobs', [
            'agent_id' => $agentId,
            'repository_id' => $repoId,
            'backup_plan_id' => null,
            'task_type' => 'catalog_sync',
            'status' => 'queued',
        ]);

        $this->db->insert('server_log', [
            'agent_id' => $agentId,
            'level' => 'info',
            'message' => "Remote repository \"{$name}\" imported ({$encryption}) from {$config['remote_user']}@{$config['remote_host']}",
        ]);

        $this->flash('success', "Repository \"{$name}\" imported from {$config['remote_host']}. A catalog sync has been queued.");
        $this->redirect("/clients/{$agentId}?tab=repos");
    }

    /**
     * Resolve a local storage location with the same fallback chain used by
     * repo creation and import: explicit id → default row → storage_path setting.
     */
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

    /**
     * Run a bbs-ssh-helper command, returning [exitCode, stdout, stderr].
     */
    private function runHelper(array $args, ?string $stdin = null): array
    {
        $cmd = array_merge(['sudo', '/usr/local/bin/bbs-ssh-helper'], $args);
        $proc = proc_open($cmd, [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes);
        if (!is_resource($proc)) {
            return [-1, '', 'failed to start helper'];
        }
        if ($stdin !== null) {
            fwrite($pipes[0], $stdin);
        }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);
        return [$exit, $stdout, $stderr];
    }

    /**
     * All canonical local-repo directories currently registered, so scans
     * can hide repos BBS already knows about.
     */
    private function registeredLocalRepoPaths(): array
    {
        $default = $this->resolveLocalLocation(null);
        $rows = $this->db->fetchAll(
            "SELECT r.agent_id, r.name, sl.path AS loc_path
             FROM repositories r
             LEFT JOIN storage_locations sl ON sl.id = r.storage_location_id
             WHERE r.storage_type = 'local'"
        );
        $paths = [];
        foreach ($rows as $r) {
            $base = rtrim($r['loc_path'] ?: $default['path'], '/');
            $paths[] = "{$base}/{$r['agent_id']}/{$r['name']}";
        }
        return $paths;
    }

    /**
     * Validate that a scan/adopt source path is inside a configured storage
     * location (or the default storage path) and free of traversal tricks.
     * Returns the normalized path, or null if invalid.
     */
    private function validateSourcePath(string $path): ?string
    {
        $path = rtrim(trim($path), '/');
        if ($path === '' || $path[0] !== '/' || str_contains($path, '..')) {
            return null;
        }
        $bases = array_map(
            fn($l) => rtrim($l['path'], '/'),
            $this->db->fetchAll("SELECT path FROM storage_locations")
        );
        $bases[] = rtrim($this->resolveLocalLocation(null)['path'], '/');
        foreach ($bases as $base) {
            if ($base !== '' && str_starts_with($path . '/', $base . '/')) {
                return $path;
            }
        }
        return null;
    }

    /**
     * AJAX: Scan storage locations for borg repositories BBS doesn't know about.
     * POST /repositories/scan
     */
    public function scanRepos(): void
    {
        $this->requireAuth();
        if (!$this->isAdmin()) {
            $this->json(['status' => 'error', 'error' => 'Admin access required.'], 403);
            return;
        }

        $locations = $this->db->fetchAll("SELECT * FROM storage_locations ORDER BY is_default DESC, label");
        if (empty($locations)) {
            $locations = [$this->resolveLocalLocation(null) + ['label' => 'Default storage']];
        }

        $registered = $this->registeredLocalRepoPaths();
        $candidates = [];

        foreach ($locations as $loc) {
            [$exit, $out, ] = $this->runHelper(['find-repos', rtrim($loc['path'], '/')]);
            if ($exit !== 0) {
                continue; // location missing on disk etc. — skip silently
            }
            foreach (explode("\n", trim($out)) as $line) {
                if ($line === '') continue;
                $repo = json_decode($line, true);
                if (!is_array($repo) || empty($repo['path'])) continue;
                $normalized = rtrim($repo['path'], '/');
                if (in_array($normalized, $registered, true)) continue;

                $candidates[] = [
                    'path' => $normalized,
                    'name' => $repo['name'] ?? basename($normalized),
                    'size_bytes' => (int) ($repo['size_bytes'] ?? 0),
                    'size_label' => \BBS\Services\ServerStats::formatBytes((int) ($repo['size_bytes'] ?? 0)),
                    'modified' => !empty($repo['mtime']) ? date('Y-m-d H:i', (int) $repo['mtime']) : '',
                    'key_in_repo' => (int) ($repo['key_in_repo'] ?? 0) === 1,
                    'storage_location_id' => $loc['id'] ?? null,
                    'location_label' => $loc['label'] ?? '',
                ];
            }
        }

        $this->json(['status' => 'ok', 'candidates' => $candidates]);
    }

    /**
     * AJAX: Verify an adoption candidate and preview the move it requires.
     * Returns a plain-language statement of exactly what will happen —
     * source path, destination path, rename vs copy, sizes, free space.
     * POST /repositories/adopt/verify
     */
    public function verifyAdopt(): void
    {
        $this->requireAuth();
        if (!$this->isAdmin()) {
            $this->json(['status' => 'error', 'error' => 'Admin access required.'], 403);
            return;
        }

        $agentId = (int) ($_POST['agent_id'] ?? 0);
        $name = $this->sanitizePathName(trim($_POST['name'] ?? ''));
        $passphrase = $_POST['passphrase'] ?? '';
        $storageLocationId = !empty($_POST['storage_location_id']) ? (int) $_POST['storage_location_id'] : null;
        $sourcePath = $this->validateSourcePath($_POST['source_path'] ?? '');

        if (empty($name) || empty($agentId)) {
            $this->json(['status' => 'error', 'error' => 'Repository name and client are required. Names can only contain letters, numbers, hyphens, and underscores.']);
            return;
        }
        if ($sourcePath === null) {
            $this->json(['status' => 'error', 'error' => 'Source path must be inside a configured storage location.']);
            return;
        }

        $agent = $this->db->fetchOne("SELECT * FROM agents WHERE id = ?", [$agentId]);
        if (!$agent || !$this->canAccessAgent($agentId)) {
            $this->json(['status' => 'error', 'error' => 'Access denied.']);
            return;
        }

        $existing = $this->db->fetchOne(
            "SELECT id FROM repositories WHERE agent_id = ? AND name = ?",
            [$agentId, $name]
        );
        if ($existing) {
            $this->json(['status' => 'error', 'error' => "A repository named \"{$name}\" already exists for this client."]);
            return;
        }

        // Verify it's a real borg repo and the passphrase works
        [$exit, $out, $err] = $this->runHelper(['verify-repo', '-', $sourcePath], $passphrase . "\n");
        if ($exit !== 0) {
            $errorMsg = trim($err ?: $out);
            if (str_contains($errorMsg, 'passphrase') || str_contains($errorMsg, 'Passphrase')) {
                $errorMsg = 'Incorrect passphrase for this repository.';
            } elseif (stripos($errorMsg, 'key file') !== false || stripos($errorMsg, 'keyfile') !== false) {
                $errorMsg = 'This repository uses keyfile encryption — its key is stored outside the repository '
                    . '(in ~/.config/borg/keys on the machine that created it) and was not found. '
                    . 'Migrate the key file first, or export/import the key with borg key export/import.';
            } elseif (str_contains($errorMsg, 'not a valid repository') || str_contains($errorMsg, 'does not exist')) {
                $errorMsg = "No valid borg repository found at: {$sourcePath}";
            }
            $this->json(['status' => 'error', 'error' => $errorMsg ?: 'Failed to verify repository.']);
            return;
        }

        $infoData = json_decode($out, true);
        if (!$infoData) {
            $this->json(['status' => 'error', 'error' => 'Failed to parse repository info. Is this a valid borg repository?']);
            return;
        }
        $encryption = $infoData['encryption']['mode'] ?? 'unknown';
        if (str_starts_with($encryption, 'keyfile')) {
            $this->json(['status' => 'error', 'error' => 'This repository uses keyfile encryption — the key lives outside the repository, so backups and restores would fail after adoption. Convert it to repokey (borg key change-location) before importing.']);
            return;
        }
        $archiveCount = count($infoData['archives'] ?? []);

        // Destination and move preview
        $location = $this->resolveLocalLocation($storageLocationId);
        $destPath = rtrim($location['path'], '/') . '/' . $agentId . '/' . $name;

        $move = [
            'from' => $sourcePath,
            'to' => $destPath,
            'required' => $sourcePath !== $destPath,
            'same_fs' => true,
            'fits' => true,
            'size_label' => '',
            'free_label' => '',
        ];

        if ($move['required']) {
            [$mExit, $mOut, $mErr] = $this->runHelper(['check-move', $sourcePath, $destPath]);
            $check = $mExit === 0 ? json_decode(trim($mOut), true) : null;
            if (!is_array($check)) {
                $this->json(['status' => 'error', 'error' => 'Could not check the move: ' . trim($mErr ?: $mOut)]);
                return;
            }
            $move['same_fs'] = (int) $check['same_fs'] === 1;
            $move['fits'] = (int) $check['fits'] === 1;
            $move['size_label'] = \BBS\Services\ServerStats::formatBytes((int) $check['src_bytes']);
            $move['free_label'] = \BBS\Services\ServerStats::formatBytes((int) $check['free_bytes']);
        }

        $this->json([
            'status' => 'ok',
            'encryption' => $encryption,
            'archive_count' => $archiveCount,
            'move' => $move,
        ]);
    }

    /**
     * Adopt a repository found by scan: move it into the canonical
     * <storage location>/<agent_id>/<name> spot, then register it exactly
     * like a normal import (perms, ssh-gate paths, catalog sync).
     * POST /repositories/adopt
     */
    public function adopt(): void
    {
        $this->requireAuth();
        $this->verifyCsrf();

        $agentId = (int) ($_POST['agent_id'] ?? 0);
        $name = $this->sanitizePathName(trim($_POST['name'] ?? ''));
        $encryption = $_POST['encryption'] ?? 'unknown';
        $passphrase = $_POST['passphrase'] ?? '';
        $storageLocationId = !empty($_POST['storage_location_id']) ? (int) $_POST['storage_location_id'] : null;
        $sourcePath = $this->validateSourcePath($_POST['source_path'] ?? '');

        if (!$this->isAdmin()) {
            $this->flash('danger', 'Admin access required.');
            $this->redirect("/clients/{$agentId}?tab=repos");
            return;
        }
        if (empty($name) || empty($agentId) || $sourcePath === null) {
            $this->flash('danger', 'Repository name, client, and a valid source path are required.');
            $this->redirect("/clients/{$agentId}?tab=repos");
            return;
        }

        $agent = $this->db->fetchOne("SELECT * FROM agents WHERE id = ?", [$agentId]);
        if (!$agent || !$this->canAccessAgent($agentId)) {
            $this->flash('danger', 'Access denied.');
            $this->redirect('/clients');
            return;
        }
        $this->requirePermission(PermissionService::MANAGE_REPOS, $agentId);

        $existing = $this->db->fetchOne(
            "SELECT id FROM repositories WHERE agent_id = ? AND name = ?",
            [$agentId, $name]
        );
        if ($existing) {
            $this->flash('warning', "Repository \"{$name}\" already exists.");
            $this->redirect("/clients/{$agentId}?tab=repos");
            return;
        }

        $location = $this->resolveLocalLocation($storageLocationId);
        $destPath = rtrim($location['path'], '/') . '/' . $agentId . '/' . $name;

        if ($sourcePath !== $destPath) {
            // Cross-filesystem moves copy the whole repo — don't let PHP's
            // execution limit kill it halfway.
            set_time_limit(0);
            [$exit, $out, $err] = $this->runHelper(['move-repo', $sourcePath, $destPath]);
            if ($exit !== 0) {
                $this->flash('danger', 'Move failed — repository was NOT imported: ' . trim($err ?: $out));
                $this->redirect("/clients/{$agentId}?tab=repos");
                return;
            }
            $this->db->insert('server_log', [
                'agent_id' => $agentId,
                'level' => 'info',
                'message' => "Repository \"{$name}\" moved for adoption: {$sourcePath} -> {$destPath}",
            ]);
        }

        // From here the repo sits at the canonical path — register it through
        // the standard import path (DB row, perms, ssh-gate paths, catalog sync).
        $this->importLocal($agentId, $agent, $name, $encryption, $passphrase, $storageLocationId);
    }
}
