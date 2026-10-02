import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { parseHTML } from 'linkedom';

const script = readFileSync(new URL('../../resources/js/app.js', import.meta.url), 'utf8');
function source(name, next) {
    return script.slice(script.indexOf(`function ${name}(`), script.indexOf(`\n${next}`, script.indexOf(`function ${name}(`)));
}
function drawer(width = 1440) {
    const { document, window } = parseHTML('<html><body data-sidebar-drawer="true"><button id="vertical-menu-btn" class="vertical-menu-btn"></button><aside id="app-sidebar" class="vertical-menu"><a href="/">Dashboard</a></aside></body></html>');
    window.innerWidth = width;
    const functions = new Function('document', 'window', 'MOBILE_SIDEBAR_BREAKPOINT', [
        source('isDesktopHoverSidebar', 'function isSidebarHoveredOrFocused'),
        source('setMobileSidebarOpen', '/*'),
        source('syncSidebarToggleState', 'function scheduleDesktopSidebarCollapse'),
        'return { setMobileSidebarOpen, syncSidebarToggleState, isDesktopHoverSidebar };',
    ].join('\n'))(document, window, 1024);
    return { ...functions, document, window };
}

test('all viewport sizes start closed and inert, open by button state and close completely', () => {
    for (const width of [320, 390, 960, 1024, 1389, 1920]) {
        const state = drawer(width);
        const sidebar = state.document.getElementById('app-sidebar');
        const button = state.document.getElementById('vertical-menu-btn');
        state.syncSidebarToggleState();
        assert.equal(state.isDesktopHoverSidebar(), false);
        assert.equal(sidebar.inert, true);
        state.setMobileSidebarOpen(true);
        assert.equal(state.document.body.classList.contains('sidebar-enable'), true);
        assert.equal(sidebar.inert, false);
        assert.equal(sidebar.getAttribute('aria-hidden'), 'false');
        assert.equal(button.getAttribute('aria-expanded'), 'true');
        state.setMobileSidebarOpen(false);
        assert.equal(sidebar.inert, true);
        assert.equal(button.getAttribute('aria-expanded'), 'false');
    }
});

test('closing while focus is inside returns focus to the burger', () => {
    const state = drawer();
    let focused = false;
    state.document.getElementById('vertical-menu-btn').focus = () => { focused = true; };
    Object.defineProperty(state.document, 'activeElement', { value: state.document.querySelector('aside a') });
    state.setMobileSidebarOpen(false);
    assert.equal(focused, true);
});

test('chromeless pages cannot open a missing drawer', () => {
    const state = drawer();
    state.document.getElementById('app-sidebar').remove();
    state.setMobileSidebarOpen(true);
    assert.equal(state.document.body.classList.contains('sidebar-enable'), false);
});
