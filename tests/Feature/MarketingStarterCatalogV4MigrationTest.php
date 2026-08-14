<?php

namespace Tests\Feature;

use App\Enums\MarketingCreativeFormat;
use App\Enums\MarketingCreativeStatus;
use App\Models\MarketingCreative;
use App\Models\MarketingCreativeVariant;
use App\Models\User;
use App\Services\Marketing\MarketingContentBinder;
use App\Services\Marketing\MarketingHtmlSanitizer;
use App\Services\Marketing\MarketingStudioService;
use App\Services\Marketing\MarketingTemplateFactory;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Tests\TestCase;

class MarketingStarterCatalogV4MigrationTest extends TestCase
{
    use DatabaseMigrations;

    /** @var array<string, string> */
    private const RELEASES = [
        MarketingTemplateFactory::INFO_WAGENMEISTER_ROLE => MarketingTemplateFactory::PREMIUM_COMPANY_PROFILE,
        MarketingTemplateFactory::JOB_WAGENMEISTER => MarketingTemplateFactory::PREMIUM_JOB_WAGENMEISTER,
        MarketingTemplateFactory::INFO_GERMANY_NETWORK => MarketingTemplateFactory::PREMIUM_GERMANY_NETWORK,
    ];

    /** @var array<string, array{html:string,css:string}> */
    private const OLD_JOB_FINGERPRINTS = [
        'story' => [
            'html' => 'c3a69b9f28f3c78cc97cf461c242e2ace52cbb7bad252cf94886080c5e88e987',
            'css' => '9450c08ae1ec9123e4797e67a6ce4c158ae7fca0149236f1b8d845e0f7bc01fe',
        ],
        'post' => [
            'html' => '1ec4fba2037151d43cde74e721b069a350062dbde3f795c375bff5337d759882',
            'css' => 'f24176b5aa449ec6498f0f4bfa76d840b2f23a1f25bfa133f5f5338c73362d85',
        ],
        'web' => [
            'html' => 'e62c8bb8b19ad21fbd875c09f7d50a20df5e46d61497ad44123961f0e09be8bf',
            'css' => '6335bfd68b24c5da5ffd494cc60ccf811f1de61d82d6144a138d3a264f6abf36',
        ],
    ];

    public function test_it_converts_exact_v3_starters_in_place_and_is_idempotent(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $studio = app(MarketingStudioService::class);
        $templates = app(MarketingTemplateFactory::class);
        $legacyByKey = [];

        foreach (array_keys(self::RELEASES) as $legacyKey) {
            $legacy = $this->createFromKey($admin, $legacyKey);
            $legacyByKey[$legacyKey] = [
                'id' => $legacy->id,
                'versions' => $legacy->variants
                    ->mapWithKeys(fn ($variant): array => [$variant->format->value => $variant->version])
                    ->all(),
            ];
        }

        $migration = $this->migration();
        $migration->up();

        foreach (self::RELEASES as $legacyKey => $premiumKey) {
            $premium = MarketingCreative::query()
                ->where('shared_content->template_key', $premiumKey)
                ->with('variants')
                ->sole();
            $definition = $templates->definitionByKey($premiumKey);

            $this->assertSame($legacyByKey[$legacyKey]['id'], $premium->id);
            $this->assertSame($definition['title'], $premium->title);
            $this->assertSame(MarketingTemplateFactory::PREMIUM_SEED_VERSION, $premium->shared_content['seed_version']);
            $this->assertSame(MarketingCreativeStatus::Draft, $premium->status);
            $this->assertSame(0, MarketingCreative::withTrashed()
                ->where('shared_content->template_key', $legacyKey)
                ->count());
            $this->assertCount(3, $premium->variants);

            foreach ($premium->variants as $variant) {
                $format = $variant->format->value;
                $this->assertSame($legacyByKey[$legacyKey]['versions'][$format] + 1, $variant->version);
                $this->assertSame(4, data_get($variant->builder_data, 'railtime.schema'));
                $this->assertSame($premiumKey, data_get($variant->builder_data, 'railtime.template'));
                $this->assertSame(
                    $studio->contentHash($variant->builder_data, $variant->html, $variant->css),
                    $variant->content_hash,
                );
            }
        }

        $state = $this->catalogState();
        $migration->up();

        $this->assertSame($state, $this->catalogState());
        $this->assertDatabaseCount('marketing_creatives', 3);
        $this->assertDatabaseCount('marketing_creative_variants', 9);
    }

    public function test_it_preserves_custom_approved_and_deleted_v3_records_and_installs_separate_v4_starters(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $customJob = $this->createFromKey($admin, MarketingTemplateFactory::JOB_WAGENMEISTER);
        $customJob->forceFill([
            'shared_content' => array_replace($customJob->shared_content, [
                'intro' => 'Individuell gepflegte Recruiting-Kampagne',
            ]),
        ])->save();
        $customHashes = $customJob->variants()->orderBy('id')->pluck('content_hash')->all();

        $approvedInfo = $this->createFromKey($admin, MarketingTemplateFactory::INFO_WAGENMEISTER_ROLE);
        $approvedInfo->forceFill([
            'status' => MarketingCreativeStatus::Approved,
            'approved_by' => $admin->id,
            'approved_at' => now(),
            'approval_dependency_hash' => str_repeat('a', 64),
        ])->save();

        $deletedNetwork = $this->createFromKey($admin, MarketingTemplateFactory::INFO_GERMANY_NETWORK);
        $deletedNetwork->delete();

        $this->migration()->up();

        foreach (array_values(self::RELEASES) as $premiumKey) {
            $premium = MarketingCreative::query()
                ->where('shared_content->template_key', $premiumKey)
                ->with('variants')
                ->sole();

            $this->assertSame(MarketingTemplateFactory::PREMIUM_SEED_VERSION, $premium->shared_content['seed_version']);
            $this->assertCount(3, $premium->variants);
            $this->assertNotContains($premium->id, [$customJob->id, $approvedInfo->id, $deletedNetwork->id]);
        }

        $customJob->refresh();
        $this->assertSame('Individuell gepflegte Recruiting-Kampagne', $customJob->shared_content['intro']);
        $this->assertSame($customHashes, $customJob->variants()->orderBy('id')->pluck('content_hash')->all());

        $approvedInfo->refresh();
        $this->assertSame(MarketingCreativeStatus::Approved, $approvedInfo->status);
        $this->assertSame($admin->id, $approvedInfo->approved_by);
        $this->assertSame(str_repeat('a', 64), $approvedInfo->approval_dependency_hash);

        $deletedNetwork = MarketingCreative::withTrashed()->findOrFail($deletedNetwork->id);
        $this->assertTrue($deletedNetwork->trashed());
        $this->assertSame(3, $deletedNetwork->variants()->withTrashed()->count());
        $this->assertSame(0, $deletedNetwork->variants()->count());

        $this->assertSame(6, MarketingCreative::withTrashed()->count());
        $this->assertSame(18, MarketingCreativeVariant::withTrashed()->count());
    }

    public function test_an_existing_soft_deleted_v4_key_blocks_conversion_and_recreation(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $legacyInfo = $this->createFromKey($admin, MarketingTemplateFactory::INFO_WAGENMEISTER_ROLE);
        $deletedPremium = $this->createFromKey($admin, MarketingTemplateFactory::PREMIUM_COMPANY_PROFILE);
        $this->createFromKey($admin, MarketingTemplateFactory::PREMIUM_JOB_WAGENMEISTER);
        $this->createFromKey($admin, MarketingTemplateFactory::PREMIUM_GERMANY_NETWORK);
        $deletedPremium->delete();

        $migration = $this->migration();
        $migration->up();

        $this->assertSame(1, MarketingCreative::withTrashed()
            ->where('shared_content->template_key', MarketingTemplateFactory::PREMIUM_COMPANY_PROFILE)
            ->count());
        $this->assertSoftDeleted('marketing_creatives', ['id' => $deletedPremium->id]);
        $this->assertDatabaseHas('marketing_creatives', [
            'id' => $legacyInfo->id,
            'deleted_at' => null,
        ]);
        $this->assertSame(
            MarketingTemplateFactory::INFO_WAGENMEISTER_ROLE,
            data_get($legacyInfo->fresh()->shared_content, 'template_key'),
        );

        $state = $this->catalogState();
        $migration->up();
        $this->assertSame($state, $this->catalogState());
    }

    public function test_job_only_refresh_updates_an_exact_old_v4_draft_in_place_and_is_idempotent(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $oldJob = $this->createOldV4Job($admin);
        $oldJob->forceFill([
            'shared_content' => array_replace($oldJob->shared_content, [
                'contact_phone' => '+49 4171 000000',
            ]),
        ])->save();
        $oldVersions = $oldJob->variants
            ->mapWithKeys(fn (MarketingCreativeVariant $variant): array => [
                $variant->format->value => $variant->version,
            ])
            ->all();

        $alreadyCurrentJob = $this->createFromKey($admin, MarketingTemplateFactory::PREMIUM_JOB_WAGENMEISTER);
        $company = $this->createFromKey($admin, MarketingTemplateFactory::PREMIUM_COMPANY_PROFILE);
        $network = $this->createFromKey($admin, MarketingTemplateFactory::PREMIUM_GERMANY_NETWORK);
        $protectedState = $this->catalogStateForIds([
            $alreadyCurrentJob->id,
            $company->id,
            $network->id,
        ]);

        $migration = $this->jobRefreshMigration();
        $editorialHash = new \ReflectionMethod($migration, 'editorialHash');
        $htmlStructureHash = new \ReflectionMethod($migration, 'htmlStructureHash');
        $this->assertSame(
            'f7f211de5fbfae7fc4a337d05c9078cf2cdcfbbb8ade341b55c4b2a9e8dacaf9',
            $editorialHash->invoke($migration, $oldJob->shared_content),
        );
        foreach ($oldJob->variants as $variant) {
            $format = $variant->format->value;
            $this->assertSame(
                self::OLD_JOB_FINGERPRINTS[$format]['html'],
                $htmlStructureHash->invoke($migration, $variant->html),
            );
            $this->assertSame(
                self::OLD_JOB_FINGERPRINTS[$format]['css'],
                hash('sha256', $variant->css),
            );
        }
        $migration->up();

        $oldJob->refresh()->load('variants');
        $definition = app(MarketingTemplateFactory::class)
            ->definitionByKey(MarketingTemplateFactory::PREMIUM_JOB_WAGENMEISTER);
        $expectedContent = array_replace($definition['shared_content'], [
            'contact_phone' => '+49 4171 000000',
        ]);

        $this->assertSame($definition['title'], $oldJob->title);
        $this->assertSame($expectedContent, $oldJob->shared_content);
        $this->assertSame(MarketingCreativeStatus::Draft, $oldJob->status);
        $this->assertNull($oldJob->approved_by);
        $this->assertNull($oldJob->approved_at);
        $this->assertNull($oldJob->approval_dependency_hash);
        $this->assertCount(3, $oldJob->variants);

        $binder = app(MarketingContentBinder::class);
        $sanitizer = app(MarketingHtmlSanitizer::class);
        $studio = app(MarketingStudioService::class);

        foreach ($oldJob->variants as $variant) {
            $format = $variant->format->value;
            $template = $definition['variants'][$format];
            $expectedHtml = $sanitizer->html($binder->bindHtml($template['html'], $expectedContent));
            $expectedCss = $sanitizer->css($template['css']);
            $expectedBuilderData = $binder->syncBuilderData($template['builder_data'], $expectedHtml);

            $this->assertSame($oldVersions[$format] + 1, $variant->version);
            $this->assertSame($expectedHtml, $variant->html);
            $this->assertSame($expectedCss, $variant->css);
            $this->assertSame($expectedBuilderData, $variant->builder_data);
            $this->assertSame(
                $studio->contentHash($expectedBuilderData, $expectedHtml, $expectedCss),
                $variant->content_hash,
            );
        }

        $this->assertSame($protectedState, $this->catalogStateForIds([
            $alreadyCurrentJob->id,
            $company->id,
            $network->id,
        ]));

        $stateAfterRefresh = $this->catalogState();
        $migration->up();

        $this->assertSame($stateAfterRefresh, $this->catalogState());
        $this->assertSame($oldJob->id, MarketingCreative::query()
            ->whereKey($oldJob->id)
            ->value('id'));
    }

    public function test_job_only_refresh_preserves_custom_approved_incomplete_and_soft_deleted_old_v4_jobs(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $contentEdited = $this->createOldV4Job($admin);
        $contentEdited->forceFill([
            'shared_content' => array_replace($contentEdited->shared_content, [
                'intro' => 'Individuell gepflegte Recruiting-Kampagne',
            ]),
        ])->save();

        $layoutEdited = $this->createOldV4Job($admin);
        $layoutVariant = $layoutEdited->variants()
            ->where('format', MarketingCreativeFormat::Story->value)
            ->firstOrFail();
        $layoutCss = $layoutVariant->css.'.rt-user-layout{display:block}';
        $layoutVariant->forceFill([
            'css' => $layoutCss,
            'content_hash' => app(MarketingStudioService::class)->contentHash(
                $layoutVariant->builder_data,
                $layoutVariant->html,
                $layoutCss,
            ),
            'version' => $layoutVariant->version + 1,
        ])->save();

        $approved = $this->createOldV4Job($admin);
        $approved->forceFill([
            'status' => MarketingCreativeStatus::Approved,
            'approved_by' => $admin->id,
            'approved_at' => now(),
            'approval_dependency_hash' => str_repeat('a', 64),
        ])->save();

        $incomplete = $this->createOldV4Job($admin);
        $incomplete->variants()
            ->where('format', MarketingCreativeFormat::Web->value)
            ->firstOrFail()
            ->delete();

        $deleted = $this->createOldV4Job($admin);
        $deleted->delete();

        $state = $this->catalogState();
        $this->jobRefreshMigration()->up();

        $this->assertSame($state, $this->catalogState());
        $this->assertSoftDeleted('marketing_creatives', ['id' => $deleted->id]);
        $this->assertSame(MarketingCreativeStatus::Approved, $approved->fresh()->status);
        $this->assertSame(1, $incomplete->variants()->withTrashed()->whereNotNull('deleted_at')->count());
    }

    private function migration(): Migration
    {
        return require database_path('migrations/2026_08_14_000100_install_premium_marketing_catalog_v4.php');
    }

    private function jobRefreshMigration(): Migration
    {
        return require database_path('migrations/2026_08_14_000200_refresh_untouched_premium_job_catalog.php');
    }

    private function createFromKey(User $admin, string $templateKey): MarketingCreative
    {
        $type = app(MarketingTemplateFactory::class)->typeForKey($templateKey);

        return app(MarketingStudioService::class)->createFromTemplate(
            $type,
            $admin,
            $templateKey,
        );
    }

    private function createOldV4Job(User $admin): MarketingCreative
    {
        $creative = $this->createFromKey($admin, MarketingTemplateFactory::PREMIUM_JOB_WAGENMEISTER);
        $definition = app(MarketingTemplateFactory::class)
            ->definitionByKey(MarketingTemplateFactory::PREMIUM_JOB_WAGENMEISTER);
        $binder = app(MarketingContentBinder::class);
        $sanitizer = app(MarketingHtmlSanitizer::class);
        $studio = app(MarketingStudioService::class);

        foreach ($creative->variants as $variant) {
            $format = $variant->format;
            $template = $definition['variants'][$format->value];
            $templateHtml = preg_replace(
                '#(<figure class="rt-photo"><img )src="[^"]+" alt="[^"]+"#',
                '$1src="/rt-brand/img/wagenmeister-pruefung.jpg" alt="Wagenmeister bei der technischen Prüfung eines Güterwagens"',
                $template['html'],
                1,
            );
            if (! is_string($templateHtml)) {
                throw new \RuntimeException('Das alte Premium-Job-HTML konnte nicht rekonstruiert werden.');
            }
            $templateCss = $this->oldV4JobCss($format, $template['css']);
            $html = $sanitizer->html($binder->bindHtml($templateHtml, $creative->shared_content));
            $css = $sanitizer->css($templateCss);
            $builderData = $binder->syncBuilderData($template['builder_data'], $html);

            $variant->forceFill([
                'builder_data' => $builderData,
                'html' => $html,
                'css' => $css,
                'content_hash' => $studio->contentHash($builderData, $html, $css),
                'version' => 2,
            ])->save();
        }

        return $creative->refresh()->load('variants');
    }

    private function oldV4JobCss(MarketingCreativeFormat $format, string $currentCss): string
    {
        $marker = '.rt-job-premium-'.$format->value;
        $specificOffset = strpos($currentCss, $marker);
        if ($specificOffset === false) {
            throw new \RuntimeException('Der Premium-Job-CSS-Marker fehlt.');
        }

        return substr($currentCss, 0, $specificOffset).$this->oldV4JobSpecificCss($format);
    }

    private function oldV4JobSpecificCss(MarketingCreativeFormat $format): string
    {
        return match ($format) {
            MarketingCreativeFormat::Story => <<<'CSS'
.rt-job-premium-story{background:#090c11;color:#fff}.rt-job-premium-story:before{z-index:2;background-image:linear-gradient(rgba(255,255,255,.035) 1px,transparent 1px),linear-gradient(90deg,rgba(255,255,255,.035) 1px,transparent 1px)}.rt-job-premium-story .rt-photo{inset:0 0 auto;height:1120px;background:#090c11}.rt-job-premium-story .rt-photo:after{position:absolute;inset:0;background:linear-gradient(180deg,rgba(9,12,17,.2),rgba(9,12,17,.08) 35%,#090c11 96%),linear-gradient(90deg,rgba(9,12,17,.18),transparent 62%);content:""}.rt-job-premium-story .rt-photo img{object-position:55% center}.rt-job-premium-story .rt-mast{top:52px;right:58px;left:58px;display:flex;align-items:center;justify-content:space-between}.rt-job-premium-story .rt-brand-lockup{width:260px}.rt-job-premium-story .rt-code{color:#fff}.rt-job-premium-story .rt-copy{top:430px;right:58px;left:58px}.rt-job-premium-story .rt-kicker{font-size:15px}.rt-job-premium-story h1{max-width:900px;margin-top:18px;font-size:113px;line-height:.86}.rt-job-premium-story .rt-subtitle{max-width:800px;margin-top:27px;font-size:31px;line-height:1.2}.rt-job-premium-story .rt-job-panel{position:absolute;z-index:3;top:1080px;right:58px;left:58px}.rt-job-premium-story .rt-intro{max-width:890px;color:#c2cbd3;font-size:20px}.rt-job-premium-story .rt-keywords{margin-top:28px;font-size:14px}.rt-job-premium-story .rt-facts{margin-top:42px;grid-template-columns:repeat(3,1fr);border-top:1px solid rgba(255,255,255,.18);border-bottom:1px solid rgba(255,255,255,.18)}.rt-job-premium-story .rt-facts>div{min-height:150px;padding:27px 18px;border-right:1px solid rgba(255,255,255,.18)}.rt-job-premium-story .rt-facts>div:last-child{border:0}.rt-job-premium-story .rt-facts strong{color:#fff;font-size:50px}.rt-job-premium-story .rt-facts span{margin-top:10px;color:#aab4bd;font-size:14px;text-transform:uppercase}.rt-job-premium-story footer{right:0;bottom:0;left:0;display:flex;height:300px;padding:53px 58px;background:#f4f2ed;color:#10151b}.rt-job-premium-story footer>div{display:grid;margin-left:auto;text-align:right;gap:9px}.rt-job-premium-story footer strong{font-size:23px}.rt-job-premium-story footer span{font-size:14px}
.rt-job-premium-story .rt-mast{top:104px}.rt-job-premium-story .rt-code,.rt-job-premium-story .rt-kicker{font-size:18px}.rt-job-premium-story .rt-copy{top:450px}.rt-job-premium-story .rt-intro{font-size:26px;line-height:1.42}.rt-job-premium-story .rt-keywords{font-size:17px}.rt-job-premium-story .rt-facts span{font-size:17px}.rt-job-premium-story footer{align-items:flex-start}.rt-job-premium-story .rt-cta{font-size:16px}.rt-job-premium-story footer strong{font-size:24px}.rt-job-premium-story footer span{font-size:18px}
CSS,
            MarketingCreativeFormat::Post => <<<'CSS'
.rt-job-premium-post{background:#090c11;color:#fff}.rt-job-premium-post .rt-photo{top:0;right:0;width:55%;height:815px}.rt-job-premium-post .rt-photo:after{position:absolute;inset:0;background:linear-gradient(90deg,#090c11 0,transparent 31%),linear-gradient(0deg,#090c11 0,transparent 27%);content:""}.rt-job-premium-post .rt-photo img{object-position:60% center}.rt-job-premium-post .rt-mast{top:42px;right:48px;left:48px;display:flex;align-items:center;justify-content:space-between}.rt-job-premium-post .rt-brand-lockup{width:230px}.rt-job-premium-post .rt-code{color:#fff}.rt-job-premium-post .rt-copy{top:240px;left:48px;width:690px}.rt-job-premium-post h1{margin-top:13px;font-size:78px;line-height:.87}.rt-job-premium-post .rt-subtitle{max-width:570px;margin-top:20px;font-size:25px}.rt-job-premium-post .rt-keywords{margin-top:25px;font-size:12px}.rt-job-premium-post .rt-cta{margin-top:32px}.rt-job-premium-post footer{right:0;bottom:0;left:0;display:flex;height:265px;padding:39px 48px;background:#f4f2ed;color:#10151b}.rt-job-premium-post footer .rt-facts{width:790px;grid-template-columns:repeat(3,1fr)}.rt-job-premium-post .rt-facts>div{padding-right:24px;border-right:1px solid rgba(16,21,27,.17)}.rt-job-premium-post .rt-facts>div+div{padding-left:24px}.rt-job-premium-post .rt-facts strong{font-size:39px}.rt-job-premium-post .rt-facts span{margin-top:7px;font-size:12px;text-transform:uppercase}.rt-job-premium-post footer>strong{margin-left:auto;font-size:14px}
CSS,
            MarketingCreativeFormat::Web => <<<'CSS'
.rt-job-premium-web{background:#090c11;color:#fff}.rt-job-premium-web .rt-copy{top:0;bottom:0;left:0;width:59%;padding:35px 48px}.rt-job-premium-web .rt-brand-lockup{width:205px}.rt-job-premium-web .rt-kicker{margin-top:28px;font-size:11px}.rt-job-premium-web h1{margin-top:10px;font-size:59px;line-height:.86}.rt-job-premium-web .rt-subtitle{max-width:620px;margin-top:14px;font-size:20px}.rt-job-premium-web .rt-intro{max-width:610px;margin-top:14px;color:#bcc5cd;font-size:13px}.rt-job-premium-web .rt-actions{margin-top:20px}.rt-job-premium-web .rt-cta{min-height:46px;padding:0 19px;font-size:11px}.rt-job-premium-web .rt-actions strong{font-size:12px}.rt-job-premium-web .rt-photo{top:0;right:0;width:45%;height:630px}.rt-job-premium-web .rt-photo:after{position:absolute;inset:0;background:linear-gradient(90deg,#090c11 0,transparent 25%),linear-gradient(0deg,rgba(9,12,17,.7),transparent 50%);content:""}.rt-job-premium-web .rt-photo img{object-position:58% center}.rt-job-premium-web .rt-photo figcaption{right:22px;bottom:20px}.rt-job-premium-web footer{right:22px;bottom:66px;width:430px;padding:17px 18px;background:rgba(9,12,17,.91)}.rt-job-premium-web footer .rt-facts{grid-template-columns:repeat(3,1fr)}.rt-job-premium-web .rt-facts strong{font-size:24px}.rt-job-premium-web .rt-facts span{margin-top:4px;color:#fff;font-size:9px;text-transform:uppercase}
CSS,
        };
    }

    /** @param list<int> $creativeIds
     * @return array<int, array<string, mixed>>
     */
    private function catalogStateForIds(array $creativeIds): array
    {
        return MarketingCreative::withTrashed()
            ->whereIn('id', $creativeIds)
            ->with(['variants' => fn ($query) => $query->withTrashed()->orderBy('id')])
            ->orderBy('id')
            ->get()
            ->map(fn (MarketingCreative $creative): array => [
                'id' => $creative->id,
                'type' => $creative->getRawOriginal('type'),
                'status' => $creative->getRawOriginal('status'),
                'title' => $creative->title,
                'content' => $creative->shared_content,
                'approved_by' => $creative->approved_by,
                'approved_at' => $creative->approved_at?->toISOString(),
                'approval_dependency_hash' => $creative->approval_dependency_hash,
                'deleted_at' => $creative->deleted_at?->toISOString(),
                'variants' => $creative->variants->map(fn (MarketingCreativeVariant $variant): array => [
                    'id' => $variant->id,
                    'format' => $variant->getRawOriginal('format'),
                    'builder_data' => $variant->builder_data,
                    'html' => $variant->html,
                    'css' => $variant->css,
                    'version' => $variant->version,
                    'hash' => $variant->content_hash,
                    'deleted_at' => $variant->deleted_at?->toISOString(),
                ])->all(),
            ])
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function catalogState(): array
    {
        return MarketingCreative::withTrashed()
            ->with(['variants' => fn ($query) => $query->withTrashed()->orderBy('id')])
            ->orderBy('id')
            ->get()
            ->map(fn (MarketingCreative $creative): array => [
                'id' => $creative->id,
                'title' => $creative->title,
                'content' => $creative->shared_content,
                'deleted_at' => $creative->deleted_at?->toISOString(),
                'variants' => $creative->variants->map(fn ($variant): array => [
                    'id' => $variant->id,
                    'version' => $variant->version,
                    'hash' => $variant->content_hash,
                    'deleted_at' => $variant->deleted_at?->toISOString(),
                ])->all(),
            ])
            ->all();
    }
}
