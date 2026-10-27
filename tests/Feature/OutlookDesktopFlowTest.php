<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Mail\OutlookSignatureInlineStyle;
use App\Support\Mail\SignaturePersonalColumns;
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
use Symfony\Component\CssSelector\CssSelectorConverter;
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
        $train = $xpath->query('//table[contains(concat(" ",normalize-space(@class)," ")," rt-df-train ")]')->item(0);
        self::assertStringContainsString('max-width:400px;', $train->getAttribute('style'));
        self::assertStringContainsString('padding:0 40px 0 0;', $xpath->query('./tbody/tr/td|./tr/td', $train)->item(0)->getAttribute('style'));
        self::assertStringContainsString('width:100%;max-width:100%;height:auto;', $xpath->query('.//img[contains(concat(" ",normalize-space(@class)," ")," rt-delivery-train ")]', $train)->item(0)->getAttribute('style'));
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

    public function test_personal_v5_has_one_logo_and_natural_narrow_reading_order_without_a_layout_copy(): void
    {
        $canonical = $this->document(personal: true);
        $html = OutlookDesktopFlow::project($canonical);
        self::assertSame($html, OutlookDesktopFlow::project($html));
        SignaturePersonalColumns::assertRuntime($html);
        self::assertSame($this->inventory($canonical), $this->inventory($html));
        $xpath = $this->xpath($html);
        $groups = $xpath->query('//table[@data-rt-desktop-flow="outlook-desktop-flow-v5"]/tbody/tr/td/table');
        self::assertCount(4, $groups);
        foreach (['rt-personal-logo-column', 'rt-personal-left', 'rt-personal-right', 'rt-df-train'] as $index => $class) {
            self::assertStringContainsString($class, $groups->item($index)->getAttribute('class'));
            self::assertStringContainsString('display:'.($index === 3 ? 'inline-table' : 'inline-block').';', $groups->item($index)->getAttribute('style'));
            self::assertStringContainsString('width:100%;min-width:0;max-width:340px;', $groups->item($index)->getAttribute('style'));
            self::assertDoesNotMatchRegularExpression('/\b(?:min|max|calc)\s*\(/i', $groups->item($index)->getAttribute('style'));
            self::assertDoesNotMatchRegularExpression('/(?:^|;)\s*(?:float|clear|position|top)\s*:/i', $groups->item($index)->getAttribute('style'));
            self::assertSame('ltr', $groups->item($index)->getAttribute('dir'));
            self::assertStringContainsString('border-right:40px solid transparent;', $groups->item($index)->getAttribute('style'));
        }
        self::assertSame(1, $xpath->query('//img[contains(concat(" ",normalize-space(@class)," ")," rt-logo ")]')->length);
        self::assertSame(1, $xpath->query('.//img[contains(concat(" ",normalize-space(@class)," ")," rt-logo ")]', $groups->item(0))->length);
        self::assertStringContainsString('@media(max-width:680px){', $html);
        self::assertMatchesRegularExpression('/\.(rt-df[0-9]+[0-9a-f]{10}) \.rt-personal-cell-logo,\.\1 \.rt-personal-cell-left,\.\1 \.rt-personal-cell-right\{padding:0!important;/', $html);
        preg_match_all('~<style\b[^>]*>(.*?)</style\s*>~is', $html, $styles);
        self::assertDoesNotMatchRegularExpression('/>\s*(?:tbody|tr|td)\b/', implode('', $styles[1]), 'Outlook send/receive rewriting must not broaden child-selector rules into global element styles.');
        self::assertStringContainsString('border-left-width:18px!important;border-right-width:18px!important;', $html);
        self::assertStringContainsString('margin-bottom:10px!important;', $html);
        self::assertStringContainsString('margin-bottom:10px;', $groups->item(0)->getAttribute('style'));
        self::assertStringContainsString('border-top:4px solid transparent;', $groups->item(2)->getAttribute('style'));
        self::assertStringContainsString('rt-personal-company-contacts" style="padding-top:0;"', $html);
        self::assertStringNotContainsString('.rt-personal-company-contacts{padding-top:14px!important;}', $html);
        self::assertStringContainsString('.rt-personal-direct{padding-top:14px!important;}', $html);
        self::assertStringContainsString('.rt-df-train{display:inline-table!important;vertical-align:bottom!important;border-left:0!important;}', $html);
        self::assertStringContainsString('width:520px;max-width:none!important;height:auto;', $html);
        $this->assertBudget($html, 12288, 30000);
        $styleless = $this->xpath(preg_replace('~<style\b[^>]*>.*?</style\s*>~is', '', $html));
        $stylelessFlow = $styleless->query('//table[@data-rt-desktop-flow="outlook-desktop-flow-v5"]')->item(0);
        self::assertSame('rtl', $stylelessFlow->getAttribute('dir'));
        self::assertSame('rtl', $styleless->query('./tbody/tr/td', $stylelessFlow)->item(0)->getAttribute('dir'));
        self::assertStringContainsString('max-width:680px;', $stylelessFlow->getAttribute('style'));
        self::assertStringContainsString('text-align:left;', $stylelessFlow->getAttribute('style'));
        self::assertSame(4, $styleless->query('./tbody/tr/td/table', $stylelessFlow)->length);
        self::assertSame($this->inventory($html), $this->inventory($styleless->document->saveHTML()));

        $malformed = str_replace('class="rt-personal-cell-logo"', 'class="rt-personal-cell-logo" data-fixture="1"', $html);
        $malformed = preg_replace('~(<td class="rt-personal-cell-logo"[^>]*>)~', '$1<p>Unexpected text</p>', $malformed, 1);
        $this->expectException(RuntimeException::class);
        OutlookDesktopFlow::project($malformed);
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
        $this->assertOfficeSelectors($wire['templates'][0]['combinedComposeHtml']);
    }

    public function test_office_single_class_prefixing_and_inline_removal_keep_root_styles_and_all_critical_aliases_matching(): void
    {
        $wire = OutlookClientPresentation::payload($this->payload());
        $this->assertOfficeSelectors($wire['signature']['html']);
    }

    public function test_generated_internal_css_preserves_geometry_density_and_normal_word_wrap_when_inline_styles_are_removed(): void
    {
        $wire = OutlookClientPresentation::payload($this->payload())['signature']['html'];
        $noInline = preg_replace_callback('~<!--.*?-->|<[a-z][a-z0-9:-]*(?:[^"\'<>]|"[^"]*"|\'[^\']*\')*>~is', static fn (array $match): string => str_starts_with($match[0], '<!--') ? $match[0] : preg_replace('~\sstyle=(?:"[^"]*"|\'[^\']*\')~i', '', $match[0]), $wire);
        self::assertStringContainsString('display:inline-table!important;', $noInline);
        self::assertStringContainsString('.rt-df-brand{width:40%!important;min-width:220px!important;min-width:min(220px,100%)!important;max-width:280px!important;}', $noInline);
        self::assertStringContainsString('.rt-df-contacts{width:60%!important;min-width:280px!important;min-width:min(280px,100%)!important;max-width:600px!important;}', $noInline);
        self::assertStringContainsString('.rt-df-train{max-width:400px!important;}', $noInline);
        self::assertStringContainsString('max-width:600px!important;', $noInline);
        self::assertStringContainsString('max-width:280px!important;', $noInline);
        self::assertStringContainsString('padding:0 40px 0 0!important;border:0!important;', $noInline);
        self::assertStringContainsString('.rt-dfc-company{padding:0 40px 0 22px!important;}', $noInline);
        self::assertMatchesRegularExpression('/\.rt-df40123456789 \.rt-sign-stage \.rt-sign-content-frame td\.oi[0-9a-z]+\{[^{}]*line-height:18px!important;[^{}]*word-break:normal!important;/', $noInline);
        self::assertStringContainsString('data-rt-desktop-flow-mso-css="1"', $noInline);
        self::assertStringContainsString('display:table!important;width:100%!important;min-width:0!important;max-width:none!important;', $noInline);
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

    private function document(bool $profile = true, bool $personal = false): string
    {
        $rows = trim(file_get_contents(base_path('tests/Fixtures/mail/signature-v27-ledger.html')));
        if ($profile) {
            $rows = str_replace('rt-sign-layout rt-sign-ledger', 'rt-sign-layout rt-sign-ledger design-train-logo', $rows);
        }
        if ($personal) {
            $rows = str_replace('design-train-logo', 'design-train-logo design-personal-columns', $rows);
            $rows = preg_replace('~(<img class="rt-logo"[^>]*>\s*<!--\[if mso\]>.*?<!\[endif\]-->)~s', '<table role="presentation" width="180" border="0" cellspacing="0" cellpadding="0" style="width:180px;max-width:100%;table-layout:fixed;"><tbody><tr><td style="padding:0;font-size:0;line-height:0;">$1</td></tr></tbody></table>', $rows, 1, $logoCount);
            self::assertSame(1, $logoCount);
        }
        $rows = preg_replace_callback('/\{\{([A-Z0-9_]+)\}\}/', static fn ($match) => match ($match[1]) {
            'TRAIN_SRC' => 'cid:train.gif', 'LOGO_SRC' => 'cid:logo.gif', 'LOGO_STILL_SRC' => 'cid:logo.png',
            'E_MAIL', 'FIRMEN_EMAIL' => 'fixture@example.test', 'FIRMEN_WEBSITE_HREF' => 'https://example.test',
            default => str_ends_with($match[1], '_SRC') ? 'cid:icon.png' : 'Fixture',
        }, $rows);
        $rows = SignatureTableOverlapDelivery::project($rows, 'cid:train-still.png');
        $rows = SignaturePersonalColumns::project($rows);
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

    private function assertOfficeSelectors(string $html): void
    {
        $xpath = $this->xpath($html);
        $root = $xpath->query('//div[contains(concat(" ",normalize-space(@class)," ")," rt-outlook-signature ")]')->item(0);
        self::assertNotNull($root);
        $main = $xpath->query('./style[@data-rt-desktop-flow-css="1"]', $root);
        self::assertSame(1, $main->length, 'The new stylesheet must survive removal of every stylesheet outside the native signature root.');
        $mso = $xpath->query('./comment()[contains(.,"data-rt-desktop-flow-mso-css")]', $root);
        self::assertSame(1, $mso->length);
        $styles = $main->item(0)->textContent;
        foreach ($xpath->query('//style[@data-rt-outlook-signature-inline-css="1"]') as $style) {
            preg_match_all('~([^{}]+)\{([^{}]*)\}~', $style->textContent, $rules, PREG_SET_ORDER);
            foreach ($rules as $rule) {
                if (str_contains($rule[1], ' .rt-sign-stage .rt-sign-content-frame ')) {
                    $styles .= $rule[0];
                }
            }
        }
        preg_match_all('~([^{}]+)\{([^{}]*)\}~', $styles, $rules, PREG_SET_ORDER);
        $converter = new CssSelectorConverter;
        foreach ($rules as $rule) {
            self::assertGreaterThan(0, $xpath->query($converter->toXPath($rule[1]))->length, $rule[1].' must match before Office changes classes.');
        }
        $train = $xpath->query('//td[contains(concat(" ",normalize-space(@class)," ")," rt-delivery-train-cell ")]')->item(0);
        self::assertNotNull($train);
        preg_match('/(?:^|\s)(oi[0-9a-z]+)(?=\s|$)/', $train->getAttribute('class'), $trainAlias);
        self::assertArrayHasKey(1, $trainAlias);
        foreach ($xpath->query('//*[@style]') as $element) {
            $element->removeAttribute('style');
        }
        foreach ($xpath->query('//*[@class]') as $element) {
            $element->setAttribute('class', implode(' ', array_map(static fn (string $class): string => 'x_'.$class, preg_split('/\s+/', trim($element->getAttribute('class'))))));
        }
        $critical = 0;
        $trainBorder = false;
        foreach ($rules as $rule) {
            // Emulate the real host: every HTML class changes, but only the
            // first class reference in each CSS compound gets its prefix.
            $compounds = preg_split('/([\s>+~,]+)/', $rule[1], -1, PREG_SPLIT_DELIM_CAPTURE);
            foreach ($compounds as &$compound) {
                self::assertLessThanOrEqual(1, preg_match_all('/\.[a-z_][a-z0-9_-]*/i', $compound), 'Multiple classes in one compound are not rewritten consistently by Outlook.');
                $compound = preg_replace('/\.([a-z_][a-z0-9_-]*)/i', '.x_$1', $compound, 1);
            }
            unset($compound);
            $selector = implode('', $compounds);
            self::assertGreaterThan(0, $xpath->query($converter->toXPath($selector))->length, $selector.' must still match after Office prefixing and inline removal.');
            if (str_contains($rule[1], ' .rt-sign-stage .rt-sign-content-frame ')) {
                $critical++;
                self::assertSame(4, preg_match_all('/\.[a-z_][a-z0-9_-]*/i', $rule[1]), 'Four separate classes outrank the old runtime maximum of three classes plus a type.');
            }
            if (str_ends_with($rule[1], 'td.'.$trainAlias[1])) {
                self::assertStringContainsString('border:0!important;', $rule[2]);
                self::assertTrue($xpath->query($converter->toXPath($selector))->item(0)->isSameNode($train));
                $trainBorder = true;
            }
        }
        self::assertGreaterThan(5, $critical);
        self::assertTrue($trainBorder, 'The old matching border-right:1px runtime must lose to the matching border:0 override.');
    }
}
