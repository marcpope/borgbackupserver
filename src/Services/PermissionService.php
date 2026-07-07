<?php

namespace BBS\Services;

use BBS\Core\Database;

class PermissionService
{
    private Database $db;

    // Permission constants
    public const TRIGGER_BACKUP = 'trigger_backup';
    public const MANAGE_REPOS = 'manage_repos';
    public const MANAGE_PLANS = 'manage_plans';
    public const RESTORE = 'restore';
    public const REPO_MAINTENANCE = 'repo_maintenance';

    public const ALL_PERMISSIONS = [
        self::TRIGGER_BACKUP,
        self::MANAGE_REPOS,
        self::MANAGE_PLANS,
        self::RESTORE,
        self::REPO_MAINTENANCE,
    ];

    public const PERMISSION_LABELS = [
        self::TRIGGER_BACKUP => 'Trigger Backups',
        self::MANAGE_REPOS => 'Manage Repositories',
        self::MANAGE_PLANS => 'Manage Backup Plans',
        self::RESTORE => 'Perform Restores',
        self::REPO_MAINTENANCE => 'Repository Maintenance',
    ];

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * Check if user can access a specific agent (client).
     */
    public function canAccessAgent(int $userId, int $agentId): bool
    {
        // Admins bypass all checks
        if ($this->isAdmin($userId)) {
            return true;
        }

        // Check all_clients flag
        $user = $this->db->fetchOne("SELECT all_clients FROM users WHERE id = ?", [$userId]);
        if ($user && $user['all_clients']) {
            return true;
        }

        // Check junction table
        $assignment = $this->db->fetchOne(
            "SELECT id FROM user_agents WHERE user_id = ? AND agent_id = ?",
            [$userId, $agentId]
        );

        return $assignment !== null;
    }

    /**
     * Check if user has a specific permission on an agent.
     */
    public function hasPermission(int $userId, string $permission, int $agentId): bool
    {
        // Admins bypass all checks
        if ($this->isAdmin($userId)) {
            return true;
        }

        // First verify user has access to the agent
        if (!$this->canAccessAgent($userId, $agentId)) {
            return false;
        }

        // Check for global permission (agent_id IS NULL)
        $globalPerm = $this->db->fetchOne(
            "SELECT id FROM user_permissions WHERE user_id = ? AND permission = ? AND agent_id IS NULL",
            [$userId, $permission]
        );
        if ($globalPerm) {
            return true;
        }

        // Check for agent-specific permission
        $specificPerm = $this->db->fetchOne(
            "SELECT id FROM user_permissions WHERE user_id = ? AND permission = ? AND agent_id = ?",
            [$userId, $permission, $agentId]
        );

        return $specificPerm !== null;
    }

    /**
     * Get all agents a user can access.
     */
    public function getAccessibleAgentIds(int $userId): array
    {
        if ($this->isAdmin($userId)) {
            $rows = $this->db->fetchAll("SELECT id FROM agents");
            return array_column($rows, 'id');
        }

        $user = $this->db->fetchOne("SELECT all_clients FROM users WHERE id = ?", [$userId]);
        if ($user && $user['all_clients']) {
            $rows = $this->db->fetchAll("SELECT id FROM agents");
            return array_column($rows, 'id');
        }

        $rows = $this->db->fetchAll(
            "SELECT agent_id FROM user_agents WHERE user_id = ?",
            [$userId]
        );
        return array_column($rows, 'agent_id');
    }

    /**
     * Get SQL WHERE clause for agent filtering.
     * Returns [where_clause, params] tuple.
     */
    public function getAgentWhereClause(int $userId, string $agentAlias = 'a'): array
    {
        if ($this->isAdmin($userId)) {
            return ['1=1', []];
        }

        $user = $this->db->fetchOne("SELECT all_clients FROM users WHERE id = ?", [$userId]);
        if ($user && $user['all_clients']) {
            return ['1=1', []];
        }

        return [
            "{$agentAlias}.id IN (SELECT agent_id FROM user_agents WHERE user_id = ?)",
            [$userId]
        ];
    }

    /**
     * Grant a client's owner the default full access: visibility plus all
     * five permissions on that agent (#337). Rows are materialized (not
     * implicit) so an admin can back individual permissions off afterward
     * in the user's profile. Idempotent — existing rows are left alone.
     */
    public function grantOwnerDefaults(int $userId, int $agentId): void
    {
        $this->db->query(
            "INSERT IGNORE INTO user_agents (user_id, agent_id) VALUES (?, ?)",
            [$userId, $agentId]
        );
        foreach (self::ALL_PERMISSIONS as $permission) {
            $this->db->query(
                "INSERT IGNORE INTO user_permissions (user_id, permission, agent_id) VALUES (?, ?, ?)",
                [$userId, $permission, $agentId]
            );
        }
    }

    /**
     * Assign user to agents.
     */
    public function assignAgents(int $userId, array $agentIds): void
    {
        // Remove existing assignments
        $this->db->delete('user_agents', 'user_id = ?', [$userId]);

        // Add new assignments
        foreach ($agentIds as $agentId) {
            if (!empty($agentId)) {
                $this->db->insert('user_agents', [
                    'user_id' => $userId,
                    'agent_id' => (int) $agentId,
                ]);
            }
        }
    }

    /**
     * Set user permissions (replaces all existing permissions).
     * Format: [['permission' => 'trigger_backup', 'agent_id' => null], ...]
     */
    public function setPermissions(int $userId, array $permissions): void
    {
        // Remove existing permissions
        $this->db->delete('user_permissions', 'user_id = ?', [$userId]);

        // Add new permissions
        foreach ($permissions as $perm) {
            if (!empty($perm['permission']) && in_array($perm['permission'], self::ALL_PERMISSIONS)) {
                $this->db->insert('user_permissions', [
                    'user_id' => $userId,
                    'permission' => $perm['permission'],
                    'agent_id' => $perm['agent_id'] ?? null,
                ]);
            }
        }
    }

    /**
     * Get user's current permissions.
     */
    public function getUserPermissions(int $userId): array
    {
        return $this->db->fetchAll(
            "SELECT permission, agent_id FROM user_permissions WHERE user_id = ? ORDER BY permission, agent_id",
            [$userId]
        );
    }

    /**
     * Get user's assigned agents.
     */
    public function getUserAgents(int $userId): array
    {
        return $this->db->fetchAll(
            "SELECT a.id, a.name FROM agents a
             JOIN user_agents ua ON ua.agent_id = a.id
             WHERE ua.user_id = ?
             ORDER BY a.name",
            [$userId]
        );
    }

    /**
     * Get user's assigned agent IDs.
     */
    public function getUserAgentIds(int $userId): array
    {
        $rows = $this->db->fetchAll(
            "SELECT agent_id FROM user_agents WHERE user_id = ?",
            [$userId]
        );
        return array_column($rows, 'agent_id');
    }

    /**
     * Check if user has a global permission (applies to all their agents).
     */
    public function hasGlobalPermission(int $userId, string $permission): bool
    {
        $perm = $this->db->fetchOne(
            "SELECT id FROM user_permissions WHERE user_id = ? AND permission = ? AND agent_id IS NULL",
            [$userId, $permission]
        );
        return $perm !== null;
    }

    /**
     * Set all_clients flag for user.
     */
    public function setAllClients(int $userId, bool $allClients): void
    {
        $this->db->update('users', ['all_clients' => $allClients ? 1 : 0], 'id = ?', [$userId]);
    }

    /**
     * Check if user has all_clients flag.
     */
    public function hasAllClients(int $userId): bool
    {
        $user = $this->db->fetchOne("SELECT all_clients FROM users WHERE id = ?", [$userId]);
        return $user && (bool) $user['all_clients'];
    }

    private function isAdmin(int $userId): bool
    {
        $user = $this->db->fetchOne("SELECT role FROM users WHERE id = ?", [$userId]);
        return $user && $user['role'] === 'admin';
    }
}
