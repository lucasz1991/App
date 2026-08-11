<?php

namespace Tests\Feature;

use App\Enums\MailDocumentKind;
use App\Enums\MarketingCreativeType;
use App\Models\MailDocument;
use App\Models\User;
use App\Services\Marketing\MarketingStudioService;
use Database\Seeders\MailDocumentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PageBuilderPreviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('private');
        config()->set('marketing.disk', 'private');
    }

    public function test_shared_preview_card_renders_without_loading_its_frame(): void
    {
        $title = 'Vorschau `${globalThis.__rtPreviewPwned=1}`';
        $html = Blade::render(
            '<x-ui.page-builder.preview-card :title="$title" :sources="$sources" />',
            [
                'title' => $title,
                'sources' => ['post' => [
                    'label' => 'Post',
                    'url' => '/preview',
                    'width' => 1080,
                    'height' => 1080,
                ]],
            ],
        );

        $this->assertStringContainsString('data-page-builder-preview-card', $html);
        $this->assertStringContainsString('sandbox=""', $html);
        $this->assertStringContainsString('x-bind:title="titlePrefix + (active?.label || \'Vorschau\')"', $html);
        $this->assertStringNotContainsString('x-bind:title="`', $html);
    }

    public function test_marketing_preview_is_admin_only_sandbox_ready_and_network_free(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $staff = User::factory()->create(['role' => 'staff']);
        $creative = app(MarketingStudioService::class)->createFromTemplate(
            MarketingCreativeType::Job,
            $admin,
        );
        $hashes = $creative->variants()->pluck('content_hash', 'format')->all();
        $url = route('admin.marketing.creatives.preview', [$creative, 'story']);

        $this->actingAs($staff)->get($url)->assertForbidden();

        $response = $this->actingAs($admin)->get($url)
            ->assertOk()
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
            ->assertHeader('X-PageBuilder-Preview-Width', '1080')
            ->assertHeader('X-PageBuilder-Preview-Height', '1920');

        $csp = (string) $response->headers->get('Content-Security-Policy');
        $html = (string) $response->getContent();

        $this->assertStringContainsString("default-src 'none'", $csp);
        $this->assertStringContainsString("script-src 'none'", $csp);
        $this->assertStringContainsString('connect-src', $csp);
        $this->assertStringContainsString('sandbox', $csp);
        $this->assertStringContainsString('data-preview-width="1080"', $html);
        $this->assertStringContainsString('data-preview-height="1920"', $html);
        $this->assertStringContainsString('data:image/', $html);
        $this->assertStringNotContainsString('<script', strtolower($html));
        $this->assertStringNotContainsString('http://', strtolower($html));
        $this->assertStringNotContainsString('https://', strtolower($html));
        $this->assertStringNotContainsString('{{', $html);
        $this->assertSame($hashes, $creative->fresh()->variants()->pluck('content_hash', 'format')->all());
    }

    public function test_mail_preview_uses_current_draft_profile_and_local_assets_without_mutating_publish_state(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'name' => 'Mara Vorschau',
            'email' => 'mara.vorschau@rail-time.test',
        ]);
        User::factory()->create(['role' => 'staff']);
        (new MailDocumentSeeder)->run();
        $document = MailDocument::query()->where('kind', MailDocumentKind::Template->value)->firstOrFail();
        $before = $document->only(['html', 'css', 'published_html', 'published_css', 'published_at', 'version']);
        $url = route('admin.mail-documents.preview', [$document, 'theme' => 'dark']);

        $this->actingAs(User::query()->where('role', 'staff')->firstOrFail())
            ->get($url)
            ->assertForbidden();

        $response = $this->actingAs($admin)->get($url)
            ->assertOk()
            ->assertHeader('X-PageBuilder-Preview-Width', '1024')
            ->assertHeader('X-PageBuilder-Preview-Height', '820');
        $html = (string) $response->getContent();

        $this->assertStringContainsString('Mara Vorschau', $html);
        $this->assertStringContainsString('mara.vorschau@rail-time.test', $html);
        $this->assertStringContainsString('data:image/png;base64,', $html);
        $this->assertStringContainsString('data-preview-document="template"', $html);
        $this->assertStringContainsString('data-preview-theme="dark"', $html);
        $this->assertStringNotContainsString('{{', $html);
        $this->assertStringNotContainsString('<script', strtolower($html));
        $this->assertStringNotContainsString('http://', strtolower($html));
        $this->assertStringNotContainsString('https://', strtolower($html));
        $this->assertSame($before, $document->fresh()->only(array_keys($before)));
    }

    public function test_marketing_source_page_renders_sandboxed_format_cards(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $creative = app(MarketingStudioService::class)->createFromTemplate(
            MarketingCreativeType::Info,
            $admin,
        );

        $response = $this->actingAs($admin)
            ->get(route('admin.marketing.creatives.index'))
            ->assertOk();
        $html = (string) $response->getContent();

        foreach ([
            'data-page-builder-preview-card',
            'data-page-builder-preview-frame',
            'sandbox=""',
            route('admin.marketing.creatives.preview', [$creative, 'story']),
            route('admin.marketing.creatives.preview', [$creative, 'post']),
            route('admin.marketing.creatives.preview', [$creative, 'web']),
        ] as $needle) {
            $this->assertTrue(str_contains($html, $needle), 'Marketing-Seite enthält nicht: '.$needle);
        }
    }

    public function test_email_source_page_renders_two_admin_preview_cards(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        (new MailDocumentSeeder)->run();

        $response = $this->actingAs($admin)
            ->get(route('email-templates.index'))
            ->assertOk();
        $html = (string) $response->getContent();

        foreach (['data-email-template-page-builder-previews', 'Nachrichtenschale', 'Signatur', 'sandbox=""'] as $needle) {
            $this->assertTrue(str_contains($html, $needle), 'Mail-Seite enthält nicht: '.$needle);
        }
    }

    public function test_editor_uses_guarded_fullscreen_shell_and_explicit_panel_targets(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $creative = app(MarketingStudioService::class)->createFromTemplate(
            MarketingCreativeType::Info,
            $admin,
        );

        $response = $this->actingAs($admin)
            ->get(route('admin.marketing.creatives.editor', $creative))
            ->assertOk();
        $html = (string) $response->getContent();

        foreach ([
            'data-page-builder-fullscreen',
            'data-page-builder-workspace',
            'aria-modal="false"',
            'page-builder-shell:before-close',
            'page-builder-shell:close-approved',
            'data-page-builder-panel-target="spacing"',
            'data-page-builder-panel-target="media"',
        ] as $needle) {
            $this->assertTrue(str_contains($html, $needle), 'Editor enthält nicht: '.$needle);
        }
    }
}
