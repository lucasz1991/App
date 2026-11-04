<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\MailDocument;
use App\Models\Setting;
use App\Support\CompanyData;
use App\Support\EmailTemplateBuilder;
use App\Support\Mail\SystemMailCssInliner;
use App\Support\Mail\SystemMailDispatchActions;
use App\Support\Mail\SystemMailInlineImageEmbedder;
use App\Support\OutlookAddin\OutlookLinkedAnimatedDelivery;
use App\Support\OutlookAddin\OutlookStaticLinkedTestDelivery;
use App\Support\OutlookAddin\OutlookStaticLinkedTestProfiles;
use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\HtmlString;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;
use Symfony\Component\Mime\Email;
use Tests\TestCase;
use TijsVerkoyen\CssToInlineStyles\CssToInlineStyles;

final class SystemMailDispatchActionsTest extends TestCase
{
    public function test_real_published_system_default_has_one_numeric_action_row_and_white_number_without_changing_documents(): void
    {
        [$source, $actual, $documents] = $this->fixture('+491601881839');
        $xpath = $this->xpath($actual);
        $table = $xpath->query('//table[@data-rt-system-dispatch-actions="v1"]');
        self::assertSame(1, $table->length);
        self::assertSame('215', $table->item(0)->getAttribute('width'));
        $cells = $xpath->query('./tbody/tr/td', $table->item(0));
        self::assertSame(['75', '32', '108'], array_map(static fn ($n): string => $n->getAttribute('width'), iterator_to_array($cells)));
        self::assertStringContainsString('padding-left:0!important;text-align:left!important;', $cells->item(2)->getAttribute('style'));
        self::assertStringContainsString('margin-left:0;', $xpath->query('//a[@href="https://wa.me/491601881848"]')->item(0)->getAttribute('style'));
        self::assertSame(['24', '8', '24'], $this->slotWidths($xpath, 'rt-system-dispatch-links'));
        self::assertSame(['2', '24', '6'], $this->slotWidths($xpath, 'rt-system-dispatch-phone'));
        self::assertStringNotContainsString('padding: 8px 8px', $cells->item(1)->getAttribute('style'));
        $number = $xpath->query('//img[@src="https://mail.example.test/m/ef89fd23c6.png"]');
        self::assertSame(1, $number->length);
        self::assertSame('108', $number->item(0)->getAttribute('width'));
        self::assertSame('20', $number->item(0)->getAttribute('height'));
        self::assertSame('+49 160 1881839', $number->item(0)->getAttribute('alt'));
        self::assertSame('tel:+491601881839', $number->item(0)->parentNode->getAttribute('href'));
        self::assertSame(0, $xpath->query('//a[@href="tel:+491601881839"]/span')->length);
        self::assertSame(9, $xpath->query('//img')->length);
        self::assertSame(1, $xpath->query('//table[contains(concat(" ",normalize-space(@class)," ")," rt-v17-dispatch-block ")]')->length);
        self::assertSame(1, $xpath->query('//table[contains(concat(" ",normalize-space(@class)," ")," rt-system-columns ")]')->length);
        self::assertSame(0, $xpath->query('//*[contains(concat(" ",normalize-space(@class)," ")," rt-personal-name ")]')->length);
        self::assertSame($documents, MailDocument::query()->orderBy('id')->get()->toArray());
        self::assertSame($actual, SystemMailDispatchActions::project($actual, OutlookLinkedAnimatedDelivery::PROFILE, 'https://mail.example.test'));
        $email = (new Email)->from('system@example.test')->to('self@example.test')->html($actual);
        self::assertSame(0, (new SystemMailInlineImageEmbedder)->embed($email));
        self::assertCount(0, $email->getAttachments());
        self::assertSame(0, $xpath->query('//body//style')->length);
        self::assertSame(13, $xpath->query('//head/style')->length);
        self::assertStringContainsString('width="100%"', $this->actions($source));
        $noInline = preg_replace('/\sstyle="[^"]*"/s', '', $actual);
        self::assertSame(['24', '8', '24'], $this->slotWidths($this->xpath($noInline), 'rt-system-dispatch-links'));
        self::assertSame(['2', '24', '6'], $this->slotWidths($this->xpath($noInline), 'rt-system-dispatch-phone'));
        Http::assertNothingSent();
    }

    #[DataProvider('genericPhones')]
    public function test_other_configured_phone_numbers_keep_their_live_text_and_href(string $phone): void
    {
        [, $actual] = $this->fixture($phone);
        $xpath = $this->xpath($actual);
        $number = $xpath->query('//table[@data-rt-system-dispatch-actions="v1"]/tbody/tr/td[3]/a');
        self::assertSame(1, $number->length);
        self::assertSame('tel:'.$phone, $number->item(0)->getAttribute('href'));
        self::assertSame($phone, $number->item(0)->textContent);
        self::assertSame(0, $number->item(0)->getElementsByTagName('img')->length);
        self::assertSame(0, $xpath->query('//img[contains(@src,"ef89fd23c6")]')->length);
        self::assertSame(['62', '32', '121'], $this->slotWidths($xpath, 'rt-v17-dispatch-actions'));
        self::assertStringContainsString('white-space:nowrap!important;', $number->item(0)->getElementsByTagName('span')->item(0)->getAttribute('style'));
        self::assertSame($actual, SystemMailDispatchActions::project($actual, OutlookLinkedAnimatedDelivery::PROFILE, 'https://mail.example.test'));
    }

    public static function genericPhones(): array
    {
        return [['+491705550202'], ['+442079460958'], ['+33123456789']];
    }

    public function test_projection_preserves_all_outside_bytes_semantic_links_head_css_and_opaque_comments(): void
    {
        [$source] = $this->fixture('+491601881839');
        $opaque = '<!-- <table class="rt-v17-dispatch-actions">unrelated</table> -->'
            .'<style>.unrelated:after{content:"<table class=rt-v17-dispatch-actions>"}</style>';
        $source = str_replace('</body>', $opaque.'<div id="author-after">Keep outside content &amp; formatting.</div></body>', $source);
        $actual = SystemMailDispatchActions::project($source, OutlookLinkedAnimatedDelivery::PROFILE, 'https://mail.example.test');
        self::assertSame(str_replace($this->actions($source), '__ACTIONS__', $source), str_replace($this->actions($actual), '__ACTIONS__', $actual));
        self::assertSame($this->hrefs($source), $this->hrefs($actual));
        self::assertStringContainsString($opaque, $actual);
        self::assertStringContainsString('<div id="author-after">Keep outside content &amp; formatting.</div>', $actual);
    }

    public function test_blocked_images_still_have_numeric_boxes_meaningful_alt_and_live_generic_contact_text(): void
    {
        [, $html] = $this->fixture('+491705550202');
        $xpath = $this->xpath($html);
        $images = $xpath->query('//table[@data-rt-system-dispatch-actions="v1"]//img');
        foreach ($images as $image) {
            self::assertSame('24', $image->getAttribute('width'));
            self::assertSame('24', $image->getAttribute('height'));
            self::assertNotSame('', $image->getAttribute('alt'));
        }
        $caption = $xpath->query('//img[contains(concat(" ",normalize-space(@class)," ")," rt-v17-dispatch-caption ")]')->item(0);
        self::assertSame('Dispo 24/7 erreichbar', $caption->getAttribute('alt'));
        self::assertSame('215', $caption->getAttribute('width'));
        self::assertSame('50', $caption->getAttribute('height'));
        self::assertStringContainsString('+491705550202', $xpath->query('//body')->item(0)->textContent);
        self::assertGreaterThan(0, $xpath->query('//a[starts-with(@href,"https:")][normalize-space(.)!=""]')->length);
        Http::assertNothingSent();
    }

    public function test_unadmitted_other_profiles_and_employee_layout_are_byte_unchanged(): void
    {
        [$source] = $this->fixture('+491601881839');
        foreach ([null, OutlookStaticLinkedTestDelivery::PROFILE, 'unknown-profile'] as $profile) {
            self::assertSame($source, SystemMailDispatchActions::project($source, $profile, 'not-used'));
        }
        $employee = str_replace('rt-system-columns', 'rt-employee-columns', $source);
        self::assertSame($employee, SystemMailDispatchActions::project($employee, OutlookLinkedAnimatedDelivery::PROFILE, 'https://mail.example.test'));
        self::assertSame('<p>Unrelated</p>', SystemMailDispatchActions::project('<p>Unrelated</p>', OutlookLinkedAnimatedDelivery::PROFILE, 'not-used'));
    }

    #[DataProvider('tamperCases')]
    public function test_recognized_ambiguous_or_tampered_geometry_fails_before_rewriting(string $case): void
    {
        [$source, $actual] = $this->fixture('+491601881839');
        $actions = $this->actions($source);
        $bad = match ($case) {
            'double-card' => str_replace($actions, $actions.$actions, $source),
            'width' => str_replace('width="100%"', 'width="99"', $actions),
            'style-width' => str_replace('width: 100%;', 'width: 999px;', $actions),
            'foreign-icon' => str_replace('/m/19125261c2.png', '/m/foreign.png', $actions),
            'phone-mismatch' => preg_replace('/tel:\+491601881839/', 'tel:+491601881840', $actions, 1),
            'quote' => '<blockquote>'.$source.'</blockquote>',
            'owned-width' => str_replace('width="108"', 'width="107"', $actual),
            'owned-marker' => str_replace('data-rt-system-dispatch-actions="v1"', 'data-rt-system-dispatch-actions="foreign"', $actual),
            default => throw new RuntimeException('Unknown test case.'),
        };
        if (in_array($case, ['width', 'style-width', 'foreign-icon', 'phone-mismatch'], true)) {
            $bad = str_replace($actions, $bad, $source);
        }
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Systemmail-Aktionszeile');
        SystemMailDispatchActions::project($bad, OutlookLinkedAnimatedDelivery::PROFILE, 'https://mail.example.test');
    }

    public static function tamperCases(): array
    {
        return array_map(static fn (string $value): array => [$value], ['double-card', 'width', 'style-width', 'foreign-icon', 'phone-mismatch', 'quote', 'owned-width', 'owned-marker']);
    }

    /** Actual builder/inliner plus independent pre-egress preview, all SQLite in memory. */
    private function fixture(string $phone): array
    {
        $fixture = new PublishedStaticSystemDeliveryTest('test_effective_published_system_pair_uses_remote_pngs_through_the_real_inliner_and_embedder');
        (new ReflectionMethod($fixture, 'setUp'))->invoke($fixture);
        $signature = (new ReflectionProperty($fixture, 'linked'))->getValue($fixture);
        $template = (new ReflectionProperty($fixture, 'template'))->getValue($fixture);
        $mark = new ReflectionMethod(OutlookLinkedAnimatedDeliveryTest::class, 'mark');
        $marker = new OutlookLinkedAnimatedDeliveryTest('test_actual_system_default_and_explicit_published_testmail_keep_the_gif_link_without_inline_attachments');
        $signatureHtml = $mark->invoke($marker, $signature->published_html, true);
        $templateHtml = $mark->invoke($marker, $template->published_html, false);
        $signature->forceFill(['html' => $signatureHtml, 'published_html' => $signatureHtml])->saveQuietly();
        $template->forceFill(['html' => $templateHtml, 'published_html' => $templateHtml, 'published_signature_document_id' => $signature->id])->saveQuietly();
        Setting::setValue('company', 'profile', array_replace(CompanyData::all(), ['emergency_phone' => $phone]));
        app()->forgetScopedInstances();
        $before = MailDocument::query()->orderBy('id')->get()->toArray();
        // This fixture exercises the historical V17 adapter. The current U2e
        // default and real Markdown/CID delivery have their own integration suite.
        app()->instance(\App\Support\Mail\OutlookUniversalSystemMailPresentation::class, new class
        {
            public function project(...$arguments): ?array
            {
                return null;
            }
        });
        try {
            $compiled = EmailTemplateBuilder::buildSystemMailHtml(new HtmlString('<p>Untouched application content &amp; links.</p>'));
        } finally {
            app()->forgetInstance(\App\Support\Mail\OutlookUniversalSystemMailPresentation::class);
        }
        $source = OutlookStaticLinkedTestProfiles::renderedPreview((new CssToInlineStyles)->convert($compiled), OutlookLinkedAnimatedDelivery::PROFILE, 'https://mail.example.test');
        $actual = (new SystemMailCssInliner)->convert($compiled);
        Http::assertNothingSent();

        return [$source, $actual, $before];
    }

    private function xpath(string $html): DOMXPath
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        try {
            $document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return new DOMXPath($document);
    }

    private function actions(string $html): string
    {
        $masked = preg_replace_callback('~<!--.*?-->|<style\b[^>]*>.*?</style\s*>~is', static fn (array $m): string => str_repeat(' ', strlen($m[0])), $html);
        self::assertSame(1, preg_match_all('~<table\b(?=[^>]*\bclass="rt-v17-dispatch-actions")[^>]*>~s', $masked, $match, PREG_OFFSET_CAPTURE));
        $start = $match[0][0][1];
        preg_match_all('~</?table\b[^>]*>~i', $masked, $tables, PREG_OFFSET_CAPTURE, $start);
        $depth = 0;
        foreach ($tables[0] as [$tag, $offset]) {
            $depth += str_starts_with(strtolower($tag), '</') ? -1 : 1;
            if ($depth === 0) {
                return substr($html, $start, $offset + strlen($tag) - $start);
            }
        }
        self::fail('Missing outer actions close.');
    }

    private function slotWidths(DOMXPath $xpath, string $class): array
    {
        $cells = $xpath->query('//table[@class="'.$class.'"]/tbody/tr/td');

        return array_map(static fn ($node): string => $node->getAttribute('width'), iterator_to_array($cells));
    }

    private function hrefs(string $html): array
    {
        return array_map(static fn ($node): string => $node->getAttribute('href'), iterator_to_array($this->xpath($html)->query('//a')));
    }
}
