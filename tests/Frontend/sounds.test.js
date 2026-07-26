import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

import { incomingNotificationSound } from '../../resources/js/realtime-notification-sound.js';

const soundSource = readFileSync(
    new URL('../../public/js/rt-sounds.js', import.meta.url),
    'utf8',
);

function createSoundHarness(initialState = 'running') {
    const oscillators = [];
    const listeners = new Map();
    const stored = new Map();

    class FakeAudioParam {
        constructor() {
            this.events = [];
        }

        setValueAtTime(value, time) {
            this.events.push(['set', value, time]);
        }

        exponentialRampToValueAtTime(value, time) {
            this.events.push(['ramp', value, time]);
        }
    }

    class FakeAudioNode {
        constructor() {
            this.connections = [];
        }

        connect(node) {
            this.connections.push(node);

            return node;
        }
    }

    class FakeAudioContext {
        constructor() {
            this.currentTime = 2;
            this.destination = new FakeAudioNode();
            this.state = initialState;
        }

        createOscillator() {
            const oscillator = new FakeAudioNode();
            oscillator.frequency = new FakeAudioParam();
            oscillator.startTimes = [];
            oscillator.stopTimes = [];
            oscillator.start = (time) => oscillator.startTimes.push(time);
            oscillator.stop = (time) => oscillator.stopTimes.push(time);
            oscillators.push(oscillator);

            return oscillator;
        }

        createGain() {
            const gain = new FakeAudioNode();
            gain.gain = new FakeAudioParam();

            return gain;
        }

        createBiquadFilter() {
            const filter = new FakeAudioNode();
            filter.frequency = new FakeAudioParam();

            return filter;
        }

        createStereoPanner() {
            const panner = new FakeAudioNode();
            panner.pan = new FakeAudioParam();

            return panner;
        }

        async resume() {
            this.state = 'running';
        }
    }

    const localStorage = {
        getItem: (key) => stored.get(key) ?? null,
        setItem: (key, value) => stored.set(key, String(value)),
    };
    const windowLike = {
        AudioContext: FakeAudioContext,
        addEventListener: (name, listener) => listeners.set(name, listener),
    };

    vm.runInNewContext(soundSource, {
        window: windowLike,
        localStorage,
        Date,
    });

    return {
        api: windowLike.RTSound,
        listeners,
        oscillators,
        stored,
    };
}

test('selects an audible signature even when the related view is already open', () => {
    assert.equal(incomingNotificationSound('chat'), 'chat');
    assert.equal(incomingNotificationSound('messages'), 'message');
    assert.equal(incomingNotificationSound('both'), 'message');
});

test('provides original layered chat and internal-message signatures', () => {
    const harness = createSoundHarness();

    assert.ok(harness.api.names.includes('chat'));
    assert.ok(harness.api.names.includes('message'));

    harness.api.play('chat');
    assert.equal(harness.oscillators.length, 5);
    assert.ok(new Set(harness.oscillators.map((oscillator) => oscillator.type)).size >= 2);

    harness.api.play('message');
    assert.equal(harness.oscillators.length, 9);
});

test('keeps the sound preference authoritative and persisted', () => {
    const harness = createSoundHarness();

    harness.api.setEnabled(false);
    harness.api.play('chat');

    assert.equal(harness.api.enabled, false);
    assert.equal(harness.stored.get('rt-sound'), 'false');
    assert.equal(harness.oscillators.length, 0);
});

test('resumes a previously unlocked suspended context for later notifications', async () => {
    const harness = createSoundHarness('suspended');

    assert.ok(harness.listeners.has('pointerdown'));
    assert.ok(harness.listeners.has('keydown'));
    assert.ok(harness.listeners.has('touchstart'));

    harness.api.play('chat');
    await Promise.resolve();

    assert.equal(harness.oscillators.length, 5);
});
