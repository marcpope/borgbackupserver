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
     * In hosted mode, storage-class plugin configs (s3_sync) are managed
     * by the platform — customers can toggle per-repo sync but must not
     * be able to create, edit, delete, or test the configs themselves
     * (which would expose / let them write credentials). The plugins tab
     * already hides them in the UI; this guard handles direct POSTs.
     */
    private function denyIfHostedStoragePluginById(int $pluginId): void
    {
        if (!\BBS\Core\Config::isHosted()) return;
        $row = $this->db->fetchOne("SELECT slug FROM plugins WHERE id = ?", [$pluginId]);
        if ($row && in_array($row['slug'] ?? '', ['s3_sync'], true)) {
            $this->json(['error' => 'Storage plugin configuration is managed by the platform.'], 403);
        }
    }

    private function denyIfHostedStoragePluginByConfig(int $configId): void
    {
        if (!\BBS\Core\Config::isHosted()) return;
        $row = $this->db->fetchOne(
            "SELECT p.slug FROM plugin_configs pc JOIN plugins p ON p.id = pc.plugin_id WHERE pc.id = ?",
            [$configId]
        );
        if ($row && in_array($row['slug'] ?? '', ['s3_sync'], true)) {
            $this->json(['error' => 'Storage plugin configuration is managed by the platform.'], 403);
        }
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

        $this->denyIfHostedStoragePluginById($pluginId);

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

        $this->denyIfHostedStoragePluginByConfig($configId);

        $name = trim($_POST['name'] ?? '');
        $config = $_POST['plugin_config'] ?? [];

        if (empty($name)) {
            $this->flash('danger', 'Name is required.');
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

        $this->denyIfHostedStoragePluginByConfig($configId);

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

        $this->denyIfHostedStoragePluginByConfig($configId);

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
