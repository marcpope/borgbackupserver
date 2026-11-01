/*
 * "Detect databases" picker for plugin configurations (SQL Server).
 *
 * Markup (rendered by clients/detail.php next to a field with 'picker' => true):
 *
 *   <div class="db-picker-wrap" data-agent-id data-config-id data-csrf>
 *     <input type="text" ...>                      the field that is saved
 *     <button class="db-picker-detect">            asks the client for its databases
 *     <div class="db-picker-status">
 *     <div class="db-picker-panel" hidden>
 *       <input type="checkbox" class="db-picker-all">   "All databases (*)"
 *       <div class="db-picker-list">                    one checkbox per database
 *
 * The saved connection settings are used: the button queues the same
 * plugin test job as the lightning button, and the agent returns the list of
 * databases it found next to the usual message. The checkboxes only write
 * into the text field (* or a comma-separated list), so the form saves
 * exactly what it always did and the field stays editable by hand.
 */
(function () {
    'use strict';

    var SYSTEM_DBS = ['master', 'model', 'msdb'];

    // For text placed between tags (status messages). Quotes are escaped too
    // so the result is also safe inside an attribute, should it ever be used there.
    function esc(s) {
        return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    function parseList(value) {
        var v = String(value || '').trim();
        if (v === '*') return { all: true, names: [] };
        return { all: false, names: v.split(',').map(function (x) { return x.trim(); }).filter(Boolean) };
    }

    function Picker(wrap) {
        this.wrap = wrap;
        this.input = wrap.querySelector('input[type="text"]');
        this.btn = wrap.querySelector('.db-picker-detect');
        this.status = wrap.querySelector('.db-picker-status');
        this.panel = wrap.querySelector('.db-picker-panel');
        this.all = wrap.querySelector('.db-picker-all');
        this.list = wrap.querySelector('.db-picker-list');
        this.databases = [];
        var self = this;
        this.btn.addEventListener('click', function () { self.detect(); });
        this.all.addEventListener('change', function () { self.onAllChanged(); });
        this.list.addEventListener('change', function () { self.onListChanged(); });
        this.input.addEventListener('input', function () { if (self.databases.length) self.render(); });
    }

    Picker.prototype.setStatus = function (html, kind) {
        this.status.innerHTML = html ? '<div class="small mt-1 text-' + (kind || 'muted') + '">' + html + '</div>' : '';
    };

    Picker.prototype.detect = function () {
        var self = this;
        var base = '/clients/' + self.wrap.dataset.agentId + '/plugin-configs/' + self.wrap.dataset.configId;
        self.btn.disabled = true;
        self.setStatus('<span class="spinner-border spinner-border-sm me-1"></span> Asking the client for its databases...');

        function fail(msg) {
            self.btn.disabled = false;
            self.setStatus('<i class="bi bi-x-circle me-1"></i>' + esc(msg), 'danger');
        }

        fetch(base + '/test', { method: 'POST', credentials: 'same-origin', headers: { 'X-CSRF-Token': self.wrap.dataset.csrf } })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data.job_id) return fail(data.error || 'Could not queue the request.');
                var started = Date.now();
                var timer = setInterval(function () {
                    fetch(base + '/test-status', { credentials: 'same-origin' })
                        .then(function (r) { return r.json(); })
                        .then(function (s) {
                            if (s.status === 'completed') {
                                clearInterval(timer);
                                self.btn.disabled = false;
                                if (!Array.isArray(s.databases)) return fail('The client did not return a database list.');
                                self.databases = s.databases;
                                self.setStatus('<i class="bi bi-check-circle me-1"></i>' + s.databases.length + ' database(s) found.', 'success');
                                self.render();
                            } else if (s.status === 'failed') {
                                clearInterval(timer);
                                fail(s.error || 'Detection failed.');
                            } else if (Date.now() - started > 90000) {
                                clearInterval(timer);
                                fail('Timed out. The client may be offline.');
                            }
                        })
                        .catch(function () { /* keep polling until the timeout */ });
                }, 2000);
            })
            .catch(function () { fail('Error contacting the server.'); });
    };

    // One checkbox row, built with the DOM API. Database names come from the
    // SQL Server and may contain any character (quotes included), so nothing
    // is ever concatenated into markup: value, id and text are set as
    // properties.
    function makeRow(opts) {
        var row = document.createElement('div');
        row.className = 'form-check';
        var box = document.createElement('input');
        box.className = 'form-check-input db-picker-item';
        box.type = 'checkbox';
        box.value = opts.name;
        if (opts.id) box.id = opts.id;
        if (opts.comma) box.dataset.comma = '1';
        box.checked = !!opts.checked;
        box.disabled = !!opts.disabled;
        row.appendChild(box);
        var label = document.createElement('label');
        label.className = 'form-check-label small font-monospace' + (opts.warn ? ' text-warning' : '');
        if (opts.id) label.htmlFor = opts.id;
        label.textContent = opts.name;
        row.appendChild(label);
        (opts.notes || []).forEach(function (n) {
            row.appendChild(document.createTextNode(' '));
            var span = document.createElement('span');
            span.className = n.className;
            span.textContent = n.text;
            row.appendChild(span);
        });
        return row;
    }

    // Draw the checkboxes from the databases found and the field's current value.
    Picker.prototype.render = function () {
        var cur = parseList(this.input.value);
        var chosen = {};
        cur.names.forEach(function (n) { chosen[n.toLowerCase()] = true; });
        this.all.checked = cur.all;
        var known = {};
        var rows = document.createDocumentFragment();
        var configId = this.wrap.dataset.configId;
        this.databases.forEach(function (name, i) {
            known[name.toLowerCase()] = true;
            var comma = name.indexOf(',') !== -1;
            var notes = [];
            if (SYSTEM_DBS.indexOf(name.toLowerCase()) !== -1) notes.push({ className: 'badge text-bg-secondary', text: 'system' });
            if (comma) notes.push({ className: 'text-muted small', text: '(name contains a comma: only selectable through *)' });
            rows.appendChild(makeRow({
                name: name,
                id: 'dbp_' + configId + '_' + i,
                comma: comma,
                checked: cur.all || (!comma && chosen[name.toLowerCase()]),
                disabled: cur.all || comma,
                notes: notes
            }));
        });
        // Names typed by hand that the server did not report stay visible
        cur.names.filter(function (n) { return !known[n.toLowerCase()]; }).forEach(function (n) {
            rows.appendChild(makeRow({
                name: n, checked: true, warn: true,
                notes: [{ className: 'text-warning small', text: 'not found on the server' }]
            }));
        });
        this.list.textContent = '';
        if (rows.childNodes.length) {
            this.list.appendChild(rows);
        } else {
            var empty = document.createElement('div');
            empty.className = 'text-muted small';
            empty.textContent = 'No databases found.';
            this.list.appendChild(empty);
        }
        this.panel.hidden = false;
    };

    Picker.prototype.onAllChanged = function () {
        var items = this.list.querySelectorAll('.db-picker-item');
        if (this.all.checked) {
            this.input.value = '*';
            items.forEach(function (c) { c.checked = true; c.disabled = true; });
        } else {
            // A name containing a comma cannot be written into a comma-separated
            // list, so it is only ever covered by "All".
            items.forEach(function (c) {
                if (c.dataset.comma) { c.checked = false; c.disabled = true; } else { c.disabled = false; }
            });
            this.writeSelection();
        }
    };

    Picker.prototype.onListChanged = function () {
        this.writeSelection();
    };

    Picker.prototype.writeSelection = function () {
        var names = [];
        this.list.querySelectorAll('.db-picker-item').forEach(function (c) { if (c.checked && !c.dataset.comma) names.push(c.value); });
        this.input.value = names.join(', ');
        if (!names.length) this.setStatus('Select at least one database, or tick "All databases".', 'warning');
        else if (this.status.textContent.indexOf('Select at least') === 0) this.setStatus('');
    };

    function init(root) {
        (root || document).querySelectorAll('.db-picker-wrap').forEach(function (wrap) {
            if (!wrap.__picker) wrap.__picker = new Picker(wrap);
        });
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', function () { init(); });
    else init();
    window.BBSDbPicker = { init: init, parseList: parseList };
})();
