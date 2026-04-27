<?php

namespace BBS\Controllers\Api;

use BBS\Core\Controller;
use BBS\Services\QueueManager;
use BBS\Services\BorgCommandBuilder;
use BBS\Services\Mailer;
use BBS\Services\NotificationService;
use BBS\Services\CatalogImporter;
use BBS\Services\SshKeyManager;

class AgentApiController extends Controller
{
    /**
     * Authenticate the agent via Bearer token.
     * Returns the agent record or sends 401 and exits.
     */
    private function authenticateAgent(): array
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        $token = '';

        if (str_starts_with($header, 'Bearer ')) {
            $token = substr($header, 7);
        }

        if (empty($token)) {
            $this->json(['error' => 'Missing authorization token'], 401);
        }

        $tokenHash = hash('sha256', $token);
        $agent = $this->db->fetchOne("SELECT * FROM agents WHERE api_key_hash = ?", [$tokenHash]);

        // Legacy fallback + transparent upgrade for records that haven't been
        // migrated yet. Once migrated, plaintext is cleared.
        if (!$agent) {
            $legacy = $this->db->fetchOne("SELECT * FROM agents WHERE api_key = ?", [$token]);
            if ($legacy) {
                try {
                    $this->db->update('agents', [
                        'api_key_hash' => $tokenHash,
                        'api_key_encrypted' => \BBS\Services\Encryption::encrypt($token),
                        'api_key' => null,
                    ], 'id = ?', [$legacy['id']]);
                    $this->db->insert('server_log', [
                        'agent_id' => $legacy['id'],
                        'level' => 'info',
                        'message' => 'Agent credential storage upgraded (security hardening).',
                    ]);
                    $legacy['api_key_hash'] = $tokenHash;
                    $legacy['api_key'] = null;
                } catch (\Throwable $e) {
                    // Non-fatal — continue with the legacy record this request.
                }
                $agent = $legacy;
            }
        }

        if (!$agent) {
            if (!$this->checkRateLimit('agent_api', 20, 300)) {
                $this->json(['error' => 'Too many failed attempts'], 429);
            }
            $this->json(['error' => 'Invalid API key'], 401);
        }

        // Update heartbeat on every authenticated request (use PHP date() to match app timezone)
        $this->db->query(
            "UPDATE agents SET last_heartbeat = ?, status = 'online' WHERE id = ?",
            [date('Y-m-d H:i:s'), $agent['id']]
        );

        // Auto-resolve agent_offline notification on heartbeat
        $notifService = new NotificationService();

        // Check if there was an unresolved agent_offline notification (means agent was offline)
        $wasOffline = $this->db->fetchOne(
            "SELECT id FROM notifications WHERE type = 'agent_offline' AND agent_id = ? AND resolved_at IS NULL",
            [$agent['id']]
        );

        $notifService->resolve('agent_offline', $agent['id'], null);

        // If agent was offline and is now back online, send agent_online notification
        if ($wasOffline) {
            $notifService->notify(
                'agent_online',
                $agent['id'],
                null,
                "Client \"{$agent['name']}\" is back online",
                'info'
            );
        }

        return $agent;
    }

    /**
     * POST /api/agent/register
     * One-time registration: agent sends its hostname, OS, borg version.
     */
    public function register(): void
    {
        $agent = $this->authenticateAgent();
        $input = $this->getJsonInput();

        $data = [];
        if (!empty($input['hostname']))             $data['hostname'] = substr($input['hostname'], 0, 255);
        if (!empty($input['ip_address']))           $data['ip_address'] = substr($input['ip_address'], 0, 45);
        if (!empty($input['os_info']))              $data['os_info'] = substr($input['os_info'], 0, 255);
        if (!empty($input['borg_version']))         $data['borg_version'] = substr($input['borg_version'], 0, 20);
        if (!empty($input['agent_version']))        $data['agent_version'] = substr($input['agent_version'], 0, 20);
        if (!empty($input['borg_install_method']))  $data['borg_install_method'] = substr($input['borg_install_method'], 0, 20);
        if (!empty($input['borg_binary_path']))     $data['borg_binary_path'] = substr($input['borg_binary_path'], 0, 255);
        if (!empty($input['glibc_version']))        $data['glibc_version'] = substr($input['glibc_version'], 0, 20);
        if (!empty($input['platform']))             $data['platform'] = substr($input['platform'], 0, 20);
        if (!empty($input['architecture']))         $data['architecture'] = substr($input['architecture'], 0, 20);
        $data['status'] = 'online';

        if (!empty($data)) {
            $this->db->update('agents', $data, 'id = ?', [$agent['id']]);
        }

        $this->db->insert('server_log', [
            'agent_id' => $agent['id'],
            'level' => 'info',
            'message' => "Agent registered: " . ($input['hostname'] ?? $agent['name']),
        ]);

        // Return server config the agent needs
        $pollInterval = $this->db->fetchOne("SELECT `value` FROM settings WHERE `key` = 'agent_poll_interval'");
        $serverHost = $this->db->fetchOne("SELECT `value` FROM settings WHERE `key` = 'server_host'");
        $sshPort = $this->db->fetchOne("SELECT `value` FROM settings WHERE `key` = 'ssh_port'");

        // Per-agent overrides take precedence over global settings
        $host = !empty($agent['server_host_override']) ? $agent['server_host_override'] : SshKeyManager::stripHostPort($serverHost['value'] ?? '');
        $port = !empty($agent['ssh_port_override']) ? (int) $agent['ssh_port_override'] : (int) ($sshPort['value'] ?? 22);

        $this->json([
            'status' => 'ok',
            'agent_id' => $agent['id'],
            'name' => $agent['name'],
            'poll_interval' => (int) ($pollInterval['value'] ?? 30),
            'ssh_unix_user' => $agent['ssh_unix_user'] ?? '',
            'server_host' => $host,
            'ssh_port' => $port,
        ]);
    }

    /**
     * GET /api/agent/tasks
     * Agent polls for pending tasks.
     */
    public function tasks(): void
    {
        $agent = $this->authenticateAgent();

        // Auto-queue borg update if agent is outdated and a target version is set
        $this->autoQueueBorgUpdate($agent);

        // First, run the queue manager to promote any queued jobs
        $queueManager = new QueueManager();
        $queueManager->processQueue();

        // Get tasks assigned to this agent
        $tasks = $queueManager->getTasksForAgent($agent['id']);

        $pollInterval = $this->db->fetchOne("SELECT `value` FROM settings WHERE `key` = 'agent_poll_interval'");

        // Detect stalled jobs: running/sent with no recent progress from this agent
        // Exclude server-side task types — those run in the scheduler, not on the agent
        // Exclude jobs being delivered in THIS response (race condition: agent would
        // report "not running" for a task it literally just received)
        $deliveredJobIds = array_map(fn($t) => (int) ($t['job_id'] ?? 0), $tasks);
        $excludeClause = '';
        $stalledParams = [$agent['id']];
        if (!empty($deliveredJobIds)) {
            $placeholders = implode(',', array_fill(0, count($deliveredJobIds), '?'));
            $excludeClause = "AND bj.id NOT IN ({$placeholders})";
            $stalledParams = array_merge($stalledParams, $deliveredJobIds);
        }
        $stalledJobs = $this->db->fetchAll("
            SELECT bj.id FROM backup_jobs bj
            WHERE bj.agent_id = ?
              AND bj.status IN ('running', 'sent')
              AND bj.task_type NOT IN ('prune', 'compact', 's3_sync', 's3_restore', 'repo_check', 'repo_repair', 'break_lock', 'catalog_sync', 'catalog_rebuild', 'catalog_rebuild_full')
              {$excludeClause}
              AND (
                  (bj.last_progress_at IS NOT NULL AND bj.last_progress_at < DATE_SUB(NOW(), INTERVAL 10 MINUTE))
                  OR
                  (bj.last_progress_at IS NULL AND bj.started_at IS NOT NULL AND bj.started_at < DATE_SUB(NOW(), INTERVAL 30 MINUTE))
                  OR
                  (bj.last_progress_at IS NULL AND bj.started_at IS NULL AND bj.queued_at < DATE_SUB(NOW(), INTERVAL 30 MINUTE))
              )
        ", $stalledParams);

        $response = [
            'status' => 'ok',
            'tasks' => $tasks,
            'poll_interval' => (int) ($pollInterval['value'] ?? 30),
        ];

        if (!empty($stalledJobs)) {
            $response['check_jobs'] = array_map(fn($j) => (int) $j['id'], $stalledJobs);
        }

        $this->json($response);
    }

    /**
     * POST /api/agent/progress
     * Agent reports in-progress updates for a running job.
     */
    public function progress(): void
    {
        $agent = $this->authenticateAgent();
        $input = $this->getJsonInput();

        $jobId = (int) ($input['job_id'] ?? 0);
        if (!$jobId) {
            $this->json(['error' => 'job_id required'], 400);
        }

        // Verify job belongs to this agent
        $job = $this->db->fetchOne(
            "SELECT * FROM backup_jobs WHERE id = ? AND agent_id = ?",
            [$jobId, $agent['id']]
        );

        if (!$job) {
            $this->json(['error' => 'Job not found'], 404);
        }

        // Don't overwrite terminal statuses (completed/failed) with 'running'
        if (in_array($job['status'], ['completed', 'failed', 'cancelled'])) {
            $this->json(['status' => 'ok', 'cancel' => ($job['status'] === 'cancelled')]);
        }

        $data = ['status' => 'running', 'last_progress_at' => $this->db->now()];
        if (isset($input['files_total']))      $data['files_total'] = (int) $input['files_total'];
        if (isset($input['files_processed']))  $data['files_processed'] = (int) $input['files_processed'];
        if (isset($input['bytes_total']))      $data['bytes_total'] = (int) $input['bytes_total'];
        if (isset($input['bytes_processed']))  $data['bytes_processed'] = (int) $input['bytes_processed'];
        if (isset($input['status_message']))   $data['status_message'] = substr($input['status_message'], 0, 255);

        if (empty($job['started_at'])) {
            $data['started_at'] = date('Y-m-d H:i:s');

            // Log that the agent started working on this job
            $planName = '';
            if ($job['backup_plan_id']) {
                $plan = $this->db->fetchOne("SELECT name FROM backup_plans WHERE id = ?", [$job['backup_plan_id']]);
                $planName = $plan['name'] ?? '';
            }
            $this->db->insert('server_log', [
                'agent_id' => $agent['id'],
                'backup_job_id' => $jobId,
                'level' => 'info',
                'message' => "Agent started {$job['task_type']} job #{$jobId}" . ($planName ? " for plan \"{$planName}\"" : ''),
            ]);
        }

        $this->db->update('backup_jobs', $data, 'id = ?', [$jobId]);

        // Allow agent to send log messages (e.g. plugin activity)
        if (!empty($input['log_message'])) {
            $this->db->insert('server_log', [
                'agent_id' => $agent['id'],
                'backup_job_id' => $jobId,
                'level' => $input['log_level'] ?? 'info',
                'message' => substr($input['log_message'], 0, 2000),
            ]);
        }

        $this->json(['status' => 'ok', 'cancel' => false]);
    }

    /**
     * POST /api/agent/status
     * Agent reports task completion or failure.
     */
    public function status(): void
    {
        $agent = $this->authenticateAgent();
        $input = $this->getJsonInput();

        $jobId = (int) ($input['job_id'] ?? 0);
        $result = $input['result'] ?? '';  // 'completed', 'failed', or 'cataloging'

        if (!$jobId || !in_array($result, ['completed', 'failed', 'cataloging', 'abandoned'])) {
            $this->json(['error' => 'job_id and result (completed/failed/cataloging/abandoned) required'], 400);
        }

        $job = $this->db->fetchOne(
            "SELECT * FROM backup_jobs WHERE id = ? AND agent_id = ?",
            [$jobId, $agent['id']]
        );

        if (!$job) {
            $this->json(['error' => 'Job not found'], 404);
        }

        // Idempotency: if job is already in a terminal state, return OK without
        // re-processing (prevents duplicate archives, notifications on retry)
        if (in_array($job['status'], ['completed', 'failed', 'cancelled'])) {
            $archiveId = null;
            if ($job['task_type'] === 'backup') {
                $archive = $this->db->fetchOne(
                    "SELECT id FROM archives WHERE backup_job_id = ?",
                    [$jobId]
                );
                $archiveId = $archive ? (int) $archive['id'] : null;
            }
            $this->json(['status' => 'ok', 'already_terminal' => true, 'archive_id' => $archiveId]);
        }

        // Handle "abandoned" — agent confirms it's no longer running this job
        // (typically means the original completion report was lost due to server error)
        if ($result === 'abandoned') {
            $this->db->update('backup_jobs', [
                'status' => 'failed',
                'completed_at' => date('Y-m-d H:i:s'),
                'error_log' => 'Job abandoned — agent confirmed it is no longer running this task (status report likely lost)',
            ], 'id = ?', [$jobId]);

            $taskLabel = ucfirst(str_replace('_', ' ', $job['task_type']));
            $this->db->insert('server_log', [
                'agent_id' => $agent['id'],
                'backup_job_id' => $jobId,
                'level' => 'error',
                'message' => "{$taskLabel} job #{$jobId} abandoned — agent confirmed not running (stall detected)",
            ]);

            if ($job['task_type'] === 'backup' && $job['backup_plan_id']) {
                $notificationService = new NotificationService();
                $plan = $this->db->fetchOne("SELECT name FROM backup_plans WHERE id = ?", [$job['backup_plan_id']]);
                $planName = $plan['name'] ?? '';
                $notificationService->notify(
                    'backup_failed',
                    $agent['id'],
                    (int)$job['backup_plan_id'],
                    "Backup failed for plan \"{$planName}\" on client \"{$agent['name']}\" — job stalled (status report lost)",
                    'critical'
                );
            }

            $this->json(['status' => 'ok']);
        }

        $now = $this->db->now();
        $startedAt = $job['started_at'] ?? $job['queued_at'] ?? $now;
        // Calculate duration in MySQL to avoid PHP/MySQL timezone mismatches
        $durRow = $this->db->fetchOne(
            "SELECT TIMESTAMPDIFF(SECOND, ?, NOW()) as dur",
            [$startedAt]
        );
        $duration = (int) ($durRow['dur'] ?? 0);

        // "cataloging" means borg finished but agent is about to upload catalog —
        // keep job as "running" so it doesn't appear completed prematurely
        $isCataloging = ($result === 'cataloging');

        // Check if a catalog file exists that needs importing — if so, defer
        // marking the job as completed until after the import finishes
        $hasPendingCatalog = false;
        if ($result === 'completed' && $job['task_type'] === 'backup') {
            if (!empty($agent['ssh_home_dir'])) {
                $cp = $agent['ssh_home_dir'] . '/.catalog-logs/catalog-' . $jobId . '.jsonl';
                $hasPendingCatalog = file_exists($cp) && filesize($cp) > 0;
            }
        }

        $data = [
            'status' => ($isCataloging || $hasPendingCatalog) ? 'running' : $result,
            'last_progress_at' => $now,
        ];

        if ($hasPendingCatalog) {
            $data['status_message'] = 'Importing file catalog...';
        } elseif (!$isCataloging) {
            $data['completed_at'] = $now;
            $data['duration_seconds'] = max(0, $duration);
            $data['status_message'] = null;
        }

        // If the agent never reported "running", backfill started_at
        if (empty($job['started_at'])) {
            $data['started_at'] = $now;
        }

        if (isset($input['files_total']))     $data['files_total'] = (int) $input['files_total'];
        if (isset($input['files_processed'])) $data['files_processed'] = (int) $input['files_processed'];
        if (isset($input['bytes_total']))     $data['bytes_total'] = (int) $input['bytes_total'];
        if (isset($input['bytes_processed'])) $data['bytes_processed'] = (int) $input['bytes_processed'];
        if (!empty($input['error_log']))      $data['error_log'] = $input['error_log'];
        if (!empty($input['had_warnings']))   $data['had_warnings'] = 1;

        $this->db->update('backup_jobs', $data, 'id = ?', [$jobId]);

        $taskLabel = ucfirst(str_replace('_', ' ', $job['task_type']));

        // For "cataloging", create the archive and return archive_id but skip
        // notifications, prune, and completion logging
        if ($isCataloging && $job['task_type'] === 'backup' && !empty($input['archive_name'])) {
            $archiveData = [
                'repository_id' => $job['repository_id'],
                'backup_job_id' => $jobId,
                'archive_name' => $input['archive_name'],
                'file_count' => (int) ($input['files_total'] ?? 0),
                'original_size' => (int) ($input['original_size'] ?? 0),
                'deduplicated_size' => (int) ($input['deduplicated_size'] ?? 0),
            ];

            if (!empty($input['databases_backed_up'])) {
                $archiveData['databases_backed_up'] = json_encode($input['databases_backed_up']);
            }

            $this->db->insert('archives', $archiveData);

            $origSize = $this->formatBytesLog((int)($input['original_size'] ?? 0));
            $dedupSize = $this->formatBytesLog((int)($input['deduplicated_size'] ?? 0));
            $this->db->insert('server_log', [
                'agent_id' => $agent['id'],
                'backup_job_id' => $jobId,
                'level' => 'info',
                'message' => "Archive created: \"{$input['archive_name']}\" — {$origSize} original, {$dedupSize} deduplicated",
            ]);

            // Update archive count + borg version. Size is refreshed below
            // via RepositorySizeService (du for local, SUM for remote SSH) —
            // runs once per backup instead of a periodic scan, so idle disks
            // stay idle.
            $borgVer = !empty($agent['borg_version']) ? preg_replace('/^borg\s+/', '', $agent['borg_version']) : null;
            $this->db->query("
                UPDATE repositories SET
                    archive_count = (SELECT COUNT(*) FROM archives WHERE repository_id = ?)
                    " . ($borgVer ? ", borg_version_last = ?" : "") . "
                WHERE id = ?
            ", $borgVer
                ? [$job['repository_id'], $borgVer, $job['repository_id']]
                : [$job['repository_id'], $job['repository_id']]
            );
            \BBS\Services\RepositorySizeService::refresh((int) $job['repository_id']);

            $this->db->insert('server_log', [
                'agent_id' => $agent['id'],
                'backup_job_id' => $jobId,
                'level' => 'info',
                'message' => "{$taskLabel} finished, uploading file catalog...",
            ]);

            $archive = $this->db->fetchOne(
                "SELECT id FROM archives WHERE backup_job_id = ?",
                [$jobId]
            );

            $this->json(['status' => 'ok', 'archive_id' => $archive ? (int) $archive['id'] : null]);
            return;
        }

        // For cataloging without archive data, just acknowledge
        if ($isCataloging) {
            $this->json(['status' => 'ok', 'archive_id' => null]);
            return;
        }

        // --- Normal completed/failed flow below ---

        // Log the result
        $level = $result === 'completed' ? 'info' : 'error';
        $message = $result === 'completed'
            ? "{$taskLabel} completed: job #{$jobId}" . (($data['files_total'] ?? 0) > 0 ? ", {$data['files_total']} files" : '') . ", " . $this->formatDuration($duration)
            : "{$taskLabel} failed: job #{$jobId} — " . ($input['error_log'] ?? 'unknown error');

        $this->db->insert('server_log', [
            'agent_id' => $agent['id'],
            'backup_job_id' => $jobId,
            'level' => $level,
            'message' => $message,
        ]);

        // Log output from tasks like update_borg
        if (!empty($input['output_log'])) {
            $this->db->insert('server_log', [
                'agent_id' => $agent['id'],
                'backup_job_id' => $jobId,
                'level' => 'info',
                'message' => "{$taskLabel} output: " . substr($input['output_log'], 0, 2000),
            ]);
        }

        // Notification system: task-based notifications
        $notificationService = new NotificationService();
        $planName = '';
        if ($job['backup_plan_id']) {
            $plan = $this->db->fetchOne("SELECT name FROM backup_plans WHERE id = ?", [$job['backup_plan_id']]);
            $planName = $plan['name'] ?? '';
        }

        // Handle notifications by task type
        switch ($job['task_type']) {
            case 'backup':
                if ($result === 'failed') {
                    $notificationService->notify(
                        'backup_failed',
                        $agent['id'],
                        $job['backup_plan_id'] ? (int)$job['backup_plan_id'] : null,
                        "Backup failed for plan \"{$planName}\" on client \"{$agent['name']}\" — " . ($input['error_log'] ?? 'unknown error'),
                        'critical'
                    );
                } elseif ($result === 'completed' && !empty($input['had_warnings'])) {
                    // borg returned a warning — most commonly a configured
                    // source path that didn't exist (#203). Resolve any
                    // prior backup_failed and fire a warning-level event so
                    // the user actually sees this instead of a silent green.
                    if ($job['backup_plan_id']) {
                        $notificationService->resolve('backup_failed', $agent['id'], (int)$job['backup_plan_id']);
                    }
                    $warningTail = !empty($input['error_log'])
                        ? ' — ' . trim(substr($input['error_log'], 0, 300))
                        : '';
                    $notificationService->notify(
                        'backup_warning',
                        $agent['id'],
                        $job['backup_plan_id'] ? (int)$job['backup_plan_id'] : null,
                        "Backup completed with warnings for plan \"{$planName}\" on client \"{$agent['name']}\"{$warningTail}",
                        'warning'
                    );
                } elseif ($result === 'completed' && $job['backup_plan_id']) {
                    $notificationService->resolve('backup_failed', $agent['id'], (int)$job['backup_plan_id']);
                    $notificationService->resolve('backup_warning', $agent['id'], (int)$job['backup_plan_id']);
                    $notificationService->notify(
                        'backup_completed',
                        $agent['id'],
                        (int)$job['backup_plan_id'],
                        "Backup completed for plan \"{$planName}\" on client \"{$agent['name']}\"" .
                            (($data['files_total'] ?? 0) > 0 ? " — {$data['files_total']} files in " . $this->formatDuration($duration) : ''),
                        'info'
                    );
                }
                break;

            case 'restore':
                if ($result === 'failed') {
                    $notificationService->notify(
                        'restore_failed',
                        $agent['id'],
                        $job['backup_plan_id'] ? (int)$job['backup_plan_id'] : null,
                        "Restore failed on client \"{$agent['name']}\"" . ($planName ? " for plan \"{$planName}\"" : '') .
                            " — " . ($input['error_log'] ?? 'unknown error'),
                        'critical'
                    );
                } elseif ($result === 'completed') {
                    $notificationService->notify(
                        'restore_completed',
                        $agent['id'],
                        $job['backup_plan_id'] ? (int)$job['backup_plan_id'] : null,
                        "Restore completed on client \"{$agent['name']}\"" . ($planName ? " for plan \"{$planName}\"" : ''),
                        'info'
                    );
                }
                break;

            case 'check':
                if ($result === 'failed') {
                    $notificationService->notify(
                        'repo_check_failed',
                        $agent['id'],
                        $job['repository_id'] ? (int)$job['repository_id'] : null,
                        "Repository check failed on client \"{$agent['name']}\"" . ($planName ? " for plan \"{$planName}\"" : '') .
                            " — " . ($input['error_log'] ?? 'unknown error'),
                        'critical'
                    );
                }
                break;

            case 'compact':
                if ($result === 'completed') {
                    $notificationService->notify(
                        'repo_compact_done',
                        $agent['id'],
                        $job['repository_id'] ? (int)$job['repository_id'] : null,
                        "Repository compact completed on client \"{$agent['name']}\"" . ($planName ? " for plan \"{$planName}\"" : ''),
                        'info'
                    );
                }
                break;
        }

        // Email notification on failure
        if ($result === 'failed') {
            try {
                $mailer = new Mailer();
                $mailer->notifyFailure($agent['name'], $jobId, $input['error_log'] ?? 'Unknown error', $job['task_type']);
            } catch (\Exception $e) {
                // Don't fail the status report if email fails
            }
        }

        // If completed and it was a backup, create an archive record
        // (skip if archive was already created during "cataloging" phase)
        if ($result === 'completed' && $job['task_type'] === 'backup' && !empty($input['archive_name'])) {
            $existingArchive = $this->db->fetchOne(
                "SELECT id FROM archives WHERE backup_job_id = ?",
                [$jobId]
            );
            if (!$existingArchive) {
                $archiveData = [
                    'repository_id' => $job['repository_id'],
                    'backup_job_id' => $jobId,
                    'archive_name' => $input['archive_name'],
                    'file_count' => (int) ($input['files_total'] ?? 0),
                    'original_size' => (int) ($input['original_size'] ?? 0),
                    'deduplicated_size' => (int) ($input['deduplicated_size'] ?? 0),
                ];

                if (!empty($input['databases_backed_up'])) {
                    $archiveData['databases_backed_up'] = json_encode($input['databases_backed_up']);
                }

                $this->db->insert('archives', $archiveData);

                // Log archive creation
                $origSize = $this->formatBytesLog((int)($input['original_size'] ?? 0));
                $dedupSize = $this->formatBytesLog((int)($input['deduplicated_size'] ?? 0));
                $this->db->insert('server_log', [
                    'agent_id' => $agent['id'],
                    'backup_job_id' => $jobId,
                    'level' => 'info',
                    'message' => "Archive created: \"{$input['archive_name']}\" — {$origSize} original, {$dedupSize} deduplicated",
                ]);

                // Update archive count + borg version; size refreshed after (see above).
                $borgVer2 = !empty($agent['borg_version']) ? preg_replace('/^borg\s+/', '', $agent['borg_version']) : null;
                $this->db->query("
                    UPDATE repositories SET
                        archive_count = (SELECT COUNT(*) FROM archives WHERE repository_id = ?)
                        " . ($borgVer2 ? ", borg_version_last = ?" : "") . "
                    WHERE id = ?
                ", $borgVer2
                    ? [$job['repository_id'], $borgVer2, $job['repository_id']]
                    : [$job['repository_id'], $job['repository_id']]
                );
                \BBS\Services\RepositorySizeService::refresh((int) $job['repository_id']);

                $this->db->insert('server_log', [
                    'agent_id' => $agent['id'],
                    'backup_job_id' => $jobId,
                    'level' => 'info',
                    'message' => "Repository stats updated for job #{$jobId}",
                ]);
            }
        }

        // Build catalog import info for after response is sent
        $catalogImport = null;
        if ($result === 'completed' && $job['task_type'] === 'backup') {
            if (!empty($agent['ssh_home_dir'])) {
                $catalogPath = $agent['ssh_home_dir'] . '/.catalog-logs/catalog-' . $jobId . '.jsonl';
                if (file_exists($catalogPath)) {
                    $archive = $this->db->fetchOne(
                        "SELECT id FROM archives WHERE backup_job_id = ?",
                        [$jobId]
                    );
                    if ($archive) {
                        $catalogImport = [
                            'agent_id' => (int) $agent['id'],
                            'archive_id' => (int) $archive['id'],
                            'job_id' => $jobId,
                            'path' => $catalogPath,
                        ];
                    }
                }
            }
        }

        // Auto-prune is deferred until after catalog import (see below)
        $shouldAutoPrune = $result === 'completed' && $job['task_type'] === 'backup'
            && $job['backup_plan_id'] && $job['repository_id'];

        // Return archive_id so older agents can still send catalog after completion
        $archiveId = null;
        if ($result === 'completed' && $job['task_type'] === 'backup') {
            $archive = $this->db->fetchOne(
                "SELECT id FROM archives WHERE backup_job_id = ?",
                [$jobId]
            );
            $archiveId = $archive ? (int) $archive['id'] : null;
        }

        // Send response immediately so the agent isn't blocked
        http_response_code(200);
        header('Content-Type: application/json');
        echo json_encode(['status' => 'ok', 'archive_id' => $archiveId]);

        // Flush response to agent, then continue processing in background
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }

        // Import catalog file after response is sent (can take minutes for large catalogs)
        if ($catalogImport) {
            set_time_limit(0);
            $fileSize = filesize($catalogImport['path']);
            $fileSizeLabel = $fileSize > 1048576
                ? round($fileSize / 1048576, 1) . ' MB'
                : round($fileSize / 1024, 1) . ' KB';
            $this->db->insert('server_log', [
                'agent_id' => $catalogImport['agent_id'],
                'backup_job_id' => $catalogImport['job_id'],
                'level' => 'info',
                'message' => "Importing file catalog ({$fileSizeLabel})...",
            ]);
            try {
                $startTime = microtime(true);
                $importer = new CatalogImporter();
                $count = $importer->processFile(
                    $this->db, $catalogImport['agent_id'], $catalogImport['archive_id'], $catalogImport['path'], $catalogImport['job_id']
                );
                $elapsed = round(microtime(true) - $startTime, 1);
                $this->db->insert('server_log', [
                    'agent_id' => $catalogImport['agent_id'],
                    'backup_job_id' => $catalogImport['job_id'],
                    'level' => 'info',
                    'message' => "File catalog imported: " . number_format($count) . " entries in {$elapsed}s",
                ]);
            } catch (\Exception $e) {
                $diagInfo = '';
                $catPath = $catalogImport['path'];
                if (file_exists($catPath)) {
                    $perms = substr(sprintf('%o', fileperms($catPath)), -4);
                    $owner = function_exists('posix_getpwuid') ? (posix_getpwuid(fileowner($catPath))['name'] ?? fileowner($catPath)) : fileowner($catPath);
                    $group = function_exists('posix_getgrgid') ? (posix_getgrgid(filegroup($catPath))['name'] ?? filegroup($catPath)) : filegroup($catPath);
                    $size = filesize($catPath);
                    $diagInfo = " [file: {$perms} {$owner}:{$group} {$size}b, www-data readable: " . (is_readable($catPath) ? 'yes' : 'NO') . ']';
                } else {
                    $diagInfo = ' [file does not exist at import time]';
                }
                // Check if the gate's diagnostic log exists
                $diagLog = dirname($catPath) . '/.catalog-diag.log';
                $gateDiag = '';
                if (file_exists($diagLog)) {
                    $tail = file_get_contents($diagLog);
                    $lines = explode("\n", trim($tail));
                    $gateDiag = ' | gate-diag: ' . implode(' / ', array_slice($lines, -6));
                }
                $this->db->insert('server_log', [
                    'agent_id' => $catalogImport['agent_id'],
                    'backup_job_id' => $catalogImport['job_id'],
                    'level' => 'error',
                    'message' => "Catalog import failed: " . $e->getMessage() . $diagInfo . $gateDiag,
                ]);
            }
            @unlink($catalogImport['path']);

            // Now mark the job as completed (was kept as 'running' during import)
            $catDurRow = $this->db->fetchOne(
                "SELECT TIMESTAMPDIFF(SECOND, ?, NOW()) as dur",
                [$startedAt]
            );
            $this->db->update('backup_jobs', [
                'status' => 'completed',
                'completed_at' => $this->db->now(),
                'duration_seconds' => max(0, (int) ($catDurRow['dur'] ?? 0)),
                'status_message' => null,
            ], 'id = ?', [$jobId]);
        }

        // Auto-queue prune AFTER catalog import is complete to avoid table lock contention
        if ($shouldAutoPrune) {
            $existingPrune = $this->db->fetchOne(
                "SELECT id FROM backup_jobs
                 WHERE backup_plan_id = ? AND task_type = 'prune' AND status IN ('queued', 'sent', 'running')
                 LIMIT 1",
                [$job['backup_plan_id']]
            );
            if (!$existingPrune) {
                $pruneJobId = $this->db->insert('backup_jobs', [
                    'backup_plan_id' => $job['backup_plan_id'],
                    'agent_id' => $job['agent_id'],
                    'repository_id' => $job['repository_id'],
                    'task_type' => 'prune',
                    'status' => 'queued',
                ]);
                $this->db->insert('server_log', [
                    'agent_id' => $agent['id'],
                    'backup_job_id' => $pruneJobId,
                    'level' => 'info',
                    'message' => "Auto-prune queued (job #{$pruneJobId}) after backup job #{$jobId}",
                ]);
            }
        }

        exit;
    }

    /**
     * POST /api/agent/catalog
     * Agent sends file catalog entries after a successful backup.
     * Accepts batches: { archive_id, files: [{ path, size, status, mtime }, ...] }
     */
    public function catalog(): void
    {
        $agent = $this->authenticateAgent();
        $input = $this->getJsonInput();

        $archiveId = (int) ($input['archive_id'] ?? 0);
        $files = $input['files'] ?? [];

        if (!$archiveId || empty($files)) {
            $this->json(['error' => 'archive_id and files[] required'], 400);
        }

        // Verify archive exists AND belongs to the calling agent. Without the
        // ownership check, a compromised agent token could upload catalog rows
        // against another tenant's archive (cross-tenant pollution).
        $archive = $this->db->fetchOne(
            "SELECT ar.id FROM archives ar
             JOIN repositories r ON r.id = ar.repository_id
             WHERE ar.id = ? AND r.agent_id = ?",
            [$archiveId, (int) $agent['id']]
        );
        if (!$archive) {
            $this->json(['error' => 'Archive not found'], 404);
        }

        $agentId = (int) $agent['id'];
        $ch = \BBS\Core\ClickHouse::getInstance();

        // Build TSV and upload to ClickHouse
        $escape = fn(string $s) => str_replace(["\t", "\n", "\\"], ["\\t", "\\n", "\\\\"], $s);
        $tsvFile = sys_get_temp_dir() . "/catalog_api_{$agentId}_{$archiveId}_" . getmypid() . '.tsv';
        $fh = fopen($tsvFile, 'w');
        $batchSize = 0;

        if ($fh) {
            foreach ($files as $file) {
                $path = $file['path'] ?? '';
                if (empty($path)) continue;

                $status = substr($file['status'] ?? 'U', 0, 1);
                $mtime = $file['mtime'] ?? '\\N';
                fwrite($fh, "{$agentId}\t{$archiveId}\t{$escape($path)}\t{$escape(basename($path))}\t{$escape(dirname($path))}\t" . (int) ($file['size'] ?? 0) . "\t{$status}\t{$mtime}\n");
                $batchSize++;
            }
            fclose($fh);

            if ($batchSize > 0) {
                try {
                    $ch->insertTsv('file_catalog', $tsvFile, [
                        'agent_id', 'archive_id', 'path', 'file_name', 'parent_dir', 'file_size', 'status', 'mtime'
                    ]);
                } finally {
                    @unlink($tsvFile);
                }
            } else {
                @unlink($tsvFile);
            }
        }

        $isDone = !empty($input['done']);

        if ($isDone) {
            $archiveRow = $this->db->fetchOne("SELECT backup_job_id FROM archives WHERE id = ?", [$archiveId]);
            $logJobId = $archiveRow['backup_job_id'] ?? null;

            $totalRow = $ch->fetchOne(
                "SELECT count() as cnt FROM file_catalog WHERE agent_id = ? AND archive_id = ?",
                [$agentId, $archiveId]
            );
            $totalIndexed = (int) ($totalRow['cnt'] ?? 0);

            // Build catalog_dirs index from ClickHouse data
            $this->buildDirsFromCatalog($agentId, $archiveId);

            $this->db->insert('server_log', [
                'agent_id' => $agentId,
                'backup_job_id' => $logJobId,
                'level' => 'info',
                'message' => "File catalog indexed: {$totalIndexed} entries for archive #{$archiveId}",
            ]);
        }

        $this->json(['status' => 'ok', 'inserted' => $batchSize]);
    }

    /**
     * Build catalog_dirs index from file_catalog data in ClickHouse.
     */
    private function buildDirsFromCatalog(int $agentId, int $archiveId): void
    {
        $ch = \BBS\Core\ClickHouse::getInstance();

        // Remove old dir entries for this archive
        try {
            $ch->exec("ALTER TABLE catalog_dirs DELETE WHERE agent_id = {$agentId} AND archive_id = {$archiveId} SETTINGS mutations_sync = 1");
        } catch (\Exception $e) { /* ignore */ }

        // Get dir stats grouped by parent_dir from ClickHouse
        $dirRows = $ch->fetchAll("
            SELECT parent_dir, count() as file_count, sum(file_size) as total_size
            FROM file_catalog
            WHERE agent_id = ? AND archive_id = ? AND status != 'D'
            GROUP BY parent_dir
        ", [$agentId, $archiveId]);

        $allDirs = [];
        foreach ($dirRows as $d) {
            $dirPath = $d['parent_dir'];
            if ($dirPath === '' || $dirPath === '/') continue;

            if (!isset($allDirs[$dirPath])) {
                $allDirs[$dirPath] = [0, 0];
            }
            $allDirs[$dirPath][0] += (int) $d['file_count'];
            $allDirs[$dirPath][1] += (int) $d['total_size'];

            // Walk up ancestors
            $p = dirname($dirPath);
            while ($p !== '/' && $p !== '.' && !isset($allDirs[$p])) {
                $allDirs[$p] = [0, 0];
                $p = dirname($p);
            }
        }

        if (empty($allDirs)) return;

        // Write dirs TSV and upload to ClickHouse
        $escape = fn(string $s) => str_replace(["\t", "\n", "\\"], ["\\t", "\\n", "\\\\"], $s);
        $dirsTsv = sys_get_temp_dir() . "/catalog_dirs_api_{$agentId}_{$archiveId}_" . getmypid() . '.tsv';
        $fh = fopen($dirsTsv, 'w');
        if (!$fh) return;

        foreach ($allDirs as $dirPath => [$fc, $sz]) {
            $parent = dirname($dirPath);
            if ($parent === '.') $parent = '/';
            $name = basename($dirPath);
            fwrite($fh, "{$agentId}\t{$archiveId}\t{$escape($dirPath)}\t{$escape($parent)}\t{$escape($name)}\t{$fc}\t{$sz}\n");
        }
        fclose($fh);

        try {
            $ch->insertTsv('catalog_dirs', $dirsTsv, [
                'agent_id', 'archive_id', 'dir_path', 'parent_dir', 'name', 'file_count', 'total_size'
            ]);
        } catch (\Exception $e) { /* ignore */ }
        @unlink($dirsTsv);
    }

    public function heartbeat(): void
    {
        $agent = $this->authenticateAgent();

        $response = [
            'status' => 'ok',
            'agent_id' => $agent['id'],
            'server_time' => date('Y-m-d H:i:s'),
        ];

        // Check for stalled jobs on this agent. The main poll loop is blocked
        // during task execution so the stall check in tasks() never fires.
        // The heartbeat is the only channel active during a running task.
        $stallSetting = $this->db->fetchOne("SELECT `value` FROM settings WHERE `key` = 'stall_timeout_minutes'");
        $stallMinutes = max(10, (int) ($stallSetting['value'] ?? 120));

        $stalledJobs = $this->db->fetchAll("
            SELECT bj.id FROM backup_jobs bj
            WHERE bj.agent_id = ?
              AND bj.status = 'running'
              AND bj.task_type NOT IN ('prune', 'compact', 's3_sync', 's3_restore', 'repo_check', 'repo_repair', 'break_lock', 'catalog_sync', 'catalog_rebuild', 'catalog_rebuild_full', 'archive_delete')
              AND (
                  (bj.last_progress_at IS NOT NULL AND bj.last_progress_at < DATE_SUB(NOW(), INTERVAL {$stallMinutes} MINUTE))
                  OR
                  (bj.last_progress_at IS NULL AND bj.started_at IS NOT NULL AND bj.started_at < DATE_SUB(NOW(), INTERVAL {$stallMinutes} MINUTE))
              )
        ", [$agent['id']]);

        if (!empty($stalledJobs)) {
            $response['check_jobs'] = array_map(fn($j) => (int) $j['id'], $stalledJobs);
        }

        // Also relay cancel signals for the currently running job
        $cancelledJob = $this->db->fetchOne("
            SELECT id FROM backup_jobs
            WHERE agent_id = ? AND status = 'cancelled'
              AND task_type NOT IN ('prune', 'compact', 's3_sync', 's3_restore', 'repo_check', 'repo_repair', 'break_lock', 'catalog_sync', 'catalog_rebuild', 'catalog_rebuild_full', 'archive_delete')
              AND completed_at IS NULL
            LIMIT 1
        ", [$agent['id']]);
        if ($cancelledJob) {
            $response['cancel_job'] = (int) $cancelledJob['id'];
        }

        $this->json($response);
    }

    /**
     * POST /api/agent/info
     * Agent reports system information (OS, borg version, disk usage).
     */
    public function info(): void
    {
        $agent = $this->authenticateAgent();
        $input = $this->getJsonInput();

        $data = [];
        if (!empty($input['os_info']))              $data['os_info'] = substr($input['os_info'], 0, 255);
        if (!empty($input['borg_version']))         $data['borg_version'] = substr($input['borg_version'], 0, 20);
        if (!empty($input['agent_version']))        $data['agent_version'] = substr($input['agent_version'], 0, 20);
        if (!empty($input['hostname']))             $data['hostname'] = substr($input['hostname'], 0, 255);
        if (!empty($input['ip_address']))           $data['ip_address'] = substr($input['ip_address'], 0, 45);
        if (!empty($input['borg_install_method']))  $data['borg_install_method'] = substr($input['borg_install_method'], 0, 20);
        if (!empty($input['borg_source']))          $data['borg_source'] = substr($input['borg_source'], 0, 20);
        if (!empty($input['borg_binary_path']))     $data['borg_binary_path'] = substr($input['borg_binary_path'], 0, 255);
        if (!empty($input['glibc_version']))        $data['glibc_version'] = substr($input['glibc_version'], 0, 20);
        if (!empty($input['platform']))             $data['platform'] = substr($input['platform'], 0, 20);
        if (!empty($input['architecture']))         $data['architecture'] = substr($input['architecture'], 0, 20);

        if (!empty($data)) {
            $this->db->update('agents', $data, 'id = ?', [$agent['id']]);
        }

        $this->json(['status' => 'ok']);
    }

    /**
     * GET /api/agent/ssh-key
     * Agent downloads its SSH private key for borg SSH access.
     */
    public function sshKey(): void
    {
        $agent = $this->authenticateAgent();

        if (empty($agent['ssh_private_key_encrypted'])) {
            $this->json(['error' => 'No SSH key provisioned for this agent'], 404);
        }

        try {
            $privateKey = \BBS\Services\Encryption::decrypt($agent['ssh_private_key_encrypted']);
        } catch (\Exception $e) {
            $this->json(['error' => 'Failed to decrypt SSH key'], 500);
        }

        // Return server_host and ssh_port settings so the agent knows how to connect
        // Per-agent overrides take precedence over global settings
        $serverHost = $this->db->fetchOne("SELECT `value` FROM settings WHERE `key` = 'server_host'");
        $sshPort = $this->db->fetchOne("SELECT `value` FROM settings WHERE `key` = 'ssh_port'");

        $host = !empty($agent['server_host_override']) ? $agent['server_host_override'] : SshKeyManager::stripHostPort($serverHost['value'] ?? '');
        $port = !empty($agent['ssh_port_override']) ? (int) $agent['ssh_port_override'] : (int) ($sshPort['value'] ?? 22);

        $this->json([
            'status' => 'ok',
            'ssh_private_key' => $privateKey,
            'ssh_unix_user' => $agent['ssh_unix_user'],
            'server_host' => $host,
            'ssh_port' => $port,
        ]);
    }

    /**
     * Serve agent files (install.sh, bbs-agent.py, plist) from outside public/.
     */
    public function downloadFile(): void
    {
        $allowed = [
            'install.sh' => 'agent/install.sh',
            'bbs-agent.py' => 'agent/bbs-agent.py',
            'bbs-agent-start.sh' => 'agent/bbs-agent-start.sh',
            'com.borgbackupserver.agent.plist' => 'agent/com.borgbackupserver.agent.plist',
            'install-windows.ps1' => 'agent/install-windows.ps1',
            'uninstall-windows.ps1' => 'agent/uninstall-windows.ps1',
            'bbs-agent.exe' => 'agent/bbs-agent.exe',
            'bbs-mac-agent' => 'agent/bbs-mac-agent',
            'uninstall.sh' => 'agent/uninstall.sh',
        ];

        $filename = $_GET['file'] ?? '';
        if (!isset($allowed[$filename])) {
            http_response_code(404);
            echo "Not found";
            exit;
        }

        $path = dirname(__DIR__, 3) . '/' . $allowed[$filename];
        if (!file_exists($path)) {
            http_response_code(404);
            echo "Not found";
            exit;
        }

        $contentType = str_ends_with($filename, '.sh') || str_ends_with($filename, '.py') || str_ends_with($filename, '.plist') || str_ends_with($filename, '.ps1')
            ? 'text/plain; charset=utf-8'
            : 'application/octet-stream';
        header('Content-Type: ' . $contentType);
        header('Content-Disposition: inline; filename="' . $filename . '"');
        readfile($path);
        exit;
    }

    /**
     * Shorthand route: /get-agent → serves install.sh by default.
     */
    public function getAgent(): void
    {
        $_GET['file'] = $_GET['file'] ?? 'install.sh';
        $this->downloadFile();
    }

    /**
     * Shorthand route: /get-agent-windows → serves install-windows.ps1 by default.
     */
    public function getAgentWindows(): void
    {
        $_GET['file'] = $_GET['file'] ?? 'install-windows.ps1';
        $this->downloadFile();
    }

    /**
     * Parse JSON request body.
     */
    private function getJsonInput(): array
    {
        $raw = file_get_contents('php://input');
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }

    private function formatDuration(int $seconds): string
    {
        $d = intdiv($seconds, 86400);
        $h = intdiv($seconds % 86400, 3600);
        $m = intdiv($seconds % 3600, 60);
        $s = $seconds % 60;

        $parts = [];

        if ($d > 0) $parts[] = "{$d}d";
        if ($h > 0) $parts[] = "{$h}h";
        if ($m > 0) $parts[] = "{$m}m";
        if ($s > 0 || empty($parts)) $parts[] = "{$s}s";

        return implode(' ', $parts);
    }

    private function formatBytesLog(int $bytes): string
    {
        $nbsp = "\u{00A0}";
        if ($bytes == 0) return "0{$nbsp}B";
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) { $bytes /= 1024; $i++; }
        return round($bytes, $i > 0 ? 1 : 0) . $nbsp . $units[$i];
    }

    /**
     * Auto-queue a borg update job if the agent is outdated relative to the target version
     * and a compatible binary exists (GitHub or server-hosted fallback).
     */
    private function autoQueueBorgUpdate(array $agent): void
    {
        $borgService = new \BBS\Services\BorgVersionService();

        // Skip if auto-update is disabled
        if (!$borgService->isAutoUpdateEnabled()) {
            return;
        }

        // Skip if no platform info yet (agent hasn't registered fully)
        $platform = $agent['platform'] ?? null;
        $arch = $agent['architecture'] ?? null;
        if (!$platform || !$arch) {
            return;
        }

        // Don't queue if there's already a pending/running borg update,
        // or if one failed in the last 24 hours (avoid retry loops on persistent failures like full disk)
        $existing = $this->db->fetchOne(
            "SELECT id FROM backup_jobs WHERE agent_id = ? AND task_type = 'update_borg'
             AND (status IN ('queued', 'sent', 'running') OR (status = 'failed' AND completed_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)))",
            [$agent['id']]
        );
        if ($existing) {
            return;
        }

        // Get best available version for this agent
        $best = $borgService->getBestVersionForAgent($agent);
        if (!$best) {
            return; // No compatible binary available
        }

        // If no versioned binary exists for this agent's arch, the fallback
        // is pip 'latest' — we can't tell whether an update is actually
        // needed, and on arches without a working pip (armv7l, some BSD
        // variants) it just fails daily forever. Skip auto-queue; the user
        // can still click "Update Borg" manually. #187
        if (($best['source'] ?? '') === 'pip' && ($best['version'] ?? '') === 'latest') {
            return;
        }

        // Check if agent already has this version or newer (but < 2.0)
        $agentBorgVer = preg_replace('/^borg\s+/', '', $agent['borg_version'] ?? '');
        if (!empty($agentBorgVer) && version_compare($agentBorgVer, $best['version'], '>=')) {
            return; // Already up to date
        }

        // Queue the update
        $this->db->insert('backup_jobs', [
            'agent_id' => $agent['id'],
            'task_type' => 'update_borg',
            'status' => 'queued',
        ]);
    }
}
