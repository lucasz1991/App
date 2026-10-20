<?php

namespace Tests\Unit;

use App\Support\Mail\SystemMailCssInliner;
use App\Support\Mail\SystemMailInlineImageEmbedder;
use App\Support\Mail\TrustedEmailCss;
use DOMDocument;
use DOMXPath;
use PHPUnit\Framework\TestCase;
use TijsVerkoyen\CssToInlineStyles\CssToInlineStyles;

final class SystemMailCssInlinerTest extends TestCase
{
    public function test_visible_fallback_urls_wrap_without_changing_targets_or_named_links(): void
    {
        $url = 'https://railtime.test/invitation/'.str_repeat('a', 128).'?signature='.str_repeat('b', 64);
        $source = str_replace('</body>', '<p id="fallback"><span><a id="url" href="'.$url.'">'.$url.'</a></span></p><p><a id="action" href="'.$url.'">Access account</a></p></body>', $this->document());
        $html = (new SystemMailCssInliner)->convert($source);
        $dom = new DOMDocument;
        $dom->loadHTML($html);
        self::assertSame($url, $dom->getElementById('url')->getAttribute('href'));
        self::assertSame($url, $dom->getElementById('url')->textContent);
        foreach (['url', 'fallback'] as $id) {
            self::assertMatchesRegularExpression('/(?:^|;)\s*word-break:\s*break-all;/', $dom->getElementById($id)->getAttribute('style'));
            self::assertMatchesRegularExpression('/(?:^|;)\s*overflow-wrap:\s*anywhere;/', $dom->getElementById($id)->getAttribute('style'));
        }
        self::assertStringNotContainsString('word-break', $dom->getElementById('action')->getAttribute('style'));
    }

    private function document(bool $system = true): string
    {
        return '<!doctype html><html><head><meta charset="UTF-8"><style>/* '.TrustedEmailCss::RUNTIME_MARKER.' */'
            .'tr[data-rt-artifact-version="v27"] .column{display:table-cell!important;width:32%!important;}'
            .'@media(max-width:860px){tr[data-rt-artifact-version="v27"] .column{display:block!important;width:100%!important;}}'
            .'</style></head><body'.($system ? ' '.SystemMailInlineImageEmbedder::RUNTIME_ATTRIBUTE : '').'>'
            .'<!-- RT_TEMPLATE_MARK_START --><!-- RT_TEMPLATE_MARK_END -->'
            .'<table><tr data-rt-artifact-version="v27"><td><table><tr><th id="column" class="column" style="color:red!important;">Synthetic</th></tr></table></td></tr></table>'
            .'<!--[if mso]><img src="cid:synthetic-train" width="300" height="38"><![endif]--></body></html>';
    }

    public function test_generated_fallbacks_can_reflow_but_authored_importance_is_preserved(): void
    {
        $html = (new SystemMailCssInliner)->convert($this->document());
        $dom = new DOMDocument;
        $dom->loadHTML($html);
        $style = $dom->getElementById('column')->getAttribute('style');
        self::assertStringContainsString('display: table-cell;', $style);
        self::assertStringNotContainsString('table-cell!important', $style);
        self::assertStringContainsString('color: red!important;', $style);
        self::assertStringContainsString('tr.rt-system-signature-v27 .column{display:block!important', $html);
        self::assertStringContainsString('class="rt-system-signature-v27"', $html);
        self::assertStringNotContainsString('tr[data-rt-artifact-version=', $html);
        self::assertStringContainsString('<!--[if mso]><img src="cid:synthetic-train" width="300" height="38"><![endif]-->', $html);
    }

    public function test_non_system_html_is_byte_identical_to_stock_inliner_even_after_reuse(): void
    {
        $inliner = new SystemMailCssInliner;
        $inliner->convert($this->document());
        self::assertSame((new CssToInlineStyles)->convert($this->document(false)), $inliner->convert($this->document(false)));
    }

    public function test_all_table_delivery_versions_keep_class_scopes_and_partial_markers_do_not_opt_in(): void
    {
        foreach (['v27', 'v28', 'v29'] as $version) {
            $source = str_replace('v27', $version, $this->document());
            $result = (new SystemMailCssInliner)->convert($source);
            self::assertStringContainsString('class="rt-system-signature-'.$version.'"', $result);
            self::assertStringContainsString('tr.rt-system-signature-'.$version.' .column{display:block!important', $result);
        }
        foreach (['<!-- RT_TEMPLATE_MARK_START -->', '<!-- RT_TEMPLATE_MARK_END -->', TrustedEmailCss::RUNTIME_MARKER] as $marker) {
            $source = str_replace($marker, '', $this->document());
            self::assertSame((new CssToInlineStyles)->convert($source), (new SystemMailCssInliner)->convert($source));
        }
    }

    public function test_only_bounded_ledger_logos_are_isolated_from_retained_legacy_logo_rules(): void
    {
        foreach (['v27', 'v28', 'v29'] as $version) {
            $html = (new SystemMailCssInliner)->convert($this->deliveryDocument($version));
            $dom = $this->dom($html);
            $logo = $dom->getElementById('delivery-logo');
            self::assertSame('rt-system-delivery-logo', $logo->getAttribute('class'));
            self::assertMatchesRegularExpression('/(?:^|;)\s*width:\s*100%/', $logo->getAttribute('style'));
            self::assertMatchesRegularExpression('/(?:^|;)\s*max-width:\s*180px/', $logo->getAttribute('style'));
            self::assertSame('rt-logo', $dom->getElementById('ordinary-logo')->getAttribute('class'));
            self::assertStringContainsString('img.rt-logo{width:180px!important;}', $html);
            self::assertStringContainsString('tr.rt-system-signature-'.$version.' .rt-sign-ledger img.rt-system-delivery-logo{', $html);
            self::assertStringNotContainsString('tr.rt-system-signature-'.$version.' .rt-sign-ledger img.rt-logo{', $html);
        }
    }

    public function test_trusted_runtime_styles_have_one_complete_media_block_per_style_in_original_order(): void
    {
        $html = (new SystemMailCssInliner)->convert($this->deliveryDocument());
        $dom = $this->dom($html);
        $xpath = new DOMXPath($dom);
        $styles = [];
        foreach ($xpath->query('//style[not(@id="unrelated-css")]') as $style) {
            $styles[] = trim($style->textContent);
        }

        // Outlook's partial-style retention must not discard later rules just
        // because one style contains several top-level media blocks or a base
        // rule after a media block. Nested blocks stay together verbatim.
        self::assertCount(5, $styles);
        self::assertStringStartsWith('/* '.TrustedEmailCss::RUNTIME_MARKER.' */.plain{', $styles[0]);
        self::assertStringStartsWith('@media only screen and (max-width:1000px){', $styles[1]);
        self::assertStringEndsWith('img.rt-logo{width:180px!important;}}', $styles[1]);
        self::assertStringStartsWith('tr.rt-system-signature-v27 .rt-sign-ledger img.rt-system-delivery-logo{', $styles[2]);
        self::assertStringStartsWith('@media only screen and (max-width:860px){', $styles[3]);
        self::assertStringEndsWith('.column{display:block!important;width:100%!important;}}', $styles[3]);
        self::assertStringStartsWith('.quoted::before{content:', $styles[4]);
        self::assertStringContainsString('.quoted::before{content:"} /* @media(fake){ */ {";}', $styles[4]);
        self::assertStringContainsString('/* A comment with } and { */', $styles[4]);
        self::assertStringContainsString('@supports(display:grid){.nested{color:blue;}@media(prefers-reduced-motion:reduce){.nested{color:green;}}}', $styles[4]);
        self::assertStringEndsWith('.tail{color:red;}', $styles[4]);
        self::assertSame(1, substr_count($html, TrustedEmailCss::RUNTIME_MARKER));
        self::assertSame($this->unrelatedCss(), $dom->getElementById('unrelated-css')->textContent);
    }

    public function test_unmarked_delivery_html_stays_identical_to_stock_without_logo_or_style_projection(): void
    {
        $source = str_replace(SystemMailInlineImageEmbedder::RUNTIME_ATTRIBUTE, '', $this->deliveryDocument());
        self::assertSame((new CssToInlineStyles)->convert($source), (new SystemMailCssInliner)->convert($source));
    }

    public function test_non_ledger_logo_and_its_scoped_selectors_keep_their_existing_contract(): void
    {
        $source = str_replace('rt-sign-ledger', 'rt-sign-heading-table', $this->deliveryDocument());
        $html = (new SystemMailCssInliner)->convert($source);
        self::assertSame('rt-logo', $this->dom($html)->getElementById('delivery-logo')->getAttribute('class'));
        self::assertStringContainsString('tr.rt-system-signature-v27 .rt-sign-heading-table img.rt-logo{', $html);
        self::assertStringNotContainsString('rt-system-delivery-logo', $html);
    }

    public function test_mixed_selector_lists_do_not_rename_the_generic_legacy_logo_selector(): void
    {
        $source = str_replace('img.rt-logo{width:100%', 'img.rt-logo,img.rt-logo{width:100%', $this->deliveryDocument());
        $html = (new SystemMailCssInliner)->convert($source);
        self::assertStringContainsString('tr.rt-system-signature-v27 .rt-sign-ledger img.rt-system-delivery-logo,img.rt-logo{width:100%', $html);
    }

    private function deliveryDocument(string $version = 'v27'): string
    {
        $scope = 'tr[data-rt-artifact-version="'.$version.'"]';

        return '<!doctype html><html><head><meta charset="UTF-8"><style>'
            .'/* '.TrustedEmailCss::RUNTIME_MARKER.' */.plain{color:#142231;}'
            .'@media only screen and (max-width:1000px){img.rt-logo{width:180px!important;}}'
            .$scope.' .rt-sign-ledger img.rt-logo{width:100%!important;max-width:180px!important;height:auto!important;}'
            .'@media only screen and (max-width:860px){'.$scope.' .rt-sign-ledger img.rt-logo{max-width:175px!important;}'
            .$scope.' .column{display:block!important;width:100%!important;}}'
            .'.quoted::before{content:"} /* @media(fake){ */ {";}/* A comment with } and { */'
            .'@supports(display:grid){.nested{color:blue;}@media(prefers-reduced-motion:reduce){.nested{color:green;}}}.tail{color:red;}'
            .'</style><style id="unrelated-css">'.$this->unrelatedCss().'</style></head>'
            .'<body '.SystemMailInlineImageEmbedder::RUNTIME_ATTRIBUTE.'><!-- RT_TEMPLATE_MARK_START --><!-- RT_TEMPLATE_MARK_END -->'
            .'<img id="ordinary-logo" class="rt-logo" src="cid:ordinary-logo" width="180" height="31">'
            .'<table><tr data-rt-artifact-version="'.$version.'"><td class="rt-sign-cell">'
            .'<table data-rt-train-delivery="bounded-img-v2"><tr><td class="rt-sign-content">'
            .'<table class="rt-sign-ledger"><tr><th class="rt-ledger-brand column" width="32%" style="width:32%;padding:0 22px 0 0;">'
            .'<img id="delivery-logo" class="rt-logo" src="cid:delivery-logo" width="180" height="31" style="display:block;width:100%;max-width:180px;height:auto;">'
            .'</th><th class="rt-ledger-contacts column" width="68%" style="width:68%;padding:0 0 0 22px;">Synthetic</th></tr></table>'
            .'</td></tr></table></td></tr></table></body></html>';
    }

    private function unrelatedCss(): string
    {
        return '#unrelated{color:black;}@media(max-width:1px){#unrelated{color:green;}}';
    }

    private function dom(string $html): DOMDocument
    {
        $dom = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        try {
            self::assertTrue($dom->loadHTML($html, LIBXML_NONET));
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return $dom;
    }
}
