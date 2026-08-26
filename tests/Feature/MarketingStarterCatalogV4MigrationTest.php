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

    /**
     * @var array<string, array{
     *     title:string,
     *     editorial_hash:string,
     *     variants:array<string, array{html:string,css:string}>
     * }>
     */
    private const SHORTENED_JOB_RELEASES = [
        'pre_000200' => [
            'title' => 'Wagenmeister (m/w/d) – Gemeinsam Sicherheit bewegen',
            'editorial_hash' => 'f7f211de5fbfae7fc4a337d05c9078cf2cdcfbbb8ade341b55c4b2a9e8dacaf9',
            'variants' => [
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
            ],
        ],
        'webp_f754' => [
            'title' => 'Wagenmeister (m/w/d) – Gemeinsam Sicherheit bewegen',
            'editorial_hash' => 'f7f211de5fbfae7fc4a337d05c9078cf2cdcfbbb8ade341b55c4b2a9e8dacaf9',
            'variants' => [
                'story' => [
                    'html' => '6bb90fcad5978cfb178392805fa57763df4141dedf4286d9cbb52589e566594a',
                    'css' => '683d3d6fee185b8fb61e1856275541e81d59b49d9c48ea497c12b354e75d45ac',
                ],
                'post' => [
                    'html' => '790ec0c5bb57ede6099d6a1752500435203a3594f1523b23494e06428f9a160b',
                    'css' => 'd3258a5a226e3d659992321489af0033a78c0f9a404026f56419c13d66d1fe5e',
                ],
                'web' => [
                    'html' => '0ae65313f255d4478285f9bf68d0c97adf7a56847ec489ae7f6680276f0dfc96',
                    'css' => 'a2854111207ade6159219102ba2dda36cc2524dd4874559876c6690697b9fd2f',
                ],
            ],
        ],
        'jpeg_438' => [
            'title' => 'Wagenmeister (m/w/d) – Gemeinsam Sicherheit bewegen',
            'editorial_hash' => 'f7f211de5fbfae7fc4a337d05c9078cf2cdcfbbb8ade341b55c4b2a9e8dacaf9',
            'variants' => [
                'story' => [
                    'html' => '825817818be275e144306ddf2e7504338948c7ff9caca896d0687b7752f6c15e',
                    'css' => '683d3d6fee185b8fb61e1856275541e81d59b49d9c48ea497c12b354e75d45ac',
                ],
                'post' => [
                    'html' => '03434c79ee8438ad9e65faa55e2458b5184ada39f3fd40f601d4dab2032406f0',
                    'css' => 'd3258a5a226e3d659992321489af0033a78c0f9a404026f56419c13d66d1fe5e',
                ],
                'web' => [
                    'html' => 'e6b97784ce3433d6d77fedf1be8ca7c3171d20915cffa37f9b84cd12daaa3f58',
                    'css' => 'a2854111207ade6159219102ba2dda36cc2524dd4874559876c6690697b9fd2f',
                ],
            ],
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

    public function test_complete_job_content_refresh_uses_all_pinned_releases_and_updates_an_exact_draft(): void
    {
        $migration = $this->completeJobContentMigration();
        $releaseConstant = (new \ReflectionClass($migration))->getReflectionConstant('RELEASES');

        $this->assertInstanceOf(\ReflectionClassConstant::class, $releaseConstant);
        $this->assertSame(self::SHORTENED_JOB_RELEASES, $releaseConstant->getValue());

        $admin = User::factory()->create(['role' => 'admin']);
        $job = $this->createOldV4Job($admin);
        $companyFields = [
            'contact_phone' => '+49 4171 123456',
            'contact_email' => 'karriere@example.test',
            'website' => 'jobs.example.test',
            'company_name' => 'RailTime Testbetrieb GmbH',
            'company_address' => 'Testgleis 7, 21423 Winsen',
        ];
        $job->forceFill([
            'shared_content' => array_replace($job->shared_content, $companyFields),
        ])->save();
        $oldVersions = $job->variants
            ->mapWithKeys(fn (MarketingCreativeVariant $variant): array => [
                $variant->format->value => $variant->version,
            ])
            ->all();

        $migration->up();

        $job->refresh()->load('variants');
        $definition = app(MarketingTemplateFactory::class)
            ->definitionByKey(MarketingTemplateFactory::PREMIUM_JOB_WAGENMEISTER);
        $expectedContent = array_replace($definition['shared_content'], $companyFields);
        $binder = app(MarketingContentBinder::class);
        $sanitizer = app(MarketingHtmlSanitizer::class);
        $studio = app(MarketingStudioService::class);

        $this->assertSame($definition['title'], $job->title);
        $this->assertSame($expectedContent, $job->shared_content);
        $this->assertSame(MarketingCreativeStatus::Draft, $job->status);
        $this->assertFalse($job->trashed());
        $this->assertNull($job->approved_by);
        $this->assertNull($job->approved_at);
        $this->assertNull($job->approval_dependency_hash);
        $this->assertSame($definition['shared_content']['tasks'], $job->shared_content['tasks']);
        $this->assertSame($definition['shared_content']['profile'], $job->shared_content['profile']);
        $this->assertSame($definition['shared_content']['benefits'], $job->shared_content['benefits']);

        foreach ($companyFields as $field => $value) {
            $this->assertSame($value, $job->shared_content[$field]);
        }

        foreach ($job->variants as $variant) {
            $format = $variant->format->value;
            $template = $definition['variants'][$format];
            $expectedHtml = $sanitizer->html($binder->bindHtml($template['html'], $expectedContent));
            $expectedCss = $sanitizer->css($template['css']);
            $expectedBuilderData = $binder->syncBuilderData($template['builder_data'], $expectedHtml);

            $this->assertSame($oldVersions[$format] + 1, $variant->version);
            $this->assertSame($expectedHtml, $variant->html);
            $this->assertSame($expectedCss, $variant->css);
            $this->assertSame($expectedBuilderData, $variant->builder_data);
            $this->assertSame(4, data_get($variant->builder_data, 'railtime.schema'));
            $this->assertSame(
                $studio->contentHash($expectedBuilderData, $expectedHtml, $expectedCss),
                $variant->content_hash,
            );

            foreach (['tasks', 'profile', 'benefits'] as $binding) {
                $this->assertStringContainsString('data-rt-binding-list="'.$binding.'"', $variant->html);
                foreach ($expectedContent[$binding] as $item) {
                    $this->assertStringContainsString(e($item), $variant->html);
                }
            }
        }

        $state = $this->catalogStateForIds([$job->id]);
        $migration->down();
        $this->assertSame($state, $this->catalogStateForIds([$job->id]));

        $migration->up();
        $this->assertSame($state, $this->catalogStateForIds([$job->id]));
    }

    public function test_complete_job_content_refresh_preserves_non_exact_and_mixed_release_records(): void
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

        $invalidHash = $this->createOldV4Job($admin);
        $invalidHash->variants()
            ->where('format', MarketingCreativeFormat::Post->value)
            ->firstOrFail()
            ->forceFill(['content_hash' => str_repeat('f', 64)])
            ->save();

        $missingCompanyField = $this->createOldV4Job($admin);
        $missingCompanyContent = $missingCompanyField->shared_content;
        unset($missingCompanyContent['company_address']);
        $missingCompanyField->forceFill(['shared_content' => $missingCompanyContent])->save();

        $mixedRelease = $this->createOldV4Job($admin);
        $mixedVariant = $mixedRelease->variants()
            ->where('format', MarketingCreativeFormat::Story->value)
            ->firstOrFail();
        $mixedHtml = str_replace(
            [
                '/rt-brand/img/wagenmeister-pruefung.jpg',
                'Wagenmeister bei der technischen Prüfung eines Güterwagens',
            ],
            [
                '/rt-brand/img/wagenmeister-team-gleis.jpeg',
                'RailTime-Wagenmeister im Einsatz zwischen Güterwagen',
            ],
            $mixedVariant->html,
        );
        $mixedBuilderData = $mixedVariant->builder_data;
        $mixedBuilderData['pages'][0]['component'] = $mixedHtml;
        $mixedVariant->forceFill([
            'builder_data' => $mixedBuilderData,
            'html' => $mixedHtml,
            'content_hash' => app(MarketingStudioService::class)->contentHash(
                $mixedBuilderData,
                $mixedHtml,
                $mixedVariant->css,
            ),
        ])->save();

        $protectedIds = [
            $contentEdited->id,
            $layoutEdited->id,
            $approved->id,
            $incomplete->id,
            $deleted->id,
            $invalidHash->id,
            $missingCompanyField->id,
            $mixedRelease->id,
        ];
        $state = $this->catalogStateForIds($protectedIds);

        $this->completeJobContentMigration()->up();

        $this->assertSame($state, $this->catalogStateForIds($protectedIds));
        $this->assertSoftDeleted('marketing_creatives', ['id' => $deleted->id]);
        $this->assertSame(MarketingCreativeStatus::Approved, $approved->fresh()->status);
        $this->assertSame(1, $incomplete->variants()->withTrashed()->whereNotNull('deleted_at')->count());
    }

    public function test_information_card_refresh_fails_closed_after_its_pinned_target_advances(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $job = $this->createPlainInformationCardJob($admin);
        $companyFields = [
            'contact_phone' => '+49 4171 765432',
            'contact_email' => 'karten@example.test',
            'website' => 'karriere.example.test',
            'company_name' => 'RailTime Karten Test GmbH',
            'company_address' => 'Modulgleis 4, 21423 Winsen',
        ];
        $job->forceFill([
            'shared_content' => array_replace($job->shared_content, $companyFields),
        ])->save();
        $state = $this->catalogStateForIds([$job->id]);
        $migration = $this->informationCardMigration();
        $migration->up();

        $job->refresh()->load('variants');
        $this->assertSame($state, $this->catalogStateForIds([$job->id]));
        foreach ($companyFields as $field => $value) {
            $this->assertSame($value, $job->shared_content[$field]);
        }
        $migration->down();
        $this->assertSame($state, $this->catalogStateForIds([$job->id]));
        $migration->up();
        $this->assertSame($state, $this->catalogStateForIds([$job->id]));
    }

    public function test_information_card_refresh_preserves_custom_approved_incomplete_and_deleted_records(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $contentEdited = $this->createPlainInformationCardJob($admin);
        $contentEdited->forceFill([
            'shared_content' => array_replace($contentEdited->shared_content, [
                'intro' => 'Individuell bearbeiteter Kartentext',
            ]),
        ])->save();

        $layoutEdited = $this->createPlainInformationCardJob($admin);
        $layoutVariant = $layoutEdited->variants()
            ->where('format', MarketingCreativeFormat::Story->value)
            ->firstOrFail();
        $layoutCss = $layoutVariant->css.'.rt-custom-card{display:block}';
        $layoutVariant->forceFill([
            'css' => $layoutCss,
            'content_hash' => app(MarketingStudioService::class)->contentHash(
                $layoutVariant->builder_data,
                $layoutVariant->html,
                $layoutCss,
            ),
        ])->save();

        $approved = $this->createPlainInformationCardJob($admin);
        $approved->forceFill([
            'status' => MarketingCreativeStatus::Approved,
            'approved_by' => $admin->id,
            'approved_at' => now(),
            'approval_dependency_hash' => str_repeat('b', 64),
        ])->save();

        $incomplete = $this->createPlainInformationCardJob($admin);
        $incomplete->variants()
            ->where('format', MarketingCreativeFormat::Web->value)
            ->firstOrFail()
            ->delete();

        $deleted = $this->createPlainInformationCardJob($admin);
        $deleted->delete();

        $invalidHash = $this->createPlainInformationCardJob($admin);
        $invalidHash->variants()
            ->where('format', MarketingCreativeFormat::Post->value)
            ->firstOrFail()
            ->forceFill(['content_hash' => str_repeat('f', 64)])
            ->save();

        $protectedIds = [
            $contentEdited->id,
            $layoutEdited->id,
            $approved->id,
            $incomplete->id,
            $deleted->id,
            $invalidHash->id,
        ];
        $state = $this->catalogStateForIds($protectedIds);

        $this->informationCardMigration()->up();

        $this->assertSame($state, $this->catalogStateForIds($protectedIds));
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

    private function completeJobContentMigration(): Migration
    {
        return require database_path('migrations/2026_08_14_000300_restore_complete_premium_job_content.php');
    }

    private function informationCardMigration(): Migration
    {
        return require database_path('migrations/2026_08_14_000400_upgrade_premium_job_information_cards.php');
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

        $companyContent = array_intersect_key($creative->shared_content, array_flip([
            'contact_phone',
            'contact_email',
            'website',
            'company_name',
            'company_address',
        ]));
        $creative->forceFill([
            'title' => 'Wagenmeister (m/w/d) – Gemeinsam Sicherheit bewegen',
            'shared_content' => $companyContent + [
                'template_key' => MarketingTemplateFactory::PREMIUM_JOB_WAGENMEISTER,
                'seed_version' => MarketingTemplateFactory::PREMIUM_SEED_VERSION,
                'kicker' => 'Karriere / Wagenmeister',
                'title' => 'Wagenmeister (m/w/d)',
                'subtitle' => 'Gemeinsam bringen wir Sicherheit auf die Schiene.',
                'intro' => 'Du prüfst Güterwagen, dokumentierst präzise und triffst Entscheidungen für einen sicheren Bahnbetrieb. Bei RailTime zählen Technik, Verantwortung und Team.',
                'facts' => [
                    ['value' => '60+', 'label' => 'Wagenmeister bundesweit'],
                    ['value' => 'DE', 'label' => 'bundesweit im Einsatz'],
                    ['value' => '24/7', 'label' => 'Notfalldienst'],
                ],
                'tasks' => [
                    'Güterwagen und Züge technisch untersuchen',
                    'Bremsproben und Dokumentation durchführen',
                    'Befunde sicher bewerten und kommunizieren',
                ],
                'profile' => [
                    'Technisches Verständnis',
                    'Verantwortungsbewusstsein',
                    'Teamgeist und klare Kommunikation',
                ],
                'benefits' => [
                    'Technik',
                    'Verantwortung',
                    'Team',
                ],
                'cta_label' => 'Jetzt bewerben',
                'cta_url' => 'https://www.rail-time.de/de/karriere',
                'editorial_note' => 'Qualifikation, Einsatzmodell und Leistungen sind vor Veröffentlichung mit der aktuellen Stellenausschreibung abzugleichen.',
            ],
        ])->save();

        foreach ($creative->variants as $variant) {
            $format = $variant->format;
            $template = $definition['variants'][$format->value];
            $templateHtml = $this->shortenedJobHtml(
                $format,
                '/rt-brand/img/wagenmeister-pruefung.jpg',
                'Wagenmeister bei der technischen Prüfung eines Güterwagens',
            );
            $templateCss = $this->oldV4JobCss($format);
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

    private function createPlainInformationCardJob(User $admin): MarketingCreative
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
            $html = $sanitizer->html($binder->bindHtml(
                $this->plainInformationCardHtml($format, $template['html']),
                $creative->shared_content,
            ));
            $css = $sanitizer->css($this->plainInformationCardCss($format, $template['css']));
            $builderData = $binder->syncBuilderData($template['builder_data'], $html);

            $variant->forceFill([
                'builder_data' => $builderData,
                'html' => $html,
                'css' => $css,
                'content_hash' => $studio->contentHash($builderData, $html, $css),
                'version' => 4,
            ])->save();
        }

        return $creative->refresh()->load('variants');
    }

    private function plainInformationCardHtml(MarketingCreativeFormat $format, string $html): string
    {
        $document = new \DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadHTML(
            '<?xml encoding="utf-8" ?><div data-rt-plain-card-root="1">'.$html.'</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET,
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $this->assertTrue($loaded);

        $xpath = new \DOMXPath($document);
        $articles = $xpath->query(
            '//*[contains(concat(" ", normalize-space(@class), " "), " rt-job-details ")]/article',
        );
        $this->assertNotFalse($articles);
        $this->assertSame(3, $articles->length);

        $bindings = ['tasks', 'profile', 'benefits'];
        $labels = $format === MarketingCreativeFormat::Web
            ? ['01 / Aufgaben', '02 / Anforderungen', '03 / Benefits']
            : ['01 / Deine Aufgaben', '02 / Deine Anforderungen', '03 / Deine Benefits'];

        foreach ($articles as $index => $article) {
            $this->assertInstanceOf(\DOMElement::class, $article);
            while ($article->firstChild) {
                $article->removeChild($article->firstChild);
            }
            $article->removeAttribute('class');
            $article->appendChild($document->createElement('span', $labels[$index]));
            $list = $document->createElement('ul');
            $list->setAttribute('data-rt-binding-list', $bindings[$index]);
            $article->appendChild($list);
        }

        $root = $xpath->query('//*[@data-rt-plain-card-root="1"]')?->item(0);
        $this->assertInstanceOf(\DOMElement::class, $root);
        $output = '';
        foreach ($root->childNodes as $child) {
            $output .= $document->saveHTML($child) ?: '';
        }

        return $output;
    }

    private function plainInformationCardCss(MarketingCreativeFormat $format, string $css): string
    {
        $cardsStart = strpos($css, '.rt-job-card{position:relative');
        $specificStart = strpos($css, '.rt-job-premium-'.$format->value);
        $newSpecificNeedle = match ($format) {
            MarketingCreativeFormat::Story => '.rt-job-premium-story .rt-job-panel{top:875px}',
            MarketingCreativeFormat::Post => '.rt-job-premium-post>.rt-job-details{top:493px',
            MarketingCreativeFormat::Web => '.rt-job-premium-web .rt-job-details{width:725px',
        };
        $newSpecificStart = strpos($css, $newSpecificNeedle);

        $this->assertIsInt($cardsStart);
        $this->assertIsInt($specificStart);
        $this->assertIsInt($newSpecificStart);

        return substr($css, 0, $cardsStart)
            .substr($css, $specificStart, $newSpecificStart - $specificStart);
    }

    private function shortenedJobHtml(
        MarketingCreativeFormat $format,
        string $image,
        string $alt,
    ): string {
        $logo = '<div class="rt-brand rt-brand-lockup rt-brand-lockup-reverse" data-rt-brand-lockup="official"><img class="rt-brand-logo" src="/rt-brand/img/logo-horizontal-darkbg.png" alt="RT Rail Time GmbH"></div>';

        return match ($format) {
            MarketingCreativeFormat::Story => <<<HTML
<main class="rt-marketing-canvas rt-premium rt-job-premium rt-job-premium-story">
  <figure class="rt-photo"><img src="{$image}" alt="{$alt}"></figure>
  <header class="rt-mast">{$logo}<span class="rt-code">KARRIERE / 001</span></header>
  <section class="rt-copy"><p class="rt-kicker" data-rt-binding="kicker"></p><h1 data-rt-binding="title"></h1><p class="rt-subtitle" data-rt-binding="subtitle"></p></section>
  <section class="rt-job-panel"><p class="rt-intro" data-rt-binding="intro"></p><ul class="rt-keywords" data-rt-binding-list="benefits"></ul><div class="rt-facts" data-rt-binding-facts="facts"></div></section>
  <footer><a class="rt-cta" data-rt-binding="cta_label" data-rt-binding-href="cta_url"></a><div><strong>Deine nächste Station: RailTime.</strong><span>rail-time.de/de/karriere</span></div></footer>
</main>
HTML,
            MarketingCreativeFormat::Post => <<<HTML
<main class="rt-marketing-canvas rt-premium rt-job-premium rt-job-premium-post">
  <figure class="rt-photo"><img src="{$image}" alt="{$alt}"></figure>
  <header class="rt-mast">{$logo}<span class="rt-code">JOB / 001</span></header>
  <section class="rt-copy"><p class="rt-kicker" data-rt-binding="kicker"></p><h1 data-rt-binding="title"></h1><p class="rt-subtitle" data-rt-binding="subtitle"></p><ul class="rt-keywords" data-rt-binding-list="benefits"></ul><a class="rt-cta" data-rt-binding="cta_label" data-rt-binding-href="cta_url"></a></section>
  <footer><div class="rt-facts" data-rt-binding-facts="facts"></div><strong data-rt-binding="website"></strong></footer>
</main>
HTML,
            MarketingCreativeFormat::Web => <<<HTML
<main class="rt-marketing-canvas rt-premium rt-job-premium rt-job-premium-web">
  <section class="rt-copy">{$logo}<p class="rt-kicker" data-rt-binding="kicker"></p><h1 data-rt-binding="title"></h1><p class="rt-subtitle" data-rt-binding="subtitle"></p><p class="rt-intro" data-rt-binding="intro"></p><div class="rt-actions"><a class="rt-cta" data-rt-binding="cta_label" data-rt-binding-href="cta_url"></a><strong data-rt-binding="website"></strong></div></section>
  <figure class="rt-photo"><img src="{$image}" alt="{$alt}"><figcaption>TECHNIK / VERANTWORTUNG / TEAM</figcaption></figure>
  <footer><div class="rt-facts" data-rt-binding-facts="facts"></div></footer>
</main>
HTML,
        };
    }

    private function oldV4JobCss(MarketingCreativeFormat $format): string
    {
        $dimensions = $format->dimensions();
        $base = <<<CSS
@font-face{font-family:Manrope;src:url("/rt-brand/fonts/manrope-latin.woff2") format("woff2");font-style:normal;font-weight:400 800;font-display:swap}@font-face{font-family:"Space Mono";src:url("/rt-brand/fonts/space-mono-700-latin.woff2") format("woff2");font-style:normal;font-weight:700;font-display:swap}*{box-sizing:border-box}.rt-marketing-canvas{position:relative;overflow:hidden;width:{$dimensions['width']}px;height:{$dimensions['height']}px;margin:0;background:#f4f2ed;color:#10151b;font-family:Manrope,Arial,sans-serif}.rt-premium:before{position:absolute;z-index:0;inset:0;background-image:linear-gradient(rgba(16,21,27,.045) 1px,transparent 1px),linear-gradient(90deg,rgba(16,21,27,.045) 1px,transparent 1px);background-size:54px 54px;content:"";pointer-events:none}.rt-premium h1,.rt-premium p,.rt-premium figure{margin:0}.rt-premium .rt-mast,.rt-premium .rt-copy,.rt-premium footer{position:absolute;z-index:3}.rt-brand-lockup{position:relative;width:240px}.rt-brand-logo{display:block;width:100%;height:auto}.rt-code,.rt-kicker,.rt-premium figcaption{font-family:"Space Mono",monospace;font-weight:700;letter-spacing:.14em;text-transform:uppercase}.rt-code{color:#7d8791;font-size:13px}.rt-kicker{color:#e4002b;font-size:15px}.rt-premium h1{font-weight:700;letter-spacing:-.063em}.rt-subtitle{font-weight:600;letter-spacing:-.025em}.rt-intro{color:#57636e;line-height:1.55}.rt-photo,.rt-map{position:absolute;z-index:1;overflow:hidden}.rt-photo img,.rt-map img{display:block;width:100%;height:100%;object-fit:cover}.rt-premium figcaption{position:absolute;z-index:2;color:#fff;font-size:11px}.rt-cta{display:inline-flex;min-height:56px;padding:0 25px;align-items:center;justify-content:center;background:#e4002b;color:#fff;font-size:14px;font-weight:800;letter-spacing:.035em;text-decoration:none;text-transform:uppercase}.rt-cta:after{margin-left:22px;content:"→"}.rt-facts{display:grid}.rt-facts>div{display:grid}.rt-facts strong{font-family:"Space Mono",monospace;font-weight:700;letter-spacing:-.06em}.rt-facts span{font-weight:650;line-height:1.25}.rt-keywords{display:flex;margin:0;padding:0;list-style:none}.rt-keywords li{font-family:"Space Mono",monospace;font-weight:700;text-transform:uppercase}.rt-keywords li+li:before{margin:0 13px;color:#e4002b;content:"/"}.rt-actions{display:flex;align-items:center;gap:22px}
.rt-premium .rt-intro{overflow-wrap:anywhere}
CSS;

        return $base.$this->oldV4JobSpecificCss($format);
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
