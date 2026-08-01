import assert from 'node:assert/strict';
import test from 'node:test';

import { createRailTimeNavigationCoordinator } from '../../resources/js/navigation-coordinator.js';

function deferred() {
    let resolve;
    let reject;
    const promise = new Promise((resolvePromise, rejectPromise) => {
        resolve = resolvePromise;
        reject = rejectPromise;
    });

    return { promise, reject, resolve };
}

function environment(initialUrl = 'https://railtime.test/dashboard') {
    const documentListeners = new Map();
    const windowListeners = new Map();
    const assigned = [];
    let reloads = 0;
    const location = {
        href: initialUrl,
        origin: new URL(initialUrl).origin,
        assign: (url) => assigned.push(String(url)),
        reload: () => { reloads += 1; },
    };
    const documentObject = {
        addEventListener(name, listener) {
            if (!documentListeners.has(name)) documentListeners.set(name, []);
            documentListeners.get(name).push(listener);
        },
    };
    const windowObject = {
        location,
        history: { state: {} },
        Livewire: null,
        addEventListener(name, listener) {
            if (!windowListeners.has(name)) windowListeners.set(name, []);
            windowListeners.get(name).push(listener);
        },
        dispatchEvent() {},
        setTimeout,
        clearTimeout,
    };
    const dispatchDocument = (name, event) => {
        for (const listener of documentListeners.get(name) ?? []) listener(event);
    };

    return {
        assigned,
        dispatchDocument,
        documentObject,
        location,
        reloads: () => reloads,
        windowObject,
    };
}

function navigateEvent(url, detail = {}) {
    let prevented = 0;

    return {
        event: {
            defaultPrevented: false,
            detail: { url, ...detail },
            preventDefault() {
                prevented += 1;
                this.defaultPrevented = true;
            },
        },
        prevented: () => prevented,
    };
}

test('an active flush blocks every competing navigation and releases one scoped resume', async () => {
    const env = environment();
    const coordinator = createRailTimeNavigationCoordinator(env.windowObject, env.documentObject);
    const cleanup = deferred();
    const visits = [];
    let dirty = true;
    let flushes = 0;
    let recursivePrevented = 0;
    coordinator.register({
        hasPendingWork: () => dirty,
        flush: () => {
            flushes += 1;

            return cleanup.promise.then(() => { dirty = false; });
        },
    });
    env.windowObject.Livewire = {
        navigate: (url) => {
            visits.push(String(url));
            const resumed = navigateEvent(url);
            env.dispatchDocument('livewire:navigate', resumed.event);
            recursivePrevented += resumed.prevented();
        },
    };

    const first = navigateEvent('/wagon-lists');
    const second = navigateEvent('/employees');
    env.dispatchDocument('livewire:navigate', first.event);
    env.dispatchDocument('livewire:navigate', second.event);

    assert.equal(first.prevented(), 1);
    assert.equal(second.prevented(), 1);
    assert.equal(coordinator.navigationPromise instanceof Promise, true);
    assert.deepEqual(visits, []);

    cleanup.resolve();
    await coordinator.navigationPromise;

    assert.equal(flushes, 1);
    assert.deepEqual(visits, ['https://railtime.test/wagon-lists']);
    assert.equal(recursivePrevented, 0);
    assert.equal(coordinator.resumeTicket, null);
});

test('autosave and assistant attachment participants flush together before one link visit', async () => {
    const env = environment();
    const coordinator = createRailTimeNavigationCoordinator(env.windowObject, env.documentObject);
    const autosave = deferred();
    const attachments = deferred();
    const visits = [];
    let autosaveDirty = true;
    let attachmentsDirty = true;
    coordinator.register({
        hasPendingWork: () => autosaveDirty,
        flush: () => autosave.promise.then(() => { autosaveDirty = false; }),
    });
    coordinator.register({
        hasPendingWork: () => attachmentsDirty,
        flush: () => attachments.promise.then(() => { attachmentsDirty = false; }),
    });
    env.windowObject.Livewire = {
        navigate: (url) => {
            visits.push(String(url));
            env.dispatchDocument('livewire:navigate', navigateEvent(url).event);
        },
    };
    const link = {
        href: 'https://railtime.test/employees/42',
        target: '',
        closest: () => link,
        hasAttribute: (name) => name === 'wire:navigate',
    };
    let clickPrevented = 0;
    const click = {
        target: link,
        defaultPrevented: false,
        preventDefault() { clickPrevented += 1; this.defaultPrevented = true; },
    };

    env.dispatchDocument('click', click);
    env.dispatchDocument('livewire:navigate', navigateEvent(link.href).event);
    autosave.resolve();
    await Promise.resolve();
    assert.deepEqual(visits, []);

    attachments.resolve();
    await coordinator.navigationPromise;

    assert.equal(clickPrevented, 1);
    assert.deepEqual(visits, ['https://railtime.test/employees/42']);
});

test('history navigation reloads once after success and remains fail-closed after an error', async () => {
    const successEnv = environment();
    const successCoordinator = createRailTimeNavigationCoordinator(
        successEnv.windowObject,
        successEnv.documentObject,
    );
    let dirty = true;
    successCoordinator.register({
        hasPendingWork: () => dirty,
        flush: async () => { dirty = false; },
    });
    successEnv.location.href = 'https://railtime.test/employees';
    const historyVisit = navigateEvent(new URL(successEnv.location.href), { history: true, cached: true });
    successEnv.dispatchDocument('livewire:navigate', historyVisit.event);
    await successCoordinator.navigationPromise;

    assert.equal(historyVisit.prevented(), 1);
    assert.equal(successEnv.reloads(), 1);
    assert.deepEqual(successEnv.assigned, []);

    const failureEnv = environment();
    const failureCoordinator = createRailTimeNavigationCoordinator(
        failureEnv.windowObject,
        failureEnv.documentObject,
    );
    let reported = 0;
    let unrelatedReports = 0;
    failureCoordinator.register({
        hasPendingWork: () => true,
        flush: () => Promise.reject(new Error('save failed')),
        onFlushError: () => { reported += 1; },
    });
    failureCoordinator.register({
        hasPendingWork: () => true,
        flush: () => Promise.resolve(true),
        onFlushError: () => { unrelatedReports += 1; },
    });
    failureEnv.location.href = 'https://railtime.test/employees';
    const failedHistory = navigateEvent(new URL(failureEnv.location.href), { history: true, cached: false });
    failureEnv.dispatchDocument('livewire:navigate', failedHistory.event);
    await new Promise((resolve) => setTimeout(resolve, 0));

    assert.equal(failedHistory.prevented(), 1);
    assert.equal(failureEnv.reloads(), 0);
    assert.deepEqual(failureEnv.assigned, []);
    assert.equal(reported, 1);
    assert.equal(unrelatedReports, 0);
    assert.equal(failureEnv.location.href, 'https://railtime.test/employees');
});

test('without pending work the coordinator leaves the original navigation untouched', () => {
    const env = environment();
    createRailTimeNavigationCoordinator(env.windowObject, env.documentObject);
    const navigation = navigateEvent('/employees');

    env.dispatchDocument('livewire:navigate', navigation.event);

    assert.equal(navigation.prevented(), 0);
});
