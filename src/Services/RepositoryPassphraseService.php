<?php

namespace BBS\Services;

use BBS\Core\Database;

/**
 * Changing a repository's passphrase (#412).
 *
 * Imported repositories often arrive with whatever passphrase they were built
 * with, and there was no way to improve on it short of starting again. borg
 * can re-encrypt the key in place with `key change-passphrase`, which leaves
 * every archive where it is.
 *
 * The dangerous part is not the borg call, it is the bookkeeping around it. If
 * borg succeeds and the new passphrase is not stored, the repository is intact
 * and unreachable — so the stored copy is written immediately afterwards, and a
 * failure to write it is reported as the emergency it is, with the passphrase
 * in the message.
 */
class RepositoryPassphraseService
{
    private const HELPER = '/usr/local/bin/bbs-ssh-helper';

    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * Why this repository cannot have its passphrase changed right now, or
     * null when it can.
     *
     * Checked before the borg call and again by the caller's confirmation, so
     * a backup that starts in between fails on the lock rather than corrupting
     * anything — borg holds the repository for the duration either way.
     */
    public function blockedReason(array $repo): ?string
    {
        $encryption = (string) ($repo['encryption'] ?? '');
        if ($encryption === '' || $encryption === 'none') {
            return 'This repository is not encrypted, so it has no passphrase to change.';
        }

        $busy = $this->db->fetchOne("
            SELECT bj.id, bj.task_type, bj.status
            FROM backup_jobs bj
            WHERE bj.repository_id = ?
              AND bj.status IN ('queued', 'sent', 'running')
            ORDER BY FIELD(bj.status, 'running', 'sent', 'queued')
            LIMIT 1
        ", [$repo['id']]);

        if ($busy) {
            return sprintf(
                'A %s job (#%d) is %s on this repository. Wait for it to finish, or cancel it, then try again.',
                str_replace('_', ' ', $busy['task_type']),
                $busy['id'],
                $busy['status'] === 'running' ? 'running' : 'waiting to run'
            );
        }

        return null;
    }

    /** A passphrase of the same shape the repository-create form suggests. */
    public function suggest(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * Re-encrypt the repository key with a new passphrase and store it.
     *
     * @return array{success: bool, message: string, output: string, orphaned: bool}
     *         `orphaned` is true when borg accepted the change but the new
     *         passphrase could not be saved — the one outcome that needs the
     *         operator to act immediately.
     */
    public function change(array $repo, string $newPassphrase): array
    {
        $newPassphrase = trim($newPassphrase);
        if ($newPassphrase === '') {
            return ['success' => false, 'message' => 'The new passphrase cannot be empty.', 'output' => '', 'orphaned' => false];
        }

        $blocked = $this->blockedReason($repo);
        if ($blocked !== null) {
            return ['success' => false, 'message' => $blocked, 'output' => '', 'orphaned' => false];
        }

        try {
            $current = Encryption::decrypt($repo['passphrase_encrypted']);
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'orphaned' => false,
                'output' => '',
                'message' => 'The stored passphrase for this repository could not be decrypted, so borg cannot be given the current one.',
            ];
        }

        $result = ($repo['storage_type'] ?? 'local') === 'remote_ssh'
            ? $this->changeRemote($repo, $current, $newPassphrase)
            : $this->changeLocal($repo, $current, $newPassphrase);

        if (!$result['success']) {
            return [
                'success' => false,
                'orphaned' => false,
                'output' => $result['output'],
                'message' => 'borg refused to change the passphrase. The repository is unchanged and the current passphrase still works.',
            ];
        }

        // borg has accepted it. From here the stored copy is the only record of
        // how to open this repository.
        try {
            $this->db->update('repositories', [
                'passphrase_encrypted' => Encryption::encrypt($newPassphrase),
            ], 'id = ?', [$repo['id']]);
        } catch (\Throwable $e) {
            $this->db->insert('server_log', [
                'agent_id' => $repo['agent_id'] ?? null,
                'level' => 'error',
                'message' => "Repository \"{$repo['name']}\" passphrase was changed in borg but could not be saved. "
                    . "Record it now — it is the only way to open the repository: {$newPassphrase}",
            ]);
            return [
                'success' => false,
                'orphaned' => true,
                'output' => $result['output'],
                'message' => 'borg changed the passphrase but it could not be saved to the database. '
                    . 'Record this passphrase now — it is the only way to open the repository: ' . $newPassphrase,
            ];
        }

        $this->db->insert('server_log', [
            'agent_id' => $repo['agent_id'] ?? null,
            'level' => 'info',
            'message' => "Passphrase changed for repository \"{$repo['name']}\"",
        ]);

        return [
            'success' => true,
            'orphaned' => false,
            'output' => $result['output'],
            // Agents are handed the passphrase with each job, so nothing on the
            // client needs touching.
            'message' => 'Passphrase changed. Backups continue as normal — clients are given the passphrase with each job.',
        ];
    }

    /** Repositories on this server, through the helper, as the owning user. */
    private function changeLocal(array $repo, string $old, string $new): array
    {
        $agent = $this->db->fetchOne("SELECT ssh_unix_user FROM agents WHERE id = ?", [$repo['agent_id'] ?? 0]);
        $user = $agent['ssh_unix_user'] ?? '';
        if ($user === '') {
            return ['success' => false, 'output' => 'This repository has no owning system user, so borg cannot be run against it.'];
        }

        $path = BorgCommandBuilder::getLocalRepoPath($repo) ?: ($repo['path'] ?? '');
        if ($path === '') {
            return ['success' => false, 'output' => 'Repository path is empty.'];
        }

        $cmd = sprintf(
            'sudo %s borg-passphrase %s %s 2>&1',
            escapeshellarg(self::HELPER),
            escapeshellarg($user),
            escapeshellarg($path)
        );

        // Both secrets go in on stdin so neither shows up in argv or `ps`.
        $proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($proc)) {
            return ['success' => false, 'output' => 'Could not start the helper.'];
        }
        fwrite($pipes[0], $old . "\n" . $new . "\n");
        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);

        return ['success' => $exit === 0, 'output' => trim($out)];
    }

    /** Repositories on a remote SSH host, driven from this server. */
    private function changeRemote(array $repo, string $old, string $new): array
    {
        $config = (new RemoteSshService())->getDecrypted((int) $repo['remote_ssh_config_id']);
        if (!$config) {
            return ['success' => false, 'output' => 'The remote SSH configuration for this repository is missing.'];
        }

        $path = $repo['path'] ?? '';
        $res = (new RemoteSshService())->runBorgCommand(
            $config,
            $path,
            // --lock-wait here rather than letting runBorgCommand add it: this
              // is a two-word subcommand and its injection point assumes one.
              ['key', 'change-passphrase', '-v', '--lock-wait=30', $path],
            $old,
            ['BORG_NEW_PASSPHRASE' => $new]
        );

        return ['success' => !empty($res['success']), 'output' => trim((string) ($res['output'] ?? ''))];
    }
}
