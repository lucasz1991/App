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
use App\Services\Ai\AssistantKnowledgeToolRunner;
use App\Services\Ai\OpenRouterChatClient;
use App\Services\Ai\OpenRouterModelProfile;
use App\Services\Ai\OpenRouterToolDecision;
use App\Services\Marketing\MarketingHtmlSanitizer;
use App\Services\Marketing\MarketingRenderService;
use App\Services\Marketing\MarketingStudioService;
use App\Services\Marketing\MarketingTemplateFactory;
use App\Support\CompanyData;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Routing\Middleware\SubstituteBindings;
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

    public function test_three_starter_templates_create_distinct_bound_formats(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $studio = app(MarketingStudioService::class);
        $job = $studio->createFromTemplate(MarketingCreativeType::Job, $admin);
        $info = $studio->createFromTemplate(MarketingCreativeType::Info, $admin);
        $network = $studio->createFromTemplate(
            MarketingCreativeType::Info,
            $admin,
            MarketingTemplateFactory::INFO_GERMANY_NETWORK,
        );

        $this->assertSame(MarketingCreativeStatus::Draft, $job->status);
        $this->assertSame('Wagenmeister (m/w/d)', $job->shared_content['title']);
        $this->assertSame(CompanyData::all()['name'], $job->shared_content['company_name']);
        $this->assertArrayNotHasKey('hero_image_url', $job->shared_content);
        $this->assertSame('Was macht ein Wagenmeister?', $info->shared_content['title']);
        $this->assertSame('Deutschlandweit im Einsatz', $network->shared_content['title']);

        foreach ([$job, $info, $network] as $creative) {
            $this->assertCount(3, $creative->variants);
            $this->assertCount(3, $creative->variants->pluck('html')->unique());
            foreach (MarketingCreativeFormat::cases() as $format) {
                $variant = $creative->variants->firstWhere('format', $format);
                $this->assertNotNull($variant);
                $this->assertSame(64, strlen($variant->content_hash));
                $this->assertStringContainsString('data-rt-binding="title"', $variant->html);
                $this->assertStringContainsString('data-rt-brand-lockup="official"', $variant->html);
                $this->assertMatchesRegularExpression(
                    '#src="/rt-brand/img/logo-horizontal(?:-darkbg)?\.png"#',
                    $variant->html,
                );
                $this->assertStringNotContainsString('/rt-brand/rt-logo.svg', $variant->html);
                $this->assertStringNotContainsString('<span>RAILTIME</span>', $variant->html);
                $this->assertStringNotContainsString('data-rt-binding-src="hero_image_url"', $variant->html);
                $this->assertSame($format->value, $variant->builder_data['railtime']['format']);
                $this->assertSame(3, $variant->builder_data['railtime']['schema']);
            }
        }

        $this->assertTrue($job->variants->every(fn ($variant): bool => str_contains($variant->html, '/rt-brand/img/wagenmeister-pruefung.jpg')));
        $this->assertTrue($info->variants->every(fn ($variant): bool => str_contains($variant->html, '/rt-brand/img/wagenmeister-team.webp')));
        $this->assertTrue($network->variants->every(fn ($variant): bool => str_contains($variant->html, '/rt-brand/img/deutschland-netzwerk.png')));

        $this->assertSame(['width' => 1080, 'height' => 1920], MarketingCreativeFormat::Story->dimensions());
        $this->assertSame(['width' => 1080, 'height' => 1080], MarketingCreativeFormat::Post->dimensions());
        $this->assertSame(['width' => 1200, 'height' => 630], MarketingCreativeFormat::Web->dimensions());
    }

    public function test_starter_templates_use_format_specific_premium_information_hierarchies(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $studio = app(MarketingStudioService::class);
        $job = $studio->createFromTemplate(MarketingCreativeType::Job, $admin);
        $info = $studio->createFromTemplate(MarketingCreativeType::Info, $admin);

        $story = $job->variants->firstWhere('format', MarketingCreativeFormat::Story);
        $post = $job->variants->firstWhere('format', MarketingCreativeFormat::Post);
        $web = $job->variants->firstWhere('format', MarketingCreativeFormat::Web);

        $this->assertStringContainsString('class="rt-job-body"', $story->html);
        $this->assertStringContainsString('class="rt-columns"', $story->html);
        $this->assertStringContainsString('class="rt-photo-code"', $story->html);
        $this->assertStringContainsString('data-rt-binding-list="tasks"', $story->html);
        $this->assertStringContainsString('data-rt-binding-list="profile"', $story->html);
        $this->assertStringContainsString('height:880px', $story->css);
        $this->assertStringContainsString('height:710px', $story->css);
        $this->assertStringContainsString('height:330px', $story->css);

        $this->assertStringContainsString('class="rt-copy"', $post->html);
        $this->assertStringContainsString('data-rt-binding-list="benefits"', $post->html);
        $this->assertStringContainsString('.rt-job-post>.rt-copy{position:absolute', $post->css);

        $this->assertStringContainsString('class="rt-intro" data-rt-binding="intro"', $web->html);
        $this->assertStringContainsString('class="rt-actions"', $web->html);
        $this->assertStringContainsString('class="rt-photo-code"', $web->html);
        $this->assertStringContainsString('.rt-job-web{display:grid', $web->css);

        foreach ($job->variants as $variant) {
            $dimensions = $variant->format->dimensions();

            $this->assertStringContainsString('data-rt-brand-lockup="official"', $variant->html);
            $this->assertStringContainsString('src="/rt-brand/img/logo-horizontal-darkbg.png"', $variant->html);
            $this->assertStringContainsString('src="/rt-brand/img/wagenmeister-pruefung.jpg"', $variant->html);
            $this->assertStringContainsString('data-rt-binding-href="cta_url"', $variant->html);
            $this->assertStringContainsString("width:{$dimensions['width']}px", $variant->css);
            $this->assertStringContainsString("height:{$dimensions['height']}px", $variant->css);
        }

        foreach ($info->variants as $variant) {
            $this->assertStringContainsString('src="/rt-brand/img/wagenmeister-team.webp"', $variant->html);
            $this->assertStringContainsString('data-rt-binding="subtitle"', $variant->html);
            $this->assertStringContainsString('data-rt-binding-href="cta_url"', $variant->html);
        }
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
                'content_hash' => $studio->contentHash(
                    $builderData,
                    '<div class="rt-brand"><span class="rt-brand-mark"><img src="/rt-brand/rt-logo.svg" alt=""></span><span>RAILTIME</span></div>',
                    '.rt-brand{display:flex}',
                ),
                'version' => 1,
            ])->save();
        }
        $creative->forceFill([
            'status' => MarketingCreativeStatus::Approved,
            'approved_by' => $admin->id,
            'approved_at' => now(),
        ])->save();

        $migration = require database_path('migrations/2026_08_07_000300_refresh_untouched_marketing_starter_templates.php');
        $migration->up();

        $refreshed = $creative->fresh(['variants']);
        $this->assertSame(MarketingCreativeStatus::Draft, $refreshed->status);
        $this->assertNull($refreshed->approved_by);
        $this->assertNull($refreshed->approved_at);
        foreach ($refreshed->variants as $variant) {
            $this->assertSame(2, $variant->version);
            $this->assertSame(3, $variant->builder_data['railtime']['schema']);
            $this->assertMatchesRegularExpression(
                '#/rt-brand/img/logo-horizontal(?:-darkbg)?\.png#',
                $variant->html,
            );
            $this->assertStringNotContainsString('<span>RAILTIME</span>', $variant->html);
        }
    }

    public function test_shared_content_updates_every_variant_and_resets_approval(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $studio = app(MarketingStudioService::class);
        $creative = $studio->createFromTemplate(MarketingCreativeType::Job, $admin);
        $post = $creative->variants->firstWhere('format', MarketingCreativeFormat::Post);
        $formatSpecificImage = '/rt-brand/img/hero-railtime.jpg';
        $studio->saveVariant(
            $creative,
            MarketingCreativeFormat::Post,
            $post->builder_data,
            str_replace('/rt-brand/img/wagenmeister-pruefung.jpg', $formatSpecificImage, $post->html),
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

    public function test_complete_redesign_uses_all_format_cas_preserves_content_and_resets_approval_with_one_audit_entry(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $studio = app(MarketingStudioService::class);
        $creative = $studio->createFromTemplate(MarketingCreativeType::Job, $admin);
        $creative = $studio->updateSharedContent(
            $creative,
            ['title' => 'Wagenmeister Zukunft 24/7'],
            $admin,
            $this->variantHashes($creative),
            'RailTime Zukunftskampagne',
        );

        foreach ($creative->variants as $variant) {
            $customHtml = str_replace(
                '</main>',
                '<section data-test-custom-layout="true">Individuelles Altlayout</section></main>',
                $variant->html,
            );
            $this->assertNotSame($variant->html, $customHtml);
            $builderData = $variant->builder_data;
            $builderData['pages'][0]['component'] = $customHtml;
            $studio->saveVariant(
                $creative,
                $variant->format,
                $builderData,
                $customHtml,
                $variant->css,
                $variant->content_hash,
                $admin,
            );
        }

        $approved = $studio->approve($creative->fresh(), $admin)->fresh(['variants']);
        $sharedContent = $approved->shared_content;
        $beforeVersions = $approved->variants->mapWithKeys(
            fn ($variant): array => [$variant->format->value => $variant->version],
        )->all();
        $beforeHashes = $this->variantHashes($approved);

        $result = $studio->redesignFromPreset(
            $approved,
            'railtime_modern',
            $beforeHashes,
            $admin,
        );
        $redesigned = $result['creative'];

        $this->assertTrue($result['changed']);
        $this->assertSame('railtime_modern', $result['preset']);
        $this->assertSame(MarketingCreativeStatus::Draft, $redesigned->status);
        $this->assertNull($redesigned->approved_by);
        $this->assertNull($redesigned->approved_at);
        $this->assertNull($redesigned->approval_dependency_hash);
        $this->assertSame($admin->id, $redesigned->updated_by);
        $this->assertSame($sharedContent, $redesigned->shared_content);
        $this->assertSame('Wagenmeister Zukunft 24/7', $redesigned->shared_content['title']);

        foreach (MarketingCreativeFormat::cases() as $format) {
            $variant = $redesigned->variants->firstWhere('format', $format);
            $this->assertNotNull($variant);
            $this->assertSame($beforeVersions[$format->value] + 1, $variant->version);
            $this->assertNotSame($beforeHashes[$format->value], $variant->content_hash);
            $this->assertSame('railtime_modern', $variant->builder_data['railtime']['design_preset']);
            $this->assertStringContainsString('Wagenmeister Zukunft 24/7', $variant->html);
            $this->assertStringContainsString('data-rt-brand-lockup="official"', $variant->html);
            $this->assertStringNotContainsString('data-test-custom-layout', $variant->html);
        }

        $activity = Activity::query()
            ->where('log_name', 'marketing')
            ->where('description', 'marketing_creative_redesigned')
            ->sole();
        $this->assertSame($redesigned->id, $activity->subject_id);
        $this->assertSame($admin->id, $activity->causer_id);
        $this->assertSame('railtime_modern', $activity->properties->get('preset'));
        $this->assertSame(['story', 'post', 'web'], $activity->properties->get('formats'));

        $afterVersions = $redesigned->variants->mapWithKeys(
            fn ($variant): array => [$variant->format->value => $variant->version],
        )->all();
        $unchanged = $studio->redesignFromPreset(
            $redesigned,
            'railtime_modern',
            $this->variantHashes($redesigned),
            $admin,
        );
        $this->assertFalse($unchanged['changed']);
        $this->assertSame($afterVersions, $unchanged['creative']->variants->mapWithKeys(
            fn ($variant): array => [$variant->format->value => $variant->version],
        )->all());
        $this->assertSame(1, Activity::query()->where('description', 'marketing_creative_redesigned')->count());

        $approvedModern = $studio->approve($unchanged['creative'], $admin)->fresh(['variants']);
        $approvedModernVersions = $approvedModern->variants->mapWithKeys(
            fn ($variant): array => [$variant->format->value => $variant->version],
        )->all();
        $approvalOnlyReset = $studio->redesignFromPreset(
            $approvedModern,
            'railtime_modern',
            $this->variantHashes($approvedModern),
            $admin,
        );

        $this->assertTrue($approvalOnlyReset['changed']);
        $this->assertSame(MarketingCreativeStatus::Draft, $approvalOnlyReset['creative']->status);
        $this->assertNull($approvalOnlyReset['creative']->approved_by);
        $this->assertNull($approvalOnlyReset['creative']->approved_at);
        $this->assertNull($approvalOnlyReset['creative']->approval_dependency_hash);
        $this->assertSame($approvedModernVersions, $approvalOnlyReset['creative']->variants->mapWithKeys(
            fn ($variant): array => [$variant->format->value => $variant->version],
        )->all());
        $this->assertSame(2, Activity::query()->where('description', 'marketing_creative_redesigned')->count());

        $finalUnchanged = $studio->redesignFromPreset(
            $approvalOnlyReset['creative'],
            'railtime_modern',
            $this->variantHashes($approvalOnlyReset['creative']),
            $admin,
        );
        $this->assertFalse($finalUnchanged['changed']);
        $this->assertSame(2, Activity::query()->where('description', 'marketing_creative_redesigned')->count());

        $story = $finalUnchanged['creative']->variants->firstWhere('format', MarketingCreativeFormat::Story);
        $builderData = $story->builder_data;
        $builderData['railtime']['provider_injected'] = 'muss entfernt werden';
        $savedStory = $studio->saveVariant(
            $finalUnchanged['creative'],
            MarketingCreativeFormat::Story,
            $builderData,
            $story->html,
            $story->css,
            $story->content_hash,
            $admin,
        );
        $this->assertSame('railtime_modern', $savedStory->builder_data['railtime']['design_preset']);
        $this->assertArrayNotHasKey('provider_injected', $savedStory->builder_data['railtime']);
    }

    public function test_complete_redesign_rolls_back_atomically_for_a_stale_format_and_denies_archived_creatives(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $studio = app(MarketingStudioService::class);
        $approved = $studio->approve(
            $studio->createFromTemplate(MarketingCreativeType::Info, $admin),
            $admin,
        )->fresh(['variants']);
        $beforeHashes = $this->variantHashes($approved);
        $beforeVersions = $approved->variants->mapWithKeys(
            fn ($variant): array => [$variant->format->value => $variant->version],
        )->all();
        $staleHashes = $beforeHashes;
        $staleHashes['web'] = ($staleHashes['web'][0] === 'a' ? 'b' : 'a').substr($staleHashes['web'], 1);

        try {
            $studio->redesignFromPreset($approved, 'railtime_modern', $staleHashes, $admin);
            $this->fail('Ein Redesign mit einem veralteten Web-Hash durfte nicht ausgeführt werden.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('expected_hashes.web', $exception->errors());
        }

        $afterConflict = $approved->fresh(['variants']);
        $this->assertSame(MarketingCreativeStatus::Approved, $afterConflict->status);
        $this->assertNotNull($afterConflict->approved_at);
        $this->assertSame($beforeHashes, $this->variantHashes($afterConflict));
        $this->assertSame($beforeVersions, $afterConflict->variants->mapWithKeys(
            fn ($variant): array => [$variant->format->value => $variant->version],
        )->all());
        $this->assertDatabaseMissing('activity_log', ['description' => 'marketing_creative_redesigned']);

        $archived = $studio->archive($afterConflict, $admin)->fresh(['variants']);
        try {
            $studio->redesignFromPreset(
                $archived,
                'railtime_modern',
                $this->variantHashes($archived),
                $admin,
            );
            $this->fail('Ein archiviertes Motiv durfte nicht neu gestaltet werden.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('creative', $exception->errors());
        }

        $this->assertSame(MarketingCreativeStatus::Archived, $archived->fresh()->status);
        $this->assertSame($beforeHashes, $this->variantHashes($archived->fresh(['variants'])));
        $this->assertDatabaseMissing('activity_log', ['description' => 'marketing_creative_redesigned']);
    }

    public function test_complete_redesign_route_is_admin_only_and_returns_all_three_fresh_variants(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $staff = User::factory()->create(['role' => 'staff']);
        $creative = app(MarketingStudioService::class)
            ->createFromTemplate(MarketingCreativeType::Job, $admin);
        $payload = [
            'preset' => 'railtime_modern',
            'expected_hashes' => $this->variantHashes($creative),
        ];

        $this->actingAs($staff)
            ->postJson(route('admin.marketing.creatives.redesign', $creative), $payload)
            ->assertForbidden();

        $this->actingAs($admin)
            ->postJson(route('admin.marketing.creatives.redesign', $creative), $payload)
            ->assertOk()
            ->assertJsonPath('changed', true)
            ->assertJsonPath('preset', 'railtime_modern')
            ->assertJsonPath('creative.status', 'draft')
            ->assertJsonStructure([
                'variants' => [
                    'story' => ['builder_data', 'content_hash', 'version'],
                    'post' => ['builder_data', 'content_hash', 'version'],
                    'web' => ['builder_data', 'content_hash', 'version'],
                ],
            ]);
    }

    public function test_clear_complete_redesign_intent_bypasses_the_provider_once_while_design_advice_uses_it(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $creative = app(MarketingStudioService::class)
            ->createFromTemplate(MarketingCreativeType::Job, $admin);
        $story = $creative->variants->firstWhere('format', MarketingCreativeFormat::Story);
        $context = $this->marketingAssistantContext($creative, $story);
        $client = new class extends OpenRouterChatClient
        {
            public int $toolDecisions = 0;

            public function __construct() {}

            public function completeToolDecision(
                array $messages,
                array $tools,
                OpenRouterModelProfile $profile = OpenRouterModelProfile::Text,
                array $plugins = [],
            ): OpenRouterToolDecision {
                $this->toolDecisions++;

                return new OpenRouterToolDecision('Provider-Designrat', []);
            }
        };
        $runner = app(AssistantKnowledgeToolRunner::class);
        $effects = [];
        $deltas = [];

        $answer = $runner->answer(
            $client,
            [['role' => 'user', 'content' => 'Redesign des kompletten Entwurfes bitte jetzt machen !!!']],
            function (string $delta) use (&$deltas): void {
                $deltas[] = $delta;
            },
            $admin,
            'admin.marketing.creatives.editor',
            [],
            function (array $effect) use (&$effects): void {
                $effects[] = $effect;
            },
            $context,
        );

        $this->assertSame(0, $client->toolDecisions);
        $this->assertCount(1, $effects);
        $this->assertSame('redesign_document', $effects[0]['command']);
        $this->assertSame('railtime_modern', $effects[0]['preset']);
        $this->assertStringContainsString('komplette RailTime-Modern-Redesign', $answer);
        $this->assertSame([$answer], $deltas);

        $effects = [];
        $deltas = [];
        $followUp = $runner->answer(
            $client,
            [
                ['role' => 'user', 'content' => 'Komplettes Design neu machen, komplettes Redesign bitte.'],
                ['role' => 'assistant', 'content' => 'Gerne. Soll es modern, klassisch oder technisch werden?'],
                ['role' => 'user', 'content' => 'modern'],
                ['role' => 'assistant', 'content' => 'Alles klar: modern. Ich richte Farben, Typografie und Hierarchie passend aus.'],
                ['role' => 'user', 'content' => 'Wie die RailTime-Website von den Farben und vom Layout her, aber mit eigenem Design.'],
                ['role' => 'assistant', 'content' => 'Gerne. Ich kann die Bereiche jetzt im Editor modern gestalten. Wenn du möchtest, setze ich als Nächstes den Entwurf gezielt um.'],
                ['role' => 'user', 'content' => 'ja bitte komplett umsetzen bitte'],
            ],
            function (string $delta) use (&$deltas): void {
                $deltas[] = $delta;
            },
            $admin,
            'admin.marketing.creatives.editor',
            [],
            function (array $effect) use (&$effects): void {
                $effects[] = $effect;
            },
            $context,
        );

        $this->assertSame(0, $client->toolDecisions);
        $this->assertCount(1, $effects);
        $this->assertSame('redesign_document', $effects[0]['command']);
        $this->assertStringContainsString('komplette RailTime-Modern-Redesign', $followUp);
        $this->assertSame([$followUp], $deltas);

        foreach ([
            [['role' => 'user', 'content' => 'Mach kein komplettes Redesign, bitte nur beraten.']],
            [['role' => 'user', 'content' => 'Das komplette Redesign bitte nicht umsetzen.']],
            [['role' => 'user', 'content' => 'Do not implement a complete redesign.']],
            [['role' => 'user', 'content' => 'Welche Risiken hat ein komplettes Redesign?']],
            [['role' => 'user', 'content' => 'Wie kann man ein komplettes Redesign umsetzen?']],
            [['role' => 'user', 'content' => 'Der Kunde schrieb: „Redesign des kompletten Entwurfes bitte jetzt machen!“ Was bedeutet das?']],
        ] as $providerMessages) {
            $effects = [];
            $deltas = [];
            $providerAnswer = $runner->answer(
                $client,
                $providerMessages,
                function (string $delta) use (&$deltas): void {
                    $deltas[] = $delta;
                },
                $admin,
                'admin.marketing.creatives.editor',
                [],
                function (array $effect) use (&$effects): void {
                    $effects[] = $effect;
                },
                $context,
            );

            $this->assertSame('Provider-Designrat', $providerAnswer);
            $this->assertSame(['Provider-Designrat'], $deltas);
            $this->assertSame([], $effects);
        }

        $this->assertSame(6, $client->toolDecisions);

        $effects = [];
        $deltas = [];
        $advice = $runner->answer(
            $client,
            [['role' => 'user', 'content' => 'Welche modernen Designprinzipien empfiehlst du für dieses Motiv?']],
            function (string $delta) use (&$deltas): void {
                $deltas[] = $delta;
            },
            $admin,
            'admin.marketing.creatives.editor',
            [],
            function (array $effect) use (&$effects): void {
                $effects[] = $effect;
            },
            $context,
        );

        $this->assertSame(7, $client->toolDecisions);
        $this->assertSame('Provider-Designrat', $advice);
        $this->assertSame(['Provider-Designrat'], $deltas);
        $this->assertSame([], $effects);
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
            '<div class="rt-brand rt-brand-lockup rt-brand-lockup-standard" data-rt-brand-lockup="official"><img class="rt-brand-logo" src="/rt-brand/img/logo-horizontal.png" alt="RT Rail Time GmbH"></div><section onclick="alert(1)"><a href="java&#x0A;script:alert(1)">Text</a><a href="https://www.rail-time.de/de/karriere">CTA</a><img src="https://attacker.example/pixel.png"><style>@import url(https://example.org)</style><script>alert(1)</script><iframe src="https://example.org"></iframe><svg><script>alert(2)</script></svg></section>',
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

    public function test_variant_save_and_approval_require_an_unmodified_official_brand_lockup(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $studio = app(MarketingStudioService::class);
        $creative = $studio->createFromTemplate(MarketingCreativeType::Job, $admin);
        $story = $creative->variants->firstWhere('format', MarketingCreativeFormat::Story);
        $withoutLogo = preg_replace(
            '/<div[^>]*data-rt-brand-lockup="official"[^>]*>.*?<\/div>/si',
            '',
            $story->html,
            1,
        );

        try {
            $studio->saveVariant(
                $creative,
                MarketingCreativeFormat::Story,
                $story->builder_data,
                (string) $withoutLogo,
                $story->css,
                $story->content_hash,
                $admin,
            );
            $this->fail('Ein Motiv ohne offizielles Firmenlogo durfte nicht gespeichert werden.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('html', $exception->errors());
        }

        $hiddenParent = preg_replace('/<main\b/i', '<main style="opacity:0"', $story->html, 1);
        try {
            $studio->saveVariant(
                $creative,
                MarketingCreativeFormat::Story,
                $story->builder_data,
                (string) $hiddenParent,
                $story->css,
                $story->content_hash,
                $admin,
            );
            $this->fail('Ein Motiv mit unsichtbarer Markenstruktur durfte nicht gespeichert werden.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('html', $exception->errors());
        }

        $story->forceFill([
            'html' => str_replace('/rt-brand/img/logo-horizontal-darkbg.png', '/rt-brand/img/unofficial-logo.png', $story->html),
        ])->save();

        try {
            $studio->approve($creative->fresh(), $admin);
            $this->fail('Ein Motiv mit verändertem Firmenlogo durfte nicht freigegeben werden.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('html', $exception->errors());
        }
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
            .'.f{background-image:image-set("https://attacker.example/set.png" 1x)}'
            .'.g{background-image:-webkit-image-set("https://attacker.example/webkit.png" 1x)}'
            .'.h{background-image:image("https://attacker.example/image.png")}'
            .'.i{background-image:cross-fade("https://attacker.example/fade.png", #fff 50%)}'
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
        $this->assertStringNotContainsStringIgnoringCase('image-set(', $css);
        $this->assertStringNotContainsStringIgnoringCase('cross-fade(', $css);
    }

    public function test_sanitizer_allows_exact_official_brand_assets_and_blocks_lookalike_paths(): void
    {
        $html = app(MarketingHtmlSanitizer::class)->html(
            '<img src="/rt-brand/img/logo-horizontal.png">'
            .'<img src="/rt-brand/img/logo-horizontal-darkbg.png">'
            .'<img src="/rt-brand/img/wagenmeister-pruefung.jpg">'
            .'<img src="/rt-brand/img/wagenmeister-team.webp">'
            .'<img src="/rt-brand/img/deutschland-netzwerk.png">'
            .'<img src="/rt-brand/img/logo-horizontal.png.exe">'
            .'<img src="/rt-brand/img/unofficial-logo.png">',
        );

        $this->assertStringContainsString('src="/rt-brand/img/logo-horizontal.png"', $html);
        $this->assertStringContainsString('src="/rt-brand/img/logo-horizontal-darkbg.png"', $html);
        $this->assertStringContainsString('src="/rt-brand/img/wagenmeister-pruefung.jpg"', $html);
        $this->assertStringContainsString('src="/rt-brand/img/wagenmeister-team.webp"', $html);
        $this->assertStringContainsString('src="/rt-brand/img/deutschland-netzwerk.png"', $html);
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

    /** @return array<string, mixed> */
    private function marketingAssistantContext(MarketingCreative $creative, mixed $variant): array
    {
        return [
            'version' => 1,
            'route_name' => 'admin.marketing.creatives.editor',
            'mode' => 'marketing',
            'resource_id' => (string) $creative->public_id,
            'format_or_kind' => $variant->format->value,
            'workspace_nonce' => 'workspace_redesign_test_2026',
            'fullscreen_open' => true,
            'editor_ready' => true,
            'read_only' => false,
            'persisted_content_hash' => (string) $variant->content_hash,
            'persisted_version' => (int) $variant->version,
            'client_revision' => 0,
            'unsaved' => false,
            'selection' => null,
            'capabilities' => ['open_fullscreen', 'save', 'redesign_document'],
            'available_block_ids' => [],
            'validation' => ['state' => 'valid', 'issues' => []],
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
