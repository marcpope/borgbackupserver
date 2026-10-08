<?php

namespace BBS\Services;

/**
 * Locks / unlocks archives (legal hold, #314). Locking renames the archive
 * with a "locked." prefix so borg prune's --glob-archives filter can never
 * select it; the archives.locked flag is the source of truth for the UI,
 * the API, and the delete guards. Shared by the web controller and the
 * admin API so both behave identically (queue when the repo is busy,
 * rename immediately when idle).
 */
class ArchiveLockService
{
    private \BBS\Core\Database $db;

    public function __construct()
    {
        $this->db = \BBS\Core\Database::getInstance();
    }

    /**
     * Set an archive's lock state.
     *
     * @param int       $agentId   Owning client
     * @param int       $repoId    Repository
     * @param int       $archiveId Archive (recovery point)
     * @param bool|null $desired   true=lock, false=unlock, null=toggle
     * @return array{ok:bool,result:string,message:string,archive_name?:string,locked?:bool,code:int}
     *         result ∈ locked|unlocked|already|queued|not_found|error
     */
    public function setLock(int $agentId, int $repoId, int $archiveId, ?bool $desired = null): array
    {
        $repo = $this->db->fetchOne("
            SELECT r.*, a.ssh_unix_user
            FROM repositories r JOIN agents a ON a.id = r.agent_id
            WHERE r.id = ? AND r.agent_id = ?", [$repoId, $agentId]);
        if (!$repo) {
            return ['ok' => false, 'result' => 'not_found', 'message' => 'Repository not found', 'code' => 404];
        }
        if (($refusal = QueueManager::readOnlyRefusal($repo, 'archive_lock')) !== null) {
            return ['ok' => false, 'result' => 'read_only', 'message' => $refusal, 'code' => 409];
        }

        $archive = $this->db->fetchOne(
            "SELECT * FROM archives WHERE id = ? AND repository_id = ?",
            [$archiveId, $repoId]
        );
        if (!$archive) {
            return ['ok' => false, 'result' => 'not_found', 'message' => 'Archive not found', 'code' => 404];
        }

        $currentlyLocked = (bool) ((int) $archive['locked']);
        $locking = $desired === null ? !$currentlyLocked : $desired;

        // Idempotent no-op when already in the requested state
        if ($locking === $currentlyLocked) {
            return [
                'ok' => true,
                'result' => 'already',
                'message' => $locking ? 'Archive is already locked.' : 'Archive is already unlocked.',
                'archive_name' => $archive['archive_name'],
                'locked' => $currentlyLocked,
                'code' => 200,
            ];
        }

        $oldName = $archive['archive_name'];
        $newName = $locking ? 'locked.' . $oldName : preg_replace('/^locked\./', '', $oldName);
        if ($newName === $oldName) {
            // Flag and name disagree (shouldn't happen) — repair the flag only.
            $this->db->update('archives', ['locked' => $locking ? 1 : 0], 'id = ?', [$archiveId]);
            return [
                'ok' => true,
                'result' => $locking ? 'locked' : 'unlocked',
                'message' => 'Lock state repaired.',
                'archive_name' => $oldName,
                'locked' => $locking,
                'code' => 200,
            ];
        }

        // Busy repo → queue a server-side archive_lock job (applies when the
        // running job finishes). Callers never have to poll or retry.
        $activeJob = $this->db->fetchOne(
            "SELECT id FROM backup_jobs WHERE repository_id = ? AND status IN ('queued', 'sent', 'running') LIMIT 1",
            [$repoId]
        );
        if ($activeJob) {
            return $this->queue($agentId, $repoId, $archiveId, $archive, $locking);
        }

        $passphrase = '';
        if (!empty($repo['passphrase_encrypted'])) {
            $passphrase = Encryption::decrypt($repo['passphrase_encrypted']);
        }

        [$ok, $errOut] = $this->renameArchive($repo, $oldName, $newName, $passphrase);

        if (!$ok) {
            // A job grabbed the repo lock between the check and the rename —
            // fall back to queueing rather than failing.
            if (stripos($errOut, 'lock') !== false) {
                return $this->queue($agentId, $repoId, $archiveId, $archive, $locking);
            }
            return [
                'ok' => false,
                'result' => 'error',
                'message' => 'Failed to ' . ($locking ? 'lock' : 'unlock') . ' archive: ' . substr($errOut, 0, 300),
                'code' => 500,
            ];
        }

        $this->db->update('archives', [
            'archive_name' => $newName,
            'locked' => $locking ? 1 : 0,
        ], 'id = ?', [$archiveId]);

        $this->db->insert('server_log', [
            'agent_id' => $agentId,
            'level' => 'info',
            'message' => $locking
                ? "Archive \"{$oldName}\" locked (renamed to \"{$newName}\") — excluded from pruning"
                : "Archive \"{$oldName}\" unlocked (renamed to \"{$newName}\") — normal retention applies",
        ]);

        return [
            'ok' => true,
            'result' => $locking ? 'locked' : 'unlocked',
            'message' => $locking
                ? 'Archive locked — it will never be pruned or deleted until unlocked.'
                : 'Archive unlocked — normal retention rules apply again.',
            'archive_name' => $newName,
            'locked' => $locking,
            'code' => 200,
        ];
    }

    /**
     * Queue an archive_lock job (deduped per archive) for a busy repo.
     */
    private function queue(int $agentId, int $repoId, int $archiveId, array $archive, bool $locking): array
    {
        $existing = $this->db->fetchOne(
            "SELECT id FROM backup_jobs
             WHERE repository_id = ? AND task_type = 'archive_lock'
               AND restore_archive_id = ? AND status IN ('queued', 'sent', 'running')
             LIMIT 1",
            [$repoId, $archiveId]
        );
        if (!$existing) {
            $this->db->insert('backup_jobs', [
                'agent_id' => $agentId,
                'repository_id' => $repoId,
                'task_type' => 'archive_lock',
                'status' => 'queued',
                'restore_archive_id' => $archiveId,
                'status_message' => $archive['archive_name'],
            ]);
        }
        return [
            'ok' => true,
            'result' => 'queued',
            'message' => ($locking ? 'Lock' : 'Unlock')
                . ' queued — the repository is busy, so it will apply automatically when the current job finishes.',
            'archive_name' => $archive['archive_name'],
            'code' => 202,
        ];
    }

    /**
     * Run `borg rename` on the archive, local or remote SSH. Returns [ok, stderr].
     */
    private function renameArchive(array $repo, string $oldName, string $newName, string $passphrase): array
    {
        if (($repo['storage_type'] ?? 'local') === 'remote_ssh') {
            $remoteSshService = new RemoteSshService();
            $config = $remoteSshService->getDecrypted((int) $repo['remote_ssh_config_id']);
            if (!$config) {
                return [false, 'Remote SSH host not found'];
            }
            $result = $remoteSshService->runBorgCommand(
                $config,
                $repo['path'],
                ['rename', '--lock-wait=10', '--', "{$repo['path']}::{$oldName}", $newName],
                $passphrase
            );
            return [$result['success'], trim($result['stderr'] ?? $result['output'] ?? '')];
        }

        $localPath = BorgCommandBuilder::getLocalRepoPath($repo);
        $borgArgs = ['rename', '--lock-wait=10', '--', "{$localPath}::{$oldName}", $newName];
        if (!empty($repo['ssh_unix_user'])) {
            $cmd = array_merge(['sudo', '/usr/local/bin/bbs-ssh-helper', 'borg-cmd', $repo['ssh_unix_user'], '-'], $borgArgs);
            $env = null;
        } else {
            $cmd = array_merge(['borg'], $borgArgs);
            $env = array_filter($_SERVER, 'is_string') + [
                'BORG_PASSPHRASE' => $passphrase,
                'BORG_UNKNOWN_UNENCRYPTED_REPO_ACCESS_IS_OK' => 'yes',
                'BORG_RELOCATED_REPO_ACCESS_IS_OK' => 'yes',
                'BORG_BASE_DIR' => '/tmp/bbs-borg-www-data',
                'HOME' => '/tmp/bbs-borg-www-data',
            ];
        }

        $proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
        if (!is_resource($proc)) {
            return [false, 'Failed to start borg'];
        }
        fwrite($pipes[0], $passphrase . "\n");
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $errOut = trim(stream_get_contents($pipes[2]) ?: $stdout);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return [proc_close($proc) === 0, $errOut];
    }
}
