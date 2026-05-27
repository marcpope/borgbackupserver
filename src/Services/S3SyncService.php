<?php

namespace BBS\Services;

use BBS\Core\Database;

class S3SyncService
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * Resolve S3 credentials from plugin config.
     * If credential_source is 'global', loads from settings table.
     * If 'custom', uses the config values directly.
     */
    public function resolveCredentials(array $config): array
    {
        $source = $config['credential_source'] ?? 'global';

        if ($source === 'global') {
            $settings = [];
            $rows = $this->db->fetchAll("SELECT `key`, `value` FROM settings WHERE `key` LIKE 's3_%'");
            foreach ($rows as $row) {
                // Strip 's3_' prefix to get field name
                $field = substr($row['key'], 3);
                $settings[$field] = $row['value'];
            }

            // Decrypt sensitive fields from global settings
            foreach (['access_key', 'secret_key'] as $sensitive) {
                if (!empty($settings[$sensitive])) {
                    try {
                        $settings[$sensitive] = Encryption::decrypt($settings[$sensitive]);
                    } catch (\Exception $e) {
                        // May already be plaintext
                    }
                }
            }

            // Allow per-config overrides for path_prefix and bandwidth_limit
            return [
                'endpoint' => $settings['endpoint'] ?? '',
                'region' => $settings['region'] ?? '',
                'bucket' => $settings['bucket'] ?? '',
                'access_key' => $settings['access_key'] ?? '',
                'secret_key' => $settings['secret_key'] ?? '',
                'path_prefix' => $config['path_prefix'] ?? $settings['path_prefix'] ?? '',
                'bandwidth_limit' => $config['bandwidth_limit'] ?? $settings['bandwidth_limit'] ?? '',
                'storage_class' => $settings['storage_class'] ?? '',
                'sse_mode' => $settings['sse_mode'] ?? '',
                'sse_kms_key_id' => $settings['sse_kms_key_id'] ?? '',
            ];
        }

        // Custom credentials — decrypt sensitive fields
        $secretKey = $config['secret_key'] ?? '';
        if (!empty($secretKey)) {
            try {
                $secretKey = Encryption::decrypt($secretKey);
            } catch (\Exception $e) {
                // May already be plaintext
            }
        }

        $accessKey = $config['access_key'] ?? '';
        if (!empty($accessKey)) {
            try {
                $accessKey = Encryption::decrypt($accessKey);
            } catch (\Exception $e) {
                // May already be plaintext
            }
        }

        return [
            'endpoint' => $config['endpoint'] ?? '',
            'region' => $config['region'] ?? 'us-east-1',
            'bucket' => $config['bucket'] ?? '',
            'access_key' => $accessKey,
            'secret_key' => $secretKey,
            'path_prefix' => $config['path_prefix'] ?? '',
            'bandwidth_limit' => $config['bandwidth_limit'] ?? '',
            'storage_class' => $config['storage_class'] ?? '',
            'sse_mode' => $config['sse_mode'] ?? '',
            'sse_kms_key_id' => $config['sse_kms_key_id'] ?? '',
        ];
    }

    /**
     * Build environment variables for rclone (env-based config, no rclone.conf needed).
     */
    public function buildRcloneEnv(array $creds): array
    {
        $env = [
            'RCLONE_CONFIG_S3_TYPE' => 's3',
            'RCLONE_CONFIG_S3_PROVIDER' => 'Other',
            'RCLONE_CONFIG_S3_ACCESS_KEY_ID' => $creds['access_key'],
            'RCLONE_CONFIG_S3_SECRET_ACCESS_KEY' => $creds['secret_key'],
            'RCLONE_CONFIG_S3_ENDPOINT' => $creds['endpoint'],
            'RCLONE_CONFIG_S3_REGION' => $creds['region'],
        ];

        if (!empty($creds['storage_class'])) {
            $env['RCLONE_CONFIG_S3_STORAGE_CLASS'] = $creds['storage_class'];
        }
        if (!empty($creds['sse_mode'])) {
            $env['RCLONE_CONFIG_S3_SERVER_SIDE_ENCRYPTION'] = $creds['sse_mode'];
            if ($creds['sse_mode'] === 'aws:kms' && !empty($creds['sse_kms_key_id'])) {
                $env['RCLONE_CONFIG_S3_SSE_KMS_KEY_ID'] = $creds['sse_kms_key_id'];
            }
        }

        return $env;
    }

    /**
     * Sync a borg repository to S3.
     * Returns ['success' => bool, 'output' => string].
     */
    public function syncRepository(array $repo, array $agent, array $creds, ?string $runAsUser = null): array
    {
        if (empty($creds['bucket'])) {
            return ['success' => false, 'output' => 'No S3 bucket configured'];
        }

        if (!$this->isRcloneInstalled()) {
            return ['success' => false, 'output' => 'rclone is not installed on this server'];
        }

        // Build remote path: bucket/prefix/agent-name/repo-name/
        $agentName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $agent['name'] ?? 'unknown');
        $repoName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $repo['name'] ?? 'unknown');
        $prefix = trim($creds['path_prefix'], '/');
        $remotePath = $prefix ? "{$prefix}/{$agentName}/{$repoName}" : "{$agentName}/{$repoName}";
        $remote = "S3:{$creds['bucket']}/{$remotePath}/";

        // Get local repo path
        $localPath = \BBS\Services\BorgCommandBuilder::getLocalRepoPath($repo);
        if (empty($localPath) || !is_dir($localPath)) {
            return ['success' => false, 'output' => "Local repo path not found: {$localPath}"];
        }

        // Build rclone command. --s3-no-check-bucket skips the pre-flight
        // CreateBucket call rclone otherwise issues on every session — the
        // bucket already exists (we never create it), and least-privilege
        // IAM policies that don't grant s3:CreateBucket 403 on that probe
        // and abort the whole sync before any object is written.
        $cmd = ['rclone', 'sync', $localPath, $remote, '--transfers', '4', '--checkers', '8', '--s3-no-check-bucket'];

        if (!empty($creds['bandwidth_limit'])) {
            $cmd[] = '--bwlimit';
            $cmd[] = $creds['bandwidth_limit'];
        }

        // Build environment
        $env = $this->buildRcloneEnv($creds);

        // Run via bbs-ssh-helper (runs as root via sudo, then sudo -u to the repo user)
        if ($runAsUser) {
            $cmd = [
                'sudo', '/usr/local/bin/bbs-ssh-helper', 'rclone-sync',
                $runAsUser, $localPath, $remote,
                $creds['endpoint'] ?? '', $creds['region'] ?? '',
                $creds['access_key'] ?? '', $creds['secret_key'] ?? '',
            ];
            if (!empty($creds['bandwidth_limit'])) {
                $cmd[] = '--bwlimit';
                $cmd[] = $creds['bandwidth_limit'];
            }
        }

        $desc = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        // For non-sudo runs (e.g. test connection), pass env vars directly
        // Filter $_SERVER to only include string values (avoid "Array to string conversion" warnings)
        $baseEnv = array_filter($_SERVER, 'is_string');
        $procEnv = array_merge($baseEnv, $env);

        $proc = proc_open($cmd, $desc, $pipes, null, $procEnv);
        if (!is_resource($proc)) {
            return ['success' => false, 'output' => 'Failed to start rclone process'];
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exitCode = proc_close($proc);

        $fullOutput = trim($stdout . "\n" . $stderr);

        // Extract just the final stats line (e.g. "65.046M / 65.046 MBytes, 100%, 26.503 MBytes/s, ETA 0s")
        $summary = '';
        if ($exitCode === 0 && !empty($fullOutput)) {
            $lines = array_filter(array_map('trim', explode("\n", $fullOutput)));
            $lastLine = end($lines);
            // Strip rclone log prefix (e.g. "2026/02/02 23:44:04 INFO : ")
            $summary = preg_replace('/^\d{4}\/\d{2}\/\d{2}\s+\d{2}:\d{2}:\d{2}\s+\w+\s+:\s+/', '', $lastLine);
        }

        return [
            'success' => $exitCode === 0,
            'output' => $exitCode === 0
                ? ($summary ?: 'Sync completed')
                : ($fullOutput ?: "rclone exited with code {$exitCode}"),
        ];
    }

    /**
     * Restore a borg repository from S3.
     * Returns ['success' => bool, 'output' => string].
     *
     * @param array $repo Target repository to restore into
     * @param array $agent Agent that owns the repository
     * @param array $creds S3 credentials
     * @param string|null $runAsUser Unix user to run as
     * @param array|null $sourceRepo For "copy" mode: the source repo to pull S3 data from
     */
    public function restoreRepository(array $repo, array $agent, array $creds, ?string $runAsUser = null, ?array $sourceRepo = null): array
    {
        if (empty($creds['bucket'])) {
            return ['success' => false, 'output' => 'No S3 bucket configured'];
        }

        if (!$this->isRcloneInstalled()) {
            return ['success' => false, 'output' => 'rclone is not installed on this server'];
        }

        // Build remote path: bucket/prefix/agent-name/repo-name/
        // For "copy" mode, use sourceRepo's name for the S3 path
        $agentName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $agent['name'] ?? 'unknown');
        $sourceRepoName = $sourceRepo['name'] ?? $repo['name'];
        $repoName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $sourceRepoName ?? 'unknown');
        $prefix = trim($creds['path_prefix'], '/');
        $remotePath = $prefix ? "{$prefix}/{$agentName}/{$repoName}" : "{$agentName}/{$repoName}";
        $remote = "S3:{$creds['bucket']}/{$remotePath}/";

        // Get local repo path
        $localPath = \BBS\Services\BorgCommandBuilder::getLocalRepoPath($repo);
        if (empty($localPath)) {
            return ['success' => false, 'output' => "Local repo path not configured"];
        }

        // Create local directory if it doesn't exist
        if (!is_dir($localPath)) {
            $parentDir = dirname($localPath);
            if (!is_dir($parentDir)) {
                mkdir($parentDir, 0755, true);
            }
        }

        // Build rclone command - sync FROM S3 TO local (reverse of syncRepository).
        // --s3-no-check-bucket: see syncRepository().
        $cmd = ['rclone', 'sync', $remote, $localPath, '--transfers', '4', '--checkers', '8', '--s3-no-check-bucket'];

        if (!empty($creds['bandwidth_limit'])) {
            $cmd[] = '--bwlimit';
            $cmd[] = $creds['bandwidth_limit'];
        }

        // Build environment
        $env = $this->buildRcloneEnv($creds);

        // Run via bbs-ssh-helper (runs as root via sudo, then sudo -u to the repo user)
        if ($runAsUser) {
            $cmd = [
                'sudo', '/usr/local/bin/bbs-ssh-helper', 'rclone-restore',
                $runAsUser, $remote, $localPath,
                $creds['endpoint'] ?? '', $creds['region'] ?? '',
                $creds['access_key'] ?? '', $creds['secret_key'] ?? '',
            ];
            if (!empty($creds['bandwidth_limit'])) {
                $cmd[] = '--bwlimit';
                $cmd[] = $creds['bandwidth_limit'];
            }
        }

        $desc = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        // For non-sudo runs, pass env vars directly
        // When running via helper (runAsUser is set), env is handled by the helper
        $envStrings = $runAsUser ? null : array_filter($_SERVER, 'is_string') + $env;

        $proc = proc_open($cmd, $desc, $pipes, null, $envStrings);
        if (!is_resource($proc)) {
            return ['success' => false, 'output' => 'Failed to start rclone process'];
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exitCode = proc_close($proc);

        $fullOutput = trim($stdout . "\n" . $stderr);

        // Extract summary
        $summary = '';
        if ($exitCode === 0 && !empty($fullOutput)) {
            $lines = array_filter(array_map('trim', explode("\n", $fullOutput)));
            $lastLine = end($lines);
            $summary = preg_replace('/^\d{4}\/\d{2}\/\d{2}\s+\d{2}:\d{2}:\d{2}\s+\w+\s+:\s+/', '', $lastLine);
        }

        return [
            'success' => $exitCode === 0,
            'output' => $exitCode === 0
                ? ($summary ?: 'Restore completed')
                : ($fullOutput ?: "rclone exited with code {$exitCode}"),
        ];
    }

    /**
     * Test S3 connection by listing the bucket root.
     */
    public function testConnection(array $creds): array
    {
        if (empty($creds['bucket'])) {
            return ['success' => false, 'error' => 'No bucket configured'];
        }

        if (!$this->isRcloneInstalled()) {
            return ['success' => false, 'error' => 'rclone is not installed on this server. Install with: apt install rclone'];
        }

        // Validate endpoint looks like a real URL before wasting time on rclone
        $endpoint = $creds['endpoint'] ?? '';
        if (!empty($endpoint)) {
            if (!preg_match('#^https?://#i', $endpoint)) {
                $endpoint = 'https://' . $endpoint;
            }
            $parsed = parse_url($endpoint);
            if (empty($parsed['host']) || !preg_match('/\.[a-z]{2,}$/i', $parsed['host'])) {
                return ['success' => false, 'error' => 'Invalid endpoint URL — must be a hostname like s3.us-east-1.amazonaws.com'];
            }
        }

        $remote = "S3:{$creds['bucket']}/";
        $cmd = ['rclone', 'lsd', $remote, '--max-depth', '1', '--contimeout', '5s', '--timeout', '8s', '--s3-no-check-bucket'];

        $result = $this->runRcloneWithTimeout($cmd, $creds, 10);

        if ($result['exitCode'] !== 0) {
            $error = trim($result['stderr']) ?: trim($result['stdout']) ?: "rclone exited with code {$result['exitCode']}";
            if ($result['timedOut']) {
                $error = 'Connection timed out — check endpoint URL and credentials';
            }
            return ['success' => false, 'error' => $error];
        }

        return ['success' => true];
    }

    /**
     * Check if rclone is installed.
     */
    public function isRcloneInstalled(): bool
    {
        $output = @shell_exec('which rclone 2>/dev/null');
        return !empty(trim($output ?? ''));
    }

    /**
     * Run an rclone command with a PHP-level timeout to prevent hangs.
     * Returns ['stdout' => ..., 'stderr' => ..., 'exitCode' => int, 'timedOut' => bool]
     */
    private function runRcloneWithTimeout(array $cmd, array $creds, int $timeoutSeconds = 10): array
    {
        $env = $this->buildRcloneEnv($creds);

        $desc = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $envStrings = [
            'RCLONE_CONFIG' => '/dev/null',
            'HOME' => '/tmp',
            'PATH' => '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin',
        ];
        foreach ($env as $k => $v) {
            $envStrings[$k] = $v;
        }

        $proc = proc_open($cmd, $desc, $pipes, null, $envStrings);
        if (!is_resource($proc)) {
            return ['stdout' => '', 'stderr' => 'Failed to start rclone process', 'exitCode' => -1, 'timedOut' => false];
        }

        fclose($pipes[0]);

        // Set pipes to non-blocking so we can enforce a timeout
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $timedOut = false;
        $deadline = time() + $timeoutSeconds;

        while (true) {
            $status = proc_get_status($proc);
            if (!$status['running']) {
                // Process finished — drain remaining output
                $stdout .= stream_get_contents($pipes[1]);
                $stderr .= stream_get_contents($pipes[2]);
                break;
            }

            if (time() >= $deadline) {
                $timedOut = true;
                proc_terminate($proc, 9);
                break;
            }

            $stdout .= fread($pipes[1], 8192);
            $stderr .= fread($pipes[2], 8192);
            usleep(50000); // 50ms
        }

        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($proc);

        if ($timedOut) {
            $exitCode = -1;
        }

        return ['stdout' => $stdout, 'stderr' => $stderr, 'exitCode' => $exitCode, 'timedOut' => $timedOut];
    }

    /**
     * List remote repositories in S3 for a given agent.
     * Returns array of repo names found in S3 for this agent.
     */
    public function listRemoteRepos(string $agentName, array $creds): array
    {
        if (empty($creds['bucket'])) {
            return ['success' => false, 'repos' => [], 'error' => 'No S3 bucket configured'];
        }

        if (!$this->isRcloneInstalled()) {
            return ['success' => false, 'repos' => [], 'error' => 'rclone is not installed'];
        }

        // Sanitize agent name same way as sync
        $agentName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $agentName);
        $prefix = trim($creds['path_prefix'], '/');
        $remotePath = $prefix ? "{$prefix}/{$agentName}/" : "{$agentName}/";
        $remote = "S3:{$creds['bucket']}/{$remotePath}";

        // List directories (repos) under the agent folder
        $cmd = ['rclone', 'lsd', $remote, '--contimeout', '5s', '--timeout', '8s', '--s3-no-check-bucket'];

        $result = $this->runRcloneWithTimeout($cmd, $creds, 10);

        // Exit code 3 means directory not found (no repos for this agent yet)
        if ($result['exitCode'] === 3) {
            return ['success' => true, 'repos' => []];
        }

        if ($result['exitCode'] !== 0) {
            $error = trim($result['stderr']) ?: trim($result['stdout']) ?: "rclone exited with code {$result['exitCode']}";
            if ($result['timedOut']) {
                $error = 'Connection timed out — check endpoint URL and credentials';
            }
            return ['success' => false, 'repos' => [], 'error' => $error];
        }

        $stdout = $result['stdout'];

        // Parse rclone lsd output - format: "          -1 2024-01-15 10:30:00        -1 repo-name"
        $repos = [];
        $lines = array_filter(array_map('trim', explode("\n", $stdout)));
        foreach ($lines as $line) {
            // Last space-separated token is the directory name
            $parts = preg_split('/\s+/', $line);
            if (!empty($parts)) {
                $repoName = end($parts);
                if (!empty($repoName)) {
                    $repos[] = $repoName;
                }
            }
        }

        return ['success' => true, 'repos' => $repos];
    }

    /**
     * Generate a manifest file containing repository metadata, archives, and file catalog.
     * Streams directly to file to handle large catalogs (millions of files) without memory issues.
     * Returns ['success' => bool, 'file' => string, 'archives' => int, 'files' => int].
     */
    public function generateManifestFile(array $repo, array $agent, string $passphrase): array
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'bbs-manifest-');
        $fp = fopen($tempFile, 'w');

        // Write opening brace and header fields
        fwrite($fp, "{\n");
        fwrite($fp, '  "version": 1,' . "\n");
        fwrite($fp, '  "generated_at": ' . json_encode(date('c')) . ",\n");
        fwrite($fp, '  "repository": ' . json_encode([
            'name' => $repo['name'],
            'encryption' => $repo['encryption'] ?? 'unknown',
            'passphrase' => $passphrase,
        ], JSON_UNESCAPED_SLASHES) . ",\n");

        // Write archives array
        fwrite($fp, '  "archives": [' . "\n");
        $archives = $this->db->fetchAll(
            "SELECT archive_name, original_size, deduplicated_size, file_count, created_at
             FROM archives WHERE repository_id = ? ORDER BY created_at",
            [$repo['id']]
        );
        $archiveCount = count($archives);
        foreach ($archives as $i => $ar) {
            $archiveJson = json_encode([
                'name' => $ar['archive_name'],
                'original_size' => (int) $ar['original_size'],
                'deduplicated_size' => (int) $ar['deduplicated_size'],
                'file_count' => (int) $ar['file_count'],
                'created_at' => $ar['created_at'],
            ], JSON_UNESCAPED_SLASHES);
            $comma = ($i < $archiveCount - 1) ? ',' : '';
            fwrite($fp, "    {$archiveJson}{$comma}\n");
        }
        fwrite($fp, "  ],\n");

        // Build archive ID -> name mapping
        $archiveIds = $this->db->fetchAll(
            "SELECT id, archive_name FROM archives WHERE repository_id = ?",
            [$repo['id']]
        );
        $archiveNameById = [];
        foreach ($archiveIds as $a) {
            $archiveNameById[$a['id']] = $a['archive_name'];
        }

        // Stream file catalog from ClickHouse in batches
        $ch = \BBS\Core\ClickHouse::getInstance();
        $agentId = (int) $agent['id'];
        fwrite($fp, '  "file_catalog": [' . "\n");

        $batchSize = 10000;
        $firstFile = true;
        $fileCount = 0;

        // Keyset pagination: iterate one archive at a time, paging on path > last.
        // OFFSET-based pagination is O(N^2) in ClickHouse because every batch
        // re-sorts and skips all previous rows — catastrophic for catalogs
        // with tens of millions of entries.
        foreach ($archiveNameById as $aid => $archiveName) {
            $aid = (int) $aid;
            $lastPath = '';
            do {
                $lastPathEsc = addslashes($lastPath);
                $files = $ch->fetchAll(
                    "SELECT path, file_size,
                            formatDateTime(mtime, '%Y-%m-%d %H:%i:%S') as mtime
                     FROM file_catalog
                     WHERE agent_id = {$agentId}
                       AND archive_id = {$aid}
                       AND path > '{$lastPathEsc}'
                     ORDER BY path
                     LIMIT {$batchSize}"
                );

                foreach ($files as $file) {
                    $fileJson = json_encode([
                        'archive' => $archiveName,
                        'path' => $file['path'],
                        'size' => (int) $file['file_size'],
                        'mtime' => $file['mtime'],
                    ], JSON_UNESCAPED_SLASHES);

                    if (!$firstFile) {
                        fwrite($fp, ",\n");
                    }
                    fwrite($fp, "    {$fileJson}");
                    $firstFile = false;
                    $fileCount++;
                    $lastPath = $file['path'];
                }
            } while (count($files) === $batchSize);
        }

        fwrite($fp, "\n  ]\n");
        fwrite($fp, "}\n");
        fclose($fp);

        return [
            'success' => true,
            'file' => $tempFile,
            'archives' => $archiveCount,
            'files' => $fileCount,
        ];
    }

    /**
     * Upload manifest file to S3 alongside the repository.
     * Manifest is stored as .bbs-manifest.json in the repo's S3 folder.
     * @param string $manifestFile Path to the manifest file (will be deleted after upload)
     */
    public function uploadManifestFile(string $manifestFile, array $repo, array $agent, array $creds): array
    {
        if (empty($creds['bucket'])) {
            @unlink($manifestFile);
            return ['success' => false, 'output' => 'No S3 bucket configured'];
        }

        if (!$this->isRcloneInstalled()) {
            @unlink($manifestFile);
            return ['success' => false, 'output' => 'rclone is not installed'];
        }

        // Build remote path
        $agentName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $agent['name'] ?? 'unknown');
        $repoName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $repo['name'] ?? 'unknown');
        $prefix = trim($creds['path_prefix'], '/');
        $remotePath = $prefix ? "{$prefix}/{$agentName}/{$repoName}" : "{$agentName}/{$repoName}";
        $remote = "S3:{$creds['bucket']}/{$remotePath}/.bbs-manifest.json";

        // Upload via rclone copyto.
        // --s3-no-check-bucket: skip the pre-flight CreateBucket probe that
        // 403s on least-privilege IAM policies (see syncRepository()).
        $cmd = ['rclone', 'copyto', $manifestFile, $remote, '--s3-no-check-bucket'];
        $env = $this->buildRcloneEnv($creds);

        $desc = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $envStrings = [
            'RCLONE_CONFIG' => '/dev/null',
            'HOME' => '/tmp',
            'PATH' => '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin',
        ];
        foreach ($env as $k => $v) {
            $envStrings[$k] = $v;
        }

        $proc = proc_open($cmd, $desc, $pipes, null, $envStrings);
        if (!is_resource($proc)) {
            @unlink($manifestFile);
            return ['success' => false, 'output' => 'Failed to start rclone process'];
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exitCode = proc_close($proc);

        @unlink($manifestFile);

        return [
            'success' => $exitCode === 0,
            'output' => $exitCode === 0
                ? 'Manifest uploaded'
                : (trim($stderr . $stdout) ?: "rclone exited with code {$exitCode}"),
        ];
    }

    /**
     * Download manifest from S3.
     * Returns ['success' => bool, 'file' => string|null, 'error' => string|null].
     * Caller is responsible for deleting the temp file after use.
     */
    public function downloadManifestFile(array $repo, array $agent, array $creds, ?array $sourceRepo = null): array
    {
        if (empty($creds['bucket'])) {
            return ['success' => false, 'file' => null, 'error' => 'No S3 bucket configured'];
        }

        if (!$this->isRcloneInstalled()) {
            return ['success' => false, 'file' => null, 'error' => 'rclone is not installed'];
        }

        // Build remote path (use sourceRepo for copy mode)
        $agentName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $agent['name'] ?? 'unknown');
        $sourceRepoName = $sourceRepo['name'] ?? $repo['name'];
        $repoName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $sourceRepoName ?? 'unknown');
        $prefix = trim($creds['path_prefix'], '/');
        $remotePath = $prefix ? "{$prefix}/{$agentName}/{$repoName}" : "{$agentName}/{$repoName}";
        $remote = "S3:{$creds['bucket']}/{$remotePath}/.bbs-manifest.json";

        // Download to temp file
        $tempFile = tempnam(sys_get_temp_dir(), 'bbs-manifest-');

        $cmd = ['rclone', 'copyto', $remote, $tempFile];
        $env = $this->buildRcloneEnv($creds);

        $desc = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $envStrings = [
            'RCLONE_CONFIG' => '/dev/null',
            'HOME' => '/tmp',
            'PATH' => '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin',
        ];
        foreach ($env as $k => $v) {
            $envStrings[$k] = $v;
        }

        $proc = proc_open($cmd, $desc, $pipes, null, $envStrings);
        if (!is_resource($proc)) {
            @unlink($tempFile);
            return ['success' => false, 'file' => null, 'error' => 'Failed to start rclone process'];
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exitCode = proc_close($proc);

        if ($exitCode !== 0) {
            @unlink($tempFile);
            // Exit code 3 typically means file not found
            if ($exitCode === 3 || strpos($stderr . $stdout, 'not found') !== false) {
                return ['success' => false, 'file' => null, 'error' => 'Manifest not found'];
            }
            return ['success' => false, 'file' => null, 'error' => trim($stderr . $stdout) ?: "rclone exited with code {$exitCode}"];
        }

        if (!file_exists($tempFile) || filesize($tempFile) === 0) {
            @unlink($tempFile);
            return ['success' => false, 'file' => null, 'error' => 'Empty manifest file'];
        }

        return ['success' => true, 'file' => $tempFile, 'error' => null];
    }

    /**
     * Import manifest from file into database (archives, file catalog, repo metadata).
     * Streams the file to handle large manifests without memory issues.
     * Returns ['success' => bool, 'archives' => int, 'files' => int, 'error' => string|null].
     * @param string $manifestFile Path to manifest file (will be deleted after import)
     */
    public function importManifestFile(string $manifestFile, int $repoId): array
    {
        // For streaming JSON parsing, we'll read the file in sections
        // First, read the header portion (version, generated_at, repository, archives)
        // which is small, then stream the file_catalog

        $content = file_get_contents($manifestFile);
        if (empty($content)) {
            @unlink($manifestFile);
            return ['success' => false, 'archives' => 0, 'files' => 0, 'error' => 'Empty manifest file'];
        }

        // For manifests under 50MB, just parse normally
        $fileSize = filesize($manifestFile);
        if ($fileSize < 50 * 1024 * 1024) {
            $manifest = json_decode($content, true);
            @unlink($manifestFile);

            if (!$manifest || !isset($manifest['version'])) {
                return ['success' => false, 'archives' => 0, 'files' => 0, 'error' => 'Invalid manifest format'];
            }

            return $this->importManifestArray($manifest, $repoId);
        }

        // For large manifests, use streaming approach
        @unlink($manifestFile);

        // Parse normally but in chunks - for very large files, use JsonMachine or similar
        // For now, rely on PHP's memory and parse the full file
        // TODO: Implement true streaming JSON parser for 100M+ file catalogs
        $manifest = json_decode($content, true);
        unset($content);  // Free memory

        if (!$manifest || !isset($manifest['version'])) {
            return ['success' => false, 'archives' => 0, 'files' => 0, 'error' => 'Invalid manifest format'];
        }

        return $this->importManifestArray($manifest, $repoId);
    }

    /**
     * Import manifest array into database.
     * Internal helper used by importManifestFile.
     */
    private function importManifestArray(array $manifest, int $repoId): array
    {
        if (!isset($manifest['version']) || $manifest['version'] !== 1) {
            return ['success' => false, 'archives' => 0, 'files' => 0, 'error' => 'Unsupported manifest version'];
        }

        // Update repository metadata if available
        if (!empty($manifest['repository'])) {
            $repoUpdate = [];
            if (!empty($manifest['repository']['encryption'])) {
                $repoUpdate['encryption'] = $manifest['repository']['encryption'];
            }
            if (!empty($manifest['repository']['passphrase'])) {
                $repoUpdate['passphrase_encrypted'] = Encryption::encrypt($manifest['repository']['passphrase']);
            }
            if (!empty($repoUpdate)) {
                $this->db->update('repositories', $repoUpdate, 'id = ?', [$repoId]);
            }
        }

        // Clear existing archives for this repo (also cascades to file_catalog via FK)
        $this->db->delete('archives', 'repository_id = ?', [$repoId]);

        // Import archives
        $archiveCount = 0;
        $totalSize = 0;
        $archiveNameToId = [];

        foreach ($manifest['archives'] ?? [] as $ar) {
            $archiveId = $this->db->insert('archives', [
                'repository_id' => $repoId,
                'archive_name' => $ar['name'],
                'original_size' => $ar['original_size'] ?? 0,
                'deduplicated_size' => $ar['deduplicated_size'] ?? 0,
                'file_count' => $ar['file_count'] ?? 0,
                'created_at' => $ar['created_at'] ?? date('Y-m-d H:i:s'),
            ]);
            $archiveNameToId[$ar['name']] = $archiveId;
            $archiveCount++;
            $totalSize += $ar['deduplicated_size'] ?? 0;
        }

        // Get agent_id for ClickHouse catalog
        $repo = $this->db->fetchOne("SELECT agent_id FROM repositories WHERE id = ?", [$repoId]);
        $agentId = (int) ($repo['agent_id'] ?? 0);

        // Import file catalog via ClickHouse TSV upload
        $ch = \BBS\Core\ClickHouse::getInstance();
        $escape = fn(string $s) => str_replace(["\t", "\n", "\\"], ["\\t", "\\n", "\\\\"], $s);
        $fileCount = 0;
        $fileCatalog = $manifest['file_catalog'] ?? [];

        if (!empty($fileCatalog)) {
            $tsvFile = sys_get_temp_dir() . "/s3_import_catalog_{$agentId}_" . getmypid() . '.tsv';
            $tsvFh = fopen($tsvFile, 'w');

            foreach ($fileCatalog as $file) {
                $archiveId = $archiveNameToId[$file['archive']] ?? null;
                $path = $file['path'] ?? '';
                if ($archiveId && $path) {
                    $mtime = $file['mtime'] ?? '\\N';
                    fwrite($tsvFh, "{$agentId}\t{$archiveId}\t{$escape($path)}\t{$escape(basename($path))}\t{$escape(dirname($path))}\t" . ((int) ($file['size'] ?? 0)) . "\tU\t{$mtime}\n");
                    $fileCount++;
                }
            }
            fclose($tsvFh);

            if ($fileCount > 0) {
                try {
                    $ch->insertTsv('file_catalog', $tsvFile, [
                        'agent_id', 'archive_id', 'path', 'file_name', 'parent_dir', 'file_size', 'status', 'mtime'
                    ]);
                } catch (\Exception $e) {
                    @unlink($tsvFile);
                    // Non-fatal — archives are still imported
                }
            }
            @unlink($tsvFile);
        }

        // Update repository stats
        $this->db->update('repositories', [
            'archive_count' => $archiveCount,
            'size_bytes' => $totalSize,
        ], 'id = ?', [$repoId]);

        return [
            'success' => true,
            'archives' => $archiveCount,
            'files' => $fileCount,
            'error' => null,
        ];
    }

    /**
     * Delete a repository from S3.
     * Returns ['success' => bool, 'output' => string].
     */
    public function deleteFromS3(array $repo, array $agent, array $creds): array
    {
        if (empty($creds['bucket'])) {
            return ['success' => false, 'output' => 'No S3 bucket configured'];
        }

        if (!$this->isRcloneInstalled()) {
            return ['success' => false, 'output' => 'rclone is not installed on this server'];
        }

        // Build remote path: bucket/prefix/agent-name/repo-name/
        $agentName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $agent['name'] ?? 'unknown');
        $repoName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $repo['name'] ?? 'unknown');
        $prefix = trim($creds['path_prefix'], '/');
        $remotePath = $prefix ? "{$prefix}/{$agentName}/{$repoName}" : "{$agentName}/{$repoName}";
        $remote = "S3:{$creds['bucket']}/{$remotePath}/";

        // Use rclone purge to delete the directory and all contents
        $cmd = ['rclone', 'purge', $remote];

        $env = $this->buildRcloneEnv($creds);

        $desc = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $envStrings = [
            'RCLONE_CONFIG' => '/dev/null',
            'HOME' => '/tmp',
            'PATH' => '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin',
        ];
        foreach ($env as $k => $v) {
            $envStrings[$k] = $v;
        }

        $proc = proc_open($cmd, $desc, $pipes, null, $envStrings);
        if (!is_resource($proc)) {
            return ['success' => false, 'output' => 'Failed to start rclone process'];
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exitCode = proc_close($proc);

        $fullOutput = trim($stdout . "\n" . $stderr);

        return [
            'success' => $exitCode === 0,
            'output' => $exitCode === 0
                ? 'Deleted from S3'
                : ($fullOutput ?: "rclone exited with code {$exitCode}"),
        ];
    }
}
