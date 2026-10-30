<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\MailDocumentKind;
use App\Models\MailDocument;
use App\Support\EmailTemplateBuilder;
use App\Support\Mail\PublishedMailDocumentSnapshotStore;
use App\Support\Mail\SystemMailInlineImageEmbedder;
use App\Support\OutlookAddin\OutlookAddinPayloadService;
use App\Support\OutlookAddin\OutlookClientPresentation;
use App\Support\OutlookAddin\OutlookDispatchCardPresentation;
use App\Support\OutlookAddin\OutlookMobileSignature;
use App\Support\OutlookAddin\OutlookReferenceCardPresentation;
use App\Support\OutlookAddin\OutlookStaticLinkedTestDelivery;
use DOMDocument;
use DOMXPath;
use Illuminate\Support\HtmlString;
use ReflectionMethod;
use Symfony\Component\Mime\Email;
use Tests\TestCase;
use TijsVerkoyen\CssToInlineStyles\CssToInlineStyles;

final class OutlookDispatchCardPresentationTest extends TestCase
{
    public function test_v14_red_card_changes_only_the_dispatch_block_after_the_historical_v13_contract(): void
    {
        $fixture = new OutlookFluidSignaturePresentationTest('test_portable_source_fits_the_measured_226_pixel_host_without_a_stylesheet');
        $user = (new ReflectionMethod($fixture, 'fixture'))->invoke($fixture);
        $canonical = app(OutlookAddinPayloadService::class)->forUser($user);
        $before = MailDocument::query()->orderBy('id')->get()->toArray();
        foreach ([$canonical, OutlookMobileSignature::payload($canonical)] as $source) {
            $historical = OutlookReferenceCardPresentation::payload(OutlookReferenceCardPresentationTest::historicalPayload($source));
            $wire = OutlookClientPresentation::payload($source);
            $documents = [[$wire['signature']['html'], 30000]];
            foreach ($wire['templates'] as $entry) {
                $documents[] = [$entry['signature']['html'], 30000];
                foreach (['combinedComposeHtml', 'mobileComposeHtml'] as $field) {
                    if (isset($entry[$field])) {
                        $documents[] = [$entry[$field], $field === 'mobileComposeHtml' ? 30000 : 99000];
                    }
                }
            }
            foreach ($documents as [$html, $limit]) {
                $xpath = $this->xpath($html);
                $root = $xpath->query('//table[@data-rt-desktop-flow="outlook-desktop-flow-v14"]')->item(0);
                self::assertNotNull($root);
                self::assertSame('100%', $root->getAttribute('width'));
                self::assertStringContainsString('rt-sign-content-frame', $root->getAttribute('class'));
                $main = $xpath->query('./tbody/tr[1]/td', $root)->item(0);
                self::assertStringContainsString('padding:12px 0 0 18px;', $main->getAttribute('style'));
                $columns = $xpath->query('./table', $main);
                self::assertCount(4, $columns);
                foreach ([200, 260, 230, 247] as $index => $width) {
                    self::assertSame((string) $width, $columns->item($index)->getAttribute('width'));
                    self::assertStringContainsString('max-width:100%;', $columns->item($index)->getAttribute('style'));
                }
                foreach ($xpath->query('.//td[contains(concat(" ",normalize-space(@class)," ")," rt-contact-icon ")]', $main) as $icon) {
                    self::assertSame('27', $icon->getAttribute('width'));
                    self::assertStringContainsString('width:27px!important;min-width:27px!important;', $icon->getAttribute('style'), 'Inline important must win against the old17px important generated alias.');
                }
                $direct = $xpath->query('.//a[@href]', $columns->item(1));
                self::assertStringStartsWith('mailto:', $direct->item(0)->getAttribute('href'));
                for ($i = 1; $i < $direct->length; $i++) {
                    self::assertStringStartsWith('tel:', $direct->item($i)->getAttribute('href'));
                }
                self::assertSame(1, $xpath->query('.//a[starts-with(@href,"http")]', $columns->item(2))->length);
                self::assertSame(0, $xpath->query('.//a[starts-with(@href,"mailto:") or starts-with(@href,"tel:")]', $columns->item(2))->length);
                self::assertSame(1, $xpath->query('.//p[@class="rt-v9-address"]', $columns->item(2))->length);
                $card = $xpath->query('.//table[contains(@class,"rt-v14-dispatch-block")]', $columns->item(3))->item(0);
                self::assertSame('100%', $card->getAttribute('width'));
                self::assertSame('#e90032', $card->getAttribute('bgcolor'));
                self::assertStringContainsString('max-width:227px;', $card->getAttribute('style'));
                self::assertStringContainsString('border-radius:8px;', $card->getAttribute('style'));
                $cell = $xpath->query('./tbody/tr/td', $card)->item(0);
                self::assertStringContainsString('padding:8px 14px 5px;', $cell->getAttribute('style'));
                self::assertSame('#e90032', $cell->getAttribute('bgcolor'));
                $caption = $xpath->query('.//img[@width="177"][@height="34"]', $card)->item(0);
                self::assertNotNull($caption);
                self::assertStringContainsString('width:min(199px,100%)!important;', $caption->getAttribute('style'));
                self::assertSame(2, $xpath->query('.//img[@width="25"][@height="25"]', $card)->length);
                self::assertSame(1, $xpath->query('.//table[contains(@style,"border-top:1px solid #ff6b8b;")]', $card)->length);
                self::assertSame(1, $xpath->query('.//span[contains(@style,"font-size:12px;")][contains(@style,"color:#ffffff;")]', $card)->length);
                $train = $xpath->query('./tbody/tr[2]/td/table[@width="242"]//img[@width="224"][@height="49"]', $root)->item(0);
                self::assertNotNull($train);
                self::assertStringContainsString('width:min(547px,100%)!important;', $xpath->query('./tbody/tr[2]/td/table', $root)->item(0)->getAttribute('style'));
                self::assertStringContainsString('width:min(529px,100%)!important;', $train->getAttribute('style'));
                self::assertStringContainsString('height:auto!important;', $train->getAttribute('style'));
                // Word's observed loss of IMG inline CSS must not expose529px.
                $noImageStyles = preg_replace('~(<img\b[^>]*?)\sstyle="[^"]*"~i', '$1', $html);
                self::assertSame(1, $this->xpath($noImageStyles)->query('//img[contains(@class,"rt-v14-train-image")][@width="224"][@height="49"]')->length);
                $legalHost = $xpath->query('//td[table[contains(@class,"rt-brand-legal-table")]]')->item(0);
                self::assertNotNull($legalHost);
                self::assertStringContainsString('padding:8px 0!important;padding-left:0!important;padding-right:0!important;', $legalHost->getAttribute('style'));
                self::assertStringNotContainsString('padding-left:18px', $legalHost->getAttribute('style'));
                self::assertStringNotContainsString('padding-left:40px', $legalHost->getAttribute('style'));
                self::assertSame(1, $xpath->query('.//td[@class="rt-v14-legal-cell"][@style="padding:0 18px;"]', $legalHost)->length);
                self::assertSame($html, OutlookDispatchCardPresentation::document($html));
                self::assertLessThanOrEqual($limit, intdiv(strlen(mb_convert_encoding($html, 'UTF-16LE', 'UTF-8')), 2), 'Every output keeps its existing field-specific Office budget.');
            }
            $signature = $wire['signature'];
            foreach ($signature['media'] as $asset) {
                self::assertStringContainsString('cid:'.$asset['contentId'], $signature['html']);
            }
            $hashes = array_map(static fn (array $asset): string => hash('sha256', base64_decode($asset['base64'], true)), $signature['media']);
            foreach (['464054a84f32ad05d4c6e65a67105c6500ed070a818a40935d3b9f390bec7ab7', '36ecdeda3135132910ead299dec2fdef8786ed05a39d7fadb3ee4156e5d1696d', 'a3564d9bc8fafc2fc9dcd577361b882dda579f539049876e589fd6d12b090dc5', '3ee5033b5b112852a0255cf62d99542391117ba462408510c460c799a5ad6583'] as $hash) {
                self::assertContains($hash, $hashes);
            }
            self::assertNotContains('37683e55f5469c8593450bfeaeea13ed0b1849976dce15b7479d7095d998540a', $hashes);
            self::assertStringContainsString('outlook-desktop-flow-v13', $historical['signature']['html']);
            self::assertStringNotContainsString('rt-v14-', $historical['signature']['html']);
        }
        self::assertSame($before, MailDocument::query()->orderBy('id')->get()->toArray());
    }

    public function test_actual_system_source_and_static_delivery_use_the_red_card_without_orphan_media(): void
    {
        $fixture = new OutlookThreeColumnPresentationTest('test_actual_legacy_system_source_compiles_with_doctype_and_replaces_both_imported_icons');
        (new ReflectionMethod($fixture, 'fixture'))->invoke($fixture, false);
        $source = (new ReflectionMethod($fixture, 'legacySystem'))->invoke($fixture);
        foreach ([false, true] as $static) {
            $rows = $static ? preg_replace('/<tr(?=\s+data-rt-artifact-version="v27")/', '<tr data-rt-static="v1"', $source['html'], 1) : $source['html'];
            app(PublishedMailDocumentSnapshotStore::class)->useSnapshot(MailDocumentKind::Signature, $rows, $source['css'], explicit: true);
            $html = (new CssToInlineStyles)->convert(EmailTemplateBuilder::buildSystemMailHtml(new HtmlString('<p>Reference red card compiler test</p>')));
            if ($static) {
                $html = OutlookStaticLinkedTestDelivery::renderedPreview($html, OutlookStaticLinkedTestDelivery::PROFILE, 'https://mail.example.test');
            }
            $xpath = $this->xpath($html);
            self::assertSame(3, $xpath->query('//table[contains(concat(" ",normalize-space(@class)," ")," rt-v14-column ")]')->length);
            self::assertSame(7, $xpath->query('//img')->length);
            self::assertStringNotContainsString('/storage/mail-imports/', $html);
            $message = (new Email)->from('system@example.test')->to('self@example.test')->html($html);
            $embedded = app(SystemMailInlineImageEmbedder::class)->embed($message);
            if ($static) {
                self::assertSame(0, $embedded);
                self::assertStringContainsString('/m/464054a84f.png', $message->getHtmlBody());
                self::assertStringContainsString('/m/3ee5033b5b.png', $message->getHtmlBody());
                self::assertStringNotContainsString('cid:', $message->getHtmlBody());
            } else {
                self::assertSame(8, $embedded, 'Seven real images and the single shared red tile.');
                self::assertStringNotContainsString('/mail-assets/outlook-hotline-fill-v1.png', $message->getHtmlBody());
                $tile = array_values(array_filter($message->getAttachments(), static fn ($asset): bool => $asset->getFilename() === 'outlook-hotline-fill-v1.png'));
                self::assertCount(1, $tile);
                self::assertStringContainsString('multipart/related', $message->toString());
                $foreign = str_replace('data-rt-dispatch-card="v14"', 'data-rt-dispatch-card="unknown"', $html);
                $unknown = (new Email)->from('system@example.test')->to('self@example.test')->html($foreign);
                self::assertSame(7, app(SystemMailInlineImageEmbedder::class)->embed($unknown));
                self::assertStringContainsString('/mail-assets/outlook-hotline-fill-v1.png', $unknown->getHtmlBody());
            }
        }
    }

    public function test_unknown_and_tampered_card_sources_are_not_promoted_or_accepted(): void
    {
        self::assertSame('<p>Custom authored signature</p>', OutlookDispatchCardPresentation::document('<p>Custom authored signature</p>'));
        $fixture = new OutlookFluidSignaturePresentationTest('test_portable_source_fits_the_measured_226_pixel_host_without_a_stylesheet');
        $user = (new ReflectionMethod($fixture, 'fixture'))->invoke($fixture);
        $canonical = app(OutlookAddinPayloadService::class)->forUser($user);
        $v13 = OutlookReferenceCardPresentation::payload(OutlookReferenceCardPresentationTest::historicalPayload($canonical));
        $html = $v13['signature']['html'];
        $authored = '<style data-rt-mail-document-css="signature">p{color:green}</style>'.$html;
        self::assertSame($authored, OutlookDispatchCardPresentation::document($authored));
        foreach ([str_replace('max-width:227px;', 'max-width:228px;', $html), str_replace('height="28"', 'height="29"', $html)] as $bad) {
            try {
                OutlookDispatchCardPresentation::document($bad);
                self::fail('A modified historical card cannot enter the new family.');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('Vertrag', $error->getMessage());
            }
        }
        $current = OutlookDispatchCardPresentation::document($html);
        foreach ([str_replace('bgcolor="#e90032"', 'bgcolor="#ffffff"', $current), str_replace('font-size:12px;', 'font-size:13px;', $current), str_replace('min-width:27px!important;', 'min-width:17px!important;', $current), str_replace('padding-left:0!important;', 'padding-left:18px!important;', $current)] as $bad) {
            try {
                OutlookDispatchCardPresentation::document($bad);
                self::fail('A modified current card cannot pass the idempotence guard.');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('Vertrag', $error->getMessage());
            }
        }
    }

    private function xpath(string $html): DOMXPath
    {
        $document = new DOMDocument;
        $old = libxml_use_internal_errors(true);
        try {
            $document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($old);
        }

        return new DOMXPath($document);
    }
}
