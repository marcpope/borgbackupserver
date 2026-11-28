<?php

namespace BBS\Services;

use BBS\Core\Database;

/**
 * Creates an agent record with everything it needs before its installer
 * runs: API key, storage directory, Unix user and SSH key for borg, owner
 * permissions. Shared by Add Client and Add Hypervisor, which differ only
 * in the agent type.
 *
 * Hypervisors: this is the body of ClientController::store() moved here
 * unchanged, so that HypervisorController::store() provisions its agent the
 * same way. The only additions are the agent_type column, the wording of
 * the server log line, and discard() to undo a creation whose next step
 * failed; errors that used to be flashed by the controller are now thrown
 * as RuntimeException and flashed by the caller, with the same text. Privileged steps still go through SshKeyManager and bbs-ssh-helper.
 * See docs/hypervisors.md#changes-to-existing-bbs-code
 */
class AgentProvisioningService
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * @param ?int $userId owner (null: admin-only agent)
     * @param ?int $profileId client profile; null or unknown means the default profile
     * @param string $agentType 'client' or 'hypervisor'. agents.agent_type is
     *        set here only, never by the agent; the hypervisor API endpoints
     *        refuse any agent that is not 'hypervisor'.
     * @return int the new agent id
     * @throws \RuntimeException with a message fit for the user; nothing is left behind
     */
    public function create(string $name, ?int $userId, ?int $profileId, ?string $location, string $agentType = 'client'): int
    {
        if (!in_array($agentType, ['client', 'hypervisor'], true)) {
            throw new \InvalidArgumentException("Unknown agent type: {$agentType}");
        }

        $apiKey = bin2hex(random_bytes(32));

        // Falls back to the default profile rather than null, so failure
        // settings and plan defaults always resolve to something.
        $profileService = new ClientProfileService();
        if (!$profileId || !$profileService->find($profileId)) {
            $profileId = $profileService->defaultProfileId();
        }

        $location = $location !== null ? mb_substr(trim($location), 0, 100) : '';

        $id = $this->db->insert('agents', [
            'name' => $name,
            'agent_type' => $agentType,
            'location' => $location !== '' ? $location : null,
            'api_key_hash' => hash('sha256', $apiKey),
            'api_key_encrypted' => Encryption::encrypt($apiKey),
            'status' => 'setup',
            'user_id' => $userId,
            'client_profile_id' => $profileId,
        ]);

        // Determine SSH home path: use storage_path unless a separate storage location
        // is configured for repos. SSH home dirs MUST be on a local filesystem (chown
        // is required for sshd), so if storage_path points to a NAS/NFS mount that also
        // has a matching storage location, fall back to /var/bbs/home.
        $storageSetting = $this->db->fetchOne("SELECT `value` FROM settings WHERE `key` = 'storage_path'");
        $storagePath = $storageSetting['value'] ?? null;
        if (!$storagePath) {
            $this->db->delete('agents', 'id = ?', [$id]);
            throw new \RuntimeException('no storage path configured. Go to Settings to set one.');
        }

        // If storage_path matches a storage location, it's intended for repo storage
        // (possibly a NAS/NFS mount). Use /var/bbs/home for SSH home dirs instead.
        $sshHomePath = $storagePath;
        $matchingLocation = $this->db->fetchOne(
            "SELECT id FROM storage_locations WHERE path = ?",
            [rtrim($storagePath, '/')]
        );
        if ($matchingLocation && is_dir('/var/bbs/home')) {
            $sshHomePath = '/var/bbs/home';
        }

        // Create storage directory
        $clientDir = rtrim($sshHomePath, '/') . '/' . $id;
        if (!is_dir($clientDir) && !@mkdir($clientDir, 0755, true)) {
            $this->db->delete('agents', 'id = ?', [$id]);
            throw new \RuntimeException("failed to create storage directory: {$clientDir}. Check permissions on {$sshHomePath}.");
        }

        // Provision SSH access: create Unix user, SSH keys, authorized_keys
        $sshResult = SshKeyManager::provisionClient($id, $name, $sshHomePath);
        if (!$sshResult) {
            @rmdir($clientDir);
            $this->db->delete('agents', 'id = ?', [$id]);
            $detail = SshKeyManager::getLastHelperError();
            throw new \RuntimeException('SSH provisioning failed'
                . ($detail ? ': ' . htmlspecialchars($detail) : '')
                . '. Ensure bbs-ssh-helper is installed at /usr/local/bin/bbs-ssh-helper with sudo access. See the Installation Guide: https://github.com/marcpope/borgbackupserver/wiki/Installation');
        }

        $this->db->insert('server_log', [
            'agent_id' => $id,
            'level' => 'info',
            'message' => ($agentType === 'hypervisor' ? 'Hypervisor agent' : 'Client')
                . " created. SSH provisioned: user {$sshResult['unix_user']}, home {$sshResult['home_dir']}",
        ]);

        // The owner gets access and all permissions on their agent by
        // default; an admin can back individual permissions off in the
        // user's profile (#337)
        if ($userId) {
            (new PermissionService())->grantOwnerDefaults($userId, $id);
        }

        return $id;
    }

    /**
     * Undo create() for an agent that was never used: when the caller's next
     * step fails (Add Hypervisor could not store the hypervisor row), the
     * new agent would otherwise stay behind, hidden from both Clients and
     * Hypervisors.
     *
     * Same steps as ClientController::delete() for these resources: the Unix
     * user and the storage directory are removed through bbs-ssh-helper, then
     * the agent row (its permissions cascade). Nothing else exists yet for a
     * new agent: no repository, archive or catalog data. Errors are logged,
     * not thrown: the caller is already reporting a failure.
     */
    public function discard(int $agentId): void
    {
        try {
            $agent = $this->db->fetchOne(
                "SELECT ssh_unix_user, ssh_home_dir FROM agents WHERE id = ?",
                [$agentId]
            );
            if (!$agent) {
                return;
            }
            if (!empty($agent['ssh_unix_user'])) {
                SshKeyManager::deprovisionClient($agent['ssh_unix_user']);
            }
            if (!empty($agent['ssh_home_dir']) && is_dir($agent['ssh_home_dir'])) {
                SshKeyManager::deleteStorage($agent['ssh_home_dir']);
            }
            $this->db->delete('agents', 'id = ?', [$agentId]);
        } catch (\Throwable $e) {
            error_log("Could not discard agent {$agentId}: " . $e->getMessage());
        }
    }
}
