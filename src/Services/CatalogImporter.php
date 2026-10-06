<?php

namespace BBS\Services;

use BBS\Core\ClickHouse;
use BBS\Core\Database;

class CatalogImporter
{
    /**
     * Catalog fields from an agent are untrusted and go into a TabSeparated
     * stream: a tab or newline in one would start a row of the agent's
     * choosing, under any agent or archive id. Status is one letter, mtime
     * a "Y-m-d H:i:s" timestamp; anything else becomes unknown.
     */
    public static function safeStatus($status): string
    {
        $status = substr((string) ($status ?? 'U'), 0, 1);
        return preg_match('/^[A-Za-z?-]$/', $status) ? $status : 'U';
    }

    public static function safeMtime($mtime): string
    {
        $mtime = (string) ($mtime ?? '');
        return preg_match('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/', $mtime) ? $mtime : '\\N';
    }

    /**
     * Process a JSONL catalog file into ClickHouse file_catalog table.
     *
     * Converts JSONL → TSV in a single pass, then bulk-uploads via
     * ClickHouse HTTP interface for maximum speed.
     *
     * @param int|null $jobId Optional backup job ID for detailed log entries
     * @return int Number of catalog entries imported
     */
    public function processFile(Database $db, int $agentId, int $archiveId, string $filePath, ?int $jobId = null): int
    {
        set_time_limit(0);
        ini_set('memory_limit', '-1');

        $log = function (string $message) use ($db, $agentId, $jobId) {
            $data = ['agent_id' => $agentId, 'level' => 'info', 'message' => $message];
            if ($jobId) {
                $data['backup_job_id'] = $jobId;
            }
            try { $db->insert('server_log', $data); } catch (\Exception $e) { /* ignore */ }
        };

        // Update job status_message so the UI shows import progress
        $updateStatus = function (string $msg) use ($db, $jobId) {
            if (!$jobId) return;
            try { $db->update('backup_jobs', ['status_message' => $msg], 'id = ?', [$jobId]); } catch (\Exception $e) { /* ignore */ }
        };

        $handle = fopen($filePath, 'r');
        if (!$handle) {
            throw new \RuntimeException("Cannot open catalog file: {$filePath}");
        }

        $ch = ClickHouse::getInstance();
        $tsvFile = sys_get_temp_dir() . "/catalog_{$agentId}_{$archiveId}_" . getmypid() . '.tsv';

        $tsvFh = fopen($tsvFile, 'w');
        if (!$tsvFh) {
            fclose($handle);
            throw new \RuntimeException("Cannot write temp file: {$tsvFile}");
        }

        try {
            $tsvStart = microtime(true);
            $count = 0;
            $escape = fn(string $s) => str_replace(["\t", "\n", "\\"], ["\\t", "\\n", "\\\\"], $s);

            // Track directory stats: dirPath => [file_count, total_size]

            while (($line = fgets($handle)) !== false) {
                $line = trim($line);
                if (empty($line)) continue;

                $entry = json_decode($line, true);
                if (!$entry || empty($entry['path'])) continue;

                // Normalize Windows paths: backslashes to forward slashes, convert
                // drive letter to directory prefix (C:\Users\... → C/Users/...)
                // to match borg-windows archive format
                $rawPath = str_replace('\\', '/', $entry['path']);
                if (preg_match('/^([A-Za-z]):\//', $rawPath, $dm)) {
                    $rawPath = $dm[1] . substr($rawPath, 2);
                }
                $path = $escape($rawPath);
                $name = $escape(basename($rawPath));
                $rawParent = dirname($rawPath);
                $parentDir = $escape($rawParent);
                $status = self::safeStatus($entry['status'] ?? 'U');
                $size = (int) ($entry['size'] ?? 0);
                $mtime = self::safeMtime($entry['mtime'] ?? null);

                fwrite($tsvFh, "{$agentId}\t{$archiveId}\t{$path}\t{$name}\t{$parentDir}\t{$size}\t{$status}\t{$mtime}\n");
                $count++;

                // Accumulate per-directory stats (use raw unescaped paths)
                if ($status !== 'D') {
                }
            }

            fclose($handle);
            $handle = null;
            fclose($tsvFh);
            $tsvFh = null;

            $tsvElapsed = round(microtime(true) - $tsvStart, 1);
            $tsvSize = round(filesize($tsvFile) / 1048576, 1);

            if ($count === 0) {
                return 0;
            }

            $log("Catalog TSV generated: " . number_format($count) . " rows, {$tsvSize} MB in {$tsvElapsed}s — loading into ClickHouse");
            $updateStatus("Importing " . number_format($count) . " catalog entries...");

            $loadStart = microtime(true);

            $ch->insertTsv('file_catalog', $tsvFile, [
                'agent_id', 'archive_id', 'path', 'file_name', 'parent_dir', 'file_size', 'status', 'mtime'
            ]);

            $loadElapsed = round(microtime(true) - $loadStart, 1);
            $log("Catalog ClickHouse load complete: {$loadElapsed}s");
            $updateStatus("Building directory index...");

            // Build catalog_dirs table for fast directory browsing
            $this->buildDirIndex($ch, $agentId, $archiveId, $log);

            // Update cached catalog total for dashboard
            self::updateCachedTotal($db);

            return $count;
        } finally {
            if ($handle) fclose($handle);
            if ($tsvFh) fclose($tsvFh);
            @unlink($tsvFile);
        }
    }

    /**
     * Build the catalog_dirs index for fast directory browsing.
     *
     * Aggregation and ancestor expansion happen inside ClickHouse. Collecting
     * them in PHP while streaming needed one map entry per directory plus a
     * second copy carrying every ancestor, which exhausted the memory limit on
     * large archives (#391).
     */
    private function buildDirIndex(ClickHouse $ch, int $agentId, int $archiveId, callable $log): void
    {
        try {
            $ch->rebuildDirIndex($agentId, $archiveId);
            $log("Catalog dirs index rebuilt");
        } catch (\Exception $e) {
            $log("Catalog dirs index failed: " . $e->getMessage());
        }
    }

    /**
     * Update the cached catalog_total_files in settings from ClickHouse.
     */
    public static function updateCachedTotal(Database $db): void
    {
        try {
            $ch = ClickHouse::getInstance();
            $row = $ch->fetchOne("SELECT count() as cnt FROM file_catalog");
            $total = (int) ($row['cnt'] ?? 0);
            $db->getPdo()->exec(
                "INSERT INTO settings (`key`, `value`) VALUES ('catalog_total_files', '{$total}')
                 ON DUPLICATE KEY UPDATE `value` = '{$total}'"
            );
        } catch (\Exception $e) { /* ignore */ }
    }
}
