#!/usr/bin/env php
<?php

require_once __DIR__ . '/vendor/autoload.php';

use BBS\Core\Migrator;

$migrator = new Migrator();

// Docker: an install from before the container recorded its migrations gets
// a one-time catch-up first, so old migrations are not replayed over live
// data (#532). 127 is the last .sql migration from before 2.98.5, when the
// container still ran migrations its own way. The setting holds when this
// happened; migration 134 uses it to tell a replay apart from this pass.
if (in_array('--legacy-docker', $argv ?? [], true)) {
    $db = \BBS\Core\Database::getInstance();
    $tracked = $db->fetchOne("SELECT `value` FROM settings WHERE `key` = 'migrations_docker_tracked'");
    if (!$tracked) {
        // The start of the catch-up: anything recorded from here on is this
        // pass, not a replay
        $start = date('Y-m-d H:i:s');
        foreach ($migrator->runLegacyRawLoop(127) as $line) {
            echo "Recorded (earlier Docker install): {$line}\n";
        }
        $db->query(
            "INSERT INTO settings (`key`, `value`) VALUES ('migrations_docker_tracked', ?)
             ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)",
            [$start]
        );
    }
}

$ran = $migrator->run();

foreach ($ran as $file) {
    echo "Migrated: {$file}\n";
}

// Statements stepped over because the change was already present. Normal on a
// fresh install, where schema.sql has already created most of it.
foreach ($migrator->skipped as $skip) {
    echo "Already applied: {$skip}\n";
}

if (!empty($migrator->errors)) {
    // Loud, and a non-zero exit. These migrations were NOT recorded and will
    // be retried on the next run; until one succeeds the schema is not what
    // the code expects. Reporting this as a routine skip is what let a
    // half-applied migration pass for a clean one.
    fwrite(STDERR, "\nMIGRATION FAILED — the database is not fully up to date:\n");
    foreach ($migrator->errors as $err) {
        fwrite(STDERR, "  ✗ {$err}\n");
    }
    fwrite(STDERR, "\nThese will be retried on the next update. If one keeps failing,\n"
                 . "the message above names the statement that could not be applied.\n");
    exit(1);
}

if (empty($ran) && empty($migrator->skipped)) {
    echo "Nothing to migrate.\n";
} else {
    echo "Done.\n";
}
