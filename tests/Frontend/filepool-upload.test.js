import assert from 'node:assert/strict';
import test, { afterEach } from 'node:test';
import { parseHTML } from 'linkedom';

import {
    FILEPOOL_UPLOAD_LIMITS,
    filePoolDropzone,
    filePoolExternalDrop,
    filesFromTransfer,
    formatUploadFileSize,
    isExternalFileTransfer,
    uploadFilePresentation,
    uploadPreviewTemplate,
    validateUploadFiles,
} from '../../resources/js/filepool-upload.js';

const originalGlobals = {
    window: global.window,
    document: global.document,
    CustomEvent: global.CustomEvent,
    Element: global.Element,
};

afterEach(() => {
    Object.entries(originalGlobals).forEach(([name, value]) => {
        if (value === undefined) {
            delete global[name];
        } else {
            global[name] = value;
        }
    });
});

const makeFile = (name, size = 1024, lastModified = 1) => ({ name, size, lastModified });

const externalTransfer = (files = []) => ({
    types: ['Files'],
    files,
    items: [],
    dropEffect: 'none',
});

test('erkennt nur echte OS-Dateidrops und niemals interne FilePool-Moves', () => {
    assert.equal(isExternalFileTransfer({ types: ['Files'] }), true);
    assert.equal(isExternalFileTransfer({ types: ['text/plain'] }), false);
    assert.equal(isExternalFileTransfer({
        types: ['Files', 'application/x-railtime-file'],
    }), false);
});

test('filtert Ordner, ignoriert Nicht-Dateien und dedupliziert die Dateiliste', () => {
    const report = makeFile('report.pdf', 12_000, 12);
    const transfer = {
        items: [
            {
                kind: 'file',
                webkitGetAsEntry: () => ({ isDirectory: true, name: 'Archiv' }),
                getAsFile: () => null,
            },
            {
                kind: 'file',
                webkitGetAsEntry: () => ({ isDirectory: false }),
                getAsFile: () => report,
            },
            {
                kind: 'file',
                webkitGetAsEntry: () => ({ isDirectory: false }),
                getAsFile: () => ({ ...report }),
            },
            { kind: 'string' },
        ],
    };

    const result = filesFromTransfer(transfer);

    assert.deepEqual(result.directories, ['Archiv']);
    assert.equal(result.files.length, 1);
    assert.equal(result.files[0], report);
});

test('setzt 20 Dateien und 50 MB exakt als Clientgrenzen durch', () => {
    assert.equal(FILEPOOL_UPLOAD_LIMITS.maxFiles, 20);
    assert.equal(FILEPOOL_UPLOAD_LIMITS.maxFileSizeBytes, 50 * 1024 * 1024);

    const files = Array.from({ length: 21 }, (_, index) => (
        makeFile(`file-${index}.bin`, index === 0
            ? FILEPOOL_UPLOAD_LIMITS.maxFileSizeBytes
            : 1024, index)
    ));
    files.push(makeFile('too-large.bin', FILEPOOL_UPLOAD_LIMITS.maxFileSizeBytes + 1, 999));

    const { accepted, rejected } = validateUploadFiles(files);

    assert.equal(accepted.length, 20);
    assert.equal(rejected.some(({ reason }) => reason === 'too-many'), true);
    assert.equal(rejected.some(({ reason }) => reason === 'too-large'), true);
    assert.equal(accepted[0].size, FILEPOOL_UPLOAD_LIMITS.maxFileSizeBytes);
});

test('liefert Dateityp, lesbare Größe und zugängliches RailTime-Kartenmarkup', () => {
    assert.deepEqual(uploadFilePresentation({ name: 'einsatzfoto.JPG', type: 'image/jpeg' }), {
        extension: 'JPG',
        kind: 'image',
        icon: 'fa-file-image',
    });
    assert.equal(uploadFilePresentation({ name: 'dienstplan.xlsx' }).kind, 'spreadsheet');
    assert.equal(uploadFilePresentation({ name: 'ohne-endung' }).kind, 'generic');
    assert.equal(formatUploadFileSize(50 * 1024 * 1024), '50 MB');
    assert.equal(formatUploadFileSize(2.5 * 1024 * 1024, 'en-US'), '2.5 MB');

    const { document } = parseHTML(`<main>${uploadPreviewTemplate()}</main>`);
    const card = document.querySelector('.rt-filepool-upload-file');

    assert.ok(card?.querySelector('[data-dz-thumbnail]'));
    assert.ok(card?.querySelector('[data-upload-type-icon]'));
    assert.ok(card?.querySelector('[data-upload-status]'));
    assert.equal(card?.querySelector('[role="progressbar"]')?.getAttribute('aria-valuemax'), '100');
    assert.equal(card?.querySelector('[data-dz-remove]')?.tagName, 'BUTTON');
});

test('befüllt Dateikarten und aktualisiert Fortschritt sowie Status nachvollziehbar', () => {
    const { document } = parseHTML(`<main>${uploadPreviewTemplate()}</main>`);
    const previewElement = document.querySelector('.rt-filepool-upload-file');
    const file = {
        name: 'Wagenmeister Einsatz.pdf',
        size: 2.5 * 1024 * 1024,
        previewElement,
    };
    const controller = filePoolDropzone({
        labels: {
            preparing: 'Vorbereitung',
            uploading: 'Übertragung',
            ready: 'Speicherbereit',
            removeFile: 'Entfernen',
        },
    });

    controller.decorateFilePreview(file);
    assert.equal(previewElement.dataset.fileKind, 'pdf');
    assert.equal(previewElement.querySelector('[data-upload-file-size]').textContent, '2,5 MB');
    assert.equal(previewElement.querySelector('[data-upload-extension]').textContent, 'PDF');
    assert.match(previewElement.querySelector('[data-upload-remove-label]').textContent, /Wagenmeister Einsatz\.pdf/);

    controller.updateFileStatus(file, 'uploading', 'Übertragung');
    controller.updateFileProgress(file, 63.6);
    assert.equal(previewElement.dataset.uploadState, 'uploading');
    assert.equal(previewElement.getAttribute('aria-busy'), 'true');
    assert.equal(previewElement.querySelector('[data-upload-progress-value]').textContent, '64 %');
    assert.equal(previewElement.querySelector('[role="progressbar"]').getAttribute('aria-valuenow'), '64');

    controller.updateFileStatus(file, 'ready', 'Speicherbereit');
    assert.equal(previewElement.getAttribute('aria-busy'), 'false');
    assert.equal(previewElement.querySelector('[data-upload-status]').textContent, 'Speicherbereit');
});

test('weist bereits vorgemerkte Dateien als Duplikat zurück', () => {
    const file = makeFile('plan.pdf', 2048, 77);
    const { accepted, rejected } = validateUploadFiles([{ ...file }], {
        existingFiles: [file],
    });

    assert.deepEqual(accepted, []);
    assert.equal(rejected[0].reason, 'duplicate');
});

test('Drag-Counter hält das Overlay bei verschachtelten Drag-Events stabil', () => {
    global.document = { visibilityState: 'visible' };
    const controller = filePoolExternalDrop({
        writable: true,
        model: 'fileUploads.7',
    });
    controller.$root = { isConnected: true };
    const transfer = externalTransfer();
    const event = { dataTransfer: transfer, target: null, preventDefault() {} };

    controller.handleWindowDragEnter(event);
    controller.handleWindowDragEnter(event);
    controller.handleWindowDragLeave(event);

    assert.equal(controller.dragDepth, 1);
    assert.equal(controller.externalDragActive, true);

    controller.handleWindowDragLeave(event);
    assert.equal(controller.dragDepth, 0);
    assert.equal(controller.externalDragActive, false);
});

test('read-only Explorer und interne Moves öffnen keinen Upload', () => {
    global.document = { visibilityState: 'visible' };
    const controller = filePoolExternalDrop({
        writable: false,
        model: 'fileUploads.7',
    });
    controller.$root = { isConnected: true };

    let prevented = false;
    controller.handleWindowDragEnter({
        dataTransfer: externalTransfer(),
        target: null,
        preventDefault() { prevented = true; },
    });
    assert.equal(prevented, false);
    assert.equal(controller.externalDragActive, false);

    controller.writable = true;
    controller.handleWindowDragEnter({
        dataTransfer: {
            types: ['Files', 'application/x-railtime-file'],
        },
        target: null,
        preventDefault() { prevented = true; },
    });
    assert.equal(controller.externalDragActive, false);
});

test('öffnet zuerst den serverautorisierten Dialog und übergibt Dateien genau einmal', async () => {
    const calls = [];
    global.CustomEvent = class CustomEvent {
        constructor(type, init = {}) {
            this.type = type;
            this.detail = init.detail;
        }
    };
    global.document = { visibilityState: 'visible' };
    global.window = {
        requestAnimationFrame(callback) { callback(); },
        dispatchEvent(event) { calls.push(['event', event]); },
    };

    const file = makeFile('fahrplan.pdf');
    const controller = filePoolExternalDrop({
        writable: true,
        model: 'fileUploads.7',
    });
    controller.$root = { isConnected: true };
    controller.$wire = {
        async openUploadForm() { calls.push(['open']); },
    };
    controller.$nextTick = (callback) => callback();

    const event = {
        dataTransfer: externalTransfer([file]),
        target: null,
        preventDefault() { calls.push(['prevent']); },
        stopPropagation() { calls.push(['stop']); },
    };

    await controller.handleWindowDrop(event);

    assert.equal(calls.filter(([type]) => type === 'open').length, 1);
    assert.equal(calls.filter(([type]) => type === 'event').length, 1);
    assert.equal(calls.findIndex(([type]) => type === 'open') < calls.findIndex(([type]) => type === 'event'), true);
    const handoff = calls.find(([type]) => type === 'event')[1];
    assert.equal(handoff.type, 'filepool:add-files');
    assert.equal(handoff.detail.model, 'fileUploads.7');
    assert.deepEqual(handoff.detail.files, [file]);
});

test('Drop innerhalb des Upload-Modals wird nicht zusätzlich global verarbeitet', async () => {
    class FakeElement {
        closest(selector) {
            return selector === '[data-filepool-upload-modal]' ? this : null;
        }
    }
    global.Element = FakeElement;
    global.document = { visibilityState: 'visible' };

    let opens = 0;
    const controller = filePoolExternalDrop({ writable: true, model: 'fileUploads.7' });
    controller.$root = { isConnected: true };
    controller.$wire = { async openUploadForm() { opens += 1; } };

    await controller.handleWindowDrop({
        dataTransfer: externalTransfer([makeFile('inside.pdf')]),
        target: new FakeElement(),
        preventDefault() {},
        stopPropagation() {},
    });

    assert.equal(opens, 0);
});

test('modellgebundene Übergabe wird nur von der passenden Dropzone übernommen', () => {
    const own = filePoolDropzone({ model: 'fileUploads.7' });
    const added = [];
    own.dz = {
        files: [makeFile('existing.pdf', 10, 1)],
        addFile(file) { added.push(file); },
    };

    own.handleExternalFiles({
        detail: {
            model: 'fileUploads.8',
            files: [makeFile('wrong.pdf')],
        },
    });
    own.handleExternalFiles({
        detail: {
            model: 'fileUploads.7',
            files: [
                makeFile('existing.pdf', 10, 1),
                makeFile('new.pdf', 20, 2),
                makeFile('new.pdf', 20, 2),
            ],
        },
    });

    assert.deepEqual(added.map(({ name }) => name), ['new.pdf']);
});

test('explizites Abbrechen stoppt zuerst Livewire-Transport und schließt danach das Formular', async () => {
    const calls = [];
    const controller = filePoolExternalDrop({ model: 'fileUploads.7' });
    controller.$wire = {
        async cancelUpload(model) { calls.push(`cancel:${model}`); },
        async cancelUploadForm() { calls.push('close'); },
    };

    await controller.cancelUploadForm();

    assert.deepEqual(calls, ['cancel:fileUploads.7', 'close']);
});
