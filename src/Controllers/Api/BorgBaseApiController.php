<?php

namespace BBS\Controllers\Api;

use BBS\Core\Controller;
use BBS\Services\BorgBaseService;

/**
 * Token-authenticated BorgBase account management, mirroring
 * BorgBaseAccountController for the mobile app. Same service, same rules;
 * only the transport differs.
 *
 * The API token is write-only: it is accepted on create and update and
 * never returned. `api_token_set` says whether one is stored.
 */
class BorgBaseApiController extends Controller
{
    private function getJsonInput(): array
    {
        $raw = file_get_contents('php://input');
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }

    private function guard(): void
    {
        $this->denyIfHosted();
        $this->requireApiToken();
    }

    private function accountOr404(BorgBaseService $service, int $id): array
    {
        $account = $service->getById($id);
        if (!$account) {
            $this->json(['error' => 'BorgBase account not found'], 404);
        }
        return $account;
    }

    /** GET /api/v1/borgbase-accounts */
    public function index(): void
    {
        $this->guard();
        $service = new BorgBaseService();
        $accounts = array_map(fn($a) => $this->accountPayload($a, $service), $service->getAll());
        $this->json(['borgbase_accounts' => $accounts]);
    }

    /** POST /api/v1/borgbase-accounts — {api_token, name?} */
    public function create(): void
    {
        $this->guard();
        $input = $this->getJsonInput();
        $token = trim((string) ($input['api_token'] ?? ''));
        $name = trim((string) ($input['name'] ?? '')) ?: 'BorgBase';
        if ($token === '') {
            $this->json(['error' => 'api_token is required'], 422);
        }

        $service = new BorgBaseService();
        $result = $service->createAccount($name, $token);
        if (!$result['success']) {
            $this->json(['error' => 'BorgBase rejected the token: ' . $result['error']], 422);
        }
        $this->db->insert('server_log', [
            'level' => 'info',
            'message' => "BorgBase account \"{$name}\" added via API",
        ]);
        $payload = $this->accountPayload($service->getById($result['id']), $service);
        if (!empty($result['warning'])) {
            $payload['warning'] = $result['warning'];
        }
        $this->json($payload, 201);
    }

    /**
     * GET /api/v1/borgbase-accounts/{id}[?refresh=0]
     *
     * Refreshes from BorgBase first (one API call) unless refresh=0, so the
     * page shows live usage and the repos not yet in BBS.
     */
    public function show(int $id): void
    {
        $this->guard();
        $service = new BorgBaseService();
        $account = $this->accountOr404($service, $id);

        $remoteRepos = [];
        $refreshError = null;
        if (($_GET['refresh'] ?? '1') !== '0') {
            $refresh = $service->refreshAccount($id);
            if ($refresh['success']) {
                $remoteRepos = $refresh['overview']['repos'];
                $account = $service->getById($id);
            } else {
                $refreshError = $refresh['error'];
            }
        }

        $locations = $service->getLocations($id);
        $byUser = [];
        foreach ($remoteRepos as $r) {
            $byUser[(string) $r['id']] = $r;
        }
        $configured = array_map(fn($l) => (string) $l['remote_user'], $locations);

        $payload = $this->accountPayload($account, $service);
        $payload['refresh_error'] = $refreshError;
        $payload['locations'] = array_map(fn($l) => $this->locationPayload($l, $byUser[$l['remote_user']] ?? null), $locations);
        $payload['unconfigured_repos'] = array_values(array_map(
            fn($r) => $this->remoteRepoPayload($r),
            array_filter($remoteRepos, fn($r) => !in_array((string) $r['id'], $configured, true))
        ));
        $payload['regions'] = BorgBaseService::REGIONS;
        $this->json($payload);
    }

    /** PUT /api/v1/borgbase-accounts/{id} — {name?, api_token?} */
    public function update(int $id): void
    {
        $this->guard();
        $input = $this->getJsonInput();
        $service = new BorgBaseService();
        $account = $this->accountOr404($service, $id);

        $name = trim((string) ($input['name'] ?? ''));
        if ($name !== '' && $name !== $account['name']) {
            $this->db->update('borgbase_accounts', ['name' => $name], 'id = ?', [$id]);
        }

        $warning = null;
        $token = trim((string) ($input['api_token'] ?? ''));
        if ($token !== '') {
            $result = $service->updateToken($id, $token);
            if (!$result['success']) {
                $this->json(['error' => 'BorgBase rejected the token: ' . $result['error']], 422);
            }
            $warning = $result['warning'] ?? null;
        }

        $payload = $this->accountPayload($service->getById($id), $service);
        if ($warning) {
            $payload['warning'] = $warning;
        }
        $this->json($payload);
    }

    /** DELETE /api/v1/borgbase-accounts/{id} — locations are kept, detached */
    public function delete(int $id): void
    {
        $this->guard();
        $service = new BorgBaseService();
        $account = $this->accountOr404($service, $id);
        $service->deleteAccount($id);
        $this->db->insert('server_log', [
            'level' => 'info',
            'message' => "BorgBase account \"{$account['name']}\" removed via API (its storage locations were kept)",
        ]);
        $this->json(['status' => 'ok', 'deleted' => $id]);
    }

    /** POST /api/v1/borgbase-accounts/{id}/refresh */
    public function refresh(int $id): void
    {
        $this->guard();
        $service = new BorgBaseService();
        $this->accountOr404($service, $id);
        $result = $service->refreshAccount($id);
        if (!$result['success']) {
            $this->json(['error' => $result['error']], 502);
        }
        $this->json($this->accountPayload($service->getById($id), $service));
    }

    /**
     * POST /api/v1/borgbase-accounts/{id}/repos
     *   {agent_id, name, region?, quota_gb?, encryption?}
     *
     * Creates the repository on BorgBase, initialises borg on it and creates
     * the client's repository. The client is fixed at creation.
     */
    public function createRepo(int $id): void
    {
        $this->guard();
        $input = $this->getJsonInput();
        $service = new BorgBaseService();
        $account = $this->accountOr404($service, $id);

        $agentId = (int) ($input['agent_id'] ?? 0);
        $name = trim((string) ($input['name'] ?? ''));
        $region = trim((string) ($input['region'] ?? 'eu'));
        $quotaGb = isset($input['quota_gb']) && is_numeric($input['quota_gb']) && (float) $input['quota_gb'] > 0
            ? (float) $input['quota_gb'] : null;
        $encryption = (string) ($input['encryption'] ?? 'repokey-blake2');

        $agent = $agentId ? $this->db->fetchOne("SELECT * FROM agents WHERE id = ?", [$agentId]) : null;
        if (!$agent) {
            $this->json(['error' => 'agent_id must name an existing client'], 422);
        }
        if ($name === '') {
            $this->json(['error' => 'name is required'], 422);
        }
        if (!isset(BorgBaseService::REGIONS[$region])) {
            $this->json(['error' => 'region must be one of: ' . implode(', ', array_keys(BorgBaseService::REGIONS))], 422);
        }
        if (!in_array($encryption, ['repokey-blake2', 'repokey', 'none'], true)) {
            $this->json(['error' => 'encryption must be repokey-blake2, repokey or none'], 422);
        }

        $result = $service->provisionRepository($account, $agent, $name, $region, $quotaGb, $encryption);
        if (!$result['success']) {
            $limitHit = str_contains($result['error'], 'all are in use');
            $this->json(['error' => $result['error']], $limitHit ? 409 : 502);
        }

        $this->json([
            'status' => 'ok',
            'repository_id' => (int) $result['repository_id'],
            'location_id' => (int) $result['location_id'],
            'agent_id' => $agentId,
            'path' => $result['path'],
            'borgbase_repo' => $this->remoteRepoPayload($result['repo']),
        ], 201);
    }

    /**
     * POST /api/v1/borgbase-accounts/{id}/repos/import — {repo_id}
     *
     * Adds a storage location for a repository that exists on BorgBase.
     * The client's repository is then imported through the existing
     * repository import endpoint, which takes the passphrase.
     */
    public function importRepo(int $id): void
    {
        $this->guard();
        $input = $this->getJsonInput();
        $service = new BorgBaseService();
        $account = $this->accountOr404($service, $id);

        $repoId = trim((string) ($input['repo_id'] ?? ''));
        if ($repoId === '') {
            $this->json(['error' => 'repo_id is required'], 422);
        }
        $result = $service->importRepo($account, $repoId);
        if (!$result['success']) {
            $code = str_contains($result['error'], 'already exists') ? 409 : 422;
            $this->json(['error' => $result['error']], $code);
        }
        $this->db->insert('server_log', [
            'level' => 'info',
            'message' => "BorgBase repository \"{$result['repo']['name']}\" ({$repoId}) added as a storage location via API",
        ]);
        $service->refreshAccount($id);
        $location = $this->db->fetchOne("SELECT * FROM remote_ssh_configs WHERE id = ?", [$result['location_id']]);
        $this->json([
            'status' => 'ok',
            'location' => $this->locationPayload($location + ['repo_count' => 0, 'agent_id' => null, 'agent_name' => null, 'repository_id' => null, 'repository_name' => null], $result['repo']),
        ], 201);
    }

    /**
     * DELETE /api/v1/borgbase-accounts/{id}/locations/{locationId}
     *   body {delete_remote: bool} or ?delete_remote=1
     *
     * Without delete_remote the repository stays on BorgBase.
     */
    public function deleteLocation(int $id, int $locationId): void
    {
        $this->guard();
        $input = $this->getJsonInput();
        $service = new BorgBaseService();
        $account = $this->accountOr404($service, $id);

        $config = $this->db->fetchOne(
            "SELECT * FROM remote_ssh_configs WHERE id = ? AND borgbase_account_id = ?",
            [$locationId, $id]
        );
        if (!$config) {
            $this->json(['error' => 'Storage location not found on this account'], 404);
        }
        $repos = $this->db->fetchAll("SELECT id, name FROM repositories WHERE remote_ssh_config_id = ?", [$locationId]);
        if ($repos) {
            $this->json([
                'error' => 'The location still has a repository on it. Delete the client\'s repository first.',
                'repositories' => array_map(fn($r) => ['id' => (int) $r['id'], 'name' => $r['name']], $repos),
            ], 409);
        }

        $deleteRemote = filter_var($input['delete_remote'] ?? ($_GET['delete_remote'] ?? false), FILTER_VALIDATE_BOOLEAN);
        if ($deleteRemote) {
            $result = $service->deleteRemoteRepo($account, (string) $config['remote_user']);
            if (!$result['success']) {
                $this->json(['error' => 'BorgBase refused to delete the repository, so nothing was removed: ' . $result['error']], 502);
            }
        }
        $this->db->delete('remote_ssh_configs', 'id = ?', [$locationId]);
        $this->db->insert('server_log', [
            'level' => 'info',
            'message' => "BorgBase storage location \"{$config['name']}\" deleted via API"
                . ($deleteRemote ? " and repository {$config['remote_user']} deleted on BorgBase" : ''),
        ]);
        $service->refreshAccount($id);
        $this->json(['status' => 'ok', 'deleted' => $locationId, 'deleted_on_borgbase' => $deleteRemote]);
    }

    // ── Payloads ──────────────────────────────────────────────────

    private function accountPayload(array $a, BorgBaseService $service): array
    {
        $counts = $this->db->fetchOne("
            SELECT
              (SELECT COUNT(*) FROM remote_ssh_configs WHERE borgbase_account_id = ?) AS location_count,
              (SELECT COUNT(*) FROM repositories r JOIN remote_ssh_configs rsc ON rsc.id = r.remote_ssh_config_id WHERE rsc.borgbase_account_id = ?) AS repository_count
        ", [$a['id'], $a['id']]);
        $planBytes = $a['plan_max_size_gb'] !== null ? (int) $a['plan_max_size_gb'] * 1000 * 1000 * 1000 : null;
        $used = $a['usage_bytes'] !== null ? (int) $a['usage_bytes'] : null;
        $limit = $a['plan_max_repos'] !== null ? (int) $a['plan_max_repos'] : null;
        $remoteCount = $a['remote_repo_count'] !== null ? (int) $a['remote_repo_count'] : null;
        $hasKey = !empty($a['borgbase_ssh_key_id']);

        return [
            'id' => (int) $a['id'],
            'name' => $a['name'],
            'username' => $a['username'],
            'api_token_set' => !empty($a['api_token_encrypted']),
            // null = not known yet, false = a write was refused (read-only token)
            'can_write' => $a['can_write'] === null ? null : (bool) $a['can_write'],
            'ssh_key_registered' => $hasKey,
            'ssh_public_key' => $a['ssh_public_key'],
            'plan' => [
                'name' => $a['plan_name'],
                'max_repos' => $limit,
                'max_size_gb' => $a['plan_max_size_gb'] !== null ? (int) $a['plan_max_size_gb'] : null,
                'included_gb' => $a['plan_included_gb'] !== null ? (int) $a['plan_included_gb'] : null,
            ],
            'usage_bytes' => $used,
            'plan_bytes' => $planBytes,
            'usage_percent' => ($planBytes && $used !== null) ? round($used / $planBytes * 100, 1) : null,
            'remote_repo_count' => $remoteCount,
            'location_count' => (int) ($counts['location_count'] ?? 0),
            'repository_count' => (int) ($counts['repository_count'] ?? 0),
            'can_create_repo' => !empty($a['api_token_encrypted']) && $hasKey
                && ($limit === null || $limit <= 0 || ($remoteCount ?? 0) < $limit),
            'checked_at' => $a['checked_at'],
            'check_error' => $a['check_error'],
            'created_at' => $a['created_at'],
        ];
    }

    private function locationPayload(array $l, ?array $remote): array
    {
        $total = $l['disk_total_bytes'] !== null ? (int) $l['disk_total_bytes'] : null;
        $used = $l['disk_used_bytes'] !== null ? (int) $l['disk_used_bytes'] : null;
        return [
            'id' => (int) $l['id'],
            'name' => $l['name'],
            'borgbase_repo_id' => $l['remote_user'],
            'borgbase_repo_name' => $l['borgbase_repo_name'],
            'remote_host' => $l['remote_host'],
            'region' => $remote['region'] ?? null,
            'repository_id' => isset($l['repository_id']) && $l['repository_id'] !== null ? (int) $l['repository_id'] : null,
            'repository_name' => $l['repository_name'] ?? null,
            'agent_id' => isset($l['agent_id']) && $l['agent_id'] !== null ? (int) $l['agent_id'] : null,
            'agent_name' => $l['agent_name'] ?? null,
            'disk_total_bytes' => $total,
            'disk_used_bytes' => $used,
            'usage_percent' => ($total && $used !== null) ? round($used / $total * 100, 1) : null,
            'quota_enabled' => $remote ? (bool) ($remote['quotaEnabled'] ?? false) : null,
            'last_modified' => $remote['lastModified'] ?? null,
            'disk_checked_at' => $l['disk_checked_at'],
            'disk_check_error' => $l['disk_check_error'],
            'can_delete' => (int) ($l['repo_count'] ?? 0) === 0,
        ];
    }

    private function remoteRepoPayload(array $r): array
    {
        return [
            'id' => (string) $r['id'],
            'name' => $r['name'] ?? null,
            'region' => $r['region'] ?? null,
            'repo_path' => $r['repoPath'] ?? null,
            'quota_gb' => isset($r['quota']) ? round(((float) $r['quota']) / 1000, 3) : null,
            'quota_enabled' => (bool) ($r['quotaEnabled'] ?? false),
            'usage_bytes' => (int) round(((float) ($r['currentUsage'] ?? 0)) * 1000 * 1000),
            'last_modified' => $r['lastModified'] ?? null,
            'borg_version' => $r['borgVersion'] ?? null,
        ];
    }
}
