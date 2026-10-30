<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\MailDocument;
use App\Support\OutlookAddin\OutlookAddinPayloadService;
use App\Support\OutlookAddin\OutlookClientPresentation;
use App\Support\OutlookAddin\OutlookContentFramePresentation;
use App\Support\OutlookAddin\OutlookDesktopFlow;
use App\Support\OutlookAddin\OutlookFluidSignaturePresentation;
use App\Support\OutlookAddin\OutlookIntrinsicTrainDelivery;
use App\Support\OutlookAddin\OutlookMobileSignature;
use App\Support\OutlookAddin\OutlookReferenceCardPresentation;
use App\Support\OutlookAddin\OutlookThreeCellPresentation;
use App\Support\OutlookAddin\OutlookThreeColumnPresentation;
use DOMDocument;
use DOMXPath;
use ReflectionMethod;
use Tests\TestCase;

final class OutlookReferenceCardPresentationTest extends TestCase
{
    public function test_reference_layout_preserves_the_percentage_host_orders_contacts_and_uses_the_exact_card_assets(): void
    {
        $fixture = new OutlookFluidSignaturePresentationTest('test_portable_source_fits_the_measured_226_pixel_host_without_a_stylesheet');
        $user = (new ReflectionMethod($fixture, 'fixture'))->invoke($fixture);
        $canonical = app(OutlookAddinPayloadService::class)->forUser($user);
        $before = MailDocument::query()->orderBy('id')->get()->toArray();
        foreach ([$canonical, OutlookMobileSignature::payload($canonical)] as $source) {
            $result = OutlookReferenceCardPresentation::payload(self::historicalPayload($source));
            $documents = [$result['signature']['html']];
            foreach ($result['templates'] as $entry) {
                $documents[] = $entry['signature']['html'];
                foreach (['combinedComposeHtml', 'mobileComposeHtml'] as $field) {
                    if (isset($entry[$field])) {
                        $documents[] = $entry[$field];
                    }
                }
            }
            foreach ($documents as $html) {
                $xpath = $this->xpath($html);
                $root = $xpath->query('//table[@data-rt-desktop-flow="outlook-desktop-flow-v13"]')->item(0);
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
                $direct = $xpath->query('.//a[@href]', $columns->item(1));
                self::assertStringStartsWith('mailto:', $direct->item(0)->getAttribute('href'));
                for ($i = 1; $i < $direct->length; $i++) {
                    self::assertStringStartsWith('tel:', $direct->item($i)->getAttribute('href'));
                }
                self::assertSame(1, $xpath->query('.//a[starts-with(@href,"http")]', $columns->item(2))->length);
                self::assertSame(0, $xpath->query('.//a[starts-with(@href,"mailto:") or starts-with(@href,"tel:")]', $columns->item(2))->length);
                self::assertSame(1, $xpath->query('.//p[@class="rt-v9-address"]', $columns->item(2))->length);
                self::assertSame(1, $xpath->query('.//table[contains(@class,"rt-personal-logo-frame")]//img[@width="184"][@height="32"]', $columns->item(0))->length);
                self::assertSame(1, $xpath->query('.//table[contains(@class,"rt-v13-dispatch-block")][@width="100%"]', $columns->item(3))->length);
                self::assertStringContainsString('width:100%;max-width:227px;', $xpath->query('.//table[contains(@class,"rt-v13-dispatch-block")]', $columns->item(3))->item(0)->getAttribute('style'));
                self::assertSame(1, $xpath->query('.//img[@width="199"][@height="28"]', $columns->item(3))->length);
                self::assertSame(2, $xpath->query('.//img[@width="25"][@height="25"]', $columns->item(3))->length);
                self::assertSame(0, $xpath->query('.//*[@bgcolor or @background or @data-rt-dispatch-card]', $columns->item(3))->length);
                foreach ($xpath->query('.//*[@style]', $columns->item(3)) as $node) {
                    self::assertDoesNotMatchRegularExpression('/(?:^|;)\s*background(?:-[a-z-]+)?\s*:/i', $node->getAttribute('style'));
                }
                self::assertSame(1, $xpath->query('./tbody/tr[2]/td/table[@width="547"]//img[@width="529"][@height="115"]', $root)->length);
                self::assertSame($html, OutlookReferenceCardPresentation::document($html));
            }
            $signature = $result['signature'];
            foreach ($signature['media'] as $asset) {
                self::assertStringContainsString('cid:'.$asset['contentId'], $signature['html']);
            }
            $assetHashes = array_map(static fn (array $asset): string => hash('sha256', base64_decode($asset['base64'], true)), $signature['media']);
            self::assertContains('37683e55f5469c8593450bfeaeea13ed0b1849976dce15b7479d7095d998540a', $assetHashes);
            self::assertNotContains('3ee5033b5b112852a0255cf62d99542391117ba462408510c460c799a5ad6583', $assetHashes);
            self::assertNotContains('464054a84f32ad05d4c6e65a67105c6500ed070a818a40935d3b9f390bec7ab7', $assetHashes);
        }
        self::assertSame($before, MailDocument::query()->orderBy('id')->get()->toArray());
    }

    /** Keep the historical V11/V12 suites on their own immutable output factories. */
    public static function historicalPayload(array $payload): array
    {
        $desktop = ! OutlookDesktopFlow::mobile($payload['signature']['html']);
        $document = new ReflectionMethod(OutlookClientPresentation::class, 'document');
        $prune = new ReflectionMethod(OutlookClientPresentation::class, 'withoutRemovedMark');
        $project = static function (array $pair, string $field, string $media, int $limit, int $css) use ($desktop, $document, $prune): array {
            $before = $pair[$field];
            $pair[$field] = $document->invoke(null, $before, $limit, $css, $desktop);
            $pair[$media] = $prune->invoke(null, $before, $pair[$field], $pair[$media]);

            return $pair;
        };
        foreach (['signature', 'template'] as $key) {
            if (isset($payload[$key]['html'])) {
                $payload[$key] = $project($payload[$key], 'html', 'media', $key === 'signature' ? 30000 : 99000, $key === 'signature' ? 12288 : 24576);
            }
        }
        foreach ($payload['templates'] as $index => $entry) {
            foreach (['html' => 'media', 'composeHtml' => 'composeMedia', 'combinedComposeHtml' => 'combinedComposeMedia', 'mobileComposeHtml' => 'mobileComposeMedia'] as $field => $media) {
                if (isset($entry[$field])) {
                    $entry = $project($entry, $field, $media, $field === 'mobileComposeHtml' ? 30000 : 99000, $field === 'mobileComposeHtml' ? 12288 : 24576);
                }
            }
            if (isset($entry['signature'])) {
                $entry['signature'] = $project($entry['signature'], 'html', 'media', 30000, 12288);
            }
            $payload['templates'][$index] = $entry;
        }

        return OutlookContentFramePresentation::payload(OutlookFluidSignaturePresentation::payload(OutlookThreeCellPresentation::payload(OutlookThreeColumnPresentation::payload(OutlookIntrinsicTrainDelivery::payload($payload)))));
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
