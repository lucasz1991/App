/* Leichtgewichtiger Toast-Handler fuer Livewire-Events (swal:toast / swal:alert)
   ohne externe Abhaengigkeit (Ersatz fuer SweetAlert2). */
(function () {
    'use strict';

    // Dieses Script kann bei wire:navigate erneut ausgewertet werden. Alte
    // globale Listener muessen deshalb vor jeder Registrierung entfernt
    // werden, sonst erzeugt ein einziges Livewire-Event mehrere Toasts.
    if (window.__rtToastAbortController) {
        window.__rtToastAbortController.abort();
    }

    var listenerController = new AbortController();
    window.__rtToastAbortController = listenerController;
    var recentlyShown = new Map();

    var COLORS = {
        success: '#16a34a',
        info: '#0284c7',
        warning: '#d97706',
        error: '#dc2626'
    };

    function ensureContainer() {
        var container = document.getElementById('rt-toast-container');
        if (!container) {
            container = document.createElement('div');
            container.id = 'rt-toast-container';
            container.setAttribute('aria-live', 'polite');
            container.setAttribute('aria-relevant', 'additions');
            container.style.cssText = 'position:fixed;top:16px;right:16px;z-index:99999;display:flex;flex-direction:column;gap:8px;width:340px;max-width:calc(100vw - 32px);';
            document.body.appendChild(container);
        }
        return container;
    }

    function normalizeDetail(detail) {
        if (Array.isArray(detail)) {
            if (typeof detail[0] === 'string') {
                return { text: detail[0], type: detail[1] || 'info' };
            }

            return detail[0] || {};
        }

        if (detail && typeof detail[0] === 'string') {
            return { text: detail[0], type: detail[1] || 'info' };
        }

        return detail || {};
    }

    function isDuplicate(detail) {
        var signature = JSON.stringify([
            detail.type || 'info',
            detail.title || '',
            detail.text || '',
            detail.redirectTo || ''
        ]);
        var now = Date.now();
        var lastShownAt = recentlyShown.get(signature) || 0;

        recentlyShown.set(signature, now);

        recentlyShown.forEach(function (shownAt, key) {
            if (now - shownAt > 2000) recentlyShown.delete(key);
        });

        return now - lastShownAt < 500;
    }

    function showToast(rawDetail) {
        var detail = normalizeDetail(rawDetail);
        if (isDuplicate(detail)) return;

        var type = detail.type || 'info';

        // Passender Ton zum Toast (rt-sounds.js). detail.sound erlaubt einen
        // abweichenden Ton (z.B. 'message' bei Chat-Toasts) oder false zum
        // Unterdruecken.
        if (detail.sound !== false && window.RTSound) {
            window.RTSound.play(typeof detail.sound === 'string' ? detail.sound : type);
        }
        var titles = { success: 'Erfolg', warning: 'Warnung', error: 'Fehler', info: 'Hinweis' };
        var title = detail.title || titles[type] || 'Hinweis';
        var text = detail.text || '';

        var toast = document.createElement('div');
        toast.className = 'rt-toast';
        toast.setAttribute('role', type === 'error' ? 'alert' : 'status');
        toast.setAttribute('aria-atomic', 'true');
        toast.style.borderLeftColor = COLORS[type] || COLORS.info;

        var titleElement = document.createElement('strong');
        titleElement.style.cssText = 'display:block;font-size:13px;margin-bottom:2px;';
        titleElement.textContent = title;
        toast.appendChild(titleElement);

        if (text) {
            var textElement = document.createElement('span');
            textElement.className = 'rt-toast__message';
            textElement.textContent = text;
            toast.appendChild(textElement);
        }

        ensureContainer().appendChild(toast);
        requestAnimationFrame(function () {
            toast.style.opacity = '1';
            toast.style.transform = 'translateY(0)';
        });

        var timer = detail.timer || 4000;
        setTimeout(function () {
            toast.style.opacity = '0';
            toast.style.transform = 'translateY(-6px)';
            setTimeout(function () { toast.remove(); }, 300);
        }, timer);

        if (detail.redirectTo) {
            setTimeout(function () { window.location.assign(detail.redirectTo); }, timer);
        }
    }

    var listenerOptions = { signal: listenerController.signal };
    window.addEventListener('swal:toast', function (event) { showToast(event.detail); }, listenerOptions);
    window.addEventListener('swal:alert', function (event) { showToast(event.detail); }, listenerOptions);
    window.addEventListener('showAlert', function (event) { showToast(event.detail); }, listenerOptions);
})();
