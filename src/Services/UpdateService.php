<?php

namespace BBS\Services;

use BBS\Core\Database;
use BBS\Core\Migrator;

class UpdateService
{
    private Database $db;
    private string $projectRoot;

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->projectRoot = dirname(__DIR__, 2);
    }

    public function getCurrentVersion(): string
    {
        $file = $this->projectRoot . '/VERSION';
        if (!file_exists($file)) return '0.0.0';
        return trim(file_get_contents($file));
    }

    /**
     * Detect if the application is running inside a container (Docker or Podman).
     */
    public static function isRunningInDocker(): bool
    {
        return file_exists('/.dockerenv') || file_exists('/run/.containerenv');
    }

    /**
     * The AGENT_VERSION constant bundled with this server.
     *
     * Read out of agent/bbs-agent.py, which is the source of truth for what
     * agents should be running. Lives here because three callers needed it —
     * the topbar badge, the settings JSON and the bulk upgrade — and the
     * layout was re-reading the file with fgets() on every page render.
     */
    public function getBundledAgentVersion(): ?string
    {
        $agentFile = $this->projectRoot . '/agent/bbs-agent.py';
        if (!file_exists($agentFile)) {
            return null;
        }
        $fh = fopen($agentFile, 'r');
        if (!$fh) {
            return null;
        }
        $version = null;
        for ($i = 0; $i < 50 && ($line = fgets($fh)) !== false; $i++) {
            if (preg_match('/^AGENT_VERSION\s*=\s*["\']([^"\']+)["\']/m', $line, $m)) {
                $version = $m[1];
                break;
            }
        }
        fclose($fh);
        return $version;
    }

    /**
     * Is this agent behind the version bundled with the server?
     *
     * Strictly older, compared as a version rather than as a string. An agent
     * that is NEWER than the server's bundle is not outdated and must never be
     * offered an "upgrade" — doing so pushed a 2.72.0 agent back down to the
     * 2.71.0 the server happened to carry (#387). That is easy to hit: a Docker
     * agent image can be newer than the server image.
     */
    public function isAgentOutdated(?string $agentVersion, ?string $bundled = null): bool
    {
        $bundled = $bundled ?? $this->getBundledAgentVersion();
        if (!$bundled || !$agentVersion) {
            return false;
        }
        return version_compare($agentVersion, $bundled, '<');
    }

    /**
     * Agents running a version older than the bundled one.
     *
     * Empty when the bundled version can't be determined — better to show
     * nothing than to claim every agent is out of date. Agents that manage
     * their own updates (containers, where the image carries the agent) are
     * excluded: updating them in place is undone by the next restart.
     */
    public function getOutdatedAgents(bool $onlineOnly = false): array
    {
        $bundled = $this->getBundledAgentVersion();
        if (!$bundled) {
            return [];
        }

        $sql = "SELECT id, name, agent_version, status FROM agents
                WHERE agent_version IS NOT NULL AND auto_update_enabled = 1";
        if ($onlineOnly) {
            $sql .= " AND status = 'online'";
        }
        $sql .= " ORDER BY name";

        return array_values(array_filter(
            $this->db->fetchAll($sql),
            fn($a) => $this->isAgentOutdated($a['agent_version'], $bundled)
        ));
    }

    public function countOutdatedAgents(): int
    {
        return count($this->getOutdatedAgents());
    }

    public function getIncludePrereleases(): bool
    {
        return $this->getSetting('include_prereleases', '0') === '1';
    }

    public function setIncludePrereleases(bool $value): void
    {
        $this->setSetting('include_prereleases', $value ? '1' : '0');
    }

    public function getLatestRelease(): array
    {
        return [
            'version' => $this->getSetting('latest_version', ''),
            'notes' => $this->getSetting('latest_release_notes', ''),
            'url' => $this->getSetting('latest_release_url', ''),
            'checked_at' => $this->getSetting('last_update_check', ''),
        ];
    }

    public function isUpdateAvailable(): bool
    {
        $this->checkIfStale();
        $latest = $this->getSetting('latest_version', '');
        if (empty($latest)) return false;
        return version_compare($latest, $this->getCurrentVersion(), '>');
    }

    /**
     * Auto-check for updates if last check was more than 24 hours ago.
     */
    public function checkIfStale(): void
    {
        $lastCheck = $this->getSetting('last_update_check', '');
        if (!empty($lastCheck)) {
            $lastTime = strtotime($lastCheck);
            if ($lastTime !== false && (time() - $lastTime) < 86400) {
                return;
            }
        }
        $this->checkForUpdate();
    }

    public function checkForUpdate(): array
    {
        $ctx = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => "User-Agent: BorgBackupServer/" . $this->getCurrentVersion() . "\r\n",
                'timeout' => 10,
            ],
        ]);

        $url = 'https://api.github.com/repos/marcpope/borgbackupserver/releases';
        $json = @file_get_contents($url, false, $ctx);

        if ($json === false) {
            return ['error' => 'Could not reach GitHub API'];
        }

        $releases = json_decode($json, true);
        if (!is_array($releases) || empty($releases)) {
            $this->setSetting('last_update_check', date('Y-m-d H:i:s'));
            return [
                'version' => $this->getCurrentVersion(),
                'current' => $this->getCurrentVersion(),
                'update_available' => false,
                'notes' => '',
                'url' => '',
                'message' => 'No releases published yet.',
            ];
        }

        // Filter by prerelease preference
        $includePrereleases = $this->getSetting('include_prereleases', '0') === '1';
        $release = null;
        foreach ($releases as $r) {
            if (!empty($r['draft'])) continue;
            if (!$includePrereleases && !empty($r['prerelease'])) continue;
            $release = $r;
            break;
        }

        if (!$release) {
            $this->setSetting('last_update_check', date('Y-m-d H:i:s'));
            return [
                'version' => $this->getCurrentVersion(),
                'current' => $this->getCurrentVersion(),
                'update_available' => false,
                'notes' => '',
                'url' => '',
                'message' => 'No stable releases published yet.',
            ];
        }
        if (empty($release['tag_name'])) {
            return ['error' => 'Invalid response from GitHub'];
        }

        $tag = $release['tag_name'];
        $version = ltrim($tag, 'v');
        $notes = $release['body'] ?? '';
        $htmlUrl = $release['html_url'] ?? '';

        $this->setSetting('latest_version', $version);
        $this->setSetting('latest_release_tag', $tag);
        $this->setSetting('latest_release_notes', $notes);
        $this->setSetting('latest_release_url', $htmlUrl);
        $this->setSetting('last_update_check', date('Y-m-d H:i:s'));

        $this->sendTelemetryPing();

        return [
            'version' => $version,
            'notes' => $notes,
            'url' => $htmlUrl,
            'current' => $this->getCurrentVersion(),
            'update_available' => version_compare($version, $this->getCurrentVersion(), '>'),
        ];
    }

    /**
     * Start a background upgrade process.
     *
     * @param string|null $branchOrTag  Pass 'main' for dev sync, null for latest release tag
     */
    /**
     * Work that must finish before the application can be replaced.
     *
     * Server-side task types are excluded here because they are counted
     * separately below — they run on this machine and are interrupted by the
     * restart, whereas an agent backup is writing to a repository.
     */
    public function countRunningWork(): int
    {
        $agentJobs = (int) ($this->db->fetchOne(
            "SELECT COUNT(*) as cnt FROM backup_jobs
             WHERE status IN ('sent', 'running')
               AND task_type NOT IN ('update_borg', 'update_agent',
                   'prune', 'compact', 's3_sync', 's3_restore',
                   'repo_check', 'repo_repair', 'break_lock',
                   'catalog_sync', 'catalog_rebuild', 'catalog_rebuild_full',
                   'archive_delete', 'archive_lock')
               AND (agent_id IS NULL
                    OR agent_id IN (SELECT id FROM agents WHERE status = 'online'))"
        )['cnt'] ?? 0);

        $serverJobs = 0;
        try {
            $serverJobs = (int) ($this->db->fetchOne(
                "SELECT COUNT(*) as cnt FROM server_jobs WHERE status IN ('queued', 'running')"
            )['cnt'] ?? 0);
        } catch (\Exception $e) { /* server_jobs table may not exist */ }

        return $agentJobs + $serverJobs;
    }

    /** Stop waiting, and put maintenance back as it was. */
    public function cancelPendingUpgrade(): void
    {
        if ($this->getSetting('upgrade_pending_restore_maintenance') !== '1') {
            $this->setSetting('maintenance_mode', '0');
        }
        $this->setSetting('upgrade_pending', '0');
    }

    public function startBackgroundUpgrade(?string $branchOrTag = null): array
    {
        // Already upgrading?
        if ($this->getSetting('upgrade_in_progress') === '1') {
            return ['success' => false, 'error' => 'An upgrade is already in progress.'];
        }

        $tag = $branchOrTag;
        if ($tag === null) {
            $latest = $this->getSetting('latest_version', '');
            if (empty($latest)) {
                return ['success' => false, 'error' => 'No update information available. Check for updates first.'];
            }
            if (!version_compare($latest, $this->getCurrentVersion(), '>')) {
                return ['success' => false, 'error' => 'Already up to date (v' . $this->getCurrentVersion() . ').'];
            }
            $tag = $this->getSetting('latest_release_tag', 'v' . $latest);
        }

        // Check for active backup jobs — but ignore jobs stuck 'sent' to an
        // offline agent (they'll stay stuck indefinitely and should never
        // block a server upgrade), and ignore management tasks like
        // update_borg/update_agent that aren't real backups (#184).
        // Maintenance mode first, then count. The other way round leaves a gap:
        // between counting and suspending the queue the scheduler can promote a
        // queued job to 'sent', and the upgrade then runs while a backup is
        // starting. Suspending first means anything counted after this is
        // work already under way, and nothing new can join it.
        $maintenanceWasOn = $this->getSetting('maintenance_mode') === '1';
        $this->setSetting('maintenance_mode', '1');

        $running = $this->countRunningWork();
        if ($running > 0) {
            // Leave maintenance on and wait rather than refusing. The caller is
            // told to come back; the scheduler starts the upgrade once the last
            // job finishes, re-checking under maintenance before it does.
            $this->setSetting('upgrade_pending', '1');
            $this->setSetting('upgrade_pending_restore_maintenance', $maintenanceWasOn ? '1' : '0');
            return [
                'success' => false,
                'waiting' => true,
                'running' => $running,
                'error' => "Waiting for {$running} running job(s) to finish. The queue is suspended and the upgrade will start on its own once they are done.",
            ];
        }

        // Set up log file
        $logFile = '/tmp/bbs-upgrade-' . getmypid() . '.log';
        $this->setSetting('upgrade_in_progress', '1');
        $this->setSetting('upgrade_started_at', date('Y-m-d H:i:s'));
        $this->setSetting('upgrade_log_file', $logFile);
        $this->setSetting('upgrade_target', $tag);
        // Clear any previous result
        $this->setSetting('upgrade_result', '');
        $this->setSetting('upgrade_completed_at', '');

        // Spawn background process
        $updateScript = $this->projectRoot . '/bin/bbs-update';
        $cmd = sprintf(
            'nohup sudo %s %s %s > %s 2>&1 & echo $!',
            escapeshellarg($updateScript),
            escapeshellarg($this->projectRoot),
            escapeshellarg($tag),
            escapeshellarg($logFile)
        );
        $pid = trim(shell_exec($cmd));
        $this->setSetting('upgrade_pid', $pid);

        return ['success' => true, 'pid' => $pid];
    }

    /**
     * Get the current status of a background upgrade.
     */
    public function getUpgradeStatus(): array
    {
        $inProgress = $this->getSetting('upgrade_in_progress') === '1';
        $result = $this->getSetting('upgrade_result', '');
        $startedAt = $this->getSetting('upgrade_started_at', '');
        $completedAt = $this->getSetting('upgrade_completed_at', '');
        $target = $this->getSetting('upgrade_target', '');

        if (!$inProgress && empty($result)) {
            return ['in_progress' => false, 'result' => null];
        }

        // If already completed (detected on a previous poll), return stored result
        if (!$inProgress && !empty($result)) {
            $log = $this->getSetting('last_upgrade_log', '');
            return [
                'in_progress' => false,
                'progress' => 100,
                'log' => $log,
                'last_line' => $this->extractLastMeaningfulLine($log),
                'result' => $result,
                'target' => $target,
                'started_at' => $startedAt,
                'completed_at' => $completedAt,
                'elapsed' => $this->calcElapsed($startedAt, $completedAt),
            ];
        }

        // In progress — read live log
        $logFile = $this->getSetting('upgrade_log_file', '');
        $pid = $this->getSetting('upgrade_pid', '');
        $log = '';
        if (!empty($logFile) && file_exists($logFile)) {
            $log = file_get_contents($logFile);
        }

        // Parse progress from [N/12] pattern
        $progress = 0;
        $totalSteps = 12;
        if (preg_match_all('/\[(\d+)\/(\d+)\]/', $log, $matches)) {
            $lastStep = (int) end($matches[1]);
            $totalSteps = (int) end($matches[2]);
            $progress = $totalSteps > 0 ? (int) round(($lastStep / $totalSteps) * 100) : 0;
        }

        // Check if process is still running
        $processRunning = false;
        if (!empty($pid) && is_numeric($pid)) {
            $processRunning = file_exists("/proc/{$pid}");
        }

        // Check for completion marker
        $completed = str_contains($log, '=== Update complete ===');

        // Fallback success detection: PHP-FPM restart during the update can drop
        // the final "=== Update complete ===" line. If the process exited AND
        // we reached the final step marker (N/N) AND there are no obvious error
        // indicators, treat as success. The upgrade work is already finished by
        // that point — only the echo line got eaten.
        $fallbackSuccess = false;
        if (!$completed && !$processRunning && !empty($log)
            && preg_match('/\[(\d+)\/(\d+)\]/', $log, $stepMatch)) {
            // Find the last step number seen and compare to the total
            if (preg_match_all('/\[(\d+)\/(\d+)\]/', $log, $allSteps)) {
                $lastStepNum = (int) end($allSteps[1]);
                $totalSteps = (int) end($allSteps[2]);
                if ($lastStepNum === $totalSteps
                    && !preg_match('/^(error|fatal|exception)/mi', $log)) {
                    $fallbackSuccess = true;
                }
            }
        }

        $failed = !$processRunning && !$completed && !$fallbackSuccess && !empty($log);

        if ($completed || $fallbackSuccess || $failed) {
            $resultStr = ($completed || $fallbackSuccess) ? 'success' : 'failed';
            $now = date('Y-m-d H:i:s');

            $this->setSetting('upgrade_in_progress', '0');
            $this->setSetting('maintenance_mode', '0');
            $this->setSetting('upgrade_completed_at', $now);
            $this->setSetting('upgrade_result', $resultStr);
            $this->setSetting('last_upgrade_log', $log);

            return [
                'in_progress' => false,
                'progress' => ($completed || $fallbackSuccess) ? 100 : $progress,
                'log' => $log,
                'last_line' => $this->extractLastMeaningfulLine($log),
                'result' => $resultStr,
                'target' => $target,
                'started_at' => $startedAt,
                'completed_at' => $now,
                'elapsed' => $this->calcElapsed($startedAt, $now),
            ];
        }

        return [
            'in_progress' => true,
            'progress' => $progress,
            'log' => $log,
            'last_line' => $this->extractLastMeaningfulLine($log),
            'result' => null,
            'target' => $target,
            'started_at' => $startedAt,
            'elapsed' => $this->calcElapsed($startedAt),
        ];
    }

    /**
     * Clear upgrade state after user acknowledges completion.
     */
    public function clearUpgrade(): void
    {
        $this->setSetting('upgrade_in_progress', '0');
        $this->setSetting('upgrade_result', '');
        $this->setSetting('upgrade_pid', '');
        $this->setSetting('upgrade_log_file', '');
        $this->setSetting('upgrade_started_at', '');
        $this->setSetting('upgrade_completed_at', '');
        $this->setSetting('upgrade_target', '');
        // Ensure maintenance mode is off
        $this->setSetting('maintenance_mode', '0');
    }

    private function extractLastMeaningfulLine(string $log): string
    {
        $lines = array_filter(array_map('trim', explode("\n", $log)), fn($l) => $l !== '');
        return !empty($lines) ? end($lines) : '';
    }

    private function calcElapsed(string $startedAt, ?string $endAt = null): int
    {
        if (empty($startedAt)) return 0;
        $start = strtotime($startedAt);
        $end = $endAt ? strtotime($endAt) : time();
        return max(0, $end - $start);
    }

    /**
     * Send anonymous telemetry ping (version + OS) once per version.
     *
     * The request has to be completed, not just written. The previous
     * fire-and-forget socket — write the request, close, never read the
     * reply — stopped landing at the endpoint in late July: the connection
     * was closed before the request was forwarded, so the ping was dropped,
     * and because the version was marked as reported before sending, it was
     * never retried. Now the version is recorded only once the endpoint has
     * answered 200; a failure is retried, at most every six hours.
     */
    private function sendTelemetryPing(): void
    {
        try {
            if ($this->getSetting('telemetry_opt_out', '0') === '1') {
                return;
            }

            $currentVersion = $this->getCurrentVersion();
            if ($this->getSetting('telemetry_last_version') === $currentVersion) {
                return;
            }
            $lastAttempt = $this->getSetting('telemetry_last_attempt');
            if ($lastAttempt !== '' && strtotime($lastAttempt) > time() - 6 * 3600) {
                return;
            }
            $this->setSetting('telemetry_last_attempt', date('Y-m-d H:i:s'));

            $os = php_uname('s') . ' ' . php_uname('r');
            if (file_exists('/etc/os-release')) {
                $osRelease = parse_ini_file('/etc/os-release');
                if (!empty($osRelease['PRETTY_NAME'])) {
                    $os = $osRelease['PRETTY_NAME'];
                }
            }

            $payload = json_encode([
                'version' => $currentVersion,
                'os' => $os,
            ]);
            $url = 'https://www.borgbackupserver.com/api/telemetry.php';

            $status = 0;
            if (function_exists('curl_init')) {
                $ch = curl_init($url);
                curl_setopt_array($ch, [
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => $payload,
                    CURLOPT_HTTPHEADER => [
                        'Content-Type: application/json',
                        'User-Agent: BorgBackupServer/' . $currentVersion,
                    ],
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_CONNECTTIMEOUT => 3,
                    CURLOPT_TIMEOUT => 6,
                ]);
                curl_exec($ch);
                $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
                curl_close($ch);
            } else {
                $host = 'www.borgbackupserver.com';
                $fp = @fsockopen('ssl://' . $host, 443, $errno, $errstr, 3);
                if ($fp) {
                    stream_set_timeout($fp, 6);
                    $header = "POST /api/telemetry.php HTTP/1.1\r\n";
                    $header .= "Host: {$host}\r\n";
                    $header .= "Content-Type: application/json\r\n";
                    $header .= "User-Agent: BorgBackupServer/{$currentVersion}\r\n";
                    $header .= "Content-Length: " . strlen($payload) . "\r\n";
                    $header .= "Connection: close\r\n\r\n";
                    fwrite($fp, $header . $payload);
                    $first = (string) fgets($fp, 256);
                    while (!feof($fp)) { fgets($fp, 1024); }
                    fclose($fp);
                    if (preg_match('#^HTTP/\S+\s+(\d{3})#', $first, $m)) {
                        $status = (int) $m[1];
                    }
                }
            }

            if ($status === 200) {
                $this->setSetting('telemetry_last_version', $currentVersion);
            }
        } catch (\Exception $e) {
            // Silently ignore telemetry failures
        }
    }

    private function getSetting(string $key, string $default = ''): string
    {
        $row = $this->db->fetchOne("SELECT `value` FROM settings WHERE `key` = ?", [$key]);
        return $row['value'] ?? $default;
    }

    private function setSetting(string $key, string $value): void
    {
        $existing = $this->db->fetchOne("SELECT `key` FROM settings WHERE `key` = ?", [$key]);
        if ($existing) {
            $this->db->update('settings', ['value' => $value], "`key` = ?", [$key]);
        } else {
            $this->db->query("INSERT INTO settings (`key`, `value`) VALUES (?, ?)", [$key, $value]);
        }
    }
}
