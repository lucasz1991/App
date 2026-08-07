<?php

namespace Tests\Feature;

use Tests\TestCase;

class MarketingBuilderVendorIntegrityTest extends TestCase
{
    /** @var array<string, string> */
    private const EXPECTED_SHA256 = [
        'lmz-builder.js' => 'EF398B4F114D123B35F88103E7AFFC29ABA534DA738F6ADCA0B866E3946DA53E',
        'grapesjs.js' => '1511DA9E52323A75F7329645778749FEAA11245BBADB93C8292A03C080283048',
        'lmz-builder.css' => '62C9335D4B7416F1CF3FDB4DE52A80F2F92CD888322323CDC028AF1A3C5C8E70',
        'grapesjs.css' => '2DAA348D7C55F4E9DB7A3C7FD775AE4DFD651FFF56498559E569E4AF249C954A',
    ];

    public function test_versioned_builder_runtime_matches_the_approved_joomla_245_assets(): void
    {
        $target = public_path('vendor/lmz-builder/2.4.5');

        foreach (self::EXPECTED_SHA256 as $file => $expectedHash) {
            $path = $target.DIRECTORY_SEPARATOR.$file;
            $this->assertFileExists($path);
            $this->assertSame($expectedHash, strtoupper(hash_file('sha256', $path) ?: ''), $file);
        }

        $notice = file_get_contents($target.DIRECTORY_SEPARATOR.'THIRD_PARTY_NOTICES.md');
        $this->assertIsString($notice);
        $this->assertStringContainsString('GrapesJS 0.22.14', $notice);
        $this->assertStringContainsString('media/com_lmzpagebuilder/{js,css}', $notice);
        $this->assertStringContainsString('do not depend on GrapesJS Studio SDK', $notice);
    }

    public function test_marketing_views_keep_the_builder_and_navigation_contracts_visible(): void
    {
        $editor = file_get_contents(resource_path('views/livewire/admin/marketing/creative-editor.blade.php'));
        $assets = file_get_contents(resource_path('views/livewire/admin/marketing/assets-index.blade.php'));
        $sidebar = file_get_contents(resource_path('views/layouts/admin-sidebar.blade.php'));

        $this->assertIsString($editor);
        $this->assertIsString($assets);
        $this->assertIsString($sidebar);

        $this->assertStringContainsString('data-marketing-editor-root', $editor);
        $this->assertStringContainsString('wire:ignore', $editor);
        $this->assertStringContainsString("'story' => ['Story', '1080 × 1920']", $editor);
        $this->assertStringContainsString("'post' => ['Post', '1080 × 1080']", $editor);
        $this->assertStringContainsString("'web' => ['Web', '1200 × 630']", $editor);
        $this->assertStringContainsString('data-marketing-safe-zone', $editor);
        $this->assertStringContainsString('data-marketing-export', $editor);

        $this->assertStringContainsString('JPEG, PNG, WebP oder GIF · maximal 8 MB', $assets);
        $this->assertStringContainsString('x-on:change="replace(', $assets);
        $this->assertStringContainsString("route('admin.marketing.creatives.index')", $sidebar);
        $this->assertStringContainsString("route('admin.marketing.assets.index')", $sidebar);
    }
}
