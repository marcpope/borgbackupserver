<?php

namespace BBS\Controllers;

use BBS\Core\Controller;
use BBS\Services\BorgBaseService;
use BBS\Services\Encryption;
use BBS\Services\RemoteSshService;

/**
 * A BorgBase account groups the per-repo storage locations BorgBase forces
 * on us (one SSH user per repository) and, with a Full Access token, lets
 * the admin create and delete repositories there from BBS.
 */
class BorgBaseAccountController extends Controller
{
    private function guard(): void
    {
        $this->denyIfHosted();
        $this->requireAuth();
        $this->requireAdmin();
    }

    private function accountOr404(int $id): array
    {
        $account = (new BorgBaseService())->getById($id);
        if (!$account) {
            $this->flash('danger', 'BorgBase account not found.');
            $this->redirect('/storage-locations');
        }
        return $account;
    }

    /**
     * Create an account from a name and an API token.
     */
    public function store(): void
    {
        $this->guard();
        $this->verifyCsrf();

        $name = trim($_POST['name'] ?? '') ?: 'BorgBase';
        $token = trim($_POST['api_token'] ?? '');
        if ($token === '') {
            $this->flash('danger', 'Paste the API token from BorgBase (Account → API → New Token).');
            $this->redirect('/storage-locations?section=wizard');
        }

        $service = new BorgBaseService();
        $result = $service->createAccount($name, $token);
        if (!$result['success']) {
            $this->flash('danger', 'BorgBase rejected the token: ' . $result['error']);
            $this->redirect('/storage-locations?section=wizard');
        }

        $this->db->insert('server_log', [
            'level' => 'info',
            'message' => "BorgBase account \"{$name}\" added",
        ]);
        if (!empty($result['warning'])) {
            $this->flash('warning', $result['warning']);
        } else {
            $this->flash('success', "BorgBase account \"{$name}\" added. BBS registered its SSH key on the account.");
        }
        $this->redirect('/borgbase-accounts/' . $result['id']);
    }

    /**
     * Account page: plan, usage, one line per repository, plus repos on
     * BorgBase that BBS doesn't know about yet.
     */
    public function show(int $id): void
    {
        $this->guard();
        $account = $this->accountOr404($id);
        $service = new BorgBaseService();

        // One API call: refreshes plan and usage, attaches any locations that
        // turned up, and gives us the repo list for the import section.
        $remoteRepos = [];
        $refreshError = null;
        $refresh = $service->refreshAccount($id);
        if ($refresh['success']) {
            $remoteRepos = $refresh['overview']['repos'];
            $account = $service->getById($id);
        } else {
            $refreshError = $refresh['error'];
        }

        $locations = $service->getLocations($id);
        $configuredUsers = array_map(fn($l) => (string) $l['remote_user'], $locations);
        $unconfigured = array_values(array_filter(
            $remoteRepos,
            fn($r) => !in_array((string) $r['id'], $configuredUsers, true)
        ));
        $usageByUser = [];
        foreach ($remoteRepos as $r) {
            $usageByUser[(string) $r['id']] = $r;
        }

        $agents = $this->db->fetchAll("SELECT id, name FROM agents ORDER BY name");
        $unattached = $service->getUnattachedLocations();

        $repoLimit = (int) ($account['plan_max_repos'] ?? 0);
        $repoCount = (int) ($account['remote_repo_count'] ?? count($locations));
        $canCreate = !empty($account['api_token_encrypted'])
            && !empty($account['borgbase_ssh_key_id'])
            && ($repoLimit <= 0 || $repoCount < $repoLimit);

        $this->view('storage-locations/borgbase', [
            'pageTitle' => $account['name'],
            'account' => $account,
            'locations' => $locations,
            'remoteRepos' => $usageByUser,
            'unconfigured' => $unconfigured,
            'unattached' => $unattached,
            'agents' => $agents,
            'regions' => BorgBaseService::REGIONS,
            'refreshError' => $refreshError,
            'canCreate' => $canCreate,
            'repoLimit' => $repoLimit,
            'repoCount' => $repoCount,
        ]);
    }

    public function refresh(int $id): void
    {
        $this->guard();
        $this->verifyCsrf();
        $this->accountOr404($id);
        $result = (new BorgBaseService())->refreshAccount($id);
        if ($result['success']) {
            $this->flash('success', 'Account refreshed from BorgBase.');
        } else {
            $this->flash('danger', 'Refresh failed: ' . $result['error']);
        }
        $this->redirect('/borgbase-accounts/' . $id);
    }

    /**
     * Rename, or replace the token.
     */
    public function update(int $id): void
    {
        $this->guard();
        $this->verifyCsrf();
        $account = $this->accountOr404($id);
        $service = new BorgBaseService();

        $name = trim($_POST['name'] ?? '');
        if ($name !== '' && $name !== $account['name']) {
            $this->db->update('borgbase_accounts', ['name' => $name], 'id = ?', [$id]);
        }

        $token = trim($_POST['api_token'] ?? '');
        if ($token !== '') {
            $result = $service->updateToken($id, $token);
            if (!$result['success']) {
                $this->flash('danger', 'BorgBase rejected the token: ' . $result['error']);
                $this->redirect('/borgbase-accounts/' . $id);
            }
            if (!empty($result['warning'])) {
                $this->flash('warning', $result['warning']);
                $this->redirect('/borgbase-accounts/' . $id);
            }
        }

        $this->flash('success', 'Account updated.');
        $this->redirect('/borgbase-accounts/' . $id);
    }

    /**
     * Remove the account from BBS. Its storage locations stay, detached.
     */
    public function delete(int $id): void
    {
        $this->guard();
        $this->verifyCsrf();
        $account = $this->accountOr404($id);
        (new BorgBaseService())->deleteAccount($id);
        $this->db->insert('server_log', [
            'level' => 'info',
            'message' => "BorgBase account \"{$account['name']}\" removed (its storage locations were kept)",
        ]);
        $this->flash('success', "BorgBase account \"{$account['name']}\" removed. Its storage locations were kept.");
        $this->redirect('/storage-locations');
    }

    /**
     * Create a repository on BorgBase, a storage location for it, and the
     * client's repository on top, in one go. The client is fixed here; there
     * is no reassigning later.
     */
    public function createRepo(int $id): void
    {
        $this->guard();
        $this->verifyCsrf();
        $account = $this->accountOr404($id);
        $service = new BorgBaseService();
        $back = '/borgbase-accounts/' . $id;

        $agentId = (int) ($_POST['agent_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $region = trim($_POST['region'] ?? 'eu');
        $quotaRaw = trim($_POST['quota_gb'] ?? '');
        $quotaGb = $quotaRaw !== '' && is_numeric($quotaRaw) && (float) $quotaRaw > 0 ? (float) $quotaRaw : null;
        $encryption = $_POST['encryption'] ?? 'repokey-blake2';
        if (!in_array($encryption, ['repokey-blake2', 'repokey', 'none'], true)) {
            $encryption = 'repokey-blake2';
        }

        $agent = $agentId ? $this->db->fetchOne("SELECT * FROM agents WHERE id = ?", [$agentId]) : null;
        if (!$agent) {
            $this->flash('danger', 'Pick the client this repository belongs to.');
            $this->redirect($back);
        }
        if ($name === '') {
            $this->flash('danger', 'Give the repository a name.');
            $this->redirect($back);
        }

        $limit = (int) ($account['plan_max_repos'] ?? 0);
        $count = (int) ($account['remote_repo_count'] ?? 0);
        if ($limit > 0 && $count >= $limit) {
            $this->flash('danger', "This BorgBase plan allows {$limit} repositories and all are in use.");
            $this->redirect($back);
        }

        $created = $service->createRepo($account, $name, $region, $quotaGb);
        if (!$created['success']) {
            $this->flash('danger', 'BorgBase could not create the repository: ' . $created['error']);
            $this->redirect($back);
        }
        $locationId = (int) $created['location_id'];
        $repoId = (string) $created['repo']['id'];

        // Initialise borg on it. A new BorgBase repo can take a moment to
        // accept its first SSH login, so give it a couple of tries.
        $remoteSshService = new RemoteSshService();
        $config = $remoteSshService->getById($locationId);
        $safeName = $this->sanitizePathName($name);
        $repoPath = $remoteSshService->buildRepoPath($config, $safeName);
        $passphrase = $encryption !== 'none' ? $this->generatePassphrase() : '';

        $init = ['success' => false, 'output' => ''];
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $init = $remoteSshService->initRepo($config, $repoPath, $encryption, $passphrase);
            if ($init['success']) {
                break;
            }
            sleep(3);
        }
        if (!$init['success']) {
            // Roll back so a failed init doesn't leave an empty repo counting
            // against the plan's limit.
            $errorMsg = trim((string) ($init['stderr'] ?? $init['output'] ?? 'unknown error'));
            $this->db->delete('remote_ssh_configs', 'id = ?', [$locationId]);
            $rollback = $service->deleteRemoteRepo($account, $repoId);
            $this->db->insert('server_log', [
                'agent_id' => $agentId,
                'level' => 'error',
                'message' => "borg init failed on new BorgBase repo \"{$name}\" ({$repoId}): {$errorMsg}"
                    . ($rollback['success'] ? ' — removed it from BorgBase again' : ' — could not remove it from BorgBase: ' . $rollback['error']),
            ]);
            $this->flash('danger', "BorgBase created the repository but borg init failed: {$errorMsg}");
            $this->redirect($back);
        }

        $this->db->insert('repositories', [
            'agent_id' => $agentId,
            'storage_type' => 'remote_ssh',
            'remote_ssh_config_id' => $locationId,
            'name' => $safeName,
            'path' => $repoPath,
            'encryption' => $encryption,
            'passphrase_encrypted' => $encryption !== 'none' ? Encryption::encrypt($passphrase) : null,
        ]);
        $this->db->insert('server_log', [
            'agent_id' => $agentId,
            'level' => 'info',
            'message' => "Repository \"{$safeName}\" created on BorgBase ({$repoId}, {$region}) for {$agent['name']} and initialized ({$encryption})",
        ]);
        $service->refreshAccount($id);

        $this->flash('success', "Repository \"{$name}\" created on BorgBase for {$agent['name']}.");
        $this->redirect($back);
    }

    /**
     * Add a storage location for a repository that exists on BorgBase but
     * not in BBS. The client's repository is then imported from its Repos
     * tab, which asks for the passphrase.
     */
    public function importRepo(int $id): void
    {
        $this->guard();
        $this->verifyCsrf();
        $account = $this->accountOr404($id);
        $repoId = trim($_POST['repo_id'] ?? '');
        $service = new BorgBaseService();
        $result = $service->importRepo($account, $repoId);
        if (!$result['success']) {
            $this->flash('danger', 'Could not add the repository: ' . $result['error']);
        } else {
            $this->db->insert('server_log', [
                'level' => 'info',
                'message' => "BorgBase repository \"{$result['repo']['name']}\" ({$repoId}) added as a storage location",
            ]);
            $this->flash('success', "Storage location added for \"{$result['repo']['name']}\". Import the repository from the client's Repos tab to start using it.");
            $service->refreshAccount($id);
        }
        $this->redirect('/borgbase-accounts/' . $id);
    }

    /**
     * Delete a storage location on this account. Deleting the repository
     * on BorgBase as well is opt-in and irreversible.
     */
    public function deleteLocation(int $id, int $locationId): void
    {
        $this->guard();
        $this->verifyCsrf();
        $account = $this->accountOr404($id);
        $back = '/borgbase-accounts/' . $id;

        $config = $this->db->fetchOne(
            "SELECT * FROM remote_ssh_configs WHERE id = ? AND borgbase_account_id = ?",
            [$locationId, $id]
        );
        if (!$config) {
            $this->flash('danger', 'Storage location not found on this account.');
            $this->redirect($back);
        }

        $repoCount = (new RemoteSshService())->getRepoCount($locationId);
        if ($repoCount > 0) {
            $this->flash('danger', "\"{$config['name']}\" still has a repository on it. Delete that from the client's Repos tab first.");
            $this->redirect($back);
        }

        $deleteRemote = !empty($_POST['delete_remote']);
        if ($deleteRemote) {
            $result = (new BorgBaseService())->deleteRemoteRepo($account, (string) $config['remote_user']);
            if (!$result['success']) {
                $this->flash('danger', 'BorgBase refused to delete the repository, so nothing was removed: ' . $result['error']);
                $this->redirect($back);
            }
        }

        $this->db->delete('remote_ssh_configs', 'id = ?', [$locationId]);
        $this->db->insert('server_log', [
            'level' => 'info',
            'message' => "BorgBase storage location \"{$config['name']}\" deleted"
                . ($deleteRemote ? " and repository {$config['remote_user']} deleted on BorgBase" : ''),
        ]);
        $this->flash('success', "\"{$config['name']}\" removed" . ($deleteRemote ? ' and deleted on BorgBase.' : '. The repository still exists on BorgBase.'));
        (new BorgBaseService())->refreshAccount($id);
        $this->redirect($back);
    }

    private function sanitizePathName(string $name): string
    {
        $slug = mb_strtolower($name, 'UTF-8');
        $slug = preg_replace('/[^a-z0-9_-]+/', '-', $slug);
        $slug = preg_replace('/-{2,}/', '-', $slug);
        $slug = trim($slug, '-');
        return $slug ?: 'repo';
    }

    private function generatePassphrase(): string
    {
        $segments = [];
        for ($i = 0; $i < 5; $i++) {
            $segments[] = strtoupper(substr(bin2hex(random_bytes(3)), 0, 4));
        }
        return implode('-', $segments);
    }
}
