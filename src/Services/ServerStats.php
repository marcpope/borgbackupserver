<?php

namespace BBS\Services;

class ServerStats
{
    /**
     * Get CPU load averages (1, 5, 15 min).
     */
    public static function getCpuLoad(): array
    {
        $load = sys_getloadavg();
        if ($load === false) {
            return ['1min' => 0, '5min' => 0, '15min' => 0, 'cores' => 1, 'percent' => 0];
        }

        $cores = self::getCpuCores();
        $percent = round(($load[0] / max($cores, 1)) * 100, 1);

        return [
            '1min' => round($load[0], 2),
            '5min' => round($load[1], 2),
            '15min' => round($load[2], 2),
            'cores' => $cores,
            'percent' => min($percent, 100),
        ];
    }

    /**
     * Get number of CPU cores.
     */
    private static function getCpuCores(): int
    {
        if (PHP_OS_FAMILY === 'Darwin') {
            $result = trim(shell_exec('sysctl -n hw.ncpu 2>/dev/null') ?? '');
        } else {
            $result = trim(shell_exec('nproc 2>/dev/null') ?? '');
        }
        return max((int) $result, 1);
    }

    /**
     * Get memory usage.
     */
    public static function getMemory(): array
    {
        if (PHP_OS_FAMILY === 'Darwin') {
            return self::getMemoryMac();
        }
        return self::getMemoryLinux();
    }

    private static function getMemoryLinux(): array
    {
        $meminfo = @file_get_contents('/proc/meminfo');
        if ($meminfo === false) {
            return ['total' => 0, 'used' => 0, 'free' => 0, 'percent' => 0];
        }

        $values = [];
        foreach (explode("\n", $meminfo) as $line) {
            if (preg_match('/^(\w+):\s+(\d+)\s+kB/', $line, $m)) {
                $values[$m[1]] = (int) $m[2] * 1024; // Convert to bytes
            }
        }

        $total = $values['MemTotal'] ?? 0;
        $available = $values['MemAvailable'] ?? ($values['MemFree'] ?? 0);
        $used = $total - $available;

        return [
            'total' => $total,
            'used' => $used,
            'free' => $available,
            'percent' => $total > 0 ? round(($used / $total) * 100, 1) : 0,
        ];
    }

    private static function getMemoryMac(): array
    {
        $total = (int) trim(shell_exec('sysctl -n hw.memsize 2>/dev/null') ?? '0');
        $pageSize = (int) trim(shell_exec('sysctl -n hw.pagesize 2>/dev/null') ?? '4096');

        // Parse vm_stat for page counts
        $vmstat = shell_exec('vm_stat 2>/dev/null') ?? '';
        $pages = [];
        foreach (explode("\n", $vmstat) as $line) {
            if (preg_match('/^(.+?):\s+(\d+)/', $line, $m)) {
                $pages[trim($m[1])] = (int) $m[2];
            }
        }

        $free = ($pages['Pages free'] ?? 0) * $pageSize;
        $inactive = ($pages['Pages inactive'] ?? 0) * $pageSize;
        $available = $free + $inactive;
        $used = $total - $available;

        return [
            'total' => $total,
            'used' => max($used, 0),
            'free' => $available,
            'percent' => $total > 0 ? round(($used / $total) * 100, 1) : 0,
        ];
    }

    /**
     * Get disk partition usage.
     */
    public static function getPartitions(): array
    {
        $partitions = [];

        if (PHP_OS_FAMILY === 'Darwin') {
            // macOS: standard df output
            $output = shell_exec('df -h / 2>/dev/null') ?? '';
            $useExtendedFormat = false;
        } else {
            // Linux: try extended format first, fallback to standard
            $output = shell_exec('df -h --output=source,fstype,size,used,avail,pcent,target -x tmpfs -x devtmpfs -x squashfs 2>/dev/null') ?? '';
            $useExtendedFormat = !empty(trim($output));
            if (!$useExtendedFormat) {
                $output = shell_exec('df -h -x tmpfs -x devtmpfs 2>/dev/null') ?? '';
            }
        }

        $lines = explode("\n", trim($output));
        array_shift($lines); // Remove header

        foreach ($lines as $line) {
            $parts = preg_split('/\s+/', trim($line));
            if (count($parts) < 6) continue;

            // Skip non-physical filesystems
            $device = $parts[0];
            if (str_starts_with($device, 'tmpfs') || str_starts_with($device, 'devtmpfs')) {
                continue;
            }

            $mount = end($parts);

            // Skip bind mounts to files (not directories) - common in Docker
            if (!is_dir($mount)) {
                continue;
            }

            if ($useExtendedFormat && count($parts) >= 7) {
                // Extended format: source, fstype, size, used, avail, pcent, target
                // Indices:         0       1       2     3     4      5      6
                $pct = (int) str_replace('%', '', $parts[5] ?? '0');
                $partitions[] = [
                    'mount' => $mount,
                    'size' => $parts[2] ?? '--',
                    'used' => $parts[3] ?? '--',
                    'free' => $parts[4] ?? '--',
                    'percent' => $pct,
                ];
            } else {
                // Standard df -h output: Filesystem, Size, Used, Avail, Use%, Mounted on
                // Indices:                0           1     2     3      4     5
                $pct = (int) str_replace('%', '', $parts[4] ?? '0');
                $partitions[] = [
                    'mount' => $mount,
                    'size' => $parts[1] ?? '--',
                    'used' => $parts[2] ?? '--',
                    'free' => $parts[3] ?? '--',
                    'percent' => $pct,
                ];
            }
        }

        // Ensure /var/bbs is represented if it exists (df deduplicates by device,
        // so in Docker with XFS project quotas /var/bbs may be hidden behind
        // another mount from the same device like /entrypoint.sh)
        $hasVarBbs = false;
        foreach ($partitions as $p) {
            if ($p['mount'] === '/var/bbs') {
                $hasVarBbs = true;
                break;
            }
        }
        if (!$hasVarBbs && is_dir('/var/bbs')) {
            $diskUsage = self::getDiskUsage('/var/bbs');
            if ($diskUsage) {
                $partitions[] = [
                    'mount' => '/var/bbs',
                    'size' => self::formatDfSize($diskUsage['total']),
                    'used' => self::formatDfSize($diskUsage['used']),
                    'free' => self::formatDfSize($diskUsage['free']),
                    'percent' => $diskUsage['percent'],
                ];
                $hasVarBbs = true;
            }
        }

        // If /var/bbs sits on the root filesystem, the Server Health widget
        // was showing both / and /var/bbs as identical rows (#178). When they
        // share a device, drop / so the widget reserves the space for the
        // BBS-relevant mount.
        if ($hasVarBbs) {
            $rootStat = @stat('/');
            $bbsStat  = @stat('/var/bbs');
            if ($rootStat && $bbsStat && $rootStat['dev'] === $bbsStat['dev']) {
                $partitions = array_values(array_filter(
                    $partitions,
                    fn($p) => $p['mount'] !== '/'
                ));
            }
        }

        return $partitions;
    }

    public static function formatDfSize(float $bytes): string
    {
        $nbsp = "\u{00A0}";
        if ($bytes >= 1099511627776) return round($bytes / 1099511627776, 1) . $nbsp . 'TB';
        if ($bytes >= 1073741824) return round($bytes / 1073741824) . $nbsp . 'GB';
        if ($bytes >= 1048576) return round($bytes / 1048576) . $nbsp . 'MB';
        return round($bytes / 1024) . $nbsp . 'KB';
    }

    /**
     * Network throughput in bytes/sec since the previous call, via a cached
     * counter snapshot — same delta pattern as the MySQL QPS meter below.
     * Returns ['rx_bps' => int|null, 'tx_bps' => int|null]; the values are
     * null until a second sample exists or after a counter reset. Returns
     * null entirely when no interface counters are readable.
     */
    public static function getNetworkThroughput(): ?array
    {
        $counters = self::readNetworkCounters();
        if ($counters === null) {
            return null;
        }

        $cache = Cache::getInstance();
        $now = microtime(true);
        $prev = $cache->get('net_io_snapshot');

        // A second poller inside the same 2s window (extra dashboard tab,
        // page load racing the poll) reuses the last computed rate instead
        // of measuring a near-zero interval.
        if (is_array($prev) && isset($prev['t']) && ($now - (float) $prev['t']) < 2) {
            $rate = $cache->get('net_io_rate');
            return is_array($rate) ? $rate : ['rx_bps' => null, 'tx_bps' => null];
        }

        $cache->set('net_io_snapshot', ['rx' => $counters['rx'], 'tx' => $counters['tx'], 't' => $now], 300);

        if (!is_array($prev) || !isset($prev['rx'], $prev['tx'], $prev['t'])) {
            return ['rx_bps' => null, 'tx_bps' => null];
        }

        $dt = $now - (float) $prev['t'];
        $dRx = $counters['rx'] - (int) $prev['rx'];
        $dTx = $counters['tx'] - (int) $prev['tx'];
        if ($dt <= 0 || $dRx < 0 || $dTx < 0) {
            // Counter reset (interface bounce / reboot) — skip this sample.
            return ['rx_bps' => null, 'tx_bps' => null];
        }

        $rate = ['rx_bps' => (int) round($dRx / $dt), 'tx_bps' => (int) round($dTx / $dt)];
        $cache->set('net_io_rate', $rate, 300);
        return $rate;
    }

    /**
     * Cumulative rx/tx byte counters summed across real interfaces.
     * Linux reads /proc/net/dev — inside Docker that's the container's own
     * network namespace, so the counters are BBS traffic only. Excludes
     * loopback and host-side virtual devices (veth/bridges) that would
     * double-count container traffic on a bare-metal host.
     */
    private static function readNetworkCounters(): ?array
    {
        if (PHP_OS_FAMILY === 'Darwin') {
            return self::readNetworkCountersMac();
        }

        $raw = @file_get_contents('/proc/net/dev');
        if ($raw === false) {
            return null;
        }

        $rx = 0;
        $tx = 0;
        $found = false;
        foreach (explode("\n", $raw) as $line) {
            if (!str_contains($line, ':')) continue;
            [$iface, $data] = explode(':', $line, 2);
            $iface = trim($iface);
            if ($iface === 'lo'
                || str_starts_with($iface, 'veth')
                || str_starts_with($iface, 'br-')
                || str_starts_with($iface, 'docker')
                || str_starts_with($iface, 'virbr')) {
                continue;
            }
            $cols = preg_split('/\s+/', trim($data));
            if (count($cols) < 9) continue;
            // /proc/net/dev: rx bytes is col 0, tx bytes is col 8
            $rx += (int) $cols[0];
            $tx += (int) $cols[8];
            $found = true;
        }

        return $found ? ['rx' => $rx, 'tx' => $tx] : null;
    }

    private static function readNetworkCountersMac(): ?array
    {
        // netstat -ibn prints one row per address; the <Link#N> row carries
        // the interface's byte counters. The Address column can be empty on
        // link rows, shifting the columns left by one.
        $out = @shell_exec('netstat -ibn 2>/dev/null');
        if (empty($out)) {
            return null;
        }

        $rx = 0;
        $tx = 0;
        $seen = [];
        $found = false;
        foreach (explode("\n", trim($out)) as $i => $line) {
            if ($i === 0) continue;
            $cols = preg_split('/\s+/', trim($line));
            if (count($cols) < 10 || !str_contains($cols[2] ?? '', 'Link')) continue;
            $iface = $cols[0];
            if ($iface === 'lo0' || isset($seen[$iface])) continue;
            $seen[$iface] = true;
            // With address:    Name Mtu Network Address Ipkts Ierrs Ibytes Opkts Oerrs Obytes
            // Without address: Name Mtu Network Ipkts Ierrs Ibytes Opkts Oerrs Obytes Coll
            $shift = count($cols) >= 11 ? 0 : -1;
            $rx += (int) $cols[6 + $shift];
            $tx += (int) $cols[9 + $shift];
            $found = true;
        }

        return $found ? ['rx' => $rx, 'tx' => $tx] : null;
    }

    /**
     * "12.4 MB/s" label for the dashboard network meter.
     */
    public static function formatRate(?int $bps): string
    {
        if ($bps === null) {
            return '—';
        }
        return self::formatBytes($bps) . '/s';
    }

    /**
     * Get MySQL row counts and interesting aggregate stats.
     */
    public static function getMysqlStats(): array
    {
        $db = \BBS\Core\Database::getInstance();

        // Read cached catalog count from settings (updated by CatalogImporter)
        // Avoids querying ClickHouse on every dashboard load
        $catalogRow = $db->fetchOne(
            "SELECT `value` FROM settings WHERE `key` = 'catalog_total_files'"
        );
        $catalogFiles = ['cnt' => (int) ($catalogRow['value'] ?? 0)];
        $archives = $db->fetchOne("SELECT COUNT(*) AS cnt FROM archives");
        $jobs = $db->fetchOne("SELECT COALESCE(MAX(id), 0) AS cnt FROM backup_jobs");

        // MySQL performance stats from SHOW GLOBAL STATUS
        $statusVars = [];
        $rows = $db->fetchAll("SHOW GLOBAL STATUS WHERE Variable_name IN (
            'Uptime', 'Questions', 'Threads_connected', 'Threads_running',
            'Innodb_buffer_pool_read_requests', 'Innodb_buffer_pool_reads',
            'Innodb_buffer_pool_pages_total', 'Innodb_buffer_pool_pages_free',
            'Bytes_received', 'Bytes_sent', 'Slow_queries'
        )");
        foreach ($rows as $r) {
            $statusVars[$r['Variable_name']] = (int) $r['Value'];
        }

        // Buffer pool size from variables
        $bpVar = $db->fetchOne("SHOW VARIABLES LIKE 'innodb_buffer_pool_size'");
        $bufferPoolSize = (int) ($bpVar['Value'] ?? 0);

        $uptime = $statusVars['Uptime'] ?? 1;
        $questions = $statusVars['Questions'] ?? 0;
        $readRequests = $statusVars['Innodb_buffer_pool_read_requests'] ?? 0;
        $diskReads = $statusVars['Innodb_buffer_pool_reads'] ?? 0;
        $pagesTotal = $statusVars['Innodb_buffer_pool_pages_total'] ?? 1;
        $pagesFree = $statusVars['Innodb_buffer_pool_pages_free'] ?? 0;

        // Calculate real-time QPS from delta between polls
        $cache = Cache::getInstance();
        $prevSnapshot = $cache->get('mysql_qps_snapshot');
        if ($prevSnapshot && isset($prevSnapshot['questions'], $prevSnapshot['uptime'])) {
            $dQuestions = $questions - $prevSnapshot['questions'];
            $dUptime = $uptime - $prevSnapshot['uptime'];
            $qps = $dUptime > 0 ? round($dQuestions / $dUptime, 1) : 0;
        } else {
            // First call: fall back to lifetime average
            $qps = round($questions / max($uptime, 1), 1);
        }
        $cache->set('mysql_qps_snapshot', ['questions' => $questions, 'uptime' => $uptime], 300);
        $hitRate = $readRequests > 0
            ? round((1 - $diskReads / $readRequests) * 100, 2)
            : 100.0;
        $bufferPoolUsedPct = round((($pagesTotal - $pagesFree) / max($pagesTotal, 1)) * 100, 1);

        return [
            'catalog_files' => (int) ($catalogFiles['cnt'] ?? 0),
            'archives' => (int) ($archives['cnt'] ?? 0),
            'completed_jobs' => (int) ($jobs['cnt'] ?? 0),
            'qps' => $qps,
            'threads_connected' => $statusVars['Threads_connected'] ?? 0,
            'threads_running' => $statusVars['Threads_running'] ?? 0,
            'buffer_pool_size' => $bufferPoolSize,
            'buffer_pool_used_pct' => $bufferPoolUsedPct,
            'hit_rate' => $hitRate,
            'uptime' => $uptime,
            'slow_queries' => $statusVars['Slow_queries'] ?? 0,
        ];
    }

    /**
     * Get MySQL database size and the free space on its data partition.
     */
    public static function getMysqlStorage(): array
    {
        $db = \BBS\Core\Database::getInstance();

        $row = $db->fetchOne("
            SELECT SUM(data_length + index_length) AS db_bytes
            FROM information_schema.TABLES
            WHERE table_schema = DATABASE()
        ");
        $dbBytes = (int) ($row['db_bytes'] ?? 0);

        // Find MySQL data directory and get partition free space
        $dataDirRow = $db->fetchOne("SHOW VARIABLES LIKE 'datadir'");
        $dataDir = $dataDirRow['Value'] ?? '/var/lib/mysql';

        $diskTotal = 0;
        $diskFree = 0;
        $diskUsed = 0;
        $diskUsage = self::getDiskUsage($dataDir);
        if ($diskUsage) {
            $diskTotal = $diskUsage['total'];
            $diskFree = $diskUsage['free'];
            $diskUsed = $diskUsage['used'];
        }

        return [
            'db_bytes' => $dbBytes,
            'disk_total' => $diskTotal,
            'disk_free' => $diskFree,
            'disk_used' => $diskUsed,
        ];
    }

    /**
     * Get accurate disk usage for a path using df (excludes reserved blocks).
     * Returns [total, used, free, percent] in bytes, or null on failure.
     */
    public static function getDiskUsage(string $path): ?array
    {
        if (!is_dir($path)) {
            return null;
        }

        $output = @shell_exec('df -P -B1 ' . escapeshellarg($path) . ' 2>/dev/null');
        if (empty($output)) {
            return null;
        }

        $lines = explode("\n", trim($output));
        if (count($lines) < 2) {
            return null;
        }

        // df -P -B1 output: Filesystem 1-blocks Used Available Capacity Mounted
        $parts = preg_split('/\s+/', trim($lines[1]));
        if (count($parts) < 6) {
            return null;
        }

        $total = (int) $parts[1];
        $used = (int) $parts[2];
        $free = (int) $parts[3];
        $percent = (int) str_replace('%', '', $parts[4]);

        if ($total <= 0) {
            return null;
        }

        return [
            'total' => $total,
            'used' => $used,
            'free' => $free,
            'percent' => $percent,
        ];
    }

    /**
     * Get ClickHouse catalog statistics for the dashboard.
     * Returns null if ClickHouse is unavailable.
     */
    public static function getClickHouseStats(): ?array
    {
        try {
            $ch = \BBS\Core\ClickHouse::getInstance();
            if (!$ch->isAvailable()) return null;

            // Total row count from system.parts (sum of rows across active parts)
            // — avoids a full-table scan. With hundreds of millions of rows,
            // uniqExact() on the whole file_catalog pegs ClickHouse at 100% CPU.
            $rowsRow = $ch->fetchOne("
                SELECT sum(rows) AS total_rows
                FROM system.parts
                WHERE database = 'bbs' AND table = 'file_catalog' AND active = 1
            ");
            $totalRows = (int) ($rowsRow['total_rows'] ?? 0);

            // Agent/archive counts come from MySQL (authoritative and cheap)
            $db = \BBS\Core\Database::getInstance();
            $agentCount = (int) ($db->fetchOne("SELECT COUNT(DISTINCT a.id) AS cnt FROM agents a JOIN repositories r ON r.agent_id = a.id JOIN archives ar ON ar.repository_id = r.id")['cnt'] ?? 0);
            $archiveCount = (int) ($db->fetchOne("SELECT COUNT(*) AS cnt FROM archives")['cnt'] ?? 0);
            $avgPerArchive = $archiveCount > 0 ? round($totalRows / $archiveCount) : 0;

            // Disk usage from system.parts (active parts only)
            $diskRow = $ch->fetchOne("
                SELECT sum(bytes_on_disk) AS disk_bytes,
                       sum(data_uncompressed_bytes) AS raw_bytes
                FROM system.parts
                WHERE database = 'bbs' AND table = 'file_catalog' AND active = 1
            ");
            $diskBytes = (int) ($diskRow['disk_bytes'] ?? 0);
            $rawBytes = (int) ($diskRow['raw_bytes'] ?? 0);
            $compressionRatio = $diskBytes > 0 ? round($rawBytes / $diskBytes, 0) : 0;

            // Per-agent stats: rows, disk, archives — join agent names from MySQL
            $perAgent = $ch->fetchAll("
                SELECT partition AS agent_id,
                       sum(rows) AS rows,
                       sum(bytes_on_disk) AS disk_bytes
                FROM system.parts
                WHERE database = 'bbs' AND table = 'file_catalog' AND active = 1
                GROUP BY partition
                ORDER BY rows DESC
            ");

            // Get archive counts per agent from MySQL — same data, zero scan cost
            $archivesPerAgent = $db->fetchAll("
                SELECT r.agent_id, COUNT(ar.id) AS archives
                FROM archives ar
                JOIN repositories r ON r.id = ar.repository_id
                GROUP BY r.agent_id
            ");
            $archiveMap = [];
            foreach ($archivesPerAgent as $a) {
                $archiveMap[(int) $a['agent_id']] = (int) $a['archives'];
            }

            $agentIds = array_map(fn($r) => (int) $r['agent_id'], $perAgent);
            $agentNames = [];
            if (!empty($agentIds)) {
                $placeholders = implode(',', array_fill(0, count($agentIds), '?'));
                $rows = $db->fetchAll(
                    "SELECT id, name FROM agents WHERE id IN ({$placeholders})",
                    $agentIds
                );
                foreach ($rows as $r) {
                    $agentNames[(int) $r['id']] = $r['name'];
                }
            }

            // Build top repos list (max 5)
            $topRepos = [];
            foreach (array_slice($perAgent, 0, 5) as $agent) {
                $aid = (int) $agent['agent_id'];
                $topRepos[] = [
                    'name' => $agentNames[$aid] ?? "Agent #{$aid}",
                    'rows' => (int) $agent['rows'],
                    'archives' => $archiveMap[$aid] ?? 0,
                    'disk_bytes' => (int) $agent['disk_bytes'],
                ];
            }

            return [
                'total_rows' => $totalRows,
                'agent_count' => $agentCount,
                'archive_count' => $archiveCount,
                'avg_per_archive' => $avgPerArchive,
                'disk_bytes' => $diskBytes,
                'raw_bytes' => $rawBytes,
                'compression_ratio' => $compressionRatio,
                'top_repos' => $topRepos,
            ];
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Format bytes to human readable.
     */
    public static function formatBytes(int $bytes, int $precision = 1): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        $size = (float) $bytes;
        while ($size >= 1024 && $i < count($units) - 1) {
            $size /= 1024;
            $i++;
        }
        return round($size, $precision) . "\u{00A0}" . $units[$i];
    }

    /**
     * Render "used / total" with the unit collapsed when both sides match.
     * Example: 2.3 GB used of 31 GB → "2.3 / 31 GB". Different units stay
     * fully labeled.
     */
    public static function formatBytesPair(int $used, int $total, int $precision = 1): string
    {
        $u = self::formatBytes($used, $precision);
        $t = self::formatBytes($total, $precision);
        $uParts = explode("\u{00A0}", $u);
        $tParts = explode("\u{00A0}", $t);
        if (count($uParts) === 2 && count($tParts) === 2 && $uParts[1] === $tParts[1]) {
            return $uParts[0] . ' / ' . $tParts[0] . "\u{00A0}" . $tParts[1];
        }
        return $u . ' / ' . $t;
    }

    /**
     * Same idea for df-style sizes ("3.5T", "4.9T") — fix the unit suffix
     * to "GB"/"TB" and collapse when both sides share a unit.
     */
    public static function dfPairLabel(string $used, string $total): string
    {
        $fix = function (string $s): string {
            if (preg_match('/^([\d.]+)([TGMK])$/', $s, $m)) return $m[1] . "\u{00A0}" . $m[2] . 'B';
            return $s;
        };
        $u = $fix($used);
        $t = $fix($total);
        $uParts = explode("\u{00A0}", $u);
        $tParts = explode("\u{00A0}", $t);
        if (count($uParts) === 2 && count($tParts) === 2 && $uParts[1] === $tParts[1]) {
            return $uParts[0] . ' / ' . $tParts[0] . "\u{00A0}" . $tParts[1];
        }
        return $u . ' / ' . $t;
    }
}
