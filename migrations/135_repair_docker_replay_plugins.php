<?php
/**
 * Migration 135: more repairs for the 2.98.5 to 2.98.7 migration replay on
 * Docker installs (#532, #535)
 *
 * Migration 134 missed these replayed migrations:
 *
 *   018  made an empty copy of every plan's plugin config, named
 *        "<plugin> - <plan>", and pointed the plan at the copy. Backups then
 *        failed with "Pre-backup plugin failed: 'list' object has no
 *        attribute 'get'" (#535).
 *   032  reset the "Interworx Server" template's folders and excludes
 *   044  switched users who chose the light theme to dark
 *   091  gave client owners access again where an admin had removed it
 *
 * Plans are pointed back at the config they used before, from the newest
 * server backup taken before the replay. Without a backup, a plan is pointed
 * at the client's only other config for that plugin; when there is more than
 * one, the activity log names the plan so the config can be picked by hand.
 * The empty copies are then removed.
 */

$tracked = $db->fetchOne("SELECT `value` FROM settings WHERE `key` = 'migrations_docker_tracked'");
if (!$tracked || !preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string) $tracked['value'])) {
    echo "  Not an earlier Docker install; nothing to repair.\n";
    return;
}
$catchUp = $tracked['value'];

// When the replay started (as in migration 134), on an install with data
// from before it
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

$log = function (string $level, string $message) use ($db): void {
    $db->insert('server_log', ['level' => $level, 'message' => $message]);
    echo "  {$message}\n";
};

// 018's empty copies: added during the replay (it kept running on every
// restart until the catch-up), named "<plugin> - <plan>", with no settings
$junkIds = array_map('intval', array_column($db->fetchAll(
    "SELECT pc.id FROM plugin_configs pc
     JOIN plugins p ON p.id = pc.plugin_id
     WHERE pc.created_at >= DATE_SUB(?, INTERVAL 10 MINUTE) AND pc.created_at < ?
       AND JSON_LENGTH(pc.config) = 0
       AND pc.name LIKE CONCAT(p.name, ' - %')",
    [$replayStart, $catchUp]
), 'id'));
$isJunk = array_flip($junkIds);

// The newest server backup taken before the replay, loaded into temporary
// _bbs_pre_* tables
$tables = ['backup_plan_plugins', 'plugin_configs', 'backup_templates', 'users', 'user_agents', 'agents'];
$pdo = $db->getPdo();
foreach ($tables as $table) {
    $pdo->exec("DROP TABLE IF EXISTS `_bbs_pre_{$table}`");
}
$loaded = [];
$backup = null;
foreach (glob('/var/bbs/backups/bbs-backup-*.tar.gz') ?: [] as $file) {
    $mtime = @filemtime($file);
    if ($mtime !== false && $mtime < strtotime($replayStart) && ($backup === null || $mtime > filemtime($backup))) {
        $backup = $file;
    }
}
if ($backup !== null) {
    $tmp = tempnam(sys_get_temp_dir(), 'bbs-repair-');
    exec('tar -xzOf ' . escapeshellarg($backup) . ' ./dump.sql > ' . escapeshellarg($tmp) . ' 2>/dev/null', $out, $rc);
    if ($rc !== 0 || filesize($tmp) === 0) {
        exec('tar -xzOf ' . escapeshellarg($backup) . ' dump.sql > ' . escapeshellarg($tmp) . ' 2>/dev/null', $out, $rc);
    }
    if ($rc === 0 && filesize($tmp) > 0) {
        $names = implode('|', $tables);
        $fh = fopen($tmp, 'r');
        $create = null;
        $createFor = null;
        $insert = null;
        $insertFor = null;
        while (($line = fgets($fh)) !== false) {
            // An INSERT runs until the line that ends with ";": MySQL writes
            // it on one line, MariaDB puts each row on its own line.
            if ($insertFor !== null) {
                $insert .= $line;
                if (str_ends_with(rtrim($line), ';')) {
                    $pdo->exec(rtrim($insert, "\n;"));
                    $insert = $insertFor = null;
                }
                continue;
            }
            if ($createFor === null && preg_match('/^CREATE TABLE `(' . $names . ')` \(/', $line, $m)) {
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
            if (preg_match('/^INSERT INTO `(' . $names . ')` VALUES\b/', $line, $m) && isset($loaded[$m[1]])) {
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
    }
    @unlink($tmp);
}
$preColumns = function (string $table) use ($db, $loaded): array {
    return isset($loaded[$table]) ? array_column($db->fetchAll("SHOW COLUMNS FROM `_bbs_pre_{$table}`"), 'Field') : [];
};

// 018: point plans back at their own plugin config. A plan whose copy was
// deleted by hand has no config at all now; the backup covers it too.
$repointed = 0;
$unresolved = [];
$planPlugins = $db->fetchAll(
    "SELECT bpp.id, bpp.backup_plan_id, bpp.plugin_id, bpp.plugin_config_id,
            bp.agent_id, bp.name AS plan_name, a.name AS agent_name, p.name AS plugin_name
     FROM backup_plan_plugins bpp
     JOIN backup_plans bp ON bp.id = bpp.backup_plan_id
     JOIN agents a ON a.id = bp.agent_id
     JOIN plugins p ON p.id = bpp.plugin_id
     WHERE bpp.enabled = 1"
);
$hasPreBpp = in_array('plugin_config_id', $preColumns('backup_plan_plugins'), true);
foreach ($planPlugins as $pp) {
    $current = $pp['plugin_config_id'] !== null ? (int) $pp['plugin_config_id'] : null;
    $onCopy = $current !== null && isset($isJunk[$current]);
    if (!$onCopy && $current !== null) {
        continue;
    }

    $target = null;
    if ($hasPreBpp) {
        $pre = $db->fetchOne("SELECT plugin_config_id FROM `_bbs_pre_backup_plan_plugins` WHERE id = ?", [$pp['id']]);
        $preId = (int) ($pre['plugin_config_id'] ?? 0);
        if ($preId > 0 && !isset($isJunk[$preId]) && $db->fetchOne(
            "SELECT 1 FROM plugin_configs WHERE id = ? AND agent_id = ? AND plugin_id = ?",
            [$preId, $pp['agent_id'], $pp['plugin_id']]
        )) {
            $target = $preId;
        }
    }
    if ($target === null && $onCopy) {
        // No backup to go by: the client's only other config for this plugin
        $others = array_values(array_filter(
            array_map('intval', array_column($db->fetchAll(
                "SELECT id FROM plugin_configs WHERE agent_id = ? AND plugin_id = ?",
                [$pp['agent_id'], $pp['plugin_id']]
            ), 'id')),
            fn($id) => !isset($isJunk[$id])
        ));
        if (count($others) === 1) {
            $target = $others[0];
        }
    }
    // The plan already uses that config in another row
    if ($target !== null && $db->fetchOne(
        "SELECT 1 FROM backup_plan_plugins WHERE backup_plan_id = ? AND plugin_config_id = ? AND id <> ?",
        [$pp['backup_plan_id'], $target, $pp['id']]
    )) {
        $target = null;
    }

    if ($target !== null) {
        $db->update('backup_plan_plugins', ['plugin_config_id' => $target], 'id = ?', [$pp['id']]);
        $repointed++;
    } else {
        // On an empty copy, or with no settings at all (a copy deleted by
        // hand); either way the plugin cannot run until settings are picked
        $unresolved[] = "{$pp['plugin_name']} on plan \"{$pp['plan_name']}\" ({$pp['agent_name']})";
    }
}
if ($repointed > 0) {
    $log('warning', "Pointed {$repointed} backup plan plugin(s) back at their own settings. An update in 2.98.5 to 2.98.7 had switched them to empty copies, which made backups fail with \"'list' object has no attribute 'get'\".");
}
if ($unresolved) {
    $log('warning', 'These backup plan plugins have no settings, or an empty copy that an update in 2.98.5 to 2.98.7 made, and the right settings could not be worked out. Edit each plan and pick the plugin settings: ' . implode('; ', $unresolved) . '.');
}

// The empty copies nothing uses any more
$removed = 0;
foreach ($junkIds as $id) {
    $used = $db->fetchOne("SELECT 1 FROM backup_plan_plugins WHERE plugin_config_id = ? LIMIT 1", [$id])
        ?: $db->fetchOne("SELECT 1 FROM repository_s3_configs WHERE plugin_config_id = ? LIMIT 1", [$id]);
    if (!$used) {
        $db->delete('plugin_configs', 'id = ?', [$id]);
        $removed++;
    }
}
if ($removed > 0) {
    $log('info', "Removed {$removed} empty plugin setting(s) that an update in 2.98.5 to 2.98.7 created.");
}

// 032: the Interworx template, where it still has the values 032 wrote
$restored = [];
if (in_array('directories', $preColumns('backup_templates'), true)) {
    $n = $db->query(
        "UPDATE backup_templates t JOIN `_bbs_pre_backup_templates` p ON p.id = t.id
         SET t.directories = p.directories, t.excludes = p.excludes
         WHERE t.name = 'Interworx Server'
           AND t.directories = '/chroot/home\\n/var\\n/etc\\n/usr/local\\n/root'
           AND t.excludes = '*.tmp\\n*.log\\n*.cache'
           AND (p.directories <> t.directories OR COALESCE(p.excludes, '') <> t.excludes)"
    )->rowCount();
    if ($n > 0) {
        $restored[] = 'the Interworx Server template';
    }
}

// 044: the light theme, for users who had it
if (in_array('theme', $preColumns('users'), true)) {
    $n = $db->query(
        "UPDATE users u JOIN `_bbs_pre_users` p ON p.id = u.id SET u.theme = 'light'
         WHERE u.theme = 'dark' AND p.theme = 'light'"
    )->rowCount();
    if ($n > 0) {
        $restored[] = "{$n} user theme(s)";
    }
}

// 091: client access an admin had removed from the client's owner. Only rows
// the replay added: dated in its window, for an owner who was already the
// owner in the backup and had no access then.
if (isset($loaded['user_agents']) && in_array('user_id', $preColumns('agents'), true)) {
    $n = $db->query(
        "DELETE ua FROM user_agents ua
         JOIN agents a ON a.id = ua.agent_id AND a.user_id = ua.user_id
         JOIN `_bbs_pre_agents` pa ON pa.id = a.id AND pa.user_id = a.user_id
         LEFT JOIN `_bbs_pre_user_agents` pua ON pua.user_id = ua.user_id AND pua.agent_id = ua.agent_id
         WHERE pua.user_id IS NULL
           AND ua.created_at >= DATE_SUB(?, INTERVAL 10 MINUTE) AND ua.created_at < ?",
        [$replayStart, $catchUp]
    )->rowCount();
    if ($n > 0) {
        $restored[] = "{$n} client access removal(s)";
    }
}

foreach ($tables as $table) {
    $pdo->exec("DROP TABLE IF EXISTS `_bbs_pre_{$table}`");
}

if ($restored) {
    $log('warning', 'Restored settings that an update in 2.98.5 to 2.98.7 overwrote, from the server backup ' . basename($backup) . ': ' . implode(', ', $restored) . '.');
} elseif ($backup === null) {
    $log('warning', 'An update in 2.98.5 to 2.98.7 may have reset the Interworx Server template, users\' light theme, and client access removed from client owners. No server backup from before it was found to check against.');
}
