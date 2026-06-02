<?php

namespace BBS\Core;

class ClickHouse
{
    private static ?self $instance = null;
    private string $baseUrl;
    private string $database;
    /** Whether this server knows query_plan_optimize_lazy_materialization (null = undetected). */
    private ?bool $lazyMatSettingSupported = null;

    private function __construct()
    {
        $host = Config::get('CLICKHOUSE_HOST', 'localhost');
        $port = Config::get('CLICKHOUSE_PORT', '8123');
        $this->database = Config::get('CLICKHOUSE_DB', 'bbs');
        $this->baseUrl = "http://{$host}:{$port}";
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Execute a query (DDL, INSERT, ALTER DELETE, etc.)
     */
    public function exec(string $sql): string
    {
        return $this->request($sql);
    }

    /**
     * Execute a SELECT query, return rows as associative arrays.
     */
    public function fetchAll(string $sql, array $params = []): array
    {
        $sql = $this->bindParams($sql, $params);
        $response = $this->request($sql . ' FORMAT JSONEachRow');
        if (trim($response) === '') return [];

        $rows = [];
        foreach (explode("\n", trim($response)) as $line) {
            if ($line !== '') {
                $rows[] = json_decode($line, true);
            }
        }
        return $rows;
    }

    /**
     * fetchAll for `ORDER BY <non-key-col> LIMIT n` queries against the file
     * catalog, with the ClickHouse 26.5 lazy-materialization top-N bug worked
     * around (#301).
     *
     * CH 26.5's `__topKFilter` optimization (query_plan_optimize_lazy_
     * materialization) mis-maps columns when the planner picks the lazy plan —
     * which it does under memory pressure on larger archives — feeding a String
     * column into a UInt64 filter and throwing `Code: 53 TYPE_MISMATCH`. That
     * blanks the "Largest Files" panel and the catalog file list while the
     * streaming GROUP BY queries (File Changes) keep working. Disabling lazy
     * materialization for the query restores the correct plan.
     *
     * Older ClickHouse builds predate the setting (and the bug); if the server
     * rejects it we cache that and fall back to a plain fetchAll so we don't
     * pay a failed round-trip on every call.
     */
    public function fetchAllOrdered(string $sql, array $params = []): array
    {
        if ($this->lazyMatSettingSupported === false) {
            return $this->fetchAll($sql, $params);
        }
        try {
            $rows = $this->fetchAll($sql . ' SETTINGS query_plan_optimize_lazy_materialization = 0', $params);
            $this->lazyMatSettingSupported = true;
            return $rows;
        } catch (\Throwable $e) {
            if (stripos($e->getMessage(), 'UNKNOWN_SETTING') !== false) {
                $this->lazyMatSettingSupported = false;
                return $this->fetchAll($sql, $params);
            }
            throw $e;
        }
    }

    /**
     * Execute a SELECT query, return first row or null.
     */
    public function fetchOne(string $sql, array $params = []): ?array
    {
        $rows = $this->fetchAll($sql . ' LIMIT 1', $params);
        return $rows[0] ?? null;
    }

    /**
     * Bulk insert TSV data by streaming from a file.
     */
    public function insertTsv(string $table, string $tsvFilePath, array $columns): void
    {
        $cols = implode(', ', $columns);
        $sql = "INSERT INTO {$table} ({$cols}) FORMAT TabSeparated";

        $fileSize = filesize($tsvFilePath);
        $fh = fopen($tsvFilePath, 'r');
        if ($fh === false) {
            throw new \RuntimeException("Cannot read TSV file: {$tsvFilePath}");
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $this->baseUrl . '/?database=' . urlencode($this->database)
                         . '&query=' . urlencode($sql),
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/octet-stream',
                'Content-Length: ' . $fileSize,
            ],
            CURLOPT_READFUNCTION => function ($ch, $fh_inner, $length) use ($fh) {
                return fread($fh, $length);
            },
            CURLOPT_TIMEOUT => 600,
        ]);
        $response = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        fclose($fh);

        if ($error) {
            throw new \RuntimeException("ClickHouse TSV upload failed: {$error}");
        }
        if ($code !== 200) {
            throw new \RuntimeException("ClickHouse TSV insert failed ({$code}): {$response}");
        }
    }

    /**
     * Check if ClickHouse is reachable.
     */
    public function isAvailable(): bool
    {
        try {
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $this->baseUrl . '/ping',
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 3,
                CURLOPT_CONNECTTIMEOUT => 2,
            ]);
            $response = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            return $code === 200;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Bind positional ? parameters into SQL with proper escaping.
     */
    private function bindParams(string $sql, array $params): string
    {
        if (empty($params)) return $sql;

        $i = 0;
        return preg_replace_callback('/\?/', function () use ($params, &$i) {
            $val = $params[$i++] ?? null;
            if (is_null($val)) return 'NULL';
            if (is_int($val) || is_float($val)) return (string) $val;
            // Escape for ClickHouse: single quotes, backslashes
            $escaped = str_replace(['\\', "'"], ['\\\\', "\\'"], (string) $val);
            return "'{$escaped}'";
        }, $sql);
    }

    /**
     * Core HTTP request to ClickHouse.
     */
    private function request(string $sql): string
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $this->baseUrl . '/?database=' . urlencode($this->database),
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $sql,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 300,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new \RuntimeException("ClickHouse connection failed: {$error}");
        }
        if ($httpCode !== 200) {
            throw new \RuntimeException("ClickHouse error ({$httpCode}): {$response}");
        }
        return $response;
    }
}
