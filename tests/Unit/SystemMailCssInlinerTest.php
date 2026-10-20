<?php

namespace Tests\Unit;

use App\Support\Mail\SystemMailCssInliner;
use App\Support\Mail\SystemMailInlineImageEmbedder;
use App\Support\Mail\TrustedEmailCss;
use DOMDocument;
use PHPUnit\Framework\TestCase;
use TijsVerkoyen\CssToInlineStyles\CssToInlineStyles;

final class SystemMailCssInlinerTest extends TestCase
{
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
}
