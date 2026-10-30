<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\MailDocumentKind;
use App\Models\MailDocument;
use App\Models\User;
use App\Support\EmailTemplateBuilder;
use App\Support\Mail\PublishedMailDocumentSnapshotStore;
use App\Support\Mail\SystemMailInlineImageEmbedder;
use App\Support\OutlookAddin\OutlookAddinPayloadService;
use App\Support\OutlookAddin\OutlookClientPresentation;
use App\Support\OutlookAddin\OutlookFluidSignaturePresentation;
use App\Support\OutlookAddin\OutlookMobileSignature;
use App\Support\OutlookAddin\OutlookStaticLinkedTestDelivery;
use App\Support\OutlookAddin\OutlookThreeCellPresentation;
use App\Support\OutlookAddin\OutlookThreeColumnPresentation;
use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\HtmlString;
use ReflectionMethod;
use Symfony\Component\Mime\Email;
use Tests\TestCase;
use TijsVerkoyen\CssToInlineStyles\CssToInlineStyles;

final class OutlookThreeColumnPresentationTest extends TestCase
{
    public function test_actual_personal_desktop_and_mobile_keep_four_portable_source_tables_and_media(): void
    {
        $user = $this->fixture(true);
        $canonical = app(OutlookAddinPayloadService::class)->forUser($user);
        $published = MailDocument::query()->orderBy('id')->get()->toArray();
        foreach ([$canonical, OutlookMobileSignature::payload($canonical)] as $source) {
            $result = OutlookClientPresentation::payload($source);
            $html = $result['signature']['html'];
            $xpath = $this->xpath($html);
            self::assertSame(1, $xpath->query('//table[@data-rt-desktop-flow="outlook-desktop-flow-v11"]')->length);
            self::assertSame(4, $xpath->query('//table[contains(concat(" ",normalize-space(@class)," ")," rt-v11-column ")][@align="left"]')->length);
            self::assertSame(1, $xpath->query('//table[contains(@class,"rt-personal-left")]/tbody/tr/td/table[contains(@class,"rt-personal-logo-frame")]//img[@width="160"][@height="27"]')->length);
            self::assertSame(0, $xpath->query('//table[contains(@class,"rt-personal-right")]//img[contains(@class,"rt-v9-logo")]')->length);
            self::assertSame(1, $xpath->query('//table[contains(@class,"rt-dispatch-column")]//img[@alt="Dispo 24/7 erreichbar"][@width="160"]')->length);
            self::assertSame(1, $xpath->query('//br[@clear="all"]')->length);
            self::assertSame(0, $xpath->query('//tr[@class="rt-v10-grid-row"]')->length);
            self::assertSame(1, $xpath->query('//td[@class="rt-v11-columns-cell"]')->length);
            self::assertSame(1, $xpath->query('//td[contains(concat(" ",normalize-space(@class)," ")," rt-v9-top-rule ")]')->length);
            self::assertSame(1, $xpath->query('//p[@class="rt-v9-address"]')->length);
            self::assertSame(1, $xpath->query('//p[@class="rt-v9-address"]//br')->length);
            foreach ($xpath->query('//table[contains(concat(" ",normalize-space(@class)," ")," rt-v11-column ")]//span') as $span) {
                self::assertDoesNotMatchRegularExpression('/background(?:-color)?\s*:\s*(?:#fff(?:fff)?|white|rgb\(\s*255\s*,\s*255\s*,\s*255\s*\))/i', $span->getAttribute('style'));
            }
            foreach ($result['templates'] as $entry) {
                foreach (['html', 'combinedComposeHtml', 'mobileComposeHtml'] as $field) {
                    if (isset($entry[$field])) {
                        self::assertStringContainsString('data-rt-desktop-flow="outlook-desktop-flow-v11"', $entry[$field], $field.' must not retain the old fallback layout.');
                    }
                }
            }
            self::assertStringContainsString('border-top:3px solid #e90032;', $html);
            self::assertStringNotContainsString('data-rt-brand-hotline=', $html);
            self::assertStringNotContainsString('rt-brand-outline-cell', $html);
            self::assertLessThanOrEqual(30000 - 855, intdiv(strlen(mb_convert_encoding($html, 'UTF-16LE', 'UTF-8')), 2));
            self::assertSame($html, OutlookFluidSignaturePresentation::document($html));
            $refs = [];
            preg_match_all('/cid:([A-Za-z0-9._-]+)/', $html, $refs);
            $ids = array_column($result['signature']['media'], 'contentId');
            foreach (array_unique($refs[1]) as $cid) {
                self::assertContains($cid, $ids);
            }
            foreach ($ids as $cid) {
                self::assertStringContainsString('cid:'.$cid, $html);
            }
        }
        self::assertSame($published, MailDocument::query()->orderBy('id')->get()->toArray());
    }

    public function test_actual_legacy_system_source_compiles_with_doctype_and_replaces_both_imported_icons(): void
    {
        $this->fixture(false);
        $source = $this->legacySystem();
        self::assertSame(2, substr_count($source['html'], '/storage/mail-imports/'));
        $published = MailDocument::query()->orderBy('id')->get()->toArray();
        app(PublishedMailDocumentSnapshotStore::class)->useSnapshot(MailDocumentKind::Signature, $source['html'], $source['css'], explicit: true);
        $html = EmailTemplateBuilder::buildSystemMailHtml(new HtmlString('<p>Independent system test</p>'));
        $html = (new CssToInlineStyles)->convert($html);
        self::assertMatchesRegularExpression('/\A\s*<!doctype html>/i', $html);
        self::assertStringContainsString('data-rt-system-flow="v1"', $html);
        self::assertStringContainsString('rt-system-signature-v27', $html);
        self::assertStringNotContainsString('/storage/mail-imports/', $html);
        self::assertStringNotContainsString('class="rt-mark"', $html);
        self::assertSame(1, $this->xpath($html)->query('//img[contains(@class,"rt-v9-logo")]')->length);
        self::assertSame(1, $this->xpath($html)->query('//img[contains(@class,"rt-delivery-train")]')->length);
        self::assertStringContainsString((string) __('app.mail_signature_company_role'), html_entity_decode(strip_tags($html)));
        $message = (new Email)->from('system@example.test')->to('self@example.test')->html($html);
        self::assertGreaterThanOrEqual(8, app(SystemMailInlineImageEmbedder::class)->embed($message));
        self::assertStringContainsString('cid:', $message->getHtmlBody());
        self::assertSame($published, MailDocument::query()->orderBy('id')->get()->toArray());
    }

    public function test_actual_marked_legacy_system_source_passes_full_static_profile_without_relaxing_asset_admission(): void
    {
        $this->fixture(false);
        $source = $this->legacySystem();
        $marked = preg_replace('/<tr(?=\s+data-rt-artifact-version="v27")/', '<tr data-rt-static="v1"', $source['html'], 1, $count);
        self::assertSame(1, $count);
        app(PublishedMailDocumentSnapshotStore::class)->useSnapshot(MailDocumentKind::Signature, $marked, $source['css'], explicit: true);
        $html = (new CssToInlineStyles)->convert(EmailTemplateBuilder::buildSystemMailHtml(new HtmlString('<p>Static source test</p>')));
        $linked = OutlookStaticLinkedTestDelivery::renderedPreview($html, OutlookStaticLinkedTestDelivery::PROFILE, 'https://mail.example.test');
        self::assertStringNotContainsString('/storage/', $linked);
        self::assertStringNotContainsString('/mail-assets/', $linked);
        self::assertStringNotContainsString('cid:', $linked);
        self::assertStringNotContainsString('data-rt-static=', $linked);
        self::assertSame(9, $this->xpath($linked)->query('//img')->length);
        foreach ($this->xpath($linked)->query('//img') as $image) {
            self::assertMatchesRegularExpression('~\Ahttps://mail\.example\.test/m/[a-f0-9]{10}\.png\z~', $image->getAttribute('src'));
        }
        $message = (new Email)->from('system@example.test')->to('self@example.test')->html($linked);
        self::assertSame(0, app(SystemMailInlineImageEmbedder::class)->embed($message));
    }

    public function test_unknown_html_and_authored_signature_rules_are_not_reinterpreted(): void
    {
        $unowned = '<table><tr><td>External layout</td></tr></table>';
        self::assertSame($unowned, OutlookThreeColumnPresentation::document($unowned));
        self::assertSame($unowned, OutlookThreeColumnPresentation::systemDocument($unowned, 'https://mail.example.test'));
        self::assertSame($unowned, OutlookThreeColumnPresentation::systemDocument($unowned, 'https://mail.example.test', '.custom{color:purple}'));
        $user = $this->fixture(true);
        $canonical = app(OutlookAddinPayloadService::class)->forUser($user);
        $custom = '<style data-rt-mail-document-css="signature">.rt-personal-left{color:purple}</style>'.$canonical['signature']['html'];
        self::assertSame($custom, OutlookThreeColumnPresentation::document($custom));
    }

    public function test_word_fallback_has_three_real_cells_without_paragraph_anchored_tables(): void
    {
        $user = $this->fixture(true);
        $canonical = app(OutlookAddinPayloadService::class)->forUser($user);
        $base = (new ReflectionMethod(OutlookClientPresentation::class, 'document'))->invoke(null, $canonical['signature']['html'], 30000, 12288, true);
        $html = OutlookThreeCellPresentation::document(OutlookThreeColumnPresentation::document($base));
        $xpath = $this->xpath($html);
        $root = $xpath->query('//table[@data-rt-desktop-flow="outlook-desktop-flow-v10"]')->item(0);
        self::assertSame('720', $root->getAttribute('width'));
        self::assertStringContainsString('width:720px;max-width:100%;', $root->getAttribute('style'));
        $cells = $xpath->query('./tbody/tr[1]/td', $root);
        self::assertCount(3, $cells);
        foreach ($cells as $cell) {
            self::assertSame('240', $cell->getAttribute('width'));
            self::assertSame('top', $cell->getAttribute('valign'));
            self::assertStringContainsString('display:inline-block;', $cell->getAttribute('style'));
            $columns = $xpath->query('./table', $cell);
            self::assertCount(1, $columns);
            self::assertFalse($columns->item(0)->hasAttribute('align'));
            self::assertStringNotContainsString('float:', $columns->item(0)->getAttribute('style'));
            self::assertStringContainsString('padding:0 12px 0 40px;', $xpath->query('./tbody/tr/td', $columns->item(0))->item(0)->getAttribute('style'));
        }
        $train = $xpath->query('//table[contains(concat(" ",normalize-space(@class)," ")," rt-df-train ")]')->item(0);
        $image = $xpath->query('//img[contains(@class,"rt-v10-train-image")]')->item(0);
        self::assertSame('364', $train->getAttribute('width'));
        self::assertFalse($train->hasAttribute('align'));
        self::assertSame('324', $image->getAttribute('width'));
        self::assertSame('70', $image->getAttribute('height'));
        self::assertStringContainsString('width:100%;max-width:100%;height:auto;', $image->getAttribute('style'));
        self::assertSame(1, $xpath->query('./tbody/tr[2]/td[@colspan="3"]', $root)->length);
        self::assertSame('outlook-desktop-flow-v9', OutlookThreeColumnPresentation::FLOW, 'The historical family keeps its own immutable source contract.');
    }

    public function test_word_border_paint_is_on_owned_frame_cells_and_keeps_a_single_three_pixel_edge(): void
    {
        $user = $this->fixture(true);
        $payload = OutlookClientPresentation::payload(app(OutlookAddinPayloadService::class)->forUser($user));
        $examples = [$payload['signature']['html']];
        foreach ($payload['templates'] as $entry) {
            if (isset($entry['combinedComposeHtml'])) {
                $examples[] = $entry['combinedComposeHtml'];
            }
        }
        foreach ($examples as $html) {
            $xpath = $this->xpath($html);
            $frames = $xpath->query('//table[contains(@class,"rt-managed-combined-v1") or (contains(@class,"rt-managed-signature-v1") and not(contains(@class,"rt-combined-signature-frame")))]');
            self::assertSame(1, $frames->length);
            $frame = $frames->item(0);
            self::assertStringContainsString('border-left:0!important;', $frame->getAttribute('style'));
            foreach ($xpath->query('./tbody/tr/td', $frame) as $cell) {
                self::assertStringContainsString('border-left:3px solid #e90032!important;', $cell->getAttribute('style'));
            }
            foreach ($xpath->query('//*[@style]') as $element) {
                self::assertStringNotContainsString('border-left:6px solid #e90032;', $element->getAttribute('style'));
            }
            self::assertStringContainsString('padding:0 18px 0 0;', $xpath->query('//td[contains(@class,"rt-v11-train-cell")]')->item(0)->getAttribute('style'));
            self::assertStringContainsString('padding:0 18px;', $xpath->query('//td[contains(@class,"rt-v11-legal-cell")]')->item(0)->getAttribute('style'));
        }
    }

    public function test_word_losing_table_and_anchor_inheritance_keeps_legal_and_dispatch_leaf_paint(): void
    {
        $user = $this->fixture(true);
        $html = OutlookClientPresentation::payload(app(OutlookAddinPayloadService::class)->forUser($user))['signature']['html'];
        $xpath = $this->xpath($html);
        foreach ($xpath->query('//table | //td | //a') as $element) {
            $element->removeAttribute('style');
        }
        foreach ($xpath->query('//style') as $style) {
            $style->parentNode->removeChild($style);
        }
        $legal = $xpath->query('//td[@class="rt-v11-legal-cell"]')->item(0);
        $textNodes = $xpath->query('.//text()[normalize-space(.) != ""]', $legal);
        self::assertGreaterThanOrEqual(2, $textNodes->length);
        foreach ($textNodes as $text) {
            self::assertSame('span', $text->parentNode->nodeName);
            self::assertStringContainsString('font-size:9px;', $text->parentNode->getAttribute('style'));
            self::assertStringContainsString('line-height:14px;', $text->parentNode->getAttribute('style'));
            self::assertStringContainsString('color:#718096;', $text->parentNode->getAttribute('style'));
        }
        $phone = $xpath->query('//table[contains(@class,"rt-dispatch-column")]//a[starts-with(@href,"tel:")]/span')->item(0);
        self::assertNotNull($phone);
        self::assertStringContainsString('font-size:13px;', $phone->getAttribute('style'));
        self::assertStringContainsString('line-height:16px;', $phone->getAttribute('style'));
        self::assertStringContainsString('color:#e90032;', $phone->getAttribute('style'));
        self::assertSame('tel:'.preg_replace('/[^+0-9]/', '', $phone->textContent), $phone->parentNode->getAttribute('href'));
        $authored = '<style data-rt-mail-document-css="signature">.custom{color:purple}</style>'.$html;
        self::assertSame($authored, OutlookThreeCellPresentation::document($authored));
    }

    private function fixture(bool $personal): User
    {
        $case = new OutlookBrandedSignatureIntegrationTest('test_actual_global_paired_desktop_and_mobile_documents_keep_exact_media_and_budgets');
        [, $user] = (new ReflectionMethod($case, 'fixture'))->invoke($case, $personal);
        Http::preventStrayRequests();
        config(['app.url' => 'https://mail.example.test']);
        URL::forceRootUrl('https://mail.example.test');
        URL::forceScheme('https');

        return $user;
    }

    private function legacySystem(): array
    {
        return json_decode(file_get_contents(__DIR__.'/../Fixtures/mail/r70-legacy-system-source.json'), true, 512, JSON_THROW_ON_ERROR);
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
}
