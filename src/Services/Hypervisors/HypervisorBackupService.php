<?php

namespace BBS\Services\Hypervisors;

use BBS\Core\Database;

/**
 * VM backup plans: a backup plan with hypervisor_id set. It runs as one
 * ordinary 'backup' job per VM (backup_jobs.vm_ref), so everything that
 * follows a backup — progress, archive record, catalog, notifications,
 * auto-prune — works unchanged. What differs is gathered here:
 *
 *  - which VMs a plan covers (queueJobs)
 *  - the archive prefix, plan<id>-vm<ref>, which also scopes the prune so
 *    retention applies per VM (archivePrefix)
 *  - what the agent needs to know about the VM (taskContext)
 */
class HypervisorBackupService
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /** Where the tar.gz of a file-level download (vm_browse job) is kept. */
    public static function browseDownloadPath(int $jobId): string
    {
        $dir = '/var/bbs/tmp/vm-browse';
        if (!is_dir($dir) && !@mkdir($dir, 0770, true)) {
            $dir = sys_get_temp_dir() . '/bbs-vm-browse';
            @mkdir($dir, 0770, true);
        }
        return $dir . '/' . $jobId . '.tar.gz';
    }

    /** Downloads are kept for BROWSE_DOWNLOAD_HOURS, then removed. */
    public const BROWSE_DOWNLOAD_HOURS = 6;

    public static function cleanBrowseDownloads(): void
    {
        $dir = dirname(self::browseDownloadPath(0));
        foreach (glob($dir . '/*.tar.gz') ?: [] as $f) {
            if (@filemtime($f) < time() - self::BROWSE_DOWNLOAD_HOURS * 3600) {
                @unlink($f);
            }
        }
    }

    public static function archivePrefix(int $planId, string $vmRef): string
    {
        return 'plan' . $planId . '-vm' . $vmRef;
    }

    /**
     * The VMs a plan backs up now, from the hypervisor's latest inventory.
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
     * not a VM backup.
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
                'exclude_disks' => array_values(array_filter(
                    (array) (json_decode($row['exclude_disks'] ?? '[]', true) ?: []),
                    'is_string'
                )),
            ],
        ];
    }
}
