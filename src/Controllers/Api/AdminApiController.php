<?php

namespace BBS\Controllers\Api;

use BBS\Core\Config;
use BBS\Core\Controller;
use BBS\Controllers\StorageLocationController;
use BBS\Services\BorgCommandBuilder;
use BBS\Services\Encryption;
use BBS\Services\ServerStats;
use BBS\Services\SshKeyManager;

class AdminApiController extends Controller
{
    private function getJsonInput(): array
    {
        $raw = file_get_contents('php://input');
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }

    // ── Clients ──────────────────────────────────────────

    public function listClients(): void
    {
        // Mobile/non-admin tokens are allowed but see only their agents.
        $ctx = $this->requireApiAuth();
        [$agentWhere, $agentParams] = $this->apiAgentWhereClause($ctx, 'a');

        $agents = $this->db->fetchAll("
            SELECT a.id, a.name, a.hostname, a.ip_address, a.os_info,
                   a.borg_version, a.agent_version, a.status, a.last_heartbeat,
                   a.created_at, u.username as owner,
                   a.client_profile_id, cp.name AS client_profile_name
            FROM agents a
            LEFT JOIN users u ON u.id = a.user_id
            LEFT JOIN client_profiles cp ON cp.id = a.client_profile_id
            WHERE {$agentWhere}
            ORDER BY a.name
        ", $agentParams);

        $this->json(['clients' => $agents]);
    }

    /**
     * GET /api/v1/metrics — monitoring snapshot for external systems
     * (health checks, dashboards, Prometheus via a JSON adapter). Timestamps
     * are returned both as datetime strings and unix epochs so time-series
     * tools can consume them directly.
     */
    public function metrics(): void
    {
        $this->requireApiToken();

        $clients = ['total' => 0, 'online' => 0, 'offline' => 0, 'error' => 0, 'setup' => 0];
        foreach ($this->db->fetchAll("SELECT status, COUNT(*) AS c FROM agents GROUP BY status") as $row) {
            $clients[$row['status']] = (int) $row['c'];
            $clients['total'] += (int) $row['c'];
        }

        $queue = ['queued' => 0, 'sent' => 0, 'running' => 0];
        foreach ($this->db->fetchAll("SELECT status, COUNT(*) AS c FROM backup_jobs WHERE status IN ('queued','sent','running') GROUP BY status") as $row) {
            $queue[$row['status']] = (int) $row['c'];
        }

        // All-time backup job outcomes per client
        $jobTotals = [];
        $jobRows = $this->db->fetchAll("
            SELECT bj.agent_id, a.name AS client_name, bj.status, COUNT(*) AS c
            FROM backup_jobs bj
            JOIN agents a ON a.id = bj.agent_id
            WHERE bj.task_type = 'backup' AND bj.status IN ('completed','failed','cancelled')
            GROUP BY bj.agent_id, a.name, bj.status
        ");
        foreach ($jobRows as $row) {
            $cid = (int) $row['agent_id'];
            if (!isset($jobTotals[$cid])) {
                $jobTotals[$cid] = ['client_id' => $cid, 'client' => $row['client_name'], 'completed' => 0, 'failed' => 0, 'cancelled' => 0];
            }
            $jobTotals[$cid][$row['status']] = (int) $row['c'];
        }

        // Per-plan last run (any terminal status) and last success
        $planRows = $this->db->fetchAll("
            SELECT bp.id AS plan_id, bp.name AS plan_name, bp.enabled,
                   a.id AS client_id, a.name AS client_name,
                   lastj.status AS last_status,
                   lastj.completed_at AS last_run_at,
                   lastj.duration_seconds AS last_run_duration_seconds,
                   lasts.completed_at AS last_success_at,
                   lasts.duration_seconds AS last_success_duration_seconds,
                   lasts.bytes_processed AS last_success_bytes
            FROM backup_plans bp
            JOIN agents a ON a.id = bp.agent_id
            LEFT JOIN (
                SELECT backup_plan_id, status, completed_at, duration_seconds,
                       ROW_NUMBER() OVER (PARTITION BY backup_plan_id ORDER BY completed_at DESC, id DESC) AS rn
                FROM backup_jobs
                WHERE task_type = 'backup' AND status IN ('completed', 'failed')
            ) lastj ON lastj.backup_plan_id = bp.id AND lastj.rn = 1
            LEFT JOIN (
                SELECT backup_plan_id, completed_at, duration_seconds, bytes_processed,
                       ROW_NUMBER() OVER (PARTITION BY backup_plan_id ORDER BY completed_at DESC, id DESC) AS rn
                FROM backup_jobs
                WHERE task_type = 'backup' AND status = 'completed'
            ) lasts ON lasts.backup_plan_id = bp.id AND lasts.rn = 1
            ORDER BY a.name, bp.name
        ");
        $plans = array_map(fn($r) => [
            'plan_id' => (int) $r['plan_id'],
            'plan' => $r['plan_name'],
            'enabled' => (bool) $r['enabled'],
            'client_id' => (int) $r['client_id'],
            'client' => $r['client_name'],
            'last_status' => $r['last_status'],
            'last_run_at' => $r['last_run_at'],
            'last_run_ts' => $r['last_run_at'] ? strtotime($r['last_run_at']) : null,
            'last_run_duration_seconds' => $r['last_run_duration_seconds'] !== null ? (int) $r['last_run_duration_seconds'] : null,
            'last_success_at' => $r['last_success_at'],
            'last_success_ts' => $r['last_success_at'] ? strtotime($r['last_success_at']) : null,
            'last_success_duration_seconds' => $r['last_success_duration_seconds'] !== null ? (int) $r['last_success_duration_seconds'] : null,
            'last_success_bytes' => $r['last_success_bytes'] !== null ? (int) $r['last_success_bytes'] : null,
        ], $planRows);

        $repos = array_map(fn($r) => [
            'id' => (int) $r['id'],
            'client_id' => (int) $r['agent_id'],
            'name' => $r['name'],
            'storage_type' => $r['storage_type'],
            'size_bytes' => (int) $r['size_bytes'],
        ], $this->db->fetchAll("SELECT id, agent_id, name, storage_type, size_bytes FROM repositories"));

        $this->json([
            'generated_at' => date('c'),
            'clients' => $clients,
            'queue' => $queue,
            'backup_jobs_total' => array_values($jobTotals),
            'plans' => $plans,
            'repositories' => $repos,
        ]);
    }

    public function summary(): void
    {
        $this->requireApiToken();

        // Latest terminal backup job per plan, computed once via ROW_NUMBER()
        // ordered by completed_at (the previous correlated subquery did the
        // equivalent per-row, which scaled as O(plans × jobs)).
        $rows = $this->db->fetchAll("
            SELECT
                a.id AS client_id,
                a.name AS client_name,
                a.status AS client_status,
                bp.id AS backup_plan_id,
                bp.name AS backup_plan_name,
                bp.enabled AS backup_plan_enabled,
                bp.repository_id AS repository_id,
                r.name AS repository_name,
                bj.id AS last_backup_job_id,
                bj.status AS last_backup_result,
                bj.duration_seconds AS last_backup_duration_seconds,
                bj.queued_at AS last_backup_queued_at,
                bj.started_at AS last_backup_started_at,
                bj.completed_at AS last_backup_completed_at
            FROM agents a
            LEFT JOIN backup_plans bp ON bp.agent_id = a.id
            LEFT JOIN repositories r ON r.id = bp.repository_id
            LEFT JOIN (
                SELECT id, backup_plan_id, status, duration_seconds,
                       queued_at, started_at, completed_at,
                       ROW_NUMBER() OVER (
                           PARTITION BY backup_plan_id
                           ORDER BY completed_at DESC, id DESC
                       ) AS rn
                FROM backup_jobs
                WHERE task_type = 'backup'
                  AND status IN ('completed', 'failed')
            ) bj ON bj.backup_plan_id = bp.id AND bj.rn = 1
            ORDER BY a.name, bp.name
        ");

        $clients = [];
        foreach ($rows as $row) {
            $clientId = (int) $row['client_id'];
            if (!isset($clients[$clientId])) {
                $clients[$clientId] = [
                    'id' => $clientId,
                    'name' => $row['client_name'],
                    'status' => $row['client_status'],
                    'backup_plans' => [],
                ];
            }

            if ($row['backup_plan_id'] === null) {
                continue;
            }

            $clients[$clientId]['backup_plans'][] = [
                'id' => (int) $row['backup_plan_id'],
                'name' => $row['backup_plan_name'],
                'enabled' => (bool) $row['backup_plan_enabled'],
                'repository_id' => $row['repository_id'] !== null ? (int) $row['repository_id'] : null,
                'repository_name' => $row['repository_name'],
                'last_backup' => $row['last_backup_job_id'] === null ? null : [
                    'job_id' => (int) $row['last_backup_job_id'],
                    'result' => $row['last_backup_result'],
                    'duration_seconds' => $row['last_backup_duration_seconds'] !== null ? (int) $row['last_backup_duration_seconds'] : null,
                    'queued_at' => $row['last_backup_queued_at'],
                    'started_at' => $row['last_backup_started_at'],
                    'completed_at' => $row['last_backup_completed_at'],
                ],
            ];
        }

        $this->json([
            'clients' => array_values($clients),
        ]);
    }

    public function getClient(int $id): void
    {
        $ctx = $this->requireApiAuth();
        if (!$this->apiCanAccessAgent($ctx, $id)) {
            $this->json(['error' => 'Client not found'], 404);
        }

        $agent = $this->db->fetchOne("
            SELECT a.id, a.name, a.hostname, a.ip_address, a.os_info,
                   a.borg_version, a.agent_version, a.status, a.last_heartbeat,
                   a.api_key, a.api_key_encrypted, a.created_at, u.username as owner,
                   a.client_profile_id, cp.name AS client_profile_name
            FROM agents a
            LEFT JOIN users u ON u.id = a.user_id
            LEFT JOIN client_profiles cp ON cp.id = a.client_profile_id
            WHERE a.id = ?
        ", [$id]);

        if (!$agent) {
            $this->json(['error' => 'Client not found'], 404);
        }

        // The agent api_key is its registration credential. Mobile tokens
        // never receive it — the app has no use for it and it must not sit
        // in a phone's response cache. Admin CLI tokens keep it (existing
        // automation reads it).
        if (($ctx['token_kind'] ?? 'user') === 'mobile' || ($ctx['role'] ?? '') !== 'admin') {
            $agent['api_key'] = null;
            unset($agent['api_key_encrypted']);
        } else {
            // Decrypt stored token for API response (falls back to legacy plaintext).
            if (empty($agent['api_key']) && !empty($agent['api_key_encrypted'])) {
                try {
                    $agent['api_key'] = \BBS\Services\Encryption::decrypt($agent['api_key_encrypted']);
                } catch (\Throwable $e) { /* leave blank */ }
            }
            unset($agent['api_key_encrypted']);
        }

        // Include repos and plans
        $repos = $this->db->fetchAll(
            "SELECT id, name, path, encryption, storage_type, size_bytes, archive_count, created_at
             FROM repositories WHERE agent_id = ? ORDER BY name", [$id]
        );
        $plans = $this->db->fetchAll(
            "SELECT bp.id, bp.name, bp.directories, bp.excludes, bp.advanced_options, bp.enabled,
                    bp.repository_id,
                    bp.prune_minutes, bp.prune_hours, bp.prune_days,
                    bp.prune_weeks, bp.prune_months, bp.prune_years,
                    s.frequency, s.times, s.day_of_week, s.day_of_month
             FROM backup_plans bp
             LEFT JOIN schedules s ON s.backup_plan_id = bp.id
             WHERE bp.agent_id = ? ORDER BY bp.name", [$id]
        );
        foreach ($plans as &$p) {
            $p['id'] = (int) $p['id'];
            $p['enabled'] = (bool) $p['enabled'];
            $p['repository_id'] = $p['repository_id'] !== null ? (int) $p['repository_id'] : null;
            foreach (['prune_minutes', 'prune_hours', 'prune_days',
                      'prune_weeks', 'prune_months', 'prune_years'] as $k) {
                $p[$k] = (int) $p[$k];
            }
        }
        unset($p);

        $agent['repositories'] = $repos;
        $agent['plans'] = $plans;

        $this->json($agent);
    }

    public function createClient(): void
    {
        $this->requireApiToken();
        $input = $this->getJsonInput();

        $name = trim($input['name'] ?? '');
        if (empty($name)) {
            $this->json(['error' => 'Client name is required'], 400);
        }

        $apiKey = bin2hex(random_bytes(32));

        $id = $this->db->insert('agents', [
            'name' => $name,
            'api_key_hash' => hash('sha256', $apiKey),
            'api_key_encrypted' => \BBS\Services\Encryption::encrypt($apiKey),
            'status' => 'setup',
            'user_id' => null,
        ]);

        // Determine SSH home path
        $storageSetting = $this->db->fetchOne("SELECT `value` FROM settings WHERE `key` = 'storage_path'");
        $storagePath = $storageSetting['value'] ?? null;
        if (!$storagePath) {
            $this->db->delete('agents', 'id = ?', [$id]);
            $this->json(['error' => 'No storage path configured on server'], 500);
        }

        $sshHomePath = $storagePath;
        $matchingLocation = $this->db->fetchOne(
            "SELECT id FROM storage_locations WHERE path = ?",
            [rtrim($storagePath, '/')]
        );
        if ($matchingLocation && is_dir('/var/bbs/home')) {
            $sshHomePath = '/var/bbs/home';
        }

        $clientDir = rtrim($sshHomePath, '/') . '/' . $id;
        if (!is_dir($clientDir) && !@mkdir($clientDir, 0755, true)) {
            $this->db->delete('agents', 'id = ?', [$id]);
            $this->json(['error' => 'Failed to create storage directory'], 500);
        }

        $sshResult = SshKeyManager::provisionClient($id, $name, $sshHomePath);
        if (!$sshResult) {
            @rmdir($clientDir);
            $this->db->delete('agents', 'id = ?', [$id]);
            $detail = SshKeyManager::getLastHelperError();
            $this->json(['error' => 'SSH provisioning failed' . ($detail ? ': ' . $detail : '') . '. Ensure bbs-ssh-helper is installed.'], 500);
        }

        $this->db->insert('server_log', [
            'agent_id' => $id,
            'level' => 'info',
            'message' => "Client created via API. SSH provisioned: user {$sshResult['unix_user']}, home {$sshResult['home_dir']}",
        ]);

        // Build install command
        $serverHost = $this->db->fetchOne("SELECT `value` FROM settings WHERE `key` = 'server_host'");
        $host = $serverHost['value'] ?? '';

        $this->json([
            'id' => (int) $id,
            'name' => $name,
            'api_key' => $apiKey,
            'status' => 'setup',
            'install_command' => $host ? "curl -s https://{$host}/get-agent | sudo bash -s -- --server https://{$host} --key {$apiKey}" : null,
        ], 201);
    }

    public function deleteClient(int $id): void
    {
        $this->requireApiToken();

        $agent = $this->db->fetchOne("SELECT * FROM agents WHERE id = ?", [$id]);
        if (!$agent) {
            $this->json(['error' => 'Client not found'], 404);
        }

        // Deleting a client cascades its repositories — locked archives
        // (legal hold, #314) must not be removable that way.
        $lockedCount = $this->db->fetchOne(
            "SELECT COUNT(*) as cnt FROM archives ar
             JOIN repositories r ON r.id = ar.repository_id
             WHERE r.agent_id = ? AND ar.locked = 1", [$id]);
        if ((int) ($lockedCount['cnt'] ?? 0) > 0) {
            $this->json(['error' => 'Cannot delete — client has locked archives. Unlock them first.'], 409);
        }

        // Deprovision SSH user
        if (!empty($agent['ssh_unix_user'])) {
            SshKeyManager::deprovisionClient($agent['ssh_unix_user']);
        }

        // Remove storage directory
        $clientDir = $agent['ssh_home_dir'] ?? null;
        if ($clientDir && is_dir($clientDir)) {
            SshKeyManager::deleteStorage($clientDir);
        }

        // Drop ClickHouse catalog data
        try {
            $ch = \BBS\Core\ClickHouse::getInstance();
            $ch->exec("ALTER TABLE file_catalog DROP PARTITION " . (int) $id);
            $ch->exec("ALTER TABLE catalog_dirs DROP PARTITION " . (int) $id);
        } catch (\Exception $e) { /* ignore */ }

        // Clear S3 sync links first: repository_s3_configs RESTRICTs
        // plugin_config deletion, and the agent cascade reaches
        // plugin_configs before the repository cascade clears these rows,
        // so deleting the agent directly fails with an FK violation (#378)
        $this->db->query(
            "DELETE rsc FROM repository_s3_configs rsc
             JOIN repositories r ON r.id = rsc.repository_id
             WHERE r.agent_id = ?", [$id]
        );
        $this->db->query(
            "DELETE rsc FROM repository_s3_configs rsc
             JOIN plugin_configs pc ON pc.id = rsc.plugin_config_id
             WHERE pc.agent_id = ?", [$id]
        );

        $this->db->delete('agents', 'id = ?', [$id]);

        $this->json(['status' => 'ok', 'message' => "Client \"{$agent['name']}\" deleted"]);
    }

    // ── Repositories ─────────────────────────────────────

    public function listRepositories(int $id): void
    {
        $ctx = $this->requireApiAuth();
        if (!$this->apiCanAccessAgent($ctx, $id)) {
            $this->json(['error' => 'Client not found'], 404);
        }

        $agent = $this->db->fetchOne("SELECT id FROM agents WHERE id = ?", [$id]);
        if (!$agent) {
            $this->json(['error' => 'Client not found'], 404);
        }

        $repos = $this->db->fetchAll(
            "SELECT r.id, r.name, r.path, r.encryption, r.storage_type, r.size_bytes, r.archive_count, r.created_at,
                    COALESCE(rsc.enabled, 0) AS s3_sync_enabled,
                    rsc.last_sync_at AS s3_last_sync_at
             FROM repositories r
             LEFT JOIN (
                 SELECT repository_id, MAX(enabled) AS enabled, MAX(last_sync_at) AS last_sync_at
                 FROM repository_s3_configs GROUP BY repository_id
             ) rsc ON rsc.repository_id = r.id
             WHERE r.agent_id = ? ORDER BY r.name", [$id]
        );
        foreach ($repos as &$r) {
            $r['s3_sync_enabled'] = (bool) $r['s3_sync_enabled'];
        }
        unset($r);

        $this->json(['repositories' => $repos]);
    }

    public function createRepository(int $id): void
    {
        $this->requireApiToken();
        $input = $this->getJsonInput();

        $agent = $this->db->fetchOne("SELECT * FROM agents WHERE id = ?", [$id]);
        if (!$agent) {
            $this->json(['error' => 'Client not found'], 404);
        }

        $name = trim($input['name'] ?? '');
        $encryption = $input['encryption'] ?? 'repokey-blake2';
        $passphrase = $input['passphrase'] ?? '';
        $storageType = $input['storage_type'] ?? 'local';
        $remoteSshConfigId = !empty($input['remote_ssh_config_id']) ? (int) $input['remote_ssh_config_id'] : null;

        if (empty($name)) {
            $this->json(['error' => 'Repository name is required'], 400);
        }

        // Hosted mode: storage choices are locked to the platform-provided
        // default location. Reject any attempt to use remote SSH or to pin
        // a non-default storage_location_id. The customer UI doesn't expose
        // these options, so this guard exists for API callers.
        if (Config::isHosted()) {
            if ($storageType !== 'local') {
                $this->json(['error' => 'Storage type is locked to local in hosted mode.'], 422);
            }
            $requestedLocId = !empty($input['storage_location_id']) ? (int) $input['storage_location_id'] : null;
            if ($requestedLocId !== null) {
                $default = $this->db->fetchOne("SELECT id FROM storage_locations WHERE is_default = 1");
                if (!$default || (int) $default['id'] !== $requestedLocId) {
                    $this->json(['error' => 'Only the default storage location may be used in hosted mode.'], 422);
                }
            }
        }

        // Route to remote SSH handler if requested
        if ($storageType === 'remote_ssh') {
            $this->createRemoteSshRepository($id, $name, $encryption, $passphrase, $remoteSshConfigId);
            return;
        }

        $validEncryptions = ['none', 'repokey', 'repokey-blake2', 'authenticated', 'authenticated-blake2'];
        if (!in_array($encryption, $validEncryptions)) {
            $this->json(['error' => 'Invalid encryption type. Valid: ' . implode(', ', $validEncryptions)], 400);
        }

        // Auto-generate passphrase if needed
        if (empty($passphrase) && $encryption !== 'none') {
            $segments = [];
            for ($i = 0; $i < 5; $i++) {
                $segments[] = strtoupper(substr(bin2hex(random_bytes(3)), 0, 4));
            }
            $passphrase = implode('-', $segments);
        }

        // Sanitize name for filesystem
        $safeName = $this->sanitizeRepoName($name);
        if (empty($safeName)) {
            $this->json(['error' => 'Repository name must contain at least one alphanumeric character'], 400);
        }

        // Resolve storage location
        $storageLocationId = !empty($input['storage_location_id']) ? (int) $input['storage_location_id'] : null;
        $location = null;
        if ($storageLocationId) {
            $location = $this->db->fetchOne("SELECT * FROM storage_locations WHERE id = ?", [$storageLocationId]);
        }
        if (!$location) {
            $location = $this->db->fetchOne("SELECT * FROM storage_locations WHERE is_default = 1");
        }
        if (!$location) {
            $storageSetting = $this->db->fetchOne("SELECT `value` FROM settings WHERE `key` = 'storage_path'");
            $location = ['id' => null, 'path' => $storageSetting['value'] ?? '/var/bbs', 'is_default' => 1];
        }

        $serverHost = $this->db->fetchOne("SELECT `value` FROM settings WHERE `key` = 'server_host'");
        $host = !empty($agent['server_host_override']) ? $agent['server_host_override'] : ($serverHost['value'] ?? '');

        // Build path
        $locationPath = rtrim($location['path'], '/');
        $sshHomeDir = $agent['ssh_home_dir'] ?? null;
        $sshHomePath = $sshHomeDir ? rtrim(dirname($sshHomeDir), '/') : null;
        $isNonDefault = !$sshHomePath || $locationPath !== $sshHomePath;

        if ($isNonDefault) {
            $localPath = $locationPath . '/' . $id . '/' . $safeName;
            if (!empty($agent['ssh_unix_user']) && !empty($host)) {
                $sshHost = SshKeyManager::stripHostPort($host);
                $path = "ssh://{$agent['ssh_unix_user']}@{$sshHost}//{$localPath}";
            } else {
                $path = $localPath;
            }
        } else {
            if (!empty($agent['ssh_unix_user']) && !empty($host)) {
                $path = SshKeyManager::buildSshRepoPath($agent['ssh_unix_user'], $host, $safeName);
            } else {
                $path = rtrim($location['path'], '/') . '/' . $id . '/' . $safeName;
            }
        }

        // Check for duplicate path
        $existing = $this->db->fetchOne("SELECT id FROM repositories WHERE path = ?", [$path]);
        if ($existing) {
            $this->json(['error' => 'A repository already exists at that path. Try a different name.'], 409);
        }

        $repoId = $this->db->insert('repositories', [
            'agent_id' => $id,
            'storage_type' => 'local',
            'storage_location_id' => $location['id'] ?? null,
            'name' => $safeName,
            'path' => $path,
            'encryption' => $encryption,
            'passphrase_encrypted' => $encryption !== 'none' ? Encryption::encrypt($passphrase) : null,
        ]);

        // Run borg init
        $repo = $this->db->fetchOne("SELECT * FROM repositories WHERE id = ?", [$repoId]);
        $repoLocalPath = BorgCommandBuilder::getLocalRepoPath($repo);

        $helperCmd = ['sudo', '/usr/local/bin/bbs-ssh-helper', 'create-repo-dir', $repoLocalPath];
        exec(implode(' ', array_map('escapeshellarg', $helperCmd)) . ' 2>&1', $helperOutput, $helperRet);
        if ($helperRet !== 0) {
            $parentDir = dirname($repoLocalPath);
            if (!is_dir($parentDir)) {
                mkdir($parentDir, 0755, true);
            }
        }

        // Update .storage-paths BEFORE borg init so SSH access works even if init fails
        if (!empty($agent['ssh_unix_user'])) {
            SshKeyManager::updateAgentStoragePaths($this->db, $id, $agent);
        }

        // Run borg init via bbs-ssh-helper against the local path — same flow as the
        // web UI. Never init over the repo's ssh:// path: the server has neither the
        // agent's SSH identity nor a guaranteed loopback on the configured port (#356).
        $initCmd = ['sudo', '/usr/local/bin/bbs-ssh-helper', 'borg-init', $repoLocalPath, $encryption];
        $passphraseToPipe = '';
        if ($encryption !== 'none' && !empty($passphrase)) {
            $initCmd[] = '-';
            $passphraseToPipe = $passphrase;
        }
        $exitCode = 1;
        $stdout = '';
        $stderr = '';
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = proc_open($initCmd, $descriptors, $pipes);
        if (is_resource($proc)) {
            if ($passphraseToPipe !== '') {
                fwrite($pipes[0], $passphraseToPipe . "\n");
            }
            fclose($pipes[0]);
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exitCode = proc_close($proc);
        }

        if ($exitCode !== 0) {
            $errorMsg = trim($stdout . "\n" . $stderr);
            $this->db->insert('server_log', [
                'agent_id' => $id,
                'level' => 'error',
                'message' => "borg init failed for repo \"{$safeName}\" via API: {$errorMsg}",
            ]);
        } elseif (!empty($agent['ssh_unix_user'])) {
            // Fix ownership: borg init runs as root, but the agent's unix user
            // must own the repo for SSH access
            $fixCmd = ['sudo', '/usr/local/bin/bbs-ssh-helper', 'fix-repo-perms', $repoLocalPath, $agent['ssh_unix_user']];
            exec(implode(' ', array_map('escapeshellarg', $fixCmd)) . ' 2>&1', $fixOutput, $fixRet);
            if ($fixRet !== 0) {
                $this->db->insert('server_log', [
                    'agent_id' => $id,
                    'level' => 'warning',
                    'message' => "fix-repo-perms failed via API: " . implode(' ', $fixOutput),
                ]);
            }
        }

        $this->db->insert('server_log', [
            'agent_id' => $id,
            'level' => 'info',
            'message' => "Repository \"{$safeName}\" created via API ({$encryption})",
        ]);

        $response = [
            'id' => (int) $repoId,
            'name' => $safeName,
            'path' => $path,
            'encryption' => $encryption,
            'storage_type' => 'local',
        ];

        if ($encryption !== 'none') {
            $response['passphrase'] = $passphrase;
        }

        if ($exitCode !== 0) {
            $response['warning'] = 'Repository created in database but borg init failed: ' . ($errorMsg ?? 'unknown error');
        }

        $this->json($response, 201);
    }

    // ── Backup Plans ─────────────────────────────────────

    public function listPlans(int $id): void
    {
        $ctx = $this->requireApiAuth();
        if (!$this->apiCanAccessAgent($ctx, $id)) {
            $this->json(['error' => 'Client not found'], 404);
        }

        $agent = $this->db->fetchOne("SELECT id FROM agents WHERE id = ?", [$id]);
        if (!$agent) {
            $this->json(['error' => 'Client not found'], 404);
        }

        // Retention is returned as well as accepted. Without it an editor has
        // no current values to show, and a form rendering blanks would write
        // zeros back on save and quietly wipe the plan's retention policy —
        // only noticed later, when archives start disappearing.
        $plans = $this->db->fetchAll("
            SELECT bp.id, bp.name, bp.directories, bp.excludes, bp.advanced_options,
                   bp.enabled, bp.repository_id, r.name as repository_name,
                   bp.prune_minutes, bp.prune_hours, bp.prune_days,
                   bp.prune_weeks, bp.prune_months, bp.prune_years,
                   s.frequency, s.times, s.day_of_week, s.day_of_month
            FROM backup_plans bp
            LEFT JOIN schedules s ON s.backup_plan_id = bp.id
            LEFT JOIN repositories r ON r.id = bp.repository_id
            WHERE bp.agent_id = ?
            ORDER BY bp.name
        ", [$id]);

        foreach ($plans as &$p) {
            $p['id'] = (int) $p['id'];
            $p['enabled'] = (bool) $p['enabled'];
            $p['repository_id'] = $p['repository_id'] !== null ? (int) $p['repository_id'] : null;
            // Signed: a negative keep count is borg's "no limit" (#386), so
            // these must not be coerced to unsigned or clamped at zero.
            foreach (['prune_minutes', 'prune_hours', 'prune_days',
                      'prune_weeks', 'prune_months', 'prune_years'] as $k) {
                $p[$k] = (int) $p[$k];
            }
        }
        unset($p);

        $this->json(['plans' => $plans]);
    }

    public function createPlan(int $id): void
    {
        $this->requireApiToken();
        $input = $this->getJsonInput();

        $agent = $this->db->fetchOne("SELECT id FROM agents WHERE id = ?", [$id]);
        if (!$agent) {
            $this->json(['error' => 'Client not found'], 404);
        }

        $name = trim($input['name'] ?? '');
        $repositoryId = (int) ($input['repository_id'] ?? 0);
        $directories = trim($input['directories'] ?? '');
        $excludes = trim($input['excludes'] ?? '');
        $advancedOptions = trim($input['advanced_options'] ?? '--compression lz4 --exclude-caches --noatime');
        $frequency = $input['frequency'] ?? 'daily';
        $times = $input['times'] ?? '02:00';
        $dayOfWeek = $input['day_of_week'] ?? null;
        $dayOfMonth = $input['day_of_month'] ?? null;
        $pruneMinutes = (int) ($input['prune_minutes'] ?? 0);
        $pruneHours = (int) ($input['prune_hours'] ?? 0);
        $pruneDays = (int) ($input['prune_days'] ?? 7);
        $pruneWeeks = (int) ($input['prune_weeks'] ?? 4);
        $pruneMonths = (int) ($input['prune_months'] ?? 6);
        $pruneYears = (int) ($input['prune_years'] ?? 0);

        if (empty($name) || empty($directories) || empty($repositoryId)) {
            $this->json(['error' => 'name, repository_id, and directories are required'], 400);
        }

        // Verify repository belongs to this agent
        $repo = $this->db->fetchOne(
            "SELECT id FROM repositories WHERE id = ? AND agent_id = ?",
            [$repositoryId, $id]
        );
        if (!$repo) {
            $this->json(['error' => 'Repository not found or does not belong to this client'], 404);
        }

        $validFreqs = ['hourly', 'daily', 'weekly', 'monthly', 'manual'];
        if (!in_array($frequency, $validFreqs)) {
            $this->json(['error' => 'Invalid frequency. Valid: ' . implode(', ', $validFreqs)], 400);
        }

        $planId = $this->db->insert('backup_plans', [
            'agent_id' => $id,
            'repository_id' => $repositoryId,
            'name' => $name,
            'directories' => $directories,
            'excludes' => $excludes ?: null,
            'advanced_options' => $advancedOptions,
            'prune_minutes' => $pruneMinutes,
            'prune_hours' => $pruneHours,
            'prune_days' => $pruneDays,
            'prune_weeks' => $pruneWeeks,
            'prune_months' => $pruneMonths,
            'prune_years' => $pruneYears,
            'enabled' => 1,
        ]);

        // Create schedule
        $scheduleId = $this->db->insert('schedules', [
            'backup_plan_id' => $planId,
            'frequency' => $frequency,
            'times' => $times,
            'day_of_week' => $dayOfWeek,
            'day_of_month' => $dayOfMonth,
            'enabled' => $frequency !== 'manual' ? 1 : 0,
        ]);

        // Attach plugin configs if provided
        // Format: "plugins": [{"plugin_config_id": 5}, {"plugin_config_id": 8}]
        // Or: "plugins": {"1": 5, "2": 8}  (plugin_id: plugin_config_id)
        $plugins = $input['plugins'] ?? [];
        $order = 0;
        if (is_array($plugins)) {
            foreach ($plugins as $key => $val) {
                if (is_array($val)) {
                    // Array of objects: [{"plugin_config_id": 5}]
                    $configId = (int) ($val['plugin_config_id'] ?? 0);
                    if ($configId <= 0) continue;
                    $pc = $this->db->fetchOne("SELECT plugin_id FROM plugin_configs WHERE id = ? AND agent_id = ?", [$configId, $id]);
                    if (!$pc) continue;
                    $this->db->insert('backup_plan_plugins', [
                        'backup_plan_id' => $planId,
                        'plugin_id' => $pc['plugin_id'],
                        'plugin_config_id' => $configId,
                        'config' => '{}',
                        'execution_order' => $order++,
                        'enabled' => 1,
                    ]);
                } else {
                    // Map format: {plugin_id: config_id}
                    $pluginId = (int) $key;
                    $configId = (int) $val;
                    if ($pluginId <= 0 || $configId <= 0) continue;
                    $this->db->insert('backup_plan_plugins', [
                        'backup_plan_id' => $planId,
                        'plugin_id' => $pluginId,
                        'plugin_config_id' => $configId,
                        'config' => '{}',
                        'execution_order' => $order++,
                        'enabled' => 1,
                    ]);
                }
            }
        }

        $this->db->insert('server_log', [
            'agent_id' => $id,
            'level' => 'info',
            'message' => "Backup plan \"{$name}\" created via API (schedule: {$frequency})",
        ]);

        $this->json([
            'id' => (int) $planId,
            'name' => $name,
            'repository_id' => $repositoryId,
            'directories' => $directories,
            'frequency' => $frequency,
            'schedule_id' => (int) $scheduleId,
            'plugins_attached' => $order,
        ], 201);
    }

    // ── Plugins ──────────────────────────────────────────

    public function listPlugins(): void
    {
        $this->requireApiToken();

        $plugins = $this->db->fetchAll("SELECT id, slug, name, description, plugin_type, is_active FROM plugins ORDER BY name");

        $this->json(['plugins' => $plugins]);
    }

    public function listPluginConfigs(int $id): void
    {
        $this->requireApiToken();

        $agent = $this->db->fetchOne("SELECT id FROM agents WHERE id = ?", [$id]);
        if (!$agent) {
            $this->json(['error' => 'Client not found'], 404);
        }

        $configs = $this->db->fetchAll("
            SELECT pc.id, pc.name, pc.config, p.slug, p.name as plugin_name
            FROM plugin_configs pc
            JOIN plugins p ON p.id = pc.plugin_id
            WHERE pc.agent_id = ?
            ORDER BY p.name, pc.name
        ", [$id]);

        // Decode config JSON and mask sensitive fields
        foreach ($configs as &$cfg) {
            $decoded = json_decode($cfg['config'], true) ?: [];
            // Mask sensitive values
            foreach ($decoded as $k => &$v) {
                if (in_array($k, ['password', 'secret_key', 'access_key']) && !empty($v)) {
                    $v = '********';
                }
            }
            $cfg['config'] = $decoded;
        }

        $this->json(['plugin_configs' => $configs]);
    }

    public function createPluginConfig(int $id): void
    {
        $this->requireApiToken();
        $input = $this->getJsonInput();

        $agent = $this->db->fetchOne("SELECT id FROM agents WHERE id = ?", [$id]);
        if (!$agent) {
            $this->json(['error' => 'Client not found'], 404);
        }

        $pluginSlug = trim($input['plugin'] ?? '');
        $configName = trim($input['name'] ?? '');
        $config = $input['config'] ?? [];

        if (empty($pluginSlug) || empty($configName)) {
            $this->json(['error' => 'plugin (slug) and name are required'], 400);
        }

        $plugin = $this->db->fetchOne("SELECT id, slug FROM plugins WHERE slug = ?", [$pluginSlug]);
        if (!$plugin) {
            $this->json(['error' => "Unknown plugin: {$pluginSlug}"], 404);
        }

        // Check duplicate name
        $existing = $this->db->fetchOne(
            "SELECT id FROM plugin_configs WHERE agent_id = ? AND plugin_id = ? AND name = ?",
            [$id, $plugin['id'], $configName]
        );
        if ($existing) {
            $this->json(['error' => "A config named \"{$configName}\" already exists for this plugin"], 409);
        }

        // Encrypt sensitive fields
        $schema = (new \BBS\Services\PluginManager($this->db))->getPluginSchema($pluginSlug);
        foreach ($schema as $field => $def) {
            if (!empty($def['sensitive']) && !empty($config[$field])) {
                $config[$field] = Encryption::encrypt($config[$field]);
            }
        }

        // Enable plugin for agent if not already
        $agentPlugin = $this->db->fetchOne(
            "SELECT id FROM agent_plugins WHERE agent_id = ? AND plugin_id = ?",
            [$id, $plugin['id']]
        );
        if (!$agentPlugin) {
            $this->db->insert('agent_plugins', [
                'agent_id' => $id,
                'plugin_id' => $plugin['id'],
                'enabled' => 1,
            ]);
        } else {
            $this->db->update('agent_plugins', ['enabled' => 1], 'id = ?', [$agentPlugin['id']]);
        }

        $configId = $this->db->insert('plugin_configs', [
            'agent_id' => $id,
            'plugin_id' => $plugin['id'],
            'name' => $configName,
            'config' => json_encode($config),
        ]);

        $this->json([
            'id' => (int) $configId,
            'plugin' => $pluginSlug,
            'name' => $configName,
        ], 201);
    }

    public function getPluginSchema(): void
    {
        $this->requireApiToken();

        $pm = new \BBS\Services\PluginManager($this->db);
        $plugins = $this->db->fetchAll("SELECT slug, name FROM plugins WHERE is_active = 1 ORDER BY name");

        $schemas = [];
        foreach ($plugins as $p) {
            $schema = $pm->getPluginSchema($p['slug']);
            // Strip sensitive defaults
            foreach ($schema as &$field) {
                if (!empty($field['sensitive'])) {
                    unset($field['default']);
                }
            }
            $schemas[$p['slug']] = [
                'name' => $p['name'],
                'fields' => $schema,
            ];
        }

        $this->json(['schemas' => $schemas]);
    }

    // ── Storage Locations ────────────────────────────────

    public function listStorageLocations(): void
    {
        $this->requireApiToken();

        $locations = $this->db->fetchAll("SELECT id, label as name, path, capacity_bytes, is_default, created_at FROM storage_locations ORDER BY label");
        $remoteConfigs = $this->db->fetchAll("
            SELECT id, name, provider, remote_host, remote_port, remote_user, remote_base_path,
                   borg_remote_path, append_repo_name, disk_total_bytes, disk_used_bytes,
                   disk_free_bytes, disk_checked_at, created_at
            FROM remote_ssh_configs ORDER BY name
        ");

        // Decorate local locations with live df capacity/usage (#157).
        foreach ($locations as &$loc) {
            // capacityForLocation() prefers a stated capacity and returns null
            // rather than the local cache disk's figures for a mount that
            // can't report its own size (#415).
            $disk = \BBS\Services\ServerStats::capacityForLocation($loc);
            $loc['capacity_source'] = $disk['source'] ?? null;
            $loc['capacity_unknown_reason'] = $disk === null
                ? \BBS\Services\ServerStats::capacityUnknownReason($loc)
                : null;
            if ($disk && $disk['total'] > 0 && $disk['used'] !== null) {
                $loc['total_bytes'] = (int) $disk['total'];
                $loc['used_bytes'] = (int) $disk['used'];
                $loc['free_bytes'] = (int) $disk['free'];
                $loc['total_size_gb'] = round($disk['total'] / 1073741824, 2);
                $loc['current_usage_gb'] = round($disk['used'] / 1073741824, 2);
                $loc['free_space_gb'] = round($disk['free'] / 1073741824, 2);
                $loc['usage_percentage'] = $disk['total'] > 0
                    ? round(($disk['used'] / $disk['total']) * 100, 1)
                    : 0;
            } else {
                $loc['total_bytes'] = null;
                $loc['used_bytes'] = null;
                $loc['free_bytes'] = null;
                $loc['total_size_gb'] = null;
                $loc['current_usage_gb'] = null;
                $loc['free_space_gb'] = null;
                $loc['usage_percentage'] = null;
            }
        }
        unset($loc);

        // Decorate remote SSH configs with the same fields derived from the
        // pre-polled disk_* columns (updated by the scheduler every 15 min).
        foreach ($remoteConfigs as &$rc) {
            $total = $rc['disk_total_bytes'] !== null ? (int) $rc['disk_total_bytes'] : null;
            $used  = $rc['disk_used_bytes']  !== null ? (int) $rc['disk_used_bytes']  : null;
            $free  = $rc['disk_free_bytes']  !== null ? (int) $rc['disk_free_bytes']  : null;
            $rc['total_size_gb'] = $total !== null ? round($total / 1073741824, 2) : null;
            $rc['current_usage_gb'] = $used !== null ? round($used / 1073741824, 2) : null;
            $rc['free_space_gb'] = $free !== null ? round($free / 1073741824, 2) : null;
            $rc['usage_percentage'] = ($total && $used !== null) ? round(($used / $total) * 100, 1) : null;
        }
        unset($rc);

        $this->json([
            'local' => $locations,
            'remote_ssh' => $remoteConfigs,
        ]);
    }

    // ── Remote SSH Repos ────────────────────────────────

    private function createRemoteSshRepository(int $id, string $name, string $encryption, string $passphrase, ?int $remoteSshConfigId): void
    {
        if (!$remoteSshConfigId) {
            $this->json(['error' => 'remote_ssh_config_id is required for remote SSH repositories'], 400);
        }

        $remoteSshService = new \BBS\Services\RemoteSshService();
        $config = $remoteSshService->getById($remoteSshConfigId);
        if (!$config) {
            $this->json(['error' => 'Remote SSH config not found'], 404);
        }

        $safeName = $this->sanitizeRepoName($name);

        // Auto-generate passphrase if needed
        if (empty($passphrase) && $encryption !== 'none') {
            $segments = [];
            for ($i = 0; $i < 5; $i++) {
                $segments[] = strtoupper(substr(bin2hex(random_bytes(3)), 0, 4));
            }
            $passphrase = implode('-', $segments);
        }

        $repoPath = $remoteSshService->buildRepoPath($config, $safeName);

        $result = $remoteSshService->initRepo($config, $repoPath, $encryption, $passphrase);
        if (!$result['success']) {
            $errorMsg = $result['stderr'] ?? $result['output'] ?? 'Unknown error';
            $this->db->insert('server_log', [
                'agent_id' => $id,
                'level' => 'error',
                'message' => "borg init failed for remote repo \"{$safeName}\" via API on {$config['remote_host']}: {$errorMsg}",
            ]);
            $this->json(['error' => "Failed to initialize repository on {$config['remote_host']}: {$errorMsg}"], 500);
        }

        $repoId = $this->db->insert('repositories', [
            'agent_id' => $id,
            'storage_type' => 'remote_ssh',
            'remote_ssh_config_id' => $remoteSshConfigId,
            'name' => $safeName,
            'path' => $repoPath,
            'encryption' => $encryption,
            'passphrase_encrypted' => $encryption !== 'none' ? Encryption::encrypt($passphrase) : null,
        ]);

        $this->db->insert('server_log', [
            'agent_id' => $id,
            'level' => 'info',
            'message' => "Remote repository \"{$safeName}\" created via API on {$config['remote_user']}@{$config['remote_host']}",
        ]);

        $response = [
            'id' => (int) $repoId,
            'name' => $safeName,
            'path' => $repoPath,
            'encryption' => $encryption,
            'storage_type' => 'remote_ssh',
            'remote_host' => $config['remote_host'],
        ];

        if ($encryption !== 'none') {
            $response['passphrase'] = $passphrase;
        }

        $this->json($response, 201);
    }

    // ── Repo Edit/Delete ────────────────────────────────

    public function renameRepository(int $id, int $repoId): void
    {
        $this->requireApiToken();
        $input = $this->getJsonInput();

        $repo = $this->db->fetchOne("SELECT r.*, a.id as agent_id FROM repositories r JOIN agents a ON a.id = r.agent_id WHERE r.id = ? AND r.agent_id = ?", [$repoId, $id]);
        if (!$repo) {
            $this->json(['error' => 'Repository not found'], 404);
        }

        $newName = trim($input['name'] ?? '');
        if (empty($newName)) {
            $this->json(['error' => 'name is required'], 400);
        }

        if (($repo['storage_type'] ?? 'local') === 'remote_ssh') {
            $this->json(['error' => 'Rename is not supported for remote SSH repositories'], 400);
        }

        $activeJobs = $this->db->fetchOne("SELECT COUNT(*) as cnt FROM backup_jobs WHERE repository_id = ? AND status IN ('queued', 'sent', 'running')", [$repoId]);
        if ((int) ($activeJobs['cnt'] ?? 0) > 0) {
            $this->json(['error' => 'Cannot rename while jobs are active'], 409);
        }

        $safeName = $this->sanitizeRepoName($newName);
        if (empty($safeName)) {
            $this->json(['error' => 'Name must contain at least one alphanumeric character'], 400);
        }

        $lastSlash = strrpos($repo['path'], '/');
        $newPath = substr($repo['path'], 0, $lastSlash + 1) . $safeName;

        $existing = $this->db->fetchOne("SELECT id FROM repositories WHERE path = ? AND id != ?", [$newPath, $repoId]);
        if ($existing) {
            $this->json(['error' => 'A repository already exists at that path'], 409);
        }

        // Rename on disk
        $oldLocalPath = BorgCommandBuilder::getLocalRepoPath($repo);
        if (!empty($oldLocalPath) && is_dir($oldLocalPath)) {
            $newLocalPath = dirname($oldLocalPath) . '/' . $safeName;
            $cmd = 'sudo /usr/local/bin/bbs-ssh-helper rename-repo-dir ' . escapeshellarg($oldLocalPath) . ' ' . escapeshellarg($newLocalPath) . ' 2>&1';
            exec($cmd, $output, $retval);
            if ($retval !== 0) {
                $this->json(['error' => 'Rename failed: ' . implode(' ', $output)], 500);
            }
        }

        $this->db->update('repositories', ['name' => $safeName, 'path' => $newPath], 'id = ?', [$repoId]);

        $this->db->insert('server_log', [
            'agent_id' => $id,
            'level' => 'info',
            'message' => "Repository renamed from \"{$repo['name']}\" to \"{$safeName}\" via API",
        ]);

        $this->json(['status' => 'ok', 'name' => $safeName, 'path' => $newPath]);
    }

    public function deleteRepository(int $id, int $repoId): void
    {
        $this->requireApiToken();

        $repo = $this->db->fetchOne("SELECT r.*, a.id as agent_id FROM repositories r JOIN agents a ON a.id = r.agent_id WHERE r.id = ? AND r.agent_id = ?", [$repoId, $id]);
        if (!$repo) {
            $this->json(['error' => 'Repository not found'], 404);
        }

        // Locked archives (legal hold, #314) block deletion — matches the web UI.
        $lockedCount = $this->db->fetchOne("SELECT COUNT(*) as cnt FROM archives WHERE repository_id = ? AND locked = 1", [$repoId]);
        if ((int) ($lockedCount['cnt'] ?? 0) > 0) {
            $this->json([
                'error' => 'Cannot delete — repository contains locked archives. Unlock them first.',
                'reason' => 'locked_archives',
                'locked_archives' => (int) ($lockedCount['cnt'] ?? 0),
            ], 409);
        }

        // Name the plans rather than just refusing: a caller can then offer to
        // open them instead of leaving someone guessing why nothing happened.
        $plans = $this->db->fetchAll(
            "SELECT id, name FROM backup_plans WHERE repository_id = ? ORDER BY name",
            [$repoId]
        );
        if (!empty($plans)) {
            $this->json([
                'error' => sprintf('In use by %d backup plan(s)', count($plans)),
                'reason' => 'plans_attached',
                'plans' => array_map(fn($p) => ['id' => (int) $p['id'], 'name' => $p['name']], $plans),
            ], 409);
        }

        $activeJobs = $this->db->fetchOne("SELECT COUNT(*) as cnt FROM backup_jobs WHERE repository_id = ? AND status IN ('queued', 'sent', 'running')", [$repoId]);
        if ((int) ($activeJobs['cnt'] ?? 0) > 0) {
            $this->json([
                'error' => 'Cannot delete — repository has active jobs',
                'reason' => 'active_jobs',
            ], 409);
        }

        // ?keep_data=1 (or JSON body {"keep_data": true}): unlink only — keep
        // the borg data on disk for later re-attachment via scan/adopt (#369)
        $input = $this->getJsonInput();
        $keepData = !empty($_GET['keep_data']) || !empty($input['keep_data']);

        // Delete from disk
        $localPath = BorgCommandBuilder::getLocalRepoPath($repo);
        if (!$keepData && !empty($localPath) && is_dir($localPath)) {
            exec('sudo /usr/local/bin/bbs-ssh-helper delete-storage ' . escapeshellarg($localPath) . ' 2>&1', $output, $retval);
        }

        $this->db->delete('repositories', 'id = ?', [$repoId]);

        $agent = $this->db->fetchOne("SELECT * FROM agents WHERE id = ?", [$id]);
        if ($agent && !empty($agent['ssh_unix_user'])) {
            // Refresh storage paths
            $repos = $this->db->fetchAll("SELECT r.*, sl.path as location_path FROM repositories r LEFT JOIN storage_locations sl ON sl.id = r.storage_location_id WHERE r.agent_id = ?", [$id]);
            $storagePaths = [];
            foreach ($repos as $r) {
                if (!empty($r['location_path'])) {
                    $storagePaths[] = rtrim($r['location_path'], '/') . '/' . $id;
                }
            }
            if (!empty($storagePaths)) {
                $pathList = implode("\n", array_unique($storagePaths));
                exec('sudo /usr/local/bin/bbs-ssh-helper update-storage-paths ' . escapeshellarg($agent['ssh_unix_user']) . ' ' . escapeshellarg($pathList) . ' 2>&1');
            }
        }

        $this->db->insert('server_log', [
            'agent_id' => $id,
            'level' => 'info',
            'message' => "Repository \"{$repo['name']}\" " . ($keepData ? 'unlinked (data kept on disk)' : 'deleted') . " via API",
        ]);

        $this->json(['status' => 'ok', 'message' => "Repository \"{$repo['name']}\" " . ($keepData ? 'unlinked — data kept on disk' : 'deleted')]);
    }

    // ── Archives (recovery points) ───────────────────────

    /**
     * GET /api/v1/clients/{id}/repositories/{repoId}/archives
     * List a repository's recovery points, including each one's lock state
     * (#314). Use this to find the archive id to lock.
     */
    public function listArchives(int $id, int $repoId): void
    {
        $ctx = $this->requireApiAuth();
        if (!$this->apiCanAccessAgent($ctx, $id)) {
            $this->json(['error' => 'Client not found'], 404);
        }

        $repo = $this->db->fetchOne(
            "SELECT r.id FROM repositories r WHERE r.id = ? AND r.agent_id = ?",
            [$repoId, $id]
        );
        if (!$repo) {
            $this->json(['error' => 'Repository not found'], 404);
        }

        $rows = $this->db->fetchAll(
            "SELECT id, archive_name, file_count, original_size, deduplicated_size, locked, created_at,
                    databases_backed_up IS NOT NULL AND databases_backed_up != '' AS has_databases
             FROM archives WHERE repository_id = ? ORDER BY created_at DESC",
            [$repoId]
        );
        $archives = array_map(static function ($a) {
            return [
                // Lets a caller show only the restore points a database
                // restore can actually use, instead of offering every archive
                // and failing once one is chosen.
                'has_databases'     => (bool) $a['has_databases'],
                'id'                => (int) $a['id'],
                'name'              => $a['archive_name'],
                'file_count'        => (int) $a['file_count'],
                'original_size'     => (int) $a['original_size'],
                'deduplicated_size' => (int) $a['deduplicated_size'],
                'locked'            => (bool) ((int) $a['locked']),
                'created_at'        => $a['created_at'],
            ];
        }, $rows);

        $this->json(['archives' => $archives]);
    }

    /**
     * POST /api/v1/clients/{id}/repositories/{repoId}/archives/{archiveId}/lock
     * Lock or unlock a recovery point (#314). Body: {"locked": true|false}.
     * A locked archive is never pruned and cannot be deleted. If the repo is
     * busy the change is queued and applies when the running job finishes
     * (response 202). Idempotent — locking an already-locked archive is a
     * no-op 200.
     */
    public function setArchiveLock(int $id, int $repoId, int $archiveId): void
    {
        $this->requireApiToken();
        $input = $this->getJsonInput();

        if (!array_key_exists('locked', $input)) {
            $this->json(['error' => 'locked is required (boolean)'], 400);
        }
        $desired = (bool) $input['locked'];

        $result = (new \BBS\Services\ArchiveLockService())->setLock($id, $repoId, $archiveId, $desired);

        $this->json([
            'status'  => $result['ok'] ? 'ok' : 'error',
            'result'  => $result['result'],
            'message' => $result['message'],
            'name'    => $result['archive_name'] ?? null,
            'locked'  => $result['locked'] ?? ($result['result'] === 'queued' ? $desired : null),
        ], $result['code']);
    }

    // ── Plan Edit/Delete/Trigger ─────────────────────────

    public function updatePlan(int $id, int $planId): void
    {
        $this->requireApiToken();
        $input = $this->getJsonInput();

        $plan = $this->db->fetchOne("SELECT * FROM backup_plans WHERE id = ? AND agent_id = ?", [$planId, $id]);
        if (!$plan) {
            $this->json(['error' => 'Plan not found'], 404);
        }

        // Update plan fields (only those provided)
        $planData = [];
        foreach (['name', 'directories', 'excludes', 'advanced_options'] as $field) {
            if (isset($input[$field])) {
                $planData[$field] = trim($input[$field]) ?: null;
            }
        }
        if (isset($input['repository_id'])) {
            $repo = $this->db->fetchOne("SELECT id FROM repositories WHERE id = ? AND agent_id = ?", [(int) $input['repository_id'], $id]);
            if (!$repo) {
                $this->json(['error' => 'Repository not found or does not belong to this client'], 404);
            }
            $planData['repository_id'] = (int) $input['repository_id'];
        }
        foreach (['prune_minutes', 'prune_hours', 'prune_days', 'prune_weeks', 'prune_months', 'prune_years'] as $field) {
            if (isset($input[$field])) {
                $planData[$field] = (int) $input[$field];
            }
        }
        if (isset($input['enabled'])) {
            $planData['enabled'] = $input['enabled'] ? 1 : 0;
        }

        if (!empty($planData)) {
            $this->db->update('backup_plans', $planData, 'id = ?', [$planId]);
        }

        // Update schedule if provided
        $schedule = $this->db->fetchOne("SELECT * FROM schedules WHERE backup_plan_id = ?", [$planId]);
        if ($schedule) {
            $schedData = [];
            if (isset($input['frequency'])) $schedData['frequency'] = $input['frequency'];
            if (isset($input['times'])) $schedData['times'] = $input['times'];
            if (isset($input['day_of_week'])) $schedData['day_of_week'] = $input['day_of_week'];
            if (isset($input['day_of_month'])) $schedData['day_of_month'] = $input['day_of_month'];
            if (isset($input['timezone'])) $schedData['timezone'] = $input['timezone'];

            if (isset($input['frequency'])) {
                $schedData['enabled'] = ($input['frequency'] !== 'manual') ? 1 : 0;
                // Calculate next_run
                $freq = $input['frequency'];
                $times = $input['times'] ?? $schedule['times'] ?? '02:00';
                $dow = $input['day_of_week'] ?? $schedule['day_of_week'];
                $dom = $input['day_of_month'] ?? $schedule['day_of_month'];
                $tz = $input['timezone'] ?? $schedule['timezone'] ?? 'UTC';
                $schedData['next_run'] = $this->calcNextRun($freq, $times, $dow, $dom, $tz);
            }

            if (!empty($schedData)) {
                $this->db->update('schedules', $schedData, 'id = ?', [$schedule['id']]);
            }
        }

        // Update plugins if provided
        if (isset($input['plugins'])) {
            $this->db->query("DELETE FROM backup_plan_plugins WHERE backup_plan_id = ?", [$planId]);
            $order = 0;
            foreach ($input['plugins'] as $key => $val) {
                if (is_array($val)) {
                    $configId = (int) ($val['plugin_config_id'] ?? 0);
                    if ($configId <= 0) continue;
                    $pc = $this->db->fetchOne("SELECT plugin_id FROM plugin_configs WHERE id = ? AND agent_id = ?", [$configId, $id]);
                    if (!$pc) continue;
                    $this->db->insert('backup_plan_plugins', [
                        'backup_plan_id' => $planId, 'plugin_id' => $pc['plugin_id'],
                        'plugin_config_id' => $configId, 'config' => '{}',
                        'execution_order' => $order++, 'enabled' => 1,
                    ]);
                } else {
                    $pluginId = (int) $key;
                    $configId = (int) $val;
                    if ($pluginId <= 0 || $configId <= 0) continue;
                    $this->db->insert('backup_plan_plugins', [
                        'backup_plan_id' => $planId, 'plugin_id' => $pluginId,
                        'plugin_config_id' => $configId, 'config' => '{}',
                        'execution_order' => $order++, 'enabled' => 1,
                    ]);
                }
            }
        }

        $this->json(['status' => 'ok', 'message' => 'Plan updated']);
    }

    public function deletePlan(int $id, int $planId): void
    {
        $this->requireApiToken();

        $plan = $this->db->fetchOne("SELECT * FROM backup_plans WHERE id = ? AND agent_id = ?", [$planId, $id]);
        if (!$plan) {
            $this->json(['error' => 'Plan not found'], 404);
        }

        $this->db->delete('backup_plans', 'id = ?', [$planId]);

        $this->db->insert('server_log', [
            'agent_id' => $id,
            'level' => 'info',
            'message' => "Backup plan \"{$plan['name']}\" deleted via API",
        ]);

        $this->json(['status' => 'ok', 'message' => "Plan \"{$plan['name']}\" deleted"]);
    }

    public function pausePlan(int $id, int $planId): void
    {
        $ctx = $this->requireApiAuth();
        if (!$this->apiCanAccessAgent($ctx, $id)) {
            $this->json(['error' => 'Plan not found'], 404);
        }

        $plan = $this->db->fetchOne("SELECT * FROM backup_plans WHERE id = ? AND agent_id = ?", [$planId, $id]);
        if (!$plan) {
            $this->json(['error' => 'Plan not found'], 404);
        }

        $schedule = $this->db->fetchOne("SELECT * FROM schedules WHERE backup_plan_id = ?", [$planId]);
        if (!$schedule) {
            $this->json(['error' => 'No schedule found for this plan'], 404);
        }

        $this->db->update('schedules', ['enabled' => 0], 'id = ?', [$schedule['id']]);

        $this->json(['status' => 'ok', 'message' => "Plan \"{$plan['name']}\" paused"]);
    }

    public function resumePlan(int $id, int $planId): void
    {
        $ctx = $this->requireApiAuth();
        if (!$this->apiCanAccessAgent($ctx, $id)) {
            $this->json(['error' => 'Plan not found'], 404);
        }

        $plan = $this->db->fetchOne("SELECT * FROM backup_plans WHERE id = ? AND agent_id = ?", [$planId, $id]);
        if (!$plan) {
            $this->json(['error' => 'Plan not found'], 404);
        }

        $schedule = $this->db->fetchOne("SELECT * FROM schedules WHERE backup_plan_id = ?", [$planId]);
        if (!$schedule) {
            $this->json(['error' => 'No schedule found for this plan'], 404);
        }

        $this->db->update('schedules', ['enabled' => 1], 'id = ?', [$schedule['id']]);

        $this->json(['status' => 'ok', 'message' => "Plan \"{$plan['name']}\" resumed"]);
    }

    public function triggerPlan(int $id, int $planId): void
    {
        $ctx = $this->requireApiAuth();
        if (!$this->apiCanAccessAgent($ctx, $id)) {
            $this->json(['error' => 'Plan not found'], 404);
        }

        $plan = $this->db->fetchOne("SELECT * FROM backup_plans WHERE id = ? AND agent_id = ?", [$planId, $id]);
        if (!$plan) {
            $this->json(['error' => 'Plan not found'], 404);
        }

        // Check for existing active job on this plan
        $activeJob = $this->db->fetchOne(
            "SELECT id FROM backup_jobs WHERE backup_plan_id = ? AND status IN ('queued', 'sent', 'running')", [$planId]
        );
        if ($activeJob) {
            $this->json(['error' => 'A backup is already queued or running for this plan'], 409);
        }

        $jobId = $this->db->insert('backup_jobs', [
            'backup_plan_id' => $planId,
            'agent_id' => $id,
            'repository_id' => $plan['repository_id'],
            'task_type' => 'backup',
            'status' => 'queued',
        ]);

        $this->db->insert('server_log', [
            'agent_id' => $id,
            'level' => 'info',
            'message' => "Backup triggered via API for plan \"{$plan['name']}\"",
        ]);

        $this->json(['status' => 'ok', 'job_id' => (int) $jobId, 'message' => "Backup queued for plan \"{$plan['name']}\""]);
    }

    // ── Client Edit ──────────────────────────────────────

    /**
     * POST /api/v1/clients/{id}/rotate-key
     *
     * Issues a new key and stops accepting the old one, at once. The client
     * cannot report until its own configuration carries the new key, so the
     * response says where that lives.
     */
    public function rotateClientKey(int $id): void
    {
        $this->requireApiToken();

        $agent = $this->db->fetchOne("SELECT id, name, platform FROM agents WHERE id = ?", [$id]);
        if (!$agent) {
            $this->json(['error' => 'Client not found'], 404);
        }

        $svc = new \BBS\Services\AgentKeyService();
        $key = $svc->rotate($id, 'API');
        [$location, $instructions] = $svc->reconfigureHint($agent);

        $this->json([
            'status' => 'ok',
            'client_id' => (int) $agent['id'],
            'api_key' => $key,
            'previous_key_revoked' => true,
            'reconfigure_location' => $location,
            'reconfigure_instructions' => $instructions,
        ]);
    }

    public function updateClient(int $id): void
    {
        $this->requireApiToken();
        $input = $this->getJsonInput();

        $agent = $this->db->fetchOne("SELECT * FROM agents WHERE id = ?", [$id]);
        if (!$agent) {
            $this->json(['error' => 'Client not found'], 404);
        }

        $data = [];
        if (isset($input['name'])) $data['name'] = trim($input['name']);

        // Moving a client between profiles changes how patient BBS is with it
        // and what its next new plan starts from. It deliberately does not
        // rewrite the plans it already has — that is what the profile's apply
        // endpoint is for.
        if (array_key_exists('client_profile_id', $input)) {
            $svc = new \BBS\Services\ClientProfileService();
            $pid = $input['client_profile_id'] !== null ? (int) $input['client_profile_id'] : null;
            if ($pid !== null && !$svc->find($pid)) {
                $this->json(['error' => 'client_profile_id does not exist'], 422);
            }
            $data['client_profile_id'] = $pid ?? $svc->defaultProfileId();
        }

        if (empty($data)) {
            $this->json(['error' => 'No fields to update'], 400);
        }

        $this->db->update('agents', $data, 'id = ?', [$id]);

        $this->json(['status' => 'ok', 'message' => 'Client updated']);
    }

    // ── Jobs & Queue ─────────────────────────────────────

    public function listJobs(int $id): void
    {
        $ctx = $this->requireApiAuth();
        if (!$this->apiCanAccessAgent($ctx, $id)) {
            $this->json(['error' => 'Client not found'], 404);
        }

        $agent = $this->db->fetchOne("SELECT id FROM agents WHERE id = ?", [$id]);
        if (!$agent) {
            $this->json(['error' => 'Client not found'], 404);
        }

        $limit = min((int) ($_GET['limit'] ?? 50), 200);
        $offset = (int) ($_GET['offset'] ?? 0);
        $status = $_GET['status'] ?? null;

        $where = "bj.agent_id = ?";
        $params = [$id];

        // Accepts one status or a comma-separated list ("completed,failed").
        // A caller plotting finished jobs shouldn't have to over-fetch and
        // discard: results are ordered by queued_at, so a long backlog would
        // otherwise return queued rows ahead of the completed ones it wants.
        if ($status) {
            $valid = ['queued', 'sent', 'running', 'completed', 'failed', 'cancelled'];
            $wanted = array_values(array_intersect(
                array_map('trim', explode(',', (string) $status)),
                $valid
            ));
            if (empty($wanted)) {
                $this->json(['error' => 'status must be one or more of: ' . implode(', ', $valid)], 400);
            }
            $where .= " AND bj.status IN (" . implode(',', array_fill(0, count($wanted), '?')) . ")";
            $params = array_merge($params, $wanted);
        }

        $jobs = $this->db->fetchAll("
            SELECT bj.id, bj.task_type, bj.status, bj.files_total, bj.files_processed,
                   bj.bytes_total, bj.bytes_processed, bj.duration_seconds,
                   bj.status_message, bj.queued_at, bj.started_at, bj.completed_at,
                   bp.name as plan_name, r.name as repository_name
            FROM backup_jobs bj
            LEFT JOIN backup_plans bp ON bp.id = bj.backup_plan_id
            LEFT JOIN repositories r ON r.id = bj.repository_id
            WHERE {$where}
            ORDER BY bj.queued_at DESC
            LIMIT {$limit} OFFSET {$offset}
        ", $params);

        $total = $this->db->fetchOne("SELECT COUNT(*) as cnt FROM backup_jobs bj WHERE {$where}", $params);

        $this->json([
            'jobs' => $jobs,
            'total' => (int) $total['cnt'],
            'limit' => $limit,
            'offset' => $offset,
        ]);
    }

    public function getJob(int $id, int $jobId): void
    {
        $ctx = $this->requireApiAuth();
        if (!$this->apiCanAccessAgent($ctx, $id)) {
            $this->json(['error' => 'Job not found'], 404);
        }

        $job = $this->db->fetchOne("
            SELECT bj.*, bp.name as plan_name, r.name as repository_name
            FROM backup_jobs bj
            LEFT JOIN backup_plans bp ON bp.id = bj.backup_plan_id
            LEFT JOIN repositories r ON r.id = bj.repository_id
            WHERE bj.id = ? AND bj.agent_id = ?
        ", [$jobId, $id]);

        if (!$job) {
            $this->json(['error' => 'Job not found'], 404);
        }

        $this->json($job);
    }

    public function getQueue(): void
    {
        $ctx = $this->requireApiAuth();
        [$agentWhere, $agentParams] = $this->apiAgentWhereClause($ctx, 'a');

        $jobs = $this->db->fetchAll("
            SELECT bj.id, bj.task_type, bj.status, bj.status_message,
                   bj.queued_at, bj.started_at, bj.files_total, bj.files_processed,
                   bj.bytes_total, bj.bytes_processed,
                   a.name as client_name, a.id as client_id,
                   bp.name as plan_name, r.name as repository_name
            FROM backup_jobs bj
            JOIN agents a ON a.id = bj.agent_id
            LEFT JOIN backup_plans bp ON bp.id = bj.backup_plan_id
            LEFT JOIN repositories r ON r.id = bj.repository_id
            WHERE bj.status IN ('queued', 'sent', 'running') AND {$agentWhere}
            ORDER BY bj.queued_at ASC
        ", $agentParams);

        // Recent history (?recent=N, default 10, cap 50) — the finished
        // jobs the web queue page shows below the in-flight section.
        // Cross-client ordering has to happen in SQL; the per-client jobs
        // endpoint can't answer this without a request per client.
        $recentN = isset($_GET['recent']) ? max(1, min(50, (int) $_GET['recent'])) : 10;
        $recent = $this->db->fetchAll("
            SELECT bj.id, bj.task_type, bj.status, bj.status_message,
                   bj.queued_at, bj.started_at, bj.completed_at,
                   bj.duration_seconds, bj.had_warnings,
                   a.name as client_name, a.id as client_id,
                   bp.name as plan_name, r.name as repository_name
            FROM backup_jobs bj
            JOIN agents a ON a.id = bj.agent_id
            LEFT JOIN backup_plans bp ON bp.id = bj.backup_plan_id
            LEFT JOIN repositories r ON r.id = bj.repository_id
            WHERE bj.status IN ('completed', 'failed') AND {$agentWhere}
            ORDER BY bj.completed_at DESC
            LIMIT {$recentN}
        ", $agentParams);
        foreach ($recent as &$r) {
            $r['id'] = (int) $r['id'];
            $r['client_id'] = (int) $r['client_id'];
            $r['duration_seconds'] = $r['duration_seconds'] !== null ? (int) $r['duration_seconds'] : null;
            $r['had_warnings'] = (int) $r['had_warnings'];
        }
        unset($r);

        // Queue slots are server-wide capacity (global); stats are scoped
        // to the caller's agents like everything else.
        $activeCount = $this->db->count('backup_jobs', "status IN ('sent', 'running')");
        $maxQueue = (int) ($this->db->fetchOne("SELECT `value` FROM settings WHERE `key` = 'max_queue'")['value'] ?? 4);

        $stats = ['queued' => 0, 'running' => 0, 'completed_24h' => 0, 'failed_24h' => 0, 'avg_seconds_24h' => 0];
        foreach ($this->db->fetchAll("
            SELECT bj.status, COUNT(*) AS c FROM backup_jobs bj
            JOIN agents a ON a.id = bj.agent_id
            WHERE bj.status IN ('queued', 'sent', 'running') AND {$agentWhere}
            GROUP BY bj.status", $agentParams) as $row) {
            if ($row['status'] === 'running') {
                $stats['running'] += (int) $row['c'];
            } else {
                $stats['queued'] += (int) $row['c']; // queued + sent
            }
        }
        foreach ($this->db->fetchAll("
            SELECT bj.status, COUNT(*) AS c FROM backup_jobs bj
            JOIN agents a ON a.id = bj.agent_id
            WHERE bj.status IN ('completed', 'failed') AND bj.completed_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
              AND {$agentWhere}
            GROUP BY bj.status", $agentParams) as $row) {
            $stats[$row['status'] . '_24h'] = (int) $row['c'];
        }
        $avg = $this->db->fetchOne("
            SELECT ROUND(AVG(TIMESTAMPDIFF(SECOND, bj.started_at, bj.completed_at))) AS avg_sec
            FROM backup_jobs bj
            JOIN agents a ON a.id = bj.agent_id
            WHERE bj.status = 'completed' AND bj.completed_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
              AND bj.started_at IS NOT NULL AND {$agentWhere}
        ", $agentParams);
        $stats['avg_seconds_24h'] = (int) ($avg['avg_sec'] ?? 0);

        $this->json([
            'queue' => $jobs,
            'count' => count($jobs),
            'recent' => $recent,
            'slots' => ['active' => $activeCount, 'max' => $maxQueue],
            'stats' => $stats,
        ]);
    }

    /**
     * GET /api/v1/dashboard — one round trip for a status screen,
     * scoped to the caller's agents .
     */
    public function dashboard(): void
    {
        $ctx = $this->requireApiAuth();
        [$agentWhere, $agentParams] = $this->apiAgentWhereClause($ctx, 'a');

        $clients = ['total' => 0, 'online' => 0, 'offline' => 0, 'error' => 0, 'setup' => 0];
        foreach ($this->db->fetchAll(
            "SELECT a.status, COUNT(*) AS c FROM agents a WHERE {$agentWhere} GROUP BY a.status",
            $agentParams
        ) as $row) {
            $clients[$row['status']] = (int) $row['c'];
            $clients['total'] += (int) $row['c'];
        }

        $jobs = ['running' => 0, 'queued' => 0, 'failed_24h' => 0, 'completed_24h' => 0];
        foreach ($this->db->fetchAll("
            SELECT bj.status, COUNT(*) AS c FROM backup_jobs bj
            JOIN agents a ON a.id = bj.agent_id
            WHERE bj.status IN ('queued', 'sent', 'running') AND {$agentWhere}
            GROUP BY bj.status", $agentParams) as $row) {
            if ($row['status'] === 'queued') {
                $jobs['queued'] += (int) $row['c'];
            } else {
                $jobs['running'] += (int) $row['c']; // running + sent
            }
        }
        foreach ($this->db->fetchAll("
            SELECT bj.status, COUNT(*) AS c FROM backup_jobs bj
            JOIN agents a ON a.id = bj.agent_id
            WHERE bj.status IN ('failed', 'completed') AND bj.completed_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
              AND {$agentWhere}
            GROUP BY bj.status", $agentParams) as $row) {
            $jobs[$row['status'] . '_24h'] = (int) $row['c'];
        }

        $storage = ['used_bytes' => 0, 'total_bytes' => 0, 'locations' => 0];
        $storage['locations'] = (int) ($this->db->fetchOne("SELECT COUNT(*) AS c FROM storage_locations")['c'] ?? 0);
        $defaultLoc = $this->db->fetchOne("SELECT id, path, capacity_bytes FROM storage_locations WHERE is_default = 1");
        if ($defaultLoc) {
            // capacityForLocation(), so a default location on a mount that
            // can't report its own size shows nothing rather than the local
            // cache disk's figures (#415).
            $disk = \BBS\Services\ServerStats::capacityForLocation($defaultLoc);
            if ($disk && $disk['used'] !== null) {
                $storage['used_bytes'] = (int) $disk['used'];
                $storage['total_bytes'] = (int) $disk['total'];
            }
        }

        $active = $this->db->fetchAll("
            SELECT bj.id, a.name AS client, a.id AS client_id, r.name AS repo,
                   bj.task_type, bj.status,
                   bj.bytes_processed, bj.bytes_total,
                   bj.files_processed, bj.files_total, bj.started_at
            FROM backup_jobs bj
            JOIN agents a ON a.id = bj.agent_id
            LEFT JOIN repositories r ON r.id = bj.repository_id
            WHERE bj.status IN ('queued', 'sent', 'running') AND {$agentWhere}
            ORDER BY bj.queued_at ASC
        ", $agentParams);
        foreach ($active as &$aj) {
            foreach (['id', 'client_id', 'bytes_processed', 'bytes_total', 'files_processed', 'files_total'] as $k) {
                $aj[$k] = (int) $aj[$k];
            }
        }
        unset($aj);

        // Unread notifications visible to this user: their own, plus
        // unowned ones for agents in their scope (admins see everything).
        if (($ctx['role'] ?? '') === 'admin') {
            $unread = $this->db->fetchOne(
                "SELECT COUNT(*) AS c FROM notifications WHERE read_at IS NULL AND resolved_at IS NULL"
            );
        } else {
            $unread = $this->db->fetchOne("
                SELECT COUNT(*) AS c FROM notifications n
                LEFT JOIN agents a ON a.id = n.agent_id
                WHERE n.read_at IS NULL AND n.resolved_at IS NULL
                  AND (n.user_id = ? OR (n.user_id IS NULL AND n.agent_id IS NOT NULL AND {$agentWhere}))",
                array_merge([(int) $ctx['id']], $agentParams));
        }

        // ── Backup Summary ──────────────────────────────────────────────
        // Scoped like everything else here: a non-admin sees the totals for
        // the clients they can reach, not the install's.
        $arch = $this->db->fetchOne("
            SELECT COUNT(*) AS recovery_points,
                   COALESCE(SUM(ar.original_size), 0) AS original_bytes,
                   COALESCE(SUM(ar.deduplicated_size), 0) AS deduplicated_bytes,
                   MAX(ar.created_at) AS last_backup_at
            FROM archives ar
            JOIN repositories r ON r.id = ar.repository_id
            JOIN agents a ON a.id = r.agent_id
            WHERE {$agentWhere}", $agentParams) ?: [];

        // "On disk" is what the repositories actually occupy, which is not the
        // same as the sum of archive dedup sizes — compaction and pruning move
        // one without the other. The web's tile reads repository size, so this
        // does too.
        $onDisk = (int) ($this->db->fetchOne("
            SELECT COALESCE(SUM(r.size_bytes), 0) AS b
            FROM repositories r JOIN agents a ON a.id = r.agent_id
            WHERE {$agentWhere}", $agentParams)['b'] ?? 0);

        $origBytes = (int) ($arch['original_bytes'] ?? 0);
        $dedupBytes = (int) ($arch['deduplicated_bytes'] ?? 0);
        $savings = 0.0;
        if ($origBytes > 0) {
            $savings = round((1 - $dedupBytes / $origBytes) * 100, 1);
            // Clamped exactly as the web does (#191): rounding can reach 100
            // while dedup is still non-zero, and "100% saved" reads as a bug.
            if ($savings >= 100 && $dedupBytes > 0) {
                $savings = 99.9;
            }
        }

        $archives = [
            'recovery_points' => (int) ($arch['recovery_points'] ?? 0),
            'original_bytes' => $origBytes,
            'deduplicated_bytes' => $dedupBytes,
            'on_disk_bytes' => $onDisk,
            'dedup_savings_percent' => $savings,
            'last_backup_at' => $arch['last_backup_at'] ?? null,
        ];

        // ── Jobs (last 24h), one bucket per hour ────────────────────────
        // Always 24 buckets including the empty ones: a chart with gaps is a
        // different shape from a chart with zeroes, and every client
        // reconstructing the missing hours would be re-deriving them against a
        // clock it does not share with this server.
        $rows = $this->db->fetchAll("
            SELECT DATE_FORMAT(bj.completed_at, '%Y-%m-%d %H:00:00') AS hour,
                   bj.task_type, bj.status, COUNT(*) AS c
            FROM backup_jobs bj
            JOIN agents a ON a.id = bj.agent_id
            WHERE bj.completed_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
              AND bj.status IN ('completed', 'failed')
              AND {$agentWhere}
            GROUP BY hour, bj.task_type, bj.status", $agentParams);

        $restoreTypes = ['restore', 'restore_mysql', 'restore_pg', 'restore_mongo'];
        $buckets = [];
        foreach ($rows as $row) {
            $h = $row['hour'];
            $buckets[$h] ??= ['backup' => 0, 'restore' => 0, 's3_sync' => 0, 'failed' => 0];
            $n = (int) $row['c'];
            // A failed job is counted once, in `failed` only — the four series
            // stack, so counting it in its type as well would overstate the bar.
            if ($row['status'] === 'failed') {
                $buckets[$h]['failed'] += $n;
                continue;
            }
            if ($row['task_type'] === 'backup') {
                $buckets[$h]['backup'] += $n;
            } elseif (in_array($row['task_type'], $restoreTypes, true)) {
                $buckets[$h]['restore'] += $n;
            } elseif ($row['task_type'] === 's3_sync') {
                $buckets[$h]['s3_sync'] += $n;
            }
        }

        $jobs24h = [];
        $hourDt = new \DateTime('now', new \DateTimeZone('UTC'));
        for ($i = 23; $i >= 0; $i--) {
            $h = (clone $hourDt)->modify("-{$i} hours")->format('Y-m-d H:00:00');
            $jobs24h[] = ['hour' => $h] + ($buckets[$h] ?? ['backup' => 0, 'restore' => 0, 's3_sync' => 0, 'failed' => 0]);
        }

        $maint = $this->db->fetchOne("SELECT `value` FROM settings WHERE `key` = 'maintenance_mode'");

        // Feeds the account menu's upgrade item. Admin-only like the web
        // badge — null for anyone else, and the client hides the item. Both
        // numbers are sent honestly; the web shows the server update in
        // preference to the agent count, and clients follow the same rule.
        $updates = null;
        if (($ctx['role'] ?? '') === 'admin') {
            $updateService = new \BBS\Services\UpdateService();
            $updates = [
                'server_available' => $updateService->isUpdateAvailable(),
                'agents_outdated' => $updateService->countOutdatedAgents(),
            ];
        }

        $this->json([
            'archives' => $archives,
            'jobs_24h' => $jobs24h,
            'clients' => $clients,
            'jobs' => $jobs,
            'storage' => $storage,
            'active' => $active,
            'notifications_unread' => (int) ($unread['c'] ?? 0),
            'maintenance_mode' => ($maint['value'] ?? '0') === '1',
            'updates' => $updates,
            'generated_at' => date('c'),
        ]);
    }

    /**
     * GET /api/v1/server-stats
     *
     * Its own endpoint rather than more keys on /dashboard, for the same
     * reason the web split them: this is the part worth polling on a short
     * timer, and it shells out to read the system.
     *
     * Numbers, not formatted strings. The web endpoint formats server-side
     * because its JavaScript only swaps text; a client that draws meters needs
     * the figures.
     */
    public function serverStats(): void
    {
        $ctx = $this->requireApiAuth();
        if (($ctx['role'] ?? '') !== 'admin') {
            $this->json(['error' => 'Admin access required'], 403);
        }

        $cpu = ServerStats::getCpuLoad();
        $mem = ServerStats::getMemory();
        $net = ServerStats::getNetworkThroughput();

        // Byte figures per mount, rather than getPartitions()'s df-formatted
        // strings ("4.0T"), which a client cannot do arithmetic on.
        $partitions = [];
        foreach (ServerStats::getPartitions() as $p) {
            $mount = $p['mount'] ?? null;
            if (!$mount) {
                continue;
            }
            $du = ServerStats::getDiskUsage($mount);
            $partitions[] = [
                'mount' => $mount,
                'used_bytes' => $du ? (int) $du['used'] : null,
                'total_bytes' => $du ? (int) $du['total'] : null,
                'percent' => (int) ($p['percent'] ?? ($du['percent'] ?? 0)),
            ];
        }

        // Which mount the health card features. Same fallback the web uses —
        // sent rather than described, so a client doesn't reimplement it and
        // arrive somewhere else.
        $featured = null;
        if (\BBS\Core\Config::isHosted()) {
            $row = $this->db->fetchOne("SELECT path FROM storage_locations WHERE is_default = 1");
            if ($row && !empty($row['path'])) {
                $featured = rtrim($row['path'], '/') ?: '/';
            }
        }
        $mounts = array_column($partitions, 'mount');
        if ($featured === null || !in_array($featured, $mounts, true)) {
            foreach (['/var/bbs', '/'] as $candidate) {
                if (in_array($candidate, $mounts, true)) {
                    $featured = $candidate;
                    break;
                }
            }
        }
        // A hosted default mount that df didn't list still gets measured, so
        // the card has something to feature.
        if ($featured !== null && !in_array($featured, $mounts, true)) {
            $du = ServerStats::getDiskUsage($featured);
            if ($du) {
                $partitions[] = [
                    'mount' => $featured,
                    'used_bytes' => (int) $du['used'],
                    'total_bytes' => (int) $du['total'],
                    'percent' => (int) $du['percent'],
                ];
            } else {
                $featured = $mounts[0] ?? null;
            }
        }

        $this->json([
            'cpu' => [
                'percent' => (float) ($cpu['percent'] ?? 0),
                'load_1min' => (float) ($cpu['1min'] ?? 0),
                'cores' => (int) ($cpu['cores'] ?? 1),
            ],
            'memory' => [
                'percent' => (float) ($mem['percent'] ?? 0),
                'used_bytes' => (int) ($mem['used'] ?? 0),
                'total_bytes' => (int) ($mem['total'] ?? 0),
            ],
            // null rather than 0 when throughput cannot be read at all, so
            // "idle" and "unavailable" stay distinguishable.
            'network' => [
                'rx_bytes_per_sec' => $net ? (int) $net['rx_bps'] : null,
                'tx_bytes_per_sec' => $net ? (int) $net['tx_bps'] : null,
            ],
            'partitions' => $partitions,
            'featured_mount' => $featured,
        ]);
    }

    // ── Helpers ──────────────────────────────────────────

    private function calcNextRun(string $frequency, string $times, $dayOfWeek, $dayOfMonth, string $timezone = 'UTC'): ?string
    {
        if ($frequency === 'manual') return null;

        $tz = new \DateTimeZone($timezone);
        $utcTz = new \DateTimeZone('UTC');
        $now = new \DateTime('now', $utcTz);

        $intervals = ['10min' => 'PT10M', '15min' => 'PT15M', '30min' => 'PT30M', 'hourly' => 'PT1H'];
        if (isset($intervals[$frequency])) {
            $now->add(new \DateInterval($intervals[$frequency]));
            return $now->format('Y-m-d H:i:s');
        }

        $nowLocal = clone $now;
        $nowLocal->setTimezone($tz);

        $timeList = array_filter(array_map('trim', explode(',', $times)));
        $firstTime = !empty($timeList) ? $timeList[0] : '02:00';

        if ($frequency === 'daily' && !empty($timeList)) {
            $today = new \DateTime('today', $tz);
            foreach ($timeList as $time) {
                $candidate = clone $today;
                $parts = explode(':', $time);
                $candidate->setTime((int)($parts[0] ?? 0), (int)($parts[1] ?? 0));
                if ($candidate > $nowLocal) {
                    $candidate->setTimezone($utcTz);
                    return $candidate->format('Y-m-d H:i:s');
                }
            }
            $tomorrow = new \DateTime('tomorrow', $tz);
            $parts = explode(':', $timeList[0]);
            $tomorrow->setTime((int)($parts[0] ?? 0), (int)($parts[1] ?? 0));
            $tomorrow->setTimezone($utcTz);
            return $tomorrow->format('Y-m-d H:i:s');
        }

        if ($frequency === 'weekly' && $dayOfWeek !== null) {
            $days = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
            $dayName = $days[(int) $dayOfWeek] ?? 'Monday';
            $parts = explode(':', $firstTime);
            $next = new \DateTime("next {$dayName}", $tz);
            $next->setTime((int)($parts[0] ?? 0), (int)($parts[1] ?? 0));
            $next->setTimezone($utcTz);
            return $next->format('Y-m-d H:i:s');
        }

        if ($frequency === 'monthly') {
            $parts = explode(':', $firstTime);
            $next = new \DateTime('first day of next month', $tz);
            if ($dayOfMonth === 'last') {
                $next = new \DateTime('last day of this month', $tz);
                if ($next <= $nowLocal) {
                    $next = new \DateTime('last day of next month', $tz);
                }
            } else {
                $dom = max(1, min(28, (int) $dayOfMonth));
                $next->setDate((int) $next->format('Y'), (int) $next->format('m'), $dom);
            }
            $next->setTime((int)($parts[0] ?? 0), (int)($parts[1] ?? 0));
            $next->setTimezone($utcTz);
            return $next->format('Y-m-d H:i:s');
        }

        return null;
    }

    private function sanitizeRepoName(string $name): string
    {
        $slug = mb_strtolower($name, 'UTF-8');
        $slug = preg_replace('/[^a-z0-9_-]+/', '-', $slug);
        $slug = preg_replace('/-{2,}/', '-', $slug);
        $slug = trim($slug, '-');
        return $slug ?: 'repo';
    }

    // ── Storage management API (hosted-platform provisioning) ───────

    /**
     * POST /api/v1/storage
     * Create a local storage location. In hosted mode, this is the
     * mechanism by which the platform seeds the managed storage on
     * first boot — `is_default` is forced to true so the resulting
     * row is the one the customer's repos are pinned to.
     */
    public function createStorageLocation(): void
    {
        $this->requireApiToken();
        $input = $this->getJsonInput();

        $label = trim((string) ($input['label'] ?? ''));
        $path  = trim((string) ($input['path'] ?? ''));
        $isDefault = !empty($input['is_default']);

        if ($label === '' || $path === '') {
            $this->json(['error' => 'label and path are required'], 400);
        }
        if ($path[0] !== '/') {
            $this->json(['error' => 'path must be absolute'], 400);
        }
        if (!is_dir($path)) {
            $this->json(['error' => "Path does not exist or is not a directory: {$path}"], 400);
        }
        if ($this->db->fetchOne("SELECT id FROM storage_locations WHERE path = ?", [$path])) {
            $this->json(['error' => 'A storage location already exists at that path.'], 409);
        }

        // Hosted mode: the API is how the platform seeds storage, and the
        // customer-facing repo create form is locked to the default. Force
        // is_default true so the platform never has to track a separate flag.
        if (Config::isHosted()) {
            $isDefault = true;
        }

        if ($isDefault) {
            $this->db->query("UPDATE storage_locations SET is_default = 0 WHERE is_default = 1");
        }

        $newId = $this->db->insert('storage_locations', [
            'label' => $label,
            'path' => $path,
            'is_default' => $isDefault ? 1 : 0,
        ]);

        // Refresh /etc/bbs/allowed-storage-paths so bbs-ssh-helper accepts
        // repo operations under the new path.
        (new StorageLocationController())->updateAllowedPaths();

        $row = $this->db->fetchOne("SELECT id, label, path, is_default FROM storage_locations WHERE id = ?", [$newId]);
        $row['is_default'] = (bool) $row['is_default'];
        $this->json($row, 201);
    }

    /**
     * GET /api/v1/storage/capacity
     * Provisioned / used / free bytes for the default storage location.
     * Useful for any admin dashboard, and the data source for the hosted
     * mode "Storage" customer-visible card.
     */
    public function getStorageCapacity(): void
    {
        // Aggregate disk numbers only — same data the dashboard storage
        // widget shows every signed-in user, so no admin gate.
        $this->requireApiAuth();

        $loc = $this->db->fetchOne("SELECT id, path, capacity_bytes FROM storage_locations WHERE is_default = 1");
        if (!$loc) {
            $this->json(['error' => 'No default storage location configured'], 404);
        }
        $disk = \BBS\Services\ServerStats::capacityForLocation($loc);
        if (!$disk || $disk['used'] === null) {
            // A mount that can't report its own size and hasn't been told it:
            // say why rather than returning the cache disk's numbers (#415).
            $this->json([
                'error' => 'Capacity is unknown for the default storage location',
                'reason' => \BBS\Services\ServerStats::capacityUnknownReason($loc)
                    ?: 'Could not read disk usage for default storage',
            ], 409);
        }
        $this->json([
            'provisioned_bytes' => (int) $disk['total'],
            'used_bytes' => (int) $disk['used'],
            'free_bytes' => (int) $disk['free'],
            'capacity_source' => $disk['source'],
        ]);
    }

    // ── S3 credentials API (platform-only) ──────────────────────────

    /**
     * GET /api/v1/s3-credentials
     * Return the current global S3 sync settings. Any admin token works
     * in a normal deployment — the same data is visible in the Settings
     * UI. In hosted mode, credentials are platform-owned, so this is
     * gated to the platform token to prevent a customer-minted admin
     * token from reading the secret key. Returns null fields when unset.
     */
    public function getS3Credentials(): void
    {
        // Hosted mode: platform-token only for the whole endpoint (even
        // the non-secret bucket/region fields could reveal where customer
        // data lives). Non-hosted: any admin token can see the
        // non-secret fields; the access_key + secret_key only come back
        // when ?include_secrets=1 is set, and only for tokens with the
        // Display Secrets capability.
        $ctx = \BBS\Core\Config::isHosted()
            ? $this->requirePlatformApiToken()
            : $this->requireApiToken();

        $includeSecrets = !empty($_GET['include_secrets']) && $_GET['include_secrets'] !== '0';
        if ($includeSecrets && !$this->tokenCanReadSecrets($ctx)) {
            $this->json(['error' => 'This token is not permitted to read secrets. Create a token with the "Display Secrets" capability and try again.'], 403);
        }

        $publicKeys = [
            's3_endpoint'    => 'endpoint',
            's3_region'      => 'region',
            's3_bucket'      => 'bucket',
            's3_path_prefix' => 'path_prefix',
        ];
        $secretKeys = [
            's3_access_key'  => 'access_key',
            's3_secret_key'  => 'secret_key',
        ];

        $out = [];
        foreach ($publicKeys as $settingKey => $responseKey) {
            $row = $this->db->fetchOne("SELECT `value` FROM settings WHERE `key` = ?", [$settingKey]);
            $out[$responseKey] = $row['value'] ?? null;
        }
        if ($includeSecrets) {
            foreach ($secretKeys as $settingKey => $responseKey) {
                $row = $this->db->fetchOne("SELECT `value` FROM settings WHERE `key` = ?", [$settingKey]);
                $out[$responseKey] = $row['value'] ?? null;
            }
        }

        // 'configured' has to look at the secret-side fields too, but it
        // doesn't reveal their value — just yes/no.
        $accessKeyRow = $this->db->fetchOne("SELECT `value` FROM settings WHERE `key` = 's3_access_key'");
        $out['configured'] = !empty($out['endpoint'])
            && !empty($out['bucket'])
            && !empty($accessKeyRow['value'] ?? '');

        if ($includeSecrets) {
            $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
            $tokenName = $ctx['token_name'] ?? 'unknown';
            $this->db->insert('server_log', [
                'level'   => 'warning',
                'message' => "S3 credentials exported via API (token=\"{$tokenName}\", ip={$ip})",
            ]);
        }

        $this->json($out);
    }

    /**
     * POST /api/v1/s3-credentials
     * Set the global S3 sync credentials. In a normal deployment any admin
     * token works — matches the Settings UI surface where any admin can
     * configure S3. Hosted mode keeps the tighter platform-token gate
     * because the customer admin shouldn't be able to redirect syncs to
     * a non-platform bucket.
     */
    public function setS3Credentials(): void
    {
        if (\BBS\Core\Config::isHosted()) {
            $this->requirePlatformApiToken();
        } else {
            $this->requireApiToken();
        }
        $input = $this->getJsonInput();

        $fields = ['s3_endpoint', 's3_region', 's3_bucket', 's3_access_key', 's3_secret_key', 's3_path_prefix'];
        $map = [
            's3_endpoint'    => $input['endpoint']    ?? null,
            's3_region'      => $input['region']      ?? null,
            's3_bucket'      => $input['bucket']      ?? null,
            's3_access_key'  => $input['access_key']  ?? null,
            's3_secret_key'  => $input['secret_key']  ?? null,
            's3_path_prefix' => $input['path_prefix'] ?? '',
        ];

        foreach (['s3_endpoint', 's3_region', 's3_bucket', 's3_access_key', 's3_secret_key'] as $required) {
            if ($map[$required] === null || $map[$required] === '') {
                $this->json(['error' => "Missing required field: " . str_replace('s3_', '', $required)], 400);
            }
        }

        foreach ($map as $key => $value) {
            $existing = $this->db->fetchOne("SELECT `key` FROM settings WHERE `key` = ?", [$key]);
            if ($existing) {
                $this->db->update('settings', ['value' => (string) $value], '`key` = ?', [$key]);
            } else {
                $this->db->insert('settings', ['key' => $key, 'value' => (string) $value]);
            }
        }

        $this->json(['status' => 'ok', 'fields' => array_keys($map)]);
    }

    /**
     * DELETE /api/v1/s3-credentials
     * Clear the global S3 credentials AND disable per-repo S3 sync on
     * every repository. The platform calls this when a tenant downgrades
     * off the S3 add-on tier — the customer's repos stop syncing and the
     * platform separately deletes the bucket on its side.
     */
    public function clearS3Credentials(): void
    {
        if (\BBS\Core\Config::isHosted()) {
            $this->requirePlatformApiToken();
        } else {
            $this->requireApiToken();
        }

        $keys = ['s3_endpoint', 's3_region', 's3_bucket', 's3_access_key', 's3_secret_key', 's3_path_prefix'];
        foreach ($keys as $key) {
            $this->db->delete('settings', '`key` = ?', [$key]);
        }
        $disabled = $this->db->query(
            "UPDATE repository_s3_configs SET enabled = 0 WHERE enabled = 1"
        );

        $this->json(['status' => 'ok', 'disabled_repositories' => $disabled ?? 0]);
    }

    // ── Platform token rotation ─────────────────────────────────────

    /**
     * POST /api/v1/platform/rotate-token
     * Mint a new platform token, invalidate the old one. Returns the new
     * plaintext token — caller must save it. The old token (the one used
     * to authenticate this request) is removed *after* the new row is
     * inserted, so a partial failure can't leave the install without a
     * platform token.
     */
    public function rotatePlatformToken(): void
    {
        $ctx = $this->requirePlatformApiToken();

        $oldRow = $this->db->fetchOne("SELECT id, user_id, name FROM api_tokens WHERE id = ?", [$ctx['token_id']]);
        if (!$oldRow) {
            $this->json(['error' => 'Current token row not found'], 500);
        }

        $plain = 'bbs_tok_' . bin2hex(random_bytes(24));
        $hash  = hash('sha256', $plain);

        $this->db->insert('api_tokens', [
            'name'       => $oldRow['name'],
            'kind'       => 'platform',
            'token_hash' => $hash,
            'user_id'    => $oldRow['user_id'],
        ]);

        $this->db->delete('api_tokens', 'id = ?', [$oldRow['id']]);

        $this->json(['token' => $plain]);
    }

    // ── Maintenance mode ────────────────────────────────────────────

    /**
     * GET /api/v1/maintenance
     * Read the current maintenance-mode flag.
     */
    public function getMaintenance(): void
    {
        $this->requireApiToken();
        $row = $this->db->fetchOne("SELECT `value` FROM settings WHERE `key` = 'maintenance_mode'");
        $this->json(['enabled' => ($row['value'] ?? '0') === '1']);
    }

    /**
     * POST /api/v1/maintenance
     * Toggle maintenance mode. Body: {"enabled": true|false}.
     * When enabled, the scheduler stops creating new jobs and the queue
     * skips agent dispatch (server-side promotions still run).
     */
    public function setMaintenance(): void
    {
        $this->requireApiToken();
        $input = $this->getJsonInput();

        if (!array_key_exists('enabled', $input)) {
            $this->json(['error' => 'enabled is required (boolean)'], 400);
        }
        $value = ((bool) $input['enabled']) ? '1' : '0';

        $existing = $this->db->fetchOne("SELECT `key` FROM settings WHERE `key` = 'maintenance_mode'");
        if ($existing) {
            $this->db->update('settings', ['value' => $value], '`key` = ?', ['maintenance_mode']);
        } else {
            $this->db->insert('settings', ['key' => 'maintenance_mode', 'value' => $value]);
        }

        $this->json(['enabled' => $value === '1']);
    }

    // ── Per-repo S3 sync toggle ─────────────────────────────────────

    /**
     * PUT /api/v1/repositories/{repoId}/s3-sync
     * Toggle per-repository S3 sync. Body: {"enabled": true|false}.
     * Customer UI on the repo detail page hits the same code path via
     * a session-authed route.
     */
    public function setRepositoryS3Sync(int $repoId): void
    {
        $this->requireApiToken();
        $input = $this->getJsonInput();

        if (!array_key_exists('enabled', $input)) {
            $this->json(['error' => 'enabled is required (boolean)'], 400);
        }
        $enabled = (bool) $input['enabled'];

        $repo = $this->db->fetchOne("SELECT id, name FROM repositories WHERE id = ?", [$repoId]);
        if (!$repo) {
            $this->json(['error' => 'Repository not found'], 404);
        }

        // Toggles every destination this repo replicates to. A destination
        // link must already exist — creating one needs a plugin_config_id,
        // which this endpoint doesn't take (the old insert-without-config
        // branch violated the NOT NULL foreign key anyway).
        $existing = $this->db->fetchOne("SELECT id FROM repository_s3_configs WHERE repository_id = ? LIMIT 1", [$repoId]);
        if (!$existing) {
            $this->json(['error' => 'Repository has no S3 destination configured'], 400);
        }
        $this->db->update('repository_s3_configs', ['enabled' => $enabled ? 1 : 0], 'repository_id = ?', [$repoId]);

        $this->json([
            'id' => $repo['id'],
            'name' => $repo['name'],
            's3_sync_enabled' => $enabled,
        ]);
    }

    /**
     * GET /api/v1/repositories
     * List every repository across every client. Pass ?include_secrets=1
     * to also return the decrypted passphrase per repo — meant for
     * operator escrow ("save my repo passwords somewhere safe in case the
     * BBS server itself burns down"). The flag is opt-in so casual reads
     * don't ever leak secrets, and every secret-bearing call is written
     * to server_log for audit.
     */
    public function listAllRepositories(): void
    {
        $ctx = $this->requireApiToken();
        $includeSecrets = !empty($_GET['include_secrets']) && $_GET['include_secrets'] !== '0';
        if ($includeSecrets && !$this->tokenCanReadSecrets($ctx)) {
            $this->json(['error' => 'This token is not permitted to read secrets. Create a token with the "Display Secrets" capability and try again.'], 403);
        }

        $rows = $this->db->fetchAll(
            "SELECT r.id, r.agent_id, a.name AS agent_name,
                    r.name, r.path, r.encryption, r.storage_type,
                    r.size_bytes, r.archive_count, r.created_at,
                    r.passphrase_encrypted,
                    COALESCE(rsc.enabled, 0) AS s3_sync_enabled,
                    rsc.last_sync_at AS s3_last_sync_at
             FROM repositories r
             LEFT JOIN agents a ON a.id = r.agent_id
             LEFT JOIN (
                 SELECT repository_id, MAX(enabled) AS enabled, MAX(last_sync_at) AS last_sync_at
                 FROM repository_s3_configs GROUP BY repository_id
             ) rsc ON rsc.repository_id = r.id
             ORDER BY a.name, r.name"
        );

        $out = [];
        foreach ($rows as $r) {
            $repo = [
                'id'              => (int) $r['id'],
                'agent_id'        => (int) $r['agent_id'],
                'agent_name'      => $r['agent_name'],
                'name'            => $r['name'],
                'path'            => $r['path'],
                'encryption'      => $r['encryption'],
                'storage_type'    => $r['storage_type'],
                'size_bytes'      => (int) $r['size_bytes'],
                'archive_count'   => (int) $r['archive_count'],
                'created_at'      => $r['created_at'],
                's3_sync_enabled' => (bool) $r['s3_sync_enabled'],
                's3_last_sync_at' => $r['s3_last_sync_at'],
            ];
            if ($includeSecrets) {
                $repo['passphrase'] = null;
                if (!empty($r['passphrase_encrypted'])) {
                    try {
                        $repo['passphrase'] = Encryption::decrypt($r['passphrase_encrypted']);
                    } catch (\Exception $e) {
                        $repo['passphrase'] = null;
                    }
                }
            }
            $out[] = $repo;
        }

        if ($includeSecrets) {
            // Audit trail: secrets-bearing read is sensitive enough to log
            // by token name and source IP so an admin can see who pulled
            // the master passphrase list and when.
            $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
            $tokenName = $ctx['token_name'] ?? 'unknown';
            $this->db->insert('server_log', [
                'level'   => 'warning',
                'message' => "Repository passphrases exported via API (token=\"{$tokenName}\", ip={$ip}, count=" . count($out) . ")",
            ]);
        }

        $this->json([
            'repositories'    => $out,
            'include_secrets' => $includeSecrets,
        ]);
    }

    // ── Users ───────────────────────────────────────────────────────

    /**
     * Shape a users-table row for API responses. Strips password_hash
     * and totp_secret unconditionally — those are never returned.
     */
    private function shapeUser(array $row): array
    {
        return [
            'id'            => (int) $row['id'],
            'username'      => $row['username'],
            'email'         => $row['email'],
            'role'          => $row['role'],
            'all_clients'   => (bool) $row['all_clients'],
            'auth_provider' => $row['auth_provider'] ?? 'local',
            'oidc_status'   => $row['oidc_status'] ?? 'active',
            'totp_enabled'  => (bool) ($row['totp_enabled'] ?? false),
            'timezone'      => $row['timezone'],
            'time_format'   => $row['time_format'],
        ];
    }

    /**
     * GET /api/v1/users
     */
    public function listUsers(): void
    {
        $this->requireApiToken();
        $rows = $this->db->fetchAll("SELECT * FROM users ORDER BY username");
        $out = array_map(fn($r) => $this->shapeUser($r), $rows);
        $this->json(['users' => $out]);
    }

    /**
     * GET /api/v1/users/{id}
     */
    public function getUser(int $id): void
    {
        $this->requireApiToken();
        $row = $this->db->fetchOne("SELECT * FROM users WHERE id = ?", [$id]);
        if (!$row) {
            $this->json(['error' => 'User not found'], 404);
        }
        $this->json($this->shapeUser($row));
    }

    /**
     * POST /api/v1/users
     * Body: {"username": "...", "email": "...", "password": "...", "role": "user|admin"}
     */
    public function createUser(): void
    {
        $this->requireApiToken();
        $input = $this->getJsonInput();

        $username = trim((string) ($input['username'] ?? ''));
        $email    = trim((string) ($input['email'] ?? ''));
        $password = (string) ($input['password'] ?? '');
        $role     = $input['role'] ?? 'user';

        if ($username === '' || $email === '' || $password === '') {
            $this->json(['error' => 'username, email, and password are required'], 400);
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->json(['error' => 'email is not valid'], 400);
        }
        if (strlen($password) < 8) {
            $this->json(['error' => 'password must be at least 8 characters'], 400);
        }
        $role = in_array($role, ['admin', 'user'], true) ? $role : 'user';

        if ($this->db->fetchOne("SELECT id FROM users WHERE username = ? OR email = ?", [$username, $email])) {
            $this->json(['error' => 'A user with that username or email already exists'], 409);
        }

        $newId = $this->db->insert('users', [
            'username'      => $username,
            'email'         => $email,
            'password_hash' => password_hash($password, PASSWORD_BCRYPT),
            'role'          => $role,
        ]);
        $row = $this->db->fetchOne("SELECT * FROM users WHERE id = ?", [$newId]);
        $this->json($this->shapeUser($row), 201);
    }

    /**
     * PUT /api/v1/users/{id}
     * Body: any of email / password / role / all_clients / timezone / time_format.
     * username changes are NOT supported via this endpoint (creates session
     * inconsistency for the in-flight user; do it via the UI if you really
     * need to). Pass reset_totp:true to clear the user's 2FA secret.
     */
    public function updateUser(int $id): void
    {
        $this->requireApiToken();
        $input = $this->getJsonInput();

        $existing = $this->db->fetchOne("SELECT * FROM users WHERE id = ?", [$id]);
        if (!$existing) {
            $this->json(['error' => 'User not found'], 404);
        }

        $updates = [];
        if (isset($input['email'])) {
            $email = trim((string) $input['email']);
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $this->json(['error' => 'email is not valid'], 400);
            }
            $dup = $this->db->fetchOne("SELECT id FROM users WHERE email = ? AND id != ?", [$email, $id]);
            if ($dup) $this->json(['error' => 'That email is already in use'], 409);
            $updates['email'] = $email;
        }
        if (isset($input['password'])) {
            $password = (string) $input['password'];
            if (strlen($password) < 8) {
                $this->json(['error' => 'password must be at least 8 characters'], 400);
            }
            $updates['password_hash'] = password_hash($password, PASSWORD_BCRYPT);
        }
        if (isset($input['role'])) {
            $role = $input['role'];
            if (!in_array($role, ['admin', 'user'], true)) {
                $this->json(['error' => 'role must be admin or user'], 400);
            }
            // Don't allow demoting the last remaining admin.
            if ($existing['role'] === 'admin' && $role !== 'admin') {
                $adminCount = (int) ($this->db->fetchOne("SELECT COUNT(*) AS c FROM users WHERE role = 'admin'")['c'] ?? 0);
                if ($adminCount <= 1) {
                    $this->json(['error' => 'Cannot demote the last admin user'], 409);
                }
            }
            $updates['role'] = $role;
        }
        if (isset($input['all_clients'])) {
            $updates['all_clients'] = $input['all_clients'] ? 1 : 0;
        }
        if (isset($input['timezone'])) {
            $updates['timezone'] = (string) $input['timezone'];
        }
        if (isset($input['time_format'])) {
            $tf = $input['time_format'];
            if (!in_array($tf, ['12h', '24h'], true)) {
                $this->json(['error' => 'time_format must be 12h or 24h'], 400);
            }
            $updates['time_format'] = $tf;
        }
        if (!empty($input['reset_totp'])) {
            $updates['totp_secret'] = null;
            $updates['totp_enabled'] = 0;
            $updates['totp_enabled_at'] = null;
        }

        if (empty($updates)) {
            $this->json(['error' => 'No updatable fields provided'], 400);
        }

        $this->db->update('users', $updates, 'id = ?', [$id]);
        $row = $this->db->fetchOne("SELECT * FROM users WHERE id = ?", [$id]);
        $this->json($this->shapeUser($row));
    }

    /**
     * DELETE /api/v1/users/{id}
     */
    public function deleteUser(int $id): void
    {
        $ctx = $this->requireApiToken();

        $row = $this->db->fetchOne("SELECT * FROM users WHERE id = ?", [$id]);
        if (!$row) {
            $this->json(['error' => 'User not found'], 404);
        }
        if ((int) $ctx['id'] === (int) $id) {
            $this->json(['error' => 'Cannot delete the user the API token belongs to'], 409);
        }
        if ($row['role'] === 'admin') {
            $adminCount = (int) ($this->db->fetchOne("SELECT COUNT(*) AS c FROM users WHERE role = 'admin'")['c'] ?? 0);
            if ($adminCount <= 1) {
                $this->json(['error' => 'Cannot delete the last admin user'], 409);
            }
        }
        $this->db->delete('users', 'id = ?', [$id]);
        $this->json(['status' => 'ok']);
    }

    // ── Server log ──────────────────────────────────────────────────

    /**
     * GET /api/v1/log
     * Filters: ?level=info|warning|error&agent_id=N&since=YYYY-MM-DD%20HH:MM:SS&limit=N&offset=N
     */
    public function listLog(): void
    {
        $ctx = $this->requireApiAuth();

        $level   = $_GET['level'] ?? null;
        $agentId = isset($_GET['agent_id']) ? (int) $_GET['agent_id'] : null;
        $since   = $_GET['since'] ?? null;
        $limit   = isset($_GET['limit']) ? max(1, min(500, (int) $_GET['limit'])) : 100;
        $offset  = isset($_GET['offset']) ? max(0, (int) $_GET['offset']) : 0;

        $where  = [];
        $params = [];
        // Non-admin callers only see log entries for agents they can access
        // (server-wide rows with no agent are admin-only).
        if (($ctx['role'] ?? '') !== 'admin') {
            [$agentWhere, $agentParams] = $this->apiAgentWhereClause($ctx, 'a');
            $where[] = "(a.id IS NOT NULL AND {$agentWhere})";
            $params = array_merge($params, $agentParams);
        }
        if ($level !== null && in_array($level, ['info', 'warning', 'error'], true)) {
            $where[] = "l.level = ?";
            $params[] = $level;
        }
        if ($agentId !== null && $agentId > 0) {
            $where[] = "l.agent_id = ?";
            $params[] = $agentId;
        }
        if ($since !== null && $since !== '') {
            $where[] = "l.created_at >= ?";
            $params[] = $since;
        }
        $whereSql = !empty($where) ? ('WHERE ' . implode(' AND ', $where)) : '';

        $total = (int) ($this->db->fetchOne(
            "SELECT COUNT(*) AS c FROM server_log l LEFT JOIN agents a ON a.id = l.agent_id {$whereSql}",
            $params
        )['c'] ?? 0);

        $rows = $this->db->fetchAll(
            "SELECT l.id, l.agent_id, a.name AS agent_name, l.backup_job_id, l.level, l.message, l.created_at
             FROM server_log l
             LEFT JOIN agents a ON a.id = l.agent_id
             {$whereSql}
             ORDER BY l.created_at DESC, l.id DESC
             LIMIT {$limit} OFFSET {$offset}",
            $params
        );

        $this->json([
            'log'    => $rows,
            'total'  => $total,
            'limit'  => $limit,
            'offset' => $offset,
        ]);
    }

    // ── Schedules (cross-client overview) ───────────────────────────

    /**
     * GET /api/v1/schedules
     * Flat view of every backup plan and its schedule across all clients.
     * Aggregates what /clients/{id}/plans returns per-client into one
     * response so monitoring / oncall tools don't have to fan-out.
     */
    public function listSchedules(): void
    {
        $ctx = $this->requireApiAuth();
        [$agentWhere, $agentParams] = $this->apiAgentWhereClause($ctx, 'a');

        $rows = $this->db->fetchAll(
            "SELECT bp.id AS plan_id, bp.name AS plan_name, bp.enabled AS plan_enabled,
                    bp.agent_id, a.name AS agent_name, a.status AS agent_status,
                    bp.repository_id, r.name AS repository_name,
                    s.frequency, s.times, s.day_of_week, s.day_of_month,
                    s.timezone, s.enabled AS schedule_enabled,
                    s.next_run, s.last_run,
                    (SELECT bj.status FROM backup_jobs bj
                       WHERE bj.backup_plan_id = bp.id
                       ORDER BY bj.id DESC LIMIT 1) AS last_status,
                    (SELECT bj.completed_at FROM backup_jobs bj
                       WHERE bj.backup_plan_id = bp.id AND bj.status = 'completed'
                       ORDER BY bj.id DESC LIMIT 1) AS last_completed_at
             FROM backup_plans bp
             JOIN agents a ON a.id = bp.agent_id
             JOIN repositories r ON r.id = bp.repository_id
             LEFT JOIN schedules s ON s.backup_plan_id = bp.id
             WHERE {$agentWhere}
             ORDER BY a.name, bp.name",
            $agentParams
        );

        foreach ($rows as &$r) {
            $r['plan_id']         = (int) $r['plan_id'];
            $r['agent_id']        = (int) $r['agent_id'];
            $r['repository_id']   = (int) $r['repository_id'];
            $r['plan_enabled']    = (bool) $r['plan_enabled'];
            $r['schedule_enabled']= isset($r['schedule_enabled']) ? (bool) $r['schedule_enabled'] : null;
            $r['day_of_week']     = isset($r['day_of_week']) && $r['day_of_week'] !== null
                                    ? (int) $r['day_of_week'] : null;
        }
        unset($r);

        $this->json(['schedules' => $rows]);
    }

    /**
     * GET /api/v1/schedules/day?date=YYYY-MM-DD&client_id=N
     *
     * A schedules screen: one day of concrete occurrences for the
     * whole server — what already ran and what is still coming — plus the
     * Mon–Sun day-picker counts for the surrounding week.
     *
     * This is ScheduleController::week() cut to a single day and returned as
     * JSON. The caller does no timezone arithmetic: minute_of_day, time_label
     * and now_minute are all pre-resolved into the caller's timezone, because
     * the app's runtime ships a trimmed Intl with no usable TimeZone support.
     */
    public function schedulesDay(): void
    {
        $ctx = $this->requireApiAuth();

        // The caller's display preferences drive every pre-formatted field.
        // $ctx carries no session, so read them off the user row.
        $prefs = $this->db->fetchOne(
            "SELECT timezone, time_format FROM users WHERE id = ?",
            [(int) $ctx['id']]
        ) ?: [];
        $tzName = $prefs['timezone'] ?: 'America/New_York';
        try {
            $viewerTz = new \DateTimeZone($tzName);
        } catch (\Exception $e) {
            $tzName = 'UTC';
            $viewerTz = new \DateTimeZone('UTC');
        }
        $timeFormat = ($prefs['time_format'] ?? '12h') === '24h' ? '24h' : '12h';
        $is24h = $timeFormat === '24h';
        $utc = new \DateTimeZone('UTC');

        $today = (new \DateTime('now', $viewerTz))->format('Y-m-d');
        $date = isset($_GET['date']) ? trim((string) $_GET['date']) : '';
        if ($date === '') {
            $date = $today;
        }
        $parsed = \DateTime::createFromFormat('!Y-m-d', $date, $viewerTz);
        if (!$parsed || $parsed->format('Y-m-d') !== $date) {
            $this->json(['error' => 'date must be a valid YYYY-MM-DD value'], 400);
        }

        $clientId = isset($_GET['client_id']) && $_GET['client_id'] !== ''
            ? (int) $_GET['client_id'] : null;

        // Monday–Sunday week containing $date, in the caller's timezone.
        $weekStart = (clone $parsed)->modify('monday this week');
        $weekDates = [];
        for ($i = 0; $i < 7; $i++) {
            $weekDates[] = (clone $weekStart)->modify("+{$i} days")->format('Y-m-d');
        }

        [$agentWhere, $agentParams] = $this->apiAgentWhereClause($ctx, 'a');

        $schedules = $this->db->fetchAll("
            SELECT s.id, s.backup_plan_id, s.frequency, s.times, s.day_of_week,
                   s.day_of_month, s.timezone,
                   bp.name AS plan_name, bp.repository_id,
                   r.name AS repository_name,
                   a.id AS agent_id, a.name AS agent_name
            FROM schedules s
            JOIN backup_plans bp ON bp.id = s.backup_plan_id
            JOIN agents a ON a.id = bp.agent_id
            LEFT JOIN repositories r ON r.id = bp.repository_id
            WHERE s.enabled = 1 AND bp.enabled = 1 AND {$agentWhere}
            ORDER BY a.name, bp.name
        ", $agentParams);

        // Median duration per plan from the last 10 successful backups, same
        // bounded query the web week view uses.
        $planIds = array_values(array_unique(array_map(
            fn($s) => (int) $s['backup_plan_id'], $schedules
        )));
        $durations = [];
        if (!empty($planIds)) {
            $placeholders = implode(',', array_fill(0, count($planIds), '?'));
            $rows = $this->db->fetchAll("
                SELECT backup_plan_id, duration_seconds
                FROM (
                    SELECT backup_plan_id, duration_seconds,
                           ROW_NUMBER() OVER (
                               PARTITION BY backup_plan_id
                               ORDER BY completed_at DESC
                           ) AS rn
                    FROM backup_jobs
                    WHERE backup_plan_id IN ({$placeholders})
                      AND status = 'completed' AND task_type = 'backup'
                      AND duration_seconds IS NOT NULL AND duration_seconds > 0
                      AND completed_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                ) ranked
                WHERE rn <= 10
            ", $planIds);
            $byPlan = [];
            foreach ($rows as $r) {
                $byPlan[(int) $r['backup_plan_id']][] = (int) $r['duration_seconds'];
            }
            foreach ($byPlan as $pid => $ds) {
                sort($ds);
                $durations[$pid] = $ds[(int) (count($ds) / 2)];
            }
        }

        // Expand every non-interval schedule into concrete occurrences.
        // Occurrences are bucketed by their date in the CALLER's timezone, so
        // a schedule running in another timezone lands on the day the user
        // actually sees it — including when the conversion crosses midnight.
        $intervalFreqs = ['10min', '15min', '30min', 'hourly'];
        $byDate = array_fill_keys($weekDates, []);
        $intervalSchedules = [];
        $clients = [];

        // Walk a day either side of the week so a timezone shift can pull an
        // occurrence into the window from the adjacent day.
        $scanStart = (clone $weekStart)->modify('-1 day');

        foreach ($schedules as $s) {
            $agentId = (int) $s['agent_id'];
            $clients[$agentId] = $s['agent_name'];

            if (in_array($s['frequency'], $intervalFreqs, true)) {
                if ($clientId === null || $agentId === $clientId) {
                    $intervalSchedules[] = [
                        'plan_id' => (int) $s['backup_plan_id'],
                        'plan_name' => $s['plan_name'],
                        'client_id' => $agentId,
                        'client_name' => $s['agent_name'],
                        'frequency' => $s['frequency'],
                    ];
                }
                continue;
            }

            $timeList = array_values(array_filter(array_map('trim', explode(',', $s['times'] ?? ''))));
            if (empty($timeList)) {
                continue;
            }

            try {
                $schedTz = new \DateTimeZone($s['timezone'] ?: 'UTC');
            } catch (\Exception $e) {
                $schedTz = $utc;
            }

            $planId = (int) $s['backup_plan_id'];
            $durSec = $durations[$planId] ?? 15 * 60;

            for ($d = 0; $d < 9; $d++) {
                $day = (clone $scanStart)->modify("+{$d} days");
                // Frequency is evaluated against the schedule's own calendar day.
                $dayInSchedTz = \DateTime::createFromFormat(
                    '!Y-m-d', $day->format('Y-m-d'), $schedTz
                );
                if (!$dayInSchedTz || !$this->scheduleFiresOn($s, $dayInSchedTz)) {
                    continue;
                }

                foreach ($timeList as $t) {
                    $parts = explode(':', $t);
                    $occ = (clone $dayInSchedTz)->setTime((int) ($parts[0] ?? 0), (int) ($parts[1] ?? 0));
                    $local = (clone $occ)->setTimezone($viewerTz);
                    $localDate = $local->format('Y-m-d');
                    if (!isset($byDate[$localDate])) {
                        continue;
                    }

                    $minuteOfDay = ((int) $local->format('G')) * 60 + (int) $local->format('i');
                    $byDate[$localDate][] = [
                        'key' => $planId . '@' . $localDate . 'T' . $local->format('H:i'),
                        'plan_id' => $planId,
                        'plan_name' => $s['plan_name'],
                        'client_id' => $agentId,
                        'client_name' => $s['agent_name'],
                        'repository_name' => $s['repository_name'],
                        'frequency' => $s['frequency'],
                        'minute_of_day' => $minuteOfDay,
                        'time_label' => $is24h ? $local->format('H:i') : $local->format('g:i A'),
                        'scheduled_at' => (clone $occ)->setTimezone($utc)->format('Y-m-d H:i:s'),
                        'estimated_duration_seconds' => (int) $durSec,
                        'duration_estimated' => !isset($durations[$planId]),
                        'state' => 'upcoming',
                        'had_warnings' => 0,
                        'job_id' => null,
                        'started_at' => null,
                        'completed_at' => null,
                        'duration_seconds' => null,
                    ];
                }
            }
        }

        // Day-picker chips count the whole week and are deliberately NOT
        // narrowed by client_id — the chips must not move as the filter does.
        $days = [];
        foreach ($weekDates as $wd) {
            $days[] = [
                'date' => $wd,
                'weekday' => \DateTime::createFromFormat('!Y-m-d', $wd, $viewerTz)->format('D'),
                'count' => count($byDate[$wd]),
                'is_today' => $wd === $today,
            ];
        }

        $occurrences = $byDate[$date] ?? [];
        if ($clientId !== null) {
            $occurrences = array_values(array_filter(
                $occurrences,
                fn($o) => $o['client_id'] === $clientId
            ));
        }
        usort($occurrences, fn($a, $b) => $a['minute_of_day'] <=> $b['minute_of_day']);

        $occurrences = $this->attachJobsToOccurrences($occurrences, $date, $viewerTz);

        $clientList = [];
        foreach ($clients as $id => $name) {
            $clientList[] = ['id' => $id, 'name' => $name];
        }
        usort($clientList, fn($a, $b) => strcasecmp($a['name'], $b['name']));

        $isToday = $date === $today;
        $nowLocal = new \DateTime('now', $viewerTz);

        $this->json([
            'date' => $date,
            'timezone' => $tzName,
            'time_format' => $timeFormat,
            'is_today' => $isToday,
            'now_minute' => $isToday
                ? ((int) $nowLocal->format('G')) * 60 + (int) $nowLocal->format('i')
                : null,
            'days' => $days,
            'clients' => $clientList,
            'occurrences' => $occurrences,
            'interval_schedules' => $intervalSchedules,
        ]);
    }

    /**
     * Does a daily/weekly/monthly schedule fire on this calendar day?
     * $day is midnight of the candidate day in the schedule's own timezone.
     */
    private function scheduleFiresOn(array $schedule, \DateTime $day): bool
    {
        switch ($schedule['frequency']) {
            case 'daily':
                return true;
            case 'weekly':
                // Schema stores day_of_week as 0=Sunday, matching PHP's `w`.
                return (int) ($schedule['day_of_week'] ?? 0) === (int) $day->format('w');
            case 'monthly':
                return (int) ($schedule['day_of_month'] ?? 0) === (int) $day->format('j');
            default:
                return false;
        }
    }

    /**
     * Resolve each occurrence's state by matching it to a real backup job.
     *
     * Same "nearest block" idea the web week view uses: a job belongs to the
     * occurrence of its plan whose scheduled time is closest to when the job
     * was queued or started. Pairs are assigned closest-first so two runs of
     * the same plan on one day can't both claim the same block.
     */
    private function attachJobsToOccurrences(array $occurrences, string $date, \DateTimeZone $viewerTz): array
    {
        if (empty($occurrences)) {
            return $occurrences;
        }

        $planIds = array_values(array_unique(array_map(fn($o) => $o['plan_id'], $occurrences)));
        $placeholders = implode(',', array_fill(0, count($planIds), '?'));

        // The day in the caller's timezone, as the naive UTC bounds the
        // backup_jobs timestamps are stored in.
        $dayStart = \DateTime::createFromFormat('!Y-m-d', $date, $viewerTz);
        $dayEnd = (clone $dayStart)->modify('+1 day');
        $fromUtc = (clone $dayStart)->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $toUtc = (clone $dayEnd)->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');

        $jobs = $this->db->fetchAll(
            "SELECT id, backup_plan_id, status, had_warnings, queued_at, started_at,
                    completed_at, duration_seconds
             FROM backup_jobs
             WHERE backup_plan_id IN ({$placeholders})
               AND task_type = 'backup'
               AND COALESCE(started_at, queued_at) >= ?
               AND COALESCE(started_at, queued_at) < ?
             ORDER BY id ASC",
            array_merge($planIds, [$fromUtc, $toUtc])
        );

        if (empty($jobs)) {
            return $this->markUnmatchedStates($occurrences);
        }

        // Score every plausible (occurrence, job) pair, then assign the
        // closest pairs first so each side is used at most once.
        $pairs = [];
        foreach ($jobs as $ji => $job) {
            $jobTs = strtotime($job['started_at'] ?: $job['queued_at'] ?: '') ?: 0;
            foreach ($occurrences as $oi => $occ) {
                if ($occ['plan_id'] !== (int) $job['backup_plan_id']) {
                    continue;
                }
                $pairs[] = [
                    'distance' => abs($jobTs - (strtotime($occ['scheduled_at'] . ' UTC') ?: 0)),
                    'job' => $ji,
                    'occ' => $oi,
                ];
            }
        }
        usort($pairs, fn($a, $b) => $a['distance'] <=> $b['distance']);

        $usedJobs = [];
        $usedOccs = [];
        foreach ($pairs as $pair) {
            if (isset($usedJobs[$pair['job']]) || isset($usedOccs[$pair['occ']])) {
                continue;
            }
            $usedJobs[$pair['job']] = true;
            $usedOccs[$pair['occ']] = true;

            $job = $jobs[$pair['job']];
            $status = $job['status'];
            $occurrences[$pair['occ']] = array_merge($occurrences[$pair['occ']], [
                // 'sent' is an in-flight queue state the app doesn't model.
                'state' => $status === 'sent' ? 'queued' : $status,
                'job_id' => (int) $job['id'],
                'had_warnings' => (int) $job['had_warnings'],
                'started_at' => $job['started_at'],
                'completed_at' => $job['completed_at'],
                'duration_seconds' => $job['duration_seconds'] !== null
                    ? (int) $job['duration_seconds'] : null,
            ]);
        }

        return $this->markUnmatchedStates($occurrences);
    }

    /**
     * Occurrences with no job are either still to come or were never run.
     */
    private function markUnmatchedStates(array $occurrences): array
    {
        $now = time();
        foreach ($occurrences as &$occ) {
            if ($occ['job_id'] !== null) {
                continue;
            }
            $scheduledTs = strtotime($occ['scheduled_at'] . ' UTC') ?: 0;
            $occ['state'] = $scheduledTs > $now ? 'upcoming' : 'missed';
        }
        unset($occ);

        return $occurrences;
    }

    // ── Notifications ─────────────────────────────────────

    /**
     * GET /api/v1/notifications?limit=N&offset=N
     * Same visibility rules as the web bell: global notifications, the
     * caller's own, and agent-scoped ones for agents they can access.
     */
    public function listNotifications(): void
    {
        $ctx = $this->requireApiAuth();
        $limit = isset($_GET['limit']) ? max(1, min(200, (int) $_GET['limit'])) : 50;
        $offset = isset($_GET['offset']) ? max(0, (int) $_GET['offset']) : 0;

        $service = new \BBS\Services\NotificationService();
        $rows = $service->getAll($limit, $offset, (int) $ctx['id']);
        foreach ($rows as &$n) {
            $n['id'] = (int) $n['id'];
            $n['agent_id'] = $n['agent_id'] !== null ? (int) $n['agent_id'] : null;
            $n['occurrence_count'] = (int) $n['occurrence_count'];
            $n['read'] = $n['read_at'] !== null;
            $n['resolved'] = $n['resolved_at'] !== null;
        }
        unset($n);

        $this->json([
            'notifications' => $rows,
            'unread' => $service->unreadCount((int) $ctx['id']),
            'limit' => $limit,
            'offset' => $offset,
        ]);
    }

    /** POST /api/v1/notifications/{id}/read */
    public function markNotificationRead(int $id): void
    {
        $ctx = $this->requireApiAuth();
        $service = new \BBS\Services\NotificationService();
        $service->markRead($id, (int) $ctx['id']);
        $this->json(['status' => 'ok', 'unread' => $service->unreadCount((int) $ctx['id'])]);
    }

    /** POST /api/v1/notifications/read-all */
    public function markAllNotificationsRead(): void
    {
        $ctx = $this->requireApiAuth();
        $service = new \BBS\Services\NotificationService();
        $service->markAllRead((int) $ctx['id']);
        $this->json(['status' => 'ok', 'unread' => 0]);
    }

    // ── Jobs by id / queue actions ────────────────────────

    /**
     * GET /api/v1/jobs/{id} — job detail + live progress without needing
     * the client id first (the queue list links straight here).
     *
     * The job row stays FLAT at the top level (the shape shipped in
     * v2.73.0 — nesting it would be a breaking change); the detail extras
     * ride as sibling keys: logs, queue {active,max,position},
     * current_file, prune_stats. Mirrors QueueController::detailJson().
     */
    public function getJobById(int $jobId): void
    {
        $ctx = $this->requireApiAuth();

        $job = $this->db->fetchOne("
            SELECT bj.*, a.name AS client_name, a.id AS client_id,
                   a.status AS client_status, a.last_heartbeat AS client_last_heartbeat,
                   bp.name AS plan_name, r.name AS repository_name
            FROM backup_jobs bj
            JOIN agents a ON a.id = bj.agent_id
            LEFT JOIN backup_plans bp ON bp.id = bj.backup_plan_id
            LEFT JOIN repositories r ON r.id = bj.repository_id
            WHERE bj.id = ?
        ", [$jobId]);

        if (!$job || !$this->apiCanAccessAgent($ctx, (int) $job['agent_id'])) {
            $this->json(['error' => 'Job not found'], 404);
        }

        // Activity log for this job
        $logs = $this->db->fetchAll("
            SELECT id, level, message, created_at FROM server_log
            WHERE backup_job_id = ?
            ORDER BY created_at ASC, id ASC
        ", [$jobId]);
        foreach ($logs as &$l) {
            $l['id'] = (int) $l['id'];
        }
        unset($l);

        // Queue slots are a server-wide resource, so active/max are global;
        // position is this job's place in the queued line.
        $activeCount = $this->db->count('backup_jobs', "status IN ('sent', 'running')");
        $maxQueue = (int) ($this->db->fetchOne("SELECT `value` FROM settings WHERE `key` = 'max_queue'")['value'] ?? 4);
        $queuePosition = null;
        if ($job['status'] === 'queued') {
            $pos = $this->db->fetchOne(
                "SELECT COUNT(*) as cnt FROM backup_jobs WHERE status = 'queued' AND queued_at <= ?",
                [$job['queued_at']]
            );
            $queuePosition = (int) $pos['cnt'];
        }

        // Current file for a running backup — tail of the agent's catalog log
        $currentFile = null;
        if ($job['status'] === 'running' && $job['task_type'] === 'backup') {
            $jobAgent = $this->db->fetchOne("SELECT ssh_home_dir FROM agents WHERE id = ?", [$job['agent_id']]);
            if ($jobAgent && !empty($jobAgent['ssh_home_dir'])) {
                $catalogPath = $jobAgent['ssh_home_dir'] . '/.catalog-logs/catalog-' . $jobId . '.jsonl';
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

        $pruneStats = null;
        if ($job['task_type'] === 'prune') {
            $pruneStats = \BBS\Controllers\QueueController::parsePruneStats($logs);
        }

        $this->json($job + [
            'logs' => $logs,
            'queue' => ['active' => $activeCount, 'max' => $maxQueue, 'position' => $queuePosition],
            'current_file' => $currentFile,
            'prune_stats' => $pruneStats,
        ]);
    }

    /**
     * POST /api/v1/queue/{id}/cancel — mirrors the web queue's cancel,
     * including the automatic break-lock cleanup job.
     */
    public function cancelQueueJob(int $id): void
    {
        $ctx = $this->requireApiAuth();

        $job = $this->db->fetchOne("SELECT * FROM backup_jobs WHERE id = ?", [$id]);
        if (!$job || !$this->apiCanAccessAgent($ctx, (int) $job['agent_id'])) {
            $this->json(['error' => 'Job not found'], 404);
        }
        if (!in_array($job['status'], ['queued', 'sent', 'running'])) {
            $this->json(['error' => 'Job cannot be cancelled (status: ' . $job['status'] . ')'], 409);
        }
        if (!$this->apiHasPermission($ctx, \BBS\Services\PermissionService::TRIGGER_BACKUP, (int) $job['agent_id'])) {
            $this->json(['error' => 'You do not have permission to cancel jobs on this client'], 403);
        }

        $this->db->update('backup_jobs', [
            'status' => 'cancelled',
            'error_log' => 'Cancelled by user',
            'completed_at' => date('Y-m-d H:i:s'),
        ], 'id = ?', [$id]);

        $this->db->insert('server_log', [
            'agent_id' => $job['agent_id'],
            'backup_job_id' => $id,
            'level' => 'warning',
            'message' => "Job #{$id} cancelled by user (API)",
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

        $this->json(['status' => 'ok', 'message' => "Job #{$id} cancelled"]);
    }

    /**
     * POST /api/v1/queue/{id}/retry — requeue a failed job.
     */
    public function retryQueueJob(int $id): void
    {
        $ctx = $this->requireApiAuth();

        $job = $this->db->fetchOne("SELECT * FROM backup_jobs WHERE id = ?", [$id]);
        if (!$job || !$this->apiCanAccessAgent($ctx, (int) $job['agent_id'])) {
            $this->json(['error' => 'Job not found'], 404);
        }
        if ($job['status'] !== 'failed') {
            $this->json(['error' => 'Only failed jobs can be retried'], 409);
        }
        if (!$this->apiHasPermission($ctx, \BBS\Services\PermissionService::TRIGGER_BACKUP, (int) $job['agent_id'])) {
            $this->json(['error' => 'You do not have permission to retry jobs on this client'], 403);
        }

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
            'message' => "Job #{$newJobId} queued (retry of #{$id}, API)",
        ]);

        $this->json(['status' => 'ok', 'job_id' => (int) $newJobId, 'message' => "Job #{$id} retried as #{$newJobId}"]);
    }

    // ── Catalog browse + restore ──────────────────────────

    /**
     * GET /api/v1/clients/{id}/repositories/{repoId}/archives/{archiveId}/files
     * Paginated catalog browse. Query: path (default /), limit (default 200,
     * max 1000), cursor (opaque, from the previous page). Directories are
     * returned on the first page only; files paginate via keyset cursor —
     * archives hold millions of rows, so no OFFSET scans.
     */
    public function listArchiveFiles(int $id, int $repoId, int $archiveId): void
    {
        $ctx = $this->requireApiAuth();
        if (!$this->apiCanAccessAgent($ctx, $id)) {
            $this->json(['error' => 'Client not found'], 404);
        }

        $archive = $this->db->fetchOne("
            SELECT ar.id FROM archives ar
            JOIN repositories r ON r.id = ar.repository_id
            WHERE ar.id = ? AND ar.repository_id = ? AND r.agent_id = ?
        ", [$archiveId, $repoId, $id]);
        if (!$archive) {
            $this->json(['error' => 'Archive not found'], 404);
        }

        $ch = \BBS\Core\ClickHouse::getInstance();
        if (!$ch->isAvailable()) {
            // Reported as state rather than an error: "unavailable" and "this
            // archive has nothing in it" must not look the same to a caller.
            $this->json([
                'path' => (string) ($_GET['path'] ?? ''),
                'dirs' => [], 'files' => [], 'next_cursor' => null,
                'catalog' => ['status' => 'unavailable', 'synced_at' => null],
            ]);
        }

        $prefix = $_GET['path'] ?? '/';
        if ($prefix !== '/' && !str_ends_with($prefix, '/')) {
            $prefix .= '/';
        }
        $parentDir = $prefix === '/' ? '/' : rtrim($prefix, '/');
        $limit = isset($_GET['limit']) ? max(1, min(1000, (int) $_GET['limit'])) : 200;
        $cursor = $_GET['cursor'] ?? '';
        $afterName = '';
        if ($cursor !== '') {
            $decoded = base64_decode($cursor, true);
            if ($decoded === false) {
                $this->json(['error' => 'Invalid cursor'], 400);
            }
            $afterName = $decoded;
        }

        // Directories only on the first page — they're bounded per level.
        $directories = [];
        if ($afterName === '') {
            $dirs = $ch->fetchAll("
                SELECT name, dir_path, file_count, total_size
                FROM catalog_dirs
                WHERE agent_id = ? AND archive_id = ? AND parent_dir = ?
                ORDER BY name
            ", [$id, $archiveId, $parentDir]);
            foreach ($dirs as $d) {
                $directories[] = [
                    'name' => $d['name'],
                    'path' => $d['dir_path'] . '/',
                    'file_count' => (int) $d['file_count'],
                    'total_size' => (int) $d['total_size'],
                ];
            }
        }

        // Keyset pagination on file_name (part of the table's ORDER BY key).
        // Fetch limit+1 to know whether another page exists.
        $fetch = $limit + 1;
        $files = $ch->fetchAll("
            SELECT path AS file_path, file_name, file_size, status,
                   toString(mtime) AS mtime
            FROM file_catalog
            WHERE agent_id = ? AND archive_id = ? AND parent_dir = ? AND status != 'D'
              AND file_name > ?
            ORDER BY file_name
            LIMIT {$fetch}
        ", [$id, $archiveId, $parentDir, $afterName]);

        $nextCursor = null;
        if (count($files) > $limit) {
            $files = array_slice($files, 0, $limit);
            $nextCursor = base64_encode(end($files)['file_name']);
        }
        foreach ($files as &$f) {
            $f['file_size'] = (int) $f['file_size'];
        }
        unset($f);

        $this->json([
            'path' => $prefix,
            'dirs' => $directories,
            'files' => $files,
            'next_cursor' => $nextCursor,
            // An archive with no catalog rows has either not been catalogued
            // yet or genuinely holds nothing; a caller showing an empty list
            // for the first case reads as "this backup is empty", which is
            // alarming and wrong.
            'catalog' => $this->catalogState($id, $archiveId),
        ]);
    }

    /**
     * Whether this archive's file catalog is ready, still building, or absent.
     */
    private function catalogState(int $agentId, int $archiveId): array
    {
        try {
            $ch = \BBS\Core\ClickHouse::getInstance();
            $row = $ch->fetchOne(
                "SELECT count() AS c FROM file_catalog
                 WHERE agent_id = {$agentId} AND archive_id = {$archiveId}"
            );
            if ((int) ($row['c'] ?? 0) > 0) {
                return ['status' => 'ready', 'synced_at' => null];
            }
        } catch (\Exception $e) {
            return ['status' => 'unavailable', 'synced_at' => null];
        }

        // Nothing catalogued. A queued or running catalog job means it is on
        // its way; otherwise it has simply never been built.
        $pending = $this->db->fetchOne(
            "SELECT id FROM backup_jobs
             WHERE agent_id = ? AND task_type IN ('catalog_sync', 'catalog_rebuild', 'catalog_rebuild_full')
               AND status IN ('queued', 'sent', 'running')
             ORDER BY id DESC LIMIT 1",
            [$agentId]
        );

        return [
            'status' => 'pending',
            'synced_at' => null,
            'job_id' => $pending ? (int) $pending['id'] : null,
        ];
    }

    /**
     * POST /api/v1/clients/{id}/restore
     * Body: {"archive_id": N, "paths": ["/etc", ...], "destination": "/tmp/x"}.
     * Empty paths = full archive. Mirrors the web restore flow including the
     * up-front files_total resolution for progress bars.
     */
    public function restoreFiles(int $id): void
    {
        $ctx = $this->requireApiAuth();
        if (!$this->apiCanAccessAgent($ctx, $id)) {
            $this->json(['error' => 'Client not found'], 404);
        }
        if (!$this->apiHasPermission($ctx, \BBS\Services\PermissionService::RESTORE, $id)) {
            $this->json(['error' => 'You do not have permission to restore on this client'], 403);
        }

        $input = $this->getJsonInput();
        $archiveId = (int) ($input['archive_id'] ?? 0);
        $selectedFiles = is_array($input['paths'] ?? null) ? $input['paths'] : [];
        $destination = trim($input['destination'] ?? '');

        if (!$archiveId) {
            $this->json(['error' => 'archive_id is required'], 400);
        }

        $archive = $this->db->fetchOne("
            SELECT ar.*, r.path AS repo_path
            FROM archives ar
            JOIN repositories r ON r.id = ar.repository_id
            WHERE ar.id = ? AND r.agent_id = ?
        ", [$archiveId, $id]);
        if (!$archive) {
            $this->json(['error' => 'Archive not found'], 404);
        }

        // Resolve expected file count for the queue's progress bar (same
        // logic as the web restore — see ClientController::restoreSubmit).
        $filesTotal = null;
        if (empty($selectedFiles)) {
            $filesTotal = (int) ($archive['file_count'] ?? 0) ?: null;
        } else {
            try {
                $ch = \BBS\Core\ClickHouse::getInstance();
                if ($ch->isAvailable()) {
                    $paths = array_values(array_filter(array_map(
                        fn($p) => '/' . trim((string) $p, '/'),
                        $selectedFiles
                    ), fn($p) => $p !== '/'));
                    $roots = array_filter($paths, function ($p) use ($paths) {
                        foreach ($paths as $other) {
                            if ($other !== $p && str_starts_with($p, $other . '/')) return false;
                        }
                        return true;
                    });

                    $conds = [];
                    $params = [$id, $archiveId];
                    foreach ($roots as $p) {
                        $conds[] = '(path = ? OR startsWith(path, ?))';
                        $params[] = $p;
                        $params[] = $p . '/';
                    }
                    if ($conds) {
                        $row = $ch->fetchOne(
                            "SELECT count() AS cnt FROM file_catalog
                             WHERE agent_id = ? AND archive_id = ? AND (" . implode(' OR ', $conds) . ")",
                            $params
                        );
                        $filesTotal = (int) ($row['cnt'] ?? 0) ?: null;
                    }
                }
            } catch (\Exception $e) {
                // Catalog unavailable — byte-based progress still applies
            }
        }

        $jobId = $this->db->insert('backup_jobs', [
            'agent_id' => $id,
            'backup_plan_id' => null,
            'repository_id' => $archive['repository_id'],
            'task_type' => 'restore',
            'status' => 'queued',
            'queued_at' => date('Y-m-d H:i:s'),
            'restore_archive_id' => $archiveId,
            'restore_paths' => json_encode($selectedFiles),
            'restore_destination' => $destination ?: null,
            'files_total' => $filesTotal,
        ]);

        $this->db->insert('server_log', [
            'agent_id' => $id,
            'backup_job_id' => $jobId,
            'level' => 'info',
            'message' => empty($selectedFiles)
                ? "Restore queued via API: full archive {$archive['archive_name']}"
                : "Restore queued via API: " . count($selectedFiles) . " paths from archive {$archive['archive_name']}",
        ]);

        $this->json(['status' => 'ok', 'job_id' => (int) $jobId]);
    }

    // ── Push registration ─────────────────────────────────

    /**
     * POST /api/v1/push/register — {device_id, apns_token, platform}.
     * Upserts per (user, device); re-registration refreshes the token.
     */
    /**
     * GET /health — unauthenticated liveness.
     *
     * For uptime monitors and container health checks: no credentials, no
     * detail, and cheap enough to poll often. Anything that would tell an
     * anonymous caller about the state of this install lives on the
     * authenticated endpoint below.
     *
     * 200 when serving, 503 when not.
     */
    public function healthLive(): void
    {
        $result = (new \BBS\Services\HealthService())->liveness();
        $this->json($result, $result['status'] === 'ok' ? 200 : 503);
    }

    /**
     * GET /api/v1/health — the full picture, for a monitoring system.
     *
     * 200 for ok and warning, 503 for critical, so a check that only reads the
     * status code still alerts on the things that stop backups happening.
     */
    public function health(): void
    {
        $this->requireApiAuth();
        $result = (new \BBS\Services\HealthService())->check();
        $this->json($result, $result['status'] === \BBS\Services\HealthService::CRITICAL ? 503 : 200);
    }

    // ── Restore: search, download, database restore ─────────────────

    /**
     * POST /api/v1/clients/{id}/download
     * Body: {"archive_id": N, "paths": ["/etc/nginx", ...]}
     *
     * Streams the selection back as a .tar.gz, the same way the web page
     * does — same extraction, same staging, same disk pre-flight, because it
     * is the same service underneath.
     *
     * Errors come back as JSON, but only before streaming starts. Once the
     * body is flowing the status is already sent, so a mid-transfer failure
     * can only truncate the download; the file itself is verified by the
     * extraction before a byte is written.
     */
    public function downloadArchive(int $id): void
    {
        $ctx = $this->requireApiAuth();
        if (!$this->apiCanAccessAgent($ctx, $id)) {
            $this->json(['error' => 'Client not found'], 404);
        }
        if (!$this->apiHasPermission($ctx, \BBS\Services\PermissionService::RESTORE, $id)) {
            $this->json(['error' => 'You do not have permission to restore on this client'], 403);
        }

        $input = $this->getJsonInput();
        $archiveId = (int) ($input['archive_id'] ?? ($_GET['archive_id'] ?? 0));
        $paths = is_array($input['paths'] ?? null) ? $input['paths'] : [];
        if (!$archiveId) {
            $this->json(['error' => 'archive_id is required'], 400);
        }

        $agent = $this->db->fetchOne("SELECT * FROM agents WHERE id = ?", [$id]);
        $archive = (new \BBS\Controllers\ClientController())->loadArchiveForDownload($archiveId, $id);
        if (!$agent || !$archive) {
            $this->json(['error' => 'Archive not found'], 404);
        }

        try {
            (new \BBS\Services\ArchiveDownloadService())->stream($agent, $archive, $paths);
        } catch (\BBS\Services\InsufficientSpaceException $e) {
            // Reported with the figures so a caller can say how much is needed
            // rather than only that it failed.
            $this->json([
                'error' => $e->getMessage(),
                'reason' => 'insufficient_space',
                'needed_bytes' => $e->neededBytes,
                'free_bytes' => $e->freeBytes,
            ], 507);
        } catch (\Exception $e) {
            $this->json(['error' => 'Download failed: ' . $e->getMessage()], 500);
        }
    }

    /**
     * GET /api/v1/clients/{id}/catalog/search?q=&archive_id=&limit=
     *
     * Search is how anyone finds a file on a small screen — nobody taps
     * through fourteen levels of /var/www/vhosts. Reads the pre-built
     * ClickHouse catalog, so it is a lookup rather than a walk of the archive.
     *
     * `archive_id` optional: omitted searches every restore point, which
     * answers "which backup still has this file?".
     */
    public function catalogSearch(int $id): void
    {
        $ctx = $this->requireApiAuth();
        if (!$this->apiCanAccessAgent($ctx, $id)) {
            $this->json(['error' => 'Client not found'], 404);
        }

        $q = trim((string) ($_GET['q'] ?? ''));
        if ($q === '') {
            $this->json(['error' => 'q is required'], 400);
        }
        $limit = max(1, min(200, (int) ($_GET['limit'] ?? 100)));
        $archiveId = isset($_GET['archive_id']) ? (int) $_GET['archive_id'] : 0;

        $ch = \BBS\Core\ClickHouse::getInstance();
        if (!$ch->isAvailable()) {
            $this->json(['results' => [], 'truncated' => false,
                         'catalog' => ['status' => 'unavailable']]);
        }

        if ($archiveId > 0) {
            $owns = $this->db->fetchOne(
                "SELECT ar.id FROM archives ar JOIN repositories r ON r.id = ar.repository_id
                 WHERE ar.id = ? AND r.agent_id = ?",
                [$archiveId, $id]
            );
            if (!$owns) {
                $this->json(['error' => 'Archive not found'], 404);
            }
        }

        // Fetch one more than asked so "truncated" is accurate rather than a
        // guess from a full page.
        $fetch = $limit + 1;
        $like = '%' . str_replace(['\\', "'", '%', '_'], ['\\\\', "\\'", '\\%', '\\_'], $q) . '%';
        $scope = $archiveId > 0 ? " AND archive_id = {$archiveId}" : '';

        $rows = $ch->fetchAll(
            "SELECT path, file_name, file_size, archive_id, toString(mtime) AS mtime
             FROM file_catalog
             WHERE agent_id = {$id} AND status != 'D'{$scope}
               AND (file_name LIKE ? OR path LIKE ?)
             ORDER BY path
             LIMIT {$fetch}",
            [$like, $like]
        );

        $truncated = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);

        // Name the restore point each hit came from — with no archive filter,
        // "which backup has it" is the whole question.
        $archiveNames = [];
        $ids = array_values(array_unique(array_map(fn($r) => (int) $r['archive_id'], $rows)));
        if (!empty($ids)) {
            $ph = implode(',', array_fill(0, count($ids), '?'));
            foreach ($this->db->fetchAll(
                "SELECT id, archive_name FROM archives WHERE id IN ({$ph})", $ids
            ) as $a) {
                $archiveNames[(int) $a['id']] = $a['archive_name'];
            }
        }

        $results = array_map(fn($r) => [
            'file_path' => $r['path'],
            'file_name' => $r['file_name'],
            'file_size' => (int) $r['file_size'],
            'mtime' => $r['mtime'],
            'archive_id' => (int) $r['archive_id'],
            'archive_name' => $archiveNames[(int) $r['archive_id']] ?? null,
        ], $rows);

        $this->json(['results' => $results, 'truncated' => $truncated,
                     'catalog' => ['status' => 'ready']]);
    }

    /**
     * GET /api/v1/clients/{id}/repositories/{repoId}/archives/{archiveId}/databases
     *
     * What a restore point actually contains. Without this a caller can pick
     * an archive but has nothing to offer for "which databases", which is the
     * step between choosing a restore point and starting the restore.
     *
     * Groups mirror the stored shape: several database configurations —
     * including two of the same engine — restore independently.
     */
    public function listArchiveDatabases(int $id, int $repoId, int $archiveId): void
    {
        $ctx = $this->requireApiAuth();
        if (!$this->apiCanAccessAgent($ctx, $id)) {
            $this->json(['error' => 'Client not found'], 404);
        }

        $archive = $this->db->fetchOne(
            "SELECT ar.id, ar.archive_name, ar.databases_backed_up, ar.created_at
             FROM archives ar JOIN repositories r ON r.id = ar.repository_id
             WHERE ar.id = ? AND ar.repository_id = ? AND r.agent_id = ?",
            [$archiveId, $repoId, $id]
        );
        if (!$archive) {
            $this->json(['error' => 'Archive not found'], 404);
        }

        $groups = (new \BBS\Services\ArchiveDatabaseService())
            ->groups($id, $archiveId, $archive['databases_backed_up']);

        // Which connector each group came from, so a caller can pre-select the
        // right one rather than making someone match it up by name.
        $out = [];
        $all = [];
        foreach ($groups as $g) {
            $databases = array_values($g['databases'] ?? []);
            foreach ($databases as $d) {
                $all[$d] = true;
            }
            $out[] = [
                'connector_id' => $g['config_id'] !== null ? (int) $g['config_id'] : null,
                'connector_name' => $g['config_name'] ?? null,
                'engine' => $g['engine'] ?? null,
                'databases' => $databases,
                'per_database' => (bool) ($g['per_database'] ?? true),
                'compress' => (bool) ($g['compress'] ?? true),
                // Naive UTC, like every other datetime here.
                'dumped_at' => (object) array_map(fn($m) => $m ?: null, $g['mtimes'] ?? []),
            ];
        }

        $this->json([
            'archive_id' => (int) $archive['id'],
            'archive_name' => $archive['archive_name'],
            'backed_up_at' => $archive['created_at'],
            'has_databases' => !empty($all),
            'databases' => array_keys($all),
            'groups' => $out,
        ]);
    }

    /**
     * GET /api/v1/clients/{id}/db-connectors
     *
     * The database plugin configurations a restore can target. Credentials
     * are configuration secrets, so only what is needed to choose one is
     * returned — never the password.
     */
    public function listDbConnectors(int $id): void
    {
        $ctx = $this->requireApiAuth();
        if (!$this->apiCanAccessAgent($ctx, $id)) {
            $this->json(['error' => 'Client not found'], 404);
        }

        $rows = $this->db->fetchAll(
            "SELECT pc.id, pc.name, pc.config, p.slug
             FROM plugin_configs pc JOIN plugins p ON p.id = pc.plugin_id
             WHERE pc.agent_id = ? AND p.slug IN ('mysql_dump', 'pg_dump', 'mongo_dump')
             ORDER BY p.slug, pc.name",
            [$id]
        );

        $typeBySlug = ['mysql_dump' => 'mysql', 'pg_dump' => 'postgres', 'mongo_dump' => 'mongo'];
        $connectors = [];
        foreach ($rows as $r) {
            $cfg = json_decode($r['config'] ?? '{}', true) ?: [];
            $connectors[] = [
                'id' => (int) $r['id'],
                'name' => $r['name'],
                'type' => $typeBySlug[$r['slug']] ?? $r['slug'],
                'host' => $cfg['host'] ?? null,
                'port' => isset($cfg['port']) ? (int) $cfg['port'] : null,
                'database' => $cfg['database'] ?? null,
            ];
        }

        $this->json(['connectors' => $connectors]);
    }

    /**
     * POST /api/v1/clients/{id}/restore-db
     * Body: {connector_id, archive_id, databases: [{name, mode, target_name?}]}
     *
     * One endpoint rather than three: the connector's type decides which task
     * the agent runs, so a caller isn't switching on a string just to pick a
     * URL.
     */
    public function restoreDatabase(int $id): void
    {
        $ctx = $this->requireApiAuth();
        if (!$this->apiCanAccessAgent($ctx, $id)) {
            $this->json(['error' => 'Client not found'], 404);
        }
        if (!$this->apiHasPermission($ctx, \BBS\Services\PermissionService::RESTORE, $id)) {
            $this->json(['error' => 'You do not have permission to restore on this client'], 403);
        }

        $input = $this->getJsonInput();
        $connectorId = (int) ($input['connector_id'] ?? 0);
        $archiveId = (int) ($input['archive_id'] ?? 0);
        $databases = is_array($input['databases'] ?? null) ? $input['databases'] : [];

        if (!$connectorId || !$archiveId || empty($databases)) {
            $this->json(['error' => 'connector_id, archive_id and databases are required'], 400);
        }

        $connector = $this->db->fetchOne(
            "SELECT pc.id, pc.name, p.slug FROM plugin_configs pc
             JOIN plugins p ON p.id = pc.plugin_id
             WHERE pc.id = ? AND pc.agent_id = ?
               AND p.slug IN ('mysql_dump', 'pg_dump', 'mongo_dump')",
            [$connectorId, $id]
        );
        if (!$connector) {
            $this->json(['error' => 'Database connector not found for this client'], 404);
        }

        $archive = $this->db->fetchOne(
            "SELECT ar.id, ar.archive_name, ar.repository_id, ar.databases_backed_up
             FROM archives ar JOIN repositories r ON r.id = ar.repository_id
             WHERE ar.id = ? AND r.agent_id = ?",
            [$archiveId, $id]
        );
        if (!$archive) {
            $this->json(['error' => 'Archive not found'], 404);
        }
        if (empty($archive['databases_backed_up'])) {
            $this->json(['error' => 'That restore point contains no database dumps'], 409);
        }

        // Same shape the web builds, including the rename target sanitising.
        $restoreDatabases = [];
        foreach ($databases as $entry) {
            $name = is_array($entry) ? ($entry['name'] ?? '') : (string) $entry;
            $mode = is_array($entry) ? ($entry['mode'] ?? 'replace') : 'replace';
            if ($name === '' || !in_array($mode, ['replace', 'rename'], true)) {
                continue;
            }
            $item = ['database' => $name, 'mode' => $mode];
            if ($mode === 'rename' && !empty($entry['target_name'])) {
                $item['target_name'] = preg_replace('/[^a-zA-Z0-9_]/', '', $entry['target_name']);
            }
            $restoreDatabases[] = $item;
        }
        if (empty($restoreDatabases)) {
            $this->json(['error' => 'No valid databases selected'], 422);
        }

        $taskType = match ($connector['slug']) {
            'mysql_dump' => 'restore_mysql',
            'pg_dump' => 'restore_pg',
            'mongo_dump' => 'restore_mongo',
        };

        $jobId = $this->db->insert('backup_jobs', [
            'agent_id' => $id,
            'repository_id' => $archive['repository_id'],
            'task_type' => $taskType,
            'status' => 'queued',
            'restore_archive_id' => $archiveId,
            'restore_databases' => json_encode($restoreDatabases),
            'plugin_config_id' => $connectorId,
        ]);

        $names = implode(', ', array_column($restoreDatabases, 'database'));
        $this->db->insert('server_log', [
            'agent_id' => $id,
            'backup_job_id' => $jobId,
            'level' => 'info',
            'message' => "Database restore queued: {$names} from archive {$archive['archive_name']}",
        ]);

        $this->json(['job_id' => (int) $jobId, 'task_type' => $taskType, 'status' => 'queued'], 202);
    }

    // ── Client detail: install, plugin config writes, repo check, stats ──

    /**
     * GET /api/v1/clients/{id}/install — the agent install command.
     *
     * This is the one endpoint that returns an agent's api_key to a mobile
     * token. It is an install-time enrolment credential, already shown in the
     * web UI to any admin who opens the client page, and withholding it here
     * would make the install screen useless without protecting anything the
     * web does not already expose.
     *
     * That is a deliberate exception, kept narrow: admin-only, only when asked
     * for explicitly, and nowhere else. getClient() still strips api_key for
     * mobile tokens, and no list endpoint returns it.
     */
    public function clientInstall(int $id): void
    {
        $this->requireApiAdmin();

        $agent = $this->db->fetchOne("SELECT * FROM agents WHERE id = ?", [$id]);
        if (!$agent) {
            $this->json(['error' => 'Client not found'], 404);
        }

        $apiKey = $this->resolveAgentApiKey($agent);
        $appUrl = rtrim(\BBS\Core\Config::get('APP_URL', ''), '/');
        if ($appUrl === '') {
            $hostRow = $this->db->fetchOne("SELECT `value` FROM settings WHERE `key` = 'server_host'");
            $host = $hostRow['value'] ?? '';
            $appUrl = $host !== '' ? 'https://' . $host : '';
        }

        $platform = strtolower((string) ($agent['platform'] ?? ''));
        $osInfo = strtolower((string) ($agent['os_info'] ?? ''));
        $isWindows = str_contains($platform, 'win') || str_contains($osInfo, 'windows');
        $osHint = $isWindows ? 'windows' : (str_contains($osInfo, 'darwin') || str_contains($osInfo, 'mac') ? 'macos' : 'linux');

        // Assembled here so a caller never builds it from parts and gets a
        // flag wrong.
        if ($appUrl === '' || !$apiKey) {
            $command = null;
        } elseif ($isWindows) {
            $command = sprintf(
                'powershell -ExecutionPolicy Bypass -Command "irm %s/get-agent-windows | iex" -Server %s -Key %s',
                $appUrl, $appUrl, $apiKey
            );
        } else {
            $command = sprintf(
                'curl -s %s/get-agent | sudo bash -s -- --server %s --key %s',
                $appUrl, $appUrl, $apiKey
            );
        }

        $this->json([
            'command' => $command,
            'api_key' => $apiKey,
            'server_url' => $appUrl ?: null,
            'os_hint' => $osHint,
            'status' => $agent['status'],
        ]);
    }

    /**
     * The stored key, whichever form this install keeps it in.
     */
    private function resolveAgentApiKey(array $agent): ?string
    {
        if (!empty($agent['api_key'])) {
            return $agent['api_key'];
        }
        if (!empty($agent['api_key_encrypted'])) {
            try {
                return Encryption::decrypt($agent['api_key_encrypted']);
            } catch (\Exception $e) {
                return null;
            }
        }
        return null;
    }

    /**
     * PUT /api/v1/clients/{id}/plugin-configs/{configId}
     *
     * Unlike the install key, plugin secrets are not an exception: a value is
     * accepted on write and never returned, and an omitted field keeps what is
     * stored rather than clearing it.
     */
    public function updatePluginConfig(int $id, int $configId): void
    {
        $this->requireApiToken();
        $input = $this->getJsonInput();

        $existing = $this->db->fetchOne(
            "SELECT pc.*, p.slug FROM plugin_configs pc
             JOIN plugins p ON p.id = pc.plugin_id
             WHERE pc.id = ? AND pc.agent_id = ?",
            [$configId, $id]
        );
        if (!$existing) {
            $this->json(['error' => 'Plugin configuration not found for this client'], 404);
        }

        $data = [];
        if (array_key_exists('name', $input)) {
            $name = trim((string) $input['name']);
            if ($name === '') {
                $this->json(['error' => 'name cannot be empty'], 422);
            }
            $clash = $this->db->fetchOne(
                "SELECT id FROM plugin_configs WHERE agent_id = ? AND plugin_id = ? AND name = ? AND id != ?",
                [$id, $existing['plugin_id'], $name, $configId]
            );
            if ($clash) {
                $this->json(['error' => "A config named \"{$name}\" already exists for this plugin"], 409);
            }
            $data['name'] = $name;
        }

        if (isset($input['config']) && is_array($input['config'])) {
            $stored = json_decode($existing['config'] ?? '{}', true) ?: [];
            $schema = (new \BBS\Services\PluginManager())->getPluginSchema($existing['slug']);
            foreach ($input['config'] as $field => $value) {
                if (!empty($schema[$field]['sensitive'])) {
                    // Empty means "leave it alone", not "clear it".
                    if ($value === '' || $value === null) {
                        continue;
                    }
                    $stored[$field] = Encryption::encrypt((string) $value);
                    continue;
                }
                $stored[$field] = $value;
            }
            $data['config'] = json_encode($stored);
        }

        if (!empty($data)) {
            $this->db->update('plugin_configs', $data, 'id = ?', [$configId]);
        }

        $this->json(['config' => $this->pluginConfigPayload($configId)]);
    }

    /**
     * DELETE /api/v1/clients/{id}/plugin-configs/{configId}
     */
    public function deletePluginConfig(int $id, int $configId): void
    {
        $this->requireApiToken();

        $existing = $this->db->fetchOne(
            "SELECT id FROM plugin_configs WHERE id = ? AND agent_id = ?",
            [$configId, $id]
        );
        if (!$existing) {
            $this->json(['error' => 'Plugin configuration not found for this client'], 404);
        }

        // Same guard as the web: a config a repository still syncs through
        // cannot be removed out from under it.
        $inUse = $this->db->fetchAll(
            "SELECT r.id, r.name FROM repository_s3_configs rsc
             JOIN repositories r ON r.id = rsc.repository_id
             WHERE rsc.plugin_config_id = ?",
            [$configId]
        );
        if (!empty($inUse)) {
            $this->json([
                'error' => sprintf('In use by %d repository/repositories', count($inUse)),
                'reason' => 'repositories_attached',
                'repositories' => array_map(fn($r) => ['id' => (int) $r['id'], 'name' => $r['name']], $inUse),
            ], 409);
        }

        $this->db->delete('backup_plan_plugins', 'plugin_config_id = ?', [$configId]);
        $this->db->delete('plugin_configs', 'id = ?', [$configId]);
        http_response_code(204);
        exit;
    }

    /**
     * POST /api/v1/clients/{id}/plugin-configs/{configId}/test
     *
     * S3 configurations are tested here and answer immediately; everything
     * else has to run on the client, so it queues a job and the caller polls
     * it like any other.
     */
    public function testPluginConfig(int $id, int $configId): void
    {
        $this->requireApiToken();

        $config = $this->db->fetchOne(
            "SELECT pc.*, p.slug FROM plugin_configs pc
             JOIN plugins p ON p.id = pc.plugin_id
             WHERE pc.id = ? AND pc.agent_id = ?",
            [$configId, $id]
        );
        if (!$config) {
            $this->json(['error' => 'Plugin configuration not found for this client'], 404);
        }

        if ($config['slug'] === 's3_sync') {
            $configData = json_decode($config['config'] ?? '{}', true) ?: [];
            $s3 = new \BBS\Services\S3SyncService();
            $result = $s3->testConnection($s3->resolveCredentials($configData));
            if (empty($result['success'])) {
                $this->json(['status' => 'failed', 'error' => $result['error'] ?? 'Connection failed'], 502);
            }
            $this->json(['status' => 'completed', 'message' => 'Connection successful']);
        }

        $jobId = $this->db->insert('backup_jobs', [
            'agent_id' => $id,
            'task_type' => 'plugin_test',
            'status' => 'queued',
            'plugin_config_id' => $configId,
        ]);
        $this->db->insert('server_log', [
            'agent_id' => $id,
            'backup_job_id' => $jobId,
            'level' => 'info',
            'message' => "Plugin test queued (job #{$jobId}, config #{$configId})",
        ]);

        $this->json(['status' => 'queued', 'job_id' => (int) $jobId], 202);
    }

    /** One plugin config, with secrets reported as set/unset rather than returned. */
    private function pluginConfigPayload(int $configId): array
    {
        $row = $this->db->fetchOne(
            "SELECT pc.id, pc.name, pc.config, pc.created_at, p.slug, p.name AS plugin_name
             FROM plugin_configs pc JOIN plugins p ON p.id = pc.plugin_id
             WHERE pc.id = ?",
            [$configId]
        );
        if (!$row) {
            return [];
        }
        $config = json_decode($row['config'] ?? '{}', true) ?: [];
        $schema = (new \BBS\Services\PluginManager())->getPluginSchema($row['slug']);
        foreach ($schema as $field => $def) {
            if (!empty($def['sensitive'])) {
                $config[$field . '_set'] = !empty($config[$field]);
                unset($config[$field]);
            }
        }
        return [
            'id' => (int) $row['id'],
            'name' => $row['name'],
            'plugin' => $row['slug'],
            'plugin_name' => $row['plugin_name'],
            'config' => $config,
            'created_at' => $row['created_at'],
        ];
    }

    /**
     * POST /api/v1/clients/{id}/repositories/{repoId}/check
     * Kept as its own route now that maintenance covers every action.
     */
    public function checkRepository(int $id, int $repoId): void
    {
        $this->queueRepositoryMaintenance($id, $repoId, 'check');
    }

    /**
     * POST /api/v1/clients/{id}/repositories/{repoId}/maintenance
     * Body: {"action": "check|compact|repair|break_lock|catalog_rebuild|catalog_rebuild_full"}
     *
     * The same set the web offers. compact is the one worth having away from a
     * desk — it is what actually reclaims disk after a prune — and break_lock
     * is the usual way out of an interrupted backup.
     */
    public function repositoryMaintenance(int $id, int $repoId): void
    {
        $input = $this->getJsonInput();
        $action = trim((string) ($input['action'] ?? ($_GET['action'] ?? '')));
        $this->queueRepositoryMaintenance($id, $repoId, $action);
    }

    /**
     * POST /api/v1/clients/{id}/repositories/{repoId}/catalog/sync
     *
     * Requests the file catalog for this repository be rebuilt — the action to
     * offer when browsing reports its catalog as pending.
     */
    public function syncRepositoryCatalog(int $id, int $repoId): void
    {
        $input = $this->getJsonInput();
        // Default to filling in what is missing; full re-reads every archive.
        $full = !empty($input['full']) || !empty($_GET['full']);
        $this->queueRepositoryMaintenance($id, $repoId, $full ? 'catalog_rebuild_full' : 'catalog_rebuild');
    }

    /**
     * Shared by the maintenance routes: same actions, permission and
     * duplicate-suppression as the web page.
     */
    private function queueRepositoryMaintenance(int $id, int $repoId, string $action): void
    {
        $ctx = $this->requireApiAuth();
        if (!$this->apiCanAccessAgent($ctx, $id)) {
            $this->json(['error' => 'Client not found'], 404);
        }
        if (!$this->apiHasPermission($ctx, \BBS\Services\PermissionService::REPO_MAINTENANCE, $id)) {
            $this->json(['error' => 'Repository maintenance permission required'], 403);
        }

        // "Rebuild Full" dispatches as catalog_sync, which wipes the archive
        // records, re-reads them from borg with sizes, then queues the rebuild.
        $taskType = match ($action) {
            'check' => 'repo_check',
            'compact' => 'compact',
            'repair' => 'repo_repair',
            'break_lock' => 'break_lock',
            'catalog_rebuild' => 'catalog_rebuild',
            'catalog_rebuild_full' => 'catalog_sync',
            default => null,
        };
        if ($taskType === null) {
            $this->json([
                'error' => 'Unknown action',
                'valid_actions' => ['check', 'compact', 'repair', 'break_lock',
                                    'catalog_rebuild', 'catalog_rebuild_full'],
            ], 400);
        }

        $label = match ($action) {
            'check' => 'Check',
            'compact' => 'Compact',
            'repair' => 'Repair',
            'break_lock' => 'Break Lock',
            'catalog_rebuild' => 'Rebuild Catalog (Missing)',
            'catalog_rebuild_full' => 'Rebuild Catalog (Full)',
        };

        $repo = $this->db->fetchOne(
            "SELECT id, name FROM repositories WHERE id = ? AND agent_id = ?",
            [$repoId, $id]
        );
        if (!$repo) {
            $this->json(['error' => 'Repository not found'], 404);
        }

        // One of each kind at a time; different kinds may queue together.
        $pending = $this->db->fetchOne(
            "SELECT id FROM backup_jobs WHERE repository_id = ? AND task_type = ?
               AND status IN ('queued', 'sent', 'running')",
            [$repoId, $taskType]
        );
        if ($pending) {
            $this->json([
                'status' => 'already_queued',
                'job_id' => (int) $pending['id'],
                'action' => $action,
                'message' => "A {$label} job is already queued or running for this repository",
            ], 409);
        }

        $jobId = $this->db->insert('backup_jobs', [
            'agent_id' => $id,
            'repository_id' => $repoId,
            'task_type' => $taskType,
            'status' => 'queued',
        ]);
        $this->db->insert('server_log', [
            'agent_id' => $id,
            'backup_job_id' => $jobId,
            'level' => 'info',
            'message' => "{$label} job #{$jobId} queued for repository \"{$repo['name']}\"",
        ]);

        $this->json([
            'status' => 'queued',
            'job_id' => (int) $jobId,
            'action' => $action,
            'task_type' => $taskType,
        ], 202);
    }

    /**
     * DELETE /api/v1/clients/{id}/repositories/{repoId}/archives/{archiveId}
     *
     * Removes one recovery point. Locked archives are refused — a legal hold
     * has to be lifted deliberately, not stepped over by whichever client
     * happens to be asking.
     */
    public function deleteArchive(int $id, int $repoId, int $archiveId): void
    {
        $ctx = $this->requireApiAuth();
        if (!$this->apiCanAccessAgent($ctx, $id)) {
            $this->json(['error' => 'Client not found'], 404);
        }
        if (!$this->apiHasPermission($ctx, \BBS\Services\PermissionService::MANAGE_REPOS, $id)) {
            $this->json(['error' => 'Repository management permission required'], 403);
        }

        $archive = $this->db->fetchOne(
            "SELECT ar.id, ar.archive_name, ar.locked FROM archives ar
             JOIN repositories r ON r.id = ar.repository_id
             WHERE ar.id = ? AND ar.repository_id = ? AND r.agent_id = ?",
            [$archiveId, $repoId, $id]
        );
        if (!$archive) {
            $this->json(['error' => 'Archive not found'], 404);
        }
        if (!empty($archive['locked'])) {
            $this->json([
                'error' => 'This recovery point is locked — unlock it first',
                'reason' => 'locked',
            ], 409);
        }

        $pending = $this->db->fetchOne(
            "SELECT id FROM backup_jobs WHERE repository_id = ? AND task_type = 'archive_delete'
               AND status IN ('queued', 'sent', 'running')",
            [$repoId]
        );
        if ($pending) {
            $this->json([
                'status' => 'already_queued',
                'job_id' => (int) $pending['id'],
                'message' => 'A deletion is already queued or running for this repository',
            ], 409);
        }

        // The worker resolves the target from status_message — it runs
        // `borg delete repo::name` and then clears the row by name. Passing an
        // id instead would queue a job that deletes nothing.
        $jobId = $this->db->insert('backup_jobs', [
            'agent_id' => $id,
            'repository_id' => $repoId,
            'task_type' => 'archive_delete',
            'status' => 'queued',
            'status_message' => $archive['archive_name'],
        ]);
        $this->db->insert('server_log', [
            'agent_id' => $id,
            'backup_job_id' => $jobId,
            'level' => 'info',
            'message' => "Recovery point deletion queued: \"{$archive['archive_name']}\" (job #{$jobId})",
        ]);

        $this->json(['status' => 'queued', 'job_id' => (int) $jobId], 202);
    }

    /**
     * GET /api/v1/clients/{id}/stats — the figures the web client page shows.
     *
     * A caller can derive these from /jobs, but then the two products can
     * disagree about the same client whenever the windowing differs. Computed
     * here so they don't.
     */
    public function clientStats(int $id): void
    {
        $ctx = $this->requireApiAuth();
        if (!$this->apiCanAccessAgent($ctx, $id)) {
            $this->json(['error' => 'Client not found'], 404);
        }

        $next = $this->db->fetchOne(
            "SELECT s.next_run, bp.name AS plan_name
             FROM schedules s JOIN backup_plans bp ON bp.id = s.backup_plan_id
             WHERE bp.agent_id = ? AND s.enabled = 1 AND bp.enabled = 1 AND s.next_run IS NOT NULL
             ORDER BY s.next_run ASC LIMIT 1",
            [$id]
        );

        // Last 30 completed backups, the same sample the web page averages.
        $durations = $this->db->fetchAll(
            "SELECT duration_seconds FROM backup_jobs
             WHERE agent_id = ? AND task_type = 'backup' AND status = 'completed'
               AND duration_seconds IS NOT NULL AND duration_seconds > 0
             ORDER BY completed_at DESC LIMIT 30",
            [$id]
        );
        $avg = count($durations)
            ? (int) round(array_sum(array_column($durations, 'duration_seconds')) / count($durations))
            : null;

        $outcomes = $this->db->fetchAll(
            "SELECT status, COUNT(*) c FROM backup_jobs
             WHERE agent_id = ? AND task_type = 'backup' AND status IN ('completed', 'failed')
               AND completed_at > DATE_SUB(NOW(), INTERVAL 30 DAY)
             GROUP BY status",
            [$id]
        );
        $succeeded = $failed = 0;
        foreach ($outcomes as $o) {
            if ($o['status'] === 'completed') $succeeded = (int) $o['c'];
            else $failed = (int) $o['c'];
        }

        $errors7d = (int) ($this->db->fetchOne(
            "SELECT COUNT(*) c FROM backup_jobs
             WHERE agent_id = ? AND status = 'failed'
               AND completed_at > DATE_SUB(NOW(), INTERVAL 7 DAY)",
            [$id]
        )['c'] ?? 0);

        $this->json([
            'next_backup' => $next ? ['at' => $next['next_run'], 'plan_name' => $next['plan_name']] : null,
            'avg_duration_seconds' => $avg,
            'sample_size' => count($durations),
            'success_rate' => ['succeeded' => $succeeded, 'total' => $succeeded + $failed],
            'errors_7d' => $errors7d,
        ]);
    }

    public function registerPush(): void
    {
        $ctx = $this->requireApiAuth();
        $input = $this->getJsonInput();

        $deviceId = substr(trim($input['device_id'] ?? ''), 0, 64);
        // apns_token is the original field name, kept as an alias so clients
        // written against it keep working.
        $token = substr(trim($input['push_token'] ?? ($input['apns_token'] ?? '')), 0, 512);
        $platform = substr(trim($input['platform'] ?? 'ios'), 0, 16);
        $deviceName = substr(trim($input['device_name'] ?? ''), 0, 100) ?: null;

        if ($deviceId === '' || $token === '') {
            $this->json(['error' => 'device_id and push_token are required'], 400);
        }

        // Preferences are materialised now rather than left null: the delivery
        // filter reads them as JSON, and a null would quietly match nothing.
        $events = \BBS\Services\PushService::DEFAULT_EVENTS;
        if (isset($input['events']) && is_array($input['events'])) {
            foreach ($events as $key => $default) {
                if (array_key_exists($key, $input['events'])) {
                    $events[$key] = (bool) $input['events'][$key];
                }
            }
        }

        $userId = (int) $ctx['id'];

        // A device belongs to one account at a time. The app holds a single
        // session, so registering here means any previous owner's row for this
        // handset is stale — clear it rather than leave it delivering to an
        // account the phone can no longer open.
        $this->db->delete('push_tokens', 'device_id = ? AND user_id != ?', [$deviceId, $userId]);

        $this->db->query(
            "INSERT INTO push_tokens (user_id, device_id, push_token, platform, device_name, events, enabled)
             VALUES (?, ?, ?, ?, ?, ?, 1)
             ON DUPLICATE KEY UPDATE
                user_id = VALUES(user_id),
                push_token = VALUES(push_token),
                platform = VALUES(platform),
                device_name = VALUES(device_name),
                events = VALUES(events)",
            [$userId, $deviceId, $token, $platform, $deviceName, json_encode($events)]
        );

        (new \BBS\Services\PushService())->registerDevice($userId, $deviceId, $token, $platform);

        $this->json(['status' => 'ok', 'events' => $events]);
    }

    /**
     * GET /api/v1/push/devices?device_id=… — the caller's own devices.
     * Not admin data: a user sees their devices and nobody else's.
     */
    public function listPushDevices(): void
    {
        $ctx = $this->requireApiAuth();
        $current = substr(trim($_GET['device_id'] ?? ''), 0, 64);

        $rows = $this->db->fetchAll(
            "SELECT device_id, device_name, platform, events, enabled, created_at, updated_at
             FROM push_tokens WHERE user_id = ? ORDER BY created_at",
            [(int) $ctx['id']]
        );

        $devices = [];
        foreach ($rows as $r) {
            $devices[] = [
                'device_id' => $r['device_id'],
                'device_name' => $r['device_name'],
                'platform' => $r['platform'],
                'events' => json_decode($r['events'] ?? '{}', true) ?: (object) [],
                'enabled' => (bool) $r['enabled'],
                'created_at' => $r['created_at'],
                'updated_at' => $r['updated_at'],
                'is_current' => $current !== '' && $r['device_id'] === $current,
            ];
        }

        // push_token is deliberately absent: nothing needs to read it back,
        // and it is the one value here worth stealing.
        $this->json(['devices' => $devices]);
    }

    /**
     * PATCH /api/v1/push/devices/{deviceId} — event preferences, or the
     * on/off switch. Disabling keeps the registration and stops delivery.
     */
    public function updatePushDevice(string $deviceId): void
    {
        $ctx = $this->requireApiAuth();
        $input = $this->getJsonInput();
        $userId = (int) $ctx['id'];

        $row = $this->db->fetchOne(
            "SELECT events FROM push_tokens WHERE device_id = ? AND user_id = ?",
            [$deviceId, $userId]
        );
        if (!$row) {
            $this->json(['error' => 'Device not found'], 404);
        }

        $data = [];
        if (array_key_exists('enabled', $input)) {
            $data['enabled'] = !empty($input['enabled']) ? 1 : 0;
        }
        if (isset($input['events']) && is_array($input['events'])) {
            $events = json_decode($row['events'] ?? '{}', true) ?: [];
            $events = array_merge(\BBS\Services\PushService::DEFAULT_EVENTS, $events);
            foreach (\BBS\Services\PushService::DEFAULT_EVENTS as $key => $default) {
                if (array_key_exists($key, $input['events'])) {
                    $events[$key] = (bool) $input['events'][$key];
                }
            }
            $data['events'] = json_encode($events);
        }

        if (!empty($data)) {
            $this->db->update('push_tokens', $data, 'device_id = ? AND user_id = ?', [$deviceId, $userId]);
        }

        $updated = $this->db->fetchOne(
            "SELECT device_id, device_name, platform, events, enabled, created_at, updated_at
             FROM push_tokens WHERE device_id = ? AND user_id = ?",
            [$deviceId, $userId]
        );
        $this->json(['device' => [
            'device_id' => $updated['device_id'],
            'device_name' => $updated['device_name'],
            'platform' => $updated['platform'],
            'events' => json_decode($updated['events'] ?? '{}', true) ?: (object) [],
            'enabled' => (bool) $updated['enabled'],
            'created_at' => $updated['created_at'],
            'updated_at' => $updated['updated_at'],
        ]]);
    }

    public function unregisterPush(): void
    {
        $ctx = $this->requireApiAuth();
        $input = $this->getJsonInput();
        $deviceId = substr(trim($input['device_id'] ?? ($_GET['device_id'] ?? '')), 0, 64);

        if ($deviceId === '') {
            $this->json(['error' => 'device_id is required'], 400);
        }

        $this->db->delete('push_tokens', 'user_id = ? AND device_id = ?', [(int) $ctx['id'], $deviceId]);
        // Best-effort, short timeout: a sign-out must not hang on the relay.
        (new \BBS\Services\PushService())->deleteDevice($deviceId);
        http_response_code(204);
        exit;
    }
}
