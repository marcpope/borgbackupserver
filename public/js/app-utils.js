(function(window) {
    const BBS = window.BBS = window.BBS || {};
    const durationCache = new Map();

    BBS.formatDuration = function(seconds) {
        seconds = Math.max(0, parseInt(seconds, 10) || 0);
        if (seconds <= 0) return '--';
        if (durationCache.has(seconds)) return durationCache.get(seconds);

        const days = Math.floor(seconds / 86400);
        const hours = Math.floor((seconds % 86400) / 3600);
        const minutes = Math.floor((seconds % 3600) / 60);
        const secs = seconds % 60;

        let label;
        if (days > 0) {
            label = days + 'd ' + hours + 'h';
        } else if (hours > 0) {
            label = hours + 'h ' + minutes + 'm';
        } else if (minutes > 0) {
            label = minutes + 'm ' + secs + 's';
        } else {
            label = secs + 's';
        }

        durationCache.set(seconds, label);
        return label;
    };

    // Elapsed time with seconds, for a job that is still running (#533):
    // "45s", "23m 05s", "1h 23m 05s", "2d 3h 04m".
    BBS.formatElapsed = function(seconds) {
        seconds = Math.max(0, Math.floor(seconds) || 0);
        const pad = function(n) { return (n < 10 ? '0' : '') + n; };
        const d = Math.floor(seconds / 86400);
        const h = Math.floor((seconds % 86400) / 3600);
        const m = Math.floor((seconds % 3600) / 60);
        const s = seconds % 60;
        if (d > 0) return d + 'd ' + h + 'h ' + pad(m) + 'm';
        if (h > 0) return h + 'h ' + pad(m) + 'm ' + pad(s) + 's';
        if (m > 0) return m + 'm ' + pad(s) + 's';
        return s + 's';
    };

    // A job's start time (UTC "Y-m-d H:i:s") as unix seconds, or null.
    BBS.utcSeconds = function(value) {
        if (!value) return null;
        const t = Date.parse(String(value).replace(' ', 'T') + 'Z');
        return isNaN(t) ? null : Math.floor(t / 1000);
    };

    // Elements with data-running-since="<unix seconds>" show how long ago
    // that was, updated every second. Measured on the server's clock
    // (BBS_SERVER_NOW, set by the layout), so a browser whose clock is off
    // still shows the right time. Optional data-running-prefix/-suffix wrap
    // the value.
    const pageLoaded = Date.now() / 1000;
    BBS.serverNow = function() {
        const serverAtLoad = window.BBS_SERVER_NOW;
        return typeof serverAtLoad === 'number' ? serverAtLoad + (Date.now() / 1000 - pageLoaded) : Date.now() / 1000;
    };
    BBS.tickElapsed = function() {
        const now = BBS.serverNow();
        document.querySelectorAll('[data-running-since]').forEach(function(el) {
            const since = parseInt(el.getAttribute('data-running-since'), 10);
            if (!since) return;
            el.textContent = (el.getAttribute('data-running-prefix') || '') + BBS.formatElapsed(now - since) + (el.getAttribute('data-running-suffix') || '');
        });
    };
    setInterval(function() { BBS.tickElapsed(); }, 1000);

    // navigator.clipboard only exists in secure contexts (HTTPS/localhost);
    // plain-HTTP installs need the execCommand fallback (#333).
    BBS.copyText = function(text) {
        if (navigator.clipboard && window.isSecureContext) {
            return navigator.clipboard.writeText(text);
        }
        return new Promise(function(resolve, reject) {
            const ta = document.createElement('textarea');
            ta.value = text;
            ta.setAttribute('readonly', '');
            ta.style.position = 'fixed';
            ta.style.top = '-1000px';
            document.body.appendChild(ta);
            ta.select();
            try {
                document.execCommand('copy') ? resolve() : reject(new Error('Copy failed'));
            } catch (e) {
                reject(e);
            } finally {
                document.body.removeChild(ta);
            }
        });
    };
})(window);
