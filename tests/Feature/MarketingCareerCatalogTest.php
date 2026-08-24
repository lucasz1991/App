<?php

namespace Tests\Feature;

use App\Enums\MarketingCreativeFormat;
use App\Enums\MarketingCreativeType;
use App\Models\MarketingCreative;
use App\Models\User;
use App\Services\Marketing\MarketingContentBinder;
use App\Services\Marketing\MarketingHtmlSanitizer;
use App\Services\Marketing\MarketingTemplateFactory;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\MarketingStudioSeeder;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\DatabaseMigrations;
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
            $this->assertStringContainsString('.rt-track-curve', $story['css']);
            $this->assertStringContainsString('border-radius:0 142px 142px 0', $story['css']);
            $this->assertStringContainsString('linear-gradient(90deg,rgba(7,12,18,.94)', $story['css']);
        }
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

    private function migration(): Migration
    {
        return require database_path('migrations/2026_08_24_000100_install_railtime_career_marketing_catalog.php');
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
