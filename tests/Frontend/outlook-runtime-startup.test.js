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
