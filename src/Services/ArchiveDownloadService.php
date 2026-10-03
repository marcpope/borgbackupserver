<?php

namespace BBS\Services;

use BBS\Core\Database;

/**
 * Raised when the staging volume cannot hold the requested selection.
 * Separate from a generic failure so a caller can report the size figures
 * rather than just "it didn't work".
 */
class InsufficientSpaceException extends \RuntimeException
{
    public int $neededBytes;
    public int $freeBytes;

    public function __construct(string $message, int $neededBytes, int $freeBytes)
    {
        parent::__construct($message);
        $this->neededBytes = $neededBytes;
        $this->freeBytes = $freeBytes;
    }
}

/**
 * Extracts a selection from an archive and streams it back as a .tar.gz.
 *
 * Lifted out of ClientController so the web page and the API can share one
 * implementation. This is the most intricate path in the application —
 * staging on the data volume rather than the OS disk, a disk pre-flight,
 * remote-SSH key handling, extraction through the privileged helper, two
 * passes of permission fixing, and cleanup that has to survive the client
 * hanging up mid-transfer. A second copy of it would have drifted.
 *
 * The only behavioural difference from the original is how failures surface:
 * this throws, and each caller reports in its own idiom.
 */
class ArchiveDownloadService
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * Extract and stream. Sends headers and the body itself, so nothing may be
     * written to the response before calling it.
     *
     * @param array $agent   the client row
     * @param array $archive the archive row, joined with its repository
     * @param array $selectedFiles paths to include; empty means the whole archive
     * @throws InsufficientSpaceException|\RuntimeException
     */
    public function stream(array $agent, array $archive, array $selectedFiles): void
    {
        $id = (int) $agent['id'];
        $archive_id = (int) $archive['id'];

        // Build environment — server-side execution uses local paths
        $repo = [
            'path' => $archive['repo_path'],
            'passphrase_encrypted' => $archive['passphrase_encrypted'],
            'encryption' => $archive['encryption'],
            'agent_id' => $archive['repo_agent_id'] ?? $id,
            'name' => $archive['repo_name'] ?? '',
            'storage_type' => $archive['storage_type'] ?? 'local',
            'storage_location_id' => $archive['storage_location_id'] ?? null,
        ];
        $isRemoteSsh = ($repo['storage_type'] === 'remote_ssh');
        $localPath = $isRemoteSsh ? $archive['repo_path'] : \BBS\Services\BorgCommandBuilder::getLocalRepoPath($repo);
        $env = \BBS\Services\BorgCommandBuilder::buildEnv($repo, false);

        // Stage the extraction under the data volume, NOT the OS disk (#344):
        // /tmp lives on the (often small) root filesystem — a large download
        // filled it and starved borg-serve of temp space, crashing every
        // running backup. /var/bbs is the volume actually sized for backups.
        $stagingBase = '/var/bbs/tmp';
        if (!is_dir($stagingBase) && !@mkdir($stagingBase, 0770, true)) {
            $stagingBase = sys_get_temp_dir(); // fall back to /tmp if the volume is unwritable
        }

        // Pre-flight: refuse up front when the staging disk can't hold the
        // selection, instead of dying mid-extract with a full disk.
        $neededBytes = $this->estimateDownloadBytes($id, $archive_id, $selectedFiles, (int) $archive['original_size']);
        $freeBytes = (float) @disk_free_space($stagingBase);
        if ($neededBytes !== null && $freeBytes > 0 && $neededBytes * 1.05 > $freeBytes) {
            throw new InsufficientSpaceException(sprintf(
                'Not enough space to prepare this download: it needs about %s, but only %s is free on the server (%s). Download a smaller selection or free up space.',
                ServerStats::formatBytes((int) $neededBytes),
                ServerStats::formatBytes((int) $freeBytes),
                $stagingBase
            ), (int) $neededBytes, (int) $freeBytes);
        }

        $tmpDir = $stagingBase . '/bbs-download-' . bin2hex(random_bytes(8));
        mkdir($tmpDir, 0700, true);

        $remoteSshKeyFile = null; // Track temp SSH key for cleanup

        // The user closing the browser mid-download kills this request after
        // the extract has already landed on disk — make sure the staging dir
        // is removed no matter how the request ends.
        ignore_user_abort(true);
        register_shutdown_function(function () use ($tmpDir, &$remoteSshKeyFile) {
            if ($remoteSshKeyFile && file_exists($remoteSshKeyFile)) {
                @unlink($remoteSshKeyFile);
            }
            if (is_dir($tmpDir)) {
                exec('rm -rf ' . escapeshellarg($tmpDir) . ' 2>/dev/null');
                if (is_dir($tmpDir)) {
                    // Files may be owned by the repo's bbs-* user — remove via helper perms fix + retry
                    exec('sudo /usr/local/bin/bbs-ssh-helper fix-download-perms ' . escapeshellarg($tmpDir) . ' 2>/dev/null');
                    exec('rm -rf ' . escapeshellarg($tmpDir) . ' 2>/dev/null');
                }
            }
        });

        try {
            // Build borg extract args: repo::archive, then "--" so the selected
            // paths can only ever be paths, never options.
            $borgArgs = [$localPath . '::' . $archive['archive_name'], '--'];
            foreach ($selectedFiles as $path) {
                $path = ltrim($path, '/');
                if ($path !== '') {
                    $borgArgs[] = rtrim($path, '/');
                }
            }

            if ($isRemoteSsh && !empty($archive['remote_ssh_key_encrypted'])) {
                // Remote SSH repos: run borg extract over SSH from BBS server
                try {
                    $sshKey = \BBS\Services\Encryption::decrypt($archive['remote_ssh_key_encrypted']);
                } catch (\Exception $e) {
                    $sshKey = $archive['remote_ssh_key_encrypted'];
                }
                $remoteSshKeyFile = tempnam(sys_get_temp_dir(), 'bbs-ssh-');
                // Normalize line endings (Windows \r\n → Unix \n) and ensure trailing newline
                $sshKey = str_replace("\r\n", "\n", $sshKey);
                $sshKey = str_replace("\r", "\n", $sshKey);
                $sshKey = rtrim($sshKey) . "\n";
                file_put_contents($remoteSshKeyFile, $sshKey);
                chmod($remoteSshKeyFile, 0600);

                $port = (int) ($archive['remote_port'] ?? 22);
                $env['BORG_RSH'] = "ssh -i {$remoteSshKeyFile} -p {$port} -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o BatchMode=yes -o ServerAliveInterval=10 -o ServerAliveCountMax=30 -o LogLevel=ERROR";

                $cmd = ['borg', 'extract'];
                if (RemoteSshService::safeBorgPath($archive['borg_remote_path'] ?? null) !== null) {
                    $cmd[] = '--remote-path=' . RemoteSshService::safeBorgPath($archive['borg_remote_path']);
                }
                $cmd = array_merge($cmd, $borgArgs);

                $envStrings = [];
                foreach ($_SERVER as $k => $v) {
                    if (is_string($v)) $envStrings[$k] = $v;
                }
                foreach ($env as $k => $v) {
                    $envStrings[$k] = $v;
                }
            } else {
                // Local repos: Use SSH helper to run borg extract as the repo-owning user
                // (www-data can't read repo files owned by the bbs-* user)
                $sshUser = $agent['ssh_unix_user'] ?? '';
                $useHelper = !empty($sshUser);
                if ($useHelper) {
                    // Passphrase piped on stdin ("-" marker) so it's not in argv.
                    $passphrase = $env['BORG_PASSPHRASE'] ?? '';
                    $cmd = ['sudo', '/usr/local/bin/bbs-ssh-helper', 'borg-extract', $sshUser, $tmpDir, '-'];
                    $cmd = array_merge($cmd, $borgArgs);

                    $envStrings = null; // helper handles env; null inherits current env (for PATH)
                } else {
                    // Fallback: run directly as www-data (non-SSH repos)
                    $cmd = array_merge(['borg', 'extract'], $borgArgs);
                    $envStrings = [];
                    foreach ($_SERVER as $k => $v) {
                        if (is_string($v)) $envStrings[$k] = $v;
                    }
                    foreach ($env as $k => $v) {
                        $envStrings[$k] = $v;
                    }
                }
            }

            $desc = [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ];

            $proc = proc_open($cmd, $desc, $pipes, $tmpDir, $envStrings);
            if (!is_resource($proc)) {
                throw new \RuntimeException('Failed to run borg extract');
            }

            if (!empty($useHelper)) {
                fwrite($pipes[0], ($passphrase ?? '') . "\n");
            }
            fclose($pipes[0]);
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exitCode = proc_close($proc);

            $borgOutput = trim($stdout . "\n" . $stderr);

            if ($exitCode > 1) {
                // Log the full borg output so it survives the failed request
                $this->db->insert('server_log', [
                    'agent_id' => $id,
                    'level' => 'error',
                    'message' => "Download failed — borg extract exit {$exitCode}: " . substr($borgOutput, 0, 4000),
                ]);
                throw new \RuntimeException('borg extract failed (exit ' . $exitCode . '): ' . substr($borgOutput, 0, 500));
            }

            // Fix permissions so www-data can read extracted files
            // (the helper's post-extract chmod may not complete due to pipe closure)
            exec('sudo /usr/local/bin/bbs-ssh-helper fix-download-perms ' . escapeshellarg($tmpDir) . ' 2>&1');

            // Check if anything was extracted
            $extractedFiles = [];
            try {
                $rii = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($tmpDir, \RecursiveDirectoryIterator::SKIP_DOTS),
                    \RecursiveIteratorIterator::SELF_FIRST,
                    \RecursiveIteratorIterator::CATCH_GET_CHILD
                );
                foreach ($rii as $file) {
                    if ($file->isFile()) {
                        $extractedFiles[] = $file->getPathname();
                    }
                }
            } catch (\Exception $iterErr) {
                // Iteration itself failed — log and continue to the empty-check
                $this->db->insert('server_log', [
                    'agent_id' => $id,
                    'level' => 'warning',
                    'message' => "Download: failed to iterate tmp dir {$tmpDir}: " . $iterErr->getMessage(),
                ]);
            }

            if (empty($extractedFiles)) {
                // The helper may have extracted files but PHP (as www-data) can't
                // see them due to a permission layer we don't control (FUSE/shfs,
                // NFS ID mapping, etc). Ask the helper for a root-eye view of the
                // tmp dir and include borg's output so users can see what happened.
                $lsOutput = '';
                exec('sudo /usr/local/bin/bbs-ssh-helper fix-download-perms ' . escapeshellarg($tmpDir) . ' 2>&1');
                exec('sudo find ' . escapeshellarg($tmpDir) . ' -maxdepth 4 2>&1', $lsLines);
                $lsOutput = implode("\n", array_slice($lsLines, 0, 50));

                // Re-iterate after the second fix-download-perms pass
                try {
                    $rii = new \RecursiveIteratorIterator(
                        new \RecursiveDirectoryIterator($tmpDir, \RecursiveDirectoryIterator::SKIP_DOTS),
                        \RecursiveIteratorIterator::SELF_FIRST,
                        \RecursiveIteratorIterator::CATCH_GET_CHILD
                    );
                    foreach ($rii as $file) {
                        if ($file->isFile()) {
                            $extractedFiles[] = $file->getPathname();
                        }
                    }
                } catch (\Exception $iterErr2) {
                    /* ignore */
                }
            }

            if (empty($extractedFiles)) {
                // Log the full diagnostic picture before failing so users can
                // see it in the server log (the returned message is truncated).
                $diag = "borg exit={$exitCode}\n"
                    . "borg output:\n" . substr($borgOutput, 0, 2000) . "\n"
                    . "tmp dir contents (root view):\n" . substr($lsOutput ?? '(not captured)', 0, 1500);
                $this->db->insert('server_log', [
                    'agent_id' => $id,
                    'level' => 'error',
                    'message' => "Download failed — no files in {$tmpDir}. {$diag}",
                ]);

                $userMsg = 'No files were extracted. borg exit=' . $exitCode;
                if ($borgOutput !== '') {
                    $userMsg .= ' — ' . substr($borgOutput, 0, 300);
                }
                $userMsg .= ' (see server log for full output)';
                throw new \RuntimeException($userMsg);
            }

            // Generate filename
            $safeName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $agent['name'] . '-' . $archive['archive_name']);

            // Stream as tar.gz — clear any buffered output (e.g. debug notices)
            if (ob_get_level()) {
                ob_end_clean();
            }
            header('Content-Type: application/gzip');
            header('Content-Disposition: attachment; filename="' . $safeName . '.tar.gz"');
            header('Cache-Control: no-cache');

            $tarCmd = ['tar', 'czf', '-', '-C', $tmpDir, '.'];
            $tarProc = proc_open($tarCmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $tarPipes);
            if (!is_resource($tarProc)) {
                throw new \RuntimeException('Failed to create tar archive');
            }

            fclose($tarPipes[0]);
            fpassthru($tarPipes[1]);
            fclose($tarPipes[1]);
            fclose($tarPipes[2]);
            proc_close($tarProc);

        } finally {
            // Cleanup temp directory and remote SSH key
            $this->removeDir($tmpDir);
            if ($remoteSshKeyFile && file_exists($remoteSshKeyFile)) {
                @unlink($remoteSshKeyFile);
            }
        }

        exit;
    }

    /**
     * Best-effort size of the selection, for the pre-flight.
     * Moved verbatim from ClientController — nested selections are
     * collapsed to their roots so a parent and child aren't counted twice.
     */
    private function estimateDownloadBytes(int $agentId, int $archiveId, array $selectedFiles, int $archiveOriginalSize): ?int
    {
        if (empty($selectedFiles)) {
            return $archiveOriginalSize > 0 ? $archiveOriginalSize : null;
        }
        try {
            $ch = \BBS\Core\ClickHouse::getInstance();
            if (!$ch->isAvailable()) {
                return null;
            }
            $paths = array_values(array_filter(array_map(
                fn($p) => '/' . trim((string) $p, '/'),
                $selectedFiles
            ), fn($p) => $p !== '/'));
            $roots = array_filter($paths, function ($p) use ($paths) {
                foreach ($paths as $other) {
                    if ($other !== $p && str_starts_with($p, $other . '/')) return false;
                }
                return true;
            });

            $conds = [];
            $params = [$agentId, $archiveId];
            foreach ($roots as $p) {
                $conds[] = '(path = ? OR startsWith(path, ?))';
                $params[] = $p;
                $params[] = $p . '/';
            }
            if (!$conds) {
                return null;
            }
            $row = $ch->fetchOne(
                "SELECT sum(file_size) AS bytes FROM file_catalog
                 WHERE agent_id = ? AND archive_id = ? AND (" . implode(' OR ', $conds) . ")",
                $params
            );
            $bytes = (int) ($row['bytes'] ?? 0);
            return $bytes > 0 ? $bytes : null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /** Remove a staging directory tree. Moved verbatim from ClientController. */
    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) return;
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }
}
