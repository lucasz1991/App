/* Leichtgewichtiger Toast-Handler fuer Livewire-Events (swal:toast / swal:alert)
   ohne externe Abhaengigkeit (Ersatz fuer SweetAlert2). */
(function () {
    'use strict';

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
            container.style.cssText = 'position:fixed;top:16px;right:16px;z-index:99999;display:flex;flex-direction:column;gap:8px;max-width:340px;';
            document.body.appendChild(container);
        }
        return container;
    }

    function showToast(detail) {
        detail = detail || {};
        var type = detail.type || 'info';
        var titles = { success: 'Erfolg!', warning: 'Warnung!', error: 'Fehler!', info: 'Hinweis!' };
        var title = detail.title || titles[type] || 'Hinweis!';
        var text = detail.text || '';

        var toast = document.createElement('div');
        toast.style.cssText = 'background:#fff;border:1px solid #e2e8f0;border-left:4px solid ' + (COLORS[type] || COLORS.info) + ';border-radius:8px;box-shadow:0 10px 24px rgba(15,23,42,.14);padding:10px 14px;font-family:inherit;font-size:14px;color:#0f172a;opacity:0;transform:translateY(-6px);transition:opacity .25s ease,transform .25s ease;';
        toast.innerHTML = '<strong style="display:block;font-size:13px;margin-bottom:2px;">' + title + '</strong>' + (text ? '<span style="color:#475569;">' + text + '</span>' : '');

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

    window.addEventListener('swal:toast', function (event) { showToast(event.detail); });
    window.addEventListener('swal:alert', function (event) { showToast(event.detail); });
})();
