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
            --rt-red-dark: #b80023;
            --rt-ink: #18202b;
            --rt-muted: #5d6978;
            --rt-line: #dce2e9;
            --rt-surface: #ffffff;
            --rt-soft: #f4f6f8;
            --rt-success: #08775c;
            --rt-error: #b42318;
        }

        * { box-sizing: border-box; }

        html, body { min-height: 100%; }

        body {
            margin: 0;
            background: var(--rt-soft);
            color: var(--rt-ink);
            font-size: 14px;
            line-height: 1.5;
        }

        button { font: inherit; }

        .shell {
            min-height: 100vh;
            padding: 20px;
        }

        .panel {
            width: 100%;
            max-width: 540px;
            margin: 0 auto;
            overflow: hidden;
            border: 1px solid var(--rt-line);
            border-radius: 18px;
            background: var(--rt-surface);
            box-shadow: 0 14px 38px rgba(24, 32, 43, .08);
        }

        .brand {
            display: flex;
            min-height: 86px;
            padding: 20px;
            align-items: center;
            gap: 14px;
            border-top: 4px solid var(--rt-red);
            border-bottom: 1px solid var(--rt-line);
        }

        .brand-mark {
            display: grid;
            width: 42px;
            height: 42px;
            flex: 0 0 42px;
            place-items: center;
            border-radius: 12px;
            background: var(--rt-red);
            color: #fff;
            font-size: 15px;
            font-weight: 800;
            letter-spacing: -.04em;
        }

        .brand h1,
        .brand p { margin: 0; }

        .brand h1 { font-size: 17px; line-height: 1.25; }
        .brand p { margin-top: 3px; color: var(--rt-muted); font-size: 12px; }

        .content { padding: 20px; }

        .status {
            display: grid;
            min-height: 82px;
            padding: 15px;
            border: 1px solid var(--rt-line);
            border-radius: 13px;
            background: var(--rt-soft);
            grid-template-columns: 10px minmax(0, 1fr);
            gap: 12px;
        }

        .status-dot {
            width: 10px;
            height: 10px;
            margin-top: 5px;
            border-radius: 999px;
            background: #7b8794;
        }

        .status[data-tone="working"] .status-dot { background: #d58a00; }
        .status[data-tone="success"] .status-dot { background: var(--rt-success); }
        .status[data-tone="error"] .status-dot { background: var(--rt-error); }

        .status strong,
        .status p { margin: 0; }

        .status p {
            margin-top: 3px;
            color: var(--rt-muted);
            font-size: 12px;
        }

        .account {
            margin: 10px 0 0;
            overflow-wrap: anywhere;
            color: var(--rt-muted);
            font-size: 12px;
        }

        .actions {
            display: grid;
            margin-top: 18px;
            gap: 10px;
        }

        .action {
            display: flex;
            width: 100%;
            min-height: 48px;
            padding: 11px 14px;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            border: 1px solid var(--rt-line);
            border-radius: 12px;
            background: var(--rt-surface);
            color: var(--rt-ink);
            cursor: pointer;
            text-align: left;
            transition: border-color .15s ease, background-color .15s ease, transform .15s ease;
        }

        .action:hover:not(:disabled) {
            border-color: var(--rt-red);
            background: #fff8f9;
        }

        .action:active:not(:disabled) { transform: translateY(1px); }

        .action:focus-visible {
            outline: 3px solid rgba(228, 0, 43, .25);
            outline-offset: 2px;
        }

        .action:disabled { cursor: not-allowed; opacity: .48; }

        .action-primary {
            border-color: var(--rt-red);
            background: var(--rt-red);
            color: #fff;
        }

        .action-primary:hover:not(:disabled) {
            border-color: var(--rt-red-dark);
            background: var(--rt-red-dark);
        }

        .action-copy { min-width: 0; }
        .action-copy strong,
        .action-copy span { display: block; }
        .action-copy span { margin-top: 2px; opacity: .72; font-size: 11px; }

        .action-icon {
            flex: 0 0 auto;
            font-size: 18px;
            line-height: 1;
        }

        .action[data-busy="true"] .action-icon { animation: rt-spin .8s linear infinite; }

        .footnote {
            margin: 17px 0 0;
            color: var(--rt-muted);
            font-size: 11px;
        }

        @keyframes rt-spin { to { transform: rotate(360deg); } }

        @media (prefers-reduced-motion: reduce) {
            *, *::before, *::after {
                scroll-behavior: auto !important;
                transition-duration: .01ms !important;
                animation-duration: .01ms !important;
                animation-iteration-count: 1 !important;
            }
        }

        @media (prefers-color-scheme: dark) {
            :root {
                --rt-ink: #f1f4f7;
                --rt-muted: #aab4c0;
                --rt-line: #394553;
                --rt-surface: #17202a;
                --rt-soft: #10171f;
            }

            .panel { box-shadow: none; }

            .action:hover:not(:disabled) {
                border-color: #ff516d;
                background: #2a1820;
            }
        }

        @media (max-width: 420px) {
            .shell { padding: 0; }
            .panel { min-height: 100vh; border: 0; border-radius: 0; }
            .brand, .content { padding-right: 16px; padding-left: 16px; }
        }
    </style>
</head>
<body data-outlook-config-url="{{ $resolvedConfigUrl }}">
    <main class="shell" data-outlook-addin-taskpane aria-busy="true">
        <section class="panel" aria-labelledby="outlook-addin-heading">
            <header class="brand">
                <span class="brand-mark" aria-hidden="true">RT</span>
                <div>
                    <h1 id="outlook-addin-heading">RailTime für Outlook</h1>
                    <p>Veröffentlichte Signatur und Nachrichtenvorlage</p>
                </div>
            </header>

            <div class="content">
                <div class="status" data-outlook-status data-tone="working" role="status" aria-live="polite" aria-atomic="true">
                    <span class="status-dot" aria-hidden="true"></span>
                    <div>
                        <strong data-outlook-status-title>Outlook wird vorbereitet …</strong>
                        <p data-outlook-status-detail>Die sichere Verbindung wird geprüft.</p>
                    </div>
                </div>

                <p class="account" data-outlook-account hidden></p>

                <div class="actions" aria-label="RailTime-Aktionen">
                    <button class="action action-primary" type="button" data-outlook-action="login" data-available="false" disabled>
                        <span class="action-copy">
                            <strong>Mit Microsoft verbinden</strong>
                            <span>Einmalig mit dem Firmenkonto anmelden</span>
                        </span>
                        <span class="action-icon" aria-hidden="true">→</span>
                    </button>

                    <button class="action" type="button" data-outlook-action="signature" data-available="false" disabled>
                        <span class="action-copy">
                            <strong>Signatur aktualisieren</strong>
                            <span>Aktuell veröffentlichte Version einsetzen</span>
                        </span>
                        <span class="action-icon" aria-hidden="true">↻</span>
                    </button>

                    <button class="action" type="button" data-outlook-action="template" data-available="false" disabled>
                        <span class="action-copy">
                            <strong>Nachrichtenvorlage einsetzen</strong>
                            <span>Ersetzt den Nachrichteninhalt mit der aktuellen Veröffentlichung</span>
                        </span>
                        <span class="action-icon" aria-hidden="true">＋</span>
                    </button>
                </div>

                <p class="footnote">Die Anmeldung erfolgt über Microsoft. RailTime speichert in diesem Add-in kein Kennwort und kein dauerhaftes Zugriffstoken.</p>
            </div>
        </section>
    </main>
</body>
</html>
