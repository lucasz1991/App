<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Alle Fehlerseiten nutzen eine gemeinsame, markengerechte Vorlage. Sie muss
 * ohne App-Shell, ohne Livewire und ohne Session-/DB-Zugriff rendern — sonst
 * scheitert genau die 500-Seite, die gebraucht wird (SESSION_DRIVER=database).
 */
class ErrorPagesTest extends TestCase
{
    /**
     * @return array<string, array{int, string}>
     */
    public static function statusProvider(): array
    {
        return [
            '400' => [400, 'error_4xx_title'],
            '401' => [401, 'error_401_title'],
            '403' => [403, 'error_403_title'],
            '404' => [404, 'error_404_title'],
            '405' => [405, 'error_405_title'],
            '419' => [419, 'error_419_title'],
            '422' => [422, 'error_422_title'],
            '429' => [429, 'error_429_title'],
            '500' => [500, 'error_500_title'],
            '503' => [503, 'error_503_title'],
        ];
    }

    /**
     * @dataProvider statusProvider
     */
    public function test_every_status_renders_the_shared_branded_page(int $status, string $titleKey): void
    {
        $view = view()->exists("errors.{$status}")
            ? "errors.{$status}"
            : 'errors.'.substr((string) $status, 0, 1).'xx';

        $this->assertTrue(view()->exists($view), "Keine View fuer Status {$status}");

        $html = view($view, [
            'exception' => new \Symfony\Component\HttpKernel\Exception\HttpException($status, 'test'),
        ])->render();

        // Statusgerechte Ansprache ...
        $this->assertStringContainsString('data-error-page="'.$status.'"', $html);
        $this->assertStringContainsString(__("app.{$titleKey}"), $html);

        // ... markengerechte Darstellung ohne App-Shell ...
        $this->assertStringContainsString('rt-logo.svg', $html);
        $this->assertStringContainsString('--rt-canvas', $html);
        $this->assertStringNotContainsString('wire:', $html);
        $this->assertStringNotContainsString('x-data', $html);

        // ... und die vollstaendige Icon-Kette.
        $this->assertStringContainsString('rel="icon"', $html);
        $this->assertStringContainsString('rel="apple-touch-icon"', $html);
    }

    public function test_shared_page_never_touches_session_or_database(): void
    {
        $page = File::get(resource_path('views/errors/partials/page.blade.php'));

        // Bei SESSION_DRIVER=database wuerde auth() auf einer 500-Seite die
        // Datenbank anfassen und beim Rendern selbst scheitern.
        foreach (['@auth', '@guest', 'auth()', 'Auth::', 'session(', '$errors->'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $page, "Fehlerseite darf {$forbidden} nicht verwenden");
        }

        // Deshalb bindet sie das reine Icon-Partial ein, nicht pwa-head.
        $this->assertStringContainsString("@include('layouts.icon-head')", $page);
        $this->assertStringNotContainsString("@include('layouts.pwa-head')", $page);
    }

    public function test_expired_session_page_recovers_without_user_interaction(): void
    {
        $html = view('errors.419', [
            'exception' => new \Symfony\Component\HttpKernel\Exception\HttpException(419, 'expired'),
        ])->render();

        // Sofortige Wiederherstellung per JavaScript ...
        $this->assertStringContainsString('window.location.replace(', $html);
        // ... und ein Fallback ohne JavaScript.
        $this->assertStringContainsString('http-equiv="refresh"', $html);
        // Schleifenschutz mit Zeitfenster.
        $this->assertStringContainsString('rt-419-recovered-at', $html);

        // Kein Reload: die 419-Antwort gehoert zu einem POST, ein Reload wuerde
        // ihn erneut senden. Es wird per GET auf die Ausgangsseite gewechselt.
        $this->assertStringNotContainsString('location.reload()', $html);

        // Keine Aufforderung an den Nutzer.
        $this->assertStringNotContainsString('Erneut anmelden', $html);
        $this->assertStringNotContainsString('confirm(', $html);
    }

    public function test_livewire_requests_recover_from_419_without_the_confirm_dialog(): void
    {
        $script = File::get(resource_path('js/app.js'));

        // Livewire zeigt sonst confirm("This page has expired...").
        // Der fail-Hook laeuft davor und bricht das mit preventDefault() ab.
        $this->assertStringContainsString("Livewire.hook('request'", $script);
        $this->assertStringContainsString('status !== 419', $script);
        $this->assertStringContainsString('preventDefault();', $script);
        $this->assertStringContainsString('rt-419-recovered-at', $script);
        $this->assertStringContainsString('window.location.reload()', $script);
    }

    public function test_handler_leaves_html_rendering_to_laravel_but_keeps_json_messages(): void
    {
        $handler = File::get(app_path('Exceptions/Handler.php'));

        // Frueher zeigte jede Closure auf eine eigene View — errors.403 gab es
        // gar nicht, wodurch ein 403 zu einem 500 wurde.
        $this->assertStringNotContainsString("response()->view('errors.", $handler);

        // JSON behaelt die deutschen Meldungen.
        $this->assertStringContainsString('Seite nicht gefunden', $handler);
        $this->assertStringContainsString('Die Sitzung ist abgelaufen', $handler);
        $this->assertStringContainsString('keine Berechtigung', $handler);
    }

    public function test_unknown_route_returns_json_message_for_api_clients(): void
    {
        $this->getJson('/gibt-es-nicht-xyz')
            ->assertStatus(404)
            ->assertJson(['message' => 'Seite nicht gefunden. Die angeforderte Seite existiert nicht.']);
    }
}
