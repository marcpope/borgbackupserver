<?php
/**
 * Small test harness for restore checks. Runs inside the disposable
 * development container against its real MariaDB and real borg; see
 * README.md. No framework: BBS has none, and a plain runner keeps the
 * dependencies at zero.
 */

namespace RcTest;

use BBS\Core\Config;
use BBS\Core\Database;
use BBS\Services\Encryption;

require_once '/var/www/bbs/vendor/autoload.php';
date_default_timezone_set('UTC');
Config::load();

final class T
{
    public static int $pass = 0;
    public static array $failures = [];
    public static ?string $filter = null;
    private static string $current = '';

    public static function test(string $name, callable $fn): void
    {
        if (self::$filter !== null && stripos($name, self::$filter) === false) {
            return;
        }
        self::$current = $name;
        $start = microtime(true);
        try {
            $fn();
            teardown();
            self::$pass++;
            printf("  ok    %s (%.1fs)\n", $name, microtime(true) - $start);
        } catch (\Throwable $e) {
            teardown();
            self::$failures[] = [$name, $e];
            printf("  FAIL  %s\n        %s\n        at %s:%d\n", $name, $e->getMessage(), basename($e->getFile()), $e->getLine());
        }
    }

    public static function eq($expected, $actual, string $msg = ''): void
    {
        if ($expected !== $actual) {
            throw new \RuntimeException(($msg ? "{$msg}: " : '') . 'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
        }
    }

    public static function true($cond, string $msg): void
    {
        if (!$cond) {
            throw new \RuntimeException($msg);
        }
    }

    public static function section(string $title): void
    {
        if (self::$filter === null) {
            echo "\n== {$title}\n";
        }
    }
}

function maintenance(bool $on): void
{
    db()->query(
        "INSERT INTO settings (`key`, `value`) VALUES ('maintenance_mode', ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)",
        [$on ? '1' : '0']
    );
}

/**
 * After every test: nothing of ours left queued or holding the single
 * restore check slot, no lock holder, no stray staging. One failing test
 * must not make the next ones fail for the wrong reason.
 */
function teardown(): void
{
    maintenance(false);
    exec('pkill -f "borg with-lock" 2>/dev/null');
    // Every outstanding job of the test client, including the catalog
    // rebuilds a full scheduler pass queues for its unindexed archives.
    db()->query("UPDATE backup_jobs SET status = 'cancelled', completed_at = NOW()
                 WHERE status IN ('queued', 'sent', 'running')
                   AND agent_id IN (SELECT id FROM agents WHERE name LIKE 'rc-test-%')");
    // A worker still running notices the cancellation within a poll and
    // cleans up after itself; wait for that rather than racing it.
    $deadline = time() + 90;
    while (time() < $deadline && db()->fetchOne(
        "SELECT r.id FROM restore_check_runs r JOIN backup_jobs bj ON bj.id = r.backup_job_id WHERE r.result IS NULL AND bj.worker_pid IS NOT NULL AND bj.status = 'cancelled'"
    )) {
        $alive = false;
        foreach (db()->fetchAll("SELECT bj.id, bj.worker_pid FROM restore_check_runs r JOIN backup_jobs bj ON bj.id = r.backup_job_id WHERE r.result IS NULL AND bj.worker_pid IS NOT NULL") as $j) {
            $cmd = @file_get_contents("/proc/{$j['worker_pid']}/cmdline");
            $alive = $alive || ($cmd !== false && str_contains($cmd, '--job=' . $j['id']));
        }
        if (!$alive) {
            break;
        }
        usleep(300000);
    }
    $runner = new \BBS\Services\RestoreCheckRunner();
    $runner->reconcile();
    db()->query("UPDATE restore_check_plans SET enabled = 0, next_due_at = NULL");
}

function db(): Database
{
    return Database::getInstance();
}

function fixtures(): array
{
    static $f = null;
    return $f ??= json_decode(file_get_contents('/var/bbs/rc-test/fixtures.json'), true);
}

function sh(string $cmd): array
{
    exec($cmd . ' 2>&1', $out, $rc);
    return [$rc, implode("\n", $out)];
}

/** Everything the tests create hangs off this agent; deleting it cascades. */
function resetWorld(): array
{
    $db = db();
    foreach ($db->fetchAll("SELECT id FROM agents WHERE name LIKE 'rc-test-%'") as $a) {
        $db->query("DELETE FROM backup_jobs WHERE agent_id = ?", [$a['id']]);
        $db->delete('agents', 'id = ?', [$a['id']]);
    }
    $db->query("DELETE FROM restore_check_runs");
    $db->query("DELETE FROM notifications WHERE type LIKE 'restore_check%'");
    $db->query("DELETE FROM users WHERE username LIKE 'rc-%'");
    $db->query("DELETE FROM remote_ssh_configs WHERE name LIKE 'rc-test%'");
    maintenance(false);

    $f = fixtures();
    $admin = (int) $db->fetchOne("SELECT id FROM users WHERE role = 'admin' ORDER BY id LIMIT 1")['id'];
    $agentId = $db->insert('agents', [
        'name' => 'rc-test-client', 'hostname' => 'rc-test', 'status' => 'online',
        'ssh_unix_user' => $f['local_user'], 'user_id' => $admin, 'last_heartbeat' => $db->now(),
    ]);
    $world = ['agent' => $agentId, 'admin' => $admin, 'repos' => [], 'plans' => [], 'archives' => []];

    $repo = function (string $key, string $path, string $pass, array $extra = []) use ($db, $agentId, &$world) {
        $world['repos'][$key] = $db->insert('repositories', array_merge([
            'agent_id' => $agentId, 'name' => "rc-{$key}", 'path' => $path,
            'encryption' => 'repokey-blake2', 'passphrase_encrypted' => Encryption::encrypt($pass),
            'storage_type' => 'local',
        ], $extra));
    };
    $home = $f['local_home'];
    $repo('main', "{$home}/repo-main", $f['pass_local']);
    $repo('full', "{$home}/repo-full", $f['pass_local']);
    $repo('dev', "{$home}/repo-dev", $f['pass_local']);
    $repo('evil', "{$home}/repo-evil", $f['pass_local']);
    $repo('evil2', "{$home}/repo-evil2", $f['pass_local']);

    $sshId = $db->insert('remote_ssh_configs', [
        'name' => 'rc-test-remote', 'remote_host' => '127.0.0.1', 'remote_port' => 22,
        'remote_user' => $f['remote_user'],
        'ssh_private_key_encrypted' => Encryption::encrypt(file_get_contents($f['remote_key'])),
    ]);
    $world['ssh_config'] = $sshId;
    $repo('remote', "ssh://{$f['remote_user']}@127.0.0.1:22/./repo-remote", $f['pass_remote'], [
        'storage_type' => 'remote_ssh', 'remote_ssh_config_id' => $sshId,
    ]);

    $plan = function (string $key, string $repoKey) use ($db, $agentId, &$world) {
        $world['plans'][$key] = $db->insert('backup_plans', [
            'agent_id' => $agentId, 'repository_id' => $world['repos'][$repoKey], 'name' => "rc-plan-{$key}", 'directories' => '/',
        ]);
    };
    $plan('a', 'main');
    $plan('b', 'main');
    $plan('full', 'full');
    $plan('dev', 'dev');
    $plan('evil', 'evil');
    $plan('evil2', 'evil2');
    $plan('remote', 'remote');

    // Archive rows as a completed backup would leave them; created_at
    // spaced so "newest" is unambiguous.
    $archive = function (string $repoKey, string $planKey, string $name, int $minutesAgo, int $warnings = 0, ?string $status = 'completed') use ($db, $agentId, &$world) {
        $jobId = null;
        if ($status !== null) {
            $jobId = $db->insert('backup_jobs', [
                'agent_id' => $agentId, 'backup_plan_id' => $world['plans'][$planKey], 'repository_id' => $world['repos'][$repoKey],
                'task_type' => 'backup', 'status' => $status, 'had_warnings' => $warnings,
                'queued_at' => gmdate('Y-m-d H:i:s', time() - $minutesAgo * 60 - 120),
                'completed_at' => gmdate('Y-m-d H:i:s', time() - $minutesAgo * 60),
            ]);
        }
        $world['archives'][$name] = $db->insert('archives', [
            'repository_id' => $world['repos'][$repoKey], 'backup_job_id' => $jobId, 'archive_name' => $name,
            'created_at' => gmdate('Y-m-d H:i:s', time() - $minutesAgo * 60),
        ]);
        return $world['archives'][$name];
    };
    $archive('main', 'a', 'plan-a-2026-10-01', 300);
    $archive('main', 'b', 'plan-b-2026-10-01', 200);
    $archive('main', 'a', 'plan-a-2026-10-02', 100);
    $archive('full', 'full', 'full-2026-10-02', 60);
    $archive('dev', 'dev', 'dev-2026-10-02', 60);
    $archive('evil', 'evil', 'evil-2026-10-02', 60);
    $archive('evil2', 'evil2', 'evil2-2026-10-02', 60);
    $archive('remote', 'remote', 'remote-2026-10-02', 60);
    $world['addArchive'] = $archive;

    return $world;
}

function makeUser(string $name, string $role = 'user', array $agentPerms = [], ?int $agentId = null): int
{
    $db = db();
    $id = $db->insert('users', [
        'username' => $name, 'email' => "{$name}@rc-test.invalid",
        'password_hash' => password_hash('rc-pass-' . $name, PASSWORD_DEFAULT), 'role' => $role,
        'timezone' => 'UTC',
    ]);
    if ($agentId !== null) {
        $db->insert('user_agents', ['user_id' => $id, 'agent_id' => $agentId]);
        foreach ($agentPerms as $p) {
            $db->insert('user_permissions', ['user_id' => $id, 'permission' => $p, 'agent_id' => $agentId]);
        }
    }
    return $id;
}

/** A plan through the same validation the form uses; returns its id. */
function makePlan(array $world, array $input, ?int $owner = null): int
{
    $svc = new \BBS\Services\RestoreCheckPlanService();
    $input = array_merge([
        'name' => 'rc check', 'repository_id' => $world['repos']['main'], 'scope' => 'plan',
        'backup_plan_id' => $world['plans']['a'], 'mode' => 'paths', 'paths' => ['etc/hostname'], 'sha256' => [''],
        'frequency' => 'weekly', 'times' => '03:00', 'day_of_week' => ['1'], 'timezone' => 'Europe/Berlin',
        'max_archive_age_hours' => 48, 'queue_deadline_minutes' => 1440, 'run_timeout_minutes' => 120,
        'max_files' => 100000, 'overdue_hours' => 192, 'max_gib' => 10, 'min_free_gib' => 0.01,
    ], $input);
    [$data, $errors] = $svc->validateInput($world['agent'], $input);
    if ($errors) {
        throw new \RuntimeException('plan invalid: ' . implode('; ', $errors));
    }
    $data['agent_id'] = $world['agent'];
    $data['owner_user_id'] = $owner ?? $world['admin'];
    $data['created_by'] = $owner ?? $world['admin'];
    return db()->insert('restore_check_plans', $data);
}

function enqueue(int $planId, int $userId, ?string $key = null): array
{
    return (new \BBS\Services\RestoreCheckPlanService())->enqueue($planId, 'manual', $userId, $key ?? bin2hex(random_bytes(8)));
}

function run(int $runId): array
{
    return db()->fetchOne("SELECT * FROM restore_check_runs WHERE id = ?", [$runId]);
}

function job(int $jobId): ?array
{
    return db()->fetchOne("SELECT * FROM backup_jobs WHERE id = ?", [$jobId]);
}

/** Promote through the real queue, then run the real worker as www-data. */
function promote(): void
{
    (new \BBS\Services\QueueManager())->processQueue();
}

function startWorker(int $jobId, bool $background = false): void
{
    $cmd = 'su -s /bin/sh www-data -c ' . escapeshellarg('cd /var/www/bbs && php scheduler.php --job=' . $jobId . ' >> /var/bbs/tmp/rc-worker.log 2>&1' . ($background ? ' &' : ''));
    exec($cmd);
}

function runToEnd(int $runId, int $timeout = 180): array
{
    $r = run($runId);
    promote();
    $j = job((int) $r['backup_job_id']);
    if ($j['status'] !== 'sent') {
        throw new \RuntimeException("job #{$j['id']} was not promoted (status {$j['status']})");
    }
    startWorker((int) $r['backup_job_id']);
    $deadline = time() + $timeout;
    while (time() < $deadline) {
        $r = run($runId);
        if ($r['result'] !== null) {
            return $r;
        }
        usleep(200000);
    }
    throw new \RuntimeException("run #{$runId} did not finish");
}

function waitFor(callable $cond, int $seconds, string $what): void
{
    $deadline = microtime(true) + $seconds;
    while (microtime(true) < $deadline) {
        if ($cond()) {
            return;
        }
        usleep(200000);
    }
    throw new \RuntimeException("timed out waiting for {$what}");
}

function stagingDirs(): array
{
    return array_values(array_filter(scandir('/var/bbs/restore-checks') ?: [], fn($n) => preg_match('/^[0-9a-f]{32}$/', $n)));
}

function probes(array $run): array
{
    $s = json_decode((string) $run['probe_summary'], true) ?: [];
    $out = [];
    foreach ($s['probes'] ?? [] as $p) {
        $out[$p['path']] = $p;
    }
    return $out;
}

/** Hold the repository's borg lock as its owner for $seconds. */
function holdLock(string $repo, int $seconds): void
{
    $f = fixtures();
    // Passphrase via the environment, so the secrets test below never sees
    // the test's own command line.
    putenv('BORG_PASSPHRASE=' . $f['pass_local']);
    exec(sprintf(
        'cd /tmp && BORG_BASE_DIR=/var/bbs/cache/%s setpriv --reuid=%s --regid=$(id -g %s) --init-groups borg with-lock %s sleep %d > /dev/null 2>&1 &',
        $f['local_user'], $f['local_user'], $f['local_user'], escapeshellarg($repo), $seconds
    ));
    putenv('BORG_PASSPHRASE');
    sleep(2);
}
