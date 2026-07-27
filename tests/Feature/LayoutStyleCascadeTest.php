<?php

namespace Tests\Feature;

use App\Support\Sound\SoundLibrary;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class LayoutStyleCascadeTest extends TestCase
{
    public function test_page_styles_are_declared_after_the_global_vite_styles(): void
    {
        foreach (['master', 'app', 'admin', 'guest'] as $layoutName) {
            $layout = file_get_contents(resource_path("views/layouts/{$layoutName}.blade.php"));
            $vitePosition = strpos($layout, "@vite(['resources/css/app.css");
            $livewirePosition = strpos($layout, '@livewireStyles');
            $yieldPosition = strpos($layout, "@yield('css')");
            $stackPosition = strpos($layout, "@stack('styles')");

            $this->assertNotFalse($vitePosition, "{$layoutName} layout must load the global app stylesheet.");
            $this->assertNotFalse($livewirePosition, "{$layoutName} layout must load Livewire styles.");
            $this->assertNotFalse($yieldPosition, "{$layoutName} layout must expose the page CSS section.");
            $this->assertNotFalse($stackPosition, "{$layoutName} layout must expose the page style stack.");
            $this->assertGreaterThan($vitePosition, $yieldPosition, "{$layoutName} page CSS must follow Vite styles.");
            $this->assertGreaterThan($livewirePosition, $yieldPosition, "{$layoutName} page CSS must follow Livewire styles.");
            $this->assertGreaterThan($yieldPosition, $stackPosition, "{$layoutName} pushed styles must render last.");
        }

        $headCss = file_get_contents(resource_path('views/layouts/head-css.blade.php'));
        $this->assertStringNotContainsString("@yield('css')", $headCss);
        $this->assertStringNotContainsString("@stack('styles')", $headCss);
    }

    public function test_legacy_layout_keeps_page_styles_after_its_global_styles(): void
    {
        $layout = file_get_contents(resource_path('views/layouts/master-without-nav.blade.php'));
        $globalStylesPosition = strpos($layout, "@include('layouts.head-css')");
        $livewirePosition = strpos($layout, '@livewireStyles');
        $yieldPosition = strpos($layout, "@yield('css')");
        $stackPosition = strpos($layout, "@stack('styles')");

        $this->assertNotFalse($globalStylesPosition);
        $this->assertNotFalse($livewirePosition);
        $this->assertNotFalse($yieldPosition);
        $this->assertNotFalse($stackPosition);
        $this->assertGreaterThan($globalStylesPosition, $yieldPosition);
        $this->assertGreaterThan($livewirePosition, $yieldPosition);
        $this->assertGreaterThan($yieldPosition, $stackPosition);
    }

    public function test_auth_assets_render_in_the_guest_head_and_not_inside_the_component_body(): void
    {
        foreach (SoundLibrary::defaults() as $event => $signature) {
            Cache::put("settings.sounds.{$event}", $signature);
        }

        $html = Blade::render(<<<'BLADE'
            <x-guest-layout>
                <x-auth-brand-layout title="Login">
                    <form><input type="email" name="email"></form>
                </x-auth-brand-layout>
            </x-guest-layout>
        BLADE);
        [$head, $body] = explode('</head>', $html, 2);

        $this->assertStringContainsString('rt-brand/rt-auth.css', $head);
        $this->assertStringContainsString('rt-brand/rt-logo.svg', $head);
        $this->assertStringContainsString('fonts.googleapis.com', $head);
        $this->assertStringContainsString('fonts.gstatic.com', $head);
        $this->assertStringNotContainsString('rt-brand/rt-auth.css', $body);
        $this->assertStringNotContainsString('rel="preconnect"', $body);
        $this->assertStringContainsString('data-rt-logo-3d', $body);
        $this->assertStringContainsString('rt-brand/js/logo-3d.js', $body);

        $guestLayout = file_get_contents(resource_path('views/layouts/guest.blade.php'));
        $component = file_get_contents(resource_path('views/components/auth-brand-layout.blade.php'));
        $vitePosition = strpos($guestLayout, "@vite(['resources/css/app.css");
        $authStylePosition = strpos($guestLayout, 'rt-brand/rt-auth.css');
        $pageStylePosition = strpos($guestLayout, "@yield('css')");

        $this->assertGreaterThan($vitePosition, $authStylePosition);
        $this->assertGreaterThan($authStylePosition, $pageStylePosition);
        $this->assertStringNotContainsString('rt-brand/rt-auth.css', $component);
        $this->assertStringNotContainsString('fonts.googleapis.com', $component);
        $this->assertStringNotContainsString('rel="icon"', $component);
    }
}
