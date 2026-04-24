<?php

namespace BBS\Services;

class BorgCommandBuilder
{
    /**
     * Build the borg create command arguments for a backup plan.
     */
    public static function buildCreateCommand(array $plan, array $repo, string $archiveName): array
    {
        $cmd = ['borg', 'create'];

        // JSON output on stdout for archive stats (original_size, deduplicated_size)
        $cmd[] = '--json';
        // JSON logging on stderr for progress parsing + file list for catalog
        $cmd[] = '--log-json';
        $cmd[] = '--list';
        $cmd[] = '--progress';
        // Wait up to 10 min for the repo lock instead of failing immediately
        // when a transient operation (e.g. borg prune/compact, concurrent
        // backup on the same remote repo) still holds it. Borg's default is 1
        // second, which turns any brief contention into a hard failure (#194).
        $cmd[] = '--lock-wait=600';

        // Advanced options from the plan
        // Borg flags like --pattern take a value that may start with + or -
        // which confuses argparse. We join these as --flag=value to avoid ambiguity.
        if (!empty($plan['advanced_options'])) {
            $tokens = preg_split('/\s+/', trim($plan['advanced_options']));
            $flagsWithValues = ['--pattern', '--compression', '--exclude', '--exclude-from',
                '--patterns-from', '--comment', '--chunker-params', '--remote-path'];
            for ($i = 0; $i < count($tokens); $i++) {
                $token = $tokens[$i];
                if (empty($token)) continue;
                if (in_array($token, $flagsWithValues) && isset($tokens[$i + 1])) {
                    $cmd[] = $token . '=' . $tokens[$i + 1];
                    $i++;
                } else {
                    $cmd[] = $token;
                }
            }
        }

        // Exclude patterns
        if (!empty($plan['excludes'])) {
            $excludes = preg_split('/[\n\r]+/', trim($plan['excludes']));
            foreach ($excludes as $pattern) {
                $pattern = trim($pattern);
                if (!empty($pattern)) {
                    $cmd[] = '--exclude';
                    $cmd[] = $pattern;
                }
            }
        }

        // Repository::archive
        $cmd[] = $repo['path'] . '::' . $archiveName;

        // Directories to back up (one per line)
        $dirs = preg_split('/[\n\r]+/', trim($plan['directories']));
        foreach ($dirs as $dir) {
            $dir = trim($dir);
            if (!empty($dir)) {
                $cmd[] = $dir;
            }
        }

        return $cmd;
    }

    /**
     * Build the borg prune command arguments.
     */
    public static function buildPruneCommand(array $plan, array $repo, ?string $archivePrefix = null): array
    {
        $cmd = ['borg', 'prune', '--list', '--log-json'];

        // Scope prune to archives from this specific plan
        if ($archivePrefix) {
            $cmd[] = '--glob-archives=' . $archivePrefix . '-*';
        }

        if ($plan['prune_minutes'] > 0) $cmd[] = '--keep-minutely=' . $plan['prune_minutes'];
        if ($plan['prune_hours'] > 0)   $cmd[] = '--keep-hourly=' . $plan['prune_hours'];
        if ($plan['prune_days'] > 0)    $cmd[] = '--keep-daily=' . $plan['prune_days'];
        if ($plan['prune_weeks'] > 0)   $cmd[] = '--keep-weekly=' . $plan['prune_weeks'];
        if ($plan['prune_months'] > 0)  $cmd[] = '--keep-monthly=' . $plan['prune_months'];
        if ($plan['prune_years'] > 0)   $cmd[] = '--keep-yearly=' . $plan['prune_years'];

        $cmd[] = $repo['path'];

        return $cmd;
    }

    /**
     * Build the borg init command for a new repository.
     */
    public static function buildInitCommand(array $repo): array
    {
        $cmd = ['borg', 'init', '--encryption=' . $repo['encryption'], $repo['path']];
        return $cmd;
    }

    /**
     * Build the borg list command.
     */
    public static function buildListCommand(array $repo, ?string $archiveName = null): array
    {
        $cmd = ['borg', 'list', '--json'];
        $target = $repo['path'];
        if ($archiveName) {
            $target .= '::' . $archiveName;
        }
        $cmd[] = $target;
        return $cmd;
    }

    /**
     * Build the borg info command.
     */
    public static function buildInfoCommand(array $repo): array
    {
        return ['borg', 'info', '--json', $repo['path']];
    }

    /**
     * Build a borg extract (restore) command.
     */
    /**
     * Build a borg extract (restore) command.
     * Note: borg 1.x has no --destination flag. Callers should set the working
     * directory (cwd) to the desired extraction target instead.
     */
    public static function buildExtractCommand(array $repo, string $archiveName, array $paths = [], int $stripComponents = 0): array
    {
        // --progress tells borg to emit progress_percent events; the agent
        // forwards those to /api/agent/progress so the UI shows a live bar
        // during restore instead of staying stuck at "Starting task..." (#168).
        // --list makes borg emit one file_status event per extracted item
        // so the agent can report an accurate file count back to the
        // server (otherwise progress_percent only carries bytes).
        // --lock-wait=600 matches borg create — avoid instant failure when
        // another transient op is holding the repo lock (#194).
        $cmd = ['borg', 'extract', '--log-json', '--progress', '--list', '--lock-wait=600'];

        if ($stripComponents > 0) {
            $cmd[] = '--strip-components=' . $stripComponents;
        }

        $cmd[] = $repo['path'] . '::' . $archiveName;

        foreach ($paths as $path) {
            // Borg extract expects paths without leading slash
            $cmd[] = ltrim($path, '/');
        }

        return $cmd;
    }

    /**
     * Generate an archive name based on current timestamp.
     */
    public static function generateArchiveName(string $prefix = 'backup'): string
    {
        return $prefix . '-' . date('Y-m-d_H-i-s');
    }

    /**
     * Build the environment variables needed for borg (passphrase, SSH).
     *
     * When $forAgent is true (default), includes BORG_RSH for SSH key-based access.
     * When false (server-side execution), omits BORG_RSH since we access repos locally.
     *
     * @param array $repo Repository data
     * @param bool $forAgent Whether this is for agent-side execution
     * @param int|null $sshPort SSH port to use (default 22, used for Docker multi-tenant)
     * @param array|null $remoteSshConfig Remote SSH config for remote_ssh repos (agent-side)
     */
    public static function buildEnv(array $repo, bool $forAgent = true, ?int $sshPort = null, ?array $remoteSshConfig = null): array
    {
        $env = [
            // Agent runs on a different machine than where the repo was created
            'BORG_UNKNOWN_UNENCRYPTED_REPO_ACCESS_IS_OK' => 'yes',
            // Allow repos that were restored from S3 (copies share the same UUID)
            'BORG_RELOCATED_REPO_ACCESS_IS_OK' => 'yes',
        ];
        if (!empty($repo['passphrase_encrypted']) && ($repo['encryption'] ?? '') !== 'none') {
            try {
                $env['BORG_PASSPHRASE'] = Encryption::decrypt($repo['passphrase_encrypted']);
            } catch (\Exception $e) {
                // Fallback: passphrase might be stored in plaintext (pre-encryption migration)
                $env['BORG_PASSPHRASE'] = $repo['passphrase_encrypted'];
            }
        }

        if ($forAgent) {
            if ($remoteSshConfig) {
                // Remote SSH repo: agent uses a temp key file written from the task payload
                $port = (int) ($remoteSshConfig['remote_port'] ?? 22);
                $env['BORG_RSH'] = "ssh -i /tmp/bbs-remote-ssh-key -p {$port} -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o BatchMode=yes";
            } elseif (self::isSshRepo($repo['path'] ?? '')) {
                // Local repo on BBS server: agent uses its installed SSH key
                $port = $sshPort ?? 22;
                $env['BORG_RSH'] = "ssh -i /etc/bbs-agent/ssh_key -p {$port} -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o BatchMode=yes";
            }
        } else {
            // Server-side: www-data can't write to /var/www/.config, redirect borg's config/cache
            $cacheDir = '/var/bbs/cache/www-data';
            if (!is_dir($cacheDir)) {
                @mkdir($cacheDir, 0700, true);
            }
            $env['BORG_BASE_DIR'] = $cacheDir;
            $env['HOME'] = $cacheDir;
        }

        return $env;
    }

    /**
     * Check if a repo path is an SSH path.
     */
    public static function isSshRepo(string $path): bool
    {
        return str_starts_with($path, 'ssh://');
    }

    /**
     * Get the local path for a repo (for server-side operations like prune).
     * Converts ssh://user@host/./reponame to the actual local path using storage location.
     * Returns null for remote SSH repos (storage_type = 'remote_ssh') — they have no local path.
     */
    public static function getLocalRepoPath(array $repo): ?string
    {
        // Remote SSH repos have no local path
        if (($repo['storage_type'] ?? 'local') === 'remote_ssh') {
            return null;
        }

        if (!self::isSshRepo($repo['path'])) {
            return $repo['path'];
        }

        if (!empty($repo['local_path'])) {
            return $repo['local_path'];
        }

        // Extract the directory name from the SSH path rather than using $repo['name'],
        // because the name column may not match the actual directory after renames.
        $parsedPath = parse_url($repo['path'], PHP_URL_PATH) ?? '';
        // Relative paths: /./reponame -> reponame
        // Absolute paths: //var/bbs/home/27/reponame -> /var/bbs/home/27/reponame
        if (str_starts_with($parsedPath, '//')) {
            // Absolute path embedded in SSH URL — strip extra leading slashes
            // parse_url returns ///path for ssh://host//path, so trim down to one /
            return '/' . ltrim($parsedPath, '/');
        }

        // Relative path (e.g. /./reponame) — need to resolve against a base dir
        $repoDir = ltrim($parsedPath, '/');
        if (str_starts_with($repoDir, './')) {
            $repoDir = substr($repoDir, 2);
        }

        $db = \BBS\Core\Database::getInstance();

        // If repo has a storage_location_id, use that location's path
        if (!empty($repo['storage_location_id'])) {
            $loc = $db->fetchOne("SELECT path FROM storage_locations WHERE id = ?", [$repo['storage_location_id']]);
            if ($loc) {
                return rtrim($loc['path'], '/') . '/' . $repo['agent_id'] . '/' . $repoDir;
            }
        }

        // Use agent's stored ssh_home_dir (set at provisioning time)
        if (!empty($repo['agent_id'])) {
            $agent = $db->fetchOne("SELECT ssh_home_dir FROM agents WHERE id = ?", [$repo['agent_id']]);
            if ($agent && !empty($agent['ssh_home_dir'])) {
                return $agent['ssh_home_dir'] . '/' . $repoDir;
            }
        }

        // Final fallback: derive from global storage_path (pre-migration compatibility)
        $setting = $db->fetchOne("SELECT `value` FROM settings WHERE `key` = 'storage_path'");
        if ($setting) {
            return rtrim($setting['value'], '/') . '/' . $repo['agent_id'] . '/' . $repoDir;
        }

        return $repo['path'];
    }

    /**
     * Append --remote-path to a command array if borg_remote_path is set.
     * Inserts after the subcommand (index 1) for proper borg argument order.
     */
    public static function appendRemotePath(array $cmd, ?string $borgRemotePath): array
    {
        if ($borgRemotePath) {
            // Insert --remote-path after 'borg <subcommand>'
            array_splice($cmd, 2, 0, ['--remote-path=' . $borgRemotePath]);
        }
        return $cmd;
    }

    /**
     * Convert command array to a JSON task payload for the agent.
     */
    public static function toTaskPayload(string $taskType, array $cmd, array $env = [], array $extra = []): array
    {
        return array_merge([
            'task' => $taskType,
            'command' => $cmd,
            'env' => $env,
        ], $extra);
    }
}
