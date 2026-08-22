<?php

namespace BBS\Services;

/**
 * The TLS certificate Apache is serving: what it is, when it expires, and
 * renewing it on demand.
 *
 * certbot runs during installation, before the setup wizard has collected an
 * admin address, so the ACME account was registered against a guessed
 * `admin@<hostname>` that usually does not exist. Expiry warnings went
 * nowhere, and the first anyone knew about a stalled renewal was a browser
 * warning. The address the admin enters in the panel is pushed to the account
 * from here instead.
 *
 * Everything privileged goes through bin/bbs-ssh-helper — www-data cannot run
 * certbot or read /etc/letsencrypt.
 */
class CertificateService
{
    /**
     * The installed helper, not the copy in the repo — sudoers grants
     * NOPASSWD on /usr/local/bin/bbs-ssh-helper only.
     */
    private const HELPER = '/usr/local/bin/bbs-ssh-helper';

    private function run(array $args): array
    {
        $cmd = 'sudo ' . escapeshellarg(self::HELPER);
        foreach ($args as $arg) {
            $cmd .= ' ' . escapeshellarg((string) $arg);
        }
        $output = [];
        $exit = 0;
        exec($cmd . ' 2>&1', $output, $exit);
        return ['exit' => $exit, 'output' => implode("\n", $output)];
    }

    /**
     * @return array{
     *   installed: bool, domains: array, issuer: ?string, expires_at: ?string,
     *   days_remaining: ?int, expiring_soon: bool, expired: bool,
     *   auto_renewal: string, self_signed: bool, raw: string
     * }
     */
    public function status(?string $domain = null): array
    {
        $res = $this->run(array_filter(['cert-status', $domain]));
        $raw = $res['output'];

        $block = [];
        if (($pos = strpos($raw, '---BBS-CERT---')) !== false) {
            foreach (explode("\n", substr($raw, $pos + 14)) as $line) {
                if (str_contains($line, '=')) {
                    [$k, $v] = explode('=', trim($line), 2);
                    $block[$k] = $v;
                }
            }
        }

        $installed = !empty($block['path']);
        $expiresAt = null;
        $daysRemaining = null;

        if (!empty($block['not_after'])) {
            $ts = strtotime($block['not_after']);
            if ($ts !== false) {
                $expiresAt = date('Y-m-d H:i:s', $ts);
                // Rounded down: "0 days remaining" should mean today, not
                // "expires in a few hours so we'll call it one".
                $daysRemaining = (int) floor(($ts - time()) / 86400);
            }
        }

        $issuer = $block['issuer'] ?? null;
        if ($issuer && preg_match('/CN\s*=\s*([^,\/]+)/', $issuer, $m)) {
            $issuer = trim($m[1]);
        }

        $domains = [];
        if (!empty($block['domains'])) {
            $domains = array_values(array_filter(array_map('trim', explode(',', $block['domains']))));
        }

        // A self-signed certificate renews through nothing, so the page should
        // not offer a Renew button that cannot work.
        $selfSigned = $issuer !== null
            && !empty($block['subject'])
            && str_contains($block['subject'], (string) $issuer);

        return [
            'installed'      => $installed,
            'domains'        => $domains,
            'issuer'         => $issuer,
            'expires_at'     => $expiresAt,
            'days_remaining' => $daysRemaining,
            'expiring_soon'  => $daysRemaining !== null && $daysRemaining <= 21 && $daysRemaining >= 0,
            'expired'        => $daysRemaining !== null && $daysRemaining < 0,
            'auto_renewal'   => $block['timer'] ?? 'unknown',
            'self_signed'    => $selfSigned,
            'last_error'     => $block['last_error'] ?? null,
            'raw'            => $raw,
        ];
    }

    /**
     * Why renewal is not happening, as a clause to append to a warning.
     *
     * Null when nothing is known — a trailing "Reason: unknown" is worse than
     * no clause at all.
     */
    public function stalledReason(array $status): ?string
    {
        if (($status['auto_renewal'] ?? '') === 'none') {
            return 'nothing is scheduled to renew it (no certbot timer or cron job)';
        }
        if (!empty($status['self_signed'])) {
            return 'the certificate is self-signed and cannot be renewed automatically';
        }
        if (!empty($status['last_error'])) {
            return trim($status['last_error']);
        }
        return null;
    }

    /**
     * Renew now.
     *
     * Without `force`, certbot declines while the certificate still has more
     * than 30 days left, and says so — which is the correct answer to a button
     * pressed out of curiosity, so it is passed straight back.
     */
    public function renew(?string $domain = null, bool $force = false): array
    {
        $args = ['cert-renew', $domain ?: ''];
        if ($force) {
            $args[] = '--force';
        }
        $res = $this->run($args);

        return [
            'success' => $res['exit'] === 0,
            'output'  => $res['output'],
        ];
    }

    /** Set the Let's Encrypt account contact address. */
    public function setContactEmail(string $email): array
    {
        $email = trim($email);
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'output' => "'{$email}' is not a valid email address"];
        }

        $res = $this->run(['cert-email', $email === '' ? '--remove' : $email]);
        return ['success' => $res['exit'] === 0, 'output' => $res['output']];
    }

    /**
     * Push the admin's address to the ACME account, quietly.
     *
     * Called when the setup wizard finishes and when an admin changes their
     * email. Certbot may not be installed (an install behind a reverse proxy
     * that terminates TLS elsewhere), so a failure here is not worth
     * interrupting the save for — it is recorded and the page carries on.
     */
    public function syncContactEmail(string $email): bool
    {
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return false;
        }
        if (!is_dir('/etc/letsencrypt')) {
            return false;
        }
        $res = $this->setContactEmail($email);
        return $res['success'];
    }
}
