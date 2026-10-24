<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Mail\OutlookSignatureInlineStyle;
use App\Support\Mail\SignatureTableOverlapDelivery;
use App\Support\Mail\TrustedOutlookSignatureCss;
use App\Support\OutlookAddin\OutlookClientPresentation;
use App\Support\OutlookAddin\OutlookCombinedComposeDocument;
use App\Support\OutlookAddin\OutlookDesktopFlow;
use App\Support\OutlookAddin\OutlookMobileSignature;
use App\Support\OutlookAddin\OutlookNativeMetadataPlacement;
use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

final class OutlookDesktopFlowTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        self::assertSame(':memory:', config('database.connections.sqlite.database'));
        Http::preventStrayRequests();
    }

    public function test_final_native_flow_is_single_copy_compact_and_does_not_mutate_canonical_payload(): void
    {
        $payload = $this->payload();
        $before = $payload;
        $wire = OutlookClientPresentation::payload($payload);
        self::assertSame($before, $payload);
        self::assertSame($before['signature']['media'], $wire['signature']['media']);
        self::assertTrue(OutlookDesktopFlow::projected($wire['signature']['html']));
        $xpath = $this->xpath($wire['signature']['html']);
        foreach (['brand', 'contacts', 'direct', 'company', 'train'] as $kind) {
            self::assertSame(1, $xpath->query('//table[contains(concat(" ",normalize-space(@class)," ")," rt-df-'.$kind.' ")]')->length);
        }
        self::assertSame(2, $xpath->query('//table[contains(concat(" ",normalize-space(@class)," ")," rt-contact ")]')->length);
        self::assertSame(0, $xpath->query('//td[contains(@class,"rt-delivery-train-gap")]')->length);
        self::assertSame($this->inventory($before['signature']['html']), $this->inventory($wire['signature']['html']));
        self::assertStringContainsString('padding:16px 0 0;', $wire['signature']['html']);
        self::assertStringContainsString('padding:0 0 4px 9px;', $wire['signature']['html']);
        self::assertStringContainsString('line-height:18px;', $wire['signature']['html']);
        self::assertStringContainsString('word-break:normal;', $wire['signature']['html']);
        $this->assertBudget($wire['signature']['html'], 12288, 30000);
        Http::assertNothingSent();
    }

    public function test_mobile_compact_alias_mode_default_and_non_native_documents_are_byte_equal_bypasses(): void
    {
        $mobile = OutlookMobileSignature::payload($this->payload());
        self::assertSame($mobile['signature']['html'], OutlookDesktopFlow::project($mobile['signature']['html']));
        $aliasMobile = '<div data-rt-compose-document="combined-native-v1" class="x10">'.$this->document().'</div>';
        self::assertSame($aliasMobile, OutlookDesktopFlow::project($aliasMobile));
        $default = $this->document(false);
        self::assertSame($default, OutlookDesktopFlow::project($default));
        $nonNative = str_replace('rt-outlook-signature', 'ordinary-signature', $this->document());
        self::assertSame($nonNative, OutlookDesktopFlow::project($nonNative));
        self::assertSame('<p>Ordinary legacy signature</p>', OutlookDesktopFlow::project('<p>Ordinary legacy signature</p>'));
        self::assertSame('<p>Historic unclosed fragment', OutlookDesktopFlow::project('<p>Historic unclosed fragment'));
        $columns = '<table><colgroup><col span="2"></colgroup><tr><td>Legacy columns</td></tr></table>';
        self::assertSame($columns, OutlookDesktopFlow::project($columns));
        self::assertFalse(OutlookDesktopFlow::mobile($columns));
        $mobileColumns = '<div class="rt-mobile-ledger design-train-logo">'.$columns.'</div>';
        self::assertTrue(OutlookDesktopFlow::mobile($mobileColumns));
        self::assertSame($mobileColumns, OutlookDesktopFlow::project($mobileColumns));
        self::assertStringNotContainsString('data-rt-desktop-flow=', OutlookClientPresentation::payload($mobile)['signature']['html']);
    }

    public function test_combined_template_and_signature_roots_keep_their_distinct_scopes_and_shared_version_markers(): void
    {
        $signature = $this->document();
        $declarations = 'width:100%;border-collapse:separate;border-spacing:0;table-layout:fixed;box-sizing:border-box;background-color:#ffffff;border-left:6px solid #e90032;';
        $template = '<style data-rt-outlook-template-css="1">.rtt012345abcdef .rt-native-compose-frame{'.str_replace(';', '!important;', $declarations).'}</style>'
            .'<div class="rt-outlook-template rtt012345abcdef"><table class="rt-native-compose-frame" role="presentation" width="100%" cellspacing="0" cellpadding="0" style="'.$declarations.'"><tbody><tr><td class="rt-native-template-mark" width="44">'
            .'<!-- RT-TEMPLATE-MANAGED-V1:NATIVE-SIGNATURE --><span hidden aria-hidden="true" class="rt-office-metadata" data-rt-template-signature-mode="native">RT-TEMPLATE-MANAGED-V1:NATIVE-SIGNATURE</span>'
            .'</td></tr></tbody></table></div>';
        $combined = OutlookCombinedComposeDocument::build($template, $signature);
        $payload = $this->payload();
        $payload['templates'] = [['combinedComposeHtml' => $combined, 'signature' => $payload['signature'], 'signatureVersion' => '0123456789abcdef']];
        $wire = OutlookClientPresentation::payload($payload);
        self::assertSame($wire['version']['signature'], $wire['templates'][0]['signatureVersion']);
        self::assertSame(2, substr_count($wire['templates'][0]['combinedComposeHtml'], 'RT-SIGNATURE-VERSION:'.$wire['version']['signature']));
        self::assertTrue(OutlookDesktopFlow::projected($wire['templates'][0]['combinedComposeHtml']));
        self::assertSame($this->inventory($combined), $this->inventory($wire['templates'][0]['combinedComposeHtml']));
        self::assertStringContainsString('rt-outlook-template rtt012345abcdef', $wire['templates'][0]['combinedComposeHtml']);
        $this->assertBudget($wire['templates'][0]['combinedComposeHtml'], 24576, 99000);
        $mobile = OutlookClientPresentation::payload(OutlookMobileSignature::payload($payload));
        self::assertStringNotContainsString('data-rt-desktop-flow=', $mobile['templates'][0]['combinedComposeHtml'], 'A mobile request also preserves unused desktop fallback fields.');
    }

    public function test_generated_internal_css_preserves_geometry_density_and_normal_word_wrap_when_inline_styles_are_removed(): void
    {
        $wire = OutlookClientPresentation::payload($this->payload())['signature']['html'];
        $noInline = preg_replace_callback('~<!--.*?-->|<[a-z][a-z0-9:-]*(?:[^"\'<>]|"[^"]*"|\'[^\']*\')*>~is', static fn (array $match): string => str_starts_with($match[0], '<!--') ? $match[0] : preg_replace('~\sstyle=(?:"[^"]*"|\'[^\']*\')~i', '', $match[0]), $wire);
        self::assertStringContainsString('display:inline-table!important;', $noInline);
        self::assertStringContainsString('max-width:400px!important;', $noInline);
        self::assertStringContainsString('max-width:600px!important;', $noInline);
        self::assertStringContainsString('max-width:280px!important;', $noInline);
        self::assertStringContainsString('padding:0 40px 0 0!important;border-right:1px solid #e60033!important;', $noInline);
        self::assertMatchesRegularExpression('/\.rts0123456789\.rt-dflow-host\.rt-dflow-host \.oi[0-9a-z]+\{[^{}]*line-height:18px!important;[^{}]*word-break:normal!important;/', $noInline);
        self::assertStringContainsString('padding:16px 0 0!important;', $noInline);
        self::assertStringContainsString('.rt-dfc{font-size:13px!important;line-height:18px!important;', $noInline);
        self::assertSame($this->inventory($wire), $this->inventory($noInline));
    }

    public function test_projector_is_idempotent_and_preserves_opaque_authored_rules_and_mso_only_targets(): void
    {
        $authored = '<style data-rt-fixture-opaque="1">.other{content:"$1 {; }";color:#123456;}</style>';
        $html = $authored.$this->document();
        $html = preg_replace_callback('~<style data-rt-outlook-signature-css="1">~', static fn (array $match): string => $match[0].'.rt-delivery-train-mso{content:"$1{;}";}', $html, 1);
        $output = OutlookDesktopFlow::project($html);
        self::assertTrue(OutlookDesktopFlow::projected($output));
        self::assertSame($output, OutlookDesktopFlow::project($output));
        self::assertStringContainsString($authored, $output);
        self::assertStringContainsString('.rt-delivery-train-mso{content:"$1{;}";}', $output);
        foreach (['table.rt-sign-ledger{color:#123456;}', 'th{background-color:#123456;}', '[width="50%"]{color:red;}', '.custom{color:red;}', 'th:not(.other){color:red;}'] as $css) {
            $observer = '<style data-rt-mail-document-css="signature">'.$css.'</style>'.$this->document();
            self::assertSame($observer, OutlookDesktopFlow::project($observer));
        }
    }

    public static function invalidInputs(): array
    {
        return ['partial marker' => ['marker'], 'changed canonical width' => ['width'], 'missing owned mirror' => ['mirror'], 'changed owned mirror' => ['declaration'], 'nonempty gap' => ['gap']];
    }

    public function test_unknown_plaintext_or_extra_children_keep_the_complete_original_document(): void
    {
        foreach (['rt-ledger-contacts', 'rt-ledger-brand', 'rt-ledger-direct', 'rt-ledger-company'] as $class) {
            $html = preg_replace_callback('~(<(?:td|th)\b(?=[^>]*\b'.$class.'\b)[^>]*>)(.*?)(</(?:td|th)>)~s', static fn (array $match): string => $match[1].'REVIEW_PLAIN_CONTENT_MUST_SURVIVE'.$match[2].$match[3], $this->document(), 1, $count);
            self::assertSame(1, $count);
            self::assertSame($html, OutlookDesktopFlow::project($html));
        }
        $html = preg_replace_callback('~(<th\b(?=[^>]*\brt-ledger-contacts\b)[^>]*>)(.*?)(</th>)~s', static fn (array $match): string => $match[1].$match[2].'<p>Extra contact note</p>'.$match[3], $this->document(), 1);
        self::assertSame($html, OutlookDesktopFlow::project($html));
        $note = preg_replace_callback('~(<td\b(?=[^>]*\brt-delivery-brand-heading\b)[^>]*>)~', static fn (array $match): string => $match[0].'<p>Visible unformatted brand note</p>', $this->document(), 1);
        $projected = OutlookDesktopFlow::project($note);
        self::assertTrue(OutlookDesktopFlow::projected($projected));
        self::assertSame(1, substr_count($projected, 'Visible unformatted brand note'));
        self::assertStringContainsString('.rt-dfc{font-size:13px!important;line-height:18px!important;', $projected);
    }

    #[DataProvider('invalidInputs')]
    public function test_partial_or_changed_canonical_input_is_rejected_without_losing_content(string $kind): void
    {
        $html = $this->document();
        $html = match ($kind) {
            'marker' => str_replace('rt-sign-stage', 'rt-sign-stage" data-rt-desktop-flow="partial', $html),
            'width' => preg_replace('~(<th\b(?=[^>]*\brt-ledger-brand\b)[^>]*\bwidth=")50%("[^>]*>)~', '${1}49%$2', $html, 1),
            'mirror' => str_replace('data-rt-outlook-signature-inline-css="1"', 'data-rt-missing-mirror="1"', $html),
            'declaration' => str_replace('word-break:break-all;overflow-wrap:anywhere;}', 'word-break:normal;overflow-wrap:anywhere;}', $html),
            'gap' => preg_replace('~(<td\b(?=[^>]*rt-delivery-train-gap)[^>]*>)~', '$1<a href="https://example.test/keep">Keep</a>', $html),
        };
        $this->expectException(RuntimeException::class);
        OutlookDesktopFlow::project($html);
    }

    private function payload(): array
    {
        return ['signature' => ['html' => $this->document(), 'media' => [['contentId' => 'train.gif', 'base64' => 'fixture']]], 'templates' => [], 'version' => ['signature' => '0123456789abcdef']];
    }

    private function document(bool $profile = true): string
    {
        $rows = trim(file_get_contents(base_path('tests/Fixtures/mail/signature-v27-ledger.html')));
        if ($profile) {
            $rows = str_replace('rt-sign-layout rt-sign-ledger', 'rt-sign-layout rt-sign-ledger design-train-logo', $rows);
        }
        $rows = preg_replace_callback('/\{\{([A-Z0-9_]+)\}\}/', static fn ($match) => match ($match[1]) {
            'TRAIN_SRC' => 'cid:train.gif', 'LOGO_SRC' => 'cid:logo.gif', 'LOGO_STILL_SRC' => 'cid:logo.png',
            'E_MAIL', 'FIRMEN_EMAIL' => 'fixture@example.test', 'FIRMEN_WEBSITE_HREF' => 'https://example.test',
            default => str_ends_with($match[1], '_SRC') ? 'cid:icon.png' : 'Fixture',
        }, $rows);
        $rows = SignatureTableOverlapDelivery::project($rows, 'cid:train-still.png');
        $html = TrustedOutlookSignatureCss::style($rows, scopeClass: 'rts0123456789')
            .'<div class="rt-outlook-signature rts0123456789"><table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="width:100%;border-left:6px solid #e90032;"><tbody>'.$rows.'</tbody></table></div>';
        $html = OutlookSignatureInlineStyle::apply($html, 'rts0123456789');
        foreach (['RT-SIGNATURE-MANAGED-V1', 'RT-SIGNATURE-VERSION:0123456789abcdef'] as $marker) {
            $html = OutlookNativeMetadataPlacement::signature($html, '<style data-rt-outlook-marker-css="1">.rt-office-metadata{display:none!important;}</style><!-- '.$marker.' --><span hidden aria-hidden="true" class="rt-office-metadata" style="display:none">'.$marker.'</span>');
        }

        return $html;
    }

    private function inventory(string $html): array
    {
        $xpath = $this->xpath($html);
        $images = [];
        foreach ($xpath->query('//img') as $image) {
            $images[] = array_map(static fn ($attribute) => $image->getAttribute($attribute), ['src', 'width', 'height', 'alt']);
        }
        $links = array_map(static fn ($link) => $link->getAttribute('href'), iterator_to_array($xpath->query('//a')));
        preg_match_all('~<!--\[if mso\]><img\b[^>]*><!\[endif\]-->~', $html, $mso);
        sort($images);
        sort($links);
        sort($mso[0]);

        return [$images, $links, $mso[0]];
    }

    private function xpath(string $html): DOMXPath
    {
        $dom = new DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$html);

        return new DOMXPath($dom);
    }

    private function assertBudget(string $html, int $cssLimit, int $characterLimit): void
    {
        preg_match_all('~<style\b[^>]*>(.*?)</style>~s', $html, $css);
        self::assertLessThan($cssLimit, array_sum(array_map('strlen', $css[1])));
        self::assertLessThanOrEqual($characterLimit, intdiv(strlen(mb_convert_encoding($html, 'UTF-16LE', 'UTF-8')), 2));
    }
}
