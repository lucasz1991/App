<?php

namespace Tests\Feature;

use App\Enums\MarketingCreativeFormat;
use App\Enums\MarketingCreativeStatus;
use App\Enums\MarketingCreativeType;
use App\Enums\MarketingRenderStatus;
use App\Http\Controllers\Admin\MarketingCreativeController;
use App\Jobs\RenderMarketingCreative;
use App\Models\User;
use App\Services\Marketing\MarketingAssetService;
use App\Services\Marketing\MarketingHtmlSanitizer;
use App\Services\Marketing\MarketingRenderAssetHydrator;
use App\Services\Marketing\MarketingRenderService;
use App\Services\Marketing\MarketingStudioService;
use App\Support\CompanyData;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class MarketingStudioBackendTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('private');
        config()->set('marketing.disk', 'private');
    }

    public function test_job_and_info_templates_create_three_distinct_bound_formats(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $studio = app(MarketingStudioService::class);
        $job = $studio->createFromTemplate(MarketingCreativeType::Job, $admin);
        $info = $studio->createFromTemplate(MarketingCreativeType::Info, $admin);

        $this->assertSame(MarketingCreativeStatus::Draft, $job->status);
        $this->assertSame('Wagenmeister (m/w/d)', $job->shared_content['title']);
        $this->assertSame(CompanyData::all()['name'], $job->shared_content['company_name']);
        $this->assertArrayNotHasKey('hero_image_url', $job->shared_content);
        $this->assertCount(3, $job->variants);
        $this->assertCount(3, $info->variants);
        $this->assertCount(3, $job->variants->pluck('html')->unique());
        $this->assertCount(3, $info->variants->pluck('html')->unique());

        foreach (MarketingCreativeFormat::cases() as $format) {
            $variant = $job->variants->firstWhere('format', $format);
            $this->assertNotNull($variant);
            $this->assertSame(64, strlen($variant->content_hash));
            $this->assertStringContainsString('data-rt-binding="title"', $variant->html);
            $this->assertStringContainsString('/rt-brand/rt-logo.svg', $variant->html);
            $this->assertStringContainsString('src="/rt-brand/img/hero-railtime.jpg"', $variant->html);
            $this->assertStringNotContainsString('data-rt-binding-src="hero_image_url"', $variant->html);
            $this->assertSame($format->value, $variant->builder_data['railtime']['format']);
        }

        $this->assertSame(['width' => 1080, 'height' => 1920], MarketingCreativeFormat::Story->dimensions());
        $this->assertSame(['width' => 1080, 'height' => 1080], MarketingCreativeFormat::Post->dimensions());
        $this->assertSame(['width' => 1200, 'height' => 630], MarketingCreativeFormat::Web->dimensions());
    }

    public function test_shared_content_updates_every_variant_and_resets_approval(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $studio = app(MarketingStudioService::class);
        $creative = $studio->createFromTemplate(MarketingCreativeType::Job, $admin);
        $post = $creative->variants->firstWhere('format', MarketingCreativeFormat::Post);
        $formatSpecificImage = '/administrator/marketing/assets/00000000-0000-4000-8000-000000000001';
        $studio->saveVariant(
            $creative,
            MarketingCreativeFormat::Post,
            $post->builder_data,
            str_replace('/rt-brand/img/hero-railtime.jpg', $formatSpecificImage, $post->html),
            $post->css,
            $post->content_hash,
            $admin,
        );
        $studio->approve($creative, $admin);

        $updated = $studio->updateSharedContent(
            $creative->fresh(),
            ['title' => 'Wagenmeister gesucht'],
            $admin,
            'Sommerkampagne Wagenmeister',
        );

        $this->assertSame(MarketingCreativeStatus::Draft, $updated->status);
        $this->assertNull($updated->approved_by);
        $this->assertNull($updated->approved_at);
        $this->assertSame('Sommerkampagne Wagenmeister', $updated->title);
        $this->assertSame('Wagenmeister gesucht', $updated->shared_content['title']);
        foreach ($updated->variants as $variant) {
            $this->assertSame($variant->format === MarketingCreativeFormat::Post ? 3 : 2, $variant->version);
            $this->assertStringContainsString('Wagenmeister gesucht', $variant->html);
            $this->assertStringContainsString('Wagenmeister gesucht', $variant->builder_data['pages'][0]['component']);
        }
        $this->assertStringContainsString(
            $formatSpecificImage,
            $updated->variants->firstWhere('format', MarketingCreativeFormat::Post)->html,
        );
    }

    public function test_real_shared_update_route_returns_fresh_variants_and_controller_denies_non_admins(): void
    {
        Route::patch('/_marketing-backend-test/{creative}', [MarketingCreativeController::class, 'update'])
            ->middleware(SubstituteBindings::class);
        $admin = User::factory()->create(['role' => 'admin']);
        $staff = User::factory()->create(['role' => 'staff']);
        $creative = app(MarketingStudioService::class)->createFromTemplate(MarketingCreativeType::Info, $admin);
        $payload = [
            'title' => 'Aktualisierter Service',
            'shared_content' => array_replace($creative->shared_content, ['title' => '24/7 Wagenmeister']),
        ];

        $this->actingAs($staff)
            ->patchJson('/_marketing-backend-test/'.$creative->public_id, $payload)
            ->assertForbidden();

        $response = $this->actingAs($admin)
            ->patchJson(route('admin.marketing.creatives.update', $creative), $payload)
            ->assertOk()
            ->assertJsonPath('creative.status', 'draft')
            ->assertJsonPath('creative.shared_content.title', '24/7 Wagenmeister')
            ->assertJsonStructure([
                'creative' => ['variants' => [['format', 'builder_data', 'content_hash', 'version']]],
                'variants' => [
                    'story' => ['builder_data', 'content_hash', 'version'],
                    'post' => ['builder_data', 'content_hash', 'version'],
                    'web' => ['builder_data', 'content_hash', 'version'],
                ],
            ]);

        $this->assertStringContainsString(
            '24/7 Wagenmeister',
            $response->json('variants.story.builder_data.pages.0.component'),
        );
    }

    public function test_variant_save_sanitizes_active_content_and_rejects_a_stale_hash(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $studio = app(MarketingStudioService::class);
        $creative = $studio->createFromTemplate(MarketingCreativeType::Job, $admin);
        $variant = $creative->variants->firstWhere('format', MarketingCreativeFormat::Post);
        $oldHash = $variant->content_hash;

        $saved = $studio->saveVariant(
            $creative,
            MarketingCreativeFormat::Post,
            ['pages' => [['name' => 'Post', 'component' => '<p>stale</p>']]],
            '<section onclick="alert(1)"><a href="java&#x0A;script:alert(1)">Text</a><script>alert(1)</script><iframe src="https://example.org"></iframe><svg><script>alert(2)</script></svg></section>',
            '@IMPORT url(https://example.org/a.css);body{background:url(javascript:alert(1));width:expression(alert(1))}</StYlE>',
            $oldHash,
            $admin,
        );

        $this->assertStringNotContainsStringIgnoringCase('script', $saved->html);
        $this->assertStringNotContainsStringIgnoringCase('iframe', $saved->html);
        $this->assertStringNotContainsStringIgnoringCase('onclick', $saved->html);
        $this->assertStringNotContainsStringIgnoringCase('javascript:', $saved->html);
        $this->assertStringNotContainsStringIgnoringCase('@import', $saved->css);
        $this->assertStringNotContainsStringIgnoringCase('expression(', $saved->css);
        $this->assertStringNotContainsStringIgnoringCase('</style', $saved->css);
        $this->assertNotSame($oldHash, $saved->content_hash);

        $this->expectException(ValidationException::class);
        $studio->saveVariant(
            $creative->fresh(),
            MarketingCreativeFormat::Post,
            [],
            '<p>Veraltet</p>',
            '',
            $oldHash,
            $admin,
        );
    }

    public function test_duplicate_and_archive_preserve_source_but_make_copy_a_draft(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $studio = app(MarketingStudioService::class);
        $creative = $studio->createFromTemplate(MarketingCreativeType::Info, $admin);
        $studio->approve($creative, $admin);
        $copy = $studio->duplicate($creative->fresh(), $admin);

        $this->assertNotSame($creative->public_id, $copy->public_id);
        $this->assertSame(MarketingCreativeStatus::Draft, $copy->status);
        $this->assertNull($copy->approved_at);
        $this->assertCount(3, $copy->variants);
        $this->assertSame($creative->fresh()->variants->pluck('content_hash')->all(), $copy->variants->pluck('content_hash')->all());

        $archived = $studio->archive($copy, $admin);
        $this->assertSame(MarketingCreativeStatus::Archived, $archived->status);
    }

    public function test_asset_store_replace_usage_guard_and_delete_keep_private_files_consistent(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $assets = app(MarketingAssetService::class);
        $asset = $assets->store(UploadedFile::fake()->image('eins.jpg', 80, 60), $admin);
        $publicId = $asset->public_id;
        $oldPath = $asset->path;

        Storage::disk('private')->assertExists($oldPath);
        $this->assertSame('image/jpeg', $asset->mime_type);
        $this->assertSame([80, 60], [$asset->width, $asset->height]);

        $replaced = $assets->replace($asset, UploadedFile::fake()->image('zwei.png', 120, 90), $admin);
        $this->assertSame($publicId, $replaced->public_id);
        $this->assertSame('image/png', $replaced->mime_type);
        $this->assertNotSame($oldPath, $replaced->path);
        Storage::disk('private')->assertMissing($oldPath);
        Storage::disk('private')->assertExists($replaced->path);

        $creative = app(MarketingStudioService::class)->createFromTemplate(MarketingCreativeType::Info, $admin);
        $variant = $creative->variants->first();
        $variant->update(['html' => '<img src="/administrator/marketing/assets/'.$publicId.'">']);

        try {
            $assets->delete($replaced);
            $this->fail('Ein verwendetes Medium darf nicht gelöscht werden.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('asset', $exception->errors());
        }
        Storage::disk('private')->assertExists($replaced->path);

        $variant->update(['html' => '<p>Kein Medium</p>']);
        $assets->delete($replaced->fresh());
        $this->assertSoftDeleted('marketing_assets', ['id' => $replaced->id]);
        Storage::disk('private')->assertMissing($replaced->path);
    }

    public function test_asset_service_rejects_a_spoofed_image(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->expectException(ValidationException::class);
        app(MarketingAssetService::class)->store(
            UploadedFile::fake()->createWithContent('fake.png', '<?php echo "not an image";'),
            $admin,
        );
    }

    public function test_render_queue_is_cached_by_identity_content_status_and_dimensions(): void
    {
        Queue::fake();
        $admin = User::factory()->create(['role' => 'admin']);
        $creative = app(MarketingStudioService::class)->createFromTemplate(MarketingCreativeType::Job, $admin);
        $renderer = app(MarketingRenderService::class);

        $first = $renderer->queue($creative, MarketingCreativeFormat::Story, $admin);
        $cached = $renderer->queue($creative->fresh(), MarketingCreativeFormat::Story, $admin);

        $this->assertSame($first->id, $cached->id);
        $this->assertSame(MarketingRenderStatus::Pending, $first->status);
        $this->assertSame([1080, 1920], [$first->width, $first->height]);
        $this->assertDatabaseCount('marketing_renders', 1);
        Queue::assertPushed(RenderMarketingCreative::class);

        config()->set('marketing.renders.cache_version', 2);
        $versioned = $renderer->queue($creative->fresh(), MarketingCreativeFormat::Story, $admin);
        $this->assertNotSame($first->fingerprint, $versioned->fingerprint);
        $this->assertDatabaseCount('marketing_renders', 2);

        app(MarketingStudioService::class)->approve($creative->fresh(), $admin);
        $approved = $renderer->queue($creative->fresh(), MarketingCreativeFormat::Story, $admin);
        $this->assertNotSame($first->fingerprint, $approved->fingerprint);
        $this->assertDatabaseCount('marketing_renders', 3);
    }

    public function test_real_chromium_render_produces_exact_draft_and_approved_pngs(): void
    {
        $chrome = collect([
            config('marketing.renders.chrome_path'),
            'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe',
            'C:\\Program Files (x86)\\Google\\Chrome\\Application\\chrome.exe',
            'C:\\Program Files\\Microsoft\\Edge\\Application\\msedge.exe',
            '/usr/bin/google-chrome-stable',
            '/usr/bin/google-chrome',
            '/usr/bin/chromium',
            '/usr/bin/chromium-browser',
        ])->first(fn (mixed $path): bool => is_string($path) && is_file($path));
        if (! is_string($chrome)) {
            $this->markTestSkipped('Chrome/Chromium ist für den echten PNG-Smoke nicht verfügbar.');
        }

        Queue::fake();
        config()->set('marketing.renders.chrome_path', $chrome);
        $admin = User::factory()->create(['role' => 'admin']);
        $studio = app(MarketingStudioService::class);
        $renderer = app(MarketingRenderService::class);
        $creative = $studio->createFromTemplate(MarketingCreativeType::Info, $admin);

        $draft = $renderer->queue($creative, MarketingCreativeFormat::Web, $admin);
        $draft = $renderer->render($draft);
        $this->assertSame(MarketingRenderStatus::Completed, $draft->status);
        Storage::disk('private')->assertExists($draft->path);
        $draftPng = Storage::disk('private')->get($draft->path);
        $draftDimensions = getimagesizefromstring($draftPng);
        $this->assertSame([1200, 630], [$draftDimensions[0], $draftDimensions[1]]);

        $studio->approve($creative->fresh(), $admin);
        $approved = $renderer->queue($creative->fresh(), MarketingCreativeFormat::Web, $admin);
        $approved = $renderer->render($approved);
        $this->assertSame(MarketingRenderStatus::Completed, $approved->status);
        $approvedPng = Storage::disk('private')->get($approved->path);
        $approvedDimensions = getimagesizefromstring($approvedPng);
        $this->assertSame([1200, 630], [$approvedDimensions[0], $approvedDimensions[1]]);
        $this->assertNotSame(hash('sha256', $draftPng), hash('sha256', $approvedPng));
    }

    public function test_render_asset_hydrator_embeds_private_assets_and_builtin_brand_files(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $asset = app(MarketingAssetService::class)->store(
            UploadedFile::fake()->image('foto.jpg', 60, 40),
            $admin,
        );

        $hydrated = app(MarketingRenderAssetHydrator::class)->hydrate(
            '<img src="'.route('admin.marketing.assets.show', $asset).'?v='.substr($asset->sha256, 0, 16).'"><img src="/rt-brand/rt-logo.svg">',
            '.hero{background-image:url("/administrator/marketing/medien/'.$asset->public_id.'")}',
        );

        $this->assertStringContainsString('data:image/jpeg;base64,', $hydrated['html']);
        $this->assertStringContainsString('data:image/jpeg;base64,', $hydrated['css']);
        $this->assertStringContainsString('data:image/svg+xml;base64,', $hydrated['html']);
        $this->assertStringNotContainsString('/administrator/marketing/medien/', $hydrated['html'].$hydrated['css']);
    }

    public function test_sanitizer_allows_safe_image_data_but_blocks_html_data_urls(): void
    {
        $sanitizer = app(MarketingHtmlSanitizer::class);
        $html = $sanitizer->html(
            '<img src="data:image/png;base64,iVBORw0KGgo="><a href="data:text/html;base64,PHNjcmlwdD4=">bad</a>',
        );

        $this->assertStringContainsString('data:image/png;base64,iVBORw0KGgo=', $html);
        $this->assertStringNotContainsString('data:text/html', $html);
    }
}
