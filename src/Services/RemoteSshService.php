<?php

namespace BBS\Services;

use BBS\Core\Database;

class RemoteSshService
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * Get all remote SSH configs.
     */
    public function getAll(): array
    {
        return $this->db->fetchAll("SELECT * FROM remote_ssh_configs ORDER BY name");
    }

    /**
     * Get a single config by ID.
     */
    public function getById(int $id): ?array
    {
        return $this->db->fetchOne("SELECT * FROM remote_ssh_configs WHERE id = ?", [$id]);
    }

    /**
     * Get a config by ID with decrypted SSH key.
     */
    public function getDecrypted(int $id): ?array
    {
        $config = $this->getById($id);
        if (!$config) return null;

        try {
            $config['ssh_private_key'] = Encryption::decrypt($config['ssh_private_key_encrypted']);
        } catch (\Exception $e) {
            $config['ssh_private_key'] = $config['ssh_private_key_encrypted'];
        }

        return $config;
    }

    /**
     * Build the SSH repo path for a given config and repo name.
     * Returns: ssh://user@host:port/base_path/repoName
     */
    public function buildRepoPath(array $config, string $repoName): string
    {
        $basePath = rtrim($config['remote_base_path'] ?? './', '/');
        $port = (int) ($config['remote_port'] ?? 22);
        $appendName = (int) ($config['append_repo_name'] ?? 1);

        $path = $appendName ? "{$basePath}/{$repoName}" : $basePath;

        if ($port === 22) {
            return "ssh://{$config['remote_user']}@{$config['remote_host']}/{$path}";
        }

        return "ssh://{$config['remote_user']}@{$config['remote_host']}:{$port}/{$path}";
    }

    /**
     * Test connection to remote SSH host.
     * Runs borg --version (or custom borg_remote_path) over SSH.
     */
    public function testConnection(array $config): array
    {
        $keyFile = null;
        try {
            $sshKey = $this->decryptKey($config);
            $keyFile = $this->writeTempKey($sshKey);

            $borgBin = $config['borg_remote_path'] ?: 'borg';
            $port = (int) ($config['remote_port'] ?? 22);

            $sshCmd = [
                'ssh',
                '-i', $keyFile,
                '-p', (string) $port,
                '-o', 'StrictHostKeyChecking=no',
                '-o', 'UserKnownHostsFile=/dev/null',
                '-o', 'BatchMode=yes',
                '-o', 'LogLevel=ERROR',
                '-o', 'ConnectTimeout=10',
                "{$config['remote_user']}@{$config['remote_host']}",
                "{$borgBin} --version",
            ];

            $proc = proc_open($sshCmd, [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ], $pipes, null, $this->buildServerEnv());

            if (!is_resource($proc)) {
                return ['success' => false, 'error' => 'Failed to start SSH process'];
            }

            fclose($pipes[0]);
            $stdout = trim(stream_get_contents($pipes[1]));
            $stderr = trim(stream_get_contents($pipes[2]));
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exitCode = proc_close($proc);

            if ($exitCode === 0 && !empty($stdout)) {
                return ['success' => true, 'version' => $stdout];
            }

            return ['success' => false, 'error' => $stderr ?: $stdout ?: "SSH connection failed (exit code {$exitCode})"];
        } finally {
            $this->cleanupTempKey($keyFile);
        }
    }

    /**
     * Initialize a borg repository on the remote host.
     */
    public function initRepo(array $config, string $repoPath, string $encryption, string $passphrase = ''): array
    {
        $borgRemotePath = $config['borg_remote_path'] ?? null;
        $cmd = ['borg', 'init', '--encryption=' . $encryption];
        if ($borgRemotePath) {
            $cmd[] = '--remote-path=' . $borgRemotePath;
        }
        $cmd[] = $repoPath;

        $env = $this->buildServerEnv();
        if (!empty($passphrase)) {
            $env['BORG_PASSPHRASE'] = $passphrase;
        }
        $env['BORG_UNKNOWN_UNENCRYPTED_REPO_ACCESS_IS_OK'] = 'yes';
        $env['BORG_RELOCATED_REPO_ACCESS_IS_OK'] = 'yes';

        return $this->runBorgWithKey($config, $cmd, $env);
    }

    /**
     * Run a borg command against a remote repo (prune, compact, check, list, info, etc.)
     *
     * @param array  $config    Remote SSH config (with encrypted key)
     * @param string $repoPath  Full SSH repo path (ssh://user@host/./repo)
     * @param array  $borgArgs  Borg subcommand args (e.g. ['prune', '--keep-daily=7', $repoPath])
     * @param string $passphrase Repository passphrase (already decrypted)
     */
    public function runBorgCommand(array $config, string $repoPath, array $borgArgs, string $passphrase = ''): array
    {
        $borgRemotePath = $config['borg_remote_path'] ?? null;

        $cmd = array_merge(['borg'], $borgArgs);

        // Inject --lock-wait after the subcommand so ops (compact/prune/
        // check/info/list/delete) wait up to 10 min for the repo lock
        // rather than failing immediately when a concurrent backup or
        // another server-side task is still holding it. break-lock is the
        // one case where --lock-wait is meaningless, so skip it there.
        $subcmd = $borgArgs[0] ?? '';
        if ($subcmd !== '' && $subcmd !== 'break-lock') {
            array_splice($cmd, 2, 0, ['--lock-wait=600']);
        }

        // Insert --remote-path after the subcommand if needed
        if ($borgRemotePath && count($borgArgs) >= 1) {
            array_splice($cmd, 2, 0, ['--remote-path=' . $borgRemotePath]);
        }

        $env = $this->buildServerEnv();
        if (!empty($passphrase)) {
            $env['BORG_PASSPHRASE'] = $passphrase;
        }
        $env['BORG_UNKNOWN_UNENCRYPTED_REPO_ACCESS_IS_OK'] = 'yes';
        $env['BORG_RELOCATED_REPO_ACCESS_IS_OK'] = 'yes';

        return $this->runBorgWithKey($config, $cmd, $env);
    }

    /**
     * Execute a borg command with the SSH key from the config.
     * Handles temp key creation/cleanup.
     */
    private function runBorgWithKey(array $config, array $cmd, array $env): array
    {
        $keyFile = null;
        try {
            $sshKey = $this->decryptKey($config);
            $keyFile = $this->writeTempKey($sshKey);

            $port = (int) ($config['remote_port'] ?? 22);
            $env['BORG_RSH'] = "ssh -i {$keyFile} -p {$port} -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o BatchMode=yes -o LogLevel=ERROR";

            $proc = proc_open($cmd, [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ], $pipes, null, $env);

            if (!is_resource($proc)) {
                return ['success' => false, 'output' => 'Failed to start borg process', 'exit_code' => -1];
            }

            fclose($pipes[0]);
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exitCode = proc_close($proc);

            return [
                'success' => $exitCode <= 1,
                'exit_code' => $exitCode,
                'output' => $stdout,
                'stderr' => $stderr,
            ];
        } catch (\Exception $e) {
            return ['success' => false, 'output' => $e->getMessage(), 'exit_code' => -1];
        } finally {
            $this->cleanupTempKey($keyFile);
        }
    }

    /**
     * Check disk usage on a remote SSH host by running df -k.
     * Returns ['total' => bytes, 'used' => bytes, 'free' => bytes, 'percent' => int] or null if unavailable.
     */
    public function getDiskUsage(array $config): ?array
    {
        $keyFile = null;
        try {
            $sshKey = $this->decryptKey($config);
            $keyFile = $this->writeTempKey($sshKey);

            $port = (int) ($config['remote_port'] ?? 22);
            $basePath = $config['remote_base_path'] ?: './';

            // Defense in depth: the base path is embedded in a command string
            // that runs on the REMOTE shell (proc_open's array form only
            // protects the local side). Reject anything that isn't a plain
            // POSIX path so shell metacharacters can't escape.
            if (!preg_match('#^[A-Za-z0-9_./\-]+$#', $basePath)) {
                return null;
            }

            $sshCmd = [
                'ssh',
                '-i', $keyFile,
                '-p', (string) $port,
                '-o', 'StrictHostKeyChecking=no',
                '-o', 'UserKnownHostsFile=/dev/null',
                '-o', 'BatchMode=yes',
                '-o', 'LogLevel=ERROR',
                '-o', 'ConnectTimeout=10',
                "{$config['remote_user']}@{$config['remote_host']}",
                "df -k {$basePath}",
            ];

            $proc = proc_open($sshCmd, [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ], $pipes, null, $this->buildServerEnv());

            if (!is_resource($proc)) {
                return null;
            }

            fclose($pipes[0]);
            $stdout = trim(stream_get_contents($pipes[1]));
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exitCode = proc_close($proc);

            if ($exitCode !== 0 || empty($stdout)) {
                return null;
            }

            // Parse df -k output (second line has the data)
            $lines = preg_split('/\n/', $stdout);
            if (count($lines) < 2) {
                return null;
            }

            // Handle wrapped lines: if line 2 has fewer than 4 fields,
            // the filesystem name was long and wrapped — join with next line
            $dataLine = $lines[1];
            $fields = preg_split('/\s+/', trim($dataLine));
            if (count($fields) < 4 && isset($lines[2])) {
                $dataLine = $lines[1] . ' ' . $lines[2];
                $fields = preg_split('/\s+/', trim($dataLine));
            }

            // Fields: Filesystem 1K-blocks Used Available Use% Mounted
            if (count($fields) < 4) {
                return null;
            }

            $total = (int) $fields[1] * 1024;
            $used = (int) $fields[2] * 1024;
            $free = (int) $fields[3] * 1024;
            $percent = $total > 0 ? (int) round(($used / $total) * 100) : 0;

            return ['total' => $total, 'used' => $used, 'free' => $free, 'percent' => $percent];
        } catch (\Exception $e) {
            return null;
        } finally {
            $this->cleanupTempKey($keyFile);
        }
    }

    /**
     * Store disk usage data for a remote SSH config.
     */
    public function updateDiskUsage(int $configId, ?array $diskData): void
    {
        $this->db->update('remote_ssh_configs', [
            'disk_total_bytes' => $diskData ? $diskData['total'] : null,
            'disk_used_bytes' => $diskData ? $diskData['used'] : null,
            'disk_free_bytes' => $diskData ? $diskData['free'] : null,
            'disk_checked_at' => $this->db->now(),
        ], 'id = ?', [$configId]);
    }

    /**
     * Measure a single remote borg repository directory with `du -sk`.
     * Returns bytes used on disk, or null if the path cannot be measured
     * (e.g. BorgBase's borg-only shell rejects shell commands).
     */
    public function getRepositorySizeBytes(array $config, string $repoPath): ?int
    {
        $remotePath = $this->remoteFilesystemPathFromRepoUrl($repoPath);
        if ($remotePath === null || $remotePath === '' || str_contains($remotePath, "\0")) {
            return null;
        }

        $keyFile = null;
        try {
            $sshKey = $this->decryptKey($config);
            $keyFile = $this->writeTempKey($sshKey);

            $port = (int) ($config['remote_port'] ?? 22);
            $sshCmd = [
                'ssh',
                '-i', $keyFile,
                '-p', (string) $port,
                '-o', 'StrictHostKeyChecking=no',
                '-o', 'UserKnownHostsFile=/dev/null',
                '-o', 'BatchMode=yes',
                '-o', 'LogLevel=ERROR',
                '-o', 'ConnectTimeout=30',
                "{$config['remote_user']}@{$config['remote_host']}",
                'du -sk -- ' . escapeshellarg($remotePath),
            ];

            $proc = proc_open($sshCmd, [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ], $pipes, null, $this->buildServerEnv());

            if (!is_resource($proc)) {
                return null;
            }

            fclose($pipes[0]);
            $stdout = trim(stream_get_contents($pipes[1]));
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exitCode = proc_close($proc);

            if ($exitCode !== 0 || $stdout === '') {
                return null;
            }

            $fields = preg_split('/\s+/', $stdout);
            $kib = isset($fields[0]) && is_numeric($fields[0]) ? (int) $fields[0] : 0;
            return $kib > 0 ? $kib * 1024 : 0;
        } catch (\Throwable $e) {
            return null;
        } finally {
            $this->cleanupTempKey($keyFile);
        }
    }

    /**
     * Convert a borg SSH URL to the remote filesystem path that `du` should
     * measure. Borg conventions: ssh://user@host/relative → relative to home,
     * ssh://user@host//absolute → absolute.
     */
    private function remoteFilesystemPathFromRepoUrl(string $repoPath): ?string
    {
        if (!str_starts_with($repoPath, 'ssh://')) {
            return $repoPath;
        }

        $path = parse_url($repoPath, PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            return null;
        }

        $path = rawurldecode($path);
        if (str_starts_with($path, '//')) {
            return '/' . ltrim($path, '/');
        }

        return ltrim($path, '/');
    }

    /**
     * Decrypt the SSH private key from a config record.
     */
    private function decryptKey(array $config): string
    {
        try {
            return Encryption::decrypt($config['ssh_private_key_encrypted']);
        } catch (\Exception $e) {
            // May be stored in plaintext
            return $config['ssh_private_key_encrypted'];
        }
    }

    /**
     * Write SSH key to a temp file with 0600 permissions.
     */
    private function writeTempKey(string $key): string
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'bbs-ssh-');
        // Normalize line endings (Windows \r\n → Unix \n) and ensure trailing newline
        $key = str_replace("\r\n", "\n", $key);
        $key = str_replace("\r", "\n", $key);
        $key = rtrim($key) . "\n";
        file_put_contents($tmpFile, $key);
        chmod($tmpFile, 0600);
        return $tmpFile;
    }

    /**
     * Remove temp key file.
     */
    private function cleanupTempKey(?string $path): void
    {
        if ($path && file_exists($path)) {
            @unlink($path);
        }
    }

    /**
     * Build base environment for server-side borg execution.
     */
    private function buildServerEnv(): array
    {
        $cacheDir = '/var/bbs/cache/www-data';
        if (!is_dir($cacheDir)) {
            @mkdir($cacheDir, 0700, true);
        }

        return [
            'PATH' => '/usr/local/bin:/usr/bin:/bin',
            'BORG_BASE_DIR' => $cacheDir,
            'HOME' => $cacheDir,
        ];
    }

    /**
     * Open a streaming borg process for remote SSH repos.
     * Returns process handle, pipes, and temp key path for cleanup.
     * Caller must fclose pipes, proc_close, and call cleanupStreamingProcess().
     *
     * @return array{proc: resource, pipes: array, key_file: string}|array{error: string}
     */
    public function openBorgProcess(array $config, array $borgArgs, string $passphrase = ''): array
    {
        $borgRemotePath = $config['borg_remote_path'] ?? null;
        $cmd = array_merge(['borg'], $borgArgs);
        if ($borgRemotePath && count($borgArgs) >= 1) {
            array_splice($cmd, 2, 0, ['--remote-path=' . $borgRemotePath]);
        }

        $env = $this->buildServerEnv();
        if (!empty($passphrase)) {
            $env['BORG_PASSPHRASE'] = $passphrase;
        }
        $env['BORG_UNKNOWN_UNENCRYPTED_REPO_ACCESS_IS_OK'] = 'yes';
        $env['BORG_RELOCATED_REPO_ACCESS_IS_OK'] = 'yes';

        try {
            $sshKey = $this->decryptKey($config);
            $keyFile = $this->writeTempKey($sshKey);
        } catch (\Exception $e) {
            return ['error' => $e->getMessage()];
        }

        $port = (int) ($config['remote_port'] ?? 22);
        $env['BORG_RSH'] = "ssh -i {$keyFile} -p {$port} -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o BatchMode=yes -o LogLevel=ERROR";

        $proc = proc_open($cmd, [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes, null, $env);

        if (!is_resource($proc)) {
            $this->cleanupTempKey($keyFile);
            return ['error' => 'Failed to start borg process'];
        }

        fclose($pipes[0]);

        return ['proc' => $proc, 'pipes' => $pipes, 'key_file' => $keyFile];
    }

    /**
     * Clean up after openBorgProcess().
     */
    public function cleanupStreamingProcess(array $handle): void
    {
        $this->cleanupTempKey($handle['key_file'] ?? null);
    }

    /**
     * Count repositories using a given remote SSH config.
     */
    public function getRepoCount(int $configId): int
    {
        $result = $this->db->fetchOne(
            "SELECT COUNT(*) as cnt FROM repositories WHERE remote_ssh_config_id = ?",
            [$configId]
        );
        return (int) ($result['cnt'] ?? 0);
    }
}
