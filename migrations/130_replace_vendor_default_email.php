<?php
/**
 * Migration 130: replace the vendor address on the default admin
 *
 * The default admin was created with admin@borgbackupserver.com. Docker
 * installs kept it unless the admin changed it, and password reset looks
 * accounts up by email, so a reset for that address would mail a working
 * link to a mailbox outside the install. Any account still on it moves to
 * a placeholder on the reserved .invalid domain, which never delivers.
 */

$users = $db->fetchAll("SELECT id FROM users WHERE LOWER(email) = 'admin@borgbackupserver.com'");
foreach ($users as $user) {
    $email = 'admin@localhost.invalid';
    if ($db->fetchOne("SELECT id FROM users WHERE email = ? AND id != ?", [$email, $user['id']])) {
        $email = 'admin-' . (int) $user['id'] . '@localhost.invalid';
    }
    $db->update('users', ['email' => $email], 'id = ?', [$user['id']]);
    $db->insert('server_log', [
        'level' => 'warning',
        'message' => "The admin account's email was the default placeholder and is now {$email}. Set a real address in your profile so password reset and notifications reach you.",
    ]);
    echo "  Replaced the default email on user #{$user['id']}.\n";
}
