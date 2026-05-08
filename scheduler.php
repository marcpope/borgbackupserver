#!/usr/bin/env php
<?php
date_default_timezone_set('UTC');
/**
 * Scheduler CLI - Run via cron every minute:
 *   * * * * * php /path/to/borgbackupserver/scheduler.php
 */

require_once __DIR__ . '/vendor/autoload.php';

use BBS\Core\Config;
use BBS\Services\SchedulerService;
use BBS\Services\QueueManager;
use BBS\Services\NotificationService;
use BBS\Services\UpdateService;
use BBS\Services\RemoteSshService;

Config::load();

$db = \BBS\Core\Database::getInstance();

// Step 1: Mark agents offline if no heartbeat in 3x poll interval
$pollInterval = $db->fetchOne("SELECT `value` FROM settings WHERE `key` = 'agent_poll_interval'");
$threshold = ((int)($pollInterval['value'] ?? 30)) * 3;

$now = date('Y-m-d H:i:s');
$cutoff = date('Y-m-d H:i:s', time() - $threshold);

$stale = $db->query(
    "UPDATE agents SET status = 'offline'
     WHERE status = 'online'
       AND last_heartbeat IS NOT NULL
       AND last_heartbeat < ?",
    [$cutoff]
);

if ($stale->rowCount() > 0) {
    echo date('Y-m-d H:i:s') . " Marked {$stale->rowCount()} agent(s) offline (no heartbeat in {$threshold}s)\n";

    // Notify for each agent that just went offline
    $notificationService = new NotificationService();
    $offlineAgents = $db->fetchAll(
        "SELECT id, name FROM agents WHERE status = 'offline' AND last_heartbeat IS NOT NULL AND last_heartbeat < ?",
        [$cutoff]
    );
    foreach ($offlineAgents as $offAgent) {
        $notificationService->notify('agent_offline', $offAgent['id'], null, "Client \"{$offAgent['name']}\" is offline (no heartbeat in {$threshold}s)", 'warning');
    }
}

// Step 2: Fail jobs for agents that are offline (sent or running only)
// Queued jobs are left alone — the agent may come back online and pick them up.
// Excludes:
//   - Server-side tasks (prune, compact, catalog, etc.) — run by the scheduler, don't need the agent.
//   - Management tasks (update_borg, update_agent) — these should wait for the
//     agent to come back online and pick them up, not fail at 5am because the
//     client's laptop was asleep (#144). They get their own grace-period sweep
//     in Step 2c below.
$staleJobs = $db->fetchAll("
    SELECT bj.id, bj.agent_id, bj.task_type, bj.backup_plan_id, bj.status, a.name as agent_name
    FROM backup_jobs bj
    JOIN agents a ON a.id = bj.agent_id
    WHERE bj.status IN ('sent', 'running')
      AND a.status = 'offline'
      AND bj.task_type NOT IN ('prune', 'compact', 's3_sync', 's3_restore', 'repo_check', 'repo_repair', 'break_lock', 'catalog_sync', 'catalog_rebuild', 'catalog_rebuild_full', 'archive_delete', 'update_borg', 'update_agent')
");

foreach ($staleJobs as $sj) {
    $db->update('backup_jobs', [
        'status' => 'failed',
        'completed_at' => date('Y-m-d H:i:s'),
        'error_log' => "Agent went offline — no heartbeat in {$threshold}s",
    ], 'id = ?', [$sj['id']]);

    $db->insert('server_log', [
        'agent_id' => $sj['agent_id'],
        'backup_job_id' => $sj['id'],
        'level' => 'error',
        'message' => "Job #{$sj['id']} ({$sj['task_type']}) failed — agent \"{$sj['agent_name']}\" went offline",
    ]);

    // Fire backup_failed notification if it was a backup
    if ($sj['task_type'] === 'backup' && $sj['backup_plan_id']) {
        $notificationService = $notificationService ?? new NotificationService();
        $planRow = $db->fetchOne("SELECT name FROM backup_plans WHERE id = ?", [$sj['backup_plan_id']]);
        $planName = $planRow['name'] ?? '';
        $notificationService->notify(
            'backup_failed',
            $sj['agent_id'],
            (int)$sj['backup_plan_id'],
            "Backup failed for plan \"{$planName}\" on client \"{$sj['agent_name']}\" — agent went offline",
            'critical'
        );
    }

    echo date('Y-m-d H:i:s') . " Failed: job #{$sj['id']} ({$sj['task_type']}) — agent \"{$sj['agent_name']}\" offline\n";
}

// Step 2b: Auto-fail zombie jobs — running >24h on online agents with no recent progress
// Safety net for agents that don't support check_jobs or lost status reports that were never retried
// Excludes server-side tasks (prune, compact, catalog, etc.) — those are managed by the scheduler
$zombieJobs = $db->fetchAll("
    SELECT bj.id, bj.agent_id, bj.task_type, bj.backup_plan_id, a.name as agent_name
    FROM backup_jobs bj
    JOIN agents a ON a.id = bj.agent_id
    WHERE bj.status IN ('running', 'sent')
      AND a.status = 'online'
      AND bj.task_type NOT IN ('prune', 'compact', 's3_sync', 's3_restore', 'repo_check', 'repo_repair', 'break_lock', 'catalog_sync', 'catalog_rebuild', 'catalog_rebuild_full', 'archive_delete')
      AND bj.queued_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)
      AND (bj.last_progress_at IS NULL OR bj.last_progress_at < DATE_SUB(NOW(), INTERVAL 60 MINUTE))
");

foreach ($zombieJobs as $zj) {
    $db->update('backup_jobs', [
        'status' => 'failed',
        'completed_at' => date('Y-m-d H:i:s'),
        'error_log' => 'Job timed out — running for over 24 hours with no recent progress',
    ], 'id = ?', [$zj['id']]);

    $db->insert('server_log', [
        'agent_id' => $zj['agent_id'],
        'backup_job_id' => $zj['id'],
        'level' => 'error',
        'message' => "Job #{$zj['id']} ({$zj['task_type']}) auto-failed — running >24h with no progress on online agent \"{$zj['agent_name']}\"",
    ]);

    if ($zj['task_type'] === 'backup' && $zj['backup_plan_id']) {
        $notificationService = $notificationService ?? new NotificationService();
        $planRow = $db->fetchOne("SELECT name FROM backup_plans WHERE id = ?", [$zj['backup_plan_id']]);
        $planName = $planRow['name'] ?? '';
        $notificationService->notify(
            'backup_failed',
            $zj['agent_id'],
            (int)$zj['backup_plan_id'],
            "Backup failed for plan \"{$planName}\" on client \"{$zj['agent_name']}\" — job timed out (>24h)",
            'critical'
        );
    }

    echo date('Y-m-d H:i:s') . " Auto-failed: job #{$zj['id']} ({$zj['task_type']}) — running >24h on online agent \"{$zj['agent_name']}\"\n";
}

// Step 2c: Fail stale management tasks (update_borg, update_agent) after 7 days
// unpicked. These are excluded from Step 2 so they don't fail the moment the
// client's laptop goes to sleep, but we still need a safety valve — if an agent
// has been gone for a week and still hasn't polled for its pending update, the
// job is effectively abandoned and should stop cluttering the queue.
$staleMgmtCutoffDays = 7;
$staleMgmt = $db->fetchAll("
    SELECT bj.id, bj.agent_id, bj.task_type, a.name as agent_name
    FROM backup_jobs bj
    JOIN agents a ON a.id = bj.agent_id
    WHERE bj.status IN ('queued', 'sent')
      AND bj.task_type IN ('update_borg', 'update_agent')
      AND bj.queued_at < DATE_SUB(NOW(), INTERVAL {$staleMgmtCutoffDays} DAY)
");
foreach ($staleMgmt as $sm) {
    $db->update('backup_jobs', [
        'status' => 'failed',
        'completed_at' => date('Y-m-d H:i:s'),
        'error_log' => "Agent did not pick up the update within {$staleMgmtCutoffDays} days",
    ], 'id = ?', [$sm['id']]);
    $db->insert('server_log', [
        'agent_id' => $sm['agent_id'],
        'backup_job_id' => $sm['id'],
        'level' => 'warning',
        'message' => "Job #{$sm['id']} ({$sm['task_type']}) expired — agent \"{$sm['agent_name']}\" did not poll for the update in {$staleMgmtCutoffDays} days",
    ]);
    echo date('Y-m-d H:i:s') . " Expired: job #{$sm['id']} ({$sm['task_type']}) — agent \"{$sm['agent_name']}\" offline >{$staleMgmtCutoffDays}d\n";
}

// Step 3: Check schedules and create queued jobs
$scheduler = new SchedulerService();
$created = $scheduler->run();

foreach ($created as $job) {
    echo date('Y-m-d H:i:s') . " Queued: {$job['plan']} (job #{$job['job_id']}, agent #{$job['agent_id']})\n";
}

// Step 3b: Auto-queue catalog rebuilds for repos with unindexed archives
try {
    $ch = \BBS\Core\ClickHouse::getInstance();
    if ($ch->isAvailable()) {
        // Get all archive IDs currently in ClickHouse
        $chArchives = $ch->fetchAll("SELECT DISTINCT archive_id FROM file_catalog");
        $indexedIds = array_flip(array_column($chArchives, 'archive_id'));

        // Find repos that have archives not yet in ClickHouse
        // Skip archives created in the last 30 minutes — the normal post-backup
        // catalog indexing handles those; triggering a rebuild too early causes loops
        $repos = $db->fetchAll(
            "SELECT r.id, r.agent_id, r.path, r.name, r.storage_type, r.storage_location_id, a.id AS archive_id
             FROM repositories r
             JOIN archives a ON a.repository_id = r.id
             WHERE a.created_at < DATE_SUB(NOW(), INTERVAL 30 MINUTE)"
        );
        $needsRebuild = [];
        foreach ($repos as $row) {
            if (!isset($indexedIds[$row['archive_id']])) {
                $needsRebuild[$row['id']] = $row;
            }
        }
        foreach ($needsRebuild as $repoId => $info) {
            $agentId = $info['agent_id'];

            // Skip repos whose data doesn't exist on disk (e.g. after restore to a new server)
            if ($info['storage_type'] === 'local' || empty($info['storage_type'])) {
                $checkPath = \BBS\Services\BorgCommandBuilder::getLocalRepoPath($info);
                if (!empty($checkPath) && !is_dir($checkPath)) {
                    continue;
                }
            }
            // Check for pending/running rebuild on this repo OR any repo for same agent
            // Concurrent rebuilds for same agent contend on borg repo locks
            // Also skip if a rebuild completed in the last 24 hours (prevents infinite loop
            // when some archives can never be indexed, e.g. corrupted or inaccessible)
            $pending = $db->fetchOne(
                "SELECT id FROM backup_jobs
                 WHERE (repository_id = ? OR agent_id = ?) AND task_type IN ('catalog_rebuild', 'catalog_rebuild_full')
                   AND (status IN ('queued','sent','running')
                        OR (status IN ('completed','failed') AND completed_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)))",
                [$repoId, $agentId]
            );
            if (!$pending) {
                $db->insert('backup_jobs', [
                    'agent_id' => $agentId,
                    'repository_id' => $repoId,
                    'task_type' => 'catalog_rebuild',
                    'status' => 'queued',
                ]);
                echo date('Y-m-d H:i:s') . " Auto-queued catalog_rebuild for repo #{$repoId} (missing archives in ClickHouse)\n";
            }
        }
    }
} catch (\Exception $e) {
    // ClickHouse not available yet — skip auto-rebuild
}

// Step 4: Process queue - promote queued jobs to sent
$queueManager = new QueueManager();
$promoted = $queueManager->processQueue();

foreach ($promoted as $job) {
    echo date('Y-m-d H:i:s') . " Sent: job #{$job['id']} ({$job['task_type']}) to agent #{$job['agent_id']}\n";
}

// Step 4b: Execute server-side jobs (prune/compact) locally
$serverJobs = $queueManager->getServerSideJobs();
foreach ($serverJobs as $sj) {
    $repo = [
        'path' => $sj['repo_path'],
        'encryption' => $sj['encryption'],
        'passphrase_encrypted' => $sj['passphrase_encrypted'],
        'agent_id' => $sj['repo_agent_id'] ?? $sj['agent_id'],
        'name' => $sj['repo_name'],
        'storage_type' => $sj['storage_type'] ?? 'local',
        'storage_location_id' => $sj['storage_location_id'] ?? null,
    ];

    $isRemoteSsh = ($repo['storage_type'] === 'remote_ssh');

    // Use local path for server-side execution (null for remote SSH repos)
    $localPath = \BBS\Services\BorgCommandBuilder::getLocalRepoPath($repo);
    $localRepo = $localPath ? array_merge($repo, ['path' => $localPath]) : $repo;

    $plan = [
        'prune_minutes' => $sj['prune_minutes'] ?? 0,
        'prune_hours' => $sj['prune_hours'] ?? 0,
        'prune_days' => $sj['prune_days'] ?? 7,
        'prune_weeks' => $sj['prune_weeks'] ?? 4,
        'prune_months' => $sj['prune_months'] ?? 6,
        'prune_years' => $sj['prune_years'] ?? 0,
    ];

    // Atomically claim the job. Cron runs this scheduler every minute, so
    // a long-running compact/prune can overlap with the next invocation:
    // both instances fetch the same 'sent' row via getServerSideJobs() before
    // either marks it 'running'. Without this guard both would execute the
    // same job (issue #163). The WHERE status='sent' clause makes the claim
    // atomic — if another scheduler already transitioned the row, rowCount()
    // is 0 and we skip this iteration.
    $startedAt = date('Y-m-d H:i:s');
    $claim = $db->query(
        "UPDATE backup_jobs SET status='running', started_at=? WHERE id=? AND status='sent'",
        [$startedAt, $sj['id']]
    );
    if ($claim->rowCount() === 0) {
        echo date('Y-m-d H:i:s') . " Skipped job #{$sj['id']} ({$sj['task_type']}) — already claimed by another scheduler run\n";
        continue;
    }

    echo date('Y-m-d H:i:s') . " Executing server-side: job #{$sj['id']} ({$sj['task_type']})\n";

    // S3 sync — uses rclone, not borg (skip for remote SSH repos — already offsite)
    if ($sj['task_type'] === 's3_sync') {
        if ($isRemoteSsh) {
            $db->update('backup_jobs', [
                'status' => 'completed',
                'completed_at' => date('Y-m-d H:i:s'),
                'duration_seconds' => 0,
            ], 'id = ?', [$sj['id']]);
            $db->insert('server_log', [
                'agent_id' => $sj['agent_id'],
                'backup_job_id' => $sj['id'],
                'level' => 'info',
                'message' => 'S3 sync skipped — remote SSH repos are already offsite',
            ]);
            echo date('Y-m-d H:i:s') . " S3 sync job #{$sj['id']} skipped (remote SSH repo)\n";
            continue;
        }
        $pluginManager = $pluginManager ?? new \BBS\Services\PluginManager();

        // Resolve plugin config — from job's plugin_config_id or plan plugins
        $config = [];
        if (!empty($sj['plugin_config_id'])) {
            $namedConfig = $pluginManager->getPluginConfig((int) $sj['plugin_config_id']);
            if ($namedConfig) {
                $config = json_decode($namedConfig['config'], true) ?: [];
            }
        }

        $s3Service = new \BBS\Services\S3SyncService();
        $creds = $s3Service->resolveCredentials($config);

        $s3Repo = $db->fetchOne("SELECT * FROM repositories WHERE id = ?", [$sj['repository_id']]);
        $s3Agent = $db->fetchOne("SELECT * FROM agents WHERE id = ?", [$sj['agent_id']]);

        if (!$s3Repo || !$s3Agent) {
            $s3Result = 'failed';
            $s3Error = 'Repository or agent not found';
        } else {
            $runAsUser = $sj['ssh_unix_user'] ?? null;
            $syncResult = $s3Service->syncRepository($s3Repo, $s3Agent, $creds, $runAsUser);
            $s3Result = $syncResult['success'] ? 'completed' : 'failed';
            $s3Output = $syncResult['output'] ?? '';
            $s3Error = $syncResult['success'] ? null : $s3Output;
        }

        $now = date('Y-m-d H:i:s');
        $db->update('backup_jobs', [
            'status' => $s3Result,
            'completed_at' => $now,
            'duration_seconds' => max(0, strtotime($now) - strtotime($startedAt)),
            'error_log' => $s3Error,
        ], 'id = ?', [$sj['id']]);

        $logMessage = $s3Result === 'completed'
            ? 'S3 sync completed' . (!empty($s3Output) ? ": {$s3Output}" : '')
            : 'S3 sync failed: ' . $s3Error;
        $db->insert('server_log', [
            'agent_id' => $sj['agent_id'],
            'backup_job_id' => $sj['id'],
            'level' => $s3Result === 'completed' ? 'info' : 'error',
            'message' => $logMessage,
        ]);

        // Update last_sync_at in repository_s3_configs after successful sync
        if ($s3Result === 'completed' && !empty($sj['repository_id'])) {
            $db->update('repository_s3_configs', [
                'last_sync_at' => $now,
            ], 'repository_id = ?', [$sj['repository_id']]);
        }

        // Send notifications for S3 sync results
        $notificationService = new \BBS\Services\NotificationService();
        if ($s3Result === 'failed') {
            $repoName = $s3Repo['name'] ?? 'unknown';
            $agentName = $s3Agent['name'] ?? 'unknown';
            $notificationService->notify(
                's3_sync_failed',
                $sj['agent_id'],
                $sj['repository_id'] ? (int)$sj['repository_id'] : null,
                "S3 sync failed for repository \"{$repoName}\" on client \"{$agentName}\" — " . ($s3Error ?? 'unknown error'),
                'critical'
            );
        } elseif ($s3Result === 'completed') {
            $repoName = $s3Repo['name'] ?? 'unknown';
            $agentName = $s3Agent['name'] ?? 'unknown';
            $notificationService->notify(
                's3_sync_done',
                $sj['agent_id'],
                $sj['repository_id'] ? (int)$sj['repository_id'] : null,
                "S3 sync completed for repository \"{$repoName}\" on client \"{$agentName}\"" . (!empty($s3Output) ? " — {$s3Output}" : ''),
                'info'
            );
        }

        // Generate and upload manifest after successful sync (streams to file for large catalogs)
        if ($s3Result === 'completed' && $s3Repo && $s3Agent) {
            $passphrase = '';
            if (!empty($s3Repo['passphrase_encrypted'])) {
                try {
                    $passphrase = \BBS\Services\Encryption::decrypt($s3Repo['passphrase_encrypted']);
                } catch (\Exception $e) {
                    // May already be plaintext
                }
            }

            $manifestGenResult = $s3Service->generateManifestFile($s3Repo, $s3Agent, $passphrase);
            if ($manifestGenResult['success']) {
                $manifestUploadResult = $s3Service->uploadManifestFile($manifestGenResult['file'], $s3Repo, $s3Agent, $creds);

                if ($manifestUploadResult['success']) {
                    echo date('Y-m-d H:i:s') . "   Manifest uploaded ({$manifestGenResult['archives']} archives, {$manifestGenResult['files']} files)\n";
                    $db->insert('server_log', [
                        'agent_id' => $sj['agent_id'],
                        'backup_job_id' => $sj['id'],
                        'level' => 'info',
                        'message' => "Manifest uploaded: {$manifestGenResult['archives']} archives, {$manifestGenResult['files']} files cataloged",
                    ]);
                } else {
                    echo date('Y-m-d H:i:s') . "   Warning: manifest upload failed: {$manifestUploadResult['output']}\n";
                    $db->insert('server_log', [
                        'agent_id' => $sj['agent_id'],
                        'backup_job_id' => $sj['id'],
                        'level' => 'warning',
                        'message' => 'Manifest upload failed: ' . $manifestUploadResult['output'],
                    ]);
                }
            } else {
                echo date('Y-m-d H:i:s') . "   Warning: manifest generation failed\n";
                $db->insert('server_log', [
                    'agent_id' => $sj['agent_id'],
                    'backup_job_id' => $sj['id'],
                    'level' => 'warning',
                    'message' => 'Manifest generation failed (no file catalog to backup)',
                ]);
            }
        }

        echo date('Y-m-d H:i:s') . " S3 sync job #{$sj['id']} {$s3Result}\n";
        continue;
    }

    // S3 restore — uses rclone to download from S3
    if ($sj['task_type'] === 's3_restore') {
        $pluginManager = $pluginManager ?? new \BBS\Services\PluginManager();

        // Resolve plugin config — from job's plugin_config_id
        $config = [];
        if (!empty($sj['plugin_config_id'])) {
            $namedConfig = $pluginManager->getPluginConfig((int) $sj['plugin_config_id']);
            if ($namedConfig) {
                $config = json_decode($namedConfig['config'], true) ?: [];
            }
        }

        $s3Service = new \BBS\Services\S3SyncService();
        $creds = $s3Service->resolveCredentials($config);

        $s3Repo = $db->fetchOne("SELECT * FROM repositories WHERE id = ?", [$sj['repository_id']]);
        $s3Agent = $db->fetchOne("SELECT * FROM agents WHERE id = ?", [$sj['agent_id']]);

        // For "copy" mode, source_repository_id tells us where to pull S3 data from
        $sourceRepo = null;
        if (!empty($sj['source_repository_id'])) {
            $sourceRepo = $db->fetchOne("SELECT * FROM repositories WHERE id = ?", [$sj['source_repository_id']]);
        }

        if (!$s3Repo || !$s3Agent) {
            $s3Result = 'failed';
            $s3Error = 'Repository or agent not found';
        } else {
            $runAsUser = $sj['ssh_unix_user'] ?? null;
            $restoreResult = $s3Service->restoreRepository($s3Repo, $s3Agent, $creds, $runAsUser, $sourceRepo);
            $s3Result = $restoreResult['success'] ? 'completed' : 'failed';
            $s3Output = $restoreResult['output'] ?? '';
            $s3Error = $restoreResult['success'] ? null : $s3Output;
        }

        $now = date('Y-m-d H:i:s');
        $db->update('backup_jobs', [
            'status' => $s3Result,
            'completed_at' => $now,
            'duration_seconds' => max(0, strtotime($now) - strtotime($startedAt)),
            'error_log' => $s3Error,
        ], 'id = ?', [$sj['id']]);

        $logMessage = $s3Result === 'completed'
            ? 'S3 restore completed' . (!empty($s3Output) ? ": {$s3Output}" : '')
            : 'S3 restore failed: ' . $s3Error;
        $db->insert('server_log', [
            'agent_id' => $sj['agent_id'],
            'backup_job_id' => $sj['id'],
            'level' => $s3Result === 'completed' ? 'info' : 'error',
            'message' => $logMessage,
        ]);

        echo date('Y-m-d H:i:s') . " S3 restore job #{$sj['id']} {$s3Result}\n";

        // After successful S3 restore, clear borg cache to prevent "repository relocated" errors
        // This happens because S3 copies share the same internal borg repository UUID
        if ($s3Result === 'completed' && !empty($sj['ssh_unix_user'])) {
            $clearCmd = ['sudo', '/usr/local/bin/bbs-ssh-helper', 'clear-borg-cache', $sj['ssh_unix_user']];
            exec(implode(' ', array_map('escapeshellarg', $clearCmd)) . ' 2>&1', $clearOutput, $clearRet);
            if ($clearRet === 0) {
                echo date('Y-m-d H:i:s') . "   Cleared borg cache for {$sj['ssh_unix_user']}\n";
            }
        }

        // After successful S3 restore, try to import manifest first (fast path)
        // Falls back to catalog_sync if no manifest exists (slow path via borg commands)
        if ($s3Result === 'completed' && $sj['repository_id'] && $s3Repo && $s3Agent) {
            $manifestDownload = $s3Service->downloadManifestFile($s3Repo, $s3Agent, $creds, $sourceRepo);

            if ($manifestDownload['success'] && $manifestDownload['file']) {
                // Fast path: import from manifest
                echo date('Y-m-d H:i:s') . "   Found manifest, importing catalog...\n";
                $importResult = $s3Service->importManifestFile($manifestDownload['file'], $sj['repository_id']);

                if ($importResult['success']) {
                    echo date('Y-m-d H:i:s') . "   Manifest imported ({$importResult['archives']} archives, {$importResult['files']} files)\n";
                    $db->insert('server_log', [
                        'agent_id' => $sj['agent_id'],
                        'level' => 'info',
                        'message' => "Catalog imported from manifest: {$importResult['archives']} archives, {$importResult['files']} files",
                    ]);
                } else {
                    // Manifest import failed, fall back to catalog_sync
                    echo date('Y-m-d H:i:s') . "   Manifest import failed: {$importResult['error']}, falling back to catalog_sync\n";
                    $db->insert('backup_jobs', [
                        'agent_id' => $sj['agent_id'],
                        'repository_id' => $sj['repository_id'],
                        'task_type' => 'catalog_sync',
                        'status' => 'queued',
                    ]);
                    $db->insert('server_log', [
                        'agent_id' => $sj['agent_id'],
                        'level' => 'warning',
                        'message' => "Manifest import failed ({$importResult['error']}), catalog_sync queued",
                    ]);
                }
            } else {
                // No manifest found (legacy S3 backup or external repo), queue catalog_sync
                echo date('Y-m-d H:i:s') . "   No manifest found, queuing catalog_sync (slow path)...\n";
                $catalogSyncJob = [
                    'agent_id' => $sj['agent_id'],
                    'repository_id' => $sj['repository_id'],
                    'task_type' => 'catalog_sync',
                    'status' => 'queued',
                ];
                $db->insert('backup_jobs', $catalogSyncJob);
                $db->insert('server_log', [
                    'agent_id' => $sj['agent_id'],
                    'level' => 'info',
                    'message' => "No manifest in S3, catalog_sync queued for repository after S3 restore",
                ]);
                echo date('Y-m-d H:i:s') . " Queued catalog_sync for repo #{$sj['repository_id']} after S3 restore\n";
            }
        }
        continue;
    }

    // Catalog sync — runs borg list to rebuild archives table
    if ($sj['task_type'] === 'catalog_sync') {
        $csRepo = $db->fetchOne("SELECT * FROM repositories WHERE id = ?", [$sj['repository_id']]);
        if (!$csRepo) {
            $db->update('backup_jobs', [
                'status' => 'failed',
                'completed_at' => date('Y-m-d H:i:s'),
                'error_log' => 'Repository not found',
            ], 'id = ?', [$sj['id']]);
            echo date('Y-m-d H:i:s') . " Catalog sync job #{$sj['id']} failed: repository not found\n";
            continue;
        }

        $passphrase = '';
        if (!empty($csRepo['passphrase_encrypted'])) {
            try {
                $passphrase = \BBS\Services\Encryption::decrypt($csRepo['passphrase_encrypted']);
            } catch (\Exception $e) {
                // May already be plaintext or missing
            }
        }

        // Remote SSH repos: use RemoteSshService, Local repos: use bbs-ssh-helper or direct borg
        if ($isRemoteSsh && !empty($sj['remote_ssh_config_id'])) {
            $remoteSshService = $remoteSshService ?? new RemoteSshService();
            $remoteConfig = $remoteSshService->getById((int) $sj['remote_ssh_config_id']);
            if (!$remoteConfig) {
                $db->update('backup_jobs', [
                    'status' => 'failed',
                    'completed_at' => date('Y-m-d H:i:s'),
                    'error_log' => 'Remote SSH config not found',
                ], 'id = ?', [$sj['id']]);
                echo date('Y-m-d H:i:s') . " Catalog sync job #{$sj['id']} failed: remote SSH config not found\n";
                continue;
            }

            $csResult = $remoteSshService->runBorgCommand($remoteConfig, $csRepo['path'], ['list', '--json', $csRepo['path']], $passphrase);
            $csOutput = $csResult['output'] ?? '';
            $csError = $csResult['stderr'] ?? '';
            $csExitCode = $csResult['exit_code'] ?? -1;
        } else {
            $csLocalPath = \BBS\Services\BorgCommandBuilder::getLocalRepoPath($csRepo);

            // Run borg list via bbs-ssh-helper (handles sudo to the repo-owning user).
            // Passphrase is piped on stdin ("-" marker) so it's not visible in `ps`.
            $runAsUser = $sj['ssh_unix_user'] ?? null;
            if ($runAsUser) {
                $csCmd = [
                    'sudo', '/usr/local/bin/bbs-ssh-helper', 'borg-list',
                    $runAsUser, '-', $csLocalPath
                ];
                $csEnv = [];
            } else {
                // No unix user — run directly as www-data (legacy mode)
                $csCmd = ['borg', 'list', '--json', $csLocalPath];
                $csEnv = [];
                if ($passphrase) {
                    $csEnv['BORG_PASSPHRASE'] = $passphrase;
                }
                $csEnv['BORG_UNKNOWN_UNENCRYPTED_REPO_ACCESS_IS_OK'] = 'yes';
                $csEnv['BORG_RELOCATED_REPO_ACCESS_IS_OK'] = 'yes';
                $csEnv['BORG_BASE_DIR'] = '/tmp/bbs-borg-www-data';
                $csEnv['HOME'] = '/tmp/bbs-borg-www-data';
            }

            // When running via helper (runAsUser is set), env is handled by the helper
            $csEnvStrings = $runAsUser ? null : array_filter($_SERVER, 'is_string') + $csEnv;

            $csProc = proc_open($csCmd, [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ], $csPipes, null, $csEnvStrings);

            $csOutput = '';
            $csError = '';
            $csExitCode = -1;
            if (is_resource($csProc)) {
                if ($runAsUser) {
                    fwrite($csPipes[0], $passphrase . "\n");
                }
                fclose($csPipes[0]);
                $csOutput = stream_get_contents($csPipes[1]);
                $csError = stream_get_contents($csPipes[2]);
                fclose($csPipes[1]);
                fclose($csPipes[2]);
                $csExitCode = proc_close($csProc);
            }
        }

        // Use remote repo path for archive info commands
        $csArchivePath = $isRemoteSsh ? $csRepo['path'] : ($csLocalPath ?? $csRepo['path']);

        $csNow = date('Y-m-d H:i:s');
        if ($csExitCode <= 1) {
            $csData = json_decode($csOutput, true);

            // Safety check: if JSON parse failed, fail the job rather than deleting all archive records
            if ($csData === null || !isset($csData['archives'])) {
                $stderrHint = !empty($csError) ? trim($csError) : '';
                $stdoutHint = trim(substr($csOutput, 0, 500));
                $errorMsg = "borg list output was not valid JSON";
                if ($stderrHint) {
                    $errorMsg .= ": " . $stderrHint;
                } elseif ($stdoutHint) {
                    $errorMsg .= ": " . $stdoutHint;
                } else {
                    $errorMsg .= " (empty output, exit code {$csExitCode})";
                }
                $db->update('backup_jobs', [
                    'status' => 'failed',
                    'completed_at' => $csNow,
                    'duration_seconds' => max(0, strtotime($csNow) - strtotime($startedAt)),
                    'error_log' => $errorMsg,
                ], 'id = ?', [$sj['id']]);
                $db->insert('server_log', [
                    'agent_id' => $sj['agent_id'],
                    'backup_job_id' => $sj['id'],
                    'level' => 'error',
                    'message' => "Catalog sync failed: {$errorMsg}",
                ]);
                echo date('Y-m-d H:i:s') . " Catalog sync job #{$sj['id']} failed: {$errorMsg}\n";
                continue;
            }

            $archives = $csData['archives'] ?? [];

            // Clear existing archives for this repo and rebuild
            $db->delete('archives', 'repository_id = ?', [$csRepo['id']]);

            // Set progress bar for archive processing
            $totalArchiveCount = count($archives);
            $db->update('backup_jobs', [
                'files_total' => $totalArchiveCount,
                'files_processed' => 0,
            ], 'id = ?', [$sj['id']]);

            $archiveCount = 0;
            $totalSize = 0;
            foreach ($archives as $ar) {
                $archiveName = $ar['name'] ?? 'unknown';
                $createdAt = isset($ar['start']) ? date('Y-m-d H:i:s', strtotime($ar['start'])) : $csNow;
                $originalSize = 0;
                $deduplicatedSize = 0;
                $fileCount = 0;

                // Run borg info to get archive sizes
                if ($isRemoteSsh && isset($remoteConfig)) {
                    $infoResult = $remoteSshService->runBorgCommand($remoteConfig, $csRepo['path'], ['info', '--json', $csRepo['path'] . '::' . $archiveName], $passphrase);
                    if ($infoResult['success']) {
                        $infoData = json_decode($infoResult['output'], true);
                        $archiveInfo = $infoData['archives'][0] ?? [];
                        $stats = $archiveInfo['stats'] ?? [];
                        $originalSize = (int) ($stats['original_size'] ?? 0);
                        $deduplicatedSize = (int) ($stats['deduplicated_size'] ?? 0);
                        $fileCount = (int) ($stats['nfiles'] ?? 0);
                    }
                } else {
                    $archivePath = "{$csArchivePath}::{$archiveName}";
                    $runAsUser = $sj['ssh_unix_user'] ?? null;
                    if ($runAsUser) {
                        $infoCmd = [
                            'sudo', '/usr/local/bin/bbs-ssh-helper', 'borg-cmd',
                            $runAsUser, '-', 'info', '--json', $archivePath
                        ];
                        $infoEnvStrings = null;
                    } else {
                        $infoCmd = ['borg', 'info', '--json', $archivePath];
                        $infoEnv = [];
                        if ($passphrase) {
                            $infoEnv['BORG_PASSPHRASE'] = $passphrase;
                        }
                        $infoEnv['BORG_UNKNOWN_UNENCRYPTED_REPO_ACCESS_IS_OK'] = 'yes';
                        $infoEnv['BORG_RELOCATED_REPO_ACCESS_IS_OK'] = 'yes';
                        $infoEnv['BORG_BASE_DIR'] = '/tmp/bbs-borg-www-data';
                        $infoEnv['HOME'] = '/tmp/bbs-borg-www-data';
                        $infoEnvStrings = array_filter($_SERVER, 'is_string') + $infoEnv;
                    }

                    $infoProc = proc_open($infoCmd, [
                        0 => ['pipe', 'r'],
                        1 => ['pipe', 'w'],
                        2 => ['pipe', 'w'],
                    ], $infoPipes, null, $infoEnvStrings);

                    if (is_resource($infoProc)) {
                        if ($runAsUser) {
                            fwrite($infoPipes[0], $passphrase . "\n");
                        }
                        fclose($infoPipes[0]);
                        $infoOutput = stream_get_contents($infoPipes[1]);
                        fclose($infoPipes[1]);
                        fclose($infoPipes[2]);
                        $infoExitCode = proc_close($infoProc);

                        if ($infoExitCode === 0) {
                            $infoData = json_decode($infoOutput, true);
                            $archiveInfo = $infoData['archives'][0] ?? [];
                            $stats = $archiveInfo['stats'] ?? [];
                            $originalSize = (int) ($stats['original_size'] ?? 0);
                            $deduplicatedSize = (int) ($stats['deduplicated_size'] ?? 0);
                            $fileCount = (int) ($stats['nfiles'] ?? 0);
                        }
                    }
                }

                $db->insert('archives', [
                    'repository_id' => $csRepo['id'],
                    'archive_name' => $archiveName,
                    'created_at' => $createdAt,
                    'file_count' => $fileCount,
                    'original_size' => $originalSize,
                    'deduplicated_size' => $deduplicatedSize,
                ]);
                $archiveCount++;
                $totalSize += $deduplicatedSize;

                // Update progress
                $db->update('backup_jobs', [
                    'files_processed' => $archiveCount,
                    'last_progress_at' => $db->now(),
                ], 'id = ?', [$sj['id']]);

                echo date('Y-m-d H:i:s') . "   Catalog sync {$archiveCount}/{$totalArchiveCount}: {$archiveName}\n";
            }

            // Repo size: prefer borg's own dedup-aware unique_csize over the
            // sum of per-archive deduplicated_size, which is the *incremental*
            // contribution at archive-creation time and goes wrong as soon as
            // anything is pruned/compacted (#258).
            $sshUnixUser = $sj['ssh_unix_user'] ?? null;
            $repoUniqueSize = \BBS\Services\RepositorySizeService::fetchRepoUniqueCsize($csRepo, $sshUnixUser);
            $sizeForRepo = $repoUniqueSize ?? $totalSize;

            $db->update('repositories', [
                'archive_count' => $archiveCount,
                'size_bytes' => $sizeForRepo,
            ], 'id = ?', [$csRepo['id']]);

            $db->update('backup_jobs', [
                'status' => 'completed',
                'completed_at' => $csNow,
                'duration_seconds' => max(0, strtotime($csNow) - strtotime($startedAt)),
            ], 'id = ?', [$sj['id']]);

            $db->insert('server_log', [
                'agent_id' => $sj['agent_id'],
                'backup_job_id' => $sj['id'],
                'level' => 'info',
                'message' => "Catalog sync completed: {$archiveCount} archives found",
            ]);
            echo date('Y-m-d H:i:s') . " Catalog sync job #{$sj['id']} completed: {$archiveCount} archives\n";

            // Auto-queue catalog_rebuild to populate file catalog for all archives
            if ($archiveCount > 0) {
                $db->insert('backup_jobs', [
                    'agent_id' => $sj['agent_id'],
                    'repository_id' => $sj['repository_id'],
                    'task_type' => 'catalog_rebuild',
                    'status' => 'queued',
                ]);
                $db->insert('server_log', [
                    'agent_id' => $sj['agent_id'],
                    'level' => 'info',
                    'message' => "Catalog rebuild queued for {$archiveCount} archives after catalog sync",
                ]);
                echo date('Y-m-d H:i:s') . " Queued catalog_rebuild for repo #{$sj['repository_id']} ({$archiveCount} archives)\n";
            }
        } else {
            // Error may be in $csOutput (due to 2>&1 in helper) or $csError
            $errorMsg = trim($csError ?: $csOutput) ?: "borg list failed with exit code {$csExitCode}";
            $db->update('backup_jobs', [
                'status' => 'failed',
                'completed_at' => $csNow,
                'duration_seconds' => max(0, strtotime($csNow) - strtotime($startedAt)),
                'error_log' => $errorMsg,
            ], 'id = ?', [$sj['id']]);

            $db->insert('server_log', [
                'agent_id' => $sj['agent_id'],
                'backup_job_id' => $sj['id'],
                'level' => 'error',
                'message' => "Catalog sync failed: " . $errorMsg,
            ]);
            echo date('Y-m-d H:i:s') . " Catalog sync job #{$sj['id']} failed: {$errorMsg}\n";
        }
        continue;
    }

    // Catalog rebuild — extract file listings from all archives to populate per-agent catalog table
    if ($sj['task_type'] === 'catalog_rebuild' || $sj['task_type'] === 'catalog_rebuild_full') {
        $isFullRebuild = ($sj['task_type'] === 'catalog_rebuild_full');
        $crRepo = $db->fetchOne("SELECT * FROM repositories WHERE id = ?", [$sj['repository_id']]);
        if (!$crRepo) {
            $db->update('backup_jobs', [
                'status' => 'failed',
                'completed_at' => date('Y-m-d H:i:s'),
                'error_log' => 'Repository not found',
            ], 'id = ?', [$sj['id']]);
            echo date('Y-m-d H:i:s') . " Catalog rebuild job #{$sj['id']} failed: repository not found\n";
            continue;
        }

        $crLocalPath = \BBS\Services\BorgCommandBuilder::getLocalRepoPath($crRepo);
        $passphrase = '';
        if (!empty($crRepo['passphrase_encrypted'])) {
            try {
                $passphrase = \BBS\Services\Encryption::decrypt($crRepo['passphrase_encrypted']);
            } catch (\Exception $e) {
                // May already be plaintext or missing
            }
        }

        $agentId = $sj['agent_id'];
        $repoName = $crRepo['name'] ?? "repo #{$crRepo['id']}";

        // Log: Starting
        $db->insert('server_log', [
            'agent_id' => $agentId,
            'backup_job_id' => $sj['id'],
            'level' => 'info',
            'message' => "Starting catalog rebuild for repository \"{$repoName}\"",
        ]);
        echo date('Y-m-d H:i:s') . " Catalog rebuild job #{$sj['id']}: starting for \"{$repoName}\"\n";

        // Sync archives from borg before rebuilding catalog (ensures DB is fresh)
        $syncOutput = '';
        $syncExitCode = -1;
        if ($isRemoteSsh && !empty($sj['remote_ssh_config_id'])) {
            $remoteSshService = $remoteSshService ?? new RemoteSshService();
            $syncRemoteConfig = $remoteSshService->getById((int) $sj['remote_ssh_config_id']);
            if ($syncRemoteConfig) {
                $syncResult = $remoteSshService->runBorgCommand($syncRemoteConfig, $crRepo['path'], ['list', '--json', $crRepo['path']], $passphrase);
                $syncOutput = $syncResult['output'] ?? '';
                $syncExitCode = $syncResult['exit_code'] ?? -1;
            }
        } else {
            $runAsUserSync = $sj['ssh_unix_user'] ?? null;
            if ($runAsUserSync) {
                $syncCmd = ['sudo', '/usr/local/bin/bbs-ssh-helper', 'borg-list', $runAsUserSync, '-', $crLocalPath];
                $syncEnvStrings = null;
            } else {
                $syncCmd = ['borg', 'list', '--json', $crLocalPath];
                $syncEnv = [];
                if ($passphrase) $syncEnv['BORG_PASSPHRASE'] = $passphrase;
                $syncEnv['BORG_UNKNOWN_UNENCRYPTED_REPO_ACCESS_IS_OK'] = 'yes';
                $syncEnv['BORG_RELOCATED_REPO_ACCESS_IS_OK'] = 'yes';
                $syncEnv['BORG_BASE_DIR'] = '/tmp/bbs-borg-www-data';
                $syncEnv['HOME'] = '/tmp/bbs-borg-www-data';
                $syncEnvStrings = array_filter($_SERVER, 'is_string') + $syncEnv;
            }
            $syncProc = proc_open($syncCmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $syncPipes, null, $syncEnvStrings);
            if (is_resource($syncProc)) {
                if ($runAsUserSync) {
                    fwrite($syncPipes[0], $passphrase . "\n");
                }
                fclose($syncPipes[0]);
                $syncOutput = stream_get_contents($syncPipes[1]);
                fclose($syncPipes[1]);
                fclose($syncPipes[2]);
                $syncExitCode = proc_close($syncProc);
            }
        }

        if ($syncExitCode <= 1 && $syncOutput) {
            $syncData = json_decode($syncOutput, true);
            if ($syncData === null || !isset($syncData['archives'])) {
                echo date('Y-m-d H:i:s') . " Catalog rebuild job #{$sj['id']}: borg list returned invalid JSON, skipping archive sync\n";
                $syncData = ['archives' => []];
            }
            $borgArchives = $syncData['archives'];
            $existingNames = array_column(
                $db->fetchAll("SELECT archive_name FROM archives WHERE repository_id = ?", [$crRepo['id']]),
                'archive_name'
            );
            $existingNamesMap = array_flip($existingNames);
            $newCount = 0;
            foreach ($borgArchives as $ba) {
                $baName = $ba['name'] ?? '';
                if ($baName && !isset($existingNamesMap[$baName])) {
                    $baCreatedAt = isset($ba['start']) ? date('Y-m-d H:i:s', strtotime($ba['start'])) : date('Y-m-d H:i:s');
                    $db->insert('archives', [
                        'repository_id' => $crRepo['id'],
                        'archive_name' => $baName,
                        'created_at' => $baCreatedAt,
                    ]);
                    $newCount++;
                }
            }
            if ($newCount > 0) {
                $db->update('repositories', ['archive_count' => count($borgArchives)], 'id = ?', [$crRepo['id']]);
                echo date('Y-m-d H:i:s') . " Catalog rebuild job #{$sj['id']}: synced {$newCount} new archives from borg\n";
            }
        }

        // Get all archives for this repo (now includes any newly synced)
        $crArchives = $db->fetchAll("SELECT id, archive_name FROM archives WHERE repository_id = ? ORDER BY created_at ASC", [$crRepo['id']]);
        $totalArchives = count($crArchives);

        // Log: Listing recovery points
        $db->insert('server_log', [
            'agent_id' => $agentId,
            'backup_job_id' => $sj['id'],
            'level' => 'info',
            'message' => "Listing recovery points: {$totalArchives} found",
        ]);
        echo date('Y-m-d H:i:s') . " Catalog rebuild job #{$sj['id']}: {$totalArchives} recovery points found\n";

        if ($totalArchives === 0) {
            $db->update('backup_jobs', [
                'status' => 'completed',
                'completed_at' => date('Y-m-d H:i:s'),
                'duration_seconds' => 0,
            ], 'id = ?', [$sj['id']]);
            continue;
        }

        // For remote SSH repos, load config
        $crRemoteConfig = null;
        if ($isRemoteSsh && !empty($sj['remote_ssh_config_id'])) {
            $remoteSshService = $remoteSshService ?? new RemoteSshService();
            $crRemoteConfig = $remoteSshService->getById((int) $sj['remote_ssh_config_id']);
        }

        $runAsUser = $sj['ssh_unix_user'] ?? null;

        $ch = \BBS\Core\ClickHouse::getInstance();

        // Get all archive IDs for this repo (used to scope ClickHouse operations)
        $repoArchiveIds = array_column($crArchives, 'id');
        $repoArchiveList = implode(',', array_map('intval', $repoArchiveIds));

        if ($isFullRebuild) {
            // Full rebuild: drop existing data for THIS REPO's archives only (not the whole agent)
            if (!empty($repoArchiveList)) {
                try {
                    $ch->exec("ALTER TABLE file_catalog DELETE WHERE agent_id = {$agentId} AND archive_id IN ({$repoArchiveList})");
                    $ch->exec("ALTER TABLE catalog_dirs DELETE WHERE agent_id = {$agentId} AND archive_id IN ({$repoArchiveList})");
                } catch (\Exception $e) { /* may not exist yet */ }
            }
            $missingArchives = $crArchives;
            echo date('Y-m-d H:i:s') . " Catalog rebuild job #{$sj['id']}: FULL rebuild — dropped repo data, re-indexing all {$totalArchives} archives\n";
        } else {
            // Incremental rebuild: only process archives not already in ClickHouse
            // Scope to this repo's archive IDs only (don't touch other repos on same agent)
            $existingArchiveIds = [];
            if (!empty($repoArchiveList)) {
                try {
                    $existing = $ch->fetchAll("SELECT DISTINCT archive_id FROM file_catalog WHERE agent_id = {$agentId} AND archive_id IN ({$repoArchiveList})");
                    $existingArchiveIds = array_flip(array_column($existing, 'archive_id'));
                } catch (\Exception $e) { /* table may be empty */ }
            }

            // Filter to only missing archives
            $missingArchives = array_filter($crArchives, fn($a) => !isset($existingArchiveIds[$a['id']]));
            $missingArchives = array_values($missingArchives);

            // Clean up ClickHouse data for archives that were pruned from MySQL (this repo only)
            $orphanedInCh = array_diff(array_keys($existingArchiveIds), $repoArchiveIds);
            if (!empty($orphanedInCh)) {
                $orphanList = implode(',', array_map('intval', $orphanedInCh));
                try {
                    $ch->exec("ALTER TABLE file_catalog DELETE WHERE agent_id = {$agentId} AND archive_id IN ({$orphanList})");
                    $ch->exec("ALTER TABLE catalog_dirs DELETE WHERE agent_id = {$agentId} AND archive_id IN ({$orphanList})");
                } catch (\Exception $e) { /* non-fatal */ }
                echo date('Y-m-d H:i:s') . " Catalog rebuild job #{$sj['id']}: cleaned up " . count($orphanedInCh) . " pruned archives from ClickHouse\n";
            }
        }

        $totalToProcess = count($missingArchives);
        if ($totalToProcess === 0) {
            $db->update('backup_jobs', [
                'status' => 'completed',
                'completed_at' => date('Y-m-d H:i:s'),
                'duration_seconds' => 0,
            ], 'id = ?', [$sj['id']]);
            echo date('Y-m-d H:i:s') . " Catalog rebuild job #{$sj['id']}: all {$totalArchives} archives already indexed, nothing to do\n";
            continue;
        }

        echo date('Y-m-d H:i:s') . " Catalog rebuild job #{$sj['id']}: {$totalToProcess} of {$totalArchives} archives need indexing\n";

        // Set files_total to missing archive count for progress bar display
        $db->update('backup_jobs', [
            'files_total' => $totalToProcess,
            'files_processed' => 0,
        ], 'id = ?', [$sj['id']]);

        $escape = fn(string $s) => str_replace(["\t", "\n", "\\"], ["\\t", "\\n", "\\\\"], $s);

        $processedArchives = 0;
        $totalFiles = 0;
        $errors = [];

        foreach ($missingArchives as $crArchive) {
            // Remote SSH repos: stream via RemoteSshService (constant memory)
            if ($isRemoteSsh && $crRemoteConfig) {
                $handle = $remoteSshService->openBorgProcess(
                    $crRemoteConfig,
                    ['list', '--json-lines', $crRepo['path'] . '::' . $crArchive['archive_name']],
                    $passphrase
                );
                if (isset($handle['error'])) {
                    $errors[] = "Archive {$crArchive['archive_name']}: {$handle['error']}";
                    continue;
                }

                $tsvFile = sys_get_temp_dir() . "/catalog_rebuild_{$agentId}_{$crArchive['id']}_" . getmypid() . '.tsv';
                $tsvFh = fopen($tsvFile, 'w');
                $archiveFileCount = 0;
                $dirStats = [];

                while (($line = fgets($handle['pipes'][1])) !== false) {
                    $line = trim($line);
                    if ($line === '') continue;
                    $fileData = json_decode($line, true);
                    if ($fileData && isset($fileData['path'])) {
                        if (($fileData['type'] ?? '') !== 'd') {
                            $path = $fileData['path'];
                            if ($path !== '' && $path[0] !== '/') {
                                $path = '/' . $path;
                            }
                            $size = (int) ($fileData['size'] ?? 0);
                            $mtime = isset($fileData['mtime']) ? date('Y-m-d H:i:s', strtotime($fileData['mtime'])) : '\\N';
                            $rawParent = dirname($path);

                            fwrite($tsvFh, "{$agentId}\t{$crArchive['id']}\t{$escape($path)}\t{$escape(basename($path))}\t{$escape($rawParent)}\t{$size}\tU\t{$mtime}\n");
                            $archiveFileCount++;

                            if (!isset($dirStats[$rawParent])) {
                                $dirStats[$rawParent] = [0, 0];
                            }
                            $dirStats[$rawParent][0]++;
                            $dirStats[$rawParent][1] += $size;
                        }
                    }
                }
                fclose($tsvFh);
                fclose($handle['pipes'][1]);
                $crError = stream_get_contents($handle['pipes'][2]);
                fclose($handle['pipes'][2]);
                $crExitCode = proc_close($handle['proc']);
                $remoteSshService->cleanupStreamingProcess($handle);

                if ($crExitCode !== 0) {
                    $errors[] = "Archive {$crArchive['archive_name']}: exit code {$crExitCode}";
                    @unlink($tsvFile);
                    continue;
                }
            } else {
                $archivePath = "{$crLocalPath}::{$crArchive['archive_name']}";

                // Build command to list archive files
                if ($runAsUser) {
                    $crCmd = [
                        'sudo', '/usr/local/bin/bbs-ssh-helper', 'borg-list-archive',
                        $runAsUser, '-', $archivePath
                    ];
                    $crEnv = null;
                } else {
                    $crCmd = ['borg', 'list', '--json-lines', $archivePath];
                    $crEnv = [];
                    if ($passphrase) {
                        $crEnv['BORG_PASSPHRASE'] = $passphrase;
                    }
                    $crEnv['BORG_UNKNOWN_UNENCRYPTED_REPO_ACCESS_IS_OK'] = 'yes';
                    $crEnv['BORG_RELOCATED_REPO_ACCESS_IS_OK'] = 'yes';
                    $crEnv['BORG_BASE_DIR'] = '/tmp/bbs-borg-www-data';
                    $crEnv['HOME'] = '/tmp/bbs-borg-www-data';
                    $crEnv = array_filter($_SERVER, 'is_string') + $crEnv;
                }

                $crProc = proc_open($crCmd, [
                    0 => ['pipe', 'r'],
                    1 => ['pipe', 'w'],
                    2 => ['pipe', 'w'],
                ], $crPipes, null, $crEnv);

                if (!is_resource($crProc)) {
                    $errors[] = "Failed to start borg for archive {$crArchive['archive_name']}";
                    continue;
                }

                if ($runAsUser) {
                    fwrite($crPipes[0], $passphrase . "\n");
                }
                fclose($crPipes[0]);

                // Stream borg stdout line-by-line to TSV — constant memory usage
                // instead of buffering entire output (which can be multi-GB for large archives)
                $tsvFile = sys_get_temp_dir() . "/catalog_rebuild_{$agentId}_{$crArchive['id']}_" . getmypid() . '.tsv';
                $tsvFh = fopen($tsvFile, 'w');
                $archiveFileCount = 0;
                $dirStats = [];

                while (($line = fgets($crPipes[1])) !== false) {
                    $line = trim($line);
                    if ($line === '') continue;
                    $fileData = json_decode($line, true);
                    if ($fileData && isset($fileData['path'])) {
                        if (($fileData['type'] ?? '') !== 'd') {
                            $path = $fileData['path'];
                            if ($path !== '' && $path[0] !== '/') {
                                $path = '/' . $path;
                            }
                            $size = (int) ($fileData['size'] ?? 0);
                            $mtime = isset($fileData['mtime']) ? date('Y-m-d H:i:s', strtotime($fileData['mtime'])) : '\\N';
                            $rawParent = dirname($path);

                            fwrite($tsvFh, "{$agentId}\t{$crArchive['id']}\t{$escape($path)}\t{$escape(basename($path))}\t{$escape($rawParent)}\t{$size}\tU\t{$mtime}\n");
                            $archiveFileCount++;

                            if (!isset($dirStats[$rawParent])) {
                                $dirStats[$rawParent] = [0, 0];
                            }
                            $dirStats[$rawParent][0]++;
                            $dirStats[$rawParent][1] += $size;
                        }
                    }
                }
                fclose($tsvFh);
                fclose($crPipes[1]);
                $crError = stream_get_contents($crPipes[2]);
                fclose($crPipes[2]);
                $crExitCode = proc_close($crProc);

                if ($crExitCode !== 0) {
                    $errors[] = "Archive {$crArchive['archive_name']}: exit code {$crExitCode}";
                    @unlink($tsvFile);
                    continue;
                }
            }

            if ($archiveFileCount > 0) {
                try {
                    $ch->insertTsv('file_catalog', $tsvFile, [
                        'agent_id', 'archive_id', 'path', 'file_name', 'parent_dir', 'file_size', 'status', 'mtime'
                    ]);
                } catch (\Exception $e) {
                    $errors[] = "Archive {$crArchive['archive_name']}: ClickHouse insert failed: " . $e->getMessage();
                    @unlink($tsvFile);
                    continue;
                }
                $totalFiles += $archiveFileCount;

                // Build dirs for this archive
                if (!empty($dirStats)) {
                    $allDirs = [];
                    foreach ($dirStats as $dirPath => [$fc, $sz]) {
                        if (!isset($allDirs[$dirPath])) $allDirs[$dirPath] = [0, 0];
                        $allDirs[$dirPath][0] += $fc;
                        $allDirs[$dirPath][1] += $sz;
                        $p = dirname($dirPath);
                        while ($p !== '/' && $p !== '.' && !isset($allDirs[$p])) {
                            $allDirs[$p] = [0, 0];
                            $p = dirname($p);
                        }
                    }
                    unset($allDirs['/']);

                    $dirsTsv = sys_get_temp_dir() . "/catalog_dirs_{$agentId}_{$crArchive['id']}_" . getmypid() . '.tsv';
                    $dirsFh = fopen($dirsTsv, 'w');
                    foreach ($allDirs as $dPath => [$dFc, $dSz]) {
                        $dParent = dirname($dPath);
                        if ($dParent === '.') $dParent = '/';
                        $dName = basename($dPath);
                        fwrite($dirsFh, "{$agentId}\t{$crArchive['id']}\t{$escape($dPath)}\t{$escape($dParent)}\t{$escape($dName)}\t{$dFc}\t{$dSz}\n");
                    }
                    fclose($dirsFh);
                    try {
                        $ch->insertTsv('catalog_dirs', $dirsTsv, [
                            'agent_id', 'archive_id', 'dir_path', 'parent_dir', 'name', 'file_count', 'total_size'
                        ]);
                    } catch (\Exception $e) { /* non-fatal */ }
                    @unlink($dirsTsv);
                }
            } else {
                // Archive genuinely has 0 indexable files (only directories or
                // truly empty). Insert a sentinel row so the auto-rebuild check
                // in step 3b sees this archive_id in ClickHouse and stops
                // re-triggering a rebuild every 24 hours.
                try {
                    $sentinelTsv = sys_get_temp_dir() . "/catalog_sentinel_{$agentId}_{$crArchive['id']}_" . getmypid() . '.tsv';
                    file_put_contents($sentinelTsv, "{$agentId}\t{$crArchive['id']}\t\t\t\t0\tE\t\\N\n");
                    $ch->insertTsv('file_catalog', $sentinelTsv, [
                        'agent_id', 'archive_id', 'path', 'file_name', 'parent_dir', 'file_size', 'status', 'mtime'
                    ]);
                    @unlink($sentinelTsv);
                } catch (\Exception $e) { /* non-fatal — worst case is a repeat rebuild */ }
            }
            @unlink($tsvFile);

            $processedArchives++;

            // Update progress for UI progress bar (files_processed = archives processed)
            $db->update('backup_jobs', [
                'files_processed' => $processedArchives,
                'last_progress_at' => $db->now(),
            ], 'id = ?', [$sj['id']]);

            // Log progress to server_log for UI visibility
            $db->insert('server_log', [
                'agent_id' => $agentId,
                'backup_job_id' => $sj['id'],
                'level' => 'info',
                'message' => "Catalog rebuild {$processedArchives}/{$totalToProcess}: {$crArchive['archive_name']} ({$archiveFileCount} files)",
            ]);

            echo date('Y-m-d H:i:s') . "   Catalog rebuild {$processedArchives}/{$totalToProcess}: {$crArchive['archive_name']} ({$archiveFileCount} files)\n";
        }

        $crNow = date('Y-m-d H:i:s');
        $duration = max(0, strtotime($crNow) - strtotime($startedAt));

        if (empty($errors)) {
            // Update cached catalog total for dashboard
            \BBS\Services\CatalogImporter::updateCachedTotal($db);

            $db->update('backup_jobs', [
                'status' => 'completed',
                'completed_at' => $crNow,
                'duration_seconds' => $duration,
            ], 'id = ?', [$sj['id']]);

            $db->insert('server_log', [
                'agent_id' => $agentId,
                'backup_job_id' => $sj['id'],
                'level' => 'info',
                'message' => "Catalog rebuild completed: {$processedArchives} archives, {$totalFiles} files indexed",
            ]);
            echo date('Y-m-d H:i:s') . " Catalog rebuild job #{$sj['id']} completed: {$processedArchives} archives, {$totalFiles} files\n";
        } else {
            $errorSummary = count($errors) . " errors: " . implode('; ', array_slice($errors, 0, 3));
            $db->update('backup_jobs', [
                'status' => 'failed',
                'completed_at' => $crNow,
                'duration_seconds' => $duration,
                'error_log' => $errorSummary,
            ], 'id = ?', [$sj['id']]);

            $db->insert('server_log', [
                'agent_id' => $agentId,
                'backup_job_id' => $sj['id'],
                'level' => 'error',
                'message' => "Catalog rebuild failed: {$errorSummary}",
            ]);
            echo date('Y-m-d H:i:s') . " Catalog rebuild job #{$sj['id']} failed: {$errorSummary}\n";
        }
        continue;
    }

    // Build borg command arguments — use repo path (remote SSH or local)
    $repoPath = $isRemoteSsh ? $repo['path'] : $localPath;
    if ($sj['task_type'] === 'prune') {
        // Only scope prune to this plan's archives if the repo has multiple plans.
        // Single-plan repos prune everything (including imported/orphaned archives).
        $planCount = (int) ($db->fetchOne(
            "SELECT COUNT(*) as cnt FROM backup_plans WHERE repository_id = ? AND enabled = 1",
            [$sj['repository_id']]
        )['cnt'] ?? 0);
        $archivePrefix = ($planCount > 1 && $sj['backup_plan_id']) ? 'plan' . $sj['backup_plan_id'] : null;
        $borgArgs = \BBS\Services\BorgCommandBuilder::buildPruneCommand($plan, $localRepo, $archivePrefix);
        // Remove 'borg' from the front since we'll add it back
        if ($borgArgs[0] === 'borg') {
            array_shift($borgArgs);
        }
        // For remote repos, replace the local path with the remote path in prune args
        if ($isRemoteSsh) {
            $lastIdx = count($borgArgs) - 1;
            $borgArgs[$lastIdx] = $repo['path'];
        }
    } elseif ($sj['task_type'] === 'compact') {
        // --verbose so borg emits the "compaction freed about X GB" summary
        // line, which the generic stdout logger downstream captures into
        // server_log (issue #162).
        $borgArgs = ['compact', '--verbose', $repoPath];
    } elseif ($sj['task_type'] === 'repo_check') {
        $borgArgs = ['check', '--verbose', $repoPath];
    } elseif ($sj['task_type'] === 'repo_repair') {
        $borgArgs = ['check', '--repair', $repoPath];
    } elseif ($sj['task_type'] === 'break_lock') {
        $borgArgs = ['break-lock', $repoPath];
    } elseif ($sj['task_type'] === 'archive_delete') {
        $archiveName = $sj['status_message'] ?? '';
        if (empty($archiveName)) {
            $db->update('backup_jobs', [
                'status' => 'failed',
                'completed_at' => date('Y-m-d H:i:s'),
                'error_log' => 'No archive name specified for deletion',
            ], 'id = ?', [$sj['id']]);
            continue;
        }
        $borgArgs = ['delete', $repoPath . '::' . $archiveName];
    } else {
        // Unknown task type
        $db->update('backup_jobs', [
            'status' => 'failed',
            'completed_at' => date('Y-m-d H:i:s'),
            'error_log' => "Unknown server-side task type: {$sj['task_type']}",
        ], 'id = ?', [$sj['id']]);
        echo date('Y-m-d H:i:s') . " Unknown task type: {$sj['task_type']} for job #{$sj['id']}\n";
        continue;
    }

    // Get passphrase for the helper
    $env = \BBS\Services\BorgCommandBuilder::buildEnv($localRepo, false);
    $passphrase = $env['BORG_PASSPHRASE'] ?? '';

    // Remote SSH repos: execute via RemoteSshService
    if ($isRemoteSsh && !empty($sj['remote_ssh_config_id'])) {
        $remoteSshService = $remoteSshService ?? new RemoteSshService();
        $remoteConfig = $remoteSshService->getById((int) $sj['remote_ssh_config_id']);

        if (!$remoteConfig) {
            $db->update('backup_jobs', [
                'status' => 'failed',
                'completed_at' => date('Y-m-d H:i:s'),
                'error_log' => 'Remote SSH config not found',
            ], 'id = ?', [$sj['id']]);
            echo date('Y-m-d H:i:s') . " Job #{$sj['id']} failed: remote SSH config not found\n";
            continue;
        }

        $cmdStr = implode(' ', array_map('escapeshellarg', $borgArgs));
        $db->insert('server_log', [
            'agent_id' => $sj['agent_id'],
            'backup_job_id' => $sj['id'],
            'level' => 'info',
            'message' => ucfirst($sj['task_type']) . " command (remote SSH): borg {$cmdStr}",
        ]);

        $remoteResult = $remoteSshService->runBorgCommand($remoteConfig, $repo['path'], $borgArgs, $passphrase);
        $result = $remoteResult['success'] ? 'completed' : 'failed';
        $stdout = $remoteResult['output'] ?? '';
        $errorOutput = $result === 'failed' ? trim($remoteResult['stderr'] ?? $stdout) ?: "Exit code {$remoteResult['exit_code']}" : '';
    } else {
        // Local repos: run as the repo's unix user via bbs-ssh-helper
        $runAsUser = $sj['ssh_unix_user'] ?? null;
        if ($runAsUser) {
            // Use ssh-helper which handles sudo properly. Passphrase is piped
            // via stdin ("-" marker) so it's not visible in `ps`.
            $cmd = array_merge(
                ['sudo', '/usr/local/bin/bbs-ssh-helper', 'borg-cmd', $runAsUser, '-'],
                $borgArgs
            );
            $envStrings = [];
        } else {
            // No unix user — run directly as www-data (legacy mode)
            $cmd = array_merge(['borg'], $borgArgs);
            $envStrings = [];
            foreach ($env as $k => $v) {
                $envStrings[$k] = $v;
            }
            $envStrings['BORG_BASE_DIR'] = '/tmp/bbs-borg-www-data';
            $envStrings['HOME'] = '/tmp/bbs-borg-www-data';
        }

        // Log the borg command (passphrase passed on stdin, never in argv)
        $logCmd = $runAsUser
            ? array_merge(['sudo', 'bbs-ssh-helper', 'borg-cmd', $runAsUser, '-'], $borgArgs)
            : $cmd;
        $cmdStr = implode(' ', array_map('escapeshellarg', array_values($logCmd)));
        $db->insert('server_log', [
            'agent_id' => $sj['agent_id'],
            'backup_job_id' => $sj['id'],
            'level' => 'info',
            'message' => ucfirst($sj['task_type']) . " command: {$cmdStr}",
        ]);

        // Execute
        $desc = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = proc_open($cmd, $desc, $pipes, null, $envStrings);

        $result = 'failed';
        $errorOutput = '';
        $stdout = '';

        if (is_resource($proc)) {
            if ($runAsUser) {
                fwrite($pipes[0], $passphrase . "\n");
            }
            fclose($pipes[0]);
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exitCode = proc_close($proc);

            if ($exitCode <= 1) {
                $result = 'completed';
            } else {
                // Error may be in $stdout (due to 2>&1 in helper) or $stderr
                $errorOutput = trim($stderr ?: $stdout) ?: "Exit code $exitCode";
            }
        } else {
            $errorOutput = 'Failed to execute borg command';
        }
    }

    $now = date('Y-m-d H:i:s');
    // Only finalize jobs we still own (status='running'). If an external
    // flow flipped the row to failed/cancelled while borg was running,
    // don't clobber that with a 'completed' here (#227 — stall-detect
    // marked the job abandoned 9s after we started, and the long delete
    // run then overwrote 'failed' with 'completed').
    $finalize = $db->query(
        "UPDATE backup_jobs SET status = ?, completed_at = ?, duration_seconds = ?, error_log = ?
         WHERE id = ? AND status = 'running'",
        [$result, $now, max(0, strtotime($now) - strtotime($startedAt)), $errorOutput ?: null, $sj['id']]
    );
    if ($finalize->rowCount() === 0) {
        // Surface the race in the activity log too, not just stdout, so an
        // admin can see why a long-running server-side job ended without a
        // matching "Server-side X completed" entry (PR #228, credit @SAY-5).
        $current = $db->fetchOne("SELECT status FROM backup_jobs WHERE id = ?", [$sj['id']]);
        $existingStatus = $current['status'] ?? 'unknown';
        $db->insert('server_log', [
            'agent_id' => $sj['agent_id'],
            'backup_job_id' => $sj['id'],
            'level' => 'warning',
            'message' => "Server-side {$sj['task_type']} job #{$sj['id']} finished, but its status was already '{$existingStatus}' (likely an abandoned/cancelled report came in mid-flight); not overwriting.",
        ]);
        echo date('Y-m-d H:i:s') . " Job #{$sj['id']} ({$sj['task_type']}) finished but row was already '{$existingStatus}' — leaving as-is\n";
        continue;
    }

    $level = $result === 'completed' ? 'info' : 'error';
    $db->insert('server_log', [
        'agent_id' => $sj['agent_id'],
        'backup_job_id' => $sj['id'],
        'level' => $level,
        'message' => "Server-side {$sj['task_type']} job #{$sj['id']} {$result}" . ($errorOutput ? ": $errorOutput" : ''),
    ]);

    // Log borg prune/compact output for visibility
    if ($result === 'completed' && !empty($stdout)) {
        // Truncate to a reasonable size for the log
        $trimmedOutput = mb_substr(trim($stdout), 0, 2000);
        if ($trimmedOutput) {
            $db->insert('server_log', [
                'agent_id' => $sj['agent_id'],
                'backup_job_id' => $sj['id'],
                'level' => 'info',
                'message' => ucfirst($sj['task_type']) . " output: " . $trimmedOutput,
            ]);
        }
    }

    echo date('Y-m-d H:i:s') . " Server-side {$sj['task_type']} job #{$sj['id']}: {$result}\n";

    // After successful prune, sync archives table with actual repo contents
    if ($result === 'completed' && $sj['task_type'] === 'prune') {
        $listOut = null;
        $listExit = -1;

        if ($isRemoteSsh && isset($remoteConfig)) {
            // Remote SSH: list via RemoteSshService
            $listResult = $remoteSshService->runBorgCommand($remoteConfig, $repo['path'], ['list', '--json', $repo['path']], $passphrase);
            $listOut = $listResult['output'] ?? '';
            $listExit = $listResult['exit_code'] ?? -1;
        } else {
            $runAsUser = $sj['ssh_unix_user'] ?? null;
            if ($runAsUser) {
                // Use ssh-helper for borg list
                $listCmd = [
                    'sudo', '/usr/local/bin/bbs-ssh-helper', 'borg-list',
                    $runAsUser, $passphrase, $localPath
                ];
                $listEnv = [];
            } else {
                $listCmd = \BBS\Services\BorgCommandBuilder::buildListCommand($localRepo);
                $listEnv = $envStrings ?? [];
            }
            $desc = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
            $listProc = proc_open($listCmd, $desc, $listPipes, null, $listEnv);

            if (is_resource($listProc)) {
                fclose($listPipes[0]);
                $listOut = stream_get_contents($listPipes[1]);
                fclose($listPipes[1]);
                fclose($listPipes[2]);
                $listExit = proc_close($listProc);
            }
        }

        if ($listExit <= 1 && $listOut) {
            $listData = json_decode($listOut, true);

            // Safety check: if JSON parse failed (e.g., borg warnings mixed into output),
            // skip the sync entirely to avoid deleting all archive records
            if ($listData === null || !isset($listData['archives'])) {
                $db->insert('server_log', [
                    'agent_id' => $sj['agent_id'],
                    'backup_job_id' => $sj['id'],
                    'level' => 'warning',
                    'message' => "Post-prune archive sync skipped: borg list output was not valid JSON",
                ]);
                echo date('Y-m-d H:i:s') . " Skipping post-prune archive sync for job #{$sj['id']}: invalid JSON from borg list\n";
            } else {
            $borgArchives = [];
            if (!empty($listData['archives'])) {
                foreach ($listData['archives'] as $a) {
                    $borgArchives[] = $a['name'];
                }
            }

            // Get DB archives for this repo
            $repoId = $sj['repository_id'];
            $dbArchives = $db->fetchAll(
                "SELECT id, archive_name FROM archives WHERE repository_id = ?", [$repoId]
            );

            $removed = 0;
            $removedNames = [];
            $agentId = (int) $sj['agent_id'];
            foreach ($dbArchives as $dbA) {
                if (!in_array($dbA['archive_name'], $borgArchives, true)) {
                    $db->delete('archives', 'id = ?', [$dbA['id']]);
                    // Clean up catalog entries for the pruned archive in ClickHouse
                    try {
                        $chPrune = \BBS\Core\ClickHouse::getInstance();
                        $archiveIdInt = (int) $dbA['id'];
                        $chPrune->exec("ALTER TABLE file_catalog DELETE WHERE agent_id = {$agentId} AND archive_id = {$archiveIdInt}");
                        $chPrune->exec("ALTER TABLE catalog_dirs DELETE WHERE agent_id = {$agentId} AND archive_id = {$archiveIdInt}");
                    } catch (\Exception $e) { /* ClickHouse may not be available */ }
                    $removedNames[] = $dbA['archive_name'];
                    $removed++;
                }
            }

            if ($removed > 0) {
                $nameList = implode(', ', array_slice($removedNames, 0, 20));
                if (count($removedNames) > 20) {
                    $nameList .= ' (and ' . (count($removedNames) - 20) . ' more)';
                }
                $db->insert('server_log', [
                    'agent_id' => $sj['agent_id'],
                    'backup_job_id' => $sj['id'],
                    'level' => 'info',
                    'message' => "Removed {$removed} pruned recovery point(s) from database — " . count($borgArchives) . " remaining: {$nameList}",
                ]);
                echo date('Y-m-d H:i:s') . " Removed {$removed} pruned archive(s) from DB for repo #{$repoId}\n";
            } else {
                $db->insert('server_log', [
                    'agent_id' => $sj['agent_id'],
                    'backup_job_id' => $sj['id'],
                    'level' => 'info',
                    'message' => "Prune completed — all " . count($borgArchives) . " recovery point(s) retained, none removed",
                ]);
            }
            // Refresh archive count + size. Prune just shrank the repo,
            // so measure actual disk usage now — see RepositorySizeService
            // for the local/remote chain.
            $db->query(
                "UPDATE repositories SET archive_count = (SELECT COUNT(*) FROM archives WHERE repository_id = ?) WHERE id = ?",
                [$repoId, $repoId]
            );
            \BBS\Services\RepositorySizeService::refresh((int) $repoId);

            } // end JSON validation else
        }
    }

    // After successful compact, repo shrank — refresh size.
    if ($result === 'completed' && $sj['task_type'] === 'compact') {
        \BBS\Services\RepositorySizeService::refresh((int) $sj['repository_id']);
    }

    // After successful archive_delete, remove the archive from the database
    if ($result === 'completed' && $sj['task_type'] === 'archive_delete' && !empty($sj['status_message'])) {
        $archiveName = $sj['status_message'];
        $deletedArchive = $db->fetchOne(
            "SELECT id FROM archives WHERE repository_id = ? AND archive_name = ?",
            [$sj['repository_id'], $archiveName]
        );
        if ($deletedArchive) {
            // Clean up catalog entries in ClickHouse
            try {
                $chDel = \BBS\Core\ClickHouse::getInstance();
                $archiveIdInt = (int) $deletedArchive['id'];
                $agentIdInt = (int) $sj['agent_id'];
                $chDel->exec("ALTER TABLE file_catalog DELETE WHERE agent_id = {$agentIdInt} AND archive_id = {$archiveIdInt}");
                $chDel->exec("ALTER TABLE catalog_dirs DELETE WHERE agent_id = {$agentIdInt} AND archive_id = {$archiveIdInt}");
            } catch (\Exception $e) { /* ClickHouse may not be available */ }

            $db->delete('archives', 'id = ?', [$deletedArchive['id']]);

            $db->query(
                "UPDATE repositories SET archive_count = (SELECT COUNT(*) FROM archives WHERE repository_id = ?) WHERE id = ?",
                [$sj['repository_id'], $sj['repository_id']]
            );
            \BBS\Services\RepositorySizeService::refresh((int) $sj['repository_id']);

            echo date('Y-m-d H:i:s') . " Removed archive \"{$archiveName}\" from DB for repo #{$sj['repository_id']}\n";
        }
    }

    // Auto-queue S3 sync after successful prune (skip for remote SSH — already offsite)
    if ($result === 'completed' && $sj['task_type'] === 'prune' && !empty($sj['repository_id']) && !$isRemoteSsh) {
        // Check repository_s3_configs for S3 sync configuration
        $repoS3Config = $db->fetchOne(
            "SELECT rsc.plugin_config_id
             FROM repository_s3_configs rsc
             WHERE rsc.repository_id = ? AND rsc.enabled = 1",
            [$sj['repository_id']]
        );

        if ($repoS3Config) {
            // Check if s3_sync is already queued or running for this repo
            $existingS3 = $db->fetchOne(
                "SELECT id FROM backup_jobs
                 WHERE repository_id = ? AND task_type = 's3_sync' AND status IN ('queued', 'sent', 'running')
                 LIMIT 1",
                [$sj['repository_id']]
            );
            if ($existingS3) {
                echo date('Y-m-d H:i:s') . " Skipped: S3 sync already queued/running (job #{$existingS3['id']}) for repo #{$sj['repository_id']}\n";
            } else {
                $s3JobId = $db->insert('backup_jobs', [
                    'agent_id' => $sj['agent_id'],
                    'repository_id' => $sj['repository_id'],
                    'task_type' => 's3_sync',
                    'plugin_config_id' => $repoS3Config['plugin_config_id'],
                    'status' => 'queued',
                ]);

                $db->insert('server_log', [
                    'agent_id' => $sj['agent_id'],
                    'backup_job_id' => $s3JobId,
                    'level' => 'info',
                    'message' => "S3 sync queued (job #{$s3JobId}) after prune job #{$sj['id']}",
                ]);

                // Update last_sync_at will happen when the job completes
                echo date('Y-m-d H:i:s') . " Queued: S3 sync job #{$s3JobId} after prune #{$sj['id']}\n";
            }
        }
    }
}

// Step 5: Bootstrap size for any local repo whose size_bytes is still 0
// (fresh install, newly added repo, or legacy migration). Runs every minute
// but only touches disks once per repo, since the UPDATE makes size_bytes > 0.
// After the bootstrap, size is maintained by event-driven refreshes in
// RepositorySizeService — triggered after backup, prune, compact, and
// archive_delete. No periodic rescan on idle disks.
$zeroRepos = $db->fetchAll(
    "SELECT id FROM repositories
      WHERE size_bytes = 0
        AND (storage_type = 'local' OR storage_type IS NULL)
        AND id IN (SELECT DISTINCT repository_id FROM archives)"
);
foreach ($zeroRepos as $zr) {
    \BBS\Services\RepositorySizeService::refresh((int) $zr['id']);
}

// Step 5b: Poll remote SSH host disk usage (every 15 minutes)
if ((int) date('i') % 15 === 0) {
    $remoteSshService = $remoteSshService ?? new RemoteSshService();
    $remoteConfigs = $db->fetchAll("SELECT * FROM remote_ssh_configs");
    foreach ($remoteConfigs as $rc) {
        $rcFull = $remoteSshService->getDecrypted((int) $rc['id']);
        if ($rcFull) {
            $diskData = $remoteSshService->getDiskUsage($rcFull);
            $remoteSshService->updateDiskUsage((int) $rc['id'], $diskData);
            if ($diskData) {
                echo date('Y-m-d H:i:s') . " Remote SSH \"{$rc['name']}\": {$diskData['percent']}% used\n";
            } else {
                echo date('Y-m-d H:i:s') . " Remote SSH \"{$rc['name']}\": df unavailable\n";
            }
        }
    }
}

// Step 6: Check storage for low disk space — per-user thresholds (#156).
// Each user picks their own trigger: percent-used, free-gb, or disabled.
// We collect every storage endpoint's stats once, then evaluate each user's
// threshold against them so the disk_total_space / df syscalls only run once
// regardless of how many users are on the server.
$notificationService = $notificationService ?? new NotificationService();

$storageLocations = $db->fetchAll("SELECT * FROM storage_locations ORDER BY id");
if (empty($storageLocations)) {
    $storagePathSetting = $db->fetchOne("SELECT `value` FROM settings WHERE `key` = 'storage_path'");
    if (!empty($storagePathSetting['value'])) {
        $storageLocations = [['path' => $storagePathSetting['value'], 'label' => 'Default']];
    }
}

// Collect usage for every storage endpoint once.
$storageStats = []; // [{label, detail, total_bytes, free_bytes, used_percent}]
foreach ($storageLocations as $sl) {
    $slPath = $sl['path'] ?? '';
    if (empty($slPath) || !is_dir($slPath)) continue;
    $total = @disk_total_space($slPath);
    $free  = @disk_free_space($slPath);
    if ($total === false || $free === false || $total <= 0) continue;
    $storageStats[] = [
        'label'        => $sl['label'] ?? $slPath,
        'detail'       => $slPath,
        'total_bytes'  => (int) $total,
        'free_bytes'   => (int) $free,
        'used_percent' => round((($total - $free) / $total) * 100, 1),
    ];
}
$remoteConfigs = $db->fetchAll("SELECT * FROM remote_ssh_configs WHERE disk_total_bytes IS NOT NULL AND disk_total_bytes > 0");
foreach ($remoteConfigs as $rc) {
    $total = (int) $rc['disk_total_bytes'];
    $free  = (int) $rc['disk_free_bytes'];
    if ($total <= 0) continue;
    $storageStats[] = [
        'label'        => "Remote storage \"{$rc['name']}\"",
        'detail'       => "{$rc['remote_user']}@{$rc['remote_host']}",
        'total_bytes'  => $total,
        'free_bytes'   => $free,
        'used_percent' => round((($total - $free) / $total) * 100, 1),
    ];
}

// Evaluate each active user's threshold against the collected stats.
$users = $db->fetchAll("SELECT id, storage_alert_mode, storage_alert_value FROM users WHERE storage_alert_mode != 'disabled'");
foreach ($users as $u) {
    $mode  = $u['storage_alert_mode'];
    $value = (int) $u['storage_alert_value'];
    $userId = (int) $u['id'];
    $anyLow = false;

    foreach ($storageStats as $st) {
        $triggered = false;
        $suffix    = '';
        if ($mode === 'percent') {
            if ($st['used_percent'] >= $value) {
                $triggered = true;
                $suffix = "{$st['used_percent']}% used";
            }
        } elseif ($mode === 'gb_free') {
            $freeGb = round($st['free_bytes'] / 1073741824, 1);
            if ($freeGb <= $value) {
                $triggered = true;
                $suffix = "{$freeGb} GB free";
            }
        }
        if (!$triggered) continue;

        $msg = "{$st['label']} is low on space ({$suffix}) — {$st['detail']}";
        $notificationService->notify('storage_low', null, null, $msg, 'warning', $userId);
        $anyLow = true;
    }

    if (!$anyLow) {
        $notificationService->resolve('storage_low', null, null, $userId);
    }
}

// Step 7: Cleanup old resolved notifications and server logs
$notificationService->cleanup();

// Purge server_log entries older than 30 days
$purged = $db->delete('server_log', 'created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)');
if ($purged > 0) {
    echo date('Y-m-d H:i:s') . " Purged {$purged} server log entries older than 30 days\n";
}

// Step 7: Check for updates (hourly)
$lastCheck = $db->fetchOne("SELECT `value` FROM settings WHERE `key` = 'last_update_check'");
$lastCheckTime = $lastCheck['value'] ?? null;
if (!$lastCheckTime || strtotime($lastCheckTime) < time() - 3600) {
    $updateService = new UpdateService();
    $result = $updateService->checkForUpdate();
    if (isset($result['update_available']) && $result['update_available']) {
        echo date('Y-m-d H:i:s') . " Update available: v{$result['version']} (current: v{$result['current']})\n";
    }
}

// Step 8: Sync available borg versions from GitHub (daily)
$lastBorgCheck = $db->fetchOne("SELECT `value` FROM settings WHERE `key` = 'last_borg_version_check'");
$lastBorgCheckTime = $lastBorgCheck['value'] ?? null;
if (!$lastBorgCheckTime || strtotime($lastBorgCheckTime) < time() - 86400) {
    $borgVersionService = new \BBS\Services\BorgVersionService();
    $syncResult = $borgVersionService->syncVersionsFromGitHub();
    if (isset($syncResult['added'])) {
        echo date('Y-m-d H:i:s') . " Borg version sync: {$syncResult['added']} new versions added\n";
    } elseif (isset($syncResult['error'])) {
        echo date('Y-m-d H:i:s') . " Borg version sync failed: {$syncResult['error']}\n";
    }
}

// Step 9: Clean up old backup jobs (daily, keep 30 days)
$lastJobCleanup = $db->fetchOne("SELECT `value` FROM settings WHERE `key` = 'last_job_cleanup'");
$lastJobCleanupTime = $lastJobCleanup['value'] ?? null;
if (!$lastJobCleanupTime || strtotime($lastJobCleanupTime) < time() - 86400) {
    $cutoffDate = date('Y-m-d H:i:s', time() - 30 * 86400);

    // Delete related server_log entries first
    $db->query(
        "DELETE FROM server_log WHERE backup_job_id IN (
            SELECT id FROM backup_jobs
            WHERE status IN ('completed', 'failed', 'cancelled')
              AND COALESCE(completed_at, queued_at) < ?
        )",
        [$cutoffDate]
    );

    // Delete old completed/failed/cancelled jobs
    $deleted = $db->query(
        "DELETE FROM backup_jobs
         WHERE status IN ('completed', 'failed', 'cancelled')
           AND COALESCE(completed_at, queued_at) < ?",
        [$cutoffDate]
    );

    $count = $deleted->rowCount();
    if ($count > 0) {
        echo date('Y-m-d H:i:s') . " Job cleanup: removed {$count} jobs older than 30 days\n";
    }

    $db->query(
        "INSERT INTO settings (`key`, `value`) VALUES ('last_job_cleanup', ?)
         ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)",
        [date('Y-m-d H:i:s')]
    );
}

// Step 10: Generate daily backup report (once per calendar day)
// Check if a report already exists for today's date — prevents duplicate generation
// regardless of what time zone or hour the scheduler runs.
// Regenerate today's report every run so counts stay current
// (the report is emailed at each user's preferred hour — it should reflect
// all backups completed so far, not just the ones before midnight)
$todayDate = date('Y-m-d');
try {
    $reportService = new \BBS\Services\ReportService();
    $report = $reportService->generate($todayDate);
    $reportService->cleanup();
} catch (\Exception $e) {
    echo date('Y-m-d H:i:s') . " Daily report error: {$e->getMessage()}\n";
}

// Step 10b: Email report to subscribers at their preferred local hour/frequency
$subscribers = $db->fetchAll(
    "SELECT id, email, timezone, daily_report_hour, report_frequency, report_day FROM users WHERE daily_report_email = 1 AND email != ''"
);
if (!empty($subscribers)) {
    $todayReport = $db->fetchOne("SELECT id FROM daily_reports WHERE report_date = CURDATE() ORDER BY created_at DESC LIMIT 1");
    if ($todayReport) {
        $reportService = $reportService ?? new \BBS\Services\ReportService();
        foreach ($subscribers as $sub) {
            // Check if current time matches the user's preferred hour in their timezone
            try {
                $userNow = new \DateTime('now', new \DateTimeZone($sub['timezone'] ?: 'UTC'));
            } catch (\Exception $e) {
                $userNow = new \DateTime('now', new \DateTimeZone('UTC'));
            }
            $userHour = (int) $userNow->format('G');
            if ($userHour !== (int) $sub['daily_report_hour']) {
                continue;
            }

            // Weekly subscribers only receive on their chosen day (0=Sun, 6=Sat)
            $frequency = $sub['report_frequency'] ?? 'daily';
            if ($frequency === 'weekly') {
                $userDow = (int) $userNow->format('w'); // 0=Sunday
                if ($userDow !== (int) ($sub['report_day'] ?? 1)) {
                    continue;
                }
            }

            // Dedup: only email once per user per calendar day (in their timezone)
            $userDate = $userNow->format('Y-m-d');
            $dedupKey = 'last_report_email_user_' . $sub['id'];
            $lastSent = $db->fetchOne("SELECT `value` FROM settings WHERE `key` = ?", [$dedupKey]);
            if (($lastSent['value'] ?? '') === $userDate) {
                continue;
            }
            try {
                $reportService->emailReport((int) $todayReport['id'], (int) $sub['id']);
                $freqLabel = $frequency === 'weekly' ? 'weekly' : 'daily';
                echo date('Y-m-d H:i:s') . " Emailed {$freqLabel} report to {$sub['email']}\n";
                $db->query(
                    "INSERT INTO settings (`key`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = ?",
                    [$dedupKey, $userDate, $userDate]
                );
            } catch (\Exception $e) {
                echo date('Y-m-d H:i:s') . " Report email failed for {$sub['email']}: {$e->getMessage()}\n";
            }
        }
    }
}

// Step 11: Daily BBS self-backup
$selfBackupEnabled = $db->fetchOne("SELECT `value` FROM settings WHERE `key` = 'self_backup_enabled'");
if (($selfBackupEnabled['value'] ?? '1') === '1') {
    $lastSelfBackup = $db->fetchOne("SELECT `value` FROM settings WHERE `key` = 'last_self_backup'");
    $lastSelfBackupTime = $lastSelfBackup['value'] ?? null;
    if (!$lastSelfBackupTime || strtotime($lastSelfBackupTime) < time() - 86400) {
        $helper = '/usr/local/bin/bbs-ssh-helper';
        if (is_file($helper)) {
            // Build backup command with user-configured options
            $backupArgs = '';
            $selfBackupCatalogs = $db->fetchOne("SELECT `value` FROM settings WHERE `key` = 'self_backup_catalogs'");
            if (($selfBackupCatalogs['value'] ?? '0') === '1') {
                $backupArgs .= ' --with-catalogs';
            }
            $selfBackupRetention = $db->fetchOne("SELECT `value` FROM settings WHERE `key` = 'self_backup_retention'");
            $keepCount = (int)($selfBackupRetention['value'] ?? '7');
            if ($keepCount < 1) $keepCount = 1;
            $backupArgs .= ' --keep ' . $keepCount;

            $output = shell_exec("sudo $helper server-backup$backupArgs 2>&1");
            if (str_contains($output ?? '', 'OK')) {
                echo date('Y-m-d H:i:s') . " Self-backup completed\n";
            } else {
                echo date('Y-m-d H:i:s') . " Self-backup failed: " . trim($output ?? '') . "\n";
            }
        }

        $db->query(
            "INSERT INTO settings (`key`, `value`) VALUES ('last_self_backup', ?)
             ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)",
            [date('Y-m-d H:i:s')]
        );

        // Sync server backups to S3 if enabled
        $syncEnabled = $db->fetchOne("SELECT `value` FROM settings WHERE `key` = 's3_sync_server_backups'");
        if (($syncEnabled['value'] ?? '0') === '1') {
            $s3Service = new \BBS\Services\S3SyncService();
            $creds = $s3Service->resolveCredentials(['credential_source' => 'global']);

            if (!empty($creds['bucket']) && $s3Service->isRcloneInstalled()) {
                $backupDir = '/var/bbs/backups';
                $prefix = trim($creds['path_prefix'], '/');
                $remotePath = $prefix ? "{$prefix}/_server-backups" : '_server-backups';
                $remote = "S3:{$creds['bucket']}/{$remotePath}/";

                // Use bbs-ssh-helper for S3 sync (runs as root with proper env)
                $cmd = "sudo $helper rclone-server-sync "
                     . escapeshellarg($backupDir) . " "
                     . escapeshellarg($remote) . " "
                     . escapeshellarg($creds['endpoint']) . " "
                     . escapeshellarg($creds['region']) . " "
                     . escapeshellarg($creds['access_key']) . " "
                     . escapeshellarg($creds['secret_key']) . " 2>&1";
                $syncOutput = shell_exec($cmd);

                if (str_contains($syncOutput ?? '', 'ERROR') && !str_contains($syncOutput ?? '', 'OK')) {
                    echo date('Y-m-d H:i:s') . " Server backup S3 sync failed: " . trim($syncOutput) . "\n";
                } else {
                    echo date('Y-m-d H:i:s') . " Server backups synced to S3\n";
                }
            }
        }
    }
}

// Step 11: Weekly auto-compact of all repositories (Saturday night at 2 AM)
// Jobs are queued sequentially and processed one at a time by the scheduler
$dayOfWeek = (int) date('w'); // 0=Sunday, 6=Saturday
$hourOfDay = (int) date('G'); // 0-23

// Check if it's Saturday (6) and within the 2 AM hour
if ($dayOfWeek === 6 && $hourOfDay === 2) {
    $lastAutoCompact = $db->fetchOne("SELECT `value` FROM settings WHERE `key` = 'last_auto_compact'");
    $lastAutoCompactTime = $lastAutoCompact['value'] ?? null;

    // Only run once per week (check if last run was more than 6 days ago)
    if (!$lastAutoCompactTime || strtotime($lastAutoCompactTime) < time() - (6 * 86400)) {
        // Get all repositories
        $repos = $db->fetchAll("SELECT r.id, r.name, r.agent_id FROM repositories r");
        $queued = 0;

        foreach ($repos as $repo) {
            // Check if there's already a pending compact job for this repo
            $existing = $db->fetchOne(
                "SELECT id FROM backup_jobs WHERE repository_id = ? AND task_type = 'compact' AND status IN ('queued', 'sent', 'running')",
                [$repo['id']]
            );
            if ($existing) {
                continue;
            }

            // Queue compact job
            $jobId = $db->insert('backup_jobs', [
                'agent_id' => $repo['agent_id'],
                'repository_id' => $repo['id'],
                'task_type' => 'compact',
                'status' => 'queued',
            ]);

            $db->insert('server_log', [
                'agent_id' => $repo['agent_id'],
                'backup_job_id' => $jobId,
                'level' => 'info',
                'message' => "Weekly auto-compact job #{$jobId} queued for repository \"{$repo['name']}\"",
            ]);

            $queued++;
        }

        if ($queued > 0) {
            echo date('Y-m-d H:i:s') . " Weekly auto-compact: queued {$queued} compact job(s)\n";
        }

        $db->query(
            "INSERT INTO settings (`key`, `value`) VALUES ('last_auto_compact', ?)
             ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)",
            [date('Y-m-d H:i:s')]
        );
    }
}

// Step 12: Auto-sync GitHub borg versions (daily, or if table is empty)
{
    $borgService = new \BBS\Services\BorgVersionService();
    $versionCount = $db->fetchOne("SELECT COUNT(*) as cnt FROM borg_versions");
    $lastGitHubSync = $db->fetchOne("SELECT `value` FROM settings WHERE `key` = 'last_borg_github_sync'");
    $lastSyncTime = $lastGitHubSync['value'] ?? null;

    // Sync if table is empty or last sync was more than 24 hours ago
    $needsSync = ($versionCount['cnt'] ?? 0) == 0 || !$lastSyncTime || strtotime($lastSyncTime) < time() - 86400;

    if ($needsSync) {
        try {
            $result = $borgService->syncVersionsFromGitHub();
            if ($result['added'] > 0) {
                echo date('Y-m-d H:i:s') . " GitHub sync: added {$result['added']} borg versions, skipped {$result['skipped']} pre-release\n";
            }
            $db->query(
                "INSERT INTO settings (`key`, `value`) VALUES ('last_borg_github_sync', ?)
                 ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)",
                [date('Y-m-d H:i:s')]
            );
        } catch (\Exception $e) {
            echo date('Y-m-d H:i:s') . " GitHub sync failed: {$e->getMessage()}\n";
        }
    }
}

// Step 13: Daily auto-update of borg (if enabled, at 3 AM)
if ($hourOfDay === 3) {
    $borgService = $borgService ?? new \BBS\Services\BorgVersionService();
    if ($borgService->isAutoUpdateEnabled()) {
        $lastBorgAutoUpdate = $db->fetchOne("SELECT `value` FROM settings WHERE `key` = 'last_borg_auto_update'");
        $lastBorgAutoUpdateTime = $lastBorgAutoUpdate['value'] ?? null;

        // Only run once per day
        if (!$lastBorgAutoUpdateTime || strtotime($lastBorgAutoUpdateTime) < time() - 82800) {
            $mode = $borgService->getUpdateMode();
            $queued = 0;
            $skipped = 0;

            // Update server first
            $serverResult = $borgService->updateServerBorgByMode();
            if ($serverResult['success']) {
                echo date('Y-m-d H:i:s') . " Auto-update: server borg updated to v{$serverResult['version']}\n";
            }

            // Queue updates for agents
            $agents = $borgService->getAllAgentVersions();
            $pending = $db->fetchAll(
                "SELECT agent_id FROM backup_jobs WHERE task_type = 'update_borg' AND status IN ('queued', 'sent', 'running')"
            );
            $pendingIds = array_column($pending, 'agent_id');

            $alreadyCurrent = 0;
            foreach ($agents as $agent) {
                if (in_array($agent['id'], $pendingIds)) {
                    continue;
                }

                // In server mode, skip incompatible agents
                if ($mode === 'server') {
                    $version = $borgService->getServerVersion();
                    if (!$borgService->isAgentCompatibleWithServerVersion($agent, $version)) {
                        $skipped++;
                        continue;
                    }
                }

                // Skip agents already on the target version (#174). The old
                // behavior queued every day for every client regardless of
                // whether an update was actually available, which re-installed
                // the same binary and spammed the log.
                $target = $borgService->getBestVersionForAgent($agent);
                $targetVersion = $target['version'] ?? null;
                $currentVersion = $agent['borg_version'] ?? null;

                // No versioned binary for this arch — only pip 'latest' is
                // available, and we can't tell whether an update is actually
                // needed. Arches without a working pip (armv7l, some BSDs)
                // would otherwise fail daily forever. Skip auto-queue; the
                // user can still trigger "Update Borg" manually. #187
                if (($target['source'] ?? '') === 'pip' && $targetVersion === 'latest') {
                    $alreadyCurrent++;
                    continue;
                }

                if (
                    $targetVersion
                    && $targetVersion !== 'latest'
                    && $currentVersion
                    && version_compare($currentVersion, $targetVersion, '>=')
                ) {
                    $alreadyCurrent++;
                    continue;
                }

                $jobId = $db->insert('backup_jobs', [
                    'agent_id' => $agent['id'],
                    'task_type' => 'update_borg',
                    'status' => 'queued',
                ]);
                $db->insert('server_log', [
                    'agent_id' => $agent['id'],
                    'backup_job_id' => $jobId,
                    'level' => 'info',
                    'message' => "Auto-update borg queued ({$mode} mode)",
                ]);
                $queued++;
            }

            if ($queued > 0 || $skipped > 0 || $alreadyCurrent > 0) {
                echo date('Y-m-d H:i:s') . " Auto-update: queued {$queued} borg update(s), skipped {$skipped} incompatible, {$alreadyCurrent} already current\n";
            }

            $db->query(
                "INSERT INTO settings (`key`, `value`) VALUES ('last_borg_auto_update', ?)
                 ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)",
                [date('Y-m-d H:i:s')]
            );
        }
    }
}

// Step 14: Auto-update agents when bundled version is newer than reported version
// Runs every scheduler tick but only queues once — skips agents that already have a pending update_agent job
{
    $agentFile = __DIR__ . '/agent/bbs-agent.py';
    $bundledAgentVersion = null;
    if (file_exists($agentFile)) {
        $fh = fopen($agentFile, 'r');
        if ($fh) {
            for ($i = 0; $i < 50 && ($line = fgets($fh)) !== false; $i++) {
                if (preg_match('/^AGENT_VERSION\s*=\s*["\']([^"\']+)["\']/m', $line, $m)) {
                    $bundledAgentVersion = $m[1];
                    break;
                }
            }
            fclose($fh);
        }
    }

    if ($bundledAgentVersion) {
        $outdatedAgents = $db->fetchAll(
            "SELECT id, name, agent_version FROM agents
             WHERE agent_version IS NOT NULL AND agent_version != ? AND status = 'online'",
            [$bundledAgentVersion]
        );

        if (!empty($outdatedAgents)) {
            $pending = $db->fetchAll(
                "SELECT agent_id FROM backup_jobs WHERE task_type = 'update_agent' AND status IN ('queued', 'sent', 'running')"
            );
            $pendingIds = array_column($pending, 'agent_id');

            $queued = 0;
            foreach ($outdatedAgents as $agent) {
                if (in_array($agent['id'], $pendingIds)) {
                    continue;
                }

                $jobId = $db->insert('backup_jobs', [
                    'agent_id' => $agent['id'],
                    'task_type' => 'update_agent',
                    'status' => 'queued',
                ]);
                $db->insert('server_log', [
                    'agent_id' => $agent['id'],
                    'backup_job_id' => $jobId,
                    'level' => 'info',
                    'message' => "Auto-update agent queued: v{$agent['agent_version']} → v{$bundledAgentVersion}",
                ]);
                $queued++;
            }

            if ($queued > 0) {
                echo date('Y-m-d H:i:s') . " Auto-update: queued agent updates for {$queued} client(s) to v{$bundledAgentVersion}\n";
            }
        }
    }
}

// Step 15: One-time migration — install SSH gate if missing
// The gate was introduced in a version where bbs-update split into two scripts.
// Old bbs-update (loaded in memory before git pull) never ran the new post-pull
// steps, so the gate may be missing after the first update. This detects that
// and runs the install via bbs-ssh-helper (which www-data has sudo access to).
if (!file_exists('/usr/local/bin/bbs-ssh-gate')) {
    $helper = '/usr/local/bin/bbs-ssh-helper';
    if (file_exists($helper)) {
        echo date('Y-m-d H:i:s') . " New updater detected — installing SSH gate and updating authorized_keys\n";
        $out1 = shell_exec("sudo {$helper} install-gate 2>&1");
        $out2 = shell_exec("sudo {$helper} update-all-keys 2>&1");
        echo $out1 . $out2;
        $db->insert('server_log', [
            'level' => 'info',
            'message' => 'SSH gate auto-installed by scheduler (post-update migration)',
        ]);
    }
}

// Step 16: Clean up orphaned temp files from catalog imports
// Files are created in /tmp by CatalogImporter, scheduler, and AgentApiController.
// If a process crashes before cleanup, they persist. We evict files older than 4 hours
// that are not actively being written to (check if mtime is still advancing).
$tmpDir = sys_get_temp_dir();
$maxAge = 4 * 3600; // 4 hours
$patterns = ['catalog_*.tsv', 'catalog_dirs_*.tsv', 'catalog_api_*.tsv',
             'catalog_dirs_api_*.tsv', 'catalog_rebuild_*.tsv',
             'bbs-manifest-*', 's3_import_catalog_*.tsv'];
$cleaned = 0;
foreach ($patterns as $pattern) {
    foreach (glob("{$tmpDir}/{$pattern}") as $file) {
        if (!is_file($file)) continue;
        $mtime = filemtime($file);
        if ($mtime === false || (time() - $mtime) < $maxAge) continue;
        // File hasn't been modified in 4+ hours — safe to remove
        @unlink($file);
        $cleaned++;
    }
}
if ($cleaned > 0) {
    echo date('Y-m-d H:i:s') . " Cleaned up {$cleaned} orphaned temp file(s)\n";
}

// Step 16b: Clean up imported catalog log files from .catalog-logs directories
// These are written by the agent via SSH and should be deleted after import,
// but the unlink may fail if directory permissions haven't been updated yet.
$agentHomeDirs = $db->fetchAll("SELECT DISTINCT ssh_home_dir FROM agents WHERE ssh_home_dir IS NOT NULL AND ssh_home_dir != ''");
$catalogCleaned = 0;
foreach ($agentHomeDirs as $ahd) {
    foreach (glob($ahd['ssh_home_dir'] . '/.catalog-logs/catalog-*.jsonl') as $catFile) {
        // Extract job ID from filename (catalog-{jobId}.jsonl)
        if (preg_match('/catalog-(\d+)\.jsonl$/', $catFile, $m)) {
            $catJobId = (int) $m[1];
            $catJob = $db->fetchOne(
                "SELECT status FROM backup_jobs WHERE id = ? AND status IN ('completed', 'failed')",
                [$catJobId]
            );
            if ($catJob) {
                @unlink($catFile);
                $catalogCleaned++;
            }
        }
    }
}
if ($catalogCleaned > 0) {
    echo date('Y-m-d H:i:s') . " Cleaned up {$catalogCleaned} imported catalog log file(s)\n";
}

// Step 10: Prune old server_log and backup_jobs entries
// Run once per hour (minute 30) to avoid running on every scheduler tick
if ((int) date('i') === 30) {
    $logDeleted = $db->delete('server_log', 'created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)');
    if ($logDeleted > 0) {
        echo date('Y-m-d H:i:s') . " Pruned {$logDeleted} server_log entries older than 30 days\n";
    }

    $jobsDeleted = $db->delete('backup_jobs', "status IN ('completed', 'failed', 'cancelled') AND completed_at < DATE_SUB(NOW(), INTERVAL 90 DAY)");
    if ($jobsDeleted > 0) {
        echo date('Y-m-d H:i:s') . " Pruned {$jobsDeleted} backup_jobs older than 90 days\n";
    }

    // Prune orphaned PHP session files. On Docker installs where PHP writes
    // sessions into $TMPDIR (/var/bbs/tmp), there's no systemd timer or cron
    // to clean them up, so files accumulate unboundedly.
    $sessionDirs = array_unique(array_filter([
        ini_get('session.save_path') ?: null,
        sys_get_temp_dir(),
        '/var/bbs/tmp',
        '/var/lib/php/sessions',
    ]));
    $cutoff = time() - (30 * 86400);
    $sessionDeleted = 0;
    foreach ($sessionDirs as $dir) {
        if (!is_dir($dir)) continue;
        foreach (glob($dir . '/sess_*') ?: [] as $file) {
            if (is_file($file) && @filemtime($file) < $cutoff) {
                if (@unlink($file)) $sessionDeleted++;
            }
        }
    }
    if ($sessionDeleted > 0) {
        echo date('Y-m-d H:i:s') . " Pruned {$sessionDeleted} PHP session files older than 30 days\n";
    }
}
