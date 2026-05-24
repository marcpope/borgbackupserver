<?php

namespace BBS\Controllers;

use BBS\Core\Controller;
use BBS\Services\Encryption;
use BBS\Services\RemoteSshService;

class RemoteSshConfigController extends Controller
{
    /**
     * Create a new remote SSH config.
     */
    public function store(): void
    {
        $this->requireAuth();
        $this->requireAdmin();
        $this->verifyCsrf();

        $name = trim($_POST['name'] ?? '');
        $provider = trim($_POST['provider'] ?? '') ?: null;
        $remoteHost = trim($_POST['remote_host'] ?? '');
        $remotePort = (int) ($_POST['remote_port'] ?? 22);
        $remoteUser = trim($_POST['remote_user'] ?? '');
        $remoteBasePath = trim($_POST['remote_base_path'] ?? './');
        $sshPrivateKey = trim($_POST['ssh_private_key'] ?? '');
        $borgRemotePath = trim($_POST['borg_remote_path'] ?? '') ?: null;
        $appendRepoName = isset($_POST['append_repo_name']) ? 1 : 0;
        $borgBaseFields = $this->borgBaseFieldsFromPost();

        if (empty($name) || empty($remoteHost) || empty($remoteUser) || empty($sshPrivateKey)) {
            $this->flash('danger', 'Name, host, user, and SSH private key are required.');
            $this->redirect('/storage-locations');
        }

        if ($remotePort < 1 || $remotePort > 65535) {
            $remotePort = 22;
        }

        if (empty($remoteBasePath)) {
            $remoteBasePath = './';
        }
        if ($provider === 'borgbase' && trim($_POST['borgbase_api_key'] ?? '') !== '' && empty($borgBaseFields['borgbase_repo_name'])) {
            $this->flash('danger', 'BorgBase repository name is required when an API key is provided.');
            $this->redirect('/storage-locations?section=wizard');
        }

        $data = [
            'name' => $name,
            'provider' => $provider,
            'remote_host' => $remoteHost,
            'remote_port' => $remotePort,
            'remote_user' => $remoteUser,
            'remote_base_path' => $remoteBasePath,
            'ssh_private_key_encrypted' => Encryption::encrypt($sshPrivateKey),
            'borg_remote_path' => $borgRemotePath,
            'append_repo_name' => $appendRepoName,
        ];
        $data = array_merge($data, $borgBaseFields);

        if ($provider === 'borgbase' && !empty($borgBaseFields['borgbase_api_key_encrypted']) && !empty($borgBaseFields['borgbase_repo_name'])) {
            $remoteSshService = new RemoteSshService();
            $apiCheck = $remoteSshService->getBorgBaseApiUsage(
                array_merge($data, ['remote_user' => $remoteUser]),
                trim($_POST['borgbase_api_key'] ?? ''),
                $borgBaseFields['borgbase_repo_name']
            );
            if (!$apiCheck['success']) {
                $this->flash('danger', 'BorgBase API check failed: ' . $apiCheck['error']);
                $this->redirect('/storage-locations?section=wizard');
            }
        }

        $id = $this->db->insert('remote_ssh_configs', $data);
        if ($provider === 'borgbase') {
            (new RemoteSshService())->refreshBorgBaseDiskUsage(array_merge($data, ['id' => $id]));
        }

        $this->db->insert('server_log', [
            'level' => 'info',
            'message' => "Remote SSH config \"{$name}\" created ({$remoteUser}@{$remoteHost})",
        ]);

        $this->flash('success', "Remote SSH host \"{$name}\" created.");
        $this->redirect('/storage-locations');
    }

    /**
     * Update an existing remote SSH config.
     */
    public function update(int $id): void
    {
        $this->requireAuth();
        $this->requireAdmin();
        $this->verifyCsrf();

        $existing = $this->db->fetchOne("SELECT * FROM remote_ssh_configs WHERE id = ?", [$id]);
        if (!$existing) {
            $this->flash('danger', 'Remote SSH config not found.');
            $this->redirect('/storage-locations');
        }

        $name = trim($_POST['name'] ?? '');
        $remoteHost = trim($_POST['remote_host'] ?? '');
        $remotePort = (int) ($_POST['remote_port'] ?? 22);
        $remoteUser = trim($_POST['remote_user'] ?? '');
        $remoteBasePath = trim($_POST['remote_base_path'] ?? './');
        $sshPrivateKey = trim($_POST['ssh_private_key'] ?? '');
        $borgRemotePath = trim($_POST['borg_remote_path'] ?? '') ?: null;
        $appendRepoName = isset($_POST['append_repo_name']) ? 1 : 0;
        $borgBaseFields = $this->borgBaseFieldsFromPost($existing);
        $isBorgBase = (($existing['provider'] ?? '') === 'borgbase') || str_contains($remoteHost, '.repo.borgbase.com');

        if (empty($name) || empty($remoteHost) || empty($remoteUser)) {
            $this->flash('danger', 'Name, host, and user are required.');
            $this->redirect('/storage-locations');
        }

        if ($remotePort < 1 || $remotePort > 65535) {
            $remotePort = 22;
        }

        if (empty($remoteBasePath)) {
            $remoteBasePath = './';
        }

        $data = [
            'name' => $name,
            'remote_host' => $remoteHost,
            'remote_port' => $remotePort,
            'remote_user' => $remoteUser,
            'remote_base_path' => $remoteBasePath,
            'borg_remote_path' => $borgRemotePath,
            'append_repo_name' => $appendRepoName,
        ];
        if ($isBorgBase) {
            $data['provider'] = 'borgbase';
        }
        $data = array_merge($data, $borgBaseFields);
        if ($isBorgBase && trim($_POST['borgbase_api_key'] ?? '') !== '' && empty($data['borgbase_repo_name'])) {
            $this->flash('danger', 'BorgBase repository name is required when an API key is provided.');
            $this->redirect('/storage-locations');
        }

        // Only update SSH key if a new one was provided
        if (!empty($sshPrivateKey)) {
            $data['ssh_private_key_encrypted'] = Encryption::encrypt($sshPrivateKey);
        }

        $borgBaseApiChanged = trim($_POST['borgbase_api_key'] ?? '') !== ''
            || ($data['borgbase_repo_name'] ?? null) !== ($existing['borgbase_repo_name'] ?? null)
            || $remoteUser !== ($existing['remote_user'] ?? '');
        if ($isBorgBase && $borgBaseApiChanged && !empty($data['borgbase_api_key_encrypted']) && !empty($data['borgbase_repo_name'])) {
            $remoteSshService = new RemoteSshService();
            $apiKey = trim($_POST['borgbase_api_key'] ?? '');
            if ($apiKey === '') {
                $apiKey = $remoteSshService->getBorgBaseApiKey($existing) ?? '';
            }
            $apiCheck = $remoteSshService->getBorgBaseApiUsage(
                array_merge($existing, $data, ['remote_user' => $remoteUser]),
                $apiKey,
                $data['borgbase_repo_name']
            );
            if (!$apiCheck['success']) {
                $this->flash('danger', 'BorgBase API check failed: ' . $apiCheck['error']);
                $this->redirect('/storage-locations');
            }
        }

        $this->db->update('remote_ssh_configs', $data, 'id = ?', [$id]);
        if ($isBorgBase) {
            (new RemoteSshService())->refreshBorgBaseDiskUsage(array_merge($existing, $data, ['id' => $id]));
        }

        $this->db->insert('server_log', [
            'level' => 'info',
            'message' => "Remote SSH config \"{$name}\" updated",
        ]);

        $this->flash('success', "Remote SSH host \"{$name}\" updated.");
        $this->redirect('/storage-locations');
    }

    /**
     * Delete a remote SSH config (blocked if repos reference it).
     */
    public function delete(int $id): void
    {
        $this->requireAuth();
        $this->requireAdmin();
        $this->verifyCsrf();

        $config = $this->db->fetchOne("SELECT * FROM remote_ssh_configs WHERE id = ?", [$id]);
        if (!$config) {
            $this->flash('danger', 'Remote SSH config not found.');
            $this->redirect('/storage-locations');
        }

        $remoteSshService = new RemoteSshService();
        $repoCount = $remoteSshService->getRepoCount($id);
        if ($repoCount > 0) {
            $this->flash('danger', "Cannot delete \"{$config['name']}\" — {$repoCount} repository/ies still use this host. Delete or migrate them first.");
            $this->redirect('/storage-locations');
        }

        $this->db->delete('remote_ssh_configs', 'id = ?', [$id]);

        $this->db->insert('server_log', [
            'level' => 'info',
            'message' => "Remote SSH config \"{$config['name']}\" deleted",
        ]);

        $this->flash('success', "Remote SSH host \"{$config['name']}\" deleted.");
        $this->redirect('/storage-locations');
    }

    /**
     * Test connection to a remote SSH host.
     */
    public function test(int $id): void
    {
        $this->requireAuth();
        $this->requireAdmin();
        $this->verifyCsrf();

        $config = $this->db->fetchOne("SELECT * FROM remote_ssh_configs WHERE id = ?", [$id]);
        if (!$config) {
            $this->json(['success' => false, 'error' => 'Config not found']);
            return;
        }

        $remoteSshService = new RemoteSshService();
        $result = $remoteSshService->testConnection($config);

        if ($result['success']) {
            $this->json(['status' => 'ok', 'version' => $result['version'] ?? '']);
        } else {
            $this->json(['status' => 'error', 'error' => $result['error'] ?? 'Connection failed']);
        }
    }

    /**
     * Test connection with raw (unsaved) config data.
     */
    public function testNew(): void
    {
        $this->requireAuth();
        $this->requireAdmin();
        $this->verifyCsrf();

        $remoteHost = trim($_POST['remote_host'] ?? '');
        $remotePort = (int) ($_POST['remote_port'] ?? 22);
        $remoteUser = trim($_POST['remote_user'] ?? '');
        $sshPrivateKey = trim($_POST['ssh_private_key'] ?? '');
        $borgRemotePath = trim($_POST['borg_remote_path'] ?? '') ?: null;

        if (empty($remoteHost) || empty($remoteUser) || empty($sshPrivateKey)) {
            $this->json(['status' => 'error', 'error' => 'Host, user, and SSH key are required']);
            return;
        }

        // Build a fake config array with the key stored as "encrypted" so
        // decryptKey() falls back to using it as-is (plaintext fallback).
        $config = [
            'remote_host' => $remoteHost,
            'remote_port' => $remotePort,
            'remote_user' => $remoteUser,
            'ssh_private_key_encrypted' => $sshPrivateKey,
            'borg_remote_path' => $borgRemotePath,
        ];

        $remoteSshService = new RemoteSshService();
        $result = $remoteSshService->testConnection($config);

        if ($result['success']) {
            $this->json(['status' => 'ok', 'version' => $result['version'] ?? '']);
        } else {
            $this->json(['status' => 'error', 'error' => $result['error'] ?? 'Connection failed']);
        }
    }

    /**
     * Test optional BorgBase API configuration against SSH user + repo name.
     */
    public function testBorgBaseApi(): void
    {
        $this->requireAuth();
        $this->requireAdmin();
        $this->verifyCsrf();

        $configId = (int) ($_POST['config_id'] ?? 0);
        $remoteUser = trim($_POST['remote_user'] ?? '');
        $repoName = trim($_POST['borgbase_repo_name'] ?? '');
        $apiKey = trim($_POST['borgbase_api_key'] ?? '');

        $config = ['remote_user' => $remoteUser];
        $remoteSshService = new RemoteSshService();
        if ($configId > 0) {
            $existing = $remoteSshService->getById($configId);
            if (!$existing) {
                $this->json(['status' => 'error', 'error' => 'Config not found']);
                return;
            }
            $config = array_merge($existing, $config);
            if ($remoteUser === '') $config['remote_user'] = $existing['remote_user'];
            if ($repoName === '') $repoName = (string) ($existing['borgbase_repo_name'] ?? '');
            if ($apiKey === '') $apiKey = $remoteSshService->getBorgBaseApiKey($existing) ?? '';
        }

        if ($apiKey === '' || trim((string) ($config['remote_user'] ?? '')) === '' || $repoName === '') {
            $this->json(['status' => 'error', 'error' => 'API key and BorgBase repository name are required']);
            return;
        }

        $result = $remoteSshService->getBorgBaseApiUsage($config, $apiKey, $repoName);
        if (!$result['success']) {
            $this->json(['status' => 'error', 'error' => $result['error'] ?? 'BorgBase API check failed']);
            return;
        }

        $repo = $result['repo'];
        $this->json([
            'status' => 'ok',
            'repo' => [
                'id' => $repo['id'] ?? '',
                'name' => $repo['name'] ?? '',
                'quota_gb' => isset($repo['quota']) ? round(((float) $repo['quota']) / 1000, 3) : null,
                'current_usage_mb' => isset($repo['currentUsage']) ? round((float) $repo['currentUsage'], 3) : null,
                'last_modified' => $repo['lastModified'] ?? null,
            ],
        ]);
    }

    private function borgBaseFieldsFromPost(?array $existing = null): array
    {
        $repoName = trim($_POST['borgbase_repo_name'] ?? '');
        $manualQuota = trim($_POST['borgbase_manual_quota_gb'] ?? '');
        $apiKey = trim($_POST['borgbase_api_key'] ?? '');
        $clearApiKey = isset($_POST['borgbase_clear_api_key']);

        $data = [
            'borgbase_repo_name' => $repoName !== '' ? $repoName : null,
            'borgbase_manual_quota_gb' => $manualQuota !== '' && is_numeric($manualQuota) && (float) $manualQuota > 0
                ? (string) round((float) $manualQuota, 3)
                : null,
        ];

        if ($apiKey !== '') {
            $data['borgbase_api_key_encrypted'] = Encryption::encrypt($apiKey);
        } elseif ($clearApiKey) {
            $data['borgbase_api_key_encrypted'] = null;
        } elseif ($existing && array_key_exists('borgbase_api_key_encrypted', $existing)) {
            $data['borgbase_api_key_encrypted'] = $existing['borgbase_api_key_encrypted'];
        }

        return $data;
    }
}
