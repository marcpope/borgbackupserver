<?php

namespace BBS\Services;

use BBS\Core\Database;

/**
 * Client profiles — what a kind of machine should back up, how often, how long
 * it is kept, and how patient to be when it drops out mid-backup.
 *
 * A profile is a starting point, not a binding. Editing one leaves existing
 * clients exactly as they are: someone who tuned a plan for a particular
 * server should not lose that because a colleague adjusted the profile it was
 * created from. Pushing changes down is a separate, explicit action.
 *
 * The exception is the failure settings, which are read live. They describe how
 * BBS should treat a client that is misbehaving right now, and pinning a copy
 * of them onto every plan at creation time would make them impossible to
 * adjust across a fleet.
 */
class ClientProfileService
{
    /** Settings a profile may override, and the global key each falls back to. */
    private const FAILURE_SETTINGS = [
        'auto_retry_max_attempts'    => ['setting' => 'auto_retry_max_attempts',    'default' => 3],
        'job_offline_grace_minutes'  => ['setting' => 'job_offline_grace_minutes',  'default' => 5],
        'auto_retry_backoff_minutes' => ['setting' => 'auto_retry_backoff_minutes', 'default' => 5],
        // How long a client of this kind may go without a successful backup
        // before it counts as overdue (#409). A laptop that is off for the
        // weekend is not a fault; a database server silent for a day is.
        'backup_overdue_hours'       => ['setting' => 'backup_overdue_hours',       'default' => 48],
    ];

    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    public function all(): array
    {
        return $this->db->fetchAll("
            SELECT cp.*, t.name AS template_name,
                   (SELECT COUNT(*) FROM agents a WHERE a.client_profile_id = cp.id) AS client_count
            FROM client_profiles cp
            LEFT JOIN backup_templates t ON t.id = cp.template_id
            ORDER BY cp.is_default DESC, cp.name ASC
        ");
    }

    public function find(int $id): ?array
    {
        return $this->db->fetchOne("SELECT * FROM client_profiles WHERE id = ?", [$id]) ?: null;
    }

    public function defaultProfile(): ?array
    {
        return $this->db->fetchOne("SELECT * FROM client_profiles WHERE is_default = 1 LIMIT 1") ?: null;
    }

    public function defaultProfileId(): ?int
    {
        $row = $this->defaultProfile();
        return $row ? (int) $row['id'] : null;
    }

    /** The profile a client belongs to, falling back to the default. */
    public function forAgent(int $agentId): ?array
    {
        $row = $this->db->fetchOne("
            SELECT cp.* FROM client_profiles cp
            JOIN agents a ON a.client_profile_id = cp.id
            WHERE a.id = ?
        ", [$agentId]);
        return $row ?: $this->defaultProfile();
    }

    /**
     * Resolve the failure settings that apply to a client: the profile's value
     * where it sets one, the server-wide setting otherwise.
     *
     * @return array{auto_retry_max_attempts:int, job_offline_grace_minutes:int, auto_retry_backoff_minutes:int}
     */
    public function failureSettingsForAgent(int $agentId): array
    {
        $profile = $this->forAgent($agentId);
        $out = [];
        foreach (self::FAILURE_SETTINGS as $field => $meta) {
            $out[$field] = $this->resolveFailureSetting($field, $profile[$field] ?? null);
        }
        return $out;
    }

    /**
     * The timezone a profile's run hours are stated in.
     *
     * Null on the profile means the server's own zone rather than whoever
     * happens to be looking — a schedule that moved when a different admin
     * applied a profile would be a worse bug than the one this fixes.
     */
    public function timezoneFor(array $profile): string
    {
        if (!empty($profile['timezone'])) {
            return $profile['timezone'];
        }
        $row = $this->db->fetchOne("SELECT `value` FROM settings WHERE `key` = 'server_timezone'");
        return ($row['value'] ?? '') ?: date_default_timezone_get();
    }

    /** Hours a client may go without a successful backup before it is overdue. */
    public function overdueHoursForAgent(int $agentId): int
    {
        return $this->failureSettingsForAgent($agentId)['backup_overdue_hours'];
    }

    /** Global value for one failure setting, used when a profile doesn't override it. */
    public function globalFailureSetting(string $field): int
    {
        return $this->resolveFailureSetting($field, null);
    }

    private function resolveFailureSetting(string $field, $profileValue): int
    {
        $meta = self::FAILURE_SETTINGS[$field] ?? null;
        if (!$meta) {
            return 0;
        }
        if ($profileValue !== null && $profileValue !== '') {
            return max(0, (int) $profileValue);
        }
        $row = $this->db->fetchOne("SELECT `value` FROM settings WHERE `key` = ?", [$meta['setting']]);
        $val = $row['value'] ?? null;
        return ($val === null || $val === '') ? $meta['default'] : max(0, (int) $val);
    }

    /**
     * The plan and schedule defaults a new backup plan should start from.
     * Directory/exclude/option defaults come from the profile's template.
     */
    public function planDefaults(int $agentId): array
    {
        $profile = $this->forAgent($agentId);
        if (!$profile) {
            return [];
        }

        $template = null;
        if (!empty($profile['template_id'])) {
            $template = $this->db->fetchOne("SELECT * FROM backup_templates WHERE id = ?", [$profile['template_id']]);
        }

        return [
            'profile_id'   => (int) $profile['id'],
            'profile_name' => $profile['name'],
            'directories'  => $template['directories'] ?? '',
            'excludes'     => $template['excludes'] ?? '',
            'advanced_options' => $template['advanced_options'] ?? '',
            'template_name' => $template['name'] ?? null,
            'frequency'    => $profile['frequency'],
            'times'        => $profile['times'],
            'timezone'     => $this->timezoneFor($profile),
            'day_of_week'  => $profile['day_of_week'],
            'day_of_month' => $profile['day_of_month'],
            'prune_minutes' => (int) $profile['prune_minutes'],
            'prune_hours'   => (int) $profile['prune_hours'],
            'prune_days'    => (int) $profile['prune_days'],
            'prune_weeks'   => (int) $profile['prune_weeks'],
            'prune_months'  => (int) $profile['prune_months'],
            'prune_years'   => (int) $profile['prune_years'],
        ];
    }

    /**
     * Overwrite every plan and schedule belonging to clients in this profile.
     *
     * Destructive on purpose, and only ever reached from a confirmed action:
     * directories, excludes, options and retention are replaced with the
     * profile's, and schedules are re-pointed at the profile's frequency. What
     * it does not touch is which repository a plan writes to, or which plugins
     * it runs — those are per-client facts a profile has no opinion about.
     *
     * @return array{plans:int, schedules:int, clients:int}
     */
    public function applyToClients(int $profileId): array
    {
        $profile = $this->find($profileId);
        if (!$profile) {
            return ['plans' => 0, 'schedules' => 0, 'clients' => 0];
        }

        $template = null;
        if (!empty($profile['template_id'])) {
            $template = $this->db->fetchOne("SELECT * FROM backup_templates WHERE id = ?", [$profile['template_id']]);
        }

        $agents = $this->db->fetchAll("SELECT id FROM agents WHERE client_profile_id = ?", [$profileId]);
        $agentIds = array_column($agents, 'id');
        if (empty($agentIds)) {
            return ['plans' => 0, 'schedules' => 0, 'clients' => 0];
        }

        $in = implode(',', array_map('intval', $agentIds));
        $plans = $this->db->fetchAll("SELECT id FROM backup_plans WHERE agent_id IN ({$in})");

        $planUpdate = [
            'prune_minutes' => (int) $profile['prune_minutes'],
            'prune_hours'   => (int) $profile['prune_hours'],
            'prune_days'    => (int) $profile['prune_days'],
            'prune_weeks'   => (int) $profile['prune_weeks'],
            'prune_months'  => (int) $profile['prune_months'],
            'prune_years'   => (int) $profile['prune_years'],
        ];
        // Only a profile with a template has anything to say about what gets
        // backed up. Without one, retention and schedule are applied and the
        // directories are left alone rather than blanked.
        if ($template) {
            $planUpdate['directories'] = $template['directories'];
            $planUpdate['excludes'] = $template['excludes'];
            $planUpdate['advanced_options'] = $template['advanced_options'];
        }

        $scheduleCount = 0;
        $scheduler = new SchedulerService();
        foreach ($plans as $p) {
            $this->db->update('backup_plans', $planUpdate, 'id = ?', [$p['id']]);

            $schedules = $this->db->fetchAll("SELECT * FROM schedules WHERE backup_plan_id = ?", [$p['id']]);
            foreach ($schedules as $sch) {
                $newSchedule = [
                    'frequency'    => $profile['frequency'],
                    'times'        => $profile['times'],
                    // The zone goes with the time. Without this the same
                    // "01:00" lands in schedules that each read it in their
                    // own zone, and the profile appears to run at different
                    // hours on different clients (#411).
                    'timezone'     => $this->timezoneFor($profile),
                    'day_of_week'  => $profile['day_of_week'],
                    'day_of_month' => $profile['day_of_month'],
                ];
                // Recompute rather than clear. Clearing looked safe — a stale
                // time can't fire under the new frequency — but the scheduler
                // selects on `next_run <= now` and skips NULL, and nothing
                // refills it, so applying a profile silently stopped every
                // schedule it touched: still shown as active, never queued,
                // and only a manual run worked (#420).
                $newSchedule['next_run'] = $scheduler->calculateNextRun(
                    array_merge($sch, $newSchedule)
                );
                $this->db->update('schedules', $newSchedule, 'id = ?', [$sch['id']]);
                $scheduleCount++;
            }
        }

        return [
            'plans' => count($plans),
            'schedules' => $scheduleCount,
            'clients' => count($agentIds),
        ];
    }

    /** What applyToClients() would touch, for the confirmation dialog. */
    public function applyImpact(int $profileId): array
    {
        $agents = $this->db->fetchAll("SELECT id FROM agents WHERE client_profile_id = ?", [$profileId]);
        if (empty($agents)) {
            return ['clients' => 0, 'plans' => 0, 'schedules' => 0];
        }
        $in = implode(',', array_map(fn($a) => (int) $a['id'], $agents));
        $plans = (int) ($this->db->fetchOne("SELECT COUNT(*) c FROM backup_plans WHERE agent_id IN ({$in})")['c'] ?? 0);
        $schedules = (int) ($this->db->fetchOne("
            SELECT COUNT(*) c FROM schedules s
            JOIN backup_plans bp ON bp.id = s.backup_plan_id
            WHERE bp.agent_id IN ({$in})
        ")['c'] ?? 0);

        return ['clients' => count($agents), 'plans' => $plans, 'schedules' => $schedules];
    }
}
