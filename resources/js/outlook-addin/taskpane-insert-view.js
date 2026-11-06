// UI only: opening a tab or sheet never grants permission for an Office write.
export function isUnwrittenPreflight(report) {
    return report?.phase === 'preflight' && !['uncertain', 'pending', 'complete'].includes(report?.state)
        && report?.attempted === false
        && report?.headerWritten === false && report?.bodyWriteStarted === false
        && report?.bodyWriteSucceeded === false;
}

export function compactComposeMessage(code, report, fallback = '') {
    if (code === 'U2E_BUNDLE_STALE') return 'Bitte Outlook vollständig schließen und neu starten.';
    if (code === 'U2E_API_UNAVAILABLE') return 'Dieses Outlook unterstützt die Aktion nicht. Bitte die Nachricht im neuen Outlook oder am Desktop öffnen.';
    if (report?.step === 'snapshot.region' && isUnwrittenPreflight(report)) {
        return 'Einfügen gerade nicht möglich. Dein Text wurde nicht ersetzt; die Zitatgrenze ist nicht eindeutig.';
    }
    if (report?.bodyWriteStarted === true || report?.state === 'uncertain'
        || ['U2E_INSERT_UNCERTAIN', 'TEMPLATE_INSERT_UNCERTAIN', 'SIGNATURE_LAYOUT_UNCONFIRMED'].includes(code)) {
        return 'Einfügung konnte nicht bestätigt werden. Bitte sieh dir die Nachricht an.';
    }
    if (code === 'U2E_NEW_DRAFT_REQUIRED') return 'Einfügen ist in dieser Nachricht derzeit nicht möglich.';
    if (['ITEM_CHANGED', 'COMPOSE_ITEM_CHANGED', 'COMPOSE_BODY_CHANGED'].includes(code)) {
        return 'Die Nachricht hat sich geändert. Wähle die Vorlage für die aktuelle Nachricht erneut aus.';
    }
    if (isUnwrittenPreflight(report)) return 'Einfügen gerade nicht möglich. RailTime hat nichts eingefügt.';
    return fallback;
}

export function bindInsertView(root, { templates, capture, choose, failure, available = () => true }) {
    const tabs = [...root.querySelectorAll('[data-outlook-pane-tab]')];
    const panels = [...root.querySelectorAll('[data-outlook-pane-panel]')];
    const opener = root.querySelector('[data-outlook-insert-template]');
    const picker = root.querySelector('[data-outlook-template-picker]');
    const options = root.querySelector('[data-outlook-template-options]');
    const status = root.querySelector('[data-outlook-template-picker-status]');
    const close = root.querySelector('[data-outlook-template-picker-close]');
    if (!opener || !picker || !options) return null;
    let context = null, running = false;

    const tab = (name, focus = false) => {
        tabs.forEach(button => {
            const active = button.dataset.outlookPaneTab === name;
            button.setAttribute('aria-selected', String(active));
            button.tabIndex = active ? 0 : -1;
            if (active && focus) button.focus();
        });
        panels.forEach(panel => { panel.hidden = panel.dataset.outlookPanePanel !== name; });
    };
    tabs.forEach((button, index) => {
        button.addEventListener('click', () => tab(button.dataset.outlookPaneTab));
        button.addEventListener('keydown', event => {
            let next = index;
            if (event.key === 'ArrowRight') next = (index + 1) % tabs.length;
            else if (event.key === 'ArrowLeft') next = (index + tabs.length - 1) % tabs.length;
            else if (event.key === 'Home') next = 0;
            else if (event.key === 'End') next = tabs.length - 1;
            else return;
            event.preventDefault(); tab(tabs[next].dataset.outlookPaneTab, true);
        });
    });
    const message = value => { if (status) { status.textContent = value; status.hidden = !value; } };
    const refresh = () => {
        options.replaceChildren();
        for (const template of templates()) {
            const button = root.ownerDocument.createElement('button');
            button.type = 'button'; button.className = 'template-option';
            button.dataset.templateId = template.id;
            button.disabled = running || !available();
            const copy = root.ownerDocument.createElement('span'); copy.className = 'template-option-copy';
            const title = root.ownerDocument.createElement('strong'); title.className = 'template-option-title'; title.textContent = template.name;
            const detail = root.ownerDocument.createElement('span'); detail.className = 'template-option-detail'; detail.textContent = 'Mit deiner persönlichen Signatur';
            copy.append(title, detail); button.append(copy);
            button.addEventListener('click', async () => {
                if (running || !context || !available()) return;
                running = true; picker.setAttribute('aria-busy', 'true'); refresh(); message('Wird eingefügt …');
                try {
                    const result = await choose(template.id, context);
                    if (result?.applied) { picker.close(); tab('insert'); }
                    else message(result?.message || 'Einfügen gerade nicht möglich. Bitte schließe die Auswahl.');
                } catch (error) { const detail = failure(error); message(detail || 'Einfügen gerade nicht möglich. Bitte schließe die Auswahl.'); }
                finally {
                    running = false; picker.setAttribute('aria-busy', 'false'); refresh();
                    if (!picker.open) opener.focus({ preventScroll: true });
                    else if (status && !status.hidden) { status.tabIndex = -1; status.focus({ preventScroll: true }); }
                }
            });
            options.append(button);
        }
        if (!options.childElementCount) message('Keine Vorlage verfügbar.');
    };
    opener.addEventListener('click', () => {
        if (running || !available() || picker.open) return;
        try {
            context = capture(); refresh(); message(''); picker.showModal();
            options.querySelector('button:not(:disabled)')?.focus();
        } catch (error) { context = null; failure(error); }
    });
    close?.addEventListener('click', () => { if (!running) picker.close(); });
    picker.addEventListener('cancel', event => { if (running) event.preventDefault(); });
    picker.addEventListener('close', () => { context = null; if (!running) opener.focus({ preventScroll: true }); });
    tab('insert');
    return {
        refresh,
        reset() { if (picker.open && !running) picker.close(); context = null; tab('insert'); },
    };
}
