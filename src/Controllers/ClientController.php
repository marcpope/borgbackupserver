<?php

namespace BBS\Controllers;

use BBS\Core\Controller;
use BBS\Services\SshKeyManager;
use BBS\Services\Encryption;
use BBS\Services\PermissionService;
use BBS\Services\S3SyncService;

class ClientController extends Controller
{
    public function index(): void
    {
        $this->requireAuth();

        [$where, $params] = $this->getAgentWhereClause('a');

        $agents = $this->db->fetchAll("
            SELECT a.*,
                   u.username as owner_name,
                   (SELECT COUNT(*) FROM repositories r WHERE r.agent_id = a.id) as repo_count,
                   (SELECT COUNT(*) FROM schedules s JOIN backup_plans bp ON bp.id = s.backup_plan_id WHERE bp.agent_id = a.id) as schedule_count,
                   (SELECT COALESCE(SUM(r2.size_bytes), 0) FROM repositories r2 WHERE r2.agent_id = a.id) as total_size,
                   (SELECT COUNT(*) FROM archives ar JOIN repositories r3 ON r3.id = ar.repository_id WHERE r3.agent_id = a.id) as restore_points
            FROM agents a
            LEFT JOIN users u ON u.id = a.user_id
            WHERE {$where}
            ORDER BY a.name ASC, a.id DESC
        ", $params);

        // Aggregate stats for stat cards - reuse the same where clause
        $jobScope = $where === '1=1' ? '' : "AND {$where}";
        $jobParams = $params;

        $totalClients = count($agents);
        $onlineCount = 0;
        $offlineCount = 0;
        $errorCount = 0;
        $totalRepos = 0;
        $totalSchedules = 0;
        $totalSize = 0;
        $totalRestorePoints = 0;
        foreach ($agents as $a) {
            if ($a['status'] === 'online') $onlineCount++;
            elseif ($a['status'] === 'offline') $offlineCount++;
            elseif ($a['status'] === 'error') $errorCount++;
            $totalRepos += (int) $a['repo_count'];
            $totalSchedules += (int) $a['schedule_count'];
            $totalSize += (int) $a['total_size'];
            $totalRestorePoints += (int) $a['restore_points'];
        }

        // Active schedules (enabled only)
        $activeSchedules = $this->db->fetchOne("
            SELECT COUNT(*) as cnt
            FROM schedules s
            JOIN backup_plans bp ON bp.id = s.backup_plan_id
            JOIN agents a ON a.id = bp.agent_id
            WHERE s.enabled = 1 AND bp.enabled = 1 AND {$where}
        ", $params)['cnt'];

        $planCount = $this->db->fetchOne("
            SELECT COUNT(*) as cnt
            FROM backup_plans bp
            JOIN agents a ON a.id = bp.agent_id
            WHERE bp.enabled = 1 AND {$where}
        ", $params)['cnt'];

        // Out of date agents — compare against server's bundled agent version
        $latestVersion = null;
        $agentFile = dirname(__DIR__, 2) . '/agent/bbs-agent.py';
        if (file_exists($agentFile)) {
            $handle = fopen($agentFile, 'r');
            if ($handle) {
                for ($i = 0; $i < 50 && ($line = fgets($handle)) !== false; $i++) {
                    if (preg_match('/^AGENT_VERSION\s*=\s*["\']([^"\']+)["\']/m', $line, $m)) {
                        $latestVersion = $m[1];
                        break;
                    }
                }
                fclose($handle);
            }
        }
        if (!$latestVersion) {
            // Fallback to max version from agents
            $latestVersion = $this->db->fetchOne("
                SELECT MAX(agent_version) as v FROM agents a WHERE agent_version IS NOT NULL AND {$where}
            ", $params)['v'];
        }
        $outdatedCount = 0;
        if ($latestVersion) {
            $outdatedCount = $this->db->fetchOne("
                SELECT COUNT(*) as cnt FROM agents a
                WHERE (agent_version IS NULL OR agent_version != ?) AND status != 'setup' AND {$where}
            ", array_merge([$latestVersion], $params))['cnt'];
        }

        // 7-day backup activity chart — group by user's local date, segmented by category
        $utcTz = new \DateTimeZone('UTC');
        $userTz = new \DateTimeZone($_SESSION['timezone'] ?? 'UTC');
        $recentJobs = $this->db->fetchAll("
            SELECT bj.completed_at, bj.status, bj.task_type
            FROM backup_jobs bj
            JOIN agents a ON a.id = bj.agent_id
            WHERE bj.completed_at > DATE_SUB(NOW(), INTERVAL 7 DAY)
              AND bj.status IN ('completed', 'failed')
              {$jobScope}
        ", $jobParams);

        // Group task types into categories. Failed jobs are split between
        // "backup_failed" (actual data-loss risk) and "other_failed" (update
        // jobs for sleeping clients, plugin tests, etc.) so a laptop that
        // was offline at 5am doesn't paint the chart red like a backup
        // disaster happened (#141).
        $categoryMap = [
            'backup' => 'backups',
            'restore' => 'restores', 'restore_mysql' => 'restores', 'restore_pg' => 'restores', 'restore_mongo' => 'restores',
            's3_sync' => 's3_sync',
        ];

        // Convert each timestamp to user's local date, then tally
        $byDay = [];
        foreach ($recentJobs as $job) {
            $dt = new \DateTime($job['completed_at'], $utcTz);
            $dt->setTimezone($userTz);
            $dayKey = $dt->format('Y-m-d');
            if (!isset($byDay[$dayKey])) {
                $byDay[$dayKey] = ['backups' => 0, 's3_sync' => 0, 'backup_failed' => 0, 'other_failed' => 0];
            }
            if ($job['status'] === 'failed') {
                if ($job['task_type'] === 'backup') {
                    $byDay[$dayKey]['backup_failed']++;
                } else {
                    $byDay[$dayKey]['other_failed']++;
                }
            } else {
                $cat = $categoryMap[$job['task_type']] ?? null;
                if ($cat && $cat !== 'restores') {
                    $byDay[$dayKey][$cat] = ($byDay[$dayKey][$cat] ?? 0) + 1;
                }
            }
        }

        // Build 7-day series anchored to "today" in user's timezone
        $chartActivity = [];
        $today = new \DateTime('today', $userTz);
        for ($i = 6; $i >= 0; $i--) {
            $dt = clone $today;
            $dt->modify("-{$i} days");
            $dayKey = $dt->format('Y-m-d');
            $chartActivity[] = [
                'label' => $dt->format('D'),
                'backups' => $byDay[$dayKey]['backups'] ?? 0,
                's3_sync' => $byDay[$dayKey]['s3_sync'] ?? 0,
                'backup_failed' => $byDay[$dayKey]['backup_failed'] ?? 0,
                'other_failed'  => $byDay[$dayKey]['other_failed'] ?? 0,
            ];
        }

        // Storage by client (top 5 + other)
        $storageByClient = [];
        $sorted = $agents;
        usort($sorted, fn($a, $b) => (int)$b['total_size'] - (int)$a['total_size']);
        $otherSize = 0;
        foreach ($sorted as $i => $a) {
            if ($i < 5 && (int)$a['total_size'] > 0) {
                $storageByClient[] = ['name' => $a['name'], 'size' => (int)$a['total_size']];
            } else {
                $otherSize += (int)$a['total_size'];
            }
        }
        if ($otherSize > 0) {
            $storageByClient[] = ['name' => 'Other', 'size' => $otherSize];
        }

        // Format total size for display (use ServerStats for consistency)
        $totalSizeFormatted = $totalSize > 0 ? \BBS\Services\ServerStats::formatBytes((int) $totalSize) : '--';

        $this->view('clients/index', [
            'pageTitle' => 'Clients',
            'agents' => $agents,
            'totalClients' => $totalClients,
            'onlineCount' => $onlineCount,
            'offlineCount' => $offlineCount,
            'errorCount' => $errorCount,
            'totalRepos' => $totalRepos,
            'totalSizeFormatted' => $totalSizeFormatted,
            'activeSchedules' => (int) $activeSchedules,
            'planCount' => (int) $planCount,
            'outdatedCount' => $outdatedCount,
            'latestVersion' => $latestVersion,
            'chartActivity' => $chartActivity,
            'storageByClient' => $storageByClient,
        ]);
    }

    public function add(): void
    {
        $this->requireAdmin();

        $users = $this->db->fetchAll("SELECT id, username FROM users ORDER BY username");

        $this->view('clients/add', [
            'pageTitle' => 'Clients',
            'users' => $users,
        ]);
    }

    public function store(): void
    {
        $this->requireAdmin();
        $this->verifyCsrf();

        $name = trim($_POST['name'] ?? '');
        if (empty($name)) {
            $this->flash('danger', 'Client name is required.');
            $this->redirect('/clients/add');
        }

        $apiKey = bin2hex(random_bytes(32));
        $userId = !empty($_POST['user_id']) ? (int) $_POST['user_id'] : null;

        $id = $this->db->insert('agents', [
            'name' => $name,
            'api_key_hash' => hash('sha256', $apiKey),
            'api_key_encrypted' => \BBS\Services\Encryption::encrypt($apiKey),
            'status' => 'setup',
            'user_id' => $userId,
        ]);

        // Determine SSH home path: use storage_path unless a separate storage location
        // is configured for repos. SSH home dirs MUST be on a local filesystem (chown
        // is required for sshd), so if storage_path points to a NAS/NFS mount that also
        // has a matching storage location, fall back to /var/bbs/home.
        $storageSetting = $this->db->fetchOne("SELECT `value` FROM settings WHERE `key` = 'storage_path'");
        $storagePath = $storageSetting['value'] ?? null;
        if (!$storagePath) {
            $this->db->delete('agents', 'id = ?', [$id]);
            $this->flash('danger', 'Cannot create client — no storage path configured. Go to Settings to set one.');
            $this->redirect('/clients/add');
        }

        // If storage_path matches a storage location, it's intended for repo storage
        // (possibly a NAS/NFS mount). Use /var/bbs/home for SSH home dirs instead.
        $sshHomePath = $storagePath;
        $matchingLocation = $this->db->fetchOne(
            "SELECT id FROM storage_locations WHERE path = ?",
            [rtrim($storagePath, '/')]
        );
        if ($matchingLocation && is_dir('/var/bbs/home')) {
            $sshHomePath = '/var/bbs/home';
        }

        // Create storage directory
        $clientDir = rtrim($sshHomePath, '/') . '/' . $id;
        if (!is_dir($clientDir) && !@mkdir($clientDir, 0755, true)) {
            $this->db->delete('agents', 'id = ?', [$id]);
            $this->flash('danger', "Cannot create client — failed to create storage directory: {$clientDir}. Check permissions on {$sshHomePath}.");
            $this->redirect('/clients/add');
        }

        // Provision SSH access: create Unix user, SSH keys, authorized_keys
        $sshResult = SshKeyManager::provisionClient($id, $name, $sshHomePath);
        if (!$sshResult) {
            // Clean up: remove storage dir and agent record
            @rmdir($clientDir);
            $this->db->delete('agents', 'id = ?', [$id]);
            $this->flash('danger', 'Cannot create client — SSH provisioning failed. Ensure bbs-ssh-helper is installed at /usr/local/bin/bbs-ssh-helper with sudo access. See the Installation Guide: https://github.com/marcpope/borgbackupserver/blob/main/docs/INSTALL.md');
            $this->redirect('/clients/add');
        }

        $this->db->insert('server_log', [
            'agent_id' => $id,
            'level' => 'info',
            'message' => "Client created. SSH provisioned: user {$sshResult['unix_user']}, home {$sshResult['home_dir']}",
        ]);

        $this->flash('success', 'Client created. To install, copy the Install Agent code and run it in a terminal on the client machine.');
        $this->redirect("/clients/{$id}?tab=install");
    }

    public function detail(int $id): void
    {
        $this->requireAuth();

        $agent = $this->getAgent($id);
        if (!$agent) {
            $this->flash('danger', 'Client not found.');
            $this->redirect('/clients');
        }

        // Refresh archive_count from the archives table (cheap and always accurate).
        // Do NOT touch size_bytes here — it's maintained by RepositorySizeService
        // after backup/prune/compact/delete events. Summing archives.deduplicated_size
        // is always less than real on-disk size because it excludes borg repo
        // metadata and uncompacted chunks.
        $this->db->query("
            UPDATE repositories r SET
                r.archive_count = (SELECT COUNT(*) FROM archives a WHERE a.repository_id = r.id)
            WHERE r.agent_id = ?
        ", [$id]);

        $repositories = $this->db->fetchAll("
            SELECT r.*
            FROM repositories r
            WHERE r.agent_id = ?
            ORDER BY r.id DESC
        ", [$id]);

        $plans = $this->db->fetchAll("
            SELECT bp.*, r.name as repo_name, r.storage_type as repo_storage_type, s.frequency, s.times, s.day_of_week, s.day_of_month, s.enabled as schedule_enabled, s.id as schedule_id
            FROM backup_plans bp
            LEFT JOIN repositories r ON r.id = bp.repository_id
            LEFT JOIN schedules s ON s.backup_plan_id = bp.id
            WHERE bp.agent_id = ?
            ORDER BY bp.id DESC
        ", [$id]);

        $recentJobs = $this->db->fetchAll("
            SELECT bj.*, SUBSTRING(bj.error_log, 1, 255) as error_log, r.name as repo_name
            FROM backup_jobs bj
            LEFT JOIN repositories r ON r.id = bj.repository_id
            WHERE bj.agent_id = ?
            ORDER BY bj.queued_at DESC
            LIMIT 20
        ", [$id]);

        $serverHost = $this->db->fetchOne("SELECT `value` FROM settings WHERE `key` = 'server_host'");

        // Templates for schedules tab
        $templates = $this->db->fetchAll("SELECT * FROM backup_templates ORDER BY name");

        // Archives for restore tab
        $archives = $this->db->fetchAll("
            SELECT ar.*, r.name as repo_name, bp.name as plan_name
            FROM archives ar
            JOIN repositories r ON r.id = ar.repository_id
            LEFT JOIN backup_jobs bj ON bj.id = ar.backup_job_id
            LEFT JOIN backup_plans bp ON bp.id = bj.backup_plan_id
            WHERE r.agent_id = ?
            ORDER BY r.name ASC, ar.created_at DESC
        ", [$id]);

        // Check if ClickHouse is available (needed for file catalog browsing)
        $clickhouseAvailable = false;
        try {
            $ch = \BBS\Core\ClickHouse::getInstance();
            $clickhouseAvailable = $ch->isAvailable();
        } catch (\Exception $e) {}

        // Summary stats for header
        $totalSize = array_sum(array_column($repositories, 'size_bytes'));
        $totalArchives = array_sum(array_column($repositories, 'archive_count'));
        $lastJob = $this->db->fetchOne("
            SELECT status, completed_at FROM backup_jobs
            WHERE agent_id = ? AND status IN ('completed','failed')
            ORDER BY completed_at DESC LIMIT 1
        ", [$id]);

        // Users list for owner assignment
        $users = $this->isAdmin() ? $this->db->fetchAll("SELECT id, username FROM users ORDER BY username") : [];

        // Status tab data
        $nextBackup = $this->db->fetchOne("
            SELECT s.next_run, bp.name as plan_name, bp.id as plan_id, s.id as schedule_id
            FROM schedules s
            JOIN backup_plans bp ON bp.id = s.backup_plan_id
            WHERE bp.agent_id = ? AND s.enabled = 1 AND s.next_run IS NOT NULL
            ORDER BY s.next_run ASC LIMIT 1
        ", [$id]);

        $jobStats = $this->db->fetchOne("
            SELECT
                COUNT(*) as total,
                SUM(status = 'completed') as completed,
                SUM(status = 'failed') as failed,
                AVG(CASE WHEN status = 'completed' THEN duration_seconds END) as avg_duration
            FROM (
                SELECT status, duration_seconds FROM backup_jobs
                WHERE agent_id = ? AND task_type = 'backup' AND status IN ('completed','failed')
                ORDER BY completed_at DESC LIMIT 30
            ) recent
        ", [$id]);

        $recentErrors = $this->db->fetchOne(
            "SELECT COUNT(*) as cnt FROM backup_jobs WHERE agent_id = ? AND status = 'failed' AND completed_at > DATE_SUB(NOW(), INTERVAL 7 DAY)",
            [$id]
        );

        $durationChart = $this->db->fetchAll("
            SELECT DATE_FORMAT(completed_at, '%b %d %H:%i') as label,
                   duration_seconds, status, task_type
            FROM backup_jobs
            WHERE agent_id = ? AND task_type = 'backup' AND status IN ('completed','failed') AND completed_at IS NOT NULL
            ORDER BY completed_at DESC LIMIT 30
        ", [$id]);

        // Plugins for schedules and plugins tabs
        $pluginManager = new \BBS\Services\PluginManager();
        $agentPlugins = $pluginManager->getAgentPlugins($id);
        $allPlugins = $pluginManager->getAllPlugins();
        $pluginConfigs = $pluginManager->getPluginConfigs($id);

        // Check if global S3 settings have a bucket configured
        $globalS3Bucket = $this->db->fetchOne("SELECT `value` FROM settings WHERE `key` = 's3_bucket'");
        $globalS3Configured = !empty($globalS3Bucket['value']);

        // Repos with S3 sync enabled (via repository_s3_configs)
        $s3SyncRepos = $this->db->fetchAll("
            SELECT rsc.repository_id, rsc.plugin_config_id, rsc.last_sync_at as last_s3_sync, rsc.enabled
            FROM repository_s3_configs rsc
            JOIN repositories r ON r.id = rsc.repository_id
            WHERE r.agent_id = ?
        ", [$id]);
        $s3SyncByRepo = [];
        foreach ($s3SyncRepos as $sr) {
            $s3SyncByRepo[$sr['repository_id']] = [
                'last_sync' => $sr['last_s3_sync'],
                'plugin_config_id' => $sr['plugin_config_id'],
                'enabled' => $sr['enabled'],
            ];
        }

        // Detect orphaned S3 repos (exist in S3 but not locally)
        $s3Orphans = [];
        $s3PluginConfigId = null;
        // Get any S3 plugin config for this agent (for orphan detection)
        $s3PluginConfig = $this->db->fetchOne("
            SELECT pc.id as plugin_config_id, pc.config
            FROM plugin_configs pc
            JOIN plugins p ON p.id = pc.plugin_id
            WHERE p.slug = 's3_sync' AND pc.agent_id = ?
            LIMIT 1
        ", [$id]);

        if ($s3PluginConfig) {
            $s3PluginConfigId = $s3PluginConfig['plugin_config_id'];
            $config = json_decode($s3PluginConfig['config'] ?? '{}', true) ?: [];
            $s3Service = new S3SyncService();
            $creds = $s3Service->resolveCredentials($config);

            if (!empty($creds['bucket'])) {
                $remoteResult = $s3Service->listRemoteRepos($agent['name'], $creds);
                if ($remoteResult['success'] && !empty($remoteResult['repos'])) {
                    // Get local repo names (sanitized the same way as S3)
                    $localRepoNames = array_map(
                        fn($r) => preg_replace('/[^a-zA-Z0-9_-]/', '_', $r['name']),
                        $repositories
                    );

                    // Find repos that exist in S3 but not locally
                    foreach ($remoteResult['repos'] as $remoteName) {
                        if (!in_array($remoteName, $localRepoNames)) {
                            $s3Orphans[] = $remoteName;
                        }
                    }
                }
            }
        }

        $this->view('clients/detail', [
            'pageTitle' => 'Clients',
            'agent' => $agent,
            'totalSize' => $totalSize,
            'totalArchives' => $totalArchives,
            'lastJob' => $lastJob,
            'users' => $users,
            'repositories' => $repositories,
            'plans' => $plans,
            'recentJobs' => $recentJobs,
            'serverHost' => $serverHost['value'] ?? 'YOUR_SERVER_HOST',
            'archives' => $archives,
            'templates' => $templates,
            'nextBackup' => $nextBackup,
            'jobStats' => $jobStats,
            'recentErrors' => (int) ($recentErrors['cnt'] ?? 0),
            'durationChart' => array_reverse($durationChart),
            'agentPlugins' => $agentPlugins,
            'allPlugins' => $allPlugins,
            'pluginManager' => $pluginManager,
            'pluginConfigs' => $pluginConfigs,
            's3SyncByRepo' => $s3SyncByRepo,
            's3Orphans' => $s3Orphans,
            's3PluginConfigId' => $s3PluginConfigId,
            'globalS3Configured' => $globalS3Configured,
            'remoteSshConfigs' => (new \BBS\Services\RemoteSshService())->getAll(),
            'storageLocations' => $this->db->fetchAll("SELECT * FROM storage_locations ORDER BY is_default DESC, label"),
            'clickhouseAvailable' => $clickhouseAvailable,
        ]);
    }

    public function detailJson(int $id): void
    {
        $this->requireAuth();

        $agent = $this->getAgent($id);
        if (!$agent) {
            http_response_code(404);
            echo json_encode(['error' => 'Not found']);
            return;
        }

        $seenAgo = $agent['last_heartbeat']
            ? \BBS\Core\TimeHelper::ago($agent['last_heartbeat'])
            : 'Never';

        // Refresh archive_count from archives; size_bytes is maintained by
        // RepositorySizeService after backup/prune/compact/delete — don't overwrite
        // it with SUM(deduplicated_size) which excludes repo metadata/uncompacted chunks.
        $this->db->query("
            UPDATE repositories r SET
                r.archive_count = (SELECT COUNT(*) FROM archives a WHERE a.repository_id = r.id)
            WHERE r.agent_id = ?
        ", [$id]);

        $repositories = $this->db->fetchAll("SELECT name, size_bytes, archive_count FROM repositories WHERE agent_id = ? ORDER BY id DESC", [$id]);
        $totalSize = array_sum(array_column($repositories, 'size_bytes'));
        $totalArchives = array_sum(array_column($repositories, 'archive_count'));

        $plans = $this->db->fetchAll("
            SELECT bp.id, bp.name, s.enabled as schedule_enabled, s.id as schedule_id
            FROM backup_plans bp LEFT JOIN schedules s ON s.backup_plan_id = bp.id
            WHERE bp.agent_id = ?
        ", [$id]);

        $lastJob = $this->db->fetchOne("
            SELECT status, completed_at FROM backup_jobs
            WHERE agent_id = ? AND status IN ('completed','failed')
            ORDER BY completed_at DESC LIMIT 1
        ", [$id]);

        $nextBackup = $this->db->fetchOne("
            SELECT s.next_run, bp.name as plan_name
            FROM schedules s JOIN backup_plans bp ON bp.id = s.backup_plan_id
            WHERE bp.agent_id = ? AND s.enabled = 1 AND s.next_run IS NOT NULL
            ORDER BY s.next_run ASC LIMIT 1
        ", [$id]);

        $jobStats = $this->db->fetchOne("
            SELECT COUNT(*) as total, SUM(status = 'completed') as completed, SUM(status = 'failed') as failed,
                   AVG(CASE WHEN status = 'completed' THEN duration_seconds END) as avg_duration
            FROM (SELECT status, duration_seconds FROM backup_jobs
                  WHERE agent_id = ? AND task_type = 'backup' AND status IN ('completed','failed')
                  ORDER BY completed_at DESC LIMIT 30) recent
        ", [$id]);

        $recentErrors = $this->db->fetchOne(
            "SELECT COUNT(*) as cnt FROM backup_jobs WHERE agent_id = ? AND status = 'failed' AND completed_at > DATE_SUB(NOW(), INTERVAL 7 DAY)",
            [$id]
        );

        // Format size
        $sizeDisplay = $totalSize > 0 ? \BBS\Services\ServerStats::formatBytes((int) $totalSize) : '0';

        // Format next backup
        $nextRunLabel = '--';
        $nextRunSub = 'No schedule';
        $pausedCount = 0;
        $totalSchedules = 0;
        foreach ($plans as $p) {
            if ($p['schedule_id'] ?? null) {
                $totalSchedules++;
                if (!($p['schedule_enabled'] ?? false)) $pausedCount++;
            }
        }
        if ($nextBackup && $nextBackup['next_run']) {
            $nextDiff = strtotime($nextBackup['next_run']) - time();
            if ($nextDiff < 0) $nextRunLabel = 'Overdue';
            elseif ($nextDiff < 3600) $nextRunLabel = floor($nextDiff / 60) . 'm';
            elseif ($nextDiff < 86400) $nextRunLabel = floor($nextDiff / 3600) . 'h ' . floor(($nextDiff % 3600) / 60) . 'm';
            else $nextRunLabel = floor($nextDiff / 86400) . 'd ' . floor(($nextDiff % 86400) / 3600) . 'h';
            $nextRunSub = $nextBackup['plan_name'];
        } elseif ($pausedCount > 0) {
            $nextRunLabel = 'No Jobs';
            $nextRunSub = $pausedCount . ' Paused Schedule' . ($pausedCount > 1 ? 's' : '');
        }

        // Format avg duration
        $avgDuration = (int) ($jobStats['avg_duration'] ?? 0);
        $avgDurLabel = $avgDuration >= 60 ? floor($avgDuration / 60) . 'm ' . ($avgDuration % 60) . 's' : $avgDuration . 's';

        // Format last backup
        $lastBackupLabel = $lastJob ? \BBS\Core\TimeHelper::format($lastJob['completed_at'], 'M j g:ia') : '--';
        $lastBackupStatus = $lastJob ? $lastJob['status'] : null;

        $successRate = ($jobStats['total'] ?? 0) > 0
            ? round(($jobStats['completed'] / $jobStats['total']) * 100) : 0;

        header('Content-Type: application/json');
        echo json_encode([
            'status' => $agent['status'],
            'last_heartbeat' => $agent['last_heartbeat'],
            'seen_ago' => $seenAgo,
            'agent_version' => $agent['agent_version'],
            'repos_count' => count($repositories),
            'total_archives' => $totalArchives,
            'size_display' => $sizeDisplay,
            'plans_count' => count($plans),
            'last_backup_label' => $lastBackupLabel,
            'last_backup_status' => $lastBackupStatus,
            'next_run_label' => $nextRunLabel,
            'next_run_sub' => $nextRunSub,
            'avg_duration' => $avgDurLabel,
            'success_rate' => $successRate,
            'completed_jobs' => (int) ($jobStats['completed'] ?? 0),
            'total_jobs' => (int) ($jobStats['total'] ?? 0),
            'recent_errors' => (int) ($recentErrors['cnt'] ?? 0),
            'repositories' => array_map(fn($r) => [
                'name' => $r['name'],
                'size_bytes' => (int) $r['size_bytes'],
                'archive_count' => (int) $r['archive_count'],
            ], $repositories),
        ]);
    }

    public function repos(int $id): void
    {
        $this->detail($id);
    }

    public function schedules(int $id): void
    {
        $this->detail($id);
    }

    public function restore(int $id): void
    {
        $this->detail($id);
    }

    /**
     * GET /clients/{id}/catalog/{archive_id}
     * Returns paginated file catalog as JSON for AJAX.
     */
    public function catalog(int $id, int $archive_id): void
    {
        $this->requireAuth();

        $agent = $this->getAgent($id);
        if (!$agent) {
            $this->json(['error' => 'Client not found'], 404);
        }

        $ch = \BBS\Core\ClickHouse::getInstance();
        $search = trim($_GET['search'] ?? '');
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $perPage = 100;
        $offset = ($page - 1) * $perPage;

        $where = 'agent_id = ? AND archive_id = ?';
        $params = [$id, $archive_id];

        if ($search !== '') {
            $where .= ' AND (file_name LIKE ? OR path LIKE ?)';
            $params[] = "%{$search}%";
            $params[] = "%{$search}%";
        }

        $total = $ch->fetchOne(
            "SELECT count() as cnt FROM file_catalog WHERE {$where}",
            $params
        );

        $files = $ch->fetchAll(
            "SELECT path as file_path, file_name, file_size, status,
                    formatDateTime(mtime, '%Y-%m-%d %H:%i:%S') as mtime
             FROM file_catalog
             WHERE {$where}
             ORDER BY path
             LIMIT {$perPage} OFFSET {$offset}",
            $params
        );

        $this->json([
            'files' => $files,
            'total' => (int) ($total['cnt'] ?? 0),
            'page' => $page,
            'pages' => max(1, ceil(($total['cnt'] ?? 0) / $perPage)),
        ]);
    }

    /**
     * GET /clients/{id}/catalog/{archive_id}/tree
     * Returns directory tree children for a given path prefix.
     */
    public function catalogTree(int $id, int $archive_id): void
    {
        $this->requireAuth();

        $agent = $this->getAgent($id);
        if (!$agent) {
            $this->json(['error' => 'Client not found'], 404);
        }

        $ch = \BBS\Core\ClickHouse::getInstance();
        $prefix = $_GET['path'] ?? '/';
        // Ensure prefix ends with /
        if ($prefix !== '/' && !str_ends_with($prefix, '/')) {
            $prefix .= '/';
        }

        // parent_dir for this level: strip trailing slash (root stays as '/')
        $parentDir = $prefix === '/' ? '/' : rtrim($prefix, '/');

        // Get subdirectories from catalog_dirs (exact match, instant via ORDER BY)
        $dirs = $ch->fetchAll("
            SELECT name, dir_path, file_count, total_size
            FROM catalog_dirs
            WHERE agent_id = ? AND archive_id = ? AND parent_dir = ?
            ORDER BY name
        ", [$id, $archive_id, $parentDir]);

        $directories = [];
        foreach ($dirs as $d) {
            $directories[] = [
                'name' => $d['name'],
                'path' => $d['dir_path'] . '/',
                'file_count' => (int) $d['file_count'],
                'total_size' => (int) $d['total_size'],
            ];
        }

        // Get files directly at this level (exact parent_dir match)
        $files = $ch->fetchAll("
            SELECT path as file_path, file_name, file_size, status,
                   formatDateTime(mtime, '%Y-%m-%d %H:%i:%S') as mtime
            FROM file_catalog
            WHERE agent_id = ? AND archive_id = ? AND parent_dir = ? AND status != 'D'
            ORDER BY file_name
        ", [$id, $archive_id, $parentDir]);

        $this->json([
            'dirs' => $directories,
            'files' => $files,
            'path' => $prefix,
        ]);
    }

    /**
     * GET /clients/{id}/catalog/search-all
     * Search for a file across ALL archives for this agent, showing version history.
     */
    public function catalogSearchAll(int $id): void
    {
        $this->requireAuth();

        $agent = $this->getAgent($id);
        if (!$agent) {
            $this->json(['error' => 'Client not found'], 404);
        }

        $search = trim($_GET['q'] ?? '');
        if ($search === '') {
            $this->json(['files' => [], 'total' => 0, 'page' => 1, 'pages' => 1]);
            return;
        }

        $ch = \BBS\Core\ClickHouse::getInstance();
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $perPage = 20;

        // Find distinct file paths matching the search
        $countRow = $ch->fetchOne(
            "SELECT uniqExact(path) as cnt
             FROM file_catalog
             WHERE agent_id = ? AND (file_name LIKE ? OR path LIKE ?)",
            [$id, "%{$search}%", "%{$search}%"]
        );
        $total = (int) ($countRow['cnt'] ?? 0);
        $pages = max(1, ceil($total / $perPage));
        $offset = ($page - 1) * $perPage;

        // Get the matching paths (paginated by unique path)
        $pathRows = $ch->fetchAll(
            "SELECT DISTINCT path, file_name
             FROM file_catalog
             WHERE agent_id = ? AND (file_name LIKE ? OR path LIKE ?)
             ORDER BY path
             LIMIT {$perPage} OFFSET {$offset}",
            [$id, "%{$search}%", "%{$search}%"]
        );

        if (empty($pathRows)) {
            $this->json(['files' => [], 'total' => $total, 'page' => $page, 'pages' => $pages]);
            return;
        }

        // Get all versions for these paths from ClickHouse
        $paths = array_column($pathRows, 'path');
        $pathList = implode(', ', array_map(fn($p) => "'" . str_replace(["\\", "'"], ["\\\\", "\\'"], $p) . "'", $paths));

        $versions = $ch->fetchAll(
            "SELECT path, archive_id, file_size, status,
                    formatDateTime(mtime, '%Y-%m-%d %H:%i:%S') as mtime
             FROM file_catalog
             WHERE agent_id = {$id} AND path IN ({$pathList})
             ORDER BY path, archive_id DESC"
        );

        // Get archive metadata from MySQL
        $archiveIds = array_unique(array_column($versions, 'archive_id'));
        $archiveMap = [];
        if (!empty($archiveIds)) {
            $placeholders = implode(',', array_fill(0, count($archiveIds), '?'));
            $archiveRows = $this->db->fetchAll("
                SELECT ar.id, ar.archive_name, ar.created_at as archive_date,
                       r.name as repo_name
                FROM archives ar
                JOIN repositories r ON r.id = ar.repository_id
                WHERE ar.id IN ({$placeholders})
            ", $archiveIds);
            foreach ($archiveRows as $ar) {
                $archiveMap[$ar['id']] = $ar;
            }
        }

        // Merge archive metadata into versions
        $versionMap = [];
        foreach ($versions as $v) {
            $ar = $archiveMap[$v['archive_id']] ?? null;
            $versionMap[$v['path']][] = [
                'path' => $v['path'],
                'archive_id' => (int) $v['archive_id'],
                'file_size' => $v['file_size'],
                'status' => $v['status'],
                'mtime' => $v['mtime'],
                'archive_name' => $ar['archive_name'] ?? null,
                'archive_date' => $ar['archive_date'] ?? null,
                'repo_name' => $ar['repo_name'] ?? null,
            ];
        }

        // Build response
        $files = [];
        foreach ($pathRows as $pr) {
            $files[] = [
                'path' => $pr['path'],
                'file_name' => $pr['file_name'],
                'versions' => $versionMap[$pr['path']] ?? [],
            ];
        }

        $this->json([
            'files' => $files,
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
        ]);
    }

    /**
     * POST /clients/{id}/restore
     * Creates a restore job for selected files from an archive.
     */
    public function restoreSubmit(int $id): void
    {
        $this->requireAuth();
        $this->verifyCsrf();

        $agent = $this->getAgent($id);
        if (!$agent) {
            $this->flash('danger', 'Client not found.');
            $this->redirect('/clients');
        }

        // Require restore permission
        $this->requirePermission(PermissionService::RESTORE, $id);

        $archive_id = (int) ($_POST['archive_id'] ?? 0);
        $selectedFiles = $_POST['files'] ?? [];
        $destination = trim($_POST['destination'] ?? '');

        if (!$archive_id) {
            $this->flash('danger', 'Select an archive to restore.');
            $this->redirect("/clients/{$id}?tab=restore");
        }

        // Get archive and repo info
        $archive = $this->db->fetchOne("
            SELECT ar.*, r.path as repo_path, r.passphrase_encrypted
            FROM archives ar
            JOIN repositories r ON r.id = ar.repository_id
            WHERE ar.id = ? AND r.agent_id = ?
        ", [$archive_id, $id]);

        if (!$archive) {
            $this->flash('danger', 'Archive not found.');
            $this->redirect("/clients/{$id}?tab=restore");
        }


        // Create restore job
        $jobId = $this->db->insert('backup_jobs', [
            'agent_id' => $id,
            'backup_plan_id' => null,
            'repository_id' => $archive['repository_id'],
            'task_type' => 'restore',
            'status' => 'queued',
            'queued_at' => date('Y-m-d H:i:s'),
            'restore_archive_id' => $archive_id,
            'restore_paths' => json_encode($selectedFiles),
            'restore_destination' => $destination ?: null,
        ]);

        $this->db->insert('server_log', [
            'agent_id' => $id,
            'backup_job_id' => $jobId,
            'level' => 'info',
            'message' => empty($selectedFiles)
                ? "Restore queued: full archive {$archive['archive_name']}"
                : "Restore queued: " . count($selectedFiles) . " paths from archive {$archive['archive_name']}",
        ]);

        $this->flash('success', 'Restore job queued. It will run when a slot is available.');
        $this->redirect("/queue/{$jobId}");
    }

    /**
     * GET /clients/{id}/archive/{archive_id}/databases
     * Returns the list of databases backed up in this archive (JSON).
     */
    public function archiveDatabases(int $id, int $archive_id): void
    {
        $this->requireAuth();

        $agent = $this->getAgent($id);
        if (!$agent) {
            $this->json(['error' => 'Client not found'], 404);
        }

        $archive = $this->db->fetchOne("
            SELECT ar.databases_backed_up, ar.created_at
            FROM archives ar
            JOIN repositories r ON r.id = ar.repository_id
            WHERE ar.id = ? AND r.agent_id = ?
        ", [$archive_id, $id]);

        if (!$archive) {
            $this->json(['error' => 'Archive not found'], 404);
        }

        $data = $archive['databases_backed_up'] ? json_decode($archive['databases_backed_up'], true) : null;
        $databases = $data['databases'] ?? [];
        $compress = $data['compress'] ?? true;

        // Look up dump file mtimes from the file catalog
        $mtimes = [];
        if (!empty($databases)) {
            $dumpDir = $data['dump_dir'] ?? null;
            if (!$dumpDir) {
                // Try to find dump_dir from plugin configs for this agent
                $pluginManager = new \BBS\Services\PluginManager();
                $configs = $pluginManager->getPluginConfigs($id);
                foreach ($configs as $c) {
                    if (in_array($c['slug'], ['mysql_dump', 'pg_dump'])) {
                        $cfgData = json_decode($c['config_data'] ?? '{}', true);
                        if (!empty($cfgData['dump_dir'])) {
                            $dumpDir = rtrim($cfgData['dump_dir'], '/');
                            break;
                        }
                    }
                }
            }
            if ($dumpDir) {
                $patterns = [];
                foreach ($databases as $db) {
                    $ext = $compress ? '.sql.gz' : '.sql';
                    $patterns[] = $dumpDir . '/' . $db . $ext;
                }
                $ch = \BBS\Core\ClickHouse::getInstance();
                $pathList = implode(', ', array_map(fn($p) => "'" . str_replace(["\\", "'"], ["\\\\", "\\'"], $p) . "'", $patterns));
                $rows = $ch->fetchAll("
                    SELECT path, formatDateTime(mtime, '%Y-%m-%d %H:%i:%S') as mtime
                    FROM file_catalog
                    WHERE agent_id = {$id} AND archive_id = {$archive_id} AND path IN ({$pathList})
                ");
                foreach ($rows as $row) {
                    $basename = basename($row['path']);
                    $dbName = preg_replace('/\\.sql(\\.gz)?$/', '', $basename);
                    $mtimes[$dbName] = $row['mtime'] ? \BBS\Core\TimeHelper::format($row['mtime'], 'M j, Y g:i A') : null;
                }
            }
        }

        $this->json([
            'databases' => $databases,
            'per_database' => $data['per_database'] ?? true,
            'compress' => $compress,
            'backed_up_at' => $archive['created_at'] ? \BBS\Core\TimeHelper::format($archive['created_at'], 'M j, Y g:i A') : null,
            'mtimes' => $mtimes,
        ]);
    }

    /**
     * POST /clients/{id}/restore-mysql
     * Creates a MySQL database restore job.
     */
    public function restoreMysqlSubmit(int $id): void
    {
        $this->requireAuth();
        $this->verifyCsrf();

        $agent = $this->getAgent($id);
        if (!$agent) {
            $this->flash('danger', 'Client not found.');
            $this->redirect('/clients');
        }

        // Require restore permission
        $this->requirePermission(PermissionService::RESTORE, $id);

        $archive_id = (int) ($_POST['archive_id'] ?? 0);
        $databases = $_POST['databases'] ?? [];
        $pluginConfigId = (int) ($_POST['plugin_config_id'] ?? 0);

        if (!$archive_id || empty($databases)) {
            $this->flash('danger', 'Select an archive and at least one database to restore.');
            $this->redirect("/clients/{$id}?tab=restore");
        }

        // Validate plugin config exists and belongs to this agent
        if ($pluginConfigId) {
            $pluginManager = new \BBS\Services\PluginManager();
            $configCheck = $pluginManager->getPluginConfig($pluginConfigId);
            if (!$configCheck || $configCheck['agent_id'] != $id || $configCheck['slug'] !== 'mysql_dump') {
                $this->flash('danger', 'Invalid MySQL connection selected.');
                $this->redirect("/clients/{$id}?tab=restore");
            }
        }

        // Validate archive exists and has database info
        $archive = $this->db->fetchOne("
            SELECT ar.*, r.path as repo_path, r.passphrase_encrypted
            FROM archives ar
            JOIN repositories r ON r.id = ar.repository_id
            WHERE ar.id = ? AND r.agent_id = ?
        ", [$archive_id, $id]);

        if (!$archive || empty($archive['databases_backed_up'])) {
            $this->flash('danger', 'Archive not found or has no database backup info.');
            $this->redirect("/clients/{$id}?tab=restore");
        }

        // Check mysql_dump plugin is enabled
        $pluginManager = new \BBS\Services\PluginManager();
        $enabledPlugins = $pluginManager->getEnabledAgentPlugins($id);
        $mysqlEnabled = false;
        foreach ($enabledPlugins as $p) {
            if ($p['slug'] === 'mysql_dump') {
                $mysqlEnabled = true;
                break;
            }
        }
        if (!$mysqlEnabled) {
            $this->flash('danger', 'MySQL plugin is not enabled for this client.');
            $this->redirect("/clients/{$id}?tab=restore");
        }


        // Build restore_databases JSON: [{database: name, mode: replace|rename, target_name: ...}, ...]
        $restoreDatabases = [];
        foreach ($databases as $entry) {
            $dbName = $entry['name'] ?? '';
            $mode = $entry['mode'] ?? 'replace';
            if ($dbName && in_array($mode, ['replace', 'rename'])) {
                $item = ['database' => $dbName, 'mode' => $mode];
                if ($mode === 'rename' && !empty($entry['target_name'])) {
                    $item['target_name'] = preg_replace('/[^a-zA-Z0-9_]/', '', $entry['target_name']);
                }
                $restoreDatabases[] = $item;
            }
        }

        if (empty($restoreDatabases)) {
            $this->flash('danger', 'No valid databases selected.');
            $this->redirect("/clients/{$id}?tab=restore");
        }

        $jobId = $this->db->insert('backup_jobs', [
            'agent_id' => $id,
            'backup_plan_id' => null,
            'repository_id' => $archive['repository_id'],
            'task_type' => 'restore_mysql',
            'status' => 'queued',
            'queued_at' => date('Y-m-d H:i:s'),
            'restore_archive_id' => $archive_id,
            'restore_databases' => json_encode($restoreDatabases),
            'plugin_config_id' => $pluginConfigId ?: null,
        ]);

        $dbNames = array_column($restoreDatabases, 'database');
        $this->db->insert('server_log', [
            'agent_id' => $id,
            'backup_job_id' => $jobId,
            'level' => 'info',
            'message' => "MySQL restore queued: " . implode(', ', $dbNames) . " from archive {$archive['archive_name']}",
        ]);

        $this->flash('success', 'MySQL restore job queued. It will run when a slot is available.');
        $this->redirect("/queue/{$jobId}");
    }

    /**
     * Submit a PostgreSQL database restore job.
     * POST /clients/{id}/restore-pg
     */
    public function restorePgSubmit(int $id): void
    {
        $this->requireAuth();
        $this->verifyCsrf();

        $agent = $this->getAgent($id);
        if (!$agent) {
            $this->flash('danger', 'Client not found.');
            $this->redirect('/clients');
        }

        // Require restore permission
        $this->requirePermission(PermissionService::RESTORE, $id);

        $archive_id = (int) ($_POST['archive_id'] ?? 0);
        $databases = $_POST['databases'] ?? [];
        $pluginConfigId = (int) ($_POST['plugin_config_id'] ?? 0);

        if (!$archive_id || empty($databases)) {
            $this->flash('danger', 'Select an archive and at least one database to restore.');
            $this->redirect("/clients/{$id}?tab=restore");
        }

        if ($pluginConfigId) {
            $pluginManager = new \BBS\Services\PluginManager();
            $configCheck = $pluginManager->getPluginConfig($pluginConfigId);
            if (!$configCheck || $configCheck['agent_id'] != $id || $configCheck['slug'] !== 'pg_dump') {
                $this->flash('danger', 'Invalid PostgreSQL connection selected.');
                $this->redirect("/clients/{$id}?tab=restore");
            }
        }

        $archive = $this->db->fetchOne("
            SELECT ar.*, r.path as repo_path, r.passphrase_encrypted
            FROM archives ar
            JOIN repositories r ON r.id = ar.repository_id
            WHERE ar.id = ? AND r.agent_id = ?
        ", [$archive_id, $id]);

        if (!$archive || empty($archive['databases_backed_up'])) {
            $this->flash('danger', 'Archive not found or has no database backup info.');
            $this->redirect("/clients/{$id}?tab=restore");
        }

        $pluginManager = new \BBS\Services\PluginManager();
        $enabledPlugins = $pluginManager->getEnabledAgentPlugins($id);
        $pgEnabled = false;
        foreach ($enabledPlugins as $p) {
            if ($p['slug'] === 'pg_dump') {
                $pgEnabled = true;
                break;
            }
        }
        if (!$pgEnabled) {
            $this->flash('danger', 'PostgreSQL plugin is not enabled for this client.');
            $this->redirect("/clients/{$id}?tab=restore");
        }

        $restoreDatabases = [];
        foreach ($databases as $entry) {
            $dbName = $entry['name'] ?? '';
            $mode = $entry['mode'] ?? 'replace';
            if ($dbName && in_array($mode, ['replace', 'rename'])) {
                $item = ['database' => $dbName, 'mode' => $mode];
                if ($mode === 'rename' && !empty($entry['target_name'])) {
                    $item['target_name'] = preg_replace('/[^a-zA-Z0-9_]/', '', $entry['target_name']);
                }
                $restoreDatabases[] = $item;
            }
        }

        if (empty($restoreDatabases)) {
            $this->flash('danger', 'No valid databases selected.');
            $this->redirect("/clients/{$id}?tab=restore");
        }

        $jobId = $this->db->insert('backup_jobs', [
            'agent_id' => $id,
            'backup_plan_id' => null,
            'repository_id' => $archive['repository_id'],
            'task_type' => 'restore_pg',
            'status' => 'queued',
            'queued_at' => date('Y-m-d H:i:s'),
            'restore_archive_id' => $archive_id,
            'restore_databases' => json_encode($restoreDatabases),
            'plugin_config_id' => $pluginConfigId ?: null,
        ]);

        $dbNames = array_column($restoreDatabases, 'database');
        $this->db->insert('server_log', [
            'agent_id' => $id,
            'backup_job_id' => $jobId,
            'level' => 'info',
            'message' => "PostgreSQL restore queued: " . implode(', ', $dbNames) . " from archive {$archive['archive_name']}",
        ]);

        $this->flash('success', 'PostgreSQL restore job queued. It will run when a slot is available.');
        $this->redirect("/queue/{$jobId}");
    }

    /**
     * Submit a MongoDB database restore job.
     * POST /clients/{id}/restore-mongo
     */
    public function restoreMongoSubmit(int $id): void
    {
        $this->requireAuth();
        $this->verifyCsrf();

        $agent = $this->getAgent($id);
        if (!$agent) {
            $this->flash('danger', 'Client not found.');
            $this->redirect('/clients');
        }

        // Require restore permission
        $this->requirePermission(PermissionService::RESTORE, $id);

        $archive_id = (int) ($_POST['archive_id'] ?? 0);
        $databases = $_POST['databases'] ?? [];
        $pluginConfigId = (int) ($_POST['plugin_config_id'] ?? 0);

        if (!$archive_id || empty($databases)) {
            $this->flash('danger', 'Select an archive and at least one database to restore.');
            $this->redirect("/clients/{$id}?tab=restore");
        }

        $pluginManager = new \BBS\Services\PluginManager($this->db);
        if ($pluginConfigId) {
            $configCheck = $pluginManager->getPluginConfig($pluginConfigId);
            if (!$configCheck || $configCheck['agent_id'] != $id || $configCheck['slug'] !== 'mongo_dump') {
                $this->flash('danger', 'Invalid MongoDB connection selected.');
                $this->redirect("/clients/{$id}?tab=restore");
            }
        }

        $archive = $this->db->fetchOne("
            SELECT ar.*, r.path as repo_path, r.passphrase_encrypted
            FROM archives ar
            JOIN repositories r ON r.id = ar.repository_id
            WHERE ar.id = ? AND r.agent_id = ?
        ", [$archive_id, $id]);

        if (!$archive || empty($archive['databases_backed_up'])) {
            $this->flash('danger', 'Archive not found or has no database backup info.');
            $this->redirect("/clients/{$id}?tab=restore");
        }

        $enabledPlugins = $pluginManager->getEnabledAgentPlugins($id);
        $mongoEnabled = false;
        foreach ($enabledPlugins as $p) {
            if ($p['slug'] === 'mongo_dump') {
                $mongoEnabled = true;
                break;
            }
        }
        if (!$mongoEnabled) {
            $this->flash('danger', 'MongoDB plugin is not enabled for this client.');
            $this->redirect("/clients/{$id}?tab=restore");
        }

        $restoreDatabases = [];
        foreach ($databases as $entry) {
            $dbName = $entry['name'] ?? '';
            $mode = $entry['mode'] ?? 'replace';
            if ($dbName && in_array($mode, ['replace', 'rename'])) {
                $item = ['database' => $dbName, 'mode' => $mode];
                if ($mode === 'rename' && !empty($entry['target_name'])) {
                    $item['target_name'] = preg_replace('/[^a-zA-Z0-9_]/', '', $entry['target_name']);
                }
                $restoreDatabases[] = $item;
            }
        }

        if (empty($restoreDatabases)) {
            $this->flash('danger', 'No valid databases selected.');
            $this->redirect("/clients/{$id}?tab=restore");
        }

        $jobId = $this->db->insert('backup_jobs', [
            'agent_id' => $id,
            'backup_plan_id' => null,
            'repository_id' => $archive['repository_id'],
            'task_type' => 'restore_mongo',
            'status' => 'queued',
            'queued_at' => date('Y-m-d H:i:s'),
            'restore_archive_id' => $archive_id,
            'restore_databases' => json_encode($restoreDatabases),
            'plugin_config_id' => $pluginConfigId ?: null,
        ]);

        $dbNames = array_column($restoreDatabases, 'database');
        $this->db->insert('server_log', [
            'agent_id' => $id,
            'backup_job_id' => $jobId,
            'level' => 'info',
            'message' => "MongoDB restore queued: " . implode(', ', $dbNames) . " from archive {$archive['archive_name']}",
        ]);

        $this->flash('success', 'MongoDB restore job queued. It will run when a slot is available.');
        $this->redirect("/queue/{$jobId}");
    }

    /**
     * POST /clients/{id}/download
     * Extracts selected paths from a borg archive on the server and streams as tar.gz.
     */
    public function download(int $id): void
    {
        $this->requireAuth();
        $this->verifyCsrf();

        $agent = $this->getAgent($id);
        if (!$agent) {
            $this->flash('danger', 'Client not found.');
            $this->redirect('/clients');
        }

        // Require restore permission (download is a form of restore)
        $this->requirePermission(PermissionService::RESTORE, $id);

        $archive_id = (int) ($_POST['archive_id'] ?? 0);
        $selectedFiles = $_POST['files'] ?? [];

        if (!$archive_id) {
            $this->flash('danger', 'Select an archive to download.');
            $this->redirect("/clients/{$id}?tab=restore");
        }

        $archive = $this->db->fetchOne("
            SELECT ar.*, r.path as repo_path, r.passphrase_encrypted, r.encryption,
                   r.agent_id as repo_agent_id, r.name as repo_name,
                   r.storage_type, r.storage_location_id, r.remote_ssh_config_id,
                   rsc.remote_host, rsc.remote_port, rsc.remote_user,
                   rsc.ssh_private_key_encrypted as remote_ssh_key_encrypted,
                   rsc.borg_remote_path
            FROM archives ar
            JOIN repositories r ON r.id = ar.repository_id
            LEFT JOIN remote_ssh_configs rsc ON rsc.id = r.remote_ssh_config_id
            WHERE ar.id = ? AND r.agent_id = ?
        ", [$archive_id, $id]);

        if (!$archive) {
            $this->flash('danger', 'Archive not found.');
            $this->redirect("/clients/{$id}?tab=restore");
        }

        // Build environment — server-side execution uses local paths
        $repo = [
            'path' => $archive['repo_path'],
            'passphrase_encrypted' => $archive['passphrase_encrypted'],
            'encryption' => $archive['encryption'],
            'agent_id' => $archive['repo_agent_id'] ?? $id,
            'name' => $archive['repo_name'] ?? '',
            'storage_type' => $archive['storage_type'] ?? 'local',
            'storage_location_id' => $archive['storage_location_id'] ?? null,
        ];
        $isRemoteSsh = ($repo['storage_type'] === 'remote_ssh');
        $localPath = $isRemoteSsh ? $archive['repo_path'] : \BBS\Services\BorgCommandBuilder::getLocalRepoPath($repo);
        $env = \BBS\Services\BorgCommandBuilder::buildEnv($repo, false);

        // Create temp directory for extraction
        $tmpDir = '/tmp/bbs-download-' . bin2hex(random_bytes(8));
        mkdir($tmpDir, 0700, true);

        $remoteSshKeyFile = null; // Track temp SSH key for cleanup

        try {
            // Build borg extract args: repo::archive + selected paths
            $borgArgs = [$localPath . '::' . $archive['archive_name']];
            foreach ($selectedFiles as $path) {
                $path = ltrim($path, '/');
                if ($path !== '') {
                    $borgArgs[] = rtrim($path, '/');
                }
            }

            if ($isRemoteSsh && !empty($archive['remote_ssh_key_encrypted'])) {
                // Remote SSH repos: run borg extract over SSH from BBS server
                try {
                    $sshKey = \BBS\Services\Encryption::decrypt($archive['remote_ssh_key_encrypted']);
                } catch (\Exception $e) {
                    $sshKey = $archive['remote_ssh_key_encrypted'];
                }
                $remoteSshKeyFile = tempnam(sys_get_temp_dir(), 'bbs-ssh-');
                // Normalize line endings (Windows \r\n → Unix \n) and ensure trailing newline
                $sshKey = str_replace("\r\n", "\n", $sshKey);
                $sshKey = str_replace("\r", "\n", $sshKey);
                $sshKey = rtrim($sshKey) . "\n";
                file_put_contents($remoteSshKeyFile, $sshKey);
                chmod($remoteSshKeyFile, 0600);

                $port = (int) ($archive['remote_port'] ?? 22);
                $env['BORG_RSH'] = "ssh -i {$remoteSshKeyFile} -p {$port} -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o BatchMode=yes";

                $cmd = ['borg', 'extract'];
                if (!empty($archive['borg_remote_path'])) {
                    $cmd[] = '--remote-path=' . $archive['borg_remote_path'];
                }
                $cmd = array_merge($cmd, $borgArgs);

                $envStrings = [];
                foreach ($_SERVER as $k => $v) {
                    if (is_string($v)) $envStrings[$k] = $v;
                }
                foreach ($env as $k => $v) {
                    $envStrings[$k] = $v;
                }
            } else {
                // Local repos: Use SSH helper to run borg extract as the repo-owning user
                // (www-data can't read repo files owned by the bbs-* user)
                $sshUser = $agent['ssh_unix_user'] ?? '';
                $useHelper = !empty($sshUser);
                if ($useHelper) {
                    // Passphrase piped on stdin ("-" marker) so it's not in argv.
                    $passphrase = $env['BORG_PASSPHRASE'] ?? '';
                    $cmd = ['sudo', '/usr/local/bin/bbs-ssh-helper', 'borg-extract', $sshUser, $tmpDir, '-'];
                    $cmd = array_merge($cmd, $borgArgs);

                    $envStrings = null; // helper handles env; null inherits current env (for PATH)
                } else {
                    // Fallback: run directly as www-data (non-SSH repos)
                    $cmd = array_merge(['borg', 'extract'], $borgArgs);
                    $envStrings = [];
                    foreach ($_SERVER as $k => $v) {
                        if (is_string($v)) $envStrings[$k] = $v;
                    }
                    foreach ($env as $k => $v) {
                        $envStrings[$k] = $v;
                    }
                }
            }

            $desc = [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ];

            $proc = proc_open($cmd, $desc, $pipes, $tmpDir, $envStrings);
            if (!is_resource($proc)) {
                throw new \RuntimeException('Failed to run borg extract');
            }

            if (!empty($useHelper)) {
                fwrite($pipes[0], ($passphrase ?? '') . "\n");
            }
            fclose($pipes[0]);
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exitCode = proc_close($proc);

            $borgOutput = trim($stdout . "\n" . $stderr);

            if ($exitCode > 1) {
                // Log the full borg output so it's visible even after redirect
                $this->db->insert('server_log', [
                    'agent_id' => $id,
                    'level' => 'error',
                    'message' => "Download failed — borg extract exit {$exitCode}: " . substr($borgOutput, 0, 4000),
                ]);
                throw new \RuntimeException('borg extract failed (exit ' . $exitCode . '): ' . substr($borgOutput, 0, 500));
            }

            // Fix permissions so www-data can read extracted files
            // (the helper's post-extract chmod may not complete due to pipe closure)
            exec('sudo /usr/local/bin/bbs-ssh-helper fix-download-perms ' . escapeshellarg($tmpDir) . ' 2>&1');

            // Check if anything was extracted
            $extractedFiles = [];
            try {
                $rii = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($tmpDir, \RecursiveDirectoryIterator::SKIP_DOTS),
                    \RecursiveIteratorIterator::SELF_FIRST,
                    \RecursiveIteratorIterator::CATCH_GET_CHILD
                );
                foreach ($rii as $file) {
                    if ($file->isFile()) {
                        $extractedFiles[] = $file->getPathname();
                    }
                }
            } catch (\Exception $iterErr) {
                // Iteration itself failed — log and continue to the empty-check
                $this->db->insert('server_log', [
                    'agent_id' => $id,
                    'level' => 'warning',
                    'message' => "Download: failed to iterate tmp dir {$tmpDir}: " . $iterErr->getMessage(),
                ]);
            }

            if (empty($extractedFiles)) {
                // The helper may have extracted files but PHP (as www-data) can't
                // see them due to a permission layer we don't control (FUSE/shfs,
                // NFS ID mapping, etc). Ask the helper for a root-eye view of the
                // tmp dir and include borg's output so users can see what happened.
                $lsOutput = '';
                exec('sudo /usr/local/bin/bbs-ssh-helper fix-download-perms ' . escapeshellarg($tmpDir) . ' 2>&1');
                exec('sudo find ' . escapeshellarg($tmpDir) . ' -maxdepth 4 2>&1', $lsLines);
                $lsOutput = implode("\n", array_slice($lsLines, 0, 50));

                // Re-iterate after the second fix-download-perms pass
                try {
                    $rii = new \RecursiveIteratorIterator(
                        new \RecursiveDirectoryIterator($tmpDir, \RecursiveDirectoryIterator::SKIP_DOTS),
                        \RecursiveIteratorIterator::SELF_FIRST,
                        \RecursiveIteratorIterator::CATCH_GET_CHILD
                    );
                    foreach ($rii as $file) {
                        if ($file->isFile()) {
                            $extractedFiles[] = $file->getPathname();
                        }
                    }
                } catch (\Exception $iterErr2) {
                    /* ignore */
                }
            }

            if (empty($extractedFiles)) {
                // Log the full diagnostic picture before failing so users can
                // see it in the server log (the flash message is truncated).
                $diag = "borg exit={$exitCode}\n"
                    . "borg output:\n" . substr($borgOutput, 0, 2000) . "\n"
                    . "tmp dir contents (root view):\n" . substr($lsOutput ?? '(not captured)', 0, 1500);
                $this->db->insert('server_log', [
                    'agent_id' => $id,
                    'level' => 'error',
                    'message' => "Download failed — no files in {$tmpDir}. {$diag}",
                ]);

                $userMsg = 'No files were extracted. borg exit=' . $exitCode;
                if ($borgOutput !== '') {
                    $userMsg .= ' — ' . substr($borgOutput, 0, 300);
                }
                $userMsg .= ' (see server log for full output)';
                throw new \RuntimeException($userMsg);
            }

            // Generate filename
            $safeName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $agent['name'] . '-' . $archive['archive_name']);

            // Stream as tar.gz — clear any buffered output (e.g. debug notices)
            if (ob_get_level()) {
                ob_end_clean();
            }
            header('Content-Type: application/gzip');
            header('Content-Disposition: attachment; filename="' . $safeName . '.tar.gz"');
            header('Cache-Control: no-cache');

            $tarCmd = ['tar', 'czf', '-', '-C', $tmpDir, '.'];
            $tarProc = proc_open($tarCmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $tarPipes);
            if (!is_resource($tarProc)) {
                throw new \RuntimeException('Failed to create tar archive');
            }

            fclose($tarPipes[0]);
            fpassthru($tarPipes[1]);
            fclose($tarPipes[1]);
            fclose($tarPipes[2]);
            proc_close($tarProc);

        } catch (\Exception $e) {
            $this->flash('danger', 'Download failed: ' . $e->getMessage());
            $this->redirect("/clients/{$id}?tab=restore");
        } finally {
            // Cleanup temp directory and remote SSH key
            $this->removeDir($tmpDir);
            if ($remoteSshKeyFile && file_exists($remoteSshKeyFile)) {
                @unlink($remoteSshKeyFile);
            }
        }

        exit;
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) return;
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }

    public function update(int $id): void
    {
        $this->requireAuth();
        $this->verifyCsrf();

        $agent = $this->getAgent($id);
        if (!$agent) {
            $this->flash('danger', 'Client not found.');
            $this->redirect('/clients');
        }

        $data = [];
        if (isset($_POST['name']) && trim($_POST['name']) !== '') {
            $data['name'] = trim($_POST['name']);
        }
        if ($this->isAdmin() && array_key_exists('user_id', $_POST)) {
            $data['user_id'] = $_POST['user_id'] !== '' ? (int) $_POST['user_id'] : null;
        }
        if ($this->isAdmin() && array_key_exists('server_host_override', $_POST)) {
            $host = trim($_POST['server_host_override']);
            $data['server_host_override'] = $host !== '' ? $host : null;
        }
        if ($this->isAdmin() && array_key_exists('ssh_port_override', $_POST)) {
            $port = trim($_POST['ssh_port_override']);
            $data['ssh_port_override'] = ($port !== '' && (int) $port > 0 && (int) $port <= 65535) ? (int) $port : null;
        }

        if (!empty($data)) {
            $this->db->update('agents', $data, 'id = ?', [$id]);
            $this->flash('success', 'Client updated.');
        }

        $this->redirect("/clients/{$id}");
    }

    public function delete(int $id): void
    {
        $this->requireAdmin();
        $this->verifyCsrf();

        $agent = $this->getAgent($id);
        if (!$agent) {
            $this->flash('danger', 'Client not found.');
            $this->redirect('/clients');
        }

        // Deprovision SSH user
        if (!empty($agent['ssh_unix_user'])) {
            SshKeyManager::deprovisionClient($agent['ssh_unix_user']);
        }

        // Remove storage directory (runs as root via SSH helper)
        $clientDir = $agent['ssh_home_dir'] ?? null;
        if ($clientDir && is_dir($clientDir)) {
            SshKeyManager::deleteStorage($clientDir);
        }

        // Drop catalog data from ClickHouse (instant partition drop)
        try {
            $ch = \BBS\Core\ClickHouse::getInstance();
            $ch->exec("ALTER TABLE file_catalog DROP PARTITION " . (int) $id);
            $ch->exec("ALTER TABLE catalog_dirs DROP PARTITION " . (int) $id);
        } catch (\Exception $e) { /* ignore */ }

        $this->db->delete('agents', 'id = ?', [$id]);
        $this->flash('success', "Client \"{$agent['name']}\" deleted.");
        $this->redirect('/clients');
    }

    private function getAgent(int $id): ?array
    {
        $agent = $this->db->fetchOne("
            SELECT a.*, u.username as owner_name
            FROM agents a
            LEFT JOIN users u ON u.id = a.user_id
            WHERE a.id = ?
        ", [$id]);

        if (!$agent) {
            return null;
        }

        // Use the new permission service to check access
        if (!$this->canAccessAgent($id)) {
            return null;
        }

        // Populate plaintext token for display (install command) from stored
        // encrypted blob. Falls back silently if decrypt fails.
        if (empty($agent['api_key']) && !empty($agent['api_key_encrypted'])) {
            try {
                $agent['api_key'] = \BBS\Services\Encryption::decrypt($agent['api_key_encrypted']);
            } catch (\Throwable $e) { /* leave blank */ }
        }

        return $agent;
    }

    public function updateBorg(int $id): void
    {
        $this->requireAuth();
        $this->verifyCsrf();

        $agent = $this->getAgent($id);
        if (!$agent) {
            http_response_code(404);
            echo 'Not found';
            return;
        }

        // Create an update_borg job
        $this->db->insert('backup_jobs', [
            'agent_id' => $id,
            'task_type' => 'update_borg',
            'status' => 'queued',
        ]);

        $this->db->insert('server_log', [
            'agent_id' => $id,
            'level' => 'info',
            'message' => 'Borg update requested by ' . ($_SESSION['username'] ?? 'unknown'),
        ]);

        $this->flash('success', 'Borg update job queued for ' . $agent['name']);
        $this->redirect("/clients/{$id}");
    }

    public function updateAgent(int $id): void
    {
        $this->requireAuth();
        $this->verifyCsrf();

        $agent = $this->getAgent($id);
        if (!$agent) {
            http_response_code(404);
            echo 'Not found';
            return;
        }

        $this->db->insert('backup_jobs', [
            'agent_id' => $id,
            'task_type' => 'update_agent',
            'status' => 'queued',
        ]);

        $this->db->insert('server_log', [
            'agent_id' => $id,
            'level' => 'info',
            'message' => 'Agent update requested by ' . ($_SESSION['username'] ?? 'unknown'),
        ]);

        $this->flash('success', 'Agent update job queued for ' . $agent['name']);
        $this->redirect("/clients/{$id}");
    }
}
