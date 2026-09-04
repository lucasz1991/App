@php
    $resolvedConfigUrl = (string) ($configUrl ?? $outlookAddinConfigUrl ?? '');
    $resolvedScriptUrl = (string) ($scriptUrl ?? asset('outlook-addin/taskpane.js'));
    $resolvedNonce = (string) ($cspNonce ?? '');
@endphp
<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-outlook-config-url="{{ $resolvedConfigUrl }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light dark">
    <meta name="railtime-outlook-config-url" content="{{ $resolvedConfigUrl }}">
    <title>RailTime für Outlook</title>
    <script src="https://appsforoffice.microsoft.com/lib/1/hosted/office.js" type="text/javascript"></script>
    <script src="{{ $resolvedScriptUrl }}" type="text/javascript" defer></script>
    <style @if ($resolvedNonce !== '') nonce="{{ $resolvedNonce }}" @endif>
        :root {
            color-scheme: light dark;
            font-family: "Segoe UI", Arial, sans-serif;
            --rt-red: #e4002b;
            --rt-red-strong: #bd001f;
            --rt-navy: #15263c;
            --rt-navy-soft: #263b55;
            --rt-ink: #152234;
            --rt-muted: #667386;
            --rt-line: #dce3eb;
            --rt-surface: #ffffff;
            --rt-subtle: #f4f7fa;
            --rt-soft-red: #fff1f4;
            --rt-success: #08775c;
            --rt-success-soft: #e9f7f2;
            --rt-warning: #a15c00;
            --rt-warning-soft: #fff7e7;
            --rt-error: #b42318;
            --rt-error-soft: #fff0ef;
            --rt-focus: rgba(228, 0, 43, .26);
        }

        * { box-sizing: border-box; }

        [hidden] { display: none !important; }

        html,
        body { min-height: 100%; }

        body {
            margin: 0;
            background: var(--rt-subtle);
            color: var(--rt-ink);
            font-size: 14px;
            line-height: 1.45;
            -webkit-font-smoothing: antialiased;
        }

        button,
        select { font: inherit; }

        button,
        select { -webkit-tap-highlight-color: transparent; }

        .shell {
            min-height: 100vh;
            padding: 12px;
        }

        .panel {
            width: 100%;
            max-width: 480px;
            margin: 0 auto;
            overflow: hidden;
            border: 1px solid var(--rt-line);
            border-radius: 18px;
            background: var(--rt-surface);
            box-shadow: 0 16px 42px rgba(21, 38, 60, .1);
        }

        .brand {
            position: relative;
            display: flex;
            min-height: 82px;
            padding: 17px 18px 16px;
            align-items: center;
            gap: 12px;
            border-top: 4px solid var(--rt-red);
            border-bottom: 1px solid var(--rt-line);
            background:
                linear-gradient(112deg, rgba(228, 0, 43, .055), transparent 42%),
                var(--rt-surface);
        }

        .brand-mark {
            display: grid;
            width: 42px;
            height: 42px;
            flex: 0 0 42px;
            place-items: center;
            border: 1px solid rgba(228, 0, 43, .2);
            border-radius: 12px;
            background: var(--rt-red);
            box-shadow: inset 0 -4px 0 rgba(21, 38, 60, .16);
            color: #fff;
            font-size: 15px;
            font-weight: 800;
            letter-spacing: -.05em;
        }

        .brand-copy { min-width: 0; }

        .brand-kicker,
        .brand h1 { margin: 0; }

        .brand-kicker {
            color: var(--rt-red);
            font-size: 9px;
            font-weight: 800;
            letter-spacing: .16em;
            text-transform: uppercase;
        }

        .brand h1 {
            margin-top: 2px;
            overflow: hidden;
            font-size: 17px;
            line-height: 1.2;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .connection-chip {
            display: inline-flex;
            min-height: 26px;
            margin-left: auto;
            padding: 5px 8px;
            align-items: center;
            gap: 6px;
            border: 1px solid var(--rt-line);
            border-radius: 999px;
            background: var(--rt-subtle);
            color: var(--rt-muted);
            font-size: 10px;
            font-weight: 700;
            line-height: 1;
            white-space: nowrap;
        }

        .connection-chip::before {
            width: 6px;
            height: 6px;
            border-radius: 999px;
            background: currentColor;
            content: "";
        }

        .connection-chip[data-tone="success"] {
            border-color: rgba(8, 119, 92, .24);
            background: var(--rt-success-soft);
            color: var(--rt-success);
        }

        .connection-chip[data-tone="working"] {
            border-color: rgba(161, 92, 0, .22);
            background: var(--rt-warning-soft);
            color: var(--rt-warning);
        }

        .connection-chip[data-tone="error"] {
            border-color: rgba(180, 35, 24, .22);
            background: var(--rt-error-soft);
            color: var(--rt-error);
        }

        .content { padding: 16px; }

        .status {
            display: grid;
            min-height: 66px;
            padding: 12px;
            align-items: start;
            border: 1px solid var(--rt-line);
            border-radius: 13px;
            background: var(--rt-subtle);
            grid-template-columns: 24px minmax(0, 1fr);
            gap: 10px;
        }

        .status:focus-visible {
            outline: 3px solid var(--rt-focus);
            outline-offset: 2px;
        }

        .status-symbol {
            display: grid;
            width: 24px;
            height: 24px;
            place-items: center;
            border-radius: 8px;
            background: #e2e7ed;
            color: var(--rt-navy);
            font-size: 12px;
            font-weight: 800;
        }

        .status[data-tone="working"] {
            border-color: #f0d7ae;
            background: var(--rt-warning-soft);
        }

        .status[data-tone="working"] .status-symbol {
            background: #ffe5b8;
            color: var(--rt-warning);
        }

        .status[data-tone="success"] {
            border-color: #c9e8dd;
            background: var(--rt-success-soft);
        }

        .status[data-tone="success"] .status-symbol {
            background: #c9eade;
            color: var(--rt-success);
        }

        .status[data-tone="error"] {
            border-color: #f0cac7;
            background: var(--rt-error-soft);
        }

        .status[data-tone="error"] .status-symbol {
            background: #f7d2cf;
            color: var(--rt-error);
        }

        .status strong,
        .status p { margin: 0; }

        .status strong {
            display: block;
            font-size: 13px;
            line-height: 1.25;
        }

        .status p {
            margin-top: 3px;
            color: var(--rt-muted);
            font-size: 11px;
            line-height: 1.4;
        }

        .account {
            display: flex;
            min-height: 30px;
            margin: 9px 0 0;
            padding: 6px 9px;
            align-items: center;
            gap: 7px;
            border-radius: 9px;
            background: var(--rt-subtle);
            color: var(--rt-muted);
            font-size: 11px;
        }

        .account::before {
            width: 7px;
            height: 7px;
            flex: 0 0 7px;
            border-radius: 999px;
            background: var(--rt-success);
            content: "";
        }

        .account span {
            min-width: 0;
            overflow-wrap: anywhere;
        }

        .template-card {
            margin-top: 14px;
            padding: 14px;
            border: 1px solid var(--rt-line);
            border-radius: 15px;
            background: var(--rt-surface);
        }

        .section-heading {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 10px;
        }

        .section-kicker,
        .section-heading h2,
        .section-heading p { margin: 0; }

        .section-kicker {
            color: var(--rt-red);
            font-size: 9px;
            font-weight: 800;
            letter-spacing: .15em;
            text-transform: uppercase;
        }

        .section-heading h2 {
            margin-top: 2px;
            color: var(--rt-navy);
            font-size: 15px;
            line-height: 1.25;
        }

        .section-heading p {
            margin-top: 3px;
            color: var(--rt-muted);
            font-size: 11px;
        }

        .template-count {
            display: inline-flex;
            min-height: 24px;
            padding: 4px 8px;
            align-items: center;
            justify-content: center;
            border-radius: 999px;
            background: var(--rt-soft-red);
            color: var(--rt-red-strong);
            font-size: 10px;
            font-weight: 800;
            white-space: nowrap;
        }

        .template-ready { margin-top: 12px; }

        .field-label {
            display: block;
            margin: 0 0 6px;
            color: var(--rt-muted);
            font-size: 10px;
            font-weight: 700;
        }

        .template-select {
            width: 100%;
            min-height: 44px;
            padding: 9px 34px 9px 11px;
            border: 1px solid #bdc8d4;
            border-radius: 10px;
            background: var(--rt-surface);
            color: var(--rt-ink);
            cursor: pointer;
        }

        .template-select:hover:not(:disabled) { border-color: var(--rt-navy-soft); }

        .template-select:focus-visible {
            border-color: var(--rt-red);
            outline: 3px solid var(--rt-focus);
            outline-offset: 1px;
        }

        .template-select:disabled {
            cursor: not-allowed;
            opacity: .58;
        }

        .template-meta {
            display: flex;
            min-height: 44px;
            margin-top: 8px;
            padding: 8px 10px;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
            border-radius: 10px;
            background: var(--rt-subtle);
        }

        .template-meta-copy { min-width: 0; }

        .template-meta strong,
        .template-meta span { display: block; }

        .template-meta strong {
            overflow: hidden;
            color: var(--rt-navy);
            font-size: 12px;
            line-height: 1.25;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .template-version {
            margin-top: 2px;
            overflow: hidden;
            color: var(--rt-muted);
            font-family: Consolas, "SFMono-Regular", monospace;
            font-size: 9px;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .active-badge {
            flex: 0 0 auto;
            padding: 4px 7px;
            border: 1px solid rgba(8, 119, 92, .22);
            border-radius: 999px;
            background: var(--rt-success-soft);
            color: var(--rt-success);
            font-size: 9px;
            font-weight: 800;
            text-transform: uppercase;
        }

        .template-loading {
            display: grid;
            margin-top: 12px;
            gap: 7px;
        }

        .skeleton {
            height: 13px;
            border-radius: 999px;
            background: linear-gradient(90deg, #e9edf2 25%, #f6f8fa 50%, #e9edf2 75%);
            background-size: 200% 100%;
            animation: rt-shimmer 1.35s ease-in-out infinite;
        }

        .skeleton:first-child { width: 62%; }
        .skeleton:nth-child(2) { width: 100%; height: 44px; border-radius: 10px; }

        .template-message {
            margin-top: 12px;
            padding: 12px;
            border: 1px dashed #bdc8d4;
            border-radius: 10px;
            background: var(--rt-subtle);
            color: var(--rt-muted);
            font-size: 11px;
        }

        .template-card:not([data-state="loading"]) .template-loading,
        .template-card:not([data-state="ready"]) .template-ready,
        .template-card:not([data-state="empty"]) .template-empty,
        .template-card:not([data-state="locked"]) .template-locked,
        .template-card:not([data-state="error"]) .template-error { display: none; }

        .actions {
            display: grid;
            margin-top: 10px;
            gap: 8px;
        }

        .maintenance-actions {
            display: grid;
            margin-top: 10px;
            gap: 8px;
        }

        .action {
            display: flex;
            width: 100%;
            min-height: 46px;
            padding: 9px 11px;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            border: 1px solid var(--rt-line);
            border-radius: 11px;
            background: var(--rt-surface);
            color: var(--rt-navy);
            cursor: pointer;
            text-align: left;
            transition: border-color .15s ease, background-color .15s ease, transform .15s ease, box-shadow .15s ease;
        }

        .action:hover:not(:disabled) {
            border-color: #aebbc9;
            background: var(--rt-subtle);
        }

        .action:active:not(:disabled) { transform: translateY(1px); }

        .action:focus-visible {
            outline: 3px solid var(--rt-focus);
            outline-offset: 2px;
        }

        .action:disabled {
            cursor: not-allowed;
            opacity: .5;
        }

        .action-primary {
            border-color: var(--rt-red);
            background: var(--rt-red);
            box-shadow: 0 6px 14px rgba(228, 0, 43, .16);
            color: #fff;
        }

        .action-primary:hover:not(:disabled) {
            border-color: var(--rt-red-strong);
            background: var(--rt-red-strong);
        }

        .action-copy { min-width: 0; }

        .action-copy strong,
        .action-copy span { display: block; }

        .action-copy strong {
            font-size: 12px;
            line-height: 1.25;
        }

        .action-copy span {
            margin-top: 2px;
            opacity: .76;
            font-size: 10px;
            line-height: 1.35;
        }

        .action-icon {
            position: relative;
            display: grid;
            width: 26px;
            height: 26px;
            flex: 0 0 26px;
            place-items: center;
            border-radius: 8px;
            background: rgba(21, 38, 60, .07);
            font-size: 15px;
            line-height: 1;
        }

        .action-primary .action-icon { background: rgba(255, 255, 255, .16); }

        .action[data-busy="true"] .action-icon { color: transparent; }

        .action[data-busy="true"] .action-icon::after {
            position: absolute;
            width: 12px;
            height: 12px;
            border: 2px solid currentColor;
            border-right-color: transparent;
            border-radius: 999px;
            color: var(--rt-red);
            content: "";
            animation: rt-spin .75s linear infinite;
        }

        .action-primary[data-busy="true"] .action-icon::after { color: #fff; }

        .footnote {
            margin: 13px 2px 0;
            color: var(--rt-muted);
            font-size: 9px;
            line-height: 1.45;
        }

        @keyframes rt-spin { to { transform: rotate(360deg); } }
        @keyframes rt-shimmer { to { background-position: -200% 0; } }

        @media (prefers-reduced-motion: reduce) {
            *,
            *::before,
            *::after {
                scroll-behavior: auto !important;
                transition-duration: .01ms !important;
                animation-duration: .01ms !important;
                animation-iteration-count: 1 !important;
            }
        }

        @media (prefers-color-scheme: dark) {
            :root {
                --rt-navy: #f0f4f8;
                --rt-navy-soft: #d8e0e9;
                --rt-ink: #f1f4f7;
                --rt-muted: #aab6c3;
                --rt-line: #394a5d;
                --rt-surface: #172334;
                --rt-subtle: #101a28;
                --rt-soft-red: #341821;
                --rt-success-soft: #122d29;
                --rt-warning-soft: #302516;
                --rt-error-soft: #321b1b;
            }

            .panel { box-shadow: none; }

            .brand {
                background:
                    linear-gradient(112deg, rgba(228, 0, 43, .1), transparent 45%),
                    var(--rt-surface);
            }

            .status-symbol,
            .action-icon { background: rgba(255, 255, 255, .08); }

            .template-select { border-color: #53657a; }

            .skeleton {
                background: linear-gradient(90deg, #28374a 25%, #34465b 50%, #28374a 75%);
                background-size: 200% 100%;
            }
        }

        @media (max-width: 420px) {
            .shell { padding: 0; }

            .panel {
                min-height: 100vh;
                border: 0;
                border-radius: 0;
                box-shadow: none;
            }

            .brand { padding-right: 14px; padding-left: 14px; }
            .content { padding: 14px; }
        }

        @media (max-width: 340px) {
            .brand { gap: 9px; }
            .brand-mark { width: 38px; height: 38px; flex-basis: 38px; }
            .connection-chip { padding-right: 6px; padding-left: 6px; font-size: 9px; }
            .content { padding: 12px; }
            .template-card { padding: 12px; }
        }
    </style>
</head>
<body data-outlook-config-url="{{ $resolvedConfigUrl }}">
    <main class="shell" data-outlook-addin-taskpane aria-busy="true">
        <section class="panel" aria-labelledby="outlook-addin-heading">
            <header class="brand">
                <span class="brand-mark" aria-hidden="true">RT</span>
                <div class="brand-copy">
                    <p class="brand-kicker">RailTime / Outlook</p>
                    <h1 id="outlook-addin-heading">Mail-Assistent</h1>
                </div>
                <span class="connection-chip" data-outlook-connection-chip data-tone="working">Prüfung</span>
            </header>

            <div class="content">
                <div
                    class="status"
                    data-outlook-status
                    data-tone="working"
                    role="status"
                    aria-live="polite"
                    aria-atomic="true"
                    tabindex="-1"
                >
                    <span class="status-symbol" data-outlook-status-symbol aria-hidden="true">…</span>
                    <div>
                        <strong data-outlook-status-title>Outlook wird vorbereitet …</strong>
                        <p data-outlook-status-detail>Die sichere Verbindung wird geprüft.</p>
                    </div>
                </div>

                <p class="account" data-outlook-account hidden>
                    <span data-outlook-account-label></span>
                </p>

                <section
                    class="template-card"
                    data-outlook-template-region
                    data-state="loading"
                    aria-labelledby="outlook-template-heading"
                >
                    <div class="section-heading">
                        <div>
                            <p class="section-kicker">Vorlagen</p>
                            <h2 id="outlook-template-heading">Nachricht einfügen</h2>
                            <p>Veröffentlichte RailTime-Inhalte</p>
                        </div>
                        <span class="template-count" data-outlook-template-count aria-live="polite">Laden …</span>
                    </div>

                    <div class="template-loading" data-outlook-template-loading aria-hidden="true">
                        <span class="skeleton"></span>
                        <span class="skeleton"></span>
                    </div>

                    <div class="template-ready" data-outlook-template-ready>
                        <label class="field-label" for="outlook-template-select">Vorlage auswählen</label>
                        <select
                            class="template-select"
                            id="outlook-template-select"
                            data-outlook-template-select
                            aria-describedby="outlook-template-meta"
                            disabled
                        >
                            <option value="">Vorlagen werden geladen …</option>
                        </select>

                        <div class="template-meta" id="outlook-template-meta" data-outlook-template-meta>
                            <div class="template-meta-copy">
                                <strong data-outlook-template-name>Keine Vorlage ausgewählt</strong>
                                <span class="template-version" data-outlook-template-version>Version nicht verfügbar</span>
                            </div>
                            <span class="active-badge" data-outlook-template-active hidden>Aktiv</span>
                        </div>

                        <div class="actions" aria-label="Vorlagenaktion">
                            <button
                                class="action action-primary"
                                type="button"
                                data-outlook-action="template"
                                data-available="false"
                                data-busy="false"
                                disabled
                            >
                                <span class="action-copy">
                                    <strong>Vorlage einfügen</strong>
                                    <span data-outlook-template-action-detail>Ausgewählte Vorlage in die Nachricht übernehmen</span>
                                </span>
                                <span class="action-icon" aria-hidden="true">＋</span>
                            </button>
                        </div>
                    </div>

                    <p class="template-message template-empty" data-outlook-template-empty>
                        Momentan ist keine veröffentlichte Nachrichtenvorlage verfügbar.
                    </p>
                    <p class="template-message template-locked" data-outlook-template-locked>
                        Nach der Microsoft-Anmeldung werden Ihre verfügbaren Vorlagen angezeigt.
                    </p>
                    <p class="template-message template-error" data-outlook-template-error>
                        Die Vorlagen konnten noch nicht geladen werden. Verbindung prüfen und erneut versuchen.
                    </p>
                </section>

                <div class="maintenance-actions" data-outlook-maintenance-actions aria-label="Verbindung und Signatur">
                    <button
                        class="action action-primary"
                        type="button"
                        data-outlook-action="login"
                        data-available="false"
                        data-busy="false"
                        disabled
                    >
                        <span class="action-copy">
                            <strong data-outlook-login-title>Mit Microsoft verbinden</strong>
                            <span data-outlook-login-detail>Einmalig mit dem Firmenkonto anmelden</span>
                        </span>
                        <span class="action-icon" aria-hidden="true">→</span>
                    </button>

                    <button
                        class="action"
                        type="button"
                        data-outlook-action="signature"
                        data-available="false"
                        data-busy="false"
                        disabled
                    >
                        <span class="action-copy">
                            <strong>Signatur aktualisieren</strong>
                            <span data-outlook-signature-detail>Aktuell veröffentlichte Version einsetzen</span>
                        </span>
                        <span class="action-icon" aria-hidden="true">↻</span>
                    </button>
                </div>

                <p class="footnote">
                    Sichere Microsoft-Anmeldung · keine Kennwortspeicherung · Inhalte aus Ihrer veröffentlichten RailTime-Version
                </p>
            </div>
        </section>
    </main>
</body>
</html>
