<?php

namespace BBS\Services;

use BBS\Core\Database;

class S3SyncService
{
    /**
     * Maximum file_catalog rows to embed in a manifest.
     *
     * The manifest is generated inline by the once-a-minute scheduler tick and
     * holds the scheduler's flock for the whole run, so an unbounded catalog
     * export stalls every other queued job. It is also pointless past this
     * size: importManifestFile() json_decode()s the whole document in memory,
     * so a multi-GB manifest can never be read back.
     *
     * At roughly 200 bytes of JSON per row this keeps the catalog section near
     * the ~50MB ceiling the importer can actually handle. Larger repositories
     * write archives only and rebuild the catalog via catalog_sync on restore.
     */
    public const MANIFEST_MAX_CATALOG_ROWS = 250000;

    /**
     * What makes rclone report as it goes. bin/bbs-ssh-helper already passes
     * these on the helper path; the direct path was silent, so a run as the
     * web user had nothing to show.
     */
    private const STATS_FLAGS = ['-v', '--stats-one-line', '--stats', '5s'];

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
     * The stored global credentials with a form's unsaved values laid over
     * them, for testing before saving (#441). Text fields replace the stored
     * value when present in the input; the two keys only when non-empty,
     * since a blank key field means "unchanged". Nothing is written.
     */
    public function globalCredentialsWithOverrides(array $input): array
    {
        $creds = $this->resolveCredentials(['credential_source' => 'global']);
        foreach (['endpoint', 'region', 'bucket', 'path_prefix'] as $f) {
            foreach ([$f, 's3_' . $f] as $k) {
                if (array_key_exists($k, $input)) {
                    $creds[$f] = trim((string) $input[$k]);
                }
            }
        }
        foreach (['access_key', 'secret_key'] as $f) {
            foreach ([$f, 's3_' . $f] as $k) {
                $v = trim((string) ($input[$k] ?? ''));
                if ($v !== '') {
                    $creds[$f] = $v;
                }
            }
        }
        return $creds;
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
     * Queue a sync of one repository to one of its destinations now, rather
     * than waiting for the next prune (#501). Returns ['ok' => bool,
     * 'code' => int, 'error' => ?string, 'job_id' => ?int, 'note' => ?string].
     * The queue runs one job per repository at a time, so a sync queued
     * while a backup runs waits for it; 'note' says so.
     */
    public function queueSync(int $agentId, int $repoId, int $pluginConfigId): array
    {
        $repo = $this->db->fetchOne("SELECT id, name, storage_type FROM repositories WHERE id = ? AND agent_id = ?", [$repoId, $agentId]);
        if (!$repo) {
            return ['ok' => false, 'code' => 404, 'error' => 'Repository not found', 'job_id' => null, 'note' => null];
        }
        if (($repo['storage_type'] ?? 'local') !== 'local') {
            return ['ok' => false, 'code' => 400, 'error' => 'Only local repositories are copied offsite', 'job_id' => null, 'note' => null];
        }
        $dest = $this->db->fetchOne(
            "SELECT pc.name FROM repository_s3_configs rsc JOIN plugin_configs pc ON pc.id = rsc.plugin_config_id
             WHERE rsc.repository_id = ? AND rsc.plugin_config_id = ?",
            [$repoId, $pluginConfigId]
        );
        if (!$dest) {
            return ['ok' => false, 'code' => 404, 'error' => 'That destination is not attached to this repository', 'job_id' => null, 'note' => null];
        }
        $pending = $this->db->fetchOne(
            "SELECT id, status FROM backup_jobs WHERE repository_id = ? AND task_type = 's3_sync' AND plugin_config_id = ?
               AND status IN ('queued', 'sent', 'running') LIMIT 1",
            [$repoId, $pluginConfigId]
        );
        if ($pending) {
            return ['ok' => false, 'code' => 409, 'error' => "A sync to \"{$dest['name']}\" is already {$pending['status']} (job #{$pending['id']})", 'job_id' => (int) $pending['id'], 'note' => null];
        }
        $active = $this->db->fetchOne(
            "SELECT id, task_type FROM backup_jobs WHERE repository_id = ? AND status IN ('queued', 'sent', 'running') LIMIT 1",
            [$repoId]
        );
        $jobId = (int) $this->db->insert('backup_jobs', [
            'agent_id' => $agentId,
            'repository_id' => $repoId,
            'task_type' => 's3_sync',
            'plugin_config_id' => $pluginConfigId,
            'status' => 'queued',
        ]);
        $this->db->insert('server_log', [
            'agent_id' => $agentId,
            'backup_job_id' => $jobId,
            'level' => 'info',
            'message' => "Offsite sync to \"{$dest['name']}\" queued by request (job #{$jobId})",
        ]);
        $note = $active ? "It runs after the {$active['task_type']} job #{$active['id']} that is on this repository now, so the copy is taken from a settled repository." : null;
        return ['ok' => true, 'code' => 202, 'error' => null, 'job_id' => $jobId, 'note' => $note];
    }

    /** Destination types an Offsite Sync config can point at (#413). */
    public const TYPE_S3 = 's3';
    public const TYPE_SFTP = 'sftp';
    public const TYPE_LOCAL = 'local';

    /**
     * Turn a plugin config into an rclone destination: its type, a label
     * for the UI, the RCLONE_CONFIG_DEST_* environment that configures the
     * backend, the base "DEST:bucket/prefix" (or a bare local path) that
     * client and repository folders hang under, and extra rclone flags.
     * 'error' is set, and nothing else is usable, when the destination
     * cannot be built: a missing bucket, a deleted SSH host or storage
     * location, or a BorgBase host, which accepts borg only.
     *
     * S3 is the default so every config saved before destination types
     * existed keeps working unchanged.
     */
    public function resolveDestination(array $config): array
    {
        $type = $config['target_type'] ?? self::TYPE_S3;
        if (!in_array($type, [self::TYPE_S3, self::TYPE_SFTP, self::TYPE_LOCAL], true)) {
            $type = self::TYPE_S3;
        }
        $prefix = trim((string) ($config['path_prefix'] ?? ''), '/');
        $bandwidth = trim((string) ($config['bandwidth_limit'] ?? ''));

        if ($type === self::TYPE_S3) {
            return $this->destinationFromCredentials($this->resolveCredentials($config));
        }

        if ($type === self::TYPE_SFTP) {
            $sshId = (int) ($config['remote_ssh_config_id'] ?? 0);
            $ssh = $sshId > 0 ? (new RemoteSshService())->getDecrypted($sshId) : null;
            if (!$ssh) {
                return $this->destinationError($type, 'The SSH host for this destination no longer exists');
            }
            if (($ssh['provider'] ?? '') === 'borgbase' || str_contains((string) ($ssh['remote_host'] ?? ''), '.repo.borgbase.com')) {
                return $this->destinationError($type, 'BorgBase hosts accept borg only, not SFTP; pick another host');
            }
            // The host's base path, relative to the login's home unless
            // absolute. "./" and "" both mean home.
            $rawBase = trim((string) ($ssh['remote_base_path'] ?? './'));
            $absolute = str_starts_with($rawBase, '/');
            $base = trim(preg_replace('#^\./#', '', $rawBase), '/');
            $root = ($absolute ? '/' : '') . ($base !== '' ? $base . '/' : '') . ($prefix !== '' ? $prefix : 'bbs-sync');
            // rclone takes the key inline, one line, newlines written as \n.
            $pem = str_replace(["\r\n", "\r"], "\n", trim((string) ($ssh['ssh_private_key'] ?? ''))) . "\n";
            return [
                'type' => $type,
                'label' => $ssh['name'] . ' (' . $ssh['remote_user'] . '@' . $ssh['remote_host'] . ')',
                'env' => [
                    'RCLONE_CONFIG_DEST_TYPE' => 'sftp',
                    'RCLONE_CONFIG_DEST_HOST' => (string) $ssh['remote_host'],
                    'RCLONE_CONFIG_DEST_PORT' => (string) ((int) ($ssh['remote_port'] ?? 22) ?: 22),
                    'RCLONE_CONFIG_DEST_USER' => (string) $ssh['remote_user'],
                    'RCLONE_CONFIG_DEST_KEY_PEM' => str_replace("\n", '\n', $pem),
                ],
                'base' => 'DEST:' . $root,
                'flags' => [],
                'bandwidth_limit' => $bandwidth,
                'error' => null,
            ];
        }

        $locId = (int) ($config['storage_location_id'] ?? 0);
        $loc = $locId > 0 ? $this->db->fetchOne("SELECT * FROM storage_locations WHERE id = ?", [$locId]) : null;
        if (!$loc) {
            return $this->destinationError($type, 'The storage location for this destination no longer exists');
        }
        return [
            'type' => $type,
            'label' => $loc['label'] . ' (' . $loc['path'] . ')',
            'env' => [],
            'base' => rtrim($loc['path'], '/') . '/' . ($prefix !== '' ? $prefix : 'bbs-sync'),
            'flags' => [],
            'bandwidth_limit' => $bandwidth,
            'error' => null,
        ];
    }

    /**
     * An S3 destination from resolved credentials: the global settings, a
     * config's custom credentials, or a form's unsaved values under test.
     */
    public function destinationFromCredentials(array $creds): array
    {
        if (empty($creds['bucket'])) {
            return $this->destinationError(self::TYPE_S3, 'No S3 bucket configured');
        }
        $env = [];
        foreach ($this->buildRcloneEnv($creds) as $k => $v) {
            $env[str_replace('RCLONE_CONFIG_S3_', 'RCLONE_CONFIG_DEST_', $k)] = $v;
        }
        $prefix = trim((string) ($creds['path_prefix'] ?? ''), '/');
        $host = preg_replace('#^https?://#', '', (string) ($creds['endpoint'] ?? ''));
        return [
            'type' => self::TYPE_S3,
            'label' => 'S3 bucket ' . $creds['bucket'] . ($host !== '' ? ' at ' . $host : ''),
            'env' => $env,
            'base' => 'DEST:' . $creds['bucket'] . ($prefix !== '' ? '/' . $prefix : ''),
            // Skip the pre-flight CreateBucket call rclone otherwise issues
            // on every session: the bucket exists, and least-privilege IAM
            // policies without s3:CreateBucket 403 on the probe.
            'flags' => ['--s3-no-check-bucket'],
            'bandwidth_limit' => trim((string) ($creds['bandwidth_limit'] ?? '')),
            'error' => null,
        ];
    }

    /**
     * Type and label of a config's destination without touching secrets,
     * for lists and badges.
     */
    public function describeDestination(array $config): array
    {
        $type = $config['target_type'] ?? self::TYPE_S3;
        if ($type === self::TYPE_SFTP) {
            $ssh = (new RemoteSshService())->getById((int) ($config['remote_ssh_config_id'] ?? 0));
            return ['type' => $type, 'label' => $ssh ? $ssh['name'] . ' (' . $ssh['remote_user'] . '@' . $ssh['remote_host'] . ')' : 'SSH host (deleted)'];
        }
        if ($type === self::TYPE_LOCAL) {
            $loc = $this->db->fetchOne("SELECT label, path FROM storage_locations WHERE id = ?", [(int) ($config['storage_location_id'] ?? 0)]);
            return ['type' => $type, 'label' => $loc ? $loc['label'] . ' (' . $loc['path'] . ')' : 'Storage location (deleted)'];
        }
        if (($config['credential_source'] ?? 'global') === 'global') {
            $bucket = $this->db->fetchOne("SELECT `value` FROM settings WHERE `key` = 's3_bucket'");
            return ['type' => self::TYPE_S3, 'label' => !empty($bucket['value']) ? 'S3 bucket ' . $bucket['value'] . ' (global settings)' : 'Global S3 settings (not configured)'];
        }
        return ['type' => self::TYPE_S3, 'label' => 'S3 bucket ' . ($config['bucket'] ?? '?')];
    }

    private function destinationError(string $type, string $error): array
    {
        return ['type' => $type, 'label' => '', 'env' => [], 'base' => '', 'flags' => [], 'bandwidth_limit' => '', 'error' => $error];
    }

    /** Callers that still pass S3 credentials get a destination made of them. */
    private function asDestination(array $destOrCreds): array
    {
        if (isset($destOrCreds['type'], $destOrCreds['base'])) {
            return $destOrCreds;
        }
        return $this->destinationFromCredentials($destOrCreds);
    }

    /**
     * Where a client's copies live on the destination:
     * <base>/<client>/[<repo>/][suffix]. Names are reduced to
     * [A-Za-z0-9_-], as they always were, so existing S3 layouts are
     * unchanged.
     */
    private function remoteFor(array $dest, string $agentName, ?string $repoName = null, string $suffix = ''): string
    {
        $clean = fn(string $n) => preg_replace('/[^a-zA-Z0-9_-]/', '_', $n !== '' ? $n : 'unknown');
        $path = $dest['base'] . '/' . $clean($agentName) . '/';
        if ($repoName !== null) {
            $path .= $clean($repoName) . '/';
        }
        return $path . $suffix;
    }

    private function rcloneFlags(array $dest, bool $withBandwidth = true): array
    {
        $flags = $dest['flags'] ?? [];
        if ($withBandwidth && !empty($dest['bandwidth_limit'])) {
            $flags[] = '--bwlimit';
            $flags[] = $dest['bandwidth_limit'];
        }
        return $flags;
    }

    /**
     * Drain a running process, handing each complete output line to $onLine.
     *
     * The blocking stream_get_contents() this replaces held every line until
     * rclone exited, so the periodic stats lines it already emits — transferred
     * / total, percent, speed, ETA — were read and thrown away, and a sync of
     * several hours showed no progress at all.
     *
     * stdout and stderr are drained in the same pass: rclone logs to stderr
     * when run directly, while the SSH helper folds both into its stdout with
     * 2>&1. A partial line is kept until its newline arrives, so a stats line
     * split across two reads is never handed over truncated.
     *
     * @return array{stdout:string,stderr:string,timedOut:bool}
     */
    private function pump($proc, array $pipes, ?callable $onLine, ?int $timeoutSeconds): array
    {
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $out = ['', ''];
        $tail = ['', ''];
        $timedOut = false;
        $deadline = $timeoutSeconds === null ? null : time() + $timeoutSeconds;

        while (true) {
            // Status first, read second: a process that exits between the two
            // has already closed its pipes, so the read that follows still
            // returns everything it wrote.
            $running = (bool) proc_get_status($proc)['running'];

            foreach ([0, 1] as $i) {
                $chunk = stream_get_contents($pipes[$i + 1]);
                if ($chunk === false || $chunk === '') {
                    continue;
                }
                $out[$i] .= $chunk;
                if ($onLine === null) {
                    continue;
                }
                $tail[$i] .= $chunk;
                while (($nl = strpos($tail[$i], "\n")) !== false) {
                    $line = rtrim(substr($tail[$i], 0, $nl), "\r");
                    $tail[$i] = substr($tail[$i], $nl + 1);
                    if ($line !== '') {
                        $onLine($line);
                    }
                }
            }

            if (!$running) {
                break;
            }
            if ($deadline !== null && time() >= $deadline) {
                $timedOut = true;
                proc_terminate($proc, 9);
                break;
            }
            // rclone reports every 5s; polling five times a second is enough to
            // stay responsive without spinning.
            usleep(200000);
        }

        return ['stdout' => $out[0], 'stderr' => $out[1], 'timedOut' => $timedOut];
    }

    /**
     * Run rclone as the web user with the destination in its environment.
     * A timeout kills a run that hangs on a dead endpoint; null waits.
     * Returns ['stdout', 'stderr', 'exitCode', 'timedOut'].
     */
    private function runDirect(array $dest, array $args, ?int $timeoutSeconds = null, ?callable $onLine = null): array
    {
        $env = [
            'RCLONE_CONFIG' => '/dev/null',
            'HOME' => '/tmp',
            'PATH' => '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin',
        ] + ($dest['env'] ?? []);
        $desc = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = proc_open(array_merge(['rclone'], $args), $desc, $pipes, null, $env);
        if (!is_resource($proc)) {
            return ['stdout' => '', 'stderr' => 'Failed to start rclone process', 'exitCode' => -1, 'timedOut' => false];
        }
        fclose($pipes[0]);
        $r = $this->pump($proc, $pipes, $onLine, $timeoutSeconds);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($proc);
        return [
            'stdout' => $r['stdout'],
            'stderr' => $r['stderr'],
            'exitCode' => $r['timedOut'] ? -1 : $exitCode,
            'timedOut' => $r['timedOut'],
        ];
    }

    /**
     * Run a repository transfer through bbs-ssh-helper as the repo-owning
     * user. The destination's environment goes to the helper in a 0600
     * file of KEY=base64(value) lines, never on the command line, so
     * credentials and SSH keys do not show in ps. The helper removes the
     * file; this removes it too in case the helper never ran.
     */
    private function runViaHelper(array $dest, string $runAsUser, string $direction, string $localPath, string $remote, ?callable $onLine = null): array
    {
        $envFile = tempnam(sys_get_temp_dir(), 'bbs-dest-');
        if ($envFile === false) {
            return ['stdout' => '', 'stderr' => 'Cannot create a temp file in ' . sys_get_temp_dir(), 'exitCode' => -1, 'timedOut' => false];
        }
        chmod($envFile, 0600);
        $lines = '';
        foreach ($dest['env'] ?? [] as $k => $v) {
            $lines .= $k . '=' . base64_encode((string) $v) . "\n";
        }
        file_put_contents($envFile, $lines);
        try {
            $cmd = array_merge(
                ['sudo', '/usr/local/bin/bbs-ssh-helper', 'rclone-transfer', $runAsUser, $direction, $localPath, $remote, $envFile],
                $this->rcloneFlags($dest)
            );
            $desc = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
            $proc = proc_open($cmd, $desc, $pipes, null, array_filter($_SERVER, 'is_string'));
            if (!is_resource($proc)) {
                return ['stdout' => '', 'stderr' => 'Failed to start rclone process', 'exitCode' => -1, 'timedOut' => false];
            }
            fclose($pipes[0]);
            $r = $this->pump($proc, $pipes, $onLine, null);
            fclose($pipes[1]);
            fclose($pipes[2]);
            return [
                'stdout' => $r['stdout'],
                'stderr' => $r['stderr'],
                'exitCode' => proc_close($proc),
                'timedOut' => false,
            ];
        } finally {
            @unlink($envFile);
        }
    }

    /**
     * A line handler that turns rclone's periodic stats into job progress.
     *
     * rclone already prints, every 5s under --stats-one-line:
     *   Transferred: 521.469 MiB / 1.196 GiB, 43%, 9.185 MiB/s, ETA 1m15s
     * which carries everything the queue needs. The columns it fills —
     * bytes_processed, bytes_total, status_message, last_progress_at — are
     * the ones /api/v1/queue already returns for every task type, so the
     * queue page and the API draw the bar with no further change.
     *
     * Writing last_progress_at is safe here: the stalled-job sweep that reads
     * it skips server-side task types, s3_sync among them.
     *
     * The total and the ETA are rough while rclone is still walking the tree,
     * and settle once the listing is done. That is rclone's behaviour, not a
     * rounding choice made here.
     */
    private function progressReporter(int $jobId): callable
    {
        $lastWrite = 0.0;
        return function (string $line) use ($jobId, &$lastWrite): void {
            // The "Transferred:" label is optional: rclone dropped it from the
            // one-line format (1.75 prints "  178.168 MiB / 178.168 MiB, 100%,
            // 0 B/s, ETA -"), older builds still print it. Requiring it matched
            // nothing at all on a current rclone, and said nothing about why.
            // The size units are what makes this a stats line and not a file
            // name — the sibling "Transferred: 12 / 12, 100%" counter carries
            // no unit and is correctly ignored.
            if (!preg_match(
                '~(?:Transferred:)?\s*([\d.]+)\s*([KMGTP]?i?B)\s*/\s*([\d.]+)\s*([KMGTP]?i?B),\s*(\d+)\s*%~',
                $line, $m
            )) {
                return;
            }
            // rclone speaks every 5s; this only guards against a build that
            // was configured to speak far more often. The last line is never
            // dropped, whatever its timing: a finished sync left showing 93%
            // reads as a partial failure, and rclone's closing line often
            // follows the previous one by less than the guard.
            $now = microtime(true);
            if ((int) $m[5] < 100 && $now - $lastWrite < 2.0) {
                return;
            }
            $lastWrite = $now;

            $clean = preg_replace('/^\d{4}\/\d{2}\/\d{2}\s+\d{2}:\d{2}:\d{2}\s+\w+\s*:\s*/', '', $line);

            $row = [
                'bytes_processed' => self::toBytes($m[1], $m[2]),
                'bytes_total' => self::toBytes($m[3], $m[4]),
                'status_message' => mb_substr(trim($clean), 0, 255),
                'last_progress_at' => date('Y-m-d H:i:s'),
            ];
            // rclone appends "(xfr#29/923)" while transfers are in flight:
            // files started over files queued. Matched on its own rather than
            // folded into the pattern above — it is absent from the closing
            // line, and an optional tail makes the whole expression fragile.
            if (preg_match('~\(xfr#(\d+)/(\d+)\)~', $line, $x)) {
                $row['files_processed'] = (int) $x[1];
                $row['files_total'] = (int) $x[2];
            }

            try {
                $this->db->update('backup_jobs', $row, 'id = ?', [$jobId]);
            } catch (\Throwable $e) {
                // Progress is a courtesy: a failed write must never abort a
                // transfer that is otherwise going fine.
            }
        };
    }

    /** rclone's human sizes back to bytes. Binary units, as rclone prints them. */
    private static function toBytes(string $n, string $unit): int
    {
        $mult = ['B' => 1, 'KiB' => 1024, 'MiB' => 1024 ** 2, 'GiB' => 1024 ** 3,
                 'TiB' => 1024 ** 4, 'PiB' => 1024 ** 5,
                 'KB' => 1000, 'MB' => 1000 ** 2, 'GB' => 1000 ** 3,
                 'TB' => 1000 ** 4, 'PB' => 1000 ** 5];
        return (int) round(((float) $n) * ($mult[$unit] ?? 1));
    }

    /** The final rclone stats line, without its timestamp prefix. */
    private function summaryLine(string $output): string
    {
        $lines = array_filter(array_map('trim', explode("\n", $output)));
        $last = end($lines);
        return $last ? preg_replace('/^\d{4}\/\d{2}\/\d{2}\s+\d{2}:\d{2}:\d{2}\s+\w+\s+:\s+/', '', $last) : '';
    }

    /**
     * Copy a repository to its destination.
     * Returns ['success' => bool, 'output' => string].
     */
    public function syncRepository(array $repo, array $agent, array $destOrCreds, ?string $runAsUser = null, ?int $jobId = null): array
    {
        $dest = $this->asDestination($destOrCreds);
        if ($dest['error']) {
            return ['success' => false, 'output' => $dest['error']];
        }
        if (!$this->isRcloneInstalled()) {
            return ['success' => false, 'output' => 'rclone is not installed on this server'];
        }
        $remote = $this->remoteFor($dest, (string) ($agent['name'] ?? ''), (string) ($repo['name'] ?? ''));
        $localPath = \BBS\Services\BorgCommandBuilder::getLocalRepoPath($repo);
        if (empty($localPath) || !is_dir($localPath)) {
            return ['success' => false, 'output' => "Local repo path not found: {$localPath}"];
        }
        if ($dest['type'] === self::TYPE_LOCAL && str_starts_with(rtrim($remote, '/') . '/', rtrim($localPath, '/') . '/')) {
            return ['success' => false, 'output' => 'The destination is inside the repository itself'];
        }

        // The helper already runs rclone with -v --stats-one-line --stats 5s;
        // the direct path did not, so it had nothing to report. Both now do.
        $onLine = $jobId !== null ? $this->progressReporter($jobId) : null;
        if ($runAsUser) {
            $r = $this->runViaHelper($dest, $runAsUser, 'push', $localPath, $remote, $onLine);
        } else {
            $r = $this->runDirect($dest, array_merge(
                ['sync', $localPath, $remote, '--transfers', '4', '--checkers', '8'],
                self::STATS_FLAGS,
                $this->rcloneFlags($dest)
            ), null, $onLine);
        }
        $fullOutput = trim($r['stdout'] . "\n" . $r['stderr']);
        $ok = $r['exitCode'] === 0;
        return [
            'success' => $ok,
            'output' => $ok ? ($this->summaryLine($fullOutput) ?: 'Sync completed') : ($fullOutput ?: "rclone exited with code {$r['exitCode']}"),
        ];
    }

    /**
     * Bring a repository back from its destination, over the local copy.
     * For "copy" mode, $sourceRepo names the repository whose offsite copy
     * to pull, into $repo's path.
     * Returns ['success' => bool, 'output' => string].
     */
    public function restoreRepository(array $repo, array $agent, array $destOrCreds, ?string $runAsUser = null, ?array $sourceRepo = null, ?int $jobId = null): array
    {
        $dest = $this->asDestination($destOrCreds);
        if ($dest['error']) {
            return ['success' => false, 'output' => $dest['error']];
        }
        if (!$this->isRcloneInstalled()) {
            return ['success' => false, 'output' => 'rclone is not installed on this server'];
        }
        $remote = $this->remoteFor($dest, (string) ($agent['name'] ?? ''), (string) (($sourceRepo['name'] ?? null) ?? ($repo['name'] ?? '')));
        $localPath = \BBS\Services\BorgCommandBuilder::getLocalRepoPath($repo);
        if (empty($localPath)) {
            return ['success' => false, 'output' => 'Local repo path not configured'];
        }
        if (!is_dir($localPath) && !$runAsUser) {
            $parentDir = dirname($localPath);
            if (!is_dir($parentDir)) {
                mkdir($parentDir, 0755, true);
            }
        }

        $onLine = $jobId !== null ? $this->progressReporter($jobId) : null;
        if ($runAsUser) {
            $r = $this->runViaHelper($dest, $runAsUser, 'pull', $localPath, $remote, $onLine);
        } else {
            $r = $this->runDirect($dest, array_merge(
                ['sync', $remote, $localPath, '--transfers', '4', '--checkers', '8'],
                self::STATS_FLAGS,
                $this->rcloneFlags($dest)
            ), null, $onLine);
        }
        $fullOutput = trim($r['stdout'] . "\n" . $r['stderr']);
        $ok = $r['exitCode'] === 0;
        return [
            'success' => $ok,
            'output' => $ok ? ($this->summaryLine($fullOutput) ?: 'Restore completed') : ($fullOutput ?: "rclone exited with code {$r['exitCode']}"),
        ];
    }

    /**
     * Can the destination be reached? Lists the bucket, the SSH login's
     * home, or checks the local folder exists.
     * Returns ['success' => bool, 'error' => string|null, 'label' => string].
     */
    public function testConnection(array $destOrCreds): array
    {
        $dest = $this->asDestination($destOrCreds);
        if ($dest['error']) {
            return ['success' => false, 'error' => $dest['error'], 'label' => ''];
        }
        if ($dest['type'] === self::TYPE_LOCAL) {
            $root = preg_replace('#/[^/]+$#', '', $dest['base']);
            if (!is_dir($root)) {
                return ['success' => false, 'error' => "Folder not found: {$root}", 'label' => $dest['label']];
            }
            return ['success' => true, 'error' => null, 'label' => $dest['label']];
        }
        if (!$this->isRcloneInstalled()) {
            return ['success' => false, 'error' => 'rclone is not installed on this server. Install with: apt install rclone', 'label' => $dest['label']];
        }
        if ($dest['type'] === self::TYPE_S3) {
            $endpoint = (string) ($dest['env']['RCLONE_CONFIG_DEST_ENDPOINT'] ?? '');
            if ($endpoint !== '') {
                if (!preg_match('#^https?://#i', $endpoint)) {
                    $endpoint = 'https://' . $endpoint;
                }
                $parsed = parse_url($endpoint);
                if (empty($parsed['host']) || !preg_match('/\.[a-z]{2,}$/i', $parsed['host'])) {
                    return ['success' => false, 'error' => 'Invalid endpoint URL — must be a hostname like s3.us-east-1.amazonaws.com', 'label' => $dest['label']];
                }
            }
            // The bucket root, whatever prefix the config adds.
            $target = preg_replace('#^(DEST:[^/]+).*$#', '$1/', $dest['base']);
        } else {
            $target = 'DEST:';
        }
        $r = $this->runDirect($dest, array_merge(['lsd', $target, '--max-depth', '1', '--contimeout', '5s', '--timeout', '8s'], $this->rcloneFlags($dest, false)), 12);
        if ($r['exitCode'] !== 0) {
            $error = trim($r['stderr']) ?: trim($r['stdout']) ?: "rclone exited with code {$r['exitCode']}";
            if ($r['timedOut']) {
                $error = 'Connection timed out — check the host or endpoint and the credentials';
            }
            return ['success' => false, 'error' => $error, 'label' => $dest['label']];
        }
        return ['success' => true, 'error' => null, 'label' => $dest['label']];
    }

    /**
     * Repository folders present for a client on the destination, for
     * finding copies whose local repository is gone.
     * Returns ['success' => bool, 'repos' => string[], 'error' => string|null].
     */
    public function listRemoteRepos(string $agentName, array $destOrCreds): array
    {
        $dest = $this->asDestination($destOrCreds);
        if ($dest['error']) {
            return ['success' => false, 'repos' => [], 'error' => $dest['error']];
        }
        $remote = $this->remoteFor($dest, $agentName);
        if ($dest['type'] === self::TYPE_LOCAL) {
            if (!is_dir($remote)) {
                return ['success' => true, 'repos' => [], 'error' => null];
            }
            $repos = array_values(array_filter(scandir($remote) ?: [], fn($n) => $n[0] !== '.' && is_dir($remote . $n)));
            return ['success' => true, 'repos' => $repos, 'error' => null];
        }
        if (!$this->isRcloneInstalled()) {
            return ['success' => false, 'repos' => [], 'error' => 'rclone is not installed'];
        }
        $r = $this->runDirect($dest, array_merge(['lsd', $remote, '--contimeout', '5s', '--timeout', '8s'], $this->rcloneFlags($dest, false)), 10);
        // Exit code 3: the client folder does not exist yet.
        if ($r['exitCode'] === 3) {
            return ['success' => true, 'repos' => [], 'error' => null];
        }
        if ($r['exitCode'] !== 0) {
            $error = trim($r['stderr']) ?: trim($r['stdout']) ?: "rclone exited with code {$r['exitCode']}";
            if ($r['timedOut']) {
                $error = 'Connection timed out — check the host or endpoint and the credentials';
            }
            return ['success' => false, 'repos' => [], 'error' => $error];
        }
        // rclone lsd: "          -1 2024-01-15 10:30:00        -1 repo-name"
        $repos = [];
        foreach (array_filter(array_map('trim', explode("\n", $r['stdout']))) as $line) {
            $parts = preg_split('/\s+/', $line);
            $name = end($parts);
            if (!empty($name)) {
                $repos[] = $name;
            }
        }
        return ['success' => true, 'repos' => $repos, 'error' => null];
    }

    /**
     * Put the manifest beside the repository copy on the destination.
     * Returns ['success' => bool, 'output' => string]. The file is removed.
     */
    public function uploadManifestFile(string $manifestFile, array $repo, array $agent, array $destOrCreds): array
    {
        $dest = $this->asDestination($destOrCreds);
        if ($dest['error']) {
            @unlink($manifestFile);
            return ['success' => false, 'output' => $dest['error']];
        }
        if (!$this->isRcloneInstalled()) {
            @unlink($manifestFile);
            return ['success' => false, 'output' => 'rclone is not installed'];
        }
        $remote = $this->remoteFor($dest, (string) ($agent['name'] ?? ''), (string) ($repo['name'] ?? ''), '.bbs-manifest.json');
        $r = $this->runDirect($dest, array_merge(['copyto', $manifestFile, $remote], $this->rcloneFlags($dest, false)));
        @unlink($manifestFile);
        return [
            'success' => $r['exitCode'] === 0,
            'output' => $r['exitCode'] === 0 ? 'Manifest uploaded' : (trim($r['stderr'] . $r['stdout']) ?: "rclone exited with code {$r['exitCode']}"),
        ];
    }

    /**
     * Fetch the manifest from the destination into a temp file.
     * Returns ['success' => bool, 'file' => string|null, 'error' => string|null].
     * The caller deletes the file.
     */
    public function downloadManifestFile(array $repo, array $agent, array $destOrCreds, ?array $sourceRepo = null): array
    {
        $dest = $this->asDestination($destOrCreds);
        if ($dest['error']) {
            return ['success' => false, 'file' => null, 'error' => $dest['error']];
        }
        if (!$this->isRcloneInstalled()) {
            return ['success' => false, 'file' => null, 'error' => 'rclone is not installed'];
        }
        $remote = $this->remoteFor($dest, (string) ($agent['name'] ?? ''), (string) (($sourceRepo['name'] ?? null) ?? ($repo['name'] ?? '')), '.bbs-manifest.json');
        $tempFile = tempnam(sys_get_temp_dir(), 'bbs-manifest-');
        if ($tempFile === false) {
            return ['success' => false, 'file' => null, 'error' => 'Cannot create temp file in ' . sys_get_temp_dir() . ' — check that the directory exists and is writable (TMPDIR)'];
        }
        $r = $this->runDirect($dest, array_merge(['copyto', $remote, $tempFile], $this->rcloneFlags($dest, false)));
        if ($r['exitCode'] !== 0) {
            @unlink($tempFile);
            if ($r['exitCode'] === 3 || strpos($r['stderr'] . $r['stdout'], 'not found') !== false) {
                return ['success' => false, 'file' => null, 'error' => 'Manifest not found'];
            }
            return ['success' => false, 'file' => null, 'error' => trim($r['stderr'] . $r['stdout']) ?: "rclone exited with code {$r['exitCode']}"];
        }
        if (!file_exists($tempFile) || filesize($tempFile) === 0) {
            @unlink($tempFile);
            return ['success' => false, 'file' => null, 'error' => 'Empty manifest file'];
        }
        return ['success' => true, 'file' => $tempFile, 'error' => null];
    }

    /**
     * Remove a repository's copy from the destination.
     * Returns ['success' => bool, 'output' => string].
     */
    public function deleteFromS3(array $repo, array $agent, array $destOrCreds): array
    {
        $dest = $this->asDestination($destOrCreds);
        if ($dest['error']) {
            return ['success' => false, 'output' => $dest['error']];
        }
        if (!$this->isRcloneInstalled()) {
            return ['success' => false, 'output' => 'rclone is not installed on this server'];
        }
        $remote = $this->remoteFor($dest, (string) ($agent['name'] ?? ''), (string) ($repo['name'] ?? ''));
        $r = $this->runDirect($dest, array_merge(['purge', $remote], $this->rcloneFlags($dest, false)));
        $fullOutput = trim($r['stdout'] . "\n" . $r['stderr']);
        return [
            'success' => $r['exitCode'] === 0,
            'output' => $r['exitCode'] === 0 ? 'Offsite copy deleted' : ($fullOutput ?: "rclone exited with code {$r['exitCode']}"),
        ];
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
     * Generate a manifest file containing repository metadata, archives, and file catalog.
     * Streams directly to file to handle large catalogs (millions of files) without memory issues.
     * Returns ['success' => bool, 'file' => string, 'archives' => int, 'files' => int].
     */
    public function generateManifestFile(array $repo, array $agent, string $passphrase): array
    {
        // tempnam() returns false when the temp dir is unusable — e.g. TMPDIR
        // pointing at a directory that doesn't exist (#371). Fail cleanly
        // instead of fataling on fopen('').
        $tempFile = tempnam(sys_get_temp_dir(), 'bbs-manifest-');
        if ($tempFile === false) {
            return ['success' => false, 'error' => 'Cannot create temp file in ' . sys_get_temp_dir() . ' — check that the directory exists and is writable (TMPDIR)'];
        }
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

        // Count first: repositories with tens of millions of catalog rows would
        // otherwise block the scheduler for an hour writing a manifest no
        // importer can read back. Skip the catalog and flag it instead.
        $catalogRows = 0;
        if (!empty($archiveNameById)) {
            $idList = implode(',', array_map('intval', array_keys($archiveNameById)));
            $countRow = $ch->fetchOne(
                "SELECT count() AS c FROM file_catalog
                 WHERE agent_id = {$agentId} AND archive_id IN ({$idList})"
            );
            $catalogRows = (int) ($countRow['c'] ?? 0);
        }

        $catalogSkipped = $catalogRows > self::MANIFEST_MAX_CATALOG_ROWS;
        fwrite($fp, '  "file_catalog_skipped": ' . ($catalogSkipped ? 'true' : 'false') . ",\n");
        fwrite($fp, '  "file_catalog_row_count": ' . $catalogRows . ",\n");
        fwrite($fp, '  "file_catalog": [' . "\n");

        $batchSize = 10000;
        $firstFile = true;
        $fileCount = 0;

        // An oversized catalog writes an empty array — archives are still
        // present, and restore falls back to catalog_sync to rebuild.
        if ($catalogSkipped) {
            $archiveNameById = [];
        }

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
                            toString(mtime) as mtime
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
            'catalog_skipped' => $catalogSkipped,
            'catalog_rows' => $catalogRows,
        ];
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

        $catalogSkipped = !empty($manifest['file_catalog_skipped']);

        // Update repository stats
        $this->db->update('repositories', [
            'archive_count' => $archiveCount,
            'size_bytes' => $totalSize,
        ], 'id = ?', [$repoId]);

        return [
            'success' => true,
            'archives' => $archiveCount,
            'files' => $fileCount,
            'catalog_skipped' => $catalogSkipped,
            'error' => null,
        ];
    }

}
