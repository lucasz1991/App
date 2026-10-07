import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { parseHTML } from 'linkedom';

const app = readFileSync(new URL('../../resources/js/app.js', import.meta.url), 'utf8');
const source = app.slice(app.indexOf('function initActiveMenu('), app.indexOf('function scrollSidebarBlockIntoView('));
assert.ok(source.startsWith('function initActiveMenu('));

function setup(view = 'inbox') {
    const { document } = parseHTML(`<html><body><section data-case-workspace="${view}"></section>
        <ul id="side-menu">
            <li><a href="/dashboard" data-rt-sidebar-link data-menu-active="false">Dashboard</a></li>
            <li class="mm-active"><a href="#" data-rt-sidebar-group data-menu-active="true" aria-expanded="true">Planung</a>
                <ul class="mm-show">
                    <li><a href="/arbeitsplatz/ansicht/cases?view=inbox" data-rt-sidebar-link data-current data-menu-active="true" class="bg-rt-accent-soft/70 text-rt-accent font-semibold">Eingang</a></li>
                    <li><a href="/arbeitsplatz/ansicht/cases?view=orders" data-rt-sidebar-link data-current data-menu-active="false">Aufträge</a></li>
                    <li><a href="/arbeitsplatz/ansicht/cases?view=shifts&amp;section=plan" data-rt-sidebar-link data-current data-menu-active="false">Schichtplan</a></li>
                    <li><a href="/arbeitsplatz/ansicht/cases?view=shifts&amp;section=calendar" data-rt-sidebar-link data-current data-menu-active="false">Kalender</a></li>
                </ul>
            </li>
            <li><a href="/employees" data-rt-sidebar-link data-menu-active="false">Mitarbeiter</a></li>
        </ul></body></html>`);
    const window = { location: { href: 'https://railtime.test/arbeitsplatz/ansicht/cases?view=inbox' } };
    const run = new Function('document', 'window', `${source}; return initActiveMenu;`)(document, window);
    return {
        document,
        window,
        run,
        active: () => Array.from(document.querySelectorAll('[data-rt-sidebar-link][aria-current="page"]')).map((item) => item.textContent),
    };
}

test('query-specific planning links stay exclusive across persisted sidebar navigation', () => {
    const state = setup();
    for (const [query, label] of [
        ['view=inbox&section=ai-intake', 'Eingang'],
        ['view=orders&order=42&search=train', 'Aufträge'],
        ['view=shifts&section=calendar&from=2026-10-01', 'Kalender'],
        ['view=shifts', 'Schichtplan'],
        ['view=shifts&section=plan#selected', 'Schichtplan'],
        ['view=inbox', 'Eingang'],
    ]) {
        state.window.location.href = `https://railtime.test/arbeitsplatz/ansicht/cases?${query}`;
        state.run();
        assert.deepEqual(state.active(), [label]);
        for (const link of state.document.querySelectorAll('[data-rt-sidebar-link]')) {
            assert.equal(link.dataset.menuActive, link.textContent === label ? 'true' : 'false');
            assert.equal(link.classList.contains('bg-rt-accent-soft/70'), link.textContent === label);
            assert.equal(link.classList.contains('font-semibold'), link.textContent === label);
        }
        const group = state.document.querySelector('[data-rt-sidebar-group]');
        assert.equal(group.getAttribute('aria-expanded'), 'true');
        assert.equal(group.dataset.menuActive, 'true');
        assert.equal(group.hasAttribute('aria-current'), false);
    }
});

test('default view follows the mounted authorized workspace, not stale server markers', () => {
    const state = setup('orders');
    state.window.location.href = 'https://railtime.test/arbeitsplatz/ansicht/cases';
    state.run();
    assert.deepEqual(state.active(), ['Aufträge']);
});

test('offers and invalid query values never select all links via path-only wire current', () => {
    const state = setup('offers');
    for (const query of ['', '?view=offers', '?view=shifts&section=unknown', '?view[]=inbox']) {
        state.window.location.href = `https://railtime.test/arbeitsplatz/ansicht/cases${query}`;
        state.run();
        assert.deepEqual(state.active(), []);
        assert.equal(state.document.querySelector('[data-rt-sidebar-group]').getAttribute('aria-expanded'), 'false');
    }
});

test('ordinary exact pages close planning and keep group triggers out of active matches', () => {
    const state = setup();
    state.window.location.href = 'https://railtime.test/dashboard?filter=new#status';
    state.run();
    assert.deepEqual(state.active(), ['Dashboard']);
    assert.equal(state.document.querySelector('[data-rt-sidebar-group]').dataset.menuActive, 'false');
    assert.equal(state.document.querySelector('ul.mm-show'), null);
});

test('existing Livewire child-route matching remains available when no exact path exists', () => {
    const state = setup();
    for (const link of state.document.querySelectorAll('[data-current]')) link.removeAttribute('data-current');
    state.document.querySelector('a[href="/employees"]').setAttribute('data-current', '');
    state.window.location.href = 'https://railtime.test/employees/42';
    state.run();
    assert.deepEqual(state.active(), ['Mitarbeiter']);
});

test('a server-active legacy route still works before its canonical redirect', () => {
    const state = setup();
    for (const link of state.document.querySelectorAll('[data-current]')) link.removeAttribute('data-current');
    state.window.location.href = 'https://railtime.test/arbeitsplatz/inquiries';
    state.run();
    assert.deepEqual(state.active(), ['Eingang']);
});
