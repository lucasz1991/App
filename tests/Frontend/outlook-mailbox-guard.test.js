import assert from 'node:assert/strict';
import test from 'node:test';
import {
    MAILBOX_GUARD_TIMEOUT_MS,
    MailboxGuardError,
    assertMailboxBinding,
    readComposeSender,
} from '../../resources/js/outlook-addin/mailbox-guard.js';

const WORK = 'employee@company.example';
const PRIVATE = 'private@personal.example';

function fixture({ sender = WORK, profile = WORK, invoke } = {}) {
    const calls = [];
    const item = {
        from: {
            getAsync(callback) {
                calls.push('from');
                if (invoke) return invoke(callback);
                callback({ status: 'succeeded', value: { emailAddress: sender } });
            },
        },
        body: {
            setAsync: () => calls.push('set'),
            prependAsync: () => calls.push('prepend'),
            setSignatureAsync: () => calls.push('signature'),
        },
        addFileAttachmentFromBase64Async: () => calls.push('attachment'),
        removeAttachmentAsync: () => calls.push('remove'),
        disableClientSignatureAsync: () => calls.push('disable'),
    };
    const office = {
        AsyncResultStatus: { Succeeded: 'succeeded' },
        context: { mailbox: { item, userProfile: { emailAddress: profile } } },
    };
    const binding = { schema: 1, mailboxAddress: WORK, senderAddress: WORK, allowedSenderAddresses: [WORK] };
    return { office, item, binding, calls };
}

test('reads actual Compose From independently of the profile and never writes', async () => {
    const { office, item, calls } = fixture({ sender: ` ${WORK.toUpperCase()} `, profile: PRIVATE });
    assert.equal(await readComposeSender(office, item), WORK);
    assert.deepEqual(calls, ['from']);
});

test('valid binding returns a frozen item/sender snapshot without mutating its inputs', async () => {
    const { office, item, binding, calls } = fixture();
    Object.freeze(binding.allowedSenderAddresses);
    Object.freeze(binding);
    const snapshot = await assertMailboxBinding(office, item, binding);
    assert.deepEqual(snapshot, { item, senderAddress: WORK, mailboxAddress: WORK });
    assert.equal(Object.isFrozen(snapshot), true);
    assert.deepEqual(calls, ['from']);
});

test('normalizes exact address case and surrounding spaces in every binding field', async () => {
    const { office, item, binding } = fixture({ sender: ` ${WORK.toUpperCase()} `, profile: WORK.toUpperCase() });
    binding.mailboxAddress = ` ${WORK.toUpperCase()} `;
    binding.senderAddress = WORK.toUpperCase();
    binding.allowedSenderAddresses = [` ${WORK.toUpperCase()} `];
    assert.deepEqual(await assertMailboxBinding(office, item, binding), {
        item, senderAddress: WORK, mailboxAddress: WORK,
    });
});

test('a business profile never permits inserting into a private From address', async () => {
    const { office, item, binding, calls } = fixture({ sender: PRIVATE });
    await assert.rejects(assertMailboxBinding(office, item, binding), { code: 'SENDER_MISMATCH' });
    assert.deepEqual(calls, ['from']);
});

test('a business From address never permits a different or missing profile mailbox', async () => {
    for (const profile of [PRIVATE, undefined, '', null]) {
        const { office, item, binding, calls } = fixture();
        office.context.mailbox.userProfile.emailAddress = profile;
        await assert.rejects(assertMailboxBinding(office, item, binding), {
            code: profile === PRIVATE ? 'MAILBOX_MISMATCH' : 'MAILBOX_MISSING',
        });
        assert.deepEqual(calls, []);
    }
});

test('absent or malformed From never falls back to the matching profile', async () => {
    for (const sender of [undefined, null, '', 'not-an-address', '*@company.example',
        'Employee <employee@company.example>', 'mailto:employee@company.example',
        'employee@company.example,other@company.example', 'employee@company.example\r\n', ['employee@company.example']]) {
        const { office, item, binding, calls } = fixture({ invoke: (callback) => {
            callback({ status: 'succeeded', value: { emailAddress: sender } });
        } });
        await assert.rejects(assertMailboxBinding(office, item, binding), { code: 'SENDER_MISSING' });
        assert.deepEqual(calls, ['from']);
    }
});

test('fails closed when Compose From API or Office success enum is unavailable', async () => {
    for (const remove of [
        (office, item) => { delete item.from; },
        (office, item) => { item.from.getAsync = null; },
        (office) => { delete office.AsyncResultStatus; },
        (office) => { office.AsyncResultStatus.Succeeded = ''; },
    ]) {
        const { office, item, binding, calls } = fixture();
        remove(office, item);
        await assert.rejects(assertMailboxBinding(office, item, binding), { code: 'SENDER_API_UNAVAILABLE' });
        assert.deepEqual(calls, []);
    }
});

test('missing and malformed server bindings cannot use any implicit allow list', async () => {
    const invalid = [null, undefined, {}, { schema: 2 },
        { schema: 1, mailboxAddress: WORK, senderAddress: WORK, allowedSenderAddresses: [] },
        { schema: 1, mailboxAddress: WORK, senderAddress: WORK, allowedSenderAddresses: [PRIVATE] },
        { schema: 1, mailboxAddress: WORK, senderAddress: WORK, allowedSenderAddresses: ['*@company.example'] },
        { schema: 1, mailboxAddress: WORK, senderAddress: WORK, allowedSenderAddresses: new Array(1) },
        { schema: 1, mailboxAddress: WORK, senderAddress: '', allowedSenderAddresses: [WORK] },
    ];
    for (const binding of invalid) {
        const { office, item, calls } = fixture();
        await assert.rejects(assertMailboxBinding(office, item, binding), { code: 'MAILBOX_BINDING_INVALID' });
        assert.deepEqual(calls, []);
    }
});

test('only explicitly granted exact aliases are allowed and a changed original sender still blocks', async () => {
    const alias = 'employee+alias@company.example';
    const { office, item, binding, calls } = fixture({ sender: alias });
    binding.allowedSenderAddresses.push(alias);
    await assert.rejects(assertMailboxBinding(office, item, binding), { code: 'SENDER_MISMATCH' });
    binding.senderAddress = alias;
    assert.equal((await assertMailboxBinding(office, item, binding)).senderAddress, alias);
    assert.deepEqual(calls, ['from', 'from']);
});

test('callback errors and synchronous host errors reject without exposing private details', async () => {
    for (const invoke of [
        (callback) => callback({ status: 'failed', error: { message: PRIVATE } }),
        (callback) => callback(undefined),
        (callback) => callback({ value: { emailAddress: WORK } }),
        () => { throw new Error(PRIVATE); },
        (callback) => callback({ status: 'succeeded', get value() { throw new Error(PRIVATE); } }),
    ]) {
        const { office, item, calls } = fixture({ invoke });
        await assert.rejects(readComposeSender(office, item), (error) => {
            assert.equal(error instanceof MailboxGuardError, true);
            assert.equal(error.code, 'SENDER_READ_FAILED');
            assert.equal(error.message.includes(PRIVATE), false);
            assert.equal(JSON.stringify(error).includes(PRIVATE), false);
            return true;
        });
        assert.deepEqual(calls, ['from']);
    }
});

test('a missing callback times out and a late successful callback cannot authorize writes', async (context) => {
    context.mock.timers.enable({ apis: ['setTimeout'] });
    let callback;
    const { office, item, binding, calls } = fixture({ invoke: (cb) => { callback = cb; } });
    const operation = assertMailboxBinding(office, item, binding);
    const rejected = assert.rejects(operation, { code: 'SENDER_READ_TIMEOUT' });
    context.mock.timers.tick(MAILBOX_GUARD_TIMEOUT_MS);
    await rejected;
    callback({ status: 'succeeded', value: { emailAddress: WORK } });
    assert.deepEqual(calls, ['from']);
});

test('duplicate callbacks settle only once and clear the timeout', async (context) => {
    context.mock.timers.enable({ apis: ['setTimeout'] });
    const { office, item, calls } = fixture({ invoke: (callback) => {
        callback({ status: 'succeeded', value: { emailAddress: WORK } });
        callback({ status: 'succeeded', value: { emailAddress: PRIVATE } });
        callback({ get status() { throw new Error('duplicate must not be read'); } });
    } });
    assert.equal(await readComposeSender(office, item), WORK);
    context.mock.timers.tick(MAILBOX_GUARD_TIMEOUT_MS);
    assert.deepEqual(calls, ['from']);
});

test('an old item is blocked before the read even when the new item has the same sender', async () => {
    const { office, item, binding, calls } = fixture();
    office.context.mailbox.item = { ...item };
    await assert.rejects(assertMailboxBinding(office, item, binding), { code: 'COMPOSE_ITEM_CHANGED' });
    assert.deepEqual(calls, []);
});

test('an item switch during the From callback is blocked', async () => {
    let callback;
    const { office, item, binding, calls } = fixture({ invoke: (cb) => { callback = cb; } });
    const operation = assertMailboxBinding(office, item, binding);
    office.context.mailbox.item = { ...item };
    callback({ status: 'succeeded', value: { emailAddress: WORK } });
    await assert.rejects(operation, { code: 'COMPOSE_ITEM_CHANGED' });
    assert.deepEqual(calls, ['from']);
});

test('an item switch immediately after a successful callback still blocks approval', async () => {
    const fixtureData = fixture({ invoke: (callback) => {
        callback({ status: 'succeeded', value: { emailAddress: WORK } });
        fixtureData.office.context.mailbox.item = { ...fixtureData.item };
    } });
    const { office, item, binding, calls } = fixtureData;
    await assert.rejects(assertMailboxBinding(office, item, binding), { code: 'COMPOSE_ITEM_CHANGED' });
    assert.deepEqual(calls, ['from']);
});

test('a profile switch during the From callback is blocked', async () => {
    let callback;
    const { office, item, binding, calls } = fixture({ invoke: (cb) => { callback = cb; } });
    const operation = assertMailboxBinding(office, item, binding);
    office.context.mailbox.userProfile.emailAddress = PRIVATE;
    callback({ status: 'succeeded', value: { emailAddress: WORK } });
    await assert.rejects(operation, { code: 'MAILBOX_MISMATCH' });
    assert.deepEqual(calls, ['from']);
});

test('a previously approved item must read From again before the next mutation', async () => {
    let sender = WORK;
    const { office, item, binding, calls } = fixture({ invoke: (callback) => {
        callback({ status: 'succeeded', value: { emailAddress: sender } });
    } });
    await assertMailboxBinding(office, item, binding);
    sender = PRIVATE;
    await assert.rejects(assertMailboxBinding(office, item, binding), { code: 'SENDER_MISMATCH' });
    assert.deepEqual(calls, ['from', 'from']);
});

test('binding changes while From is pending cannot broaden the original approval', async () => {
    let callback;
    const { office, item, binding, calls } = fixture({ invoke: (cb) => { callback = cb; } });
    const operation = assertMailboxBinding(office, item, binding);
    binding.senderAddress = PRIVATE;
    binding.allowedSenderAddresses.push(PRIVATE);
    callback({ status: 'succeeded', value: { emailAddress: PRIVATE } });
    await assert.rejects(operation, { code: 'SENDER_MISMATCH' });
    assert.deepEqual(calls, ['from']);
});
