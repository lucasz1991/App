import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import vm from 'node:vm';

test('production IIFE exposes manifest callbacks for mobile without waiting for onReady', async () => {
    const bundleUrl = process.env.OUTLOOK_RUNTIME_TEST_BUNDLE
        || new URL('../../public/outlook-addin/runtime.js', import.meta.url);
    const bundle = await readFile(bundleUrl, 'utf8');
    const associations = new Map();
    const office = {
        onReady() {},
        actions: { associate(name, callback) { associations.set(name, callback); } },
    };
    const context = vm.createContext({ Office: office, console, setTimeout, clearTimeout });
    vm.runInContext(bundle, context);
    assert.equal(typeof context.onNewMessageComposeHandler, 'function');
    assert.equal(context.onNewMessageComposeHandler, associations.get('onNewMessageComposeHandler'));
    assert.equal(context.onMessageComposeHandler, context.onNewMessageComposeHandler);
});

test('hidden mobile HTML loads its entry point synchronously after Office.js', async () => {
    const html = await readFile(new URL('../../resources/views/outlook-addin/runtime.blade.php', import.meta.url), 'utf8');
    assert.match(html, /office\.js[\s\S]*<script src="\{\{ \$resolvedScriptUrl \}\}" type="text\/javascript"><\/script>/);
    assert.doesNotMatch(html, /\bdefer\b|\basync\b/);
});

test('actual HTML bundle startup probes contain only fixed labels and no credentials', async () => {
    const bundle = await readFile(process.env.OUTLOOK_RUNTIME_TEST_BUNDLE
        || new URL('../../public/outlook-addin/runtime.js', import.meta.url), 'utf8');
    const requests = [];
    const office = { onReady(callback) { callback(); }, actions: { associate() {} } };
    const context = vm.createContext({
        Office: office, console, setTimeout, clearTimeout, URL, AbortController,
        document: { querySelector() { return { getAttribute() { return 'https://example.test/outlook-addin/config.json'; } }; } },
        fetch(url, options) { requests.push({ url, options }); return Promise.resolve({ ok: true }); },
    });
    vm.runInContext(bundle, context);
    for (let index = 0; index < 10; index += 1) await Promise.resolve();
    assert.equal(requests.length, 2);
    assert.deepEqual(requests.map(({ url }) => new URL(url).searchParams.get('rt_phase')),
        ['runtime-loaded', 'office-ready']);
    for (const { url, options } of requests) {
        assert.equal(new URL(url).origin, 'https://example.test');
        assert.equal(new URL(url).pathname, '/outlook-addin/config.json');
        assert.equal(new URL(url).searchParams.get('rt_rev'), 'compose-order-20261005');
        assert.equal(options.credentials, 'omit');
        assert.equal(options.referrerPolicy, 'no-referrer');
        assert.equal(options.headers, undefined);
        assert.equal(options.body, undefined);
    }
});
