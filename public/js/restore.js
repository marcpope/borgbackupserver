(function() {
    const agentId = window.RESTORE_AGENT_ID;
    if (!agentId) return;

    const clickhouseAvailable = !!window.CLICKHOUSE_AVAILABLE;

    // Read URL params so other pages can deep-link to a specific archive
    // and restore mode (e.g. the archive detail page's "Restore" buttons)
    const urlParams = new URLSearchParams(window.location.search);
    const preselectArchive = urlParams.get('archive') || '';
    const preselectMode = urlParams.get('mode') || '';  // 'files' | 'database'

    // DOM refs
    const archiveSelect = document.getElementById('archive-select');
    const searchInput = document.getElementById('restore-search');
    const searchBtn = document.getElementById('restore-search-btn');
    const searchModeBtn = document.getElementById('search-mode-btn');
    const searchModeMenu = document.getElementById('search-mode-menu');
    const browsePanel = document.getElementById('browse-panel');
    const searchPanel = document.getElementById('search-panel');
    const historyPanel = document.getElementById('history-panel');
    const treeRoot = document.getElementById('tree-root');
    const treeLoading = document.getElementById('tree-loading');
    const treeEmpty = document.getElementById('tree-empty');
    const backBtn = document.getElementById('back-to-browse');
    const selectedList = document.getElementById('selected-list');
    const selectedCountEl = document.getElementById('selected-count');
    const noSelection = document.getElementById('no-selection');
    const restoreBtn = document.getElementById('restore-btn');
    const downloadBtn = document.getElementById('download-btn');

    // Manual path mode refs (when ClickHouse unavailable)
    const manualPathPanel = document.getElementById('manual-path-panel');
    const manualPathInput = document.getElementById('manual-path-input');
    const manualPathAddBtn = document.getElementById('manual-path-add-btn');
    const manualPathForm = document.getElementById('manual-path-form');
    const manualPathInfo = document.getElementById('manual-path-info');
    const manualPathPlaceholder = document.getElementById('manual-path-placeholder');
    const manualEntireArchive = document.getElementById('manual-path-entire-archive');
    // Ticking every top-level entry by hand was the only way to take a whole
    // archive, and a full system backup has a lot of them (#437).
    const treeSelectAll = document.getElementById('tree-select-all');
    let entireArchiveSelected = false;

    // State
    let selectedPaths = new Set();           // Set of path strings (single-archive mode)
    let selectedVersions = new Map();        // Map<path, archiveId> (cross-archive mode)
    let currentMode = 'browse';              // browse | search-current | search-all
    let searchMode = 'current';              // current | all
    let searchPage = 1;

    // Utilities
    function formatSize(bytes) {
        if (bytes === null || bytes === undefined || bytes === '') return '';
        bytes = Number(bytes);
        if (bytes === 0) return '0\u00A0B';
        const units = ['B', 'KB', 'MB', 'GB', 'TB'];
        let i = 0, size = bytes;
        while (size >= 1024 && i < units.length - 1) { size /= 1024; i++; }
        return size.toFixed(i > 0 ? 1 : 0) + '\u00A0' + units[i];
    }

    function esc(s) { return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

    function statusBadge(s) {
        const map = { A: ['success','New'], M: ['warning','Mod'], U: ['secondary','Unch'], E: ['danger','Err'] };
        const [color, label] = map[s] || ['secondary', s];
        return '<span class="badge bg-' + color + '" style="font-size:0.65em;">' + label + '</span>';
    }

    function formatDate(d) {
        if (!d) return '';
        const dt = new Date(d);
        return dt.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }) +
               ' ' + dt.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' });
    }

    // Path selection helpers
    function isPathSelected(path) {
        if (selectedPaths.has(path)) return true;
        for (const sel of selectedPaths) {
            if (sel.endsWith('/') && path.startsWith(sel)) return true;
        }
        return false;
    }

    function togglePath(path, checked) {
        // Picking a path means the user no longer wants the whole archive.
        if (checked && entireArchiveSelected) {
            entireArchiveSelected = false;
            if (manualEntireArchive) manualEntireArchive.checked = false;
        }
        if (checked) {
            if (path.endsWith('/')) {
                for (const sel of selectedPaths) {
                    if (sel.startsWith(path) && sel !== path) selectedPaths.delete(sel);
                }
            }
            selectedPaths.add(path);
        } else {
            selectedPaths.delete(path);
        }
        updateSelectionUI();
    }

    function toggleVersion(path, archiveId, checked) {
        if (checked && entireArchiveSelected) {
            entireArchiveSelected = false;
            if (manualEntireArchive) manualEntireArchive.checked = false;
        }
        if (checked) {
            selectedVersions.set(path, archiveId);
        } else {
            selectedVersions.delete(path);
        }
        updateSelectionUI();
    }

    // Selection UI
    function updateSelectionUI() {
        const isCrossArchive = (currentMode === 'search-all' && selectedVersions.size > 0);
        const count = isCrossArchive ? selectedVersions.size : selectedPaths.size;
        const hasSelection = count > 0 || entireArchiveSelected;

        selectedCountEl.textContent = entireArchiveSelected ? 'all' : count;
        restoreBtn.disabled = !hasSelection;
        downloadBtn.disabled = !hasSelection;
        noSelection.style.display = hasSelection ? 'none' : 'block';

        selectedList.innerHTML = '';

        if (entireArchiveSelected) {
            const div = document.createElement('div');
            div.className = 'restore-selection-item';
            div.innerHTML =
                '<div class="small font-monospace text-truncate"><i class="bi bi-archive-fill text-primary me-1"></i>Entire archive</div>' +
                '<button class="btn btn-sm p-0 btn-remove" data-remove-entire><i class="bi bi-x-lg text-danger"></i></button>';
            selectedList.appendChild(div);
        }

        if (isCrossArchive) {
            selectedVersions.forEach((archiveId, path) => {
                const div = document.createElement('div');
                div.className = 'restore-selection-item';
                const isDir = path.endsWith('/');
                div.innerHTML =
                    '<div class="small font-monospace text-truncate"><i class="bi bi-' + (isDir ? 'folder-fill text-warning' : 'file-earmark') + ' me-1"></i>' + esc(path) +
                    '<br><span class="text-muted" style="font-size:0.75em;">Archive #' + archiveId + '</span></div>' +
                    '<button class="btn btn-sm p-0 btn-remove" data-remove-version="' + esc(path) + '"><i class="bi bi-x-lg text-danger"></i></button>';
                selectedList.appendChild(div);
            });
        } else {
            selectedPaths.forEach(path => {
                const div = document.createElement('div');
                div.className = 'restore-selection-item';
                const isDir = path.endsWith('/');
                div.innerHTML =
                    '<div class="small font-monospace text-truncate"><i class="bi bi-' + (isDir ? 'folder-fill text-warning' : 'file-earmark') + ' me-1"></i>' + esc(path) + '</div>' +
                    '<button class="btn btn-sm p-0 btn-remove" data-remove="' + esc(path) + '"><i class="bi bi-x-lg text-danger"></i></button>';
                selectedList.appendChild(div);
            });
        }
    }

    // Remove from selection
    selectedList.addEventListener('click', function(e) {
        const entireBtn = e.target.closest('[data-remove-entire]');
        if (entireBtn) {
            entireArchiveSelected = false;
            if (manualEntireArchive) manualEntireArchive.checked = false;
            if (manualEntireArchive) manualEntireArchive.checked = false;
            updateSelectionUI();
            return;
        }
        const btn = e.target.closest('[data-remove]');
        if (btn) {
            selectedPaths.delete(btn.dataset.remove);
            if (treeRoot) {
                const cb = treeRoot.querySelector('input[data-path="' + CSS.escape(btn.dataset.remove) + '"]');
                if (cb) cb.checked = false;
            }
            updateSelectionUI();
        }
        const vBtn = e.target.closest('[data-remove-version]');
        if (vBtn) {
            selectedVersions.delete(vBtn.dataset.removeVersion);
            updateSelectionUI();
        }
    });

    // Tree loading
    function loadTreeNode(parentEl, path) {
        const archiveId = archiveSelect.value;
        if (!archiveId) return;

        const spinner = document.createElement('div');
        spinner.className = 'ps-4 py-1 text-muted small';
        spinner.innerHTML = '<div class="spinner-border spinner-border-sm me-1"></div> Loading...';
        parentEl.appendChild(spinner);

        fetch('/clients/' + agentId + '/catalog/' + archiveId + '/tree?path=' + encodeURIComponent(path), { credentials: 'same-origin' })
            .then(r => r.json())
            .then(data => {
                spinner.remove();

                if (data.dirs.length === 0 && data.files.length === 0) {
                    if (path === '/') {
                        browsePanel.querySelector('.restore-panel-body').innerHTML =
                            '<div class="p-3 text-muted text-center">No file catalog available for this archive.</div>';
                    }
                    return;
                }

                data.dirs.forEach(d => {
                    const item = document.createElement('div');
                    item.className = 'tree-item tree-dir';
                    const isChecked = isPathSelected(d.path);

                    item.innerHTML =
                        '<span class="tree-toggle"><i class="bi bi-chevron-right"></i></span>' +
                        '<input type="checkbox" class="tree-cb" data-path="' + esc(d.path) + '" data-type="dir"' + (isChecked ? ' checked' : '') + '>' +
                        '<span class="tree-icon"><i class="bi bi-folder-fill text-warning"></i></span>' +
                        '<span class="tree-label">' + esc(d.name) + '</span>' +
                        '<span class="tree-meta"><span class="tree-count">(' + d.file_count.toLocaleString() + ' files, ' + formatSize(d.total_size) + ')</span></span>';

                    const children = document.createElement('div');
                    children.className = 'tree-children';
                    children.style.paddingLeft = '20px';

                    parentEl.appendChild(item);
                    parentEl.appendChild(children);

                    let loaded = false;

                    item.querySelector('.tree-toggle, .tree-label').addEventListener('click', function(e) {
                        e.stopPropagation();
                        const isOpen = children.classList.contains('open');
                        if (isOpen) {
                            children.classList.remove('open');
                            item.querySelector('.tree-toggle i').className = 'bi bi-chevron-right';
                        } else {
                            children.classList.add('open');
                            item.querySelector('.tree-toggle i').className = 'bi bi-chevron-down';
                            if (!loaded) {
                                loaded = true;
                                loadTreeNode(children, d.path);
                            }
                        }
                    });

                    item.querySelector('.tree-cb').addEventListener('change', function(e) {
                        e.stopPropagation();
                        togglePath(d.path, this.checked);
                        if (this.checked) {
                            children.querySelectorAll('.tree-cb').forEach(cb => cb.checked = true);
                        } else {
                            children.querySelectorAll('.tree-cb').forEach(cb => cb.checked = false);
                        }
                    });
                });

                data.files.forEach(f => {
                    const item = document.createElement('div');
                    item.className = 'tree-item tree-file';
                    const isChecked = isPathSelected(f.file_path);

                    item.innerHTML =
                        '<span class="tree-toggle"></span>' +
                        '<input type="checkbox" class="tree-cb" data-path="' + esc(f.file_path) + '" data-type="file"' + (isChecked ? ' checked' : '') + '>' +
                        '<span class="tree-icon"><i class="bi bi-file-earmark"></i></span>' +
                        '<span class="tree-label">' + esc(f.file_name) + '</span>' +
                        '<span class="tree-meta">' +
                            statusBadge(f.status) +
                            '<span class="tree-size">' + formatSize(f.file_size) + '</span>' +
                            (f.mtime ? '<span class="tree-mtime">' + formatDate(f.mtime) + '</span>' : '') +
                        '</span>';

                    parentEl.appendChild(item);

                    item.querySelector('.tree-cb').addEventListener('change', function(e) {
                        e.stopPropagation();
                        togglePath(f.file_path, this.checked);
                    });
                });
            })
            .catch(() => {
                spinner.remove();
                if (path === '/') {
                    browsePanel.querySelector('.restore-panel-body').innerHTML =
                        '<div class="p-3 text-muted text-center">Failed to load file catalog.</div>';
                }
            });
    }

    // Show/hide panels
    function showBrowse() {
        currentMode = 'browse';
        if (browsePanel) browsePanel.style.display = '';
        if (searchPanel) searchPanel.style.display = 'none';
        if (historyPanel) historyPanel.style.display = 'none';
        if (backBtn) backBtn.style.display = 'none';
    }

    function showSearchCurrent() {
        currentMode = 'search-current';
        if (browsePanel) browsePanel.style.display = 'none';
        if (searchPanel) searchPanel.style.display = '';
        if (historyPanel) historyPanel.style.display = 'none';
        if (backBtn) backBtn.style.display = '';
    }

    function showHistory() {
        currentMode = 'search-all';
        if (browsePanel) browsePanel.style.display = 'none';
        if (searchPanel) searchPanel.style.display = 'none';
        if (historyPanel) historyPanel.style.display = '';
        if (backBtn) backBtn.style.display = '';
    }

    // Search mode switching
    if (searchModeMenu) {
        searchModeMenu.addEventListener('click', function(e) {
            const item = e.target.closest('[data-mode]');
            if (item) {
                searchMode = item.dataset.mode;
                searchModeBtn.innerHTML = '<i class="bi bi-funnel' + (searchMode === 'all' ? '-fill' : '') + '"></i>';
                // Enable search even without archive selected in "all" mode
                if (searchMode === 'all') {
                    searchInput.disabled = false;
                    searchBtn.disabled = false;
                } else {
                    searchInput.disabled = !archiveSelect.value;
                    searchBtn.disabled = !archiveSelect.value;
                }
            }
        });
    }

    // Archive selection
    archiveSelect.addEventListener('change', function() {
        selectedPaths.clear();
        selectedVersions.clear();
        entireArchiveSelected = false;
        if (manualEntireArchive) manualEntireArchive.checked = false;
        // Only offered once an archive is chosen — nothing to select before that.
        if (treeSelectAll) {
            treeSelectAll.style.display = this.value ? '' : 'none';
            const lbl = document.getElementById('tree-select-all-label');
            if (lbl) lbl.textContent = 'Select all';
        }
        updateSelectionUI();

        if (clickhouseAvailable) {
            if (searchMode !== 'all') {
                searchInput.disabled = !this.value;
                searchBtn.disabled = !this.value;
            }

            showBrowse();

            if (this.value) {
                treeRoot.innerHTML = '';
                loadTreeNode(treeRoot, '/');
            } else {
                treeRoot.innerHTML = '<div class="p-3 text-muted text-center">Select an archive to browse files</div>';
            }
        } else {
            // Manual path mode
            if (this.value) {
                if (manualPathPlaceholder) manualPathPlaceholder.style.display = 'none';
                if (manualPathForm) manualPathForm.style.display = '';
                if (manualPathInfo) manualPathInfo.style.display = '';
            } else {
                if (manualPathPlaceholder) manualPathPlaceholder.style.display = '';
                if (manualPathForm) manualPathForm.style.display = 'none';
                if (manualPathInfo) manualPathInfo.style.display = 'none';
            }
        }
    });

    // Search
    function doSearchCurrent(page) {
        const archiveId = archiveSelect.value;
        const q = searchInput.value.trim();
        if (!archiveId || !q) return;

        searchPage = page || 1;
        showSearchCurrent();

        const body = document.getElementById('search-results-body');
        body.innerHTML = '<div class="p-3 text-center"><div class="spinner-border spinner-border-sm"></div></div>';

        fetch('/clients/' + agentId + '/catalog/' + archiveId + '?page=' + searchPage + '&search=' + encodeURIComponent(q), { credentials: 'same-origin' })
            .then(r => r.json())
            .then(data => {
                document.getElementById('search-count').textContent = data.total.toLocaleString();
                document.getElementById('search-page-info').textContent = 'Page ' + data.page + ' / ' + data.pages;
                document.getElementById('search-prev').disabled = data.page <= 1;
                document.getElementById('search-next').disabled = data.page >= data.pages;

                body.innerHTML = '';
                if (data.files.length === 0) {
                    body.innerHTML = '<div class="p-3 text-muted text-center">No files found</div>';
                    return;
                }
                data.files.forEach(f => {
                    const row = document.createElement('div');
                    row.className = 'tree-item tree-file';
                    const isChecked = isPathSelected(f.file_path);
                    row.innerHTML =
                        '<input type="checkbox" class="tree-cb search-cb" data-path="' + esc(f.file_path) + '"' + (isChecked ? ' checked' : '') + '>' +
                        '<span class="tree-icon"><i class="bi bi-file-earmark"></i></span>' +
                        '<span class="small font-monospace">' + esc(f.file_path) + '</span>' +
                        ' ' + statusBadge(f.status) +
                        '<span class="tree-size">' + formatSize(f.file_size) + '</span>';
                    body.appendChild(row);
                });
            });
    }

    function doSearchAll(page) {
        const q = searchInput.value.trim();
        if (!q) return;

        searchPage = page || 1;
        showHistory();

        const body = document.getElementById('history-results-body');
        body.innerHTML = '<div class="p-3 text-center"><div class="spinner-border spinner-border-sm"></div></div>';

        fetch('/clients/' + agentId + '/catalog/search-all?page=' + searchPage + '&q=' + encodeURIComponent(q), { credentials: 'same-origin' })
            .then(r => r.json())
            .then(data => {
                document.getElementById('history-count').textContent = data.total.toLocaleString();
                document.getElementById('history-page-info').textContent = 'Page ' + data.page + ' / ' + data.pages;
                document.getElementById('history-prev').disabled = data.page <= 1;
                document.getElementById('history-next').disabled = data.page >= data.pages;

                body.innerHTML = '';
                if (data.files.length === 0) {
                    body.innerHTML = '<div class="p-3 text-muted text-center">No files found across any archive</div>';
                    return;
                }

                data.files.forEach(file => {
                    const group = document.createElement('div');
                    group.className = 'version-group';

                    const header = document.createElement('div');
                    header.className = 'version-group-header font-monospace';
                    header.innerHTML = '<i class="bi bi-file-earmark me-1"></i>' + esc(file.path);
                    group.appendChild(header);

                    file.versions.forEach(v => {
                        const row = document.createElement('div');
                        row.className = 'version-row';
                        const isSelected = selectedVersions.get(file.path) == v.archive_id;
                        row.innerHTML =
                            '<input type="radio" name="ver_' + esc(file.path) + '" class="form-check-input version-radio" ' +
                            'data-path="' + esc(file.path) + '" data-archive="' + v.archive_id + '"' + (isSelected ? ' checked' : '') + '>' +
                            '<span class="text-muted">' + formatDate(v.archive_date) + '</span>' +
                            '<span class="text-muted small">' + esc(v.repo_name) + '</span>' +
                            ' ' + statusBadge(v.status) +
                            '<span class="tree-size">' + formatSize(v.file_size) + '</span>';
                        group.appendChild(row);
                    });

                    body.appendChild(group);
                });
            });
    }

    function performSearch(page) {
        if (searchMode === 'all') {
            doSearchAll(page);
        } else {
            doSearchCurrent(page);
        }
    }

    if (searchBtn) searchBtn.addEventListener('click', () => performSearch(1));
    if (searchInput) searchInput.addEventListener('keypress', e => { if (e.key === 'Enter') { e.preventDefault(); performSearch(1); } });
    if (backBtn) backBtn.addEventListener('click', showBrowse);

    // Delegate: search result checkboxes
    const searchResultsBody = document.getElementById('search-results-body');
    if (searchResultsBody) {
        searchResultsBody.addEventListener('change', function(e) {
            if (e.target.classList.contains('search-cb')) {
                togglePath(e.target.dataset.path, e.target.checked);
            }
        });
    }

    // Delegate: search pagination
    const searchPrev = document.getElementById('search-prev');
    const searchNext = document.getElementById('search-next');
    if (searchPrev) searchPrev.addEventListener('click', () => doSearchCurrent(searchPage - 1));
    if (searchNext) searchNext.addEventListener('click', () => doSearchCurrent(searchPage + 1));

    // Delegate: history result radios
    const historyResultsBody = document.getElementById('history-results-body');
    if (historyResultsBody) {
        historyResultsBody.addEventListener('change', function(e) {
            if (e.target.classList.contains('version-radio')) {
                toggleVersion(e.target.dataset.path, parseInt(e.target.dataset.archive), e.target.checked);
            }
        });
    }

    // Delegate: history pagination
    const historyPrev = document.getElementById('history-prev');
    const historyNext = document.getElementById('history-next');
    if (historyPrev) historyPrev.addEventListener('click', () => doSearchAll(searchPage - 1));
    if (historyNext) historyNext.addEventListener('click', () => doSearchAll(searchPage + 1));

    // Manual path mode handlers (when ClickHouse unavailable)
    if (manualPathAddBtn) {
        manualPathAddBtn.addEventListener('click', function() {
            const raw = manualPathInput.value.trim();
            if (!raw) return;
            // Strip leading slash — borg paths don't use leading slashes
            const path = raw.replace(/^\/+/, '');
            if (path) {
                selectedPaths.add(path);
                updateSelectionUI();
            }
            manualPathInput.value = '';
            manualPathInput.focus();
        });
    }
    if (manualPathInput) {
        manualPathInput.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                manualPathAddBtn.click();
            }
        });
    }
    if (manualEntireArchive) {
        manualEntireArchive.addEventListener('change', function() {
            entireArchiveSelected = this.checked;
            updateSelectionUI();
        });
    }

    // Select all / Clear all over the entries at the top level of the tree.
    // Each one is added as its own path, so the list on the right stays
    // editable — take everything, then drop the two directories you don't
    // want, rather than ticking twenty by hand.
    const treeSelectAllLabel = document.getElementById('tree-select-all-label');
    if (treeSelectAll) {
        treeSelectAll.addEventListener('click', function (e) {
            e.preventDefault();
            const boxes = Array.from(treeRoot.querySelectorAll(':scope > .tree-item > input.tree-cb'));
            if (boxes.length === 0) return;

            const selecting = boxes.some(cb => !cb.checked);
            boxes.forEach(function (cb) {
                cb.checked = selecting;
                togglePath(cb.dataset.path, selecting);
            });
            if (treeSelectAllLabel) {
                treeSelectAllLabel.textContent = selecting ? 'Clear all' : 'Select all';
            }
        });
    }

    // Form submission helpers
    function fillFormAndSubmit(formId, archiveFieldId, filesContainerId, destFieldId) {
        const container = document.getElementById(filesContainerId);
        container.innerHTML = '';

        if (currentMode === 'search-all' && selectedVersions.size > 0) {
            // Cross-archive: group by archive_id and submit multiple forms
            const byArchive = new Map();
            selectedVersions.forEach((aid, path) => {
                if (!byArchive.has(aid)) byArchive.set(aid, []);
                byArchive.get(aid).push(path);
            });

            let first = true;
            byArchive.forEach((paths, aid) => {
                if (first) {
                    document.getElementById(archiveFieldId).value = aid;
                    if (destFieldId) {
                        document.getElementById(destFieldId).value = document.getElementById('restore-destination').value;
                    }
                    paths.forEach(p => {
                        const input = document.createElement('input');
                        input.type = 'hidden'; input.name = 'files[]'; input.value = p;
                        container.appendChild(input);
                    });
                    document.getElementById(formId).submit();
                    first = false;
                } else {
                    // Additional archives: create and submit temp forms
                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.action = document.getElementById(formId).action;
                    form.style.display = 'none';
                    form.innerHTML = '<input type="hidden" name="csrf_token" value="' + document.querySelector('[name=csrf_token]').value + '">' +
                        '<input type="hidden" name="archive_id" value="' + aid + '">';
                    if (destFieldId) {
                        form.innerHTML += '<input type="hidden" name="destination" value="' + esc(document.getElementById('restore-destination').value) + '">';
                    }
                    paths.forEach(p => {
                        form.innerHTML += '<input type="hidden" name="files[]" value="' + esc(p) + '">';
                    });
                    document.body.appendChild(form);
                    form.submit();
                }
            });
        } else {
            // Single archive mode
            document.getElementById(archiveFieldId).value = archiveSelect.value;
            if (destFieldId) {
                document.getElementById(destFieldId).value = document.getElementById('restore-destination').value;
            }
            selectedPaths.forEach(path => {
                const input = document.createElement('input');
                input.type = 'hidden'; input.name = 'files[]'; input.value = path;
                container.appendChild(input);
            });
            document.getElementById(formId).submit();
        }
    }

    restoreBtn.addEventListener('click', function() {
        const count = (currentMode === 'search-all' && selectedVersions.size > 0) ? selectedVersions.size : selectedPaths.size;
        if (count === 0 && !entireArchiveSelected) return;

        // Check for multi-drive Windows restores without a custom destination
        const dest = document.getElementById('restore-destination').value.trim();
        if (!dest) {
            const paths = (currentMode === 'search-all' && selectedVersions.size > 0)
                ? Array.from(selectedVersions.keys())
                : Array.from(selectedPaths);
            const drives = new Set();
            paths.forEach(p => {
                const m = p.match(/^([A-Za-z])\//);
                if (m) drives.add(m[1].toUpperCase());
            });
            if (drives.size > 1) {
                alert('Selected files span multiple drives (' + Array.from(drives).sort().join(':, ') + ':). ' +
                    'In-place restore can only target one drive at a time.\n\n' +
                    'Either select files from a single drive, or enter a destination path.');
                return;
            }
        }

        const msg = entireArchiveSelected
            ? 'Restore the entire archive to the client?\n\nThis may overwrite existing files.'
            : 'Restore ' + count + ' path(s) to the client?\n\nThis may overwrite existing files.';
        confirmAction(msg, function() {
            fillFormAndSubmit('restore-form', 'restore-archive-id', 'restore-files-container', 'restore-dest-field');
        }, { danger: true });
    });

    downloadBtn.addEventListener('click', function() {
        const count = (currentMode === 'search-all' && selectedVersions.size > 0) ? selectedVersions.size : selectedPaths.size;
        if (count === 0 && !entireArchiveSelected) return;
        const msg = entireArchiveSelected
            ? 'Download the entire archive as a .tar.gz?'
            : 'Download ' + count + ' path(s) as a .tar.gz archive?';
        confirmAction(msg, function() {
            fillFormAndSubmit('download-form', 'download-archive-id', 'download-files-container', null);
        });
    });

    // Initialize
    if (treeRoot) treeRoot.innerHTML = '<div class="p-3 text-muted text-center">Select an archive to browse files</div>';

    // ================================================================
    // Database Restore Mode
    // ================================================================
    if (window.DB_PLUGIN_ENABLED) {
        const modeToggle = document.getElementById('restore-mode-toggle');
        const filesSection = document.getElementById('files-restore-section');
        const dbSection = document.getElementById('db-restore-section');
        const dbArchiveSelect = document.getElementById('db-archive-select');
        const dbTableBody = document.getElementById('db-table-body');
        const dbTable = document.getElementById('db-table');
        const dbNoData = document.getElementById('db-no-data');
        const dbLoading = document.getElementById('db-loading');
        const dbSelectedCount = document.getElementById('db-selected-count');
        const dbRestoreBtn = document.getElementById('db-restore-btn');
        const dbAllDbNote = document.getElementById('db-all-databases-note');
        const dbConfigId = document.getElementById('db-config-id');

        let dbRestoreMode = 'files';
        let dbPerDatabase = true;
        let dbGroups = [];      // per-config database groups from the listing (#382)
        let dbBackedUpAt = '';

        // Render one row per database into the table body.
        function renderDbRows(databases, mtimes, perDatabase) {
            dbTableBody.innerHTML = '';
            (databases || []).forEach(function(dbName) {
                const tr = document.createElement('tr');
                const escapedName = esc(dbName);
                tr.innerHTML =
                    '<td>' +
                        '<select class="form-select form-select-sm db-mode-select" name="dbmode_' + escapedName + '" data-db="' + escapedName + '">' +
                            '<option value="none">None</option>' +
                            '<option value="replace">Replace</option>' +
                            (perDatabase ? '<option value="rename">Copy</option>' : '') +
                        '</select>' +
                    '</td>' +
                    '<td>' +
                        '<span class="font-monospace">' + escapedName + '</span>' +
                        '<div class="db-copy-input mt-1" style="display:none;">' +
                            '<div class="input-group input-group-sm">' +
                                '<span class="input-group-text"><i class="bi bi-arrow-right"></i></span>' +
                                '<input type="text" class="form-control form-control-sm font-monospace" data-rename-for="' + escapedName + '" value="' + escapedName + '_copy" placeholder="New database name">' +
                            '</div>' +
                        '</div>' +
                    '</td>' +
                    '<td class="text-end text-muted small">' + esc((mtimes && mtimes[dbName]) || dbBackedUpAt) + '</td>';
                dbTableBody.appendChild(tr);
            });
        }

        // Show the databases belonging to the currently-selected connection's
        // group. Two configs of the same engine each restore independently.
        function refreshDbTableForConnection() {
            if (!dbGroups.length) { dbTable.style.display = 'none'; return; }
            var sel = parseConfigValue();
            var group = null;
            if (dbGroups.length === 1 && (dbGroups[0].config_id === null || dbGroups[0].config_id === undefined)) {
                group = dbGroups[0]; // legacy single flat group
            } else if (sel.id) {
                group = dbGroups.find(function(g) { return String(g.config_id) === String(sel.id); });
            }
            if (!group) {
                dbTable.style.display = 'none';
                dbAllDbNote.style.display = 'none';
                dbNoData.style.display = '';
                var names = dbGroups.map(function(g) { return g.config_name || ('config ' + g.config_id); }).join(', ');
                dbNoData.innerHTML = '<i class="bi bi-database d-block mb-2" style="font-size:2rem;opacity:0.3;"></i>This archive backed up databases from: ' + esc(names) + '.<br>Pick the matching connection above to restore its databases.';
                updateDbSelection();
                return;
            }
            dbNoData.style.display = 'none';
            dbPerDatabase = group.per_database !== false;
            dbAllDbNote.style.display = dbPerDatabase ? 'none' : '';
            renderDbRows(group.databases || [], group.mtimes || {}, dbPerDatabase);
            dbTable.style.display = (group.databases && group.databases.length) ? '' : 'none';
            updateDbSelection();
        }

        // Parse connection picker value: "mysql:123" or "pg:456"
        function parseConfigValue() {
            if (!dbConfigId) return { type: 'mysql', id: '' };
            const val = dbConfigId.value || '';
            const parts = val.split(':');
            return { type: parts[0] || 'mysql', id: parts[1] || '' };
        }

        // Update grant text and form action when connection changes
        function updateConnectionInfo() {
            const { type, id } = parseConfigValue();
            const grantSpan = document.getElementById('db-restore-grant-user');
            const grantCode = document.getElementById('db-restore-grant-code');
            const form = document.getElementById('db-restore-form');
            const rawUser = window.DB_CONFIG_USERS && window.DB_CONFIG_USERS[dbConfigId.value];
            const user = (rawUser !== undefined && rawUser !== null && rawUser !== '') ? rawUser : (type === 'mongo' ? '' : 'backup_user');

            if (grantSpan) grantSpan.textContent = user;

            if (grantCode) {
                if (type === 'pg') {
                    grantCode.textContent = 'ALTER ROLE ' + user + ' CREATEDB; GRANT ALL PRIVILEGES ON DATABASE mydb TO ' + user + ';';
                } else if (type === 'mongo') {
                    if (user) {
                        grantCode.textContent = "use admin; db.createUser({user: '" + user + "', pwd: '<password>', roles: [{role: 'root', db: 'admin'}]});";
                    } else {
                        grantCode.textContent = 'MongoDB authentication is not configured — no grant required.';
                    }
                } else {
                    grantCode.innerHTML = "GRANT SELECT, LOCK TABLES, SHOW VIEW, EVENT, TRIGGER, CREATE, INSERT, DROP, ALTER, INDEX, REFERENCES ON *.* TO '" + '<span id="db-restore-grant-user">' + user + '</span>' + "'@'localhost'; FLUSH PRIVILEGES;";
                }
            }

            if (form) {
                if (type === 'pg') {
                    form.action = form.dataset.pgAction || form.action;
                } else if (type === 'mongo') {
                    form.action = form.dataset.mongoAction || form.action;
                } else {
                    form.action = form.dataset.mysqlAction || form.action;
                }
            }
            // Re-show the databases for the newly-selected connection (#382)
            if (dbArchiveSelect && dbArchiveSelect.value && dbGroups.length) {
                refreshDbTableForConnection();
            }
        }
        if (dbConfigId) {
            dbConfigId.addEventListener('change', updateConnectionInfo);
            updateConnectionInfo();
        }
        // Toggle the copy-name input and refresh counts when a mode changes.
        // Attached once (delegation) so re-rendering rows keeps working.
        dbTableBody.addEventListener('change', function(e) {
            if (e.target.classList.contains('db-mode-select')) {
                const row = e.target.closest('tr');
                const copyDiv = row.querySelector('.db-copy-input');
                if (copyDiv) copyDiv.style.display = e.target.value === 'rename' ? '' : 'none';
                updateDbSelection();
            }
        });

        // Mode toggle
        if (modeToggle) {
            modeToggle.addEventListener('click', function(e) {
                const btn = e.target.closest('[data-restore-mode]');
                if (!btn) return;
                dbRestoreMode = btn.dataset.restoreMode;
                modeToggle.querySelectorAll('.btn').forEach(b => {
                    b.classList.remove('btn-primary', 'active');
                    b.classList.add('btn-outline-primary');
                });
                btn.classList.remove('btn-outline-primary');
                btn.classList.add('btn-primary', 'active');

                var filesControls = document.querySelectorAll('.restore-files-controls');
                var dbControls = document.querySelectorAll('.restore-db-controls');
                if (dbRestoreMode === 'database') {
                    filesSection.style.display = 'none';
                    dbSection.style.display = '';
                    filesControls.forEach(function(el) { el.style.display = 'none'; });
                    dbControls.forEach(function(el) { el.style.display = ''; });
                } else {
                    filesSection.style.display = '';
                    dbSection.style.display = 'none';
                    filesControls.forEach(function(el) { el.style.display = ''; });
                    dbControls.forEach(function(el) { el.style.display = 'none'; });
                }
            });
        }

        // Archive selection for DB mode
        if (dbArchiveSelect) {
            dbArchiveSelect.addEventListener('change', function() {
                const archiveId = this.value;
                dbTableBody.innerHTML = '';
                dbTable.style.display = 'none';
                dbAllDbNote.style.display = 'none';
                updateDbSelection();

                if (!archiveId) {
                    dbNoData.style.display = '';
                    dbLoading.style.display = 'none';
                    return;
                }

                dbNoData.style.display = 'none';
                dbLoading.style.display = '';

                fetch('/clients/' + agentId + '/archive/' + archiveId + '/databases', { credentials: 'same-origin' })
                    .then(r => r.json())
                    .then(data => {
                        dbLoading.style.display = 'none';
                        dbGroups = data.groups || [];
                        dbBackedUpAt = data.backed_up_at || '';

                        if (!dbGroups.length && (!data.databases || data.databases.length === 0)) {
                            dbNoData.style.display = '';
                            dbNoData.innerHTML = '<i class="bi bi-database d-block mb-2" style="font-size:2rem;opacity:0.3;"></i>No database backup info for this archive';
                            return;
                        }
                        // Older server without grouped output: wrap the flat list.
                        if (!dbGroups.length) {
                            dbGroups = [{ config_id: null, databases: data.databases, per_database: data.per_database, compress: data.compress, mtimes: data.mtimes || {} }];
                        }
                        refreshDbTableForConnection();
                    })
                    .catch(function() {
                        dbLoading.style.display = 'none';
                        dbNoData.style.display = '';
                        dbNoData.innerHTML = '<i class="bi bi-exclamation-triangle d-block mb-2" style="font-size:2rem;opacity:0.3;"></i>Failed to load database info';
                    });
            });
        }

        function getSelectedDbs() {
            var selected = [];
            dbTableBody.querySelectorAll('.db-mode-select').forEach(function(sel) {
                if (sel.value !== 'none') selected.push(sel);
            });
            return selected;
        }

        function updateDbSelection() {
            var selected = getSelectedDbs();
            dbSelectedCount.textContent = selected.length;
            dbRestoreBtn.disabled = selected.length === 0 || !window.DB_CONFIG_AVAILABLE;
        }

        // Submit DB restore
        dbRestoreBtn.addEventListener('click', function() {
            const selected = getSelectedDbs();
            if (selected.length === 0) return;

            const lines = [];
            selected.forEach(function(sel) {
                const dbName = sel.dataset.db;
                const mode = sel.value;
                if (mode === 'rename') {
                    const renameField = dbTableBody.querySelector('input[data-rename-for="' + CSS.escape(dbName) + '"]');
                    const target = renameField ? renameField.value.trim() : dbName + '_copy';
                    lines.push('Copy ' + dbName + ' \u2192 ' + target);
                } else {
                    lines.push('Replace ' + dbName);
                }
            });
            confirmAction('Perform the following on the client?\n\n' + lines.join('\n') + '\n\nThis may overwrite existing data.', function() {
                const form = document.getElementById('db-restore-form');
            const fieldsContainer = document.getElementById('db-restore-fields');
            fieldsContainer.innerHTML = '';
            document.getElementById('db-restore-archive-id').value = dbArchiveSelect.value;
            const configIdField = document.getElementById('db-restore-config-id');
            if (configIdField && dbConfigId) {
                const { id } = parseConfigValue();
                configIdField.value = id;
            }
            // Update form action based on selected connection type
            updateConnectionInfo();

            selected.forEach(function(sel, i) {
                const dbName = sel.dataset.db;
                const mode = sel.value;

                const nameInput = document.createElement('input');
                nameInput.type = 'hidden';
                nameInput.name = 'databases[' + i + '][name]';
                nameInput.value = dbName;
                fieldsContainer.appendChild(nameInput);

                const modeInput = document.createElement('input');
                modeInput.type = 'hidden';
                modeInput.name = 'databases[' + i + '][mode]';
                modeInput.value = mode;
                fieldsContainer.appendChild(modeInput);

                if (mode === 'rename') {
                    const renameField = dbTableBody.querySelector('input[data-rename-for="' + CSS.escape(dbName) + '"]');
                    const targetInput = document.createElement('input');
                    targetInput.type = 'hidden';
                    targetInput.name = 'databases[' + i + '][target_name]';
                    targetInput.value = renameField ? renameField.value.trim() : dbName + '_copy';
                    fieldsContainer.appendChild(targetInput);
                }
            });

                form.submit();
            }, { danger: true });
        });
    }

    // Handle deep-link params from other pages (?archive=N&mode=files|database)
    if (preselectArchive) {
        // Switch to database mode first if requested
        if (preselectMode === 'database' && window.DB_PLUGIN_ENABLED) {
            const dbBtn = document.querySelector('[data-restore-mode="database"]');
            if (dbBtn) dbBtn.click();
            const dbSelect = document.getElementById('db-archive-select');
            if (dbSelect && [...dbSelect.options].some(o => o.value === preselectArchive)) {
                dbSelect.value = preselectArchive;
                dbSelect.dispatchEvent(new Event('change', { bubbles: true }));
            }
        } else if ([...archiveSelect.options].some(o => o.value === preselectArchive)) {
            archiveSelect.value = preselectArchive;
            archiveSelect.dispatchEvent(new Event('change', { bubbles: true }));
        }
    }
})();
