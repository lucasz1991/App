import test from 'node:test';
import assert from 'node:assert/strict';

import { welcomeIntro } from '../../resources/js/welcome-intro.js';

class FakeElement {
    constructor(interactive = false) {
        this.interactive = interactive;
    }

    closest() {
        return this.interactive ? this : null;
    }
}

const makeIntro = () => {
    const video = {
        currentTime: 8,
        pauses: 0,
        pause() {
            this.pauses += 1;
        },
    };
    const intro = welcomeIntro({
        initiallyOpen: true,
        slides: [
            { id: 'intro', title: 'Intro', points: [] },
            { id: 'devices', title: 'Devices', points: [] },
        ],
        labels: {
            progress: 'Step :current of :total',
            openStep: 'Open :current/:total: :title',
        },
    });

    intro.$refs = {
        video,
        heading: { focus() {} },
    };
    intro.$nextTick = (callback) => callback();

    return { intro, video };
};

globalThis.Element = FakeElement;
globalThis.window = {
    requestAnimationFrame: (callback) => callback(),
    setTimeout: (callback) => callback(),
};

test('changing a module pauses and resets the active original video', () => {
    const { intro, video } = makeIntro();

    intro.videoPlaying = true;
    intro.goTo(1);

    assert.equal(intro.step, 1);
    assert.equal(video.pauses, 1);
    assert.equal(video.currentTime, 0);
    assert.equal(intro.videoPlaying, false);
    assert.equal(intro.videoFailed, false);
});

test('closing and destroying the tour both stop media playback', () => {
    const { intro, video } = makeIntro();

    intro.closeIntro(false);
    video.currentTime = 5;
    intro.destroy();

    assert.equal(intro.open, false);
    assert.equal(video.pauses, 2);
    assert.equal(video.currentTime, 0);
});

test('video controls keep their arrow keys while non-interactive content navigates', () => {
    const { intro } = makeIntro();
    let prevented = false;

    intro.handleKey({
        key: 'ArrowRight',
        target: new FakeElement(true),
        defaultPrevented: false,
        altKey: false,
        ctrlKey: false,
        metaKey: false,
        preventDefault() {
            prevented = true;
        },
    });

    assert.equal(intro.step, 0);
    assert.equal(prevented, false);

    intro.handleKey({
        key: 'ArrowRight',
        target: new FakeElement(false),
        defaultPrevented: false,
        altKey: false,
        ctrlKey: false,
        metaKey: false,
        preventDefault() {
            prevented = true;
        },
    });

    assert.equal(intro.step, 1);
    assert.equal(prevented, true);
});
