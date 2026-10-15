<?php

namespace BBS\Services;

/**
 * The evidence report for a finished restore check run: built once when the
 * run finishes, stored with its SHA-256, and served as JSON or a printable
 * page. It holds names, counts, sizes and hashes — never file contents.
 *
 * The digest shows the stored report has not changed only when compared
 * with a copy kept somewhere trusted; on its own, in the same database, it
 * is not tamper-proof.
 */
class RestoreCheckReport
{
    public static function build(array $run): array
    {
        $snapshot = json_decode((string) $run['config_snapshot'], true) ?: [];
        $decode = fn($v) => $v === null ? null : json_decode((string) $v, true);
        $num = fn($v) => $v === null ? null : (float) $v;
        $int = fn($v) => $v === null ? null : (int) $v;
        $utc = fn($v) => $v === null ? null : str_replace(' ', 'T', $v) . 'Z';

        $queueSeconds = ($run['started_at'] && $run['queued_at'])
            ? max(0, strtotime($run['started_at'] . ' UTC') - strtotime($run['queued_at'] . ' UTC'))
            : null;

        return [
            'schema' => 'bbs.restore_check.report',
            'schema_version' => RestoreCheckRunner::REPORT_SCHEMA_VERSION,
            'run' => [
                'id' => (int) $run['id'],
                'plan_id' => $int($run['plan_id']),
                'plan_name' => $run['plan_name'],
                'config_revision' => (int) $run['config_revision'],
                'trigger' => $run['trigger_type'],
                'requested_by' => $run['requested_by_name'],
                'scheduled_for_utc' => $utc($run['scheduled_for']),
                'missed_occurrences' => (int) $run['missed_occurrences'],
                'job_id' => $int($run['backup_job_id']),
            ],
            'result' => [
                'status' => $run['result'],
                'strict_pass' => $run['result'] === 'passed',
                'reason_code' => $run['reason_code'],
                'reason_detail' => $run['reason_detail'],
                'extraction' => $run['extraction_status'],
                'validation' => $run['validation_status'],
                'cleanup' => $run['cleanup_status'],
                'cleanup_detail' => $run['cleanup_detail'],
                'borg_exit_code' => $int($run['borg_exit_code']),
            ],
            'scope' => [
                'mode' => $snapshot['mode'] ?? null,
                'coverage' => ($snapshot['mode'] ?? '') === 'full' ? 'full_archive' : 'selected_paths',
                'archive_selection' => ($snapshot['scope'] ?? 'plan') === 'plan' ? 'backup_plan' : 'repository_wide',
                'execution_location' => 'bbs_server_staging',
                'application_recovery_tested' => false,
            ],
            'subject' => [
                'client_id' => $int($run['agent_id']),
                'client_name' => $run['client_name'],
                'repository_id' => $int($run['repository_id']),
                'repository_name' => $run['repository_name'],
                'source_backup_plan' => $run['source_plan_name'],
                'archive' => [
                    'name' => $run['archive_name'],
                    'borg_id' => $run['borg_archive_id'],
                    'catalog_id' => $int($run['archive_db_id']),
                    'created_utc' => $utc($run['archive_time']),
                    'age_seconds' => $int($run['archive_age_seconds']),
                    'source_job_id' => $int($run['source_job_id']),
                    'source_backup_had_warnings' => $run['source_had_warnings'] === null ? null : (bool) $run['source_had_warnings'],
                ],
            ],
            'settings' => $snapshot,
            'measurements' => [
                'expected_items' => $int($run['expected_files']),
                'expected_bytes' => $int($run['expected_bytes']),
                'staged_entries' => $int($run['extracted_files']),
                'staged_bytes' => $int($run['extracted_bytes']),
                'validation' => $decode($run['probe_summary']),
            ],
            'timing' => [
                'queued_utc' => $utc($run['queued_at']),
                'started_utc' => $utc($run['started_at']),
                'finished_utc' => $utc($run['finished_at']),
                'queue_wait_seconds' => $queueSeconds,
                'extraction_seconds' => $num($run['extraction_seconds']),
                'validation_seconds' => $num($run['validation_seconds']),
                'measured_restore_check_seconds' => $num($run['total_seconds']),
                'note' => 'Measured restore-check duration, not a proven recovery time objective.',
            ],
            'warnings' => $decode($run['warnings']) ?? [],
            'limitations' => $decode($run['limitations']) ?? [],
            'diagnostic' => $run['diagnostic'],
            'software' => [
                'bbs_version' => $run['bbs_version'],
                'borg_version' => $run['borg_version'],
            ],
            'generated_utc' => gmdate('Y-m-d\TH:i:s\Z'),
        ];
    }

    /** Stable serialisation for storage and hashing: sorted keys, no whitespace. */
    public static function canonicalJson(array $report): string
    {
        $sort = function ($v) use (&$sort) {
            if (!is_array($v)) {
                return $v;
            }
            if (array_is_list($v)) {
                return array_map($sort, $v);
            }
            ksort($v);
            return array_map($sort, $v);
        };
        return json_encode($sort($report), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
    }

    /** Display label and Bootstrap colour for a run or plan status. */
    public static function badge(?string $status): array
    {
        return match ($status) {
            'passed' => ['Passed', 'success'],
            'warning' => ['Warnings', 'warning'],
            'failed' => ['Failed', 'danger'],
            'cancelled' => ['Cancelled', 'secondary'],
            'skipped' => ['Skipped', 'secondary'],
            'queued' => ['Queued', 'info'],
            'running' => ['Running', 'primary'],
            'overdue' => ['Overdue', 'danger'],
            'never' => ['Never run', 'secondary'],
            default => [ucfirst((string) $status), 'secondary'],
        };
    }
}
