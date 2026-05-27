<?php

namespace BBS\Controllers\Api;

use BBS\Core\Config;
use BBS\Core\Controller;
use BBS\Controllers\StorageLocationController;
use BBS\Services\BorgCommandBuilder;
use BBS\Services\Encryption;
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
        $this->requireApiToken();

        $agents = $this->db->fetchAll("
            SELECT a.id, a.name, a.hostname, a.ip_address, a.os_info,
                   a.borg_version, a.agent_version, a.status, a.last_heartbeat,
                   a.created_at, u.username as owner
            FROM agents a
            LEFT JOIN users u ON u.id = a.user_id
            ORDER BY a.name
        ");

        $this->json(['clients' => $agents]);
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
        $this->requireApiToken();

        $agent = $this->db->fetchOne("
            SELECT a.id, a.name, a.hostname, a.ip_address, a.os_info,
                   a.borg_version, a.agent_version, a.status, a.last_heartbeat,
                   a.api_key, a.api_key_encrypted, a.created_at, u.username as owner
            FROM agents a
            LEFT JOIN users u ON u.id = a.user_id
            WHERE a.id = ?
        ", [$id]);

        if (!$agent) {
            $this->json(['error' => 'Client not found'], 404);
        }

        // Decrypt stored token for API response (falls back to legacy plaintext).
        if (empty($agent['api_key']) && !empty($agent['api_key_encrypted'])) {
            try {
                $agent['api_key'] = \BBS\Services\Encryption::decrypt($agent['api_key_encrypted']);
            } catch (\Throwable $e) { /* leave blank */ }
        }
        unset($agent['api_key_encrypted']);

        // Include repos and plans
        $repos = $this->db->fetchAll(
            "SELECT id, name, path, encryption, storage_type, size_bytes, archive_count, created_at
             FROM repositories WHERE agent_id = ? ORDER BY name", [$id]
        );
        $plans = $this->db->fetchAll(
            "SELECT bp.id, bp.name, bp.directories, bp.excludes, bp.advanced_options, bp.enabled,
                    s.frequency, s.times, s.day_of_week, s.day_of_month
             FROM backup_plans bp
             LEFT JOIN schedules s ON s.backup_plan_id = bp.id
             WHERE bp.agent_id = ? ORDER BY bp.name", [$id]
        );

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
            $this->json(['error' => 'SSH provisioning failed. Ensure bbs-ssh-helper is installed.'], 500);
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

        $this->db->delete('agents', 'id = ?', [$id]);

        $this->json(['status' => 'ok', 'message' => "Client \"{$agent['name']}\" deleted"]);
    }

    // ── Repositories ─────────────────────────────────────

    public function listRepositories(int $id): void
    {
        $this->requireApiToken();

        $agent = $this->db->fetchOne("SELECT id FROM agents WHERE id = ?", [$id]);
        if (!$agent) {
            $this->json(['error' => 'Client not found'], 404);
        }

        $repos = $this->db->fetchAll(
            "SELECT r.id, r.name, r.path, r.encryption, r.storage_type, r.size_bytes, r.archive_count, r.created_at,
                    COALESCE(rsc.enabled, 0) AS s3_sync_enabled,
                    rsc.last_sync_at AS s3_last_sync_at
             FROM repositories r
             LEFT JOIN repository_s3_configs rsc ON rsc.repository_id = r.id
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

        $initCmd = BorgCommandBuilder::buildInitCommand($repo);
        $env = BorgCommandBuilder::buildEnv($repo);
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = proc_open($initCmd, $descriptors, $pipes, null, $env);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[0]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($proc);

        if ($exitCode !== 0) {
            $errorMsg = trim($stderr ?: $stdout);
            $this->db->insert('server_log', [
                'agent_id' => $id,
                'level' => 'error',
                'message' => "borg init failed for repo \"{$safeName}\" via API: {$errorMsg}",
            ]);
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
        $this->requireApiToken();

        $agent = $this->db->fetchOne("SELECT id FROM agents WHERE id = ?", [$id]);
        if (!$agent) {
            $this->json(['error' => 'Client not found'], 404);
        }

        $plans = $this->db->fetchAll("
            SELECT bp.id, bp.name, bp.directories, bp.excludes, bp.advanced_options,
                   bp.enabled, bp.repository_id, r.name as repository_name,
                   s.frequency, s.times, s.day_of_week, s.day_of_month
            FROM backup_plans bp
            LEFT JOIN schedules s ON s.backup_plan_id = bp.id
            LEFT JOIN repositories r ON r.id = bp.repository_id
            WHERE bp.agent_id = ?
            ORDER BY bp.name
        ", [$id]);

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

        $locations = $this->db->fetchAll("SELECT id, label as name, path, is_default, created_at FROM storage_locations ORDER BY label");
        $remoteConfigs = $this->db->fetchAll("
            SELECT id, name, provider, remote_host, remote_port, remote_user, remote_base_path,
                   borg_remote_path, append_repo_name, disk_total_bytes, disk_used_bytes,
                   disk_free_bytes, disk_checked_at, created_at
            FROM remote_ssh_configs ORDER BY name
        ");

        // Decorate local locations with live df capacity/usage (#157).
        foreach ($locations as &$loc) {
            $disk = \BBS\Services\ServerStats::getDiskUsage($loc['path']);
            if ($disk) {
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

        $planCount = $this->db->fetchOne("SELECT COUNT(*) as cnt FROM backup_plans WHERE repository_id = ?", [$repoId]);
        if ((int) ($planCount['cnt'] ?? 0) > 0) {
            $this->json(['error' => 'Cannot delete — repository has backup plans attached. Delete the plans first.'], 409);
        }

        $activeJobs = $this->db->fetchOne("SELECT COUNT(*) as cnt FROM backup_jobs WHERE repository_id = ? AND status IN ('queued', 'sent', 'running')", [$repoId]);
        if ((int) ($activeJobs['cnt'] ?? 0) > 0) {
            $this->json(['error' => 'Cannot delete — repository has active jobs'], 409);
        }

        // Delete from disk
        $localPath = BorgCommandBuilder::getLocalRepoPath($repo);
        if (!empty($localPath) && is_dir($localPath)) {
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
            'message' => "Repository \"{$repo['name']}\" deleted via API",
        ]);

        $this->json(['status' => 'ok', 'message' => "Repository \"{$repo['name']}\" deleted"]);
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
        $this->requireApiToken();

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
        $this->requireApiToken();

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
        $this->requireApiToken();

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

        if (empty($data)) {
            $this->json(['error' => 'No fields to update'], 400);
        }

        $this->db->update('agents', $data, 'id = ?', [$id]);

        $this->json(['status' => 'ok', 'message' => 'Client updated']);
    }

    // ── Jobs & Queue ─────────────────────────────────────

    public function listJobs(int $id): void
    {
        $this->requireApiToken();

        $agent = $this->db->fetchOne("SELECT id FROM agents WHERE id = ?", [$id]);
        if (!$agent) {
            $this->json(['error' => 'Client not found'], 404);
        }

        $limit = min((int) ($_GET['limit'] ?? 50), 200);
        $offset = (int) ($_GET['offset'] ?? 0);
        $status = $_GET['status'] ?? null;

        $where = "bj.agent_id = ?";
        $params = [$id];

        if ($status) {
            $where .= " AND bj.status = ?";
            $params[] = $status;
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
        $this->requireApiToken();

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
        $this->requireApiToken();

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
            WHERE bj.status IN ('queued', 'sent', 'running')
            ORDER BY bj.queued_at ASC
        ");

        $this->json(['queue' => $jobs, 'count' => count($jobs)]);
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
        $this->requireApiToken();

        $loc = $this->db->fetchOne("SELECT path FROM storage_locations WHERE is_default = 1");
        if (!$loc) {
            $this->json(['error' => 'No default storage location configured'], 404);
        }
        $disk = \BBS\Services\ServerStats::getDiskUsage($loc['path']);
        if (!$disk) {
            $this->json(['error' => 'Could not read disk usage for default storage'], 500);
        }
        $this->json([
            'provisioned_bytes' => (int) $disk['total'],
            'used_bytes' => (int) $disk['used'],
            'free_bytes' => (int) $disk['free'],
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
     * Set the global S3 sync credentials. Platform-only because in hosted
     * mode the customer never sees the access key/secret.
     */
    public function setS3Credentials(): void
    {
        $this->requirePlatformApiToken();
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
        $this->requirePlatformApiToken();

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

        $existing = $this->db->fetchOne("SELECT id FROM repository_s3_configs WHERE repository_id = ?", [$repoId]);
        if ($existing) {
            $this->db->update('repository_s3_configs', ['enabled' => $enabled ? 1 : 0], 'repository_id = ?', [$repoId]);
        } else {
            $this->db->insert('repository_s3_configs', [
                'repository_id' => $repoId,
                'enabled' => $enabled ? 1 : 0,
            ]);
        }

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
             LEFT JOIN repository_s3_configs rsc ON rsc.repository_id = r.id
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
}
