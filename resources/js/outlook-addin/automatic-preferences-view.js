export function normalizeAutomaticPreferences(value) {
    if (!value || typeof value !== 'object' || Array.isArray(value) || value.schema !== 2
        || typeof value.revision !== 'string' || !/^[a-f0-9]{64}$/i.test(value.revision)) return null;
    const normalized = { schema: 2, revision: value.revision.toLowerCase() };
    for (const group of ['newMessages', 'replies']) {
        if (Array.isArray(value[group]) || typeof value[group]?.signature !== 'boolean' || typeof value[group]?.template !== 'boolean') return null;
        normalized[group] = Object.freeze({ signature: value[group].signature, template: value[group].template });
    }
    return Object.freeze(normalized);
}

/** Account settings never inspect a selected message or use an Office body API. */
export function createAccountPreferencesSession({ readMailbox, authenticate, request }) {
    let snapshot = null;
    let busy = false;
    let generation = 0;
    const fail = code => Object.assign(new Error(code), { code });
    const mailbox = () => {
        const raw = readMailbox();
        if (typeof raw !== 'string' || /[\u0000-\u001f\u007f]/.test(raw)) throw fail('MAILBOX_ADDRESS_INVALID');
        const value = raw.trim().toLowerCase();
        if (value.length > 254 || !/^[^\s@<>,;:"\\()[\]*]+@[^\s@<>,;:"\\()[\]*]+$/u.test(value)) throw fail('MAILBOX_ADDRESS_INVALID');
        return value;
    };
    const receipt = (payload, account) => {
        const preferences = normalizeAutomaticPreferences(payload?.automaticInsertionPreferences);
        const binding = payload?.binding;
        if (!preferences || binding?.schema !== 1 || binding.mailboxAddress !== account || binding.senderAddress !== account
            || !Array.isArray(binding.allowedSenderAddresses) || !binding.allowedSenderAddresses.includes(account)) {
            throw fail('ACCOUNT_PREFERENCES_BINDING_INVALID');
        }
        return Object.freeze({ account, preferences });
    };
    const run = async (method, change, allowPopup) => {
        if (busy) throw fail('PREFERENCES_BUSY');
        let account;
        try { account = mailbox(); } catch (error) { snapshot = null; throw error; }
        if (snapshot && snapshot.account !== account) {
            snapshot = null;
            if (method === 'PATCH') throw fail('MAILBOX_MISMATCH');
        }
        const revision = generation;
        const assertCurrent = () => {
            if (generation !== revision || mailbox() !== account) throw fail('MAILBOX_MISMATCH');
        };
        if (method === 'PATCH' && (!snapshot || snapshot.account !== account
            || !['newMessages', 'replies'].includes(change?.group) || !['signature', 'template'].includes(change?.field)
            || typeof change.enabled !== 'boolean' || change.revision !== snapshot.preferences.revision)) throw fail('PREFERENCES_UNAVAILABLE');
        busy = true;
        try {
            const token = await authenticate(allowPopup);
            assertCurrent();
            if (typeof token !== 'string' || !token.trim()) throw fail('ACCESS_TOKEN_MISSING');
            const options = {
                method, cache: 'no-store', credentials: 'omit',
                headers: { Accept: 'application/json', Authorization: `Bearer ${token}`,
                    'X-RailTime-Outlook-Context': 'account-settings',
                    'X-RailTime-Compose-Contract': 'native-signature-v1', 'X-RailTime-Outlook-Mailbox': account },
            };
            if (method === 'PATCH') {
                options.headers['Content-Type'] = 'application/json';
                options.body = JSON.stringify({ group: change.group, field: change.field, enabled: change.enabled, revision: change.revision });
            }
            const payload = await request(options);
            assertCurrent();
            snapshot = receipt(payload, account);
            return payload;
        } catch (error) {
            try { assertCurrent(); } catch (changed) { snapshot = null; throw changed; }
            const current = normalizeAutomaticPreferences(error?.currentPreferences);
            if (current && snapshot?.account === account) snapshot = Object.freeze({ account, preferences: current });
            if (['HTTP_401', 'HTTP_403', 'ACCOUNT_PREFERENCES_BINDING_INVALID'].includes(error?.code)) snapshot = null;
            throw error;
        } finally { busy = false; }
    };
    return Object.freeze({
        load: (allowPopup = false) => run('GET', null, allowPopup),
        save: change => run('PATCH', change, false),
        current: () => snapshot,
        reset() { generation += 1; snapshot = null; },
    });
}

export function bindAutomaticPreferencesView(root, { save }) {
    const region = root.querySelector('[data-outlook-automatic-region]');
    if (!region) return { update() {} };
    const inputs = [...region.querySelectorAll('[data-automatic-setting]')];
    const feedback = region.querySelector('[data-automatic-feedback]');
    const accountLabel = region.querySelector('[data-automatic-account]');
    let state = { preferences: null, available: false, busy: false, account: '' };
    let saving = false;
    let optimistic = null;
    let generation = 0;
    let message = '';
    let tone = 'neutral';

    const canSave = () => state.available && state.preferences && !state.busy && !saving;
    const render = () => {
        region.setAttribute('aria-busy', saving ? 'true' : 'false');
        inputs.forEach((input) => {
            const { automaticGroup: group, automaticField: field } = input.dataset;
            input.checked = optimistic?.group === group && optimistic?.field === field
                ? optimistic.enabled : state.preferences?.[group]?.[field] === true;
            input.disabled = !canSave();
        });
        if (accountLabel) {
            accountLabel.textContent = state.account;
            accountLabel.hidden = !state.account;
        }
        if (feedback) {
            feedback.dataset.tone = saving ? 'neutral' : tone;
            feedback.textContent = saving ? 'Wird gespeichert …'
                : !state.available || !state.preferences ? 'Mit Microsoft verbinden, um Ihre Auswahl zu laden.'
                    : message || 'Ihre Auswahl wird automatisch gespeichert.';
        }
    };

    inputs.forEach((input) => input.addEventListener('change', async () => {
        const group = input.dataset.automaticGroup;
        const field = input.dataset.automaticField;
        if (!['newMessages', 'replies'].includes(group) || !['signature', 'template'].includes(field) || !canSave()) {
            render();
            return;
        }
        const enabled = input.checked;
        const expectedGeneration = generation;
        const previous = state.preferences;
        optimistic = { group, field, enabled };
        saving = true;
        render();
        try {
            const response = await save({ group, field, enabled, revision: previous.revision });
            const preferences = normalizeAutomaticPreferences(response?.automaticInsertionPreferences ?? response);
            if (!preferences) throw new Error('Invalid preferences response');
            if (generation !== expectedGeneration) return;
            state.preferences = preferences;
            tone = 'success';
            message = 'Gespeichert. Gilt für künftige Einfügungen.';
        } catch (error) {
            if (generation !== expectedGeneration) return;
            const current = normalizeAutomaticPreferences(error?.currentPreferences);
            state.preferences = current || previous;
            tone = 'error';
            message = current ? 'Die Auswahl wurde inzwischen geändert. Bitte erneut wählen.'
                : 'Speichern nicht bestätigt. Bitte die Verbindung prüfen und erneut versuchen.';
        } finally {
            saving = false;
            optimistic = null;
            render();
        }
    }));

    render();
    return {
        update({ preferences, available, busy, account = '' }) {
            if (account !== state.account) {
                generation += 1;
                message = '';
                tone = 'neutral';
                optimistic = null;
            }
            state = { preferences: normalizeAutomaticPreferences(preferences), available: available === true,
                busy: busy === true, account };
            render();
        },
    };
}
