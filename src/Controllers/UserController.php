<?php

namespace BBS\Controllers;

use BBS\Core\Controller;
use BBS\Services\PermissionService;
use BBS\Services\TwoFactorService;

class UserController extends Controller
{
    public function index(): void
    {
        $this->requireAdmin();

        $users = $this->db->fetchAll("
            SELECT u.*,
                   (SELECT COUNT(*) FROM user_agents ua WHERE ua.user_id = u.id) as agent_count
            FROM users u
            ORDER BY u.id
        ");

        $this->view('users/index', [
            'pageTitle' => 'User Management',
            'users' => $users,
        ]);
    }

    public function store(): void
    {
        $this->requireAdmin();
        $this->verifyCsrf();

        $username = trim($_POST['username'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $role = $_POST['role'] ?? 'user';

        if (empty($username) || empty($email) || empty($password)) {
            $this->flash('danger', 'All fields are required.');
            $this->redirect('/users');
        }

        $existing = $this->db->fetchOne("SELECT id FROM users WHERE username = ? OR email = ?", [$username, $email]);
        if ($existing) {
            $this->flash('danger', 'Username or email already exists.');
            $this->redirect('/users');
        }

        $userId = $this->db->insert('users', [
            'username' => $username,
            'email' => $email,
            'password_hash' => password_hash($password, PASSWORD_BCRYPT),
            'role' => in_array($role, ['admin', 'user']) ? $role : 'user',
        ]);

        $this->flash('success', "User \"{$username}\" created.");
        $this->redirect("/users/{$userId}/edit");
    }

    public function edit(int $id): void
    {
        $this->requireAdmin();

        $user = $this->db->fetchOne("SELECT * FROM users WHERE id = ?", [$id]);
        if (!$user) {
            $this->flash('danger', 'User not found.');
            $this->redirect('/users');
        }

        $permService = new PermissionService();
        $userPermissions = $permService->getUserPermissions($id);
        $userAgentIds = $permService->getUserAgentIds($id);
        $allAgents = $this->db->fetchAll("SELECT id, name FROM agents ORDER BY name");

        // Transform permissions into a more usable format for the view
        $permissionData = [];
        foreach (PermissionService::ALL_PERMISSIONS as $perm) {
            $permissionData[$perm] = [
                'enabled' => false,
                'global' => false,
                'agent_ids' => [],
            ];
        }
        foreach ($userPermissions as $up) {
            $perm = $up['permission'];
            $permissionData[$perm]['enabled'] = true;
            if ($up['agent_id'] === null) {
                $permissionData[$perm]['global'] = true;
            } else {
                $permissionData[$perm]['agent_ids'][] = $up['agent_id'];
            }
        }

        $this->view('users/edit', [
            'pageTitle' => 'Edit User',
            'user' => $user,
            'permissionData' => $permissionData,
            'userAgentIds' => $userAgentIds,
            'allAgents' => $allAgents,
            'allPermissions' => PermissionService::ALL_PERMISSIONS,
            'permissionLabels' => PermissionService::PERMISSION_LABELS,
        ]);
    }

    public function update(int $id): void
    {
        $this->requireAdmin();
        $this->verifyCsrf();

        $user = $this->db->fetchOne("SELECT * FROM users WHERE id = ?", [$id]);
        if (!$user) {
            $this->flash('danger', 'User not found.');
            $this->redirect('/users');
        }

        // Update basic fields
        $data = [];
        if (!empty($_POST['email'])) {
            $newEmail = trim($_POST['email']);
            // Check for duplicate email (exclude current user)
            $existingEmail = $this->db->fetchOne(
                "SELECT id FROM users WHERE email = ? AND id != ?",
                [$newEmail, $id]
            );
            if ($existingEmail) {
                $this->flash('danger', 'Email already in use by another user.');
                $this->redirect("/users/{$id}/edit");
            }
            $data['email'] = $newEmail;
        }
        if (!empty($_POST['role']) && in_array($_POST['role'], ['admin', 'user'])) {
            $data['role'] = $_POST['role'];
        }
        if (!empty($_POST['password'])) {
            $data['password_hash'] = password_hash($_POST['password'], PASSWORD_BCRYPT);
        }
        $data['all_clients'] = isset($_POST['all_clients']) ? 1 : 0;
        $data['can_create_clients'] = isset($_POST['can_create_clients']) ? 1 : 0;

        if (!empty($data)) {
            $this->db->update('users', $data, 'id = ?', [$id]);
        }

        // Update permissions (only for non-admin users)
        $newRole = $_POST['role'] ?? $user['role'];
        if ($newRole !== 'admin') {
            $permService = new PermissionService();
            $allClients = !empty($_POST['all_clients']);

            // Assign agents (only if not all_clients)
            if (!$allClients) {
                $agentIds = $_POST['agents'] ?? [];
                $permService->assignAgents($id, $agentIds);
            } else {
                // Clear agent assignments when all_clients is enabled
                $permService->assignAgents($id, []);
            }

            // Set permissions based on form mode
            $permissions = [];

            if ($allClients) {
                // All clients mode: use global permission checkboxes (perm_global_{permission})
                foreach (PermissionService::ALL_PERMISSIONS as $perm) {
                    if (isset($_POST['perm_global_' . $perm])) {
                        $permissions[] = ['permission' => $perm, 'agent_id' => null];
                    }
                }
            } else {
                // Specific clients mode: use per-agent permission checkboxes (perm_{permission}_{agent_id})
                $assignedAgentIds = $_POST['agents'] ?? [];
                foreach (PermissionService::ALL_PERMISSIONS as $perm) {
                    foreach ($assignedAgentIds as $agentId) {
                        if (isset($_POST['perm_' . $perm . '_' . $agentId])) {
                            $permissions[] = ['permission' => $perm, 'agent_id' => (int) $agentId];
                        }
                    }
                }
            }
            $permService->setPermissions($id, $permissions);
        } else {
            // Admin users don't need permissions - clear them
            $permService = new PermissionService();
            $permService->assignAgents($id, []);
            $permService->setPermissions($id, []);
        }

        $this->flash('success', 'User updated.');
        $this->redirect('/users');
    }

    public function reset2fa(int $id): void
    {
        $this->requireAdmin();
        $this->verifyCsrf();

        $user = $this->db->fetchOne("SELECT username, totp_enabled FROM users WHERE id = ?", [$id]);
        if (!$user) {
            $this->flash('danger', 'User not found.');
            $this->redirect('/users');
        }

        if (!$user['totp_enabled']) {
            $this->flash('warning', '2FA is not enabled for this user.');
            $this->redirect('/users');
        }

        $twoFactor = new TwoFactorService();
        $twoFactor->disableTotp($id);

        $this->flash('success', "2FA disabled for user \"{$user['username']}\".");
        $this->redirect('/users');
    }

    public function approveOidc(int $id): void
    {
        $this->requireAdmin();
        $this->verifyCsrf();

        $user = $this->db->fetchOne("SELECT * FROM users WHERE id = ?", [$id]);
        if (!$user || $user['oidc_status'] !== 'pending') {
            $this->flash('danger', 'User not found or not pending.');
            $this->redirect('/users');
        }

        $this->db->update('users', ['oidc_status' => 'active'], 'id = ?', [$id]);
        $this->flash('success', "User \"{$user['username']}\" approved for SSO access.");
        $this->redirect('/users');
    }

    public function delete(int $id): void
    {
        $this->requireAdmin();
        $this->verifyCsrf();

        if ($id == $_SESSION['user_id']) {
            $this->flash('danger', 'You cannot delete your own account.');
            $this->redirect('/users');
        }

        $user = $this->db->fetchOne("SELECT username FROM users WHERE id = ?", [$id]);
        $this->db->delete('users', 'id = ?', [$id]);
        $this->flash('success', "User \"{$user['username']}\" deleted.");
        $this->redirect('/users');
    }
}
