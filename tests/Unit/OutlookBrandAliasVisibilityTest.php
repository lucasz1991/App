<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\OutlookAddin\OutlookBrandAliasVisibility;
use PHPUnit\Framework\TestCase;

final class OutlookBrandAliasVisibilityTest extends TestCase
{
    private const IMG = '<img class="rt-client-logo oix" src="cid:railtime-abcd.png" width="180" height="31" alt="Example" style="display:block;mso-hide:all;width:100%;max-width:200px;height:auto;">';

    private const RULE = '.rts1234567890.oix,.rts1234567890 .oix{display:block;mso-hide:all;width:100%;max-width:180px;height:auto;}';

    private function document(string $extra = '', string $css = self::RULE, string $attribute = 'data-rt-outlook-signature-inline-css="1"'): string
    {
        return '<style '.$attribute.'>'.$css.'</style><div class="rt-outlook-signature rts1234567890"><table class="rt-managed-signature-v1 rts1234567890"><tbody><tr><td>'.self::IMG.$extra.'</td></tr></tbody></table></div>';
    }

    public function test_only_owned_alias_hide_is_removed_preserving_responsive_difference_and_all_other_bytes(): void
    {
        $html = $this->document('<!--[if mso]><img src="cid:railtime-abcd.png" width="180" height="31"><![endif]-->');
        $actual = OutlookBrandAliasVisibility::clear($html, [['opening' => self::IMG, 'role' => 'logo']]);
        self::assertSame(str_replace(self::RULE, str_replace('mso-hide:all;', '', self::RULE), $html), $actual);
        self::assertStringContainsString(self::IMG, $actual);
    }

    public function test_foreign_alias_member_or_external_alias_rule_is_never_mutated(): void
    {
        foreach ([$this->document('<span class="oix">Untouched</span>'), $this->document(css: self::RULE.'.oix{mso-hide:all;}'), $this->document().'<div class="rts1234567890"><img class="oix" src="cid:foreign.png"></div>', $this->document().'<img class="rts1234567890 oix" src="cid:foreign.png">'] as $html) {
            self::assertNull(OutlookBrandAliasVisibility::clear($html, [['opening' => self::IMG, 'role' => 'logo']]));
        }
    }

    public function test_authored_or_unknown_owned_stylesheet_and_global_hide_are_rejected(): void
    {
        foreach ([$this->document(attribute: 'data-rt-mail-document-css="signature"'), $this->document(attribute: 'data-unknown-css="1"'), $this->document(css: self::RULE.'img{mso-hide:all;}'), $this->document().'<style data-rt-mail-document-css="template">img.rt-client-logo{border:0;}</style>'] as $html) {
            self::assertNull(OutlookBrandAliasVisibility::clear($html, [['opening' => self::IMG, 'role' => 'logo']]));
        }
    }

    public function test_current_unaliased_mark_and_unrelated_authored_paragraph_styles_remain_byte_identical(): void
    {
        $mark = '<img class="rt-mark" src="cid:mark.png" width="44" height="44" style="mso-hide: all;">';
        $html = '<style data-rt-mail-document-css="template">.rtt123456789012 .design-spacer{font-size:16px;}</style>'.$mark;
        self::assertSame($html, OutlookBrandAliasVisibility::clear($html, [['opening' => $mark, 'role' => 'mark']]));
    }

    public function test_unknown_escaped_function_or_mso_hide_value_is_left_untouched(): void
    {
        foreach (['display:block;mso-hide:screen;', 'display:block;mso-hide:all;background:var(--x);', 'content:"mso-hide:all";mso-hide:all;'] as $body) {
            $html = $this->document(css: '.rts1234567890.oix,.rts1234567890 .oix{'.$body.'}');
            self::assertNull(OutlookBrandAliasVisibility::clear($html, [['opening' => self::IMG, 'role' => 'logo']]));
        }
    }
}
