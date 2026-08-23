<?php

namespace BBS\Controllers\Api;

use BBS\Core\Controller;
use BBS\Services\Encryption;
use BBS\Services\UpdateService;
use BBS\Services\AppriseService;
use BBS\Services\Mailer;
use BBS\Services\SshKeyManager;

/**
 * Token-authenticated settings API — see docs/API.md for the shapes.
 *
 * Settings are server-wide, so everything here is admin-only, matching
 * SettingsController::requireAdmin(). The web controller writes the whole
 * settings form in one post with an allow-list; these endpoints split that by
 * section so a client can save one screen without round-tripping keys it never
 * displayed.
 *
 * Secret material is write-only throughout: send a value to set it, omit it to
 * leave the stored one alone, and never read it back.
 */
class SettingsApiController extends Controller
{
    /**
     * Section definitions: setting key => type.
     *
     * The settings table stores everything as a string, so the type drives both
     * the cast on the way out and the normalisation on the way in — otherwise
     * every client reimplements the same "0"-is-truthy coercion and they
     * disagree about it.
     */
    private const SECTIONS = [
        'general' => [
            'max_queue' => 'int',
            'agent_poll_interval' => 'int',
            'stall_timeout_minutes' => 'int',
            'agent_offline_notify_minutes' => 'int',
            'auto_retry_failed_backups' => 'bool',
            'auto_retry_max_attempts' => 'int',
            // Per-install defaults for the two settings a client profile can
            // override. Null on a profile means "use these".
            'job_offline_grace_minutes' => 'int',
            'auto_retry_backoff_minutes' => 'int',
            'precount_files' => 'bool',
            'backup_overdue_hours' => 'int',
            'auto_update_agents' => 'bool',
            'auto_compact_day' => 'int',
            'auto_compact_hour' => 'int',
            'self_backup_enabled' => 'bool',
            'self_backup_catalogs' => 'bool',
            'self_backup_retention' => 'int',
            'maintenance_mode' => 'bool',
            'debug_mode' => 'bool',
            'default_theme' => 'str',
            'server_host' => 'str',
            'ssh_port' => 'int',
            'session_timeout_hours' => 'int',
            'notification_retention_days' => 'int',
            'force_2fa' => 'bool',
            'telemetry_opt_out' => 'bool',
            'storage_alert_threshold' => 'int',
        ],
        'email' => [
            'smtp_host' => 'str',
            'smtp_port' => 'int',
            'smtp_user' => 'str',
            'smtp_secure' => 'str',
            'smtp_from' => 'str',
            'inapp_notify_success_events' => 'bool',
            'apprise_urls' => 'str',
            'apprise_on_backup_failed' => 'bool',
            'apprise_on_backup_warning' => 'bool',
            'apprise_on_agent_offline' => 'bool',
            'apprise_on_storage_low' => 'bool',
            'apprise_on_certificate_expiring' => 'bool',
            'apprise_on_missed_schedule' => 'bool',
            'email_on_backup_failed' => 'bool',
            'email_on_backup_warning' => 'bool',
            'email_on_agent_offline' => 'bool',
            'email_on_storage_low' => 'bool',
            'email_on_certificate_expiring' => 'bool',
            'email_on_missed_schedule' => 'bool',
        ],
        'auth' => [
            'oidc_enabled' => 'bool',
            'oidc_provider_url' => 'str',
            'oidc_client_id' => 'str',
            'oidc_redirect_url' => 'str',
            'oidc_scopes' => 'str',
            'oidc_button_label' => 'str',
            'oidc_new_user_policy' => 'str',
            'oidc_template_user_id' => 'int_or_null',
            'oidc_logout_enabled' => 'bool',
        ],
        'branding' => [
            'branding_login_theme' => 'str',
        ],
    ];

    /** Written only when non-empty, stored encrypted, never returned. */
    private const SECRET_KEYS = [
        'email' => ['smtp_pass'],
        'auth' => ['oidc_client_secret'],
    ];

    private function getJsonInput(): array
    {
        $raw = file_get_contents('php://input');
        $decoded = json_decode($raw ?: '', true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Defaults the web form applies when a setting has no row.
     *
     * Without these, GET reports 0/false for anything never explicitly saved —
     * so a server whose auto-retry is on with 3 attempts (the shipped
     * behaviour, no row written) told the app it was off with 0. The number a
     * client shows has to be the number that will actually be used.
     */
    private const DEFAULTS = [
        'max_queue' => '4',
        'agent_poll_interval' => '30',
        'stall_timeout_minutes' => '120',
        'agent_offline_notify_minutes' => '5',
        'auto_retry_failed_backups' => '1',
        'auto_retry_max_attempts' => '3',
        'job_offline_grace_minutes' => '5',
        'auto_retry_backoff_minutes' => '5',
        'precount_files' => '1',
        'backup_overdue_hours' => '48',
        'auto_update_agents' => '1',
        'auto_compact_day' => '6',
        'auto_compact_hour' => '2',
        'self_backup_enabled' => '1',
        'self_backup_retention' => '7',
        'session_timeout_hours' => '8',
        'notification_retention_days' => '30',
        'storage_alert_threshold' => '90',
        'default_theme' => 'dark',
        'ssh_port' => '22',
        'smtp_port' => '587',
        'push_relay_url' => 'https://push.borgbackupserver.com',
    ];

    private function allSettings(): array
    {
        $out = self::DEFAULTS;
        foreach ($this->db->fetchAll("SELECT `key`, `value` FROM settings") as $row) {
            // A stored empty string is a real choice for text fields, but for
            // the numeric and boolean ones it means "never set" and the
            // default still applies — same as the web form reads it.
            if ($row['value'] === '' && array_key_exists($row['key'], self::DEFAULTS)) {
                continue;
            }
            $out[$row['key']] = $row['value'];
        }
        return $out;
    }

    private function saveSetting(string $key, string $value): void
    {
        $existing = $this->db->fetchOne("SELECT `key` FROM settings WHERE `key` = ?", [$key]);
        if ($existing) {
            $this->db->update('settings', ['value' => $value], "`key` = ?", [$key]);
        } else {
            $this->db->insert('settings', ['key' => $key, 'value' => $value]);
        }
    }

    private function castOut(?string $raw, string $type, string $key): mixed
    {
        if ($type === 'bool') {
            return ($raw ?? '0') === '1';
        }
        if ($type === 'int') {
            return (int) ($raw ?? 0);
        }
        if ($type === 'int_or_null') {
            return ($raw === null || $raw === '') ? null : (int) $raw;
        }
        return $raw ?? '';
    }

    private function castIn(mixed $value, string $type): ?string
    {
        if ($type === 'bool') {
            return !empty($value) && $value !== '0' ? '1' : '0';
        }
        if ($type === 'int') {
            return (string) (int) $value;
        }
        if ($type === 'int_or_null') {
            return ($value === null || $value === '') ? '' : (string) (int) $value;
        }
        return trim((string) $value);
    }

    /**
     * One section, typed, with secrets replaced by *_set booleans.
     */
    private function sectionPayload(string $section, array $settings): array
    {
        $out = [];
        foreach (self::SECTIONS[$section] as $key => $type) {
            // branding_login_theme is exposed as login_theme, matching the doc
            $outKey = $key === 'branding_login_theme' ? 'login_theme' : $key;
            $out[$outKey] = $this->castOut($settings[$key] ?? null, $type, $key);
        }

        foreach (self::SECRET_KEYS[$section] ?? [] as $secret) {
            $out[$secret . '_set'] = !empty($settings[$secret]);
        }

        if ($section === 'general') {
            // Derived from APP_URL rather than stored, exactly as the web form
            // reads it — there is no url_protocol row in the settings table.
            $out['url_protocol'] = str_starts_with(
                \BBS\Core\Config::get('APP_URL', 'https://'), 'https://'
            ) ? 'https' : 'http';
        }

        if ($section === 'branding') {
            // Only the app icon has a serving route; the other assets are
            // reported as presence flags. Uploads stay in the web UI.
            $out['icon_url'] = !empty($settings['branding_app_icon']) ? '/branding/icon/180' : null;
            $out['app_icon_url'] = null;
            $out['login_logo_url'] = null;
            $out['navbar_icon_set'] = !empty($settings['branding_icon']);
            $out['login_logo_set'] = !empty($settings['branding_login_logo']);
        }

        return $out;
    }

    /**
     * GET /api/v1/settings — every section in one call.
     */
    public function show(): void
    {
        $this->requireApiAdmin();
        $settings = $this->allSettings();

        $out = [];
        foreach (array_keys(self::SECTIONS) as $section) {
            $out[$section] = $this->sectionPayload($section, $settings);
        }
        $this->json($out);
    }

    /**
     * PATCH /api/v1/settings/{section} — partial save of one section.
     *
     * Only keys present in the body are written; absent keys are left alone.
     * The app shows one section at a time, so treating an absent key as "clear
     * this" would wipe settings the screen never displayed.
     */
    public function updateSection(string $section): void
    {
        $this->requireApiAdmin();

        if (!isset(self::SECTIONS[$section])) {
            $this->json(['error' => 'Unknown settings section'], 404);
        }

        $input = $this->getJsonInput();
        $fields = self::SECTIONS[$section];
        $sideEffects = [];

        $oldServerHost = null;
        if ($section === 'general' && array_key_exists('server_host', $input)) {
            $row = $this->db->fetchOne("SELECT `value` FROM settings WHERE `key` = 'server_host'");
            $oldServerHost = $row['value'] ?? '';
        }

        foreach ($fields as $key => $type) {
            $inKey = $key === 'branding_login_theme' ? 'login_theme' : $key;
            if (!array_key_exists($inKey, $input)) {
                continue;
            }
            $this->saveSetting($key, $this->castIn($input[$inKey], $type));
        }

        // Secrets: set when a non-empty value arrives, otherwise untouched.
        // A blank field from a client means "keep what is stored", never "clear".
        foreach (self::SECRET_KEYS[$section] ?? [] as $secret) {
            if (!empty($input[$secret])) {
                $this->saveSetting($secret, Encryption::encrypt((string) $input[$secret]));
            }
        }

        if ($section === 'general' && $oldServerHost !== null) {
            $newHost = trim((string) $input['server_host']);

            // Repo paths bake the host in at creation time, so changing it has
            // to rewrite every agent without a per-client override (#367). A
            // PATCH that skipped this would leave every repo path pointing at
            // the old host.
            if ($newHost !== $oldServerHost) {
                $agents = $this->db->fetchAll(
                    "SELECT id FROM agents WHERE server_host_override IS NULL OR server_host_override = ''"
                );
                $updated = 0;
                foreach ($agents as $a) {
                    $updated += SshKeyManager::rewriteAgentRepoHosts($this->db, (int) $a['id'], $newHost);
                }
                if ($updated > 0) {
                    $this->db->insert('server_log', [
                        'level' => 'info',
                        'message' => "Server host changed — updated {$updated} repository path(s)",
                    ]);
                }
                $sideEffects['repo_paths_rewritten'] = $updated;
            }

            // Keep APP_URL in step with the host and protocol
            $protocol = (($input['url_protocol'] ?? null) === 'http') ? 'http' : (
                str_starts_with(\BBS\Core\Config::get('APP_URL', 'https://'), 'https://') ? 'https' : 'http'
            );
            if (isset($input['url_protocol'])) {
                $protocol = $input['url_protocol'] === 'http' ? 'http' : 'https';
            }
            $envPath = dirname(__DIR__, 3) . '/config/.env';
            if (file_exists($envPath) && is_writable($envPath)) {
                $env = file_get_contents($envPath);
                $env = preg_replace('/^APP_URL=.*$/m', 'APP_URL=' . $protocol . '://' . $newHost, $env);
                file_put_contents($envPath, $env);
            }
        }

        $response = [$section => $this->sectionPayload($section, $this->allSettings())];
        if (!empty($sideEffects)) {
            $response['side_effects'] = $sideEffects;
        }
        $this->json($response);
    }

    /**
     * POST /api/v1/settings/email/test — send a test message through the
     * saved SMTP settings. The one thing on the email screen worth having on
     * a phone: it's how you find out the settings are wrong before a backup
     * fails at 3am.
     */
    public function testEmail(): void
    {
        $ctx = $this->requireApiAdmin();
        $input = $this->getJsonInput();

        $to = trim((string) ($input['to'] ?? ''));
        if ($to === '') {
            $user = $this->db->fetchOne("SELECT email FROM users WHERE id = ?", [(int) $ctx['id']]);
            $to = $user['email'] ?? '';
        }
        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $this->json(['error' => 'A valid recipient address is required.'], 422);
        }

        $mailer = new Mailer();
        if (!$mailer->isEnabled()) {
            $this->json(['error' => 'SMTP is not configured on this server'], 409);
        }

        $sent = $mailer->send(
            $to,
            'BBS test message',
            '<p>This is a test message from Borg Backup Server. If you received it, your SMTP settings work.</p>'
        );

        if (!$sent) {
            $this->json(['error' => 'Failed to send. Check the SMTP settings.'], 502);
        }
        $this->json(['status' => 'ok']);
    }

    // ── Notification services (Apprise targets) ─────────────────────

    /**
     * The canonical event list. Shipped with the response rather than
     * hardcoded client-side: it has grown twice already, and a client with a
     * stale copy silently drops the new events from its editor.
     */
    private const EVENT_TYPES = [
        'backup_completed' => 'Backup Completed',
        'backup_warning' => 'Backup Completed with Warnings',
        'backup_failed' => 'Backup Failed',
        'restore_completed' => 'Restore Completed',
        'restore_failed' => 'Restore Failed',
        'agent_offline' => 'Client Offline',
        'agent_online' => 'Client Online',
        'repo_check_failed' => 'Check Failed',
        'repo_compact_done' => 'Compact Done',
        'storage_low' => 'Storage Low',
        'certificate_expiring' => 'SSL Certificate Expiring',
        's3_sync_failed' => 'S3 Sync Failed',
        's3_sync_done' => 'S3 Sync Done',
        'missed_schedule' => 'Missed Schedule',
    ];

    /**
     * An Apprise URL embeds a webhook credential, so it is never returned.
     * The hint keeps the scheme and a few identifying characters so the user
     * can tell two Slack targets apart before replacing one wholesale.
     */
    private function urlHint(string $url): string
    {
        if (!preg_match('#^(\w+)://(.*)$#', $url, $m)) {
            return '(hidden)';
        }
        $rest = $m[2];
        $tail = strlen($rest) > 5 ? substr($rest, -5) : '';
        return $m[1] . '://…' . $tail;
    }

    private function servicePayload(array $row): array
    {
        $events = json_decode($row['events'] ?? '{}', true) ?: [];
        return [
            'id' => (int) $row['id'],
            'name' => $row['name'],
            'service_type' => $row['service_type'],
            'enabled' => (bool) $row['enabled'],
            'url_hint' => $this->urlHint($row['apprise_url'] ?? ''),
            'events' => (object) $events,
            'last_used_at' => $row['last_used_at'],
            'created_at' => $row['created_at'],
        ];
    }

    private function detectServiceType(string $url): string
    {
        return preg_match('/^(\w+):\/\//', $url, $m) ? strtolower($m[1]) : 'unknown';
    }

    /** Normalise a posted events map to the known keys only. */
    private function normaliseEvents(mixed $events, array $existing = []): array
    {
        $out = [];
        foreach (array_keys(self::EVENT_TYPES) as $event) {
            if (is_array($events) && array_key_exists($event, $events)) {
                $out[$event] = !empty($events[$event]);
            } else {
                $out[$event] = !empty($existing[$event]);
            }
        }
        return $out;
    }

    public function listNotificationServices(): void
    {
        $this->requireApiAdmin();
        $rows = $this->db->fetchAll("SELECT * FROM notification_services ORDER BY name");
        $this->json([
            'services' => array_map(fn($r) => $this->servicePayload($r), $rows),
            'event_types' => self::EVENT_TYPES,
        ]);
    }

    public function createNotificationService(): void
    {
        $this->requireApiAdmin();
        $input = $this->getJsonInput();

        $name = trim((string) ($input['name'] ?? ''));
        $url = trim((string) ($input['apprise_url'] ?? ''));
        if ($name === '' || $url === '') {
            $this->json(['error' => 'name and apprise_url are required'], 422);
        }

        $id = $this->db->insert('notification_services', [
            'name' => $name,
            'service_type' => $this->detectServiceType($url),
            'apprise_url' => $url,
            'enabled' => array_key_exists('enabled', $input) ? (!empty($input['enabled']) ? 1 : 0) : 1,
            'events' => json_encode($this->normaliseEvents($input['events'] ?? null)),
        ]);

        $row = $this->db->fetchOne("SELECT * FROM notification_services WHERE id = ?", [$id]);
        $this->json(['service' => $this->servicePayload($row)], 201);
    }

    public function updateNotificationService(int $id): void
    {
        $this->requireApiAdmin();
        $input = $this->getJsonInput();

        $row = $this->db->fetchOne("SELECT * FROM notification_services WHERE id = ?", [$id]);
        if (!$row) {
            $this->json(['error' => 'Service not found'], 404);
        }

        $data = [];
        if (array_key_exists('name', $input)) {
            $name = trim((string) $input['name']);
            if ($name === '') {
                $this->json(['error' => 'name cannot be empty'], 422);
            }
            $data['name'] = $name;
        }
        // An empty apprise_url means "keep the stored one", never "clear it"
        if (!empty($input['apprise_url'])) {
            $url = trim((string) $input['apprise_url']);
            $data['apprise_url'] = $url;
            $data['service_type'] = $this->detectServiceType($url);
        }
        if (array_key_exists('enabled', $input)) {
            $data['enabled'] = !empty($input['enabled']) ? 1 : 0;
        }
        if (array_key_exists('events', $input)) {
            $existing = json_decode($row['events'] ?? '{}', true) ?: [];
            $data['events'] = json_encode($this->normaliseEvents($input['events'], $existing));
        }

        if (!empty($data)) {
            $this->db->update('notification_services', $data, 'id = ?', [$id]);
        }

        $this->json(['service' => $this->servicePayload(
            $this->db->fetchOne("SELECT * FROM notification_services WHERE id = ?", [$id])
        )]);
    }

    public function deleteNotificationService(int $id): void
    {
        $this->requireApiAdmin();
        if (!$this->db->fetchOne("SELECT id FROM notification_services WHERE id = ?", [$id])) {
            $this->json(['error' => 'Service not found'], 404);
        }
        $this->db->delete('notification_services', 'id = ?', [$id]);
        http_response_code(204);
        exit;
    }

    public function testNotificationService(int $id): void
    {
        $this->requireApiAdmin();

        $service = $this->db->fetchOne("SELECT * FROM notification_services WHERE id = ?", [$id]);
        if (!$service) {
            $this->json(['error' => 'Service not found'], 404);
        }

        $apprise = new AppriseService();
        if (!$apprise->isAppriseInstalled()) {
            $this->json(['error' => 'Apprise is not installed on the server.'], 409);
        }

        $cmd = 'apprise -t ' . escapeshellarg('BBS Test Notification')
             . ' -b ' . escapeshellarg('This is a test notification from Borg Backup Server. If you receive this, the service is configured correctly.')
             . ' ' . escapeshellarg($service['apprise_url']) . ' 2>&1';
        exec($cmd, $output, $code);

        if ($code !== 0) {
            $this->json(['error' => implode("\n", $output) ?: 'Apprise command failed.'], 502);
        }

        $this->db->update('notification_services', ['last_used_at' => date('Y-m-d H:i:s')], 'id = ?', [$id]);
        $this->json(['status' => 'ok']);
    }

    // ── Backup templates ────────────────────────────────────────────

    private function templatePayload(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'name' => $row['name'],
            'description' => $row['description'],
            'directories' => $row['directories'],
            'excludes' => $row['excludes'],
            'advanced_options' => $row['advanced_options'],
            'usage_count' => isset($row['usage_count']) ? (int) $row['usage_count'] : 0,
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'],
        ];
    }

    public function listTemplates(): void
    {
        $this->requireApiAdmin();
        // usage_count: plans whose directories match the template, the only
        // link that exists — templates are copied into a plan, not referenced.
        $rows = $this->db->fetchAll("
            SELECT t.*,
                   (SELECT COUNT(*) FROM backup_plans bp WHERE bp.directories = t.directories) AS usage_count
            FROM backup_templates t ORDER BY t.name");
        $this->json(['templates' => array_map(fn($r) => $this->templatePayload($r), $rows)]);
    }

    public function createTemplate(): void
    {
        $this->requireApiAdmin();
        $input = $this->getJsonInput();

        $name = trim((string) ($input['name'] ?? ''));
        $directories = trim((string) ($input['directories'] ?? ''));
        if ($name === '' || $directories === '') {
            $this->json(['error' => 'name and directories are required'], 422);
        }

        $id = $this->db->insert('backup_templates', [
            'name' => $name,
            'description' => trim((string) ($input['description'] ?? '')) ?: null,
            'directories' => $directories,
            'excludes' => trim((string) ($input['excludes'] ?? '')) ?: null,
            'advanced_options' => trim((string) ($input['advanced_options'] ?? '')) ?: null,
        ]);

        $this->json(['template' => $this->templatePayload(
            $this->db->fetchOne("SELECT * FROM backup_templates WHERE id = ?", [$id])
        )], 201);
    }

    public function updateTemplate(int $id): void
    {
        $this->requireApiAdmin();
        $input = $this->getJsonInput();

        if (!$this->db->fetchOne("SELECT id FROM backup_templates WHERE id = ?", [$id])) {
            $this->json(['error' => 'Template not found'], 404);
        }

        $data = [];
        if (array_key_exists('name', $input)) {
            $name = trim((string) $input['name']);
            if ($name === '') {
                $this->json(['error' => 'name cannot be empty'], 422);
            }
            $data['name'] = $name;
        }
        if (array_key_exists('directories', $input)) {
            $dirs = trim((string) $input['directories']);
            if ($dirs === '') {
                $this->json(['error' => 'directories cannot be empty'], 422);
            }
            $data['directories'] = $dirs;
        }
        foreach (['description', 'excludes', 'advanced_options'] as $field) {
            if (array_key_exists($field, $input)) {
                $data[$field] = trim((string) $input[$field]) ?: null;
            }
        }

        if (!empty($data)) {
            $this->db->update('backup_templates', $data, 'id = ?', [$id]);
        }

        $this->json(['template' => $this->templatePayload(
            $this->db->fetchOne("SELECT * FROM backup_templates WHERE id = ?", [$id])
        )]);
    }

    public function deleteTemplate(int $id): void
    {
        $this->requireApiAdmin();
        if (!$this->db->fetchOne("SELECT id FROM backup_templates WHERE id = ?", [$id])) {
            $this->json(['error' => 'Template not found'], 404);
        }
        $this->db->delete('backup_templates', 'id = ?', [$id]);
        http_response_code(204);
        exit;
    }

    // ── Push notification service ───────────────────────────────────

    /**
     * GET /api/v1/settings/push
     *
     * Not part of the settings section map on purpose. Turning this on is not
     * a value being saved — it registers the install with an external relay,
     * which is the first thing that ever leaves the server on its behalf. That
     * deserves its own endpoint and its own response rather than being one
     * key in a bulk PATCH that could enable it by accident.
     */
    public function showPush(): void
    {
        $this->requireApiAdmin();
        $push = new \BBS\Services\PushService();
        $this->json(['push' => [
            'enabled' => $push->isEnabled(),
            'relay_url' => $push->relayUrl(),
            'registered' => $push->isConfigured(),
            'server_id' => $push->serverId(),
        ]]);
    }

    /**
     * PUT /api/v1/settings/push — opt in or out.
     *
     * Enabling registers with the relay and reports whether that worked; the
     * flag is left on either way so a transient failure is retryable rather
     * than silently reverting, matching the web form.
     */
    public function updatePush(): void
    {
        $this->requireApiAdmin();
        $input = $this->getJsonInput();
        $push = new \BBS\Services\PushService();

        if (!array_key_exists('enabled', $input)) {
            $this->json(['error' => 'enabled is required'], 422);
        }

        if (empty($input['enabled'])) {
            $push->disable();
            $this->json(['push' => [
                'enabled' => false,
                'relay_url' => $push->relayUrl(),
                'registered' => false,
                'server_id' => null,
            ], 'message' => 'Push notifications disabled. Nothing further is sent to the push notification service.']);
        }

        $this->saveSetting('push_enabled', '1');
        if (!empty($input['relay_url'])) {
            $url = rtrim(trim((string) $input['relay_url']), '/');
            if (!filter_var($url, FILTER_VALIDATE_URL) || !str_starts_with($url, 'https://')) {
                $this->json(['error' => 'relay_url must be an https URL'], 422);
            }
            $this->saveSetting('push_relay_url', $url);
        }

        $fresh = new \BBS\Services\PushService();
        [$ok, $message] = $fresh->registerInstall();

        $this->json([
            'push' => [
                'enabled' => true,
                'relay_url' => $fresh->relayUrl(),
                'registered' => $fresh->isConfigured(),
                'server_id' => $fresh->serverId(),
            ],
            'registered_ok' => $ok,
            'message' => $message,
        ], $ok ? 200 : 502);
    }

    // ── Client profiles ─────────────────────────────────────────────

    private function profilePayload(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'name' => $row['name'],
            'description' => $row['description'],
            'is_default' => !empty($row['is_default']),
            'template_id' => $row['template_id'] !== null ? (int) $row['template_id'] : null,
            'template_name' => $row['template_name'] ?? null,
            'schedule' => [
                'frequency' => $row['frequency'],
                'times' => $row['times'],
                // Null = the server's zone; 'timezone_effective' is what an
                // apply would actually write onto the schedules.
                'timezone' => $row['timezone'],
                'timezone_effective' => (new \BBS\Services\ClientProfileService())->timezoneFor($row),
                'day_of_week' => $row['day_of_week'] !== null ? (int) $row['day_of_week'] : null,
                'day_of_month' => $row['day_of_month'],
            ],
            'retention' => [
                'minutes' => (int) $row['prune_minutes'],
                'hours' => (int) $row['prune_hours'],
                'days' => (int) $row['prune_days'],
                'weeks' => (int) $row['prune_weeks'],
                'months' => (int) $row['prune_months'],
                'years' => (int) $row['prune_years'],
            ],
            // Null means "follow the server-wide setting". The resolved value
            // is sent alongside so a client can show what will actually happen
            // without fetching settings and applying the fallback itself.
            'failure_handling' => [
                'max_retry_attempts' => $row['auto_retry_max_attempts'] !== null ? (int) $row['auto_retry_max_attempts'] : null,
                'give_up_after_minutes' => $row['job_offline_grace_minutes'] !== null ? (int) $row['job_offline_grace_minutes'] : null,
                'retry_backoff_minutes' => $row['auto_retry_backoff_minutes'] !== null ? (int) $row['auto_retry_backoff_minutes'] : null,
                'backup_overdue_hours' => $row['backup_overdue_hours'] !== null ? (int) $row['backup_overdue_hours'] : null,
            ],
            'failure_handling_effective' => $this->profileEffectiveFailure($row),
            'client_count' => isset($row['client_count']) ? (int) $row['client_count'] : 0,
            'created_at' => $row['created_at'] ?? null,
            'updated_at' => $row['updated_at'] ?? null,
        ];
    }

    private function profileEffectiveFailure(array $row): array
    {
        $svc = new \BBS\Services\ClientProfileService();
        $map = [
            'max_retry_attempts' => ['auto_retry_max_attempts', 'auto_retry_max_attempts'],
            'give_up_after_minutes' => ['job_offline_grace_minutes', 'job_offline_grace_minutes'],
            'retry_backoff_minutes' => ['auto_retry_backoff_minutes', 'auto_retry_backoff_minutes'],
            'backup_overdue_hours' => ['backup_overdue_hours', 'backup_overdue_hours'],
        ];
        $out = [];
        foreach ($map as $key => [$col, $setting]) {
            $out[$key] = ($row[$col] !== null && $row[$col] !== '')
                ? (int) $row[$col]
                : $svc->globalFailureSetting($setting);
        }
        return $out;
    }

    /** Fields a profile accepts, validated. Returns [data, errorOrNull]. */
    private function profileInput(array $input, bool $creating): array
    {
        $data = [];

        if ($creating || array_key_exists('name', $input)) {
            $name = trim((string) ($input['name'] ?? ''));
            if ($name === '') {
                return [null, 'name is required'];
            }
            $data['name'] = $name;
        }
        if (array_key_exists('description', $input)) {
            $data['description'] = substr(trim((string) $input['description']), 0, 255);
        }
        if (array_key_exists('template_id', $input)) {
            $tid = $input['template_id'];
            if ($tid !== null && $tid !== '') {
                if (!$this->db->fetchOne("SELECT id FROM backup_templates WHERE id = ?", [(int) $tid])) {
                    return [null, 'template_id does not exist'];
                }
                $data['template_id'] = (int) $tid;
            } else {
                $data['template_id'] = null;
            }
        }

        $schedule = $input['schedule'] ?? [];
        if (array_key_exists('frequency', $schedule)) {
            $freq = (string) $schedule['frequency'];
            if (!in_array($freq, ['hourly', 'daily', 'weekly', 'monthly'], true)) {
                return [null, 'schedule.frequency must be hourly, daily, weekly or monthly'];
            }
            $data['frequency'] = $freq;
        }
        if (array_key_exists('times', $schedule)) {
            $data['times'] = substr(trim((string) $schedule['times']), 0, 255) ?: '02:00';
        }
        if (array_key_exists('timezone', $schedule)) {
            $tz = $schedule['timezone'];
            if ($tz !== null && $tz !== '' && !in_array($tz, \DateTimeZone::listIdentifiers(), true)) {
                return [null, 'schedule.timezone must be a valid timezone identifier'];
            }
            $data['timezone'] = ($tz === '' ) ? null : $tz;
        }
        if (array_key_exists('day_of_week', $schedule)) {
            $dow = $schedule['day_of_week'];
            if ($dow !== null && ($dow < 0 || $dow > 6)) {
                return [null, 'schedule.day_of_week must be 0-6'];
            }
            $data['day_of_week'] = $dow === null ? null : (int) $dow;
        }
        if (array_key_exists('day_of_month', $schedule)) {
            $data['day_of_month'] = $schedule['day_of_month'] === null
                ? null : substr((string) $schedule['day_of_month'], 0, 20);
        }

        $retention = $input['retention'] ?? [];
        foreach (['minutes', 'hours', 'days', 'weeks', 'months', 'years'] as $unit) {
            if (array_key_exists($unit, $retention)) {
                // -1 is borg's "keep every one at this interval" (#386).
                $data['prune_' . $unit] = max(-1, (int) $retention[$unit]);
            }
        }

        $failure = $input['failure_handling'] ?? [];
        $fmap = [
            'max_retry_attempts' => 'auto_retry_max_attempts',
            'give_up_after_minutes' => 'job_offline_grace_minutes',
            'retry_backoff_minutes' => 'auto_retry_backoff_minutes',
            'backup_overdue_hours' => 'backup_overdue_hours',
        ];
        foreach ($fmap as $key => $col) {
            if (array_key_exists($key, $failure)) {
                // null is meaningful: it means follow the server-wide setting.
                $data[$col] = ($failure[$key] === null || $failure[$key] === '')
                    ? null : max(0, (int) $failure[$key]);
            }
        }

        return [$data, null];
    }

    private function profileRow(int $id): ?array
    {
        return $this->db->fetchOne("
            SELECT cp.*, t.name AS template_name,
                   (SELECT COUNT(*) FROM agents a WHERE a.client_profile_id = cp.id) AS client_count
            FROM client_profiles cp
            LEFT JOIN backup_templates t ON t.id = cp.template_id
            WHERE cp.id = ?
        ", [$id]) ?: null;
    }

    public function listProfiles(): void
    {
        $this->requireApiAdmin();
        $rows = (new \BBS\Services\ClientProfileService())->all();
        $this->json(['profiles' => array_map(fn($r) => $this->profilePayload($r), $rows)]);
    }

    public function getProfile(int $id): void
    {
        $this->requireApiAdmin();
        $row = $this->profileRow($id);
        if (!$row) {
            $this->json(['error' => 'Profile not found'], 404);
        }
        $payload = $this->profilePayload($row);
        $payload['apply_impact'] = (new \BBS\Services\ClientProfileService())->applyImpact($id);
        $this->json(['profile' => $payload]);
    }

    public function createProfile(): void
    {
        $this->requireApiAdmin();
        [$data, $error] = $this->profileInput($this->getJsonInput(), true);
        if ($error) {
            $this->json(['error' => $error], 422);
        }

        try {
            $id = $this->db->insert('client_profiles', $data);
        } catch (\Exception $e) {
            $this->json(['error' => 'A profile with that name already exists'], 409);
        }

        $this->json(['profile' => $this->profilePayload($this->profileRow($id))], 201);
    }

    public function updateProfile(int $id): void
    {
        $this->requireApiAdmin();
        if (!$this->profileRow($id)) {
            $this->json(['error' => 'Profile not found'], 404);
        }

        [$data, $error] = $this->profileInput($this->getJsonInput(), false);
        if ($error) {
            $this->json(['error' => $error], 422);
        }

        if (!empty($data)) {
            try {
                $this->db->update('client_profiles', $data, 'id = ?', [$id]);
            } catch (\Exception $e) {
                $this->json(['error' => 'A profile with that name already exists'], 409);
            }
        }

        // Deliberately does not touch existing clients — see applyProfile().
        $this->json(['profile' => $this->profilePayload($this->profileRow($id))]);
    }

    public function deleteProfile(int $id): void
    {
        $this->requireApiAdmin();
        $row = $this->profileRow($id);
        if (!$row) {
            $this->json(['error' => 'Profile not found'], 404);
        }
        if (!empty($row['is_default'])) {
            $this->json(['error' => 'The default profile cannot be deleted'], 409);
        }

        $svc = new \BBS\Services\ClientProfileService();
        $this->db->query("UPDATE agents SET client_profile_id = ? WHERE client_profile_id = ?",
            [$svc->defaultProfileId(), $id]);
        $this->db->delete('client_profiles', 'id = ?', [$id]);

        http_response_code(204);
        exit;
    }

    /**
     * POST /api/v1/client-profiles/{id}/apply
     *
     * Overwrites the plans and schedules of every client in the profile. The
     * one destructive call here, so it requires confirm:true in the body — a
     * mistyped id should not be able to rewrite a fleet.
     */
    public function applyProfile(int $id): void
    {
        $this->requireApiAdmin();
        $row = $this->profileRow($id);
        if (!$row) {
            $this->json(['error' => 'Profile not found'], 404);
        }

        $input = $this->getJsonInput();
        $svc = new \BBS\Services\ClientProfileService();
        if (empty($input['confirm'])) {
            $this->json([
                'error' => 'confirm must be true — this overwrites settings on every client in the profile',
                'apply_impact' => $svc->applyImpact($id),
            ], 422);
        }

        $result = $svc->applyToClients($id);
        $this->db->insert('server_log', [
            'level' => 'warning',
            'message' => "Profile \"{$row['name']}\" applied via API to {$result['clients']} client(s): "
                       . "{$result['plans']} backup plan(s) and {$result['schedules']} schedule(s) overwritten.",
        ]);

        $this->json(['applied' => $result]);
    }

    // ── API tokens ──────────────────────────────────────────────────

    public function listTokens(): void
    {
        $ctx = $this->requireApiAdmin();
        $rows = $this->db->fetchAll("
            SELECT t.id, t.name, t.kind, t.can_read_secrets, t.user_id, u.username,
                   t.created_at, t.last_used_at, t.last_seen_ip, t.expires_at, t.device_name
            FROM api_tokens t JOIN users u ON u.id = t.user_id
            ORDER BY t.created_at DESC");

        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'id' => (int) $r['id'],
                'name' => $r['name'],
                'kind' => $r['kind'],
                'can_read_secrets' => (bool) $r['can_read_secrets'],
                'user_id' => (int) $r['user_id'],
                'username' => $r['username'],
                'created_at' => $r['created_at'],
                'last_used_at' => $r['last_used_at'],
                'last_seen_ip' => $r['last_seen_ip'],
                'expires_at' => $r['expires_at'],
                'device_name' => $r['device_name'],
                // The caller cannot otherwise tell which bbs_tok_… row is the
                // session it is holding, and revoking it signs them out.
                'is_current' => (int) $r['id'] === (int) $ctx['token_id'],
            ];
        }
        $this->json(['tokens' => $out]);
    }

    public function createToken(): void
    {
        $ctx = $this->requireApiAdmin();
        $input = $this->getJsonInput();

        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '') {
            $this->json(['error' => 'name is required'], 422);
        }
        if ($this->db->fetchOne("SELECT id FROM api_tokens WHERE name = ?", [$name])) {
            $this->json(['error' => "A token named \"{$name}\" already exists."], 409);
        }

        // A mobile token must never be able to mint itself a secrets-reading
        // token — that would walk straight around the restriction that keeps
        // repository passphrases and S3 credentials off the phone.
        $canReadSecrets = !empty($input['can_read_secrets']);
        if ($canReadSecrets && ($ctx['token_kind'] ?? 'user') === 'mobile') {
            $this->json([
                'error' => 'A mobile session cannot create a token that reads secrets.',
            ], 403);
        }

        $token = 'bbs_tok_' . bin2hex(random_bytes(24));
        $id = $this->db->insert('api_tokens', [
            'name' => $name,
            'token_hash' => hash('sha256', $token),
            'user_id' => (int) $ctx['id'],
            'can_read_secrets' => $canReadSecrets ? 1 : 0,
        ]);

        // Returned once, here, and never again — same contract as the 2FA
        // recovery codes.
        $this->json(['id' => (int) $id, 'token' => $token], 201);
    }

    public function deleteToken(int $id): void
    {
        $this->requireApiAdmin();

        $row = $this->db->fetchOne("SELECT kind FROM api_tokens WHERE id = ?", [$id]);
        if (!$row) {
            $this->json(['error' => 'Token not found'], 404);
        }
        // Same guard the web UI has: the hosted platform's own token is not
        // revocable from a customer-facing surface.
        if (($row['kind'] ?? 'user') === 'platform') {
            $this->json(['error' => 'This token is managed by the hosted platform.'], 403);
        }

        $this->db->delete('api_tokens', 'id = ?', [$id]);
        http_response_code(204);
        exit;
    }

    // ── Updates ─────────────────────────────────────────────────────

    private function updatesPayload(UpdateService $svc): array
    {
        $latest = $svc->getLatestRelease();
        return [
            'server' => [
                'current_version' => $svc->getCurrentVersion(),
                'latest_version' => $latest['version'] ?: null,
                'update_available' => $svc->isUpdateAvailable(),
                'release_notes' => $latest['notes'] ?: null,
                'release_url' => $latest['url'] ?: null,
                'include_prereleases' => $svc->getIncludePrereleases(),
                'checked_at' => $latest['checked_at'] ?: null,
            ],
            'agents' => [
                'bundled_version' => $svc->getBundledAgentVersion(),
                'outdated' => array_map(fn($a) => [
                    'id' => (int) $a['id'],
                    'name' => $a['name'],
                    'agent_version' => $a['agent_version'],
                ], $svc->getOutdatedAgents()),
            ],
        ];
    }

    public function updates(): void
    {
        $this->requireApiAdmin();
        $this->json($this->updatesPayload(new UpdateService()));
    }

    public function checkUpdates(): void
    {
        $this->requireApiAdmin();
        $svc = new UpdateService();
        $svc->checkForUpdate();
        $this->json($this->updatesPayload($svc));
    }

    /**
     * Queue an agent upgrade, skipping agents that already have one pending.
     * Returns the number queued so the caller can report it.
     */
    private function queueAgentUpgrades(array $agents, string $bundledVersion, string $note): int
    {
        $pending = array_column($this->db->fetchAll(
            "SELECT agent_id FROM backup_jobs
             WHERE task_type = 'update_agent' AND status IN ('queued', 'sent', 'running')"
        ), 'agent_id');

        $queued = 0;
        foreach ($agents as $agent) {
            if (in_array($agent['id'], $pending)) {
                continue;
            }
            $jobId = $this->db->insert('backup_jobs', [
                'agent_id' => $agent['id'],
                'task_type' => 'update_agent',
                'status' => 'queued',
            ]);
            $this->db->insert('server_log', [
                'agent_id' => $agent['id'],
                'backup_job_id' => $jobId,
                'level' => 'info',
                'message' => "Agent update queued ({$note}) to v{$bundledVersion}",
            ]);
            $queued++;
        }
        return $queued;
    }

    public function upgradeAgent(int $id): void
    {
        $this->requireApiAdmin();

        $svc = new UpdateService();
        $bundled = $svc->getBundledAgentVersion();
        if (!$bundled) {
            $this->json(['error' => 'Could not determine the bundled agent version.'], 500);
        }

        $agent = $this->db->fetchOne("SELECT id, name FROM agents WHERE id = ?", [$id]);
        if (!$agent) {
            $this->json(['error' => 'Client not found'], 404);
        }

        $queued = $this->queueAgentUpgrades([$agent], $bundled, 'API');
        $this->json([
            'status' => 'ok',
            'queued' => $queued,
            'already_pending' => $queued === 0,
        ]);
    }

    /**
     * POST /api/v1/updates/upgrade-server
     *
     * The API twin of the web's upgrade button. No target is accepted: it
     * upgrades to the latest release the update check found, the same as the
     * web. The 'main' branch variant stays web-only — that is a developer
     * action and does not belong on a remote control.
     *
     * The upgrade restarts the application, so this response races the restart.
     * It returns as soon as the upgrade has been started rather than waiting
     * for a result that may never arrive down this connection.
     */
    public function upgradeServer(): void
    {
        $this->requireApiAdmin();

        $svc = new \BBS\Services\UpdateService();
        $result = $svc->startBackgroundUpgrade();

        if (empty($result['success'])) {
            $error = (string) ($result['error'] ?? 'Upgrade could not be started.');
            // Already running is a conflict; nothing to upgrade to is a bad
            // request. Anything else is ours.
            // "already in progress" and "jobs still running" are both "not
            // now" rather than "never" — the spec named the first, and the
            // second is the same class of answer, so it gets the same code.
            $conflict = str_contains($error, 'already in progress') || str_contains($error, 'still running');
            $badRequest = str_contains($error, 'No update information') || str_contains($error, 'Already up to date');
            $code = $conflict ? 409 : ($badRequest ? 422 : 500);
            $this->json(['error' => $error], $code);
        }

        $this->db->insert('server_log', [
            'level' => 'info',
            'message' => 'Server upgrade started via API',
        ]);

        $this->json([
            'status' => 'started',
            'target' => $result['tag'] ?? $result['version'] ?? $this->settingValue('latest_version'),
        ], 202);
    }

    /**
     * DELETE /api/v1/updates/upgrade-server — stop waiting for the queue.
     */
    public function cancelUpgradeServer(): void
    {
        $this->requireApiAdmin();

        if ($this->settingValue('upgrade_pending') !== '1') {
            $this->json(['error' => 'No upgrade is waiting to start.'], 422);
        }

        (new \BBS\Services\UpdateService())->cancelPendingUpgrade();
        $this->db->insert('server_log', [
            'level' => 'info',
            'message' => 'Waiting upgrade cancelled via API',
        ]);

        $this->json(['status' => 'cancelled']);
    }

    private function settingValue(string $key): ?string
    {
        return $this->db->fetchOne("SELECT `value` FROM settings WHERE `key` = ?", [$key])['value'] ?? null;
    }

    public function upgradeAgents(): void
    {
        $this->requireApiAdmin();
        $input = $this->getJsonInput();

        $svc = new UpdateService();
        $bundled = $svc->getBundledAgentVersion();
        if (!$bundled) {
            $this->json(['error' => 'Could not determine the bundled agent version.'], 500);
        }

        $outdated = $svc->getOutdatedAgents();
        if (!empty($input['client_ids']) && is_array($input['client_ids'])) {
            $wanted = array_map('intval', $input['client_ids']);
            $outdated = array_values(array_filter($outdated, fn($a) => in_array((int) $a['id'], $wanted, true)));
        }

        $queued = $this->queueAgentUpgrades($outdated, $bundled, 'bulk');
        $this->json([
            'status' => 'ok',
            'queued' => $queued,
            'outdated' => count($outdated),
            'bundled_version' => $bundled,
        ]);
    }
}
