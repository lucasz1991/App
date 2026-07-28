import assert from 'node:assert/strict';
import test from 'node:test';

import { wagonListPrototype } from '../../resources/js/wagon-list-prototype.js';

const createStorage = (initial = {}) => {
    const values = new Map(Object.entries(initial));

    return {
        getItem(key) {
            return values.has(key) ? values.get(key) : null;
        },
        setItem(key, value) {
            values.set(key, String(value));
        },
        removeItem(key) {
            values.delete(key);
        },
    };
};

const createClassList = () => {
    const values = new Set();

    return {
        add(value) {
            values.add(value);
        },
        remove(value) {
            values.delete(value);
        },
        contains(value) {
            return values.has(value);
        },
    };
};

const setupComponent = (stored = null) => {
    const storageKey = 'rt-wagon-list-prototype:v1:test';
    const localStorage = createStorage(stored ? {
        [storageKey]: JSON.stringify(stored),
    } : {});
    const htmlClassList = createClassList();
    const bodyClassList = createClassList();

    globalThis.localStorage = localStorage;
    globalThis.window = {
        clearTimeout: globalThis.clearTimeout,
        setTimeout: globalThis.setTimeout,
        dispatchEvent(event) {
            event.detail.action();
            return true;
        },
    };
    globalThis.document = {
        activeElement: null,
        documentElement: { classList: htmlClassList },
        body: { classList: bodyClassList },
    };

    const component = wagonListPrototype({
        storageKey,
        locale: 'de-DE',
    });
    component.$watch = () => {};
    component.$nextTick = (callback) => callback();
    component.$refs = {};
    component.$root = { querySelector: () => null };
    component.init();

    return {
        bodyClassList,
        component,
        htmlClassList,
        localStorage,
        storageKey,
    };
};

test('migrates the existing single v1 draft and starts on the overview', () => {
    const { component, localStorage, storageKey } = setupComponent({
        version: 1,
        meta: {
            trainNumber: '4711',
            origin: 'Berlin',
            destination: 'Hamburg',
        },
        wagons: [{ category: 'Habbiins' }],
        visibleCount: 5,
        persistedAt: '2026-07-24T10:00:00.000Z',
    });

    assert.equal(component.editorOpen, false);
    assert.equal(component.drafts.length, 1);
    assert.equal(component.drafts[0].meta.trainNumber, '4711');
    assert.equal(component.drafts[0].visibleCount, 5);
    assert.equal(component.draftWagonCount(component.drafts[0]), 1);

    const migrated = JSON.parse(localStorage.getItem(storageKey));
    assert.equal(migrated.version, 2);
    assert.equal(migrated.drafts.length, 1);
});

test('keeps several drafts, saves on close and restores a selected entry', () => {
    const {
        bodyClassList,
        component,
        htmlClassList,
        localStorage,
        storageKey,
    } = setupComponent();

    component.createDraft();
    const firstId = component.activeDraftId;
    component.meta.trainNumber = 'ICE 100';
    component.meta.origin = 'Köln';
    component.meta.destination = 'Berlin';
    component.wagons[0].category = 'Sgns';
    component.saveAndClose();

    assert.equal(component.editorOpen, false);
    assert.equal(htmlClassList.contains('rt-wagon-editor-is-open'), false);
    assert.equal(bodyClassList.contains('rt-wagon-editor-is-open'), false);

    component.createDraft();
    const secondId = component.activeDraftId;
    component.meta.trainNumber = 'IC 2048';
    component.saveAndClose();

    assert.notEqual(firstId, secondId);
    assert.equal(component.drafts.length, 2);

    component.openDraft(firstId);
    assert.equal(component.editorOpen, true);
    assert.equal(component.meta.trainNumber, 'ICE 100');
    assert.equal(component.wagons[0].category, 'Sgns');
    assert.equal(htmlClassList.contains('rt-wagon-editor-is-open'), true);

    const persisted = JSON.parse(localStorage.getItem(storageKey));
    assert.equal(persisted.version, 2);
    assert.equal(persisted.drafts.length, 2);
});

test('deletes only after confirmation and keeps the forty-wagon limit', async () => {
    const { component } = setupComponent();

    component.createDraft();
    const firstId = component.activeDraftId;
    component.saveAndClose();
    component.createDraft();
    component.saveAndClose();

    window.dispatchEvent = (event) => {
        event.detail.cancel();
        return true;
    };
    await component.deleteDraft(firstId);
    assert.equal(component.drafts.length, 2);

    window.dispatchEvent = (event) => {
        event.detail.action();
        return true;
    };
    await component.deleteDraft(firstId);
    assert.equal(component.drafts.length, 1);
    assert.equal(component.drafts.some((draft) => draft.id === firstId), false);

    component.openDraft(component.drafts[0].id);
    for (let index = component.visibleCount; index < 45; index += 1) {
        component.addWagon();
    }
    assert.equal(component.visibleCount, 40);

    component.saveAndClose();
    await component.deleteAllDrafts();
    assert.equal(component.drafts.length, 0);
});

test('does not open a new draft when browser storage fails', () => {
    const { component } = setupComponent();
    component.saveCollection = () => false;

    assert.equal(component.createDraft(), false);
    assert.equal(component.drafts.length, 0);
    assert.equal(component.editorOpen, false);
    assert.equal(component.activeDraftId, null);
});
