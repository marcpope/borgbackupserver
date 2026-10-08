<?php

namespace BBS\Services;

use BBS\Core\Database;

/**
 * The full file list of a dry run (#414). The agent uploads it over the SSH
 * gate to <client home>/.catalog-logs/dryrun-<job id>.txt; it is kept for a
 * day, can be downloaded from the job page, and deleted early.
 *
 * That directory is writable by the client's own unix user, so nothing
 * found there is trusted:
 * - the path comes from the database only: the client's home and the
 *   integer job id;
 * - every operation runs inside the directory after checking, from inside,
 *   that it is the client's own (getcwd), and then uses only the bare file
 *   name, so swapping the directory for a link afterwards changes nothing;
 * - only a regular file is read, and the opened file must be the one that
 *   was checked; delete removes that one directory entry and never follows
 *   a link.
 */
class DryRunLogService
{
    public const KEEP_SECONDS = 86400;

    private const S_IFMT = 0170000;
    private const S_IFREG = 0100000;
    private const S_IFLNK = 0120000;

    /** The client's log directory, as it must resolve, or null. */
    private static function expectedDir(?array $agent): ?string
    {
        $home = rtrim((string) ($agent['ssh_home_dir'] ?? ''), '/');
        if ($home === '' || $home[0] !== '/') {
            return null;
        }
        $realHome = realpath($home);
        return $realHome === false ? null : $realHome . '/.catalog-logs';
    }

    /** The bare file name for a dry run job, or null when the job can't have a list. */
    private static function fileName(array $job, ?array $agent): ?string
    {
        if (($job['task_type'] ?? '') !== 'backup_dry_run' || !$agent
            || (int) ($agent['id'] ?? 0) !== (int) ($job['agent_id'] ?? -1)) {
            return null;
        }
        return 'dryrun-' . (int) $job['id'] . '.txt';
    }

    /**
     * Run $fn inside $dir, after making sure the directory we are in is
     * $dir itself and not wherever a link pointed. Returns $fn's result,
     * or null when the directory can't be entered or isn't the right one.
     */
    private static function inDir(string $dir, callable $fn)
    {
        $prev = getcwd();
        if (!@chdir($dir)) {
            return null;
        }
        try {
            if (getcwd() !== $dir) {
                return null;
            }
            return $fn();
        } finally {
            if ($prev !== false) {
                @chdir($prev);
            }
        }
    }

    /** ['size' => bytes, 'mtime' => unix time] when the list is there and current, else null. */
    public static function info(array $job, ?array $agent): ?array
    {
        $name = self::fileName($job, $agent);
        $dir = self::expectedDir($agent);
        if ($name === null || $dir === null) {
            return null;
        }
        return self::inDir($dir, function () use ($name) {
            $st = @lstat($name);
            if ($st === false || ($st['mode'] & self::S_IFMT) !== self::S_IFREG
                || $st['mtime'] < time() - self::KEEP_SECONDS) {
                return null;
            }
            return ['size' => (int) $st['size'], 'mtime' => (int) $st['mtime']];
        });
    }

    /**
     * An open read handle on the list, or null. The handle must be the
     * same regular file lstat() saw.
     */
    public static function open(array $job, ?array $agent)
    {
        $name = self::fileName($job, $agent);
        $dir = self::expectedDir($agent);
        if ($name === null || $dir === null) {
            return null;
        }
        return self::inDir($dir, function () use ($name) {
            $before = @lstat($name);
            if ($before === false || ($before['mode'] & self::S_IFMT) !== self::S_IFREG
                || $before['mtime'] < time() - self::KEEP_SECONDS) {
                return null;
            }
            $fh = @fopen($name, 'rb');
            if (!$fh) {
                return null;
            }
            $after = fstat($fh);
            if ($after === false || $before['dev'] !== $after['dev'] || $before['ino'] !== $after['ino']
                || ($after['mode'] & self::S_IFMT) !== self::S_IFREG) {
                fclose($fh);
                return null;
            }
            return $fh;
        });
    }

    /**
     * Delete this job's list: the one entry dryrun-<id>.txt in the client's
     * own log directory. A regular file, or a link of that name (the link
     * is removed, never what it points to). Nothing else.
     */
    public static function delete(array $job, ?array $agent): bool
    {
        $name = self::fileName($job, $agent);
        $dir = self::expectedDir($agent);
        if ($name === null || $dir === null) {
            return false;
        }
        return (bool) self::inDir($dir, function () use ($name) {
            $st = @lstat($name);
            if ($st === false) {
                return false;
            }
            $type = $st['mode'] & self::S_IFMT;
            if ($type !== self::S_IFREG && $type !== self::S_IFLNK) {
                return false;
            }
            return @unlink($name);
        });
    }

    /** Send an open list as a text download, then end the request. */
    public static function send($fh, int $jobId): void
    {
        while (ob_get_level()) {
            ob_end_clean();
        }
        $st = fstat($fh);
        header('Content-Type: text/plain; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('Content-Disposition: attachment; filename="dry-run-' . $jobId . '.txt"');
        header('Cache-Control: no-store');
        if ($st !== false) {
            header('Content-Length: ' . $st['size']);
        }
        fpassthru($fh);
        fclose($fh);
        exit;
    }

    /** Remove lists older than a day. Returns how many. */
    public static function cleanupExpired(Database $db): int
    {
        $removed = 0;
        $cutoff = time() - self::KEEP_SECONDS;
        foreach ($db->fetchAll("SELECT id, ssh_home_dir FROM agents WHERE ssh_home_dir IS NOT NULL AND ssh_home_dir != ''") as $agent) {
            $dir = self::expectedDir($agent);
            if ($dir === null) {
                continue;
            }
            $removed += (int) self::inDir($dir, function () use ($cutoff) {
                $n = 0;
                foreach (glob('dryrun-*.txt') ?: [] as $name) {
                    if (!preg_match('/^dryrun-\d+\.txt$/', $name)) {
                        continue;
                    }
                    $st = @lstat($name);
                    if ($st === false) {
                        continue;
                    }
                    $type = $st['mode'] & self::S_IFMT;
                    if (($type === self::S_IFREG || $type === self::S_IFLNK) && $st['mtime'] < $cutoff && @unlink($name)) {
                        $n++;
                    }
                }
                return $n;
            });
        }
        return $removed;
    }
}
