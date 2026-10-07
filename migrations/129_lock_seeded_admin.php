<?php
/**
 * Migration 129: lock admin accounts that still use the seeded password
 *
 * On Docker, every container start fed each migration file to MySQL again,
 * including 002, which inserted "admin" with the password "admin". An install
 * whose default admin had been renamed or deleted got that account back on
 * the next restart. 002 is now a no-op and Docker uses the migration runner.
 *
 * This locks any account whose password is still the seeded one, as long as
 * another admin can sign in. When it is the only admin it is left alone, so
 * nobody is locked out, and a warning goes to the log.
 */

$seeded = '$2y$12$OMFE1ma3aKDFjEYAP24eTuIznogvlOD2k3Emh0Hmvdckirgu73U2m';

foreach ($db->fetchAll("SELECT id, username, role FROM users WHERE password_hash = ?", [$seeded]) as $user) {
    $others = $db->fetchOne(
        "SELECT COUNT(*) AS n FROM users WHERE role = 'admin' AND id != ? AND password_hash != ?",
        [$user['id'], $seeded]
    );
    if ((int) ($others['n'] ?? 0) > 0) {
        $db->update('users', ['password_hash' => '!'], 'id = ?', [$user['id']]);
        $db->insert('server_log', [
            'level' => 'warning',
            'message' => "Locked account \"{$user['username']}\": it still had the default password. Reset its password from Users to use it again.",
        ]);
        echo "  Locked account {$user['username']} (default password).\n";
    } else {
        $db->insert('server_log', [
            'level' => 'warning',
            'message' => "Account \"{$user['username']}\" still uses the default password \"admin\". Change it now.",
        ]);
        echo "  Account {$user['username']} still uses the default password; it is the only admin, so it was left as is.\n";
    }
}
