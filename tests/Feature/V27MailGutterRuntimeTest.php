<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Mail\TrustedEmailCss;
use App\Support\Mail\TrustedOutlookSignatureCss;
use App\Support\OutlookAddin\OutlookMobileSignature;
use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\Http;
use Symfony\Component\CssSelector\CssSelectorConverter;
use Tests\TestCase;

final class V27MailGutterRuntimeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        self::assertSame(':memory:', config('database.connections.sqlite.database'));
        Http::preventStrayRequests();
    }

    public function test_runtime_gutter_only_matches_direct_v27_template_and_outer_opted_in_hotline_cells(): void
    {
        $runtime = TrustedEmailCss::responsive('#dfe3e6');
        preg_match_all('~([^{}]*design-v27[^{}]*)\{([^{}]*)\}~', $runtime, $rules, PREG_SET_ORDER);
        self::assertCount(2, $rules);
        self::assertSame('padding-left:40px!important;padding-right:40px!important;', preg_replace('/\s+/', '', $rules[0][2]));
        self::assertSame('padding-left:22px!important;padding-right:22px!important;', preg_replace('/\s+/', '', $rules[1][2]));
        $selectors = trim($rules[0][1]);
        $dom = new DOMDocument;
        $dom->loadHTML('<html><body>'
            .'<table class="rt-shell design-v27"><tbody><tr><td id="template" class="rt-pad"><table><tbody><tr><td id="nested" class="rt-pad">Nested card</td></tr></tbody></table></td></tr></tbody></table>'
            .'<table class="rt-shell design-v27"><tr><td id="direct" class="rt-pad">Direct row</td></tr></table>'
            .'<table class="rt-shell design-v27"><tr><td id="native" class="rt-pad rt-native-template-pad">Native projection</td></tr></table>'
            .'<table class="rt-shell design-other"><tr><td id="legacy" class="rt-pad">Other design</td></tr></table>'
            .'<table><tr data-rt-artifact-version="v27"><td class="rt-sign-cell"><table><tbody><tr><td id="hotline" class="design-hotline-row"><table><tr><td id="action">Action</td></tr></table></td></tr></tbody></table>'
            .'<div class="rt-sign-stage"><table><tr><td id="signature" class="rt-pad rt-sign-content">Signature</td></tr></table></div></td></tr></table>'
            .'</body></html>');
        $matches = (new DOMXPath($dom))->query((new CssSelectorConverter)->toXPath($selectors));
        $ids = [];
        foreach ($matches as $element) {
            $ids[] = $element->getAttribute('id');
        }
        sort($ids);
        self::assertSame(['direct', 'hotline', 'template'], $ids, 'Nested cards, signature internals and native/other layouts retain their own padding.');
        Http::assertNothingSent();
    }

    public function test_native_scope_keeps_its_existing_gutter_ownership_and_the_hotline_rule_stays_signature_scoped(): void
    {
        $signature = trim(file_get_contents(base_path('tests/Fixtures/mail/signature-v27-ledger.html')));
        $signature = str_replace('<div class="rt-sign-stage"', '<table><tbody><tr><td class="design-hotline-row" style="padding:6px 40px;">24/7</td></tr></tbody></table><div class="rt-sign-stage"', $signature);
        $html = '<html><body><table class="rt-shell design-v27"><tbody><tr><td class="rt-pad">Message</td></tr>'.$signature.'</tbody></table></body></html>';
        $runtime = TrustedEmailCss::forDocument($html, '#dfe3e6');
        self::assertStringContainsString('td.rt-pad:not(.rt-native-template-pad)', $runtime);
        $native = TrustedOutlookSignatureCss::composeStylesheet($html, [$runtime], 'rtt012345abcdef', '#dfe3e6');
        // Native template cells receive their generated class after this pass.
        // The filter must omit this normal-document override completely: the
        // physical mobile compiler supports a deliberately smaller CSS grammar.
        self::assertStringNotContainsString(':not(.rt-native-template-pad)', $native);
        self::assertStringNotContainsString('design-v27', $native);
        self::assertStringContainsString('.rtt012345abcdef ', $native);
        self::assertStringContainsString('td.design-hotline-row{padding-left:40px!important;padding-right:40px!important;}', $native);
        self::assertStringContainsString('td.design-hotline-row{padding-left:22px!important;padding-right:22px!important;}', $native);
        Http::assertNothingSent();
    }

    public function test_physical_mobile_changes_only_the_outer_hotline_sides_and_preserves_its_vertical_padding_and_actions(): void
    {
        $rows = trim(file_get_contents(base_path('tests/Fixtures/mail/signature-v27-ledger.html')));
        $hotline = '<table><tbody><tr><td id="hotline" class="design-hotline-row" style="padding:6px 40px;"><table><tr>'
            .'<td>24/7 Hotline</td><td id="action" width="26" style="width:26px;padding:9px;"><a href="mailto:hotline@example.test"><img src="cid:hotline.png" width="26" style="display:block;width:26px;height:auto;"></a></td>'
            .'</tr></table></td></tr></tbody></table>'
            .'<table><tr><td id="ordinary" style="padding:6px 40px;">Ordinary content</td></tr></table>';
        $rows = str_replace('<div class="rt-sign-stage"', $hotline.'<div class="rt-sign-stage"', $rows);
        $rows = preg_replace_callback('/\{\{([A-Z0-9_]+)\}\}/', static fn ($match) => str_ends_with($match[1], '_SRC') ? 'cid:fixture.png' : 'Fixture', $rows);
        $html = '<!-- RT-SIGNATURE-VERSION:0123456789abcdef --><span style="display:none">RT-SIGNATURE-VERSION:0123456789abcdef</span>'
            .TrustedOutlookSignatureCss::style($rows, scopeClass: 'rts0123456789')
            .'<div class="rt-outlook-signature rts0123456789"><table><tbody>'.$rows.'</tbody></table></div>';
        $payload = ['signature' => ['html' => $html, 'media' => [['contentId' => 'hotline.png']]], 'templates' => [], 'version' => ['signature' => '0123456789abcdef']];
        $mobile = OutlookMobileSignature::payload($payload);
        self::assertSame($payload['signature']['media'], $mobile['signature']['media']);
        $dom = new DOMDocument;
        $dom->loadHTML($mobile['signature']['html']);
        $xpath = new DOMXPath($dom);
        $outer = $xpath->query('//td[@id="hotline"]')->item(0);
        self::assertStringContainsString('padding:6px 40px', $outer->getAttribute('style'));
        self::assertStringContainsString('padding-left:18px;padding-right:18px', $outer->getAttribute('style'));
        self::assertSame('width:26px;padding:9px;', $xpath->query('//td[@id="action"]')->item(0)->getAttribute('style'));
        self::assertSame('padding:6px 40px;', $xpath->query('//td[@id="ordinary"]')->item(0)->getAttribute('style'));
        $image = $xpath->query('//a[@href="mailto:hotline@example.test"]/img')->item(0);
        self::assertSame('cid:hotline.png', $image->getAttribute('src'));
        self::assertSame('26', $image->getAttribute('width'));
        Http::assertNothingSent();
    }
}
