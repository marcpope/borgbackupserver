<?php

namespace BBS\Services;

use BBS\Core\Database;

/**
 * Replacing a client's API key.
 *
 * A key was issued once, when the client was created, and could not be changed
 * afterwards — so a leaked key could only be revoked by deleting the client and
 * adding it back, taking its plans, schedules, repositories and catalogue with
 * it (#433).
 *
 * Rotation is immediate and there is no grace period. The point of the button
 * is to stop a leaked key from working, and a key that keeps working for an
 * hour after being revoked has not been revoked. The consequence is that the
 * client stops reporting until its own configuration is updated, which the
 * caller is told plainly rather than discovering later.
 */
class AgentKeyService
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * Issue a new key and stop accepting the old one.
     *
     * @return string The new key, in plaintext. Stored encrypted for the
     *                install command; this is the only time it is returned by
     *                a write, and callers show it to the admin.
     */
    public function rotate(int $agentId, ?string $actor = null): string
    {
        $key = bin2hex(random_bytes(32));

        $this->db->update('agents', [
            'api_key_hash'      => hash('sha256', $key),
            'api_key_encrypted' => Encryption::encrypt($key),
            // Older records kept the key in plaintext here. Clearing it stops
            // the legacy authentication fallback from accepting the old key.
            'api_key'           => null,
        ], 'id = ?', [$agentId]);

        $agent = $this->db->fetchOne("SELECT name FROM agents WHERE id = ?", [$agentId]);
        $by = $actor ? " by {$actor}" : '';
        $this->db->insert('server_log', [
            'agent_id' => $agentId,
            'level' => 'warning',
            'message' => "API key replaced{$by} for client \"" . ($agent['name'] ?? $agentId)
                . "\" — the previous key no longer works and the client cannot report until it is reconfigured",
        ]);

        return $key;
    }

    /**
     * How to put the new key on the client, given what we know about it.
     *
     * The agent reads its key from a config file, or from an environment
     * variable when it runs as a container, so there is no single instruction
     * that fits every install.
     */
    public function reconfigureHint(array $agent): array
    {
        $platform = strtolower((string) ($agent['platform'] ?? ''));

        if ($platform === 'windows') {
            return [
                'config.ini in the agent directory (C:\\ProgramData\\bbs-agent), then restart the BBS Agent service',
                'Set api_key under [server], then: Restart-Service bbs-agent',
            ];
        }

        return [
            '/etc/bbs-agent/config.ini, then restart the agent',
            "Set api_key under [server], then: sudo systemctl restart bbs-agent\n"
                . 'A containerised agent takes its key from BBS_API_KEY — update that and recreate the container.',
        ];
    }
}
