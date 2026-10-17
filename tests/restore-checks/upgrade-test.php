<?php
/**
 * Migration 137 on an existing installation: a scratch database built from
 * the pre-feature schema.sql (passed as argv[1]) with migrations 001-136
 * recorded, some jobs in every state, then the real Migrator. Also replays
 * 137 to prove it is safe to run twice. Run as root in the development
 * container:
 *   php tests/restore-checks/upgrade-test.php /tmp/schema-base.sql
 */

require_once '/var/www/bbs/vendor/autoload.php';

use BBS\Core\Config;

if (getenv('BBS_RC_TEST') !== '1' || posix_getuid() !== 0 || empty($argv[1])) {
    fwrite(STDERR, "usage: BBS_RC_TEST=1 php upgrade-test.php <base-schema.sql>\n");
    exit(2);
}
date_default_timezone_set('UTC');
Config::load();
$user = $_ENV['DB_USER'];
$scratch = 'bbs_rc_upgrade';
$fail = 0;
$check = function (bool $ok, string $what) use (&$fail) {
    echo ($ok ? '  ok    ' : '  FAIL  ') . $what . "\n";
    $fail += $ok ? 0 : 1;
};

$mysql = fn(string $sql) => shell_exec('mysql -N -e ' . escapeshellarg($sql) . ' 2>&1');
$mysql("DROP DATABASE IF EXISTS {$scratch}; CREATE DATABASE {$scratch}; GRANT ALL ON {$scratch}.* TO '{$user}'@'localhost'; FLUSH PRIVILEGES;");
shell_exec("mysql {$scratch} < " . escapeshellarg($argv[1]) . ' 2>&1');

$_ENV['DB_NAME'] = $scratch;
$db = \BBS\Core\Database::getInstance();
$db->query("CREATE TABLE IF NOT EXISTS migrations (id INT AUTO_INCREMENT PRIMARY KEY, filename VARCHAR(255) NOT NULL UNIQUE, executed_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
foreach (array_merge(glob('/var/www/bbs/migrations/*.sql'), glob('/var/www/bbs/migrations/*.php')) as $file) {
    if (!str_starts_with(basename($file), '137_')) {
        $db->query("INSERT IGNORE INTO migrations (filename) VALUES (?)", [basename($file)]);
    }
}

$check(!$db->fetchOne("SHOW TABLES LIKE 'restore_check_plans'"), 'base schema has no restore check tables');
$agent = $db->insert('agents', ['name' => 'upgrade-client']);
$jobs = [];
foreach (['queued', 'sent', 'running', 'completed', 'failed', 'cancelled'] as $status) {
    foreach (['backup', 'restore', 'prune', 'archive_lock'] as $type) {
        $jobs[] = $db->insert('backup_jobs', ['agent_id' => $agent, 'task_type' => $type, 'status' => $status, 'had_warnings' => $status === 'completed' ? 1 : 0]);
    }
}
$before = $db->fetchAll("SELECT id, task_type, status, had_warnings FROM backup_jobs ORDER BY id");

$migrator = new \BBS\Core\Migrator();
$ran = $migrator->run();
$check($migrator->errors === [], 'migration ran without errors' . ($migrator->errors ? ': ' . json_encode($migrator->errors) : ''));
$check(in_array('137_restore_checks.sql', (array) $ran, true) || (bool) $db->fetchOne("SELECT id FROM migrations WHERE filename = '137_restore_checks.sql'"), '137 recorded');
$check((bool) $db->fetchOne("SHOW TABLES LIKE 'restore_check_plans'") && (bool) $db->fetchOne("SHOW TABLES LIKE 'restore_check_runs'"), 'tables created');
$enum = $db->fetchOne("SELECT COLUMN_TYPE t FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'backup_jobs' AND COLUMN_NAME = 'task_type'", [$scratch])['t'];
$check(str_contains($enum, "'restore_check'") && str_contains($enum, "'archive_lock'") && str_contains($enum, "'plugin_post'"), 'task enum extended, existing values kept');
$check($before === $db->fetchAll("SELECT id, task_type, status, had_warnings FROM backup_jobs ORDER BY id"), 'existing jobs unchanged (' . count($before) . ' rows)');
$check(($db->fetchOne("SELECT `value` v FROM settings WHERE `key` = 'restore_check_evidence_days'")['v'] ?? null) === '365', 'settings seeded');

// Replaying it must be harmless (fresh installs replay every migration).
$db->query("DELETE FROM migrations WHERE filename = '137_restore_checks.sql'");
$db->query("UPDATE settings SET `value` = '30' WHERE `key` = 'restore_check_evidence_days'");
$m2 = new \BBS\Core\Migrator();
$m2->run();
$check($m2->errors === [], 'replaying 137 raises no errors');
$check($db->fetchOne("SELECT `value` v FROM settings WHERE `key` = 'restore_check_evidence_days'")['v'] === '30', 'replay keeps an operator\'s setting');

// The schema.sql shipped with the feature matches what the migration builds.
$cols = fn(string $db_, string $t) => array_column((new \PDO("mysql:host={$_ENV['DB_HOST']};dbname={$db_}", $_ENV['DB_USER'], $_ENV['DB_PASS']))
    ->query("SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = '{$db_}' AND TABLE_NAME = '{$t}' ORDER BY ORDINAL_POSITION")
    ->fetchAll(\PDO::FETCH_ASSOC), null);
foreach (['restore_check_plans', 'restore_check_runs'] as $t) {
    $check($cols($scratch, $t) === $cols('bbs', $t), "{$t}: migrated columns match a fresh install");
}

$mysql("DROP DATABASE {$scratch};");
echo $fail ? "\n{$fail} failed\n" : "\nall passed\n";
exit($fail ? 1 : 0);
