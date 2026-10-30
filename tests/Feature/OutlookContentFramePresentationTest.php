<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Support\OutlookAddin\OutlookAddinPayloadService;
use App\Support\OutlookAddin\OutlookClientPresentation;
use App\Support\OutlookAddin\OutlookContentFramePresentation;
use App\Support\OutlookAddin\OutlookFluidSignaturePresentation;
use App\Support\OutlookAddin\OutlookIntrinsicTrainDelivery;
use App\Support\OutlookAddin\OutlookMobileSignature;
use App\Support\OutlookAddin\OutlookThreeCellPresentation;
use App\Support\OutlookAddin\OutlookThreeColumnPresentation;
use DOMDocument;
use DOMXPath;
use ReflectionMethod;
use RuntimeException;
use Tests\TestCase;

final class OutlookContentFramePresentationTest extends TestCase
{
    public function test_historical_v11_is_still_idempotent_and_new_stage_removes_exactly_one_frame_layer(): void
    {
        $user = $this->fixture();
        $historical = $this->historicalSignature($user);
        self::assertSame('outlook-desktop-flow-v11', OutlookFluidSignaturePresentation::FLOW);
        self::assertSame($historical['signature']['html'], OutlookFluidSignaturePresentation::document($historical['signature']['html']));
        $result = OutlookContentFramePresentation::payload($historical);
        self::assertSame($historical['signature']['media'], $result['signature']['media']);
        $before = $this->xpath($historical['signature']['html']);
        $after = $this->xpath($result['signature']['html']);
        self::assertSame($before->query('//table')->length - 1, $after->query('//table')->length);
        self::assertSame(0, $after->query('//table[@data-rt-desktop-flow="outlook-desktop-flow-v11"]')->length);
        $root = $after->query('//table[@data-rt-desktop-flow="outlook-desktop-flow-v12"]')->item(0);
        self::assertNotNull($root);
        self::assertStringContainsString('rt-sign-content-frame', $root->getAttribute('class'));
        self::assertSame('100%', $root->getAttribute('width'));
        self::assertStringContainsString('width:100%;', $root->getAttribute('style'));
        self::assertStringNotContainsString('max-width:', $root->getAttribute('style'));
        self::assertSame(2, $after->query('./tbody/tr', $root)->length);
        self::assertSame('100%', $after->query('./tbody/tr[1]/td', $root)->item(0)->getAttribute('width'));
        self::assertSame(0, $after->query('//td[contains(concat(" ",normalize-space(@class)," ")," rt-sign-content ")]')->length);
        self::assertSame($result['signature']['html'], OutlookContentFramePresentation::document($result['signature']['html']));
    }

    public function test_current_source_retains_all_four_natural_groups_without_an_intermediate_auto_width_table(): void
    {
        $result = OutlookClientPresentation::payload(app(OutlookAddinPayloadService::class)->forUser($this->fixture()));
        $html = $result['signature']['html'];
        $xpath = $this->xpath($html);
        $root = $xpath->query('//table[@data-rt-desktop-flow="outlook-desktop-flow-v12"]')->item(0);
        $main = $xpath->query('./tbody/tr[1]/td', $root)->item(0);
        $columns = $xpath->query('./table', $main);
        self::assertCount(4, $columns);
        foreach ([176, 204, 172, 164] as $index => $width) {
            self::assertSame((string) $width, $columns->item($index)->getAttribute('width'));
            self::assertSame('left', $columns->item($index)->getAttribute('align'));
        }
        self::assertSame(1, $xpath->query('./br[@clear="all"]', $main)->length);
        self::assertSame(1, $xpath->query('./tbody/tr[2]/td/table[@width="218"]//img[@width="200"][@height="43"]', $root)->length);
        self::assertStringNotContainsString('border-left:3px solid #e90032!important;', $html);
        self::assertSame(2, $xpath->query('//table[contains(@class,"rt-managed-signature-v1")]/tbody/tr/td[contains(@style,"border-left:3px solid #e90032;")]')->length);
        self::assertLessThanOrEqual(30000, intdiv(strlen(mb_convert_encoding($html, 'UTF-16LE', 'UTF-8')), 2) + 166);
    }

    public function test_unknown_content_and_authored_signature_selectors_remain_byte_exact(): void
    {
        $unknown = '<table width="100%"><tbody><tr><td>External layout</td></tr></tbody></table>';
        self::assertSame($unknown, OutlookContentFramePresentation::document($unknown));
        $historical = $this->historicalSignature($this->fixture())['signature']['html'];
        $authored = '<style data-rt-mail-document-css="signature">.custom{color:purple}</style>'.$historical;
        self::assertSame($authored, OutlookContentFramePresentation::document($authored));
        $value = '<p title="rt-v11-identity">rt-v11-identity</p>';
        $current = OutlookContentFramePresentation::document($historical);
        self::assertSame($value.$current, OutlookContentFramePresentation::document($value.$current));
    }

    public function test_current_family_rejects_a_lost_percentage_root_instead_of_acknowledging_it_as_responsive(): void
    {
        $html = OutlookClientPresentation::payload(app(OutlookAddinPayloadService::class)->forUser($this->fixture()))['signature']['html'];
        $html = preg_replace_callback('~<table\b(?=[^>]*data-rt-desktop-flow="outlook-desktop-flow-v12")[^>]*>~', static fn (array $tag): string => str_replace(' width="100%"', '', $tag[0]), $html, 1, $changed);
        self::assertSame(1, $changed);
        $this->expectException(RuntimeException::class);
        OutlookContentFramePresentation::document($html);
    }

    public function test_physical_mobile_compose_promotes_the_owned_minified_percentage_host(): void
    {
        $canonical = app(OutlookAddinPayloadService::class)->forUser($this->fixture());
        $result = OutlookClientPresentation::payload(OutlookMobileSignature::payload($canonical));
        $checked = 0;
        foreach ($result['templates'] as $entry) {
            if (! isset($entry['mobileComposeHtml'])) {
                continue;
            }
            $xpath = $this->xpath($entry['mobileComposeHtml']);
            $root = $xpath->query('//table[@data-rt-desktop-flow="outlook-desktop-flow-v12"]')->item(0);
            self::assertNotNull($root);
            self::assertStringContainsString('rt-sign-content-frame', $root->getAttribute('class'));
            self::assertSame('bounded-img-v2', $root->getAttribute('data-rt-train-delivery'));
            self::assertSame('100%', $root->getAttribute('width'));
            self::assertSame(4, $xpath->query('./tbody/tr[1]/td/table', $root)->length);
            self::assertSame(0, $xpath->query('//table[@data-rt-desktop-flow="outlook-desktop-flow-v11"]')->length);
            self::assertSame($entry['mobileComposeHtml'], OutlookContentFramePresentation::document($entry['mobileComposeHtml']));
            self::assertLessThanOrEqual(30000, intdiv(strlen(mb_convert_encoding($entry['mobileComposeHtml'], 'UTF-16LE', 'UTF-8')), 2));
            $checked++;
        }
        self::assertGreaterThan(0, $checked);
    }

    public function test_unmarked_lookalike_parent_cannot_use_the_minified_host_admission(): void
    {
        $historical = $this->historicalSignature($this->fixture())['signature']['html'];
        $bad = str_replace('data-rt-train-delivery="bounded-img-v2"', 'data-rt-train-delivery="unowned"', $historical, $changed);
        self::assertSame(1, $changed);
        $this->expectException(RuntimeException::class);
        OutlookContentFramePresentation::document($bad);
    }

    private function historicalSignature(User $user): array
    {
        $payload = app(OutlookAddinPayloadService::class)->forUser($user);
        $payload['templates'] = [];
        unset($payload['template']);
        $payload['signature']['html'] = (new ReflectionMethod(OutlookClientPresentation::class, 'document'))->invoke(null, $payload['signature']['html'], 30000, 12288, true);

        return OutlookFluidSignaturePresentation::payload(OutlookThreeCellPresentation::payload(OutlookThreeColumnPresentation::payload(OutlookIntrinsicTrainDelivery::payload($payload))));
    }

    private function fixture(): User
    {
        $case = new OutlookFluidSignaturePresentationTest('test_portable_source_fits_the_measured_226_pixel_host_without_a_stylesheet');

        return (new ReflectionMethod($case, 'fixture'))->invoke($case);
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
