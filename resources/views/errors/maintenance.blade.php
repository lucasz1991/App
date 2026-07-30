@php
    $formattedUpdatedAt = null;

    if (filled($lastUpdated ?? null)) {
        try {
            $formattedUpdatedAt = \Carbon\Carbon::parse($lastUpdated)
                ->setTimezone('Europe/Berlin')
                ->format('d.m.Y · H:i');
        } catch (\Throwable) {
            $formattedUpdatedAt = null;
        }
    }
@endphp
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, minimum-scale=1, maximum-scale=1, user-scalable=no, viewport-fit=cover, interactive-widget=resizes-content">
    <meta name="robots" content="noindex, nofollow">
    <meta name="theme-color" content="#f3f6fa" media="(prefers-color-scheme: light)">
    <meta name="theme-color" content="#0b1120" media="(prefers-color-scheme: dark)">

    <title>Wartung · RailTime</title>

    @include('layouts.icon-head')

    <script>
        try {
            if (localStorage.getItem('rt-theme') === 'true') {
                document.documentElement.classList.add('rt-dark');
            }
        } catch (error) {}
    </script>

    <style>
        :root {
            color-scheme: light;
            --rt-canvas: #f3f6fa;
            --rt-surface: rgba(255, 255, 255, 0.94);
            --rt-surface-muted: #f7f9fc;
            --rt-border: #dbe3ee;
            --rt-border-strong: #c8d3e1;
            --rt-text: #172033;
            --rt-muted: #64748b;
            --rt-soft: #91a0b5;
            --rt-accent: #e4002b;
            --rt-accent-hover: #c90026;
            --rt-warning: #f59e0b;
            --rt-shadow: 0 24px 70px -34px rgba(15, 23, 42, 0.34);
        }

        html.rt-dark {
            color-scheme: dark;
            --rt-canvas: #0b1120;
            --rt-surface: rgba(17, 24, 39, 0.94);
            --rt-surface-muted: #151e2e;
            --rt-border: #273449;
            --rt-border-strong: #35445b;
            --rt-text: #e7edf7;
            --rt-muted: #a9b6c9;
            --rt-soft: #77869d;
            --rt-accent: #ff5f78;
            --rt-accent-hover: #ff8295;
            --rt-warning: #fbbf24;
            --rt-shadow: 0 28px 80px -34px rgba(0, 0, 0, 0.72);
        }

        @media (prefers-color-scheme: dark) {
            html:not(.rt-light) {
                color-scheme: dark;
                --rt-canvas: #0b1120;
                --rt-surface: rgba(17, 24, 39, 0.94);
                --rt-surface-muted: #151e2e;
                --rt-border: #273449;
                --rt-border-strong: #35445b;
                --rt-text: #e7edf7;
                --rt-muted: #a9b6c9;
                --rt-soft: #77869d;
                --rt-accent: #ff5f78;
                --rt-accent-hover: #ff8295;
                --rt-warning: #fbbf24;
                --rt-shadow: 0 28px 80px -34px rgba(0, 0, 0, 0.72);
            }
        }

        * {
            box-sizing: border-box;
        }

        html,
        body {
            min-height: 100%;
        }

        body {
            margin: 0;
            min-height: 100vh;
            min-height: 100dvh;
            background:
                radial-gradient(60rem 28rem at 50% -10rem, rgba(228, 0, 43, 0.12), transparent 68%),
                linear-gradient(var(--rt-canvas), var(--rt-canvas));
            color: var(--rt-text);
            font-family: "Plus Jakarta Sans", Inter, system-ui, -apple-system, "Segoe UI", sans-serif;
            line-height: 1.55;
            -webkit-font-smoothing: antialiased;
        }

        body::before {
            position: fixed;
            inset: 0;
            z-index: -1;
            content: "";
            pointer-events: none;
            background-image:
                linear-gradient(rgba(100, 116, 139, 0.055) 1px, transparent 1px),
                linear-gradient(90deg, rgba(100, 116, 139, 0.055) 1px, transparent 1px);
            background-size: 56px 56px;
            mask-image: linear-gradient(to bottom, black, transparent 78%);
        }

        .rt-maintenance-page {
            min-height: 100vh;
            min-height: 100dvh;
            display: grid;
            place-items: center;
            padding: clamp(1rem, 4vw, 3rem);
        }

        .rt-maintenance {
            position: relative;
            width: min(100%, 58rem);
            overflow: hidden;
            border: 1px solid var(--rt-border);
            border-radius: 1.35rem;
            background: var(--rt-surface);
            box-shadow: var(--rt-shadow);
            backdrop-filter: blur(20px);
        }

        .rt-maintenance::before {
            position: absolute;
            inset: 0 0 auto;
            height: 3px;
            content: "";
            background: linear-gradient(90deg, var(--rt-accent), #ff8295 46%, transparent 92%);
        }

        .rt-maintenance__header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            padding: 1.25rem clamp(1.25rem, 4vw, 2rem);
            border-bottom: 1px solid var(--rt-border);
        }

        .rt-brand {
            display: inline-flex;
            min-width: 0;
            align-items: center;
            gap: 0.75rem;
            color: inherit;
            text-decoration: none;
        }

        .rt-brand__logo {
            width: 2.75rem;
            height: 2.75rem;
            flex: 0 0 auto;
            object-fit: contain;
        }

        .rt-brand__copy {
            min-width: 0;
        }

        .rt-brand__name {
            display: block;
            font-size: 0.95rem;
            font-weight: 800;
            letter-spacing: 0.14em;
            line-height: 1.15;
            text-transform: uppercase;
        }

        .rt-brand__company {
            display: block;
            margin-top: 0.2rem;
            color: var(--rt-muted);
            font-size: 0.72rem;
            font-weight: 600;
        }

        .rt-status {
            display: inline-flex;
            flex: 0 0 auto;
            align-items: center;
            gap: 0.5rem;
            min-height: 2rem;
            padding: 0.35rem 0.7rem;
            border: 1px solid color-mix(in srgb, var(--rt-warning) 34%, var(--rt-border));
            border-radius: 999px;
            background: color-mix(in srgb, var(--rt-warning) 9%, transparent);
            color: var(--rt-text);
            font-size: 0.72rem;
            font-weight: 700;
            white-space: nowrap;
        }

        .rt-status__dot {
            width: 0.5rem;
            height: 0.5rem;
            border-radius: 50%;
            background: var(--rt-warning);
            box-shadow: 0 0 0 0 color-mix(in srgb, var(--rt-warning) 30%, transparent);
            animation: rt-status-pulse 2.2s ease-out infinite;
        }

        .rt-maintenance__body {
            display: grid;
            grid-template-columns: minmax(0, 1.35fr) minmax(17rem, 0.65fr);
            gap: clamp(1.5rem, 4vw, 3rem);
            align-items: center;
            padding: clamp(2rem, 6vw, 4rem) clamp(1.25rem, 6vw, 4rem);
        }

        .rt-eyebrow {
            margin: 0 0 0.75rem;
            color: var(--rt-accent);
            font-size: 0.72rem;
            font-weight: 800;
            letter-spacing: 0.14em;
            text-transform: uppercase;
        }

        .rt-title {
            max-width: 13ch;
            margin: 0;
            font-size: clamp(2rem, 5vw, 3.4rem);
            font-weight: 750;
            letter-spacing: -0.045em;
            line-height: 1.02;
        }

        .rt-copy {
            max-width: 38rem;
            margin: 1.15rem 0 0;
            color: var(--rt-muted);
            font-size: clamp(0.95rem, 2vw, 1.05rem);
        }

        .rt-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 0.7rem;
            margin-top: 1.75rem;
        }

        .rt-button {
            display: inline-flex;
            min-height: 2.8rem;
            align-items: center;
            justify-content: center;
            gap: 0.55rem;
            padding: 0.7rem 1rem;
            border: 1px solid var(--rt-border-strong);
            border-radius: 0.75rem;
            background: var(--rt-surface);
            color: var(--rt-text);
            font-size: 0.85rem;
            font-weight: 700;
            text-decoration: none;
            transition: transform 180ms ease, border-color 180ms ease, color 180ms ease, background 180ms ease;
        }

        .rt-button:hover {
            border-color: var(--rt-accent);
            color: var(--rt-accent);
            transform: translateY(-1px);
        }

        .rt-button--primary {
            border-color: var(--rt-accent);
            background: var(--rt-accent);
            color: #fff;
        }

        .rt-button--primary:hover {
            border-color: var(--rt-accent-hover);
            background: var(--rt-accent-hover);
            color: #fff;
        }

        .rt-button svg {
            width: 1rem;
            height: 1rem;
        }

        .rt-service-card {
            position: relative;
            overflow: hidden;
            padding: 1.25rem;
            border: 1px solid var(--rt-border);
            border-radius: 1rem;
            background: var(--rt-surface-muted);
        }

        .rt-service-card::after {
            position: absolute;
            right: -2.8rem;
            bottom: -3.4rem;
            width: 9rem;
            height: 9rem;
            content: "";
            border-radius: 50%;
            background: color-mix(in srgb, var(--rt-accent) 8%, transparent);
        }

        .rt-service-card__icon {
            display: grid;
            width: 3rem;
            height: 3rem;
            place-items: center;
            border: 1px solid color-mix(in srgb, var(--rt-accent) 22%, var(--rt-border));
            border-radius: 0.85rem;
            background: color-mix(in srgb, var(--rt-accent) 8%, var(--rt-surface));
            color: var(--rt-accent);
        }

        .rt-service-card__icon svg {
            width: 1.35rem;
            height: 1.35rem;
        }

        .rt-service-card__label {
            margin: 1rem 0 0.2rem;
            color: var(--rt-soft);
            font-size: 0.66rem;
            font-weight: 800;
            letter-spacing: 0.12em;
            text-transform: uppercase;
        }

        .rt-service-card__value {
            margin: 0;
            font-size: 0.9rem;
            font-weight: 700;
        }

        .rt-service-card__meta {
            position: relative;
            z-index: 1;
            margin: 1rem 0 0;
            padding-top: 1rem;
            border-top: 1px solid var(--rt-border);
            color: var(--rt-muted);
            font-size: 0.78rem;
        }

        .rt-maintenance__footer {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            padding: 1rem clamp(1.25rem, 4vw, 2rem);
            border-top: 1px solid var(--rt-border);
            color: var(--rt-soft);
            font-size: 0.72rem;
        }

        .rt-maintenance__footer a {
            color: var(--rt-muted);
            font-weight: 700;
            text-decoration: none;
        }

        .rt-maintenance__footer a:hover {
            color: var(--rt-accent);
        }

        @keyframes rt-status-pulse {
            0% {
                box-shadow: 0 0 0 0 color-mix(in srgb, var(--rt-warning) 32%, transparent);
            }
            70%,
            100% {
                box-shadow: 0 0 0 0.55rem transparent;
            }
        }

        @media (max-width: 720px) {
            .rt-maintenance-page {
                place-items: stretch;
                padding: 0;
            }

            .rt-maintenance {
                min-height: 100vh;
                min-height: 100dvh;
                border: 0;
                border-radius: 0;
            }

            .rt-maintenance__body {
                grid-template-columns: 1fr;
                align-content: center;
            }

            .rt-title {
                max-width: 15ch;
            }

            .rt-service-card {
                max-width: none;
            }
        }

        @media (max-width: 420px) {
            .rt-brand__company {
                display: none;
            }

            .rt-status {
                padding-inline: 0.55rem;
            }

            .rt-actions {
                flex-direction: column;
            }

            .rt-button {
                width: 100%;
            }

            .rt-maintenance__footer {
                align-items: flex-start;
                flex-direction: column;
                gap: 0.35rem;
            }
        }

        @media (prefers-reduced-motion: reduce) {
            *,
            *::before,
            *::after {
                scroll-behavior: auto !important;
                animation-duration: 0.01ms !important;
                animation-iteration-count: 1 !important;
                transition-duration: 0.01ms !important;
            }
        }
    </style>
</head>
<body>
    <main class="rt-maintenance-page">
        <section class="rt-maintenance" aria-labelledby="maintenance-title">
            <header class="rt-maintenance__header">
                <a class="rt-brand" href="{{ url('/') }}" aria-label="RailTime Startseite">
                    <img
                        class="rt-brand__logo"
                        src="{{ asset('rt-brand/rt-logo.svg') }}"
                        alt=""
                        width="44"
                        height="44"
                    >
                    <span class="rt-brand__copy">
                        <span class="rt-brand__name">RailTime</span>
                        <span class="rt-brand__company">Rail Time GmbH</span>
                    </span>
                </a>

                <span class="rt-status">
                    <span class="rt-status__dot" aria-hidden="true"></span>
                    Wartungsmodus
                </span>
            </header>

            <div class="rt-maintenance__body">
                <div>
                    <p class="rt-eyebrow">Systemwartung</p>
                    <h1 class="rt-title" id="maintenance-title">Wir sind gleich wieder für Sie da.</h1>
                    <p class="rt-copy">
                        RailTime wird momentan aktualisiert. Währenddessen ist der Benutzerbereich vorübergehend nicht erreichbar.
                        Bitte versuchen Sie es in wenigen Minuten erneut.
                    </p>

                    <div class="rt-actions">
                        <a class="rt-button rt-button--primary" href="{{ url()->current() }}">
                            Erneut versuchen
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12a7.5 7.5 0 1 0 2.2-5.3L4.5 9m0 0V4.5M4.5 9H9"/>
                            </svg>
                        </a>

                        <a class="rt-button" href="{{ route('admin.login') }}">
                            Administratorzugang
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M15 8.25V6a3 3 0 0 0-6 0v2.25M6.75 8.25h10.5v10.5H6.75V8.25Z"/>
                            </svg>
                        </a>
                    </div>
                </div>

                <aside class="rt-service-card" aria-label="Wartungsstatus">
                    <div class="rt-service-card__icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M11.1 3.3a1.8 1.8 0 0 1 1.8 0l.65.38a1.8 1.8 0 0 0 1.4.18l.74-.2a1.8 1.8 0 0 1 2.2 1.27l.2.74a1.8 1.8 0 0 0 .7 1.22l.65.38a1.8 1.8 0 0 1 .9 1.56v.75a1.8 1.8 0 0 0 .36 1.18l.47.6a1.8 1.8 0 0 1 0 2.22l-.47.6a1.8 1.8 0 0 0-.36 1.18v.75a1.8 1.8 0 0 1-.9 1.56l-.65.38a1.8 1.8 0 0 0-.7 1.22l-.2.74a1.8 1.8 0 0 1-2.2 1.27l-.74-.2a1.8 1.8 0 0 0-1.4.18l-.65.38a1.8 1.8 0 0 1-1.8 0l-.65-.38a1.8 1.8 0 0 0-1.4-.18l-.74.2a1.8 1.8 0 0 1-2.2-1.27l-.2-.74a1.8 1.8 0 0 0-.7-1.22l-.65-.38a1.8 1.8 0 0 1-.9-1.56v-.75a1.8 1.8 0 0 0-.36-1.18l-.47-.6a1.8 1.8 0 0 1 0-2.22l.47-.6a1.8 1.8 0 0 0 .36-1.18v-.75a1.8 1.8 0 0 1 .9-1.56l.65-.38a1.8 1.8 0 0 0 .7-1.22l.2-.74a1.8 1.8 0 0 1 2.2-1.27l.74.2a1.8 1.8 0 0 0 1.4-.18l.65-.38Z"/>
                            <circle cx="12" cy="12" r="3"/>
                        </svg>
                    </div>

                    <p class="rt-service-card__label">Aktueller Status</p>
                    <p class="rt-service-card__value">Wartungsarbeiten laufen</p>

                    <p class="rt-service-card__meta">
                        @if ($formattedUpdatedAt)
                            Zuletzt aktualisiert<br>
                            <strong>{{ $formattedUpdatedAt }} Uhr</strong>
                        @else
                            Sobald die Arbeiten abgeschlossen sind, steht RailTime automatisch wieder zur Verfügung.
                        @endif
                    </p>
                </aside>
            </div>

            <footer class="rt-maintenance__footer">
                <span>RailTime · Betriebsportal</span>
                <a href="mailto:kontakt@rail-time.de">kontakt@rail-time.de</a>
            </footer>
        </section>
    </main>
</body>
</html>
