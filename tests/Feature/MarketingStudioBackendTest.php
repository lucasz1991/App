<?php

namespace Tests\Feature;

use App\Enums\MarketingCreativeFormat;
use App\Enums\MarketingCreativeStatus;
use App\Enums\MarketingCreativeType;
use App\Enums\MarketingRenderStatus;
use App\Http\Controllers\Admin\MarketingCreativeController;
use App\Jobs\RenderMarketingCreative;
use App\Models\MarketingCreative;
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
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;
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

        foreach ([$job, $info] as $creative) {
            foreach (MarketingCreativeFormat::cases() as $format) {
                $variant = $creative->variants->firstWhere('format', $format);
                $this->assertNotNull($variant);
                $this->assertSame(64, strlen($variant->content_hash));
                $this->assertStringContainsString('data-rt-binding="title"', $variant->html);
                $this->assertStringContainsString('data-rt-brand-lockup="official"', $variant->html);
                $this->assertStringContainsString('src="/rt-brand/img/logo-horizontal.png"', $variant->html);
                $this->assertStringNotContainsString('/rt-brand/rt-logo.svg', $variant->html);
                $this->assertStringNotContainsString('<span>RAILTIME</span>', $variant->html);
                $this->assertStringContainsString('src="/rt-brand/img/hero-railtime.jpg"', $variant->html);
                $this->assertStringNotContainsString('data-rt-binding-src="hero_image_url"', $variant->html);
                $this->assertSame($format->value, $variant->builder_data['railtime']['format']);
                $this->assertSame(2, $variant->builder_data['railtime']['schema']);
            }
        }

        $this->assertSame(['width' => 1080, 'height' => 1920], MarketingCreativeFormat::Story->dimensions());
        $this->assertSame(['width' => 1080, 'height' => 1080], MarketingCreativeFormat::Post->dimensions());
        $this->assertSame(['width' => 1200, 'height' => 630], MarketingCreativeFormat::Web->dimensions());
    }

    public function test_untouched_schema_one_starter_motives_are_refreshed_to_the_official_brand_design(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $studio = app(MarketingStudioService::class);
        $creative = $studio->createFromTemplate(MarketingCreativeType::Job, $admin);

        foreach ($creative->variants as $variant) {
            $builderData = $variant->builder_data;
            $builderData['railtime']['schema'] = 1;
            $variant->forceFill([
                'builder_data' => $builderData,
                'html' => '<div class="rt-brand"><span class="rt-brand-mark"><img src="/rt-brand/rt-logo.svg" alt=""></span><span>RAILTIME</span></div>',
                'css' => '.rt-brand{display:flex}',
                'version' => 1,
            ])->save();
        }
        $studio->approve($creative->fresh(), $admin);

        $migration = require database_path('migrations/2026_08_07_000300_refresh_untouched_marketing_starter_templates.php');
        $migration->up();

        $refreshed = $creative->fresh(['variants']);
        $this->assertSame(MarketingCreativeStatus::Draft, $refreshed->status);
        $this->assertNull($refreshed->approved_by);
        $this->assertNull($refreshed->approved_at);
        foreach ($refreshed->variants as $variant) {
            $this->assertSame(2, $variant->version);
            $this->assertSame(2, $variant->builder_data['railtime']['schema']);
            $this->assertStringContainsString('/rt-brand/img/logo-horizontal.png', $variant->html);
            $this->assertStringNotContainsString('<span>RAILTIME</span>', $variant->html);
        }
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
        $approval = Activity::query()
            ->where('log_name', 'marketing')
            ->where('description', 'marketing_creative_approved')
            ->sole();
        $this->assertSame($creative->id, $approval->subject_id);
        $this->assertSame($admin->id, $approval->causer_id);
        $this->assertCount(3, $approval->properties->get('fingerprints'));
        $this->assertNotEmpty($approval->properties->get('approved_at'));

        $updated = $studio->updateSharedContent(
            $creative->fresh(),
            ['title' => 'Wagenmeister gesucht'],
            $admin,
            $this->variantHashes($creative->fresh(['variants'])),
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
        $this->assertDatabaseHas('activity_log', [
            'id' => $approval->id,
            'description' => 'marketing_creative_approved',
        ]);
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
            'expected_hashes' => $this->variantHashes($creative),
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

        $this->actingAs($admin)
            ->patchJson(route('admin.marketing.creatives.update', $creative), array_replace($payload, [
                'title' => 'Veralteter Schreibversuch',
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['expected_hashes.story']);
        $this->assertSame('Aktualisierter Service', $creative->fresh()->title);
    }

    public function test_variant_save_sanitizes_active_content_and_rejects_a_stale_hash(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $studio = app(MarketingStudioService::class);
        $creative = $studio->createFromTemplate(MarketingCreativeType::Job, $admin);
        $variant = $creative->variants->firstWhere('format', MarketingCreativeFormat::Story);
        $oldHash = $variant->content_hash;

        $saved = $studio->saveVariant(
            $creative,
            MarketingCreativeFormat::Story,
            [
                'pages' => [
                    ['name' => 'Post', 'component' => '<p>stale</p>'],
                    ['name' => 'Böse', 'component' => '<script>alert(3)</script>'],
                ],
                'styles' => [['selectors' => ['body'], 'style' => ['background' => 'url(javascript:alert(4))']]],
            ],
            '<section onclick="alert(1)"><a href="java&#x0A;script:alert(1)">Text</a><a href="https://www.rail-time.de/de/karriere">CTA</a><img src="https://attacker.example/pixel.png"><style>@import url(https://example.org)</style><script>alert(1)</script><iframe src="https://example.org"></iframe><svg><script>alert(2)</script></svg></section>',
            '@IMPORT url(https://example.org/a.css);body{background:url(jav\\61script:alert(1));width:expression(alert(1))}.remote{background:url(https://attacker.example/pixel.png)}</StYlE>',
            $oldHash,
            $admin,
        );

        $this->assertStringNotContainsStringIgnoringCase('script', $saved->html);
        $this->assertStringNotContainsStringIgnoringCase('iframe', $saved->html);
        $this->assertStringNotContainsStringIgnoringCase('onclick', $saved->html);
        $this->assertStringNotContainsStringIgnoringCase('javascript:', $saved->html);
        $this->assertStringContainsString('https://www.rail-time.de/de/karriere', $saved->html);
        $this->assertStringNotContainsStringIgnoringCase('attacker.example', $saved->html.$saved->css);
        $this->assertStringNotContainsStringIgnoringCase('@import', $saved->css);
        $this->assertStringNotContainsStringIgnoringCase('expression(', $saved->css);
        $this->assertStringNotContainsStringIgnoringCase('</style', $saved->css);
        $this->assertSame([], $saved->builder_data['styles']);
        $this->assertCount(1, $saved->builder_data['pages']);
        $this->assertStringNotContainsStringIgnoringCase('javascript', json_encode($saved->builder_data));
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

    public function test_render_fails_closed_when_an_asset_changes_during_chromium_processing(): void
    {
        Queue::fake();
        $admin = User::factory()->create(['role' => 'admin']);
        $studio = app(MarketingStudioService::class);
        $assets = app(MarketingAssetService::class);
        $renderer = app(MarketingRenderService::class);
        $creative = $studio->createFromTemplate(MarketingCreativeType::Info, $admin);
        $variant = $creative->variants->firstWhere('format', MarketingCreativeFormat::Story);
        $asset = $assets->store(UploadedFile::fake()->image('vorher.jpg', 120, 80), $admin);
        $variant = $studio->saveVariant(
            $creative,
            MarketingCreativeFormat::Story,
            $variant->builder_data,
            str_replace('/rt-brand/img/hero-railtime.jpg', route('admin.marketing.assets.show', $asset), $variant->html),
            $variant->css,
            $variant->content_hash,
            $admin,
        );
        $studio->approve($creative->fresh(), $admin);
        $this->assertStringContainsString($asset->public_id, $variant->html);
        $render = $renderer->queue($creative->fresh(), MarketingCreativeFormat::Story, $admin);

        Process::fake(function ($process) use ($asset, $assets, $admin, $creative, $renderer, $render): string {
            $outputIndex = array_search('--output', $process->command, true);
            $this->assertIsInt($outputIndex);
            $this->writePng($process->command[$outputIndex + 1], 1080, 1920);
            $assets->replace($asset, UploadedFile::fake()->image('nachher.png', 140, 90), $admin);
            $this->assertSame(MarketingCreativeStatus::Draft, $creative->fresh()->status);
            $this->assertNotSame(
                $render->fingerprint,
                $renderer->fingerprint($creative->fresh(), $render->variant()->firstOrFail()),
            );

            return json_encode(['ok' => true, 'width' => 1080, 'height' => 1920], JSON_THROW_ON_ERROR);
        });

        $finished = $renderer->render($render);

        $this->assertSame(MarketingRenderStatus::Failed, $finished->status);
        $this->assertNull($finished->path);
        $this->assertStringContainsString('während des Exports geändert', $finished->error);
        $this->assertSame(MarketingCreativeStatus::Draft, $creative->fresh()->status);
        $this->assertFalse($renderer->isCurrent($finished));
    }

    public function test_stale_completed_render_is_reported_as_failed_and_cannot_be_downloaded(): void
    {
        Queue::fake();
        $admin = User::factory()->create(['role' => 'admin']);
        $studio = app(MarketingStudioService::class);
        $renderer = app(MarketingRenderService::class);
        $creative = $studio->createFromTemplate(MarketingCreativeType::Info, $admin);
        $studio->approve($creative, $admin);
        $render = $renderer->queue($creative->fresh(), MarketingCreativeFormat::Web, $admin);
        $path = 'marketing/renders/test/current.png';
        $temporaryPng = tempnam(sys_get_temp_dir(), 'rt-render-');
        $this->assertNotFalse($temporaryPng);
        $this->writePng($temporaryPng, 1200, 630);
        Storage::disk('private')->put($path, file_get_contents($temporaryPng));
        unlink($temporaryPng);
        $render->forceFill([
            'status' => MarketingRenderStatus::Completed,
            'path' => $path,
            'mime_type' => 'image/png',
            'rendered_at' => now(),
        ])->save();
        $this->assertTrue($renderer->isCurrent($render->fresh()));

        $creative->fresh()->forceFill([
            'status' => MarketingCreativeStatus::Draft,
            'approved_by' => null,
            'approved_at' => null,
        ])->save();

        $this->assertFalse($renderer->isCurrent($render->fresh()));
        $this->actingAs($admin)
            ->getJson(route('admin.marketing.renders.show', $render))
            ->assertOk()
            ->assertJsonPath('render.status', MarketingRenderStatus::Failed->value)
            ->assertJsonPath('render.download_url', null)
            ->assertJsonPath('render.error', 'Das Motiv oder ein verwendetes Medium wurde nach diesem Export geändert. Bitte den Export erneut starten.');
        $this->actingAs($admin)
            ->get(route('admin.marketing.renders.download', $render))
            ->assertStatus(409);
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

    public function test_replacing_an_asset_under_the_same_public_url_invalidates_render_fingerprint(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $studio = app(MarketingStudioService::class);
        $assets = app(MarketingAssetService::class);
        $renderer = app(MarketingRenderService::class);
        $creative = $studio->createFromTemplate(MarketingCreativeType::Info, $admin);
        $variant = $creative->variants->firstWhere('format', MarketingCreativeFormat::Story);
        $asset = $assets->store(UploadedFile::fake()->image('alt.jpg', 80, 60), $admin);
        $assetUrl = route('admin.marketing.assets.show', $asset);
        $variant = $studio->saveVariant(
            $creative,
            MarketingCreativeFormat::Story,
            $variant->builder_data,
            str_replace('/rt-brand/img/hero-railtime.jpg', $assetUrl, $variant->html),
            $variant->css,
            $variant->content_hash,
            $admin,
        );
        $studio->approve($creative->fresh(), $admin);
        $this->assertStringContainsString($asset->public_id, $variant->html);
        $dependencyBefore = app(MarketingRenderAssetHydrator::class)->fingerprint($variant->html, $variant->css);
        $assetHashBefore = $asset->sha256;
        $before = $renderer->fingerprint($creative->fresh(), $variant);

        $replaced = $assets->replace($asset, UploadedFile::fake()->image('neu.png', 100, 80), $admin);
        $creativeAfterReplace = $creative->fresh();
        $dependencyAfter = app(MarketingRenderAssetHydrator::class)->fingerprint($variant->fresh()->html, $variant->fresh()->css);
        $after = $renderer->fingerprint($creativeAfterReplace, $variant->fresh());

        $this->assertSame($asset->public_id, $replaced->public_id);
        $this->assertNotSame($assetHashBefore, $replaced->sha256);
        $this->assertNotSame($dependencyBefore, $dependencyAfter);
        $this->assertNotSame($before, $after);
        $this->assertSame(MarketingCreativeStatus::Draft, $creativeAfterReplace->status);
        $this->assertNull($creativeAfterReplace->approved_by);
        $this->assertNull($creativeAfterReplace->approved_at);
        $this->assertTrue($renderer->requiresWatermark($creativeAfterReplace));
    }

    public function test_render_asset_hydrator_embeds_private_assets_and_builtin_brand_files(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $asset = app(MarketingAssetService::class)->store(
            UploadedFile::fake()->image('foto.jpg', 60, 40),
            $admin,
        );

        $hydrated = app(MarketingRenderAssetHydrator::class)->hydrate(
            '<img src="'.route('admin.marketing.assets.show', $asset).'?v='.substr($asset->sha256, 0, 16).'">'
            .'<img src="/rt-brand/rt-logo.svg">'
            .'<img src="/rt-brand/img/logo-horizontal.png">'
            .'<img src="/rt-brand/img/logo-horizontal-darkbg.png">',
            '.hero{background-image:url("/administrator/marketing/medien/'.$asset->public_id.'")}',
        );

        $this->assertStringContainsString('data:image/jpeg;base64,', $hydrated['html']);
        $this->assertStringContainsString('data:image/jpeg;base64,', $hydrated['css']);
        $this->assertStringContainsString('data:image/svg+xml;base64,', $hydrated['html']);
        $this->assertSame(2, substr_count($hydrated['html'], 'data:image/png;base64,'));
        $this->assertStringNotContainsString('/rt-brand/img/logo-horizontal', $hydrated['html']);
        $this->assertStringNotContainsString('/administrator/marketing/medien/', $hydrated['html'].$hydrated['css']);
        $this->assertStringNotContainsString('?v=', $hydrated['html'].$hydrated['css']);

        Storage::disk('private')->put($asset->path, 'manipulated-image-bytes');
        $tampered = app(MarketingRenderAssetHydrator::class)->hydrate(
            '<img src="'.route('admin.marketing.assets.show', $asset).'">',
            '',
        );
        $this->assertStringContainsString($asset->public_id, $tampered['html']);
        $this->assertStringNotContainsString('data:image/', $tampered['html']);
    }

    public function test_sanitizer_allows_safe_image_data_but_blocks_html_data_urls(): void
    {
        $sanitizer = app(MarketingHtmlSanitizer::class);
        $html = $sanitizer->html(
            '<img src="data:image/png;base64,iVBORw0KGgo=">'
            .'<a href="data:text/html;base64,PHNjcmlwdD4=">bad</a>'
            .'<img src="//attacker.example/pixel.png">'
            .'<img src="\\\\attacker.example\\share\\pixel.png">'
            .'<img src="../.env">'
            .'<img src="/rt-brand/%2e%2e/.env">'
            .'<img srcset="/safe.png 1x, //attacker.example/two.png 2x">'
            .'<link imagesrcset="//attacker.example/three.png 1x">',
        );
        $css = $sanitizer->css(
            '.a{background:url(//attacker.example/a.png)}'
            .'.b{background:url(../storage/secret.png)}'
            .'.c{background:url(\\\\attacker.example\\share\\a.png)}'
            .'.d{background:url(f\\69le:///etc/passwd)}'
            .'.e{background:url(h\\74tps://attacker.example/a.png)}'
            .'@\\69mport url(https://attacker.example/escaped.css);',
        );

        $this->assertStringNotContainsString('data:image/png;base64,iVBORw0KGgo=', $html);
        $this->assertStringNotContainsString('data:text/html', $html);
        $this->assertStringNotContainsString('attacker.example', $html.$css);
        $this->assertStringNotContainsString('../', $html.$css);
        $this->assertStringNotContainsString('%2e%2e', strtolower($html.$css));
        $this->assertStringNotContainsStringIgnoringCase('srcset', $html);
        $this->assertStringNotContainsStringIgnoringCase('imagesrcset', $html);
        $this->assertStringNotContainsStringIgnoringCase('file:', $css);
    }

    public function test_sanitizer_allows_exact_official_brand_assets_and_blocks_lookalike_paths(): void
    {
        $html = app(MarketingHtmlSanitizer::class)->html(
            '<img src="/rt-brand/img/logo-horizontal.png">'
            .'<img src="/rt-brand/img/logo-horizontal-darkbg.png">'
            .'<img src="/rt-brand/img/logo-horizontal.png.exe">'
            .'<img src="/rt-brand/img/unofficial-logo.png">',
        );

        $this->assertStringContainsString('src="/rt-brand/img/logo-horizontal.png"', $html);
        $this->assertStringContainsString('src="/rt-brand/img/logo-horizontal-darkbg.png"', $html);
        $this->assertStringNotContainsString('logo-horizontal.png.exe', $html);
        $this->assertStringNotContainsString('unofficial-logo.png', $html);
    }

    public function test_sanitizer_keeps_valid_inline_qr_images_but_rejects_mime_and_pixel_bombs(): void
    {
        $sanitizer = app(MarketingHtmlSanitizer::class);
        $qr = $this->pngDataUri(29, 29);

        $safe = $sanitizer->html('<img src="'.$qr.'" alt="QR-Code">');
        $this->assertStringContainsString($qr, $safe);

        $mismatched = $sanitizer->html('<img src="'.str_replace('data:image/png', 'data:image/gif', $qr).'">');
        $this->assertStringNotContainsString('src=', $mismatched);

        config()->set('marketing.assets.max_pixels', 100);
        $pixelBomb = $this->pngDataUri(11, 10);
        $blocked = $sanitizer->html('<img src="'.$pixelBomb.'">');
        $this->assertStringNotContainsString('src=', $blocked);
    }

    /** @return array{story: string, post: string, web: string} */
    private function variantHashes(MarketingCreative $creative): array
    {
        $variants = $creative->relationLoaded('variants')
            ? $creative->variants
            : $creative->variants()->get();

        return [
            'story' => $variants->firstWhere('format', MarketingCreativeFormat::Story)->content_hash,
            'post' => $variants->firstWhere('format', MarketingCreativeFormat::Post)->content_hash,
            'web' => $variants->firstWhere('format', MarketingCreativeFormat::Web)->content_hash,
        ];
    }

    private function pngDataUri(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        $dark = imagecolorallocate($image, 16, 34, 55);
        $light = imagecolorallocate($image, 255, 255, 255);
        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                imagesetpixel($image, $x, $y, ($x + $y) % 2 === 0 ? $dark : $light);
            }
        }

        ob_start();
        imagepng($image);
        $contents = (string) ob_get_clean();
        imagedestroy($image);

        return 'data:image/png;base64,'.base64_encode($contents);
    }

    private function writePng(string $path, int $width, int $height): void
    {
        $image = imagecreatetruecolor($width, $height);
        $color = imagecolorallocate($image, 16, 34, 55);
        imagefill($image, 0, 0, $color);
        imagepng($image, $path);
        imagedestroy($image);
    }
}
