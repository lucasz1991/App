<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class AppShellRedesignTest extends TestCase
{
    public function test_shared_content_uses_the_full_available_width(): void
    {
        $layout = file_get_contents(resource_path('views/layouts/master.blade.php'));
        $shellStyles = file_get_contents(resource_path('css/shell-redesign.css'));

        $this->assertStringContainsString('container-fluid w-full max-w-none', $layout);
        $this->assertStringContainsString("'md:px-5' => ! \$viewportMode", $layout);
        $this->assertStringContainsString("'px-0' => \$viewportMode", $layout);
        $this->assertStringContainsString("'px-1' => ! \$viewportMode", $layout);
        $this->assertStringNotContainsString('max-w-[100rem]', $layout);
        $this->assertStringContainsString('id="main-content"', $layout);
        $this->assertMatchesRegularExpression(
            '/\.page-content\s*\{[^}]*left:\s*0\s*!important;[^}]*width:\s*100%;[^}]*max-width:\s*100%;/s',
            $shellStyles,
        );
    }

    public function test_shared_modals_escape_the_content_stacking_context(): void
    {
        $modal = file_get_contents(resource_path('views/components/modal.blade.php'));

        $this->assertStringContainsString('<template x-teleport="body">', $modal);
        $this->assertStringContainsString('fixed inset-0 z-[190]', $modal);
        $this->assertStringContainsString('x-trap.inert.noscroll="show"', $modal);
        $this->assertStringContainsString('aria-modal="true"', $modal);
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
        $this->assertStringContainsString('flex-nowrap', $html);
        $this->assertStringContainsString('truncate', $html);
        $this->assertStringNotContainsString('Konten und Zugriffe im Blick behalten.', $html);
        $this->assertStringContainsString('Mitarbeiter öffnen', $html);
        $this->assertStringContainsString('Informationen zu dieser Seite', $html);
    }

    public function test_navigation_loader_is_a_textless_orb_without_a_modal_panel(): void
    {
        $script = file_get_contents(resource_path('js/app.js'));
        $styles = file_get_contents(resource_path('css/app.css'));

        $this->assertStringContainsString('rt-nav-loader__orb', $script);
        $this->assertStringContainsString('window.gsap.fromTo(loader', $script);
        $this->assertStringNotContainsString('rt-nav-loader__wordmark', $script);
        $this->assertStringNotContainsString('rt-nav-loader__label', $script);
        $this->assertStringNotContainsString('SEITENWECHSEL', $script);
        $this->assertStringContainsString("document.querySelectorAll('#rt-nav-overlay')", $script);
        $this->assertStringContainsString('.rt-nav-loader::before', $styles);
        $this->assertStringContainsString('.rt-nav-loader__fluid--drift', $styles);
        $this->assertStringContainsString('.rt-nav-loader__fluid--counter', $styles);
        $this->assertStringContainsString('background: transparent', $styles);
        $this->assertStringNotContainsString('.rt-nav-loader__track', $styles);
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
        $this->assertStringContainsString('@layer rt-legacy-tabs', $styles);
    }

    public function test_temporary_visual_qa_login_bypass_is_not_part_of_the_app(): void
    {
        $login = file_get_contents(resource_path('views/auth/login.blade.php'));
        $routes = file_get_contents(base_path('routes/web.php'));

        $this->assertStringNotContainsString('data-dev-login', $login);
        $this->assertStringNotContainsString('Dev-Login', $login);
        $this->assertStringNotContainsString('__dev-login', $routes);
        $this->assertStringNotContainsString('loginUsingId(1)', $routes);
    }

    public function test_shell_uses_one_desktop_breakpoint_for_brand_sidebar_and_swipe(): void
    {
        $topbar = file_get_contents(resource_path('views/layouts/topbar.blade.php'));
        $script = file_get_contents(resource_path('js/app.js'));
        $appStyles = file_get_contents(resource_path('css/app.css'));
        $shellStyles = file_get_contents(resource_path('css/shell-redesign.css'));

        $this->assertStringContainsString('lg:hidden', $topbar);
        $this->assertStringNotContainsString('min-[1140px]:hidden', $topbar);
        $this->assertStringContainsString('window.innerWidth >= 1024', $script);
        $this->assertStringNotContainsString('window.innerWidth >= 1140', $script);
        $this->assertStringContainsString('@media (min-width: 1024px)', $appStyles);
        $this->assertStringContainsString('@media (min-width: 1024px)', $shellStyles);
        $this->assertStringContainsString('body.sidebar-enable .vertical-menu', $appStyles);
        $this->assertMatchesRegularExpression(
            '/@media \(max-width: 1023\.98px\).*?\.rt-shell-brand-mark,\s*\.vertical-menu-btn\s*\{[^}]*display:\s*flex\s*!important;.*?'
            .'\.topbar-brand \.navbar-brand,\s*\.vertical-menu\s*\{[^}]*display:\s*none\s*!important;/s',
            $appStyles,
        );
        $this->assertMatchesRegularExpression(
            '/@media \(min-width: 1024px\).*?\.rt-shell-brand-mark\s*\{[^}]*display:\s*none\s*!important;.*?'
            .'\.topbar-brand \.navbar-brand\s*\{[^}]*display:\s*flex\s*!important;.*?'
            .'\.vertical-menu\s*\{[^}]*display:\s*block\s*!important;/s',
            $appStyles,
        );
    }

    public function test_vengeance_glow_is_explicitly_scoped_and_cleans_up_before_navigation(): void
    {
        $motion = file_get_contents(resource_path('js/vengeance-motion.js'));
        $styles = file_get_contents(resource_path('css/app.css'));
        $shellStyles = file_get_contents(resource_path('css/shell-redesign.css'));
        $layout = file_get_contents(resource_path('views/layouts/master.blade.php'));

        $this->assertStringContainsString("const GLOW_SELECTOR = '[data-rt-glow]'", $motion);
        $this->assertStringContainsString("const AMBIENT_SELECTOR = '[data-rt-shell-ambient]'", $motion);
        $this->assertStringContainsString("gsap.quickTo(ambientPointer, 'x'", $motion);
        $this->assertStringContainsString('window.cancelAnimationFrame(pendingFrame)', $motion);
        $this->assertStringContainsString("removeProperty('--rt-glow-o')", $motion);
        $this->assertStringContainsString("event.pointerType === 'touch'", $motion);
        $this->assertStringContainsString("reducedMotion.addEventListener('change', resetGlow)", $motion);
        $this->assertStringContainsString('data-rt-shell-ambient', $layout);
        $this->assertStringContainsString('.rt-shell-ambient__pointer', $shellStyles);
        $this->assertStringContainsString('[data-rt-glow]::after', $styles);
        $this->assertStringContainsString('[data-rt-glow] > *', $styles);
        $this->assertStringNotContainsString('.rt-admin-panel::after', $styles);
        $this->assertStringNotContainsString('.rt-operational-stat::after', $styles);
    }
}
