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
     */
    public function generate(?string $date = null): array
    {
        if ($date) {
            $reportDate = $date;
        } else {
            $tz = $_SESSION['timezone'] ?? 'UTC';
            $reportDate = (new \DateTime('now', new \DateTimeZone($tz)))->format('Y-m-d');
        }
        // Count backups since the last report (not just "today" which is timezone-dependent)
        $lastReport = $this->db->fetchOne(
            "SELECT created_at FROM daily_reports WHERE report_date < ? ORDER BY report_date DESC LIMIT 1",
            [$reportDate]
        );
        $sinceTime = $lastReport['created_at'] ?? date('Y-m-d H:i:s', strtotime('-24 hours'));

        // All agents
        $agents = $this->db->fetchAll("SELECT id, name, hostname, status, last_heartbeat FROM agents ORDER BY name");

        $agentData = [];
        $totalCompleted = 0;
        $totalFailed = 0;
        $totalBytes = 0;

        foreach ($agents as $agent) {
            // Last backup job for this agent
            $lastJob = $this->db->fetchOne("
                SELECT bj.status, bj.completed_at, bj.files_processed,
                       COALESCE(a.original_size, bj.bytes_total, 0) as original_size,
                       COALESCE(a.deduplicated_size, 0) as deduplicated_size,
                       bj.error_log, bj.duration_seconds, bp.name as plan_name
                FROM backup_jobs bj
                LEFT JOIN backup_plans bp ON bp.id = bj.backup_plan_id
                LEFT JOIN archives a ON a.backup_job_id = bj.id
                WHERE bj.agent_id = ? AND bj.task_type = 'backup' AND bj.status IN ('completed', 'failed')
                ORDER BY bj.completed_at DESC LIMIT 1
            ", [$agent['id']]);

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

            $agentData[] = [
                'id' => $agent['id'],
                'name' => $agent['name'],
                'hostname' => $agent['hostname'],
                'status' => $agent['status'],
                'last_heartbeat' => $agent['last_heartbeat'],
                'last_backup' => $lastJob ? [
                    'status' => $lastJob['status'],
                    'completed_at' => $lastJob['completed_at'],
                    'plan_name' => $lastJob['plan_name'],
                    'files' => (int) $lastJob['files_processed'],
                    'original_size' => (int) $lastJob['original_size'],
                    'deduplicated_size' => (int) $lastJob['deduplicated_size'],
                    'duration' => (int) $lastJob['duration_seconds'],
                    'error' => $lastJob['status'] === 'failed' ? substr($lastJob['error_log'] ?? '', 0, 500) : null,
                ] : null,
                'today_completed' => $completed,
                'today_failed' => $failed,
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
        $diskUsage = ServerStats::getDiskUsage($storagePath);

        $repoStats = $this->db->fetchOne("
            SELECT COUNT(*) as repo_count, COALESCE(SUM(size_bytes), 0) as total_size
            FROM repositories
        ");

        $archiveStats = $this->db->fetchOne("
            SELECT COUNT(*) as archive_count,
                   COALESCE(SUM(original_size), 0) as total_original,
                   COALESCE(SUM(deduplicated_size), 0) as total_dedup
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
            'errors' => $errors,
            'server' => [
                'storage_path' => $storagePath,
                'disk_total' => $diskUsage['total'] ?? 0,
                'disk_used' => $diskUsage['used'] ?? 0,
                'disk_free' => $diskUsage['free'] ?? 0,
                'disk_percent' => $diskUsage['percent'] ?? 0,
                'repo_count' => (int) ($repoStats['repo_count'] ?? 0),
                'repo_total_size' => (int) ($repoStats['total_size'] ?? 0),
                'archive_count' => (int) ($archiveStats['archive_count'] ?? 0),
                'archive_original' => (int) ($archiveStats['total_original'] ?? 0),
                'archive_dedup' => (int) ($archiveStats['total_dedup'] ?? 0),
            ],
        ];

        // Remote SSH storage
        $remoteConfigs = $this->db->fetchAll("SELECT name, remote_host, remote_user, disk_total_bytes, disk_used_bytes, disk_free_bytes FROM remote_ssh_configs WHERE disk_total_bytes IS NOT NULL AND disk_total_bytes > 0 ORDER BY name");
        $remoteStorageData = [];
        foreach ($remoteConfigs as $rc) {
            $remoteStorageData[] = [
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

        // Upsert: update existing report for this date or create new one
        $existing = $this->db->fetchOne("SELECT id FROM daily_reports WHERE report_date = ?", [$reportDate]);
        if ($existing) {
            $this->db->update('daily_reports', ['data' => json_encode($data)], 'id = ?', [$existing['id']]);
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
        $agents = array_filter($data['agents'] ?? [], fn($a) => in_array($a['id'], $accessibleIds));
        $errors = array_filter($data['errors'] ?? [], fn($e) => !$e['agent_id'] || in_array($e['agent_id'], $accessibleIds));

        // Recalculate summary for this user's scope
        $completed = 0;
        $failed = 0;
        $bytesBackedUp = 0;
        $online = 0;
        $offline = 0;
        foreach ($agents as $a) {
            $completed += $a['today_completed'];
            $failed += $a['today_failed'];
            if ($a['status'] === 'online') $online++;
            else $offline++;
        }

        $is24h = \BBS\Core\TimeHelper::is24h();
        $fmtTime = function (string $utc, string $format) use ($tz, $is24h): string {
            $dt = new \DateTime($utc, new \DateTimeZone('UTC'));
            $dt->setTimezone($tz);
            if ($is24h) {
                $format = str_replace(['g:i A T', 'g:i A', 'g:i a'], ['H:i T', 'H:i', 'H:i'], $format);
            }
            return $dt->format($format);
        };

        $reportDate = $data['report_date'] ?? date('Y-m-d');
        $dateFormatted = date('M j, Y', strtotime($reportDate));
        $serverHost = htmlspecialchars($data['server_host'] ?? 'BBS');
        $generatedAt = !empty($data['generated_at']) ? $fmtTime($data['generated_at'], 'Y-m-d g:i A T') : '';

        $html = <<<HTML
        <div style="font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;max-width:700px;margin:0 auto;color:#333;background:#fff;border-radius:8px;">
            <div style="background:#1a1a2e;color:#fff;padding:20px 24px;border-radius:8px 8px 0 0;">
                <h2 style="margin:0 0 4px 0;font-size:20px;">Daily Backup Report</h2>
                <div style="opacity:0.8;font-size:14px;">{$dateFormatted} &mdash; {$serverHost}</div>
            </div>
        HTML;

        // Summary bar
        $totalAgents = count($agents);
        $summaryColor = $failed > 0 ? '#dc3545' : '#28a745';
        $html .= <<<HTML
            <div style="display:flex;background:#f8f9fa;padding:16px 24px;border-bottom:1px solid #dee2e6;gap:32px;flex-wrap:wrap;">
                <div style="text-align:center;min-width:80px;">
                    <div style="font-size:24px;font-weight:700;color:#0d6efd;">{$totalAgents}</div>
                    <div style="font-size:12px;color:#6c757d;">Clients</div>
                </div>
                <div style="text-align:center;min-width:80px;">
                    <div style="font-size:24px;font-weight:700;color:#28a745;">{$completed}</div>
                    <div style="font-size:12px;color:#6c757d;">Completed</div>
                </div>
                <div style="text-align:center;min-width:80px;">
                    <div style="font-size:24px;font-weight:700;color:{$summaryColor};">{$failed}</div>
                    <div style="font-size:12px;color:#6c757d;">Failed</div>
                </div>
                <div style="text-align:center;min-width:80px;">
                    <div style="font-size:24px;font-weight:700;color:#17a2b8;">{$online}</div>
                    <div style="font-size:12px;color:#6c757d;">Online</div>
                </div>
            </div>
        HTML;

        // Client table
        $html .= '<div style="padding:16px 24px;">';
        $html .= '<h3 style="font-size:16px;margin:0 0 12px 0;color:#333;">Client Status</h3>';
        $html .= '<table style="width:100%;border-collapse:collapse;font-size:13px;">';
        $html .= '<tr style="background:#f1f3f5;text-align:left;">'
                . '<th style="padding:8px 10px;border-bottom:2px solid #dee2e6;">Client</th>'
                . '<th style="padding:8px 10px;border-bottom:2px solid #dee2e6;">Status</th>'
                . '<th style="padding:8px 10px;border-bottom:2px solid #dee2e6;">Last Backup</th>'
                . '<th style="padding:8px 10px;border-bottom:2px solid #dee2e6;">Result</th>'
                . '<th style="padding:8px 10px;border-bottom:2px solid #dee2e6;text-align:right;">Files</th>'
                . '<th style="padding:8px 10px;border-bottom:2px solid #dee2e6;text-align:right;">Size</th>'
                . '</tr>';

        foreach ($agents as $agent) {
            $name = htmlspecialchars($agent['name']);
            $statusColor = $agent['status'] === 'online' ? '#28a745' : '#dc3545';
            $statusLabel = ucfirst($agent['status']);
            $statusDot = "<span style='display:inline-block;width:8px;height:8px;border-radius:50%;background:{$statusColor};margin-right:4px;'></span>";

            $lastBackup = '--';
            $result = '--';
            $files = '--';
            $size = '--';

            if ($agent['last_backup']) {
                $lb = $agent['last_backup'];
                $lastBackup = $lb['completed_at'] ? $fmtTime($lb['completed_at'], 'M j, g:i A') : '--';
                if ($lb['status'] === 'completed') {
                    $result = "<span style='color:#28a745;font-weight:600;'>OK</span>";
                } else {
                    $result = "<span style='color:#dc3545;font-weight:600;'>FAILED</span>";
                }
                $files = number_format($lb['files']);
                $size = self::formatBytes($lb['original_size']);
            }

            $todayNote = '';
            if ($agent['today_completed'] > 0 || $agent['today_failed'] > 0) {
                $parts = [];
                if ($agent['today_completed'] > 0) $parts[] = "{$agent['today_completed']} ok";
                if ($agent['today_failed'] > 0) $parts[] = "<span style='color:#dc3545;'>{$agent['today_failed']} failed</span>";
                $todayNote = ' <span style="font-size:11px;color:#6c757d;">(' . implode(', ', $parts) . ' today)</span>';
            }

            $html .= "<tr style='border-bottom:1px solid #eee;'>"
                    . "<td style='padding:8px 10px;'>{$name}{$todayNote}</td>"
                    . "<td style='padding:8px 10px;'>{$statusDot}{$statusLabel}</td>"
                    . "<td style='padding:8px 10px;'>{$lastBackup}</td>"
                    . "<td style='padding:8px 10px;'>{$result}</td>"
                    . "<td style='padding:8px 10px;text-align:right;'>{$files}</td>"
                    . "<td style='padding:8px 10px;text-align:right;'>{$size}</td>"
                    . "</tr>";
        }

        $html .= '</table></div>';

        // Errors section
        if (!empty($errors)) {
            $errorCount = count($errors);
            $html .= '<div style="padding:0 24px 16px;">';
            $html .= "<h3 style='font-size:16px;margin:0 0 12px 0;color:#dc3545;'>Errors ({$errorCount})</h3>";
            $html .= '<table style="width:100%;border-collapse:collapse;font-size:12px;">';
            foreach (array_slice($errors, 0, 20) as $err) {
                $time = $fmtTime($err['created_at'], 'g:i A');
                $errAgent = htmlspecialchars($err['agent_name'] ?? 'System');
                $msg = htmlspecialchars(substr($err['message'], 0, 200));
                $html .= "<tr style='border-bottom:1px solid #f1f3f5;'>"
                        . "<td style='padding:6px 8px;color:#6c757d;white-space:nowrap;'>{$time}</td>"
                        . "<td style='padding:6px 8px;font-weight:600;'>{$errAgent}</td>"
                        . "<td style='padding:6px 8px;'>{$msg}</td>"
                        . "</tr>";
            }
            $html .= '</table>';
            if ($errorCount > 20) {
                $html .= "<div style='font-size:12px;color:#6c757d;margin-top:8px;'>... and " . ($errorCount - 20) . " more</div>";
            }
            $html .= '</div>';
        }

        // Server stats (admin only)
        if ($isAdmin && !empty($data['server'])) {
            $srv = $data['server'];
            $diskPct = $srv['disk_percent'];
            $diskUsed = self::formatBytes($srv['disk_used']);
            $diskTotal = self::formatBytes($srv['disk_total']);
            $diskColor = $diskPct >= 90 ? '#dc3545' : ($diskPct >= 75 ? '#ffc107' : '#28a745');
            $repoCount = $srv['repo_count'];
            $archiveCount = $srv['archive_count'];
            $archiveOriginal = self::formatBytes($srv['archive_original']);
            $archiveDedup = self::formatBytes($srv['archive_dedup']);
            $dedupSavings = $srv['archive_original'] > 0
                ? round((1 - $srv['archive_dedup'] / $srv['archive_original']) * 100, 1) : 0;

            $html .= <<<HTML
                <div style="padding:0 24px 16px;">
                    <h3 style="font-size:16px;margin:0 0 12px 0;color:#333;">Server</h3>
                    <table style="font-size:13px;border-collapse:collapse;">
                        <tr><td style="padding:4px 16px 4px 0;color:#6c757d;">Storage</td>
                            <td style="padding:4px 0;"><span style="color:{$diskColor};font-weight:600;">{$diskPct}%</span> used ({$diskUsed} / {$diskTotal})</td></tr>
                        <tr><td style="padding:4px 16px 4px 0;color:#6c757d;">Repositories</td>
                            <td style="padding:4px 0;">{$repoCount}</td></tr>
                        <tr><td style="padding:4px 16px 4px 0;color:#6c757d;">Archives</td>
                            <td style="padding:4px 0;">{$archiveCount} ({$archiveOriginal} original, {$archiveDedup} deduplicated, {$dedupSavings}% savings)</td></tr>
                    </table>
                </div>
            HTML;
        }

        // Remote storage section
        if (!empty($data['remote_storage'])) {
            $html .= '<div style="padding:0 24px 16px;">';
            $html .= '<h3 style="font-size:16px;margin:0 0 12px 0;color:#333;">Remote Storage</h3>';
            $html .= '<table style="font-size:13px;border-collapse:collapse;">';
            foreach ($data['remote_storage'] as $rs) {
                $rsPct = $rs['disk_percent'];
                $rsColor = $rsPct >= 90 ? '#dc3545' : ($rsPct >= 75 ? '#ffc107' : '#28a745');
                $rsName = htmlspecialchars($rs['name']);
                $rsUsed = self::formatBytes($rs['disk_used']);
                $rsTotal = self::formatBytes($rs['disk_total']);
                $html .= "<tr><td style='padding:4px 16px 4px 0;color:#6c757d;'>{$rsName}</td>"
                        . "<td style='padding:4px 0;'><span style='color:{$rsColor};font-weight:600;'>{$rsPct}%</span> used ({$rsUsed} / {$rsTotal})</td></tr>";
            }
            $html .= '</table></div>';
        }

        // Footer
        $html .= <<<HTML
            <div style="padding:12px 24px;background:#f8f9fa;border-radius:0 0 8px 8px;border-top:1px solid #dee2e6;">
                <div style="font-size:11px;color:#adb5bd;">Generated {$generatedAt} &mdash; Borg Backup Server</div>
            </div>
        </div>
        HTML;

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

        $mailer = new Mailer();
        if (!$mailer->isEnabled()) return false;

        $html = $this->renderHtml($report['data'], $userId);
        $dateFormatted = date('M j, Y', strtotime($report['report_date']));
        $subject = "[BBS] Daily Report — {$dateFormatted}";

        return $mailer->send($toEmail, $subject, $html);
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
        if ($bytes <= 0) return '0 B';
        if ($bytes >= 1099511627776) return round($bytes / 1099511627776, 1) . ' TB';
        if ($bytes >= 1073741824) return round($bytes / 1073741824, 1) . ' GB';
        if ($bytes >= 1048576) return round($bytes / 1048576, 1) . ' MB';
        if ($bytes >= 1024) return round($bytes / 1024, 1) . ' KB';
        return $bytes . ' B';
    }
}
