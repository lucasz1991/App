<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Sichert die beiden Frontend-Notbremsen ab:
 *  - Das Seitenwechsel-Overlay kann nicht dauerhaft sichtbar haengen bleiben.
 *  - Ein abgestuerztes Alpine laedt die Seite genau einmal kontrolliert neu.
 */
class FrontendResilienceTest extends TestCase
{
    public function test_navigation_overlay_cannot_stay_visible_forever(): void
    {
        $script = file_get_contents(resource_path('js/app.js'));

        // Zeitliche Notbremse: Livewires performFetch() hat kein .catch(), ein
        // abgebrochener Navigations-Request feuert daher nie 'livewire:navigated'.
        $this->assertStringContainsString('FAILSAFE_MS', $script);
        $this->assertStringContainsString('window.setTimeout(done, FAILSAFE_MS)', $script);

        // Jeder Weg, auf dem eine Navigation endet ODER scheitert, raeumt auf.
        foreach ([
            "window.addEventListener('popstate', done)",
            "window.addEventListener('pageshow', done)",
            "window.addEventListener('offline', done)",
            "window.addEventListener('unhandledrejection', done)",
            "window.addEventListener('error', done)",
        ] as $listener) {
            $this->assertStringContainsString($listener, $script);
        }

        // Nach einer erfolgreichen Navigation wird zuerst aufgeraeumt und
        // erst danach die dezente Content-Einblendung abgespielt.
        $this->assertMatchesRegularExpression(
            "/document\\.addEventListener\\('livewire:navigated', function \\(\\) \\{\\s*done\\(\\);\\s*playContentEntrance\\(\\);/",
            $script
        );

        // Der Zurueck-Button stellt eine HTML-Kopie der Seite wieder her. Ein
        // darin enthaltener Overlay-Klon muss mit aufgeraeumt werden ...
        $this->assertStringContainsString("document.querySelectorAll('#rt-nav-overlay')", $script);
        // ... und darf gar nicht erst in die Kopie wandern.
        $this->assertStringContainsString('overlay.remove()', $script);

        // Andere Module (Alpine-Watchdog) muessen das Overlay entfernen koennen.
        $this->assertStringContainsString('window.RTNavOverlay', $script);
    }

    public function test_alpine_watchdog_is_installed_before_every_other_module(): void
    {
        $script = file_get_contents(resource_path('js/app.js'));

        $watchdogImport = strpos($script, "import './alpine-watchdog'");
        $this->assertNotFalse($watchdogImport, 'Der Alpine-Watchdog muss in app.js importiert werden.');

        // ES-Imports werden gehoistet: nur als ERSTER Import stehen die
        // Fehler-Listener, bevor die anderen Module ausgewertet werden.
        foreach ([
            "import './bootstrap'",
            "from '../../vendor/livewire/livewire/dist/livewire.esm'",
            "import './gsap'",
        ] as $laterImport) {
            $position = strpos($script, $laterImport);
            $this->assertNotFalse($position, "Import nicht gefunden: {$laterImport}");
            $this->assertLessThan($position, $watchdogImport);
        }
    }

    public function test_alpine_watchdog_only_reloads_on_a_real_error_and_never_loops(): void
    {
        $guard = file_get_contents(resource_path('js/alpine-watchdog.js'));

        // Der Watchdog darf selbst NICHTS importieren — sonst wuerde sein
        // Modul die Auswertungsreihenfolge verschieben, die er gerade sichern
        // soll. Geprueft wird auf echte import-Anweisungen, nicht auf blosse
        // Vorkommen im Kommentar.
        $this->assertDoesNotMatchRegularExpression('/^\s*import\s/m', $guard);

        // Ausloeser sind ausschliesslich echte JavaScript-Fehler ...
        $this->assertStringContainsString("window.addEventListener('error'", $guard);
        $this->assertStringContainsString("window.addEventListener('unhandledrejection'", $guard);
        // ... nicht fehlgeschlagene Ressourcen (Bild, Stylesheet, Script-Tag).
        $this->assertStringContainsString('event.target !== window', $guard);

        // Neu geladen wird nur bei tatsaechlich defektem Alpine.
        $this->assertStringContainsString('alpineIsHealthy', $guard);
        $this->assertStringContainsString('window.location.reload()', $guard);

        // Und niemals in einer Schleife.
        $this->assertStringContainsString('MAX_ATTEMPTS', $guard);
        $this->assertStringContainsString('ATTEMPT_WINDOW_MS', $guard);
        $this->assertStringContainsString('sessionStorage', $guard);

        // Kein Reload offline oder mitten in einer Eingabe des Nutzers.
        $this->assertStringContainsString('navigator.onLine === false', $guard);
        $this->assertStringContainsString('isContentEditable', $guard);

        // Ein haengendes Seitenwechsel-Overlay wird vorher entfernt.
        $this->assertStringContainsString('window.RTNavOverlay?.hide()', $guard);
    }
}
