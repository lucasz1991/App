import assert from 'node:assert/strict';
import test from 'node:test';
import { aiIntakeComposer } from '../../resources/js/ai-intake-composer.js';

function fixture(options = {}, custom = {}) {
    const events = new EventTarget();
    const created = [], revoked = [], canceled = [], uploads = [], calls = [];
    let stopped = 0;
    const stream = { getTracks: () => [{ stop() { stopped++; } }] };
    const recorders = [];
    class Recorder {
        static isTypeSupported() { return true; }
        constructor() { this.state = 'inactive'; this.mimeType = 'audio/webm'; recorders.push(this); }
        start() { this.state = 'recording'; }
        stop() {
            if (this.state === 'inactive') return;
            this.state = 'inactive';
            this.ondataavailable?.({ data: new Blob(['synthetic audio'], { type: 'audio/webm' }) });
            this.onstop?.();
        }
    }
    const wire = {
        intakeId: 3, intakeRevision: 2, sourceRevision: 1, settingsRevision: 5,
        contextChanged: false, captureOpen: true, uploads: [], audioUpload: null,
        async beginContextUpload() { calls.push('bind'); },
        upload(name, file, success, error) { uploads.push({ name, file, success, error }); },
        cancelUpload(name) { canceled.push(name); },
        async removeUpload(index) { this.uploads.splice(index, 1); },
        async removeAudio() { this.audioUpload = null; },
        async submitPrompt() { calls.push('submit'); },
        ...custom.wire,
    };
    const state = aiIntakeComposer(wire, { mode: 'assistant', componentId: 'fixture', ...options }, {
        events, mediaDevices: { getUserMedia: custom.getUserMedia ?? (() => Promise.resolve(stream)) },
        MediaRecorder: Recorder, timers: { setInterval: () => 1, clearInterval() {} },
        URL: { createObjectURL(file) { const value = `blob:${created.length}`; created.push({ value, file }); return value; }, revokeObjectURL(value) { revoked.push(value); } },
    });
    state.$refs = { prompt: { value: 'Synthetic question', style: {}, scrollHeight: 50 }, files: { click() { calls.push('picker'); } } };
    state.init();
    return { state, wire, events, calls, uploads, canceled, created, revoked, recorders, stream, stopped: () => stopped };
}

test('file picker binds exact selected context before opening', async () => {
    const f = fixture(); await f.state.chooseFiles(); assert.deepEqual(f.calls, ['bind', 'picker']); f.state.destroy();
});

test('changed context while binding cannot open picker or microphone', async () => {
    let done; const f = fixture({}, { wire: { beginContextUpload: () => new Promise(resolve => { done = resolve; }) } });
    const pending = f.state.chooseFiles(); f.wire.intakeId = 4; done(); await pending; assert.deepEqual(f.calls, []); f.state.destroy();
});

test('text and completed files survive hiding and reopening the persistent panel', () => {
    const f = fixture(); f.wire.uploads = [{}]; f.wire.audioUpload = {}; f.state.previewAudio(new File(['a'], 'voice.wav'));
    f.events.dispatchEvent(new Event('ai-intake-panel-hidden'));
    assert.equal(f.state.$refs.prompt.value, 'Synthetic question'); assert.equal(f.wire.uploads.length, 1); assert.ok(f.state.audioPreview); assert.equal(f.revoked.length, 0); f.state.destroy();
});

test('hidden panel cancels recording and late microphone permission without uploads', async () => {
    let done; const f = fixture({}, { getUserMedia: () => new Promise(resolve => { done = resolve; }) });
    const pending = f.state.start(); await new Promise(resolve => setImmediate(resolve)); f.events.dispatchEvent(new Event('ai-intake-panel-hidden')); done(f.stream); await pending;
    assert.equal(f.uploads.length, 0); assert.equal(f.recorders.length, 0); assert.equal(f.stopped(), 1); f.state.destroy();
});

test('explicit recording stop gives an audio preview and never submits automatically', async () => {
    const f = fixture(); await f.state.start(); f.state.stop(); assert.equal(f.uploads.length, 1); assert.equal(f.uploads[0].name, 'audioUpload');
    assert.ok(f.state.audioPreview); assert.ok(f.stopped() > 0); assert.ok(!f.calls.includes('submit')); f.uploads[0].success(); f.state.destroy(); assert.equal(f.revoked.length, 1);
});

test('destroy cancels upload and prevents late success UI changes', async () => {
    const f = fixture(); await f.state.start(); f.state.stop(); f.state.destroy(); f.uploads[0].success();
    assert.ok(f.canceled.includes('audioUpload')); assert.equal(f.state.fileName, ''); assert.equal(f.state.audioPreview, '');
});

test('repeated multiple selection appends previews and removal revokes exactly owned URL', async () => {
    const f = fixture(); const first = new File(['a'], 'first.png', { type: 'image/png' }); const second = new File(['b'], 'second.wav', { type: 'audio/wav' });
    f.state.filesSelected({ target: { files: [first] } }); f.wire.uploads = [{}]; f.state.filesSelected({ target: { files: [second] } }); f.wire.uploads.push({});
    assert.deepEqual(f.state.filePreviews.map(file => file.kind), ['image', 'audio']); await f.state.removeFile(0); assert.equal(f.revoked.length, 1); assert.equal(f.state.filePreviews[0].name, 'second.wav'); f.state.destroy();
});

test('failed removal keeps its preview for retry', async () => {
    const f = fixture({}, { wire: { async removeUpload() {} } }); f.state.filesSelected({ target: { files: [new File(['a'], 'first.png', { type: 'image/png' })] } }); f.wire.uploads = [{}];
    await f.state.removeFile(0); assert.equal(f.state.filePreviews.length, 1); assert.equal(f.revoked.length, 0); f.state.destroy();
});

test('canceling a later upload preserves ready earlier previews', () => {
    const f = fixture(); f.state.filesSelected({ target: { files: [new File(['a'], 'first.png', { type: 'image/png' })] } }); f.wire.uploads = [{}];
    f.state.filesSelected({ target: { files: [new File(['b'], 'second.png', { type: 'image/png' })] } }); f.state.beginUpload(); f.state.progressUpload({ progress: 47 });
    f.events.dispatchEvent(new Event('ai-intake-panel-hidden')); assert.equal(f.state.filePreviews.length, 1); assert.equal(f.state.uploadActive, false); assert.equal(f.revoked.length, 1); f.state.finishUpload(); assert.equal(f.state.uploadProgress, 0); f.state.destroy();
});

test('Enter sends once while Shift and IME Enter remain editing', async () => {
    const f = fixture(); let prevented = 0;
    f.state.enter({ shiftKey: true, preventDefault() { prevented++; } }); f.state.enter({ isComposing: true, preventDefault() { prevented++; } });
    await f.state.enter({ preventDefault() { prevented++; } }); assert.deepEqual(f.calls, ['submit']); assert.equal(prevented, 1); f.state.destroy();
});

test('busy, pending uploads and recording prevent duplicate submission', async () => {
    let done; const f = fixture({}, { wire: { submitPrompt: () => new Promise(resolve => { done = resolve; }) } });
    const pending = f.state.submit(); assert.equal(f.state.busy, true); await f.state.submit(); done(); await pending; assert.equal(f.state.busy, false);
    f.state.beginUpload(); assert.equal(f.state.canSubmit(), false); f.state.cancelUpload(); f.state.recording = true; assert.equal(f.state.canSubmit(), false); f.state.destroy();
});

test('configured capture count includes recorded audio and requires no question binding', async () => {
    const f = fixture({ mode: 'capture', maxFiles: 1 }); f.wire.audioUpload = {}; await f.state.chooseFiles(); assert.equal(f.calls.length, 0); f.wire.audioUpload = null; await f.state.chooseFiles(); assert.deepEqual(f.calls, ['picker']); f.state.destroy();
});

test('successful matching submission clears previews, other component event is ignored', () => {
    const f = fixture(); f.state.previewAudio(new File(['a'], 'voice.wav')); f.state.submitted('other'); assert.ok(f.state.audioPreview); f.state.submitted('fixture'); assert.equal(f.state.audioPreview, ''); f.state.destroy();
});

test('context reset releases streams and previews without sending or reusing old input', async () => {
    const f = fixture(); await f.state.start(); f.wire.sourceRevision++; f.state.syncContext(); assert.equal(f.uploads.length, 0); assert.ok(f.stopped() > 0); assert.equal(f.state.recording, false); f.state.destroy();
});

test('typing into an initially empty composer changes reactive submit availability', async () => {
    const f = fixture(); f.state.$refs.prompt.value = ''; f.state.updateInput(); assert.equal(f.state.hasText, false); assert.equal(f.state.canSubmit(), false);
    f.state.$refs.prompt.value = 'Typed question'; f.state.updateInput(); assert.equal(f.state.hasText, true); assert.equal(f.state.canSubmit(), true);
    await f.state.submit(); assert.deepEqual(f.calls, ['submit']);
    f.state.$refs.prompt.value = '  '; f.state.updateInput(); assert.equal(f.state.hasText, false); assert.equal(f.state.canSubmit(), false); f.state.destroy();
});
