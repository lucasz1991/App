<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light dark">
    <meta name="robots" content="noindex, nofollow">
    <title>RailTime · PC mit Outlook koppeln</title>
    <style nonce="{{ $cspNonce }}">
        :root { color-scheme: light dark; --red: #e4002b; --ink: #15263c; --muted: #526176; --line: #d5dfe9; --bg: #f0f4f8; --card: #fff; }
        @media (prefers-color-scheme: dark) { :root { --ink: #e8eef6; --muted: #a3b1c4; --line: #2b3a4e; --bg: #0f1722; --card: #162131; } }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; padding: 24px 16px; background: var(--bg); color: var(--ink); font: 15px/1.55 "Segoe UI", Arial, sans-serif; }
        main { width: 100%; max-width: 440px; background: var(--card); border: 1px solid var(--line); border-radius: 16px; padding: 28px 24px; }
        .brand { display: flex; align-items: center; gap: 10px; font-weight: 700; letter-spacing: -.01em; }
        .brand span { width: 5px; height: 26px; border-radius: 3px; background: var(--red); }
        h1 { font-size: 21px; line-height: 1.3; margin: 22px 0 8px; }
        p { margin: 0 0 12px; color: var(--muted); }
        ol { margin: 0 0 18px; padding-left: 20px; color: var(--muted); }
        li { margin: 4px 0; }
        .action { display: flex; align-items: center; justify-content: center; min-height: 48px; border-radius: 11px; background: var(--red); color: #fff; font-weight: 650; text-decoration: none; }
        .action:focus-visible, a:focus-visible { outline: 3px solid #376fbe; outline-offset: 3px; }
        .secondary { display: inline-block; margin-top: 16px; color: var(--ink); }
    </style>
</head>
<body>
<main>
    <div class="brand"><span aria-hidden="true"></span>RailTime</div>
    @if ($valid)
        <h1>Diesen PC mit Outlook koppeln</h1>
        <p>Die RailTime-App öffnet sich gleich und fragt, ob du diesen PC koppeln möchtest.</p>
        <ol>
            <li>Fragt der Browser „RailTime öffnen?“, bitte zulassen.</li>
            <li>In der RailTime-App mit „OK“ bestätigen.</li>
            <li>Zurück in Outlook: die Kopplung erscheint von selbst.</li>
        </ol>
        <a class="action" href="{{ $appLink }}" data-app-link>RailTime-App öffnen</a>
        <p><a class="secondary" href="{{ $installUrl }}">Die App ist auf diesem PC noch nicht installiert?</a></p>
        <script nonce="{{ $cspNonce }}">
            // Einmal von selbst versuchen; der Knopf bleibt für Browser, die eine Nutzeraktion verlangen.
            window.addEventListener('load', function () {
                var link = document.querySelector('[data-app-link]');
                if (link) { window.location.href = link.getAttribute('href'); }
            }, { once: true });
        </script>
    @else
        <h1>Diese Kopplung ist abgelaufen</h1>
        <p>Eine Kopplungsanfrage gilt zwei Minuten. Bitte in Outlook unter „RailTime-Einstellungen“ erneut „Diesen PC koppeln“ wählen.</p>
        <p><a class="secondary" href="{{ $installUrl }}">RailTime-App installieren</a></p>
    @endif
</main>
</body>
</html>
