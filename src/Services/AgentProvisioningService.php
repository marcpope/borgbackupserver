<?php

namespace BBS\Services;

use BBS\Core\Database;

/**
 * Creates an agent record with everything it needs before its installer
 * runs: API key, storage directory, Unix user and SSH key for borg, owner
 * permissions. Shared by Add Client and Add Hypervisor, which differ only
 * in the agent type.
 */
class AgentProvisioningService
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * @param string $agentType 'client' or 'hypervisor'
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
}
