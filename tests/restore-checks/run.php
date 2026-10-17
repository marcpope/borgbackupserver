<?php
/**
 * Restore check tests. Run as root inside the development container:
 *   php /var/www/bbs/tests/restore-checks/run.php [name-filter]
 * Workers are started as www-data, exactly as cron starts them.
 */

namespace RcTest;

use BBS\Services\RestoreCheckPlanService as Plans;
use BBS\Services\RestoreCheckReport;
use BBS\Services\RestoreCheckRunner;
use BBS\Services\RestoreCheckSchedule as Sched;
use BBS\Services\QueueManager;

require __DIR__ . '/lib.php';

if (getenv('BBS_RC_TEST') !== '1' || posix_getuid() !== 0) {
    fwrite(STDERR, "Run as root in the development container with BBS_RC_TEST=1\n");
    exit(2);
}
T::$filter = $argv[1] ?? null;
$f = fixtures();
$utc = new \DateTimeZone('UTC');
$at = fn(string $s, string $tz = 'UTC') => new \DateTimeImmutable($s, new \DateTimeZone($tz));
$local = fn(?\DateTimeImmutable $d, string $tz) => $d?->setTimezone(new \DateTimeZone($tz))->format('Y-m-d H:i T');

// =====================================================================
T::section('Schedule calculation');

T::test('times are validated, sorted and de-duplicated', function () {
    T::eq(['03:00', '07:05', '23:59'], Sched::normalizeTimes('23:59, 3:00,07:05,03:00'));
    foreach (['24:00', '3', 'ab:cd', ''] as $bad) {
        try { Sched::normalizeTimes($bad); throw new \LogicException("accepted {$bad}"); } catch (\InvalidArgumentException) {}
    }
});

T::test('daily with several times picks the next one', function () use ($at, $local) {
    $p = ['frequency' => 'daily', 'times' => '06:00,18:00', 'timezone' => 'Europe/Berlin'];
    T::eq('2026-06-10 18:00 CEST', $local(Sched::nextOccurrence($p, $at('2026-06-10 12:00', 'Europe/Berlin')), 'Europe/Berlin'));
    T::eq('2026-06-11 06:00 CEST', $local(Sched::nextOccurrence($p, $at('2026-06-10 18:00', 'Europe/Berlin')), 'Europe/Berlin'));
});

T::test('weekly and monthly follow the stored conventions', function () use ($at, $local) {
    $w = ['frequency' => 'weekly', 'times' => '03:00', 'day_of_week' => '1,4', 'timezone' => 'Europe/Berlin'];
    T::eq('2026-10-19 03:00 CEST', $local(Sched::nextOccurrence($w, $at('2026-10-15 04:00', 'Europe/Berlin')), 'Europe/Berlin'));
    $m = ['frequency' => 'monthly', 'times' => '03:00', 'day_of_month' => 'last', 'timezone' => 'UTC'];
    T::eq('2027-02-28 03:00 UTC', $local(Sched::nextOccurrence($m, $at('2027-02-01')), 'UTC'));
    $m15 = ['frequency' => 'monthly', 'times' => '03:00', 'day_of_month' => '15', 'timezone' => 'UTC'];
    T::eq('2026-11-15 03:00 UTC', $local(Sched::nextOccurrence($m15, $at('2026-10-15 03:00')), 'UTC'));
});

T::test('spring forward: a non-existent time is skipped that day', function () use ($at, $local) {
    // Europe/Berlin jumps 02:00 -> 03:00 on 2027-03-28.
    $p = ['frequency' => 'daily', 'times' => '02:30', 'timezone' => 'Europe/Berlin'];
    T::eq('2027-03-29 02:30 CEST', $local(Sched::nextOccurrence($p, $at('2027-03-27 03:00', 'Europe/Berlin')), 'Europe/Berlin'));
});

T::test('fall back: a repeated time runs once', function () use ($at) {
    // Europe/Berlin repeats 02:00-03:00 on 2026-10-25 (00:00-01:00 UTC twice).
    $p = ['frequency' => 'daily', 'times' => '02:30', 'timezone' => 'Europe/Berlin'];
    $first = Sched::nextOccurrence($p, $at('2026-10-25 00:00', 'Europe/Berlin'));
    T::eq('2026-10-25 00:30', $first->format('Y-m-d H:i'), 'first instance (UTC)');
    $second = Sched::nextOccurrence($p, $first);
    T::eq('2026-10-26 01:30', $second->format('Y-m-d H:i'), 'next day, not the repeat at 01:30 UTC');
});

T::test('catch-up counts missed occurrences without listing them', function () use ($at) {
    $p = ['frequency' => 'daily', 'times' => '03:00', 'timezone' => 'UTC'];
    T::eq(10, Sched::countOccurrences($p, $at('2026-10-05 03:00'), $at('2026-10-15 04:00')));
});

// =====================================================================
T::section('Plan validation');

T::test('archive paths are literal and confined', function () {
    T::eq('etc/hostname', Plans::normalizeArchivePath('/etc/hostname'));
    T::eq('data/sh:*', Plans::normalizeArchivePath('data/sh:*'));
    T::eq('-dash.txt', Plans::normalizeArchivePath('-dash.txt'));
    foreach (['../etc/passwd', 'a/../b', 'a//b', './a', '', "a\0b", "a\nb", "a\x7fb", '//etc'] as $bad) {
        try { Plans::normalizeArchivePath($bad); throw new \LogicException('accepted ' . json_encode($bad)); } catch (\InvalidArgumentException) {}
    }
});

$world = resetWorld();

T::test('form validation refuses incomplete or foreign input', function () use ($world) {
    $svc = new Plans();
    $base = ['name' => 'x', 'repository_id' => $world['repos']['main'], 'scope' => 'plan', 'backup_plan_id' => $world['plans']['a'],
        'mode' => 'paths', 'paths' => ['etc/hostname'], 'frequency' => 'weekly', 'times' => '03:00', 'timezone' => 'Europe/Berlin',
        'max_archive_age_hours' => 48, 'queue_deadline_minutes' => 1440, 'run_timeout_minutes' => 120, 'max_files' => 10,
        'overdue_hours' => 10, 'max_gib' => 1, 'min_free_gib' => 1];
    [, $e] = $svc->validateInput($world['agent'], $base);
    T::eq([], $e, 'valid input');
    $cases = [
        'empty paths' => ['paths' => ['  ']],
        'bad timezone' => ['timezone' => 'Mars/Olympus'],
        'bad sha' => ['sha256' => ['xyz']],
        'plan of another repo' => ['backup_plan_id' => $world['plans']['full']],
        'foreign repository' => ['repository_id' => 999999],
        'full without budget' => ['mode' => 'full'],
        'bad frequency' => ['frequency' => 'hourly'],
        'duplicate path' => ['paths' => ['a', '/a']],
    ];
    foreach ($cases as $label => $override) {
        [, $e] = $svc->validateInput($world['agent'], array_merge($base, $override));
        T::true($e !== [], "{$label} was accepted");
    }
    db()->update('repositories', ['read_only' => 1], 'id = ?', [$world['repos']['dev']]);
    [, $e] = $svc->validateInput($world['agent'], array_merge($base, ['repository_id' => $world['repos']['dev'], 'scope' => 'repository']));
    db()->update('repositories', ['read_only' => 0], 'id = ?', [$world['repos']['dev']]);
    T::true($e !== [], 'read-only copy accepted');
});

T::test('overdue uses the last strict pass, or the enable time', function () use ($at) {
    $now = $at('2026-10-15 12:00');
    $p = ['enabled' => 1, 'overdue_hours' => 192, 'enabled_at' => '2026-10-01 00:00:00', 'last_strict_success_at' => null];
    T::true(Plans::isOverdue($p, $now), 'never passed, enabled 14 days ago');
    T::true(!Plans::isOverdue(array_merge($p, ['last_strict_success_at' => '2026-10-14 00:00:00']), $now), 'recent pass');
    T::true(!Plans::isOverdue(array_merge($p, ['enabled' => 0]), $now), 'disabled');
    T::true(!Plans::isOverdue(array_merge($p, ['last_strict_success_at' => '2026-09-01 00:00:00', 'enabled_at' => '2026-10-14 00:00:00']), $now), 're-enabled recently');
});

// =====================================================================
T::section('Scheduling and idempotency');

T::test('due plan queues one run, advances, and records missed runs', function () use ($world) {
    $id = makePlan($world, ['frequency' => 'daily', 'times' => '03:00', 'timezone' => 'UTC']);
    db()->update('restore_check_plans', ['enabled' => 1, 'enabled_at' => db()->now(), 'next_due_at' => gmdate('Y-m-d 03:00:00', time() - 10 * 86400)], 'id = ?', [$id]);
    (new Plans())->tick();
    $runs = db()->fetchAll("SELECT * FROM restore_check_runs WHERE plan_id = ?", [$id]);
    T::eq(1, count($runs), 'runs queued');
    T::true((int) $runs[0]['missed_occurrences'] >= 9, 'missed occurrences recorded');
    T::eq('scheduled', $runs[0]['trigger_type']);
    $plan = db()->fetchOne("SELECT next_due_at FROM restore_check_plans WHERE id = ?", [$id]);
    T::true($plan['next_due_at'] > db()->now(), 'next due is in the future');
    (new Plans())->tick();
    T::eq(1, (int) db()->fetchOne("SELECT COUNT(*) n FROM restore_check_runs WHERE plan_id = ?", [$id])['n'], 'second tick adds nothing');
    db()->query("UPDATE backup_jobs SET status='cancelled' WHERE id = ?", [$runs[0]['backup_job_id']]);
    (new RestoreCheckRunner())->reconcile();
});

T::test('two schedulers at once queue one run', function () use ($world) {
    $id = makePlan($world, ['frequency' => 'daily', 'times' => '03:00', 'timezone' => 'UTC']);
    db()->update('restore_check_plans', ['enabled' => 1, 'enabled_at' => db()->now(), 'next_due_at' => gmdate('Y-m-d H:i:s', time() - 60)], 'id = ?', [$id]);
    $script = '/tmp/rc-tick.php';
    file_put_contents($script, "<?php require '/var/www/bbs/vendor/autoload.php'; BBS\\Core\\Config::load(); usleep(" . 100000 . "); (new BBS\\Services\\RestoreCheckPlanService())->tick();");
    exec("php {$script} > /dev/null 2>&1 & php {$script} > /dev/null 2>&1 & php {$script} > /dev/null 2>&1 & wait");
    T::eq(1, (int) db()->fetchOne("SELECT COUNT(*) n FROM restore_check_runs WHERE plan_id = ?", [$id])['n'], 'runs');
    T::eq(1, (int) db()->fetchOne("SELECT COUNT(*) n FROM backup_jobs bj JOIN restore_check_runs r ON r.backup_job_id = bj.id WHERE r.plan_id = ?", [$id])['n'], 'jobs');
    $r = db()->fetchOne("SELECT backup_job_id FROM restore_check_runs WHERE plan_id = ?", [$id]);
    db()->query("UPDATE backup_jobs SET status='cancelled' WHERE id = ?", [$r['backup_job_id']]);
    (new RestoreCheckRunner())->reconcile();
});

T::test('disabled plans and maintenance mode queue nothing', function () use ($world) {
    $id = makePlan($world, []);
    db()->update('restore_check_plans', ['enabled' => 0, 'next_due_at' => gmdate('Y-m-d H:i:s', time() - 60)], 'id = ?', [$id]);
    (new Plans())->tick();
    $id2 = makePlan($world, []);
    db()->update('restore_check_plans', ['enabled' => 1, 'enabled_at' => db()->now(), 'next_due_at' => gmdate('Y-m-d H:i:s', time() - 60)], 'id = ?', [$id2]);
    maintenance(true);
    (new Plans())->tick();
    maintenance(false);
    T::eq(0, (int) db()->fetchOne("SELECT COUNT(*) n FROM restore_check_runs WHERE plan_id IN (?, ?)", [$id, $id2])['n']);
    db()->update('restore_check_plans', ['enabled' => 0], 'id = ?', [$id2]);
});

T::test('an occurrence due while a run is active is recorded as skipped', function () use ($world) {
    $id = makePlan($world, []);
    $first = enqueue($id, $world['admin']);
    db()->update('restore_check_plans', ['enabled' => 1, 'enabled_at' => db()->now(), 'next_due_at' => gmdate('Y-m-d H:i:s', time() - 60)], 'id = ?', [$id]);
    (new Plans())->tick();
    $skipped = db()->fetchOne("SELECT * FROM restore_check_runs WHERE plan_id = ? AND result = 'skipped'", [$id]);
    T::eq('previous_run_active', $skipped['reason_code'] ?? null);
    T::eq(null, $skipped['backup_job_id']);
    db()->update('restore_check_plans', ['enabled' => 0], 'id = ?', [$id]);
    db()->query("UPDATE backup_jobs SET status='cancelled' WHERE id = ?", [$first['job_id']]);
    (new RestoreCheckRunner())->reconcile();
});

T::test('owner who lost restore access: plan disabled, occurrence skipped', function () use ($world) {
    $u = makeUser('rc-leaver', 'user', ['restore', 'manage_plans'], $world['agent']);
    $id = makePlan($world, [], $u);
    db()->update('restore_check_plans', ['enabled' => 1, 'enabled_at' => db()->now(), 'next_due_at' => gmdate('Y-m-d H:i:s', time() - 60)], 'id = ?', [$id]);
    db()->delete('user_permissions', 'user_id = ? AND permission = ?', [$u, 'restore']);
    (new Plans())->tick();
    $p = db()->fetchOne("SELECT enabled, disabled_reason FROM restore_check_plans WHERE id = ?", [$id]);
    T::eq(0, (int) $p['enabled']);
    T::true(str_contains((string) $p['disabled_reason'], 'no longer'), 'reason recorded');
    T::eq('owner_unauthorized', db()->fetchOne("SELECT reason_code FROM restore_check_runs WHERE plan_id = ?", [$id])['reason_code']);
});

T::test('Run now is idempotent and refuses overlap', function () use ($world) {
    $id = makePlan($world, []);
    $key = bin2hex(random_bytes(8));
    $a = enqueue($id, $world['admin'], $key);
    $b = enqueue($id, $world['admin'], $key);
    T::eq($a['run_id'], $b['run_id'], 'same submission, same run');
    T::eq(false, $b['created']);
    $c = enqueue($id, $world['admin']);
    T::eq('already_active', $c['error'] ?? null);
    T::eq(0, (int) db()->fetchOne("SELECT enabled FROM restore_check_plans WHERE id = ?", [$id])['enabled'], 'Run now does not enable');
    db()->query("UPDATE backup_jobs SET status='cancelled' WHERE id = ?", [$a['job_id']]);
    (new RestoreCheckRunner())->reconcile();
    T::eq('cancelled', run($a['run_id'])['result'], 'cancelled while queued');
});

// =====================================================================
T::section('Queue admission');

T::test('one restore check at a time, and never alongside other work on the repository', function () use ($world) {
    $p1 = makePlan($world, []);
    $p2 = makePlan($world, ['scope' => 'repository']);
    $p3 = makePlan($world, ['repository_id' => $world['repos']['full'], 'backup_plan_id' => $world['plans']['full'], 'paths' => ['docs/readme.txt']]);
    $r1 = enqueue($p1, $world['admin']);
    $r2 = enqueue($p2, $world['admin']);
    $r3 = enqueue($p3, $world['admin']);
    promote();
    $sent = array_filter([$r1, $r2, $r3], fn($r) => job($r['job_id'])['status'] === 'sent');
    T::eq(1, count($sent), 'global cap of one');
    T::eq('sent', job($r1['job_id'])['status'], 'oldest first');
    // A prune queued on the same repository waits for it.
    $prune = db()->insert('backup_jobs', ['agent_id' => $world['agent'], 'repository_id' => $world['repos']['main'], 'task_type' => 'prune', 'status' => 'queued']);
    promote();
    T::eq('queued', job($prune)['status'], 'prune waits for the busy repository');
    // Agents never receive restore checks.
    foreach ((new QueueManager())->getTasksForAgent($world['agent']) as $task) {
        T::true(($task['task'] ?? '') !== 'restore_check', 'restore check sent to agent');
    }
    foreach ([$r1, $r2, $r3] as $r) {
        db()->query("UPDATE backup_jobs SET status='cancelled' WHERE id = ?", [$r['job_id']]);
    }
    db()->query("UPDATE backup_jobs SET status='cancelled' WHERE id = ?", [$prune]);
    (new RestoreCheckRunner())->reconcile();
});

T::test('runs while the client is offline', function () use ($world) {
    db()->update('agents', ['status' => 'offline'], 'id = ?', [$world['agent']]);
    $id = makePlan($world, []);
    $r = runToEnd(enqueue($id, $world['admin'])['run_id']);
    db()->update('agents', ['status' => 'online'], 'id = ?', [$world['agent']]);
    T::eq('passed', $r['result'], $r['reason_detail'] ?? '');
});

T::test('maintenance mode puts a claimed run back in the queue', function () use ($world) {
    $id = makePlan($world, []);
    $e = enqueue($id, $world['admin']);
    promote();
    maintenance(true);
    startWorker($e['job_id']);
    maintenance(false);
    T::eq('queued', job($e['job_id'])['status']);
    T::eq(null, run($e['run_id'])['result']);
    T::eq([], stagingDirs(), 'no staging created');
    $r = runToEnd($e['run_id']);
    T::eq('passed', $r['result']);
});

// =====================================================================
T::section('Archive selection');

T::test('plan scope tests that plan\'s newest archive, repository scope the newest overall', function () use ($world) {
    $b = runToEnd(enqueue(makePlan($world, ['backup_plan_id' => $world['plans']['b'], 'paths' => ['srv/b-only.txt']]), $world['admin'])['run_id']);
    T::eq('plan-b-2026-10-01', $b['archive_name']);
    T::eq('passed', $b['result'], (string) $b['reason_detail']);
    $repo = runToEnd(enqueue(makePlan($world, ['scope' => 'repository']), $world['admin'])['run_id']);
    T::eq('plan-a-2026-10-02', $repo['archive_name']);
    T::true((bool) preg_match('/^[0-9a-f]{64}$/', (string) $repo['borg_archive_id']), 'borg archive id pinned');
});

T::test('warning, failed, checkpoint and imported archives are not selected', function () use ($world) {
    $add = $world['addArchive'];
    $ids = [
        $add('main', 'a', 'plan-a-warn', 50, 1),
        $add('main', 'a', 'plan-a-failedjob', 40, 0, 'failed'),
        $add('main', 'a', 'plan-a-2026-10-03.checkpoint', 30),
        $add('main', 'a', 'imported-archive', 20, 0, null),
    ];
    $r = runToEnd(enqueue(makePlan($world, []), $world['admin'])['run_id']);
    T::eq('plan-a-2026-10-02', $r['archive_name']);
    T::eq('passed', $r['result'], (string) $r['reason_detail']);
    $codes = array_column(json_decode($r['limitations'], true), 'code');
    T::true(in_array('newer_archives_ineligible', $codes, true) && in_array('imported_archives_excluded', $codes, true), 'notes recorded');

    // Opting in to warning archives selects it; it is not in the live
    // repository, so the run fails rather than testing an older one.
    $w = runToEnd(enqueue(makePlan($world, ['allow_warning_archives' => 1]), $world['admin'])['run_id']);
    T::eq('failed', $w['result']);
    T::eq('archive_missing', $w['reason_code']);
    T::eq('plan-a-warn', $w['archive_name']);
    foreach ($ids as $id) {
        db()->delete('archives', 'id = ?', [$id]);
    }
});

T::test('missing, absent and stale archives fail with a specific reason', function () use ($world) {
    $ghost = ($world['addArchive'])('main', 'a', 'plan-a-ghost', 5);
    $r = runToEnd(enqueue(makePlan($world, []), $world['admin'])['run_id']);
    T::eq('archive_missing', $r['reason_code']);
    db()->delete('archives', 'id = ?', [$ghost]);

    $planC = db()->insert('backup_plans', ['agent_id' => $world['agent'], 'repository_id' => $world['repos']['main'], 'name' => 'rc-plan-empty', 'directories' => '/']);
    $r = runToEnd(enqueue(makePlan($world, ['backup_plan_id' => $planC]), $world['admin'])['run_id']);
    T::eq('no_eligible_archive', $r['reason_code']);

    $r = runToEnd(enqueue(makePlan($world, ['max_archive_age_hours' => 1]), $world['admin'])['run_id']);
    T::eq('archive_too_old', $r['reason_code']);
    T::eq('skipped', $r['extraction_status']);
});

// =====================================================================
T::section('Extraction and validation');

T::test('awkward names are selected literally and validated', function () use ($world, $f) {
    $paths = ['etc/hostname', 'data/we ird/sp ace:colon.txt', 'data/ünïcode-файл.txt', 'data/-dash.txt', 'data/empty.txt',
        'data/sh:*', 'data/<script>alert(1)<!--x.txt', 'data/mode000.txt', 'data/locked', 'data/dir'];
    $r = runToEnd(enqueue(makePlan($world, ['paths' => $paths, 'sha256' => [$f['hostname_sha256']]]), $world['admin'])['run_id']);
    T::eq('passed', $r['result'], (string) $r['reason_detail']);
    $p = probes($r);
    T::eq(1, $p['data/sh:*']['matched_items'], 'sh:* is literal');
    T::eq('match', $p['etc/hostname']['sha256_status']);
    T::eq('fingerprint_only', $p['data/empty.txt']['sha256_status']);
    T::eq(hash('sha256', ''), $p['data/empty.txt']['sha256_actual']);
    T::eq(2, $p['data/locked']['matched_items'], '000 directory and its file');
    T::eq('succeeded', $r['cleanup_status']);
    T::eq([], stagingDirs(), 'staging removed');
});

T::test('hash mismatch, missing path and hash on a directory fail', function () use ($world) {
    $r = runToEnd(enqueue(makePlan($world, ['paths' => ['etc/hostname'], 'sha256' => [str_repeat('0', 64)]]), $world['admin'])['run_id']);
    T::eq('hash_mismatch', $r['reason_code']);
    T::eq('failed', $r['validation_status']);
    $r = runToEnd(enqueue(makePlan($world, ['paths' => ['etc/hostname', 'no/such/file']]), $world['admin'])['run_id']);
    T::eq('probe_missing', $r['reason_code']);
    T::eq('skipped', $r['extraction_status'], 'nothing extracted');
    $r = runToEnd(enqueue(makePlan($world, ['paths' => ['data/dir'], 'sha256' => [str_repeat('a', 64)]]), $world['admin'])['run_id']);
    T::eq('probe_type_mismatch', $r['reason_code']);
});

T::test('full archive: links, hard links, sparse, FIFO and empty directories', function () use ($world) {
    $r = runToEnd(enqueue(makePlan($world, ['repository_id' => $world['repos']['full'], 'backup_plan_id' => $world['plans']['full'], 'mode' => 'full', 'full_max_gib' => 1]), $world['admin'])['run_id']);
    T::eq('passed', $r['result'], (string) $r['reason_detail']);
    $inv = json_decode($r['probe_summary'], true)['inventory'];
    T::eq(2, $inv['symlinks']);
    T::eq(1, $inv['hardlinks']);
    T::eq(1, $inv['fifos']);
    T::eq(0, $inv['special']);
    T::true($inv['bytes'] > 8 * 1048576, 'logical size counts the sparse file');
});

T::test('device nodes cannot be verified: warnings, never a strict pass', function () use ($world) {
    $r = runToEnd(enqueue(makePlan($world, ['repository_id' => $world['repos']['dev'], 'backup_plan_id' => $world['plans']['dev'], 'mode' => 'full', 'full_max_gib' => 1]), $world['admin'])['run_id']);
    T::eq('warning', $r['result']);
    $codes = array_column(json_decode($r['warnings'], true), 'code');
    T::true(in_array('special_files_unverified', $codes, true), 'special files warning');
    T::true(in_array('names_not_verified', $codes, true), 'non-UTF-8 name reported, not passed');
    T::eq(1, (int) $r['borg_exit_code']);
    T::eq(1, (int) job($r['backup_job_id'])['had_warnings'], 'job marked with warnings');
    T::eq(null, db()->fetchOne("SELECT last_strict_success_at FROM restore_check_plans WHERE id = ?", [$r['plan_id']])['last_strict_success_at']);
});

T::test('selected paths never widen to the whole archive', function () use ($world) {
    $r = runToEnd(enqueue(makePlan($world, ['repository_id' => $world['repos']['full'], 'backup_plan_id' => $world['plans']['full'], 'paths' => ['docs/readme.txt']]), $world['admin'])['run_id']);
    T::eq(1, (int) $r['expected_files']);
});

// =====================================================================
T::section('Safety');

T::test('an archive that writes through a symlink is refused; outside file unchanged', function () use ($world, $f) {
    $before = hash_file('sha256', $f['sentinel']);
    $r = runToEnd(enqueue(makePlan($world, ['repository_id' => $world['repos']['evil'], 'backup_plan_id' => $world['plans']['evil'], 'mode' => 'full', 'full_max_gib' => 1]), $world['admin'])['run_id']);
    T::eq('unsafe_archive_layout', $r['reason_code']);
    T::eq('skipped', $r['extraction_status']);
    T::eq($before, hash_file('sha256', $f['sentinel']));
    T::eq(1, count(scandir(dirname($f['sentinel']))) - 2, 'nothing written next to the sentinel');
});

T::test('the same escape under a name that is not valid UTF-8 is refused too', function () use ($world, $f) {
    // Unit: raw bytes map one-to-one; real surrogate pairs and escaped
    // backslashes are left alone.
    [$i, $u] = RestoreCheckRunner::decodeItem('{"path":"a\\udcffb/c"}');
    T::eq(["path" => "a\u{e0ff}b/c"], $i);
    T::eq(true, $u);
    [$i, $u] = RestoreCheckRunner::decodeItem('{"path":"\\ud83d\\ude00"}');
    T::eq(["path" => "\u{1F600}"], $i);
    T::eq(false, $u);
    $before = hash_file('sha256', $f['sentinel']);
    $r = runToEnd(enqueue(makePlan($world, ['repository_id' => $world['repos']['evil2'], 'backup_plan_id' => $world['plans']['evil2'], 'mode' => 'full', 'full_max_gib' => 1]), $world['admin'])['run_id']);
    T::eq('unsafe_archive_layout', $r['reason_code'], (string) $r['reason_detail']);
    T::eq('skipped', $r['extraction_status']);
    T::eq($before, hash_file('sha256', $f['sentinel']));
});

T::test('helper refuses malformed tokens, patterns, targets and owners', function () use ($f) {
    $h = 'echo pw | /usr/local/bin/bbs-ssh-helper restore-check ';
    $tok = bin2hex(random_bytes(16));
    [$rc] = sh("/usr/local/bin/bbs-ssh-helper restore-check prepare {$tok} {$f['local_user']}");
    T::eq(0, $rc, 'prepare');
    $repo = "{$f['local_home']}/repo-main";
    $cases = [
        'token traversal' => "borg ../../etc {$f['local_user']} items 10 10 {$repo}::x",
        'non-pp pattern' => "borg {$tok} {$f['local_user']} items 10 10 {$repo}::plan-a-2026-10-02 'sh:*'",
        'repo outside storage' => "borg {$tok} {$f['local_user']} items 10 10 /etc::x",
        'option-like target' => "borg {$tok} {$f['local_user']} items 10 10 --rsh=evil",
        'wrong owner' => "borg {$tok} bbs-someoneelse items 10 10 {$repo}::plan-a-2026-10-02",
        'unknown op' => "borg {$tok} {$f['local_user']} delete 10 10 {$repo}::plan-a-2026-10-02",
        'root owner' => "prepare " . bin2hex(random_bytes(16)) . " root",
    ];
    foreach ($cases as $label => $args) {
        [$rc, $out] = sh($h . $args);
        T::true($rc !== 0, "{$label} accepted: {$out}");
    }
    sh("/usr/local/bin/bbs-ssh-helper restore-check cleanup {$tok}");
});

T::test('cleanup touches only its own run and never follows links', function () use ($f) {
    $tok = bin2hex(random_bytes(16));
    sh("/usr/local/bin/bbs-ssh-helper restore-check prepare {$tok} {$f['local_user']}");
    $data = "/var/bbs/restore-checks/{$tok}/data";
    symlink(dirname($f['sentinel']), "{$data}/link-out");
    file_put_contents("{$data}/.bbs-restore-check", "spoof");
    [$rc, $out] = sh("/usr/local/bin/bbs-ssh-helper restore-check cleanup {$tok}");
    T::eq(0, $rc, $out);
    T::true(file_exists($f['sentinel']), 'link target survived');
    T::true(!file_exists("/var/bbs/restore-checks/{$tok}"), 'run removed');

    // A directory without a root-owned marker is not ours to delete.
    $fake = bin2hex(random_bytes(16));
    mkdir("/var/bbs/restore-checks/{$fake}");
    file_put_contents("/var/bbs/restore-checks/{$fake}/keep", 'x');
    [$rc] = sh("/usr/local/bin/bbs-ssh-helper restore-check cleanup {$fake}");
    T::true($rc !== 0, 'cleanup without marker');
    file_put_contents("/var/bbs/restore-checks/{$fake}/.bbs-restore-check", "{$fake} www-data 0\n");
    chown("/var/bbs/restore-checks/{$fake}/.bbs-restore-check", 'www-data');
    [$rc] = sh("/usr/local/bin/bbs-ssh-helper restore-check cleanup {$fake}");
    T::true($rc !== 0, 'cleanup with a marker not owned by root');
    T::true(file_exists("/var/bbs/restore-checks/{$fake}/keep"), 'foreign directory untouched');
    sh("rm -rf /var/bbs/restore-checks/{$fake}");
});

// =====================================================================
T::section('Lifecycle');

T::test('cancel during a silent lock wait stops borg and cleans up', function () use ($world, $f) {
    holdLock("{$f['local_home']}/repo-main", 60);
    $e = enqueue(makePlan($world, []), $world['admin']);
    promote();
    startWorker($e['job_id'], true);
    waitFor(fn() => in_array(run($e['run_id'])['phase'], ['selecting', 'listing'], true), 30, 'borg to start');
    sleep(3);
    db()->query("UPDATE backup_jobs SET status='cancelled', completed_at=NOW(), error_log='Cancelled by user' WHERE id = ?", [$e['job_id']]);
    waitFor(fn() => run($e['run_id'])['result'] !== null, 30, 'run to finish');
    $r = run($e['run_id']);
    T::eq('cancelled', $r['result']);
    T::eq('succeeded', $r['cleanup_status']);
    T::eq('cancelled', job($e['job_id'])['status']);
    [, $ps] = sh("for p in /proc/[0-9]*; do readlink \$p/cwd; done | grep -c /var/bbs/restore-checks/ || true");
    T::eq('0', trim($ps), 'no process left in staging');
    sh('pkill -f "borg with-lock"');
});

T::test('a held repository lock is waited for, bounded, and never broken', function () use ($world, $f) {
    holdLock("{$f['local_home']}/repo-main", 90);
    $e = enqueue(makePlan($world, ['run_timeout_minutes' => 5]), $world['admin']);
    // Shorten this run only: lock wait is a quarter of the remaining time.
    $snap = json_decode(run($e['run_id'])['config_snapshot'], true);
    $snap['run_timeout_minutes'] = 1;
    db()->update('restore_check_runs', ['config_snapshot' => json_encode($snap)], 'id = ?', [$e['run_id']]);
    $r = runToEnd($e['run_id'], 120);
    T::eq('failed', $r['result']);
    T::true(in_array($r['reason_code'], ['repository_unavailable', 'timeout'], true), (string) $r['reason_code']);
    [$rc] = sh('pgrep -f "borg with-lock"');
    T::eq(0, $rc, 'the lock holder is still running: nothing broke its lock');
    sh('pkill -f "borg with-lock"');
});

T::test('the helper enforces its own time limit', function () use ($f) {
    holdLock("{$f['local_home']}/repo-main", 30);
    $tok = bin2hex(random_bytes(16));
    sh("/usr/local/bin/bbs-ssh-helper restore-check prepare {$tok} {$f['local_user']}");
    $start = time();
    [$rc] = sh("echo " . escapeshellarg($f['pass_local']) . " | /usr/local/bin/bbs-ssh-helper restore-check borg {$tok} {$f['local_user']} items 25 3 {$f['local_home']}/repo-main::plan-a-2026-10-02");
    T::eq(124, $rc, 'timeout exit code');
    T::true(time() - $start < 20, 'stopped near its limit');
    sh("/usr/local/bin/bbs-ssh-helper restore-check cleanup {$tok}");
    sh('pkill -f "borg with-lock"');
});

T::test('a worker that dies is detected; its run fails and its staging is removed', function () use ($world, $f) {
    holdLock("{$f['local_home']}/repo-main", 120);
    $e = enqueue(makePlan($world, []), $world['admin']);
    promote();
    startWorker($e['job_id'], true);
    waitFor(fn() => run($e['run_id'])['staging_created'] == 1 && in_array(run($e['run_id'])['phase'], ['selecting', 'listing'], true), 30, 'worker to start borg');
    $pid = (int) job($e['job_id'])['worker_pid'];
    posix_kill($pid, 9);
    // The orphan sweep waits two minutes before judging a worker dead.
    db()->query("UPDATE backup_jobs SET started_at = DATE_SUB(NOW(), INTERVAL 5 MINUTE) WHERE id = ?", [$e['job_id']]);
    sh("su -s /bin/sh www-data -c 'cd /var/www/bbs && php scheduler.php' > /var/bbs/tmp/rc-pass.log 2>&1");
    $r = run($e['run_id']);
    T::eq('failed', $r['result']);
    T::eq('worker_lost', $r['reason_code']);
    T::eq('succeeded', $r['cleanup_status'], (string) $r['cleanup_detail']);
    T::eq([], stagingDirs());
    sh('pkill -f "borg with-lock"');
});

T::test('a queued run past its deadline expires', function () use ($world) {
    $e = enqueue(makePlan($world, []), $world['admin']);
    db()->query("UPDATE backup_jobs SET queued_at = DATE_SUB(NOW(), INTERVAL 2 DAY) WHERE id = ?", [$e['job_id']]);
    (new RestoreCheckRunner())->reconcile();
    T::eq('queue_timeout', run($e['run_id'])['reason_code']);
    T::eq('cancelled', job($e['job_id'])['status']);
});

T::test('a job that ended without evidence is never a pass', function () use ($world) {
    $e = enqueue(makePlan($world, []), $world['admin']);
    db()->query("UPDATE backup_jobs SET status = 'completed' WHERE id = ?", [$e['job_id']]);
    (new RestoreCheckRunner())->reconcile();
    $r = run($e['run_id']);
    T::eq('failed', $r['result']);
    T::eq('incomplete_evidence', $r['reason_code']);
});

T::test('access revoked between queueing and running fails the run', function () use ($world) {
    $u = makeUser('rc-revoked', 'user', ['restore'], $world['agent']);
    $e = enqueue(makePlan($world, []), $u);
    db()->delete('user_permissions', 'user_id = ?', [$u]);
    $r = runToEnd($e['run_id']);
    T::eq('unauthorized', $r['reason_code']);
});

// =====================================================================
T::section('Resources');

T::test('space reserve, size and item limits stop the run before extraction', function () use ($world) {
    $r = runToEnd(enqueue(makePlan($world, ['min_free_gib' => 1000000]), $world['admin'])['run_id']);
    T::eq('insufficient_space', $r['reason_code']);
    $r = runToEnd(enqueue(makePlan($world, ['repository_id' => $world['repos']['full'], 'backup_plan_id' => $world['plans']['full'], 'paths' => ['docs'], 'max_gib' => 0.000001]), $world['admin'])['run_id']);
    T::eq('limit_exceeded', $r['reason_code']);
    $r = runToEnd(enqueue(makePlan($world, ['paths' => ['data/dir'], 'max_files' => 1]), $world['admin'])['run_id']);
    T::eq('limit_exceeded', $r['reason_code']);
    T::eq('skipped', $r['extraction_status']);
});

T::test('a cleanup that cannot finish is reported, retried a bounded number of times', function () use ($world) {
    $id = makePlan($world, []);
    $tok = bin2hex(random_bytes(16));
    mkdir("/var/bbs/restore-checks/{$tok}");           // no marker: the helper refuses
    $runId = db()->insert('restore_check_runs', [
        'run_token' => $tok, 'plan_id' => $id, 'agent_id' => $world['agent'], 'repository_id' => $world['repos']['main'],
        'trigger_type' => 'manual', 'config_revision' => 1, 'config_snapshot' => '{}', 'plan_name' => 'rc cleanup',
        'phase' => 'finished', 'result' => 'passed', 'staging_created' => 1, 'cleanup_status' => 'pending', 'finished_at' => db()->now(),
    ]);
    for ($i = 0; $i < 5; $i++) {
        (new RestoreCheckRunner())->reconcile();
    }
    $r = run($runId);
    T::eq('failed', $r['cleanup_status']);
    T::eq(3, (int) $r['cleanup_attempts'], 'bounded retries');
    T::eq('failed', $r['result'], 'a pass with data left behind is downgraded');
    T::eq('cleanup_failed', $r['reason_code']);
    T::true((bool) db()->fetchOne("SELECT id FROM notifications WHERE type = 'restore_check_cleanup_failed' AND reference_id = ?", [$id]), 'notified');
    rmdir("/var/bbs/restore-checks/{$tok}");
});

// =====================================================================
T::section('Remote SSH repositories and credentials');

T::test('remote SSH repository is extracted on the server', function () use ($world) {
    $r = runToEnd(enqueue(makePlan($world, ['repository_id' => $world['repos']['remote'], 'backup_plan_id' => $world['plans']['remote'], 'paths' => ['etc/hostname', 'data/dir']]), $world['admin'])['run_id']);
    T::eq('passed', $r['result'], (string) $r['reason_detail']);
    T::eq([], stagingDirs());
});

T::test('remote authentication failure fails cleanly', function () use ($world, $f) {
    db()->update('remote_ssh_configs', ['ssh_private_key_encrypted' => \BBS\Services\Encryption::encrypt(file_get_contents($f['wrong_key']))], 'id = ?', [$world['ssh_config']]);
    $r = runToEnd(enqueue(makePlan($world, ['repository_id' => $world['repos']['remote'], 'backup_plan_id' => $world['plans']['remote']]), $world['admin'])['run_id']);
    db()->update('remote_ssh_configs', ['ssh_private_key_encrypted' => \BBS\Services\Encryption::encrypt(file_get_contents($f['remote_key']))], 'id = ?', [$world['ssh_config']]);
    T::eq('failed', $r['result']);
    T::eq('repository_unavailable', $r['reason_code']);
    T::true(!str_contains((string) $r['reason_detail'] . $r['diagnostic'], 'PRIVATE KEY'), 'no key material');
});

T::test('no passphrase or key in argv, evidence, logs or job rows', function () use ($world, $f) {
    // Watch every command line while a run waits on a held lock.
    holdLock("{$f['local_home']}/repo-main", 20);
    $e = enqueue(makePlan($world, []), $world['admin']);
    promote();
    T::eq('sent', job($e['job_id'])['status'], 'promoted');
    startWorker($e['job_id'], true);
    $seen = '';
    for ($i = 0; $i < 30; $i++) {
        // The processes of this run: the worker, the helper it starts, and
        // anything working inside the run's staging directory.
        foreach (glob('/proc/[0-9]*') as $proc) {
            $line = str_replace("\0", ' ', (string) @file_get_contents("{$proc}/cmdline"));
            $cwd = (string) @readlink("{$proc}/cwd");
            if (str_contains($line, 'restore-check') || str_contains($line, '--job=' . $e['job_id'])
                || str_starts_with($cwd, '/var/bbs/restore-checks/')) {
                $seen .= $line . "\n";
            }
        }
        usleep(300000);
    }
    sh('pkill -f "borg with-lock"');
    waitFor(fn() => run($e['run_id'])['result'] !== null, 120, 'run');
    T::true(str_contains($seen, 'restore-check borg'), 'the helper was observed');
    $secrets = [$f['pass_local'], $f['pass_remote'], trim(explode("\n", file_get_contents($f['remote_key']))[1])];
    $sources = ['process command lines' => $seen, 'worker log' => (string) @file_get_contents('/var/bbs/tmp/rc-worker.log')];
    foreach (['restore_check_runs', 'restore_check_plans', 'server_log', 'backup_jobs', 'notifications'] as $t) {
        $sources["table {$t}"] = json_encode(db()->fetchAll("SELECT * FROM {$t}"));
    }
    foreach ($sources as $where => $text) {
        foreach ($secrets as $s) {
            T::true(!str_contains($text, $s), 'secret ' . substr($s, 0, 4) . '… found in ' . $where);
        }
    }
});

// =====================================================================
T::section('Evidence');

T::test('report is canonical JSON matching its digest', function () use ($world) {
    $r = runToEnd(enqueue(makePlan($world, []), $world['admin'])['run_id']);
    T::eq(hash('sha256', $r['report_json']), $r['report_digest']);
    $rep = json_decode($r['report_json'], true);
    T::eq(1, $rep['schema_version']);
    T::eq(true, $rep['result']['strict_pass']);
    T::eq(false, $rep['scope']['application_recovery_tested']);
    T::eq($r['report_json'], RestoreCheckReport::canonicalJson($rep), 'stable serialisation');
    T::true(str_contains($rep['timing']['note'], 'not a proven recovery time objective'), 'RTO caveat');
});

T::test('evidence survives plan, job and archive deletion; retention purges old runs', function () use ($world) {
    $id = makePlan($world, []);
    $r = runToEnd(enqueue($id, $world['admin'])['run_id']);
    db()->delete('backup_jobs', 'id = ?', [$r['backup_job_id']]);
    db()->delete('restore_check_plans', 'id = ?', [$id]);
    $after = run($r['id']);
    T::eq(null, $after['plan_id']);
    T::eq(null, $after['backup_job_id']);
    T::eq($r['report_digest'], $after['report_digest']);
    db()->update('restore_check_runs', ['finished_at' => gmdate('Y-m-d H:i:s', time() - 400 * 86400)], 'id = ?', [$r['id']]);
    $kept = runToEnd(enqueue(makePlan($world, []), $world['admin'])['run_id']);
    (new RestoreCheckRunner())->purgeEvidence();
    T::eq(null, db()->fetchOne("SELECT id FROM restore_check_runs WHERE id = ?", [$r['id']]), 'old run purged');
    T::true((bool) run($kept['id']), 'recent run kept');
});

T::test('overdue alerts deduplicate and a strict pass resolves them', function () use ($world) {
    $id = makePlan($world, ['overdue_hours' => 1]);
    db()->update('restore_check_plans', ['enabled' => 1, 'enabled_at' => gmdate('Y-m-d H:i:s', time() - 7200), 'next_due_at' => gmdate('Y-m-d H:i:s', time() + 86400)], 'id = ?', [$id]);
    (new Plans())->checkOverdue();
    (new Plans())->checkOverdue();
    $n = db()->fetchAll("SELECT * FROM notifications WHERE type = 'restore_check_overdue' AND reference_id = ? AND resolved_at IS NULL", [$id]);
    T::eq(1, count($n), 'one open alert');
    T::eq(2, (int) $n[0]['occurrence_count']);
    runToEnd(enqueue($id, $world['admin'])['run_id']);
    T::eq(0, count(db()->fetchAll("SELECT id FROM notifications WHERE type = 'restore_check_overdue' AND reference_id = ? AND resolved_at IS NULL", [$id])), 'resolved');
    db()->update('restore_check_plans', ['enabled' => 0], 'id = ?', [$id]);
});

T::test('a failed run notifies; the next pass resolves it', function () use ($world) {
    $id = makePlan($world, ['paths' => ['no/such/path']]);
    runToEnd(enqueue($id, $world['admin'])['run_id']);
    T::true((bool) db()->fetchOne("SELECT id FROM notifications WHERE type='restore_check_failed' AND reference_id=? AND resolved_at IS NULL", [$id]), 'failure notified');
    db()->update('restore_check_plans', ['paths' => json_encode([['path' => 'etc/hostname', 'sha256' => null]])], 'id = ?', [$id]);
    runToEnd(enqueue($id, $world['admin'])['run_id']);
    T::eq(null, db()->fetchOne("SELECT id FROM notifications WHERE type='restore_check_failed' AND reference_id=? AND resolved_at IS NULL", [$id]));
});

// =====================================================================
T::section('Web: permissions, CSRF and escaping');

$http = new class {
    private array $jars = [];
    public function login(string $user, string $pass): string
    {
        $jar = tempnam('/tmp', 'rcjar');
        $this->jars[] = $jar;
        $page = $this->get($jar, '/login')['body'];
        preg_match('/name="csrf_token" value="([^"]+)"/', $page, $m);
        $this->post($jar, '/login', ['username' => $user, 'password' => $pass, 'csrf_token' => $m[1] ?? '']);
        return $jar;
    }
    public function get(string $jar, string $path): array
    {
        return $this->req($jar, $path, null);
    }
    public function post(string $jar, string $path, array $data): array
    {
        return $this->req($jar, $path, $data);
    }
    public function csrf(string $jar, string $path): string
    {
        preg_match('/name="csrf_token" value="([^"]+)"/', $this->get($jar, $path)['body'], $m);
        return $m[1] ?? '';
    }
    private function req(string $jar, string $path, ?array $data): array
    {
        $ch = curl_init('http://127.0.0.1' . $path);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => false, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_FOLLOWLOCATION => false]);
        if ($data !== null) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        }
        $body = (string) curl_exec($ch);
        $out = ['code' => curl_getinfo($ch, CURLINFO_RESPONSE_CODE), 'location' => (string) curl_getinfo($ch, CURLINFO_REDIRECT_URL), 'body' => $body];
        curl_close($ch);
        return $out;
    }
};

T::test('permissions are enforced on pages, actions and exports', function () use ($world, $http) {
    $agent = $world['agent'];
    makeUser('rc-operator', 'user', ['restore'], $agent);
    makeUser('rc-viewer', 'user', [], $agent);
    makeUser('rc-outsider', 'user', [], null);
    $planId = makePlan($world, ['paths' => ['data/<script>alert(1)<!--x.txt', 'etc/hostname']]);
    $run = runToEnd(enqueue($planId, $world['admin'])['run_id']);
    $base = "/clients/{$agent}/restore-checks/runs/{$run['id']}";

    $op = $http->login('rc-operator', 'rc-pass-rc-operator');
    T::eq(200, $http->get($op, $base)['code'], 'operator sees evidence');
    $json = $http->get($op, "{$base}/report.json");
    T::eq($run['report_json'], $json['body'], 'JSON export is the stored report');
    $page = $http->get($op, "{$base}/report")['body'];
    T::true(str_contains($page, '&lt;script&gt;alert(1)&lt;!--x.txt'), 'name escaped in the printable report');
    T::true(!str_contains($page, '<script>alert(1)'), 'raw markup absent');
    T::eq(302, $http->get($op, "/clients/{$agent}/restore-checks/new")['code'], 'operator without Manage Plans cannot create');
    $before = (int) db()->fetchOne("SELECT COUNT(*) n FROM restore_check_plans")['n'];
    $csrf = $http->csrf($op, "/clients/{$agent}/restore-checks/{$planId}");
    $http->post($op, "/clients/{$agent}/restore-checks", ['csrf_token' => $csrf, 'name' => 'sneaky', 'repository_id' => $world['repos']['main']]);
    T::eq($before, (int) db()->fetchOne("SELECT COUNT(*) n FROM restore_check_plans")['n'], 'plan not created');
    T::eq(403, $http->post($op, "/clients/{$agent}/restore-checks/{$planId}/run", [])['code'], 'Run now without CSRF');
    $res = $http->post($op, "/clients/{$agent}/restore-checks/{$planId}/run", ['csrf_token' => $csrf, 'idempotency_key' => bin2hex(random_bytes(16))]);
    T::eq(302, $res['code']);
    T::true(str_contains($res['location'], '/restore-checks/runs/'), 'redirects to the new run');

    foreach (['rc-viewer', 'rc-outsider'] as $u) {
        $jar = $http->login($u, 'rc-pass-' . $u);
        T::eq(302, $http->get($jar, $base)['code'], "{$u} redirected from evidence");
        T::true(!str_contains($http->get($jar, "{$base}/report.json")['body'], 'schema_version'), "{$u} cannot export");
        T::true($http->get($jar, '/restore-checks')['code'] !== 200, "{$u} has no admin evidence page");
    }
    $active = db()->fetchOne("SELECT backup_job_id FROM restore_check_runs WHERE plan_id = ? AND result IS NULL", [$planId]);
    if ($active) {
        db()->query("UPDATE backup_jobs SET status='cancelled' WHERE id = ?", [$active['backup_job_id']]);
        (new RestoreCheckRunner())->reconcile();
    }
});

T::test('UI flow: create disabled, enable, run now, read the evidence', function () use ($world, $http, $f) {
    $agent = $world['agent'];
    $jar = $http->login('admin', 'rc-dev-admin-pass');
    $tab = $http->get($jar, "/clients/{$agent}?tab=restore");
    T::eq(200, $tab['code']);
    T::true(str_contains($tab['body'], 'id="restore-checks"'), 'Restore checks section on the Restore tab');
    $form = $http->get($jar, "/clients/{$agent}/restore-checks/new");
    T::eq(200, $form['code']);
    preg_match('/name="csrf_token" value="([^"]+)"/', $form['body'], $m);
    $res = $http->post($jar, "/clients/{$agent}/restore-checks", [
        'csrf_token' => $m[1], 'name' => 'UI flow <b>check</b>', 'repository_id' => $world['repos']['main'], 'scope' => 'plan',
        'backup_plan_id' => $world['plans']['a'], 'mode' => 'paths', 'paths' => ['/etc/hostname', ''], 'sha256' => [$f['hostname_sha256'], ''],
        'frequency' => 'weekly', 'times' => '03:00', 'day_of_week' => ['1'], 'timezone' => 'Europe/Berlin',
        'max_archive_age_hours' => 48, 'queue_deadline_minutes' => 1440, 'run_timeout_minutes' => 120, 'max_files' => 1000,
        'overdue_hours' => 192, 'max_gib' => 1, 'min_free_gib' => 0.01,
    ]);
    T::eq(302, $res['code'], 'created');
    $plan = db()->fetchOne("SELECT * FROM restore_check_plans WHERE name = 'UI flow <b>check</b>'");
    T::eq(0, (int) $plan['enabled'], 'created disabled');
    T::eq('etc/hostname', json_decode($plan['paths'], true)[0]['path']);
    $show = $http->get($jar, "/clients/{$agent}/restore-checks/{$plan['id']}");
    T::eq(200, $show['code']);
    T::true(str_contains($show['body'], 'UI flow &lt;b&gt;check&lt;/b&gt;'), 'plan name escaped');
    T::true(str_contains($show['body'], 'Never run'), 'never-run state shown');
    preg_match('/name="csrf_token" value="([^"]+)"/', $show['body'], $m);
    $http->post($jar, "/clients/{$agent}/restore-checks/{$plan['id']}/enable", ['csrf_token' => $m[1]]);
    $plan = db()->fetchOne("SELECT * FROM restore_check_plans WHERE id = ?", [$plan['id']]);
    T::eq(1, (int) $plan['enabled']);
    T::true($plan['next_due_at'] !== null, 'next run computed');
    $edit = $http->get($jar, "/clients/{$agent}/restore-checks/{$plan['id']}/edit");
    T::eq(200, $edit['code'], 'edit form renders');
    $res = $http->post($jar, "/clients/{$agent}/restore-checks/{$plan['id']}/run", ['csrf_token' => $m[1], 'idempotency_key' => bin2hex(random_bytes(16))]);
    preg_match('#/restore-checks/runs/(\d+)#', $res['location'], $rm);
    $r = runToEnd((int) $rm[1]);
    T::eq('passed', $r['result']);
    T::eq(1, (int) db()->fetchOne("SELECT enabled FROM restore_check_plans WHERE id = ?", [$plan['id']])['enabled'], 'still enabled');
    $page = $http->get($jar, "/clients/{$agent}/restore-checks/runs/{$r['id']}");
    T::eq(200, $page['code']);
    T::true(str_contains($page['body'], 'strict pass') && str_contains($page['body'], 'matches expected'), 'run page shows the outcome');
    T::eq(200, $http->get($jar, "/clients/{$agent}/restore-checks/runs/{$r['id']}/report")['code'], 'printable report');
    $queue = $http->get($jar, "/queue/{$r['backup_job_id']}");
    T::true(str_contains($queue['body'], "restore-checks/runs/{$r['id']}"), 'queue page links the evidence');
    $tab = $http->get($jar, "/clients/{$agent}?tab=restore");
    T::true(str_contains($tab['body'], 'Passed'), 'Restore tab shows the result');
    T::eq(200, $http->get($jar, '/restore-checks')['code'], 'admin evidence page');
    foreach (['/notifications', '/settings?tab=push', '/notification-services', '/queue', '/'] as $p) {
        T::true(in_array($http->get($jar, $p)['code'], [200, 302], true), "{$p} still renders");
    }
});

T::test('admin keeps evidence of a deleted client; its users do not', function () use ($world, $http) {
    $planId = makePlan($world, []);
    $run = runToEnd(enqueue($planId, $world['admin'])['run_id']);
    db()->query("DELETE FROM backup_jobs WHERE agent_id = ?", [$world['agent']]);
    db()->delete('agents', 'id = ?', [$world['agent']]);
    $adminJar = $http->login('admin', 'rc-dev-admin-pass');
    $page = $http->get($adminJar, "/restore-checks/runs/{$run['id']}");
    T::eq(200, $page['code'], 'admin can open it');
    T::true(str_contains($http->get($adminJar, '/restore-checks')['body'], 'deleted'), 'listed as deleted client');
    $op = $http->login('rc-operator', 'rc-pass-rc-operator');
    T::eq(302, $http->get($op, "/clients/{$world['agent']}/restore-checks/runs/{$run['id']}")['code']);
});

// =====================================================================
echo "\n" . T::$pass . ' passed, ' . count(T::$failures) . " failed\n";
exit(T::$failures ? 1 : 0);
