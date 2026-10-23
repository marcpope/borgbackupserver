<?php

namespace BBS\Services;

use BBS\Core\Database;

/**
 * Reads the database dumps recorded against an archive.
 *
 * The stored shape has two generations: newer archives keep
 * {"version":2,"groups":[…]} so several database configurations — including
 * two of the same engine — restore independently (#382), while older ones
 * hold a single flat object. Normalising that, resolving the dump directory
 * for legacy rows and looking up each dump's timestamp is the same work for
 * the web page and the API, so it lives here rather than in both.
 *
 * Timestamps come back raw; callers format for their own audience.
 */
class ArchiveDatabaseService
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * Normalised dump groups for one archive, or an empty array when it holds
     * no database dumps.
     */
    public function groups(int $agentId, int $archiveId, ?string $databasesBackedUp): array
    {
        $archive = ['databases_backed_up' => $databasesBackedUp];
        $id = $agentId;

        $data = $archive['databases_backed_up'] ? json_decode($archive['databases_backed_up'], true) : null;

        // Normalize into groups so multiple database configs — including two of
        // the same engine — each restore independently (#382). New format is
        // {"version":2,"groups":[...]}; older archives store a single flat
        // object, which we wrap as one group.
        $groups = [];
        if (is_array($data) && !empty($data['groups'])) {
            $groups = $data['groups'];
        } elseif (is_array($data) && !empty($data['databases'])) {
            $dumpDir = $data['dump_dir'] ?? null;
            if (!$dumpDir) {
                $pluginManager = new \BBS\Services\PluginManager();
                foreach ($pluginManager->getPluginConfigs($id) as $c) {
                    if (in_array($c['slug'], ['mysql_dump', 'pg_dump'])) {
                        $cfgData = json_decode($c['config'] ?? '{}', true);
                        if (!empty($cfgData['dump_dir'])) { $dumpDir = rtrim($cfgData['dump_dir'], '/'); break; }
                    }
                }
            }
            $groups = [[
                'config_id' => null,
                'config_name' => null,
                'engine' => null,
                'dump_dir' => $dumpDir,
                'databases' => $data['databases'],
                'per_database' => $data['per_database'] ?? true,
                'compress' => $data['compress'] ?? true,
            ]];
        }

        // Resolve dump-file mtimes from the file catalog, per group's dump dir.
        foreach ($groups as &$g) {
            $g['mtimes'] = [];
            $dumpDir = rtrim($g['dump_dir'] ?? '', '/');
            $dbs = $g['databases'] ?? [];
            if ($dumpDir === '' || empty($dbs)) continue;
            // SQL Server dumps are .bak files, often under a Windows path the
            // catalog spells differently; the timestamp is optional.
            if (($g['engine'] ?? null) === 'mssql_dump') continue;
            $compress = $g['compress'] ?? true;
            $patterns = [];
            foreach ($dbs as $db) {
                $patterns[] = $dumpDir . '/' . $db . ($compress ? '.sql.gz' : '.sql');
            }
            try {
                $ch = \BBS\Core\ClickHouse::getInstance();
                $pathList = implode(', ', array_map(fn($p) => "'" . str_replace(["\\", "'"], ["\\\\", "\\'"], $p) . "'", $patterns));
                $rows = $ch->fetchAll("
                    SELECT path, toString(mtime) as mtime
                    FROM file_catalog
                    WHERE agent_id = {$id} AND archive_id = {$archiveId} AND path IN ({$pathList})
                ");
                foreach ($rows as $row) {
                    $dbName = preg_replace('/\\.sql(\\.gz)?$/', '', basename($row['path']));
                    $g['mtimes'][$dbName] = $row['mtime'] ?: null;
                }
            } catch (\Exception $e) { /* catalog unavailable — mtimes optional */ }
        }
        unset($g);


        return $groups;
    }

    /** Resolve only recorded dump paths, never paths supplied by the browser. */
    public static function downloadPaths(array $groups, int $configId, string $engine, array $selectedNames): array
    {
        if (!$configId || !$selectedNames || !in_array($engine, ['mysql_dump', 'pg_dump', 'mongo_dump'], true)) {
            throw new \InvalidArgumentException('Select a connector and at least one database to download.');
        }

        $group = null;
        foreach ($groups as $candidate) {
            if ((int) ($candidate['config_id'] ?? 0) === $configId && ($candidate['engine'] ?? $engine) === $engine) {
                $group = $candidate;
                break;
            }
        }
        // Older archives have one ungrouped database dump. Their metadata has
        // no connector ID or engine, so use the selected connector's type.
        if ($group === null && count($groups) === 1 && empty($groups[0]['config_id'])
            && in_array($groups[0]['engine'] ?? null, [null, $engine], true)) {
            $group = $groups[0];
        }
        if ($group === null) {
            throw new \InvalidArgumentException('This connector has no database dumps in the selected archive.');
        }

        $dumpDir = rtrim((string) ($group['dump_dir'] ?? ''), '/');
        if ($dumpDir === '' && empty($group['config_id'])) {
            $dumpDir = $engine === 'pg_dump' ? '/home/bbs/pgdump' : '/home/bbs/mysql';
        }
        if (!str_starts_with($dumpDir, '/') || str_contains($dumpDir, '\\')
            || preg_match('~(^|/)\.\.?(?:/|$)~', $dumpDir)
            || preg_match('/[\x00-\x1f]/', $dumpDir)) {
            throw new \InvalidArgumentException('The database dump directory is invalid or unavailable.');
        }

        $available = $group['databases'] ?? [];
        if (!is_array($available)) {
            throw new \InvalidArgumentException('This archive has no valid database list.');
        }
        $basePath = ltrim($dumpDir, '/');
        $paths = [];
        foreach ($selectedNames as $name) {
            if (!is_string($name) || $name === '' || $name[0] === '-' || str_contains($name, '/') || str_contains($name, '\\')
                || str_contains($name, "\0") || in_array($name, ['.', '..'], true) || !in_array($name, $available, true)) {
                throw new \InvalidArgumentException('A selected database is not in this archive.');
            }
            if ($engine === 'mongo_dump') {
                $paths[] = $basePath . '/' . $name;
            } elseif (empty($group['per_database'])) {
                $paths[] = $basePath . '/all_databases.sql' . (!empty($group['compress']) ? '.gz' : '');
            } else {
                $paths[] = $basePath . '/' . $name . '.sql' . (!empty($group['compress']) ? '.gz' : '');
            }
        }

        return array_values(array_unique($paths));
    }
}
