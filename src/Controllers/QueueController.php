<?php

namespace BBS\Controllers;

use BBS\Core\Controller;
use BBS\Services\PermissionService;

class QueueController extends Controller
{
    public function index(): void
    {
        $this->requireAuth();

        [$agentWhere, $agentParams] = $this->getAgentWhereClause('a');

        $inProgress = $this->db->fetchAll("
            SELECT bj.*, SUBSTRING(bj.error_log, 1, 255) as error_log, a.name as agent_name, r.name as repo_name
            FROM backup_jobs bj
            JOIN agents a ON a.id = bj.agent_id
            LEFT JOIN repositories r ON r.id = bj.repository_id
            WHERE bj.status IN ('queued', 'sent', 'running')
            AND {$agentWhere}
            ORDER BY bj.queued_at ASC
        ", $agentParams);

        $completed = $this->db->fetchAll("
            SELECT bj.*, SUBSTRING(bj.error_log, 1, 255) as error_log, a.name as agent_name, r.name as repo_name
            FROM backup_jobs bj
            JOIN agents a ON a.id = bj.agent_id
            LEFT JOIN repositories r ON r.id = bj.repository_id
            WHERE bj.status IN ('completed', 'failed')
            AND {$agentWhere}
            ORDER BY bj.completed_at DESC
            LIMIT 25
        ", $agentParams);

        $stats = $this->collectQueueStats($agentWhere, $agentParams);

        $this->view('queue/index', [
            'pageTitle' => 'Queue',
            'inProgress' => $inProgress,
            'completed' => $completed,
        ] + $stats);
    }

    private function collectQueueStats(string $agentWhere, array $agentParams): array
    {
        $queuedCount = (int) ($this->db->fetchOne("
            SELECT COUNT(*) AS cnt FROM backup_jobs bj
            JOIN agents a ON a.id = bj.agent_id
            WHERE bj.status IN ('queued', 'sent') AND {$agentWhere}
        ", $agentParams)['cnt'] ?? 0);

        $runningCount = (int) ($this->db->fetchOne("
            SELECT COUNT(*) AS cnt FROM backup_jobs bj
            JOIN agents a ON a.id = bj.agent_id
            WHERE bj.status = 'running' AND {$agentWhere}
        ", $agentParams)['cnt'] ?? 0);

        $completed24h = (int) ($this->db->fetchOne("
            SELECT COUNT(*) AS cnt FROM backup_jobs bj
            JOIN agents a ON a.id = bj.agent_id
            WHERE bj.status = 'completed' AND bj.completed_at > DATE_SUB(NOW(), INTERVAL 24 HOUR) AND {$agentWhere}
        ", $agentParams)['cnt'] ?? 0);

        $failed24h = (int) ($this->db->fetchOne("
            SELECT COUNT(*) AS cnt FROM backup_jobs bj
            JOIN agents a ON a.id = bj.agent_id
            WHERE bj.status = 'failed' AND bj.completed_at > DATE_SUB(NOW(), INTERVAL 24 HOUR) AND {$agentWhere}
        ", $agentParams)['cnt'] ?? 0);

        $avgDuration = $this->db->fetchOne("
            SELECT ROUND(AVG(TIMESTAMPDIFF(SECOND, bj.started_at, bj.completed_at))) AS avg_sec
            FROM backup_jobs bj
            JOIN agents a ON a.id = bj.agent_id
            WHERE bj.status = 'completed' AND bj.completed_at > DATE_SUB(NOW(), INTERVAL 24 HOUR) AND bj.started_at IS NOT NULL AND {$agentWhere}
        ", $agentParams);
        $avgSec = (int) ($avgDuration['avg_sec'] ?? 0);

        $maxQueue = (int) ($this->db->fetchOne("SELECT `value` FROM settings WHERE `key` = 'max_queue'")['value'] ?? 4);

        return [
            'queuedCount' => $queuedCount,
            'runningCount' => $runningCount,
            'completed24h' => $completed24h,
            'failed24h' => $failed24h,
            'avgSec' => $avgSec,
            'maxQueue' => $maxQueue,
        ];
    }

    public function indexJson(): void
    {
        $this->requireAuth();

        [$agentWhere, $agentParams] = $this->getAgentWhereClause('a');

        $inProgress = $this->db->fetchAll("
            SELECT bj.*, SUBSTRING(bj.error_log, 1, 255) as error_log, a.name as agent_name, r.name as repo_name
            FROM backup_jobs bj
            JOIN agents a ON a.id = bj.agent_id
            LEFT JOIN repositories r ON r.id = bj.repository_id
            WHERE bj.status IN ('queued', 'sent', 'running')
            AND {$agentWhere}
            ORDER BY bj.queued_at ASC
        ", $agentParams);

        $completed = $this->db->fetchAll("
            SELECT bj.*, SUBSTRING(bj.error_log, 1, 255) as error_log, a.name as agent_name, r.name as repo_name
            FROM backup_jobs bj
            JOIN agents a ON a.id = bj.agent_id
            LEFT JOIN repositories r ON r.id = bj.repository_id
            WHERE bj.status IN ('completed', 'failed')
            AND {$agentWhere}
            ORDER BY bj.completed_at DESC
            LIMIT 25
        ", $agentParams);

        $stats = $this->collectQueueStats($agentWhere, $agentParams);

        $this->json([
            'inProgress' => $inProgress,
            'completed' => $completed,
        ] + $stats);
    }

    public function detail(int $id): void
    {
        $this->requireAuth();

        $job = $this->db->fetchOne("
            SELECT bj.*, a.name as agent_name, a.id as agent_id,
                   a.status as agent_status, a.last_heartbeat,
                   r.name as repo_name, bp.name as plan_name,
                   bp.excludes, bp.advanced_options,
                   COALESCE(bj.directories, bp.directories) AS directories
            FROM backup_jobs bj
            JOIN agents a ON a.id = bj.agent_id
            LEFT JOIN repositories r ON r.id = bj.repository_id
            LEFT JOIN backup_plans bp ON bp.id = bj.backup_plan_id
            WHERE bj.id = ?
        ", [$id]);

        if (!$job || !$this->canAccessAgent($job['agent_id'])) {
            $this->flash('danger', 'Job not found.');
            $this->redirect('/queue');
        }

        // Get log entries for this job
        $logs = $this->db->fetchAll("
            SELECT * FROM server_log
            WHERE backup_job_id = ?
            ORDER BY created_at ASC
        ", [$id]);

        // The archive this backup wrote — its deduplicated size is the cost
        // of the backup on disk, which the frozen byte counters can't say.
        $jobArchive = null;
        if (($job['task_type'] ?? '') === 'backup') {
            $jobArchive = $this->db->fetchOne(
                "SELECT archive_name, original_size, deduplicated_size FROM archives
                 WHERE backup_job_id = ? ORDER BY id DESC LIMIT 1", [$id]
            );
        }

        // Queue context: active count, max queue, position
        $activeCount = $this->db->count('backup_jobs', "status IN ('sent', 'running')");
        $maxQueue = (int) ($this->db->fetchOne("SELECT `value` FROM settings WHERE `key` = 'max_queue'")['value'] ?? 4);
        $queuePosition = null;
        if ($job['status'] === 'queued') {
            $pos = $this->db->fetchOne("SELECT COUNT(*) as cnt FROM backup_jobs WHERE status = 'queued' AND queued_at <= ?", [$job['queued_at']]);
            $queuePosition = (int) $pos['cnt'];
        }
        $pollInterval = (int) ($this->db->fetchOne("SELECT `value` FROM settings WHERE `key` = 'agent_poll_interval'")['value'] ?? 30);

        // For prune jobs, parse the log entries for keep rules and per-rule counts
        $pruneStats = null;
        if ($job['task_type'] === 'prune') {
            $pruneStats = self::parsePruneStats($logs);
        }

        $this->view('queue/detail', [
            'pageTitle' => 'Job #' . $id,
            'job' => $job,
            'jobArchive' => $jobArchive,
            'logs' => $logs,
            'activeCount' => $activeCount,
            'maxQueue' => $maxQueue,
            'queuePosition' => $queuePosition,
            'pollInterval' => $pollInterval,
            'pruneStats' => $pruneStats,
        ]);
    }

    public function detailJson(int $id): void
    {
        $this->requireAuth();

        $job = $this->db->fetchOne("
            SELECT bj.*, a.name as agent_name, a.id as agent_id,
                   a.status as agent_status, a.last_heartbeat,
                   r.name as repo_name, bp.name as plan_name,
                   bp.excludes, bp.advanced_options,
                   COALESCE(bj.directories, bp.directories) AS directories
            FROM backup_jobs bj
            JOIN agents a ON a.id = bj.agent_id
            LEFT JOIN repositories r ON r.id = bj.repository_id
            LEFT JOIN backup_plans bp ON bp.id = bj.backup_plan_id
            WHERE bj.id = ?
        ", [$id]);

        if (!$job || !$this->canAccessAgent($job['agent_id'])) {
            http_response_code(404);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Not found']);
            return;
        }

        $logs = $this->db->fetchAll("
            SELECT * FROM server_log
            WHERE backup_job_id = ?
            ORDER BY created_at ASC
        ", [$id]);

        $activeCount = $this->db->count('backup_jobs', "status IN ('sent', 'running')");
        $maxQueue = (int) ($this->db->fetchOne("SELECT `value` FROM settings WHERE `key` = 'max_queue'")['value'] ?? 4);
        $queuePosition = null;
        if ($job['status'] === 'queued') {
            $pos = $this->db->fetchOne("SELECT COUNT(*) as cnt FROM backup_jobs WHERE status = 'queued' AND queued_at <= ?", [$job['queued_at']]);
            $queuePosition = (int) $pos['cnt'];
        }

        $jobArchive = null;
        if (($job['task_type'] ?? '') === 'backup') {
            $jobArchive = $this->db->fetchOne(
                "SELECT archive_name, original_size, deduplicated_size FROM archives
                 WHERE backup_job_id = ? ORDER BY id DESC LIMIT 1", [$id]
            );
        }

        // For running backup jobs, tail the catalog log file to get the current file being backed up
        $currentFile = null;
        if ($job['status'] === 'running' && $job['task_type'] === 'backup') {
            $jobAgent = $this->db->fetchOne("SELECT ssh_home_dir FROM agents WHERE id = ?", [$job['agent_id']]);
            if ($jobAgent && !empty($jobAgent['ssh_home_dir'])) {
                $catalogPath = $jobAgent['ssh_home_dir']
                             . '/.catalog-logs/catalog-' . $id . '.jsonl';
                if (file_exists($catalogPath)) {
                    $lastLine = trim(shell_exec('tail -n 1 ' . escapeshellarg($catalogPath)) ?? '');
                    if ($lastLine) {
                        $entry = json_decode($lastLine, true);
                        if ($entry && !empty($entry['path'])) {
                            $currentFile = rtrim($entry['path'], '/');
                        }
                    }
                }
            }
        }

        header('Content-Type: application/json');
        echo json_encode([
            'job' => $job,
            'logs' => $logs,
            'activeCount' => $activeCount,
            'maxQueue' => $maxQueue,
            'queuePosition' => $queuePosition,
            'currentFile' => $currentFile,
            'archive' => $jobArchive,
        ]);
    }

    /**
     * Parse prune-specific stats from server_log entries for a job.
     * Returns keep rules (from command), kept/deleted counts, and per-rule counts where available.
     * Public static so the token API's job-detail endpoint can reuse it.
     */
    public static function parsePruneStats(array $logs): array
    {
        $stats = [
            'keep_rules' => [],       // ['hourly' => 6, 'daily' => 14, ...]
            'per_rule_counts' => [],  // ['hourly' => 6, 'daily' => 14, ...] — from "Keeping archive (rule: X #N)"
            'kept' => null,
            'deleted' => null,
            'existing' => null,
            'deleted_names' => [],
        ];

        foreach ($logs as $log) {
            $msg = $log['message'] ?? '';

            // Parse keep rules from the Prune command line
            if (str_starts_with($msg, 'Prune command:')) {
                foreach (['hourly', 'daily', 'weekly', 'monthly', 'yearly', 'minutely', 'secondly'] as $rule) {
                    if (preg_match("/--keep-{$rule}=?'?(\d+)'?/", $msg, $m)) {
                        $stats['keep_rules'][$rule] = (int) $m[1];
                    }
                }
            }

            // Parse per-rule counts from Prune output (truncated, so may be partial)
            if (str_starts_with($msg, 'Prune output:')) {
                if (preg_match_all('/rule:\s*(\w+)\s+#(\d+)/', $msg, $matches, PREG_SET_ORDER)) {
                    foreach ($matches as $m) {
                        $rule = $m[1];
                        $num = (int) $m[2];
                        // Track the highest # seen per rule = count kept for that rule
                        if (!isset($stats['per_rule_counts'][$rule]) || $num > $stats['per_rule_counts'][$rule]) {
                            $stats['per_rule_counts'][$rule] = $num;
                        }
                    }
                }
            }

            // Parse the summary message: "Removed N pruned recovery point(s) from database — X remaining: name1, name2..."
            if (preg_match('/Removed (\d+) pruned recovery point\(s\) from database — (\d+) remaining(?:: (.+?))?$/', $msg, $m)) {
                $stats['deleted'] = (int) $m[1];
                $stats['kept'] = (int) $m[2];
                $stats['existing'] = $stats['deleted'] + $stats['kept'];
                if (!empty($m[3])) {
                    $names = explode(', ', trim($m[3]));
                    // Strip trailing "(and N more)" entry
                    $names = array_filter($names, fn($n) => !str_starts_with($n, '(and '));
                    $stats['deleted_names'] = array_values($names);
                }
            }

            // "Prune completed — all N recovery point(s) retained, none removed"
            if (preg_match('/Prune completed — all (\d+) recovery point\(s\) retained, none removed/', $msg, $m)) {
                $stats['deleted'] = 0;
                $stats['kept'] = (int) $m[1];
                $stats['existing'] = (int) $m[1];
            }
        }

        return $stats;
    }

    public function cancel(int $id): void
    {
        $this->requireAuth();
        $this->verifyCsrf();

        $job = $this->db->fetchOne("SELECT * FROM backup_jobs WHERE id = ?", [$id]);
        if (!$job || !in_array($job['status'], ['queued', 'sent', 'running'])) {
            $this->flash('danger', 'Job cannot be cancelled.');
            $this->redirect('/queue');
        }

        // Check access to the agent
        if (!$this->canAccessAgent($job['agent_id'])) {
            $this->flash('danger', 'Job not found.');
            $this->redirect('/queue');
        }

        // Require trigger_backup permission to cancel jobs
        $this->requirePermission(PermissionService::TRIGGER_BACKUP, $job['agent_id']);

        $this->db->update('backup_jobs', [
            'status' => 'cancelled',
            'error_log' => 'Cancelled by user',
            'completed_at' => date('Y-m-d H:i:s'),
        ], 'id = ?', [$id]);

        $this->db->insert('server_log', [
            'agent_id' => $job['agent_id'],
            'backup_job_id' => $id,
            'level' => 'warning',
            'message' => "Job #{$id} cancelled by user",
        ]);

        // Auto-queue a break-lock to clean up any stale borg lock
        if ($job['repository_id'] && in_array($job['task_type'], ['backup', 'restore', 'restore_mysql', 'restore_pg', 'restore_mongo'])) {
            $this->db->insert('backup_jobs', [
                'agent_id' => $job['agent_id'],
                'repository_id' => $job['repository_id'],
                'task_type' => 'break_lock',
                'status' => 'queued',
            ]);
        }

        $this->flash('success', "Job #{$id} cancelled.");
        $this->redirect('/queue');
    }

    public function retry(int $id): void
    {
        $this->requireAuth();
        $this->verifyCsrf();

        $job = $this->db->fetchOne("SELECT * FROM backup_jobs WHERE id = ? AND status = 'failed'", [$id]);
        if (!$job) {
            $this->flash('danger', 'Job cannot be retried.');
            $this->redirect('/queue');
        }

        // Check access to the agent
        if (!$this->canAccessAgent($job['agent_id'])) {
            $this->flash('danger', 'Job not found.');
            $this->redirect('/queue');
        }

        // Require trigger_backup permission to retry jobs
        $this->requirePermission(PermissionService::TRIGGER_BACKUP, $job['agent_id']);

        // Create a new queued job based on the failed one
        $newJobId = $this->db->insert('backup_jobs', [
            'agent_id' => $job['agent_id'],
            'backup_plan_id' => $job['backup_plan_id'],
            'repository_id' => $job['repository_id'],
            'task_type' => $job['task_type'],
            'status' => 'queued',
            'queued_at' => date('Y-m-d H:i:s'),
            'restore_archive_id' => $job['restore_archive_id'],
            'restore_paths' => $job['restore_paths'],
            'restore_destination' => $job['restore_destination'],
            'restore_databases' => $job['restore_databases'],
        ]);

        $this->db->insert('server_log', [
            'agent_id' => $job['agent_id'],
            'backup_job_id' => $newJobId,
            'level' => 'info',
            'message' => "Job #{$newJobId} queued (retry of #{$id})",
        ]);

        $this->flash('success', "Job #{$id} retried as #{$newJobId}.");
        $this->redirect('/queue');
    }
}
