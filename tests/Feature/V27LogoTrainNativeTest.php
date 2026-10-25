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
use App\Support\OutlookAddin\OutlookDesktopFlow;
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

    public function test_native_profile_has_desktop_contact_columns_and_a_final_train_row_while_default_personal_overlay_is_unchanged(): void
    {
        $profile = $this->document();
        self::assertStringNotContainsString('min-width:1360px', $profile);
        self::assertSame($profile, OutlookNativePersonalSignature::project($profile));
        self::assertSame($profile, OutlookTrainBottomOverlay::project($profile));
        self::assertSame($profile, OutlookNativePersonalSignature::restore(OutlookTrainBottomOverlay::restore($profile)));
        SignatureTableOverlapDelivery::assertRuntime($profile);
        $xpath = $this->xpath($profile);
        $brand = $xpath->query('//th[contains(@class,"rt-ledger-brand")]')->item(0);
        self::assertSame('50%', $brand->getAttribute('width'));
        self::assertStringContainsString('padding:0 40px 0 0;', $brand->getAttribute('style'));
        $content = $xpath->query('//td[contains(@class,"rt-sign-ledger-content")]')->item(0);
        self::assertStringContainsString('padding:25px 0 0;', $content->getAttribute('style'));
        self::assertSame('2', $content->getAttribute('colspan'));
        self::assertStringContainsString('padding:0 62px 0 0;', $xpath->query('//th[contains(@class,"rt-ledger-contacts")]')->item(0)->getAttribute('style'));
        self::assertStringContainsString('padding:0 0 0 40px;', $xpath->query('//td[contains(@class,"rt-delivery-brand-heading")]')->item(0)->getAttribute('style'));
        self::assertSame(0, $xpath->query('.//img[contains(@class,"rt-delivery-train")]', $brand)->length);
        $direct = $xpath->query('//td[contains(@class,"rt-ledger-direct")]')->item(0);
        $company = $xpath->query('//td[contains(@class,"rt-ledger-company")]')->item(0);
        self::assertTrue($direct->parentNode->isSameNode($company->parentNode));
        self::assertSame('50%', $direct->getAttribute('width'));
        self::assertSame('50%', $company->getAttribute('width'));
        $train = $xpath->query('//td[contains(@class,"rt-delivery-train-cell")]')->item(0);
        $gap = $xpath->query('//td[contains(@class,"rt-delivery-train-gap")]')->item(0);
        self::assertSame('50%', $train->getAttribute('width'));
        self::assertSame('50%', $gap->getAttribute('width'));
        self::assertTrue($content->parentNode->nextElementSibling?->isSameNode($train->parentNode));
        self::assertNull($train->parentNode->nextElementSibling);
        self::assertSame(2, $xpath->query('./td', $train->parentNode)->length);
        self::assertStringContainsString('padding:0 40px 0 0;', $train->getAttribute('style'));
        self::assertStringContainsString('width="220" height="28"', $this->wordTrain($profile));
        self::assertStringNotContainsString('data-rt-train-bottom-overlay=', $profile);
        self::assertStringNotContainsString('data-rt-personal-layout=', $profile);
        $default = $this->document(false);
        $personal = OutlookNativePersonalSignature::project($default);
        $overlay = OutlookTrainBottomOverlay::project($personal);
        self::assertStringContainsString('data-rt-personal-layout=', $personal);
        self::assertStringContainsString('data-rt-train-bottom-overlay="intrinsic-img-v1"', $overlay);
        self::assertStringContainsString('width="300" height="38"', $this->wordTrain($default));
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
        self::assertStringNotContainsString('.rts0123456789 .rt-delivery-train-cell{width:100%!important;padding:0 22px 0 0!important;', $mobile['signature']['html']);
        self::assertStringNotContainsString('min-width:1360px', $mobile['signature']['html']);
        self::assertStringNotContainsString('width:28.571429%', $mobile['signature']['html']);
        self::assertStringNotContainsString('width:71.428571%', $mobile['signature']['html']);
        $xpath = $this->xpath($mobile['signature']['html']);
        $ledger = $xpath->query('//table[contains(concat(" ",normalize-space(@class)," ")," rt-sign-ledger ")]')->item(0);
        self::assertSame('100%', $ledger->getAttribute('width'));
        self::assertStringContainsString('width:100%;', $ledger->getAttribute('style'));
        $moving = $xpath->query('//img[contains(concat(" ",normalize-space(@class)," ")," rt-delivery-train ")]')->item(0);
        self::assertStringContainsString('width:100%;max-width:100%;height:auto;', $moving->getAttribute('style'));
        self::assertMatchesRegularExpression('/\\{[^{}]*width:100%!important;max-width:100%!important;height:auto!important;[^{}]*\\}/', $mobile['signature']['html']);
        foreach (['rt-ledger-brand', 'rt-ledger-contacts', 'rt-ledger-direct', 'rt-ledger-company'] as $class) {
            $cell = $xpath->query('//td[contains(concat(" ",normalize-space(@class)," ")," '.$class.' ")]')->item(0);
            self::assertNotNull($cell);
            self::assertSame('100%', $cell->getAttribute('width'));
            self::assertSame(1, $xpath->query('./td', $cell->parentNode)->length);
        }
        self::assertSame(0, $xpath->query('//td[contains(@class,"rt-ledger-brand")]//img[contains(@class,"rt-delivery-train")]')->length);
        $content = $xpath->query('//td[contains(@class,"rt-sign-ledger-content")]')->item(0);
        self::assertStringContainsString('padding:20px 0 0;', $content->getAttribute('style'));
        self::assertFalse($content->hasAttribute('colspan'));
        self::assertSame(0, $xpath->query('//td[contains(@class,"rt-delivery-train-gap")]')->length);
        $train = $xpath->query('//td[contains(@class,"rt-delivery-train-cell")]')->item(0);
        self::assertSame('100%', $train->getAttribute('width'));
        self::assertStringContainsString('padding:0;border:0;font-size:0;line-height:0;', $train->getAttribute('style'));
        self::assertSame(1, $xpath->query('./td', $train->parentNode)->length);
        self::assertTrue($content->parentNode->nextElementSibling?->isSameNode($train->parentNode));
        self::assertNull($train->parentNode->nextElementSibling);
        self::assertStringContainsString('padding:0 18px 0 0;', $xpath->query('//td[contains(@class,"rt-ledger-brand")]')->item(0)->getAttribute('style'));
        self::assertStringContainsString('padding:14px 18px 0;', $xpath->query('//td[contains(@class,"rt-ledger-contacts")]')->item(0)->getAttribute('style'));
        self::assertStringContainsString('padding:0 0 0 18px;', $xpath->query('//td[contains(@class,"rt-delivery-brand-heading")]')->item(0)->getAttribute('style'));
        self::assertStringContainsString('padding:0;', $xpath->query('//td[contains(@class,"rt-ledger-direct")]')->item(0)->getAttribute('style'));
        self::assertStringContainsString('padding:14px 0 0;', $xpath->query('//td[contains(@class,"rt-ledger-company")]')->item(0)->getAttribute('style'));
        foreach ([$payload, $mobile] as $device) {
            $wire = OutlookClientPresentation::payload($device);
            preg_match_all('~<style\b[^>]*>(.*?)</style>~is', $wire['signature']['html'], $styles);
            self::assertLessThan(12288, array_sum(array_map('strlen', $styles[1])));
            self::assertLessThanOrEqual(30000, intdiv(strlen(mb_convert_encoding($wire['signature']['html'], 'UTF-16LE', 'UTF-8')), 2));
            self::assertSame($document['media'], $wire['signature']['media']);
        }
        Http::assertNothingSent();
    }

    public function test_marked_hotline_right_gutter_is_native_runtime_css_while_mobile_keeps_its_physical_gutter(): void
    {
        $source = $this->source(true, true);
        SignatureDocumentContract::assertValid($source);
        $report = (new EmailHtmlSanitizer)->sanitize($source);
        self::assertFalse($report->hasViolations());
        self::assertStringContainsString('design-hotline-spaced', $report->html);
        $payload = ['signature' => ['html' => $this->document(true, true), 'media' => []], 'templates' => [], 'version' => ['signature' => '0123456789abcdef']];
        $wire = OutlookClientPresentation::payload($payload);
        self::assertTrue(OutlookDesktopFlow::projected($wire['signature']['html']));
        $noInline = preg_replace('~\sstyle="[^"]*"~', '', $wire['signature']['html']);
        $rule = '.rts0123456789 td.design-hotline-row{padding-right:24px!important;}';
        self::assertStringContainsString($rule, $noInline);
        self::assertStringContainsString('@media only screen and (max-width:860px){.rts0123456789 td.design-hotline-row{padding-right:22px!important;}}', $noInline);
        self::assertStringNotContainsString('data-rt-mail-document-css=', $noInline);
        $mobile = OutlookMobileSignature::payload($payload);
        $hotline = $this->xpath($mobile['signature']['html'])->query('//td[contains(concat(" ",normalize-space(@class)," ")," design-hotline-spaced ")]')->item(0);
        self::assertStringContainsString('padding-left:18px;padding-right:18px;', $hotline->getAttribute('style'));
        self::assertStringNotContainsString($rule, $mobile['signature']['html']);
        foreach ([$wire, OutlookClientPresentation::payload($mobile)] as $device) {
            preg_match_all('~<style\b[^>]*>(.*?)</style>~is', $device['signature']['html'], $styles);
            self::assertLessThan(12288, array_sum(array_map('strlen', $styles[1])));
            self::assertLessThanOrEqual(30000, intdiv(strlen(mb_convert_encoding($device['signature']['html'], 'UTF-16LE', 'UTF-8')), 2));
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
        self::assertSame(1, $this->xpath($output)->query('//tr[contains(@class,"rt-delivery-train-row")]/td[contains(@class,"rt-delivery-train-cell")]/img[contains(@class,"rt-delivery-train")]')->length);
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

    public function test_mobile_projection_never_discards_content_from_the_reserved_train_gap(): void
    {
        $html = preg_replace('~(<td\b(?=[^>]*\bclass="[^"]*\brt-delivery-train-gap\b)[^>]*>)~', '$1<a href="https://example.test/keep">Keep content</a>', $this->document(), 1, $count);
        self::assertSame(1, $count);
        $payload = ['signature' => ['html' => $html, 'media' => []], 'templates' => [], 'version' => ['signature' => '0123456789abcdef']];
        $this->expectException(RuntimeException::class);
        OutlookMobileSignature::payload($payload);
    }

    private function source(bool $profile = true, bool $spacedHotline = false): string
    {
        $html = trim(file_get_contents(base_path('tests/Fixtures/mail/signature-v27-ledger.html')));
        if ($spacedHotline) {
            $banner = '<table role="presentation"><tr><td class="design-hotline-row design-hotline-spaced" style="padding:3px 40px;padding-right:24px;">Fixture hotline</td></tr></table>';
            $html = str_replace('<div class="rt-sign-stage"', $banner.'<div class="rt-sign-stage"', $html);
        }

        return $profile ? str_replace('rt-sign-layout rt-sign-ledger', 'rt-sign-layout rt-sign-ledger design-train-logo', $html) : $html;
    }

    private function document(bool $profile = true, bool $spacedHotline = false): string
    {
        $rows = preg_replace_callback('/\{\{([A-Z0-9_]+)\}\}/', static fn ($match) => match ($match[1]) {
            'TRAIN_SRC' => 'cid:train.gif',
            'LOGO_SRC' => 'cid:logo.gif',
            'LOGO_STILL_SRC' => 'cid:logo.png',
            'E_MAIL', 'FIRMEN_EMAIL' => 'fixture@example.test',
            'FIRMEN_WEBSITE_HREF' => 'https://example.test',
            default => str_ends_with($match[1], '_SRC') ? 'cid:icon.png' : 'Fixture',
        }, $this->source($profile, $spacedHotline));
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
