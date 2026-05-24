<?php

namespace BBS\Core;

class Controller
{
    protected Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    protected function view(string $template, array $data = []): void
    {
        extract($data);
        $viewPath = dirname(__DIR__) . '/Views/';
        require $viewPath . 'layouts/app.php';
    }

    protected function authView(string $template, array $data = []): void
    {
        // Load branding and theme settings for auth pages. branding_icon is the
        // small navbar logo, reused on the mobile login header (the big
        // login_logo is too large for a phone-width pane).
        $brandingRows = $this->db->fetchAll("SELECT `key`, `value` FROM settings WHERE `key` IN ('default_theme', 'branding_login_logo', 'branding_login_theme', 'branding_icon')");
        $brandSettings = [];
        foreach ($brandingRows as $row) {
            $brandSettings[$row['key']] = $row['value'];
        }
        if (!isset($data['defaultTheme'])) {
            // Login theme override takes priority, then system default
            $loginTheme = $brandSettings['branding_login_theme'] ?? 'default';
            $data['defaultTheme'] = ($loginTheme !== 'default') ? $loginTheme : ($brandSettings['default_theme'] ?? 'dark');
            $data['loginThemeForced'] = ($loginTheme !== 'default');
        }
        if (!isset($data['loginLogo'])) {
            $data['loginLogo'] = $brandSettings['branding_login_logo'] ?? null;
        }
        if (!isset($data['brandingIcon'])) {
            $data['brandingIcon'] = $brandSettings['branding_icon'] ?? null;
        }
        extract($data);
        $viewPath = dirname(__DIR__) . '/Views/';
        require $viewPath . 'layouts/auth.php';
    }

    protected function redirect(string $url): void
    {
        header("Location: {$url}");
        exit;
    }

    protected function json(mixed $data, int $code = 200): void
    {
        http_response_code($code);
        header('Content-Type: application/json');
        echo json_encode($data);
        exit;
    }

    protected function requireAuth(): void
    {
        if (empty($_SESSION['user_id'])) {
            $this->redirect('/login');
        }

        // Session timeout (configurable, default 8 hours of inactivity)
        $timeoutSetting = $this->db->fetchOne("SELECT `value` FROM settings WHERE `key` = 'session_timeout_hours'");
        $timeoutHours = (int) ($timeoutSetting['value'] ?? 8);
        if ($timeoutHours < 1) $timeoutHours = 1;
        $timeout = $timeoutHours * 3600;
        if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > $timeout) {
            session_destroy();
            session_start();
            $this->flash('warning', 'Session expired. Please log in again.');
            $this->redirect('/login');
        }
        $_SESSION['last_activity'] = time();

        // Force 2FA: redirect users without 2FA to profile setup
        // Skip for OIDC users — they rely on their identity provider for auth strength
        $currentUri = $_SERVER['REQUEST_URI'] ?? '';
        if (($_SESSION['auth_provider'] ?? 'local') !== 'oidc'
            && !str_starts_with($currentUri, '/profile') && !str_starts_with($currentUri, '/logout')) {
            $force2fa = $this->db->fetchOne("SELECT `value` FROM settings WHERE `key` = 'force_2fa'");
            if ($force2fa && $force2fa['value'] === '1') {
                $user = $this->db->fetchOne("SELECT totp_enabled FROM users WHERE id = ?", [$_SESSION['user_id']]);
                if ($user && $user['totp_enabled'] == 0) {
                    $this->flash('warning', 'Two-factor authentication is required. Please set it up now.');
                    $this->redirect('/profile?tab=2fa');
                }
            }
        }
    }

    protected function requireAdmin(): void
    {
        $this->requireAuth();
        if (($_SESSION['user_role'] ?? '') !== 'admin') {
            http_response_code(403);
            echo 'Access denied';
            exit;
        }
    }

    /**
     * Authenticate via Bearer token for admin API endpoints.
     * Returns the user record associated with the token.
     */
    protected function requireApiToken(): array
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        $token = '';
        if (str_starts_with($header, 'Bearer ')) {
            $token = substr($header, 7);
        }

        if (empty($token)) {
            $this->json(['error' => 'Missing authorization token. Use: Authorization: Bearer <token>'], 401);
        }

        if (!$this->checkRateLimit('admin_api', 20, 300)) {
            $this->json(['error' => 'Too many failed attempts. Try again later.'], 429);
        }

        $hash = hash('sha256', $token);
        $apiToken = $this->db->fetchOne(
            "SELECT t.*, u.role FROM api_tokens t JOIN users u ON u.id = t.user_id WHERE t.token_hash = ?",
            [$hash]
        );

        if (!$apiToken) {
            $this->json(['error' => 'Invalid API token'], 401);
        }

        if ($apiToken['role'] !== 'admin') {
            $this->json(['error' => 'API token must belong to an admin user'], 403);
        }

        $this->resetRateLimit('admin_api');

        $this->db->query("UPDATE api_tokens SET last_used_at = NOW() WHERE id = ?", [$apiToken['id']]);

        return [
            'id' => $apiToken['user_id'],
            'token_id' => $apiToken['id'],
            'token_name' => $apiToken['name'],
        ];
    }

    protected function currentUser(): ?array
    {
        if (empty($_SESSION['user_id'])) {
            return null;
        }
        return [
            'id' => $_SESSION['user_id'],
            'username' => $_SESSION['username'],
            'role' => $_SESSION['user_role'],
        ];
    }

    protected function isAdmin(): bool
    {
        return ($_SESSION['user_role'] ?? '') === 'admin';
    }

    /**
     * Check if current user can access a specific agent (client).
     */
    protected function canAccessAgent(int $agentId): bool
    {
        if ($this->isAdmin()) {
            return true;
        }
        $permService = new \BBS\Services\PermissionService();
        return $permService->canAccessAgent($_SESSION['user_id'], $agentId);
    }

    /**
     * Check if current user has a specific permission on an agent.
     */
    protected function hasPermission(string $permission, int $agentId): bool
    {
        if ($this->isAdmin()) {
            return true;
        }
        $permService = new \BBS\Services\PermissionService();
        return $permService->hasPermission($_SESSION['user_id'], $permission, $agentId);
    }

    /**
     * Require a specific permission, redirecting with error if denied.
     */
    protected function requirePermission(string $permission, int $agentId): void
    {
        if (!$this->hasPermission($permission, $agentId)) {
            $this->flash('danger', 'You do not have permission to perform this action.');
            $this->redirect('/clients');
        }
    }

    /**
     * Get SQL WHERE clause for agent filtering based on user permissions.
     * Returns [where_clause, params] tuple.
     */
    protected function getAgentWhereClause(string $agentAlias = 'a'): array
    {
        if ($this->isAdmin()) {
            return ['1=1', []];
        }
        $permService = new \BBS\Services\PermissionService();
        return $permService->getAgentWhereClause($_SESSION['user_id'], $agentAlias);
    }

    protected function csrfToken(): string
    {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    protected function verifyCsrf(): void
    {
        // Form posts put it in $_POST; JSON fetch calls send it as a header.
        $token = $_POST['csrf_token']
            ?? $_SERVER['HTTP_X_CSRF_TOKEN']
            ?? '';
        if (!hash_equals($this->csrfToken(), $token)) {
            http_response_code(403);
            echo 'Invalid CSRF token';
            exit;
        }
    }

    /**
     * Check rate limit. Returns true if allowed, false if rate-limited.
     */
    protected function checkRateLimit(string $endpoint, int $maxAttempts = 10, int $windowSeconds = 300): bool
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        // Clean old entries
        $this->db->query(
            "DELETE FROM rate_limits WHERE window_start < DATE_SUB(NOW(), INTERVAL ? SECOND)",
            [$windowSeconds]
        );

        $row = $this->db->fetchOne(
            "SELECT * FROM rate_limits WHERE ip_address = ? AND endpoint = ? AND window_start > DATE_SUB(NOW(), INTERVAL ? SECOND)",
            [$ip, $endpoint, $windowSeconds]
        );

        if ($row) {
            if ($row['attempts'] >= $maxAttempts) {
                return false;
            }
            $this->db->query(
                "UPDATE rate_limits SET attempts = attempts + 1 WHERE id = ?",
                [$row['id']]
            );
        } else {
            $this->db->insert('rate_limits', [
                'ip_address' => $ip,
                'endpoint' => $endpoint,
            ]);
        }

        return true;
    }

    /**
     * Reset rate limit on successful action (e.g. after login).
     */
    protected function resetRateLimit(string $endpoint): void
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $this->db->delete('rate_limits', 'ip_address = ? AND endpoint = ?', [$ip, $endpoint]);
    }

    protected function flash(string $type, string $message): void
    {
        $_SESSION['flash'] = ['type' => $type, 'message' => $message];
    }

    protected function getFlash(): ?array
    {
        $flash = $_SESSION['flash'] ?? null;
        unset($_SESSION['flash']);
        return $flash;
    }
}
