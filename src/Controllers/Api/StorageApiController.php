<?php

namespace BBS\Controllers\Api;

use BBS\Core\Controller;
use BBS\Services\Encryption;
use BBS\Services\RemoteSshService;
use BBS\Controllers\StorageLocationController;

/**
 * Token-authenticated storage administration.
 *
 * `/api/v1/storage` could list and create but never rename, re-default or
 * remove, and remote SSH targets were readable and nothing more — so a client
 * could show every storage location on an install and act on none of them.
 * These are the write halves, mirroring StorageLocationController and
 * RemoteSshConfigController rather than reimplementing their rules.
 *
 * Two things are deliberately not offered. A local location's `path` is not
 * editable: repositories live under it, so moving one is a filesystem
 * operation, not a settings change. And nothing here touches the filesystem —
 * deleting unregisters a location, it never erases backups.
 */
class StorageApiController extends Controller
{
    /** Providers the web wizard offers; anything else is a plain SSH host. */
    private const PROVIDERS = ['borgbase', 'hetzner', 'rsync.net'];

    /** Same private helper the other Api\* controllers each carry. */
    private function getJsonInput(): array
    {
        $raw = file_get_contents('php://input');
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }

    // ── Local storage locations ─────────────────────────────────────

    /**
     * PUT /api/v1/storage/{id} — {label?, is_default?, capacity_bytes?}
     */
    public function updateLocation(int $id): void
    {
        $this->denyIfHosted();
        $this->requireApiToken();
        $input = $this->getJsonInput();

        $location = $this->db->fetchOne("SELECT * FROM storage_locations WHERE id = ?", [$id]);
        if (!$location) {
            $this->json(['error' => 'Storage location not found'], 404);
        }

        // The path is where repositories physically live. Renaming the label is
        // a settings change; changing the path is a data migration, and quietly
        // accepting one here would point the server at an empty directory.
        if (array_key_exists('path', $input) && rtrim(trim((string) $input['path']), '/') !== rtrim($location['path'], '/')) {
            $this->json([
                'error' => 'path cannot be changed — repositories are stored under it. '
                         . 'Create a new location and move the repositories instead.',
            ], 422);
        }

        $update = [];
        if (array_key_exists('label', $input)) {
            $label = trim((string) $input['label']);
            if ($label === '') {
                $this->json(['error' => 'label cannot be empty'], 422);
            }
            $update['label'] = $label;
        }

        // A stated capacity for mounts df can't measure (#415). null/0 clears
        // it and returns the location to being measured.
        if (array_key_exists('capacity_bytes', $input)) {
            $cap = $input['capacity_bytes'];
            if ($cap === null || $cap === '' || (int) $cap <= 0) {
                $update['capacity_bytes'] = null;
            } elseif (!is_numeric($cap)) {
                $this->json(['error' => 'capacity_bytes must be a positive integer of bytes, or null'], 422);
            } else {
                $update['capacity_bytes'] = (int) $cap;
            }
        }

        $makeDefault = array_key_exists('is_default', $input)
            ? filter_var($input['is_default'], FILTER_VALIDATE_BOOLEAN)
            : null;

        if ($makeDefault === false && $location['is_default']) {
            $this->json([
                'error' => 'Cannot unset the default. Make another location the default instead.',
            ], 422);
        }

        if ($makeDefault === true) {
            $this->db->query("UPDATE storage_locations SET is_default = 0");
            $update['is_default'] = 1;
        }

        if (empty($update)) {
            $this->json(['error' => 'Nothing to update — send label, is_default and/or capacity_bytes'], 422);
        }

        $this->db->update('storage_locations', $update, 'id = ?', [$id]);

        // Keep /etc/bbs/allowed-storage-paths in step so bbs-ssh-helper still
        // accepts repo operations under this location.
        (new StorageLocationController())->updateAllowedPaths();

        $this->db->insert('server_log', [
            'level' => 'info',
            'message' => "Storage location \"{$location['label']}\" updated via API",
        ]);

        $this->json($this->locationPayload($id));
    }

    /**
     * DELETE /api/v1/storage/{id}
     *
     * Unregisters the location. Borg data on disk is left exactly where it is.
     */
    public function deleteLocation(int $id): void
    {
        $this->denyIfHosted();
        $this->requireApiToken();

        $location = $this->db->fetchOne("SELECT * FROM storage_locations WHERE id = ?", [$id]);
        if (!$location) {
            $this->json(['error' => 'Storage location not found'], 404);
        }

        $repos = $this->db->fetchAll(
            "SELECT name FROM repositories WHERE storage_location_id = ? ORDER BY name",
            [$id]
        );
        if (!empty($repos)) {
            $names = array_column($repos, 'name');
            $this->json([
                'error' => sprintf(
                    'Cannot delete "%s" — %d repository/ies still live there: %s. Delete or move them first.',
                    $location['label'],
                    count($names),
                    implode(', ', array_slice($names, 0, 10)) . (count($names) > 10 ? ', …' : '')
                ),
                'repositories' => $names,
            ], 409);
        }

        if ($location['is_default']) {
            $this->json(['error' => 'Cannot delete the default storage location'], 409);
        }

        $remaining = (int) ($this->db->fetchOne("SELECT COUNT(*) c FROM storage_locations")['c'] ?? 0);
        if ($remaining <= 1) {
            $this->json(['error' => 'Cannot delete the only storage location'], 409);
        }

        $this->db->delete('storage_locations', 'id = ?', [$id]);
        (new StorageLocationController())->updateAllowedPaths();

        $this->db->insert('server_log', [
            'level' => 'info',
            'message' => "Storage location \"{$location['label']}\" deleted via API (data left on disk)",
        ]);

        $this->json(['status' => 'ok', 'deleted' => $id, 'data_removed' => false]);
    }

    // ── Remote SSH targets ──────────────────────────────────────────

    /** GET /api/v1/remote-ssh-configs */
    public function listRemote(): void
    {
        $this->requireApiToken();

        $rows = $this->db->fetchAll("SELECT * FROM remote_ssh_configs ORDER BY name");
        $this->json(['remote_ssh_configs' => array_map([$this, 'remotePayload'], $rows)]);
    }

    /** GET /api/v1/remote-ssh-configs/{id} */
    public function showRemote(int $id): void
    {
        $this->requireApiToken();

        $row = $this->db->fetchOne("SELECT * FROM remote_ssh_configs WHERE id = ?", [$id]);
        if (!$row) {
            $this->json(['error' => 'Remote SSH config not found'], 404);
        }
        $this->json($this->remotePayload($row));
    }

    /** POST /api/v1/remote-ssh-configs */
    public function createRemote(): void
    {
        $this->denyIfHosted();
        $this->requireApiToken();
        $input = $this->getJsonInput();

        $name = trim((string) ($input['name'] ?? ''));
        $host = trim((string) ($input['remote_host'] ?? ''));
        $user = trim((string) ($input['remote_user'] ?? ''));
        $key  = trim((string) ($input['ssh_private_key'] ?? ''));

        if ($name === '' || $host === '' || $user === '' || $key === '') {
            $this->json(['error' => 'name, remote_host, remote_user and ssh_private_key are required'], 422);
        }

        $provider = $this->normaliseProvider($input['provider'] ?? null, $host);
        if ($provider === false) {
            $this->json([
                'error' => 'provider must be one of: ' . implode(', ', self::PROVIDERS) . ', or omitted',
            ], 422);
        }

        $data = [
            'name'                      => $name,
            'provider'                  => $provider,
            'remote_host'               => $host,
            'remote_port'               => $this->port($input['remote_port'] ?? 22),
            'remote_user'               => $user,
            'remote_base_path'          => trim((string) ($input['remote_base_path'] ?? './')) ?: './',
            'ssh_private_key_encrypted' => Encryption::encrypt($key),
            'borg_remote_path'          => trim((string) ($input['borg_remote_path'] ?? '')) ?: null,
            'append_repo_name'          => array_key_exists('append_repo_name', $input)
                ? (filter_var($input['append_repo_name'], FILTER_VALIDATE_BOOLEAN) ? 1 : 0)
                : 1,
        ];

        // BorgBase: API key, repository name and manual quota, with the web
        // wizard's rules. A key without a repo name can't match anything.
        $bb = $this->borgBaseFieldsFromInput($input, null);
        $apiKey = trim((string) ($input['borgbase_api_key'] ?? ''));
        if ($apiKey !== '' && empty($bb['borgbase_repo_name'])) {
            $this->json(['error' => 'borgbase_repo_name is required with borgbase_api_key'], 422);
        }
        $data = array_merge($data, $bb);
        if ($provider === 'borgbase' && $apiKey !== '' && !empty($bb['borgbase_repo_name'])) {
            $check = (new RemoteSshService())->getBorgBaseApiUsage($data, $apiKey, $bb['borgbase_repo_name']);
            if (empty($check['success'])) {
                $this->json(['error' => 'BorgBase API check failed: ' . ($check['error'] ?? 'unknown error')], 422);
            }
        }

        $id = $this->db->insert('remote_ssh_configs', $data);

        if ($provider === 'borgbase') {
            (new RemoteSshService())->refreshBorgBaseDiskUsage(array_merge($data, ['id' => $id]));
        }

        $this->db->insert('server_log', [
            'level' => 'info',
            'message' => "Remote SSH config \"{$name}\" created via API ({$user}@{$host})",
        ]);

        $this->json($this->remotePayload($this->db->fetchOne("SELECT * FROM remote_ssh_configs WHERE id = ?", [$id])), 201);
    }

    /**
     * PUT /api/v1/remote-ssh-configs/{id}
     *
     * An absent or empty `ssh_private_key` leaves the stored key alone — the
     * same contract as smtp_pass and oidc_client_secret, so a client can round
     * -trip a config it fetched without having to re-enter the key.
     */
    public function updateRemote(int $id): void
    {
        $this->denyIfHosted();
        $this->requireApiToken();
        $input = $this->getJsonInput();

        $existing = $this->db->fetchOne("SELECT * FROM remote_ssh_configs WHERE id = ?", [$id]);
        if (!$existing) {
            $this->json(['error' => 'Remote SSH config not found'], 404);
        }

        $data = [];
        foreach (['name' => 'name', 'remote_host' => 'remote_host', 'remote_user' => 'remote_user'] as $field => $col) {
            if (array_key_exists($field, $input)) {
                $val = trim((string) $input[$field]);
                if ($val === '') {
                    $this->json(["error" => "{$field} cannot be empty"], 422);
                }
                $data[$col] = $val;
            }
        }
        if (array_key_exists('remote_port', $input)) {
            $data['remote_port'] = $this->port($input['remote_port']);
        }
        if (array_key_exists('remote_base_path', $input)) {
            $data['remote_base_path'] = trim((string) $input['remote_base_path']) ?: './';
        }
        if (array_key_exists('borg_remote_path', $input)) {
            $data['borg_remote_path'] = trim((string) $input['borg_remote_path']) ?: null;
        }
        if (array_key_exists('append_repo_name', $input)) {
            $data['append_repo_name'] = filter_var($input['append_repo_name'], FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
        }
        if (array_key_exists('provider', $input)) {
            $provider = $this->normaliseProvider($input['provider'], $data['remote_host'] ?? $existing['remote_host']);
            if ($provider === false) {
                $this->json(['error' => 'provider must be one of: ' . implode(', ', self::PROVIDERS) . ', or null'], 422);
            }
            $data['provider'] = $provider;
        }

        // Only replace the key when one was actually supplied.
        $key = trim((string) ($input['ssh_private_key'] ?? ''));
        if ($key !== '') {
            $data['ssh_private_key_encrypted'] = Encryption::encrypt($key);
        }

        // BorgBase fields: blank api key keeps the stored one; a key needs a
        // repo name; a changed key/repo/user is checked against BorgBase
        // before it is saved, as the web form does.
        $isBorgBase = (($data['provider'] ?? $existing['provider'] ?? '') === 'borgbase')
            || str_contains($data['remote_host'] ?? $existing['remote_host'], '.repo.borgbase.com');
        $bb = $this->borgBaseFieldsFromInput($input, $existing);
        $apiKey = trim((string) ($input['borgbase_api_key'] ?? ''));
        $merged = array_merge($existing, $data, $bb);
        if ($apiKey !== '' && empty($merged['borgbase_repo_name'])) {
            $this->json(['error' => 'borgbase_repo_name is required with borgbase_api_key'], 422);
        }
        $data = array_merge($data, $bb);
        $borgBaseChanged = $apiKey !== ''
            || (array_key_exists('borgbase_repo_name', $bb) && $bb['borgbase_repo_name'] !== ($existing['borgbase_repo_name'] ?? null))
            || (isset($data['remote_user']) && $data['remote_user'] !== $existing['remote_user']);
        if ($isBorgBase && $borgBaseChanged && !empty($merged['borgbase_api_key_encrypted']) && !empty($merged['borgbase_repo_name'])) {
            $svc = new RemoteSshService();
            $checkKey = $apiKey !== '' ? $apiKey : ($svc->getBorgBaseApiKey($merged) ?? '');
            $check = $svc->getBorgBaseApiUsage($merged, $checkKey, $merged['borgbase_repo_name']);
            if (empty($check['success'])) {
                $this->json(['error' => 'BorgBase API check failed: ' . ($check['error'] ?? 'unknown error')], 422);
            }
        }

        if (empty($data)) {
            $this->json(['error' => 'Nothing to update'], 422);
        }

        $this->db->update('remote_ssh_configs', $data, 'id = ?', [$id]);
        if ($isBorgBase) {
            (new RemoteSshService())->refreshBorgBaseDiskUsage(array_merge($existing, $data, ['id' => $id]));
        }

        $this->db->insert('server_log', [
            'level' => 'info',
            'message' => "Remote SSH config \"{$existing['name']}\" updated via API",
        ]);

        $this->json($this->remotePayload($this->db->fetchOne("SELECT * FROM remote_ssh_configs WHERE id = ?", [$id])));
    }

    /** DELETE /api/v1/remote-ssh-configs/{id} */
    public function deleteRemote(int $id): void
    {
        $this->denyIfHosted();
        $this->requireApiToken();

        $config = $this->db->fetchOne("SELECT * FROM remote_ssh_configs WHERE id = ?", [$id]);
        if (!$config) {
            $this->json(['error' => 'Remote SSH config not found'], 404);
        }

        $repos = $this->db->fetchAll(
            "SELECT name FROM repositories WHERE remote_ssh_config_id = ? ORDER BY name",
            [$id]
        );
        if (!empty($repos)) {
            $names = array_column($repos, 'name');
            $this->json([
                'error' => sprintf(
                    'Cannot delete "%s" — %d repository/ies still use this host: %s. Delete or migrate them first.',
                    $config['name'],
                    count($names),
                    implode(', ', array_slice($names, 0, 10)) . (count($names) > 10 ? ', …' : '')
                ),
                'repositories' => $names,
            ], 409);
        }

        $this->db->delete('remote_ssh_configs', 'id = ?', [$id]);

        $this->db->insert('server_log', [
            'level' => 'info',
            'message' => "Remote SSH config \"{$config['name']}\" deleted via API",
        ]);

        $this->json(['status' => 'ok', 'deleted' => $id]);
    }

    /**
     * POST /api/v1/remote-ssh-configs/test — an unsaved body.
     *
     * The point of testing before saving: pasting a private key into a form on
     * a phone and finding out it was wrong an hour later, when a backup fails,
     * is the failure mode worth designing out.
     */
    public function testNewRemote(): void
    {
        $this->denyIfHosted();
        $this->requireApiToken();
        $input = $this->getJsonInput();

        $host = trim((string) ($input['remote_host'] ?? ''));
        $user = trim((string) ($input['remote_user'] ?? ''));
        $key  = trim((string) ($input['ssh_private_key'] ?? ''));

        if ($host === '' || $user === '' || $key === '') {
            $this->json(['status' => 'error', 'error' => 'remote_host, remote_user and ssh_private_key are required'], 422);
        }

        // testConnection() falls back to treating the stored value as plaintext
        // when it isn't decryptable, which is what lets an unsaved key work.
        $result = (new RemoteSshService())->testConnection([
            'remote_host'               => $host,
            'remote_port'               => $this->port($input['remote_port'] ?? 22),
            'remote_user'               => $user,
            'ssh_private_key_encrypted' => $key,
            'borg_remote_path'          => trim((string) ($input['borg_remote_path'] ?? '')) ?: null,
        ]);

        $this->json($result['success']
            ? ['status' => 'ok', 'version' => $result['version'] ?? '']
            : ['status' => 'error', 'error' => $result['error'] ?? 'Connection failed']);
    }

    /**
     * POST /api/v1/remote-ssh-configs/{id}/test
     *
     * A successful test re-reads the quota straight away: the host has just
     * proved it is reachable, and waiting up to fifteen minutes for the next
     * poll leaves a fixed config still looking broken.
     */
    public function testRemote(int $id): void
    {
        $this->denyIfHosted();
        $this->requireApiToken();

        $config = $this->db->fetchOne("SELECT * FROM remote_ssh_configs WHERE id = ?", [$id]);
        if (!$config) {
            $this->json(['error' => 'Remote SSH config not found'], 404);
        }

        $svc = new RemoteSshService();
        $result = $svc->testConnection($config);

        if (!$result['success']) {
            $this->json(['status' => 'error', 'error' => $result['error'] ?? 'Connection failed']);
        }

        $quota = null;
        $full = $svc->getDecrypted($id);
        if ($full) {
            if (($full['provider'] ?? '') === 'borgbase' || str_contains((string) ($full['remote_host'] ?? ''), '.repo.borgbase.com')) {
                $quota = $svc->refreshBorgBaseDiskUsage($full);
            } else {
                $quota = $svc->getDiskUsage($full);
                $svc->updateDiskUsage($id, $quota, 'df', $svc->lastDiskError());
            }
        }

        $this->json([
            'status' => 'ok',
            'version' => $result['version'] ?? '',
            'quota_changed' => $quota !== null,
        ]);
    }

    // ── Shapes ──────────────────────────────────────────────────────

    private function locationPayload(int $id): array
    {
        $row = $this->db->fetchOne(
            "SELECT id, label AS name, path, capacity_bytes, is_default, created_at FROM storage_locations WHERE id = ?",
            [$id]
        );
        $row['is_default'] = (bool) $row['is_default'];
        $row['capacity_bytes'] = $row['capacity_bytes'] !== null ? (int) $row['capacity_bytes'] : null;
        return $row;
    }

    /**
     * The stored key never leaves the server; a client is told whether one is
     * set so it can render "Replace key" rather than an empty required field.
     */
    private function remotePayload(array $r): array
    {
        return [
            'id'                   => (int) $r['id'],
            'name'                 => $r['name'],
            'provider'             => $r['provider'],
            'remote_host'          => $r['remote_host'],
            'remote_port'          => (int) $r['remote_port'],
            'remote_user'          => $r['remote_user'],
            'remote_base_path'     => $r['remote_base_path'],
            'borg_remote_path'     => $r['borg_remote_path'],
            'append_repo_name'     => (bool) $r['append_repo_name'],
            'ssh_private_key_set'  => !empty($r['ssh_private_key_encrypted']),
            'disk_total_bytes'     => $r['disk_total_bytes'] !== null ? (int) $r['disk_total_bytes'] : null,
            'disk_used_bytes'      => $r['disk_used_bytes'] !== null ? (int) $r['disk_used_bytes'] : null,
            'disk_free_bytes'      => $r['disk_free_bytes'] !== null ? (int) $r['disk_free_bytes'] : null,
            'disk_checked_at'      => $r['disk_checked_at'],
            'disk_check_error'     => $r['disk_check_error'] ?? null,
            'borgbase_repo_name'   => $r['borgbase_repo_name'] ?? null,
            'borgbase_api_key_set' => !empty($r['borgbase_api_key_encrypted']),
            'borgbase_manual_quota_gb' => isset($r['borgbase_manual_quota_gb']) && $r['borgbase_manual_quota_gb'] !== null
                ? (float) $r['borgbase_manual_quota_gb'] : null,
            'borgbase_usage_source' => $r['borgbase_usage_source'] ?? null,
            'repository_count'     => (new RemoteSshService())->getRepoCount((int) $r['id']),
            'created_at'           => $r['created_at'],
        ];
    }

    /**
     * The three BorgBase fields from a request body, with the web form's
     * rules: a field that isn't sent is left alone on update; a blank
     * `borgbase_api_key` keeps the stored key, `borgbase_clear_api_key: true`
     * drops it; the manual quota is GB or null.
     */
    private function borgBaseFieldsFromInput(array $input, ?array $existing): array
    {
        $data = [];
        if (array_key_exists('borgbase_repo_name', $input) || $existing === null) {
            $name = trim((string) ($input['borgbase_repo_name'] ?? ''));
            $data['borgbase_repo_name'] = $name !== '' ? $name : null;
        }
        if (array_key_exists('borgbase_manual_quota_gb', $input) || $existing === null) {
            $q = $input['borgbase_manual_quota_gb'] ?? null;
            $data['borgbase_manual_quota_gb'] = ($q !== null && $q !== '' && is_numeric($q) && (float) $q > 0)
                ? (string) round((float) $q, 3) : null;
        }
        $apiKey = trim((string) ($input['borgbase_api_key'] ?? ''));
        if ($apiKey !== '') {
            $data['borgbase_api_key_encrypted'] = Encryption::encrypt($apiKey);
        } elseif (!empty($input['borgbase_clear_api_key']) && filter_var($input['borgbase_clear_api_key'], FILTER_VALIDATE_BOOLEAN)) {
            $data['borgbase_api_key_encrypted'] = null;
        }
        return $data;
    }

    /**
     * POST /api/v1/remote-ssh-configs/borgbase-api-test
     * The wizard's Verify button: {"remote_user", "borgbase_repo_name", "borgbase_api_key"}
     * or {"config_id": n, ...} to fall back to a saved target's values.
     * Answers 200 with status ok/error either way, as the web does.
     */
    public function testBorgBaseApi(): void
    {
        $this->denyIfHosted();
        $this->requireApiToken();
        $input = $this->getJsonInput();

        $configId = (int) ($input['config_id'] ?? 0);
        $remoteUser = trim((string) ($input['remote_user'] ?? ''));
        $repoName = trim((string) ($input['borgbase_repo_name'] ?? ''));
        $apiKey = trim((string) ($input['borgbase_api_key'] ?? ''));

        $config = ['remote_user' => $remoteUser];
        $svc = new RemoteSshService();
        if ($configId > 0) {
            $existing = $svc->getById($configId);
            if (!$existing) {
                $this->json(['status' => 'error', 'error' => 'Config not found']);
            }
            $config = array_merge($existing, $config);
            if ($remoteUser === '') $config['remote_user'] = $existing['remote_user'];
            if ($repoName === '') $repoName = (string) ($existing['borgbase_repo_name'] ?? '');
            if ($apiKey === '') $apiKey = $svc->getBorgBaseApiKey($existing) ?? '';
        }

        if ($apiKey === '' || trim((string) ($config['remote_user'] ?? '')) === '' || $repoName === '') {
            $this->json(['status' => 'error', 'error' => 'API key and BorgBase repository name are required']);
        }

        $result = $svc->getBorgBaseApiUsage($config, $apiKey, $repoName);
        if (empty($result['success'])) {
            $this->json(['status' => 'error', 'error' => $result['error'] ?? 'BorgBase API check failed']);
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

    /** @return string|null|false  false means "not a provider we accept" */
    private function normaliseProvider($provider, string $host)
    {
        // BorgBase identifies itself by hostname, and the quota lookup depends
        // on the provider being right, so don't take a blank one at face value.
        if (str_contains($host, '.repo.borgbase.com')) {
            return 'borgbase';
        }
        if ($provider === null || $provider === '') {
            return null;
        }
        $provider = strtolower(trim((string) $provider));
        return in_array($provider, self::PROVIDERS, true) ? $provider : false;
    }

    private function port($value): int
    {
        $port = (int) $value;
        return ($port >= 1 && $port <= 65535) ? $port : 22;
    }
}
