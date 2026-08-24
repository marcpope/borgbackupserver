<?php

namespace BBS\Controllers\Api;

use BBS\Controllers\AuthController;
use BBS\Core\Controller;
use BBS\Services\OidcService;
use BBS\Services\TwoFactorService;

/**
 * Token-authenticated sign-in endpoints. Password/TOTP/SSO sign-in
 * flows that mint kind='mobile' bearer tokens, plus session management.
 *
 * Mobile tokens authenticate through requireApiAuth() (no admin gate) and
 * are scoped per-agent via PermissionService, so a non-admin operator sees
 * exactly the clients they own. They can never read secrets — see
 * Controller::tokenCanReadSecrets().
 */
class MobileAuthController extends Controller
{
    /** Custom URL schemes the OIDC broker may bounce back to. */
    private const ALLOWED_APP_REDIRECTS = ['bbsapp://auth'];

    private function getJsonInput(): array
    {
        $raw = file_get_contents('php://input');
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }

    // ── Discovery ────────────────────────────────────────

    /**
     * GET /api/v1/auth/discover — unauthenticated server fingerprint so the
     * app can render the right login controls before a password is typed.
     * Returns nothing an anonymous visitor can't already see on /login.
     */
    public function discover(): void
    {
        if (!$this->checkRateLimit('auth_discover', 30, 300)) {
            $this->json(['error' => 'Too many requests. Try again later.'], 429);
        }

        $oidc = new OidcService();
        $siteName = $this->db->fetchOne("SELECT `value` FROM settings WHERE `key` = 'branding_site_name'");
        $theme = $this->db->fetchOne("SELECT `value` FROM settings WHERE `key` = 'default_theme'");
        $version = trim(@file_get_contents(dirname(__DIR__, 3) . '/VERSION') ?: '');

        $this->json([
            'product' => 'Borg Backup Server',
            'server_name' => $siteName['value'] ?? 'Borg Backup Server',
            'version' => $version,
            'api_version' => 1,
            'local_login_enabled' => !AuthController::isLocalLoginDisabled($oidc),
            'oidc_enabled' => $oidc->isEnabled(),
            'oidc_button_label' => $oidc->isEnabled() ? $oidc->getButtonLabel() : null,
            'logo_url' => '/branding/icon/180',
            'default_theme' => $theme['value'] ?? 'dark',
        ]);
    }

    // ── Password login ───────────────────────────────────

    /**
     * POST /api/v1/auth/login — username/password sign-in. Mirrors the web
     * flow including its rate limiter and the 2FA handoff.
     */
    public function login(): void
    {
        if (!$this->checkRateLimit('login', 5, 300)) {
            $this->json(['error' => 'Too many login attempts. Please wait a few minutes.'], 429);
        }

        if (AuthController::isLocalLoginDisabled()) {
            $this->json(['error' => 'Local login is disabled. Please sign in with SSO.'], 403);
        }

        $input = $this->getJsonInput();
        $username = trim($input['username'] ?? '');
        $password = $input['password'] ?? '';

        if (empty($username) || empty($password)) {
            $this->json(['error' => 'Username and password are required.'], 401);
        }

        $user = $this->db->fetchOne("SELECT * FROM users WHERE username = ?", [$username]);

        if (!$user || !password_verify($password, $user['password_hash'])) {
            $this->json(['error' => 'Invalid username or password.'], 401);
        }

        $this->resetRateLimit('login');

        if ($user['totp_enabled'] == 1) {
            // Stateless replacement for the web's $_SESSION['2fa_user_id']:
            // an opaque single-use challenge with the same 300s window.
            $challenge = bin2hex(random_bytes(32));
            $this->db->insert('auth_challenges', [
                'kind' => '2fa',
                'challenge_hash' => hash('sha256', $challenge),
                'user_id' => $user['id'],
                'payload' => null,
                'expires_at' => date('Y-m-d H:i:s', time() + 300),
            ]);
            $this->json(['status' => '2fa_required', 'challenge' => $challenge, 'expires_in' => 300]);
        }

        $this->issueToken($user, $input);
    }

    /**
     * POST /api/v1/auth/2fa — complete a login that required TOTP. The
     * challenge from /auth/login is the credential.
     */
    public function twoFactor(): void
    {
        if (!$this->checkRateLimit('2fa_verify', 10, 300)) {
            $this->json(['error' => 'Too many 2FA attempts. Please wait a few minutes.'], 429);
        }

        $input = $this->getJsonInput();
        $challenge = $input['challenge'] ?? '';
        $code = trim($input['code'] ?? '');

        if (empty($challenge) || empty($code)) {
            $this->json(['error' => 'Challenge and code are required.'], 401);
        }

        // Expired rows are deleted rather than filtered so the table can't
        // accumulate; the UNIQUE hash makes replay after delete impossible.
        $this->db->delete('auth_challenges', "expires_at < NOW()", []);

        $row = $this->db->fetchOne(
            "SELECT * FROM auth_challenges WHERE kind = '2fa' AND challenge_hash = ? AND expires_at >= NOW()",
            [hash('sha256', $challenge)]
        );
        if (!$row) {
            $this->json(['error' => 'Challenge expired or already used. Sign in again.'], 410);
        }

        $userId = (int) $row['user_id'];
        $twoFactor = new TwoFactorService();
        $valid = false;
        $recoveryRemaining = null;

        $secret = $twoFactor->getUserSecret($userId);
        if ($secret && $twoFactor->verifyTotp($secret, $code)) {
            $valid = true;
        }

        if (!$valid && preg_match('/^[A-Z0-9]{4}-[A-Z0-9]{4}$/i', $code)) {
            if ($twoFactor->verifyRecoveryCode($userId, strtoupper($code))) {
                $valid = true;
                $recoveryRemaining = $twoFactor->getRemainingRecoveryCodeCount($userId);
            }
        }

        if (!$valid) {
            $this->json(['error' => 'Invalid 2FA code.'], 401);
        }

        // Single use — consume before minting.
        $this->db->delete('auth_challenges', 'id = ?', [$row['id']]);
        $this->resetRateLimit('2fa_verify');

        $user = $this->db->fetchOne("SELECT * FROM users WHERE id = ?", [$userId]);
        if (!$user) {
            $this->json(['error' => 'User no longer exists.'], 401);
        }

        $extra = [];
        if ($recoveryRemaining !== null) {
            $extra['recovery_codes_remaining'] = $recoveryRemaining;
        }
        $this->issueToken($user, $input, $extra);
    }

    // ── SSO, brokered by the server ──────────────────────

    /**
     * GET /api/v1/auth/oidc/start — kick off the provider redirect inside
     * the app's ASWebAuthenticationSession. Device info is stashed in the
     * session; the IdP returns to the SAME registered web callback
     * (/login/oidc/callback), where AuthController spots the marker and
     * finishes with a one-time exchange code instead of a web session.
     * No IdP configuration changes needed.
     */
    public function oidcStart(): void
    {
        if (!$this->checkRateLimit('oidc_login', 10, 300)) {
            $this->json(['error' => 'Too many login attempts. Please wait a few minutes.'], 429);
        }

        $oidcService = new OidcService();
        if (!$oidcService->isEnabled()) {
            $this->json(['error' => 'SSO is not configured.'], 404);
        }

        $redirect = $_GET['redirect'] ?? '';
        if (!in_array($redirect, self::ALLOWED_APP_REDIRECTS, true)) {
            $this->json(['error' => 'Invalid redirect.'], 400);
        }

        $_SESSION['mobile_oidc'] = [
            'device_id' => substr(trim($_GET['device_id'] ?? ''), 0, 64),
            'device_name' => substr(trim($_GET['device_name'] ?? ''), 0, 100),
            'redirect' => $redirect,
            'state' => bin2hex(random_bytes(16)),
            'started_at' => time(),
        ];

        // Same redirect-URI resolution as the web flow (settings override
        // first, then server_host) so the IdP sees its registered URL.
        $redirectOverride = $this->db->fetchOne("SELECT `value` FROM settings WHERE `key` = 'oidc_redirect_url'");
        if (!empty($redirectOverride['value'])) {
            $redirectUri = trim($redirectOverride['value']);
        } else {
            $serverHost = $this->db->fetchOne("SELECT `value` FROM settings WHERE `key` = 'server_host'");
            $host = $serverHost['value'] ?? $_SERVER['HTTP_HOST'] ?? 'localhost';
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
                ? 'https' : 'http';
            $redirectUri = "{$scheme}://{$host}/login/oidc/callback";
        }

        try {
            $oidcService->redirectToProvider($redirectUri);
        } catch (\Exception $e) {
            unset($_SESSION['mobile_oidc']);
            $this->json(['error' => 'SSO error: ' . $e->getMessage()], 500);
        }
    }

    /**
     * POST /api/v1/auth/oidc/exchange — swap the one-time code from the
     * app-scheme redirect for a real bearer token.
     */
    public function oidcExchange(): void
    {
        if (!$this->checkRateLimit('oidc_exchange', 10, 300)) {
            $this->json(['error' => 'Too many attempts. Try again later.'], 429);
        }

        $input = $this->getJsonInput();
        $code = $input['code'] ?? '';
        if (empty($code)) {
            $this->json(['error' => 'Code is required.'], 401);
        }

        $this->db->delete('auth_challenges', "expires_at < NOW()", []);

        $row = $this->db->fetchOne(
            "SELECT * FROM auth_challenges WHERE kind = 'oidc_exchange' AND challenge_hash = ? AND expires_at >= NOW()",
            [hash('sha256', $code)]
        );
        if (!$row) {
            $this->json(['error' => 'Exchange code expired or already used.'], 410);
        }

        // Single use — consume immediately.
        $this->db->delete('auth_challenges', 'id = ?', [$row['id']]);

        $payload = json_decode($row['payload'] ?? '', true) ?: [];
        if (!empty($payload['state']) && ($input['state'] ?? '') !== $payload['state']) {
            $this->json(['error' => 'State mismatch.'], 401);
        }

        $user = $this->db->fetchOne("SELECT * FROM users WHERE id = ?", [(int) $row['user_id']]);
        if (!$user) {
            $this->json(['error' => 'User no longer exists.'], 401);
        }

        // Device info was captured at oidc/start; the request body may
        // repeat it but the session-captured copy wins.
        $device = [
            'device_id' => $payload['device_id'] ?: ($input['device_id'] ?? ''),
            'device_name' => $payload['device_name'] ?: ($input['device_name'] ?? ''),
        ];
        $this->issueToken($user, $device);
    }

    // ── Session endpoints ────────────────────────────────

    /**
     * GET /api/v1/auth/me — validate a stored token on cold start.
     */
    public function me(): void
    {
        $ctx = $this->requireApiAuth();
        $user = $this->db->fetchOne("SELECT * FROM users WHERE id = ?", [(int) $ctx['id']]);
        if (!$user) {
            $this->json(['error' => 'User no longer exists.'], 401);
        }

        // The five action permissions, meaning "granted anywhere" — globally
        // or on any single agent — so a client can hide buttons that would
        // 403 everywhere. A button shown because of one agent's grant can
        // still 403 on another agent; that answer carries its reason and the
        // client shows it as-is.
        $permissions = [];
        if ($user['role'] === 'admin') {
            foreach (\BBS\Services\PermissionService::ALL_PERMISSIONS as $perm) {
                $permissions[$perm] = true;
            }
        } else {
            $granted = array_column($this->db->fetchAll(
                "SELECT DISTINCT permission FROM user_permissions WHERE user_id = ?",
                [(int) $user['id']]
            ), 'permission');
            foreach (\BBS\Services\PermissionService::ALL_PERMISSIONS as $perm) {
                $permissions[$perm] = in_array($perm, $granted, true);
            }
        }

        $this->json([
            'user' => $this->userPayload($user),
            'capabilities' => [
                'is_admin' => $user['role'] === 'admin',
                'all_clients' => $user['role'] === 'admin' || !empty($user['all_clients']),
            ],
            'permissions' => $permissions,
        ]);
    }

    /**
     * POST /api/v1/auth/logout — delete the calling token. Always 204.
     */
    public function logout(): void
    {
        $ctx = $this->requireApiAuth();
        $this->db->delete('api_tokens', 'id = ?', [(int) $ctx['token_id']]);
        http_response_code(204);
        exit;
    }

    /**
     * GET /api/v1/auth/sessions — this user's mobile tokens.
     */
    public function sessions(): void
    {
        $ctx = $this->requireApiAuth();
        $rows = $this->db->fetchAll(
            "SELECT id, device_name, created_at, last_used_at, last_seen_ip
             FROM api_tokens WHERE user_id = ? AND kind = 'mobile' ORDER BY created_at DESC",
            [(int) $ctx['id']]
        );
        foreach ($rows as &$r) {
            $r['current'] = ((int) $r['id'] === (int) $ctx['token_id']);
        }
        $this->json(['sessions' => $rows]);
    }

    /**
     * DELETE /api/v1/auth/sessions/{id} — revoke one device, including
     * remotely wiping a lost phone.
     */
    public function deleteSession(int $id): void
    {
        $ctx = $this->requireApiAuth();
        $this->db->delete('api_tokens', "id = ? AND user_id = ? AND kind = 'mobile'", [$id, (int) $ctx['id']]);
        http_response_code(204);
        exit;
    }

    // ── Helpers ──────────────────────────────────────────

    /**
     * Mint a kind='mobile' token for a signed-in user and emit the
     * standard login success payload. A re-login from the same device_id
     * replaces that device's previous token instead of piling up rows.
     */
    private function issueToken(array $user, array $input, array $extra = []): void
    {
        $deviceId = substr(trim($input['device_id'] ?? ''), 0, 64);
        $deviceName = substr(trim($input['device_name'] ?? ''), 0, 100) ?: 'Mobile device';

        if ($deviceId !== '') {
            $this->db->delete(
                'api_tokens',
                "user_id = ? AND kind = 'mobile' AND device_id = ?",
                [$user['id'], $deviceId]
            );
        }

        $token = 'bbs_tok_' . bin2hex(random_bytes(24));
        $this->db->insert('api_tokens', [
            'name' => $deviceName,
            'kind' => 'mobile',
            'can_read_secrets' => 0,
            'token_hash' => hash('sha256', $token),
            'user_id' => $user['id'],
            'device_name' => $deviceName,
            'device_id' => $deviceId ?: null,
            'expires_at' => null,
            'last_seen_ip' => $_SERVER['REMOTE_ADDR'] ?? null,
        ]);

        $this->json(array_merge([
            'status' => 'ok',
            'token' => $token,
            'expires_at' => null,
            'user' => $this->userPayload($user),
        ], $extra));
    }

    private function userPayload(array $user): array
    {
        $defaultTheme = $this->db->fetchOne("SELECT `value` FROM settings WHERE `key` = 'default_theme'");
        return [
            'id' => (int) $user['id'],
            'username' => $user['username'],
            'email' => $user['email'] ?? null,
            'role' => $user['role'],
            'timezone' => $user['timezone'] ?? 'America/New_York',
            'time_format' => $user['time_format'] ?? '12h',
            'theme' => $user['theme'] ?? $defaultTheme['value'] ?? 'dark',
            'all_clients' => $user['role'] === 'admin' || !empty($user['all_clients']),
        ];
    }
}
