import test from 'node:test';
import assert from 'node:assert/strict';

import { welcomeIntro } from '../../resources/js/welcome-intro.js';

class FakeElement {
    constructor(interactive = false, module = null) {
        this.interactive = interactive;
        this.dataset = module ? { rtWelcomeModule: module } : {};
        this.scrolls = 0;
    }

    closest() {
        return this.interactive ? this : null;
    }

    scrollIntoView() {
        this.scrolls += 1;
    }
}

const makeIntro = () => {
    const video = {
        currentTime: 8,
        pauses: 0,
        loads: 0,
        pause() {
            this.pauses += 1;
        },
        load() {
            this.loads += 1;
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

    const viewport = { scrollTop: 80 };
    const modules = [new FakeElement(false, 'intro'), new FakeElement(false, 'devices')];

    intro.$refs = {
        video,
        heading: { focus() {} },
        viewport,
    };
    intro.$root = { querySelectorAll: () => modules };
    intro.$nextTick = (callback) => callback();

    return { intro, modules, video, viewport };
};

globalThis.Element = FakeElement;
globalThis.HTMLElement = FakeElement;
globalThis.document = { activeElement: null };
globalThis.window = {
    requestAnimationFrame: (callback) => callback(),
    setTimeout: (callback) => callback(),
};

test('changing a module pauses media and starts the new mobile step at the top', () => {
    const { intro, modules, video, viewport } = makeIntro();

    intro.videoPlaying = true;
    intro.goTo(1);

    assert.equal(intro.step, 1);
    assert.equal(video.pauses, 1);
    assert.equal(video.currentTime, 0);
    assert.equal(intro.videoPlaying, false);
    assert.equal(intro.videoFailed, false);
    assert.equal(viewport.scrollTop, 0);
    assert.equal(modules[1].scrolls, 1);
});

test('closing and destroying the tour both stop media playback', () => {
    const { intro, video } = makeIntro();

    intro.closeIntro(false);
    video.currentTime = 5;
    intro.destroy();

    assert.equal(intro.open, false);
    assert.equal(video.pauses, 2);
    assert.equal(video.currentTime, 0);
    assert.equal(video.loads, 0);
});

test('reopening resets the first current clip to its poster without reloading the old clip', () => {
    const { intro, modules, video } = makeIntro();

    intro.step = 1;
    intro.openIntro({ target: new FakeElement() });

    assert.equal(intro.step, 0);
    assert.equal(intro.open, true);
    assert.equal(video.pauses, 2);
    assert.equal(video.loads, 1);
    assert.equal(modules[0].scrolls, 1);
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
