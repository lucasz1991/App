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
            $this->assertSame(1, substr_count($boundStory, 'src="/rt-brand/icons/benefit-track-u.svg"'));
            $this->assertStringContainsString('RT / BENEFIT-GLEIS 08', $boundStory);
            $this->assertStringContainsString('.rt-benefit-list li:nth-child(5){grid-column:4}', $story['css']);
            $this->assertStringContainsString('.rt-benefit-list li:nth-child(8){grid-column:1}', $story['css']);
            $this->assertStringContainsString('linear-gradient(90deg,rgba(7,12,18,.94)', $story['css']);
        }
    }

    public function test_u_track_is_a_pinned_safe_first_party_vector_asset(): void
    {
        $publicPath = '/rt-brand/icons/benefit-track-u.svg';
        $path = public_path(ltrim($publicPath, '/'));

        $this->assertTrue(MarketingBrandAssets::allows($publicPath));
        $this->assertFileExists($path);
        $this->assertSame(
            '4F1BFE787C766370099F9B6F7B80C8AC08A8D55F0F729C54064E0A29AD064A57',
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
        $this->assertSame(6, $xpath->query('//*[local-name()="path" and contains(@d, "M78 14H814")]')?->length);
        $this->assertSame(1, $xpath->query('//*[local-name()="path" and contains(@d, "M30 -9V37")]')?->length);
        $this->assertSame(0, $xpath->query('//*[local-name()="script" or local-name()="style" or local-name()="animation" or local-name()="foreignObject" or local-name()="image" or local-name()="use"]')?->length);
        $this->assertSame(0, $xpath->query('//@*[starts-with(translate(local-name(), "ON", "on"), "on") or local-name()="href"]')?->length);
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

    public function test_exact_untouched_v5_catalog_is_upgraded_atomically_and_idempotently(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $creatives = collect(self::TEMPLATE_KEYS)
            ->mapWithKeys(fn (string $key): array => [$key => $this->createLegacyV5Career($key, $admin)]);

        $this->trackUpgradeMigration()->up();

        foreach ($creatives as $templateKey => $creative) {
            $fresh = $creative->fresh('variants');
            $this->assertNotNull($fresh);
            $this->assertSame($creative->id, $fresh->id);
            $this->assertSame('+49 4171 546803', data_get($fresh->shared_content, 'contact_phone'));
            $this->assertSame(MarketingCreativeStatus::Draft, $fresh->status);
            $this->assertSame(3, $fresh->variants->count());
            $this->assertTrue($fresh->variants->every(
                fn (MarketingCreativeVariant $variant): bool => $variant->version === 2,
            ));

            $story = $fresh->variants->firstWhere('format', MarketingCreativeFormat::Story);
            $this->assertNotNull($story, $templateKey);
            $this->assertStringContainsString('/rt-brand/icons/benefit-track-u.svg', $story->html);
            $this->assertStringContainsString('RT / BENEFIT-GLEIS 08', $story->html);
            $this->assertStringContainsString('.rt-benefit-list li:nth-child(5){grid-column:4}', $story->css);
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

    public function test_track_upgrade_preserves_custom_approved_and_deleted_v5_motives(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $custom = $this->createLegacyV5Career(self::TEMPLATE_KEYS[0], $admin);
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

        $approved = $this->createLegacyV5Career(self::TEMPLATE_KEYS[1], $admin);
        $approved->forceFill(['status' => MarketingCreativeStatus::Approved])->save();

        $deleted = $this->createLegacyV5Career(self::TEMPLATE_KEYS[2], $admin);
        $deleted->delete();

        $this->trackUpgradeMigration()->up();

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

    public function test_track_upgrade_skips_invalid_builder_data_without_blocking_other_motives(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $invalid = $this->createLegacyV5Career(self::TEMPLATE_KEYS[0], $admin);
        $valid = $this->createLegacyV5Career(self::TEMPLATE_KEYS[1], $admin);
        $invalidStory = $invalid->variants()
            ->where('format', MarketingCreativeFormat::Story)
            ->firstOrFail();

        DB::table('marketing_creative_variants')
            ->where('id', $invalidStory->id)
            ->update(['builder_data' => json_encode('invalid', JSON_THROW_ON_ERROR)]);

        $this->trackUpgradeMigration()->up();

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

    private function createLegacyV5Career(string $templateKey, User $admin): MarketingCreative
    {
        $studio = app(MarketingStudioService::class);
        $creative = $studio->createFromTemplate(
            MarketingCreativeType::Job,
            $admin,
            $templateKey,
        );

        $sharedContent = $creative->shared_content;
        $sharedContent['contact_phone'] = '+49 4171 546803';
        $creative->forceFill(['shared_content' => $sharedContent])->save();

        foreach ($creative->variants as $variant) {
            $html = $variant->html;
            $css = $variant->css;

            if ($variant->format === MarketingCreativeFormat::Story) {
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
                $css = Str::before($css, '.rt-benefit-map{top:1025px');
            }

            $builderData = $variant->builder_data;
            $builderData['pages'][0]['component'] = $html;

            $variant->forceFill([
                'builder_data' => $builderData,
                'html' => $html,
                'css' => $css,
                'content_hash' => $studio->contentHash($builderData, $html, $css),
                'version' => 1,
            ])->save();
        }

        return $creative->fresh('variants');
    }

    private function migration(): Migration
    {
        return require database_path('migrations/2026_08_24_000100_install_railtime_career_marketing_catalog.php');
    }

    private function trackUpgradeMigration(): Migration
    {
        return require database_path('migrations/2026_08_25_000100_upgrade_railtime_career_benefit_tracks.php');
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
