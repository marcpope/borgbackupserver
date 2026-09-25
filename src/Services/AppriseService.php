<?php

namespace BBS\Services;

use BBS\Core\Database;

class AppriseService
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    private static ?string $binary = null;

    /**
     * Absolute path to the apprise binary, or '' when it isn't installed.
     * pip puts it in /usr/local/bin, which is not on cron's default PATH
     * (/usr/bin:/bin), so a PATH lookup alone missed it for every
     * scheduler-sent notification (#516).
     */
    public function appriseBinary(): string
    {
        if (self::$binary === null) {
            self::$binary = '';
            foreach (['/usr/local/bin/apprise', '/usr/bin/apprise'] as $path) {
                if (is_executable($path)) {
                    self::$binary = $path;
                    break;
                }
            }
            if (self::$binary === '') {
                exec('command -v apprise 2>/dev/null', $output, $code);
                if ($code === 0 && !empty($output[0])) {
                    self::$binary = trim($output[0]);
                }
            }
        }
        return self::$binary;
    }

    public function isAppriseInstalled(): bool
    {
        return $this->appriseBinary() !== '';
    }

    /**
     * Get all enabled services that should receive notifications for a given event type.
     */
    public function getEnabledServicesForEvent(string $eventType): array
    {
        $services = $this->db->fetchAll(
            "SELECT * FROM notification_services WHERE enabled = 1"
        );

        // Filter by event type (check JSON field)
        return array_filter($services, function ($service) use ($eventType) {
            $events = json_decode($service['events'] ?? '{}', true) ?: [];
            return !empty($events[$eventType]);
        });
    }

    /**
     * Send notification to a specific service by ID.
     */
    public function sendToService(int $serviceId, string $title, string $body, ?int $agentId = null): bool
    {
        $service = $this->db->fetchOne("SELECT * FROM notification_services WHERE id = ?", [$serviceId]);
        if (!$service || !$service['enabled']) {
            return false;
        }

        if (!$this->isAppriseInstalled()) {
            return false;
        }

        try {
            $titleEscaped = escapeshellarg($title);
            $bodyEscaped = escapeshellarg($body);
            $urlEscaped = escapeshellarg($service['apprise_url']);

            // Run synchronously so we can capture success/failure
            $cmd = escapeshellarg($this->appriseBinary()) . " -t {$titleEscaped} -b {$bodyEscaped} {$urlEscaped} 2>&1";
            exec($cmd, $output, $exitCode);

            // Update last_used_at
            $this->db->update('notification_services', ['last_used_at' => date('Y-m-d H:i:s')], 'id = ?', [$serviceId]);

            // Log the send attempt (with agent_id so it shows up in per-client
            // log filter). Failures log at WARNING — a push delivery problem
            // is operational noise about a notification service, not a backup
            // error, and shouldn't pollute the dashboard's Errors tile/chart
            // alongside actual job failures. The notification-services page
            // is the right surface for delivery health.
            $serviceName = $service['name'];
            $logRow = [
                'level' => $exitCode === 0 ? 'info' : 'warning',
            ];
            if ($agentId !== null) $logRow['agent_id'] = $agentId;
            if ($exitCode === 0) {
                $logRow['message'] = "Push notification sent via \"{$serviceName}\": {$title}";
            } else {
                $outputStr = trim(implode("\n", $output));
                // Apprise frequently exits non-zero with no stdout/stderr
                // (it logs to its own log path, not the calling process).
                // Surface the exit code at minimum so the user has something
                // to investigate when nothing else came back.
                $detail = $outputStr !== '' ? substr($outputStr, 0, 500) : "apprise exited with code {$exitCode} (no output — run `apprise -vv` against the service URL to see the actual error)";
                $logRow['message'] = "Push notification failed via \"{$serviceName}\": {$title} — " . $detail;
            }
            $this->db->insert('server_log', $logRow);

            return $exitCode === 0;
        } catch (\Exception $e) {
            error_log("Apprise error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Send notification to all enabled services for a given event type.
     */
    public function sendForEvent(string $eventType, string $title, string $body, ?int $agentId = null): int
    {
        $services = $this->getEnabledServicesForEvent($eventType);
        if (empty($services)) {
            return 0;
        }

        if (!$this->isAppriseInstalled()) {
            $this->db->insert('server_log', [
                'level' => 'warning',
                'message' => "Push notification not sent: apprise is not installed ({$title})",
            ] + ($agentId !== null ? ['agent_id' => $agentId] : []));
            return 0;
        }
        $sent = 0;

        foreach ($services as $service) {
            if ($this->sendToService($service['id'], $title, $body, $agentId)) {
                $sent++;
            }
        }

        return $sent;
    }

    /**
     * Check if any notification services are configured and enabled.
     */
    public function hasEnabledServices(): bool
    {
        $count = $this->db->fetchOne("SELECT COUNT(*) as cnt FROM notification_services WHERE enabled = 1");
        return (int) ($count['cnt'] ?? 0) > 0;
    }

    // -------------------------------------------------------------------------
    // Legacy methods for backward compatibility with old settings-based config
    // These can be removed once all installs have migrated to the new table
    // -------------------------------------------------------------------------

    private ?array $legacyUrls = null;

    private function getLegacyUrls(): array
    {
        if ($this->legacyUrls === null) {
            $setting = $this->db->fetchOne("SELECT `value` FROM settings WHERE `key` = 'apprise_urls'");
            $urlText = $setting['value'] ?? '';
            $this->legacyUrls = array_filter(
                array_map('trim', explode("\n", $urlText)),
                fn($url) => !empty($url) && !str_starts_with($url, '#')
            );
        }
        return $this->legacyUrls;
    }

    /**
     * @deprecated Use hasEnabledServices() instead
     */
    public function isEnabled(): bool
    {
        // Check new table first
        if ($this->hasEnabledServices()) {
            return true;
        }
        // Fall back to legacy settings
        return !empty($this->getLegacyUrls()) && $this->isAppriseInstalled();
    }

    /**
     * @deprecated Use sendForEvent() instead
     */
    public function send(string $title, string $body): bool
    {
        if (!$this->isAppriseInstalled()) {
            return false;
        }

        // Try new table first
        if ($this->hasEnabledServices()) {
            // This is called from NotificationService which handles event filtering
            // so we can't use sendForEvent here - the caller should switch to the new API
            return false;
        }

        // Fall back to legacy settings
        $urls = $this->getLegacyUrls();
        if (empty($urls)) {
            return false;
        }

        try {
            $titleEscaped = escapeshellarg($title);
            $bodyEscaped = escapeshellarg($body);
            $urlArgs = implode(' ', array_map('escapeshellarg', $urls));

            $cmd = escapeshellarg($this->appriseBinary()) . " -t {$titleEscaped} -b {$bodyEscaped} {$urlArgs} > /dev/null 2>&1 &";
            exec($cmd);

            return true;
        } catch (\Exception $e) {
            error_log("Apprise error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * @deprecated Use the new notification services UI for testing
     */
    public function test(): array
    {
        $urls = $this->getLegacyUrls();

        if (empty($urls)) {
            return ['success' => false, 'error' => 'No Apprise URLs configured.'];
        }

        if (!$this->isAppriseInstalled()) {
            return ['success' => false, 'error' => 'Apprise is not installed. Run: pip3 install apprise'];
        }

        try {
            $title = escapeshellarg('BBS Test Notification');
            $body = escapeshellarg('This is a test notification from Borg Backup Server. If you receive this, Apprise is configured correctly.');
            $urlArgs = implode(' ', array_map('escapeshellarg', $urls));

            $cmd = escapeshellarg($this->appriseBinary()) . " -t {$title} -b {$body} {$urlArgs} 2>&1";
            exec($cmd, $output, $code);

            if ($code === 0) {
                return ['success' => true];
            }

            return ['success' => false, 'error' => implode("\n", $output) ?: 'Apprise command failed.'];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}
