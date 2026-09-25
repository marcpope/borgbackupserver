<?php

namespace BBS\Services;

use BBS\Core\Database;

/**
 * The monitoring snapshot in Prometheus' text exposition format.
 *
 * Everything here already existed as JSON on /api/v1/metrics; what a
 * monitoring system could not do was read it. The format is the whole point,
 * so two rules shape the rendering:
 *
 *  - a value that is not known is *omitted*, never zeroed. A plan that has
 *    never succeeded must not look like one that succeeded in 1970, and
 *    `absent()` is how an alert says "this has never run".
 *  - dates are values in epoch seconds, not sample timestamps. Prometheus
 *    dates the sample at scrape time; `now - bbs_plan_last_success_...` is
 *    the expression every backup alert is built on.
 *
 * Client and plan names are operator input, so every label value is escaped.
 */
class PrometheusMetrics
{
    public const CONTENT_TYPE = 'text/plain; version=0.0.4; charset=utf-8';

    /** Where a rendered snapshot is kept between scrapes. */
    private const CACHE_DIR = '/var/bbs/cache/metrics';

    private Database $db;
    private array $lines = [];

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? Database::getInstance();
    }

    /**
     * The body to serve, rendering it only when the cached one has expired.
     *
     * The queries behind a snapshot read the whole job history, so on a large
     * fleet the cache is what makes a one-minute scrape interval affordable.
     *
     * @return array{body:string,age:int,cached:bool}
     */
    public function respond(?int $ttl = null): array
    {
        $ttl = $ttl ?? (int) $this->setting('metrics_cache_seconds', '30');
        if ($ttl < 0) {
            $ttl = 0;
        }

        $file = $this->cacheFile();
        if ($ttl > 0 && $file !== null && is_file($file)) {
            $age = time() - (int) @filemtime($file);
            if ($age >= 0 && $age < $ttl) {
                $body = @file_get_contents($file);
                if ($body !== false && $body !== '') {
                    return ['body' => $this->withAge($body, $age), 'age' => $age, 'cached' => true];
                }
            }
        }

        // One renderer at a time. A scrape that arrives while another is
        // rendering serves the previous snapshot rather than starting a
        // second pass over the same tables — which is exactly the pile-up
        // this cache exists to prevent.
        $lock = $file !== null ? @fopen($file . '.lock', 'c') : false;
        $holdsLock = $lock !== false && @flock($lock, LOCK_EX | LOCK_NB);
        if (!$holdsLock && $file !== null && is_file($file)) {
            $body = @file_get_contents($file);
            if ($body !== false && $body !== '') {
                $age = time() - (int) @filemtime($file);
                if ($lock !== false) {
                    @fclose($lock);
                }
                return ['body' => $this->withAge($body, $age), 'age' => $age, 'cached' => true];
            }
        }

        try {
            $body = $this->render();
        } catch (\Throwable $e) {
            // A scrape that fails must say so plainly. Serving the previous
            // snapshot would report stale figures as current, which is worse
            // than reporting nothing: bbs_up = 0 is unambiguous, and an alert
            // on it fires whether BBS is down or merely unable to answer.
            $body = "# HELP bbs_up Whether this snapshot could be produced.\n"
                . "# TYPE bbs_up gauge\n"
                . "bbs_up 0\n";
        }

        if ($ttl > 0 && $file !== null && $holdsLock) {
            $tmp = $file . '.' . getmypid() . '.tmp';
            if (@file_put_contents($tmp, $body) !== false) {
                @rename($tmp, $file);   // atomic: a reader never sees half a document
            }
        }
        if ($lock !== false) {
            if ($holdsLock) {
                @flock($lock, LOCK_UN);
            }
            @fclose($lock);
        }

        return ['body' => $this->withAge($body, 0), 'age' => 0, 'cached' => false];
    }

    /** Build the whole document. */
    public function render(): string
    {
        $this->lines = [];

        $this->renderServer();
        $this->renderClients();
        $this->renderPlans();
        $this->renderQueue();
        $this->renderRepositories();
        $this->renderStorage();
        $this->renderSelfBackup();

        return implode("\n", $this->lines) . "\n";
    }

    // ── sections ────────────────────────────────────────────────────

    private function renderServer(): void
    {
        $health = (new HealthService())->check();

        $this->help('bbs_up', 'Whether this snapshot could be produced.', 'gauge');
        $this->sample('bbs_up', [], 1);

        $version = trim((string) (@file_get_contents(dirname(__DIR__, 2) . '/VERSION') ?: ''));
        if ($version !== '') {
            $this->help('bbs_info', 'Server version, as a label.', 'gauge');
            $this->sample('bbs_info', ['version' => $version], 1);
        }

        // 0/1/2 rather than a status label: an alert wants a threshold, and a
        // label per state would make "is anything wrong" a join.
        $map = [HealthService::OK => 0, HealthService::WARNING => 1, HealthService::CRITICAL => 2];
        $this->help('bbs_health_check_status', 'Health check state: 0 ok, 1 warning, 2 critical.', 'gauge');
        foreach (($health['checks'] ?? []) as $name => $check) {
            $this->sample('bbs_health_check_status', ['check' => (string) $name], $map[$check['status'] ?? ''] ?? 1);
        }
        if (isset($health['status'])) {
            $this->help('bbs_health_status', 'Worst of the individual checks.', 'gauge');
            $this->sample('bbs_health_status', [], $map[$health['status']] ?? 1);
        }

        // The single most useful number here: nothing is queued while the
        // scheduler is down, and no other signal says so.
        $last = $health['checks']['scheduler']['last_run'] ?? null;
        $this->help('bbs_scheduler_last_run_timestamp_seconds', 'When the scheduler last completed a pass.', 'gauge');
        $this->maybeTimestamp('bbs_scheduler_last_run_timestamp_seconds', [], $last);

        $this->stash($health);
    }

    private function renderClients(): void
    {
        $this->help('bbs_clients', 'Clients by status.', 'gauge');
        $counts = ['setup' => 0, 'online' => 0, 'offline' => 0, 'error' => 0];
        foreach ($this->db->fetchAll("SELECT status, COUNT(*) AS c FROM agents GROUP BY status") as $row) {
            $counts[$row['status']] = (int) $row['c'];
        }
        foreach ($counts as $status => $count) {
            $this->sample('bbs_clients', ['status' => $status], $count);
        }

        $rows = $this->db->fetchAll(
            "SELECT name, status, platform, agent_version, borg_version, last_heartbeat FROM agents ORDER BY name"
        );

        // borg_version is only written when `borg --version` returned 0, so an
        // online client with an empty one cannot back anything up — a silent
        // failure that this label turns into a one-line alert.
        $this->help('bbs_client_info', 'Client identity and versions, as labels.', 'gauge');
        foreach ($rows as $row) {
            $this->sample('bbs_client_info', [
                'client' => (string) $row['name'],
                'status' => (string) ($row['status'] ?? ''),
                'platform' => (string) ($row['platform'] ?? ''),
                'agent_version' => (string) ($row['agent_version'] ?? ''),
                'borg_version' => (string) ($row['borg_version'] ?? ''),
            ], 1);
        }

        // The version clients are meant to reach. Without it "how many agents
        // are behind" cannot be asked of Prometheus at all, since the answer
        // lives in a setting rather than in a label.
        $target = $this->setting('target_borg_version', '');
        if ($target !== '') {
            $this->help('bbs_target_borg_version_info', 'The borg version clients are expected to run, as a label.', 'gauge');
            $this->sample('bbs_target_borg_version_info', ['version' => $target], 1);
        }

        $this->help('bbs_client_last_heartbeat_timestamp_seconds', 'Last check-in of each client.', 'gauge');
        foreach ($rows as $row) {
            $this->maybeTimestamp('bbs_client_last_heartbeat_timestamp_seconds', ['client' => (string) $row['name']], $row['last_heartbeat']);
        }
    }

    private function renderPlans(): void
    {
        // Same shape as the JSON endpoint: the latest terminal job and the
        // latest successful one per plan, resolved once with ROW_NUMBER()
        // rather than per row.
        $rows = $this->db->fetchAll("
            SELECT bp.id AS plan_id, bp.name AS plan_name, bp.enabled,
                   a.name AS client_name,
                   r.name AS repo_name,
                   lastj.status AS last_status,
                   lastj.completed_at AS last_run_at,
                   lastj.duration_seconds AS last_run_duration_seconds,
                   lasts.completed_at AS last_success_at,
                   lasts.bytes_processed AS last_success_bytes
            FROM backup_plans bp
            JOIN agents a ON a.id = bp.agent_id
            LEFT JOIN repositories r ON r.id = bp.repository_id
            LEFT JOIN (
                SELECT backup_plan_id, status, completed_at, duration_seconds,
                       ROW_NUMBER() OVER (PARTITION BY backup_plan_id ORDER BY completed_at DESC, id DESC) AS rn
                FROM backup_jobs
                WHERE task_type = 'backup' AND status IN ('completed', 'failed')
            ) lastj ON lastj.backup_plan_id = bp.id AND lastj.rn = 1
            LEFT JOIN (
                SELECT backup_plan_id, completed_at, bytes_processed,
                       ROW_NUMBER() OVER (PARTITION BY backup_plan_id ORDER BY completed_at DESC, id DESC) AS rn
                FROM backup_jobs
                WHERE task_type = 'backup' AND status = 'completed'
            ) lasts ON lasts.backup_plan_id = bp.id AND lasts.rn = 1
            ORDER BY a.name, bp.name
        ");

        $labels = fn(array $r) => [
            'client' => (string) $r['client_name'],
            'plan' => (string) $r['plan_name'],
            'repo' => (string) ($r['repo_name'] ?? ''),
        ];

        $this->help('bbs_plan_enabled', 'Whether the plan is scheduled to run.', 'gauge');
        foreach ($rows as $r) {
            $this->sample('bbs_plan_enabled', $labels($r), !empty($r['enabled']) ? 1 : 0);
        }

        // What the plan is *supposed* to do, so an alert does not need a
        // hardcoded threshold: a two-hourly plan and a laptop backed up on
        // Mondays cannot share one. Both figures are the ones BBS already
        // acts on — next_run drives the scheduler, and overdue_hours is what
        // health calls late, profile override included.
        $schedules = [];
        foreach ($this->db->fetchAll("
            SELECT s.backup_plan_id, s.next_run, s.enabled
            FROM schedules s
        ") as $row) {
            $schedules[(int) $row['backup_plan_id']] = $row;
        }

        $globalOverdue = max(1, (int) $this->setting('backup_overdue_hours', '48'));
        $overdue = [];
        foreach ($this->db->fetchAll("
            SELECT bp.id AS plan_id, COALESCE(cp.backup_overdue_hours, {$globalOverdue}) AS hours
            FROM backup_plans bp
            JOIN agents a ON a.id = bp.agent_id
            LEFT JOIN client_profiles cp ON cp.id = a.client_profile_id
        ") as $row) {
            $overdue[(int) $row['plan_id']] = (int) $row['hours'];
        }

        $this->help('bbs_plan_next_run_timestamp_seconds', 'When this plan is due to run next.', 'gauge');
        foreach ($rows as $r) {
            $sched = $schedules[(int) $r['plan_id']] ?? null;
            if ($sched === null || empty($sched['enabled'])) {
                continue;
            }
            $this->maybeTimestamp('bbs_plan_next_run_timestamp_seconds', $labels($r), $sched['next_run']);
        }

        // Seconds, not the hours the setting is expressed in: the exposition
        // format wants base units, and the alert compares it to time().
        $this->help('bbs_plan_overdue_seconds', 'Time without a successful backup before BBS calls this plan overdue.', 'gauge');
        foreach ($rows as $r) {
            if (!isset($overdue[(int) $r['plan_id']])) {
                continue;
            }
            $this->sample('bbs_plan_overdue_seconds', $labels($r), $overdue[(int) $r['plan_id']] * 3600);
        }

        $this->help('bbs_plan_last_success_timestamp_seconds', 'When this plan last backed up successfully.', 'gauge');
        foreach ($rows as $r) {
            $this->maybeTimestamp('bbs_plan_last_success_timestamp_seconds', $labels($r), $r['last_success_at']);
        }

        $this->help('bbs_plan_last_run_timestamp_seconds', 'When this plan last finished, successfully or not.', 'gauge');
        foreach ($rows as $r) {
            $this->maybeTimestamp('bbs_plan_last_run_timestamp_seconds', $labels($r), $r['last_run_at']);
        }

        $this->help('bbs_plan_last_run_status', 'Result of that run: 0 completed, 1 failed.', 'gauge');
        foreach ($rows as $r) {
            if ($r['last_status'] === null) {
                continue;
            }
            $this->sample('bbs_plan_last_run_status', $labels($r), $r['last_status'] === 'completed' ? 0 : 1);
        }

        $this->help('bbs_plan_last_run_duration_seconds', 'How long that run took.', 'gauge');
        foreach ($rows as $r) {
            if ($r['last_run_duration_seconds'] === null) {
                continue;
            }
            $this->sample('bbs_plan_last_run_duration_seconds', $labels($r), (int) $r['last_run_duration_seconds']);
        }

        $this->help('bbs_plan_last_success_bytes', 'Bytes processed by the last successful run.', 'gauge');
        foreach ($rows as $r) {
            if ($r['last_success_bytes'] === null) {
                continue;
            }
            $this->sample('bbs_plan_last_success_bytes', $labels($r), (int) $r['last_success_bytes']);
        }

        // Every task type, not just backups: a prune that fails stops the
        // offsite copy behind it, and until now nothing counted those.
        // Deliberately not broken down per client — task_type x status x
        // client would multiply the series count by the size of the fleet
        // for a figure nobody alerts on per machine.
        $this->help('bbs_jobs_total', 'Finished jobs by task type and outcome, since the database was created.', 'counter');
        foreach ($this->db->fetchAll("
            SELECT task_type, status, COUNT(*) AS c
            FROM backup_jobs
            WHERE status IN ('completed','failed','cancelled')
            GROUP BY task_type, status
        ") as $row) {
            $this->sample('bbs_jobs_total', [
                'task_type' => (string) $row['task_type'],
                'status' => (string) $row['status'],
            ], (int) $row['c']);
        }

        $this->help('bbs_backup_jobs_total', 'Backup jobs by client and outcome, since the database was created.', 'counter');
        foreach ($this->db->fetchAll("
            SELECT a.name AS client_name, bj.status, COUNT(*) AS c
            FROM backup_jobs bj
            JOIN agents a ON a.id = bj.agent_id
            WHERE bj.task_type = 'backup' AND bj.status IN ('completed','failed','cancelled')
            GROUP BY a.name, bj.status
        ") as $row) {
            $this->sample('bbs_backup_jobs_total', [
                'client' => (string) $row['client_name'],
                'status' => (string) $row['status'],
            ], (int) $row['c']);
        }
    }

    private function renderQueue(): void
    {
        $this->help('bbs_queue_jobs', 'Jobs waiting or in flight, by state.', 'gauge');
        $counts = ['queued' => 0, 'sent' => 0, 'running' => 0];
        foreach ($this->db->fetchAll(
            "SELECT status, COUNT(*) AS c FROM backup_jobs WHERE status IN ('queued','sent','running') GROUP BY status"
        ) as $row) {
            $counts[$row['status']] = (int) $row['c'];
        }
        foreach ($counts as $state => $count) {
            $this->sample('bbs_queue_jobs', ['state' => $state], $count);
        }

        // The oldest job still waiting. A queue that stops draining shows up
        // here long before anything fails: the running job holds the repo
        // lock, and everything for that repo piles up behind it.
        $row = $this->db->fetchOne(
            "SELECT MIN(queued_at) AS oldest FROM backup_jobs WHERE status = 'queued'"
        );
        $this->help('bbs_queue_oldest_seconds', 'Age of the oldest job still waiting to be promoted.', 'gauge');
        if (($row['oldest'] ?? null) !== null) {
            $this->sample('bbs_queue_oldest_seconds', [], max(0, time() - strtotime((string) $row['oldest'])));
        }

        // A job that has been running for hours is the shape a stuck repo
        // takes: the lock is held, and every backup behind it waits.
        //
        // Broken down by task type, because the threshold is not the same for
        // all of them: the weekly compact rewrites every segment and is
        // legitimately long, while a backup still running after six hours is
        // usually stuck. Without the label an alert has to pick one number for
        // both, or go quiet during the compact window and miss what happens in it.
        $running = $this->db->fetchAll(
            "SELECT task_type, MAX(TIMESTAMPDIFF(SECOND, started_at, NOW())) AS s, COUNT(*) AS c
             FROM backup_jobs WHERE status = 'running' AND started_at IS NOT NULL
             GROUP BY task_type"
        );
        $this->help('bbs_job_running_seconds_max', 'Age of the longest-running job of each task type.', 'gauge');
        foreach ($running as $row) {
            if (($row['s'] ?? null) === null) {
                continue;
            }
            $this->sample('bbs_job_running_seconds_max', ['task_type' => (string) $row['task_type']], max(0, (int) $row['s']));
        }

        $this->help('bbs_jobs_running', 'Jobs currently running, by task type.', 'gauge');
        foreach ($running as $row) {
            $this->sample('bbs_jobs_running', ['task_type' => (string) $row['task_type']], (int) $row['c']);
        }
    }

    private function renderRepositories(): void
    {
        $repos = $this->db->fetchAll("
            SELECT r.id, r.name, r.storage_type, r.size_bytes, r.archive_count, a.name AS client_name
            FROM repositories r JOIN agents a ON a.id = r.agent_id
            ORDER BY a.name, r.name
        ");

        $repoLabels = fn(array $r) => [
            'client' => (string) $r['client_name'],
            'repo' => (string) $r['name'],
        ];

        // storage_type lives here rather than on the size, so that every
        // repository metric carries the SAME label set. Dividing two of them
        // — original bytes over size, for the deduplication ratio — is a
        // plain division only when their labels match; an extra label on one
        // side silently returns nothing, which is how this was found.
        $this->help('bbs_repo_info', 'Repository identity: where it is stored, as a label.', 'gauge');
        foreach ($repos as $r) {
            $this->sample('bbs_repo_info', $repoLabels($r) + [
                'storage_type' => (string) ($r['storage_type'] ?? 'local'),
            ], 1);
        }

        $this->help('bbs_repo_size_bytes', 'Repository size as last measured.', 'gauge');
        foreach ($repos as $r) {
            $this->sample('bbs_repo_size_bytes', $repoLabels($r), (int) $r['size_bytes']);
        }

        $this->help('bbs_repo_archives', 'Archives currently in the repository.', 'gauge');
        foreach ($repos as $r) {
            $this->sample('bbs_repo_archives', $repoLabels($r), (int) $r['archive_count']);
        }

        // One pass over archives for the three figures that describe what a
        // repository actually holds: how far back it goes, and what borg
        // saved by storing chunks once.
        $stats = [];
        foreach ($this->db->fetchAll("
            SELECT repository_id,
                   MIN(created_at) AS oldest, MAX(created_at) AS newest,
                   SUM(original_size) AS original, SUM(deduplicated_size) AS deduplicated
            FROM archives GROUP BY repository_id
        ") as $row) {
            $stats[(int) $row['repository_id']] = $row;
        }

        // How deep the history really goes — the only measure of what the
        // retention rules kept, which archive counts alone never show.
        $this->help('bbs_repo_oldest_archive_timestamp_seconds', 'Creation date of the oldest archive still in the repository.', 'gauge');
        foreach ($repos as $r) {
            $this->maybeTimestamp('bbs_repo_oldest_archive_timestamp_seconds', $repoLabels($r), $stats[(int) $r['id']]['oldest'] ?? null);
        }

        $this->help('bbs_repo_newest_archive_timestamp_seconds', 'Creation date of the newest archive in the repository.', 'gauge');
        foreach ($repos as $r) {
            $this->maybeTimestamp('bbs_repo_newest_archive_timestamp_seconds', $repoLabels($r), $stats[(int) $r['id']]['newest'] ?? null);
        }

        // Against bbs_repo_size_bytes these give the deduplication ratio, the
        // number that explains where a fleet's storage actually went.
        $this->help('bbs_repo_original_bytes', 'Sum of the original sizes of every archive, before deduplication.', 'gauge');
        foreach ($repos as $r) {
            if (!isset($stats[(int) $r['id']])) {
                continue;
            }
            $this->sample('bbs_repo_original_bytes', $repoLabels($r), (int) $stats[(int) $r['id']]['original']);
        }

        $this->help('bbs_repo_deduplicated_bytes', 'Sum of the deduplicated sizes reported by borg for each archive.', 'gauge');
        foreach ($repos as $r) {
            if (!isset($stats[(int) $r['id']])) {
                continue;
            }
            $this->sample('bbs_repo_deduplicated_bytes', $repoLabels($r), (int) $stats[(int) $r['id']]['deduplicated']);
        }

        // The offsite copy had no metric at all, though last_sync_at has been
        // in the database all along: a repository that quietly stops being
        // replicated is the failure this whole endpoint is meant to catch.
        $dests = $this->db->fetchAll("
            SELECT a.name AS client_name, r.name AS repo_name, pc.name AS destination,
                   rsc.enabled, rsc.last_sync_at
            FROM repository_s3_configs rsc
            JOIN repositories r ON r.id = rsc.repository_id
            JOIN agents a ON a.id = r.agent_id
            JOIN plugin_configs pc ON pc.id = rsc.plugin_config_id
            ORDER BY a.name, r.name, pc.name
        ");

        $destLabels = fn(array $d) => [
            'client' => (string) $d['client_name'],
            'repo' => (string) $d['repo_name'],
            'destination' => (string) $d['destination'],
        ];

        $this->help('bbs_repo_offsite_enabled', 'Whether this repository still replicates to this destination.', 'gauge');
        foreach ($dests as $d) {
            $this->sample('bbs_repo_offsite_enabled', $destLabels($d), !empty($d['enabled']) ? 1 : 0);
        }

        $this->help('bbs_repo_last_sync_timestamp_seconds', 'Last successful offsite copy to this destination.', 'gauge');
        foreach ($dests as $d) {
            $this->maybeTimestamp('bbs_repo_last_sync_timestamp_seconds', $destLabels($d), $d['last_sync_at']);
        }
    }

    private function renderStorage(): void
    {
        $health = $this->stash();
        $locations = $health['checks']['storage']['locations'] ?? [];

        $this->help('bbs_storage_location_capacity_bytes', 'Total size of a storage location.', 'gauge');
        foreach ($locations as $loc) {
            if (($loc['total_bytes'] ?? null) === null) {
                continue;   // capacity unknown — see HealthService on WebDAV mounts
            }
            $this->sample('bbs_storage_location_capacity_bytes', ['location' => (string) $loc['name']], (int) $loc['total_bytes']);
        }

        $this->help('bbs_storage_location_free_bytes', 'Free space at a storage location.', 'gauge');
        foreach ($locations as $loc) {
            if (($loc['free_bytes'] ?? null) === null) {
                continue;
            }
            $this->sample('bbs_storage_location_free_bytes', ['location' => (string) $loc['name']], (int) $loc['free_bytes']);
        }
    }

    private function renderSelfBackup(): void
    {
        $this->help('bbs_self_backup_last_timestamp_seconds', "When the server's own backup last ran.", 'gauge');
        $this->maybeTimestamp('bbs_self_backup_last_timestamp_seconds', [], $this->setting('last_self_backup', ''));

        // Off by default, and a server backup that never leaves the server is
        // no help the day the server is gone.
        $this->help('bbs_self_backup_offsite_enabled', 'Whether server backups are copied offsite.', 'gauge');
        $this->sample('bbs_self_backup_offsite_enabled', [], $this->setting('s3_sync_server_backups', '0') === '1' ? 1 : 0);
    }

    // ── rendering helpers ───────────────────────────────────────────

    private function help(string $name, string $help, string $type): void
    {
        $this->lines[] = '# HELP ' . $name . ' ' . str_replace(["\\", "\n"], ['\\\\', '\\n'], $help);
        $this->lines[] = '# TYPE ' . $name . ' ' . $type;
    }

    private function sample(string $name, array $labels, int|float $value): void
    {
        $rendered = '';
        if ($labels !== []) {
            $parts = [];
            foreach ($labels as $key => $val) {
                $parts[] = $key . '="' . $this->escape((string) $val) . '"';
            }
            $rendered = '{' . implode(',', $parts) . '}';
        }
        $this->lines[] = $name . $rendered . ' ' . (is_float($value) ? rtrim(rtrim(sprintf('%.6F', $value), '0'), '.') : (string) $value);
    }

    /** A date, or nothing at all: never 0, which would read as 1970. */
    private function maybeTimestamp(string $name, array $labels, ?string $datetime): void
    {
        if ($datetime === null || $datetime === '' || $datetime === '0000-00-00 00:00:00') {
            return;
        }
        $ts = strtotime($datetime);
        if ($ts === false) {
            return;
        }
        $this->sample($name, $labels, $ts);
    }

    /** Backslash, quote and newline, per the exposition format. */
    private function escape(string $value): string
    {
        return str_replace(["\\", "\"", "\n"], ['\\\\', '\\"', '\\n'], $value);
    }

    /**
     * The cache age belongs to the response, not to the cached body — it
     * would otherwise report the age the snapshot had when it was written.
     */
    private function withAge(string $body, int $age): string
    {
        return $body
            . "# HELP bbs_metrics_cache_age_seconds Age of the snapshot served, in seconds.\n"
            . "# TYPE bbs_metrics_cache_age_seconds gauge\n"
            . 'bbs_metrics_cache_age_seconds ' . max(0, $age) . "\n";
    }

    private function cacheFile(): ?string
    {
        foreach ([self::CACHE_DIR, sys_get_temp_dir() . '/bbs-metrics'] as $dir) {
            if (!is_dir($dir) && !@mkdir($dir, 0770, true) && !is_dir($dir)) {
                continue;
            }
            if (is_writable($dir)) {
                return $dir . '/metrics.prom';
            }
        }
        return null;   // no cache available: render every time rather than fail
    }

    private function setting(string $key, string $default): string
    {
        $row = $this->db->fetchOne("SELECT `value` FROM settings WHERE `key` = ?", [$key]);
        $value = $row['value'] ?? '';
        return $value === '' ? $default : (string) $value;
    }

    /** Health is read once and reused by the storage section. */
    private array $healthStash = [];

    private function stash(?array $health = null): array
    {
        if ($health !== null) {
            $this->healthStash = $health;
        }
        return $this->healthStash;
    }
}
