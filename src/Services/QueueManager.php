<?php

namespace BBS\Services;

use BBS\Core\Database;

class QueueManager
{
    private Database $db;
    private int $maxQueue;
    private ?int $sshPort = null;

    public function __construct()
    {
        $this->db = Database::getInstance();
        $setting = $this->db->fetchOne("SELECT `value` FROM settings WHERE `key` = 'max_queue'");
        $this->maxQueue = (int) ($setting['value'] ?? 4);
    }

    /**
     * Get the SSH port setting (for Docker multi-tenant deployments).
     */
    private function getSshPort(): int
    {
        if ($this->sshPort === null) {
            $setting = $this->db->fetchOne("SELECT `value` FROM settings WHERE `key` = 'ssh_port'");
            $this->sshPort = (int) ($setting['value'] ?? 22);
        }
        return $this->sshPort;
    }

    /**
     * Get the SSH port for a specific agent, checking per-agent override first.
     */
    private function getSshPortForAgent(int $agentId): int
    {
        $agent = $this->db->fetchOne("SELECT ssh_port_override FROM agents WHERE id = ?", [$agentId]);
        if ($agent && !empty($agent['ssh_port_override'])) {
            return (int) $agent['ssh_port_override'];
        }
        return $this->getSshPort();
    }

    /**
     * Process the queue: assign queued jobs to agents (up to max_queue limit).
     * Prune/compact jobs are marked for server-side execution (not sent to agents).
     * Returns the jobs that were promoted to 'sent' status.
     */
    public function processQueue(): array
    {
        // Check maintenance mode — still process server-side jobs (catalog, prune, etc.)
        $maintenance = $this->db->fetchOne("SELECT `value` FROM settings WHERE `key` = 'maintenance_mode'");
        $maintenanceMode = (($maintenance['value'] ?? '0') === '1');

        // Count currently active backup/restore jobs (sent + running) for ONLINE agents.
        // Excludes: server-side tasks (run by scheduler), management tasks (update_borg,
        // update_agent — don't consume backup slots), and jobs for offline agents (stuck
        // jobs shouldn't block the entire queue).
        $activeCount = $this->db->count('backup_jobs',
            "status IN ('sent', 'running')
             AND task_type NOT IN ('prune', 'compact', 's3_sync', 's3_restore', 'repo_check', 'repo_repair', 'break_lock', 'catalog_sync', 'catalog_rebuild', 'catalog_rebuild_full', 'archive_delete', 'update_borg', 'update_agent')
             AND agent_id IN (SELECT id FROM agents WHERE status = 'online')"
        );

        $slotsAvailable = $this->maxQueue - $activeCount;
        if ($slotsAvailable <= 0) {
            return [];
        }

        // Get repos that already have an active job (borg can't run concurrent ops on same repo)
        $busyRepos = $this->db->fetchAll(
            "SELECT DISTINCT repository_id FROM backup_jobs
             WHERE status IN ('sent', 'running') AND repository_id IS NOT NULL"
        );
        $busyRepoIds = array_column($busyRepos, 'repository_id');

        // Get backup plans that already have a sent/running job (skip duplicates of the same plan)
        // Note: only check sent/running, not queued — we iterate queued jobs below and
        // track newly promoted plans to catch duplicates within the same batch
        $busyPlans = $this->db->fetchAll(
            "SELECT DISTINCT backup_plan_id FROM backup_jobs
             WHERE status IN ('sent', 'running')
               AND backup_plan_id IS NOT NULL
               AND task_type = 'backup'"
        );
        $busyPlanIds = array_column($busyPlans, 'backup_plan_id');

        // Get queued jobs ordered by queued_at (FIFO)
        // No LIMIT — we may skip busy-repo jobs and need to see more candidates
        $queuedJobs = $this->db->fetchAll("
            SELECT bj.*, bj.plugin_config_id, bp.directories, bp.excludes, bp.advanced_options,
                   bp.prune_minutes, bp.prune_hours, bp.prune_days,
                   bp.prune_weeks, bp.prune_months, bp.prune_years,
                   r.path as repo_path, r.encryption, r.passphrase_encrypted, r.name as repo_name,
                   r.agent_id as repo_agent_id, r.storage_type, r.remote_ssh_config_id,
                   rsc.remote_host, rsc.remote_port, rsc.remote_user, rsc.remote_base_path,
                   rsc.ssh_private_key_encrypted as remote_ssh_key_encrypted,
                   rsc.borg_remote_path
            FROM backup_jobs bj
            LEFT JOIN backup_plans bp ON bp.id = bj.backup_plan_id
            LEFT JOIN repositories r ON r.id = bj.repository_id
            LEFT JOIN remote_ssh_configs rsc ON rsc.id = r.remote_ssh_config_id
            WHERE bj.status = 'queued'
            ORDER BY
                CASE WHEN bj.task_type IN ('catalog_rebuild', 'catalog_rebuild_full') THEN 1 ELSE 0 END,
                bj.queued_at ASC
        ");

        $promoted = [];
        $promotedCount = 0;

        $serverSideTypes = ['prune', 'compact', 's3_sync', 's3_restore', 'repo_check', 'repo_repair', 'break_lock', 'catalog_sync', 'catalog_rebuild', 'catalog_rebuild_full', 'archive_delete'];
        $managementTypes = ['update_borg', 'update_agent'];

        // Get agents that currently have a backup/restore running (for management task gating)
        $busyAgents = $this->db->fetchAll(
            "SELECT DISTINCT agent_id FROM backup_jobs
             WHERE status IN ('sent', 'running')
               AND task_type IN ('backup', 'restore', 'restore_mysql', 'restore_pg', 'restore_mongo')"
        );
        $busyAgentIds = array_column($busyAgents, 'agent_id');

        foreach ($queuedJobs as $job) {
            $isManagement = in_array($job['task_type'], $managementTypes);

            // Management tasks (update_borg, update_agent) bypass queue slots
            // but wait if the agent has an active backup
            if ($isManagement) {
                if (in_array($job['agent_id'], $busyAgentIds)) {
                    continue; // Wait for backup to finish
                }
                // Otherwise fall through to promote immediately
            } elseif ($promotedCount >= $slotsAvailable) {
                break;
            }

            // In maintenance mode, only promote server-side jobs (not backups/restores)
            if ($maintenanceMode && !in_array($job['task_type'], $serverSideTypes) && !$isManagement) {
                continue;
            }

            // Skip duplicate backup for the same plan (no point running the same backup twice)
            if ($job['task_type'] === 'backup' && $job['backup_plan_id']
                && in_array($job['backup_plan_id'], $busyPlanIds)) {
                $this->db->update('backup_jobs', [
                    'status' => 'failed',
                    'completed_at' => date('Y-m-d H:i:s'),
                    'error_log' => 'Skipped — a backup for this plan is already queued or running',
                ], 'id = ?', [$job['id']]);
                $this->db->insert('server_log', [
                    'agent_id' => $job['agent_id'],
                    'backup_job_id' => $job['id'],
                    'level' => 'info',
                    'message' => "Backup job #{$job['id']} skipped — plan already has an active backup",
                ]);
                continue;
            }

            // Hold if repo already has an active job (borg can't run concurrent ops)
            // Different plans wait their turn; same-plan duplicates were already skipped above
            if ($job['repository_id'] && in_array($job['repository_id'], $busyRepoIds)) {
                continue;
            }
            // Build the task payload
            $repo = [
                'path' => $job['repo_path'],
                'encryption' => $job['encryption'],
                'passphrase_encrypted' => $job['passphrase_encrypted'],
                'agent_id' => $job['repo_agent_id'] ?? $job['agent_id'],
                'name' => $job['repo_name'],
            ];

            $plan = [
                'directories' => $job['directories'] ?? '',
                'excludes' => $job['excludes'] ?? '',
                'advanced_options' => $job['advanced_options'] ?? '',
                'prune_minutes' => $job['prune_minutes'] ?? 0,
                'prune_hours' => $job['prune_hours'] ?? 0,
                'prune_days' => $job['prune_days'] ?? 7,
                'prune_weeks' => $job['prune_weeks'] ?? 4,
                'prune_months' => $job['prune_months'] ?? 6,
                'prune_years' => $job['prune_years'] ?? 0,
            ];

            $taskPayload = null;

            if ($job['task_type'] === 'backup') {
                $prefix = $job['backup_plan_id'] ? 'plan' . $job['backup_plan_id'] : ($job['repo_name'] ?? 'backup');
                $archiveName = BorgCommandBuilder::generateArchiveName($prefix);

                // Build plugin payload and auto-include plugin directories (e.g. mysql dump_dir)
                $pluginManager = new PluginManager();
                $plugins = $pluginManager->buildPluginPayload($job['backup_plan_id'], $job['agent_id']);
                foreach ($plugins as $p) {
                    if (!empty($p['config']['dump_dir'])) {
                        $dumpDir = rtrim($p['config']['dump_dir'], '/');
                        if (strpos($plan['directories'], $dumpDir) === false) {
                            $plan['directories'] .= "\n" . $dumpDir;
                        }
                    }
                }

                $cmd = BorgCommandBuilder::buildCreateCommand($plan, $repo, $archiveName);

                // For remote SSH repos, add --remote-path and include SSH key in payload
                $remoteSshConfig = null;
                if (($job['storage_type'] ?? 'local') === 'remote_ssh' && !empty($job['remote_ssh_key_encrypted'])) {
                    $remoteSshConfig = [
                        'remote_port' => $job['remote_port'] ?? 22,
                        'borg_remote_path' => $job['borg_remote_path'] ?? null,
                    ];
                    $cmd = BorgCommandBuilder::appendRemotePath($cmd, $job['borg_remote_path'] ?? null);
                }

                $env = BorgCommandBuilder::buildEnv($repo, true, $this->getSshPortForAgent($job['agent_id']), $remoteSshConfig);

                $extra = [
                    'job_id' => $job['id'],
                    'archive_name' => $archiveName,
                    'directories' => $plan['directories'],
                ];
                if (!empty($plugins)) {
                    $extra['plugins'] = $plugins;
                }

                // Include decrypted SSH key for remote repos
                if ($remoteSshConfig && !empty($job['remote_ssh_key_encrypted'])) {
                    try {
                        $extra['remote_ssh_key'] = Encryption::decrypt($job['remote_ssh_key_encrypted']);
                    } catch (\Exception $e) {
                        $extra['remote_ssh_key'] = $job['remote_ssh_key_encrypted'];
                    }
                }

                $taskPayload = BorgCommandBuilder::toTaskPayload('backup', $cmd, $env, $extra);

                // Log the borg command being sent to the agent
                $cmdStr = implode(' ', array_map('escapeshellarg', $cmd));
                $this->db->insert('server_log', [
                    'agent_id' => $job['agent_id'],
                    'backup_job_id' => $job['id'],
                    'level' => 'info',
                    'message' => "Backup command: {$cmdStr}",
                ]);

                // Log plugin info for debugging
                if (!empty($plugins)) {
                    $pluginSlugs = array_map(fn($p) => $p['slug'] ?? '?', $plugins);
                    $this->db->insert('server_log', [
                        'agent_id' => $job['agent_id'],
                        'backup_job_id' => $job['id'],
                        'level' => 'info',
                        'message' => "Plugins: " . implode(', ', $pluginSlugs),
                    ]);
                }
            } elseif (in_array($job['task_type'], ['prune', 'compact', 's3_sync', 's3_restore', 'repo_check', 'repo_repair', 'break_lock', 'catalog_sync', 'catalog_rebuild', 'catalog_rebuild_full', 'archive_delete'])) {
                // Server-side jobs — mark as sent, scheduler will execute them
                $taskPayload = ['task' => $job['task_type'], 'server_side' => true, 'job_id' => $job['id']];
            } elseif ($job['task_type'] === 'restore') {
                $taskPayload = $this->buildRestorePayload($job);
            } elseif ($job['task_type'] === 'restore_mysql') {
                $taskPayload = $this->buildRestoreMysqlPayload($job);
            } elseif ($job['task_type'] === 'restore_pg') {
                $taskPayload = $this->buildRestorePgPayload($job);
            } elseif ($job['task_type'] === 'restore_mongo') {
                $taskPayload = $this->buildRestoreMongoPayload($job);
            } elseif ($job['task_type'] === 'update_borg') {
                $taskPayload = $this->buildBorgUpdatePayload($job);
            } elseif ($job['task_type'] === 'update_agent') {
                $taskPayload = ['task' => 'update_agent', 'job_id' => $job['id']];
            } elseif ($job['task_type'] === 'plugin_test') {
                $pluginManager = new PluginManager();
                $testPayload = $pluginManager->buildTestPayload((int) $job['plugin_config_id']);
                $taskPayload = [
                    'task' => 'plugin_test',
                    'job_id' => $job['id'],
                    'plugin' => $testPayload,
                ];
            }

            if ($taskPayload) {
                // Store the payload so the agent API can serve it
                $this->db->update('backup_jobs', [
                    'status' => 'sent',
                ], 'id = ?', [$job['id']]);

                $destination = ($taskPayload['server_side'] ?? false) ? 'server' : 'agent';
                $this->db->insert('server_log', [
                    'agent_id' => $job['agent_id'],
                    'backup_job_id' => $job['id'],
                    'level' => 'info',
                    'message' => "Job #{$job['id']} ({$job['task_type']}) sent to {$destination} queue",
                ]);

                $promoted[] = $job;

                // Management tasks (update_borg, update_agent) bypass the
                // slot count on the way in — they must also bypass it on
                // the way out. Otherwise a batch of queued agent updates
                // (especially for offline agents that can never pick them
                // up) eats every slot in this iteration and a backup later
                // in FIFO order hits the `break` below. #206
                if (!$isManagement) {
                    $promotedCount++;
                }

                // Mark this repo and plan as busy for remaining iterations
                if ($job['repository_id']) {
                    $busyRepoIds[] = $job['repository_id'];
                }
                if ($job['backup_plan_id'] && $job['task_type'] === 'backup') {
                    $busyPlanIds[] = $job['backup_plan_id'];
                }
            }
        }

        return $promoted;
    }

    /**
     * Get pending tasks for a specific agent.
     * Called by the Agent API when the agent polls for work.
     * Excludes prune/compact tasks (those run server-side).
     */
    public function getTasksForAgent(int $agentId): array
    {
        $jobs = $this->db->fetchAll("
            SELECT bj.*, bj.plugin_config_id, bp.directories, bp.excludes, bp.advanced_options,
                   bp.prune_minutes, bp.prune_hours, bp.prune_days,
                   bp.prune_weeks, bp.prune_months, bp.prune_years,
                   r.path as repo_path, r.encryption, r.passphrase_encrypted, r.name as repo_name,
                   r.storage_type, r.remote_ssh_config_id,
                   rsc.remote_port, rsc.ssh_private_key_encrypted as remote_ssh_key_encrypted,
                   rsc.borg_remote_path
            FROM backup_jobs bj
            LEFT JOIN backup_plans bp ON bp.id = bj.backup_plan_id
            LEFT JOIN repositories r ON r.id = bj.repository_id
            LEFT JOIN remote_ssh_configs rsc ON rsc.id = r.remote_ssh_config_id
            WHERE bj.agent_id = ?
              AND bj.status = 'sent'
              AND bj.task_type NOT IN ('prune', 'compact', 's3_sync', 's3_restore', 'catalog_sync', 'catalog_rebuild', 'catalog_rebuild_full')
            ORDER BY bj.queued_at ASC
        ", [$agentId]);

        $tasks = [];
        foreach ($jobs as $job) {
            $repo = [
                'path' => $job['repo_path'],
                'encryption' => $job['encryption'],
                'passphrase_encrypted' => $job['passphrase_encrypted'],
            ];

            $plan = [
                'directories' => $job['directories'] ?? '',
                'excludes' => $job['excludes'] ?? '',
                'advanced_options' => $job['advanced_options'] ?? '',
                'prune_minutes' => $job['prune_minutes'] ?? 0,
                'prune_hours' => $job['prune_hours'] ?? 0,
                'prune_days' => $job['prune_days'] ?? 7,
                'prune_weeks' => $job['prune_weeks'] ?? 4,
                'prune_months' => $job['prune_months'] ?? 6,
                'prune_years' => $job['prune_years'] ?? 0,
            ];

            if ($job['task_type'] === 'backup') {
                $prefix = $job['backup_plan_id'] ? 'plan' . $job['backup_plan_id'] : ($job['repo_name'] ?? 'backup');
                $archiveName = BorgCommandBuilder::generateArchiveName($prefix);

                // Build plugin payload and auto-include plugin directories (e.g. mysql dump_dir)
                $pluginManager = new PluginManager();
                $plugins = $pluginManager->buildPluginPayload($job['backup_plan_id'], $job['agent_id']);
                foreach ($plugins as $p) {
                    if (!empty($p['config']['dump_dir'])) {
                        $dumpDir = rtrim($p['config']['dump_dir'], '/');
                        if (strpos($plan['directories'], $dumpDir) === false) {
                            $plan['directories'] .= "\n" . $dumpDir;
                        }
                    }
                }

                $cmd = BorgCommandBuilder::buildCreateCommand($plan, $repo, $archiveName);

                // For remote SSH repos, add --remote-path and include SSH key
                $remoteSshConfig = null;
                if (($job['storage_type'] ?? 'local') === 'remote_ssh' && !empty($job['remote_ssh_key_encrypted'])) {
                    $remoteSshConfig = [
                        'remote_port' => $job['remote_port'] ?? 22,
                        'borg_remote_path' => $job['borg_remote_path'] ?? null,
                    ];
                    $cmd = BorgCommandBuilder::appendRemotePath($cmd, $job['borg_remote_path'] ?? null);
                }

                $env = BorgCommandBuilder::buildEnv($repo, true, $this->getSshPortForAgent($job['agent_id']), $remoteSshConfig);
                $extra = [
                    'job_id' => $job['id'],
                    'archive_name' => $archiveName,
                    'directories' => $plan['directories'],
                ];
                if (!empty($plugins)) {
                    $extra['plugins'] = $plugins;
                }
                if ($remoteSshConfig && !empty($job['remote_ssh_key_encrypted'])) {
                    try {
                        $extra['remote_ssh_key'] = Encryption::decrypt($job['remote_ssh_key_encrypted']);
                    } catch (\Exception $e) {
                        $extra['remote_ssh_key'] = $job['remote_ssh_key_encrypted'];
                    }
                }
                $tasks[] = BorgCommandBuilder::toTaskPayload('backup', $cmd, $env, $extra);
            } elseif ($job['task_type'] === 'restore') {
                $payload = $this->buildRestorePayload($job);
                if ($payload) {
                    $tasks[] = $payload;
                }
            } elseif ($job['task_type'] === 'restore_mysql') {
                $payload = $this->buildRestoreMysqlPayload($job);
                if ($payload) {
                    $tasks[] = $payload;
                }
            } elseif ($job['task_type'] === 'restore_pg') {
                $payload = $this->buildRestorePgPayload($job);
                if ($payload) {
                    $tasks[] = $payload;
                }
            } elseif ($job['task_type'] === 'restore_mongo') {
                $payload = $this->buildRestoreMongoPayload($job);
                if ($payload) {
                    $tasks[] = $payload;
                }
            } elseif ($job['task_type'] === 'update_borg') {
                $tasks[] = $this->buildBorgUpdatePayload($job);
            } elseif ($job['task_type'] === 'update_agent') {
                $tasks[] = ['task' => 'update_agent', 'job_id' => $job['id']];
            } elseif ($job['task_type'] === 'plugin_test') {
                $pluginManager = new PluginManager();
                $testPayload = $pluginManager->buildTestPayload((int) $job['plugin_config_id']);
                $tasks[] = [
                    'task' => 'plugin_test',
                    'job_id' => $job['id'],
                    'plugin' => $testPayload,
                ];
            }
        }

        return $tasks;
    }

    /**
     * Get server-side jobs (prune/compact) that are in 'sent' status.
     * Called by the scheduler to execute locally on the server.
     */
    public function getServerSideJobs(): array
    {
        return $this->db->fetchAll("
            SELECT bj.*, bp.directories, bp.excludes, bp.advanced_options,
                   bp.prune_minutes, bp.prune_hours, bp.prune_days,
                   bp.prune_weeks, bp.prune_months, bp.prune_years,
                   r.path as repo_path, r.encryption, r.passphrase_encrypted,
                   r.name as repo_name, r.agent_id as repo_agent_id,
                   r.storage_type, r.storage_location_id, r.remote_ssh_config_id,
                   a.ssh_unix_user,
                   rsc.id as rsc_id, rsc.name as rsc_name, rsc.remote_host, rsc.remote_port,
                   rsc.remote_user, rsc.remote_base_path,
                   rsc.ssh_private_key_encrypted as remote_ssh_key_encrypted,
                   rsc.borg_remote_path
            FROM backup_jobs bj
            LEFT JOIN backup_plans bp ON bp.id = bj.backup_plan_id
            LEFT JOIN repositories r ON r.id = bj.repository_id
            LEFT JOIN agents a ON a.id = bj.agent_id
            LEFT JOIN remote_ssh_configs rsc ON rsc.id = r.remote_ssh_config_id
            WHERE bj.status = 'sent'
              AND bj.task_type IN ('prune', 'compact', 's3_sync', 's3_restore', 'repo_check', 'repo_repair', 'break_lock', 'catalog_sync', 'catalog_rebuild', 'catalog_rebuild_full', 'archive_delete')
            ORDER BY bj.queued_at ASC
        ");
    }

    /**
     * Build a restore task payload from a job record.
     */
    private function buildRestorePayload(array $job): ?array
    {
        $archiveId = $job['restore_archive_id'] ?? null;
        if (!$archiveId) return null;

        $archive = $this->db->fetchOne("
            SELECT ar.archive_name, r.path as repo_path, r.passphrase_encrypted,
                   r.storage_type, r.remote_ssh_config_id,
                   rsc.remote_port, rsc.ssh_private_key_encrypted as remote_ssh_key_encrypted,
                   rsc.borg_remote_path
            FROM archives ar
            JOIN repositories r ON r.id = ar.repository_id
            LEFT JOIN remote_ssh_configs rsc ON rsc.id = r.remote_ssh_config_id
            WHERE ar.id = ?
        ", [$archiveId]);

        if (!$archive) return null;

        $paths = json_decode($job['restore_paths'] ?? '[]', true) ?: [];
        $destination = $job['restore_destination'] ?? null;

        $repo = ['path' => $archive['repo_path'], 'passphrase_encrypted' => $archive['passphrase_encrypted']];

        // Windows drive-letter restore (#167). Borg archives from Windows
        // clients store paths like "C/Users/marcp/file.txt" — the drive
        // letter is the first path segment. Our catalog indexer prepends a
        // leading slash for uniform display ("/C/Users/..."), so paths
        // arriving from the UI may or may not have that slash. Either way,
        // buildExtractCommand ltrims it before passing to borg, so the borg-
        // side path is always "C/Users/...". --strip-components=1 removes
        // that drive-letter segment and cwd=<drive>:\ routes the write back
        // to the original absolute location.
        $stripComponents = 0;
        if (!$destination && !empty($paths)) {
            $driveLetter = null;
            foreach ($paths as $p) {
                if (preg_match('#^/?([A-Za-z])/#', $p, $m)) {
                    $driveLetter = strtoupper($m[1]);
                    break;
                }
            }
            if ($driveLetter) {
                $stripComponents = 1;
                $destination = "{$driveLetter}:\\";
            }
        }

        $cmd = BorgCommandBuilder::buildExtractCommand($repo, $archive['archive_name'], $paths, $stripComponents);

        // Handle remote SSH repos
        $remoteSshConfig = null;
        if (($archive['storage_type'] ?? 'local') === 'remote_ssh' && !empty($archive['remote_ssh_key_encrypted'])) {
            $remoteSshConfig = [
                'remote_port' => $archive['remote_port'] ?? 22,
                'borg_remote_path' => $archive['borg_remote_path'] ?? null,
            ];
            $cmd = BorgCommandBuilder::appendRemotePath($cmd, $archive['borg_remote_path'] ?? null);
        }

        $env = BorgCommandBuilder::buildEnv($repo, true, $this->getSshPortForAgent($job['agent_id']), $remoteSshConfig);

        $extra = ['job_id' => $job['id']];
        if ($destination) {
            $extra['cwd'] = $destination;
        }
        if ($remoteSshConfig && !empty($archive['remote_ssh_key_encrypted'])) {
            try {
                $extra['remote_ssh_key'] = Encryption::decrypt($archive['remote_ssh_key_encrypted']);
            } catch (\Exception $e) {
                $extra['remote_ssh_key'] = $archive['remote_ssh_key_encrypted'];
            }
        }

        return BorgCommandBuilder::toTaskPayload('restore', $cmd, $env, $extra);
    }

    /**
     * Build a restore_mysql task payload from a job record.
     */
    private function buildRestoreMysqlPayload(array $job): ?array
    {
        $archiveId = $job['restore_archive_id'] ?? null;
        if (!$archiveId) return null;

        $archive = $this->db->fetchOne("
            SELECT ar.archive_name, ar.databases_backed_up,
                   r.path as repo_path, r.passphrase_encrypted,
                   r.storage_type, r.remote_ssh_config_id,
                   rsc.remote_port, rsc.ssh_private_key_encrypted as remote_ssh_key_encrypted,
                   rsc.borg_remote_path
            FROM archives ar
            JOIN repositories r ON r.id = ar.repository_id
            LEFT JOIN remote_ssh_configs rsc ON rsc.id = r.remote_ssh_config_id
            WHERE ar.id = ?
        ", [$archiveId]);

        if (!$archive) return null;

        $dbInfo = json_decode($archive['databases_backed_up'] ?? '{}', true) ?: [];
        $databases = json_decode($job['restore_databases'] ?? '[]', true) ?: [];
        $perDatabase = $dbInfo['per_database'] ?? true;
        $compress = $dbInfo['compress'] ?? true;

        // Find mysql_dump plugin config: use specified config ID, or fall back to first available
        $pluginManager = new PluginManager();
        $mysqlConfig = null;

        if (!empty($job['plugin_config_id'])) {
            $payload = $pluginManager->buildTestPayload((int) $job['plugin_config_id']);
            if ($payload && $payload['slug'] === 'mysql_dump') {
                $mysqlConfig = $payload['config'];
            }
        }

        if (!$mysqlConfig) {
            $configs = $pluginManager->getPluginConfigs($job['agent_id']);
            foreach ($configs as $c) {
                if ($c['slug'] === 'mysql_dump') {
                    $configData = json_decode($c['config'] ?? '{}', true) ?: [];
                    if (!empty($configData['password'])) {
                        $configData['password'] = Encryption::decrypt($configData['password']);
                    }
                    $mysqlConfig = $configData;
                    break;
                }
            }
        }

        if (!$mysqlConfig) return null;

        $dumpDir = $mysqlConfig['dump_dir'] ?? '/home/bbs/mysql';

        // Build borg extract command: extract only the specific dump file(s) needed
        $repo = ['path' => $archive['repo_path'], 'passphrase_encrypted' => $archive['passphrase_encrypted']];
        $extractPaths = [];
        $basePath = ltrim($dumpDir, '/');
        if ($perDatabase && !empty($databases)) {
            foreach ($databases as $db) {
                $dbName = $db['database'] ?? '';
                if (empty($dbName)) continue;
                $ext = $compress ? '.sql.gz' : '.sql';
                $extractPaths[] = $basePath . '/' . $dbName . $ext;
            }
        }
        // Fallback to full directory if non-per-database or no specific DBs
        if (empty($extractPaths)) {
            $extractPaths[] = $basePath;
        }
        $cmd = BorgCommandBuilder::buildExtractCommand($repo, $archive['archive_name'], $extractPaths);

        // Handle remote SSH repos
        $remoteSshConfig = null;
        if (($archive['storage_type'] ?? 'local') === 'remote_ssh' && !empty($archive['remote_ssh_key_encrypted'])) {
            $remoteSshConfig = [
                'remote_port' => $archive['remote_port'] ?? 22,
                'borg_remote_path' => $archive['borg_remote_path'] ?? null,
            ];
            $cmd = BorgCommandBuilder::appendRemotePath($cmd, $archive['borg_remote_path'] ?? null);
        }

        $env = BorgCommandBuilder::buildEnv($repo, true, $this->getSshPortForAgent($job['agent_id']), $remoteSshConfig);

        $payload = [
            'task' => 'restore_mysql',
            'job_id' => $job['id'],
            'command' => $cmd,
            'env' => $env,
            'cwd' => '/',
            'databases' => $databases,
            'mysql_config' => [
                'host' => $mysqlConfig['host'] ?? 'localhost',
                'port' => $mysqlConfig['port'] ?? 3306,
                'user' => $mysqlConfig['user'] ?? '',
                'password' => $mysqlConfig['password'] ?? '',
                'dump_dir' => $dumpDir,
                'compress' => $compress,
                'per_database' => $perDatabase,
            ],
        ];

        // Include remote SSH key for agent
        if ($remoteSshConfig && !empty($archive['remote_ssh_key_encrypted'])) {
            try {
                $payload['remote_ssh_key'] = Encryption::decrypt($archive['remote_ssh_key_encrypted']);
            } catch (\Exception $e) {
                $payload['remote_ssh_key'] = $archive['remote_ssh_key_encrypted'];
            }
        }

        return $payload;
    }

    /**
     * Build a restore_pg task payload from a job record.
     */
    private function buildRestorePgPayload(array $job): ?array
    {
        $archiveId = $job['restore_archive_id'] ?? null;
        if (!$archiveId) return null;

        $archive = $this->db->fetchOne("
            SELECT ar.archive_name, ar.databases_backed_up,
                   r.path as repo_path, r.passphrase_encrypted,
                   r.storage_type, r.remote_ssh_config_id,
                   rsc.remote_port, rsc.ssh_private_key_encrypted as remote_ssh_key_encrypted,
                   rsc.borg_remote_path
            FROM archives ar
            JOIN repositories r ON r.id = ar.repository_id
            LEFT JOIN remote_ssh_configs rsc ON rsc.id = r.remote_ssh_config_id
            WHERE ar.id = ?
        ", [$archiveId]);

        if (!$archive) return null;

        $dbInfo = json_decode($archive['databases_backed_up'] ?? '{}', true) ?: [];
        $databases = json_decode($job['restore_databases'] ?? '[]', true) ?: [];
        $compress = $dbInfo['compress'] ?? true;

        // Find pg_dump plugin config: use specified config ID, or fall back to first available
        $pluginManager = new PluginManager();
        $pgConfig = null;

        if (!empty($job['plugin_config_id'])) {
            $payload = $pluginManager->buildTestPayload((int) $job['plugin_config_id']);
            if ($payload && $payload['slug'] === 'pg_dump') {
                $pgConfig = $payload['config'];
            }
        }

        if (!$pgConfig) {
            $configs = $pluginManager->getPluginConfigs($job['agent_id']);
            foreach ($configs as $c) {
                if ($c['slug'] === 'pg_dump') {
                    $configData = json_decode($c['config'] ?? '{}', true) ?: [];
                    if (!empty($configData['password'])) {
                        $configData['password'] = Encryption::decrypt($configData['password']);
                    }
                    $pgConfig = $configData;
                    break;
                }
            }
        }

        if (!$pgConfig) return null;

        $dumpDir = $pgConfig['dump_dir'] ?? '/home/bbs/pgdump';

        // Build borg extract command targeting only the specific dump file(s) needed
        $repo = ['path' => $archive['repo_path'], 'passphrase_encrypted' => $archive['passphrase_encrypted']];
        $extractPaths = [];
        $basePath = ltrim($dumpDir, '/');
        $perDatabase = $dbInfo['per_database'] ?? true;
        if ($perDatabase && !empty($databases)) {
            foreach ($databases as $db) {
                $dbName = $db['database'] ?? '';
                if (empty($dbName)) continue;
                $ext = $compress ? '.sql.gz' : '.sql';
                $extractPaths[] = $basePath . '/' . $dbName . $ext;
            }
        }
        if (empty($extractPaths)) {
            $extractPaths[] = $basePath;
        }
        $cmd = BorgCommandBuilder::buildExtractCommand($repo, $archive['archive_name'], $extractPaths);

        // Handle remote SSH repos
        $remoteSshConfig = null;
        if (($archive['storage_type'] ?? 'local') === 'remote_ssh' && !empty($archive['remote_ssh_key_encrypted'])) {
            $remoteSshConfig = [
                'remote_port' => $archive['remote_port'] ?? 22,
                'borg_remote_path' => $archive['borg_remote_path'] ?? null,
            ];
            $cmd = BorgCommandBuilder::appendRemotePath($cmd, $archive['borg_remote_path'] ?? null);
        }

        $env = BorgCommandBuilder::buildEnv($repo, true, $this->getSshPortForAgent($job['agent_id']), $remoteSshConfig);

        $payload = [
            'task' => 'restore_pg',
            'job_id' => $job['id'],
            'command' => $cmd,
            'env' => $env,
            'cwd' => '/',
            'databases' => $databases,
            'pg_config' => [
                'host' => $pgConfig['host'] ?? 'localhost',
                'port' => $pgConfig['port'] ?? 5432,
                'user' => $pgConfig['user'] ?? '',
                'password' => $pgConfig['password'] ?? '',
                'dump_dir' => $dumpDir,
                'compress' => $compress,
            ],
        ];

        // Include remote SSH key for agent
        if ($remoteSshConfig && !empty($archive['remote_ssh_key_encrypted'])) {
            try {
                $payload['remote_ssh_key'] = Encryption::decrypt($archive['remote_ssh_key_encrypted']);
            } catch (\Exception $e) {
                $payload['remote_ssh_key'] = $archive['remote_ssh_key_encrypted'];
            }
        }

        return $payload;
    }

    /**
     * Build a restore_mongo task payload from a job record.
     */
    private function buildRestoreMongoPayload(array $job): ?array
    {
        $archiveId = $job['restore_archive_id'] ?? null;
        if (!$archiveId) return null;

        $archive = $this->db->fetchOne("
            SELECT ar.archive_name, ar.databases_backed_up,
                   r.path as repo_path, r.passphrase_encrypted,
                   r.storage_type, r.remote_ssh_config_id,
                   rsc.remote_port, rsc.ssh_private_key_encrypted as remote_ssh_key_encrypted,
                   rsc.borg_remote_path
            FROM archives ar
            JOIN repositories r ON r.id = ar.repository_id
            LEFT JOIN remote_ssh_configs rsc ON rsc.id = r.remote_ssh_config_id
            WHERE ar.id = ?
        ", [$archiveId]);

        if (!$archive) return null;

        $dbInfo = json_decode($archive['databases_backed_up'] ?? '{}', true) ?: [];
        $databases = json_decode($job['restore_databases'] ?? '[]', true) ?: [];
        $compress = $dbInfo['compress'] ?? true;

        // Find mongo_dump plugin config: use specified config ID, or fall back to first available
        $pluginManager = new PluginManager();
        $mongoConfig = null;

        if (!empty($job['plugin_config_id'])) {
            $payload = $pluginManager->buildTestPayload((int) $job['plugin_config_id']);
            if ($payload && $payload['slug'] === 'mongo_dump') {
                $mongoConfig = $payload['config'];
            }
        }

        if (!$mongoConfig) {
            $configs = $pluginManager->getPluginConfigs($job['agent_id']);
            foreach ($configs as $c) {
                if ($c['slug'] === 'mongo_dump') {
                    $configData = json_decode($c['config'] ?? '{}', true) ?: [];
                    if (!empty($configData['password'])) {
                        $configData['password'] = Encryption::decrypt($configData['password']);
                    }
                    $mongoConfig = $configData;
                    break;
                }
            }
        }

        if (!$mongoConfig) return null;

        $dumpDir = $mongoConfig['dump_dir'] ?? '/home/bbs/mongodump';

        // Build borg extract command targeting only the specific database directories needed
        // mongodump creates dump_dir/db_name/ directories
        $repo = ['path' => $archive['repo_path'], 'passphrase_encrypted' => $archive['passphrase_encrypted']];
        $extractPaths = [];
        $basePath = ltrim($dumpDir, '/');
        if (!empty($databases)) {
            foreach ($databases as $db) {
                $dbName = $db['database'] ?? '';
                if (empty($dbName)) continue;
                $extractPaths[] = $basePath . '/' . $dbName;
            }
        }
        if (empty($extractPaths)) {
            $extractPaths[] = $basePath;
        }
        $cmd = BorgCommandBuilder::buildExtractCommand($repo, $archive['archive_name'], $extractPaths);

        // Handle remote SSH repos
        $remoteSshConfig = null;
        if (($archive['storage_type'] ?? 'local') === 'remote_ssh' && !empty($archive['remote_ssh_key_encrypted'])) {
            $remoteSshConfig = [
                'remote_port' => $archive['remote_port'] ?? 22,
                'borg_remote_path' => $archive['borg_remote_path'] ?? null,
            ];
            $cmd = BorgCommandBuilder::appendRemotePath($cmd, $archive['borg_remote_path'] ?? null);
        }

        $env = BorgCommandBuilder::buildEnv($repo, true, $this->getSshPortForAgent($job['agent_id']), $remoteSshConfig);

        $payload = [
            'task' => 'restore_mongo',
            'job_id' => $job['id'],
            'command' => $cmd,
            'env' => $env,
            'cwd' => '/',
            'databases' => $databases,
            'mongo_config' => [
                'host' => $mongoConfig['host'] ?? '127.0.0.1',
                'port' => $mongoConfig['port'] ?? 27017,
                'user' => $mongoConfig['user'] ?? '',
                'password' => $mongoConfig['password'] ?? '',
                'auth_db' => $mongoConfig['auth_db'] ?? 'admin',
                'dump_dir' => $dumpDir,
                'compress' => $compress,
            ],
        ];

        // Include remote SSH key for agent
        if ($remoteSshConfig && !empty($archive['remote_ssh_key_encrypted'])) {
            try {
                $payload['remote_ssh_key'] = Encryption::decrypt($archive['remote_ssh_key_encrypted']);
            } catch (\Exception $e) {
                $payload['remote_ssh_key'] = $archive['remote_ssh_key_encrypted'];
            }
        }

        return $payload;
    }

    /**
     * Build an update_borg task payload with download URL and version info.
     * Uses the new two-mode system: 'official' or 'server'.
     */
    private function buildBorgUpdatePayload(array $job): array
    {
        $borgService = new BorgVersionService();

        // Get agent info for platform matching
        $agent = $this->db->fetchOne(
            "SELECT os_info, platform, architecture, glibc_version FROM agents WHERE id = ?",
            [$job['agent_id']]
        );

        // Normalize platform/arch if not stored
        if ($agent) {
            if (empty($agent['platform']) && !empty($agent['os_info'])) {
                $osInfo = $agent['os_info'];
                $agent['platform'] = 'linux';
                if (stripos($osInfo, 'Darwin') !== false) {
                    $agent['platform'] = 'macos';
                } elseif (stripos($osInfo, 'Windows') !== false) {
                    $agent['platform'] = 'windows';
                } elseif (stripos($osInfo, 'FreeBSD') !== false) {
                    $agent['platform'] = 'freebsd';
                }
            }
            if (empty($agent['architecture']) && !empty($agent['os_info'])) {
                $agent['architecture'] = 'x86_64';
                if (preg_match('/\b(aarch64|arm64)\b/i', $agent['os_info'])) {
                    $agent['architecture'] = 'arm64';
                }
            }
        }

        $payload = [
            'task' => 'update_borg',
            'job_id' => $job['id'],
            'target_version' => '',
            'download_url' => null,
            'install_method' => 'binary',
            'binary_path' => '/usr/local/bin/borg',
            'fallback_to_pip' => true, // Always allow pip as last resort
        ];

        if (!$agent) {
            $payload['install_method'] = 'pip';
            return $payload;
        }

        // Get best version: server binaries -> official GitHub -> pip
        $best = $borgService->getBestVersionForAgent($agent);

        if ($best['source'] === 'pip') {
            $payload['install_method'] = 'pip';
            $payload['source'] = 'official'; // pip installs official packages
        } else {
            $payload['target_version'] = $best['version'];
            $payload['download_url'] = $best['url'];
            $payload['source'] = $best['source']; // 'official' or 'server'
        }

        return $payload;
    }
}
