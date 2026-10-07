import assert from 'node:assert/strict';
import test from 'node:test';
import { aiIntakeRecorder } from '../../resources/js/ai-intake-recorder.js';

function fixture(getUserMedia) {
    let stopped = 0;
    const events = new EventTarget();
    const track = { stop() { stopped++; } };
    const stream = { getTracks: () => [track] };
    const uploads = [];
    const recorderInstances = [];
    class FakeRecorder {
        static isTypeSupported() { return true; }
        constructor() { this.state = 'inactive'; this.mimeType = 'audio/webm'; recorderInstances.push(this); }
        start() { this.state = 'recording'; }
        stop() { if(this.state === 'inactive') return; this.state = 'inactive'; this.ondataavailable?.({data:new Blob(['fixture audio'],{type:'audio/webm'})}); this.onstop?.(); }
    }
    const state = aiIntakeRecorder({ upload(name,file,success,error) { uploads.push({name,file,success,error}); },cancelUpload() {} },{mediaDevices:{getUserMedia:getUserMedia ?? (()=>Promise.resolve(stream))},MediaRecorder:FakeRecorder,events,timers:{setInterval:()=>1,clearInterval:()=>{}}});
    state.init();
    return {state,events,stream,uploads,recorderInstances,stopped:()=>stopped};
}

test('explicit stop uploads one bounded audio file and releases microphone',async()=>{
    const f = fixture(); await f.state.start(); assert.equal(f.state.recording,true); f.state.stop();
    assert.equal(f.uploads.length,1); assert.equal(f.uploads[0].name,'audioUpload'); assert.equal(f.uploads[0].file.name,'Sprachanfrage.webm'); assert.ok(f.stopped()>0);
    f.uploads[0].success(); assert.equal(f.state.uploading,false); f.state.destroy();
});

test('navigation cancels recording without uploading discarded audio',async()=>{
    const f = fixture(); await f.state.start(); f.events.dispatchEvent(new Event('livewire:navigating'));
    assert.equal(f.uploads.length,0); assert.equal(f.state.recording,false); assert.ok(f.stopped()>0); f.state.destroy();
});

test('late microphone permission after component destruction closes stream',async()=>{
    let resolve; const f = fixture(()=>new Promise(done=>{resolve=done;})); const pending=f.state.start(); f.state.destroy(); resolve(f.stream); await pending;
    assert.equal(f.recorderInstances.length,0); assert.equal(f.stopped(),1); assert.equal(f.uploads.length,0);
});

test('late upload completion cannot update a destroyed component',async()=>{
    const f = fixture(); await f.state.start(); f.state.stop(); f.state.destroy(); f.uploads[0].success();
    assert.equal(f.state.fileName,''); assert.equal(f.state.uploading,false);
});
