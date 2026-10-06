<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\MailDocument;
use App\Support\OutlookAddin\OutlookAddinPayloadService;
use App\Support\OutlookAddin\OutlookCombinedComposeDocument;
use App\Support\OutlookAddin\OutlookMobileCombinedComposeDocument;
use App\Support\OutlookAddin\OutlookMobileSignature;
use DOMDocument;
use DOMXPath;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use RuntimeException;
use Tests\TestCase;

final class OutlookMobileCombinedComposeDocumentTest extends TestCase
{
    public static function people(): array
    {
        return ['personal' => [true], 'generic' => [false]];
    }

    #[DataProvider('people')]
    public function test_actual_builder_payload_has_one_complete_mobile_document_without_mutating_desktop_or_sources(bool $personal): void
    {
        $desktop = $this->fixture($personal);
        $before = $desktop;
        $rows = MailDocument::query()->orderBy('id')->get()->toArray();
        $mobile = OutlookMobileSignature::payload($desktop);
        $template = $mobile['templates'][0];
        $this->assertSame($before, $desktop);
        $this->assertSame($rows, MailDocument::query()->orderBy('id')->get()->toArray());
        $this->assertSame($before['templates'][0]['composeHtml'], $template['composeHtml']);
        $this->assertSame($before['templates'][0]['combinedComposeHtml'], $template['combinedComposeHtml']);
        $this->assertSame($before['templates'][0]['composeMedia'], $template['composeMedia']);
        $this->assertSame('native', $template['signatureMode']);
        $this->assertSame(OutlookMobileCombinedComposeDocument::MODE, $template['mobileComposeDocumentMode']);
        $this->assertSame($before['automaticTemplateId'], $mobile['automaticTemplateId']);
        $this->assertSame($mobile, OutlookMobileSignature::payload($desktop));
        $html = $template['mobileComposeHtml'];
        $this->assertSame(1, substr_count($html, 'data-rt-compose-document="combined-native-v1"'));
        $this->assertSame(2, substr_count($html, 'RT-TEMPLATE-MANAGED-V1:COMBINED-DOCUMENT'));
        $this->assertSame(2, substr_count($html, 'RT-SIGNATURE-MANAGED-V1'));
        $this->assertSame(2, substr_count($html, 'RT-MOBILE-COMPOSE-VERSION:'.$template['mobileComposeVersion']));
        $this->assertStringNotContainsString('RT-TEMPLATE-MANAGED-V1:NATIVE-SIGNATURE', $html);
        foreach (['Guten Tag,', 'Mit freundlichen Grüßen,', '24/7', 'Hotline', 'railtime-example.test'] as $text) {
            $this->assertStringContainsString($text, $html);
        }
        $this->assertLessThanOrEqual(30000, intdiv(strlen(mb_convert_encoding($html, 'UTF-16LE', 'UTF-8')), 2));
        preg_match_all('~<style\b[^>]*>(.*?)</style>~s', $html, $styles);
        $this->assertLessThan(12288, array_sum(array_map('strlen', $styles[1])));
        $xpath = $this->xpath($html);
        $metadata = $xpath->query('//span[@data-rt-mobile-compose-version="1"]');
        $this->assertSame(1, $metadata->length);
        $this->assertTrue($metadata->item(0)->hasAttribute('hidden'));
        $this->assertSame('true', $metadata->item(0)->getAttribute('aria-hidden'));
        $this->assertSame('td', $metadata->item(0)->parentNode->nodeName);
        $this->assertSame(1, substr_count($html, 'border-left:6px solid #e90032;'));
        $this->assertSame(2, substr_count($html, 'padding-left:18px;padding-right:18px;'));
        $this->assertGreaterThanOrEqual(2, substr_count($html, 'padding-left:18px!important;padding-right:18px!important;'));
        $this->assertSame(2, substr_count($html, 'border-left:0;'));
        $this->assertSame(2, substr_count($html, 'border-left:0!important;'));
        $this->assertSame($this->images($template['composeHtml'].$template['signature']['html']), $this->images($html));
        $this->assertSame($this->links($template['composeHtml'].$template['signature']['html']), $this->links($html));
        $expected = array_column(array_merge($before['templates'][0]['composeMedia'], $template['signature']['media']), null, 'contentId');
        $this->assertSame(array_values($expected), $template['mobileComposeMedia']);
        preg_match_all('~\bsrc="cid:([^"]+)"~', $html, $cids);
        foreach ($cids[1] as $cid) {
            $this->assertArrayHasKey($cid, $expected);
        }
    }

    public function test_bijective_compaction_preserves_every_inline_fallback_comment_and_css_declaration(): void
    {
        $desktop = $this->fixture();
        $mobile = $this->standaloneMobile($desktop);
        $html = $this->canonical($desktop, $mobile);
        $method = new ReflectionMethod(OutlookMobileCombinedComposeDocument::class, 'compactIdentifiers');
        [$compact, $map] = $method->invoke(null, $html);
        $this->assertNotEmpty($map);
        $this->assertSame(count($map), count(array_unique($map)));
        $this->assertSame($html, (new ReflectionMethod(OutlookMobileCombinedComposeDocument::class, 'rewrite'))->invoke(null, $compact, array_flip($map)));
        preg_match_all('~\sstyle="[^"]*"~', $html, $old);
        preg_match_all('~\sstyle="[^"]*"~', $compact, $new);
        $this->assertSame($old[0], $new[0], 'No normal inline style is dropped or edited by identifier compaction.');
        preg_match_all('~<!--.*?-->~s', $html, $oldComments);
        preg_match_all('~<!--.*?-->~s', $compact, $newComments);
        $this->assertSame($oldComments[0], $newComments[0]);
        $styleFree = preg_replace('~<style\b[^>]*>.*?</style>~s', '', $compact);
        $this->assertSame($this->images($compact), $this->images($styleFree));
        preg_match_all('~\sstyle="[^"]*"~', $styleFree, $fallback);
        $this->assertSame($old[0], $fallback[0], 'STYLE-free fallback retains ALL ordinary geometry.');
        $xpath = $this->xpath($styleFree);
        foreach (['rt-ledger-brand', 'rt-ledger-contacts'] as $class) {
            $name = $map[$class] ?? $class;
            $cell = $xpath->query('//td[contains(concat(" ",normalize-space(@class)," ")," '.$name.' ")]');
            $this->assertSame(1, $cell->length);
            $this->assertSame('100%', $cell->item(0)->getAttribute('width'));
            $this->assertSame(1, $xpath->query('./td', $cell->item(0)->parentNode)->length, 'Existing mobile physical columns stay real full-width TD rows.');
        }
    }

    public function test_outside_scope_alias_and_authored_cascade_are_preserved_not_stripped(): void
    {
        $desktop = $this->fixture();
        $desktop['templates'][0]['composeHtml'] = str_replace('Guten Tag,', 'Guten Tag,<p class="oic" style="padding-top:13px;">Outside alias</p>', $desktop['templates'][0]['composeHtml']);
        $desktop['templates'][0]['combinedComposeHtml'] = OutlookCombinedComposeDocument::build($desktop['templates'][0]['composeHtml'], $desktop['templates'][0]['signature']['html']);
        $mobile = $this->standaloneMobile($desktop);
        preg_match('/\brts[0-9a-f]{10}\b/', $mobile['templates'][0]['signature']['html'], $scope);
        $opaque = '.'.$scope[0].' .oic{padding-top:99px;content:".rt-ledger-brand $1 \\rail";}/* .rt-contact-text */';
        $mobile['templates'][0]['signature']['html'] .= '<style>'.$opaque.'</style>';
        $result = OutlookMobileCombinedComposeDocument::build($desktop['templates'][0], $desktop['templates'][0]['signature'], $mobile['templates'][0]['signature']);
        $this->assertStringContainsString('style="padding-top:13px;">Outside alias', $result['html']);
        $this->assertStringContainsString('padding-top:99px;content:".rt-ledger-brand $1 \\rail";', $result['html']);
        $this->assertStringContainsString('/* .rt-contact-text */', $result['html']);
    }

    public function test_comment_branch_classes_and_unscoped_author_classes_are_not_aliased(): void
    {
        $desktop = $this->fixture();
        $mobile = $this->standaloneMobile($desktop);
        $html = $this->canonical($desktop, $mobile).'<style>.historical-author{color:red;}</style><!--[if mso]><p class="rt-ledger-brand">MSO</p><![endif]-->';
        [$output, $map] = (new ReflectionMethod(OutlookMobileCombinedComposeDocument::class, 'compactIdentifiers'))->invoke(null, $html);
        $this->assertArrayNotHasKey('rt-ledger-brand', $map);
        $this->assertArrayNotHasKey('historical-author', $map);
        $this->assertStringContainsString('<style>.historical-author{color:red;}</style>', $output);
        $this->assertStringContainsString('<!--[if mso]><p class="rt-ledger-brand">MSO</p><![endif]-->', $output);
    }

    public function test_entity_encoded_class_tokens_cannot_diverge_from_dom_semantics(): void
    {
        $desktop = $this->fixture();
        $mobile = $this->standaloneMobile($desktop);
        $html = $this->canonical($desktop, $mobile).'<p class="long&#45;name" style="padding-top:13px;">Encoded</p>';
        $this->expectException(RuntimeException::class);
        (new ReflectionMethod(OutlookMobileCombinedComposeDocument::class, 'compactIdentifiers'))->invoke(null, $html);
    }

    public function test_conditional_comment_entity_classes_keep_their_original_selector_identity(): void
    {
        $desktop = $this->fixture();
        $mobile = $this->standaloneMobile($desktop);
        $html = $this->canonical($desktop, $mobile);
        preg_match('/\brts[0-9a-f]{10}\b/', $html, $scope);
        $conditional = '<!--[if mso]><p class="long&#45;name">Conditional</p><![endif]-->';
        $html .= '<style>.'.$scope[0].' .long-name{padding:13px;}</style>'.$conditional;
        [$output, $map] = (new ReflectionMethod(OutlookMobileCombinedComposeDocument::class, 'compactIdentifiers'))->invoke(null, $html);
        $this->assertArrayNotHasKey('long-name', $map);
        $this->assertStringContainsString(' .long-name{padding:13px;}', $output);
        $this->assertStringContainsString($conditional, $output);
    }

    public function test_semantic_aliases_are_stable_when_template_classes_and_quote_protection_change(): void
    {
        $desktop = $this->fixture();
        $mobile = $this->standaloneMobile($desktop);
        $html = $this->canonical($desktop, $mobile);
        $method = new ReflectionMethod(OutlookMobileCombinedComposeDocument::class, 'compactIdentifiers');
        [, $first] = $method->invoke(null, $html);
        $changed = str_replace('Guten Tag,', '<p class="new-authored-template">Header</p>Guten Tag,', $html)
            .'<!--[if mso]><p class="rt-ledger-brand">Quote-protected</p><![endif]-->';
        [, $second] = $method->invoke(null, $changed);
        $this->assertArrayNotHasKey('new-authored-template', $second);
        $this->assertArrayNotHasKey('rt-ledger-brand', $second);
        foreach (['rt-contact-text', 'rt-ledger-contacts', 'rt-outlook-signature', 'rtm'] as $class) {
            $this->assertSame($first[$class], $second[$class], 'Same content-bound signature scope can never reuse another role alias after a template change.');
        }
    }

    public function test_native_train_overlay_alias_is_fixed_lossless_and_still_quote_protected(): void
    {
        $desktop = $this->fixture();
        $mobile = $this->standaloneMobile($desktop);
        $html = $this->canonical($desktop, $mobile);
        $compact = new ReflectionMethod(OutlookMobileCombinedComposeDocument::class, 'compactIdentifiers');
        $rewrite = new ReflectionMethod(OutlookMobileCombinedComposeDocument::class, 'rewrite');
        [$output, $map] = $compact->invoke(null, $html);
        $this->assertSame('x1b', $map['rt-native-train-overlay']);
        $this->assertSame($html, $rewrite->invoke(null, $output, array_flip($map)));
        $this->assertSame($this->images($html), $this->images($output));
        preg_match_all('~\sstyle="[^"]*"~', $html, $before);
        preg_match_all('~\sstyle="[^"]*"~', $output, $after);
        $this->assertSame($before[0], $after[0]);
        $this->assertStringContainsString('data-rt-train-bottom-overlay="intrinsic-mobile-img-v1"', $output);
        $changed = str_replace('Guten Tag,', '<p class="new-authored-template">Header</p>Guten Tag,', $html);
        [, $changedMap] = $compact->invoke(null, $changed);
        $this->assertSame('x1b', $changedMap['rt-native-train-overlay']);
        $conditional = '<!--[if mso]><p class="rt-native-train-overlay">Protected</p><![endif]-->';
        [$protected, $protectedMap] = $compact->invoke(null, $html.$conditional);
        $this->assertArrayNotHasKey('rt-native-train-overlay', $protectedMap);
        $this->assertStringContainsString($conditional, $protected);
        $this->assertStringContainsString('.rt-native-train-overlay', $protected);
    }

    public function test_new_native_train_overlay_alias_collision_fails_closed(): void
    {
        $desktop = $this->fixture();
        $mobile = $this->standaloneMobile($desktop);
        $html = str_replace('Guten Tag,', '<p class="x1b">Authored collision</p>Guten Tag,', $this->canonical($desktop, $mobile));
        $this->expectException(RuntimeException::class);
        (new ReflectionMethod(OutlookMobileCombinedComposeDocument::class, 'compactIdentifiers'))->invoke(null, $html);
    }

    public function test_existing_authored_short_class_collision_fails_closed(): void
    {
        $desktop = $this->fixture();
        $mobile = $this->standaloneMobile($desktop);
        $html = str_replace('Guten Tag,', '<p class="xg">Collision</p>Guten Tag,', $this->canonical($desktop, $mobile));
        $this->expectException(RuntimeException::class);
        (new ReflectionMethod(OutlookMobileCombinedComposeDocument::class, 'compactIdentifiers'))->invoke(null, $html);
    }

    public function test_conditional_only_short_class_collision_fails_closed(): void
    {
        $desktop = $this->fixture();
        $mobile = $this->standaloneMobile($desktop);
        $html = $this->canonical($desktop, $mobile).'<!--[if mso]><p class="xg">Conditional collision</p><![endif]-->';
        $this->expectException(RuntimeException::class);
        (new ReflectionMethod(OutlookMobileCombinedComposeDocument::class, 'compactIdentifiers'))->invoke(null, $html);
    }

    public function test_authored_scope_like_identifiers_are_not_treated_as_generated_root_scopes(): void
    {
        $desktop = $this->fixture();
        $mobile = $this->standaloneMobile($desktop);
        $html = $this->canonical($desktop, $mobile);
        preg_match('/\brts[0-9a-f]{10}\b/', $html, $scope);
        $html .= '<style>.'.$scope[0].' .rtsface{padding:13px;}</style><p class="rtsface rtt000face">Author scopes</p>';
        [$output, $map] = (new ReflectionMethod(OutlookMobileCombinedComposeDocument::class, 'compactIdentifiers'))->invoke(null, $html);
        $this->assertArrayNotHasKey('rtsface', $map);
        $this->assertArrayNotHasKey('rtt000face', $map);
        $this->assertStringContainsString(' .rtsface{padding:13px;}', $output);
        $this->assertStringContainsString('class="rtsface rtt000face"', $output);
    }

    public static function unknownSelectors(): array
    {
        return ['class attribute' => ['.scope [class~="rt-contact-text"]'], 'escaped' => ['.scope .rt\\2d contact'], 'function' => ['.scope :is(.rt-contact-text)'], 'id' => ['.scope #foreign'], 'sibling' => ['.scope+.rt-contact-text']];
    }

    #[DataProvider('unknownSelectors')]
    public function test_unsupported_selector_grammars_fail_closed(string $selector): void
    {
        $desktop = $this->fixture();
        $mobile = $this->standaloneMobile($desktop);
        $mobile['templates'][0]['signature']['html'] .= '<style>'.$selector.'{padding-top:99px;}</style>';
        $this->expectException(RuntimeException::class);
        OutlookMobileCombinedComposeDocument::build($desktop['templates'][0], $desktop['templates'][0]['signature'], $mobile['templates'][0]['signature']);
    }

    public static function invalid(): array
    {
        return array_combine(['combined mismatch', 'missing mode', 'missing mobile mirror', 'duplicate mobile mirror', 'changed mirror', 'mobile scope', 'malformed mobile table', 'visible metadata', 'missing media', 'conflicting media', 'nonportable image', 'HTML budget', 'CSS budget'], array_map(static fn (string $name): array => [$name], ['combined mismatch', 'missing mode', 'missing mobile mirror', 'duplicate mobile mirror', 'changed mirror', 'mobile scope', 'malformed mobile table', 'visible metadata', 'missing media', 'conflicting media', 'nonportable image', 'HTML budget', 'CSS budget']));
    }

    #[DataProvider('invalid')]
    public function test_modified_ambiguous_media_and_budget_contracts_fail_closed(string $mutation): void
    {
        $desktop = $this->fixture();
        $mobile = $this->standaloneMobile($desktop);
        $template = $desktop['templates'][0];
        $signature = $mobile['templates'][0]['signature'];
        switch ($mutation) {
            case 'combined mismatch': $template['combinedComposeHtml'] .= 'Changed';
                break;
            case 'missing mode': unset($template['composeDocumentMode']);
                break;
            case 'missing mobile mirror': $signature['html'] = preg_replace('~<style data-rt-outlook-mobile-css="1">.*?</style>~s', '', $signature['html']);
                break;
            case 'duplicate mobile mirror': $signature['html'] = preg_replace('~(<style data-rt-outlook-mobile-css="1">.*?</style>)~s', '$1$1', $signature['html']);
                break;
            case 'changed mirror': $signature['html'] = str_replace('border-left:6px solid #e90032!important;', 'border-left:7px solid #e90032!important;', $signature['html']);
                break;
            case 'mobile scope': $signature['html'] = str_replace('rt-mobile-ledger', 'foreign-mobile-ledger', $signature['html']);
                break;
            case 'malformed mobile table': $signature['html'] = preg_replace('~</td>~', '', $signature['html'], 1);
                break;
            case 'visible metadata': $signature['html'] = str_replace('<span hidden ', '<span ', $signature['html']);
                break;
            case 'missing media': array_pop($template['composeMedia']);
                break;
            case 'conflicting media': $template['composeMedia'][] = array_replace($signature['media'][0], ['base64' => $signature['media'][1]['base64']]);
                break;
            case 'nonportable image': $signature['html'] = preg_replace('~src="cid:[^"]+"~', 'src="https://external.example.test/image.png"', $signature['html'], 1);
                break;
            case 'HTML budget': $template['composeHtml'] = str_replace('Guten Tag,', str_repeat('😀', 20000), $template['composeHtml']);
                $template['combinedComposeHtml'] = OutlookCombinedComposeDocument::build($template['composeHtml'], $desktop['templates'][0]['signature']['html']);
                break;
            case 'CSS budget': $signature['html'] .= '<style>/*'.str_repeat('opaque ', 1700).'*/</style>';
                break;
        }
        $this->expectException(RuntimeException::class);
        OutlookMobileCombinedComposeDocument::build($template, $desktop['templates'][0]['signature'], $signature);
    }

    public function test_mobile_media_union_preserves_conditional_only_attachment_without_assuming_eleven(): void
    {
        $desktop = $this->fixture();
        $mobile = $this->standaloneMobile($desktop);
        $signature = $mobile['templates'][0]['signature'];
        $extra = array_replace($signature['media'][0], ['name' => 'conditional-extra.png', 'contentId' => 'conditional-extra.png']);
        $template = $desktop['templates'][0];
        $template['composeMedia'][] = $extra;
        $template['composeHtml'] = str_replace('Guten Tag,', 'Guten Tag,<!--[if mso]><img src="cid:conditional-extra.png" width="17" height="17"><![endif]-->', $template['composeHtml']);
        $template['combinedComposeHtml'] = OutlookCombinedComposeDocument::build($template['composeHtml'], $desktop['templates'][0]['signature']['html']);
        $result = OutlookMobileCombinedComposeDocument::build($template, $desktop['templates'][0]['signature'], $signature);
        $expected = array_column(array_merge($template['composeMedia'], $signature['media']), null, 'contentId');
        $this->assertSame(array_values($expected), $result['media']);
        $this->assertContains($extra, $result['media']);
        $this->assertStringContainsString('<!--[if mso]><img src="cid:conditional-extra.png" width="17" height="17"><![endif]-->', $result['html']);
    }

    private function fixture(bool $personal = true): array
    {
        // Reuse the actual current V32-shaped published fixture, not an invented
        // hand-authored add-in payload. PHPUnit config guarantees :memory:.
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        $case = new OutlookCombinedComposeDocumentTest('test_actual_v32_native_fragments_share_one_continuous_body_carrier');
        (new ReflectionMethod($case, 'setUp'))->invoke($case);
        $parts = (new ReflectionMethod($case, 'fragments'))->invoke($case, $personal);

        return app(OutlookAddinPayloadService::class)->forUser($parts[3]);
    }

    private function standaloneMobile(array $desktop): array
    {
        $legacy = $desktop;
        foreach ($legacy['templates'] as &$template) {
            unset($template['composeDocumentMode']);
        }
        unset($template);

        return OutlookMobileSignature::payload($legacy);
    }

    private function canonical(array $desktop, array $mobile): string
    {
        $template = $desktop['templates'][0];
        $html = $template['combinedComposeHtml'];
        $range = new ReflectionMethod(OutlookMobileCombinedComposeDocument::class, 'rootRange');
        [, $start] = $range->invoke(null, $html, 'rt-outlook-template');
        [, $end] = $range->invoke(null, $html, 'rt-outlook-signature');
        $signature = (new ReflectionMethod(OutlookMobileCombinedComposeDocument::class, 'mobileFrame'))->invoke(null, $mobile['templates'][0]['signature']['html']);

        return (new ReflectionMethod(OutlookMobileCombinedComposeDocument::class, 'mobileTemplateInset'))->invoke(null, substr($html, 0, $start).$signature.substr($html, $end));
    }

    private function xpath(string $html): DOMXPath
    {
        $dom = new DOMDocument('1.0', 'UTF-8');
        $before = libxml_use_internal_errors(true);
        try {
            $this->assertTrue($dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET));
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($before);
        }

        return new DOMXPath($dom);
    }

    private function images(string $html): array
    {
        preg_match_all('~<img\b(?:[^"\'<>]|"[^"]*"|\'[^\']*\')*>~is', $html, $matches);

        return array_map(static fn (string $tag): string => preg_replace('~\sclass\s*=\s*(["\'])(.*?)\1~s', '', $tag), $matches[0]);
    }

    private function links(string $html): array
    {
        preg_match_all('~href="[^"]*"~', $html, $matches);

        return $matches[0];
    }
}
