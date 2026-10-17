<?php

namespace BBS\Services;

use BBS\Core\Database;

/**
 * Executes one restore check run in a scheduler worker (`scheduler.php
 * --job=N`), and reconciles runs that a dead worker left behind.
 *
 * A run: pin the newest eligible archive, confirm it in the live repository,
 * list the selected items, refuse unsafe layouts and budgets, extract into
 * private staging as the repository's own user, validate what came out
 * against the listing, delete the staging, and record the evidence.
 *
 * Nothing is ever extracted as root, onto a client, or outside
 * /var/bbs/restore-checks/<token>/data. Restored content is never executed,
 * kept, attached to a notification or put in a report.
 */
class RestoreCheckRunner
{
    public const REPORT_SCHEMA_VERSION = 1;
    public const STAGING_ROOT = '/var/bbs/restore-checks';
    private const HELPER = '/usr/local/bin/bbs-ssh-helper';
    private const MAX_EXAMPLES = 20;
    private const MAX_DIAGNOSTIC_LINES = 40;
    private const CLEANUP_MAX_ATTEMPTS = 3;
    /** Disk, cancellation and deadline checks while borg runs. */
    private const POLL_SECONDS = 2.0;

    private Database $db;

    /** The run row, the job row and their context while executing. */
    private array $run = [];
    private array $snapshot = [];
    private array $repo = [];
    private ?array $remoteConfig = null;
    private ?string $passphrase = null;
    private float $deadline = 0;
    private float $startedMono = 0;
    private ?string $itemsFile = null;
    /** @var string[] */
    private array $diagnostics = [];
    private array $warnings = [];
    private array $limitations = [];

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? Database::getInstance();
    }

    // ==================================================================
    // Entry point
    // ==================================================================

    /** Called by the worker once it has claimed the job (status 'running'). */
    public function executeJob(int $jobId): void
    {
        $run = $this->db->fetchOne("SELECT * FROM restore_check_runs WHERE backup_job_id = ?", [$jobId]);
        if (!$run) {
            $this->db->query(
                "UPDATE backup_jobs SET status = 'failed', completed_at = NOW(), error_log = ? WHERE id = ? AND status = 'running'",
                ['No restore check run is linked to this job.', $jobId]
            );
            return;
        }
        if ($run['result'] !== null) {
            return;
        }
        // New checks never start in maintenance mode. The job goes back in
        // the queue, which promotion leaves alone until maintenance ends.
        if ($this->setting('maintenance_mode') === '1') {
            $this->db->query(
                "UPDATE backup_jobs SET status = 'queued', started_at = NULL, worker_pid = NULL, worker_host = NULL WHERE id = ? AND status = 'running'",
                [$jobId]
            );
            return;
        }

        $this->run = $run;
        $this->snapshot = json_decode((string) $run['config_snapshot'], true) ?: [];
        $this->startedMono = microtime(true);
        $timeout = max(60, (int) ($this->snapshot['run_timeout_minutes'] ?? 120) * 60);
        $this->deadline = $this->startedMono + $timeout;
        $this->limitations = self::standingLimitations();

        $this->updateRun(['phase' => 'preparing', 'started_at' => $this->db->now()]);
        $this->log('info', "Restore check run #{$run['id']} started");

        $outcome = null;
        try {
            $outcome = $this->execute();
        } catch (RestoreCheckStop $stop) {
            $outcome = $stop->outcome();
        } catch (\Throwable $e) {
            $outcome = ['result' => 'failed', 'reason' => 'internal_error', 'detail' => $this->redact($e->getMessage())];
        } finally {
            if ($this->itemsFile !== null) {
                @unlink($this->itemsFile);
            }
        }

        // Staging is removed on every path out, including failures and
        // cancellations. A failed cleanup is its own visible outcome.
        if ((int) ($this->run['staging_created'] ?? 0) === 1) {
            $this->updateRun(['phase' => 'cleaning']);
            $this->cleanup($this->run);
            $this->run = $this->db->fetchOne("SELECT * FROM restore_check_runs WHERE id = ?", [$this->run['id']]) ?? $this->run;
        }

        $this->finalize($outcome);
    }

    // ==================================================================
    // The run
    // ==================================================================

    private function execute(): array
    {
        $this->authorize();
        $this->loadRepository();
        $borgVersion = $this->checkBorgVersion();

        // Staging is recorded before it exists, so a worker that dies right
        // after creating it still leaves a row that reconciliation cleans up.
        $owner = $this->remoteConfig ? 'www-data' : $this->repo['ssh_unix_user'];
        $this->updateRun(['staging_created' => 1, 'cleanup_status' => 'pending', 'borg_version' => $borgVersion]);
        $prepared = $this->helper(['prepare', $this->run['run_token'], $owner]);
        if ($prepared['exit'] !== 0) {
            throw RestoreCheckStop::failed('staging_unavailable', 'The staging directory could not be created: ' . $prepared['out']);
        }
        $dataDir = self::STAGING_ROOT . '/' . $this->run['run_token'] . '/data';

        // Archive: newest eligible, pinned before anything is read.
        $this->updateRun(['phase' => 'selecting']);
        $archive = $this->selectArchive();
        $this->confirmLiveArchive($archive, $dataDir);
        $this->checkCancelled();

        // Inventory of what will be extracted.
        $this->updateRun(['phase' => 'listing']);
        $inventory = $this->listItems($archive, $dataDir);
        $this->checkBudgets($inventory, $dataDir);
        $this->checkCancelled();

        // Extraction.
        $this->updateRun(['phase' => 'extracting']);
        $tExtract = microtime(true);
        $exit = $this->extract($archive, $dataDir, $inventory);
        $extractionSeconds = microtime(true) - $tExtract;
        $this->helper(['grant-read', $this->run['run_token']]);
        $extractionStatus = match (true) {
            $exit === 0 => 'ok',
            $exit === 1 => 'warning',
            default => 'failed',
        };
        $this->updateRun([
            'borg_exit_code' => $exit,
            'extraction_seconds' => round($extractionSeconds, 3),
            'extraction_status' => $extractionStatus,
        ]);
        if ($exit === 1) {
            $this->warn('borg_warning', 'borg extract finished with warnings (exit code 1). See the diagnostic lines.');
        }
        if ($exit >= 2) {
            // Validation still runs: it says which items did and did not
            // come back, which is the useful part of the evidence.
            $this->diagnostics[] = "borg extract exited with code {$exit}";
        }
        $this->checkCancelled();

        // Validation.
        $this->updateRun(['phase' => 'validating']);
        $tValidate = microtime(true);
        $validation = $this->validate($inventory, $dataDir);
        $validationSeconds = microtime(true) - $tValidate;

        $this->updateRun([
            'validation_seconds' => round($validationSeconds, 3),
            'validation_status' => $validation['status'],
            'extracted_files' => $validation['found_entries'],
            'extracted_bytes' => $validation['found_bytes'],
            'probe_summary' => json_encode($validation['summary'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
        ]);

        if ($exit >= 2) {
            return ['result' => 'failed', 'reason' => 'borg_error', 'detail' => "borg extract failed with exit code {$exit}."];
        }
        if ($validation['status'] === 'failed') {
            return ['result' => 'failed', 'reason' => $validation['reason'], 'detail' => $validation['detail']];
        }
        if ($this->warnings) {
            return ['result' => 'warning', 'reason' => $this->warnings[0]['code'], 'detail' => $this->warnings[0]['message']];
        }
        return ['result' => 'passed', 'reason' => null, 'detail' => null];
    }

    /**
     * The user the run acts for must still be allowed to restore from this
     * client: whoever pressed Run now, or the plan's owner for a scheduled run.
     */
    private function authorize(): void
    {
        $agentId = (int) $this->run['agent_id'];
        $userId = $this->run['trigger_type'] === 'manual'
            ? (int) ($this->run['requested_by'] ?? 0)
            : (int) ($this->db->fetchOne("SELECT owner_user_id FROM restore_check_plans WHERE id = ?", [(int) $this->run['plan_id']])['owner_user_id'] ?? 0);
        if ($userId <= 0 || !$this->db->fetchOne("SELECT id FROM users WHERE id = ?", [$userId])
            || !(new PermissionService())->hasPermission($userId, PermissionService::RESTORE, $agentId)) {
            throw RestoreCheckStop::failed('unauthorized', 'The user this run acts for no longer has the Perform Restores permission for this client.');
        }
    }

    private function loadRepository(): void
    {
        $repoId = (int) ($this->snapshot['repository_id'] ?? 0);
        $repo = $this->db->fetchOne("
            SELECT r.*, a.ssh_unix_user,
                   rsc.remote_host, rsc.remote_port, rsc.remote_user,
                   rsc.ssh_private_key_encrypted AS remote_ssh_key_encrypted, rsc.borg_remote_path
            FROM repositories r
            JOIN agents a ON a.id = r.agent_id
            LEFT JOIN remote_ssh_configs rsc ON rsc.id = r.remote_ssh_config_id
            WHERE r.id = ? AND r.agent_id = ?
        ", [$repoId, (int) $this->run['agent_id']]);
        if (!$repo) {
            throw RestoreCheckStop::failed('repository_missing', 'The repository no longer exists.');
        }
        if (!empty($repo['read_only'])) {
            throw RestoreCheckStop::failed('repository_read_only', 'Read-only repository copies are not supported by restore checks yet.');
        }
        $this->repo = $repo;

        if (($repo['storage_type'] ?? 'local') === 'remote_ssh') {
            if (empty($repo['remote_host'])) {
                throw RestoreCheckStop::failed('repository_unsupported', 'The repository\'s Remote SSH host is missing.');
            }
            $this->remoteConfig = [
                'remote_host' => $repo['remote_host'],
                'remote_port' => $repo['remote_port'],
                'remote_user' => $repo['remote_user'],
                'ssh_private_key_encrypted' => $repo['remote_ssh_key_encrypted'],
                'borg_remote_path' => $repo['borg_remote_path'],
            ];
            $this->repo['borg_target'] = $repo['path'];
        } else {
            $local = BorgCommandBuilder::getLocalRepoPath($repo);
            if ($local === null || $local === '' || !str_starts_with($local, '/')) {
                throw RestoreCheckStop::failed('repository_unsupported', 'The repository has no local path on this server.');
            }
            if (!preg_match('/^bbs-[a-z0-9_-]+$/', (string) $repo['ssh_unix_user'])) {
                // Without the client's own Unix user, borg would have to run
                // as www-data or root against the repository. Neither is
                // acceptable for unattended extraction.
                throw RestoreCheckStop::failed('repository_unsupported', 'This client has no dedicated Unix user, which restore checks require.');
            }
            $this->repo['borg_target'] = $local;
        }

        $env = BorgCommandBuilder::buildEnv($repo, false);
        $this->passphrase = $env['BORG_PASSPHRASE'] ?? '';
    }

    private function checkBorgVersion(): string
    {
        $out = trim((string) @shell_exec('borg --version 2>/dev/null'));
        $version = preg_replace('/^borg(-[a-z.]+)?\s+/i', '', $out);
        if (!preg_match('/^1\.(\d+)/', (string) $version, $m) || (int) $m[1] < 2) {
            throw RestoreCheckStop::failed(
                'unsupported_borg_version',
                'Restore checks support borg 1.2 to 1.4 on the BBS server; found ' . ($version !== '' ? $version : 'no borg') . '.'
            );
        }
        return (string) $version;
    }

    // ------------------------------------------------------------------
    // Archive selection
    // ------------------------------------------------------------------

    /**
     * The newest archive that a completed BBS backup created, in scope.
     * Failed backups never leave archives in this table; warning backups
     * are only eligible by opt-in; borg checkpoints and imported archives
     * (no backup job) never are. Ties break on the archive row id.
     */
    private function selectArchive(): array
    {
        $params = [(int) $this->snapshot['repository_id']];
        $planClause = '';
        if (($this->snapshot['scope'] ?? 'plan') === 'plan') {
            if (empty($this->snapshot['backup_plan_id'])) {
                throw RestoreCheckStop::failed('source_plan_missing', 'The backup plan this check tested has been deleted.');
            }
            $planClause = 'AND bj.backup_plan_id = ?';
            $params[] = (int) $this->snapshot['backup_plan_id'];
        }
        $candidates = $this->db->fetchAll("
            SELECT a.id, a.archive_name, a.created_at, a.backup_job_id,
                   bj.had_warnings, bj.completed_at AS job_completed_at
            FROM archives a
            JOIN backup_jobs bj ON bj.id = a.backup_job_id
            WHERE a.repository_id = ?
              AND bj.task_type = 'backup' AND bj.status = 'completed'
              {$planClause}
            ORDER BY a.created_at DESC, a.id DESC
            LIMIT 200
        ", $params);

        $skipped = ['warnings' => 0, 'checkpoint' => 0];
        $chosen = null;
        foreach ($candidates as $c) {
            if (str_contains($c['archive_name'], '.checkpoint')) {
                $skipped['checkpoint']++;
                continue;
            }
            if ((int) $c['had_warnings'] === 1 && empty($this->snapshot['allow_warning_archives'])) {
                $skipped['warnings']++;
                continue;
            }
            $chosen = $c;
            break;
        }

        $imported = (int) ($this->db->fetchOne(
            "SELECT COUNT(*) AS n FROM archives WHERE repository_id = ? AND backup_job_id IS NULL",
            [(int) $this->snapshot['repository_id']]
        )['n'] ?? 0);
        if ($imported > 0) {
            $this->note('imported_archives_excluded', "{$imported} archive(s) in this repository have no BBS backup record (imported or catalogued); they are never selected.");
        }
        if ($skipped['warnings'] > 0) {
            $this->note('newer_archives_ineligible', "{$skipped['warnings']} newer archive(s) came from backups that finished with warnings and were not eligible.");
        }

        if ($chosen === null) {
            throw RestoreCheckStop::failed('no_eligible_archive', 'No archive from a successful BBS backup is available in scope.');
        }

        $ageSeconds = max(0, time() - strtotime($chosen['created_at'] . ' UTC'));
        $sourceJob = (int) $chosen['backup_job_id'];
        $this->updateRun([
            'archive_db_id' => (int) $chosen['id'],
            'archive_name' => $chosen['archive_name'],
            'archive_time' => $chosen['created_at'],
            'archive_age_seconds' => $ageSeconds,
            'source_job_id' => $sourceJob,
            'source_had_warnings' => (int) $chosen['had_warnings'],
        ]);
        $this->log('info', "Restore check run #{$this->run['id']}: testing archive {$chosen['archive_name']}");

        if ((int) $chosen['had_warnings'] === 1) {
            $this->warn('source_backup_warnings', 'The tested archive came from a backup that finished with warnings (allowed by this plan).');
        }
        $maxAge = (int) ($this->snapshot['max_archive_age_hours'] ?? 48) * 3600;
        if ($ageSeconds > $maxAge) {
            throw RestoreCheckStop::failed(
                'archive_too_old',
                sprintf('The newest eligible archive is %.1f hours old; the limit is %d hours.', $ageSeconds / 3600, $maxAge / 3600)
            );
        }
        return $chosen;
    }

    /**
     * The database can lag the repository (prune, imports, deletes). The
     * pinned archive must exist in the live repository; if it does not, the
     * run fails rather than testing an older one.
     */
    private function confirmLiveArchive(array $archive, string $dataDir): void
    {
        $res = $this->borg('archives', $this->repo['borg_target'], [], $dataDir);
        if ($res['exit'] !== 0) {
            throw RestoreCheckStop::failed(
                $res['exit'] === 124 ? 'timeout' : 'repository_unavailable',
                'Listing the live repository failed (exit ' . $res['exit'] . '): ' . $this->tail($res['stderr'])
            );
        }
        $data = json_decode($res['stdout'], true);
        if (!is_array($data) || !isset($data['archives'])) {
            throw RestoreCheckStop::failed('repository_unavailable', 'borg list did not return archive JSON.');
        }
        $live = null;
        $newerUnknown = 0;
        $known = array_flip(array_column($this->db->fetchAll(
            "SELECT archive_name FROM archives WHERE repository_id = ?",
            [(int) $this->snapshot['repository_id']]
        ), 'archive_name'));
        foreach ($data['archives'] as $a) {
            if (($a['name'] ?? null) === $archive['archive_name']) {
                $live = $a;
            }
        }
        if ($live === null) {
            throw RestoreCheckStop::failed('archive_missing', "Archive {$archive['archive_name']} is in the BBS catalog but not in the repository. It was not replaced with an older one.");
        }
        foreach ($data['archives'] as $a) {
            if (!isset($known[$a['name'] ?? '']) && ($a['time'] ?? '') > ($live['time'] ?? '')) {
                $newerUnknown++;
            }
        }
        if ($newerUnknown > 0) {
            $this->note('catalog_behind', "The live repository has {$newerUnknown} newer archive(s) that BBS has not recorded.");
        }
        $borgId = (string) ($live['id'] ?? '');
        $this->updateRun(['borg_archive_id' => preg_match('/^[0-9a-f]{64}$/', $borgId) ? $borgId : null]);
    }

    // ------------------------------------------------------------------
    // Inventory
    // ------------------------------------------------------------------

    /** pp: is borg's literal path-prefix style: no wildcard, regex or option reading. */
    private function patterns(): array
    {
        if (($this->snapshot['mode'] ?? 'paths') === 'full') {
            return [];
        }
        return array_map(fn($p) => 'pp:' . $p['path'], $this->snapshot['paths'] ?? []);
    }

    /**
     * List what the extraction will produce, streamed to a temp file of
     * compact records so a full archive with millions of items never sits
     * in memory.
     */
    private function listItems(array $archive, string $dataDir): array
    {
        $mode = $this->snapshot['mode'] ?? 'paths';
        $probes = [];
        foreach ($this->snapshot['paths'] ?? [] as $p) {
            $probes[$p['path']] = ['path' => $p['path'], 'sha256' => $p['sha256'] ?? null, 'matched' => 0, 'type' => null, 'size' => null];
        }
        if ($mode === 'paths' && !$probes) {
            throw RestoreCheckStop::failed('no_paths', 'Selected paths mode has no paths. It is never widened to a full archive.');
        }

        $tmpDir = is_dir('/var/bbs/tmp') && is_writable('/var/bbs/tmp') ? '/var/bbs/tmp' : sys_get_temp_dir();
        $this->itemsFile = $tmpDir . '/bbs-rc-' . $this->run['run_token'] . '.items';
        $fh = fopen($this->itemsFile, 'xb');
        if ($fh === false) {
            throw RestoreCheckStop::failed('staging_unavailable', 'Could not create the inventory file.');
        }
        @chmod($this->itemsFile, 0600);

        $inv = [
            'items' => 0, 'files' => 0, 'dirs' => 0, 'symlinks' => 0, 'hardlinks' => 0, 'fifos' => 0, 'special' => 0,
            'bytes' => 0, 'undecodable' => 0, 'symlink_paths' => [], 'over_limit' => false,
        ];
        $maxFiles = (int) $this->snapshot['max_files'];
        $maxBytes = (int) $this->snapshot['max_bytes'];

        $onLine = function (string $line) use ($fh, &$inv, &$probes, $maxFiles, $maxBytes) {
            if ($line === '') {
                return;
            }
            [$item, $undecodable] = self::decodeItem($line);
            if (!is_array($item) || !isset($item['path'])) {
                throw RestoreCheckStop::failed('listing_failed', 'borg list returned an item that could not be read.');
            }
            if ($undecodable) {
                $inv['undecodable']++;
            }
            $path = (string) $item['path'];
            if ($path === '.' || $path === '') {
                return;
            }
            $kind = self::kind($item);
            $size = (int) ($item['size'] ?? 0);
            $inv['items']++;
            match ($kind) {
                'file' => $inv['files']++,
                'dir' => $inv['dirs']++,
                'symlink' => $inv['symlinks']++,
                'hardlink' => $inv['hardlinks']++,
                'fifo' => $inv['fifos']++,
                default => $inv['special']++,
            };
            if ($kind === 'file') {
                $inv['bytes'] += $size;
            }
            if ($kind === 'symlink') {
                $inv['symlink_paths'][$path] = true;
            }
            if ($inv['items'] > $maxFiles || $inv['bytes'] > $maxBytes) {
                $inv['over_limit'] = true;
            }
            foreach ($probes as $pp => &$probe) {
                if ($path === $pp || str_starts_with($path, $pp . '/')) {
                    $probe['matched']++;
                    if ($path === $pp) {
                        $probe['type'] = $kind;
                        $probe['size'] = $size;
                    }
                }
            }
            unset($probe);
            fwrite($fh, json_encode([
                'p' => $path,
                'k' => $kind,
                's' => $size,
                't' => in_array($kind, ['symlink', 'hardlink'], true) ? (string) ($item['linktarget'] ?? $item['source'] ?? '') : null,
                'u' => $undecodable ? 1 : 0,
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
        };

        $res = $this->borg('items', $this->repo['borg_target'] . '::' . $archive['archive_name'], $this->patterns(), $dataDir, $onLine);
        fclose($fh);
        if ($res['exit'] !== 0) {
            throw RestoreCheckStop::failed(
                $res['exit'] === 124 ? 'timeout' : 'listing_failed',
                'Listing the archive failed (exit ' . $res['exit'] . '): ' . $this->tail($res['stderr'])
            );
        }

        $missing = array_keys(array_filter($probes, fn($p) => $p['matched'] === 0));
        if ($missing) {
            throw RestoreCheckStop::failed(
                'probe_missing',
                'Not in the archive: ' . implode(', ', array_slice($missing, 0, 10)) . (count($missing) > 10 ? ' …' : ''),
                ['probes' => array_values($probes)]
            );
        }
        if ($inv['items'] === 0) {
            throw RestoreCheckStop::failed('empty_selection', 'The selection matched nothing in the archive.');
        }
        foreach ($probes as $pp => $probe) {
            if ($probe['sha256'] !== null && $probe['type'] !== 'file') {
                throw RestoreCheckStop::failed('probe_type_mismatch', "An expected SHA-256 is set for '{$pp}', which is not a regular file in this archive.");
            }
        }
        if ($inv['undecodable'] > 0) {
            $this->warn('undecodable_names', "{$inv['undecodable']} item name(s) are not valid UTF-8 and could not be validated.");
        }
        if ($inv['special'] > 0) {
            $this->warn('special_files_unverified', "{$inv['special']} device node(s) or socket(s) were selected; they cannot be created without root and are not verified.");
        }

        $this->checkLayout($inv);

        // borg creates the parents of a selected path (etc/ for etc/hostname)
        // without listing them; they are expected in staging too.
        $implied = [];
        foreach (array_keys($probes) as $pp) {
            $parent = $pp;
            while (($pos = strrpos($parent, '/')) !== false) {
                $parent = substr($parent, 0, $pos);
                $implied[$parent] = true;
            }
        }
        foreach (array_keys($implied) as $dir) {
            if (isset($probes[$dir]) || $this->listed($dir)) {
                unset($implied[$dir]);
            }
        }
        $inv['implied_dirs'] = count($implied);

        $inv['probes'] = $probes;
        $this->updateRun(['expected_files' => $inv['items'], 'expected_bytes' => $inv['bytes']]);
        unset($inv['symlink_paths']);
        return $inv;
    }

    /**
     * An item stored under a path that is itself a symlink in the same
     * archive would be written through that link, outside staging. borg
     * refuses some of these itself; this check does not rely on it.
     */
    private function checkLayout(array $inv): void
    {
        if (!$inv['symlink_paths']) {
            return;
        }
        $fh = fopen($this->itemsFile, 'rb');
        $bad = [];
        while (($line = fgets($fh)) !== false) {
            $rec = json_decode($line, true);
            $parent = $rec['p'];
            while (($pos = strrpos($parent, '/')) !== false) {
                $parent = substr($parent, 0, $pos);
                if (isset($inv['symlink_paths'][$parent])) {
                    $bad[] = $rec['p'];
                    break;
                }
            }
            if (count($bad) >= self::MAX_EXAMPLES) {
                break;
            }
        }
        fclose($fh);
        if ($bad) {
            throw RestoreCheckStop::failed(
                'unsafe_archive_layout',
                'The archive stores items beneath a symlink (' . implode(', ', array_slice($bad, 0, 5)) . '). Nothing was extracted.'
            );
        }
    }

    /** Is this exact path in the inventory? Only used for a few parent directories. */
    private function listed(string $path): bool
    {
        $needle = '"p":' . json_encode($path, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . ',';
        $fh = fopen($this->itemsFile, 'rb');
        while (($line = fgets($fh)) !== false) {
            if (str_starts_with($line, '{' . $needle)) {
                fclose($fh);
                return true;
            }
        }
        fclose($fh);
        return false;
    }

    private function checkBudgets(array $inv, string $dataDir): void
    {
        $maxFiles = (int) $this->snapshot['max_files'];
        $maxBytes = (int) $this->snapshot['max_bytes'];
        if ($inv['over_limit'] || $inv['items'] > $maxFiles || $inv['bytes'] > $maxBytes) {
            throw RestoreCheckStop::failed('limit_exceeded', sprintf(
                'The selection is %s in %s items; this check allows %s in %s items.',
                self::bytes($inv['bytes']), number_format($inv['items']), self::bytes($maxBytes), number_format($maxFiles)
            ));
        }
        [$freeBytes, $freeInodes] = self::freeSpace($dataDir);
        $reserve = (int) $this->snapshot['min_free_bytes'];
        if ($freeBytes === null) {
            throw RestoreCheckStop::failed('staging_unavailable', 'Free space on the staging filesystem could not be read.');
        }
        // Logical sizes from the archive, plus a margin for directories and
        // filesystem overhead.
        $needed = (int) ($inv['bytes'] * 1.05) + 4096 * ($inv['dirs'] + $inv['items']);
        if ($freeBytes - $needed < $reserve) {
            throw RestoreCheckStop::failed('insufficient_space', sprintf(
                'Extraction needs about %s and %s must stay free; the staging filesystem has %s free.',
                self::bytes($needed), self::bytes($reserve), self::bytes($freeBytes)
            ));
        }
        if ($freeInodes !== null && $freeInodes < $inv['items'] + 1000) {
            throw RestoreCheckStop::failed('insufficient_inodes', "Extraction needs {$inv['items']} inodes; the staging filesystem has {$freeInodes} free.");
        }
    }

    // ------------------------------------------------------------------
    // Extraction
    // ------------------------------------------------------------------

    private function extract(array $archive, string $dataDir, array $inv): int
    {
        $reserve = (int) $this->snapshot['min_free_bytes'];
        $maxBytes = (int) $this->snapshot['max_bytes'];
        $maxFiles = (int) $this->snapshot['max_files'];
        $listed = 0;
        $current = 0;
        $lastWrite = 0.0;

        $onStderr = function (string $line) use (&$listed, &$current) {
            $msg = json_decode($line, true);
            if (!is_array($msg)) {
                if (trim($line) !== '') {
                    $this->diag($line);
                }
                return;
            }
            if (($msg['type'] ?? '') === 'progress_percent' && isset($msg['current'])) {
                $current = (int) $msg['current'];
                return;
            }
            if (($msg['type'] ?? '') === 'log_message') {
                $level = $msg['levelname'] ?? 'INFO';
                if ($level === 'INFO') {
                    $listed++;
                } else {
                    $this->diag($level . ': ' . ($msg['message'] ?? ''));
                }
            }
        };

        // Polling can overshoot by one interval's worth of writes; a
        // separate filesystem or quota for the staging root is the hard limit.
        $tick = function () use (&$listed, &$current, &$lastWrite, $dataDir, $reserve, $maxBytes, $maxFiles, $inv) {
            $this->checkCancelled();
            [$free] = self::freeSpace($dataDir);
            if ($free !== null && $free < $reserve) {
                throw RestoreCheckStop::failed('reserve_exhausted', 'Free space on the staging filesystem fell below the reserve during extraction.');
            }
            if ($current > $maxBytes || $listed > $maxFiles + 1) {
                throw RestoreCheckStop::failed('limit_exceeded', 'Extraction went beyond the configured size or file limit.');
            }
            if (microtime(true) - $lastWrite >= 5) {
                $lastWrite = microtime(true);
                $this->db->update('backup_jobs', [
                    'files_total' => min($inv['items'], 2147483647),
                    'files_processed' => min($listed, 2147483647),
                    'bytes_total' => $inv['bytes'],
                    'bytes_processed' => $current,
                    'last_progress_at' => $this->db->now(),
                ], 'id = ?', [$this->run['backup_job_id']]);
            }
        };

        $res = $this->borg('extract', $this->repo['borg_target'] . '::' . $archive['archive_name'], $this->patterns(), $dataDir, null, $onStderr, $tick);
        if ($res['exit'] === 124 || $res['exit'] === 137) {
            throw RestoreCheckStop::failed('timeout', 'The run reached its time limit during extraction.');
        }
        return $res['exit'];
    }

    // ------------------------------------------------------------------
    // Validation
    // ------------------------------------------------------------------

    /**
     * Compare staging with the inventory, item by item, without following
     * any link: type, size, link target, readability of every regular file,
     * and the expected SHA-256 of probe files that have one.
     */
    private function validate(array $inv, string $dataDir): array
    {
        // Compare against the resolved path: /var/bbs itself may be a
        // symlink to the storage volume, which is fine; what matters is that
        // nothing *inside* staging resolves elsewhere.
        $dataDir = realpath($dataDir);
        if ($dataDir === false || is_link($dataDir)) {
            return $this->validationFailed('staging_unavailable', 'The staging directory is missing or was replaced.', [], $inv, 0, 0);
        }

        $problems = ['missing' => 0, 'type' => 0, 'size' => 0, 'unreadable' => 0, 'link' => 0, 'unsafe' => 0];
        $unrepresentable = 0;
        $examples = [];
        $validated = 0;
        $hashes = [];
        $probeFiles = [];
        foreach ($inv['probes'] as $pp => $probe) {
            if ($probe['type'] === 'file') {
                $probeFiles[$pp] = $probe['sha256'];
            }
        }
        $dirOk = [];

        $fh = fopen($this->itemsFile, 'rb');
        $n = 0;
        while (($line = fgets($fh)) !== false) {
            if (++$n % 2000 === 0) {
                $this->checkCancelled();
                $this->touchProgress($n);
            }
            $rec = json_decode($line, true);
            $rel = $rec['p'];
            $full = $dataDir . '/' . $rel;
            $kind = $rec['k'];
            if (!empty($rec['u'])) {
                $unrepresentable++;
                continue;
            }

            // Every parent must resolve to itself inside staging: a symlinked
            // parent would mean the item is somewhere else entirely.
            $parentRel = str_contains($rel, '/') ? substr($rel, 0, strrpos($rel, '/')) : '';
            if (!isset($dirOk[$parentRel])) {
                $expectParent = $parentRel === '' ? $dataDir : $dataDir . '/' . $parentRel;
                $dirOk[$parentRel] = realpath($expectParent) === $expectParent;
                if (count($dirOk) > 100000) {
                    $dirOk = [$parentRel => $dirOk[$parentRel]];
                }
            }
            if (!$dirOk[$parentRel]) {
                $problems['unsafe']++;
                $this->example($examples, $rel, 'parent directory is not inside staging');
                continue;
            }

            $st = @lstat($full);
            if ($st === false) {
                if (in_array($kind, ['device', 'socket'], true)) {
                    continue; // reported as unverified with the inventory
                }
                // borg 1.4 lists bytes that are not UTF-8 as "?", so such a
                // name cannot be found under its listed spelling. Unverified,
                // which is a warning, never a pass.
                if (str_contains($rel, '?')) {
                    $unrepresentable++;
                    continue;
                }
                $problems['missing']++;
                $this->example($examples, $rel, 'missing');
                continue;
            }
            $type = $st['mode'] & 0170000;
            $isLink = $type === 0120000;
            $isDir = $type === 0040000;
            $isReg = $type === 0100000;
            $isFifo = $type === 0010000;

            switch ($kind) {
                case 'dir':
                    if (!$isDir) {
                        $problems['type']++;
                        $this->example($examples, $rel, 'expected a directory');
                    }
                    break;
                case 'symlink':
                    if (!$isLink) {
                        $problems['type']++;
                        $this->example($examples, $rel, 'expected a symlink');
                    } elseif (@readlink($full) !== $rec['t']) {
                        $problems['link']++;
                        $this->example($examples, $rel, 'symlink target differs');
                    }
                    break;
                case 'fifo':
                    if (!$isFifo) {
                        $problems['type']++;
                        $this->example($examples, $rel, 'expected a FIFO');
                    }
                    break;
                case 'device':
                case 'socket':
                    break;
                case 'file':
                case 'hardlink':
                    if (!$isReg) {
                        $problems['type']++;
                        $this->example($examples, $rel, 'expected a regular file');
                        break;
                    }
                    // Hard-link entries carry size 0 in the listing; their
                    // content is the first link's, checked there.
                    if ($kind === 'file' && $st['size'] !== $rec['s']) {
                        $problems['size']++;
                        $this->example($examples, $rel, "size {$st['size']} instead of {$rec['s']}");
                    }
                    $wantHash = array_key_exists($rel, $probeFiles);
                    $read = $this->readFile($full, $st, $wantHash);
                    if ($read === null) {
                        $problems['unreadable']++;
                        $this->example($examples, $rel, 'could not be read');
                    } elseif ($kind === 'file' && $read['bytes'] !== $rec['s']) {
                        $problems['size']++;
                        $this->example($examples, $rel, "read {$read['bytes']} bytes instead of {$rec['s']}");
                    } elseif ($wantHash) {
                        $hashes[$rel] = $read['sha256'];
                    }
                    break;
            }
            $validated++;
        }
        fclose($fh);

        // Probe results, including hashes against independently known values.
        $probeSummary = [];
        $hashMismatch = [];
        foreach ($inv['probes'] as $pp => $probe) {
            $entry = [
                'path' => $pp,
                'type' => $probe['type'] ?? 'directory-prefix',
                'matched_items' => $probe['matched'],
                'expected_size' => $probe['type'] === 'file' ? $probe['size'] : null,
            ];
            if ($probe['type'] === 'file') {
                $actual = $hashes[$pp] ?? null;
                $entry['sha256_actual'] = $actual;
                if ($probe['sha256'] !== null) {
                    $entry['sha256_expected'] = $probe['sha256'];
                    $entry['sha256_status'] = $actual === null ? 'not_read' : (hash_equals($probe['sha256'], $actual) ? 'match' : 'mismatch');
                    if ($entry['sha256_status'] !== 'match') {
                        $hashMismatch[] = $pp;
                    }
                } else {
                    // Computed only from what was restored: a fingerprint for
                    // comparing runs, not proof of correctness.
                    $entry['sha256_status'] = 'fingerprint_only';
                }
            }
            $probeSummary[] = $entry;
        }

        if ($unrepresentable > 0) {
            $this->warn('names_not_verified', "{$unrepresentable} item(s) have names that are not valid UTF-8 and could not be checked individually.");
        }
        [$foundEntries, $foundBytes, $extra] = $this->walkStaging($dataDir, $inv['items'] + $inv['implied_dirs']);
        if ($extra > 0) {
            $this->warn('unexpected_entries', "{$extra} more item(s) were found in staging than the archive listing contained.");
        }

        $summary = [
            'mode' => $this->snapshot['mode'] ?? 'paths',
            'inventory' => [
                'items' => $inv['items'], 'regular_files' => $inv['files'], 'directories' => $inv['dirs'],
                'symlinks' => $inv['symlinks'], 'hardlinks' => $inv['hardlinks'], 'fifos' => $inv['fifos'], 'special' => $inv['special'],
                'bytes' => $inv['bytes'],
            ],
            'validated_items' => $validated,
            'problems' => $problems,
            'examples' => $examples,
            'probes' => $probeSummary,
        ];

        $failedCount = array_sum($problems);
        if ($hashMismatch) {
            return $this->validationFailed('hash_mismatch', 'SHA-256 does not match the expected value for: ' . implode(', ', array_slice($hashMismatch, 0, 5)), $summary, $inv, $foundEntries, $foundBytes);
        }
        if ($failedCount > 0) {
            $reason = $problems['unsafe'] > 0 ? 'unsafe_path' : ($problems['missing'] > 0 ? 'items_missing' : 'validation_failed');
            return $this->validationFailed($reason, "{$failedCount} item(s) did not match the archive listing.", $summary, $inv, $foundEntries, $foundBytes);
        }
        return [
            'status' => $this->warnings ? 'warning' : 'ok',
            'summary' => $summary,
            'found_entries' => $foundEntries,
            'found_bytes' => $foundBytes,
        ];
    }

    private function validationFailed(string $reason, string $detail, array $summary, array $inv, int $entries, int $bytes): array
    {
        return ['status' => 'failed', 'reason' => $reason, 'detail' => $detail, 'summary' => $summary, 'found_entries' => $entries, 'found_bytes' => $bytes];
    }

    /**
     * Read a regular file to the end. Opened only after lstat said it is a
     * regular file, then the open handle is checked to be that same inode,
     * so a swap to a symlink between the two cannot redirect the read.
     */
    private function readFile(string $path, array $lst, bool $hash): ?array
    {
        $fh = @fopen($path, 'rb');
        if ($fh === false) {
            return null;
        }
        $fst = fstat($fh);
        if ($fst === false || $fst['ino'] !== $lst['ino'] || $fst['dev'] !== $lst['dev']) {
            fclose($fh);
            return null;
        }
        $ctx = $hash ? hash_init('sha256') : null;
        $bytes = 0;
        while (!feof($fh)) {
            $chunk = fread($fh, 1048576);
            if ($chunk === false) {
                fclose($fh);
                return null;
            }
            $bytes += strlen($chunk);
            if ($ctx) {
                hash_update($ctx, $chunk);
            }
        }
        fclose($fh);
        return ['bytes' => $bytes, 'sha256' => $ctx ? hash_final($ctx) : null];
    }

    /** Count what is actually in staging, without following links. */
    private function walkStaging(string $dataDir, int $expected): array
    {
        $entries = 0;
        $bytes = 0;
        try {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dataDir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::SELF_FIRST,
                \RecursiveIteratorIterator::CATCH_GET_CHILD
            );
            foreach ($it as $f) {
                /** @var \SplFileInfo $f */
                $entries++;
                if (!$f->isLink() && $f->isFile()) {
                    $bytes += $f->getSize();
                }
                if ($entries > $expected + 100000) {
                    break;
                }
            }
        } catch (\Throwable) {
        }
        return [$entries, $bytes, max(0, $entries - $expected)];
    }

    // ------------------------------------------------------------------
    // borg and the helper
    // ------------------------------------------------------------------

    /**
     * Run a borg operation for this run. Local repositories go through the
     * helper as the repository's user; remote ones run as www-data over SSH
     * with the stored key, the way every other server-side remote job does.
     * Both run with the staging directory as cwd, which is how abort finds
     * them, and both are bounded by the run's remaining time.
     */
    private function borg(string $op, string $target, array $patterns, string $dataDir, ?callable $onStdout = null, ?callable $onStderr = null, ?callable $tick = null): array
    {
        $remaining = (int) floor($this->deadline - microtime(true));
        if ($remaining < 5) {
            throw RestoreCheckStop::failed('timeout', 'The run reached its time limit.');
        }
        $lockWait = (string) max(10, min(300, intdiv($remaining, 4)));

        if ($this->remoteConfig) {
            $args = match ($op) {
                'archives' => ['list', '--lock-wait', $lockWait, '--json', '--', $target],
                'items' => array_merge(['list', '--lock-wait', $lockWait, '--json-lines', '--', $target], $patterns),
                'extract' => array_merge(['extract', '--lock-wait', $lockWait, '--log-json', '--progress', '--list', '--', $target], $patterns),
            };
            $handle = (new RemoteSshService())->openBorgProcess($this->remoteConfig, $args, (string) $this->passphrase, $dataDir);
            if (isset($handle['error'])) {
                throw RestoreCheckStop::failed('repository_unavailable', $handle['error']);
            }
            try {
                return $this->stream($handle['proc'], $handle['pipes'], $onStdout, $onStderr, $tick);
            } finally {
                (new RemoteSshService())->cleanupStreamingProcess($handle);
            }
        }

        $cmd = array_merge(
            ['sudo', self::HELPER, 'restore-check', 'borg', $this->run['run_token'], $this->repo['ssh_unix_user'], $op, $lockWait, (string) $remaining, $target],
            $patterns
        );
        $proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, null);
        if (!is_resource($proc)) {
            throw RestoreCheckStop::failed('internal_error', 'Could not start the helper.');
        }
        fwrite($pipes[0], $this->passphrase . "\n");
        fclose($pipes[0]);
        return $this->stream($proc, $pipes, $onStdout, $onStderr, $tick);
    }

    /**
     * Drain a process's output line by line, calling $tick every poll
     * interval even when it prints nothing. If $tick throws (cancelled, out
     * of time, out of space), every process in the run is stopped and
     * reaped before the exception continues.
     *
     * @return array{exit: int, stdout: string, stderr: string}
     */
    private function stream($proc, array $pipes, ?callable $onStdout, ?callable $onStderr, ?callable $tick): array
    {
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $buf = [1 => '', 2 => ''];
        $keep = [1 => '', 2 => ''];
        $open = [1 => true, 2 => true];
        $lastTick = microtime(true);

        $emit = function (int $fd, string $line) use ($onStdout, $onStderr, &$keep) {
            $cb = $fd === 1 ? $onStdout : $onStderr;
            if ($cb) {
                $cb($line);
            } elseif (strlen($keep[$fd]) < 1048576) {
                $keep[$fd] .= $line . "\n";
            }
        };

        try {
            while ($open[1] || $open[2]) {
                $read = [];
                foreach ([1, 2] as $fd) {
                    if ($open[$fd]) {
                        $read[] = $pipes[$fd];
                    }
                }
                $w = $e = null;
                $ready = @stream_select($read, $w, $e, 1, 0);
                if ($ready === false) {
                    break;
                }
                foreach ([1, 2] as $fd) {
                    if (!$open[$fd] || !in_array($pipes[$fd], $read, true)) {
                        continue;
                    }
                    $chunk = fread($pipes[$fd], 65536);
                    if ($chunk === '' || $chunk === false) {
                        if (feof($pipes[$fd])) {
                            $open[$fd] = false;
                            if ($buf[$fd] !== '') {
                                $emit($fd, $buf[$fd]);
                                $buf[$fd] = '';
                            }
                        }
                        continue;
                    }
                    $buf[$fd] .= $chunk;
                    while (($pos = strpos($buf[$fd], "\n")) !== false) {
                        $emit($fd, rtrim(substr($buf[$fd], 0, $pos), "\r"));
                        $buf[$fd] = substr($buf[$fd], $pos + 1);
                    }
                }
                // Every borg call, including a silent wait for a repository
                // lock, notices cancellation and the deadline within a poll.
                if (microtime(true) - $lastTick >= self::POLL_SECONDS) {
                    $lastTick = microtime(true);
                    $this->checkCancelled();
                    if ($tick) {
                        $tick();
                    }
                }
            }
        } catch (\Throwable $e) {
            $this->stopProcesses($proc, $pipes);
            throw $e;
        }

        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);
        return ['exit' => $exit, 'stdout' => $keep[1], 'stderr' => $keep[2]];
    }

    /** Stop everything working in this run's staging, then reap our child. */
    private function stopProcesses($proc, array $pipes): void
    {
        $this->helper(['abort', $this->run['run_token']]);
        @proc_terminate($proc, 15);
        foreach ([1, 2] as $fd) {
            if (is_resource($pipes[$fd])) {
                fclose($pipes[$fd]);
            }
        }
        @proc_close($proc);
    }

    /** @return array{exit: int, out: string} */
    private function helper(array $args): array
    {
        $cmd = array_merge(['sudo', self::HELPER, 'restore-check'], $args);
        $proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($proc)) {
            return ['exit' => -1, 'out' => 'could not start helper'];
        }
        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);
        return ['exit' => $exit, 'out' => trim(mb_substr((string) $out, 0, 2000))];
    }

    // ------------------------------------------------------------------
    // Cleanup
    // ------------------------------------------------------------------

    /**
     * Delete one run's staging. Stops anything still working in it first;
     * gives up (and says so) after a few attempts rather than retrying a
     * destructive operation forever.
     */
    public function cleanup(array $run): bool
    {
        $token = (string) $run['run_token'];
        $attempts = (int) ($run['cleanup_attempts'] ?? 0) + 1;
        $this->db->update('restore_check_runs', ['cleanup_attempts' => $attempts], 'id = ?', [$run['id']]);

        $abort = $this->helper(['abort', $token]);
        if ($abort['exit'] !== 0 && !str_contains($abort['out'], 'does not exist')) {
            $this->setCleanup($run, 'failed', 'Processes in the staging directory could not be stopped: ' . $abort['out']);
            return false;
        }
        $res = $this->helper(['cleanup', $token]);
        if ($res['exit'] === 0 || str_contains($res['out'], 'run directory does not exist')) {
            $this->setCleanup($run, 'succeeded', null);
            return true;
        }
        $this->setCleanup($run, 'failed', $res['out']);
        return false;
    }

    private function setCleanup(array $run, string $status, ?string $detail): void
    {
        $this->db->update('restore_check_runs', [
            'cleanup_status' => $status,
            'cleanup_detail' => $detail !== null ? mb_substr($this->redact($detail), 0, 500) : null,
        ], 'id = ?', [$run['id']]);
    }

    // ==================================================================
    // Finalizing
    // ==================================================================

    private function finalize(array $outcome): void
    {
        $run = $this->db->fetchOne("SELECT * FROM restore_check_runs WHERE id = ?", [$this->run['id']]) ?? $this->run;
        $result = $outcome['result'];
        $reason = $outcome['reason'] ?? null;
        $detail = $outcome['detail'] ?? null;

        if (($run['cleanup_status'] ?? '') === 'failed') {
            // Restored data is still on disk: never a pass.
            if ($result !== 'failed') {
                $reason = 'cleanup_failed';
                $detail = 'Validation finished, but the staging directory could not be removed: ' . ($run['cleanup_detail'] ?? '');
            }
            $result = $result === 'cancelled' ? 'cancelled' : 'failed';
        }

        if (!empty($outcome['extra']['probes']) && empty($run['probe_summary'])) {
            $this->updateRun(['probe_summary' => json_encode(['probes' => $outcome['extra']['probes']], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)]);
        }
        $fields = [
            'phase' => 'finished',
            'result' => $result,
            'reason_code' => $reason,
            'reason_detail' => $detail !== null ? mb_substr($this->redact($detail), 0, 500) : null,
            'finished_at' => $this->db->now(),
            'total_seconds' => round(microtime(true) - $this->startedMono, 3),
            'warnings' => json_encode($this->warnings, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
            'limitations' => json_encode($this->limitations, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
            'diagnostic' => $this->diagnostics ? mb_substr($this->redact(implode("\n", $this->diagnostics)), 0, 16000) : null,
        ];
        if (empty($run['extraction_status'])) {
            $fields['extraction_status'] = 'skipped';
        }
        if (empty($run['validation_status'])) {
            $fields['validation_status'] = 'skipped';
        }
        $this->updateRun($fields);
        self::finishRun((int) $run['id'], $this->db);
    }

    /**
     * Shared tail of every finished run, including those reconciliation
     * closes: report, job status, plan status, notifications. The report is
     * written first; if it cannot be, the job is failed, never completed.
     */
    public static function finishRun(int $runId, Database $db): void
    {
        $run = $db->fetchOne("SELECT * FROM restore_check_runs WHERE id = ?", [$runId]);
        if (!$run || $run['result'] === null) {
            return;
        }
        try {
            $report = RestoreCheckReport::build($run);
            $json = RestoreCheckReport::canonicalJson($report);
            $db->update('restore_check_runs', [
                'report_schema_version' => self::REPORT_SCHEMA_VERSION,
                'report_json' => $json,
                'report_digest' => hash('sha256', $json),
            ], 'id = ?', [$runId]);
        } catch (\Throwable $e) {
            if ($run['result'] === 'passed' || $run['result'] === 'warning') {
                $db->update('restore_check_runs', [
                    'result' => 'failed',
                    'reason_code' => 'report_failed',
                    'reason_detail' => 'The run finished but its report could not be stored.',
                ], 'id = ?', [$runId]);
                $run['result'] = 'failed';
            }
        }

        if (!empty($run['backup_job_id'])) {
            $jobStatus = match ($run['result']) {
                'passed', 'warning' => 'completed',
                'cancelled' => 'cancelled',
                default => 'failed',
            };
            $error = in_array($run['result'], ['failed', 'cancelled'], true)
                ? trim(($run['reason_code'] ?? '') . ': ' . ($run['reason_detail'] ?? ''), ': ')
                : null;
            $db->query(
                "UPDATE backup_jobs SET status = ?, completed_at = NOW(), duration_seconds = TIMESTAMPDIFF(SECOND, started_at, NOW()),
                        had_warnings = ?, error_log = COALESCE(?, error_log)
                 WHERE id = ? AND status IN ('queued', 'sent', 'running')",
                [$jobStatus, $run['result'] === 'warning' ? 1 : 0, $error, (int) $run['backup_job_id']]
            );
            $db->insert('server_log', [
                'agent_id' => $run['agent_id'],
                'backup_job_id' => $run['backup_job_id'],
                'level' => $run['result'] === 'passed' ? 'info' : ($run['result'] === 'warning' ? 'warning' : 'error'),
                'message' => "Restore check run #{$runId} {$run['result']}" . ($run['reason_code'] ? " ({$run['reason_code']})" : ''),
            ]);
        }

        if (!empty($run['plan_id'])) {
            $planUpdate = ['last_result' => $run['result']];
            if ($run['result'] === 'passed') {
                $planUpdate['last_strict_success_at'] = $run['finished_at'];
            }
            $db->update('restore_check_plans', $planUpdate, 'id = ?', [(int) $run['plan_id']]);
        }

        self::notify($run, $db);
    }

    private static function notify(array $run, Database $db): void
    {
        if (empty($run['agent_id']) || empty($run['plan_id'])) {
            return;
        }
        $notifier = new NotificationService();
        $agentId = (int) $run['agent_id'];
        $planId = (int) $run['plan_id'];
        $name = $run['plan_name'] ?? 'restore check';
        $client = $run['client_name'] ?? 'client';
        $why = $run['reason_detail'] ? ': ' . $run['reason_detail'] : '';

        if (($run['cleanup_status'] ?? '') === 'failed') {
            $notifier->notify('restore_check_cleanup_failed', $agentId, $planId,
                "Restore check \"{$name}\" on {$client} could not delete its staging directory (run #{$run['id']}). Restored data is still on the BBS server.", 'critical');
        }
        switch ($run['result']) {
            case 'passed':
                $notifier->resolve('restore_check_failed', $agentId, $planId);
                $notifier->resolve('restore_check_warning', $agentId, $planId);
                $notifier->resolve('restore_check_overdue', $agentId, $planId);
                $plan = $db->fetchOne("SELECT notify_success FROM restore_check_plans WHERE id = ?", [$planId]);
                if (!empty($plan['notify_success'])) {
                    $notifier->notify('restore_check_passed', $agentId, $planId, "Restore check \"{$name}\" on {$client} passed (run #{$run['id']}).", 'info');
                }
                break;
            case 'warning':
                $notifier->resolve('restore_check_failed', $agentId, $planId);
                $notifier->notify('restore_check_warning', $agentId, $planId, "Restore check \"{$name}\" on {$client} finished with warnings (run #{$run['id']}){$why}", 'warning');
                break;
            case 'failed':
                $notifier->notify('restore_check_failed', $agentId, $planId, "Restore check \"{$name}\" on {$client} failed (run #{$run['id']}){$why}", 'critical');
                break;
        }
    }

    // ==================================================================
    // Reconciliation (scheduler tick)
    // ==================================================================

    /**
     * Close runs nobody is working on, expire queued runs past their
     * deadline, and finish failed cleanups. Never turns missing evidence
     * into a pass.
     *
     * @return string[] log lines
     */
    public function reconcile(bool $sweepStaging = false): array
    {
        $log = [];
        $now = $this->db->now();

        // Queued for too long.
        foreach ($this->db->fetchAll("
            SELECT r.id, r.backup_job_id, r.config_snapshot
            FROM restore_check_runs r
            JOIN backup_jobs bj ON bj.id = r.backup_job_id
            WHERE r.result IS NULL AND bj.status = 'queued'
        ") as $r) {
            $snap = json_decode((string) $r['config_snapshot'], true) ?: [];
            $deadline = (int) ($snap['queue_deadline_minutes'] ?? 1440);
            $expired = $this->db->query(
                "UPDATE backup_jobs SET status = 'cancelled', completed_at = NOW(), error_log = ?
                 WHERE id = ? AND status = 'queued' AND queued_at < DATE_SUB(NOW(), INTERVAL ? MINUTE)",
                ["Restore check did not start within {$deadline} minutes.", (int) $r['backup_job_id'], $deadline]
            )->rowCount();
            if ($expired) {
                $this->closeRun((int) $r['id'], 'failed', 'queue_timeout', "The run did not start within {$deadline} minutes; the queue or repository stayed busy.");
                $log[] = "Restore check run #{$r['id']} expired in the queue";
            }
        }

        // Unfinished runs whose job is no longer active, or gone.
        foreach ($this->db->fetchAll("
            SELECT r.*, bj.status AS job_status, bj.worker_pid, bj.worker_host, bj.error_log AS job_error
            FROM restore_check_runs r
            LEFT JOIN backup_jobs bj ON bj.id = r.backup_job_id
            WHERE r.result IS NULL
              AND (bj.id IS NULL OR bj.status IN ('completed', 'failed', 'cancelled'))
        ") as $r) {
            if ($this->workerAlive($r)) {
                continue; // a cancelled run still finishing its own cleanup
            }
            if ($r['job_status'] === 'cancelled') {
                $this->closeRun((int) $r['id'], 'cancelled', 'cancelled', 'Cancelled' . ($r['phase'] === 'queued' ? ' before it started.' : '.'));
            } elseif ($r['job_status'] === 'failed') {
                $this->closeRun((int) $r['id'], 'failed', 'worker_lost', 'The process running this check stopped without recording a result. ' . mb_substr((string) $r['job_error'], 0, 300));
            } else {
                $this->closeRun((int) $r['id'], 'failed', 'incomplete_evidence', 'The job ended without this run recording a result.');
            }
            $log[] = "Closed restore check run #{$r['id']} left by job " . ($r['backup_job_id'] ?? '?');
        }

        // Finished runs whose staging is still there.
        foreach ($this->db->fetchAll("
            SELECT * FROM restore_check_runs
            WHERE result IS NOT NULL AND cleanup_status IN ('pending', 'failed') AND cleanup_attempts < ?
        ", [self::CLEANUP_MAX_ATTEMPTS]) as $r) {
            $before = $r['cleanup_status'];
            $ok = $this->cleanup($r);
            if (!$ok && $before === 'pending') {
                self::notify(array_merge($r, ['cleanup_status' => 'failed', 'result' => null]), $this->db);
            }
            if (!$ok && in_array($r['result'], ['passed', 'warning'], true)) {
                $this->downgradeForCleanup($r);
            }
            $log[] = "Restore check run #{$r['id']} cleanup " . ($ok ? 'succeeded' : 'failed');
        }

        if ($sweepStaging) {
            $log = array_merge($log, $this->sweepStaging());
        }
        return $log;
    }

    /**
     * A run that passed but whose restored data could not be deleted is not
     * a pass. Rewrite its result and report, and recompute the plan's last
     * strict pass from the runs that remain passes.
     */
    private function downgradeForCleanup(array $r): void
    {
        $fresh = $this->db->fetchOne("SELECT cleanup_detail FROM restore_check_runs WHERE id = ?", [$r['id']]);
        $this->db->update('restore_check_runs', [
            'result' => 'failed',
            'reason_code' => 'cleanup_failed',
            'reason_detail' => mb_substr('Restored data could not be removed: ' . ($fresh['cleanup_detail'] ?? ''), 0, 500),
        ], 'id = ?', [$r['id']]);
        $run = $this->db->fetchOne("SELECT * FROM restore_check_runs WHERE id = ?", [$r['id']]);
        $json = RestoreCheckReport::canonicalJson(RestoreCheckReport::build($run));
        $this->db->update('restore_check_runs', ['report_json' => $json, 'report_digest' => hash('sha256', $json)], 'id = ?', [$r['id']]);
        if (!empty($r['plan_id'])) {
            $last = $this->db->fetchOne("SELECT MAX(finished_at) AS t FROM restore_check_runs WHERE plan_id = ? AND result = 'passed'", [$r['plan_id']]);
            $this->db->update('restore_check_plans', ['last_strict_success_at' => $last['t'] ?? null, 'last_result' => 'failed'], 'id = ?', [$r['plan_id']]);
        }
    }

    /** Run directories on disk that no active run owns. */
    private function sweepStaging(): array
    {
        $log = [];
        $list = $this->helper(['list-runs']);
        if ($list['exit'] !== 0 || $list['out'] === '') {
            return $log;
        }
        foreach (preg_split('/\s+/', $list['out']) as $token) {
            if (!preg_match('/^[0-9a-f]{32}$/', $token)) {
                continue;
            }
            $run = $this->db->fetchOne("SELECT * FROM restore_check_runs WHERE run_token = ?", [$token]);
            if ($run && $run['result'] === null) {
                continue; // still in progress or awaiting reconciliation above
            }
            if ($run && (int) $run['cleanup_attempts'] >= self::CLEANUP_MAX_ATTEMPTS) {
                continue; // left for an operator; already reported
            }
            if ($run) {
                $this->cleanup($run);
            } else {
                $this->helper(['abort', $token]);
                $this->helper(['cleanup', $token]);
            }
            $log[] = "Swept restore check staging {$token}";
        }
        return $log;
    }

    private function closeRun(int $runId, string $result, string $reason, string $detail): void
    {
        $closed = $this->db->query(
            "UPDATE restore_check_runs SET result = ?, reason_code = ?, reason_detail = ?, phase = 'finished', finished_at = NOW(),
                    extraction_status = COALESCE(extraction_status, 'skipped'), validation_status = COALESCE(validation_status, 'skipped'),
                    limitations = COALESCE(limitations, ?)
             WHERE id = ? AND result IS NULL",
            [$result, $reason, mb_substr($detail, 0, 500), json_encode(self::standingLimitations()), $runId]
        )->rowCount();
        if (!$closed) {
            return;
        }
        $run = $this->db->fetchOne("SELECT * FROM restore_check_runs WHERE id = ?", [$runId]);
        if ((int) $run['staging_created'] === 1 && $run['cleanup_status'] !== 'succeeded') {
            $this->cleanup($run);
        }
        self::finishRun($runId, $this->db);
    }

    /** Same test as the scheduler's orphan sweep: this host, this job's worker. */
    private function workerAlive(array $r): bool
    {
        if (empty($r['worker_pid']) || ($r['worker_host'] ?? '') !== substr((string) gethostname(), 0, 64)) {
            return false;
        }
        $cmdline = @file_get_contents('/proc/' . (int) $r['worker_pid'] . '/cmdline');
        return $cmdline !== false && str_contains($cmdline, 'scheduler.php') && str_contains($cmdline, '--job=' . $r['backup_job_id']);
    }

    /** Delete evidence older than the configured retention (default 365 days). */
    public function purgeEvidence(): int
    {
        $days = (int) ($this->setting('restore_check_evidence_days') ?? 365);
        if ($days < 1) {
            return 0;
        }
        return $this->db->delete(
            'restore_check_runs',
            "result IS NOT NULL AND cleanup_status IN ('not_needed', 'succeeded') AND finished_at < DATE_SUB(NOW(), INTERVAL ? DAY)",
            [$days]
        );
    }

    // ==================================================================
    // Helpers
    // ==================================================================

    /**
     * Decode one --json-lines item. borg writes names that are not valid
     * UTF-8 with each raw byte as a lone surrogate (\udc80-\udcff), which
     * json_decode rejects. Those are mapped one-to-one onto private-use
     * characters: the name stays unique and keeps its path prefixes, so the
     * symlink-layout check still sees it, but it no longer names a real file
     * and is flagged so validation skips it.
     *
     * @return array{0: ?array, 1: bool} [item, had undecodable bytes]
     */
    public static function decodeItem(string $line): array
    {
        $item = json_decode($line, true);
        if (is_array($item)) {
            return [$item, false];
        }
        $mapped = preg_replace(
            '/(?<!\\\\)((?:\\\\\\\\)*)\\\\u[dD][cC]([89a-fA-F][0-9a-fA-F])(?<![dD][89abAB][0-9a-fA-F]{2}\\\\u[dD][cC][89a-fA-F][0-9a-fA-F])/',
            '$1\\\\ue0$2',
            $line
        );
        $item = is_string($mapped) ? json_decode($mapped, true) : null;
        return [is_array($item) ? $item : null, true];
    }

    private static function kind(array $item): string
    {
        $mode = (string) ($item['mode'] ?? '');
        if (str_starts_with($mode, 'h')) {
            return 'hardlink';
        }
        return match ($item['type'] ?? '') {
            '-' => 'file',
            'd' => 'dir',
            'l' => 'symlink',
            'p' => 'fifo',
            'b', 'c' => 'device',
            's' => 'socket',
            default => 'device',
        };
    }

    public static function standingLimitations(): array
    {
        return [
            ['code' => 'metadata_not_verified', 'message' => 'Ownership, permissions, ACLs, extended attributes and file flags are not verified: extraction runs as an unprivileged user.'],
            ['code' => 'not_application_recovery', 'message' => 'This proves files can be extracted and read back. It does not start a database or service, and its duration is not a recovery time objective.'],
            ['code' => 'server_side_only', 'message' => 'Extraction ran on the BBS server, not on the original client.'],
        ];
    }

    /** Free bytes and inodes on the filesystem holding $path. */
    public static function freeSpace(string $path): array
    {
        $bytes = @disk_free_space($path);
        $inodes = null;
        $out = @shell_exec('df -P --output=iavail ' . escapeshellarg($path) . ' 2>/dev/null');
        if (is_string($out) && preg_match('/(\d+)\s*$/', trim($out), $m)) {
            $inodes = (int) $m[1];
        }
        return [$bytes === false ? null : (int) $bytes, $inodes];
    }

    private function checkCancelled(): void
    {
        $job = $this->db->fetchOne("SELECT status FROM backup_jobs WHERE id = ?", [$this->run['backup_job_id']]);
        if (!$job || $job['status'] === 'cancelled') {
            throw new RestoreCheckStop(['result' => 'cancelled', 'reason' => 'cancelled', 'detail' => 'Cancelled while running.']);
        }
        if (microtime(true) > $this->deadline) {
            throw RestoreCheckStop::failed('timeout', 'The run reached its time limit.');
        }
    }

    private function touchProgress(int $n): void
    {
        $this->db->update('backup_jobs', ['last_progress_at' => $this->db->now(), 'status_message' => "Validating ({$n} items checked)"], 'id = ?', [$this->run['backup_job_id']]);
    }

    private function updateRun(array $fields): void
    {
        $this->db->update('restore_check_runs', $fields, 'id = ?', [$this->run['id']]);
        $this->run = array_merge($this->run, $fields);
    }

    private function warn(string $code, string $message): void
    {
        $this->warnings[] = ['code' => $code, 'message' => $message];
    }

    /** Informational: recorded with the limitations, does not change the result. */
    private function note(string $code, string $message): void
    {
        $this->limitations[] = ['code' => $code, 'message' => $message];
    }

    private function diag(string $line): void
    {
        if (count($this->diagnostics) < self::MAX_DIAGNOSTIC_LINES) {
            $this->diagnostics[] = mb_substr($this->redact($line), 0, 300);
        }
    }

    private function example(array &$examples, string $path, string $problem): void
    {
        if (count($examples) < self::MAX_EXAMPLES) {
            $examples[] = ['path' => $path, 'problem' => $problem];
        }
    }

    private function tail(string $text): string
    {
        $text = trim($this->redact($text));
        return mb_substr($text, max(0, mb_strlen($text) - 400));
    }

    /** Never let the passphrase, or anything shaped like a secret, into evidence. */
    private function redact(string $text): string
    {
        if ($this->passphrase !== null && $this->passphrase !== '') {
            $text = str_replace($this->passphrase, '[redacted]', $text);
        }
        $text = preg_replace('/(BORG_PASSPHRASE|BORG_NEW_PASSPHRASE|passphrase)\s*[=:]\s*\S+/i', '$1=[redacted]', $text);
        return preg_replace('/-----BEGIN [A-Z ]*PRIVATE KEY-----.*?(-----END [A-Z ]*PRIVATE KEY-----|$)/s', '[redacted key]', $text);
    }

    private function log(string $level, string $message): void
    {
        $this->db->insert('server_log', [
            'agent_id' => $this->run['agent_id'],
            'backup_job_id' => $this->run['backup_job_id'],
            'level' => $level,
            'message' => $message,
        ]);
    }

    private function setting(string $key): ?string
    {
        return $this->db->fetchOne("SELECT `value` FROM settings WHERE `key` = ?", [$key])['value'] ?? null;
    }

    public static function bytes(int|float $n): string
    {
        $units = ['B', 'KiB', 'MiB', 'GiB', 'TiB'];
        $i = 0;
        while ($n >= 1024 && $i < count($units) - 1) {
            $n /= 1024;
            $i++;
        }
        return ($i === 0 ? (string) (int) $n : number_format($n, 1)) . ' ' . $units[$i];
    }
}
