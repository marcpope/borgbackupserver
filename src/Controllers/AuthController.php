<?php

namespace BBS\Controllers;

use BBS\Core\Controller;
use BBS\Services\Mailer;
use BBS\Services\OidcService;
use BBS\Services\TwoFactorService;

class AuthController extends Controller
{
    public function loginForm(): void
    {
        if (!empty($_SESSION['user_id'])) {
            $this->redirect('/');
        }

        $flash = $this->getFlash();
        $oidcService = new OidcService();
        $this->authView('auth/login', [
            'flash' => $flash,
            'oidcEnabled' => $oidcService->isEnabled(),
            'oidcButtonLabel' => $oidcService->getButtonLabel(),
            'localLoginDisabled' => $this->localLoginDisabled($oidcService),
        ]);
    }

    /**
     * Whether username/password login is turned off in favour of SSO (#302).
     *
     * Controlled by the DISABLE_LOCAL_LOGIN env var (Docker) or a
     * 'disable_local_login' setting. Only takes effect when OIDC is actually
     * enabled — otherwise it's ignored, so a misconfiguration can't lock
     * everyone out. Break-glass: clear the env var / setting (Docker: remove it
     * and restart) to restore local login.
     */
    private function localLoginDisabled(?OidcService $oidc = null): bool
    {
        $oidc = $oidc ?? new OidcService();
        if (!$oidc->isEnabled()) {
            return false;
        }
        $env = $_ENV['DISABLE_LOCAL_LOGIN'] ?? getenv('DISABLE_LOCAL_LOGIN');
        if ($env !== false && $env !== null && $env !== ''
            && in_array(strtolower((string) $env), ['1', 'true', 'yes', 'on'], true)) {
            return true;
        }
        $row = $this->db->fetchOne("SELECT `value` FROM settings WHERE `key` = 'disable_local_login'");
        return ($row['value'] ?? '0') === '1';
    }

    public function login(): void
    {
        // Rate limit: 5 attempts per 5 minutes
        if (!$this->checkRateLimit('login', 5, 300)) {
            $this->flash('danger', 'Too many login attempts. Please wait a few minutes.');
            $this->redirect('/login');
        }

        // Local login disabled (SSO-only) — reject password auth even on a
        // direct POST, not just by hiding the form (#302).
        if ($this->localLoginDisabled()) {
            $this->flash('danger', 'Local login is disabled. Please sign in with SSO.');
            $this->redirect('/login');
        }

        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        if (empty($username) || empty($password)) {
            $this->flash('danger', 'Username and password are required.');
            $this->redirect('/login');
        }

        $user = $this->db->fetchOne(
            "SELECT * FROM users WHERE username = ?",
            [$username]
        );

        if (!$user || !password_verify($password, $user['password_hash'])) {
            $this->flash('danger', 'Invalid username or password.');
            $this->redirect('/login');
        }

        // Password verified — check if 2FA is enabled
        if ($user['totp_enabled'] == 1) {
            $_SESSION['2fa_user_id'] = $user['id'];
            $_SESSION['2fa_username'] = $user['username'];
            $_SESSION['2fa_timestamp'] = time();
            $this->resetRateLimit('login');
            $this->redirect('/login/2fa');
        }

        $this->completeLogin($user);
    }

    private function completeLogin(array $user): void
    {
        $this->resetRateLimit('login');
        session_regenerate_id(true);

        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['user_role'] = $user['role'];
        $_SESSION['timezone'] = $user['timezone'] ?? 'America/New_York';
        $_SESSION['time_format'] = $user['time_format'] ?? '12h';
        $defaultTheme = $this->db->fetchOne("SELECT `value` FROM settings WHERE `key` = 'default_theme'");
        $_SESSION['theme'] = $user['theme'] ?? $defaultTheme['value'] ?? 'dark';
        $_SESSION['auth_provider'] = $user['auth_provider'] ?? 'local';
        $_SESSION['login_time'] = time();
        $_SESSION['last_activity'] = time();

        unset($_SESSION['2fa_user_id'], $_SESSION['2fa_username'], $_SESSION['2fa_timestamp']);

        $this->redirect('/');
    }

    /**
     * GET /login/oidc — Redirect to OIDC identity provider.
     */
    public function oidcLogin(): void
    {
        if (!empty($_SESSION['user_id'])) {
            $this->redirect('/');
        }

        if (!$this->checkRateLimit('oidc_login', 10, 300)) {
            $this->flash('danger', 'Too many login attempts. Please wait a few minutes.');
            $this->redirect('/login');
        }

        $oidcService = new OidcService();
        if (!$oidcService->isEnabled()) {
            $this->flash('danger', 'SSO is not configured.');
            $this->redirect('/login');
        }

        // Explicit override from settings takes priority — needed when BBS is
        // reached via one hostname internally (e.g. agents use http://internal-ip)
        // but OIDC must use a different public hostname.
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
            $this->flash('danger', 'SSO error: ' . $e->getMessage());
            $this->redirect('/login');
        }
    }

    /**
     * GET /login/oidc/callback — Handle OIDC provider callback.
     */
    public function oidcCallback(): void
    {
        $oidcService = new OidcService();
        if (!$oidcService->isEnabled()) {
            $this->flash('danger', 'SSO is not configured.');
            $this->redirect('/login');
        }

        // Explicit override from settings takes priority — needed when BBS is
        // reached via one hostname internally (e.g. agents use http://internal-ip)
        // but OIDC must use a different public hostname.
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
            $result = $oidcService->handleCallback($redirectUri);

            if ($result['status'] === 'pending') {
                $this->flash('warning', $result['message']);
                $this->redirect('/login');
            }

            if ($result['status'] === 'denied') {
                $this->flash('danger', $result['message']);
                $this->redirect('/login');
            }

            if ($result['user']) {
                $this->completeLogin($result['user']);
            } else {
                $this->flash('danger', 'SSO login failed.');
                $this->redirect('/login');
            }
        } catch (\Exception $e) {
            $this->flash('danger', 'SSO error: ' . $e->getMessage());
            $this->redirect('/login');
        }
    }

    public function twoFactorForm(): void
    {
        if (empty($_SESSION['2fa_user_id'])) {
            $this->redirect('/login');
        }

        if (empty($_SESSION['2fa_timestamp']) || (time() - $_SESSION['2fa_timestamp']) > 300) {
            unset($_SESSION['2fa_user_id'], $_SESSION['2fa_username'], $_SESSION['2fa_timestamp']);
            $this->flash('danger', '2FA session expired. Please log in again.');
            $this->redirect('/login');
        }

        $flash = $this->getFlash();
        $this->authView('auth/2fa', ['flash' => $flash, 'username' => $_SESSION['2fa_username'] ?? '']);
    }

    public function twoFactorVerify(): void
    {
        if (empty($_SESSION['2fa_user_id'])) {
            $this->redirect('/login');
        }

        if (empty($_SESSION['2fa_timestamp']) || (time() - $_SESSION['2fa_timestamp']) > 300) {
            unset($_SESSION['2fa_user_id'], $_SESSION['2fa_username'], $_SESSION['2fa_timestamp']);
            $this->flash('danger', '2FA session expired. Please log in again.');
            $this->redirect('/login');
        }

        if (!$this->checkRateLimit('2fa_verify', 10, 300)) {
            $this->flash('danger', 'Too many 2FA attempts. Please wait a few minutes.');
            $this->redirect('/login/2fa');
        }

        $userId = $_SESSION['2fa_user_id'];
        $code = trim($_POST['code'] ?? '');

        if (empty($code)) {
            $this->flash('danger', 'Please enter your 2FA code.');
            $this->redirect('/login/2fa');
        }

        $twoFactor = new TwoFactorService();
        $valid = false;

        $secret = $twoFactor->getUserSecret($userId);
        if ($secret && $twoFactor->verifyTotp($secret, $code)) {
            $valid = true;
        }

        if (!$valid && preg_match('/^[A-Z0-9]{4}-[A-Z0-9]{4}$/i', $code)) {
            if ($twoFactor->verifyRecoveryCode($userId, strtoupper($code))) {
                $valid = true;
                $remaining = $twoFactor->getRemainingRecoveryCodeCount($userId);
                if ($remaining <= 2) {
                    $this->flash('warning', "Recovery code accepted. Only {$remaining} recovery code(s) remaining.");
                }
            }
        }

        if (!$valid) {
            $this->flash('danger', 'Invalid 2FA code.');
            $this->redirect('/login/2fa');
        }

        $user = $this->db->fetchOne("SELECT * FROM users WHERE id = ?", [$userId]);
        $this->completeLogin($user);
    }

    public function logout(): void
    {
        $oidcLogoutUrl = null;
        if (($_SESSION['auth_provider'] ?? 'local') === 'oidc') {
            $oidcService = new OidcService();
            $serverHost = $this->db->fetchOne("SELECT `value` FROM settings WHERE `key` = 'server_host'");
            $host = $serverHost['value'] ?? $_SERVER['HTTP_HOST'] ?? 'localhost';
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $oidcLogoutUrl = $oidcService->getLogoutUrl("{$scheme}://{$host}/login");
        }

        session_destroy();

        if ($oidcLogoutUrl) {
            header("Location: {$oidcLogoutUrl}");
            exit;
        }

        $this->redirect('/login');
    }

    public function forgotPasswordForm(): void
    {
        $flash = $this->getFlash();
        $this->authView('auth/forgot-password', ['flash' => $flash]);
    }

    public function forgotPassword(): void
    {
        $this->verifyCsrf();

        if (!$this->checkRateLimit('forgot_password', 3, 900)) {
            $this->flash('danger', 'Too many requests. Please wait before trying again.');
            $this->redirect('/forgot-password');
        }

        $email = trim($_POST['email'] ?? '');

        if (empty($email)) {
            $this->flash('danger', 'Please enter your email address.');
            $this->redirect('/forgot-password');
        }

        // Always show the same message to prevent email enumeration
        $successMsg = 'If an account exists with that email, a password reset link has been sent.';

        $user = $this->db->fetchOne("SELECT id, email FROM users WHERE email = ?", [$email]);

        if ($user) {
            // Clean up expired tokens
            $this->db->query("DELETE FROM password_resets WHERE expires_at < NOW()");

            // Delete existing tokens for this user
            $this->db->delete('password_resets', 'user_id = ?', [$user['id']]);

            // Generate token
            $token = bin2hex(random_bytes(32));
            $this->db->insert('password_resets', [
                'user_id' => $user['id'],
                'token' => $token,
                'expires_at' => date('Y-m-d H:i:s', strtotime('+1 hour')),
            ]);

            // Build reset URL
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $resetUrl = "{$scheme}://{$host}/reset-password/{$token}";

            // Send email
            $subject = 'Password Reset — Borg Backup Server';
            $body = "<p>A password reset was requested for your account.</p>"
                . "<p><a href=\"{$resetUrl}\" style=\"display:inline-block;padding:10px 20px;background:#198754;color:#fff;text-decoration:none;border-radius:4px;\">Reset Password</a></p>"
                . "<p>Or copy this link: {$resetUrl}</p>"
                . "<p>This link expires in 1 hour. If you did not request this, you can safely ignore this email.</p>";

            (new Mailer())->send($user['email'], $subject, $body);
        }

        $this->flash('success', $successMsg);
        $this->redirect('/forgot-password');
    }

    public function resetPasswordForm(string $token): void
    {
        // Clean up expired tokens
        $this->db->query("DELETE FROM password_resets WHERE expires_at < NOW()");

        $reset = $this->db->fetchOne(
            "SELECT * FROM password_resets WHERE token = ? AND expires_at > NOW()",
            [$token]
        );

        if (!$reset) {
            $this->flash('danger', 'This password reset link is invalid or has expired.');
            $this->redirect('/login');
        }

        $flash = $this->getFlash();
        $this->authView('auth/reset-password', ['flash' => $flash, 'token' => $token]);
    }

    public function resetPassword(): void
    {
        $this->verifyCsrf();

        $token = $_POST['token'] ?? '';
        $password = $_POST['password'] ?? '';
        $confirm = $_POST['password_confirm'] ?? '';

        $reset = $this->db->fetchOne(
            "SELECT * FROM password_resets WHERE token = ? AND expires_at > NOW()",
            [$token]
        );

        if (!$reset) {
            $this->flash('danger', 'This password reset link is invalid or has expired.');
            $this->redirect('/login');
        }

        if (strlen($password) < 6) {
            $this->flash('danger', 'Password must be at least 6 characters.');
            $this->redirect("/reset-password/{$token}");
        }

        if ($password !== $confirm) {
            $this->flash('danger', 'Passwords do not match.');
            $this->redirect("/reset-password/{$token}");
        }

        // Update password
        $this->db->update('users', [
            'password_hash' => password_hash($password, PASSWORD_BCRYPT),
        ], 'id = ?', [$reset['user_id']]);

        // Delete all tokens for this user
        $this->db->delete('password_resets', 'user_id = ?', [$reset['user_id']]);

        $this->flash('success', 'Your password has been reset. Please log in.');
        $this->redirect('/login');
    }
}
