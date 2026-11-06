@php
    $resolvedConfigUrl = (string) ($configUrl ?? $outlookAddinConfigUrl ?? '');
    $resolvedScriptUrl = (string) ($scriptUrl ?? asset('outlook-addin/taskpane.js'));
    $resolvedNonce = (string) ($cspNonce ?? '');
    $accountSettingsMode = request()->query('context') === 'settings';
@endphp
<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-outlook-config-url="{{ $resolvedConfigUrl }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light dark">
    <meta name="railtime-outlook-config-url" content="{{ $resolvedConfigUrl }}">
    <title>RailTime</title>
    <script src="https://appsforoffice.microsoft.com/lib/1/hosted/office.js" type="text/javascript"></script>
    <script src="{{ $resolvedScriptUrl }}" type="text/javascript" defer></script>
    <style @if ($resolvedNonce !== '') nonce="{{ $resolvedNonce }}" @endif>

        :root {
            color-scheme: light dark;
            font-family: "Segoe UI", Arial, sans-serif;
            --rt-red: #e4002b; --rt-red-strong: #bd001f;
            --rt-ink: #15263c; --rt-muted: #526176; --rt-line: #d5dfe9;
            --rt-surface: #fff; --rt-subtle: #f0f4f8; --rt-selected: #fff;
            --rt-success: #006b50; --rt-success-soft: #e9f7f2;
            --rt-warning: #805000; --rt-warning-soft: #fff5df;
            --rt-error: #ab201b; --rt-error-soft: #fff0ef; --rt-focus: #376fbe;
        }
        * { box-sizing: border-box; }
        [hidden] { display: none !important; }
        html, body { min-height: 100%; }
        body { margin: 0; background: var(--rt-surface); color: var(--rt-ink); font-size: 14px; line-height: 1.5; -webkit-font-smoothing: antialiased; }
        button, select, input { font: inherit; }
        button, select { -webkit-tap-highlight-color: transparent; }
        button { cursor: pointer; }
        button:disabled, select:disabled, input:disabled { cursor: not-allowed; }
        button:focus-visible, select:focus-visible, input:focus-visible, summary:focus-visible, a:focus-visible, [tabindex="-1"]:focus-visible { outline: 3px solid var(--rt-focus); outline-offset: 3px; }
        .shell { min-height: 100vh; }
        .panel { width: 100%; max-width: 480px; min-height: 100vh; margin: 0 auto; }
        .content { min-height: 100vh; padding: 24px 16px; }
        .pane-heading { display: flex; align-items: center; gap: 12px; margin-bottom: 24px; min-width: 0; }
        .pane-brand { width: 5px; height: 32px; border-radius: 3px; background: var(--rt-red); flex: 0 0 auto; }
        .pane-heading > div { min-width: 0; }
        .pane-heading h1 { margin: 0; font-size: 23px; line-height: 1.25; font-weight: 650; letter-spacing: -.025em; }
        .pane-heading p, .account { margin: 4px 0 0; color: var(--rt-muted); font-size: 12px; overflow-wrap: anywhere; }
        .pane-tabs { display: flex; gap: 4px; padding: 4px; border-radius: 12px; background: var(--rt-subtle); }
        .pane-tab { flex: 1 1 50%; min-width: 0; min-height: 44px; border: 1px solid transparent; border-radius: 9px; background: transparent; color: var(--rt-muted); font-size: 15px; font-weight: 500; }
        .pane-tab[aria-selected="true"] { background: var(--rt-selected); color: var(--rt-ink); font-weight: 650; box-shadow: 0 1px 4px #15263c14; }
        .pane-tab:hover:not([aria-selected="true"]) { color: var(--rt-ink); }
        .insert-panel { padding-top: 28px; }
        .template-card { min-width: 0; }
        .section-heading h2 { margin: 0; font-size: 21px; line-height: 1.3; font-weight: 650; letter-spacing: -.025em; }
        .section-heading p { margin: 8px 0 0; color: var(--rt-muted); font-size: 14px; }
        .template-ready { margin-top: 26px; }
        .action { display: flex; align-items: center; justify-content: center; width: 100%; gap: 9px; min-height: 48px; padding: 12px; border: 1px solid var(--rt-line); border-radius: 11px; background: var(--rt-subtle); color: var(--rt-ink); text-align: left; }
        .action:hover:not(:disabled) { border-color: var(--rt-muted); }
        .action-primary { min-height: 54px; border-color: var(--rt-red); background: var(--rt-red); color: #fff; }
        .action-primary:hover:not(:disabled) { border-color: var(--rt-red-strong); background: var(--rt-red-strong); }
        .action:disabled { border-color: var(--rt-line); background: var(--rt-subtle); color: var(--rt-muted); }
        .action-copy { min-width: 0; }
        .action-copy strong { display: block; font-size: 14px; font-weight: 650; line-height: 1.35; }
        .action-copy > span { display: block; margin-top: 3px; font-size: 12px; font-weight: 400; }
        .action-icon { position: relative; display: inline-grid; width: 24px; height: 24px; flex: 0 0 24px; place-items: center; font-size: 25px; line-height: 1; }
        .action[data-busy="true"] .action-icon { color: transparent; }
        .action[data-busy="true"] .action-icon::after { position: absolute; width: 16px; height: 16px; border: 2px solid var(--rt-muted); border-right-color: transparent; border-radius: 50%; content: ""; animation: rt-spin .8s linear infinite; }
        .insert-hint { margin: 13px 0 0; color: var(--rt-muted); font-size: 12px; }
        .template-loading, .template-message { margin: 24px 0 0; color: var(--rt-muted); font-size: 13px; }
        .template-card:not([data-state="loading"]) .template-loading,
        .template-card:not([data-state="ready"]) .template-ready,
        .template-card:not([data-state="empty"]) .template-empty,
        .template-card:not([data-state="locked"]) .template-locked,
        .template-card:not([data-state="error"]) .template-error { display: none; }
        .pane-feedback { margin: 20px 0 0; padding: 12px 14px; border: 1px solid var(--rt-line); border-radius: 10px; background: var(--rt-subtle); color: var(--rt-ink); font-size: 13px; line-height: 1.5; overflow-wrap: anywhere; }
        .pane-feedback[data-tone="error"] { border-color: var(--rt-error); background: var(--rt-error-soft); color: var(--rt-error); }
        .pane-feedback[data-tone="warning"] { border-color: var(--rt-warning); background: var(--rt-warning-soft); color: var(--rt-warning); }
        .pane-feedback[data-tone="success"] { border-color: var(--rt-success); background: var(--rt-success-soft); color: var(--rt-success); }
        .settings-card { min-width: 0; padding-top: 24px; }
        .settings-section-heading { display: flex; flex-wrap: wrap; align-items: baseline; justify-content: space-between; gap: 4px 12px; margin-bottom: 16px; }
        .settings-section-heading h2 { margin: 0; font-size: 18px; font-weight: 650; }
        .save-hint { color: var(--rt-muted); font-size: 11px; }
        .automatic-account { margin: 0 0 12px; color: var(--rt-muted); font-size: 12px; overflow-wrap: anywhere; }
        .compose-options { min-width: 0; border: 1px solid var(--rt-line); border-radius: 12px; overflow: hidden; }
        .compose-option { margin: 0; padding: 14px; border: 0; min-width: 0; }
        .compose-option + .compose-option { border-top: 1px solid var(--rt-line); }
        .compose-option legend { float: left; width: 100%; padding: 0; margin-bottom: 6px; font-size: 14px; font-weight: 650; }
        .compose-option legend small { display: block; margin-top: 1px; color: var(--rt-muted); font-size: 12px; font-weight: 400; }
        .signature-option { display: flex; clear: both; align-items: center; justify-content: space-between; gap: 12px; min-height: 44px; font-size: 13px; }
        .automatic-region .rt-ui-toggle { position: relative; display: inline-flex; width: 48px; min-height: 44px; align-items: center; justify-content: center; cursor: pointer; }
        .automatic-region .rt-ui-toggle__input { position: absolute; inset: 0; width: 100%; height: 100%; margin: 0; opacity: 0; cursor: inherit; }
        .automatic-region .rt-ui-toggle-control { position: relative; display: block; width: 38px; height: 22px; flex: 0 0 auto; border: 0; border-radius: 999px; background: var(--rt-muted); box-shadow: none; pointer-events: none; transition: background-color 160ms ease-out; }
        .automatic-region .rt-ui-toggle-control::before { position: absolute; top: 3px; left: 3px; width: 16px; height: 16px; border-radius: 50%; background: #fff; content: ""; transition: transform 160ms ease-out; }
        .automatic-region .rt-ui-toggle-control::after { content: none; }
        .automatic-region input:checked + .rt-ui-toggle-control { background: var(--rt-red); }
        .automatic-region input:checked + .rt-ui-toggle-control::before { transform: translateX(16px); }
        .automatic-region input:focus-visible + .rt-ui-toggle-control { outline: 3px solid var(--rt-focus); outline-offset: 3px; }
        .automatic-region input:disabled { cursor: not-allowed; }
        .automatic-region input:disabled + .rt-ui-toggle-control { opacity: .55; }
        .settings-fields { display: grid; gap: 12px; margin-top: 10px; min-width: 0; }
        .settings-fields label { display: grid; gap: 6px; min-width: 0; font-size: 12px; font-weight: 500; }
        .settings-fields input, .template-select { display: block; width: 100%; min-width: 0; min-height: 44px; padding: 10px; border: 1px solid var(--rt-line); border-radius: 8px; background: var(--rt-subtle); color: var(--rt-ink); font: 400 16px/1.4 "Segoe UI", Arial, sans-serif; }
        .settings-fields input:disabled, .template-select:disabled { color: var(--rt-muted); }
        .automatic-feedback { margin: 8px 0 0; color: var(--rt-muted); font-size: 12px; line-height: 1.55; overflow-wrap: anywhere; }
        .automatic-feedback:empty { margin: 0; }
        .compose-options > .automatic-feedback:not(:empty) { margin: 0; padding: 0 14px 12px; }
        .automatic-feedback[data-tone="error"], .settings-feedback[data-tone="error"] { color: var(--rt-error); }
        .automatic-feedback[data-tone="success"] { color: var(--rt-success); }
        .settings-note { margin: 12px 0 0; color: var(--rt-muted); font-size: 12px; line-height: 1.55; overflow-wrap: anywhere; }
        .wording-region { margin-top: 20px; border-top: 1px solid var(--rt-line); }
        .wording-region summary { display: flex; align-items: center; justify-content: space-between; gap: 12px; min-height: 52px; cursor: pointer; font-size: 14px; font-weight: 600; list-style: none; }
        .wording-region summary::-webkit-details-marker { display: none; }
        .wording-region summary::after { content: ""; width: 7px; height: 7px; margin-right: 4px; flex: 0 0 auto; border-right: 1.5px solid var(--rt-muted); border-bottom: 1.5px solid var(--rt-muted); transform: rotate(45deg); }
        .wording-region details[open] > summary::after { transform: rotate(225deg); }
        .settings-buttons { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 12px; }
        .glass-button { display: inline-flex; align-items: center; justify-content: center; gap: 6px; min-height: 44px; padding: 9px 12px; border: 1px solid var(--rt-line); border-radius: 8px; background: var(--rt-subtle); color: var(--rt-ink); font-size: 12px; font-weight: 600; text-decoration: none; }
        .glass-button:hover:not(:disabled) { border-color: var(--rt-muted); }
        .glass-button:disabled { color: var(--rt-muted); }
        .wording-toggles button { flex: 1 1 120px; }
        .wording-toggles button[aria-pressed="true"] { border-color: var(--rt-muted); background: var(--rt-subtle); box-shadow: inset 0 0 0 1px var(--rt-muted); }
        .wording-toggles button[aria-pressed="true"]::before { content: "✓"; }
        .save-button { background: var(--rt-ink); border-color: var(--rt-ink); color: var(--rt-surface); }
        .save-button:disabled { background: var(--rt-subtle); border-color: var(--rt-line); color: var(--rt-muted); }
        .connection-state { margin-top: 20px; padding-top: 16px; border-top: 1px solid var(--rt-line); }
        .status { display: flex; align-items: flex-start; gap: 8px; font-size: 12px; color: var(--rt-muted); }
        .status strong { font-weight: 500; }
        .status-symbol { flex: 0 0 16px; }
        .status p { margin: 4px 0 0; }
        .maintenance-actions { margin-top: 10px; }
        .maintenance-actions .action-copy strong { font-size: 13px; }
        .maintenance-actions .action-copy span { color: var(--rt-muted); }
        .settings-feedback { margin: 12px 0; font-size: 13px; }
        .app-link { display: inline-flex; align-items: center; min-height: 44px; padding-block: 8px; color: var(--rt-ink); font-size: 12px; text-underline-offset: 3px; }
        .template-picker { position: fixed; inset: auto 0 0; width: min(100%, 480px); max-width: 100%; max-height: min(75vh, 600px); max-height: min(75dvh, 600px); margin: auto auto 0; padding: 12px 16px 24px; padding-bottom: max(24px, env(safe-area-inset-bottom)); border: 1px solid var(--rt-line); border-bottom: 0; border-radius: 22px 22px 0 0; background: var(--rt-surface); color: var(--rt-ink); box-shadow: 0 -16px 44px #0003; overflow: auto; overscroll-behavior: contain; }
        .template-picker::backdrop { background: #07111e99; }
        .sheet-grip { width: 44px; height: 4px; margin: 0 auto 12px; border-radius: 4px; background: var(--rt-muted); opacity: .65; }
        .sheet-heading { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 16px; }
        .sheet-heading h2 { margin: 0; font-size: 22px; line-height: 1.3; font-weight: 650; letter-spacing: -.025em; }
        .sheet-close { display: inline-grid; width: 44px; height: 44px; flex: 0 0 44px; padding: 0; place-items: center; border: 0; border-radius: 9px; background: transparent; color: var(--rt-ink); font-size: 29px; line-height: 1; }
        .sheet-close:hover:not(:disabled) { background: var(--rt-subtle); }
        .template-options { display: grid; gap: 10px; }
        .template-option { display: flex; align-items: center; width: 100%; min-height: 74px; gap: 12px; padding: 14px; border: 1px solid var(--rt-line); border-radius: 12px; background: var(--rt-subtle); color: var(--rt-ink); text-align: left; }
        .template-option:hover:not(:disabled) { border-color: var(--rt-muted); }
        .template-option:disabled { color: var(--rt-muted); }
        .template-option-icon { display: inline-grid; width: 30px; flex: 0 0 30px; place-items: center; font-size: 25px; }
        .template-option-copy { display: block; flex: 1 1 auto; min-width: 0; }
        .template-option-title { display: block; font-size: 16px; font-weight: 650; overflow-wrap: anywhere; }
        .template-option-detail { display: block; margin-top: 3px; font-size: 12px; line-height: 1.5; color: var(--rt-muted); overflow-wrap: anywhere; }
        .template-option-arrow { flex: 0 0 12px; font-size: 26px; line-height: 1; }
        .sheet-hint { margin: 14px 0 0; color: var(--rt-muted); font-size: 13px; }
        .sheet-footnote { margin: 30px 0 0; padding-top: 16px; border-top: 1px solid var(--rt-line); color: var(--rt-muted); font-size: 12px; }
        .sheet-status:empty { display: none; }
        .sheet-status { margin: 12px 0; font-size: 13px; color: var(--rt-muted); }
        .visually-hidden { position: absolute; width: 1px; height: 1px; padding: 0; overflow: hidden; clip-path: inset(50%); white-space: nowrap; }
        @keyframes rt-spin { to { transform: rotate(360deg); } }
        @media (prefers-color-scheme: dark) {
            :root {
                --rt-ink: #f1f5fa; --rt-muted: #b9c6d7; --rt-line: #34465d;
                --rt-surface: #102033; --rt-subtle: #1d3046; --rt-selected: #3e5570;
                --rt-success: #90dfbd; --rt-success-soft: #153b32;
                --rt-warning: #ffd18a; --rt-warning-soft: #3e3020;
                --rt-error: #ffb4ab; --rt-error-soft: #3e252b; --rt-focus: #9ccaff;
            }
            .pane-tab[aria-selected="true"] { box-shadow: none; }
            .template-picker { background: #192c42; }
            .template-option { background: #2c425a; }
        }
        @media (max-width: 340px) {
            .content { padding: 20px 12px; }
            .template-picker { padding-inline: 12px; }
            .action-copy strong { font-size: 13px; }
        }
        @media (prefers-reduced-motion: reduce) {
            *, *::before, *::after { scroll-behavior: auto !important; transition: none !important; animation-duration: .01ms !important; animation-iteration-count: 1 !important; }
        }
        @media (forced-colors: active) {
            .pane-tab[aria-selected="true"] { border-color: Highlight; }
            .action, .glass-button, .template-option, .template-picker { border: 1px solid ButtonText; }
            .automatic-region .rt-ui-toggle-control { forced-color-adjust: none; background: GrayText; }
            .automatic-region input:checked + .rt-ui-toggle-control { background: Highlight; }
            .automatic-region .rt-ui-toggle-control::before { background: Canvas; }
        }
    </style>
</head>
<body data-outlook-config-url="{{ $resolvedConfigUrl }}">
    <main class="shell" data-outlook-addin-taskpane data-outlook-context="{{ $accountSettingsMode ? 'settings' : 'compose' }}" aria-busy="true">
        <section class="panel" aria-labelledby="outlook-addin-heading">
            <div class="content">
                <header class="pane-heading">
                    <span class="pane-brand" aria-hidden="true"></span>
                    <div>
                        <h1 id="outlook-addin-heading">{{ $accountSettingsMode ? 'Outlook-Einstellungen' : 'RailTime' }}</h1>
                        @if ($accountSettingsMode)
                        <p>Dein RailTime-Konto in Outlook</p>
                        @else
                        <p class="account" data-outlook-account hidden><span data-outlook-account-label></span></p>
                        @endif
                    </div>
                </header>
                @if ($accountSettingsMode)
                <p class="settings-feedback" data-outlook-settings-status role="status" aria-live="polite">Die sichere Verbindung wird geprüft.</p>
                <button type="button" class="glass-button" data-outlook-settings-connect disabled>Mit Microsoft verbinden</button>
                <a class="app-link" data-outlook-profile-settings href="{{ route('profile.show', ['tab' => 'outlook']) }}" target="_blank" rel="noopener noreferrer" hidden>Im RailTime-Profil öffnen ↗</a>
                @else
                <div class="pane-tabs" role="tablist" aria-label="RailTime-Bereich">
                    <button type="button" id="outlook-tab-insert" class="pane-tab" role="tab" data-outlook-pane-tab="insert" aria-controls="outlook-pane-insert" aria-selected="true" tabindex="0">Einfügen</button>
                    <button type="button" id="outlook-tab-automatic" class="pane-tab" role="tab" data-outlook-pane-tab="automatic" aria-controls="outlook-pane-automatic" aria-selected="false" tabindex="-1">Automatik</button>
                </div>
                <p class="pane-feedback" data-outlook-pane-feedback role="status" aria-live="polite" aria-atomic="true" tabindex="-1" hidden></p>
                <section id="outlook-pane-insert" class="insert-panel" role="tabpanel" data-outlook-pane-panel="insert" aria-labelledby="outlook-tab-insert" tabindex="0">
                    <section class="template-card" data-outlook-template-region data-state="loading" aria-labelledby="outlook-template-heading">
                        <div class="section-heading">
                            <h2 id="outlook-template-heading">Signatur &amp; Vorlage</h2>
                            <p>Dein Mailtext im RailTime-Design.</p>
                        </div>
                        <p class="template-loading" data-outlook-template-loading>Vorlagen werden geladen …</p>
                        <div class="template-ready" data-outlook-template-ready>
                            <button class="action action-primary" type="button" data-outlook-insert-template data-outlook-action="template" data-available="false" data-busy="false" aria-haspopup="dialog" aria-controls="outlook-template-picker" aria-describedby="outlook-insert-hint" disabled>
                                <span class="action-icon" aria-hidden="true">＋</span>
                                <span class="action-copy"><strong>Signatur &amp; Vorlage einfügen</strong><span data-outlook-template-action-detail hidden></span></span>
                            </button>
                            <p id="outlook-insert-hint" class="insert-hint">Ersetzt deinen Text. Zitat bleibt.</p>
                        </div>
                        <p class="template-message template-empty" data-outlook-template-empty>Momentan ist keine veröffentlichte Vorlage verfügbar.</p>
                        <p class="template-message template-locked" data-outlook-template-locked>Melde dich unter „Automatik“ mit deinem Microsoft-Konto an.</p>
                        <p class="template-message template-error" data-outlook-template-error>Die Vorlagen konnten nicht geladen werden. Öffne „Automatik“ für den Verbindungsstatus.</p>
                        <div hidden inert aria-hidden="true">
                            <span data-outlook-template-count>Laden …</span>
                            <label for="outlook-template-select">Vorlage</label>
                            <select id="outlook-template-select" class="template-select" data-outlook-template-select disabled><option value="">Vorlagen werden geladen …</option></select>
                            <div id="outlook-template-meta" data-outlook-template-meta hidden aria-hidden="true">
                                <strong data-outlook-template-name>Keine Vorlage ausgewählt</strong>
                                <span data-outlook-template-version>Version nicht verfügbar</span>
                                <span data-outlook-template-active hidden>Aktiv</span>
                            </div>
                        </div>
                    </section>
                </section>
                @endif

                <section class="settings-card" @unless ($accountSettingsMode) id="outlook-pane-automatic" role="tabpanel" data-outlook-pane-panel="automatic" aria-labelledby="outlook-tab-automatic" tabindex="0" hidden @else aria-labelledby="outlook-settings-heading" @endunless>
                    <div class="settings-section-heading"><h2 id="outlook-settings-heading">Automatisch einfügen</h2><span class="save-hint">Pro Konto gespeichert</span></div>
                    <div data-outlook-automatic-region class="automatic-region" aria-busy="false">
                        <p class="automatic-account" data-automatic-account hidden></p>
                        <div class="compose-options" data-outlook-choices-region>
                            <fieldset class="compose-option">
                                <legend>Neue Nachrichten <small>Auch für Weiterleitungen</small></legend>
                                <div class="signature-option"><label for="outlook-auto-new-signature">Signatur einfügen</label>
                                <x-ui.forms.toggle-button id="outlook-auto-new-signature" size="sm" :disabled="true" data-automatic-setting data-automatic-group="newMessages" data-automatic-field="signature" aria-label="Signatur bei neuen Mails und Weiterleitungen automatisch einfügen" />
                                </div>
                                <div class="settings-fields"><label for="outlook-auto-new-template">Vorlage
                                    <select id="outlook-auto-new-template" class="template-select" data-template-choice-group="newMessages" disabled aria-label="Vorlage für neue Nachrichten und Weiterleitungen" aria-describedby="outlook-choice-feedback"><option value="">Wird geladen …</option></select>
                                </label></div>
                            </fieldset>
                            <fieldset class="compose-option">
                                <legend>Antworten</legend>
                                <div class="signature-option"><label for="outlook-auto-reply-signature">Signatur einfügen</label>
                                <x-ui.forms.toggle-button id="outlook-auto-reply-signature" size="sm" :disabled="true" data-automatic-setting data-automatic-group="replies" data-automatic-field="signature" aria-label="Signatur bei Antworten automatisch einfügen" />
                                </div>
                                <div class="settings-fields"><label for="outlook-auto-reply-template">Vorlage
                                    <select id="outlook-auto-reply-template" class="template-select" data-template-choice-group="replies" disabled aria-label="Vorlage für Antworten" aria-describedby="outlook-choice-feedback"><option value="">Wird geladen …</option></select>
                                </label></div>
                            </fieldset>
                            <p id="outlook-choice-feedback" class="automatic-feedback" data-template-choice-feedback role="status" aria-live="polite"></p>
                        </div>
                        <p class="automatic-feedback" data-automatic-feedback role="status" aria-live="polite"></p>
                    </div>
                    <p class="settings-note settings-help">Gilt beim nächsten Verfassen. Bereits geöffnete Nachrichten bleiben unverändert.</p>
                    <section class="wording-region" data-outlook-wording-region aria-busy="false" aria-labelledby="outlook-wording-heading">
                        <details>
                        <summary id="outlook-wording-heading">Persönliche Texte</summary>
                        <div class="settings-buttons wording-toggles">
                            <button type="button" class="glass-button" data-wording-toggle="showGreeting" aria-pressed="false" disabled>Anrede anzeigen</button>
                            <button type="button" class="glass-button" data-wording-toggle="showFarewell" aria-pressed="false" disabled>Verabschiedung anzeigen</button>
                        </div>
                        <p class="settings-note" id="outlook-wording-note">Gilt beim nächsten Einfügen der Standardvorlage. Der Name wird nicht unter dem Gruß ergänzt.</p>
                        <div class="settings-fields">
                            <label for="outlook-wording-greeting">Anrede
                                <input id="outlook-wording-greeting" data-wording-field="greeting" type="text" maxlength="120" autocomplete="off" disabled aria-describedby="outlook-wording-note outlook-wording-feedback">
                            </label>
                            <label for="outlook-wording-farewell">Gruß
                                <input id="outlook-wording-farewell" data-wording-field="farewell" type="text" maxlength="120" autocomplete="off" disabled aria-describedby="outlook-wording-note outlook-wording-feedback">
                            </label>
                        </div>
                        <div class="settings-buttons">
                            <button type="button" class="glass-button save-button" data-wording-save disabled>Texte speichern</button>
                            <button type="button" class="glass-button" data-wording-reload hidden>Gespeicherten Stand laden</button>
                        </div>
                        </details>
                        <p id="outlook-wording-feedback" class="automatic-feedback" data-wording-feedback role="status" aria-live="polite"></p>
                    </section>
                    @unless ($accountSettingsMode)
                    <div class="connection-state">
                        <div class="status" data-outlook-status data-tone="working" tabindex="-1" hidden inert aria-hidden="true">
                            <span class="status-symbol" data-outlook-status-symbol aria-hidden="true">…</span>
                            <div><strong data-outlook-status-title>Outlook wird vorbereitet …</strong><p data-outlook-status-detail>Die sichere Verbindung wird geprüft.</p></div>
                        </div>
                        <div class="maintenance-actions" data-outlook-maintenance-actions>
                            <button type="button" class="action" data-outlook-action="login" data-available="false" data-busy="false" disabled>
                                <span class="action-copy"><strong data-outlook-login-title>Mit Microsoft verbinden</strong><span data-outlook-login-detail>Mit dem Firmenkonto anmelden</span></span><span class="action-icon" aria-hidden="true">→</span>
                            </button>

                        </div>

                    </div>
                    @endunless
                </section>

                @unless ($accountSettingsMode)
                <dialog id="outlook-template-picker" class="template-picker" data-outlook-template-picker aria-labelledby="outlook-template-picker-heading" aria-describedby="outlook-template-picker-hint">
                    <div class="sheet-grip" aria-hidden="true"></div>
                    <div class="sheet-heading">
                        <h2 id="outlook-template-picker-heading">Vorlage wählen</h2>
                        <button type="button" class="sheet-close" data-outlook-template-picker-close aria-label="Vorlagenauswahl schließen" autofocus>×</button>
                    </div>
                    <div class="template-options" data-outlook-template-options></div>
                    <p class="sheet-status" data-outlook-template-picker-status role="status" aria-live="polite"></p>
                    <p id="outlook-template-picker-hint" class="sheet-hint">Antippen und neu einsetzen.</p>
                    <p class="sheet-footnote">Ersetzt deinen Text. Dein Zitat bleibt erhalten.</p>
                </dialog>
                {{-- Compatibility hooks stay available to the runtime, outside the everyday UI. --}}
                <div data-outlook-developer-controls hidden inert aria-hidden="true">
                    <details data-outlook-new-message-help hidden><summary>Neue Nachricht in Outlook öffnen</summary><p>Dein Entwurf bleibt erhalten. Verwende bei Bedarf in Outlook „Neue Nachricht“.</p></details>
                            <button type="button" class="action" data-outlook-action="signature" data-available="false" data-busy="false" disabled>
                                <span class="action-copy"><strong>Signatur aktualisieren</strong><span data-outlook-signature-detail>Veröffentlichte Version einsetzen</span></span><span class="action-icon" aria-hidden="true">↻</span>
                            </button>
                        <details class="diagnostics" data-outlook-diagnostics hidden>
                            <summary>Verbindung prüfen</summary>
                            <div class="diagnostics-actions">
                                <button type="button" class="glass-button" data-outlook-diagnostics-run>Erneut prüfen</button>
                                <button type="button" class="glass-button" data-outlook-diagnostics-copy>Diagnose kopieren</button>
                                <button type="button" class="glass-button" data-outlook-head-probe hidden disabled>HEAD-CSS-Transportprobe (IT)</button>
                                <button type="button" class="glass-button" data-outlook-head-body-probe hidden disabled>HEAD-CSS über Body testen (IT, R786)</button>
                                <button type="button" class="glass-button" data-outlook-u2e-probe hidden disabled>U2e-Editorprobe (IT, Block A)</button>
                            </div>
                            <textarea data-outlook-diagnostics-output readonly aria-label="Datensparsamer Diagnosebericht" hidden></textarea>
                            <p class="automatic-feedback" data-outlook-diagnostics-feedback role="status"></p>
                        </details>
                </div>
                @endunless
            </div>
        </section>
    </main>
</body>
</html>
