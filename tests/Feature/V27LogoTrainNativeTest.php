<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Mail\EmailHtmlSanitizer;
use App\Support\Mail\OutlookSignatureInlineStyle;
use App\Support\Mail\SignatureDocumentContract;
use App\Support\Mail\SignatureTableOverlapDelivery;
use App\Support\Mail\TrustedOutlookSignatureCss;
use App\Support\OutlookAddin\OutlookClientPresentation;
use App\Support\OutlookAddin\OutlookCombinedComposeDocument;
use App\Support\OutlookAddin\OutlookMobileSignature;
use App\Support\OutlookAddin\OutlookNativeMetadataPlacement;
use App\Support\OutlookAddin\OutlookNativePersonalSignature;
use App\Support\OutlookAddin\OutlookTrainBottomOverlay;
use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

final class V27LogoTrainNativeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        self::assertSame(':memory:', config('database.connections.sqlite.database'));
        Http::preventStrayRequests();
    }

    public function test_named_profile_survives_normal_source_validation_without_changing_train_binding(): void
    {
        $source = $this->source();
        SignatureDocumentContract::assertValid($source);
        $report = (new EmailHtmlSanitizer)->sanitize($source);
        self::assertFalse($report->hasViolations());
        SignatureDocumentContract::assertValid($report->html);
        self::assertTrue(SignatureTableOverlapDelivery::usesLogoTrain($report->html));
        self::assertSame(1, substr_count($report->html, '{{TRAIN_SRC}}'));
        self::assertStringContainsString('width="6031.746032%"', $report->html);
        self::assertFalse(SignatureTableOverlapDelivery::usesLogoTrain($this->source(false)));
        Http::assertNothingSent();
    }

    public function test_native_profile_keeps_canonical_brand_train_and_default_personal_overlay_remains_unchanged(): void
    {
        $profile = $this->document();
        self::assertSame($profile, OutlookNativePersonalSignature::project($profile));
        self::assertSame($profile, OutlookTrainBottomOverlay::project($profile));
        self::assertSame($profile, OutlookNativePersonalSignature::restore(OutlookTrainBottomOverlay::restore($profile)));
        SignatureTableOverlapDelivery::assertRuntime($profile);
        $xpath = $this->xpath($profile);
        $brand = $xpath->query('//th[contains(@class,"rt-ledger-brand")]')->item(0);
        self::assertSame('50%', $brand->getAttribute('width'));
        self::assertSame(1, $xpath->query('.//img[contains(@class,"rt-delivery-train")]', $brand)->length);
        self::assertStringNotContainsString('data-rt-train-bottom-overlay=', $profile);
        self::assertStringNotContainsString('data-rt-personal-layout=', $profile);
        $default = $this->document(false);
        $personal = OutlookNativePersonalSignature::project($default);
        $overlay = OutlookTrainBottomOverlay::project($personal);
        self::assertStringContainsString('data-rt-personal-layout=', $personal);
        self::assertStringContainsString('data-rt-train-bottom-overlay="intrinsic-img-v1"', $overlay);
        self::assertSame($default, OutlookNativePersonalSignature::restore(OutlookTrainBottomOverlay::restore($overlay)));
        Http::assertNothingSent();
    }

    public function test_mobile_profile_stacks_real_cells_and_preserves_paired_images_word_branch_media_and_budget(): void
    {
        $document = ['html' => $this->document(), 'media' => [['contentId' => 'train.gif', 'base64' => 'fixture']]];
        $payload = ['signature' => $document, 'templates' => [['signature' => $document, 'signatureVersion' => '0123456789abcdef']], 'version' => ['signature' => '0123456789abcdef']];
        $before = $payload;
        $mobile = OutlookMobileSignature::payload($payload);
        self::assertSame($before, $payload);
        self::assertSame($document['media'], $mobile['signature']['media']);
        self::assertSame($mobile['signature'], $mobile['templates'][0]['signature']);
        self::assertSame($this->wordTrain($document['html']), $this->wordTrain($mobile['signature']['html']));
        self::assertStringNotContainsString('data-rt-train-bottom-overlay=', $mobile['signature']['html']);
        $xpath = $this->xpath($mobile['signature']['html']);
        foreach (['rt-ledger-brand', 'rt-ledger-contacts', 'rt-ledger-direct', 'rt-ledger-company'] as $class) {
            $cell = $xpath->query('//td[contains(concat(" ",normalize-space(@class)," ")," '.$class.' ")]')->item(0);
            self::assertNotNull($cell);
            self::assertSame('100%', $cell->getAttribute('width'));
            self::assertSame(1, $xpath->query('./td', $cell->parentNode)->length);
        }
        self::assertSame(1, $xpath->query('//td[contains(@class,"rt-ledger-brand")]//img[contains(@class,"rt-delivery-train")]')->length);
        foreach ([$payload, $mobile] as $device) {
            $wire = OutlookClientPresentation::payload($device);
            preg_match_all('~<style\b[^>]*>(.*?)</style>~is', $wire['signature']['html'], $styles);
            self::assertLessThan(12288, array_sum(array_map('strlen', $styles[1])));
            self::assertLessThanOrEqual(30000, intdiv(strlen(mb_convert_encoding($wire['signature']['html'], 'UTF-16LE', 'UTF-8')), 2));
            self::assertSame($document['media'], $wire['signature']['media']);
        }
        Http::assertNothingSent();
    }

    public function test_profile_is_accepted_by_combined_compose_without_reintroducing_an_overlay(): void
    {
        $scope = 'rtt012345abcdef';
        $declarations = 'width:100%;border-collapse:separate;border-spacing:0;table-layout:fixed;box-sizing:border-box;background-color:#ffffff;border-left:6px solid #e90032;';
        $template = '<style data-rt-outlook-template-css="1">.'.$scope.' .rt-native-compose-frame{'.str_replace(';', '!important;', $declarations).'}</style>'
            .'<div class="rt-outlook-template '.$scope.'"><table class="rt-native-compose-frame" role="presentation" width="100%" cellspacing="0" cellpadding="0" style="'.$declarations.'"><tbody><tr><td class="rt-native-template-mark" width="44">'
            .'<!-- RT-TEMPLATE-MANAGED-V1:NATIVE-SIGNATURE --><span hidden aria-hidden="true" class="rt-office-metadata" data-rt-template-signature-mode="native">RT-TEMPLATE-MANAGED-V1:NATIVE-SIGNATURE</span>'
            .'</td></tr></tbody></table></div>';
        $signature = $this->document();
        $output = OutlookCombinedComposeDocument::build($template, $signature);
        self::assertStringContainsString('data-rt-compose-document="combined-v1"', $output);
        self::assertStringContainsString('design-train-logo', $output);
        self::assertSame($this->wordTrain($signature), $this->wordTrain($output));
        self::assertSame(1, $this->xpath($output)->query('//th[contains(@class,"rt-ledger-brand")]//img[contains(@class,"rt-delivery-train")]')->length);
        self::assertStringNotContainsString('data-rt-train-bottom-overlay=', $output);
        Http::assertNothingSent();
    }

    public function test_opt_in_does_not_bypass_canonical_train_validation(): void
    {
        $html = preg_replace('~(<img\b(?=[^>]*\bsrc="cid:train.gif")[^>]*\bwidth=")300(")~', '${1}301$2', $this->document());
        self::assertStringContainsString('width="301"', $html);
        $this->expectException(RuntimeException::class);
        OutlookNativePersonalSignature::project($html);
    }

    private function source(bool $profile = true): string
    {
        $html = trim(file_get_contents(base_path('tests/Fixtures/mail/signature-v27-ledger.html')));

        return $profile ? str_replace('rt-sign-layout rt-sign-ledger', 'rt-sign-layout rt-sign-ledger design-train-logo', $html) : $html;
    }

    private function document(bool $profile = true): string
    {
        $rows = preg_replace_callback('/\{\{([A-Z0-9_]+)\}\}/', static fn ($match) => match ($match[1]) {
            'TRAIN_SRC' => 'cid:train.gif',
            'LOGO_SRC' => 'cid:logo.gif',
            'LOGO_STILL_SRC' => 'cid:logo.png',
            'E_MAIL', 'FIRMEN_EMAIL' => 'fixture@example.test',
            'FIRMEN_WEBSITE_HREF' => 'https://example.test',
            default => str_ends_with($match[1], '_SRC') ? 'cid:icon.png' : 'Fixture',
        }, $this->source($profile));
        $rows = SignatureTableOverlapDelivery::project($rows, 'cid:train-still.png');
        $html = TrustedOutlookSignatureCss::style($rows, scopeClass: 'rts0123456789')
            .'<div class="rt-outlook-signature rts0123456789"><table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="width:100%;border-left:6px solid #e90032;"><tbody>'.$rows.'</tbody></table></div>';
        $html = OutlookSignatureInlineStyle::apply($html, 'rts0123456789');
        foreach (['RT-SIGNATURE-MANAGED-V1', 'RT-SIGNATURE-VERSION:0123456789abcdef'] as $marker) {
            $html = OutlookNativeMetadataPlacement::signature($html, '<style data-rt-outlook-marker-css="1">.rt-office-metadata{display:none!important;}</style><!-- '.$marker.' --><span hidden aria-hidden="true" class="rt-office-metadata" style="display:none">'.$marker.'</span>');
        }

        return $html;
    }

    private function xpath(string $html): DOMXPath
    {
        $dom = new DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$html);

        return new DOMXPath($dom);
    }

    private function wordTrain(string $html): string
    {
        self::assertSame(1, preg_match('~<!--\[if mso\]><img class="rt-delivery-train-mso"[^>]*><!\[endif\]-->~', $html, $match));

        return $match[0];
    }
}
