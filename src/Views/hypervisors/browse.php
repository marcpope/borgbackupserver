<?php
/**
 * File-level browser of one VM restore point: disks -> partitions (primary,
 * extended, logical) -> LVM volume groups and logical volumes -> files.
 * Everything is read by the hypervisor agent (vm_browse tasks); this page
 * queues them and polls for their answers.
 */
$hid = (int) $hypervisor['id'];
$aid = (int) $archive['id'];
$when = \BBS\Core\TimeHelper::format($archive['created_at'], 'Y-m-d H:i');
?>
<div class="mb-3">
    <a href="/hypervisors/<?= $hid ?>?tab=restore" class="text-decoration-none small"><i class="bi bi-arrow-left me-1"></i><?= htmlspecialchars($hypervisor['name']) ?> · Restore</a>
</div>

<div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
    <div>
        <h5 class="mb-1"><i class="bi bi-folder2-open me-2"></i>Browse files · VM <?= htmlspecialchars($archive['vm_ref']) ?><?= $vmName ? ' · ' . htmlspecialchars($vmName) : '' ?></h5>
        <div class="text-muted small">Restore point <?= htmlspecialchars($when) ?> · repository <?= htmlspecialchars($archive['repo_name']) ?></div>
    </div>
</div>

<div id="vbLock" class="alert alert-warning small d-flex flex-wrap align-items-center gap-2 py-2" style="display:none !important">
    <i class="bi bi-lock-fill"></i>
    <span id="vbLockText"></span>
    <button class="btn btn-sm btn-warning ms-auto" id="vbClose"><i class="bi bi-unlock me-1"></i>Close session</button>
</div>

<div id="vbStatus" class="alert alert-info small d-flex align-items-center gap-2">
    <span class="spinner-border spinner-border-sm"></span>
    <span id="vbStatusText">Opening the restore point on the hypervisor (mounting the backup, reading partitions and filesystems)…</span>
</div>

<div class="row g-3" id="vbMain" style="display:none">
    <div class="col-lg-4">
        <div class="card border-0 shadow-sm">
            <div class="card-header fw-semibold"><i class="bi bi-device-hdd me-1"></i>Disks and volumes</div>
            <div class="card-body p-2" id="vbTree"></div>
        </div>
        <div class="text-muted small mt-2">
            <i class="bi bi-shield-lock me-1"></i>The filesystems are opened read-only inside a throwaway isolation VM
            on the hypervisor, never by the hypervisor itself; it is stopped after 10 minutes without activity or
            when a backup starts.
        </div>
    </div>
    <div class="col-lg-8">
        <div class="card border-0 shadow-sm">
            <div class="card-header d-flex flex-wrap align-items-center gap-2">
                <nav class="flex-grow-1" aria-label="path"><ol class="breadcrumb mb-0 small" id="vbCrumbs"><li class="breadcrumb-item text-muted">Choose a volume</li></ol></nav>
                <button class="btn btn-sm btn-primary" id="vbDownload" disabled><i class="bi bi-download me-1"></i>Download selection</button>
            </div>
            <div class="card-body p-0">
                <div id="vbListMsg" class="p-3 text-muted small">Pick a filesystem on the left to see its files.</div>
                <div class="table-responsive">
                    <table class="table table-hover table-sm mb-0 small align-middle" id="vbTable" style="display:none">
                        <thead class="table-light"><tr>
                            <th style="width:2rem"><input type="checkbox" class="form-check-input" id="vbAll"></th>
                            <th>Name</th><th class="text-end">Size</th><th>Modified</th><th class="d-none d-md-table-cell">Mode</th>
                        </tr></thead>
                        <tbody id="vbRows"></tbody>
                    </table>
                </div>
            </div>
            <div class="card-footer small text-muted" id="vbFoot" style="display:none"></div>
        </div>
        <div id="vbDownloads" class="mt-3"></div>
    </div>
</div>

<script>
(function () {
    const base = '/hypervisors/<?= $hid ?>';
    const browseUrl = base + '/browse/<?= $aid ?>';
    const csrf = <?= json_encode($this->csrfToken()) ?>;
    let structure = null;
    let current = null; // {volume, path}
    const listCache = {};

    const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));
    const fmt = b => {
        if (b === null || b === undefined) return '';
        const u = ['B', 'KB', 'MB', 'GB', 'TB']; let i = 0; let n = Number(b);
        while (n >= 1024 && i < u.length - 1) { n /= 1024; i++; }
        return (i ? n.toFixed(1) : n) + ' ' + u[i];
    };
    const status = (text, kind, spin) => {
        const el = document.getElementById('vbStatus');
        el.className = 'alert alert-' + (kind || 'info') + ' small d-flex align-items-center gap-2';
        el.style.display = text ? '' : 'none';
        el.innerHTML = (spin ? '<span class="spinner-border spinner-border-sm"></span>' : '') + '<span>' + esc(text) + '</span>';
    };

    // Queue a vm_browse task and wait for the agent's answer
    async function run(body, onWait) {
        const r = await fetch(browseUrl, {
            method: 'POST', headers: {'Content-Type': 'application/json', 'X-CSRF-Token': csrf},
            body: JSON.stringify(body),
        });
        const q = await r.json().catch(() => ({error: 'HTTP ' + r.status}));
        if (!r.ok || !q.task_id) throw new Error(q.error || 'request refused');
        const started = Date.now();
        for (;;) {
            await new Promise(res => setTimeout(res, Date.now() - started < 10000 ? 700 : 1500));
            const p = await fetch(base + '/browse-task/' + q.task_id).then(x => x.json());
            if (p.status === 'completed') return p;
            if (p.status === 'failed') throw new Error(p.error || 'failed');
            if (p.error) throw new Error(p.error);
            if (onWait) onWait(p);
            if (Date.now() - started > 30 * 60 * 1000) throw new Error('no answer from the agent');
        }
    }
    const waitText = (p, what) => p.state === 'queued' ? 'Waiting for the hypervisor agent (it may be busy with a backup)…'
        : (p.message || what);

    // -- tree ----------------------------------------------------------------
    function volLine(vid, label, extra, depth) {
        const v = structure.volumes[vid] || {};
        const fs = v.fs ? esc(v.fs) : 'unknown';
        const meta = [fs, fmt(v.size)].concat(v.label ? ['“' + esc(v.label) + '”'] : []).concat(extra || []).join(' · ');
        const pad = 'padding-left:' + (0.5 + depth * 1.1) + 'rem';
        if (v.browsable) {
            return '<a href="#" class="list-group-item list-group-item-action py-1 small vb-vol" data-vol="' + esc(vid) + '" style="' + pad + '">'
                + '<i class="bi bi-folder2 me-1 text-warning"></i>' + esc(label) + ' <span class="text-muted">' + meta + '</span></a>';
        }
        return '<div class="list-group-item py-1 small text-muted" style="' + pad + '" title="' + esc(v.error || '') + '">'
            + '<i class="bi bi-slash-circle me-1"></i>' + esc(label) + ' <span>' + meta + '</span>'
            + (v.error ? '<div class="fst-italic" style="font-size:.75rem">' + esc(v.error) + '</div>' : '') + '</div>';
    }
    function lvLines(vgName, depth) {
        const vg = (structure.volume_groups || []).find(g => g.name === vgName);
        if (!vg) return '';
        let h = '<div class="list-group-item py-1 small" style="padding-left:' + (0.5 + depth * 1.1) + 'rem"><i class="bi bi-collection me-1"></i>Volume group <strong>' + esc(vg.name) + '</strong>'
            + (vg.missing_pvs && vg.missing_pvs.length ? ' <span class="text-danger">(' + vg.missing_pvs.length + ' physical volume(s) not in this backup)</span>' : '') + '</div>';
        for (const lv of vg.lvs) {
            if (structure.volumes[lv.id]) {
                h += volLine(lv.id, 'LV ' + lv.name, [], depth + 1);
            } else {
                h += '<div class="list-group-item py-1 small text-muted" style="padding-left:' + (0.5 + (depth + 1) * 1.1) + 'rem"><i class="bi bi-slash-circle me-1"></i>LV '
                    + esc(lv.name) + ' · ' + fmt(lv.size) + '<div class="fst-italic" style="font-size:.75rem">' + esc(lv.unsupported || '') + '</div></div>';
            }
        }
        return h;
    }
    function renderTree() {
        let h = '<div class="list-group list-group-flush">';
        const shownVg = new Set();
        for (const d of structure.disks) {
            const table = d.table === 'gpt' ? 'GPT' : (d.table === 'dos' ? 'MBR' : 'no partition table');
            h += '<div class="list-group-item py-1 small fw-semibold"><i class="bi bi-device-hdd me-1"></i>Disk ' + esc(d.key) + ' <span class="text-muted fw-normal">· ' + fmt(d.size) + ' · ' + table + '</span></div>';
            for (const p of d.partitions) {
                if (p.logical) continue; // shown under their extended partition
                const depth = 1;
                if (p.extended) {
                    h += '<div class="list-group-item py-1 small text-muted" style="padding-left:' + (0.5 + depth * 1.1) + 'rem"><i class="bi bi-diagram-2 me-1"></i>Partition ' + p.number + ' · extended · ' + fmt(p.size) + '</div>';
                    for (const l of d.partitions.filter(x => x.logical)) {
                        h += partLine(l, depth + 1, 'Logical partition ' + l.number);
                        if (l.vg && !shownVg.has(l.vg)) { shownVg.add(l.vg); h += lvLines(l.vg, depth + 2); }
                    }
                    continue;
                }
                h += partLine(p, depth, p.number ? 'Partition ' + p.number : 'Whole disk');
                if (p.vg && !shownVg.has(p.vg)) { shownVg.add(p.vg); h += lvLines(p.vg, depth + 1); }
            }
        }
        h += '</div>';
        document.getElementById('vbTree').innerHTML = h;
        document.querySelectorAll('.vb-vol').forEach(a => a.addEventListener('click', e => {
            e.preventDefault(); openDir(a.dataset.vol, '');
        }));
    }
    function partLine(p, depth, label) {
        const extra = [esc(p.type)].concat(p.name ? [esc(p.name)] : []);
        if (structure.volumes[p.id]) return volLine(p.id, label, extra, depth);
        return '<div class="list-group-item py-1 small text-muted" style="padding-left:' + (0.5 + depth * 1.1) + 'rem">' + esc(label) + ' · ' + extra.join(' · ') + '</div>';
    }

    // -- listing -------------------------------------------------------------
    async function openDir(volume, path) {
        current = {volume, path};
        document.querySelectorAll('.vb-vol').forEach(a => a.classList.toggle('active', a.dataset.vol === volume));
        renderCrumbs();
        const key = volume + '\u0000' + path;
        if (!listCache[key]) {
            document.getElementById('vbTable').style.display = 'none';
            document.getElementById('vbFoot').style.display = 'none';
            const msg = document.getElementById('vbListMsg');
            msg.style.display = '';
            msg.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Reading the folder…';
            try {
                listCache[key] = (await run({action: 'list', volume, path}, p => {
                    msg.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>' + esc(waitText(p, 'Reading the folder…'));
                })).result;
            } catch (e) {
                msg.innerHTML = '<span class="text-danger">' + esc(e.message) + '</span>';
                return;
            }
        }
        if (current.volume !== volume || current.path !== path) return; // the user moved on
        renderList(listCache[key]);
    }
    function renderCrumbs() {
        const v = structure.volumes[current.volume] || {};
        const parts = current.path ? current.path.split('/') : [];
        let h = '<li class="breadcrumb-item"><a href="#" data-path="">' + esc(v.title || current.volume) + '</a></li>';
        parts.forEach((p, i) => {
            const sub = parts.slice(0, i + 1).join('/');
            h += i === parts.length - 1 ? '<li class="breadcrumb-item active">' + esc(p) + '</li>'
                : '<li class="breadcrumb-item"><a href="#" data-path="' + esc(sub) + '">' + esc(p) + '</a></li>';
        });
        const ol = document.getElementById('vbCrumbs');
        ol.innerHTML = h;
        ol.querySelectorAll('a').forEach(a => a.addEventListener('click', e => { e.preventDefault(); openDir(current.volume, a.dataset.path); }));
    }
    function renderList(res) {
        const icon = t => t === 'd' ? 'bi-folder-fill text-warning' : (t === 'l' ? 'bi-link-45deg text-info' : (t === 'f' ? 'bi-file-earmark' : 'bi-question-square'));
        let h = '';
        if (current.path) {
            const up = current.path.split('/').slice(0, -1).join('/');
            h += '<tr><td></td><td colspan="4"><a href="#" class="vb-dir" data-path="' + esc(up) + '"><i class="bi bi-arrow-90deg-up me-1"></i>..</a></td></tr>';
        }
        for (const e of res.entries) {
            const full = (current.path ? current.path + '/' : '') + e.name;
            const name = e.type === 'd'
                ? '<a href="#" class="vb-dir" data-path="' + esc(full) + '">' + esc(e.name) + '</a>'
                : esc(e.name) + (e.type === 'l' ? ' <span class="text-muted">→ ' + esc(e.target || '') + '</span>' : '');
            h += '<tr><td><input type="checkbox" class="form-check-input vb-sel" value="' + esc(full) + '"></td>'
                + '<td class="text-break"><i class="bi ' + icon(e.type) + ' me-1"></i>' + name + '</td>'
                + '<td class="text-end text-nowrap">' + fmt(e.size) + '</td>'
                + '<td class="text-nowrap">' + (e.mtime ? new Date(e.mtime * 1000).toLocaleString() : '') + '</td>'
                + '<td class="d-none d-md-table-cell font-monospace">' + esc(e.mode) + '</td></tr>';
        }
        if (!res.entries.length) h += '<tr><td></td><td colspan="4" class="text-muted">Empty folder</td></tr>';
        document.getElementById('vbRows').innerHTML = h;
        document.getElementById('vbListMsg').style.display = 'none';
        document.getElementById('vbTable').style.display = '';
        const foot = document.getElementById('vbFoot');
        foot.style.display = '';
        foot.textContent = res.entries.length + ' item(s)' + (res.truncated ? ' — only the first ' + res.entries.length + ' are shown' : '');
        document.getElementById('vbAll').checked = false;
        document.querySelectorAll('.vb-dir').forEach(a => a.addEventListener('click', e => { e.preventDefault(); openDir(current.volume, a.dataset.path); }));
        document.querySelectorAll('.vb-sel').forEach(c => c.addEventListener('change', updateButton));
        updateButton();
    }
    function selected() { return Array.from(document.querySelectorAll('.vb-sel:checked')).map(c => c.value); }
    function updateButton() {
        const n = selected().length;
        const b = document.getElementById('vbDownload');
        b.disabled = !current;
        b.innerHTML = '<i class="bi bi-download me-1"></i>' + (n ? 'Download ' + n + ' item' + (n > 1 ? 's' : '') : 'Download this folder');
    }
    document.getElementById('vbAll').addEventListener('change', e => {
        document.querySelectorAll('.vb-sel').forEach(c => { c.checked = e.target.checked; });
        updateButton();
    });

    // -- download ------------------------------------------------------------
    document.getElementById('vbDownload').addEventListener('click', async () => {
        if (!current) return;
        const paths = selected();
        const items = paths.length ? paths : [current.path];
        const label = (structure.volumes[current.volume] || {}).title + ': ' + (paths.length ? paths.length + ' item(s)' : (current.path || '/'));
        const box = document.createElement('div');
        box.className = 'alert alert-secondary small d-flex align-items-center gap-2 mb-2';
        box.innerHTML = '<span class="spinner-border spinner-border-sm"></span><span>Preparing ' + esc(label) + '…</span>';
        document.getElementById('vbDownloads').prepend(box);
        try {
            const res = await run({action: 'download', volume: current.volume, paths: items}, p => {
                box.lastChild.textContent = waitText(p, 'Preparing ' + label + '…');
            });
            box.className = 'alert alert-success small d-flex align-items-center gap-2 mb-2';
            box.innerHTML = '<i class="bi bi-check-circle"></i><span>' + esc(label) + ' · ' + fmt(res.result.file_size) + ' (tar.gz, '
                + res.result.entries + ' entries)</span><a class="btn btn-sm btn-success ms-auto" href="' + esc(res.download_url) + '"><i class="bi bi-download me-1"></i>Download</a>';
            window.location = res.download_url;
        } catch (e) {
            box.className = 'alert alert-danger small mb-2';
            box.textContent = label + ': ' + e.message;
        }
    });

    // -- repository lock held by this session --------------------------------
    // The session keeps the restore point mounted (borg mount), which locks
    // the repository; show for how long, and when it closes if left idle.
    let lock = null; // {locked, closesIn, at}: the server's numbers and when we got them
    const dur = s => {
        s = Math.max(0, Math.round(s));
        if (s < 60) return s + ' s';
        const m = Math.floor(s / 60);
        return m < 60 ? m + ' min' + (m < 10 ? ' ' + String(s % 60).padStart(2, '0') + ' s' : '')
            : Math.floor(m / 60) + ' h ' + String(m % 60).padStart(2, '0') + ' min';
    };
    function drawLock() {
        const bar = document.getElementById('vbLock');
        if (!lock) { bar.style.setProperty('display', 'none', 'important'); return; }
        const gone = (Date.now() - lock.at) / 1000;
        bar.style.removeProperty('display');
        document.getElementById('vbLockText').innerHTML = 'Repository <strong>' + esc(lock.repo) + '</strong> locked by this browse session for <strong>'
            + dur(lock.locked + gone) + '</strong>. It closes after 10 minutes without activity (in ' + dur(lock.closesIn - gone)
            + '), or when a backup or restore of this hypervisor starts.';
    }
    async function pollLock() {
        try {
            const s = await fetch(base + '/browse-session/<?= $aid ?>').then(x => x.json());
            lock = s.open ? {repo: s.repository, locked: s.locked_seconds, closesIn: s.closes_in_seconds, at: Date.now()} : null;
        } catch (e) { /* keep the last state */ }
        drawLock();
    }
    setInterval(drawLock, 1000);
    setInterval(pollLock, 15000);
    document.getElementById('vbClose').addEventListener('click', async () => {
        const b = document.getElementById('vbClose');
        b.disabled = true;
        await fetch(base + '/browse-close/<?= $aid ?>', {method: 'POST', headers: {'X-CSRF-Token': csrf, 'Accept': 'application/json'}});
        document.getElementById('vbLockText').textContent = 'Closing the session…';
        for (let i = 0; i < 20 && lock; i++) { await new Promise(r => setTimeout(r, 1500)); await pollLock(); }
        b.disabled = false;
        status('Session closed: the repository is released. Opening a folder again re-opens the restore point.', 'success', false);
        Object.keys(listCache).forEach(k => delete listCache[k]);
    });

    // -- open ----------------------------------------------------------------
    run({action: 'open'}, p => status(waitText(p, 'Opening the restore point on the hypervisor…'), 'info', true))
        .then(res => {
            structure = res.result;
            status('', null, false);
            document.getElementById('vbMain').style.display = '';
            renderTree();
            // First browsable volume in tree order: usually the system partition
            const order = [];
            for (const d of structure.disks) for (const p of d.partitions) order.push(p.id);
            for (const g of structure.volume_groups || []) for (const lv of g.lvs) order.push(lv.id);
            const first = order.find(v => (structure.volumes[v] || {}).browsable);
            if (first) openDir(first, '');
            pollLock();
        })
        .catch(e => status('Could not open this restore point: ' + e.message, 'danger', false));
})();
</script>
