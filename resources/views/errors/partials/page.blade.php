@php
    /**
     * Gemeinsame Fehlerseite fuer alle Status-Codes.
     *
     * Bewusst vollstaendig eigenstaendig: kein App-Shell, keine Sidebar, kein
     * Livewire, kein Alpine und KEIN Auth-/Session-/DB-Zugriff. Grund: bei
     * SESSION_DRIVER=database wuerde eine Fehlerseite, die den Anmeldestatus
     * abfragt, bei einem Datenbankausfall beim Rendern selbst scheitern — also
     * genau dann, wenn die 500-Seite gebraucht wird. Sie muss ausserdem ohne
     * JavaScript und ohne das Vite-Bundle lesbar sein.
     */
    $status = (int) ($status ?? 500);

    $copy = [
        401 => [__('app.error_401_title'), __('app.error_401_text')],
        403 => [__('app.error_403_title'), __('app.error_403_text')],
        404 => [__('app.error_404_title'), __('app.error_404_text')],
        405 => [__('app.error_405_title'), __('app.error_405_text')],
        408 => [__('app.error_408_title'), __('app.error_408_text')],
        419 => [__('app.error_419_title'), __('app.error_419_text')],
        422 => [__('app.error_422_title'), __('app.error_422_text')],
        429 => [__('app.error_429_title'), __('app.error_429_text')],
        500 => [__('app.error_500_title'), __('app.error_500_text')],
        503 => [__('app.error_503_title'), __('app.error_503_text')],
    ];

    [$title, $text] = $copy[$status]
        ?? ($status >= 500
            ? [__('app.error_5xx_title'), __('app.error_5xx_text')]
            : [__('app.error_4xx_title'), __('app.error_4xx_text')]);

    $isServerError = $status >= 500;
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, minimum-scale=1, maximum-scale=1, user-scalable=no, viewport-fit=cover, interactive-widget=resizes-content">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $status }} · {{ $title }} | {{ config('app.name', 'RailTime') }}</title>

    {{-- Reines Icon-Partial ohne Auth-/DB-Zugriff. --}}
    @include('layouts.icon-head')

    {{-- Dunkelmodus vor dem Zeichnen anwenden (wie im Hauptlayout). Faellt
         ohne JavaScript auf die helle Darstellung zurueck. --}}
    <script>
        try {
            if (localStorage.getItem('rt-theme') === 'true') {
                document.documentElement.classList.add('rt-dark');
            }
        } catch (e) {}
    </script>

    {{-- Eigenes CSS, damit die Seite auch bei fehlendem/kaputtem Vite-Bundle
         vollstaendig gestaltet ist. Farben entsprechen den RailTime-Tokens. --}}
    <style>
        :root {
            --rt-canvas: #f3f6fa;
            --rt-surface: #ffffff;
            --rt-border: #dbe3ee;
            --rt-text: #172033;
            --rt-muted: #64748b;
            --rt-accent: #e4002b;
        }
        html.rt-dark {
            --rt-canvas: #0b1120;
            --rt-surface: #111827;
            --rt-border: #273449;
            --rt-text: #e7edf7;
            --rt-muted: #a9b6c9;
            --rt-accent: #ff8295;
        }
        @media (prefers-color-scheme: dark) {
            html:not(.rt-light) {
                --rt-canvas: #0b1120;
                --rt-surface: #111827;
                --rt-border: #273449;
                --rt-text: #e7edf7;
                --rt-muted: #a9b6c9;
                --rt-accent: #ff8295;
            }
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1.5rem;
            background: var(--rt-canvas);
            background-image: radial-gradient(60rem 22rem at 50% -6rem, rgba(228, 0, 43, 0.10), transparent);
            color: var(--rt-text);
            font-family: "Plus Jakarta Sans", system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
            line-height: 1.55;
        }
        .rt-error {
            width: 100%;
            max-width: 34rem;
            background: var(--rt-surface);
            border: 1px solid var(--rt-border);
            border-radius: 1rem;
            box-shadow: 0 4px 8px rgba(15, 23, 42, 0.06), 0 24px 64px -24px rgba(15, 23, 42, 0.28);
            padding: 2rem 1.75rem;
            text-align: center;
        }
        .rt-error__logo { width: 56px; height: 56px; margin: 0 auto 1.25rem; display: block; }
        .rt-error__code {
            display: inline-block;
            font-size: 0.75rem;
            font-weight: 700;
            letter-spacing: 0.16em;
            text-transform: uppercase;
            color: var(--rt-accent);
            border: 1px solid var(--rt-border);
            border-radius: 999px;
            padding: 0.25rem 0.7rem;
            margin-bottom: 0.9rem;
        }
        .rt-error__title { margin: 0 0 0.6rem; font-size: 1.4rem; font-weight: 700; }
        .rt-error__text { margin: 0; color: var(--rt-muted); font-size: 0.95rem; }
        .rt-error__actions {
            margin-top: 1.6rem;
            display: flex;
            flex-wrap: wrap;
            gap: 0.6rem;
            justify-content: center;
        }
        .rt-error__button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 2.75rem;
            padding: 0 1.1rem;
            border-radius: 0.75rem;
            border: 1px solid var(--rt-border);
            background: var(--rt-surface);
            color: var(--rt-text);
            font-size: 0.9rem;
            font-weight: 600;
            text-decoration: none;
            transition: background-color .2s ease, border-color .2s ease;
        }
        .rt-error__button:hover { border-color: var(--rt-accent); color: var(--rt-accent); }
        .rt-error__button--primary {
            background: #e4002b;
            border-color: #e4002b;
            color: #ffffff;
        }
        .rt-error__button--primary:hover { background: #c90026; border-color: #c90026; color: #ffffff; }
        .rt-error__hint { margin-top: 1.25rem; font-size: 0.8rem; color: var(--rt-muted); }
        @media (max-width: 380px) {
            .rt-error { padding: 1.5rem 1.1rem; }
            .rt-error__actions { flex-direction: column; }
            .rt-error__button { width: 100%; }
        }
    </style>
    {{-- Zusaetzliche Kopfzeilen (z. B. die Meta-Weiterleitung der 419-Seite).
         Inhalt stammt ausschliesslich aus eigenen Views, nie aus Eingaben. --}}
    {!! $headExtra ?? '' !!}
</head>
<body>
    <main class="rt-error" role="main" data-error-page="{{ $status }}">
        <img class="rt-error__logo" src="{{ asset('rt-brand/rt-logo.svg') }}" alt="{{ config('app.name', 'RailTime') }}">

        <span class="rt-error__code">{{ __('app.error') }} {{ $status }}</span>
        <h1 class="rt-error__title">{{ $title }}</h1>
        <p class="rt-error__text">{{ $text }}</p>

        {!! $body ?? '' !!}

        <div class="rt-error__actions">
            <a class="rt-error__button rt-error__button--primary" href="{{ url('/') }}">{{ __('app.back_to_start') }}</a>
            @if ($isServerError)
                <a class="rt-error__button" href="{{ url()->current() }}">{{ __('app.try_again') }}</a>
            @endif
        </div>

        @if (filled($hint ?? null))
            <p class="rt-error__hint">{{ $hint }}</p>
        @endif
    </main>
</body>
</html>
