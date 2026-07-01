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
