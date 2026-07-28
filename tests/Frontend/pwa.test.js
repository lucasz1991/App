import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

import {
    clearPushDeviceBinding,
    isAndroidDevice,
    isIosDevice,
    isStandaloneMode,
    pushDeviceBindingMatches,
    readPushDeviceBinding,
    reconcilePushDeviceAccount,
    runtimeCapabilities,
    serviceWorkerScope,
    syncExistingPushSubscription,
    urlBase64ToUint8Array,
    writePushDeviceBinding,
} from '../../resources/js/pwa.js';
import { NotificationSeenCache } from '../../resources/js/notification-seen-cache.js';
import {
    activeVisibleChatId,
    NotificationPresentationContext,
} from '../../resources/js/notification-presentation.js';

test('detects iPhone and iPadOS desktop-style user agents', () => {
    assert.equal(isIosDevice({
        userAgent: 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X)',
        platform: 'iPhone',
        maxTouchPoints: 5,
    }), true);

    assert.equal(isIosDevice({
        userAgent: 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15)',
        platform: 'MacIntel',
        maxTouchPoints: 5,
    }), true);

    assert.equal(isIosDevice({
        userAgent: 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15)',
        platform: 'MacIntel',
        maxTouchPoints: 0,
    }), false);
});

test('detects Android without treating it as iOS', () => {
    const navigatorLike = {
        userAgent: 'Mozilla/5.0 (Linux; Android 16; Pixel 10) Chrome/140.0',
        platform: 'Linux armv8l',
        maxTouchPoints: 5,
    };

    assert.equal(isAndroidDevice(navigatorLike), true);
    assert.equal(isIosDevice(navigatorLike), false);
});

test('recognises both display-mode and legacy iOS standalone state', () => {
    assert.equal(isStandaloneMode(
        { matchMedia: () => ({ matches: true }) },
        { standalone: false },
    ), true);

    assert.equal(isStandaloneMode(
        { matchMedia: () => ({ matches: false }) },
        { standalone: true },
    ), true);
});

test('requires secure context and every Web Push browser API', () => {
    const windowLike = {
        isSecureContext: true,
        PushManager: function PushManager() {},
        Notification: { permission: 'default' },
        matchMedia: () => ({ matches: false }),
    };
    const navigatorLike = {
        serviceWorker: {},
        userAgent: 'Desktop Browser',
        platform: 'Win32',
        maxTouchPoints: 0,
    };

    assert.equal(runtimeCapabilities(windowLike, navigatorLike).supported, true);
    assert.equal(runtimeCapabilities(
        { ...windowLike, isSecureContext: false },
        navigatorLike,
    ).supported, false);
    assert.equal(runtimeCapabilities(
        { ...windowLike, PushManager: undefined },
        navigatorLike,
    ).supported, false);

    delete windowLike.PushManager;
    assert.equal(runtimeCapabilities(windowLike, navigatorLike).supported, false);
});

test('decodes an unpadded URL-safe VAPID public key', () => {
    const decoder = (value) => Buffer.from(value, 'base64').toString('binary');
    const decoded = urlBase64ToUint8Array('AQIDBA', decoder);

    assert.deepEqual([...decoded], [1, 2, 3, 4]);
});

test('derives a service worker scope from the script directory', () => {
    assert.equal(
        serviceWorkerScope('https://railtime.example/app/service-worker.js'),
        'https://railtime.example/app/',
    );
    assert.equal(
        serviceWorkerScope('./service-worker.js', 'https://railtime.example/sub/login'),
        'https://railtime.example/sub/',
    );
});

test('suppresses a stable notification id only for the configured short TTL', () => {
    let now = 1_000;
    const cache = new NotificationSeenCache({
        ttl: 120_000,
        now: () => now,
    });

    assert.equal(cache.take('message:42'), true);
    assert.equal(cache.take('message:42'), false);
    assert.equal(cache.take('message:43'), true);

    now += 120_001;
    assert.equal(cache.take('message:42'), true);
});

test('broadcasts seen ids so another app window can suppress the same toast', () => {
    const listeners = [];
    const posted = [];
    const channel = {
        addEventListener: (type, listener) => listeners.push(listener),
        postMessage: (payload) => posted.push(payload),
    };
    const first = new NotificationSeenCache({ channel, now: () => 10 });
    const second = new NotificationSeenCache({ channel, now: () => 10 });

    assert.equal(first.take('chat-message:7'), true);
    listeners.forEach((listener) => listener({ data: posted[0] }));
    assert.equal(second.take('chat-message:7'), false);
});

function visibleDocument({ chatId = 0, mobilePane = 'chat', focused = true } = {}) {
    return {
        visibilityState: 'visible',
        hasFocus: () => focused,
        querySelector: () => chatId > 0
            ? {
                dataset: {
                    activeChatId: String(chatId),
                    mobilePane,
                },
            }
            : null,
    };
}

test('treats a selected mobile chat as open only while its conversation pane is visible', () => {
    assert.equal(activeVisibleChatId({
        documentLike: visibleDocument({ chatId: 17, mobilePane: 'chat' }),
        windowLike: { innerWidth: 390 },
    }), 17);
    assert.equal(activeVisibleChatId({
        documentLike: visibleDocument({ chatId: 17, mobilePane: 'list' }),
        windowLike: { innerWidth: 390 },
    }), 0);
    assert.equal(activeVisibleChatId({
        documentLike: visibleDocument({ chatId: 17, mobilePane: 'list' }),
        windowLike: { innerWidth: 1280 },
    }), 17);
});

test('suppresses chat alerts across tabs when that chat is visibly open anywhere', () => {
    const context = new NotificationPresentationContext({
        clientId: 'local',
        channel: null,
        now: () => 10_000,
        documentLike: visibleDocument({ focused: true }),
        windowLike: { innerWidth: 1280 },
    });

    context.receive({
        type: 'railtime:presence',
        clientId: 'chat-tab',
        visible: true,
        focused: false,
        activeChatId: 17,
        updatedAt: 10_000,
    });

    assert.equal(context.shouldPresent({ category: 'chat', chatId: 17 }), false);
    assert.equal(context.shouldPresent({ category: 'chat', chatId: 18 }), true);
});

test('never presents an in-app alert from a hidden document', () => {
    const documentLike = {
        visibilityState: 'hidden',
        hasFocus: () => false,
        querySelector: () => null,
    };
    const context = new NotificationPresentationContext({
        clientId: 'hidden',
        channel: null,
        documentLike,
        windowLike: { innerWidth: 1280 },
    });

    assert.equal(context.shouldPresent({ category: 'messages' }), false);
});

function memoryStorage() {
    const values = new Map();

    return {
        getItem: (key) => values.get(key) ?? null,
        setItem: (key, value) => values.set(key, String(value)),
        removeItem: (key) => values.delete(key),
    };
}

test('persists only an opaque account binding and numeric server subscription id', () => {
    const storage = memoryStorage();

    assert.deepEqual(writePushDeviceBinding('opaque-account-a', 42, storage), {
        account: 'opaque-account-a',
        subscriptionId: 42,
    });
    assert.deepEqual(readPushDeviceBinding(storage), {
        account: 'opaque-account-a',
        subscriptionId: 42,
    });
    assert.equal(
        pushDeviceBindingMatches(readPushDeviceBinding(storage), 'opaque-account-a'),
        true,
    );
    assert.equal(
        pushDeviceBindingMatches(readPushDeviceBinding(storage), 'opaque-account-b'),
        false,
    );

    clearPushDeviceBinding(storage);
    assert.equal(readPushDeviceBinding(storage), null);
});

test('removes an unbound or foreign browser subscription without reassigning it', async () => {
    let unsubscribed = 0;
    let cleared = 0;
    const registration = {
        pushManager: {
            getSubscription: async () => ({
                unsubscribe: async () => {
                    unsubscribed += 1;

                    return true;
                },
            }),
        },
    };

    assert.equal(await reconcilePushDeviceAccount({
        accountBinding: 'opaque-account-b',
        deviceBinding: {
            account: 'opaque-account-a',
            subscriptionId: 42,
        },
        registration,
        clearBinding: () => {
            cleared += 1;
        },
    }), true);
    assert.equal(unsubscribed, 1);
    assert.equal(cleared, 1);

    assert.equal(await reconcilePushDeviceAccount({
        accountBinding: 'opaque-account-b',
        deviceBinding: {
            account: 'opaque-account-b',
            subscriptionId: 43,
        },
        registration,
        clearBinding: () => {
            cleared += 1;
        },
    }), false);
    assert.equal(unsubscribed, 1);
});

test('re-syncs an existing granted subscription only for its explicitly bound account', async () => {
    const requests = [];
    const subscription = { endpoint: 'https://push.example.test/device' };
    const deviceBinding = {
        account: 'opaque-account-a',
        subscriptionId: 42,
    };
    const synced = await syncExistingPushSubscription({
        subscription,
        capabilities: { permission: 'granted' },
        serverEnabled: true,
        serverConfigured: true,
        accountBinding: 'opaque-account-a',
        deviceBinding,
        subscribeUrl: '/settings/push/subscriptions',
        request: async (...args) => {
            requests.push(args);

            return { subscription_id: 42 };
        },
        serialize: (value) => ({ endpoint: value.endpoint }),
    });

    assert.deepEqual(synced, { subscription_id: 42 });
    assert.deepEqual(requests, [[
        '/settings/push/subscriptions',
        {
            method: 'POST',
            body: JSON.stringify({ endpoint: subscription.endpoint }),
        },
    ]]);

    const refused = await syncExistingPushSubscription({
        subscription,
        capabilities: { permission: 'granted' },
        serverEnabled: true,
        serverConfigured: true,
        accountBinding: 'opaque-account-b',
        deviceBinding,
        subscribeUrl: '/settings/push/subscriptions',
        request: async (...args) => requests.push(args),
        serialize: (value) => ({ endpoint: value.endpoint }),
    });

    assert.equal(refused, null);
    assert.equal(requests.length, 1);
});

test('local unsubscribe completes before the owned server device is revoked', () => {
    const source = readFileSync(
        new URL('../../resources/js/pwa.js', import.meta.url),
        'utf8',
    );
    const start = source.indexOf('async unsubscribe()');
    const end = source.indexOf('async savePreference(', start);
    const method = source.slice(start, end);

    assert.notEqual(start, -1);
    assert.notEqual(end, -1);
    assert.ok(method.indexOf('await subscription.unsubscribe()') >= 0);
    assert.ok(method.indexOf('await apiRequest(this.config.urls.unsubscribe') >= 0);
    assert.ok(
        method.indexOf('await subscription.unsubscribe()')
        < method.indexOf('await apiRequest(this.config.urls.unsubscribe'),
    );
    assert.match(method, /subscription_id:\s*binding\.subscriptionId/);
});
