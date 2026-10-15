<?php
/**
 * Body of a restore check report, shared by the run page and the printable
 * view. Every value is escaped: archive paths, names and borg's messages
 * come from outside BBS.
 *
 * @var array $report RestoreCheckReport::build() output
 */
use BBS\Services\RestoreCheckReport;
use BBS\Services\RestoreCheckRunner;

$h = fn($v) => htmlspecialchars((string) ($v ?? '—'), ENT_QUOTES | ENT_SUBSTITUTE);
$yn = fn($v) => $v === null ? '—' : ($v ? 'Yes' : 'No');
$bytes = fn($v) => $v === null ? '—' : RestoreCheckRunner::bytes((int) $v);
$secs = fn($v) => $v === null ? '—' : number_format((float) $v, 1) . ' s';
$res = $report['result'] ?? [];
$subject = $report['subject'] ?? [];
$archive = $subject['archive'] ?? [];
$scope = $report['scope'] ?? [];
$meas = $report['measurements'] ?? [];
$val = $meas['validation'] ?? [];
$timing = $report['timing'] ?? [];
$stage = fn($s) => match ($s) { 'ok' => 'OK', 'warning' => 'Warnings', 'failed' => 'Failed', 'skipped' => 'Not reached', 'succeeded' => 'Removed', 'not_needed' => 'Nothing to remove', 'pending' => 'Pending', default => $s ?? '—' };
[$label, $color] = RestoreCheckReport::badge($res['status'] ?? null);
?>
<div class="rc-report">
    <div class="alert alert-<?= $color ?> d-flex flex-wrap gap-3 align-items-center">
        <div class="fs-5 fw-semibold"><?= $h($label) ?><?= !empty($res['strict_pass']) ? ' — strict pass' : '' ?></div>
        <?php if (!empty($res['reason_code'])): ?>
        <div><code class="text-reset"><?= $h($res['reason_code']) ?></code> <?= $h($res['reason_detail']) ?></div>
        <?php endif; ?>
    </div>

    <table class="table table-sm mb-4">
        <tbody>
            <tr><th class="w-25">Extraction</th><td><?= $h($stage($res['extraction'] ?? null)) ?><?= isset($res['borg_exit_code']) ? ' (borg exit code ' . (int) $res['borg_exit_code'] . ')' : '' ?></td></tr>
            <tr><th>Validation</th><td><?= $h($stage($res['validation'] ?? null)) ?></td></tr>
            <tr><th>Cleanup of restored data</th><td><?= $h($stage($res['cleanup'] ?? null)) ?><?= !empty($res['cleanup_detail']) ? ' — ' . $h($res['cleanup_detail']) : '' ?></td></tr>
            <tr><th>Coverage</th><td><?= ($scope['coverage'] ?? '') === 'full_archive' ? 'Full archive' : 'Selected paths' ?>,
                archive chosen <?= ($scope['archive_selection'] ?? '') === 'repository_wide' ? 'repository-wide' : 'from one backup plan' ?>,
                extracted on the BBS server. Application recovery tested: no.</td></tr>
        </tbody>
    </table>

    <h6 class="fw-semibold">Subject</h6>
    <table class="table table-sm mb-4">
        <tbody>
            <tr><th class="w-25">Client</th><td><?= $h($subject['client_name'] ?? null) ?> (#<?= $h($subject['client_id'] ?? null) ?>)</td></tr>
            <tr><th>Repository</th><td><?= $h($subject['repository_name'] ?? null) ?> (#<?= $h($subject['repository_id'] ?? null) ?>)</td></tr>
            <tr><th>Backup plan</th><td><?= $h($subject['source_backup_plan'] ?? null) ?></td></tr>
            <tr><th>Archive</th><td class="font-monospace text-break"><?= $h($archive['name'] ?? null) ?></td></tr>
            <tr><th>Borg archive ID</th><td class="font-monospace text-break small"><?= $h($archive['borg_id'] ?? null) ?></td></tr>
            <tr><th>Archive created (UTC)</th><td><?= $h($archive['created_utc'] ?? null) ?><?= isset($archive['age_seconds']) ? ' — ' . number_format(((int) $archive['age_seconds']) / 3600, 1) . ' h old at test time' : '' ?></td></tr>
            <tr><th>Source backup job</th><td>#<?= $h($archive['source_job_id'] ?? null) ?>, finished with warnings: <?= $yn($archive['source_backup_had_warnings'] ?? null) ?></td></tr>
        </tbody>
    </table>

    <h6 class="fw-semibold">Measurements</h6>
    <table class="table table-sm mb-4">
        <tbody>
            <tr><th class="w-25">Expected</th><td><?= $h(isset($meas['expected_items']) ? number_format((int) $meas['expected_items']) : null) ?> items, <?= $h($bytes($meas['expected_bytes'] ?? null)) ?></td></tr>
            <tr><th>Found in staging</th><td><?= $h(isset($meas['staged_entries']) ? number_format((int) $meas['staged_entries']) : null) ?> entries, <?= $h($bytes($meas['staged_bytes'] ?? null)) ?></td></tr>
            <?php if (!empty($val['inventory'])): $inv = $val['inventory']; ?>
            <tr><th>Inventory</th><td><?= (int) $inv['regular_files'] ?> files, <?= (int) $inv['directories'] ?> directories, <?= (int) $inv['symlinks'] ?> symlinks, <?= (int) $inv['hardlinks'] ?> hard links, <?= (int) ($inv['fifos'] ?? 0) ?> FIFOs, <?= (int) $inv['special'] ?> device nodes or sockets (not verified)</td></tr>
            <?php endif; ?>
            <?php if (!empty($val['problems'])): ?>
            <tr><th>Mismatches</th><td><?php foreach ($val['problems'] as $k => $n): ?><?= $h($k) ?>: <?= (int) $n ?>&nbsp;&nbsp;<?php endforeach; ?></td></tr>
            <?php endif; ?>
        </tbody>
    </table>

    <?php if (!empty($val['probes'])): ?>
    <h6 class="fw-semibold">Selected paths</h6>
    <div class="table-responsive mb-4">
        <table class="table table-sm">
            <thead><tr><th>Path</th><th>Type</th><th>Items</th><th>Size</th><th>SHA-256</th></tr></thead>
            <tbody>
            <?php foreach ($val['probes'] as $p): ?>
                <tr>
                    <td class="font-monospace text-break"><?= $h($p['path'] ?? null) ?></td>
                    <td><?= $h($p['type'] ?? null) ?></td>
                    <td><?= $h($p['matched_items'] ?? null) ?></td>
                    <td><?= $h(isset($p['expected_size']) ? $bytes($p['expected_size']) : null) ?></td>
                    <td class="small">
                        <?php $st = $p['sha256_status'] ?? null; ?>
                        <?php if ($st === 'match'): ?><span class="text-success">matches expected</span>
                        <?php elseif ($st === 'mismatch'): ?><span class="text-danger fw-semibold">does not match expected</span>
                        <?php elseif ($st === 'fingerprint_only'): ?><span class="text-muted">fingerprint only (no expected value)</span>
                        <?php elseif ($st !== null): ?><?= $h($st) ?><?php else: ?>—<?php endif; ?>
                        <?php if (!empty($p['sha256_actual'])): ?><div class="font-monospace text-break text-muted"><?= $h($p['sha256_actual']) ?></div><?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <?php if (!empty($val['examples'])): ?>
    <h6 class="fw-semibold">Examples of mismatches</h6>
    <ul class="small font-monospace mb-4">
        <?php foreach ($val['examples'] as $ex): ?><li class="text-break"><?= $h($ex['path']) ?> — <?= $h($ex['problem']) ?></li><?php endforeach; ?>
    </ul>
    <?php endif; ?>

    <?php if (!empty($report['warnings'])): ?>
    <h6 class="fw-semibold">Warnings</h6>
    <ul class="mb-4"><?php foreach ($report['warnings'] as $w): ?><li><code><?= $h($w['code'] ?? null) ?></code> <?= $h($w['message'] ?? null) ?></li><?php endforeach; ?></ul>
    <?php endif; ?>

    <h6 class="fw-semibold">Timing</h6>
    <table class="table table-sm mb-4">
        <tbody>
            <tr><th class="w-25">Queued / started / finished (UTC)</th><td><?= $h($timing['queued_utc'] ?? null) ?> / <?= $h($timing['started_utc'] ?? null) ?> / <?= $h($timing['finished_utc'] ?? null) ?></td></tr>
            <tr><th>Queue wait</th><td><?= $h($secs($timing['queue_wait_seconds'] ?? null)) ?></td></tr>
            <tr><th>Extraction / validation</th><td><?= $h($secs($timing['extraction_seconds'] ?? null)) ?> / <?= $h($secs($timing['validation_seconds'] ?? null)) ?></td></tr>
            <tr><th>Measured restore-check duration</th><td><?= $h($secs($timing['measured_restore_check_seconds'] ?? null)) ?> <span class="text-muted small">— not a proven recovery time objective</span></td></tr>
        </tbody>
    </table>

    <h6 class="fw-semibold">Limitations</h6>
    <ul class="small mb-4"><?php foreach ($report['limitations'] ?? [] as $l): ?><li><?= $h($l['message'] ?? null) ?></li><?php endforeach; ?></ul>

    <?php if (!empty($report['diagnostic'])): ?>
    <h6 class="fw-semibold">Diagnostic output (redacted, truncated)</h6>
    <pre class="small bg-body-tertiary p-2 rounded text-break" style="white-space:pre-wrap"><?= $h($report['diagnostic']) ?></pre>
    <?php endif; ?>

    <h6 class="fw-semibold">Run</h6>
    <table class="table table-sm mb-0 small">
        <tbody>
            <tr><th class="w-25">Run / plan</th><td>#<?= $h($report['run']['id'] ?? null) ?> / <?= $h($report['run']['plan_name'] ?? null) ?> (#<?= $h($report['run']['plan_id'] ?? null) ?>, revision <?= $h($report['run']['config_revision'] ?? null) ?>)</td></tr>
            <tr><th>Trigger</th><td><?= $h($report['run']['trigger'] ?? null) ?><?= !empty($report['run']['requested_by']) ? ' by ' . $h($report['run']['requested_by']) : '' ?><?= !empty($report['run']['scheduled_for_utc']) ? ', occurrence ' . $h($report['run']['scheduled_for_utc']) . ' UTC' : '' ?><?= !empty($report['run']['missed_occurrences']) ? ', ' . (int) $report['run']['missed_occurrences'] . ' earlier occurrence(s) missed' : '' ?></td></tr>
            <tr><th>Schedule timezone</th><td><?= $h($report['settings']['schedule']['timezone'] ?? null) ?></td></tr>
            <tr><th>Software</th><td>BBS <?= $h($report['software']['bbs_version'] ?? null) ?>, borg <?= $h($report['software']['borg_version'] ?? null) ?></td></tr>
            <tr><th>Report</th><td>schema <?= $h($report['schema_version'] ?? null) ?>, generated <?= $h($report['generated_utc'] ?? null) ?></td></tr>
        </tbody>
    </table>
</div>
