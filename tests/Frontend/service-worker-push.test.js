import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';
import test from 'node:test';

const workerSource = readFileSync(new URL('../../public/service-worker.js', import.meta.url), 'utf8');

class TestMessageChannel {
    constructor() {
        const port = () => ({ onmessage: null, onmessageerror: null, start() {}, close() {} });
        this.port1 = port();
        this.port2 = port();
        this.port2.postMessage = (data) => queueMicrotask(() => this.port1.onmessage?.({ data }));
    }
}

function client({ chatId = 0, ack = true, focused = true, visible = true, url = 'https://railtime.example/app/chat', fail = false, navigateFail = false } = {}) {
    return {
        url,
        visibilityState: visible ? 'visible' : 'hidden',
        messages: [],
        navigations: [],
        focusCount: 0,
        async navigate(target) {
            if (navigateFail) throw new Error('Client was closed before navigation');
            this.navigations.push(target);
            this.url = target;
            return this;
        },
        async focus() {
            this.focusCount++;
            return this;
        },
        postMessage(message, [port]) {
            this.messages.push(message);
            if (fail) throw new Error('Client was closed');
            if (message.type === 'railtime:push-context-request') {
                port.postMessage({
                    type: 'railtime:push-context-ack',
                    notification_id: message.notification_id,
                    active_chat_id: chatId,
                    focused,
                });
            } else if (ack) {
                port.postMessage({
                    type: 'railtime:push-received-ack',
                    notification_id: message.notification_id,
                });
            }
        },
    };
}

function harness({ clients = [], enumerateError = false, displayError = false } = {}) {
    const listeners = new Map();
    const notifications = [];
    const requests = [];
    const openedWindows = [];
    const order = [];
    let enumerations = 0;
    const self = {
        registration: {
            scope: 'https://railtime.example/app/',
            async showNotification(title, options) {
                order.push('display');
                if (displayError) throw new Error('Notification display failed');
                notifications.push({ title, ...structuredClone(options) });
            },
        },
        navigator: {},
        clients: {
            async matchAll() {
                enumerations++;
                if (enumerateError) throw new Error('Client enumeration failed');
                return clients;
            },
            async openWindow(url) {
                openedWindows.push(url);
                return null;
            },
        },
        addEventListener(type, callback) { listeners.set(type, callback); },
    };
    runInNewContext(workerSource, {
        self, URL, MessageChannel: TestMessageChannel,
        setTimeout: (callback) => setTimeout(callback, 1), clearTimeout,
        fetch: async (url, options) => { order.push('ring'); requests.push({ url, options }); },
    });
    return {
        notifications, requests, order, openedWindows,
        get enumerations() { return enumerations; },
        async push(payload) {
            let pending;
            listeners.get('push')({
                data: { json: () => payload },
                waitUntil(promise) { pending = promise; },
            });
            await pending;
        },
        async clickNotification(url) {
            let pending;
            let closed = false;
            listeners.get('notificationclick')({
                notification: { data: { url }, close() { closed = true; } },
                waitUntil(promise) { pending = promise; },
            });
            await pending;
            return closed;
        },
    };
}

function payload(category = 'chat') {
    return {
        title: 'RailTime', body: 'Neue Nachricht',
        data: { category, url: 'chat?chat=17', notification_id: 'notification-17' },
    };
}

test('background push always creates one visible system notification without a page client', async () => {
    const worker = harness();
    await worker.push(payload());
    assert.equal(worker.notifications.length, 1);
    assert.equal(worker.notifications[0].silent, undefined);
    assert.equal(worker.notifications[0].data.url, 'https://railtime.example/app/chat?chat=17');
    assert.equal(worker.notifications[0].tag, 'notification-17');
});

test('an already visible conversation still creates a visible notification without a second sound', async () => {
    const foreground = client({ chatId: 17 });
    const worker = harness({ clients: [foreground] });
    await worker.push(payload());
    assert.equal(worker.notifications.length, 1);
    assert.equal(worker.notifications[0].silent, true);
    assert.deepEqual(foreground.messages.map((message) => message.type), ['railtime:push-context-request']);
});

test('a ready foreground application acknowledgement only silences the system notification', async () => {
    const backgroundWindow = client({ focused: false });
    const focusedWindow = client();
    const worker = harness({ clients: [backgroundWindow, focusedWindow] });
    await worker.push(payload('messages'));
    assert.equal(worker.notifications.length, 1);
    assert.equal(worker.notifications[0].silent, true);
    assert.equal(focusedWindow.messages.filter((message) => message.type === 'railtime:push-received').length, 1);
    assert.equal(backgroundWindow.messages.filter((message) => message.type === 'railtime:push-received').length, 0);
});

test('a suspended or unready foreground client cannot suppress notification delivery', async () => {
    const worker = harness({ clients: [client({ ack: false })] });
    await worker.push(payload('messages'));
    assert.equal(worker.notifications.length, 1);
    assert.equal(worker.notifications[0].silent, undefined);
});

test('closed clients and client enumeration failures still deliver visible notifications', async () => {
    for (const options of [{ clients: [client({ fail: true })] }, { enumerateError: true }]) {
        const worker = harness(options);
        await worker.push(payload());
        assert.equal(worker.notifications.length, 1);
        assert.equal(worker.notifications[0].silent, undefined);
    }
});

test('hidden or out of scope clients do not silence notifications or receive message contents', async () => {
    const hidden = client({ visible: false });
    const foreign = client({ url: 'https://other.example/app/' });
    const outside = client({ url: 'https://railtime.example/another/' });
    const worker = harness({ clients: [hidden, foreign, outside] });
    await worker.push(payload());
    assert.equal(worker.notifications[0].silent, undefined);
    assert.equal(hidden.messages.length + foreign.messages.length + outside.messages.length, 0);
});

test('calls display immediately and acknowledge ringing only after successful system presentation', async () => {
    const worker = harness({ clients: [client({ ack: false })] });
    const call = payload('calls');
    call.data.ring_ack_url = 'https://railtime.example/app/calls/ring?signed=fixture';
    await worker.push(call);
    assert.equal(worker.enumerations, 0);
    assert.equal(worker.notifications.length, 1);
    assert.equal(worker.notifications[0].silent, undefined);
    assert.equal(worker.notifications[0].requireInteraction, true);
    assert.equal(worker.notifications[0].renotify, true);
    assert.deepEqual(worker.order, ['display', 'ring']);
    assert.equal(worker.requests[0].options.method, 'POST');
});

test('failed system presentation never reports a call as ringing', async () => {
    const worker = harness({ displayError: true });
    const call = payload('calls');
    call.data.ring_ack_url = 'https://railtime.example/app/calls/ring?signed=fixture';
    await assert.rejects(worker.push(call), /Notification display failed/);
    assert.equal(worker.requests.length, 0);
});

test('untrusted push targets stay within the registration scope and cannot trigger foreign ring requests', async () => {
    const worker = harness();
    const call = payload('calls');
    call.data.url = 'https://other.example/';
    call.icon = 'https://other.example/private.png';
    call.data.ring_ack_url = 'https://other.example/ring';
    await worker.push(call);
    assert.equal(worker.notifications[0].data.url, 'https://railtime.example/app/');
    assert.equal(worker.notifications[0].icon, 'https://railtime.example/app/pwa-icons/pwa-192.png');
    assert.equal(worker.requests.length, 0);
});

test('repeated push events each honor the visible notification promise while retaining a replacement tag', async () => {
    const worker = harness({ clients: [client({ chatId: 17 })] });
    await worker.push(payload());
    await worker.push(payload());
    assert.equal(worker.notifications.length, 2);
    assert.equal(worker.notifications[0].tag, worker.notifications[1].tag);
    assert.ok(worker.notifications.every((notification) => notification.silent === true));
});

for (const requestType of ['shift', 'capacity']) {
    test(`${requestType} request push opens the exact workday request in an existing application window`, async () => {
        const foreground = client({ url: 'https://railtime.example/app/mein-arbeitstag' });
        const worker = harness({ clients: [foreground] });
        const request = payload('messages');
        request.data.url = `mein-arbeitstag?tab=today&request_type=${requestType}&request_id=41&request_revision=7`;
        await worker.push(request);

        const target = `https://railtime.example/app/${request.data.url}`;
        assert.equal(worker.notifications[0].data.url, target);
        assert.equal(await worker.clickNotification(worker.notifications[0].data.url), true);
        assert.deepEqual(foreground.navigations, [target]);
        assert.equal(foreground.focusCount, 1);
        assert.equal(worker.openedWindows.length, 0);
        assert.equal(worker.requests.length, 0, 'Opening a request must not submit a business response');
    });
}

test('workday request click opens a new application window when no matching client remains', async () => {
    const foreign = client({ url: 'https://other.example/app/' });
    const outside = client({ url: 'https://railtime.example/another/' });
    const closed = client({ navigateFail: true });
    const worker = harness({ clients: [foreign, outside, closed] });
    const target = 'https://railtime.example/app/mein-arbeitstag?tab=today&request_type=shift&request_id=42&request_revision=3';

    await worker.clickNotification(target);
    assert.deepEqual(worker.openedWindows, [target]);
    assert.equal(foreign.navigations.length + outside.navigations.length + closed.navigations.length, 0);
    assert.equal(worker.requests.length, 0);
});

test('request notification click keeps foreign targets inside the application scope', async () => {
    const worker = harness();
    await worker.clickNotification('https://other.example/mein-arbeitstag?request_id=41');
    assert.deepEqual(worker.openedWindows, ['https://railtime.example/app/']);
    assert.equal(worker.requests.length, 0);
});
