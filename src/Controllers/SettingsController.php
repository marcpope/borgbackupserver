<?php

namespace BBS\Controllers;

use BBS\Core\Controller;

class SettingsController extends Controller
{
    public function index(): void
    {
        $this->requireAdmin();

        $settings = [];
        $rows = $this->db->fetchAll("SELECT `key`, `value` FROM settings");
        foreach ($rows as $row) {
            $settings[$row['key']] = $row['value'];
        }

        $templates = $this->db->fetchAll("SELECT * FROM backup_templates ORDER BY name");

        $oidcUsers = $this->db->fetchAll("SELECT id, username, email, role FROM users ORDER BY username");

        $apiTokens = $this->db->fetchAll("
            SELECT t.id, t.name, t.created_at, t.last_used_at, u.username
            FROM api_tokens t
            JOIN users u ON u.id = t.user_id
            ORDER BY t.created_at
        ");

        $this->view('settings/index', [
            'pageTitle' => 'Settings',
            'settings' => $settings,
            'templates' => $templates,
            'apiTokens' => $apiTokens,
            'oidcUsers' => $oidcUsers,
        ]);
    }

    public function dockerSetup(): void
    {
        $this->requireAdmin();
        $this->verifyCsrf();

        $hostname = trim($_POST['hostname'] ?? '');
        $webPort = (int) ($_POST['web_port'] ?? 8080);
        $sshPort = (int) ($_POST['ssh_port'] ?? 22);

        if (empty($hostname)) {
            $this->flash('danger', 'Server hostname or IP is required.');
            $this->redirect('/');
            return;
        }

        // Build server_host (include port if non-standard)
        $serverHost = $hostname;
        if ($webPort && $webPort !== 80 && $webPort !== 443) {
            $serverHost .= ':' . $webPort;
        }

        // Save settings
        $settings = [
            'server_host' => $serverHost,
            'ssh_port' => (string) $sshPort,
            'docker_setup_complete' => '1',
        ];
        foreach ($settings as $key => $value) {
            $existing = $this->db->fetchOne("SELECT `key` FROM settings WHERE `key` = ?", [$key]);
            if ($existing) {
                $this->db->update('settings', ['value' => $value], "`key` = ?", [$key]);
            } else {
                $this->db->insert('settings', ['key' => $key, 'value' => $value]);
            }
        }

        // Update APP_URL in .env
        $newAppUrl = "http://{$serverHost}";
        $envPath = dirname(__DIR__, 2) . '/config/.env';
        if (file_exists($envPath) && is_writable($envPath)) {
            $env = file_get_contents($envPath);
            $env = preg_replace('/^APP_URL=.*$/m', 'APP_URL=' . $newAppUrl, $env);
            file_put_contents($envPath, $env);
        }

        $this->flash('success', 'Docker network settings configured.');
        $this->redirect('/');
    }

    public function update(): void
    {
        $this->requireAdmin();
        $this->verifyCsrf();

        $allowed = ['max_queue', 'server_host', 'ssh_port', 'agent_poll_interval', 'stall_timeout_minutes', 'session_timeout_hours', 'default_theme', 'smtp_host', 'smtp_port', 'smtp_user', 'smtp_secure', 'smtp_from', 'notification_retention_days', 'storage_alert_threshold', 'apprise_urls', 'self_backup_retention'];

        foreach ($allowed as $key) {
            if (isset($_POST[$key])) {
                $existing = $this->db->fetchOne("SELECT `key` FROM settings WHERE `key` = ?", [$key]);
                if ($existing) {
                    $this->db->update('settings', ['value' => $_POST[$key]], "`key` = ?", [$key]);
                } else {
                    $this->db->insert('settings', ['key' => $key, 'value' => $_POST[$key]]);
                }
            }
        }

        // SMTP password: only update when non-empty (so leaving the field blank
        // doesn't wipe it). Stored encrypted via APP_KEY.
        if (!empty($_POST['smtp_pass'])) {
            $this->saveSetting('smtp_pass', \BBS\Services\Encryption::encrypt($_POST['smtp_pass']));
        }

        // Checkbox toggles: unchecked = not posted, so explicitly save '0'
        $checkboxKeys = ['maintenance_mode', 'email_on_backup_failed', 'email_on_backup_warning', 'email_on_agent_offline', 'email_on_storage_low', 'email_on_missed_schedule', 'apprise_on_backup_failed', 'apprise_on_backup_warning', 'apprise_on_agent_offline', 'apprise_on_storage_low', 'apprise_on_missed_schedule', 'force_2fa', 'debug_mode', 'self_backup_enabled', 'self_backup_catalogs', 'telemetry_opt_out', 'inapp_notify_success_events'];
        foreach ($checkboxKeys as $key) {
            $value = isset($_POST[$key]) ? '1' : '0';
            $existing = $this->db->fetchOne("SELECT `key` FROM settings WHERE `key` = ?", [$key]);
            if ($existing) {
                $this->db->update('settings', ['value' => $value], "`key` = ?", [$key]);
            } else {
                $this->db->insert('settings', ['key' => $key, 'value' => $value]);
            }
        }

        // Update APP_URL in .env when server_host or protocol changes
        if (isset($_POST['server_host'])) {
            $protocol = ($_POST['url_protocol'] ?? 'https') === 'http' ? 'http' : 'https';
            $host = trim($_POST['server_host']);
            $newAppUrl = "{$protocol}://{$host}";
            $envPath = dirname(__DIR__, 2) . '/config/.env';
            if (file_exists($envPath) && is_writable($envPath)) {
                $env = file_get_contents($envPath);
                $env = preg_replace('/^APP_URL=.*$/m', 'APP_URL=' . $newAppUrl, $env);
                file_put_contents($envPath, $env);
            }
        }

        $this->flash('success', 'Settings updated.');
        $tab = $_POST['_tab'] ?? 'general';
        $this->redirect('/settings?tab=' . urlencode($tab));
    }

    public function addTemplate(): void
    {
        $this->requireAdmin();
        $this->verifyCsrf();

        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $directories = trim($_POST['directories'] ?? '');
        $excludes = trim($_POST['excludes'] ?? '');
        $advancedOptions = trim($_POST['advanced_options'] ?? '');

        if (empty($name) || empty($directories)) {
            $this->flash('danger', 'Template name and directories are required.');
            $this->redirect('/settings?tab=templates');
        }

        $this->db->insert('backup_templates', [
            'name' => $name,
            'description' => $description,
            'directories' => $directories,
            'excludes' => $excludes ?: null,
            'advanced_options' => $advancedOptions ?: null,
        ]);

        $this->flash('success', "Template \"{$name}\" created.");
        $this->redirect('/settings?tab=templates');
    }

    public function editTemplate(int $id): void
    {
        $this->requireAdmin();
        $this->verifyCsrf();

        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $directories = trim($_POST['directories'] ?? '');
        $excludes = trim($_POST['excludes'] ?? '');
        $advancedOptions = trim($_POST['advanced_options'] ?? '');

        if (empty($name) || empty($directories)) {
            $this->flash('danger', 'Template name and directories are required.');
            $this->redirect('/settings?tab=templates');
        }

        $this->db->update('backup_templates', [
            'name' => $name,
            'description' => $description,
            'directories' => $directories,
            'excludes' => $excludes ?: null,
            'advanced_options' => $advancedOptions ?: null,
        ], 'id = ?', [$id]);

        $this->flash('success', "Template \"{$name}\" updated.");
        $this->redirect('/settings?tab=templates');
    }

    public function deleteTemplate(int $id): void
    {
        $this->requireAdmin();
        $this->verifyCsrf();

        $this->db->delete('backup_templates', 'id = ?', [$id]);
        $this->flash('success', 'Template deleted.');
        $this->redirect('/settings?tab=templates');
    }

    public function saveOidc(): void
    {
        $this->requireAdmin();
        $this->verifyCsrf();

        // Plain text settings
        $fields = ['oidc_provider_url', 'oidc_client_id', 'oidc_button_label', 'oidc_scopes', 'oidc_new_user_policy', 'oidc_template_user_id', 'oidc_redirect_url'];
        foreach ($fields as $key) {
            if (isset($_POST[$key])) {
                $this->saveSetting($key, trim($_POST[$key]));
            }
        }

        // Checkboxes (default to 0 if not in POST)
        $this->saveSetting('oidc_enabled', !empty($_POST['oidc_enabled']) ? '1' : '0');
        $this->saveSetting('oidc_logout_enabled', !empty($_POST['oidc_logout_enabled']) ? '1' : '0');

        // Encrypted client secret (only update if non-empty)
        $secret = $_POST['oidc_client_secret'] ?? '';
        if (!empty($secret)) {
            $this->saveSetting('oidc_client_secret', \BBS\Services\Encryption::encrypt($secret));
        }

        $this->flash('success', 'Authentication settings saved.');
        $this->redirect('/settings?tab=auth');
    }

    public function saveBranding(): void
    {
        $this->requireAdmin();
        $this->verifyCsrf();

        $saved = [];

        // Handle navbar icon
        if (!empty($_POST['remove_branding_icon'])) {
            $this->db->query("DELETE FROM settings WHERE `key` = 'branding_icon'");
            $saved[] = 'Navbar icon removed';
        } elseif (!empty($_POST['branding_icon_data'])) {
            $data = $_POST['branding_icon_data'];
            // Validate it's valid base64 PNG
            $decoded = base64_decode($data, true);
            if ($decoded && substr($decoded, 0, 4) === "\x89PNG") {
                $this->saveSetting('branding_icon', $data);
                $saved[] = 'Navbar icon updated';
            }
        }

        // Handle login logo
        if (!empty($_POST['remove_branding_login_logo'])) {
            $this->db->query("DELETE FROM settings WHERE `key` = 'branding_login_logo'");
            $saved[] = 'Login logo removed';
        } elseif (!empty($_POST['branding_login_logo_data'])) {
            $data = $_POST['branding_login_logo_data'];
            $decoded = base64_decode($data, true);
            if ($decoded && substr($decoded, 0, 4) === "\x89PNG") {
                $this->saveSetting('branding_login_logo', $data);
                $saved[] = 'Login logo updated';
            }
        }

        // Login page theme override
        $loginTheme = $_POST['branding_login_theme'] ?? 'default';
        if (in_array($loginTheme, ['default', 'dark', 'light'])) {
            $this->saveSetting('branding_login_theme', $loginTheme);
            $saved[] = 'Login theme updated';
        }

        $this->flash('success', !empty($saved) ? implode('. ', $saved) . '.' : 'Branding saved.');
        $this->redirect('/settings?tab=branding');
    }

    public function createApiToken(): void
    {
        $this->requireAdmin();
        $this->verifyCsrf();

        $name = trim($_POST['name'] ?? '');
        if (empty($name)) {
            $this->flash('danger', 'Token name is required.');
            $this->redirect('/settings?tab=api');
        }

        // Check duplicate name
        $existing = $this->db->fetchOne("SELECT id FROM api_tokens WHERE name = ?", [$name]);
        if ($existing) {
            $this->flash('danger', "A token with name \"{$name}\" already exists.");
            $this->redirect('/settings?tab=api');
        }

        $token = 'bbs_tok_' . bin2hex(random_bytes(24));
        $hash = hash('sha256', $token);

        $this->db->insert('api_tokens', [
            'name' => $name,
            'token_hash' => $hash,
            'user_id' => $_SESSION['user_id'],
        ]);

        $_SESSION['new_api_token'] = $token;
        $this->flash('success', 'API token created. Copy it now — it will not be shown again.');
        $this->redirect('/settings?tab=api');
    }

    public function revokeApiToken(int $id): void
    {
        $this->requireAdmin();
        $this->verifyCsrf();

        $this->db->delete('api_tokens', 'id = ?', [$id]);
        $this->flash('success', 'API token revoked.');
        $this->redirect('/settings?tab=api');
    }

    private function saveSetting(string $key, string $value): void
    {
        $existing = $this->db->fetchOne("SELECT `key` FROM settings WHERE `key` = ?", [$key]);
        if ($existing) {
            $this->db->update('settings', ['value' => $value], "`key` = ?", [$key]);
        } else {
            $this->db->insert('settings', ['key' => $key, 'value' => $value]);
        }
    }

    public function agentUpdatesJson(): void
    {
        $this->requireAdmin();

        $bundledAgentVersion = null;
        $agentFile = dirname(__DIR__, 2) . '/agent/bbs-agent.py';
        if (file_exists($agentFile)) {
            $fh = fopen($agentFile, 'r');
            if ($fh) {
                for ($i = 0; $i < 50 && ($line = fgets($fh)) !== false; $i++) {
                    if (preg_match('/^AGENT_VERSION\s*=\s*["\']([^"\']+)["\']/m', $line, $mv)) {
                        $bundledAgentVersion = $mv[1];
                        break;
                    }
                }
                fclose($fh);
            }
        }

        if (!$bundledAgentVersion) {
            $this->json(['bundled_version' => null, 'total' => 0, 'outdated' => []]);
            return;
        }

        $allAgents = $this->db->fetchAll("SELECT id, name, agent_version FROM agents WHERE agent_version IS NOT NULL");
        $outdated = array_values(array_filter($allAgents, fn($a) => $a['agent_version'] !== $bundledAgentVersion));

        $this->json([
            'bundled_version' => $bundledAgentVersion,
            'total' => count($allAgents),
            'outdated' => $outdated
        ]);
    }

    public function testSmtp(): void
    {
        $this->requireAdmin();
        $this->verifyCsrf();

        $settings = [];
        $rows = $this->db->fetchAll("SELECT `key`, `value` FROM settings WHERE `key` LIKE 'smtp_%'");
        foreach ($rows as $row) {
            $settings[$row['key']] = $row['value'];
        }

        $host = $settings['smtp_host'] ?? '';
        $port = (int) ($settings['smtp_port'] ?? 587);
        $secure = $settings['smtp_secure'] ?? \BBS\Services\Mailer::inferSecure($port);
        $user = $settings['smtp_user'] ?? '';
        $rawPass = $settings['smtp_pass'] ?? '';
        // Password is stored encrypted — decrypt before sending to AUTH LOGIN.
        // Legacy plaintext values may still exist, so fall back on decrypt failure.
        $pass = '';
        if ($rawPass !== '') {
            try {
                $pass = \BBS\Services\Encryption::decrypt($rawPass);
            } catch (\Throwable $e) {
                $pass = $rawPass;
            }
        }
        $from = $settings['smtp_from'] ?? '';

        if (empty($host)) {
            $this->json(['success' => false, 'error' => 'SMTP host is not configured.']);
            return;
        }

        try {
            $connHost = $secure === 'ssl' ? "ssl://{$host}" : $host;
            $socket = @fsockopen($connHost, $port, $errno, $errstr, 10);
            if (!$socket) {
                $this->json(['success' => false, 'error' => "Connection failed: {$errstr}"]);
                return;
            }

            $this->smtpRead($socket);
            $this->smtpCmd($socket, "EHLO " . gethostname());

            if ($secure === 'starttls') {
                $this->smtpCmd($socket, "STARTTLS");
                if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT)) {
                    fclose($socket);
                    $this->json(['success' => false, 'error' => 'TLS negotiation failed.']);
                    return;
                }
                $this->smtpCmd($socket, "EHLO " . gethostname());
            }

            if ($user) {
                $this->smtpCmd($socket, "AUTH LOGIN");
                $this->smtpCmd($socket, base64_encode($user));
                $resp = $this->smtpCmd($socket, base64_encode($pass));
                if (strpos($resp, '235') === false) {
                    fclose($socket);
                    $this->json(['success' => false, 'error' => 'Authentication failed: ' . trim($resp)]);
                    return;
                }
            }

            $this->smtpCmd($socket, "QUIT");
            fclose($socket);

            $this->json(['success' => true]);
        } catch (\Exception $e) {
            $this->json(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    private function smtpCmd($socket, string $cmd): string
    {
        fwrite($socket, $cmd . "\r\n");
        return $this->smtpRead($socket);
    }

    private function smtpRead($socket): string
    {
        $response = '';
        while ($line = fgets($socket, 515)) {
            $response .= $line;
            if (isset($line[3]) && $line[3] === ' ') break;
        }
        return $response;
    }

    public function testApprise(): void
    {
        $this->requireAdmin();
        $this->verifyCsrf();

        $apprise = new \BBS\Services\AppriseService();
        $this->json($apprise->test());
    }

    public function checkUpdate(): void
    {
        $this->requireAdmin();
        $this->verifyCsrf();

        $service = new \BBS\Services\UpdateService();
        $service->setIncludePrereleases(!empty($_POST['include_prereleases']));
        $result = $service->checkForUpdate();

        if (isset($result['error'])) {
            $this->flash('danger', 'Update check failed: ' . $result['error']);
        } elseif (!empty($result['message'])) {
            $this->flash('info', $result['message']);
        } elseif ($result['update_available']) {
            $this->flash('success', 'Update available: v' . $result['version']);
        } else {
            $this->flash('success', 'You are running the latest version (v' . $result['current'] . ').');
        }

        $this->redirect('/settings?tab=updates');
    }

    public function upgrade(): void
    {
        $this->requireAdmin();
        $this->verifyCsrf();

        $service = new \BBS\Services\UpdateService();
        $result = $service->startBackgroundUpgrade();

        if (!$result['success']) {
            $this->flash('danger', $result['error']);
            $this->redirect('/settings?tab=updates');
            return;
        }

        $this->redirect('/upgrade');
    }

    public function sync(): void
    {
        $this->requireAdmin();
        $this->verifyCsrf();

        $service = new \BBS\Services\UpdateService();
        $result = $service->startBackgroundUpgrade('main');

        if (!$result['success']) {
            $this->flash('danger', $result['error']);
            $this->redirect('/settings?tab=updates');
            return;
        }

        $this->redirect('/upgrade');
    }

    /**
     * Queue agent updates for all outdated agents.
     * POST /settings/upgrade-agents
     */
    public function upgradeAgents(): void
    {
        $this->requireAdmin();
        $this->verifyCsrf();

        // Read bundled agent version
        $serverAgentVersion = null;
        $agentFile = dirname(__DIR__, 2) . '/agent/bbs-agent.py';
        if (file_exists($agentFile)) {
            $handle = fopen($agentFile, 'r');
            if ($handle) {
                for ($i = 0; $i < 50 && ($line = fgets($handle)) !== false; $i++) {
                    if (preg_match('/^AGENT_VERSION\s*=\s*["\']([^"\']+)["\']/m', $line, $m)) {
                        $serverAgentVersion = $m[1];
                        break;
                    }
                }
                fclose($handle);
            }
        }

        if (!$serverAgentVersion) {
            $this->flash('danger', 'Could not determine bundled agent version.');
            $this->redirect('/settings?tab=updates');
        }

        // Find outdated agents
        $outdated = $this->db->fetchAll(
            "SELECT id, name FROM agents WHERE agent_version IS NOT NULL AND agent_version != ?",
            [$serverAgentVersion]
        );

        // Find agents that already have a pending update job
        $pending = $this->db->fetchAll(
            "SELECT agent_id FROM backup_jobs WHERE task_type = 'update_agent' AND status IN ('queued', 'sent', 'running')"
        );
        $pendingIds = array_column($pending, 'agent_id');

        $queued = 0;
        foreach ($outdated as $agent) {
            if (in_array($agent['id'], $pendingIds)) {
                continue;
            }
            $jobId = $this->db->insert('backup_jobs', [
                'agent_id' => $agent['id'],
                'task_type' => 'update_agent',
                'status' => 'queued',
            ]);
            $this->db->insert('server_log', [
                'agent_id' => $agent['id'],
                'backup_job_id' => $jobId,
                'level' => 'info',
                'message' => "Agent update queued (bulk) to v{$serverAgentVersion}",
            ]);
            $queued++;
        }

        if ($queued > 0) {
            $this->flash('success', "Queued agent updates for {$queued} client(s).");
        } else {
            $this->flash('info', 'No agents need updating (or updates already queued).');
        }

        $this->redirect('/settings?tab=updates');
    }

    /**
     * POST /settings/borg/sync — fetch available versions from GitHub.
     */
    public function syncBorgVersions(): void
    {
        $this->requireAdmin();
        $this->verifyCsrf();

        $service = new \BBS\Services\BorgVersionService();
        $result = $service->syncVersionsFromGitHub();

        if (isset($result['error'])) {
            $this->flash('danger', 'Sync failed: ' . $result['error']);
        } else {
            $this->flash('success', "Synced borg versions from GitHub: {$result['added']} new, {$result['skipped']} pre-release skipped.");
        }

        $this->redirect('/settings?tab=borg');
    }

    /**
     * POST /settings/borg/save — save borg update mode settings.
     */
    public function saveBorgSettings(): void
    {
        $this->requireAdmin();
        $this->verifyCsrf();

        $service = new \BBS\Services\BorgVersionService();

        $mode = $_POST['borg_update_mode'] ?? 'official';
        $serverVersion = trim($_POST['borg_server_version'] ?? '');
        $autoUpdate = !empty($_POST['borg_auto_update']);

        $service->setUpdateMode($mode);
        $service->setAutoUpdate($autoUpdate);

        if ($mode === 'server') {
            // Validate version exists in server-hosted binaries
            $serverVersions = $service->getServerVersions();
            if (!empty($serverVersion) && !in_array($serverVersion, $serverVersions)) {
                $this->flash('danger', 'Selected version not found in server-hosted binaries.');
                $this->redirect('/settings?tab=borg');
                return;
            }
            $service->setServerVersion($serverVersion);
        }

        $this->flash('success', 'Borg update settings saved.');
        $this->redirect('/settings?tab=borg');
    }

    /**
     * POST /settings/borg/update-server — update server borg binary.
     */
    public function updateServerBorg(): void
    {
        $this->requireAdmin();
        $this->verifyCsrf();

        $service = new \BBS\Services\BorgVersionService();
        $result = $service->updateServerBorgByMode();

        if ($result['success']) {
            $this->flash('success', "Server borg updated to v{$result['version']}.");
        } else {
            $this->flash('danger', "Server borg update failed: {$result['error']}");
        }

        $this->redirect('/settings?tab=borg');
    }

    /**
     * POST /settings/borg/update-all — update server + queue updates for agents.
     */
    public function updateBorgBulk(): void
    {
        $this->requireAdmin();
        $this->verifyCsrf();

        $service = new \BBS\Services\BorgVersionService();
        $mode = $service->getUpdateMode();

        // First update server
        $serverResult = $service->updateServerBorgByMode();
        $serverMsg = $serverResult['success']
            ? "Server updated to v{$serverResult['version']}."
            : "Server update failed: {$serverResult['error']}";

        // Get all agents
        $agents = $service->getAllAgentVersions();

        // Find agents that already have a pending borg update job
        $pending = $this->db->fetchAll(
            "SELECT agent_id FROM backup_jobs WHERE task_type = 'update_borg' AND status IN ('queued', 'sent', 'running')"
        );
        $pendingIds = array_column($pending, 'agent_id');

        $queued = 0;
        $skipped = 0;

        foreach ($agents as $agent) {
            // Skip if already has pending job
            if (in_array($agent['id'], $pendingIds)) {
                continue;
            }

            // In server mode, skip incompatible agents
            if ($mode === 'server') {
                $version = $service->getServerVersion();
                if (!$service->isAgentCompatibleWithServerVersion($agent, $version)) {
                    $skipped++;
                    continue;
                }
            }

            $jobId = $this->db->insert('backup_jobs', [
                'agent_id' => $agent['id'],
                'task_type' => 'update_borg',
                'status' => 'queued',
            ]);
            $this->db->insert('server_log', [
                'agent_id' => $agent['id'],
                'backup_job_id' => $jobId,
                'level' => 'info',
                'message' => "Borg update queued ({$mode} mode)",
            ]);
            $queued++;
        }

        $msg = $serverMsg;
        if ($queued > 0) {
            $msg .= " Queued updates for {$queued} client(s).";
        }
        if ($skipped > 0) {
            $msg .= " Skipped {$skipped} incompatible client(s).";
        }
        if ($queued === 0 && $skipped === 0) {
            $msg .= " All clients already up to date or have pending updates.";
        }

        $this->flash($serverResult['success'] ? 'success' : 'warning', $msg);
        $this->redirect('/settings?tab=borg');
    }

    /**
     * POST /settings/borg/update-agent/{id} — queue update for a single agent.
     */
    public function updateBorgAgent(int $id): void
    {
        $this->requireAdmin();
        $this->verifyCsrf();

        $agent = $this->db->fetchOne("SELECT id, name FROM agents WHERE id = ?", [$id]);
        if (!$agent) {
            $this->flash('danger', 'Agent not found.');
            $this->redirect('/settings?tab=borg');
            return;
        }

        // Check for pending job
        $pending = $this->db->fetchOne(
            "SELECT id FROM backup_jobs WHERE agent_id = ? AND task_type = 'update_borg' AND status IN ('queued', 'sent', 'running')",
            [$id]
        );
        if ($pending) {
            $this->flash('info', 'Update already pending for this client.');
            $this->redirect('/settings?tab=borg');
            return;
        }

        $service = new \BBS\Services\BorgVersionService();
        $mode = $service->getUpdateMode();

        $jobId = $this->db->insert('backup_jobs', [
            'agent_id' => $id,
            'task_type' => 'update_borg',
            'status' => 'queued',
        ]);
        $this->db->insert('server_log', [
            'agent_id' => $id,
            'backup_job_id' => $jobId,
            'level' => 'info',
            'message' => "Borg update queued ({$mode} mode)",
        ]);

        $this->flash('success', "Borg update queued for {$agent['name']}.");
        $this->redirect('/settings?tab=borg');
    }

    /**
     * GET /api/borg-status — returns server borg version and client versions as JSON.
     * Used for AJAX refresh on borg settings tab.
     */
    public function borgStatusJson(): void
    {
        $this->requireAdmin();

        $service = new \BBS\Services\BorgVersionService();
        $updateMode = $service->getUpdateMode();
        $serverVersion = $service->getServerVersion();

        // Get fresh server borg version (slow but runs in background via AJAX)
        $serverBorgVersion = $service->getServerBorgVersion();

        // Get all agents with borg info
        $allAgents = $service->getAllAgentVersions();

        // Check compatibility for server mode
        $agents = [];
        foreach ($allAgents as $agent) {
            $borgVer = $agent['borg_version'] ?? 'unknown';
            $installMethod = $agent['borg_install_method'] ?? 'unknown';
            $borgSource = $agent['borg_source'] ?? 'unknown';
            $osInfo = $agent['os_info'] ?? '';
            $glibcVer = $agent['glibc_version'] ?? '';

            // Format glibc version
            $glibcDisplay = '';
            if ($glibcVer && preg_match('/^glibc(\d)(\d+)$/', $glibcVer, $m)) {
                $glibcDisplay = $m[1] . '.' . $m[2];
            } elseif ($glibcVer) {
                $glibcDisplay = $glibcVer;
            }

            // Shorten os_info
            $osDisplay = $osInfo;
            if ($osInfo && preg_match('/^(.+?)\s*\(/', $osInfo, $m)) {
                $osDisplay = trim($m[1]);
            } elseif ($osInfo) {
                $osDisplay = preg_replace('/\s+(x86_64|aarch64|arm64|i686)$/i', '', $osInfo);
            }

            // Check compatibility
            $isCompatible = true;
            if ($updateMode === 'server' && !empty($serverVersion)) {
                $isCompatible = $service->isAgentCompatibleWithServerVersion($agent, $serverVersion);
            }

            $agents[] = [
                'id' => $agent['id'],
                'name' => $agent['name'],
                'borg_version' => $borgVer,
                'install_method' => $installMethod,
                'borg_source' => $borgSource,
                'os_display' => $osDisplay ?: '-',
                'glibc_display' => $glibcDisplay ?: '-',
                'is_compatible' => $isCompatible,
            ];
        }

        $this->json([
            'server_borg_version' => $serverBorgVersion,
            'update_mode' => $updateMode,
            'agents' => $agents,
        ]);
    }

    /**
     * GET /api/templates/{id} — returns template data as JSON for form pre-fill.
     */
    public function templateJson(int $id): void
    {
        $this->requireAuth();

        $template = $this->db->fetchOne("SELECT * FROM backup_templates WHERE id = ?", [$id]);
        if (!$template) {
            $this->json(['error' => 'Not found'], 404);
        }

        $this->json($template);
    }

}
