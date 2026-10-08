<?php

namespace BBS\Core;

class Migrator
{
    private Database $db;
    private string $migrationsPath;

    /** Statements skipped because what they create already existed. */
    public array $skipped = [];

    /** Migrations that genuinely failed. These are NOT recorded as executed. */
    public array $errors = [];

    /**
     * MySQL errors that mean "this change is already in place".
     *
     * A migration re-running over a database that already has the change —
     * from schema.sql on a fresh install, from manual setup, or from a
     * previous partial run — is not a failure, so these are tolerated per
     * statement and the migration still counts as applied.
     *
     * Anything not in this list is a real failure and is treated as one.
     */
    private const ALREADY_APPLIED = [
        1050, // table already exists
        1060, // duplicate column name
        1061, // duplicate key name
        1022, // duplicate key
        1826, // duplicate foreign key constraint name
        1091, // can't DROP; check that column/key exists
        1359, // trigger already exists
        1517, // duplicate partition name
    ];

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->migrationsPath = dirname(__DIR__, 2) . '/migrations';
        $this->ensureMigrationsTable();
    }

    private function ensureMigrationsTable(): void
    {
        $this->db->getPdo()->exec("
            CREATE TABLE IF NOT EXISTS migrations (
                id INT AUTO_INCREMENT PRIMARY KEY,
                filename VARCHAR(255) NOT NULL UNIQUE,
                executed_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ");
    }

    /**
     * Split a migration file into individual statements.
     *
     * Statements are run one at a time rather than handing the whole file to
     * PDO::exec(). With one call, a failure part-way through leaves the earlier
     * statements applied and the later ones not — and nothing records which.
     * Per-statement execution means a benign "already exists" can be stepped
     * over while a real error still stops the file.
     *
     * Quotes, backticks and comments are tracked so a semicolon inside any of
     * them isn't mistaken for a statement boundary.
     */
    public static function splitStatements(string $sql): array
    {
        $statements = [];
        $current = '';
        $len = strlen($sql);
        $inSingle = $inDouble = $inBacktick = $inLineComment = $inBlockComment = false;

        for ($i = 0; $i < $len; $i++) {
            $char = $sql[$i];
            $next = $i + 1 < $len ? $sql[$i + 1] : '';

            if ($inLineComment) {
                if ($char === "\n") {
                    $inLineComment = false;
                    $current .= $char;
                }
                continue;
            }
            if ($inBlockComment) {
                if ($char === '*' && $next === '/') {
                    $inBlockComment = false;
                    $i++;
                }
                continue;
            }

            if (!$inSingle && !$inDouble && !$inBacktick) {
                // `-- ` and `#` start a line comment; `/*` a block comment
                if (($char === '-' && $next === '-') || $char === '#') {
                    $inLineComment = true;
                    continue;
                }
                if ($char === '/' && $next === '*') {
                    $inBlockComment = true;
                    $i++;
                    continue;
                }
                if ($char === ';') {
                    if (trim($current) !== '') {
                        $statements[] = trim($current);
                    }
                    $current = '';
                    continue;
                }
            }

            // Quote tracking. A backslash escapes the next character inside
            // single/double quotes, so it is consumed with it.
            if ($char === '\\' && ($inSingle || $inDouble)) {
                $current .= $char;
                if ($next !== '') {
                    $current .= $next;
                    $i++;
                }
                continue;
            }
            if ($char === "'" && !$inDouble && !$inBacktick)  $inSingle = !$inSingle;
            elseif ($char === '"' && !$inSingle && !$inBacktick) $inDouble = !$inDouble;
            elseif ($char === '`' && !$inSingle && !$inDouble)   $inBacktick = !$inBacktick;

            $current .= $char;
        }

        if (trim($current) !== '') {
            $statements[] = trim($current);
        }
        return $statements;
    }

    public function run(): array
    {
        $sqlFiles = glob($this->migrationsPath . '/*.sql');
        $phpFiles = glob($this->migrationsPath . '/*.php');
        $files = array_merge($sqlFiles ?: [], $phpFiles ?: []);
        sort($files);

        $executed = array_column(
            $this->db->fetchAll("SELECT filename FROM migrations"),
            'filename'
        );

        $ran = [];
        $this->errors = [];
        $this->skipped = [];

        foreach ($files as $file) {
            $filename = basename($file);
            if (in_array($filename, $executed)) {
                continue;
            }

            try {
                if (str_ends_with($file, '.php')) {
                    $db = $this->db;
                    require $file;
                } else {
                    $this->runSqlFile($file, $filename);
                }
            } catch (\Exception $e) {
                // A real failure. The migration is deliberately NOT recorded,
                // so it runs again next time rather than being silently
                // skipped forever with the change half-applied. Statements
                // that already succeeded are re-run, which is safe: they fail
                // with "already exists" and are stepped over.
                $this->errors[] = $filename . ': ' . $e->getMessage();
                continue;
            }

            $this->db->insert('migrations', ['filename' => $filename]);
            $ran[] = $filename;
        }

        return $ran;
    }

    /**
     * One-time catch-up for a Docker install from before migrations were
     * recorded there (#532).
     *
     * Until 2.98.5 the container fed every .sql migration to mysql on each
     * start, and mysql stops a file at its first error. So on every start
     * each file ran up to the first statement whose change was already in
     * place, and never past it. Recording nothing, those installs reached
     * 2.98.5 with old migrations unrecorded, and run() replayed them in full,
     * stepping over "already exists" and on into data changes meant for the
     * schema of years ago (#532: ssh_home_dir rewritten, schedule timezones
     * reset, permissions widened).
     *
     * This applies the old rule once more and records the result: every
     * unrecorded .sql file numbered up to $upTo runs until its first error
     * and is then recorded as applied. A file the install never had runs to
     * the end, as it would have under the old loop. PHP migrations are not
     * touched; run() handles them.
     *
     * @return string[] what was recorded, one line per file
     */
    public function runLegacyRawLoop(int $upTo): array
    {
        $executed = array_column($this->db->fetchAll("SELECT filename FROM migrations"), 'filename');
        $files = glob($this->migrationsPath . '/*.sql') ?: [];
        sort($files);

        $recorded = [];
        $pdo = $this->db->getPdo();
        foreach ($files as $file) {
            $filename = basename($file);
            if (in_array($filename, $executed, true) || (int) $filename > $upTo) {
                continue;
            }
            $sql = file_get_contents($file);
            if ($sql === false) {
                continue;
            }
            $stoppedAt = null;
            foreach (self::splitStatements($sql) as $index => $statement) {
                try {
                    $pdo->exec($statement);
                } catch (\PDOException $e) {
                    $stoppedAt = $index + 1;
                    break;
                }
            }
            $this->db->insert('migrations', ['filename' => $filename]);
            $recorded[] = $stoppedAt === null
                ? $filename
                : "{$filename} (stopped at statement {$stoppedAt}: already in place)";
        }
        return $recorded;
    }

    /**
     * Run one .sql file statement by statement.
     *
     * Statements whose object already exists are recorded and stepped over.
     * Anything else throws, which leaves the migration unrecorded.
     */
    private function runSqlFile(string $file, string $filename): void
    {
        $sql = file_get_contents($file);
        if ($sql === false) {
            throw new \RuntimeException("Cannot read migration file");
        }

        $pdo = $this->db->getPdo();
        foreach (self::splitStatements($sql) as $index => $statement) {
            try {
                $pdo->exec($statement);
            } catch (\PDOException $e) {
                $code = isset($e->errorInfo[1]) ? (int) $e->errorInfo[1] : 0;
                // A seed INSERT whose rows are already there (schema.sql on a
                // fresh install inserts the same default settings). Retry it
                // as INSERT IGNORE: rows that exist are kept, rows that don't
                // are still added, so the migration can be recorded. Only for
                // INSERT: a duplicate while adding a UNIQUE index is a real
                // failure and still throws.
                if ($code === 1062 && preg_match('/^\s*INSERT\s+INTO\b/i', $statement)) {
                    $pdo->exec(preg_replace('/^\s*INSERT\s+INTO\b/i', 'INSERT IGNORE INTO', $statement, 1));
                    $this->skipped[] = sprintf('%s statement %d: rows already present', $filename, $index + 1);
                    continue;
                }
                // A column rename that already happened: the old name is gone
                // and the new one is there (schema.sql on a fresh install is
                // already past the rename). Any other unknown column is real.
                if ($code === 1054 && $this->renameAlreadyApplied($statement)) {
                    $this->skipped[] = sprintf('%s statement %d: column already renamed', $filename, $index + 1);
                    continue;
                }
                if (in_array($code, self::ALREADY_APPLIED, true)) {
                    $this->skipped[] = sprintf(
                        '%s statement %d: %s',
                        $filename,
                        $index + 1,
                        $this->firstLine($e->getMessage())
                    );
                    continue;
                }
                throw new \RuntimeException(sprintf(
                    'statement %d failed (%s) — %s | SQL: %s',
                    $index + 1,
                    $code,
                    $this->firstLine($e->getMessage()),
                    $this->firstLine($statement)
                ), 0, $e);
            }
        }
    }

    /**
     * True when $statement is ALTER TABLE ... CHANGE [COLUMN] old new and the
     * table has `new` but not `old`.
     */
    private function renameAlreadyApplied(string $statement): bool
    {
        if (!preg_match('/^\s*ALTER\s+TABLE\s+`?(\w+)`?\s.*?\bCHANGE\s+(?:COLUMN\s+)?`?(\w+)`?\s+`?(\w+)`?/is', $statement, $m)) {
            return false;
        }
        [, $table, $old, $new] = $m;
        $cols = array_column($this->db->fetchAll("SHOW COLUMNS FROM `{$table}`"), 'Field');
        return in_array($new, $cols, true) && !in_array($old, $cols, true);
    }

    private function firstLine(string $text): string
    {
        $line = trim(strtok($text, "\n") ?: '');
        return strlen($line) > 200 ? substr($line, 0, 200) . '…' : $line;
    }
}
