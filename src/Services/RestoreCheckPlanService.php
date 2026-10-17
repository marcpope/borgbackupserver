<?php

namespace BBS\Services;

use BBS\Core\Database;

/**
 * Restore check plans: validation, queuing a run, the scheduling tick and
 * overdue alerts. Execution is RestoreCheckRunner's.
 */
class RestoreCheckPlanService
{
    public const MAX_PROBES = 100;
    public const MAX_PATH_LENGTH = 4096;
    private const GIB = 1073741824;

    private Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? Database::getInstance();
    }

    // ------------------------------------------------------------------
    // Validation
    // ------------------------------------------------------------------

    /**
     * One archive-relative path, as borg stores it: no leading slash, no
     * empty, "." or ".." segment, no control characters. A single leading
     * "/" (how the catalog shows paths) is removed; anything else that is
     * not literal is refused, never reinterpreted.
     */
    public static function normalizeArchivePath(string $raw): string
    {
        $path = $raw;
        if (str_starts_with($path, '/')) {
            $path = substr($path, 1);
        }
        $path = rtrim($path, '/');
        if ($path === '') {
            throw new \InvalidArgumentException('A path is empty. Use Full archive mode to test everything.');
        }
        if (strlen($path) > self::MAX_PATH_LENGTH) {
            throw new \InvalidArgumentException('A path is longer than ' . self::MAX_PATH_LENGTH . ' bytes.');
        }
        if (!mb_check_encoding($path, 'UTF-8')) {
            throw new \InvalidArgumentException('A path is not valid UTF-8.');
        }
        if (preg_match('/[\x00-\x1F\x7F]/', $path)) {
            throw new \InvalidArgumentException('A path contains control characters.');
        }
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new \InvalidArgumentException("'" . $raw . "' has an empty, '.' or '..' segment; paths must be plain archive paths.");
            }
        }
        return $path;
    }

    /**
     * Probes from parallel form arrays: paths[] and sha256[].
     *
     * @return array<int, array{path: string, sha256: ?string}>
     */
    public static function normalizeProbes(array $paths, array $hashes): array
    {
        $probes = [];
        foreach (array_values($paths) as $i => $raw) {
            $raw = (string) $raw;
            if (trim($raw) === '') {
                continue;
            }
            $path = self::normalizeArchivePath($raw);
            $hash = strtolower(trim((string) (array_values($hashes)[$i] ?? '')));
            if ($hash !== '' && !preg_match('/^[0-9a-f]{64}$/', $hash)) {
                throw new \InvalidArgumentException("The SHA-256 for '{$path}' must be 64 hexadecimal characters.");
            }
            if (isset($probes[$path])) {
                throw new \InvalidArgumentException("'{$path}' is listed twice.");
            }
            $probes[$path] = ['path' => $path, 'sha256' => $hash !== '' ? $hash : null];
        }
        if (count($probes) > self::MAX_PROBES) {
            throw new \InvalidArgumentException('At most ' . self::MAX_PROBES . ' paths can be checked; use Full archive mode for more.');
        }
        return array_values($probes);
    }

    /**
     * Turn a submitted form into plan columns. Ownership of the repository
     * and backup plan is checked against $agentId here; permissions are the
     * controller's.
     *
     * @return array{0: array, 1: string[]} [columns, errors]
     */
    public function validateInput(int $agentId, array $in): array
    {
        $errors = [];
        $data = [];

        $data['name'] = trim((string) ($in['name'] ?? ''));
        if ($data['name'] === '' || mb_strlen($data['name']) > 100) {
            $errors[] = 'Name is required (up to 100 characters).';
        }

        $repoId = (int) ($in['repository_id'] ?? 0);
        $repo = $repoId ? $this->db->fetchOne(
            "SELECT id, read_only, storage_type FROM repositories WHERE id = ? AND agent_id = ?",
            [$repoId, $agentId]
        ) : null;
        if (!$repo) {
            $errors[] = 'Choose one of this client\'s repositories.';
        } elseif (!empty($repo['read_only'])) {
            $errors[] = 'Read-only repository copies cannot be used for restore checks yet; choose the live repository.';
        } elseif (!in_array($repo['storage_type'] ?? 'local', ['local', 'remote_ssh'], true)) {
            $errors[] = 'This repository\'s storage type is not supported for restore checks.';
        }
        $data['repository_id'] = $repoId ?: null;

        $data['scope'] = ($in['scope'] ?? 'plan') === 'repository' ? 'repository' : 'plan';
        $data['backup_plan_id'] = null;
        if ($data['scope'] === 'plan') {
            $bpId = (int) ($in['backup_plan_id'] ?? 0);
            $bp = $bpId ? $this->db->fetchOne(
                "SELECT id, repository_id FROM backup_plans WHERE id = ? AND agent_id = ?",
                [$bpId, $agentId]
            ) : null;
            if (!$bp) {
                $errors[] = 'Choose the backup plan whose archives are tested, or test the whole repository.';
            } elseif ($repo && (int) $bp['repository_id'] !== $repoId) {
                $errors[] = 'That backup plan writes to a different repository.';
            } else {
                $data['backup_plan_id'] = $bpId;
            }
        }

        $data['mode'] = ($in['mode'] ?? 'paths') === 'full' ? 'full' : 'paths';
        $data['paths'] = null;
        if ($data['mode'] === 'paths') {
            try {
                $probes = self::normalizeProbes((array) ($in['paths'] ?? []), (array) ($in['sha256'] ?? []));
                if (!$probes) {
                    $errors[] = 'Selected paths mode needs at least one path. To test everything, choose Full archive explicitly.';
                }
                $data['paths'] = json_encode($probes, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            } catch (\InvalidArgumentException $e) {
                $errors[] = $e->getMessage();
            }
        }
        $browse = trim((string) ($in['browse_archive_name'] ?? ''));
        $data['browse_archive_name'] = $browse !== '' ? mb_substr($browse, 0, 255) : null;

        $data['allow_warning_archives'] = !empty($in['allow_warning_archives']) ? 1 : 0;
        $data['notify_success'] = !empty($in['notify_success']) ? 1 : 0;

        $data['frequency'] = (string) ($in['frequency'] ?? 'weekly');
        if (!in_array($data['frequency'], RestoreCheckSchedule::FREQUENCIES, true)) {
            $errors[] = 'Frequency must be daily, weekly or monthly.';
        }
        try {
            $data['times'] = implode(',', RestoreCheckSchedule::normalizeTimes((string) ($in['times'] ?? '')));
        } catch (\InvalidArgumentException $e) {
            $errors[] = $e->getMessage();
            $data['times'] = '03:00';
        }
        $data['day_of_week'] = SchedulerService::formatDaysOfWeek($in['day_of_week'] ?? '1') ?? '1';
        $data['day_of_month'] = '1';
        if ($data['frequency'] === 'monthly') {
            try {
                $data['day_of_month'] = implode(',', RestoreCheckSchedule::normalizeDaysOfMonth((string) ($in['day_of_month'] ?? '1')));
            } catch (\InvalidArgumentException $e) {
                $errors[] = $e->getMessage();
            }
        }
        $data['timezone'] = trim((string) ($in['timezone'] ?? ''));
        if (!RestoreCheckSchedule::isValidTimezone($data['timezone'])) {
            $errors[] = 'Choose a valid IANA timezone, such as Europe/Berlin.';
        }

        $ints = [
            'max_archive_age_hours' => [1, 8760, 'Maximum archive age'],
            'queue_deadline_minutes' => [10, 10080, 'Maximum queue wait'],
            'run_timeout_minutes' => [5, 2880, 'Maximum run time'],
            'max_files' => [1, 100000000, 'Maximum files'],
            'overdue_hours' => [1, 8760, 'Overdue after'],
        ];
        foreach ($ints as $key => [$min, $max, $label]) {
            $v = filter_var($in[$key] ?? null, FILTER_VALIDATE_INT);
            if ($v === false || $v < $min || $v > $max) {
                $errors[] = "{$label} must be a whole number from {$min} to {$max}.";
                $v = $min;
            }
            $data[$key] = $v;
        }

        // Budgets are entered in GiB. Full archive mode has no default: the
        // operator must size it for the archive in question.
        $budgetField = $data['mode'] === 'full' ? 'full_max_gib' : 'max_gib';
        $maxGib = filter_var($in[$budgetField] ?? null, FILTER_VALIDATE_FLOAT);
        if ($maxGib === false || $maxGib <= 0 || $maxGib > 1048576) {
            $errors[] = $data['mode'] === 'full'
                ? 'Full archive mode needs an explicit extraction budget in GiB, at least as large as the archive.'
                : 'Maximum extraction size must be a positive number of GiB.';
            $maxGib = 0;
        }
        $data['max_bytes'] = (int) round($maxGib * self::GIB);
        $minFree = filter_var($in['min_free_gib'] ?? null, FILTER_VALIDATE_FLOAT);
        if ($minFree === false || $minFree < 0 || $minFree > 1048576) {
            $errors[] = 'Free space to keep must be zero or a positive number of GiB.';
            $minFree = 5;
        }
        $data['min_free_bytes'] = (int) round($minFree * self::GIB);

        return [$data, $errors];
    }

    /** Fields whose change alters what a run means; bumping the revision on edit. */
    public const REVISION_FIELDS = [
        'repository_id', 'scope', 'backup_plan_id', 'mode', 'paths', 'allow_warning_archives',
        'max_archive_age_hours', 'run_timeout_minutes', 'max_bytes', 'max_files', 'min_free_bytes',
    ];

    /** Defaults for a new plan's form. */
    public static function defaults(string $timezone): array
    {
        return [
            'name' => '',
            'scope' => 'plan',
            'mode' => 'paths',
            'paths' => '[]',
            'frequency' => 'weekly',
            'times' => '03:00',
            'day_of_week' => '1',
            'day_of_month' => '1',
            'timezone' => $timezone,
            'max_archive_age_hours' => 48,
            'queue_deadline_minutes' => 1440,
            'run_timeout_minutes' => 120,
            'max_bytes' => 10 * self::GIB,
            'max_files' => 100000,
            'min_free_bytes' => 5 * self::GIB,
            'overdue_hours' => 192,
            'allow_warning_archives' => 0,
            'notify_success' => 0,
            'enabled' => 0,
        ];
    }

    // ------------------------------------------------------------------
    // Queuing a run
    // ------------------------------------------------------------------

    /** The settings a run is executed with, frozen when it is queued. */
    public static function snapshot(array $plan): array
    {
        return [
            'plan_id' => (int) $plan['id'],
            'name' => $plan['name'],
            'repository_id' => $plan['repository_id'] !== null ? (int) $plan['repository_id'] : null,
            'scope' => $plan['scope'],
            'backup_plan_id' => $plan['backup_plan_id'] !== null ? (int) $plan['backup_plan_id'] : null,
            'mode' => $plan['mode'],
            'paths' => $plan['mode'] === 'paths' ? (json_decode((string) $plan['paths'], true) ?: []) : [],
            'selection_policy' => 'latest_successful',
            'allow_warning_archives' => (bool) $plan['allow_warning_archives'],
            'max_archive_age_hours' => (int) $plan['max_archive_age_hours'],
            'queue_deadline_minutes' => (int) $plan['queue_deadline_minutes'],
            'run_timeout_minutes' => (int) $plan['run_timeout_minutes'],
            'max_bytes' => (int) $plan['max_bytes'],
            'max_files' => (int) $plan['max_files'],
            'min_free_bytes' => (int) $plan['min_free_bytes'],
            'schedule' => [
                'frequency' => $plan['frequency'],
                'times' => $plan['times'],
                'day_of_week' => $plan['day_of_week'],
                'day_of_month' => $plan['day_of_month'],
                'timezone' => $plan['timezone'],
            ],
            'execution_location' => 'bbs_server',
        ];
    }

    /**
     * Queue a run of a plan: a backup_jobs row for the queue, and a
     * restore_check_runs row for the evidence, in one transaction under a
     * lock on the plan row.
     *
     * Returns ['run_id' => int, 'created' => bool] or ['error' => code, 'message' => text].
     * A repeated manual submission (same idempotency key) or scheduled
     * occurrence returns the existing run with created=false.
     */
    public function enqueue(int $planId, string $trigger, ?int $userId, ?string $idempotencyKey = null, ?string $scheduledFor = null, int $missed = 0): array
    {
        $pdo = $this->db->getPdo();
        $pdo->beginTransaction();
        try {
            $plan = $this->db->fetchOne("SELECT * FROM restore_check_plans WHERE id = ? FOR UPDATE", [$planId]);
            if (!$plan) {
                $pdo->rollBack();
                return ['error' => 'plan_missing', 'message' => 'Restore check plan not found.'];
            }

            if ($idempotencyKey !== null) {
                $existing = $this->db->fetchOne("SELECT id FROM restore_check_runs WHERE idempotency_key = ?", [$idempotencyKey]);
                if ($existing) {
                    $pdo->rollBack();
                    return ['run_id' => (int) $existing['id'], 'created' => false];
                }
            }
            if ($scheduledFor !== null) {
                $existing = $this->db->fetchOne(
                    "SELECT id FROM restore_check_runs WHERE plan_id = ? AND scheduled_for = ?",
                    [$planId, $scheduledFor]
                );
                if ($existing) {
                    $pdo->rollBack();
                    return ['run_id' => (int) $existing['id'], 'created' => false];
                }
            }

            $active = $this->db->fetchOne(
                "SELECT id FROM restore_check_runs WHERE plan_id = ? AND result IS NULL ORDER BY id DESC LIMIT 1",
                [$planId]
            );
            if ($active) {
                $pdo->rollBack();
                return ['error' => 'already_active', 'message' => 'A run of this restore check is already queued or running.', 'run_id' => (int) $active['id']];
            }
            if (empty($plan['repository_id'])) {
                $pdo->rollBack();
                return ['error' => 'repository_missing', 'message' => 'The repository this check tested has been deleted.'];
            }

            $names = $this->db->fetchOne("
                SELECT a.name AS client_name, r.name AS repository_name, bp.name AS source_plan_name
                FROM agents a
                LEFT JOIN repositories r ON r.id = ?
                LEFT JOIN backup_plans bp ON bp.id = ?
                WHERE a.id = ?
            ", [$plan['repository_id'], $plan['backup_plan_id'], $plan['agent_id']]) ?? [];
            $requester = $userId ? $this->db->fetchOne("SELECT username FROM users WHERE id = ?", [$userId]) : null;

            $now = $this->db->now();
            $jobId = $this->db->insert('backup_jobs', [
                'agent_id' => $plan['agent_id'],
                'repository_id' => $plan['repository_id'],
                'task_type' => 'restore_check',
                'status' => 'queued',
                'status_message' => 'Restore check: ' . mb_substr($plan['name'], 0, 200),
                'queued_at' => $now,
            ]);

            $runId = $this->db->insert('restore_check_runs', [
                'run_token' => bin2hex(random_bytes(16)),
                'plan_id' => $planId,
                'backup_job_id' => $jobId,
                'agent_id' => $plan['agent_id'],
                'repository_id' => $plan['repository_id'],
                'trigger_type' => $trigger === 'scheduled' ? 'scheduled' : 'manual',
                'requested_by' => $userId,
                'requested_by_name' => $requester['username'] ?? null,
                'scheduled_for' => $scheduledFor,
                'idempotency_key' => $idempotencyKey,
                'missed_occurrences' => $missed,
                'config_revision' => (int) $plan['config_revision'],
                'config_snapshot' => json_encode(self::snapshot($plan), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'client_name' => $names['client_name'] ?? null,
                'repository_name' => $names['repository_name'] ?? null,
                'plan_name' => $plan['name'],
                'source_plan_name' => $names['source_plan_name'] ?? null,
                'phase' => 'queued',
                'queued_at' => $now,
                'bbs_version' => self::bbsVersion(),
            ]);

            $this->db->update('restore_check_plans', ['last_attempt_at' => $now, 'last_run_id' => $runId], 'id = ?', [$planId]);

            $this->db->insert('server_log', [
                'agent_id' => $plan['agent_id'],
                'backup_job_id' => $jobId,
                'level' => 'info',
                'message' => ($trigger === 'scheduled' ? 'Scheduled' : 'Manual') . " restore check \"{$plan['name']}\" queued (run #{$runId})",
            ]);

            $pdo->commit();
            return ['run_id' => $runId, 'created' => true, 'job_id' => $jobId];
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            // Two schedulers racing to the same occurrence: the unique key
            // turns the loser's insert into a duplicate, which is the
            // intended outcome, not an error.
            if ($e instanceof \PDOException && ($e->errorInfo[1] ?? null) === 1062) {
                return ['error' => 'duplicate', 'message' => 'This run was already queued.'];
            }
            throw $e;
        }
    }

    // ------------------------------------------------------------------
    // Scheduling tick (scheduler.php, once a minute)
    // ------------------------------------------------------------------

    /**
     * Queue due plans. At most one run per plan per tick, so a scheduler
     * that was down for a month starts one catch-up run and records how many
     * it missed, rather than a burst of them.
     *
     * @return string[] log lines
     */
    public function tick(?\DateTimeImmutable $now = null): array
    {
        $log = [];
        if ($this->setting('maintenance_mode') === '1') {
            return $log;
        }
        $now = $now ?? new \DateTimeImmutable($this->db->now(), new \DateTimeZone('UTC'));
        $nowStr = $now->format('Y-m-d H:i:s');

        // Repair: an enabled plan without a next time would never run again.
        foreach ($this->db->fetchAll("SELECT * FROM restore_check_plans WHERE enabled = 1 AND next_due_at IS NULL") as $p) {
            $next = RestoreCheckSchedule::nextOccurrence($p, $now);
            $this->db->update('restore_check_plans', ['next_due_at' => $next?->format('Y-m-d H:i:s')], 'id = ?', [$p['id']]);
        }

        $due = $this->db->fetchAll(
            "SELECT id FROM restore_check_plans WHERE enabled = 1 AND next_due_at <= ? ORDER BY next_due_at, id",
            [$nowStr]
        );
        foreach ($due as $row) {
            $line = $this->fireDuePlan((int) $row['id'], $now);
            if ($line !== null) {
                $log[] = $line;
            }
        }
        return $log;
    }

    private function fireDuePlan(int $planId, \DateTimeImmutable $now): ?string
    {
        $nowStr = $now->format('Y-m-d H:i:s');
        $pdo = $this->db->getPdo();
        $pdo->beginTransaction();
        try {
            // Re-read under the lock: another scheduler may have fired it.
            $plan = $this->db->fetchOne("SELECT * FROM restore_check_plans WHERE id = ? FOR UPDATE", [$planId]);
            if (!$plan || !(int) $plan['enabled'] || $plan['next_due_at'] === null || $plan['next_due_at'] > $nowStr) {
                $pdo->rollBack();
                return null;
            }
            $occurrence = $plan['next_due_at'];
            $occurrenceDt = new \DateTimeImmutable($occurrence, new \DateTimeZone('UTC'));
            $missed = RestoreCheckSchedule::countOccurrences($plan, $occurrenceDt, $now);
            $next = RestoreCheckSchedule::nextOccurrence($plan, $now);
            $this->db->update('restore_check_plans', ['next_due_at' => $next?->format('Y-m-d H:i:s')], 'id = ?', [$planId]);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        // The schedule has moved on; whatever happens below, this occurrence
        // is accounted for exactly once (the unique key guards the rest).
        if (!$this->ownerMayRun($plan)) {
            $this->disablePlan($plan, 'The user who enabled this restore check no longer has restore access to this client. Enable it again to adopt it.');
            $this->recordSkipped($plan, $occurrence, 'owner_unauthorized', 'The plan owner no longer has the Perform Restores permission for this client.');
            return "Restore check plan #{$planId} disabled: owner lost access";
        }

        $result = $this->enqueue($planId, 'scheduled', null, null, $occurrence, $missed);
        if (isset($result['error'])) {
            if ($result['error'] === 'already_active') {
                $this->recordSkipped($plan, $occurrence, 'previous_run_active', 'The previous run of this check had not finished when this one was due.');
                return "Restore check plan #{$planId}: occurrence {$occurrence} skipped, previous run still active";
            }
            if ($result['error'] !== 'duplicate') {
                $this->recordSkipped($plan, $occurrence, $result['error'], $result['message']);
            }
            return "Restore check plan #{$planId}: {$result['message']}";
        }
        return "Queued restore check plan #{$planId} run #{$result['run_id']}" . ($missed > 0 ? " ({$missed} occurrences missed)" : '');
    }

    /** A history row for an occurrence that did not run, so gaps are visible. */
    private function recordSkipped(array $plan, string $occurrence, string $reason, string $detail): void
    {
        try {
            $now = $this->db->now();
            $this->db->insert('restore_check_runs', [
                'run_token' => bin2hex(random_bytes(16)),
                'plan_id' => $plan['id'],
                'agent_id' => $plan['agent_id'],
                'repository_id' => $plan['repository_id'],
                'trigger_type' => 'scheduled',
                'scheduled_for' => $occurrence,
                'config_revision' => (int) $plan['config_revision'],
                'config_snapshot' => json_encode(self::snapshot($plan), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'plan_name' => $plan['name'],
                'phase' => 'finished',
                'result' => 'skipped',
                'reason_code' => $reason,
                'reason_detail' => mb_substr($detail, 0, 500),
                'queued_at' => $now,
                'finished_at' => $now,
                'bbs_version' => self::bbsVersion(),
            ]);
        } catch (\PDOException $e) {
            if (($e->errorInfo[1] ?? null) !== 1062) {
                throw $e;
            }
        }
    }

    /** May the plan's owner still restore from this client? */
    public function ownerMayRun(array $plan): bool
    {
        if (empty($plan['owner_user_id'])) {
            return false;
        }
        $user = $this->db->fetchOne("SELECT id FROM users WHERE id = ?", [$plan['owner_user_id']]);
        return $user !== null
            && (new PermissionService())->hasPermission((int) $plan['owner_user_id'], PermissionService::RESTORE, (int) $plan['agent_id']);
    }

    public function disablePlan(array $plan, string $reason): void
    {
        $this->db->update('restore_check_plans', [
            'enabled' => 0,
            'next_due_at' => null,
            'disabled_reason' => mb_substr($reason, 0, 255),
        ], 'id = ?', [$plan['id']]);
        (new NotificationService())->notify(
            'restore_check_failed',
            (int) $plan['agent_id'],
            (int) $plan['id'],
            "Restore check \"{$plan['name']}\" was disabled: {$reason}",
            'critical'
        );
    }

    /** Turn a plan on, making $userId the user its scheduled runs act for. */
    public function enable(array $plan, int $userId): ?string
    {
        $now = new \DateTimeImmutable($this->db->now(), new \DateTimeZone('UTC'));
        $next = RestoreCheckSchedule::nextOccurrence($plan, $now);
        if ($next === null) {
            return null;
        }
        $this->db->update('restore_check_plans', [
            'enabled' => 1,
            'enabled_at' => $now->format('Y-m-d H:i:s'),
            'next_due_at' => $next->format('Y-m-d H:i:s'),
            'owner_user_id' => $userId,
            'disabled_reason' => null,
        ], 'id = ?', [$plan['id']]);
        return $next->format('Y-m-d H:i:s');
    }

    // ------------------------------------------------------------------
    // Overdue
    // ------------------------------------------------------------------

    /**
     * Overdue: enabled, and no strict pass within overdue_hours of the last
     * strict pass (or of being enabled, for a plan that never passed). A
     * plan whose runs keep failing is overdue too: attempts do not count.
     */
    public static function isOverdue(array $plan, \DateTimeImmutable $now): bool
    {
        if (!(int) ($plan['enabled'] ?? 0)) {
            return false;
        }
        // The later of the last strict pass and the moment it was enabled:
        // a plan switched back on gets a full window before it is overdue.
        $since = max((string) ($plan['last_strict_success_at'] ?? ''), (string) ($plan['enabled_at'] ?? ''));
        if ($since === '') {
            return false;
        }
        $deadline = (new \DateTimeImmutable($since, new \DateTimeZone('UTC')))
            ->modify('+' . (int) $plan['overdue_hours'] . ' hours');
        return $now > $deadline;
    }

    /** Raise (deduplicated) overdue alerts; resolution happens on a strict pass. */
    public function checkOverdue(?\DateTimeImmutable $now = null): int
    {
        $now = $now ?? new \DateTimeImmutable($this->db->now(), new \DateTimeZone('UTC'));
        $n = 0;
        $notifier = new NotificationService();
        foreach ($this->db->fetchAll("SELECT * FROM restore_check_plans WHERE enabled = 1") as $plan) {
            if (!self::isOverdue($plan, $now)) {
                continue;
            }
            $what = $plan['last_strict_success_at']
                ? 'has not passed since ' . $plan['last_strict_success_at'] . ' UTC'
                : 'has never passed since it was enabled';
            $notifier->notify(
                'restore_check_overdue',
                (int) $plan['agent_id'],
                (int) $plan['id'],
                "Restore check \"{$plan['name']}\" is overdue: it {$what} (limit {$plan['overdue_hours']} h).",
                'warning'
            );
            $n++;
        }
        return $n;
    }

    // ------------------------------------------------------------------
    // Presentation
    // ------------------------------------------------------------------

    /**
     * Plans of a client with what the list needs: latest run, current
     * status, and the latest backup next to the latest verified restore so
     * the two are never confused.
     */
    public function summaryForAgent(int $agentId): array
    {
        $now = new \DateTimeImmutable($this->db->now(), new \DateTimeZone('UTC'));
        $plans = $this->db->fetchAll("
            SELECT p.*, r.name AS repository_name, bp.name AS source_plan_name, u.username AS owner_name
            FROM restore_check_plans p
            LEFT JOIN repositories r ON r.id = p.repository_id
            LEFT JOIN backup_plans bp ON bp.id = p.backup_plan_id
            LEFT JOIN users u ON u.id = p.owner_user_id
            WHERE p.agent_id = ?
            ORDER BY p.name, p.id
        ", [$agentId]);
        foreach ($plans as &$p) {
            $p['latest_run'] = $this->db->fetchOne(
                "SELECT * FROM restore_check_runs WHERE plan_id = ? AND (result IS NULL OR result <> 'skipped') ORDER BY id DESC LIMIT 1",
                [$p['id']]
            );
            $p['status'] = self::planStatus($p, $p['latest_run'], $now);
            $p['latest_backup_at'] = $this->db->fetchOne("
                SELECT MAX(a.created_at) AS t FROM archives a
                JOIN backup_jobs bj ON bj.id = a.backup_job_id
                WHERE a.repository_id = ? AND bj.status = 'completed'" . ($p['scope'] === 'plan' ? ' AND bj.backup_plan_id = ?' : ''),
                $p['scope'] === 'plan' ? [(int) $p['repository_id'], (int) $p['backup_plan_id']] : [(int) $p['repository_id']]
            )['t'] ?? null;
        }
        unset($p);
        return $plans;
    }

    /**
     * One status for a plan: in-flight work first, then overdue (which
     * outranks an old pass), then the latest result, else never run.
     */
    public static function planStatus(array $plan, ?array $latestRun, \DateTimeImmutable $now): string
    {
        if ($latestRun && $latestRun['result'] === null) {
            return $latestRun['phase'] === 'queued' ? 'queued' : 'running';
        }
        if (self::isOverdue($plan, $now)) {
            return 'overdue';
        }
        return $latestRun['result'] ?? 'never';
    }

    // ------------------------------------------------------------------

    private function setting(string $key): ?string
    {
        return $this->db->fetchOne("SELECT `value` FROM settings WHERE `key` = ?", [$key])['value'] ?? null;
    }

    public static function bbsVersion(): string
    {
        $v = @file_get_contents(dirname(__DIR__, 2) . '/VERSION');
        return $v !== false ? trim($v) : 'unknown';
    }
}
