<?php

namespace BBS\Services;

use BBS\Core\Database;

class ReportService
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * Generate a daily report for the given date (default: today).
     * Stores as JSON in daily_reports table (upserts if date already exists).
     *
     * $bumpTimestamp: when true, re-writes created_at to NOW() on upsert so
     * the UI timestamp reflects the manual refresh (#152). The scheduler's
     * automatic minute-by-minute regeneration passes false so it doesn't
     * look like the user just clicked the button (#176).
     */
    public function generate(?string $date = null, bool $bumpTimestamp = false, ?string $sinceTime = null, string $period = 'daily', bool $persist = true): array
    {
        if ($date) {
            $reportDate = $date;
        } else {
            $tz = $_SESSION['timezone'] ?? 'UTC';
            $reportDate = (new \DateTime('now', new \DateTimeZone($tz)))->format('Y-m-d');
        }
        if ($sinceTime === null) {
            // Count backups since the last report (not just "today" which is timezone-dependent)
            $lastReport = $this->db->fetchOne(
                "SELECT created_at FROM daily_reports WHERE report_date < ? ORDER BY report_date DESC LIMIT 1",
                [$reportDate]
            );
            $sinceTime = $lastReport['created_at'] ?? date('Y-m-d H:i:s', strtotime('-24 hours'));
        }

        // All agents
        $agents = $this->db->fetchAll("SELECT id, name, hostname, status, last_heartbeat FROM agents ORDER BY name");

        // On-disk repository size per agent — the deduplicated footprint
        // maintained by RepositorySizeService. This is the same figure the
        // Clients page shows (and matches `du`); the report's "Size" column
        // uses it so the two never disagree, and so it stays correct even when
        // the most recent backup failed (#292). It deliberately does NOT use
        // the last archive's original_size (uncompressed, non-deduplicated).
        $repoSizeByAgent = [];
        foreach ($this->db->fetchAll(
            "SELECT agent_id, COALESCE(SUM(size_bytes), 0) as total_size FROM repositories GROUP BY agent_id"
        ) as $rs) {
            $repoSizeByAgent[(int) $rs['agent_id']] = (int) $rs['total_size'];
        }

        $agentData = [];
        $totalCompleted = 0;
        $totalFailed = 0;
        $totalBytes = 0;

        foreach ($agents as $agent) {
            // Latest completed/failed backup per plan for this agent — clients
            // with multiple plans used to show only one plan's size in the
            // report (#175). Aggregating across plans fixes that and gives a
            // true "total backed-up" count for the client.
            $planJobs = $this->db->fetchAll("
                SELECT bj.backup_plan_id, bj.status, bj.completed_at, bj.files_processed,
                       COALESCE(a.original_size, bj.bytes_total, 0) as original_size,
                       COALESCE(a.deduplicated_size, 0) as deduplicated_size,
                       bj.error_log, bj.duration_seconds, bp.name as plan_name
                FROM backup_jobs bj
                INNER JOIN (
                    SELECT backup_plan_id, MAX(completed_at) as max_at
                    FROM backup_jobs
                    WHERE agent_id = ? AND task_type = 'backup'
                      AND status IN ('completed', 'failed')
                      AND completed_at IS NOT NULL
                    GROUP BY backup_plan_id
                ) latest ON (
                    (latest.backup_plan_id <=> bj.backup_plan_id)
                    AND latest.max_at = bj.completed_at
                )
                LEFT JOIN backup_plans bp ON bp.id = bj.backup_plan_id
                LEFT JOIN archives a ON a.backup_job_id = bj.id
                WHERE bj.agent_id = ? AND bj.task_type = 'backup'
                  AND bj.status IN ('completed', 'failed')
                ORDER BY bj.completed_at DESC
            ", [$agent['id'], $agent['id']]);

            // Aggregate across plans. "last_backup" keeps the most recent
            // per-plan entry as the top-line timestamp; plan_breakdown
            // surfaces each plan's status so a partial failure (one plan ok,
            // one failed) is visible in the HTML renderer.
            $lastJob = null;
            $aggFiles = 0;
            $aggOriginal = 0;
            $aggDedup = 0;
            $anyFailed = false;
            $anyOk = false;
            $planBreakdown = [];
            foreach ($planJobs as $pj) {
                if ($lastJob === null) $lastJob = $pj; // rows are ORDER BY completed_at DESC
                if ($pj['status'] === 'completed') {
                    $aggFiles    += (int) $pj['files_processed'];
                    $aggOriginal += (int) $pj['original_size'];
                    $aggDedup    += (int) $pj['deduplicated_size'];
                    $anyOk = true;
                } else {
                    $anyFailed = true;
                }
                $planBreakdown[] = [
                    'plan_name'    => $pj['plan_name'],
                    'status'       => $pj['status'],
                    'completed_at' => $pj['completed_at'],
                    'files'        => (int) $pj['files_processed'],
                    'original_size'=> (int) $pj['original_size'],
                    'error'        => $pj['status'] === 'failed' ? substr($pj['error_log'] ?? '', 0, 200) : null,
                ];
            }
            // Overall row status: failed if anything failed; completed if at
            // least one ok and nothing failed; otherwise mirror $lastJob.
            $overallStatus = null;
            if ($anyFailed && $anyOk)       $overallStatus = 'partial';
            elseif ($anyFailed)             $overallStatus = 'failed';
            elseif ($anyOk)                 $overallStatus = 'completed';

            // Backups since last report for this agent
            $periodStats = $this->db->fetchOne("
                SELECT
                    SUM(bj.status = 'completed') as completed,
                    SUM(bj.status = 'failed') as failed,
                    SUM(CASE WHEN bj.status = 'completed' THEN COALESCE(a.original_size, bj.bytes_total, 0) ELSE 0 END) as total_bytes
                FROM backup_jobs bj
                LEFT JOIN archives a ON a.backup_job_id = bj.id
                WHERE bj.agent_id = ? AND bj.task_type = 'backup'
                  AND bj.completed_at > ?
            ", [$agent['id'], $sinceTime]);

            $completed = (int) ($periodStats['completed'] ?? 0);
            $failed = (int) ($periodStats['failed'] ?? 0);
            $totalCompleted += $completed;
            $totalFailed += $failed;
            $totalBytes += (int) ($periodStats['total_bytes'] ?? 0);

            // Data added after deduplication in the period: what the backups
            // actually put on disk, which is the figure that matters for
            // capacity. Per client so a scoped report can sum its own.
            $periodDedup = (int) ($this->db->fetchOne("
                SELECT COALESCE(SUM(a.deduplicated_size), 0) AS dedup
                FROM backup_jobs bj
                JOIN archives a ON a.backup_job_id = bj.id
                WHERE bj.agent_id = ? AND bj.task_type = 'backup'
                  AND bj.status = 'completed' AND bj.completed_at > ?
            ", [$agent['id'], $sinceTime])['dedup'] ?? 0);

            // Why the client needs attention, if it does. One reason, in order
            // of how bad it is: the last backup failed, the client is offline,
            // or an enabled schedule has gone past its run time without running.
            $lastGood = $this->db->fetchOne("
                SELECT MAX(completed_at) AS at FROM backup_jobs
                WHERE agent_id = ? AND task_type = 'backup' AND status = 'completed'
            ", [$agent['id']]);
            $lastGoodAt = $lastGood['at'] ?? null;
            $attention = null;
            if ($overallStatus === 'failed' || $overallStatus === 'partial') {
                $err = trim((string) ($lastJob['error_log'] ?? ''));
                $attention = [
                    'reason' => 'failed',
                    'label' => $overallStatus === 'partial' ? 'Partial' : 'Failed',
                    'detail' => $err !== '' ? mb_substr(preg_replace('/\s+/', ' ', $err), 0, 90) : 'Last backup did not complete',
                ];
            } elseif ($agent['status'] !== 'online') {
                $attention = [
                    'reason' => 'offline',
                    'label' => 'Offline',
                    'detail' => 'Client has stopped checking in',
                ];
            } else {
                $overdue = $this->db->fetchOne("
                    SELECT bp.name FROM schedules s
                    JOIN backup_plans bp ON bp.id = s.backup_plan_id
                    WHERE bp.agent_id = ? AND s.enabled = 1 AND bp.enabled = 1
                      AND s.next_run IS NOT NULL AND s.next_run < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 HOUR)
                    ORDER BY s.next_run LIMIT 1
                ", [$agent['id']]);
                if ($overdue) {
                    $attention = [
                        'reason' => 'overdue',
                        'label' => 'Overdue',
                        'detail' => 'Scheduled backup "' . $overdue['name'] . '" has not run',
                    ];
                }
            }

            $agentData[] = [
                'id' => $agent['id'],
                'name' => $agent['name'],
                'hostname' => $agent['hostname'],
                'status' => $agent['status'],
                'last_heartbeat' => $agent['last_heartbeat'],
                'repo_size' => $repoSizeByAgent[(int) $agent['id']] ?? 0,
                'last_backup' => $lastJob ? [
                    // Status is the client-level overall across all plans; the
                    // individual plan results are in plan_breakdown below.
                    'status' => $overallStatus ?? $lastJob['status'],
                    'completed_at' => $lastJob['completed_at'],
                    'plan_name' => $lastJob['plan_name'],
                    // Totals aggregate every plan's latest completed backup,
                    // so clients with multiple repos show their full data (#175).
                    'files' => $aggFiles,
                    'original_size' => $aggOriginal,
                    'deduplicated_size' => $aggDedup,
                    'duration' => (int) $lastJob['duration_seconds'],
                    'error' => $lastJob['status'] === 'failed' ? substr($lastJob['error_log'] ?? '', 0, 500) : null,
                    'plan_breakdown' => $planBreakdown,
                ] : null,
                'today_completed' => $completed,
                'today_failed' => $failed,
                'period_dedup_bytes' => $periodDedup,
                'last_good_at' => $lastGoodAt,
                'attention' => $attention,
            ];
        }

        // Backup activity per day for the last seven days, per client so the
        // renderer can sum it for whichever clients the reader may see. Days
        // are UTC calendar days of the job's completion.
        $activityRows = $this->db->fetchAll("
            SELECT DATE(completed_at) AS day, agent_id,
                   SUM(status = 'completed') AS completed, SUM(status = 'failed') AS failed
            FROM backup_jobs
            WHERE task_type = 'backup' AND status IN ('completed', 'failed')
              AND completed_at >= ? AND completed_at < ?
            GROUP BY DATE(completed_at), agent_id
        ", [date('Y-m-d', strtotime($reportDate . ' -6 days')), date('Y-m-d', strtotime($reportDate . ' +1 day'))]);
        $activity = [];
        foreach ($activityRows as $ar) {
            $activity[] = [
                'day' => $ar['day'],
                'agent_id' => (int) $ar['agent_id'],
                'completed' => (int) $ar['completed'],
                'failed' => (int) $ar['failed'],
            ];
        }

        // Day's errors (from the report period)
        $dayStart = $reportDate . ' 00:00:00';
        $dayEnd = $reportDate . ' 23:59:59';
        $errors = $this->db->fetchAll("
            SELECT sl.agent_id, sl.message, sl.created_at, a.name as agent_name
            FROM server_log sl
            LEFT JOIN agents a ON a.id = sl.agent_id
            WHERE sl.level = 'error' AND sl.created_at BETWEEN ? AND ?
            ORDER BY sl.created_at DESC
            LIMIT 50
        ", [$dayStart, $dayEnd]);

        // Server info
        $settings = [];
        $rows = $this->db->fetchAll("SELECT `key`, `value` FROM settings WHERE `key` IN ('storage_path', 'server_host')");
        foreach ($rows as $row) {
            $settings[$row['key']] = $row['value'];
        }

        $storagePath = $settings['storage_path'] ?? '/var/bbs';

        // Aggregate disk usage across every configured local storage location.
        // Falls back to the default storage_path if no locations are configured.
        $locations = $this->db->fetchAll("SELECT id, label, path, capacity_bytes FROM storage_locations ORDER BY label");
        $locationStats = [];
        $seenPartitions = [];
        $aggTotal = 0; $aggUsed = 0; $aggFree = 0;
        foreach ($locations as $loc) {
            // capacityForLocation(), as the dashboard and Storage page use: a
            // stated capacity wins, and a mount that can't report its own size
            // (WebDAV answers df from the local cache disk) is marked unknown
            // rather than shown with the server disk's figures (#415, #473).
            $u = ServerStats::capacityForLocation($loc);
            if ($u === null || ($u['used'] ?? null) === null) {
                $locationStats[] = [
                    'label' => $loc['label'] ?: $loc['path'],
                    'path'  => $loc['path'],
                    'disk_total' => 0,
                    'disk_used'  => 0,
                    'disk_free'  => 0,
                    'disk_percent' => 0.0,
                    'capacity_unknown' => true,
                ];
                continue;
            }
            // Dedupe df-backed locations by (total + free) as a cheap partition
            // fingerprint so multiple logical locations on the same disk aren't
            // double-counted. A stated capacity is its own pool.
            $fp = ($u['source'] ?? 'df') === 'stated'
                ? 'stated:' . $loc['id']
                : $u['total'] . ':' . $u['free'];
            if (!isset($seenPartitions[$fp])) {
                $aggTotal += (int) $u['total'];
                $aggUsed  += (int) $u['used'];
                $aggFree  += (int) $u['free'];
                $seenPartitions[$fp] = true;
            }
            $locationStats[] = [
                'label' => $loc['label'] ?: $loc['path'],
                'path'  => $loc['path'],
                'disk_total' => (int) $u['total'],
                'disk_used'  => (int) $u['used'],
                'disk_free'  => (int) $u['free'],
                'disk_percent' => (float) ($u['percent'] ?? 0),
            ];
        }
        // Fallback when no storage_locations rows are configured (fresh install)
        if (empty($locations)) {
            $u = ServerStats::getDiskUsage($storagePath);
            if ($u) {
                $aggTotal = (int) $u['total'];
                $aggUsed  = (int) $u['used'];
                $aggFree  = (int) $u['free'];
                $locationStats[] = [
                    'label' => $storagePath,
                    'path'  => $storagePath,
                    'disk_total' => (int) $u['total'],
                    'disk_used'  => (int) $u['used'],
                    'disk_free'  => (int) $u['free'],
                    'disk_percent' => (float) ($u['percent'] ?? 0),
                ];
            }
        }
        $aggPercent = $aggTotal > 0 ? round(($aggUsed / $aggTotal) * 100, 1) : 0;

        // Counts + on-disk bytes split across two queries to avoid the JOIN
        // inflation that was reporting SUM(deduplicated_size) — which is the
        // per-archive marginal contribution, not the actual disk footprint.
        $repoStats = $this->db->fetchOne("
            SELECT COUNT(*) as repo_count, COALESCE(SUM(size_bytes), 0) as total_size
            FROM repositories
        ");

        $archiveStats = $this->db->fetchOne("
            SELECT COUNT(*) as archive_count,
                   COALESCE(SUM(original_size), 0) as total_original
            FROM archives
        ");

        $onlineCount = 0;
        $offlineCount = 0;
        foreach ($agents as $a) {
            if ($a['status'] === 'online') $onlineCount++;
            else $offlineCount++;
        }

        $data = [
            'report_date' => $reportDate,
            'server_host' => $settings['server_host'] ?? gethostname(),
            'generated_at' => date('Y-m-d H:i:s'),
            'summary' => [
                'total_agents' => count($agents),
                'online' => $onlineCount,
                'offline' => $offlineCount,
                'backups_completed' => $totalCompleted,
                'backups_failed' => $totalFailed,
                'total_bytes_backed_up' => $totalBytes,
            ],
            'agents' => $agentData,
            'activity' => $activity,
            'errors' => $errors,
            'server' => [
                'storage_path' => $storagePath,
                'disk_total' => $aggTotal,
                'disk_used' => $aggUsed,
                'disk_free' => $aggFree,
                'disk_percent' => $aggPercent,
                'storage_locations' => $locationStats,
                'repo_count' => (int) ($repoStats['repo_count'] ?? 0),
                'repo_total_size' => (int) ($repoStats['total_size'] ?? 0),
                'archive_count' => (int) ($archiveStats['archive_count'] ?? 0),
                'archive_original' => (int) ($archiveStats['total_original'] ?? 0),
            ],
        ];

        // Remote SSH storage
        $remoteConfigs = $this->db->fetchAll("
            SELECT rsc.name, rsc.provider, rsc.remote_host, rsc.remote_user, rsc.disk_total_bytes, rsc.disk_used_bytes, rsc.disk_free_bytes,
                   rsc.borgbase_repo_name, ba.name AS account_name
            FROM remote_ssh_configs rsc
            LEFT JOIN borgbase_accounts ba ON ba.id = rsc.borgbase_account_id
            WHERE rsc.disk_total_bytes IS NOT NULL AND rsc.disk_total_bytes > 0
            ORDER BY rsc.name");
        $remoteStorageData = [];
        foreach ($remoteConfigs as $rc) {
            $remoteStorageData[] = [
                'provider' => $rc['provider'],
                'repo_name' => $rc['borgbase_repo_name'],
                'account_name' => $rc['account_name'],
                'name' => $rc['name'],
                'host' => $rc['remote_user'] . '@' . $rc['remote_host'],
                'disk_total' => (int) $rc['disk_total_bytes'],
                'disk_used' => (int) $rc['disk_used_bytes'],
                'disk_free' => (int) $rc['disk_free_bytes'],
                'disk_percent' => (int) $rc['disk_total_bytes'] > 0 ? round(((int) $rc['disk_used_bytes'] / (int) $rc['disk_total_bytes']) * 100, 1) : 0,
            ];
        }
        if (!empty($remoteStorageData)) {
            $data['remote_storage'] = $remoteStorageData;
        }

        $data['period'] = $period;
        $data['period_start'] = $sinceTime;

        // Weekly (or other non-daily) datasets are transient: they're built
        // per-send with a wider window and must not overwrite the stored
        // daily report row (#285)
        if (!$persist) {
            return ['id' => 0, 'data' => $data];
        }

        // Upsert: update existing report for this date or create new one.
        // Bump created_at only when the caller asked (manual regenerate) — the
        // scheduler refreshes numbers every minute and bumping the timestamp
        // there makes the "Recent Reports" list always show the current time
        // (#176).
        $existing = $this->db->fetchOne("SELECT id FROM daily_reports WHERE report_date = ?", [$reportDate]);
        if ($existing) {
            $update = ['data' => json_encode($data)];
            if ($bumpTimestamp) {
                $update['created_at'] = date('Y-m-d H:i:s');
            }
            $this->db->update('daily_reports', $update, 'id = ?', [$existing['id']]);
            $id = (int) $existing['id'];
        } else {
            $id = $this->db->insert('daily_reports', [
                'report_date' => $reportDate,
                'data' => json_encode($data),
            ]);
        }

        return ['id' => $id, 'data' => $data];
    }

    /**
     * Get a stored report by ID.
     */
    public function getReport(int $id): ?array
    {
        $row = $this->db->fetchOne("SELECT * FROM daily_reports WHERE id = ?", [$id]);
        if (!$row) return null;
        $row['data'] = json_decode($row['data'], true);
        return $row;
    }

    /**
     * Get the most recent reports (summaries only).
     */
    public function getRecentReports(int $limit = 7): array
    {
        return $this->db->fetchAll(
            "SELECT id, report_date, created_at FROM daily_reports ORDER BY created_at DESC LIMIT ?",
            [$limit]
        );
    }

    /**
     * Render report as inline-CSS HTML, filtered to agents the user can access.
     */
    public function renderHtml(array $data, int $userId): string
    {
        $perms = new PermissionService();
        $userRow = $this->db->fetchOne("SELECT role, timezone FROM users WHERE id = ?", [$userId]);
        $isAdmin = $userRow && $userRow['role'] === 'admin';
        $tz = new \DateTimeZone($userRow['timezone'] ?? 'America/New_York');

        $accessibleIds = $perms->getAccessibleAgentIds($userId);
        $agents = array_values(array_filter($data['agents'] ?? [], fn($a) => in_array($a['id'], $accessibleIds)));

        $is24h = \BBS\Core\TimeHelper::is24h();
        $fmtTime = function (string $utc, string $format) use ($tz, $is24h): string {
            $dt = new \DateTime($utc, new \DateTimeZone('UTC'));
            $dt->setTimezone($tz);
            if ($is24h) {
                $format = str_replace(['g:i A T', 'g:i A', 'g:i a'], ['H:i T', 'H:i', 'H:i'], $format);
            }
            return $dt->format($format);
        };
        $ago = function (?string $utc): string {
            if (!$utc) {
                return 'never';
            }
            $secs = max(0, time() - strtotime($utc . ' UTC'));
            if ($secs < 3600) {
                return max(1, (int) floor($secs / 60)) . 'm ago';
            }
            if ($secs < 48 * 3600) {
                return (int) floor($secs / 3600) . 'h ago';
            }
            return (int) floor($secs / 86400) . 'd ago';
        };
        $e = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

        // Links. The report is built by the scheduler, so there is no request
        // to read the scheme from; https is what a server people email from
        // runs, and a host that already carries a scheme is used as given.
        $host = trim((string) ($data['server_host'] ?? ''));
        $baseUrl = $host === '' ? '' : (preg_match('#^https?://#', $host) ? rtrim($host, '/') : 'https://' . rtrim($host, '/'));
        $hostLabel = preg_replace('#^https?://#', '', $host) ?: 'Borg Backup Server';
        $iconUrl = $baseUrl !== '' ? $baseUrl . '/branding/icon/96' : '';

        $reportDate = $data['report_date'] ?? date('Y-m-d');
        $dateFormatted = date('F j, Y', strtotime($reportDate));
        $isWeekly = ($data['period'] ?? 'daily') === 'weekly';
        $periodLabel = $isWeekly ? 'WEEKLY REPORT' : 'DAILY REPORT';
        $periodSpan = $isWeekly ? 'Last 7 days' : 'Last 24 hours';
        $generatedAt = !empty($data['generated_at']) ? $fmtTime($data['generated_at'], 'g:i A T') : '';

        // ---- Figures for this reader's clients ----
        $completed = 0;
        $failed = 0;
        $dedupBytes = 0;
        $attention = [];
        foreach ($agents as $a) {
            $completed += (int) ($a['today_completed'] ?? 0);
            $failed += (int) ($a['today_failed'] ?? 0);
            $dedupBytes += (int) ($a['period_dedup_bytes'] ?? 0);
            if (!empty($a['attention'])) {
                $attention[] = $a;
            }
        }
        $totalJobs = $completed + $failed;
        $successRate = $totalJobs > 0 ? round($completed / $totalJobs * 100, 1) : null;
        $successStr = $successRate === null ? '--' : (fmod($successRate, 1.0) == 0.0 ? (int) $successRate . '%' : $successRate . '%');
        $successColor = $successRate === null ? '#6c757d' : ($successRate >= 99 ? '#1d7a4a' : ($successRate >= 90 ? '#b7791f' : '#c0392b'));
        $totalAgents = count($agents);
        $needCount = count($attention);
        $okCount = $totalAgents - $needCount;
        $order = ['failed' => 0, 'offline' => 1, 'overdue' => 2];
        usort($attention, fn($x, $y) => ($order[$x['attention']['reason']] ?? 9) <=> ($order[$y['attention']['reason']] ?? 9));

        // ---- Activity, last 7 days ending on the report date ----
        $days = [];
        for ($i = 6; $i >= 0; $i--) {
            $d = date('Y-m-d', strtotime($reportDate . " -{$i} days"));
            $days[$d] = ['completed' => 0, 'failed' => 0];
        }
        foreach ($data['activity'] ?? [] as $row) {
            if (!isset($days[$row['day']]) || !in_array((int) $row['agent_id'], $accessibleIds)) {
                continue;
            }
            $days[$row['day']]['completed'] += (int) $row['completed'];
            $days[$row['day']]['failed'] += (int) $row['failed'];
        }
        $maxDay = 1;
        foreach ($days as $dv) {
            $maxDay = max($maxDay, $dv['completed'] + $dv['failed']);
        }

        // ---- Styles used more than once ----
        $font = "font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;";
        $muted = 'color:#6b7280;';
        $rule = "<tr><td style=\"padding:0 24px;\"><div style=\"border-top:1px solid #e5e7eb;font-size:0;line-height:0;\">&nbsp;</div></td></tr>";
        $h2 = "font-size:17px;font-weight:700;color:#111827;margin:0;";

        $iconHtml = $iconUrl !== ''
            ? "<img src=\"{$e($iconUrl)}\" width=\"32\" height=\"32\" alt=\"\" style=\"display:block;width:32px;height:32px;border-radius:7px;\">"
            : '';

        $html = <<<HTML
<div style="{$font}background:#eef1f5;padding:24px 12px;margin:0;">
<table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="max-width:640px;margin:0 auto;background:#ffffff;border-radius:10px;border-collapse:separate;overflow:hidden;box-shadow:0 1px 3px rgba(16,24,40,0.12);">
<tr><td style="background:#1b2a4a;padding:16px 24px;">
  <table role="presentation" cellpadding="0" cellspacing="0" width="100%"><tr>
    <td style="width:40px;vertical-align:middle;">{$iconHtml}</td>
    <td style="vertical-align:middle;color:#ffffff;font-size:18px;font-weight:700;">Borg Backup Server</td>
    <td style="vertical-align:middle;text-align:right;color:#c7d2e5;font-size:12px;font-weight:700;letter-spacing:1.5px;">{$periodLabel}</td>
  </tr></table>
</td></tr>
<tr><td style="padding:24px 24px 8px;">
  <div style="font-size:28px;font-weight:800;color:#111827;line-height:1.15;">Your backups, at a glance.</div>
  <div style="font-size:15px;{$muted}margin-top:6px;">{$e($dateFormatted)} &nbsp;&middot;&nbsp; {$periodSpan}</div>
  <div style="font-size:14px;margin-top:2px;"><a href="{$e($baseUrl ?: '#')}" style="color:#0b5ed7;text-decoration:none;font-weight:600;">{$e($hostLabel)}</a></div>
</td></tr>
HTML;

        // ---- Banner ----
        if ($totalAgents === 0) {
            $html .= "<tr><td style=\"padding:8px 24px 16px;\"><div style=\"background:#f3f4f6;border:1px solid #e5e7eb;border-radius:8px;padding:12px 16px;font-size:14px;{$muted}\">No clients are visible to this report.</div></td></tr>";
        } elseif ($needCount > 0) {
            $html .= "<tr><td style=\"padding:8px 24px 16px;\"><div style=\"background:#fff8e1;border:1px solid #f3dc8a;border-radius:8px;padding:12px 16px;font-size:14px;color:#374151;\">"
                . "<span style=\"font-size:16px;\">&#9888;&#65039;</span> <strong style=\"color:#7c4a03;\">{$needCount} client" . ($needCount === 1 ? ' needs' : 's need') . " attention</strong>"
                . " <span style=\"color:#d1d5db;\">&nbsp;|&nbsp;</span> {$okCount} of {$totalAgents} clients are within their backup policy.</div></td></tr>";
        } else {
            $html .= "<tr><td style=\"padding:8px 24px 16px;\"><div style=\"background:#ecfdf3;border:1px solid #abefc6;border-radius:8px;padding:12px 16px;font-size:14px;color:#374151;\">"
                . "<span style=\"font-size:16px;color:#1d7a4a;\">&#10004;</span> <strong style=\"color:#1d7a4a;\">All clients are within their backup policy</strong>"
                . " <span style=\"color:#d1d5db;\">&nbsp;|&nbsp;</span> {$totalAgents} client" . ($totalAgents === 1 ? '' : 's') . " backed up as scheduled.</div></td></tr>";
        }

        // ---- Three stats ----
        $dedupStr = $dedupBytes > 0 ? self::formatBytes($dedupBytes) : '0 B';
        $stat = function (string $value, string $label, string $sub, string $color, bool $divider) use ($muted): string {
            $border = $divider ? 'border-left:1px solid #e5e7eb;' : '';
            return "<td width=\"33%\" style=\"padding:8px 12px;vertical-align:top;{$border}\">"
                . "<div style=\"font-size:30px;font-weight:800;color:{$color};line-height:1.1;\">{$value}</div>"
                . "<div style=\"font-size:14px;font-weight:700;color:#111827;margin-top:4px;\">{$label}</div>"
                . "<div style=\"font-size:13px;{$muted}\">{$sub}</div></td>";
        };
        $html .= "<tr><td style=\"padding:8px 12px 20px;\"><table role=\"presentation\" cellpadding=\"0\" cellspacing=\"0\" width=\"100%\"><tr>"
            . $stat($successStr, 'Job success rate', $totalJobs > 0 ? "{$completed} of {$totalJobs} jobs" : 'No jobs in this period', $successColor, false)
            . $stat($dedupStr, 'Data added', 'After deduplication', '#0b5ed7', true)
            . $stat("{$okCount} / {$totalAgents}", 'Clients up to date', $needCount > 0 ? "{$needCount} need" . ($needCount === 1 ? 's' : '') . ' attention' : 'None need attention', '#111827', true)
            . "</tr></table></td></tr>";

        // ---- Backup activity chart ----
        $html .= $rule;
        $html .= "<tr><td style=\"padding:18px 24px 4px;\"><table role=\"presentation\" cellpadding=\"0\" cellspacing=\"0\" width=\"100%\"><tr>"
            . "<td style=\"{$h2}\">Backup activity</td><td style=\"text-align:right;font-size:13px;{$muted}\">Last 7 days</td></tr></table></td></tr>";
        $barMax = 110;
        $bars = '';
        $lastDay = array_key_last($days);
        foreach ($days as $d => $dv) {
            $total = $dv['completed'] + $dv['failed'];
            $cH = (int) round($dv['completed'] / $maxDay * $barMax);
            $fH = (int) round($dv['failed'] / $maxDay * $barMax);
            if ($dv['failed'] > 0 && $fH < 3) {
                $fH = 3;
            }
            if ($dv['completed'] > 0 && $cH < 3) {
                $cH = 3;
            }
            $spacer = max(0, $barMax - $cH - $fH);
            $isLast = $d === $lastDay;
            $countLabel = $total > 0 ? ($dv['failed'] > 0 ? "{$dv['completed']}/{$total}" : (string) $total) : '';
            $cellBg = $isLast ? 'background:#eaf2fd;border-radius:6px;' : '';
            $bars .= "<td style=\"padding:0 3px;vertical-align:bottom;\"><div style=\"{$cellBg}padding:6px 2px 4px;\">"
                . "<div style=\"font-size:11px;font-weight:700;color:#111827;text-align:center;height:14px;line-height:14px;\">{$countLabel}</div>"
                . "<table role=\"presentation\" cellpadding=\"0\" cellspacing=\"0\" width=\"100%\" style=\"border-collapse:collapse;\">"
                . "<tr><td style=\"height:{$spacer}px;font-size:0;line-height:0;\"></td></tr>"
                . ($fH > 0 ? "<tr><td style=\"height:{$fH}px;background:#e5484d;font-size:0;line-height:0;\"></td></tr>" : '')
                . ($cH > 0 ? "<tr><td style=\"height:{$cH}px;background:#2aa889;font-size:0;line-height:0;\"></td></tr>" : '')
                . ($total === 0 ? "<tr><td style=\"height:2px;background:#e5e7eb;font-size:0;line-height:0;\"></td></tr>" : '')
                . "</table>"
                . "<div style=\"font-size:11px;{$muted}text-align:center;margin-top:6px;\">" . date('M j', strtotime($d)) . "</div>"
                . "</div></td>";
        }
        $html .= "<tr><td style=\"padding:8px 24px 0;\"><table role=\"presentation\" cellpadding=\"0\" cellspacing=\"0\" width=\"100%\" style=\"border-collapse:collapse;\"><tr>{$bars}</tr></table></td></tr>";
        $html .= "<tr><td style=\"padding:10px 24px 18px;text-align:center;font-size:12px;{$muted}\">"
            . "<span style=\"display:inline-block;width:9px;height:9px;border-radius:50%;background:#2aa889;\"></span>&nbsp; Completed &nbsp;&nbsp;&nbsp;"
            . "<span style=\"display:inline-block;width:9px;height:9px;border-radius:50%;background:#e5484d;\"></span>&nbsp; Failed</td></tr>";

        // ---- Needs attention ----
        $html .= $rule;
        if ($needCount > 0) {
            $html .= "<tr><td style=\"padding:18px 24px 6px;\"><span style=\"{$h2}\">Needs attention</span>"
                . " <span style=\"display:inline-block;background:#fde2e2;color:#b42318;font-size:12px;font-weight:700;padding:2px 10px;border-radius:12px;vertical-align:middle;margin-left:6px;\">{$needCount} client" . ($needCount === 1 ? '' : 's') . "</span></td></tr>";
            $shown = array_slice($attention, 0, 10);
            foreach ($shown as $i => $a) {
                $at = $a['attention'];
                $isLastRow = $i === count($shown) - 1;
                [$badgeBg, $badgeFg, $glyph, $glyphBg] = match ($at['reason']) {
                    'failed' => ['#fde2e2', '#b42318', '&#10005;', '#e5484d'],
                    'offline' => ['#e5e7eb', '#374151', '&#8211;', '#6b7280'],
                    default => ['#fff3cd', '#7c4a03', '&#9201;', '#f59e0b'],
                };
                $link = $baseUrl !== '' ? $baseUrl . '/clients/' . (int) $a['id'] : '#';
                $html .= "<tr><td style=\"padding:0 24px;\"><table role=\"presentation\" cellpadding=\"0\" cellspacing=\"0\" width=\"100%\" style=\"" . ($isLastRow ? '' : 'border-bottom:1px solid #f3f4f6;') . "\"><tr>"
                    . "<td style=\"width:34px;padding:12px 0;vertical-align:top;\"><div style=\"width:22px;height:22px;border-radius:50%;background:{$glyphBg};color:#ffffff;font-size:13px;font-weight:700;text-align:center;line-height:22px;\">{$glyph}</div></td>"
                    . "<td style=\"padding:10px 0;vertical-align:top;\">"
                    . "<div style=\"font-size:15px;font-weight:700;color:#111827;\">{$e($a['name'])} <span style=\"display:inline-block;background:{$badgeBg};color:{$badgeFg};font-size:11px;font-weight:700;padding:2px 8px;border-radius:10px;vertical-align:middle;margin-left:6px;\">{$e($at['label'])}</span></div>"
                    . "<div style=\"font-size:13px;{$muted}margin-top:2px;\">{$e($at['detail'])} &nbsp;&middot;&nbsp; Last good backup {$e($ago($a['last_good_at'] ?? null))}</div></td>"
                    . "<td style=\"text-align:right;vertical-align:middle;white-space:nowrap;padding-left:12px;\"><a href=\"{$e($link)}\" style=\"color:#0b5ed7;font-weight:700;font-size:14px;text-decoration:none;\">Review &rarr;</a></td>"
                    . "</tr></table></td></tr>";
            }
            $omitted = $okCount;
            $more = $needCount - count($shown);
            $tail = $more > 0 ? "{$more} more need attention &nbsp;&middot;&nbsp; " : ($omitted > 0 ? "{$omitted} healthy client" . ($omitted === 1 ? '' : 's') . " omitted &nbsp;&middot;&nbsp; " : '');
            $html .= "<tr><td style=\"padding:10px 24px 18px;font-size:13px;{$muted}\">{$tail}<a href=\"{$e($baseUrl !== '' ? $baseUrl . '/clients' : '#')}\" style=\"color:#0b5ed7;text-decoration:none;font-weight:600;\">View all clients &rarr;</a></td></tr>";
        } elseif ($totalAgents > 0) {
            $html .= "<tr><td style=\"padding:18px 24px;\"><span style=\"{$h2}\">Needs attention</span>"
                . " <span style=\"display:inline-block;background:#ecfdf3;color:#1d7a4a;font-size:12px;font-weight:700;padding:2px 10px;border-radius:12px;vertical-align:middle;margin-left:6px;\">none</span>"
                . "<div style=\"font-size:13px;{$muted}margin-top:6px;\">Every client's last backup completed and nothing is overdue. <a href=\"{$e($baseUrl !== '' ? $baseUrl . '/clients' : '#')}\" style=\"color:#0b5ed7;text-decoration:none;font-weight:600;\">View all clients &rarr;</a></div></td></tr>";
        }

        // ---- Storage overview (admin only: infrastructure detail) ----
        if ($isAdmin && !empty($data['server'])) {
            $srv = $data['server'];
            $pools = [];
            foreach ($srv['storage_locations'] ?? [] as $loc) {
                $pools[] = [
                    'label' => $loc['label'],
                    'unknown' => !empty($loc['capacity_unknown']),
                    'used' => (int) ($loc['disk_used'] ?? 0),
                    'total' => (int) ($loc['disk_total'] ?? 0),
                    'pct' => (float) ($loc['disk_percent'] ?? 0),
                ];
            }
            foreach ($data['remote_storage'] ?? [] as $rs) {
                $isBb = ($rs['provider'] ?? '') === 'borgbase';
                $label = $isBb
                    ? 'BorgBase &middot; ' . $e($rs['repo_name'] ?: preg_replace('/^BorgBase\s*-\s*/i', '', $rs['name']))
                    : $e($rs['name']);
                $pools[] = [
                    'label' => $label,
                    'raw_label' => true,
                    'unknown' => false,
                    'used' => (int) $rs['disk_used'],
                    'total' => (int) $rs['disk_total'],
                    'pct' => (float) $rs['disk_percent'],
                ];
            }
            if ($pools) {
                usort($pools, fn($x, $y) => $y['pct'] <=> $x['pct']);
                $withBars = array_slice($pools, 0, 4);
                $others = array_slice($pools, 4);

                $html .= $rule;
                $html .= "<tr><td style=\"padding:18px 24px 6px;{$h2}\">Storage overview</td></tr>";
                foreach ($withBars as $pl) {
                    $label = !empty($pl['raw_label']) ? $pl['label'] : $e($pl['label']);
                    if ($pl['unknown']) {
                        $html .= "<tr><td style=\"padding:6px 24px;\"><table role=\"presentation\" cellpadding=\"0\" cellspacing=\"0\" width=\"100%\"><tr>"
                            . "<td style=\"font-size:14px;font-weight:700;color:#111827;\">{$label}</td>"
                            . "<td style=\"text-align:right;font-size:13px;{$muted}\">capacity unknown &middot; set it on the Storage page</td></tr></table></td></tr>";
                        continue;
                    }
                    $pct = $pl['pct'];
                    $color = $pct >= 90 ? '#e5484d' : ($pct >= 75 ? '#f59e0b' : '#2aa889');
                    $width = max(1, min(100, (int) round($pct)));
                    $html .= "<tr><td style=\"padding:8px 24px 2px;\"><table role=\"presentation\" cellpadding=\"0\" cellspacing=\"0\" width=\"100%\"><tr>"
                        . "<td style=\"font-size:14px;font-weight:700;color:#111827;\">{$label}</td>"
                        . "<td style=\"text-align:right;font-size:13px;color:#374151;\">" . self::formatBytes($pl['used']) . " / " . self::formatBytes($pl['total']) . " &nbsp;&middot;&nbsp; <strong>{$pct}%</strong></td></tr></table>"
                        . "<table role=\"presentation\" cellpadding=\"0\" cellspacing=\"0\" width=\"100%\" style=\"margin-top:6px;border-collapse:collapse;\"><tr>"
                        . "<td width=\"{$width}%\" style=\"height:8px;background:{$color};border-radius:4px;font-size:0;line-height:0;\"></td>"
                        . ($width < 100 ? "<td style=\"height:8px;background:#e5e7eb;border-radius:4px;font-size:0;line-height:0;\"></td>" : '')
                        . "</tr></table>"
                        . ($pct >= 90 ? "<div style=\"font-size:12px;color:#b42318;font-weight:600;margin-top:6px;\">&#9888; Above 90% capacity</div>" : '')
                        . "</td></tr>";
                }
                if ($others) {
                    $parts = [];
                    foreach ($others as $pl) {
                        $label = !empty($pl['raw_label']) ? $pl['label'] : $e($pl['label']);
                        $parts[] = $label . ' ' . ($pl['unknown'] ? 'n/a' : $pl['pct'] . '%');
                    }
                    $html .= "<tr><td style=\"padding:10px 24px 4px;font-size:13px;{$muted}\">Other destinations: " . implode(' &nbsp;&middot;&nbsp; ', $parts) . "</td></tr>";
                }

                $repoCount = (int) ($srv['repo_count'] ?? 0);
                $archiveCount = (int) ($srv['archive_count'] ?? 0);
                $onDiskBytes = (int) ($srv['repo_total_size'] ?? $srv['archive_dedup'] ?? 0);
                $dedupSavings = ($srv['archive_original'] ?? 0) > 0
                    ? round((1 - $onDiskBytes / $srv['archive_original']) * 100, 1) : 0;
                if ($dedupSavings >= 100 && $onDiskBytes > 0) {
                    $dedupSavings = 99.9;   // rounding can hit 100 while bytes remain (#191)
                }
                $html .= "<tr><td style=\"padding:14px 24px 18px;\"><table role=\"presentation\" cellpadding=\"0\" cellspacing=\"0\" width=\"100%\" style=\"border-top:1px solid #e5e7eb;\"><tr>"
                    . "<td width=\"33%\" style=\"padding-top:12px;text-align:center;font-size:14px;{$muted}\"><strong style=\"color:#111827;font-size:16px;\">" . number_format($repoCount) . "</strong> repositories</td>"
                    . "<td width=\"33%\" style=\"padding-top:12px;text-align:center;font-size:14px;{$muted}border-left:1px solid #e5e7eb;\"><strong style=\"color:#111827;font-size:16px;\">" . number_format($archiveCount) . "</strong> archives</td>"
                    . "<td width=\"33%\" style=\"padding-top:12px;text-align:center;font-size:14px;{$muted}border-left:1px solid #e5e7eb;\"><strong style=\"color:#111827;font-size:16px;\">{$dedupSavings}%</strong> dedup savings</td>"
                    . "</tr></table></td></tr>";
            }
        }

        // ---- Button, app note, footer ----
        $dashUrl = $baseUrl !== '' ? $baseUrl . '/dashboard' : '#';
        $prefsUrl = $baseUrl !== '' ? $baseUrl . '/profile' : '#';
        $html .= $rule;
        $html .= "<tr><td style=\"padding:22px 24px 8px;text-align:center;\">"
            . "<a href=\"{$e($dashUrl)}\" style=\"display:inline-block;background:#0b5ed7;color:#ffffff;font-size:15px;font-weight:700;text-decoration:none;padding:12px 26px;border-radius:8px;\">Open backup dashboard &rarr;</a></td></tr>";
        $html .= "<tr><td style=\"padding:16px 24px 0;\"><div style=\"border-top:1px solid #e5e7eb;font-size:0;line-height:0;\">&nbsp;</div></td></tr>";
        $html .= "<tr><td style=\"padding:12px 24px;text-align:center;font-size:13px;color:#374151;\">"
            . "<span style=\"font-size:15px;\">&#128241;</span> <strong>New:</strong> <a href=\"https://www.borgbackupserver.com/bbs-manager/\" style=\"color:#0b5ed7;text-decoration:none;font-weight:600;\">BBS Manager for iOS</a> &mdash; manage backups from your phone. <a href=\"https://www.borgbackupserver.com/bbs-manager/\" style=\"color:#0b5ed7;text-decoration:none;\">&rarr;</a></td></tr>";
        $html .= "<tr><td style=\"padding:12px 24px 18px;border-top:1px solid #e5e7eb;\"><table role=\"presentation\" cellpadding=\"0\" cellspacing=\"0\" width=\"100%\"><tr>"
            . "<td style=\"font-size:12px;color:#9ca3af;\">Borg Backup Server" . ($generatedAt !== '' ? " &middot; Generated at {$e($generatedAt)}" : '') . "</td>"
            . "<td style=\"text-align:right;font-size:12px;\"><a href=\"{$e($prefsUrl)}\" style=\"color:#0b5ed7;text-decoration:underline;\">Manage report preferences</a></td>"
            . "</tr></table></td></tr>";
        $html .= "</table></div>";

        return $html;
    }

    /**
     * Email a report to a user (or custom address).
     */
    public function emailReport(int $reportId, int $userId, ?string $toEmail = null): bool
    {
        $report = $this->getReport($reportId);
        if (!$report) return false;

        if (!$toEmail) {
            $user = $this->db->fetchOne("SELECT email FROM users WHERE id = ?", [$userId]);
            $toEmail = $user['email'] ?? '';
        }

        if (empty($toEmail)) return false;

        return $this->emailReportData($report['data'], $report['report_date'], $userId, $toEmail);
    }

    /**
     * Email a report from an in-memory dataset (used for transient weekly
     * reports that aren't stored in daily_reports, #285).
     */
    public function emailReportData(array $data, string $reportDate, int $userId, ?string $toEmail = null): bool
    {
        if (!$toEmail) {
            $user = $this->db->fetchOne("SELECT email FROM users WHERE id = ?", [$userId]);
            $toEmail = $user['email'] ?? '';
        }
        if (empty($toEmail)) return false;

        $mailer = new Mailer();
        if (!$mailer->isEnabled()) return false;

        $html = $this->renderHtml($data, $userId);
        $dateFormatted = date('M j, Y', strtotime($reportDate));
        $periodLabel = ($data['period'] ?? 'daily') === 'weekly' ? 'Weekly' : 'Daily';
        $subject = "[BBS] {$periodLabel} Report — {$dateFormatted}";

        return $mailer->send($toEmail, $subject, $html, true);
    }

    /**
     * Delete reports older than 7 days.
     */
    public function cleanup(int $keep = 14): void
    {
        $rows = $this->db->fetchAll(
            "SELECT id FROM daily_reports ORDER BY created_at DESC LIMIT " . (int) $keep
        );
        $keepIds = array_column($rows, 'id');
        if (!empty($keepIds)) {
            $placeholders = implode(',', array_fill(0, count($keepIds), '?'));
            $this->db->query("DELETE FROM daily_reports WHERE id NOT IN ({$placeholders})", $keepIds);
        } else {
            $this->db->query("DELETE FROM daily_reports");
        }
    }

    private static function formatBytes(int $bytes): string
    {
        // An entity, not the raw U+00A0: the string only ever lands in HTML,
        // and a relay that downgrades an 8-bit body would turn the raw
        // character into "Â " in front of every unit.
        $nbsp = '&nbsp;';
        if ($bytes <= 0) return "0{$nbsp}B";
        if ($bytes >= 1099511627776) return round($bytes / 1099511627776, 1) . "{$nbsp}TB";
        if ($bytes >= 1073741824) return round($bytes / 1073741824, 1) . "{$nbsp}GB";
        if ($bytes >= 1048576) return round($bytes / 1048576, 1) . "{$nbsp}MB";
        if ($bytes >= 1024) return round($bytes / 1024, 1) . "{$nbsp}KB";
        return $bytes . "{$nbsp}B";
    }
}
