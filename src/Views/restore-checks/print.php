<?php
/**
 * Standalone printable report (Print / Save as PDF in the browser).
 *
 * @var array $report
 * @var string $digest
 */
$h = fn($v) => htmlspecialchars((string) ($v ?? ''), ENT_QUOTES | ENT_SUBSTITUTE);
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Restore check report — run #<?= (int) ($report['run']['id'] ?? 0) ?></title>
<link href="/assets/bootstrap/bootstrap.min.css" rel="stylesheet">
<style>
    body { padding: 2rem 1rem; max-width: 60rem; margin: 0 auto; font-size: 14px; }
    table { width: 100%; border-collapse: collapse; }
    th, td { text-align: left; vertical-align: top; padding: .2rem .4rem; border-bottom: 1px solid #ddd; }
    .text-break { word-break: break-all; }
    @media print { .no-print { display: none !important; } body { padding: 0; } }
</style>
</head>
<body>
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h1 class="h4 mb-0">Restore check report</h1>
            <div class="text-muted"><?= $h($report['subject']['client_name'] ?? '') ?> · <?= $h($report['run']['plan_name'] ?? '') ?> · run #<?= (int) ($report['run']['id'] ?? 0) ?></div>
        </div>
        <button class="btn btn-sm btn-outline-secondary no-print" onclick="window.print()">Print / Save as PDF</button>
    </div>
    <?php require __DIR__ . '/_report_body.php'; ?>
    <p class="small text-muted mt-4">
        Report SHA-256: <span class="font-monospace text-break"><?= $h($digest) ?></span>.
        The digest shows this report is unchanged only when compared with a copy kept somewhere trusted.
    </p>
</body>
</html>
