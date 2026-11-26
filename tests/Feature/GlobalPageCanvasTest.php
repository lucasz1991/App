<?php

namespace Tests\Feature;

use Tests\TestCase;

class GlobalPageCanvasTest extends TestCase
{
    public function test_app_bundle_owns_the_exact_disposition_canvas_for_both_existing_theme_modes(): void
    {
        // Seit Phase 6 ist tokens.css (erster Import von app.css) die Quelle:
        // --rt-app-canvas zeigt auf das semantische Canvas-Token, das unter
        // html.dark auf die dunkle Palette wechselt.
        $css = $this->source('resources/css/tokens.css');
        $light = $this->rule($css, ':root');
        $dark = $this->rule($css, 'html.dark');

        $this->assertSame('var(--rt-color-canvas)', $this->declaration($light, '--rt-app-canvas'));
        $this->assertSame('rgb(var(--rt-canvas-rgb))', $this->declaration($light, '--rt-color-canvas'));
        $this->assertSame('rgb(var(--rt-dark-canvas-rgb))', $this->declaration($dark, '--rt-color-canvas'));
        $this->assertSame('220 227 237', $this->declaration($light, '--rt-canvas-rgb'));
        $this->assertSame('3 6 11', $this->declaration($light, '--rt-dark-canvas-rgb'));
        $this->assertSame(1, preg_match_all('/--rt-app-canvas\s*:/', $css));
        $this->assertSame(0, preg_match_all('/--rt-app-canvas\s*:/', $this->source('resources/css/app.css')));
        $this->assertStringContainsString("@import './tokens.css';", $this->source('resources/css/app.css'));
        $this->assertStringNotContainsString('--rt-shell-canvas', $dark);
        foreach ([$light, $dark] as $mode) {
            $this->assertStringNotContainsString('--portal-platform', $mode);
        }
    }

    public function test_canvas_is_unconditional_and_overrides_legacy_page_colors_without_repainting_content_surfaces(): void
    {
        $css = $this->source('resources/css/app.css');
        $paint = $this->rule($css, 'body, #main-content, .rt-app-page-frame');

        $this->assertSame('var(--rt-app-canvas) !important', $this->declaration($paint, 'background-color'));
        $this->assertSame(1, preg_match_all('/background-color\s*:\s*var\(--rt-app-canvas\)\s*!important\s*;/', $css));
        // Only the page base is painted. Ambient layers and the independent
        // chrome, cards, document surfaces and scroll geometry remain intact.
        foreach (['background-image', 'background:', 'height:', 'overflow:', 'transition:', 'animation:'] as $unrelated) {
            $this->assertStringNotContainsString($unrelated, $paint);
        }
        $this->assertDoesNotMatchRegularExpression('/(?:^|[},])\s*(?:\*|main|section|\.rt-ui-surface)\s*[,\{][^{}]*background-color\s*:\s*var\(--rt-app-canvas\)/s', $css);
    }

    public function test_shell_canvas_aliases_the_app_owner_and_keeps_distinct_navigation_and_card_surfaces(): void
    {
        // Die Shell-Token sind Aliase in tokens.css; sie folgen dem Theme ueber
        // die semantischen Token und werden nur einmal (unter :root) definiert.
        $css = $this->source('resources/css/tokens.css');
        $light = $this->rule($css, ':root');

        $this->assertSame('var(--rt-app-canvas)', $this->declaration($light, '--rt-shell-canvas'));
        foreach (['--rt-shell-surface-solid', '--rt-shell-topbar', '--rt-shell-sidebar', '--rt-shell-panel'] as $surface) {
            $this->assertNotSame('var(--rt-app-canvas)', $this->declaration($light, $surface));
        }
        $this->assertSame(1, preg_match_all('/--rt-shell-canvas\s*:\s*var\(--rt-app-canvas\)\s*;/', $css));
        $this->assertSame(0, preg_match_all('/--rt-shell-canvas\s*:/', $this->source('resources/css/shell.css')));
    }

    public function test_personnel_frames_no_longer_cover_the_global_canvas_but_keep_their_calm_ambient_contract(): void
    {
        $css = $this->source('resources/css/personnel-workspace.css');
        $ambient = $this->rule($css, '.rt-app-page-frame:has(:is(.rt-personnel-page, .rt-personal-workspace-page, .employee-workspace)) .rt-shell-ambient');

        $this->assertSame('none !important', $this->declaration($ambient, 'display'));
        $this->assertDoesNotMatchRegularExpression('/[^{}]*\.rt-app-page-frame[^{}]*\{[^{}]*background(?:-color)?\s*:/s', $css);
        $this->assertStringContainsString('--personnel-inset:', $this->rule($css, '.rt-personnel-page'));
        $this->assertStringContainsString('--personnel-inset:', $this->rule($css, 'html.dark .rt-personnel-page'));
    }

    public function test_customer_portal_uses_the_same_canvas_without_a_cyclic_variable_alias(): void
    {
        $css = $this->source('resources/css/customer-portal.css');
        $light = $this->rule($css, '.rt-customer-portal-page');
        $dark = $this->rule($css, 'html.dark .rt-customer-portal-page');

        foreach ([$light, $dark] as $mode) {
            $this->assertSame('var(--rt-app-canvas)', $this->declaration($mode, '--portal-platform'));
            $this->assertNotSame('var(--rt-app-canvas)', $this->declaration($mode, '--portal-paper'));
            $this->assertNotSame('var(--rt-app-canvas)', $this->declaration($mode, '--portal-inset'));
        }
        $this->assertSame('var(--portal-platform)', $this->declaration($light, '--rt-shell-canvas'));
        $this->assertStringNotContainsString('--rt-app-canvas:', $css);
        $this->assertSame(2, preg_match_all('/--portal-platform\s*:\s*var\(--rt-app-canvas\)\s*;/', $css));
        $this->assertStringContainsString("@import './customer-portal.css';", $this->source('resources/css/app.css'));
    }

    public function test_every_active_native_app_and_auth_layout_loads_the_shared_app_bundle(): void
    {
        foreach ([
            'layouts/master.blade.php',
            'layouts/guest.blade.php',
            'layouts/customer-portal.blade.php',
            'components/customer-portal-auth-layout.blade.php',
        ] as $path) {
            $layout = $this->source('resources/views/'.$path);
            // master.blade.php setzt die Liste per array_merge() zusammen
            // (Anrufmodul nur fuer angemeldete Nutzer); app.css bleibt der erste Eintrag.
            $this->assertMatchesRegularExpression('/@vite\((?:array_merge\(\s*)?\[\s*[\'"]resources\/css\/app\.css[\'"]/', $layout, $path);
            $this->assertStringContainsString('$store.theme?.dark', $layout, $path);
        }

        // Guest and portal authentication do not load shell.css;
        // placing the canonical token in app.css is what covers them too.
        foreach (['layouts/guest.blade.php', 'components/customer-portal-auth-layout.blade.php'] as $path) {
            $this->assertStringNotContainsString('resources/css/shell.css', $this->source('resources/views/'.$path));
        }
        $this->assertStringContainsString('v=20261123-local-fonts', $this->source('resources/views/layouts/guest.blade.php'));
    }

    public function test_all_four_auth_page_bases_use_the_shared_canvas_and_keep_existing_transparent_brand_layers(): void
    {
        $css = $this->source('public/rt-brand/rt-auth.css');
        foreach ([
            '.rt-auth' => '#03060b',
            'html:not(.dark) .rt-auth' => '#dce3ed',
            '.rt-auth--premium-login' => '#dce3ed',
            'html.dark .rt-auth--premium-login' => '#03060b',
        ] as $selector => $fallback) {
            $background = $this->declaration($this->rule($css, $selector), 'background');
            $this->assertStringContainsString('radial-gradient(', $background, $selector);
            $this->assertStringContainsString('transparent', $background, $selector);
            $this->assertStringEndsWith('var(--rt-app-canvas, '.$fallback.')', $background, $selector);
            $this->assertStringNotContainsString('linear-gradient(', $background, $selector);
        }

        $this->assertSame(4, substr_count($css, 'var(--rt-app-canvas,'));
        foreach (['.rt-auth-brand__logo', '.rt-logo-stage::before', '.rt-auth-card__surface--premium', 'html.dark .rt-auth-card__surface--premium'] as $selector) {
            $this->assertStringNotContainsString('--rt-app-canvas', $this->rule($css, $selector));
        }
    }

    public function test_semantic_tailwind_canvas_utilities_agree_with_the_canonical_page_colors(): void
    {
        // Tailwind liest die Seitenfarben als Kanal-Tripel aus tokens.css.
        $config = $this->source('tailwind.config.js');
        $tokens = $this->source('resources/css/tokens.css');
        $this->assertStringContainsString("canvas: 'rgb(var(--rt-canvas-rgb) / <alpha-value>)'", $config);
        $this->assertStringContainsString("canvas: 'rgb(var(--rt-dark-canvas-rgb) / <alpha-value>)'", $config);
        $this->assertStringContainsString('--rt-canvas-rgb: 220 227 237;', $tokens);
        $this->assertStringContainsString('--rt-dark-canvas-rgb: 3 6 11;', $tokens);
        $this->assertStringContainsString('--rt-app-canvas: var(--rt-color-canvas);', $tokens);
        // Alle Auth-Seiten laufen ueber x-auth-brand-layout (authentication-card ist entfernt).
        $card = $this->source('resources/views/components/auth-brand-layout.blade.php');
        $this->assertStringContainsString('bg-rt-surface', $card);
        $this->assertStringContainsString('dark:bg-rt-dark-surface', $card);
    }

    public function test_existing_theme_store_remains_the_only_runtime_owner_and_print_reports_stay_standalone(): void
    {
        foreach (['resources/js/theme.js', 'resources/js/customer-portal.js'] as $path) {
            $js = $this->source($path);
            $this->assertStringContainsString("Alpine.store('theme',", $js);
            $this->assertStringContainsString("localStorage.getItem('rt-theme')", $js);
            $this->assertStringContainsString("localStorage.setItem('rt-theme'", $js);
            $this->assertStringNotContainsString('--rt-app-canvas', $js);
        }

        $report = $this->source('resources/views/customer-portal/report-print.blade.php');
        $this->assertStringContainsString('@page{size:A4 landscape', $report);
        $this->assertStringContainsString('@media print', $report);
        $this->assertStringNotContainsString('@vite', $report);
        $this->assertStringNotContainsString('--rt-app-canvas', $report);
        $this->assertStringNotContainsString('rt-app-page-frame', $report);
    }

    private function source(string $path): string
    {
        $source = file_get_contents(base_path($path));
        $this->assertIsString($source, $path);

        return $source;
    }

    private function rule(string $css, string $selector): string
    {
        $css = preg_replace('/\/\*.*?\*\//s', '', $css);
        $css = preg_replace('/@(?:import|tailwind)\s+[^;]+;/', '', $css);
        preg_match_all('/([^{}]+)\{([^{}]*)\}/s', $css, $rules, PREG_SET_ORDER);
        $expected = $this->normalizeSelector($selector);
        foreach ($rules as $rule) {
            if ($this->normalizeSelector($rule[1]) === $expected) {
                return $rule[2];
            }
        }

        $this->fail('Missing scoped CSS rule: '.$selector);
    }

    private function normalizeSelector(string $selector): string
    {
        return preg_replace('/\s+/', ' ', trim($selector));
    }

    private function declaration(string $rule, string $property): string
    {
        $this->assertSame(1, preg_match('/(?:^|;)\s*'.preg_quote($property, '/').'\s*:\s*([^;]+);/s', $rule, $match), $property);

        return trim($match[1]);
    }
}
