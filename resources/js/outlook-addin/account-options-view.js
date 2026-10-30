const GROUPS = ['newMessages', 'replies'];
const REVISION = /^[a-f0-9]{64}$/;
const UUID = /^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/i;
const fail = code => Object.assign(new Error(code), { code });
const WORDING_VALIDATION_FEEDBACK = 'Anrede und Verabschiedung können so nicht sicher in die mobile Signatur übernommen werden. Bitte die Texte kürzen oder einen Schalter ausschalten. Ihre Eingabe und die gespeicherten Werte bleiben erhalten.';

export function validWordingText(value) {
    return typeof value === 'string' && value.trim() !== '' && [...value].length <= 120
        && !/[\u0000-\u001f\u007f-\u009f<>]|\{\{|\}\}/u.test(value);
}

export function normalizeWordingPreferences(value) {
    if (value?.schema !== 1 || !REVISION.test(value.revision || '')
        || !validWordingText(value.greeting) || !validWordingText(value.farewell)) return null;
    if (['showGreeting', 'showFarewell'].some(key => key in value && typeof value[key] !== 'boolean')) return null;
    return Object.freeze({ schema: 1, greeting: value.greeting, farewell: value.farewell,
        showGreeting: value.showGreeting ?? false, showFarewell: value.showFarewell ?? false, revision: value.revision });
}

export function normalizeTemplateChoicePreferences(value) {
    if (value?.schema !== 1 || !REVISION.test(value.revision || '') || !Array.isArray(value.templates)) return null;
    const ids = new Set();
    const templates = [];
    for (const entry of value.templates) {
        if (!UUID.test(entry?.id || '') || ids.has(entry.id) || typeof entry.name !== 'string'
            || !entry.name.trim() || entry.name.length > 255 || /[\u0000-\u001f\u007f]/.test(entry.name)) return null;
        ids.add(entry.id);
        templates.push(Object.freeze({ id: entry.id, name: entry.name, isDefault: entry.isDefault === true }));
    }
    const result = { schema: 1, revision: value.revision, templates: Object.freeze(templates) };
    for (const group of GROUPS) {
        const choice = value[group];
        if (!choice || typeof choice.available !== 'boolean'
            || !['default', 'none'].includes(choice.selection) && !UUID.test(choice.selection || '')
            || choice.available && !['default', 'none'].includes(choice.selection) && !ids.has(choice.selection)) return null;
        result[group] = Object.freeze({ selection: choice.selection, available: choice.available });
    }
    return Object.freeze(result);
}

/** Only the signed-in Office mailbox is read. No selected item or message body is used. */
export function createAccountOptionsSession({ kind, readMailbox, authenticate, request }) {
    if (!['wording', 'choices'].includes(kind)) throw fail('PREFERENCES_INVALID');
    const key = kind === 'wording' ? 'wordingPreferences' : 'templateChoicesPreferences';
    const normalize = kind === 'wording' ? normalizeWordingPreferences : normalizeTemplateChoicePreferences;
    let snapshot = null;
    let busy = false;
    let generation = 0;
    const account = () => {
        const raw = readMailbox();
        if (typeof raw !== 'string' || /[\u0000-\u0020\u007f<>(),;:"\\]/.test(raw)
            || raw.length > 254 || !/^[^@]+@[^@]+\.[^@]+$/.test(raw)) throw fail('MAILBOX_ADDRESS_INVALID');
        return raw.toLowerCase();
    };
    const receipt = (payload, mailbox) => {
        const preferences = normalize(payload?.[key]);
        const binding = payload?.binding;
        if (!preferences || binding?.schema !== 1 || binding.mailboxAddress !== mailbox || binding.senderAddress !== mailbox
            || !Array.isArray(binding.allowedSenderAddresses) || !binding.allowedSenderAddresses.includes(mailbox)) {
            throw fail('ACCOUNT_PREFERENCES_BINDING_INVALID');
        }
        return Object.freeze({ account: mailbox, preferences });
    };
    const run = async (method, change, allowPopup) => {
        if (busy) throw fail('PREFERENCES_BUSY');
        let mailbox;
        try { mailbox = account(); } catch (error) { snapshot = null; throw error; }
        if (snapshot && snapshot.account !== mailbox) snapshot = null;
        const version = generation;
        const assertCurrent = () => { if (version !== generation || account() !== mailbox) throw fail('MAILBOX_MISMATCH'); };
        if (method === 'PATCH') {
            if (kind === 'wording' && ['showGreeting', 'showFarewell'].some(key => key in (change || {}) && typeof change[key] !== 'boolean')) throw fail('PREFERENCES_INVALID');
            if (!snapshot || change?.revision !== snapshot.preferences.revision) throw fail('PREFERENCES_UNAVAILABLE');
            if (kind === 'wording' ? !validWordingText(change.greeting) || !validWordingText(change.farewell)
                : !GROUPS.includes(change.group) || !['default', 'none'].includes(change.selection)
                    && !snapshot.preferences.templates.some(entry => entry.id === change.selection)) throw fail('PREFERENCES_INVALID');
        }
        busy = true;
        try {
            const token = await authenticate(allowPopup);
            assertCurrent();
            if (typeof token !== 'string' || !token.trim()) throw fail('ACCESS_TOKEN_MISSING');
            const options = { method, cache: 'no-store', credentials: 'omit', headers: { Accept: 'application/json',
                Authorization: `Bearer ${token}`, 'X-RailTime-Outlook-Context': 'account-settings',
                'X-RailTime-Compose-Contract': 'native-signature-v1', 'X-RailTime-Outlook-Mailbox': mailbox } };
            if (method === 'PATCH') {
                options.headers['Content-Type'] = 'application/json';
                options.body = JSON.stringify(kind === 'wording'
                    ? { greeting: change.greeting, farewell: change.farewell, revision: change.revision,
                        ...Object.fromEntries(['showGreeting', 'showFarewell'].filter(key => key in change).map(key => [key, change[key]])) }
                    : { group: change.group, selection: change.selection, revision: change.revision });
            }
            const payload = await request(options);
            assertCurrent();
            snapshot = receipt(payload, mailbox);
            return payload;
        } catch (error) {
            try { assertCurrent(); } catch (changed) { snapshot = null; throw changed; }
            if (error?.code === 'HTTP_409' && error.currentAccountOptions) {
                try { snapshot = receipt(error.currentAccountOptions, mailbox); }
                catch { snapshot = null; }
            }
            if (['HTTP_401', 'HTTP_403', 'ACCOUNT_PREFERENCES_BINDING_INVALID'].includes(error?.code)) snapshot = null;
            throw error;
        } finally { busy = false; }
    };
    return Object.freeze({ load: (allowPopup = false) => run('GET', null, allowPopup), save: change => run('PATCH', change, false),
        current: () => snapshot, reset() { generation += 1; snapshot = null; } });
}

export function bindWordingPreferencesView(root, { save, reload, beforeSave = () => null, afterSaved = async () => '' }) {
    const region = root.querySelector('[data-outlook-wording-region]');
    if (!region) return { update() {}, unavailable() {} };
    const fields = Object.fromEntries(['greeting', 'farewell'].map(key => [key, region.querySelector(`[data-wording-field="${key}"]`)]));
    const toggles = [...region.querySelectorAll('[data-wording-toggle]')];
    const button = region.querySelector('[data-wording-save]');
    const reloadButton = region.querySelector('[data-wording-reload]');
    const feedback = region.querySelector('[data-wording-feedback]');
    let state = { preferences: null, available: false, busy: false, account: '' };
    let saving = false;
    let dirty = false;
    let conflict = false;
    let generation = 0;
    const message = (text, error = false) => {
        feedback.textContent = text;
        feedback.dataset.tone = error ? 'error' : 'neutral';
        feedback.setAttribute('role', error ? 'alert' : 'status');
    };
    const render = () => {
        const available = state.available && Boolean(state.preferences);
        region.setAttribute('aria-busy', saving || state.busy ? 'true' : 'false');
        Object.values(fields).forEach(input => {
            input.disabled = !available || saving || state.busy;
            input.setAttribute('aria-invalid', dirty && !validWordingText(input.value) ? 'true' : 'false');
        });
        toggles.forEach(toggle => {
            toggle.disabled = !available || saving || state.busy || conflict;
            toggle.setAttribute('aria-pressed', state.preferences?.[toggle.dataset.wordingToggle] === true ? 'true' : 'false');
        });
        button.disabled = !available || saving || state.busy || conflict || !dirty
            || !Object.values(fields).every(input => validWordingText(input.value));
        reloadButton.hidden = !conflict;
        reloadButton.disabled = saving || state.busy;
    };
    Object.values(fields).forEach(input => input.addEventListener('input', () => {
        dirty = true;
        if (!conflict) message(Object.values(fields).every(field => validWordingText(field.value)) ? ''
            : 'Bitte bis zu 120 Zeichen pro Feld ohne HTML oder Platzhalter eingeben.', true);
        render();
    }));
    button.addEventListener('click', async () => {
        if (button.disabled) return;
        const version = generation;
        const operationContext = { account: state.account, target: beforeSave() };
        saving = true;
        render();
        message('Wird gespeichert …');
        try {
            const response = await save({ greeting: fields.greeting.value, farewell: fields.farewell.value, revision: state.preferences.revision,
                showGreeting: state.preferences.showGreeting, showFarewell: state.preferences.showFarewell });
            if (version !== generation) return;
            const preferences = normalizeWordingPreferences(response?.wordingPreferences);
            if (!preferences) throw fail('PREFERENCES_RESPONSE_INVALID');
            state.preferences = preferences;
            fields.greeting.value = preferences.greeting;
            fields.farewell.value = preferences.farewell;
            dirty = false;
            conflict = false;
            const applied = await afterSaved(operationContext);
            if (version === generation) message(applied || 'Texte gespeichert.');
        } catch (error) {
            if (version !== generation) return;
            conflict = error?.code === 'HTTP_409';
            message(conflict ? 'Inzwischen geändert. Ihre Eingabe bleibt erhalten. Zum Weiterbearbeiten den gespeicherten Stand laden.'
                : error?.code === 'HTTP_422' ? WORDING_VALIDATION_FEEDBACK : 'Speichern nicht bestätigt. Ihre Eingabe bleibt erhalten.', true);
        } finally { saving = false; render(); }
    });
    toggles.forEach(toggle => toggle.addEventListener('click', async () => {
        if (toggle.disabled) return;
        const key = toggle.dataset.wordingToggle;
        if (!['showGreeting', 'showFarewell'].includes(key)) return;
        const version = generation;
        const operationContext = { account: state.account, target: beforeSave() };
        saving = true;
        render();
        message('Wird gespeichert …');
        try {
            const response = await save({ greeting: state.preferences.greeting, farewell: state.preferences.farewell,
                revision: state.preferences.revision, showGreeting: state.preferences.showGreeting,
                showFarewell: state.preferences.showFarewell, [key]: !state.preferences[key] });
            if (version !== generation) return;
            const preferences = normalizeWordingPreferences(response?.wordingPreferences);
            if (!preferences) throw fail('PREFERENCES_RESPONSE_INVALID');
            state.preferences = preferences;
            conflict = false;
            const applied = await afterSaved(operationContext);
            if (version === generation) message(applied || 'Anzeige gespeichert.');
        } catch (error) {
            if (version !== generation) return;
            conflict = error?.code === 'HTTP_409';
            message(conflict ? 'Inzwischen geändert. Ihre Eingabe bleibt erhalten. Bitte den gespeicherten Stand laden.'
                : error?.code === 'HTTP_422' ? WORDING_VALIDATION_FEEDBACK : 'Anzeige konnte nicht bestätigt werden. Ihre Texte bleiben erhalten.', true);
        } finally { saving = false; render(); }
    }));
    reloadButton.addEventListener('click', async () => {
        if (reloadButton.disabled) return;
        const version = generation;
        saving = true;
        render();
        try {
            const response = await reload();
            if (version !== generation) return;
            const preferences = normalizeWordingPreferences(response?.wordingPreferences);
            if (!preferences) throw fail('PREFERENCES_RESPONSE_INVALID');
            state.preferences = preferences;
            fields.greeting.value = preferences.greeting;
            fields.farewell.value = preferences.farewell;
            dirty = false;
            conflict = false;
            message('Gespeicherte Texte geladen.');
        } catch { if (version === generation) message('Texte konnten nicht geladen werden.', true); }
        finally { saving = false; render(); }
    });
    render();
    return { update(next) {
        const changed = next.account !== state.account;
        const previous = state.preferences;
        if (changed) { generation += 1; dirty = false; conflict = false; Object.values(fields).forEach(input => { input.value = ''; }); }
        const incoming = normalizeWordingPreferences(next.preferences);
        const editing = !changed && (dirty || saving) && previous;
        if (editing && incoming && incoming.revision !== previous.revision) {
            conflict = true;
            message('Inzwischen geändert. Ihre Eingabe bleibt erhalten. Zum Weiterbearbeiten den gespeicherten Stand laden.', true);
        }
        state = { ...next, preferences: editing ? previous : incoming };
        if (state.preferences && (changed || !dirty && !saving)) {
            fields.greeting.value = state.preferences.greeting;
            fields.farewell.value = state.preferences.farewell;
            message('');
        }
        render();
    }, unavailable() { state.available = false; message('Texte nicht geladen. Bitte mit Microsoft verbinden.', true); render(); } };
}

export function bindTemplateChoiceView(root, { save }) {
    const region = root.querySelector('[data-outlook-choices-region]');
    if (!region) return { update() {} };
    const inputs = [...region.querySelectorAll('[data-template-choice-group]')];
    const feedback = region.querySelector('[data-template-choice-feedback]');
    let state = { preferences: null, account: '', available: false, busy: false };
    let saving = false;
    let generation = 0;
    let message = '';
    let failed = false;
    const render = () => {
        inputs.forEach(input => {
            const preferences = state.preferences;
            const selected = preferences?.[input.dataset.templateChoiceGroup];
            const defaults = preferences?.templates.filter(entry => entry.isDefault) || [];
            const defaultName = defaults.length === 1 ? defaults[0].name : '';
            const options = preferences ? [{ id: 'none', name: 'Keine Vorlage' }, { id: 'default', name: defaultName ? `Standardvorlage – ${defaultName}` : 'Standardvorlage', disabled: defaults.length !== 1 },
                ...preferences.templates.map(entry => ({ id: entry.id, name: entry.name }))] : [{ id: '', name: 'Auswahl nicht geladen', disabled: true }];
            if (selected && !selected.available) {
                const existing = options.find(option => option.id === selected.selection);
                if (existing) { existing.name += ' – nicht verfügbar'; existing.disabled = true; }
                else options.push({ id: selected.selection, name: 'Nicht mehr verfügbar', disabled: true });
            }
            input.replaceChildren(...options.map(entry => {
                const option = input.ownerDocument.createElement('option');
                option.value = entry.id;
                option.textContent = entry.name;
                option.disabled = entry.disabled === true;
                option.selected = entry.id === (selected?.selection || '');
                return option;
            }));
            input.disabled = !state.available || !preferences || state.busy || saving;
        });
        if (feedback) {
            feedback.textContent = saving ? 'Wird gespeichert …' : message || (!state.available ? 'Vorlagenauswahl nicht geladen.' : '');
            feedback.dataset.tone = failed ? 'error' : 'neutral';
            feedback.setAttribute('role', failed ? 'alert' : 'status');
        }
    };
    inputs.forEach(input => input.addEventListener('change', async () => {
        if (input.disabled) return;
        const version = generation;
        const change = { group: input.dataset.templateChoiceGroup, selection: input.value, revision: state.preferences.revision };
        saving = true;
        render();
        try {
            const payload = await save(change);
            if (version !== generation) return;
            const preferences = normalizeTemplateChoicePreferences(payload?.templateChoicesPreferences);
            if (!preferences) throw fail('PREFERENCES_RESPONSE_INVALID');
            state.preferences = preferences;
            failed = false;
            message = 'Auswahl gespeichert.';
        } catch (error) {
            if (version !== generation) return;
            const current = normalizeTemplateChoicePreferences(error?.currentAccountOptions?.templateChoicesPreferences);
            if (current) state.preferences = current;
            failed = true;
            message = current ? 'Inzwischen geändert. Bitte erneut auswählen.' : 'Speichern nicht bestätigt. Bitte erneut versuchen.';
        } finally { saving = false; render(); }
    }));
    render();
    return { update(next) {
        if (next.account !== state.account) { generation += 1; message = ''; failed = false; }
        state = { ...next, preferences: normalizeTemplateChoicePreferences(next.preferences) };
        render();
    } };
}
