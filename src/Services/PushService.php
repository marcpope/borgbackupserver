<?php

namespace BBS\Services;

use BBS\Core\Database;

/**
 * Push notification delivery.
 *
 * Delivery is brokered by a hosted relay: this server holds no transport
 * credential and never sees a device's push token after registration. Sends
 * address devices by the identifier the client registered with, which the
 * relay maps to a token, so a leaked identifier is useless on its own.
 *
 * The relay is a network dependency on an otherwise self-contained server, so
 * nothing here may ever block. Notifications enqueue with a single INSERT and
 * the scheduler drains the queue under a time budget with a circuit breaker —
 * an install with no outbound access pays nothing beyond one failed attempt
 * per cooldown, and a slow relay can never stall a request or a scheduler tick.
 */
class PushService
{
    /** Give up on a queued notification after this long — a backup alert that
     *  arrives tomorrow is worse than one that never arrives. */
    private const MAX_AGE_HOURS = 24;

    /** Consecutive relay failures before the breaker opens. */
    private const BREAKER_THRESHOLD = 3;

    /** How long the breaker stays open. */
    private const BREAKER_COOLDOWN_SECONDS = 900;

    /** Per-tick wall-clock budget for draining, checked between sends. */
    private const DRAIN_BUDGET_SECONDS = 5;

    /** Most notifications sent per tick, so a backlog spreads over ticks. */
    private const DRAIN_BATCH = 50;

    private const HTTP_TIMEOUT = 5;

    /** Events a device receives unless the user says otherwise. Failures
     *  only: a product that pushes every successful nightly backup gets its
     *  notifications turned off within a week. */
    public const DEFAULT_EVENTS = [
        'backup_failed'    => true,
        'backup_warning'   => false,
        // Off unless a device turns it on: a fleet backing up twice a day is
        // dozens of pushes, a single home server is one welcome "it ran".
        'backup_completed' => false,
        'agent_offline'    => true,
        'storage_low'      => true,
        'missed_schedule'  => false,
    ];

    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    // ── Configuration ───────────────────────────────────────────────

    private function setting(string $key, ?string $default = null): ?string
    {
        $row = $this->db->fetchOne("SELECT `value` FROM settings WHERE `key` = ?", [$key]);
        return $row['value'] ?? $default;
    }

    private function saveSetting(string $key, string $value): void
    {
        $this->db->query(
            "INSERT INTO settings (`key`, `value`) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)",
            [$key, $value]
        );
    }

    public function isEnabled(): bool
    {
        return $this->setting('push_enabled', '0') === '1';
    }

    public function relayUrl(): string
    {
        return rtrim($this->setting('push_relay_url', 'https://push.borgbackupserver.com'), '/');
    }

    public function serverId(): ?string
    {
        return $this->setting('push_server_id') ?: null;
    }

    private function serverKey(): ?string
    {
        $stored = $this->setting('push_serverkey');
        if (!$stored) {
            return null;
        }
        try {
            return Encryption::decrypt($stored);
        } catch (\Exception $e) {
            return null;
        }
    }

    /** Registered with the relay and switched on. */
    public function isConfigured(): bool
    {
        return $this->isEnabled() && $this->serverId() && $this->serverKey();
    }

    /**
     * Stable identity for this install, minted once.
     *
     * Cleared by a restore so a database restored onto a second host comes up
     * as its own server rather than sharing the original's identity.
     */
    public function installId(): string
    {
        $id = $this->setting('push_install_id');
        if (!$id) {
            $id = bin2hex(random_bytes(16));
            $this->saveSetting('push_install_id', $id);
        }
        return $id;
    }

    // ── Circuit breaker ─────────────────────────────────────────────

    /**
     * Title, body and a deep link for one queued event.
     *
     * Everything is looked up at send time rather than stored on the queue row:
     * a client renamed between the event and the send should read as its
     * current name, and the queue stays a list of what happened rather than a
     * copy of how it was worded.
     *
     * `deep_link` is a path, not a URL. The app already knows which server it
     * is talking to, and a path cannot send anyone to a different one.
     */
    private function describe(string $event, int $refId, int $clientId): array
    {
        // The queue's job_id column carries the notification's reference id,
        // and what that means depends on the event: a backup plan for
        // backup_failed / backup_warning / missed_schedule, a day-threshold
        // for certificate_expiring, nothing for the rest. Treating it as a
        // job id for every event looked up unrelated jobs and could name the
        // wrong plan and link the wrong page.
        $planEvents = ['backup_failed', 'backup_warning', 'backup_completed', 'missed_schedule'];
        $planId = in_array($event, $planEvents, true) ? $refId : 0;
        $jobId = 0;
        $agentRow = $clientId > 0
            ? $this->db->fetchOne("SELECT name, last_heartbeat FROM agents WHERE id = ?", [$clientId])
            : null;
        $client = $agentRow['name'] ?? null;

        // How long the client has been quiet, for the offline body. Relative
        // rather than a timestamp: "for 3 hours" reads the same in every
        // timezone, which a lock screen cannot ask about.
        $quietFor = null;
        if (!empty($agentRow['last_heartbeat'])) {
            $mins = (int) floor((time() - strtotime($agentRow['last_heartbeat'])) / 60);
            if ($mins >= 1) {
                if ($mins < 60) {
                    $quietFor = $mins . ' minute' . ($mins === 1 ? '' : 's');
                } elseif ($mins < 48 * 60) {
                    $h = (int) round($mins / 60);
                    $quietFor = $h . ' hour' . ($h === 1 ? '' : 's');
                } else {
                    $d = (int) round($mins / 1440);
                    $quietFor = $d . ' day' . ($d === 1 ? '' : 's');
                }
            }
        }

        $plan = null;
        if ($planId > 0) {
            $row = $this->db->fetchOne("
                SELECT bp.name AS plan_name, a.name AS client_name, bp.agent_id
                FROM backup_plans bp
                LEFT JOIN agents a ON a.id = bp.agent_id
                WHERE bp.id = ?
            ", [$planId]);
            $plan = $row['plan_name'] ?? null;
            $client = $client ?: ($row['client_name'] ?? null);
            if ($clientId <= 0 && !empty($row['agent_id'])) {
                $clientId = (int) $row['agent_id'];
            }

            // The job the failure or warning is about, for the deep link: the
            // plan's most recent job in that state.
            if ($event === 'backup_completed') {
                $done = $this->db->fetchOne(
                    "SELECT id, files_processed, files_total, duration_seconds FROM backup_jobs
                     WHERE backup_plan_id = ? AND task_type = 'backup' AND status = 'completed'
                     ORDER BY id DESC LIMIT 1",
                    [$planId]
                );
                $jobId = (int) ($done['id'] ?? 0);
                $doneFiles = (int) (($done['files_processed'] ?? 0) ?: ($done['files_total'] ?? 0));
                $doneSecs  = (int) ($done['duration_seconds'] ?? 0);
            } elseif ($event === 'backup_failed') {
                $jobId = (int) ($this->db->fetchOne(
                    "SELECT id FROM backup_jobs WHERE backup_plan_id = ? AND task_type = 'backup' AND status = 'failed' ORDER BY id DESC LIMIT 1",
                    [$planId]
                )['id'] ?? 0);
            } elseif ($event === 'backup_warning') {
                $jobId = (int) ($this->db->fetchOne(
                    "SELECT id FROM backup_jobs WHERE backup_plan_id = ? AND task_type = 'backup' AND had_warnings = 1 ORDER BY id DESC LIMIT 1",
                    [$planId]
                )['id'] ?? 0);
            }
        }

        $on = $client !== null ? " on {$client}" : '';
        $forPlan = $plan !== null ? " \"{$plan}\"" : '';

        // Deepest thing the event is actually about: a job if there is one,
        // otherwise the client, otherwise the page that lists them.
        $deepLink = $jobId > 0 ? "/jobs/{$jobId}"
            : ($clientId > 0 ? "/clients/{$clientId}" : '/dashboard');

        // Title is the client, body is what happened. An iOS banner gives
        // the title one line and the body two, so the name — the part that
        // decides whether you care — must never be the part that truncates.
        $title = $client ?? null;

        switch ($event) {
            case 'backup_failed':
                return ['title' => $title ?? 'Backup Failed',
                        'body' => ($plan !== null ? "{$plan} Failed" : 'Backup Failed') . ' — did not complete',
                        'deep_link' => $deepLink];
            case 'backup_completed':
                $detail = '';
                if (!empty($doneSecs)) {
                    $detail = $doneSecs >= 3600
                        ? floor($doneSecs / 3600) . 'h ' . floor(($doneSecs % 3600) / 60) . 'm'
                        : ($doneSecs >= 60 ? floor($doneSecs / 60) . 'm ' . ($doneSecs % 60) . 's' : $doneSecs . 's');
                }
                return ['title' => $title ?? 'Backup Complete',
                        'body' => ($plan !== null ? "{$plan} Complete" : 'Backup Complete')
                            . (!empty($doneFiles) ? ', Files: ' . number_format($doneFiles) : '')
                            . ($detail !== '' ? " / Time: {$detail}" : ''),
                        'deep_link' => $deepLink];
            case 'backup_warning':
                return ['title' => $title ?? 'Backup Warnings',
                        'body' => ($plan !== null ? "{$plan} Complete" : 'Backup Complete') . ' — with warnings',
                        'deep_link' => $deepLink];
            case 'missed_schedule':
                return ['title' => $title ?? 'Missed Backup',
                        'body' => ($plan !== null ? "{$plan} Missed" : 'Backup Missed') . ' — did not run while offline',
                        'deep_link' => $clientId > 0 ? "/clients/{$clientId}" : '/clients'];
            case 'agent_offline':
                return ['title' => $title ?? 'Client Offline',
                        'body' => $quietFor !== null
                            ? "Offline — no check-in for {$quietFor}"
                            : 'Offline — stopped checking in',
                        'deep_link' => $clientId > 0 ? "/clients/{$clientId}" : '/clients'];
            case 'storage_low':
                // Name the location(s) over the threshold, from the same
                // figures the scheduler alerted on. One location: it is the
                // title, the numbers are the body. Several: list them.
                $low = $this->lowStorage();
                if (count($low) === 1) {
                    $st = $low[0];
                    return ['title' => $st['label'] . ' is running low',
                            'body' => $st['used_percent'] . '% used, '
                                . ServerStats::formatBytes((int) $st['free_bytes']) . ' free of '
                                . ServerStats::formatBytes((int) $st['total_bytes']),
                            'deep_link' => '/storage-locations'];
                }
                if (count($low) > 1) {
                    return ['title' => count($low) . ' storage locations running low',
                            'body' => implode(', ', array_map(
                                fn($st) => $st['label'] . ' ' . $st['used_percent'] . '%',
                                $low
                            )),
                            'deep_link' => '/storage-locations'];
                }
                return ['title' => 'Storage running low',
                        'body' => 'A storage location passed your threshold.',
                        'deep_link' => '/storage-locations'];
            case 'certificate_expiring':
                return ['title' => 'SSL certificate needs attention',
                        'body' => 'The certificate is close to expiring and has not renewed.',
                        'deep_link' => '/settings?tab=ssl'];
            default:
                $label = ucfirst(str_replace('_', ' ', $event));
                return ['title' => $title ?? $label,
                        'body' => $title !== null ? "{$label}. Open for details." : 'Open for details.',
                        'deep_link' => $deepLink];
        }
    }

    /**
     * Storage endpoints at or over the server-wide threshold right now.
     * Labels lose the "Remote storage" prefix the log message carries: on a
     * lock screen the host's name is what matters.
     */
    private function lowStorage(): array
    {
        $threshold = (int) $this->setting('storage_alert_threshold', '90');
        if ($threshold < 1 || $threshold > 100) {
            $threshold = 90;
        }
        $low = [];
        try {
            foreach (ServerStats::storageUsageStats($this->db) as $st) {
                if ($st['used_percent'] < $threshold) {
                    continue;
                }
                $st['label'] = preg_replace('/^Remote storage "(.*)"$/', '$1', $st['label']);
                $low[] = $st;
            }
        } catch (\Throwable $e) {
            // Fall back to the generic wording rather than lose the push.
        }
        return $low;
    }

    private function breakerOpen(): bool
    {
        $until = $this->setting('push_relay_cooldown_until');
        return $until && strtotime($until) > time();
    }

    private function recordFailure(): void
    {
        $count = (int) $this->setting('push_relay_fail_count', '0') + 1;
        $this->saveSetting('push_relay_fail_count', (string) $count);
        if ($count >= self::BREAKER_THRESHOLD) {
            $this->saveSetting(
                'push_relay_cooldown_until',
                date('Y-m-d H:i:s', time() + self::BREAKER_COOLDOWN_SECONDS)
            );
        }
    }

    private function recordSuccess(): void
    {
        if ($this->setting('push_relay_fail_count', '0') !== '0') {
            $this->saveSetting('push_relay_fail_count', '0');
            $this->saveSetting('push_relay_cooldown_until', '');
        }
    }

    // ── Relay transport ─────────────────────────────────────────────

    /**
     * One request to the relay. Returns the decoded body, or null on any
     * failure — callers treat the relay as best-effort throughout.
     */
    private function request(string $method, string $path, ?array $body = null, bool $withAuth = true): ?array
    {
        $ch = curl_init();
        $headers = ['Content-Type: application/json'];
        if ($withAuth) {
            $key = $this->serverKey();
            if (!$key) {
                return null;
            }
            $headers[] = 'Authorization: Bearer ' . $key;
        }

        curl_setopt_array($ch, [
            CURLOPT_URL => $this->relayUrl() . $path,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => self::HTTP_TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_POSTFIELDS => $body !== null ? json_encode($body) : null,
        ]);

        $response = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false || $code < 200 || $code >= 300) {
            $this->recordFailure();
            return null;
        }
        $this->recordSuccess();
        return json_decode((string) $response, true) ?: [];
    }

    /**
     * Register this install with the service, or confirm an existing
     * registration.
     *
     * Registration is idempotent by `install_id`, and the key comes back on
     * the FIRST registration only — a repeat returns the same `server_id` with
     * a null key and `already_registered`. That is deliberate: it stops a
     * restored database coming up as a second install and silently
     * deregistering the original. It also means the key must be persisted on
     * the first call, so this saves whatever it is given immediately and never
     * overwrites a stored key with a null one.
     *
     * Returns [ok, message] so the caller can say what happened.
     */
    public function registerInstall(): array
    {
        if (!$this->isEnabled()) {
            return [false, 'Push notifications are switched off.'];
        }

        $result = $this->request('POST', '/v1/servers/register', [
            'install_id' => $this->installId(),
            'version' => trim(@file_get_contents(dirname(__DIR__, 2) . '/VERSION') ?: ''),
            'hostname' => $this->setting('server_host', '') ?: '',
        ], false);

        if ($result === null) {
            return [false, 'Could not reach the push notification service. Check outbound HTTPS access, then try again.'];
        }
        if (empty($result['server_id'])) {
            return [false, 'The push notification service did not return a server id.'];
        }

        $this->saveSetting('push_server_id', (string) $result['server_id']);

        // A key is only issued once. Save it the moment it arrives.
        if (!empty($result['serverkey'])) {
            $this->saveSetting('push_serverkey', Encryption::encrypt((string) $result['serverkey']));
            return [true, 'Registered with the push notification service.'];
        }

        // No key in the response: this install has registered before. Fine if
        // we still hold the key; unrecoverable here if we don't, since issuing
        // a replacement requires presenting the old one.
        if ($this->serverKey()) {
            return [true, 'Already registered — existing credentials confirmed.'];
        }

        return [false, 'This server has registered before but its credentials are missing, '
                     . 'and a replacement can only be issued using the old key. Contact support to reset this registration.'];
    }

    /**
     * Stop participating: forget the credentials so nothing can be sent, and
     * drop anything queued. The registration itself is left alone at the
     * service — re-enabling reuses the same identity rather than creating a
     * second one.
     */
    public function disable(): void
    {
        $this->saveSetting('push_enabled', '0');
        $this->db->query("DELETE FROM push_queue");
    }

    /** Hand a device registration to the relay. Best-effort. */
    public function registerDevice(int $userId, string $deviceId, string $token, string $platform): void
    {
        if (!$this->isConfigured() || $this->breakerOpen()) {
            return;
        }
        $this->request('POST', '/v1/devices', [
            'device_id' => $deviceId,
            'push_token' => $token,
            'platform' => $platform,
            'user_ref' => (string) $userId,
        ]);
    }

    /** Remove a device at the relay. Best-effort. */
    public function deleteDevice(string $deviceId): void
    {
        if (!$this->isConfigured() || $this->breakerOpen()) {
            return;
        }
        $this->request('DELETE', '/v1/devices/' . rawurlencode($deviceId));
    }

    // ── Queueing ────────────────────────────────────────────────────

    /**
     * Queue a notification for every device that wants this event.
     *
     * Called from request handlers and from inside the scheduler's locked
     * tick, so it does nothing but INSERT. Duplicate (device, event, job)
     * rows collapse on the unique key.
     */
    public function enqueue(string $event, array $userIds, ?int $jobId = null, ?int $clientId = null): int
    {
        if (!$this->isConfigured() || empty($userIds)) {
            return 0;
        }
        if (!array_key_exists($event, self::DEFAULT_EVENTS)) {
            return 0;  // not a push-able event
        }

        $placeholders = implode(',', array_fill(0, count($userIds), '?'));
        $devices = $this->db->fetchAll(
            "SELECT device_id, user_id FROM push_tokens
             WHERE user_id IN ({$placeholders})
               AND enabled = 1
               AND JSON_EXTRACT(events, ?) = CAST('true' AS JSON)",
            array_merge(array_map('intval', $userIds), ['$."' . $event . '"'])
        );

        $queued = 0;
        foreach ($devices as $d) {
            $this->db->query(
                "INSERT INTO push_queue (device_id, user_id, event, job_id, client_id)
                 VALUES (?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE attempts = attempts",
                [$d['device_id'], (int) $d['user_id'], $event, (int) ($jobId ?? 0), (int) ($clientId ?? 0)]
            );
            $queued++;
        }
        return $queued;
    }

    // ── Draining ────────────────────────────────────────────────────

    /**
     * Send what's queued, bounded by a wall-clock budget and a batch cap so a
     * backlog drains across several ticks instead of one long one.
     *
     * Returns a short summary for the scheduler log, or null when there was
     * nothing to do.
     */
    public function drain(): ?array
    {
        // Expire first: stale rows should disappear even while the breaker is
        // open, or a long outage leaves a pile of notifications to deliver
        // late all at once.
        // Constants interpolated rather than bound: MySQL will not accept a
        // placeholder for INTERVAL or LIMIT under native prepares.
        $expired = $this->db->query(
            "DELETE FROM push_queue
             WHERE created_at < DATE_SUB(NOW(), INTERVAL " . self::MAX_AGE_HOURS . " HOUR)"
        )->rowCount();

        if (!$this->isConfigured()) {
            return $expired ? ['sent' => 0, 'expired' => $expired, 'skipped' => 'not configured'] : null;
        }
        if ($this->breakerOpen()) {
            return $expired ? ['sent' => 0, 'expired' => $expired, 'skipped' => 'relay cooling down'] : null;
        }

        $rows = $this->db->fetchAll(
            "SELECT id, device_id, event, job_id, client_id FROM push_queue
             ORDER BY created_at ASC LIMIT " . self::DRAIN_BATCH
        );
        if (empty($rows)) {
            return $expired ? ['sent' => 0, 'expired' => $expired] : null;
        }

        // One send per (event, job, client) with all its devices, rather than
        // one request per device.
        $groups = [];
        foreach ($rows as $r) {
            $key = $r['event'] . '|' . $r['job_id'] . '|' . $r['client_id'];
            $groups[$key]['event'] = $r['event'];
            $groups[$key]['job_id'] = (int) $r['job_id'];
            $groups[$key]['client_id'] = (int) $r['client_id'];
            $groups[$key]['devices'][] = $r['device_id'];
            $groups[$key]['ids'][] = (int) $r['id'];
        }

        $started = microtime(true);
        $sent = 0;
        $dropped = 0;

        foreach ($groups as $g) {
            if (microtime(true) - $started > self::DRAIN_BUDGET_SECONDS) {
                break;  // rest goes out next tick
            }

            $payload = [
                'device_ids' => array_values(array_unique($g['devices'])),
                'event' => $g['event'],
            ];
            if ($g['job_id'] > 0)    $payload['job_id'] = $g['job_id'];
            if ($g['client_id'] > 0) $payload['client_id'] = $g['client_id'];

            // Name what happened and where. "A scheduled backup didn't run"
            // tells nobody which machine to look at, and an alert that cannot
            // be acted on without opening the app first is an alert people
            // learn to swipe away. The text is composed here, where the names
            // are, and carried to the transport as-is.
            $detail = $this->describe($g['event'], $g['job_id'], $g['client_id']);
            $payload['title'] = $detail['title'];
            $payload['body']  = $detail['body'];
            $payload['deep_link'] = $detail['deep_link'];

            $result = $this->request('POST', '/v1/send', $payload);

            if ($result === null) {
                // Leave the rows queued; the breaker decides whether the next
                // tick even tries. Count the attempt so a permanently bad row
                // can be spotted.
                $ids = implode(',', array_map('intval', $g['ids']));
                $this->db->query("UPDATE push_queue SET attempts = attempts + 1 WHERE id IN ({$ids})");
                break;  // relay is unhappy — stop this tick
            }

            $sent += (int) ($result['sent'] ?? 0);

            // A token the transport reports as dead is gone for good: drop the
            // device so we stop addressing it.
            foreach ($result['dropped'] ?? [] as $drop) {
                if (($drop['reason'] ?? '') === 'unregistered' && !empty($drop['device_id'])) {
                    $this->db->delete('push_tokens', 'device_id = ?', [$drop['device_id']]);
                    $dropped++;
                }
            }

            $ids = implode(',', array_map('intval', $g['ids']));
            $this->db->query("DELETE FROM push_queue WHERE id IN ({$ids})");
        }

        return ['sent' => $sent, 'expired' => $expired, 'unregistered' => $dropped];
    }
}
