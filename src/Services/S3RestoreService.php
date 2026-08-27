<?php

namespace BBS\Services;

use BBS\Core\Database;

/**
 * Queues a restore of a repository from its S3 mirror, either over the
 * existing repository ("replace") or into a newly created one ("copy").
 * Shared by the web page and the API so both queue the same job the same
 * way; the caller handles permissions and how to present the outcome.
 */
class S3RestoreService
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * @param array $repo Row from repositories joined to the agent's name,
     *                    ssh_unix_user and server_host_override.
     * @return array ['ok' => bool, 'code' => int, 'error' => ?string,
     *                'job_id' => ?int, 'repository_id' => int, 'repository_name' => string, 'mode' => string]
     */
    public function queue(int $agentId, array $repo, string $mode, ?string $copyName, int $requestedConfigId): array
    {
        $repoId = (int) $repo['id'];
        if (!in_array($mode, ['replace', 'copy'], true)) {
            return $this->fail(400, 'mode must be "replace" or "copy"');
        }

        // Which destination to restore from: the requested one, validated
        // against this repo's links — or the repo's only destination.
        if ($requestedConfigId > 0) {
            $s3Config = $this->db->fetchOne(
                "SELECT plugin_config_id FROM repository_s3_configs WHERE repository_id = ? AND plugin_config_id = ?",
                [$repoId, $requestedConfigId]
            );
        } else {
            $links = $this->db->fetchAll(
                "SELECT plugin_config_id FROM repository_s3_configs WHERE repository_id = ?",
                [$repoId]
            );
            if (count($links) > 1) {
                return $this->fail(400, 'This repository syncs to multiple S3 destinations — pick which one to restore from.');
            }
            $s3Config = $links[0] ?? null;
        }
        if (!$s3Config) {
            return $this->fail(400, 'This repository does not have S3 sync configured.');
        }

        $targetRepoId = $repoId;
        $targetRepoName = $repo['name'];

        if ($mode === 'copy') {
            $copyName = trim((string) $copyName);
            if ($copyName === '') {
                $copyName = $repo['name'] . '-copy';
            }
            if ($this->db->fetchOne("SELECT id FROM repositories WHERE agent_id = ? AND name = ?", [$agentId, $copyName])) {
                return $this->fail(409, "Repository \"{$copyName}\" already exists. Choose a different name.");
            }

            // Same storage location as the source repo, default otherwise.
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

            $targetRepoId = $this->db->insert('repositories', [
                'agent_id' => $agentId,
                'storage_location_id' => $copyLoc['id'] ?? null,
                'name' => $copyName,
                'path' => $copyPath,
                'encryption' => $repo['encryption'],
                'passphrase_encrypted' => $repo['passphrase_encrypted'],
            ]);
            $targetRepoName = $copyName;

            $localPath = BorgCommandBuilder::getLocalRepoPath([
                'path' => $copyPath, 'agent_id' => $agentId, 'name' => $copyName,
                'storage_location_id' => $copyLoc['id'] ?? null,
            ]);
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
            $activeJob = $this->db->fetchOne(
                "SELECT id, task_type FROM backup_jobs WHERE repository_id = ? AND status IN ('queued', 'sent', 'running')",
                [$repoId]
            );
            if ($activeJob) {
                return $this->fail(409, "Cannot restore from S3 — repository has an active {$activeJob['task_type']} job (#{$activeJob['id']}).");
            }
        }

        // In copy mode, source_repository_id tells the restore where to pull
        // the S3 data from.
        $jobData = [
            'agent_id' => $agentId,
            'repository_id' => $targetRepoId,
            'task_type' => 's3_restore',
            'plugin_config_id' => $s3Config['plugin_config_id'],
            'status' => 'queued',
        ];
        if ($mode === 'copy') {
            $jobData['source_repository_id'] = $repoId;
        }
        $jobId = $this->db->insert('backup_jobs', $jobData);

        $this->db->insert('server_log', [
            'agent_id' => $agentId,
            'backup_job_id' => $jobId,
            'level' => 'info',
            'message' => "S3 restore ({$mode}) job #{$jobId} queued for repository \"{$targetRepoName}\"",
        ]);

        return [
            'ok' => true, 'code' => 202, 'error' => null,
            'job_id' => (int) $jobId,
            'repository_id' => (int) $targetRepoId,
            'repository_name' => $targetRepoName,
            'mode' => $mode,
        ];
    }

    private function fail(int $code, string $error): array
    {
        return ['ok' => false, 'code' => $code, 'error' => $error, 'job_id' => null];
    }
}
