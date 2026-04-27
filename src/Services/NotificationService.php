<?php

namespace BBS\Services;

use BBS\Core\Database;

class NotificationService
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    // Success/info events should always send (not deduplicate) since users want
    // to know every time a backup completes. Failure events deduplicate to avoid spam.
    private const ALWAYS_SEND_EVENTS = [
        'backup_completed',
        'restore_completed',
        'agent_online',
        'repo_compact_done',
        's3_sync_done',
    ];

    public function notify(string $type, ?int $agentId, ?int $referenceId, string $message, string $severity = 'warning', ?int $userId = null): void
    {
        $alwaysSend = in_array($type, self::ALWAYS_SEND_EVENTS, true);

        // #153: success events (backup_completed, restore_completed, etc.) can
        // flood the notification bell with "routine" entries the user then
        // has to mark-as-read. Off by default — the user can opt back in via
        // Settings → Notifications.
        if ($alwaysSend) {
            $pref = $this->db->fetchOne("SELECT `value` FROM settings WHERE `key` = 'inapp_notify_success_events'");
            $showSuccess = ($pref['value'] ?? '0') === '1';
            if (!$showSuccess) {
                // Still fire email/Apprise (those have their own per-event
                // toggles), but skip the in-app notification center record.
                $this->sendEmailIfEnabled($type, $message);
                $this->sendAppriseIfEnabled($type, $message, $agentId);
                return;
            }
        }

        // For success events, resolve any previous unresolved notification first
        // so a fresh one is always created and notifications always fire
        if ($alwaysSend) {
            $this->resolve($type, $agentId, $referenceId, $userId);
        }

        // Look for existing unresolved notification with same grouping key.
        // user_id is part of the dedup key so per-user alerts (e.g. storage_low
        // with per-user thresholds) don't stomp on each other.
        $params = [$type];
        $agentClause = $agentId !== null ? 'agent_id = ?' : 'agent_id IS NULL';
        if ($agentId !== null) $params[] = $agentId;
        $refClause = $referenceId !== null ? 'reference_id = ?' : 'reference_id IS NULL';
        if ($referenceId !== null) $params[] = $referenceId;
        $userClause = $userId !== null ? 'user_id = ?' : 'user_id IS NULL';
        if ($userId !== null) $params[] = $userId;

        $existing = $this->db->fetchOne(
            "SELECT id FROM notifications WHERE type = ? AND {$agentClause} AND {$refClause} AND {$userClause} AND resolved_at IS NULL",
            $params
        );

        $isNew = false;

        if ($existing) {
            $this->db->query(
                "UPDATE notifications SET occurrence_count = occurrence_count + 1, last_occurred_at = NOW(), message = ?, severity = ?, read_at = NULL WHERE id = ?",
                [$message, $severity, $existing['id']]
            );
        } else {
            $this->db->insert('notifications', [
                'type' => $type,
                'agent_id' => $agentId,
                'reference_id' => $referenceId,
                'user_id' => $userId,
                'severity' => $severity,
                'message' => $message,
            ]);
            $isNew = true;
        }

        // Send push/email notifications on first occurrence (failure events deduplicate,
        // success events always create a new record so they always send)
        if ($isNew) {
            $this->sendEmailIfEnabled($type, $message, $userId);
            $this->sendAppriseIfEnabled($type, $message, $agentId);
        }
    }

    /**
     * Get friendly labels for all notification event types.
     */
    public static function getEventLabels(): array
    {
        return [
            // Backups
            'backup_completed' => 'Backup Completed',
            'backup_warning' => 'Backup Completed with Warnings',
            'backup_failed' => 'Backup Failed',
            // Restores
            'restore_completed' => 'Restore Completed',
            'restore_failed' => 'Restore Failed',
            // Clients
            'agent_offline' => 'Client Offline',
            'agent_online' => 'Client Online',
            // Repositories
            'repo_check_failed' => 'Check Failed',
            'repo_compact_done' => 'Compact Done',
            // Storage
            'storage_low' => 'Storage Low',
            's3_sync_failed' => 'S3 Sync Failed',
            's3_sync_done' => 'S3 Sync Done',
            // Schedules
            'missed_schedule' => 'Missed Schedule',
        ];
    }

    private function sendEmailIfEnabled(string $type, string $message, ?int $userId = null): void
    {
        $settingKey = 'email_on_' . $type;
        $setting = $this->db->fetchOne("SELECT `value` FROM settings WHERE `key` = ?", [$settingKey]);

        if (($setting['value'] ?? '0') !== '1') {
            return;
        }

        try {
            $mailer = new Mailer();
            if (!$mailer->isEnabled()) return;

            $labels = self::getEventLabels();
            $subject = '[BBS] ' . ($labels[$type] ?? ucfirst($type));

            // For user-scoped events (storage_low with per-user thresholds),
            // send to just that user. Otherwise fall back to every admin.
            if ($userId !== null) {
                $user = $this->db->fetchOne("SELECT email, timezone FROM users WHERE id = ? AND email != ''", [$userId]);
                if ($user) $mailer->send($user['email'], $subject, $this->buildEmailBody($message, $user['timezone'] ?? 'UTC'));
                return;
            }

            $admins = $this->db->fetchAll("SELECT email, timezone FROM users WHERE role = 'admin' AND email != ''");
            foreach ($admins as $admin) {
                $mailer->send($admin['email'], $subject, $this->buildEmailBody($message, $admin['timezone'] ?? 'UTC'));
            }
        } catch (\Exception $e) {
            // Don't let email failures break notification flow
        }
    }

    private function buildEmailBody(string $message, string $tz): string
    {
        try {
            $dt = new \DateTime('now', new \DateTimeZone('UTC'));
            $dt->setTimezone(new \DateTimeZone($tz));
        } catch (\Exception $e) {
            $dt = new \DateTime('now', new \DateTimeZone('UTC'));
        }
        return $message . "\n\n"
             . "Time: " . $dt->format('Y-m-d H:i:s T') . "\n"
             . "-- Borg Backup Server";
    }

    private function sendAppriseIfEnabled(string $type, string $message, ?int $agentId = null): void
    {
        try {
            $apprise = new AppriseService();
            $labels = self::getEventLabels();
            $title = '[BBS] ' . ($labels[$type] ?? ucfirst($type));

            // Use the new per-service event filtering
            $apprise->sendForEvent($type, $title, $message, $agentId);
        } catch (\Exception $e) {
            // Don't let Apprise failures break notification flow
        }
    }

    public function resolve(string $type, ?int $agentId, ?int $referenceId, ?int $userId = null): void
    {
        $params = [$type];
        $agentClause = $agentId !== null ? 'agent_id = ?' : 'agent_id IS NULL';
        if ($agentId !== null) $params[] = $agentId;
        $refClause = $referenceId !== null ? 'reference_id = ?' : 'reference_id IS NULL';
        if ($referenceId !== null) $params[] = $referenceId;
        $userClause = $userId !== null ? 'user_id = ?' : 'user_id IS NULL';
        if ($userId !== null) $params[] = $userId;

        $this->db->query(
            "UPDATE notifications SET resolved_at = NOW() WHERE type = ? AND {$agentClause} AND {$refClause} AND {$userClause} AND resolved_at IS NULL",
            $params
        );
    }

    public function markRead(int $id, ?int $userId = null): void
    {
        // Scope by accessible agents AND user_id so a user can't mark other
        // users' (or admin-only) notifications read. Global notifications
        // (agent_id + user_id both NULL) are visible to everyone. User-scoped
        // notifications are only visible to that specific user.
        [$agentWhere, $agentParams] = $this->getAgentFilter($userId);
        [$userWhere, $userParams]   = $this->getUserFilter($userId);
        $params = array_merge([date('Y-m-d H:i:s'), $id], $agentParams, $userParams);
        $this->db->query(
            "UPDATE notifications n LEFT JOIN agents a ON a.id = n.agent_id
             SET n.read_at = ?
             WHERE n.id = ? AND (n.agent_id IS NULL OR {$agentWhere}) AND {$userWhere}",
            $params
        );
    }

    public function markAllRead(?int $userId = null): void
    {
        [$agentWhere, $agentParams] = $this->getAgentFilter($userId);
        [$userWhere, $userParams]   = $this->getUserFilter($userId);
        $this->db->query(
            "UPDATE notifications n LEFT JOIN agents a ON a.id = n.agent_id SET n.read_at = NOW() WHERE n.read_at IS NULL AND (n.agent_id IS NULL OR {$agentWhere}) AND {$userWhere}",
            array_merge($agentParams, $userParams)
        );
    }

    public function unreadCount(?int $userId = null): int
    {
        [$agentWhere, $agentParams] = $this->getAgentFilter($userId);
        [$userWhere, $userParams]   = $this->getUserFilter($userId);
        $row = $this->db->fetchOne(
            "SELECT COUNT(*) as cnt FROM notifications n LEFT JOIN agents a ON a.id = n.agent_id WHERE read_at IS NULL AND resolved_at IS NULL AND (n.agent_id IS NULL OR {$agentWhere}) AND {$userWhere}",
            array_merge($agentParams, $userParams)
        );
        return (int) $row['cnt'];
    }

    public function getAll(int $limit = 50, int $offset = 0, ?int $userId = null): array
    {
        [$agentWhere, $agentParams] = $this->getAgentFilter($userId);
        [$userWhere, $userParams]   = $this->getUserFilter($userId);
        $params = array_merge($agentParams, $userParams, [$limit, $offset]);
        return $this->db->fetchAll("
            SELECT n.*, a.name as agent_name
            FROM notifications n
            LEFT JOIN agents a ON a.id = n.agent_id
            WHERE (n.agent_id IS NULL OR {$agentWhere}) AND {$userWhere}
            ORDER BY
                CASE WHEN n.resolved_at IS NULL THEN 0 ELSE 1 END,
                n.last_occurred_at DESC
            LIMIT ? OFFSET ?
        ", $params);
    }

    /**
     * User-scoped notification filter. A user sees:
     *   - global notifications (user_id IS NULL)
     *   - their own user-scoped notifications (user_id = theirs)
     * userId=null (e.g. scheduler/internal callers) means "no filter" — show
     * every notification.
     */
    private function getUserFilter(?int $userId): array
    {
        if ($userId === null) {
            return ['1=1', []];
        }
        return ['(n.user_id IS NULL OR n.user_id = ?)', [$userId]];
    }

    private function getAgentFilter(?int $userId): array
    {
        if ($userId === null) {
            return ['1=1', []];
        }
        $permService = new PermissionService();
        return $permService->getAgentWhereClause($userId, 'a');
    }

    public function cleanup(): void
    {
        $setting = $this->db->fetchOne("SELECT `value` FROM settings WHERE `key` = 'notification_retention_days'");
        $days = (int) ($setting['value'] ?? 30);

        $this->db->query(
            "DELETE FROM notifications WHERE resolved_at IS NOT NULL AND resolved_at < DATE_SUB(NOW(), INTERVAL ? DAY)",
            [$days]
        );
    }
}
