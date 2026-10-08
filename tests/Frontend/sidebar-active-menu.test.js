import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { parseHTML } from 'linkedom';

const app = readFileSync(new URL('../../resources/js/app.js', import.meta.url), 'utf8');
const source = app.slice(app.indexOf('function initActiveMenu('), app.indexOf('function scrollSidebarBlockIntoView('));
assert.ok(source.startsWith('function initActiveMenu('));
const personalTemplate = readFileSync(new URL('../../resources/views/livewire/operations/personal-page-workspace.blade.php', import.meta.url), 'utf8');
const pageTemplate = readFileSync(new URL('../../resources/views/livewire/operations/page-workspace.blade.php', import.meta.url), 'utf8');

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

function setupPersonal(page = 'people', view = 'employees', section = '') {
    const state = setup();
    state.document.querySelector('[data-case-workspace]').remove();
    const workspace = state.document.createElement('div');
    workspace.dataset.personalPage = page;
    workspace.dataset.personalView = view;
    workspace.dataset.personalSection = section;
    state.document.body.append(workspace);
    const group = state.document.createElement('li');
    group.innerHTML = `<a href="#" data-rt-sidebar-group data-menu-active="false">Personal</a><ul>
        <li><a href="/arbeitsplatz/ansicht/people?view=employees" data-rt-sidebar-link>Mitarbeiter</a></li>
        <li><a href="/arbeitsplatz/ansicht/people?view=qualifications" data-rt-sidebar-link>Nachweise</a></li>
        <li><a href="/arbeitsplatz/ansicht/people?view=documents" data-rt-sidebar-link>Unterlagen</a></li>
        <li><a href="/arbeitsplatz/ansicht/people?section=signatures" data-rt-sidebar-link>Unterzeichnungen</a></li>
        <li><a href="/arbeitsplatz/ansicht/time-review" data-rt-sidebar-link>Zeitprüfung</a></li>
        <li><a href="/arbeitsplatz/ansicht/time-review?section=rules" data-rt-sidebar-link>Regelprofile</a></li>
        <li><a href="/arbeitsplatz/ansicht/leave" data-rt-sidebar-link>Urlaub & Konten</a></li>
        <li><a href="/arbeitsplatz/ansicht/leave?section=models" data-rt-sidebar-link>Arbeitsmodelle</a></li>
    </ul>`;
    state.document.getElementById('side-menu').append(group);
    state.window.location.href = `https://railtime.test/arbeitsplatz/ansicht/${page}`;
    return { ...state, workspace };
}

test('Personal defaults follow the actual authorized child view and expose server-confirmed state', () => {
    assert.match(personalTemplate, /data-personal-page="\{\{ \$page \}\}" data-personal-view="\{\{ \$view \}\}" data-personal-section="\{\{ \$section \}\}"/);
    for (const [view, label] of [['employees', 'Mitarbeiter'], ['qualifications', 'Nachweise']]) {
        const state = setupPersonal('people', view);
        state.run();
        assert.deepEqual(state.active(), [label]);
    }
});

test('Personal explicit views stay exclusive across persisted sidebar changes', () => {
    const state = setupPersonal();
    for (const [view, label] of [['qualifications', 'Nachweise'], ['documents', 'Unterlagen'], ['employees', 'Mitarbeiter']]) {
        state.window.location.href = `https://railtime.test/arbeitsplatz/ansicht/people?view=${view}&user=42&search=ignored`;
        state.workspace.dataset.personalView = view;
        state.run();
        assert.deepEqual(state.active(), [label]);
    }
});

test('Personal section-only links override their generic page and ignore underlying view', () => {
    const state = setupPersonal('time-review', 'times', 'rules');
    for (const query of ['section=rules', 'view=times&section=rules', 'view=conflicts&section=rules&user=42']) {
        state.window.location.href = `https://railtime.test/arbeitsplatz/ansicht/time-review?${query}`;
        state.run();
        assert.deepEqual(state.active(), ['Regelprofile']);
    }
});

test('rules-only Personal default resolves its implicit section with no view or query', () => {
    const state = setupPersonal('time-review', '', 'rules');
    state.run();
    assert.deepEqual(state.active(), ['Regelprofile']);
    state.window.location.href += '?user=42';
    state.run();
    assert.deepEqual(state.active(), ['Regelprofile']);
});

test('Personal view links do not compete with selected sections; combined targets are most specific', () => {
    const state = setupPersonal('people', 'documents', 'signatures');
    state.window.location.href += '?view=documents&section=signatures';
    state.run();
    assert.deepEqual(state.active(), ['Unterzeichnungen']);
    const specific = state.document.createElement('a');
    specific.setAttribute('data-rt-sidebar-link', '');
    specific.setAttribute('href', '/arbeitsplatz/ansicht/people?view=documents&section=signatures');
    specific.textContent = 'Unterlagen unterzeichnen';
    state.document.getElementById('side-menu').append(specific);
    state.run();
    assert.deepEqual(state.active(), ['Unterlagen unterzeichnen']);
});

test('generic Personal page remains the fallback for views without direct menu entries', () => {
    const state = setupPersonal('leave', 'requests');
    for (const [query, section, expected] of [
        ['view=requests', '', 'Urlaub & Konten'],
        ['section=models', 'models', 'Arbeitsmodelle'],
        ['view=time-accounts', '', 'Urlaub & Konten'],
        ['view=leave-accounts&section=policies', 'policies', 'Urlaub & Konten'],
    ]) {
        state.window.location.href = `https://railtime.test/arbeitsplatz/ansicht/leave?${query}`;
        state.workspace.dataset.personalSection = section;
        state.run();
        assert.deepEqual(state.active(), [expected]);
    }
});

test('same-page workspace notification updates matching only after the child DOM update', () => {
    const state = setupPersonal();
    state.run();
    assert.deepEqual(state.active(), ['Mitarbeiter']);
    const listenerSource = "document.addEventListener('rt-workspace-url-updated', () => initActiveMenu());";
    assert.equal(app.split(listenerSource).length - 1, 1);
    new Function('document', 'initActiveMenu', listenerSource)(state.document, state.run);
    const { document: templateDocument } = parseHTML(pageTemplate);
    const expression = templateDocument.querySelector('[data-page-workspace-content]').getAttribute('x-on:rt-workspace-url.window');
    const handle = new Function('$event', 'location', 'history', '$nextTick', '$dispatch', expression);
    const pending = [];
    const preservedHistoryState = { retained: true };
    const history = {
        state: preservedHistoryState,
        replaceState: (value, title, href) => {
            assert.equal(value, preservedHistoryState);
            state.window.location.href = href;
        },
    };
    let notifications = 0;
    const dispatch = (name) => {
        notifications++;
        state.document.dispatchEvent(new state.document.defaultView.Event(name));
    };
    handle({ detail: { url: 'https://railtime.test/arbeitsplatz/ansicht/people?view=qualifications' } }, new URL(state.window.location.href), history, (callback) => pending.push(callback), dispatch);
    assert.equal(notifications, 0);
    assert.deepEqual(state.active(), ['Mitarbeiter']);
    state.workspace.dataset.personalView = 'qualifications';
    pending.shift()();
    assert.equal(notifications, 1);
    assert.deepEqual(state.active(), ['Nachweise']);
    for (const url of ['https://other.test/arbeitsplatz/ansicht/people?view=employees', 'https://railtime.test/arbeitsplatz/ansicht/cases?view=inbox']) {
        handle({ detail: { url } }, new URL(state.window.location.href), history, (callback) => pending.push(callback), dispatch);
        assert.equal(pending.length, 0);
        assert.equal(notifications, 1);
        assert.deepEqual(state.active(), ['Nachweise']);
    }
});
