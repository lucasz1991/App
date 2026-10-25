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
