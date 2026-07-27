<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class AppShellRedesignTest extends TestCase
{
    public function test_shared_content_uses_the_full_available_width(): void
    {
        $layout = file_get_contents(resource_path('views/layouts/master.blade.php'));

        $this->assertStringContainsString('container-fluid w-full max-w-none', $layout);
        $this->assertStringNotContainsString('max-w-[100rem]', $layout);
        $this->assertStringContainsString('id="main-content"', $layout);
    }

    public function test_page_header_keeps_actions_and_help_accessible_on_mobile(): void
    {
        $html = Blade::render(<<<'BLADE'
            <x-ui.page-header
                title="Mitarbeiter"
                description="Konten und Zugriffe im Blick behalten."
                :help="['title' => 'Seitenhilfe', 'summary' => 'Kurzfassung', 'points' => []]"
            >
                <x-slot:actions>
                    <a href="/employees">Mitarbeiter öffnen</a>
                    <button type="button">Einladen</button>
                </x-slot:actions>
            </x-ui.page-header>
        BLADE);

        $this->assertStringContainsString('data-page-header', $html);
        $this->assertStringContainsString('data-page-header-actions', $html);
        $this->assertStringContainsString('data-page-info-button', $html);
        $this->assertStringContainsString('w-full min-w-0', $html);
        $this->assertStringContainsString('Mitarbeiter öffnen', $html);
        $this->assertStringContainsString('Informationen zu dieser Seite', $html);
    }

    public function test_navigation_loader_uses_the_kinetic_railtime_stage_and_gsap(): void
    {
        $script = file_get_contents(resource_path('js/app.js'));
        $styles = file_get_contents(resource_path('css/app.css'));

        $this->assertStringContainsString('rt-nav-loader__wordmark', $script);
        $this->assertStringContainsString("window.gsap.timeline", $script);
        $this->assertStringContainsString("window.gsap.fromTo(signal", $script);
        $this->assertStringContainsString("document.querySelectorAll('#rt-nav-overlay')", $script);
        $this->assertStringContainsString('.rt-nav-loader__track', $styles);
        $this->assertStringContainsString('@media (prefers-reduced-motion: reduce)', $styles);
    }

    public function test_light_and_dark_shell_tokens_share_one_semantic_palette(): void
    {
        $tailwind = file_get_contents(base_path('tailwind.config.js'));
        $styles = file_get_contents(resource_path('css/app.css'));

        $this->assertStringContainsString("canvas: '#f2f5f9'", $tailwind);
        $this->assertStringContainsString("canvas: '#080d16'", $tailwind);
        $this->assertStringContainsString("'surface-muted': '#f7f9fc'", $tailwind);
        $this->assertStringContainsString("'surface-muted': '#182435'", $tailwind);
        $this->assertStringContainsString('background-color: #080d16', $styles);
        $this->assertStringContainsString('color: #edf2f8', $styles);
    }
}
