<?php

namespace BBS\Services;

use BBS\Core\Database;

class Mailer
{
    private string $host;
    private int $port;
    private string $secure;
    private string $username;
    private string $password;
    private string $fromEmail;
    private string $fromName;
    private bool $enabled;

    public function __construct()
    {
        $db = Database::getInstance();
        $settings = [];
        $rows = $db->fetchAll("SELECT `key`, `value` FROM settings WHERE `key` LIKE 'smtp_%'");
        foreach ($rows as $row) {
            $settings[$row['key']] = $row['value'];
        }

        $this->host = $settings['smtp_host'] ?? '';
        $this->port = (int) ($settings['smtp_port'] ?? 587);
        $this->secure = $settings['smtp_secure'] ?? self::inferSecure($this->port);
        $this->username = $settings['smtp_user'] ?? '';
        // SMTP password is stored encrypted. Legacy plaintext values auto-upgrade:
        // if decrypt() throws, we treat the raw value as plaintext and re-save
        // it encrypted for next time.
        $rawPass = $settings['smtp_pass'] ?? '';
        $this->password = '';
        if ($rawPass !== '') {
            try {
                $this->password = Encryption::decrypt($rawPass);
            } catch (\Throwable $e) {
                $this->password = $rawPass;
                try {
                    $db->update('settings',
                        ['value' => Encryption::encrypt($rawPass)],
                        "`key` = ?", ['smtp_pass']);
                } catch (\Throwable $e2) { /* non-fatal */ }
            }
        }
        $this->fromEmail = $settings['smtp_from'] ?? $settings['smtp_from_email'] ?? '';
        $this->fromName = $settings['smtp_from_name'] ?? 'Borg Backup Server';
        $this->enabled = !empty($this->host) && !empty($this->fromEmail);
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Send an email using SMTP. Honors the configured smtp_secure mode:
     * 'ssl' (implicit TLS, typically port 465), 'starttls' (upgrade in-band,
     * typically port 587), or 'none' (plaintext, port 25).
     */
    /**
     * Send one message. Plain text unless $isHtml: bodies carry text from
     * client agents (error output), which must not become markup (GHSA-jwq4-qmhw-54mm).
     */
    public function send(string $to, string $subject, string $body, bool $isHtml = false): bool
    {
        // No line breaks in header values (header injection via names)
        $to = str_replace(["\r", "\n"], '', $to);
        $subject = str_replace(["\r", "\n"], ' ', $subject);
        if (!$this->enabled) {
            return false;
        }

        if (\BBS\Core\Config::isHosted() && \BBS\Core\UrlGuard::reachesInternal((string) $this->host)) {
            error_log("SMTP host {$this->host} refused: internal address");
            return false;
        }

        try {
            $connHost = $this->secure === 'ssl' ? "ssl://{$this->host}" : $this->host;
            $socket = @fsockopen($connHost, $this->port, $errno, $errstr, 10);
            if (!$socket) {
                error_log("SMTP connect failed: {$errstr} ({$errno})");
                return false;
            }

            $this->readResponse($socket);
            $this->sendCommand($socket, "EHLO " . gethostname());

            if ($this->secure === 'starttls') {
                $this->sendCommand($socket, "STARTTLS");
                stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT);
                $this->sendCommand($socket, "EHLO " . gethostname());
            }

            // Auth
            if ($this->username) {
                $this->sendCommand($socket, "AUTH LOGIN");
                $this->sendCommand($socket, base64_encode($this->username));
                $this->sendCommand($socket, base64_encode($this->password));
            }

            $this->sendCommand($socket, "MAIL FROM:<{$this->fromEmail}>");
            $this->sendCommand($socket, "RCPT TO:<{$to}>");
            $this->sendCommand($socket, "DATA");

            $contentType = $isHtml ? 'text/html' : 'text/plain';
            // CRLF line endings, and dot-stuffing: a line holding only "." would
            // otherwise end the message and run the rest as SMTP commands.
            $body = preg_replace("/\r\n|\r|\n/", "\r\n", $body);
            $body = preg_replace('/^\./m', '..', $body);

            $headers = "From: {$this->fromName} <{$this->fromEmail}>\r\n"
                     . "To: {$to}\r\n"
                     . "Subject: {$subject}\r\n"
                     . "MIME-Version: 1.0\r\n"
                     . "Content-Type: {$contentType}; charset=UTF-8\r\n"
                     . "Date: " . date('r') . "\r\n";

            fwrite($socket, $headers . "\r\n" . $body . "\r\n.\r\n");
            $this->readResponse($socket);

            $this->sendCommand($socket, "QUIT");
            fclose($socket);

            return true;
        } catch (\Exception $e) {
            error_log("SMTP error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Send a task-failure notification. Subject/body reflect the task type
     * ('backup', 'update_borg', 'check', ...) so an update_borg failure
     * isn't delivered as "Backup Failed" (#185).
     */
    public function notifyFailure(string $agentName, int $jobId, string $error, string $taskType = 'backup'): void
    {
        if (!$this->enabled) return;

        $db = Database::getInstance();

        $admins = $db->fetchAll("SELECT email, timezone FROM users WHERE role = 'admin' AND email != ''");

        $label = self::taskLabel($taskType);
        $subject = "[BBS] {$label} Failed: {$agentName} (Job #{$jobId})";

        foreach ($admins as $admin) {
            try {
                $dt = new \DateTime('now', new \DateTimeZone('UTC'));
                $dt->setTimezone(new \DateTimeZone($admin['timezone'] ?: 'UTC'));
            } catch (\Exception $e) {
                $dt = new \DateTime('now', new \DateTimeZone('UTC'));
            }
            $body = "{$label} failed for client \"{$agentName}\".\n\n"
                  . "Client: {$agentName}\n"
                  . "Job ID: #{$jobId} ({$label})\n"
                  . "Time: " . $dt->format('Y-m-d H:i:s T') . "\n\n"
                  . "Error:\n{$error}\n\n"
                  . "-- Borg Backup Server";
            $this->send($admin['email'], $subject, $body);
        }
    }

    private static function taskLabel(string $taskType): string
    {
        static $labels = [
            'backup'               => 'Backup',
            'restore'              => 'Restore',
            'check'                => 'Repository Check',
            'prune'                => 'Prune',
            'compact'              => 'Compact',
            'update_borg'          => 'Borg Update',
            'update_agent'         => 'Agent Update',
            's3_sync'              => 'Offsite Sync',
            's3_restore'           => 'Offsite Restore',
            'repo_repair'          => 'Repository Repair',
            'break_lock'           => 'Break Lock',
            'catalog_sync'         => 'Catalog Sync',
            'catalog_rebuild'      => 'Catalog Rebuild',
            'catalog_rebuild_full' => 'Catalog Rebuild',
            'archive_delete'       => 'Archive Delete',
            'archive_lock'         => 'Archive Lock',
        ];
        return $labels[$taskType] ?? ucfirst(str_replace('_', ' ', $taskType));
    }

    public static function inferSecure(int $port): string
    {
        return match ($port) {
            465 => 'ssl',
            25 => 'none',
            default => 'starttls',
        };
    }

    private function sendCommand($socket, string $command): string
    {
        fwrite($socket, $command . "\r\n");
        return $this->readResponse($socket);
    }

    private function readResponse($socket): string
    {
        $response = '';
        while ($line = fgets($socket, 515)) {
            $response .= $line;
            if (isset($line[3]) && $line[3] === ' ') break;
        }
        return $response;
    }
}
