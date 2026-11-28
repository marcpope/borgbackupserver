<?php

namespace BBS\Services\Hypervisors;

use BBS\Core\Database;

/**
 * Server-side logic of VM backup plans and of file-level browsing.
 *
 * VM backup plans: a backup plan with hypervisor_id set. It runs as one
 * ordinary 'backup' job per VM (backup_jobs.vm_ref), so everything that
 * follows a backup (progress, archive record, catalog, notifications,
 * auto-prune) works unchanged. What differs is gathered here:
 *
 *  - which VMs a plan covers (planVms, queueJobs)
 *  - the archive prefix, plan<id>-vm<ref>, which also scopes the prune so
 *    retention applies per VM (archivePrefix)
 *  - what the agent needs to know about the VM (taskContext)
 *
 * File-level browsing: the browse sessions the agent reports (the repository
 * lock shown in the UI) and the tar.gz downloads it uploads.
 *
 * Callers: SchedulerService and BackupPlanController (queueJobs),
 * QueueManager (taskContext), scheduler.php (archivePrefix for the prune),
 * HypervisorController and AgentApiController (browsing).
 *
 * See docs/hypervisors.md#d3-one-backup-job-and-one-archive-per-vm-inside-the-normal-pipeline
 * and docs/hypervisors.md#d7-file-level-browsing-through-an-isolation-vm
 */
class HypervisorBackupService
{
    /**
     * The agent closes an idle browse session after this many seconds.
     * Must match VmBrowser.IDLE_SECONDS in agent/bbs-hypervisor.py; it is
     * only used here to show the countdown in the UI.
     */
    public const BROWSE_IDLE_SECONDS = 600;

    /** Downloads are kept for BROWSE_DOWNLOAD_HOURS, then removed. */
    public const BROWSE_DOWNLOAD_HOURS = 6;

    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    // ------------------------------------------------------------------
    // VM backup plans
    // ------------------------------------------------------------------

    /**
     * Archive name prefix of one VM in one plan: archives are named
     * plan<id>-vm<ref>-<date>. Prune uses it as --glob-archives
     * plan<id>-vm<ref>-*, so each VM keeps its own retention instead of
     * all the plan's VMs being counted together.
     */
    public static function archivePrefix(int $planId, string $vmRef): string
    {
        return 'plan' . $planId . '-vm' . $vmRef;
    }

    /**
     * The VMs a plan backs up now, from the hypervisor's latest inventory.
     *
     * vm_selection 'selected': only the VMs with a backup_plan_vms row
     * that is not excluded. vm_selection 'all': every VM except those with
     * exclude_vm set, so a VM created after the plan is included
     * automatically. Templates are never backed up.
     *
     * @return array<int, array> hypervisor_vms rows
     */
    public function planVms(array $plan): array
    {
        $vms = $this->db->fetchAll(
            "SELECT * FROM hypervisor_vms WHERE hypervisor_id = ? AND is_template = 0
             ORDER BY CAST(vm_ref AS UNSIGNED), vm_ref",
            [(int) $plan['hypervisor_id']]
        );
        $rows = [];
        foreach ($this->db->fetchAll("SELECT * FROM backup_plan_vms WHERE backup_plan_id = ?", [(int) $plan['id']]) as $r) {
            $rows[$r['vm_ref']] = $r;
        }

        return array_values(array_filter($vms, function ($vm) use ($plan, $rows) {
            $row = $rows[$vm['vm_ref']] ?? null;
            if (($plan['vm_selection'] ?? 'selected') === 'all') {
                return !$row || empty($row['exclude_vm']);
            }
            return $row && empty($row['exclude_vm']);
        }));
    }

    /**
     * Queue one backup job per VM of the plan. VMs that already have a job
     * queued or running for this plan are skipped.
     *
     * Called by SchedulerService (scheduled run) and BackupPlanController
     * (Run now), in place of the single job a file backup plan queues.
     *
     * @param string $reason "Scheduled" or "Manual", for the server log
     * @return int[] the new job ids
     */
    public function queueJobs(array $plan, string $reason): array
    {
        $jobIds = [];
        foreach ($this->planVms($plan) as $vm) {
            $busy = $this->db->fetchOne(
                "SELECT id FROM backup_jobs
                 WHERE backup_plan_id = ? AND vm_ref = ? AND task_type = 'backup'
                   AND status IN ('queued', 'sent', 'running')",
                [(int) $plan['id'], $vm['vm_ref']]
            );
            if ($busy) {
                continue;
            }
            $jobId = $this->db->insert('backup_jobs', [
                'backup_plan_id' => (int) $plan['id'],
                'vm_ref' => $vm['vm_ref'],
                'agent_id' => (int) $plan['agent_id'],
                'repository_id' => (int) $plan['repository_id'],
                'task_type' => 'backup',
                'status' => 'queued',
            ]);
            $this->db->insert('server_log', [
                'agent_id' => (int) $plan['agent_id'],
                'backup_job_id' => $jobId,
                'level' => 'info',
                'message' => "{$reason} backup queued for VM {$vm['vm_ref']} ({$vm['name']}), plan \"{$plan['name']}\"",
            ]);
            $jobIds[] = $jobId;
        }

        if (empty($jobIds)) {
            $this->db->insert('server_log', [
                'agent_id' => (int) $plan['agent_id'],
                'level' => 'warning',
                'message' => "Plan \"{$plan['name']}\": no VM to back up (none selected, or all already queued)",
            ]);
        }
        return $jobIds;
    }

    /**
     * What the agent needs for one VM backup job, or null when the job is
     * not a VM backup. QueueManager adds it to the backup task: 'prefix'
     * replaces the archive prefix and 'vm' becomes the task's hypervisor_vm
     * block, read by ProxmoxVmBackup in agent/bbs-hypervisor.py.
     *
     * @return array{prefix: string, vm: array}|null
     */
    public function taskContext(array $job): ?array
    {
        if (empty($job['vm_ref']) || empty($job['backup_plan_id'])) {
            return null;
        }
        $row = $this->db->fetchOne("
            SELECT h.id as hypervisor_id, h.type, bpv.exclude_disks, v.name as vm_name
            FROM backup_plans bp
            JOIN hypervisors h ON h.id = bp.hypervisor_id
            LEFT JOIN backup_plan_vms bpv ON bpv.backup_plan_id = bp.id AND bpv.vm_ref = ?
            LEFT JOIN hypervisor_vms v ON v.hypervisor_id = h.id AND v.vm_ref = ?
            WHERE bp.id = ?
        ", [$job['vm_ref'], $job['vm_ref'], (int) $job['backup_plan_id']]);
        if (!$row) {
            return null;
        }

        return [
            'prefix' => self::archivePrefix((int) $job['backup_plan_id'], (string) $job['vm_ref']),
            'vm' => [
                'hypervisor_id' => (int) $row['hypervisor_id'],
                'type' => $row['type'],
                'ref' => (string) $job['vm_ref'],
                'name' => $row['vm_name'] ?? '',
                // Disk keys only ("scsi0", "virtio1"); anything else is dropped
                'exclude_disks' => array_values(array_filter(
                    (array) (json_decode($row['exclude_disks'] ?? '[]', true) ?: []),
                    'is_string'
                )),
            ],
        ];
    }

    // ------------------------------------------------------------------
    // File-level browsing
    // ------------------------------------------------------------------

    /**
     * Open browse sessions of an agent, with how long each has held its
     * repository's lock and when it closes by itself if left idle.
     *
     * Rows come from vm_browse_sessions, written by
     * AgentApiController::hypervisorBrowseSession() when the agent opens or
     * closes a session, and touched by AgentApiController::status() each
     * time a browse task completes (last_used_at). Times are the server's.
     */
    public function openBrowseSessions(int $agentId, ?int $archiveId = null): array
    {
        $sql = "SELECT s.*, ar.vm_ref, ar.archive_name, ar.created_at as archive_created_at, r.name as repo_name,
                       TIMESTAMPDIFF(SECOND, s.opened_at, NOW()) as locked_seconds,
                       TIMESTAMPDIFF(SECOND, s.last_used_at, NOW()) as idle_seconds
                FROM vm_browse_sessions s
                LEFT JOIN archives ar ON ar.id = s.archive_id
                LEFT JOIN repositories r ON r.id = s.repository_id
                WHERE s.agent_id = ? AND s.closed_at IS NULL";
        $params = [$agentId];
        if ($archiveId !== null) {
            $sql .= " AND s.archive_id = ?";
            $params[] = $archiveId;
        }
        $rows = $this->db->fetchAll($sql . " ORDER BY s.id DESC", $params);
        foreach ($rows as &$row) {
            $row['closes_in_seconds'] = max(0, self::BROWSE_IDLE_SECONDS - (int) $row['idle_seconds']);
        }
        return $rows;
    }

    /** "45 s", "3 min", "1 h 05 min" (lock and idle times on the Restore tab). */
    public static function shortDuration(int $seconds): string
    {
        if ($seconds < 60) {
            return $seconds . ' s';
        }
        $m = intdiv($seconds, 60);
        return $m < 60 ? $m . ' min' : intdiv($m, 60) . ' h ' . sprintf('%02d', $m % 60) . ' min';
    }

    /**
     * Where the tar.gz of a file-level download (vm_browse job) is kept.
     *
     * SAFETY: the path is built from the job id only, never from anything
     * the agent or the browser sends, so an upload or a download cannot
     * reach another file. The directory is created on first use;
     * /var/bbs/tmp is preferred over the system temp dir because it is on
     * the BBS data disk.
     */
    public static function browseDownloadPath(int $jobId): string
    {
        $dir = '/var/bbs/tmp/vm-browse';
        if (!is_dir($dir) && !@mkdir($dir, 0770, true)) {
            $dir = sys_get_temp_dir() . '/bbs-vm-browse';
            @mkdir($dir, 0770, true);
        }
        return $dir . '/' . $jobId . '.tar.gz';
    }

    /**
     * Remove downloads older than BROWSE_DOWNLOAD_HOURS. Called each time a
     * new download is requested (HypervisorController::browseRequest()), so
     * no cron entry is needed.
     */
    public static function cleanBrowseDownloads(): void
    {
        $dir = dirname(self::browseDownloadPath(0));
        foreach (glob($dir . '/*.tar.gz') ?: [] as $f) {
            if (@filemtime($f) < time() - self::BROWSE_DOWNLOAD_HOURS * 3600) {
                @unlink($f);
            }
        }
    }
}
