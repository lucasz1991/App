<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\MailDocument;
use App\Support\OutlookAddin\OutlookClientPresentation;
use App\Support\OutlookAddin\OutlookMobileSignature;
use DOMDocument;
use DOMXPath;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\TestCase;

/** Exercise the final projection after every strict canonical compiler. */
final class OutlookClientPresentationTest extends TestCase
{
    public function test_advanced_authored_logo_class_observers_keep_their_original_contract(): void
    {
        $payload = $this->fixture(false);
        foreach (['img:not(.rt-logo){border:0;}', 'img[class="rt-logo"]{border:0;}', '.\\72t-logo{border:0;}'] as $css) {
            $candidate = $payload;
            $candidate['signature']['html'] = '<style data-rt-mail-document-css="signature">'.$css.'</style>'.$candidate['signature']['html'];
            $result = OutlookClientPresentation::payload($candidate);
            self::assertStringContainsString($css, $result['signature']['html']);
            self::assertStringNotContainsString('rt-client-logo', $result['signature']['html']);
            self::assertGreaterThan(0, $this->xpath($result['signature']['html'])->query('//img[contains(concat(" ",normalize-space(@class)," ")," rt-logo ")]')->length);
        }
    }

    public static function profiles(): array
    {
        return ['personal' => [true], 'company' => [false]];
    }

    #[DataProvider('profiles')]
    public function test_real_desktop_global_and_paired_documents_remain_portable_and_canonical_sources_unchanged(bool $personal): void
    {
        $canonical = $this->fixture($personal);
        $before = $canonical;
        $published = MailDocument::query()->orderBy('id')->get()->toArray();
        $result = OutlookClientPresentation::payload($canonical);

        self::assertSame($before, $canonical);
        self::assertSame($published, MailDocument::query()->orderBy('id')->get()->toArray());
        self::assertSame($result, OutlookClientPresentation::payload($canonical), 'The same canonical input must yield identical delivery bytes.');
        $this->assertMetadataPreserved($canonical, $result);
        $this->assertSignatureVersion($result['signature']['html'], $result['version']['signature']);
        self::assertNotSame($canonical['version']['signature'], $result['version']['signature']);
        $this->assertProjection($canonical['signature']['html'], $result['signature']['html'], 30000, 12288);
        self::assertSame($canonical['signature']['media'], $result['signature']['media']);

        foreach ($canonical['templates'] as $index => $old) {
            $new = $result['templates'][$index];
            $this->assertProjection($old['html'], $new['html'], 99000, 24576);
            $this->assertProjection($old['composeHtml'], $new['composeHtml'], 99000, 24576, logo: false);
            $this->assertProjection($old['combinedComposeHtml'], $new['combinedComposeHtml'], 99000, 24576);
            self::assertSame($old['signatureDocumentId'], $new['signatureDocumentId']);
            self::assertSame($old['signature']['media'], $new['signature']['media']);
            self::assertSame($result['version']['signature'], $new['signatureVersion']);
            $this->assertSignatureVersion($new['signature']['html'], $new['signatureVersion']);
            $this->assertSignatureVersion($new['html'], $new['signatureVersion']);
            $this->assertSignatureVersion($new['combinedComposeHtml'], $new['signatureVersion']);
        }
        self::assertSame($result['templates'][0]['html'], $result['template']['html']);
        self::assertSame($result['templates'][0]['media'], $result['template']['media']);
        // The final delivery adaptation must not feed back into exact inverse
        // and canonical-CSS checks needed for the mobile compilation path.
        self::assertSame(OutlookMobileSignature::payload($before), OutlookMobileSignature::payload($canonical));
    }

    #[DataProvider('profiles')]
    public function test_real_mobile_global_paired_and_compacted_combined_documents_keep_budgets_media_and_versions(bool $personal): void
    {
        $desktop = $this->fixture($personal);
        $canonical = OutlookMobileSignature::payload($desktop);
        $before = $canonical;
        $published = MailDocument::query()->orderBy('id')->get()->toArray();
        $result = OutlookClientPresentation::payload($canonical);

        self::assertSame($before, $canonical);
        self::assertSame($published, MailDocument::query()->orderBy('id')->get()->toArray());
        self::assertSame($result, OutlookClientPresentation::payload($canonical));
        $this->assertMetadataPreserved($canonical, $result);
        $this->assertSignatureVersion($result['signature']['html'], $result['version']['signature']);
        $this->assertProjection($canonical['signature']['html'], $result['signature']['html'], 30000, 12288);
        self::assertSame($canonical['signature']['media'], $result['signature']['media']);

        foreach ($canonical['templates'] as $index => $old) {
            $new = $result['templates'][$index];
            $this->assertProjection($old['signature']['html'], $new['signature']['html'], 30000, 12288);
            $this->assertProjection($old['mobileComposeHtml'], $new['mobileComposeHtml'], 30000, 12288);
            self::assertSame($old['mobileComposeMedia'], $new['mobileComposeMedia']);
            self::assertSame($old['mobileComposeDocumentMode'], $new['mobileComposeDocumentMode']);
            self::assertNotSame($old['mobileComposeVersion'], $new['mobileComposeVersion']);
            self::assertMatchesRegularExpression('/\A[0-9a-f]{16}\z/', $new['mobileComposeVersion']);
            self::assertSame(2, substr_count($new['mobileComposeHtml'], 'RT-MOBILE-COMPOSE-VERSION:'.$new['mobileComposeVersion']));
            self::assertSame($result['version']['signature'], $new['signatureVersion']);
            $this->assertSignatureVersion($new['signature']['html'], $new['signatureVersion']);
            $this->assertSignatureVersion($new['mobileComposeHtml'], $new['signatureVersion']);
            foreach (['html', 'composeHtml', 'combinedComposeHtml'] as $field) {
                $this->assertProjection($old[$field], $new[$field], 99000, 24576, logo: $field !== 'composeHtml');
            }
        }
        self::assertSame($result['templates'][0]['html'], $result['template']['html']);
        self::assertSame(OutlookMobileSignature::payload($desktop), $before, 'Strict mobile compilation still consumes only canonical cached artifacts.');
    }

    public function test_visible_http_urls_wrap_but_named_actions_targets_and_conditional_bytes_remain_unchanged(): void
    {
        $payload = $this->fixture();
        $url = 'https://railtime-example.test/invitation/'.str_repeat('a', 160).'?signature='.str_repeat('b', 80);
        $insertion = '<p id="long-fallback"><span><a id="long-link" href="'.$url.'">'.$url.'</a></span></p>'
            .'<p><a id="named-action" href="'.$url.'">Access account</a></p>'
            .'<!--[if mso]><p><a href="'.$url.'">'.$url.'</a></p><![endif]-->';
        $payload['templates'][0]['composeHtml'] .= $insertion;
        $before = $payload;
        $result = OutlookClientPresentation::payload($payload);
        $html = $result['templates'][0]['composeHtml'];
        $xpath = $this->xpath($html);

        self::assertSame($before, $payload);
        self::assertSame($url, $xpath->query('//*[@id="long-link"]')->item(0)->getAttribute('href'));
        self::assertSame($url, $xpath->query('//*[@id="long-link"]')->item(0)->textContent);
        foreach (['long-fallback', 'long-link'] as $id) {
            $style = $xpath->query('//*[@id="'.$id.'"]')->item(0)->getAttribute('style');
            self::assertMatchesRegularExpression('/(?:\A|;)\s*word-break:\s*break-all(?:;|\z)/', $style);
            self::assertMatchesRegularExpression('/(?:\A|;)\s*overflow-wrap:\s*anywhere(?:;|\z)/', $style);
        }
        // Office insertion may retain internal STYLE while dropping inline
        // styles. The exact long-link constraint must survive that boundary.
        $styleFree = preg_replace('~\sstyle\s*=\s*(["\'])(.*?)\1~is', '', $html);
        $withoutInline = $this->xpath($styleFree);
        $css = implode('', $this->styles($styleFree));
        foreach (['long-fallback', 'long-link'] as $id) {
            $classes = preg_split('/\s+/', trim($withoutInline->query('//*[@id="'.$id.'"]')->item(0)->getAttribute('class')));
            $covered = false;
            foreach ($classes as $class) {
                if ($class !== '') {
                    // The class name is an implementation detail. What matters
                    // is that the element still has both real wrapping rules.
                    $selector = '~(?:\A|[}])[^{}]*\.'.preg_quote($class, '~').'\b[^{}]*\{([^{}]*)\}~is';
                    preg_match_all($selector, $css, $rules);
                    $declarations = implode(';', $rules[1]);
                    $covered = preg_match('/\bword-break\s*:\s*break-all\b/', $declarations) === 1
                        && preg_match('/\boverflow-wrap\s*:\s*anywhere\b/', $declarations) === 1;
                }
                if ($covered) {
                    break;
                }
            }
            self::assertTrue($covered, $id.' remains protected when Office drops all inline styles.');
        }
        self::assertStringNotContainsString('word-break', $xpath->query('//*[@id="named-action"]')->item(0)->getAttribute('style'));
        self::assertSame($this->comments($before['templates'][0]['composeHtml']), $this->comments($html));
    }

    public function test_logo_isolation_preserves_generic_legacy_rules_and_non_ledger_images(): void
    {
        $payload = $this->fixture(false);
        $signature = $payload['signature']['html'];
        $signature .= '<style data-rt-outlook-published-css="1">'
            .'@media(max-width:1000px){img.rt-logo{width:180px!important;}}'
            .'.rt-sign-ledger img.rt-logo,img.rt-logo{width:100%;max-width:180px;height:auto;}'
            .'@media(max-width:860px){.rt-sign-ledger img.rt-logo{max-width:175px;}}'
            .'</style><img id="ordinary-logo" class="rt-logo" src="cid:ordinary.png" width="180" height="31">'
            .'<!--[if mso]><img class="rt-logo" src="cid:word-logo.png" width="180" height="31"><![endif]-->';
        $payload['signature']['html'] = $signature;
        $result = OutlookClientPresentation::payload($payload);
        $html = $result['signature']['html'];
        $xpath = $this->xpath($html);

        self::assertSame('rt-logo', $xpath->query('//*[@id="ordinary-logo"]')->item(0)->getAttribute('class'));
        self::assertStringContainsString('img.rt-logo{width:180px!important;}', $html);
        self::assertStringContainsString('.rt-sign-ledger img.rt-client-logo,img.rt-logo{width:100%;max-width:180px;height:auto;}', $html);
        self::assertSame($this->comments($signature), $this->comments($html));
        self::assertGreaterThan(0, $xpath->query('//table[contains(concat(" ",normalize-space(@class)," ")," rt-sign-ledger ")]//img[contains(concat(" ",normalize-space(@class)," ")," rt-client-logo ")]')->length);
        self::assertSame(0, $xpath->query('//table[contains(concat(" ",normalize-space(@class)," ")," rt-sign-ledger ")]//img[contains(concat(" ",normalize-space(@class)," ")," rt-logo ")]')->length);
    }

    public function test_published_logo_styling_still_targets_the_renamed_image_and_css_text_is_opaque(): void
    {
        $payload = $this->fixture(false);
        $signature = $payload['signature']['html'];
        self::assertSame(1, preg_match('/\brts[0-9a-f]{10}\b/', $signature, $scope));
        $literal = '.authored-label::before{content:".rt-sign-ledger img.rt-logo";}/* literal .rt-sign-ledger img.rt-logo */';
        $authored = '.'.$scope[0].' img.rt-logo{border-bottom:2px solid #123456;}';
        $signature .= '<style data-rt-mail-document-css="signature">'.$authored.$literal.'</style>'
            .'<div class="'.$scope[0].'"><img id="other-authored-logo" class="rt-logo" src="cid:other-logo.png" width="100" height="17"></div>';
        $payload['signature']['html'] = $signature;
        $html = OutlookClientPresentation::payload($payload)['signature']['html'];

        self::assertStringContainsString($literal, $html, 'Selector-like characters in CSS values/comments are not selectors.');
        self::assertMatchesRegularExpression('/[^{}]*\.'.preg_quote($scope[0], '/').' img\.rt-logo(?=[\s,{])[^{}]*\{[^{}]*border-bottom:2px solid #123456;[^{}]*\}/', implode('', $this->styles($html)), 'The same published style must still apply to an ordinary logo outside the bounded ledger.');
        self::assertMatchesRegularExpression('/[^{}]*\.rt-client-logo\b[^{}]*\{[^{}]*border-bottom:2px solid #123456;[^{}]*\}/', implode('', $this->styles($html)), 'Published logo styling remains effective after the output-only rename.');
        self::assertSame('rt-logo', $this->xpath($html)->query('//*[@id="other-authored-logo"]')->item(0)->getAttribute('class'));
        self::assertSame($this->images($signature), $this->images($html));
    }

    private function fixture(bool $personal = true): array
    {
        self::assertSame('sqlite', config('database.default'));
        self::assertSame(':memory:', config('database.connections.sqlite.database'));
        $case = new OutlookMobileCombinedComposeDocumentTest('test_actual_builder_payload_has_one_complete_mobile_document_without_mutating_desktop_or_sources');
        $payload = (new ReflectionMethod($case, 'fixture'))->invoke($case, $personal);
        $payload['version']['personal'] = '0123456789abcdef';
        $payload['binding'] = ['schema' => 1, 'mailboxAddress' => 'synthetic@railtime-example.test', 'senderAddress' => 'synthetic@railtime-example.test', 'allowedSenderAddresses' => ['synthetic@railtime-example.test']];

        return $payload;
    }

    private function assertMetadataPreserved(array $old, array $new): void
    {
        foreach (['schema', 'marker', 'automaticTemplateId', 'binding'] as $key) {
            self::assertSame($old[$key], $new[$key], $key);
        }
        self::assertSame($old['version']['personal'], $new['version']['personal']);
        self::assertSame($old['version']['template'], $new['version']['template']);
        foreach ($old['templates'] as $index => $template) {
            foreach (['id', 'key', 'name', 'label', 'active', 'isDefault', 'version', 'hash', 'media', 'composeMedia', 'combinedComposeMedia', 'signatureMode', 'composeDocumentMode'] as $key) {
                self::assertSame($template[$key], $new['templates'][$index][$key], $key);
            }
        }
    }

    private function assertSignatureVersion(string $html, string $version): void
    {
        self::assertMatchesRegularExpression('/\A[0-9a-f]{16}\z/', $version);
        self::assertSame(2, substr_count($html, 'RT-SIGNATURE-VERSION:'.$version));
    }

    private function assertProjection(string $old, string $new, int $htmlLimit, int $cssLimit, bool $logo = true): void
    {
        self::assertSame($this->images($old), $this->images($new), 'Image sources/dimensions/conditional branches remain intact.');
        self::assertSame($this->comments($old), $this->comments($new), 'Conditional Word branches stay byte-identical.');
        self::assertLessThanOrEqual($htmlLimit, intdiv(strlen(mb_convert_encoding($new, 'UTF-16LE', 'UTF-8')), 2));
        $styles = $this->styles($new);
        self::assertLessThan($cssLimit, array_sum(array_map('strlen', $styles)));
        foreach ($styles as $css) {
            self::assertLessThanOrEqual(1, preg_match_all('/@media\b/i', $css), 'A retained first media block cannot hide a later mobile block.');
        }
        self::assertSame(implode('', $this->styles($old)), str_replace('rt-client-logo', 'rt-logo', implode('', $styles)), 'Splitting keeps the CSS cascade and declarations byte-identical apart from the bounded logo selector.');
        if ($logo) {
            self::assertStringContainsString('rt-client-logo', $new);
        }
    }

    private function styles(string $html): array
    {
        $html = preg_replace('~<!--.*?-->~s', '', $html);
        preg_match_all('~<style\b[^>]*>(.*?)</style\s*>~is', $html, $styles);

        return $styles[1];
    }

    private function images(string $html): array
    {
        preg_match_all('~<img\b(?:[^"\'<>]|"[^"]*"|\'[^\']*\')*>~is', $html, $images);

        return array_map(static fn (string $tag): string => preg_replace('~\sclass\s*=\s*(["\'])(.*?)\1~s', '', $tag), $images[0]);
    }

    private function comments(string $html): array
    {
        preg_match_all('~<!--.*?-->~s', $html, $comments);

        return array_values(array_filter($comments[0], static fn (string $comment): bool => ! preg_match('/\A<!--\s*RT-(?:SIGNATURE|MOBILE-COMPOSE)-VERSION:/', $comment)));
    }

    private function xpath(string $html): DOMXPath
    {
        $document = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        try {
            self::assertTrue($document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET));
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return new DOMXPath($document);
    }
}
