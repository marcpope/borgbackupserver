<?php
/**
 * Migration 134: repair data changed when 2.98.5 to 2.98.7 replayed old
 * migrations on Docker installs (#532)
 *
 * Docker installs from before the container recorded its migrations had
 * every old migration run again by the migration runner in 2.98.5, 2.98.6
 * or 2.98.7. Most replays did nothing, but some changed live data:
 *
 *   007  added the default backup templates again (duplicates)
 *   014  set every schedule's timezone to America/New_York
 *   033  gave every client owner all permissions on all their clients
 *   065  added a duplicate "Default" storage location, and one more on every
 *        restart while it kept failing
 *   066  pointed ssh_home_dir at <storage_path>/<id> (#532)
 *   080  reset each user's storage alert level to the global one
 *   107  put every client back on the default client profile
 *
 * A migration was replayed when it was recorded after 2.98.5 shipped but
 * before the one-time catch-up in this release, on an install that already
 * had data. Anything else (bare metal, fresh installs, installs updating
 * straight from 2.98.4 or older) is left alone.
 *
 * Duplicates and the extra permissions are removed outright. Overwritten
 * values are restored from the newest server backup taken before the replay,
 * and only where the value is still the one the replay wrote.
 */

use BBS\Services\SchedulerService;

// Exact duplicate backup templates. Migration 007 inserts the default
// templates again whenever it runs after schema.sql already did, which
// happened on every fresh Docker install since June and in the replay.
// A copy is folded into the oldest identical template.
$dupTemplates = $db->fetchAll(
    "SELECT d.id, MIN(o.id) AS original_id
     FROM backup_templates d
     JOIN backup_templates o
       ON o.id < d.id AND o.name = d.name
      AND o.directories = d.directories
      AND COALESCE(o.description, '') = COALESCE(d.description, '')
      AND COALESCE(o.excludes, '') = COALESCE(d.excludes, '')
      AND COALESCE(o.advanced_options, '') = COALESCE(d.advanced_options, '')
     GROUP BY d.id"
);
foreach ($dupTemplates as $dup) {
    $db->query("UPDATE client_profiles SET template_id = ? WHERE template_id = ?", [(int) $dup['original_id'], (int) $dup['id']]);
    $db->delete('backup_templates', 'id = ?', [(int) $dup['id']]);
}
if ($dupTemplates) {
    echo '  Removed ' . count($dupTemplates) . " duplicate backup template(s).\n";
}

$tracked = $db->fetchOne("SELECT `value` FROM settings WHERE `key` = 'migrations_docker_tracked'");
if (!$tracked || !preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string) $tracked['value'])) {
    echo "  Not an earlier Docker install; nothing to repair.\n";
    return;
}
$catchUp = $tracked['value'];

$log = function (string $level, string $message) use ($db): void {
    $db->insert('server_log', ['level' => $level, 'message' => $message]);
    echo "  {$message}\n";
};

// When migration $file was replayed, or null when it was not.
$replayedAt = function (string $file) use ($db, $catchUp): ?string {
    $row = $db->fetchOne("SELECT executed_at FROM migrations WHERE filename = ?", [$file]);
    if (!$row || $row['executed_at'] < '2026-10-07 00:00:00' || $row['executed_at'] >= $catchUp) {
        return null;
    }
    $t = $row['executed_at'];
    // Data from before the replay: on a fresh install the migrations run
    // seconds after the schema is loaded, on an empty database, and there is
    // nothing to repair.
    $older = $db->fetchOne("SELECT 1 FROM users WHERE created_at < DATE_SUB(?, INTERVAL 5 MINUTE) LIMIT 1", [$t])
        ?: $db->fetchOne("SELECT 1 FROM agents WHERE created_at < DATE_SUB(?, INTERVAL 5 MINUTE) LIMIT 1", [$t]);
    return $older ? $t : null;
};

// When the replay started: the earliest old migration recorded in that
// window. Migrations that kept failing were never recorded by the replay,
// so their side effects (065's duplicate storage locations) are dated
// from this instead.
$startRow = $db->fetchOne(
    "SELECT MIN(executed_at) AS t FROM migrations
     WHERE filename < '128' AND executed_at >= '2026-10-07 00:00:00' AND executed_at < ?",
    [$catchUp]
);
$replayStart = !empty($startRow['t']) && ($db->fetchOne(
    "SELECT 1 FROM users WHERE created_at < DATE_SUB(?, INTERVAL 5 MINUTE) LIMIT 1", [$startRow['t']]
) ?: $db->fetchOne(
    "SELECT 1 FROM agents WHERE created_at < DATE_SUB(?, INTERVAL 5 MINUTE) LIMIT 1", [$startRow['t']]
)) ? $startRow['t'] : null;
if ($replayStart === null) {
    echo "  No replayed migrations found; nothing to repair.\n";
    return;
}

// 033: permissions the replay granted. INSERT IGNORE only added rows that
// were missing, all with the replay's timestamp.
if ($t = $replayedAt('033_user_permissions.sql')) {
    $n = $db->query(
        "DELETE FROM user_permissions
         WHERE agent_id IS NULL
           AND created_at BETWEEN DATE_SUB(?, INTERVAL 10 MINUTE) AND DATE_ADD(?, INTERVAL 10 MINUTE)",
        [$t, $t]
    )->rowCount();
    if ($n > 0) {
        $log('warning', "Removed {$n} permission(s) that an update in 2.98.5 to 2.98.7 granted by mistake (all permissions on all clients for every client owner).");
    }
}

// 065: duplicate "Default" storage locations, from the replay and from every
// restart after it.
if ($t = $replayStart) {
    $dups = $db->fetchAll(
        "SELECT d.id, MIN(o.id) AS original_id
         FROM storage_locations d
         JOIN storage_locations o ON o.path = d.path AND o.id < d.id
         WHERE d.label = 'Default' AND d.created_at >= DATE_SUB(?, INTERVAL 10 MINUTE)
         GROUP BY d.id",
        [$t]
    );
    $removed = 0;
    foreach ($dups as $dup) {
        $dupId = (int) $dup['id'];
        $origId = (int) $dup['original_id'];
        $db->query("UPDATE repositories SET storage_location_id = ? WHERE storage_location_id = ?", [$origId, $dupId]);
        // Offsite sync configs that copy to a storage location name it by id
        foreach ($db->fetchAll("SELECT id, config FROM plugin_configs WHERE config LIKE '%storage_location_id%'") as $pc) {
            $cfg = json_decode((string) $pc['config'], true);
            if (is_array($cfg) && (int) ($cfg['storage_location_id'] ?? 0) === $dupId) {
                $cfg['storage_location_id'] = $origId;
                $db->update('plugin_configs', ['config' => json_encode($cfg)], 'id = ?', [$pc['id']]);
            }
        }
        $db->delete('storage_locations', 'id = ?', [$dupId]);
        $removed++;
    }
    if ($removed > 0) {
        $log('warning', "Removed {$removed} duplicate \"Default\" storage location(s) that an update in 2.98.5 to 2.98.7 created.");
    }
}

// 018: the replay added a second foreign key for the same column.
$fks = $db->fetchAll(
    "SELECT CONSTRAINT_NAME AS name FROM information_schema.KEY_COLUMN_USAGE
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'backup_plan_plugins'
       AND COLUMN_NAME = 'plugin_config_id' AND REFERENCED_TABLE_NAME = 'plugin_configs'"
);
if (count($fks) > 1 && in_array('fk_bpp_plugin_config', array_column($fks, 'name'), true)) {
    $db->getPdo()->exec("ALTER TABLE backup_plan_plugins DROP FOREIGN KEY fk_bpp_plugin_config");
    echo "  Removed a duplicate foreign key on backup_plan_plugins.\n";
}

// 007: templates the replay added again. A user may have edited the
// original since, so these are matched by name and by when they were added,
// not by content.
$dups = $db->fetchAll(
    "SELECT d.id, MIN(o.id) AS original_id
     FROM backup_templates d
     JOIN backup_templates o ON o.name = d.name AND o.id < d.id
     WHERE d.created_at >= DATE_SUB(?, INTERVAL 10 MINUTE)
     GROUP BY d.id",
    [$replayStart]
);
foreach ($dups as $dup) {
    $db->query("UPDATE client_profiles SET template_id = ? WHERE template_id = ?", [(int) $dup['original_id'], (int) $dup['id']]);
    $db->delete('backup_templates', 'id = ?', [(int) $dup['id']]);
}
if ($dups) {
    $log('info', 'Removed ' . count($dups) . ' backup template(s) that an update in 2.98.5 to 2.98.7 added again.');
}

// 014, 066, 080, 107: overwritten values, restored from a server backup.
$valueFixes = array_filter([
    'schedules' => $replayedAt('014_schedule_timezone.sql'),
    'ssh_home_dir' => $replayedAt('066_ssh_home_dir.sql'),
    'storage_alert' => $replayedAt('080_per_user_storage_alerts.sql'),
    'client_profile' => $replayedAt('107_client_profiles.sql'),
]);
if (!$valueFixes) {
    echo "  No overwritten settings to restore.\n";
    return;
}
$replayTime = strtotime(min($valueFixes));

// The newest server backup taken before the replay
$backup = null;
foreach (glob('/var/bbs/backups/bbs-backup-*.tar.gz') ?: [] as $file) {
    $mtime = @filemtime($file);
    if ($mtime !== false && $mtime < $replayTime && ($backup === null || $mtime > filemtime($backup))) {
        $backup = $file;
    }
}

$whatToCheck = [
    'schedules' => 'schedule timezones (they were set to America/New_York)',
    'ssh_home_dir' => 'client SSH home folders',
    'storage_alert' => "users' storage alert levels",
    'client_profile' => "clients' profiles (they were set to the default profile)",
];
if ($backup === null) {
    $items = implode('; ', array_intersect_key($whatToCheck, $valueFixes));
    $log('warning', "An update in 2.98.5 to 2.98.7 overwrote some settings, and no server backup from before it was found to restore them from. Please check: {$items}.");
    return;
}

// Load the tables needed from the backup's dump into temporary tables
$tmp = tempnam(sys_get_temp_dir(), 'bbs-repair-');
exec('tar -xzOf ' . escapeshellarg($backup) . ' ./dump.sql > ' . escapeshellarg($tmp) . ' 2>/dev/null', $out, $rc);
if ($rc !== 0 || filesize($tmp) === 0) {
    exec('tar -xzOf ' . escapeshellarg($backup) . ' dump.sql > ' . escapeshellarg($tmp) . ' 2>/dev/null', $out, $rc);
}
if ($rc !== 0 || filesize($tmp) === 0) {
    @unlink($tmp);
    $items = implode('; ', array_intersect_key($whatToCheck, $valueFixes));
    $log('warning', 'Could not read the server backup ' . basename($backup) . " to restore settings an update in 2.98.5 to 2.98.7 overwrote. Please check: {$items}.");
    return;
}

$pdo = $db->getPdo();
$tables = ['schedules', 'agents', 'users'];
$loaded = [];
foreach ($tables as $table) {
    $pdo->exec("DROP TABLE IF EXISTS `_bbs_pre_{$table}`");
}
$fh = fopen($tmp, 'r');
$create = null;
$createFor = null;
$insert = null;
$insertFor = null;
while (($line = fgets($fh)) !== false) {
    // An INSERT runs until the line that ends with ";": MySQL writes it on
    // one line, MariaDB puts each row on its own line.
    if ($insertFor !== null) {
        $insert .= $line;
        if (str_ends_with(rtrim($line), ';')) {
            $pdo->exec(rtrim($insert, "\n;"));
            $insert = $insertFor = null;
        }
        continue;
    }
    if ($createFor === null && preg_match('/^CREATE TABLE `(schedules|agents|users)` \(/', $line, $m)) {
        $createFor = $m[1];
        $create = ["CREATE TABLE `_bbs_pre_{$createFor}` ("];
        continue;
    }
    if ($createFor !== null) {
        if (preg_match('/^\)/', $line)) {
            // Columns and keys only: no foreign keys to other tables
            $body = array_values(array_filter($create, fn($l) => !preg_match('/\b(CONSTRAINT|FOREIGN KEY)\b/', $l)));
            $body[count($body) - 1] = rtrim($body[count($body) - 1], ", \n");
            $pdo->exec(implode("\n", $body) . "\n) ENGINE=InnoDB");
            $loaded[$createFor] = true;
            $createFor = null;
            continue;
        }
        $create[] = rtrim($line, "\n");
        continue;
    }
    if (preg_match('/^INSERT INTO `(schedules|agents|users)` VALUES\b/', $line, $m) && isset($loaded[$m[1]])) {
        $insert = preg_replace('/^INSERT INTO `' . $m[1] . '`/', "INSERT INTO `_bbs_pre_{$m[1]}`", $line);
        if (str_ends_with(rtrim($line), ';')) {
            $pdo->exec(rtrim($insert, "\n;"));
            $insert = null;
        } else {
            $insertFor = $m[1];
        }
    }
}
fclose($fh);
@unlink($tmp);

$preColumns = function (string $table) use ($db, $loaded): array {
    return isset($loaded[$table]) ? array_column($db->fetchAll("SHOW COLUMNS FROM `_bbs_pre_{$table}`"), 'Field') : [];
};
$restored = [];

if (isset($valueFixes['schedules']) && in_array('timezone', $preColumns('schedules'), true)) {
    $ids = array_column($db->fetchAll(
        "SELECT s.id FROM schedules s JOIN `_bbs_pre_schedules` p ON p.id = s.id
         WHERE s.timezone = 'America/New_York' AND p.timezone IS NOT NULL AND p.timezone <> 'America/New_York'"
    ), 'id');
    if ($ids) {
        $db->query(
            "UPDATE schedules s JOIN `_bbs_pre_schedules` p ON p.id = s.id SET s.timezone = p.timezone
             WHERE s.id IN (" . implode(',', array_map('intval', $ids)) . ")"
        );
        // Their next run was worked out in the wrong timezone
        $scheduler = new SchedulerService();
        foreach ($db->fetchAll("SELECT * FROM schedules WHERE id IN (" . implode(',', array_map('intval', $ids)) . ")") as $schedule) {
            $next = $scheduler->calculateNextRun($schedule);
            if ($next !== null) {
                $db->update('schedules', ['next_run' => $next], 'id = ?', [$schedule['id']]);
            }
        }
        $restored[] = count($ids) . ' schedule timezone(s)';
    }
}

if (isset($valueFixes['ssh_home_dir']) && in_array('ssh_home_dir', $preColumns('agents'), true)) {
    $storagePath = rtrim((string) ($db->fetchOne("SELECT `value` FROM settings WHERE `key` = 'storage_path'")['value'] ?? ''), '/');
    $n = $db->query(
        "UPDATE agents a JOIN `_bbs_pre_agents` p ON p.id = a.id SET a.ssh_home_dir = p.ssh_home_dir
         WHERE a.ssh_home_dir = CONCAT(?, '/', a.id)
           AND p.ssh_home_dir IS NOT NULL AND p.ssh_home_dir <> '' AND p.ssh_home_dir <> a.ssh_home_dir",
        [$storagePath]
    )->rowCount();
    if ($n > 0) {
        $restored[] = "{$n} client SSH home folder(s)";
    }
}

if (isset($valueFixes['client_profile']) && in_array('client_profile_id', $preColumns('agents'), true)) {
    $default = (int) ($db->fetchOne("SELECT id FROM client_profiles WHERE is_default = 1 ORDER BY id LIMIT 1")['id'] ?? 0);
    if ($default > 0) {
        $n = $db->query(
            "UPDATE agents a
             JOIN `_bbs_pre_agents` p ON p.id = a.id
             JOIN client_profiles cp ON cp.id = p.client_profile_id
             SET a.client_profile_id = p.client_profile_id
             WHERE a.client_profile_id = ? AND p.client_profile_id <> ?",
            [$default, $default]
        )->rowCount();
        if ($n > 0) {
            $restored[] = "{$n} client profile assignment(s)";
        }
    }
}

if (isset($valueFixes['storage_alert']) && in_array('storage_alert_value', $preColumns('users'), true)) {
    $global = (int) ($db->fetchOne("SELECT `value` FROM settings WHERE `key` = 'storage_alert_threshold'")['value'] ?? 0);
    if ($global > 0) {
        $n = $db->query(
            "UPDATE users u JOIN `_bbs_pre_users` p ON p.id = u.id SET u.storage_alert_value = p.storage_alert_value
             WHERE u.storage_alert_value = ? AND p.storage_alert_value <> ?",
            [$global, $global]
        )->rowCount();
        if ($n > 0) {
            $restored[] = "{$n} user storage alert level(s)";
        }
    }
}

foreach ($tables as $table) {
    $pdo->exec("DROP TABLE IF EXISTS `_bbs_pre_{$table}`");
}

$log('warning', $restored
    ? 'Restored settings that an update in 2.98.5 to 2.98.7 overwrote, from the server backup ' . basename($backup) . ': ' . implode(', ', $restored) . '.'
    : 'Checked settings an update in 2.98.5 to 2.98.7 may have overwritten against the server backup ' . basename($backup) . '; nothing needed restoring.');
