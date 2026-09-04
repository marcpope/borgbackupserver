<?php

namespace BBS\Services;

use BBS\Core\Database;

/**
 * BorgBase accounts: the GraphQL client plus the bookkeeping that keeps
 * remote_ssh_configs rows attached to the account they belong to.
 *
 * BorgBase gives each repository its own SSH user, so every repo is its own
 * storage location in BBS. The account row is what groups them: it holds
 * the API token, the SSH key BBS registered on BorgBase, and the plan's
 * limits, and it is what the Storage page shows one card for.
 *
 * Units: the API reports quota and currentUsage in decimal megabytes, and
 * plan sizes in decimal gigabytes. Everything stored here is decimal bytes,
 * the same convention the per-repo quota sync already uses.
 */
class BorgBaseService
{
    private const API_URL = 'https://api.borgbase.com/graphql';

    /** Regions BorgBase offers for new repositories. */
    public const REGIONS = ['eu' => 'Europe', 'us' => 'United States'];

    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    // ---------------------------------------------------------------
    // Accounts
    // ---------------------------------------------------------------

    public function getAll(): array
    {
        return $this->db->fetchAll("SELECT * FROM borgbase_accounts ORDER BY name");
    }

    public function getById(int $id): ?array
    {
        return $this->db->fetchOne("SELECT * FROM borgbase_accounts WHERE id = ?", [$id]) ?: null;
    }

    public function getToken(array $account): ?string
    {
        if (empty($account['api_token_encrypted'])) {
            return null;
        }
        try {
            return Encryption::decrypt($account['api_token_encrypted']);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Storage locations attached to an account, each with its repo count and
     * the client that owns the repository on it (BBS creates exactly one
     * repository per BorgBase location).
     */
    public function getLocations(int $accountId): array
    {
        return $this->db->fetchAll("
            SELECT rsc.*,
                   (SELECT COUNT(*) FROM repositories r WHERE r.remote_ssh_config_id = rsc.id) AS repo_count,
                   (SELECT r.id FROM repositories r WHERE r.remote_ssh_config_id = rsc.id ORDER BY r.id LIMIT 1) AS repository_id,
                   (SELECT r.name FROM repositories r WHERE r.remote_ssh_config_id = rsc.id ORDER BY r.id LIMIT 1) AS repository_name,
                   (SELECT a.id FROM repositories r JOIN agents a ON a.id = r.agent_id WHERE r.remote_ssh_config_id = rsc.id ORDER BY r.id LIMIT 1) AS agent_id,
                   (SELECT a.name FROM repositories r JOIN agents a ON a.id = r.agent_id WHERE r.remote_ssh_config_id = rsc.id ORDER BY r.id LIMIT 1) AS agent_name
            FROM remote_ssh_configs rsc
            WHERE rsc.borgbase_account_id = ?
            ORDER BY rsc.name
        ", [$accountId]);
    }

    /**
     * BorgBase locations that belong to no account. These predate accounts,
     * or were added by hand without a token.
     */
    public function getUnattachedLocations(): array
    {
        return $this->db->fetchAll("
            SELECT rsc.*,
                   (SELECT COUNT(*) FROM repositories r WHERE r.remote_ssh_config_id = rsc.id) AS repo_count
            FROM remote_ssh_configs rsc
            WHERE rsc.borgbase_account_id IS NULL
              AND (rsc.provider = 'borgbase' OR rsc.remote_host LIKE '%.repo.borgbase.com')
            ORDER BY rsc.name
        ");
    }

    /**
     * Create an account from an API token: validate it, register a fresh SSH
     * key on BorgBase, store everything, then pull in any existing BorgBase
     * locations whose SSH user matches a repo on the account.
     *
     * @return array{success: bool, id?: int, error?: string, warning?: string}
     */
    public function createAccount(string $name, string $token): array
    {
        $overview = $this->fetchOverview($token);
        if (!$overview['success']) {
            return ['success' => false, 'error' => $overview['error']];
        }

        $id = $this->db->insert('borgbase_accounts', [
            'name' => $name,
            'username' => $overview['username'],
            'api_token_encrypted' => Encryption::encrypt($token),
        ]);

        $warning = null;
        $keyResult = $this->registerSshKey($id, $token);
        if (!$keyResult['success']) {
            $warning = $keyResult['error'];
        }

        $this->attachMatchingLocations($id, $overview['repos']);
        $this->applyOverview($id, $overview);

        $result = ['success' => true, 'id' => $id];
        if ($warning) {
            $result['warning'] = $warning;
        }
        return $result;
    }

    /**
     * Replace an account's token. Re-validates and, if the account has no
     * registered key yet, tries to register one with the new token.
     */
    public function updateToken(int $id, string $token): array
    {
        $account = $this->getById($id);
        if (!$account) {
            return ['success' => false, 'error' => 'Account not found.'];
        }
        $overview = $this->fetchOverview($token);
        if (!$overview['success']) {
            return ['success' => false, 'error' => $overview['error']];
        }
        $this->db->update('borgbase_accounts', [
            'api_token_encrypted' => Encryption::encrypt($token),
            'username' => $overview['username'],
            'can_write' => null,
        ], 'id = ?', [$id]);

        $warning = null;
        if (empty($account['borgbase_ssh_key_id'])) {
            $keyResult = $this->registerSshKey($id, $token);
            if (!$keyResult['success']) {
                $warning = $keyResult['error'];
            }
        }
        $this->attachMatchingLocations($id, $overview['repos']);
        $this->applyOverview($id, $overview);

        $result = ['success' => true];
        if ($warning) {
            $result['warning'] = $warning;
        }
        return $result;
    }

    /**
     * Re-read plan, usage and repo list, and push per-repo usage into the
     * attached locations. One API call per account, however many repos.
     */
    public function refreshAccount(int $id): array
    {
        $account = $this->getById($id);
        if (!$account) {
            return ['success' => false, 'error' => 'Account not found.'];
        }
        $token = $this->getToken($account);
        if (!$token) {
            $this->db->update('borgbase_accounts', [
                'checked_at' => $this->db->now(),
                'check_error' => 'No API token stored.',
            ], 'id = ?', [$id]);
            return ['success' => false, 'error' => 'No API token stored.'];
        }
        $overview = $this->fetchOverview($token);
        if (!$overview['success']) {
            $this->db->update('borgbase_accounts', [
                'checked_at' => $this->db->now(),
                'check_error' => mb_substr($overview['error'], 0, 255),
            ], 'id = ?', [$id]);
            return $overview;
        }
        $this->attachMatchingLocations($id, $overview['repos']);
        $this->applyOverview($id, $overview);
        return ['success' => true, 'overview' => $overview];
    }

    /**
     * Remove the account. Its locations stay, detached, so repositories on
     * them keep working; only the grouping and the token go.
     */
    public function deleteAccount(int $id): void
    {
        $this->db->update('remote_ssh_configs', ['borgbase_account_id' => null], 'borgbase_account_id = ?', [$id]);
        $this->db->delete('borgbase_accounts', 'id = ?', [$id]);
    }

    // ---------------------------------------------------------------
    // Repositories on BorgBase
    // ---------------------------------------------------------------

    /**
     * Create a repository on BorgBase and a storage location for it.
     * The caller runs borg init and creates the BBS repository row; on
     * failure it calls deleteRemoteRepo() to roll back.
     *
     * @return array{success: bool, location_id?: int, repo?: array, error?: string}
     */
    public function createRepo(array $account, string $name, string $region, ?float $quotaGb): array
    {
        $token = $this->getToken($account);
        if (!$token) {
            return ['success' => false, 'error' => 'No API token stored for this account.'];
        }
        if (empty($account['borgbase_ssh_key_id']) || empty($account['ssh_private_key_encrypted'])) {
            return ['success' => false, 'error' => 'This account has no SSH key registered on BorgBase. Save a Full Access token to register one.'];
        }
        if (!isset(self::REGIONS[$region])) {
            return ['success' => false, 'error' => 'Unknown region.'];
        }

        $vars = [
            'name' => $name,
            'region' => $region,
            // Match the borg the server runs (1.4.x). LATEST would create a
            // borg 2 repository the server's client can't open.
            'borgVersion' => 'V_1_4_X',
            'fullAccessKeys' => [(string) $account['borgbase_ssh_key_id']],
            'quotaEnabled' => $quotaGb !== null && $quotaGb > 0,
        ];
        if ($quotaGb !== null && $quotaGb > 0) {
            $vars['quota'] = (int) round($quotaGb * 1000);
        }

        $res = $this->graphql($token, '
            mutation ($name: String!, $region: String!, $borgVersion: String, $quota: Int, $quotaEnabled: Boolean, $fullAccessKeys: [String]) {
                repoAdd(name: $name, region: $region, borgVersion: $borgVersion, quota: $quota, quotaEnabled: $quotaEnabled, fullAccessKeys: $fullAccessKeys) {
                    repoAdded { id name region repoPath quota quotaEnabled currentUsage server { hostname } }
                }
            }', $vars);
        if (!$res['success']) {
            $this->noteWriteResult((int) $account['id'], $res['error']);
            return ['success' => false, 'error' => $res['error']];
        }
        $repo = $res['data']['repoAdd']['repoAdded'] ?? null;
        if (!$repo || empty($repo['id'])) {
            return ['success' => false, 'error' => 'BorgBase did not return the new repository.'];
        }
        $this->db->update('borgbase_accounts', ['can_write' => 1], 'id = ?', [$account['id']]);

        $locationId = $this->insertLocation($account, $repo);
        return ['success' => true, 'location_id' => $locationId, 'repo' => $repo];
    }

    /**
     * Add a storage location for a repository that already exists on
     * BorgBase. Uses the account's key, which BorgBase only lets in if the
     * key is on the repo's access list; the caller should test the
     * connection and tell the user if it isn't.
     */
    public function importRepo(array $account, string $repoId): array
    {
        $token = $this->getToken($account);
        if (!$token) {
            return ['success' => false, 'error' => 'No API token stored for this account.'];
        }
        if (empty($account['ssh_private_key_encrypted'])) {
            return ['success' => false, 'error' => 'This account has no SSH key. Save a Full Access token to register one.'];
        }
        $overview = $this->fetchOverview($token);
        if (!$overview['success']) {
            return $overview;
        }
        $repo = null;
        foreach ($overview['repos'] as $r) {
            if ((string) $r['id'] === $repoId) {
                $repo = $r;
                break;
            }
        }
        if (!$repo) {
            return ['success' => false, 'error' => 'That repository is not on this BorgBase account.'];
        }
        $existing = $this->db->fetchOne("SELECT id FROM remote_ssh_configs WHERE remote_user = ?", [$repoId]);
        if ($existing) {
            return ['success' => false, 'error' => 'A storage location for that repository already exists.'];
        }

        // Grant the account's key access if it doesn't have it yet.
        $keyId = (string) ($account['borgbase_ssh_key_id'] ?? '');
        $keys = array_map('strval', (array) ($repo['fullAccessKeys'] ?? []));
        if ($keyId !== '' && !in_array($keyId, $keys, true)) {
            $keys[] = $keyId;
            $grant = $this->graphql($token, '
                mutation ($id: String!, $keys: [String]) {
                    repoEdit(id: $id, fullAccessKeys: $keys) { repoEdited { id } }
                }', ['id' => $repoId, 'keys' => $keys]);
            if (!$grant['success']) {
                $this->noteWriteResult((int) $account['id'], $grant['error']);
                return ['success' => false, 'error' => 'Could not grant the BBS key access to the repository: ' . $grant['error']];
            }
            $this->db->update('borgbase_accounts', ['can_write' => 1], 'id = ?', [$account['id']]);
        }

        $locationId = $this->insertLocation($account, $repo);
        return ['success' => true, 'location_id' => $locationId, 'repo' => $repo];
    }

    /**
     * Delete a repository on BorgBase. Irreversible; the controller only
     * calls this when the user ticked the box for it.
     */
    public function deleteRemoteRepo(array $account, string $repoId): array
    {
        $token = $this->getToken($account);
        if (!$token) {
            return ['success' => false, 'error' => 'No API token stored for this account.'];
        }
        $res = $this->graphql($token, 'mutation ($id: String!) { repoDelete(id: $id) { ok } }', ['id' => $repoId]);
        if (!$res['success']) {
            $this->noteWriteResult((int) $account['id'], $res['error']);
            return $res;
        }
        if (empty($res['data']['repoDelete']['ok'])) {
            return ['success' => false, 'error' => 'BorgBase refused to delete the repository.'];
        }
        $this->db->update('borgbase_accounts', ['can_write' => 1], 'id = ?', [$account['id']]);
        return ['success' => true];
    }

    // ---------------------------------------------------------------
    // Internals
    // ---------------------------------------------------------------

    /**
     * One query for everything the account card and page need.
     *
     * @return array{success: bool, error?: string, username?: string, plan?: array, repos?: array, keys?: array}
     */
    public function fetchOverview(string $token): array
    {
        $res = $this->graphql($token, '{
            me { username activePlan { id name maxRepos maxSizeGb includedGb } }
            sshList { id name hashSha256 }
            repoList { id name region repoPath quota quotaEnabled currentUsage lastModified borgVersion format
                       fullAccessKeys server { hostname } }
        }');
        if (!$res['success']) {
            return $res;
        }
        $data = $res['data'];
        if (empty($data['me'])) {
            return ['success' => false, 'error' => 'BorgBase did not recognise the token.'];
        }
        return [
            'success' => true,
            'username' => (string) ($data['me']['username'] ?? ''),
            'plan' => $data['me']['activePlan'] ?? null,
            'repos' => $data['repoList'] ?? [],
            'keys' => $data['sshList'] ?? [],
        ];
    }

    /** Write plan and usage from an overview into the account row. */
    private function applyOverview(int $id, array $overview): void
    {
        $plan = $overview['plan'] ?? [];
        $usageMb = 0.0;
        foreach ($overview['repos'] as $r) {
            $usageMb += (float) ($r['currentUsage'] ?? 0);
        }
        $this->db->update('borgbase_accounts', [
            'username' => $overview['username'] ?? null,
            'plan_name' => $plan['name'] ?? null,
            'plan_max_repos' => isset($plan['maxRepos']) ? (int) $plan['maxRepos'] : null,
            'plan_max_size_gb' => isset($plan['maxSizeGb']) ? (int) $plan['maxSizeGb'] : null,
            'plan_included_gb' => isset($plan['includedGb']) ? (int) $plan['includedGb'] : null,
            'usage_bytes' => (int) round($usageMb * 1000 * 1000),
            'remote_repo_count' => count($overview['repos']),
            'checked_at' => $this->db->now(),
            'check_error' => null,
        ], 'id = ?', [$id]);

        // Per-location usage. A repo's own quota is the bar's total when
        // BorgBase enforces one; otherwise the plan's size is the ceiling
        // every repo on the account shares.
        $planBytes = isset($plan['maxSizeGb']) ? (int) $plan['maxSizeGb'] * 1000 * 1000 * 1000 : 0;
        $byUser = [];
        foreach ($overview['repos'] as $r) {
            $byUser[(string) $r['id']] = $r;
        }
        $rows = $this->db->fetchAll("SELECT id, remote_user FROM remote_ssh_configs WHERE borgbase_account_id = ?", [$id]);
        foreach ($rows as $row) {
            $r = $byUser[$row['remote_user']] ?? null;
            if (!$r) {
                $this->db->update('remote_ssh_configs', [
                    'disk_checked_at' => $this->db->now(),
                    'disk_check_error' => 'Repository no longer exists on BorgBase',
                ], 'id = ?', [$row['id']]);
                continue;
            }
            $used = max(0, (int) round(((float) ($r['currentUsage'] ?? 0)) * 1000 * 1000));
            $total = !empty($r['quotaEnabled']) && (float) ($r['quota'] ?? 0) > 0
                ? (int) round(((float) $r['quota']) * 1000 * 1000)
                : $planBytes;
            $this->db->update('remote_ssh_configs', [
                'disk_total_bytes' => $total > 0 ? $total : null,
                'disk_used_bytes' => $used,
                'disk_free_bytes' => $total > 0 ? max(0, $total - $used) : null,
                'disk_checked_at' => $this->db->now(),
                'disk_check_error' => null,
                'borgbase_usage_source' => 'borgbase_api',
                'borgbase_repo_name' => $r['name'] ?? null,
            ], 'id = ?', [$row['id']]);
        }
    }

    /**
     * Existing BorgBase locations are identified by their SSH user, which is
     * the repo id on BorgBase. Any unattached one that matches a repo on the
     * account belongs to it.
     */
    private function attachMatchingLocations(int $accountId, array $repos): void
    {
        $ids = array_map(fn($r) => (string) $r['id'], $repos);
        if (!$ids) {
            return;
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $this->db->query(
            "UPDATE remote_ssh_configs SET borgbase_account_id = ?
             WHERE borgbase_account_id IS NULL AND remote_user IN ({$placeholders})",
            array_merge([$accountId], $ids)
        );
    }

    /**
     * Generate a key pair, register the public half on BorgBase, and store
     * both on the account. Needs a Create Only or Full Access token.
     */
    private function registerSshKey(int $accountId, string $token): array
    {
        try {
            $pair = self::generateKeyPair();
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => 'Could not generate an SSH key: ' . $e->getMessage()];
        }
        $keyName = 'BBS ' . (gethostname() ?: 'server');
        $res = $this->graphql($token, '
            mutation ($name: String!, $keyData: String!) {
                sshAdd(name: $name, keyData: $keyData) { keyAdded { id } }
            }', ['name' => $keyName, 'keyData' => $pair['public_key']]);
        if (!$res['success']) {
            $this->noteWriteResult($accountId, $res['error']);
            return [
                'success' => false,
                'error' => 'The token was accepted but BorgBase refused to register an SSH key (' . $res['error']
                    . '). Repositories can\'t be created from BBS until a Full Access token is saved.',
            ];
        }
        $keyId = (string) ($res['data']['sshAdd']['keyAdded']['id'] ?? '');
        if ($keyId === '') {
            return ['success' => false, 'error' => 'BorgBase did not return the registered key.'];
        }
        $this->db->update('borgbase_accounts', [
            'ssh_private_key_encrypted' => Encryption::encrypt($pair['private_key']),
            'ssh_public_key' => $pair['public_key'],
            'borgbase_ssh_key_id' => $keyId,
            'can_write' => 1,
        ], 'id = ?', [$accountId]);
        return ['success' => true, 'key_id' => $keyId];
    }

    /** Create the remote_ssh_configs row for a BorgBase repo. */
    private function insertLocation(array $account, array $repo): int
    {
        $host = '';
        if (!empty($repo['repoPath']) && preg_match('#^ssh://[^@]+@([^/:]+)#', $repo['repoPath'], $m)) {
            $host = $m[1];
        }
        if ($host === '') {
            $host = $repo['id'] . '.repo.borgbase.com';
        }
        return $this->db->insert('remote_ssh_configs', [
            'name' => 'BorgBase - ' . ($repo['name'] ?: $repo['id']),
            'provider' => 'borgbase',
            'remote_host' => $host,
            'remote_port' => 22,
            'remote_user' => (string) $repo['id'],
            'remote_base_path' => './repo',
            'ssh_private_key_encrypted' => $account['ssh_private_key_encrypted'],
            'borg_remote_path' => null,
            'append_repo_name' => 0,
            'borgbase_repo_name' => $repo['name'] ?? null,
            'borgbase_account_id' => (int) $account['id'],
        ]);
    }

    /** A refused write means the token isn't Full Access; remember that for the UI. */
    private function noteWriteResult(int $accountId, string $error): void
    {
        if (stripos($error, 'permission') !== false || stripos($error, 'not allowed') !== false || stripos($error, 'forbidden') !== false) {
            $this->db->update('borgbase_accounts', ['can_write' => 0], 'id = ?', [$accountId]);
        }
    }

    /**
     * POST a GraphQL document. Returns ['success' => true, 'data' => ...] or
     * ['success' => false, 'error' => message].
     */
    public function graphql(string $token, string $query, array $variables = []): array
    {
        $payload = json_encode(['query' => $query, 'variables' => $variables ?: new \stdClass()]);
        if ($payload === false) {
            return ['success' => false, 'error' => 'Failed to build BorgBase API request.'];
        }
        if (!function_exists('curl_init')) {
            return ['success' => false, 'error' => 'PHP curl extension is not available.'];
        }
        $ch = curl_init(self::API_URL);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $token,
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_TIMEOUT => 20,
        ]);
        $body = curl_exec($ch);
        $err = curl_error($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false) {
            return ['success' => false, 'error' => $err ?: 'BorgBase API request failed.'];
        }
        if ($code === 401 || $code === 403) {
            return ['success' => false, 'error' => 'BorgBase rejected the API token.'];
        }
        $decoded = json_decode((string) $body, true);
        if (!is_array($decoded)) {
            return ['success' => false, 'error' => "BorgBase API returned HTTP {$code} with an unreadable body."];
        }
        if (!empty($decoded['errors'])) {
            $messages = array_map(fn($e) => (string) ($e['message'] ?? 'unknown error'), $decoded['errors']);
            return ['success' => false, 'error' => implode('; ', $messages)];
        }
        if ($code >= 400) {
            return ['success' => false, 'error' => "BorgBase API returned HTTP {$code}."];
        }
        return ['success' => true, 'data' => $decoded['data'] ?? []];
    }

    /** ed25519 pair; BorgBase accepts it and it's what borg users expect. */
    public static function generateKeyPair(): array
    {
        $tmpDir = sys_get_temp_dir() . '/bbs-bbkey-' . bin2hex(random_bytes(8));
        if (!mkdir($tmpDir, 0700, true)) {
            throw new \RuntimeException('Could not create a temporary directory.');
        }
        $keyFile = $tmpDir . '/id_ed25519';
        try {
            $proc = proc_open(
                ['ssh-keygen', '-t', 'ed25519', '-N', '', '-f', $keyFile, '-C', 'bbs-borgbase'],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes
            );
            if (!is_resource($proc)) {
                throw new \RuntimeException('Failed to run ssh-keygen');
            }
            fclose($pipes[0]);
            stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            if (proc_close($proc) !== 0) {
                throw new \RuntimeException('ssh-keygen failed: ' . trim($stderr));
            }
            return [
                'private_key' => (string) file_get_contents($keyFile),
                'public_key' => trim((string) file_get_contents($keyFile . '.pub')),
            ];
        } finally {
            @unlink($keyFile);
            @unlink($keyFile . '.pub');
            @rmdir($tmpDir);
        }
    }

    /** Decimal formatting, matching how BorgBase shows sizes. */
    public static function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        $size = (float) $bytes;
        while ($size >= 1000 && $i < count($units) - 1) {
            $size /= 1000;
            $i++;
        }
        return round($size, 1) . "\u{00A0}" . $units[$i];
    }
}
