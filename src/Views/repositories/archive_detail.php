<?php
function fmtSize($bytes) {
    $s = "\u{00A0}";
    if ($bytes >= 1073741824) return round($bytes / 1073741824, 1) . "{$s}GB";
    if ($bytes >= 1048576) return round($bytes / 1048576, 1) . "{$s}MB";
    if ($bytes >= 1024) return round($bytes / 1024, 1) . "{$s}KB";
    return $bytes . "{$s}B";
}

// Status codes from borg + display label and full badge class token.
// Using pre-composed class strings (rather than just a color name) lets us
// combine Bootstrap 5.3's *-subtle / *-emphasis pairs for readable badges on
// subtle variants — the old `bg-body-secondary` single-class token rendered
// near-invisibly on dark cards (#132).
$statusLabels = [
    'A' => ['Added',            'text-bg-success'],
    'M' => ['Modified',         'text-bg-warning'],
    'C' => ['Metadata Changed', 'text-bg-primary'],
    'U' => ['Unchanged',        'text-bg-secondary'],
    'D' => ['Directory',        'bg-secondary-subtle text-secondary-emphasis'],
    'S' => ['Symlink',          'bg-secondary-subtle text-secondary-emphasis'],
    'H' => ['Hardlink',         'bg-secondary-subtle text-secondary-emphasis'],
    'X' => ['Excluded',         'bg-warning-subtle text-warning-emphasis'],
    'B' => ['Block Device',     'bg-secondary-subtle text-secondary-emphasis'],
    'F' => ['FIFO',             'bg-secondary-subtle text-secondary-emphasis'],
    'E' => ['Empty',            'bg-secondary-subtle text-secondary-emphasis'],
];

// Non-file entry types — exclude from file counts and size totals
$nonFileStatuses = ['D', 'S', 'H', 'X', 'B', 'F', 'E'];

$durLabel = '--';
if (!empty($jobInfo['duration_seconds'])) {
    $d = (int) $jobInfo['duration_seconds'];
    $durLabel = $d >= 3600 ? floor($d / 3600) . 'h ' . floor(($d % 3600) / 60) . 'm'
        : ($d >= 60 ? floor($d / 60) . 'm ' . ($d % 60) . 's' : $d . 's');
}

// Separate file entries from non-file entries
$fileRows = [];
$otherRows = [];
$totalFiles = 0;
$totalSize = 0;
$otherCount = 0;
foreach ($statusBreakdown as $row) {
    if (in_array($row['status'], $nonFileStatuses)) {
        $otherRows[] = $row;
        $otherCount += (int) $row['cnt'];
    } else {
        $fileRows[] = $row;
        $totalFiles += (int) $row['cnt'];
        $totalSize += (int) $row['total_size'];
    }
}
// "Grand Total" row includes directories, symlinks, etc. per #133 feedback
$grandTotalCount = $totalFiles + $otherCount;

$hasDatabases = !empty($archive['databases_backed_up']);
$dbInfo = $hasDatabases ? json_decode($archive['databases_backed_up'], true) : null;

$savings = $archive['original_size'] > 0
    ? round((1 - $archive['deduplicated_size'] / $archive['original_size']) * 100, 1)
    : 0;
// Rounding can produce 100 even when dedup is > 0 bytes (#191).
if ($savings >= 100 && $archive['deduplicated_size'] > 0) {
    $savings = 99.9;
}
?>

<style>
/* Archive detail — Status Breakdown footer row (#133 item 2).
   The old `tfoot.border-top` with `fw-semibold` rendered the Total row
   nearly invisible on dark cards. Give it a subtle tinted background,
   bolder weight, and a proper top border so it reads as a summary row. */
.status-breakdown-foot { border-top: 2px solid var(--bs-border-color); }
.status-breakdown-foot td {
    background-color: var(--bs-tertiary-bg);
    padding-top: 0.55rem;
    padding-bottom: 0.55rem;
}
.status-breakdown-foot tr:not(:last-child) td { border-bottom: 1px solid var(--bs-border-color-translucent); }
</style>

<!-- Breadcrumb -->
<nav aria-label="breadcrumb" class="mb-3">
    <ol class="breadcrumb mb-0">
        <li class="breadcrumb-item"><a href="/clients" class="text-decoration-none">Clients</a></li>
        <li class="breadcrumb-item"><a href="/clients/<?= $agentId ?>" class="text-decoration-none"><?= htmlspecialchars($agent['name']) ?></a></li>
        <li class="breadcrumb-item"><a href="/clients/<?= $agentId ?>?tab=repos" class="text-decoration-none">Repos</a></li>
        <li class="breadcrumb-item"><a href="/clients/<?= $agentId ?>/repo/<?= $repo['id'] ?>" class="text-decoration-none"><?= htmlspecialchars($repo['name']) ?></a></li>
        <li class="breadcrumb-item active"><?= htmlspecialchars($planName ?: $archive['archive_name']) ?></li>
    </ol>
</nav>

<!-- Header -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-body">
        <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
            <div>
                <h4 class="mb-1">
                    <i class="bi bi-archive text-primary me-2"></i>
                    <?= htmlspecialchars($planName ?: $archive['archive_name']) ?>
                    <?php if ($hasDatabases): ?>
                    <span class="badge text-bg-info ms-2" style="font-size: 0.6em; vertical-align: middle;"><i class="bi bi-database me-1"></i>Databases</span>
                    <?php endif; ?>
                </h4>
                <?php if ($planName): ?>
                <div class="text-muted small"><code><?= htmlspecialchars($archive['archive_name']) ?></code></div>
                <?php endif; ?>
            </div>
            <div class="d-flex gap-2 flex-wrap">
                <a href="/clients/<?= $agentId ?>?tab=restore&archive=<?= $archive['id'] ?>&mode=files" class="btn btn-sm btn-primary">
                    <i class="bi bi-cloud-download me-1"></i>Restore Files
                </a>
                <?php if ($hasDatabases): ?>
                <a href="/clients/<?= $agentId ?>?tab=restore&archive=<?= $archive['id'] ?>&mode=database" class="btn btn-sm btn-info">
                    <i class="bi bi-database me-1"></i>Restore Databases
                </a>
                <?php endif; ?>
            </div>
        </div>

        <!-- Stats Row (metric-tile style, matches dashboard visual language) -->
        <div class="row g-3 mt-3">
            <div class="col-6 col-md-3">
                <div class="metric-tile primary h-100">
                    <div class="label"><i class="bi bi-hdd me-1"></i>Total Size</div>
                    <div class="value"><?= fmtSize($archive['original_size']) ?></div>
                    <div class="sub">original, pre-dedup</div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="metric-tile success h-100">
                    <div class="label"><i class="bi bi-archive me-1"></i>On Disk</div>
                    <div class="value"><?= fmtSize($archive['deduplicated_size']) ?></div>
                    <div class="sub"><?= $savings ?>% dedup savings</div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="metric-tile info h-100"
                     title="File count as recorded by borg when the archive was created. This is the authoritative number. The pre-scan count shown during backup is a quick filesystem estimate and may differ.">
                    <div class="label"><i class="bi bi-files me-1"></i>Files in Archive</div>
                    <div class="value"><?= number_format((int) $archive['file_count']) ?></div>
                    <div class="sub">from borg manifest</div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="metric-tile warning h-100">
                    <div class="label"><i class="bi bi-clock-history me-1"></i>Duration</div>
                    <div class="value"><?= htmlspecialchars($durLabel) ?></div>
                    <div class="sub"><?= $jobInfo['completed_at'] ?? '' ? \BBS\Core\TimeHelper::ago($jobInfo['completed_at']) : '' ?></div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php if ($clickhouseAvailable && !empty($statusBreakdown)): ?>
<!-- File Changes + Largest Files -->
<div class="row g-3 mb-4">
    <div class="col-lg-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header card-head-gradient fw-semibold">
                <i class="bi bi-bar-chart me-1"></i> File Changes
            </div>
            <div class="card-body">
                <?php if ($totalFiles > 0): ?>
                <div class="progress mb-3" style="height: 24px;">
                    <?php foreach ($fileRows as $row):
                        $pct = round(((int) $row['cnt'] / $totalFiles) * 100, 1);
                        if ($pct < 0.5) continue;
                        [$label, $badge] = $statusLabels[$row['status']] ?? [$row['status'], 'bg-secondary'];
                    ?>
                    <div class="progress-bar <?= $badge ?>" style="width: <?= $pct ?>%" title="<?= $label ?>: <?= number_format($row['cnt']) ?> files"><?php if ($pct > 5): ?><?= $label ?><?php endif; ?></div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <table class="table table-sm small mb-0">
                    <thead>
                        <tr>
                            <th>Status</th>
                            <th class="text-end">Files</th>
                            <th class="text-end">Size</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($fileRows as $row):
                            [$label, $badge] = $statusLabels[$row['status']] ?? [$row['status'], 'bg-secondary'];
                        ?>
                        <tr>
                            <td><span class="badge <?= $badge ?>"><?= $label ?></span></td>
                            <td class="text-end"><?= number_format($row['cnt']) ?></td>
                            <td class="text-end"><?= fmtSize($row['total_size']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if ($prevArchive): ?>
                        <tr id="row-deleted" hidden>
                            <td><span class="badge text-bg-danger">Deleted</span></td>
                            <td class="text-end" id="deleted-count">--</td>
                            <td class="text-end" id="deleted-size">--</td>
                        </tr>
                        <?php endif; ?>
                        <?php if (!empty($otherRows)): ?>
                        <tr><td colspan="3" class="text-muted small pt-3 border-0">Other Entries</td></tr>
                        <?php foreach ($otherRows as $row):
                            [$label, $badge] = $statusLabels[$row['status']] ?? [$row['status'], 'bg-secondary'];
                        ?>
                        <tr>
                            <td><span class="badge <?= $badge ?>"><?= $label ?></span></td>
                            <td class="text-end text-muted"><?= number_format($row['cnt']) ?></td>
                            <td class="text-end text-muted">--</td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                    <tfoot class="status-breakdown-foot">
                        <tr>
                            <td class="fw-bold">Files Total</td>
                            <td class="text-end fw-bold"><?= number_format($totalFiles) ?></td>
                            <td class="text-end fw-bold"><?= fmtSize($totalSize) ?></td>
                        </tr>
                        <?php if ($otherCount > 0): ?>
                        <tr>
                            <td class="fw-bold">Grand Total</td>
                            <td class="text-end fw-bold"><?= number_format($grandTotalCount) ?></td>
                            <td class="text-end text-muted small">incl. dirs/links</td>
                        </tr>
                        <?php endif; ?>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>

    <div class="col-lg-8">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header card-head-gradient fw-semibold">
                <i class="bi bi-file-earmark-arrow-up me-1"></i> Largest Files
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm small mb-0">
                        <thead>
                            <tr>
                                <th>File</th>
                                <th class="text-end" style="width: 100px;">Size</th>
                                <th style="width: 90px;">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($largestFiles as $f):
                                [$label, $badge] = $statusLabels[$f['status']] ?? [$f['status'], 'bg-secondary'];
                            ?>
                            <tr>
                                <td style="word-break: break-all;" title="<?= htmlspecialchars($f['path']) ?>">
                                    <span class="small"><?= htmlspecialchars($f['path']) ?></span>
                                </td>
                                <td class="text-end text-nowrap"><?= fmtSize($f['file_size']) ?></td>
                                <td><span class="badge <?= $badge ?>"><?= $label ?></span></td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if (empty($largestFiles)): ?>
                            <tr><td colspan="3" class="text-muted text-center py-3">No file data available</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- File Browser -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header card-head-gradient fw-semibold d-flex justify-content-between align-items-center">
        <div>
            <i class="bi bi-files me-1"></i> File Browser
        </div>
        <div style="width: 280px;">
            <input type="text" class="form-control form-control-sm" id="fileBrowserSearch" placeholder="Search files...">
        </div>
    </div>
    <div class="card-body pb-0">
        <ul class="nav nav-tabs" id="fileBrowserTabs">
            <li class="nav-item"><a class="nav-link active" href="javascript:void(0)" data-status="">All</a></li>
            <li class="nav-item"><a class="nav-link" href="javascript:void(0)" data-status="A">Added <span class="badge text-bg-success" id="tab-count-A"></span></a></li>
            <li class="nav-item"><a class="nav-link" href="javascript:void(0)" data-status="M">Modified <span class="badge text-bg-warning" id="tab-count-M"></span></a></li>
            <?php if ($prevArchive): ?>
            <li class="nav-item"><a class="nav-link" href="javascript:void(0)" data-status="deleted">Deleted <span class="badge text-bg-danger" id="tab-count-deleted"></span></a></li>
            <?php endif; ?>
            <li class="nav-item"><a class="nav-link" href="javascript:void(0)" data-status="U">Unchanged <span class="badge text-bg-secondary" id="tab-count-U"></span></a></li>
        </ul>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm table-hover small mb-0">
                <thead>
                    <tr>
                        <th>Path</th>
                        <th class="text-end" style="width:100px;">Size</th>
                        <th style="width:90px;">Status</th>
                    </tr>
                </thead>
                <tbody id="fileBrowserBody">
                    <tr><td colspan="3" class="text-center text-muted py-4"><span class="spinner-border spinner-border-sm me-1"></span> Loading...</td></tr>
                </tbody>
            </table>
        </div>
        <div class="d-flex justify-content-between align-items-center px-3 py-2 border-top">
            <small class="text-muted" id="fileBrowserInfo">--</small>
            <div>
                <button class="btn btn-sm btn-outline-secondary" id="fileBrowserPrev" disabled>&laquo; Prev</button>
                <button class="btn btn-sm btn-outline-secondary" id="fileBrowserNext" disabled>Next &raquo;</button>
            </div>
        </div>
    </div>
</div>

<script>
(function() {
    var agentId = <?= $agentId ?>;
    var repoId = <?= $repo['id'] ?>;
    var archiveId = <?= $archiveId ?>;
    var prevArchiveId = <?= $prevArchive ? (int) $prevArchive['id'] : 'null' ?>;
    var currentStatus = '';
    var currentSearch = '';
    var currentPage = 1;
    var perPage = 50;
    var searchTimeout = null;

    // [label, full-badge-class-token] — must mirror $statusLabels in the PHP prelude
    var statusLabels = {
        'A': ['Added',            'text-bg-success'],
        'M': ['Modified',         'text-bg-warning'],
        'C': ['Metadata Changed', 'text-bg-primary'],
        'U': ['Unchanged',        'text-bg-secondary'],
        'D': ['Directory',        'bg-secondary-subtle text-secondary-emphasis'],
        'S': ['Symlink',          'bg-secondary-subtle text-secondary-emphasis'],
        'H': ['Hardlink',         'bg-secondary-subtle text-secondary-emphasis'],
        'X': ['Excluded',         'bg-warning-subtle text-warning-emphasis'],
        'deleted': ['Deleted',    'text-bg-danger']
    };

    <?php foreach ($statusBreakdown as $row): ?>
    <?php if (!in_array($row['status'], $nonFileStatuses)): ?>
    var el = document.getElementById('tab-count-<?= $row['status'] ?>');
    if (el) el.textContent = '<?= number_format($row['cnt']) ?>';
    <?php endif; ?>
    <?php endforeach; ?>

    // Deferred: deleted-files summary. The anti-join that computes it is
    // expensive on large archives (millions of paths), so it's served by a
    // separate endpoint rather than blocking the initial page render.
    <?php if ($prevArchive): ?>
    (function loadDeletedSummary() {
        fetch('/clients/' + <?= (int) $agentId ?> + '/repo/' + <?= (int) $repo['id'] ?> + '/archive/' + <?= (int) $archiveId ?> + '/deleted-summary', { credentials: 'same-origin' })
            .then(function(r) { return r.ok ? r.json() : null; })
            .then(function(d) {
                if (!d || d.error || !d.count) return;
                var row = document.getElementById('row-deleted');
                var cnt = document.getElementById('deleted-count');
                var sz  = document.getElementById('deleted-size');
                var tab = document.getElementById('tab-count-deleted');
                if (cnt) cnt.textContent = Number(d.count).toLocaleString();
                if (sz)  sz.textContent  = fmtSize(d.size);
                if (row) row.hidden = false;
                if (tab) tab.textContent = Number(d.count).toLocaleString();
            })
            .catch(function() { /* silent */ });
    })();
    <?php endif; ?>

    function esc(s) { return s ? String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;') : ''; }

    function fmtSize(b) {
        if (!b || b == 0) return '--';
        b = parseInt(b);
        const s = '\u00A0';
        if (b >= 1073741824) return (b / 1073741824).toFixed(1) + s + 'GB';
        if (b >= 1048576) return (b / 1048576).toFixed(1) + s + 'MB';
        if (b >= 1024) return (b / 1024).toFixed(1) + s + 'KB';
        return b + s + 'B';
    }

    function loadFiles() {
        var tbody = document.getElementById('fileBrowserBody');
        tbody.innerHTML = '<tr><td colspan="3" class="text-center text-muted py-4"><span class="spinner-border spinner-border-sm me-1"></span> Loading...</td></tr>';

        var url = '/clients/' + agentId + '/repo/' + repoId + '/archive/' + archiveId + '/files'
            + '?page=' + currentPage + '&per_page=' + perPage;
        if (currentStatus) url += '&status=' + encodeURIComponent(currentStatus);
        if (currentSearch) url += '&search=' + encodeURIComponent(currentSearch);
        if (currentStatus === 'deleted' && prevArchiveId) url += '&prev_archive_id=' + prevArchiveId;

        fetch(url, { credentials: 'same-origin' })
            .then(function(r) {
                if (!r.ok) throw new Error('HTTP ' + r.status);
                return r.json();
            })
            .then(function(data) {
                if (data.error) { throw new Error(data.error); }
                var html = '';
                if (data.files && data.files.length > 0) {
                    data.files.forEach(function(f) {
                        var st = statusLabels[f.status] || [f.status, 'bg-secondary'];
                        html += '<tr>';
                        html += '<td style="word-break:break-all;">' + esc(f.path) + '</td>';
                        html += '<td class="text-end text-nowrap">' + fmtSize(f.file_size) + '</td>';
                        html += '<td><span class="badge ' + st[1] + '">' + st[0] + '</span></td>';
                        html += '</tr>';
                    });
                } else {
                    html = '<tr><td colspan="3" class="text-center text-muted py-4">No files found</td></tr>';
                }
                tbody.innerHTML = html;

                var total = data.total || 0;
                var pages = Math.ceil(total / perPage);
                document.getElementById('fileBrowserInfo').textContent =
                    'Showing ' + ((currentPage - 1) * perPage + 1) + '–' + Math.min(currentPage * perPage, total) + ' of ' + total.toLocaleString();
                document.getElementById('fileBrowserPrev').disabled = currentPage <= 1;
                document.getElementById('fileBrowserNext').disabled = currentPage >= pages;
            })
            .catch(function() {
                tbody.innerHTML = '<tr><td colspan="3" class="text-center text-danger py-4">Failed to load files</td></tr>';
            });
    }

    document.getElementById('fileBrowserTabs').addEventListener('click', function(e) {
        var link = e.target.closest('a[data-status]');
        if (!link) return;
        e.preventDefault();
        document.querySelectorAll('#fileBrowserTabs .nav-link').forEach(function(a) { a.classList.remove('active'); });
        link.classList.add('active');
        currentStatus = link.dataset.status;
        currentPage = 1;
        loadFiles();
    });

    document.getElementById('fileBrowserSearch').addEventListener('input', function() {
        clearTimeout(searchTimeout);
        var val = this.value;
        searchTimeout = setTimeout(function() {
            currentSearch = val;
            currentPage = 1;
            loadFiles();
        }, 300);
    });

    document.getElementById('fileBrowserPrev').addEventListener('click', function() {
        if (currentPage > 1) { currentPage--; loadFiles(); }
    });
    document.getElementById('fileBrowserNext').addEventListener('click', function() {
        currentPage++; loadFiles();
    });

    loadFiles();
})();
</script>

<?php elseif (!$clickhouseAvailable): ?>
<div class="alert alert-info">
    <i class="bi bi-info-circle me-1"></i> ClickHouse is not available. Backup file statistics require ClickHouse to be installed and running.
</div>
<?php else: ?>
<div class="alert alert-secondary">
    <i class="bi bi-info-circle me-1"></i> No file catalog data available for this archive. Run a catalog rebuild from the repository page to index file data.
</div>
<?php endif; ?>

<?php if ($hasDatabases && $dbInfo && !empty($dbInfo['databases'])): ?>
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header card-head-gradient fw-semibold">
        <i class="bi bi-database text-info me-1"></i> Database Backups
    </div>
    <div class="card-body">
        <?php foreach ($dbInfo['databases'] as $db): ?>
        <span class="badge text-bg-info me-1 mb-1"><?= htmlspecialchars($db) ?></span>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<?php if (!empty($jobInfo['directories'])): ?>
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header card-head-gradient fw-semibold">
        <i class="bi bi-folder me-1"></i> Backup Directories
    </div>
    <div class="card-body">
        <code class="small"><?= nl2br(htmlspecialchars($jobInfo['directories'])) ?></code>
    </div>
</div>
<?php endif; ?>
