<?php

namespace BBS\Controllers;

use BBS\Core\Controller;
use BBS\Services\PluginManager;
use BBS\Services\S3SyncService;

class PluginConfigController extends Controller
{
    private function getAgent(int $id): ?array
    {
        $agent = $this->db->fetchOne("SELECT * FROM agents WHERE id = ?", [$id]);
        if (!$agent || (!$this->isAdmin() && $agent['user_id'] != $_SESSION['user_id'])) {
            return null;
        }
        return $agent;
    }

    /**
     * In hosted mode, S3 sync plugin configs must use the platform's
     * managed credentials — customers can still create / edit / attach
     * configs (needed to toggle per-repo sync), but the credentials
     * source is locked to 'global' and any custom endpoint/region/
     * bucket/access_key/secret_key fields they POST are stripped.
     * Path prefix and bandwidth limit are legitimate per-config knobs
     * and pass through.
     *
     * Returns the sanitized config array. For non-S3 plugins, returns
     * the input unchanged. For non-hosted deployments, also unchanged.
     */
    private function sanitizeHostedConfig(int $pluginId, array $config): array
    {
        if (!\BBS\Core\Config::isHosted()) return $config;
        $row = $this->db->fetchOne("SELECT slug FROM plugins WHERE id = ?", [$pluginId]);
        if (!$row || ($row['slug'] ?? '') !== 's3_sync') return $config;

        $config['credential_source'] = 'global';
        foreach (['endpoint', 'region', 'bucket', 'access_key', 'secret_key'] as $field) {
            unset($config[$field]);
        }
        return $config;
    }

    private function sanitizeHostedConfigByConfigId(int $configId, array $config): array
    {
        if (!\BBS\Core\Config::isHosted()) return $config;
        $row = $this->db->fetchOne(
            "SELECT p.id AS plugin_id FROM plugin_configs pc JOIN plugins p ON p.id = pc.plugin_id WHERE pc.id = ?",
            [$configId]
        );
        if (!$row) return $config;
        return $this->sanitizeHostedConfig((int) $row['plugin_id'], $config);
    }

    /**
     * Create a named plugin config.
     * POST /clients/{id}/plugin-configs
     */
    public function store(int $id): void
    {
        $this->requireAuth();
        $this->verifyCsrf();

        if (!$this->getAgent($id)) {
            $this->flash('danger', 'Access denied.');
            $this->redirect('/clients');
        }

        $pluginId = (int) ($_POST['plugin_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $config = $_POST['plugin_config'] ?? [];

        if (empty($name) || empty($pluginId)) {
            $this->flash('danger', 'Name and plugin are required.');
            $this->redirect("/clients/{$id}?tab=plugins");
        }

        $config = $this->sanitizeHostedConfig($pluginId, $config);

        // Duplicate names hit the unique_agent_config_name key — catch it
        // up front instead of surfacing a raw PDOException
        $duplicate = $this->db->fetchOne(
            "SELECT id FROM plugin_configs WHERE agent_id = ? AND plugin_id = ? AND name = ?",
            [$id, $pluginId, $name]
        );
        if ($duplicate) {
            $this->flash('danger', "A configuration named \"{$name}\" already exists for this plugin. Choose a different name.");
            $this->redirect("/clients/{$id}?tab=plugins");
        }

        $pluginManager = new PluginManager();
        $pluginManager->savePluginConfig($id, $pluginId, $name, $config);

        // Warn if S3 config uses global credentials but globals are empty
        $plugin = $this->db->fetchOne("SELECT slug FROM plugins WHERE id = ?", [$pluginId]);
        if ($plugin && $plugin['slug'] === 's3_sync' && ($config['credential_source'] ?? 'global') === 'global') {
            $bucket = $this->db->fetchOne("SELECT `value` FROM settings WHERE `key` = 's3_bucket'");
            if (empty($bucket['value'])) {
                $this->flash('warning', "S3 config saved, but Global S3 Settings are not configured yet. Go to <a href='/settings#s3'>Settings &rarr; S3</a> to set them up.");
                $this->redirect("/clients/{$id}?tab=plugins");
            }
        }

        $this->flash('success', "Plugin configuration \"{$name}\" created.");
        $this->redirect("/clients/{$id}?tab=plugins");
    }

    /**
     * Update a named plugin config.
     * POST /clients/{id}/plugin-configs/{configId}/edit
     */
    public function update(int $id, int $configId): void
    {
        $this->requireAuth();
        $this->verifyCsrf();

        if (!$this->getAgent($id)) {
            $this->flash('danger', 'Access denied.');
            $this->redirect('/clients');
        }

        $name = trim($_POST['name'] ?? '');
        $config = $_POST['plugin_config'] ?? [];

        if (empty($name)) {
            $this->flash('danger', 'Name is required.');
            $this->redirect("/clients/{$id}?tab=plugins");
        }

        $config = $this->sanitizeHostedConfigByConfigId($configId, $config);

        // Renaming to another config's name would hit the same unique key
        $duplicate = $this->db->fetchOne(
            "SELECT pc.id FROM plugin_configs pc
             JOIN plugin_configs self ON self.id = ?
             WHERE pc.agent_id = self.agent_id AND pc.plugin_id = self.plugin_id
               AND pc.name = ? AND pc.id != self.id",
            [$configId, $name]
        );
        if ($duplicate) {
            $this->flash('danger', "A configuration named \"{$name}\" already exists for this plugin. Choose a different name.");
            $this->redirect("/clients/{$id}?tab=plugins");
        }

        $pluginManager = new PluginManager();
        $pluginManager->updatePluginConfig($configId, $name, $config);

        $this->flash('success', "Plugin configuration \"{$name}\" updated.");
        $this->redirect("/clients/{$id}?tab=plugins");
    }

    /**
     * Delete a named plugin config.
     * POST /clients/{id}/plugin-configs/{configId}/delete
     */
    public function delete(int $id, int $configId): void
    {
        $this->requireAuth();
        $this->verifyCsrf();

        if (!$this->getAgent($id)) {
            $this->flash('danger', 'Access denied.');
            $this->redirect('/clients');
        }

        // Check if this S3 config is in use by any repository
        $inUse = $this->db->fetchOne("
            SELECT r.name FROM repository_s3_configs rsc
            JOIN repositories r ON r.id = rsc.repository_id
            WHERE rsc.plugin_config_id = ?
            LIMIT 1
        ", [$configId]);

        if ($inUse) {
            $this->flash('danger', "Cannot delete — this S3 config is in use by repository \"{$inUse['name']}\". Disable S3 sync on the repository first.");
            $this->redirect("/clients/{$id}?tab=plugins");
        }

        $pluginManager = new PluginManager();
        $pluginManager->deletePluginConfig($configId);

        $this->flash('success', 'Plugin configuration deleted.');
        $this->redirect("/clients/{$id}?tab=plugins");
    }

    /**
     * Queue a plugin test job.
     * POST /clients/{id}/plugin-configs/{configId}/test
     */
    public function test(int $id, int $configId): void
    {
        $this->requireAuth();
        // Session-authenticated POST must verify CSRF — same-origin isn't
        // enforced by browsers for cross-origin form POSTs.
        $this->verifyCsrf();

        if (!$this->getAgent($id)) {
            $this->json(['error' => 'Access denied'], 403);
        }

        // Check if this is an S3 plugin config — test runs server-side (rclone is on the server)
        $pluginSlug = $this->db->fetchOne("
            SELECT p.slug FROM plugin_configs pc
            JOIN plugins p ON p.id = pc.plugin_id
            WHERE pc.id = ?
        ", [$configId]);

        if ($pluginSlug && $pluginSlug['slug'] === 's3_sync') {
            $config = $this->db->fetchOne("SELECT config FROM plugin_configs WHERE id = ?", [$configId]);
            $configData = json_decode($config['config'] ?? '{}', true) ?: [];

            $s3Service = new S3SyncService();
            $creds = $s3Service->resolveCredentials($configData);
            $result = $s3Service->testConnection($creds);

            if ($result['success']) {
                $this->json(['status' => 'completed', 'message' => "S3 connection successful. Bucket: {$creds['bucket']}"]);
            } else {
                $this->json(['status' => 'failed', 'error' => $result['error']]);
            }
            return;
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

        $this->json(['status' => 'ok', 'job_id' => $jobId]);
    }

    /**
     * Poll test job status.
     * GET /clients/{id}/plugin-configs/{configId}/test-status
     */
    public function testStatus(int $id, int $configId): void
    {
        $this->requireAuth();

        if (!$this->getAgent($id)) {
            $this->json(['error' => 'Access denied'], 403);
        }

        // Get the latest plugin_test job for this config
        $job = $this->db->fetchOne("
            SELECT id, status, error_log, completed_at
            FROM backup_jobs
            WHERE agent_id = ? AND task_type = 'plugin_test' AND plugin_config_id = ?
            ORDER BY queued_at DESC LIMIT 1
        ", [$id, $configId]);

        if (!$job) {
            $this->json(['status' => 'not_found']);
        }

        $response = ['status' => $job['status']];

        if ($job['status'] === 'failed') {
            $response['error'] = $job['error_log'] ?? 'Unknown error';
        } elseif ($job['status'] === 'completed') {
            // Get output from server_log (agent sends output_log which is stored there)
            $log = $this->db->fetchOne("
                SELECT message FROM server_log
                WHERE backup_job_id = ? AND message LIKE 'Plugin test output:%'
                ORDER BY id DESC LIMIT 1
            ", [$job['id']]);
            $response['message'] = $log
                ? str_replace('Plugin test output: ', '', $log['message'])
                : 'Test completed successfully.';
        }

        $this->json($response);
    }
}
