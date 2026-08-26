<?php

namespace Tests\Feature;

use App\Enums\MarketingCreativeFormat;
use App\Enums\MarketingCreativeStatus;
use App\Enums\MarketingCreativeType;
use App\Models\MarketingCreative;
use App\Models\MarketingCreativeVariant;
use App\Models\User;
use App\Services\Marketing\MarketingContentBinder;
use App\Services\Marketing\MarketingHtmlSanitizer;
use App\Services\Marketing\MarketingStudioService;
use App\Services\Marketing\MarketingTemplateFactory;
use App\Support\MarketingBrandAssets;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\MarketingStudioSeeder;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class MarketingCareerCatalogTest extends TestCase
{
    use DatabaseMigrations;

    /** @var list<string> */
    private const TEMPLATE_KEYS = [
        MarketingTemplateFactory::CAREER_JOB_WAGENMEISTER,
        MarketingTemplateFactory::CAREER_JOB_TRIEBFAHRZEUGFUEHRER,
        MarketingTemplateFactory::CAREER_JOB_ARBEITSZUGFUEHRER,
    ];

    /** @var list<string> */
    private const ALL_STARTER_KEYS = [
        MarketingTemplateFactory::PREMIUM_COMPANY_PROFILE,
        MarketingTemplateFactory::PREMIUM_JOB_WAGENMEISTER,
        MarketingTemplateFactory::PREMIUM_GERMANY_NETWORK,
        MarketingTemplateFactory::CAREER_JOB_WAGENMEISTER,
        MarketingTemplateFactory::CAREER_JOB_TRIEBFAHRZEUGFUEHRER,
        MarketingTemplateFactory::CAREER_JOB_ARBEITSZUGFUEHRER,
    ];

    public function test_career_definitions_are_editable_safe_and_complete_for_every_format(): void
    {
        $templates = app(MarketingTemplateFactory::class);
        $binder = app(MarketingContentBinder::class);
        $sanitizer = app(MarketingHtmlSanitizer::class);

        foreach (self::TEMPLATE_KEYS as $templateKey) {
            $this->assertTrue($templates->hasTemplateKey($templateKey));
            $this->assertSame(MarketingCreativeType::Job, $templates->typeForKey($templateKey));

            $definition = $templates->definitionByKey($templateKey);
            $this->assertSame($templateKey, data_get($definition, 'shared_content.template_key'));
            $this->assertSame(MarketingTemplateFactory::CAREER_SEED_VERSION, data_get($definition, 'shared_content.seed_version'));
            $this->assertSame(MarketingCreativeFormat::Story->value, data_get($definition, 'shared_content.preferred_preview_format'));
            $this->assertSame('Gemeinsam bringen wir Sicherheit auf die Schiene.', data_get($definition, 'shared_content.title'));
            $this->assertCount(3, data_get($definition, 'shared_content.tasks'));
            $this->assertCount(4, data_get($definition, 'shared_content.profile'));
            $this->assertCount(8, data_get($definition, 'shared_content.benefits'));

            foreach (MarketingCreativeFormat::cases() as $format) {
                $variant = $definition['variants'][$format->value];
                $this->assertSame($templateKey, data_get($variant, 'builder_data.railtime.template'));
                $this->assertSame($format->value, data_get($variant, 'builder_data.railtime.format'));
                $this->assertSame(MarketingTemplateFactory::CAREER_SEED_VERSION, data_get($variant, 'builder_data.railtime.schema'));

                $boundHtml = $binder->bindHtml($variant['html'], $definition['shared_content']);
                $safeHtml = $sanitizer->html($boundHtml);
                $safeCss = $sanitizer->css($variant['css']);

                $this->assertNotSame('', $safeHtml);
                $this->assertNotSame('', $safeCss);
                $this->assertDoesNotMatchRegularExpression('/<(?:script|style|svg)\b|\sstyle=/i', $safeHtml);
                $this->assertStringContainsString('/rt-brand/img/wagenmeister-team-gleis.jpeg', $safeHtml);
                $this->assertSame(1, $this->officialBrandLockupCount($safeHtml));
                $this->assertEveryImageUsesAnAbsoluteBrandPath($safeHtml);
                $this->assertSame(8, $this->benefitCount($safeHtml));

                if ($format !== MarketingCreativeFormat::Story) {
                    $this->assertStringContainsString('grid-template-columns:repeat(4,minmax(0,1fr))', $safeCss);
                }
            }

            $story = $definition['variants'][MarketingCreativeFormat::Story->value];
            $boundStory = $binder->bindHtml($story['html'], $definition['shared_content']);
            $this->assertStringContainsString('data-rt-binding-list="tasks"', $boundStory);
            $this->assertStringContainsString('data-rt-binding-list="profile"', $boundStory);
            $this->assertStringContainsString('data-rt-binding-list="benefits"', $boundStory);
            $this->assertSame(8, $this->benefitCount($boundStory));
            $this->assertSame(1, substr_count($boundStory, 'src="/rt-brand/illustrations/v2/benefit-track-u.svg"'));
            $this->assertSame(1, substr_count($boundStory, '/rt-brand/illustrations/v2/role-'));
            $this->assertSame(8, substr_count($story['css'], '/rt-brand/illustrations/v2/benefit-'));
            $this->assertStringContainsString('RT / BENEFIT-GLEIS 08', $boundStory);
            $this->assertStringContainsString('.rt-benefit-list li:nth-child(5){grid-column:4}', $story['css']);
            $this->assertStringContainsString('.rt-benefit-list li:nth-child(8){grid-column:1}', $story['css']);
            $this->assertStringContainsString('linear-gradient(90deg,rgba(7,12,18,.94)', $story['css']);
        }
    }

    public function test_v2_u_track_is_a_pinned_safe_first_party_vector_asset(): void
    {
        $publicPath = '/rt-brand/illustrations/v2/benefit-track-u.svg';
        $path = public_path(ltrim($publicPath, '/'));

        $this->assertTrue(MarketingBrandAssets::allows($publicPath));
        $this->assertFileExists($path);
        $this->assertSame(
            'F1B6806D13CE1F40566D8F5D540F12DFE86E58B59491DABEDAF650E3E0AF0451',
            strtoupper(hash_file('sha256', $path)),
        );

        $contents = file_get_contents($path);
        $this->assertIsString($contents);
        $document = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadXML($contents, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $this->assertTrue($loaded);
        $this->assertSame('svg', $document->documentElement?->localName);
        $this->assertSame('0 0 892 286', $document->documentElement?->getAttribute('viewBox'));
        $xpath = new DOMXPath($document);
        $this->assertSame(7, $xpath->query('//*[local-name()="path" and contains(@d, "M74 31H754")]')?->length);
        $this->assertSame(3, $xpath->query('//*[local-name()="path" and contains(@d, "v48")]')?->length);
        $this->assertSame(0, $xpath->query('//*[local-name()="script" or local-name()="style" or local-name()="animation" or local-name()="foreignObject" or local-name()="image" or local-name()="use"]')?->length);
        $this->assertSame(0, $xpath->query('//@*[starts-with(translate(local-name(), "ON", "on"), "on") or local-name()="href"]')?->length);
    }

    public function test_v2_illustration_release_pins_every_target_definition_and_asset(): void
    {
        $migration = $this->illustrationMigration();
        $reflection = new \ReflectionClass($migration);
        $definitionHashes = $reflection->getReflectionConstant('TARGET_DEFINITION_SHA256')?->getValue();
        $assetHashes = $reflection->getReflectionConstant('TARGET_ASSET_SHA256')?->getValue();
        $definitionHash = $reflection->getMethod('definitionHash');
        $templates = app(MarketingTemplateFactory::class);

        $this->assertIsArray($definitionHashes);
        $this->assertSame(self::ALL_STARTER_KEYS, array_keys($definitionHashes));
        foreach (self::ALL_STARTER_KEYS as $templateKey) {
            $this->assertSame(
                $definitionHashes[$templateKey],
                $definitionHash->invoke($migration, $templates->definitionByKey($templateKey)),
                $templateKey,
            );
        }

        $this->assertIsArray($assetHashes);
        $this->assertCount(15, $assetHashes);
        foreach ($assetHashes as $publicPath => $expectedHash) {
            $path = public_path(ltrim($publicPath, '/'));
            $this->assertTrue(MarketingBrandAssets::allows($publicPath), $publicPath);
            $this->assertFileExists($path);
            $this->assertSame($expectedHash, hash_file('sha256', $path), $publicPath);

            $contents = file_get_contents($path);
            $this->assertIsString($contents);
            $document = new DOMDocument('1.0', 'UTF-8');
            $previous = libxml_use_internal_errors(true);
            $loaded = $document->loadXML($contents, LIBXML_NONET);
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
            $this->assertTrue($loaded, $publicPath);
            $xpath = new DOMXPath($document);
            $this->assertSame(
                0,
                $xpath->query('//*[local-name()="script" or local-name()="animation" or local-name()="foreignObject" or local-name()="image" or local-name()="use"]')?->length,
                $publicPath,
            );
            $this->assertSame(
                0,
                $xpath->query('//@*[starts-with(translate(local-name(), "ON", "on"), "on") or local-name()="href"]')?->length,
                $publicPath,
            );
        }
    }

    public function test_v2_illustration_migration_upgrades_all_six_exact_drafts_and_is_idempotent(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $creatives = collect(self::ALL_STARTER_KEYS)->mapWithKeys(function (string $templateKey) use ($admin): array {
            $version = match ($templateKey) {
                MarketingTemplateFactory::PREMIUM_COMPANY_PROFILE => 4,
                MarketingTemplateFactory::PREMIUM_JOB_WAGENMEISTER => 7,
                MarketingTemplateFactory::PREMIUM_GERMANY_NETWORK => 3,
                MarketingTemplateFactory::CAREER_JOB_WAGENMEISTER => 2,
                default => 1,
            };

            return [$templateKey => $this->createPreIllustrationStarter($templateKey, $admin, $version)];
        });

        $this->illustrationMigration()->up();

        foreach ($creatives as $templateKey => $creative) {
            $fresh = $creative->fresh('variants');
            $this->assertNotNull($fresh);
            $this->assertSame($creative->id, $fresh->id);
            $this->assertSame('+49 4171 900001', data_get($fresh->shared_content, 'contact_phone'));
            $this->assertSame('marketing@example.test', data_get($fresh->shared_content, 'contact_email'));
            $this->assertSame('illustrationen.example.test', data_get($fresh->shared_content, 'website'));
            $this->assertSame('RailTime Illustrationen GmbH', data_get($fresh->shared_content, 'company_name'));
            $this->assertSame('Testgleis 2, 21423 Winsen', data_get($fresh->shared_content, 'company_address'));
            $this->assertSame(MarketingCreativeStatus::Draft, $fresh->status);
            $this->assertCount(3, $fresh->variants);
            $this->assertTrue($fresh->variants->every(
                fn (MarketingCreativeVariant $variant): bool => str_contains(
                    $variant->html."\n".$variant->css,
                    '/rt-brand/illustrations/v2/',
                ),
            ), $templateKey);

            foreach ($fresh->variants as $variant) {
                $this->assertSame(
                    app(MarketingStudioService::class)->contentHash(
                        $variant->builder_data,
                        $variant->html,
                        $variant->css,
                    ),
                    $variant->content_hash,
                );
            }
        }

        $state = $this->illustrationCatalogState($creatives->pluck('id')->all());
        $this->illustrationMigration()->up();
        $this->assertSame($state, $this->illustrationCatalogState($creatives->pluck('id')->all()));
    }

    public function test_v2_illustration_migration_supports_the_original_pre_track_v5_career_release(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $creatives = collect(self::TEMPLATE_KEYS)->map(
            fn (string $templateKey): MarketingCreative => $this->createPreIllustrationStarter(
                $templateKey,
                $admin,
                1,
                true,
            ),
        );

        $this->illustrationMigration()->up();

        foreach ($creatives as $creative) {
            $fresh = $creative->fresh('variants');
            $this->assertNotNull($fresh);
            $this->assertTrue($fresh->variants->every(
                fn (MarketingCreativeVariant $variant): bool => $variant->version === 2,
            ));
            $this->assertStringContainsString(
                '/rt-brand/illustrations/v2/benefit-track-u.svg',
                $fresh->variants->firstWhere('format', MarketingCreativeFormat::Story)?->html ?? '',
            );
        }
    }

    public function test_v2_illustration_migration_preserves_every_protected_source_state(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $custom = $this->createPreIllustrationStarter(MarketingTemplateFactory::PREMIUM_COMPANY_PROFILE, $admin);
        $customContent = $custom->shared_content;
        $customContent['intro'] = 'Individuelle Kampagne';
        $custom->forceFill(['shared_content' => $customContent])->save();

        $approved = $this->createPreIllustrationStarter(MarketingTemplateFactory::PREMIUM_JOB_WAGENMEISTER, $admin);
        $approved->forceFill([
            'status' => MarketingCreativeStatus::Approved,
            'approved_by' => $admin->id,
            'approved_at' => now(),
            'approval_dependency_hash' => str_repeat('a', 64),
        ])->save();

        $imported = $this->createPreIllustrationStarter(MarketingTemplateFactory::PREMIUM_GERMANY_NETWORK, $admin);
        $importedContent = $imported->shared_content;
        $importedContent['import_source_template_key'] = data_get($importedContent, 'template_key');
        $imported->forceFill(['shared_content' => $importedContent])->save();

        $corrupt = $this->createPreIllustrationStarter(MarketingTemplateFactory::CAREER_JOB_WAGENMEISTER, $admin);
        $corruptVariant = $corrupt->variants()->where('format', MarketingCreativeFormat::Story)->firstOrFail();
        $corruptVariant->forceFill(['content_hash' => str_repeat('0', 64)])->save();

        $incomplete = $this->createPreIllustrationStarter(MarketingTemplateFactory::CAREER_JOB_TRIEBFAHRZEUGFUEHRER, $admin);
        $incomplete->variants()->where('format', MarketingCreativeFormat::Web)->firstOrFail()->delete();

        $deleted = $this->createPreIllustrationStarter(MarketingTemplateFactory::CAREER_JOB_ARBEITSZUGFUEHRER, $admin);
        $deleted->delete();

        $ids = collect([$custom, $approved, $imported, $corrupt, $incomplete, $deleted])->pluck('id')->all();
        $state = $this->illustrationCatalogState($ids);

        $this->illustrationMigration()->up();

        $this->assertSame($state, $this->illustrationCatalogState($ids));
        $this->assertSoftDeleted('marketing_creatives', ['id' => $deleted->id]);
    }

    public function test_track_upgrade_target_pin_ignores_company_defaults_but_detects_editorial_drift(): void
    {
        $migration = $this->trackUpgradeMigration();
        $method = (new \ReflectionClass($migration))->getMethod('definitionHash');
        $definition = app(MarketingTemplateFactory::class)
            ->definitionByKey(MarketingTemplateFactory::CAREER_JOB_WAGENMEISTER);
        $baseline = $method->invoke($migration, $definition);

        foreach (['contact_phone', 'contact_email', 'website', 'company_name', 'company_address'] as $field) {
            $definition['shared_content'][$field] = 'server-specific-'.$field;
        }

        $this->assertSame($baseline, $method->invoke($migration, $definition));

        $definition['shared_content']['benefits'][0] = 'Ungeprüfte spätere Änderung';
        $this->assertNotSame($baseline, $method->invoke($migration, $definition));
    }

    public function test_historical_track_upgrade_fails_closed_after_its_pinned_target_advances(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $creatives = collect(self::TEMPLATE_KEYS)
            ->mapWithKeys(fn (string $key): array => [$key => $this->createPreIllustrationStarter($key, $admin, 1, true)]);

        $this->trackUpgradeMigration()->up();

        foreach ($creatives as $templateKey => $creative) {
            $fresh = $creative->fresh('variants');
            $this->assertNotNull($fresh);
            $this->assertSame($creative->id, $fresh->id);
            $this->assertSame('+49 4171 900001', data_get($fresh->shared_content, 'contact_phone'));
            $this->assertSame(MarketingCreativeStatus::Draft, $fresh->status);
            $this->assertSame(3, $fresh->variants->count());
            $this->assertTrue($fresh->variants->every(
                fn (MarketingCreativeVariant $variant): bool => $variant->version === 1,
            ));

            $story = $fresh->variants->firstWhere('format', MarketingCreativeFormat::Story);
            $this->assertNotNull($story, $templateKey);
            $this->assertStringNotContainsString('/rt-brand/illustrations/v2/', $story->html);
        }

        $versions = MarketingCreativeVariant::query()
            ->whereIn('marketing_creative_id', $creatives->pluck('id'))
            ->pluck('version', 'id')
            ->all();

        $this->trackUpgradeMigration()->up();

        $this->assertSame(
            $versions,
            MarketingCreativeVariant::query()
                ->whereIn('marketing_creative_id', $creatives->pluck('id'))
                ->pluck('version', 'id')
                ->all(),
        );
    }

    public function test_v2_illustration_upgrade_preserves_custom_approved_and_deleted_pre_track_v5_motives(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $custom = $this->createPreIllustrationStarter(self::TEMPLATE_KEYS[0], $admin, 1, true);
        $customStory = $custom->variants()->where('format', MarketingCreativeFormat::Story)->firstOrFail();
        $customCss = $customStory->css.' .customer-layout-marker{display:block}';
        $customStory->forceFill([
            'css' => $customCss,
            'content_hash' => app(MarketingStudioService::class)->contentHash(
                $customStory->builder_data,
                $customStory->html,
                $customCss,
            ),
        ])->save();

        $approved = $this->createPreIllustrationStarter(self::TEMPLATE_KEYS[1], $admin, 1, true);
        $approved->forceFill(['status' => MarketingCreativeStatus::Approved])->save();

        $deleted = $this->createPreIllustrationStarter(self::TEMPLATE_KEYS[2], $admin, 1, true);
        $deleted->delete();

        $this->illustrationMigration()->up();

        $this->assertStringContainsString(
            '.customer-layout-marker{display:block}',
            $customStory->fresh()->css,
        );
        $this->assertSame(1, $customStory->fresh()->version);
        $this->assertSame(MarketingCreativeStatus::Approved, $approved->fresh()->status);
        $this->assertTrue($approved->fresh('variants')->variants->every(
            fn (MarketingCreativeVariant $variant): bool => $variant->version === 1,
        ));
        $this->assertSoftDeleted('marketing_creatives', ['id' => $deleted->id]);
        $this->assertTrue($deleted->variants()->withTrashed()->get()->every(
            fn (MarketingCreativeVariant $variant): bool => $variant->version === 1,
        ));
    }

    public function test_v2_illustration_upgrade_skips_invalid_builder_data_without_blocking_other_motives(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $invalid = $this->createPreIllustrationStarter(self::TEMPLATE_KEYS[0], $admin);
        $valid = $this->createPreIllustrationStarter(self::TEMPLATE_KEYS[1], $admin);
        $invalidStory = $invalid->variants()
            ->where('format', MarketingCreativeFormat::Story)
            ->firstOrFail();

        DB::table('marketing_creative_variants')
            ->where('id', $invalidStory->id)
            ->update(['builder_data' => json_encode('invalid', JSON_THROW_ON_ERROR)]);

        $this->illustrationMigration()->up();

        $this->assertTrue($invalid->fresh('variants')->variants->every(
            fn (MarketingCreativeVariant $variant): bool => $variant->version === 1,
        ));
        $this->assertTrue($valid->fresh('variants')->variants->every(
            fn (MarketingCreativeVariant $variant): bool => $variant->version === 2,
        ));
    }

    public function test_install_migration_adds_only_missing_keys_and_never_revives_or_overwrites_records(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $migration = $this->migration();

        $migration->up();

        $creatives = MarketingCreative::query()
            ->whereIn('shared_content->template_key', self::TEMPLATE_KEYS)
            ->with('variants')
            ->get();
        $this->assertCount(3, $creatives);
        $this->assertSame(9, $creatives->sum(fn (MarketingCreative $creative): int => $creative->variants->count()));
        $this->assertTrue($creatives->every(fn (MarketingCreative $creative): bool => $creative->created_by === $admin->id));

        $custom = $creatives->firstWhere('shared_content.template_key', MarketingTemplateFactory::CAREER_JOB_WAGENMEISTER);
        $this->assertNotNull($custom);
        $custom->forceFill(['title' => 'Individuell gepflegtes Karrieremotiv'])->save();

        $deleted = $creatives->firstWhere('shared_content.template_key', MarketingTemplateFactory::CAREER_JOB_TRIEBFAHRZEUGFUEHRER);
        $this->assertNotNull($deleted);
        $deleted->delete();

        $migration->up();

        $this->assertSame('Individuell gepflegtes Karrieremotiv', $custom->fresh()->title);
        $this->assertSame(3, MarketingCreative::withTrashed()
            ->whereIn('shared_content->template_key', self::TEMPLATE_KEYS)
            ->count());
        $this->assertSoftDeleted('marketing_creatives', ['id' => $deleted->id]);
    }

    public function test_seeder_installs_the_catalog_after_a_fresh_migration_without_an_admin(): void
    {
        $this->migration()->up();
        $this->assertSame(0, MarketingCreative::withTrashed()
            ->whereIn('shared_content->template_key', self::TEMPLATE_KEYS)
            ->count());

        User::factory()->create(['role' => 'admin']);
        $this->seed(MarketingStudioSeeder::class);

        $this->assertSame(3, MarketingCreative::query()
            ->whereIn('shared_content->template_key', self::TEMPLATE_KEYS)
            ->count());
    }

    public function test_default_database_seeder_installs_the_career_catalog_after_creating_the_admin(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(3, MarketingCreative::query()
            ->whereIn('shared_content->template_key', self::TEMPLATE_KEYS)
            ->count());
        $this->assertSame(6, MarketingCreative::query()->count());
    }

    private function createPreIllustrationStarter(
        string $templateKey,
        User $admin,
        int $version = 1,
        bool $preTrackCareer = false,
    ): MarketingCreative {
        $studio = app(MarketingStudioService::class);
        $creative = $studio->createFromTemplate(
            app(MarketingTemplateFactory::class)->typeForKey($templateKey),
            $admin,
            $templateKey,
        );

        $sharedContent = $creative->shared_content;
        $sharedContent['contact_phone'] = '+49 4171 900001';
        $sharedContent['contact_email'] = 'marketing@example.test';
        $sharedContent['website'] = 'illustrationen.example.test';
        $sharedContent['company_name'] = 'RailTime Illustrationen GmbH';
        $sharedContent['company_address'] = 'Testgleis 2, 21423 Winsen';
        $creative->forceFill(['shared_content' => $sharedContent])->save();

        foreach ($creative->variants as $variant) {
            [$html, $css] = $this->preIllustrationDocument(
                $templateKey,
                $variant->format,
                $variant->html,
                $variant->css,
            );

            if ($preTrackCareer && $variant->format === MarketingCreativeFormat::Story) {
                [$html, $css] = $this->preTrackCareerDocument($html, $css);
            }

            $builderData = $variant->builder_data;
            $builderData['pages'][0]['component'] = $html;
            $variant->forceFill([
                'builder_data' => $builderData,
                'html' => $html,
                'css' => $css,
                'content_hash' => $studio->contentHash($builderData, $html, $css),
                'version' => $version,
            ])->save();
        }

        return $creative->fresh('variants');
    }

    /** @return array{string,string} */
    private function preIllustrationDocument(
        string $templateKey,
        MarketingCreativeFormat $format,
        string $html,
        string $css,
    ): array {
        if (in_array($templateKey, [
            MarketingTemplateFactory::PREMIUM_COMPANY_PROFILE,
            MarketingTemplateFactory::PREMIUM_JOB_WAGENMEISTER,
            MarketingTemplateFactory::PREMIUM_GERMANY_NETWORK,
        ], true)) {
            $html = $this->removeV2IllustrationNodes($html);
            $css = $this->removePremiumV2IllustrationCss($templateKey, $format, $css);

            if ($templateKey === MarketingTemplateFactory::PREMIUM_COMPANY_PROFILE) {
                $captions = [
                    MarketingCreativeFormat::Story->value => 'Menschen. Technik. Verantwortung.',
                    MarketingCreativeFormat::Post->value => '60+ Menschen für sicheren Bahnbetrieb.',
                    MarketingCreativeFormat::Web->value => 'COMPANY / PEOPLE / RAIL',
                ];
                $html = str_replace(
                    '<figure class="rt-photo"><img src="/rt-brand/img/wagenmeister-team.webp" alt="RailTime-Wagenmeister stimmen einen Einsatz im Team ab"></figure>',
                    '<figure class="rt-photo"><img src="/rt-brand/img/wagenmeister-team.webp" alt="RailTime-Wagenmeister stimmen einen Einsatz im Team ab"><figcaption>'.$captions[$format->value].'</figcaption></figure>',
                    $html,
                );
            }

            if ($templateKey === MarketingTemplateFactory::PREMIUM_GERMANY_NETWORK) {
                $caption = match ($format) {
                    MarketingCreativeFormat::Story => '<figcaption>Hauptsitz / Standorte / Einsatzorte</figcaption>',
                    MarketingCreativeFormat::Web => '<figcaption>RAILTIME / NETWORK / GERMANY</figcaption>',
                    MarketingCreativeFormat::Post => '',
                };
                $html = str_replace(
                    '<figure class="rt-map"><img src="/rt-brand/illustrations/v2/germany-rail-network.svg" alt="Deutschlandkarte mit RailTime-Einsatzstandorten"></figure>',
                    '<figure class="rt-map"><img src="/rt-brand/img/deutschland-netzwerk.png" alt="Deutschlandkarte mit RailTime-Einsatzstandorten">'.$caption.'</figure>',
                    $html,
                );
            }

            if (in_array($templateKey, [
                MarketingTemplateFactory::PREMIUM_COMPANY_PROFILE,
                MarketingTemplateFactory::PREMIUM_GERMANY_NETWORK,
            ], true) && $format === MarketingCreativeFormat::Story) {
                $html = str_replace(
                    [
                        'rt-brand-lockup-reverse',
                        '/rt-brand/img/logo-horizontal-darkbg.png',
                    ],
                    [
                        'rt-brand-lockup-standard',
                        '/rt-brand/img/logo-horizontal.png',
                    ],
                    $html,
                );
            }

            return [$html, $css];
        }

        $html = $this->removeV2IllustrationNodes($html);
        $html = preg_replace(
            '/ rt-career-compact rt-career-role-[a-z-]+/',
            '',
            $html,
            1,
        ) ?? $html;
        $html = preg_replace(
            '/ rt-career-role-[a-z-]+/',
            '',
            $html,
            1,
        ) ?? $html;

        if ($format === MarketingCreativeFormat::Story) {
            $html = str_replace(
                '/rt-brand/illustrations/v2/benefit-track-u.svg',
                '/rt-brand/icons/benefit-track-u.svg',
                $html,
            );
            $marker = '.rt-role-illustration{';
            $position = strpos($css, $marker);
            $this->assertIsInt($position);
            $css = substr($css, 0, $position)
                .'.rt-benefit-list li:nth-child(1):after,.rt-benefit-list li:nth-child(7):after{background-image:url("/rt-brand/icons/job-profile.svg")}'
                ."\n"
                .'.rt-benefit-list li:nth-child(4):after,.rt-benefit-list li:nth-child(8):after{background-image:url("/rt-brand/icons/job-tasks.svg")}';

            return [$html, $css];
        }

        $css = $this->removePremiumV2IllustrationCss(
            MarketingTemplateFactory::PREMIUM_JOB_WAGENMEISTER,
            $format,
            $css,
        ).$this->oldCareerCompactCss($format);

        return [$html, $css];
    }

    private function removeV2IllustrationNodes(string $html): string
    {
        $html = preg_replace(
            '/^  <div class="rt-(?:role|freight)-illustration"[^\n]*\n/m',
            '',
            $html,
        ) ?? $html;

        return str_replace(
            '<img class="rt-benefit-track-compact" src="/rt-brand/illustrations/v2/benefit-track-compact.svg" alt="" aria-hidden="true">',
            '',
            $html,
        );
    }

    private function removePremiumV2IllustrationCss(
        string $templateKey,
        MarketingCreativeFormat $format,
        string $css,
    ): string {
        $baseStart = '.rt-premium .rt-intro{overflow-wrap:anywhere}.rt-freight-illustration,';
        $baseEnd = '.rt-benefit-track-compact{display:none}';
        $start = strpos($css, $baseStart);
        $end = $start === false ? false : strpos($css, $baseEnd, $start);
        $this->assertIsInt($start);
        $this->assertIsInt($end);
        $css = substr($css, 0, $start)
            .'.rt-premium .rt-intro{overflow-wrap:anywhere}'
            .substr($css, $end + strlen($baseEnd));

        $marker = match ([$templateKey, $format]) {
            [MarketingTemplateFactory::PREMIUM_COMPANY_PROFILE, MarketingCreativeFormat::Story] => '.rt-company-story{background:#080d13;color:#fff}',
            [MarketingTemplateFactory::PREMIUM_COMPANY_PROFILE, MarketingCreativeFormat::Post] => '.rt-company-post footer{border-top:3px solid #e4002b',
            [MarketingTemplateFactory::PREMIUM_COMPANY_PROFILE, MarketingCreativeFormat::Web] => '.rt-company-web .rt-photo{border-left:1px solid rgba(255,255,255,.1)}',
            [MarketingTemplateFactory::PREMIUM_JOB_WAGENMEISTER, MarketingCreativeFormat::Story] => '.rt-job-premium-story .rt-role-illustration{z-index:2',
            [MarketingTemplateFactory::PREMIUM_JOB_WAGENMEISTER, MarketingCreativeFormat::Post] => '.rt-job-premium-post .rt-role-illustration{z-index:2',
            [MarketingTemplateFactory::PREMIUM_JOB_WAGENMEISTER, MarketingCreativeFormat::Web] => '.rt-job-premium-web .rt-role-illustration{z-index:3',
            [MarketingTemplateFactory::PREMIUM_GERMANY_NETWORK, MarketingCreativeFormat::Story] => '.rt-network-premium-story{background:#080d13;color:#fff}',
            [MarketingTemplateFactory::PREMIUM_GERMANY_NETWORK, MarketingCreativeFormat::Post] => '.rt-network-premium-post{background:#080d13;color:#fff}',
            [MarketingTemplateFactory::PREMIUM_GERMANY_NETWORK, MarketingCreativeFormat::Web] => '.rt-network-premium-web{grid-template-columns:57% 43%}',
        };
        $position = strrpos($css, $marker);
        $this->assertIsInt($position);

        return substr($css, 0, $position);
    }

    private function oldCareerCompactCss(MarketingCreativeFormat $format): string
    {
        return match ($format) {
            MarketingCreativeFormat::Post => <<<'CSS'
.rt-job-premium-post .rt-copy{top:142px}.rt-job-premium-post h1{font-size:56px;line-height:.9}.rt-job-premium-post .rt-subtitle{margin-top:12px;font-size:20px}.rt-job-premium-post .rt-intro{margin-top:11px;font-size:13px;line-height:1.34}.rt-job-premium-post>.rt-job-details{top:444px;bottom:253px;grid-template-columns:repeat(2,minmax(0,1fr));grid-template-rows:184px 189px;gap:10px 12px}.rt-job-premium-post .rt-job-details .rt-job-card{padding:12px 14px}.rt-job-premium-post .rt-job-card__head{gap:10px}.rt-job-premium-post .rt-job-card__icon{width:35px;height:35px}.rt-job-premium-post .rt-job-card__heading strong{font-size:12px}.rt-job-premium-post .rt-job-card__list{margin-top:7px}.rt-job-premium-post .rt-job-card__list li{min-height:27px;padding:4px 0 4px 18px;font-size:10.6px}.rt-job-premium-post .rt-job-card--benefits{display:grid;grid-column:1/-1;grid-template-columns:168px minmax(0,1fr)}.rt-job-premium-post .rt-job-card--benefits .rt-job-card__head{height:100%;padding-right:13px;border-right:1px solid rgba(255,255,255,.1)}.rt-job-premium-post .rt-job-card--benefits .rt-job-card__list{display:grid;margin:0 0 0 13px;grid-template-columns:repeat(4,minmax(0,1fr));grid-template-rows:repeat(2,minmax(0,1fr));gap:0 10px}.rt-job-premium-post .rt-job-card--benefits .rt-job-card__list li{min-height:0;padding:5px 3px 5px 17px;font-size:10.2px;line-height:1.16}
CSS,
            MarketingCreativeFormat::Web => <<<'CSS'
.rt-job-premium-web h1{font-size:38px;line-height:.9}.rt-job-premium-web .rt-subtitle{font-size:14px}.rt-job-premium-web .rt-intro{font-size:10px}.rt-job-premium-web .rt-job-card--benefits{grid-template-columns:132px minmax(0,1fr)}.rt-job-premium-web .rt-job-card--benefits .rt-job-card__list{grid-template-columns:repeat(4,minmax(0,1fr));grid-template-rows:repeat(2,minmax(0,1fr));gap:0 7px}.rt-job-premium-web .rt-job-card--benefits .rt-job-card__list li{min-height:0;padding:3px 1px 3px 14px;font-size:8.2px;line-height:1.12}
CSS,
            MarketingCreativeFormat::Story => '',
        };
    }

    /** @return array{string,string} */
    private function preTrackCareerDocument(string $html, string $css): array
    {
        $html = str_replace(
            '<p>Mehr Sicherheit. Mehr Perspektive. Mehr für dich.</p>',
            '<p>Acht starke Stationen für deinen nächsten Schritt.</p>',
            $html,
        );
        $html = str_replace(
            '<img class="rt-track" src="/rt-brand/icons/benefit-track-u.svg" alt="" aria-hidden="true">',
            '<div class="rt-track" aria-hidden="true"><span class="rt-track-top"></span><span class="rt-track-curve"></span><span class="rt-track-bottom"></span></div>',
            $html,
        );
        $html = str_replace(
            '<div class="rt-benefit-route-copy"><span>RT / BENEFIT-GLEIS 08</span><strong>Acht Stationen.<br>Ein starkes Paket.</strong></div>',
            '',
            $html,
        );

        return [$html, Str::before($css, '.rt-benefit-map{top:1025px')];
    }

    /** @param list<int> $creativeIds
     * @return array<int, array<string, mixed>>
     */
    private function illustrationCatalogState(array $creativeIds): array
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
                'shared_content' => $creative->shared_content,
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
                    'content_hash' => $variant->content_hash,
                    'version' => $variant->version,
                    'deleted_at' => $variant->deleted_at?->toISOString(),
                ])->all(),
            ])
            ->all();
    }

    private function migration(): Migration
    {
        return require database_path('migrations/2026_08_24_000100_install_railtime_career_marketing_catalog.php');
    }

    private function trackUpgradeMigration(): Migration
    {
        return require database_path('migrations/2026_08_25_000100_upgrade_railtime_career_benefit_tracks.php');
    }

    private function illustrationMigration(): Migration
    {
        return require database_path('migrations/2026_08_25_000200_upgrade_railtime_marketing_illustrations_v2.php');
    }

    private function officialBrandLockupCount(string $html): int
    {
        [$document, $xpath] = $this->dom($html);
        unset($document);

        return $xpath->query('//*[@data-rt-brand-lockup="official"]')?->length ?? 0;
    }

    private function benefitCount(string $html): int
    {
        [$document, $xpath] = $this->dom($html);
        unset($document);

        return $xpath->query('//*[@data-rt-binding-list="benefits"]/li')?->length ?? 0;
    }

    private function assertEveryImageUsesAnAbsoluteBrandPath(string $html): void
    {
        [$document, $xpath] = $this->dom($html);
        $images = $xpath->query('//img');
        $this->assertNotFalse($images);

        /** @var DOMElement $image */
        foreach ($images as $image) {
            $this->assertStringStartsWith('/rt-brand/', $image->getAttribute('src'));
        }

        unset($document);
    }

    /** @return array{DOMDocument,DOMXPath} */
    private function dom(string $html): array
    {
        $document = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML(
            '<?xml encoding="utf-8" ?><div>'.$html.'</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET,
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return [$document, new DOMXPath($document)];
    }
}
