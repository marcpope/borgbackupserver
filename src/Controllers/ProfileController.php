<?php

namespace BBS\Controllers;

use BBS\Core\Controller;
use BBS\Services\TwoFactorService;
use BBS\Services\ReportService;
use BBS\Services\Mailer;

class ProfileController extends Controller
{
    public function index(): void
    {
        $this->requireAuth();

        $user = $this->db->fetchOne("SELECT * FROM users WHERE id = ?", [$_SESSION['user_id']]);
        $twoFactor = new TwoFactorService();
        $enabled = $twoFactor->isEnabled($_SESSION['user_id']);

        $tab = $_GET['tab'] ?? 'account';

        // Reports tab data
        $recentReports = [];
        $selectedReport = null;
        $smtpEnabled = false;
        if ($tab === 'reports') {
            $reportService = new ReportService();
            $recentReports = $reportService->getRecentReports();
            $smtpEnabled = (new Mailer())->isEnabled();

            $reportId = (int) ($_GET['report_id'] ?? 0);
            if ($reportId) {
                $selectedReport = $reportService->getReport($reportId);
            } elseif (!empty($recentReports)) {
                $selectedReport = $reportService->getReport($recentReports[0]['id']);
            }
        }

        $this->view('profile/index', [
            'pageTitle' => 'Profile',
            'user' => $user,
            'tab' => $tab,
            'step' => $_GET['step'] ?? 'main',
            'twoFactorEnabled' => $enabled,
            'setupSecret' => $_SESSION['2fa_setup_secret'] ?? null,
            'recoveryCodes' => $_SESSION['2fa_recovery_codes'] ?? null,
            'remainingCodes' => $enabled ? $twoFactor->getRemainingRecoveryCodeCount($_SESSION['user_id']) : 0,
            'recentReports' => $recentReports,
            'selectedReport' => $selectedReport,
            'smtpEnabled' => $smtpEnabled,
        ]);
    }

    public function update(): void
    {
        $this->requireAuth();
        $this->verifyCsrf();

        $userId = $_SESSION['user_id'];
        $user = $this->db->fetchOne("SELECT * FROM users WHERE id = ?", [$userId]);
        $tab = $_POST['_tab'] ?? 'account';

        // Update timezone
        $timezone = trim($_POST['timezone'] ?? '');
        if ($timezone && in_array($timezone, timezone_identifiers_list()) && $timezone !== $user['timezone']) {
            $this->db->update('users', ['timezone' => $timezone], 'id = ?', [$userId]);
            $_SESSION['timezone'] = $timezone;
            // Also update the timezone on all schedules this user has access to,
            // so that SchedulerService recalculates next_run in the correct timezone.
            if (($_SESSION['user_role'] ?? '') === 'admin') {
                // Admin has access to all agents — update every schedule.
                $this->db->query("UPDATE schedules SET timezone = ?", [$timezone]);
            } else {
                // Regular users are linked to agents via user_agents.
                $this->db->query(
                    "UPDATE schedules s
                     JOIN backup_plans bp ON bp.id = s.backup_plan_id
                     JOIN user_agents ua ON ua.agent_id = bp.agent_id
                     SET s.timezone = ?
                     WHERE ua.user_id = ?",
                    [$timezone, $userId]
                );
            }
            $this->flash('success', 'Timezone updated.');
        }

        // Update email
        $email = trim($_POST['email'] ?? '');
        if ($email && $email !== $user['email']) {
            $existing = $this->db->fetchOne("SELECT id FROM users WHERE email = ? AND id != ?", [$email, $userId]);
            if ($existing) {
                $this->flash('danger', 'That email is already in use.');
                $this->redirect('/profile?tab=' . urlencode($tab));
            }
            $this->db->update('users', ['email' => $email], 'id = ?', [$userId]);
            $this->flash('success', 'Email updated.');
        }

        // Update password
        $newPassword = $_POST['new_password'] ?? '';
        if ($newPassword) {
            $currentPassword = $_POST['current_password'] ?? '';
            if (!password_verify($currentPassword, $user['password_hash'])) {
                $this->flash('danger', 'Current password is incorrect.');
                $this->redirect('/profile?tab=' . urlencode($tab));
            }
            $confirmPassword = $_POST['confirm_password'] ?? '';
            if ($newPassword !== $confirmPassword) {
                $this->flash('danger', 'New passwords do not match.');
                $this->redirect('/profile?tab=' . urlencode($tab));
            }
            if (strlen($newPassword) < 6) {
                $this->flash('danger', 'Password must be at least 6 characters.');
                $this->redirect('/profile?tab=' . urlencode($tab));
            }
            $this->db->update('users', [
                'password_hash' => password_hash($newPassword, PASSWORD_BCRYPT),
            ], 'id = ?', [$userId]);
            $this->flash('success', 'Password updated.');
        }

        $this->redirect('/profile?tab=' . urlencode($tab));
    }

    public function twoFactorSetup(): void
    {
        $this->requireAuth();
        $this->verifyCsrf();

        $twoFactor = new TwoFactorService();
        if ($twoFactor->isEnabled($_SESSION['user_id'])) {
            $this->flash('warning', '2FA is already enabled on your account.');
            $this->redirect('/profile?tab=2fa');
        }

        $_SESSION['2fa_setup_secret'] = $twoFactor->generateSecret();
        $this->redirect('/profile?tab=2fa&step=verify');
    }

    public function twoFactorEnable(): void
    {
        $this->requireAuth();
        $this->verifyCsrf();

        $secret = $_SESSION['2fa_setup_secret'] ?? '';
        if (empty($secret)) {
            $this->flash('danger', '2FA setup session expired. Please start over.');
            $this->redirect('/profile?tab=2fa');
        }

        $code = trim($_POST['code'] ?? '');
        if (empty($code)) {
            $this->flash('danger', 'Please enter the code from your authenticator app.');
            $this->redirect('/profile?tab=2fa&step=verify');
        }

        $twoFactor = new TwoFactorService();
        if (!$twoFactor->verifyTotp($secret, $code)) {
            $this->flash('danger', 'Invalid code. Please try again.');
            $this->redirect('/profile?tab=2fa&step=verify');
        }

        $twoFactor->enableTotp($_SESSION['user_id'], $secret);
        $_SESSION['2fa_recovery_codes'] = $twoFactor->generateRecoveryCodes($_SESSION['user_id']);
        unset($_SESSION['2fa_setup_secret']);

        $this->flash('success', '2FA has been enabled. Save your recovery codes now!');
        $this->redirect('/profile?tab=2fa&step=codes');
    }

    public function twoFactorDisable(): void
    {
        $this->requireAuth();
        $this->verifyCsrf();

        $password = $_POST['password'] ?? '';
        $user = $this->db->fetchOne("SELECT password_hash FROM users WHERE id = ?", [$_SESSION['user_id']]);
        if (!password_verify($password, $user['password_hash'])) {
            $this->flash('danger', 'Incorrect password.');
            $this->redirect('/profile?tab=2fa');
        }

        $twoFactor = new TwoFactorService();
        $twoFactor->disableTotp($_SESSION['user_id']);

        $this->flash('success', '2FA has been disabled.');
        $this->redirect('/profile?tab=2fa');
    }

    public function twoFactorRegenerateCodes(): void
    {
        $this->requireAuth();
        $this->verifyCsrf();

        $twoFactor = new TwoFactorService();
        if (!$twoFactor->isEnabled($_SESSION['user_id'])) {
            $this->flash('danger', '2FA is not enabled.');
            $this->redirect('/profile?tab=2fa');
        }

        $_SESSION['2fa_recovery_codes'] = $twoFactor->generateRecoveryCodes($_SESSION['user_id']);
        $this->flash('success', 'Recovery codes regenerated. Save them now!');
        $this->redirect('/profile?tab=2fa&step=codes');
    }

    /**
     * POST /profile/theme — toggle dark/light mode.
     */
    public function theme(): void
    {
        $this->requireAuth();
        $theme = ($_POST['theme'] ?? 'dark') === 'dark' ? 'dark' : 'light';
        $this->db->update('users', ['theme' => $theme], 'id = ?', [$_SESSION['user_id']]);
        $_SESSION['theme'] = $theme;
        http_response_code(204);
        exit;
    }

    /**
     * POST /profile/detect-timezone — browser-detected fallback.
     */
    public function detectTimezone(): void
    {
        $this->requireAuth();

        if (!empty($_SESSION['timezone'])) {
            http_response_code(204);
            exit;
        }

        $tz = trim($_POST['timezone'] ?? '');
        if ($tz && in_array($tz, timezone_identifiers_list())) {
            $_SESSION['timezone'] = $tz;
        }

        http_response_code(204);
        exit;
    }

    /**
     * POST /profile/reports/preferences — toggle daily report email.
     */
    public function reportPreferences(): void
    {
        $this->requireAuth();
        $this->verifyCsrf();

        $enabled = isset($_POST['daily_report_email']) ? 1 : 0;
        $hour = max(0, min(23, (int) ($_POST['daily_report_hour'] ?? 6)));
        $this->db->update('users', [
            'daily_report_email' => $enabled,
            'daily_report_hour' => $hour,
        ], 'id = ?', [$_SESSION['user_id']]);
        $this->flash('success', $enabled ? 'Daily report email enabled.' : 'Daily report email disabled.');
        $this->redirect('/profile?tab=reports');
    }

    /**
     * POST /profile/reports/generate — generate a report on demand.
     */
    public function reportGenerate(): void
    {
        $this->requireAuth();
        $this->verifyCsrf();

        $reportService = new ReportService();
        $report = $reportService->generate();
        $this->flash('success', 'Report generated.');
        $this->redirect('/profile?tab=reports&report_id=' . $report['id']);
    }

    /**
     * POST /profile/reports/email — email a report.
     */
    public function reportEmail(): void
    {
        $this->requireAuth();
        $this->verifyCsrf();

        $reportId = (int) ($_POST['report_id'] ?? 0);
        $email = trim($_POST['email'] ?? '');

        if (!$reportId) {
            $this->flash('danger', 'No report selected.');
            $this->redirect('/profile?tab=reports');
        }

        $reportService = new ReportService();
        $toEmail = $email ?: null;
        $sent = $reportService->emailReport($reportId, $_SESSION['user_id'], $toEmail);

        if ($sent) {
            $this->flash('success', 'Report emailed to ' . htmlspecialchars($toEmail ?: 'your email address') . '.');
        } else {
            $this->flash('danger', 'Failed to send report. Check SMTP settings.');
        }
        $this->redirect('/profile?tab=reports&report_id=' . $reportId);
    }
}
