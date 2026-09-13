<?php

namespace BBS\Core;

/**
 * Human names for backup_jobs.task_type. The type strings are part of the
 * job contract with agents and the API and never change; what people see
 * does. s3_sync stayed s3_sync when the plugin became Offsite Sync (#497).
 */
class JobType
{
    private const LABELS = [
        'backup' => 'Backup',
        'backup_dry_run' => 'Dry run',
        'restore' => 'Restore',
        'restore_mysql' => 'MySQL restore',
        'restore_pg' => 'PostgreSQL restore',
        'restore_mongo' => 'MongoDB restore',
        'prune' => 'Prune',
        'compact' => 'Compact',
        's3_sync' => 'Offsite sync',
        's3_restore' => 'Offsite restore',
        'repo_check' => 'Repository check',
        'repo_repair' => 'Repository repair',
        'break_lock' => 'Break lock',
        'catalog_sync' => 'Catalog sync',
        'catalog_rebuild' => 'Catalog rebuild',
        'catalog_rebuild_full' => 'Full catalog rebuild',
        'archive_delete' => 'Archive delete',
        'archive_lock' => 'Archive lock',
        'update_borg' => 'Borg update',
        'update_agent' => 'Agent update',
        'plugin_test' => 'Plugin test',
        'plugin_post' => 'Post-backup script',
        'check' => 'Check',
    ];

    public static function label(?string $type): string
    {
        $type = (string) $type;
        return self::LABELS[$type] ?? ucfirst(str_replace('_', ' ', $type));
    }

    /** The whole map, for the browser side. */
    public static function labels(): array
    {
        return self::LABELS;
    }
}
