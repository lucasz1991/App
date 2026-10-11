<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class StandalonePageCanvasTest extends TestCase
{
    private string $compiledViews;

    protected function setUp(): void
    {
        parent::setUp();

        $this->compiledViews = sys_get_temp_dir().'/railtime-standalone-canvas-'.bin2hex(random_bytes(8));
        File::ensureDirectoryExists($this->compiledViews);
        config(['view.compiled' => $this->compiledViews]);
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        try {
            File::deleteDirectory($this->compiledViews);
        } finally {
            parent::tearDown();
        }
    }

    /**
     * @return array<string, array{string, int}>
     */
    public static function standalonePages(): array
    {
        return [
            'client error' => ['errors.4xx', 404],
            'expired session' => ['errors.419', 419],
            'server error' => ['errors.5xx', 500],
            'maintenance' => ['errors.maintenance', 503],
        ];
    }

    #[DataProvider('standalonePages')]
    public function test_standalone_pages_use_the_application_canvas_in_all_theme_branches(string $view, int $status): void
    {
        $html = view($view, ['exception' => new HttpException($status, 'synthetic fixture')])->render();

        $this->assertMatchesRegularExpression('/:root\s*\{[^}]*--rt-canvas:\s*#dce3ed;/s', $html);
        $this->assertMatchesRegularExpression('/html\.rt-dark\s*\{[^}]*--rt-canvas:\s*#03060b;/s', $html);
        $this->assertMatchesRegularExpression('/@media\s*\(prefers-color-scheme:\s*dark\)\s*\{\s*html:not\(\.rt-light\)\s*\{[^}]*--rt-canvas:\s*#03060b;/s', $html);
        $this->assertStringNotContainsString('--rt-canvas: #f3f6fa', $html);
        $this->assertStringNotContainsString('--rt-canvas: #0b1120', $html);
        $this->assertStringContainsString("localStorage.getItem('rt-theme')", $html);
    }

    #[DataProvider('standalonePages')]
    public function test_canvas_alignment_retains_standalone_rendering_without_queries_or_bundles(string $view, int $status): void
    {
        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $html = view($view, ['exception' => new HttpException($status, 'synthetic fixture')])->render();

        $this->assertSame(0, $queries);
        $this->assertStringContainsString('rt-logo.svg', $html);
        $this->assertStringContainsString('background: var(--rt-surface)', $html);
        $this->assertStringContainsString('radial-gradient(', $html);

        foreach (['wire:', 'x-data', '/build/assets/', '@vite', 'livewire.js', 'alpine.js'] as $dependency) {
            $this->assertStringNotContainsString($dependency, $html);
        }

        Http::assertNothingSent();
    }

    #[DataProvider('standalonePages')]
    public function test_standalone_browser_chrome_uses_the_matching_canvas_colors(string $view, int $status): void
    {
        $html = view($view, ['exception' => new HttpException($status, 'synthetic fixture')])->render();

        $this->assertStringContainsString('<meta name="theme-color" content="#dce3ed" media="(prefers-color-scheme: light)">', $html);
        $this->assertStringContainsString('<meta name="theme-color" content="#03060b" media="(prefers-color-scheme: dark)">', $html);
        $this->assertStringNotContainsString('<meta name="theme-color" content="#f3f6fa"', $html);
        $this->assertStringNotContainsString('<meta name="theme-color" content="#0b1120"', $html);
    }

    public function test_standalone_templates_remain_independent_of_authentication_and_database_state(): void
    {
        foreach (['errors/partials/page.blade.php', 'errors/maintenance.blade.php'] as $template) {
            $source = File::get(resource_path('views/'.$template));

            foreach (['@auth', '@guest', 'auth()', 'Auth::', 'session(', '$errors->', '@vite', '@livewire', 'layouts.pwa-head'] as $dependency) {
                $this->assertStringNotContainsString($dependency, $source);
            }

            $this->assertStringContainsString("@include('layouts.icon-head')", $source);
        }
    }
}
