<?php
/**
 * Migration 133: flag Remote SSH hosts saved before their settings were validated
 *
 * Hosts are validated when saved and again before each use, so
 * a host whose user or host would not pass is no longer used. Say so in the
 * log, so the admin knows to edit and save it.
 */

use BBS\Services\RemoteSshService;

foreach ($db->fetchAll("SELECT id, name, remote_host, remote_user, borg_remote_path FROM remote_ssh_configs") as $row) {
    $problem = RemoteSshService::configProblem($row);
    if ($problem === null) {
        continue;
    }
    $db->insert('server_log', [
        'level' => 'warning',
        'message' => "Remote SSH host \"{$row['name']}\" (#{$row['id']}) is not used until it is fixed. {$problem}",
    ]);
    echo "  Remote SSH host #{$row['id']} has invalid settings; logged a warning.\n";
}
