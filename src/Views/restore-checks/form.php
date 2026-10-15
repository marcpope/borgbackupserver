<?php
/**
 * @var array $agent
 * @var array $plan
 * @var string[] $errors
 * @var array $repositories
 * @var array $backupPlans
 * @var array $archives
 * @var bool $clickhouseAvailable
 * @var string[] $timezones
 */
use BBS\Services\SchedulerService;

$gib = 1073741824;
$isNew = empty($plan['id']);
$agentId = (int) $agent['id'];
$action = $isNew ? "/clients/{$agentId}/restore-checks" : "/clients/{$agentId}/restore-checks/{$plan['id']}/edit";
$probes = is_array($plan['paths'] ?? null) ? $plan['paths'] : (json_decode((string) ($plan['paths'] ?? '[]'), true) ?: []);
if (!$probes) {
    $probes = [['path' => '', 'sha256' => null]];
}
$weekDays = SchedulerService::parseDaysOfWeek($plan['day_of_week'] ?? '1') ?: [1];
$fmtGib = fn($bytes) => rtrim(rtrim(number_format(((int) $bytes) / $gib, 2, '.', ''), '0'), '.');
$e = fn($v) => htmlspecialchars((string) $v);
?>
<nav class="mb-3">
    <ol class="breadcrumb mb-0">
        <li class="breadcrumb-item"><a href="/clients">Clients</a></li>
        <li class="breadcrumb-item"><a href="/clients/<?= $agentId ?>?tab=restore"><?= $e($agent['name']) ?></a></li>
        <li class="breadcrumb-item"><a href="/clients/<?= $agentId ?>?tab=restore#restore-checks">Restore checks</a></li>
        <li class="breadcrumb-item active"><?= $isNew ? 'New' : $e($plan['name']) ?></li>
    </ol>
</nav>

<?php if ($errors): ?>
<div class="alert alert-danger">
    <div class="fw-semibold mb-1">The restore check was not saved:</div>
    <ul class="mb-0"><?php foreach ($errors as $err): ?><li><?= $e($err) ?></li><?php endforeach; ?></ul>
</div>
<?php endif; ?>

<form method="POST" action="<?= $e($action) ?>" id="rc-form">
    <input type="hidden" name="csrf_token" value="<?= $this->csrfToken() ?>">
    <input type="hidden" name="browse_archive_name" id="rc-browse-archive-name" value="<?= $e($plan['browse_archive_name'] ?? '') ?>">

    <div class="row g-4">
        <div class="col-lg-7">
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header fw-semibold"><i class="bi bi-clipboard-check me-1"></i> What to test</div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label fw-semibold" for="rc-name">Name</label>
                        <input type="text" class="form-control" id="rc-name" name="name" maxlength="100" required value="<?= $e($plan['name'] ?? '') ?>" placeholder="Weekly config files">
                    </div>
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold" for="rc-repo">Repository</label>
                            <select class="form-select" id="rc-repo" name="repository_id" required>
                                <option value="">Choose…</option>
                                <?php foreach ($repositories as $r): ?>
                                <option value="<?= (int) $r['id'] ?>" <?= (int) ($plan['repository_id'] ?? 0) === (int) $r['id'] ? 'selected' : '' ?>><?= $e($r['name']) ?><?= $r['storage_type'] === 'remote_ssh' ? ' (remote SSH)' : '' ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Archives tested</label>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="scope" id="rc-scope-plan" value="plan" <?= ($plan['scope'] ?? 'plan') === 'plan' ? 'checked' : '' ?>>
                                <label class="form-check-label" for="rc-scope-plan">Newest from one backup plan</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="scope" id="rc-scope-repo" value="repository" <?= ($plan['scope'] ?? 'plan') === 'repository' ? 'checked' : '' ?>>
                                <label class="form-check-label" for="rc-scope-repo">Newest in the whole repository</label>
                            </div>
                        </div>
                    </div>
                    <div class="mb-3" id="rc-plan-wrap">
                        <label class="form-label fw-semibold" for="rc-plan">Backup plan</label>
                        <select class="form-select" id="rc-plan" name="backup_plan_id">
                            <option value="">Choose…</option>
                            <?php foreach ($backupPlans as $bp): ?>
                            <option value="<?= (int) $bp['id'] ?>" data-repo="<?= (int) $bp['repository_id'] ?>" <?= (int) ($plan['backup_plan_id'] ?? 0) === (int) $bp['id'] ? 'selected' : '' ?>><?= $e($bp['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text">Each run tests the newest archive this plan created in a successful backup. Archives from failed backups, checkpoints and imported archives are never used.</div>
                    </div>

                    <label class="form-label fw-semibold">Coverage</label>
                    <div class="mb-3">
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="radio" name="mode" id="rc-mode-paths" value="paths" <?= ($plan['mode'] ?? 'paths') === 'paths' ? 'checked' : '' ?>>
                            <label class="form-check-label" for="rc-mode-paths">Selected paths</label>
                        </div>
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="radio" name="mode" id="rc-mode-full" value="full" <?= ($plan['mode'] ?? 'paths') === 'full' ? 'checked' : '' ?>>
                            <label class="form-check-label" for="rc-mode-full">Full archive</label>
                        </div>
                    </div>

                    <div id="rc-paths-section">
                        <div class="form-text mb-2">
                            Archive paths, matched literally (no wildcards). A directory includes everything below it.
                            An expected SHA-256 is optional: give it only for a file whose content you know independently and that never changes.
                        </div>
                        <div id="rc-paths">
                            <?php foreach ($probes as $p): ?>
                            <div class="input-group input-group-sm mb-2 rc-path-row">
                                <input type="text" class="form-control font-monospace" name="paths[]" placeholder="etc/hostname" value="<?= $e($p['path'] ?? '') ?>" aria-label="Archive path">
                                <input type="text" class="form-control font-monospace" name="sha256[]" placeholder="expected SHA-256 (optional)" value="<?= $e($p['sha256'] ?? '') ?>" style="max-width:20rem" aria-label="Expected SHA-256">
                                <button type="button" class="btn btn-outline-secondary rc-remove" title="Remove"><i class="bi bi-x-lg"></i></button>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <button type="button" class="btn btn-sm btn-outline-secondary" id="rc-add-path"><i class="bi bi-plus-lg me-1"></i>Add path</button>

                        <div class="border rounded p-2 mt-3">
                            <div class="d-flex flex-wrap gap-2 align-items-center mb-2">
                                <span class="small fw-semibold"><i class="bi bi-folder2-open me-1"></i>Browse an archive</span>
                                <select class="form-select form-select-sm" id="rc-browse-archive" style="max-width:24rem" <?= $clickhouseAvailable ? '' : 'disabled' ?>>
                                    <option value="">Choose an archive…</option>
                                    <?php foreach ($archives as $a): ?>
                                    <option value="<?= (int) $a['id'] ?>" data-repo="<?= (int) $a['repository_id'] ?>" data-plan="<?= (int) $a['backup_plan_id'] ?>" data-name="<?= $e($a['archive_name']) ?>"><?= $e($a['archive_name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <?php if (!$clickhouseAvailable): ?>
                            <div class="small text-muted">The file catalog is unavailable, so paths must be typed.</div>
                            <?php else: ?>
                            <div class="small text-muted mb-2" id="rc-browse-note">Browsing only helps you pick paths. Every run uses the newest eligible archive, not the one browsed here.</div>
                            <div class="small font-monospace mb-1" id="rc-browse-path"></div>
                            <div id="rc-browse-list" class="small" style="max-height:18rem;overflow:auto"></div>
                            <?php endif; ?>
                        </div>
                        <div class="row g-3 mt-1">
                            <div class="col-md-6">
                                <label class="form-label small fw-semibold" for="rc-max-gib">Maximum extraction size (GiB)</label>
                                <input type="number" class="form-control form-control-sm" id="rc-max-gib" name="max_gib" min="0.001" step="any" value="<?= $e($fmtGib($plan['max_bytes'] ?? 10 * $gib)) ?>">
                            </div>
                        </div>
                    </div>

                    <div id="rc-full-section">
                        <div class="alert alert-warning small py-2">
                            Full archive mode extracts every item of the archive onto the BBS server's staging filesystem. Size the budget for the whole archive;
                            a run whose archive is larger fails before extracting anything.
                        </div>
                        <label class="form-label small fw-semibold" for="rc-full-gib">Extraction budget for the full archive (GiB, required)</label>
                        <input type="number" class="form-control form-control-sm" id="rc-full-gib" name="full_max_gib" min="0.001" step="any" style="max-width:12rem"
                               value="<?= ($plan['mode'] ?? '') === 'full' && (int) $plan['max_bytes'] > 0 ? $e($fmtGib($plan['max_bytes'])) : '' ?>">
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-5">
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header fw-semibold"><i class="bi bi-calendar-event me-1"></i> Schedule</div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-6">
                            <label class="form-label small fw-semibold" for="rc-frequency">Frequency</label>
                            <select class="form-select form-select-sm" id="rc-frequency" name="frequency">
                                <?php foreach (['daily' => 'Daily', 'weekly' => 'Weekly', 'monthly' => 'Monthly'] as $k => $v): ?>
                                <option value="<?= $k ?>" <?= ($plan['frequency'] ?? 'weekly') === $k ? 'selected' : '' ?>><?= $v ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-6">
                            <label class="form-label small fw-semibold" for="rc-times">Times (HH:MM, comma-separated)</label>
                            <input type="text" class="form-control form-control-sm" id="rc-times" name="times" value="<?= $e($plan['times'] ?? '03:00') ?>">
                        </div>
                        <div class="col-12" id="rc-dow-wrap">
                            <label class="form-label small fw-semibold d-block">Days</label>
                            <?php foreach (['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'] as $i => $d): ?>
                            <div class="form-check form-check-inline">
                                <input class="form-check-input" type="checkbox" name="day_of_week[]" id="rc-dow-<?= $i ?>" value="<?= $i ?>" <?= in_array($i, $weekDays, true) ? 'checked' : '' ?>>
                                <label class="form-check-label small" for="rc-dow-<?= $i ?>"><?= $d ?></label>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="col-12" id="rc-dom-wrap">
                            <label class="form-label small fw-semibold" for="rc-dom">Days of the month (1-28, comma-separated, or "last")</label>
                            <input type="text" class="form-control form-control-sm" id="rc-dom" name="day_of_month" value="<?= $e($plan['day_of_month'] ?? '1') ?>">
                        </div>
                        <div class="col-12">
                            <label class="form-label small fw-semibold" for="rc-tz">Timezone</label>
                            <input type="text" class="form-control form-control-sm" id="rc-tz" name="timezone" list="rc-tz-list" value="<?= $e($plan['timezone'] ?? 'UTC') ?>" required>
                            <datalist id="rc-tz-list"><?php foreach ($timezones as $tz): ?><option value="<?= $e($tz) ?>"><?php endforeach; ?></datalist>
                            <div class="form-text">A time skipped by a daylight-saving change does not run that day; a time that happens twice runs once.</div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header fw-semibold"><i class="bi bi-sliders me-1"></i> Limits and alerts</div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-6">
                            <label class="form-label small fw-semibold" for="rc-age">Maximum archive age (hours)</label>
                            <input type="number" class="form-control form-control-sm" id="rc-age" name="max_archive_age_hours" min="1" max="8760" value="<?= (int) ($plan['max_archive_age_hours'] ?? 48) ?>">
                        </div>
                        <div class="col-6">
                            <label class="form-label small fw-semibold" for="rc-overdue">Overdue after (hours without a pass)</label>
                            <input type="number" class="form-control form-control-sm" id="rc-overdue" name="overdue_hours" min="1" max="8760" value="<?= (int) ($plan['overdue_hours'] ?? 192) ?>">
                        </div>
                        <div class="col-6">
                            <label class="form-label small fw-semibold" for="rc-queue">Maximum queue wait (minutes)</label>
                            <input type="number" class="form-control form-control-sm" id="rc-queue" name="queue_deadline_minutes" min="10" max="10080" value="<?= (int) ($plan['queue_deadline_minutes'] ?? 1440) ?>">
                        </div>
                        <div class="col-6">
                            <label class="form-label small fw-semibold" for="rc-timeout">Maximum run time (minutes)</label>
                            <input type="number" class="form-control form-control-sm" id="rc-timeout" name="run_timeout_minutes" min="5" max="2880" value="<?= (int) ($plan['run_timeout_minutes'] ?? 120) ?>">
                        </div>
                        <div class="col-6">
                            <label class="form-label small fw-semibold" for="rc-files">Maximum items</label>
                            <input type="number" class="form-control form-control-sm" id="rc-files" name="max_files" min="1" value="<?= (int) ($plan['max_files'] ?? 100000) ?>">
                        </div>
                        <div class="col-6">
                            <label class="form-label small fw-semibold" for="rc-free">Free space to keep (GiB)</label>
                            <input type="number" class="form-control form-control-sm" id="rc-free" name="min_free_gib" min="0" step="any" value="<?= $e($fmtGib($plan['min_free_bytes'] ?? 5 * $gib)) ?>">
                        </div>
                        <div class="col-12">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="allow_warning_archives" id="rc-allow-warn" value="1" <?= !empty($plan['allow_warning_archives']) ? 'checked' : '' ?>>
                                <label class="form-check-label small" for="rc-allow-warn">Also test archives from backups that finished with warnings (the result is then at best "warnings")</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="notify_success" id="rc-notify-success" value="1" <?= !empty($plan['notify_success']) ? 'checked' : '' ?>>
                                <label class="form-check-label small" for="rc-notify-success">Notify on success too (failures, warnings, overdue and cleanup problems always notify)</label>
                            </div>
                        </div>
                        <div class="col-12 small text-muted">
                            <i class="bi bi-hdd me-1"></i>Runs on the BBS server in private staging under <code>/var/bbs/restore-checks</code>, as the repository's own user. Restored data is deleted after every run.
                        </div>
                    </div>
                </div>
            </div>

            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i><?= $isNew ? 'Create (disabled)' : 'Save' ?></button>
                <a href="<?= $isNew ? "/clients/{$agentId}?tab=restore#restore-checks" : "/clients/{$agentId}/restore-checks/{$plan['id']}" ?>" class="btn btn-outline-secondary">Cancel</a>
            </div>
            <?php if ($isNew): ?>
            <div class="form-text mt-2">New checks are saved disabled. Review, then enable the schedule or use Run now.</div>
            <?php endif; ?>
        </div>
    </div>
</form>

<script>
(function () {
    const agentId = <?= $agentId ?>;
    const $ = (sel) => document.querySelector(sel);
    const repo = $('#rc-repo'), plan = $('#rc-plan'), archiveSel = $('#rc-browse-archive');

    function sync() {
        const scopePlan = $('#rc-scope-plan').checked;
        $('#rc-plan-wrap').hidden = !scopePlan;
        const full = $('#rc-mode-full').checked;
        $('#rc-paths-section').hidden = full;
        $('#rc-full-section').hidden = !full;
        const freq = $('#rc-frequency').value;
        $('#rc-dow-wrap').hidden = freq !== 'weekly';
        $('#rc-dom-wrap').hidden = freq !== 'monthly';
        const r = repo.value;
        for (const o of plan.options) { if (o.value) o.hidden = o.dataset.repo !== r; }
        if (plan.selectedOptions[0] && plan.selectedOptions[0].hidden) plan.value = '';
        if (archiveSel) {
            for (const o of archiveSel.options) {
                if (!o.value) continue;
                o.hidden = o.dataset.repo !== r || (scopePlan && plan.value && o.dataset.plan !== plan.value);
            }
        }
    }
    document.querySelectorAll('#rc-form input, #rc-form select').forEach(el => el.addEventListener('change', sync));
    sync();

    function addRow(path) {
        const first = document.querySelector('#rc-paths .rc-path-row');
        const empty = Array.from(document.querySelectorAll('#rc-paths input[name="paths[]"]')).find(i => i.value === '');
        if (path !== undefined && empty) { empty.value = path; return; }
        const row = first.cloneNode(true);
        row.querySelectorAll('input').forEach(i => i.value = '');
        if (path !== undefined) row.querySelector('input[name="paths[]"]').value = path;
        $('#rc-paths').appendChild(row);
    }
    $('#rc-add-path').addEventListener('click', () => addRow());
    $('#rc-paths').addEventListener('click', (ev) => {
        const btn = ev.target.closest('.rc-remove');
        if (!btn) return;
        const rows = document.querySelectorAll('#rc-paths .rc-path-row');
        if (rows.length > 1) btn.closest('.rc-path-row').remove();
        else rows[0].querySelectorAll('input').forEach(i => i.value = '');
    });

    if (!archiveSel || archiveSel.disabled) return;
    const list = $('#rc-browse-list'), crumb = $('#rc-browse-path');

    function entry(icon, label, onOpen, addPath) {
        const div = document.createElement('div');
        div.className = 'd-flex align-items-center gap-2 py-1 border-bottom';
        const i = document.createElement('i'); i.className = 'bi ' + icon;
        const name = document.createElement(onOpen ? 'a' : 'span');
        name.textContent = label; name.className = 'font-monospace text-break flex-grow-1';
        if (onOpen) { name.href = '#'; name.addEventListener('click', (e) => { e.preventDefault(); onOpen(); }); }
        div.append(i, name);
        if (addPath !== null) {
            const b = document.createElement('button');
            b.type = 'button'; b.className = 'btn btn-sm btn-link py-0'; b.textContent = 'Add';
            b.addEventListener('click', () => addRow(addPath));
            div.append(b);
        }
        return div;
    }

    function load(path) {
        const id = archiveSel.value;
        list.textContent = '';
        crumb.textContent = '';
        if (!id) return;
        $('#rc-browse-archive-name').value = archiveSel.selectedOptions[0].dataset.name || '';
        crumb.textContent = 'Archive ' + (archiveSel.selectedOptions[0].dataset.name || '') + ' : ' + path;
        fetch('/clients/' + agentId + '/catalog/' + encodeURIComponent(id) + '/tree?path=' + encodeURIComponent(path), {headers: {'Accept': 'application/json'}})
            .then(r => r.json())
            .then(data => {
                if (path !== '/') {
                    const parent = path.replace(/[^/]+\/$/, '') || '/';
                    list.append(entry('bi-arrow-90deg-up', '..', () => load(parent), null));
                }
                (data.dirs || []).forEach(d => list.append(entry('bi-folder', d.name + '/', () => load(d.path), d.path.replace(/\/$/, ''))));
                (data.files || []).forEach(f => list.append(entry('bi-file-earmark', f.file_name, null, f.file_path)));
                if (!(data.dirs || []).length && !(data.files || []).length) {
                    const none = document.createElement('div'); none.className = 'text-muted'; none.textContent = 'Nothing catalogued here.';
                    list.append(none);
                }
            })
            .catch(() => { list.textContent = 'Could not load the catalog.'; });
    }
    archiveSel.addEventListener('change', () => load('/'));
})();
</script>
