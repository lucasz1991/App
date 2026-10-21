<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\OutlookAddin\OutlookMobileHtmlSerialization;
use DOMDocument;
use DOMXPath;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OutlookMobileHtmlSerializationTest extends TestCase
{
    public function test_html_attribute_values_links_class_identifiers_media_and_conditional_bytes_are_preserved(): void
    {
        $conditional = '<!--[if mso]><img width="300" height="38" src="cid:original.gif" style="width:300px;"><![endif]-->';
        $input = '<table class="xz xo m1" width="100%" role="presentation" cellpadding="0" border="0" cellspacing="0"><tr><td valign="top" align="left" style="padding: 0pt; background-color: #ffffff;">'
            .'<a href="https://example.test/?a=1&amp;b=2" title=\'Literal width="44" and style="padding: 1px;"\'>Text</a>'
            .'<img alt="Company logo" src="cid:original.png" width="175" height="30"/></td></tr></table>'.$conditional;
        $output = OutlookMobileHtmlSerialization::project($input);
        $this->assertStringContainsString('width=100% role=presentation cellpadding=0 border=0 cellspacing=0', $output);
        $this->assertStringContainsString('height=30 />', $output);
        $this->assertStringContainsString($conditional, $output);
        $this->assertStringContainsString('title=\'Literal width="44" and style="padding: 1px;"\'', $output);
        $before = $this->xpath($input);
        $after = $this->xpath($output);
        $this->assertSame($before->query('//*')->length, $after->query('//*')->length);
        foreach ($before->query('//*') as $index => $node) {
            $current = $after->query('//*')->item($index);
            $this->assertSame($node->nodeName, $current->nodeName);
            $this->assertSame($node->attributes->length, $current->attributes->length);
            foreach ($node->attributes as $attribute) {
                if ($attribute->name !== 'style') {
                    $this->assertSame($attribute->value, $current->getAttribute($attribute->name));
                }
            }
        }
        $this->assertSame($before->query('//body')->item(0)->textContent, $after->query('//body')->item(0)->textContent);
        $this->assertStringContainsString('style="padding:0;background-color:#fff"', $output);
        $this->assertSame($output, OutlookMobileHtmlSerialization::project($output));
    }

    public function test_only_equivalent_declaration_serialization_changes_without_reordering_fallbacks_or_rules(): void
    {
        $css = '/* Unchanged .x3 */.scope .x3 { padding: 0pt 12px; color: #ffffff !important; width: auto; width: 100%; }'
            .'@media only screen and (max-width: 480px){.scope .x3{padding: 0px 18px; color:#ffffff;}}';
        $input = '<style data-rt-outlook-mobile-css="1">'.$css.'</style>';
        $expected = '<style data-rt-outlook-mobile-css="1">/* Unchanged .x3 */.scope .x3 {padding:0 12px;color:#fff!important;width:auto;width:100%}'
            .'@media only screen and (max-width: 480px){.scope .x3{padding:0 18px;color:#fff}}</style>';
        $this->assertSame($expected, OutlookMobileHtmlSerialization::project($input));
    }

    public static function opaqueDeclarations(): array
    {
        return [
            ['width: calc(100% - 2px); color: #ffffff;'],
            ['content:"a; padding: 0px;";color: #ffffff;'],
            ['font-family:Escaped\\ Font; color: #ffffff;'],
            ['color: #ffffff; /* Must stay exact */'],
            ['--custom: 0px; width: 0px;'],
            ['broken declaration; width: 0px;'],
            ['content:"unterminated; width: 0px;'],
        ];
    }

    #[DataProvider('opaqueDeclarations')]
    public function test_complex_or_unknown_declaration_lists_remain_byte_identical(string $css): void
    {
        $input = '<style>.scope{'.$css.'}</style><p style=\''.$css.'\'>Body</p>';
        $this->assertSame($input, OutlookMobileHtmlSerialization::project($input));
    }

    public function test_only_exact_generated_contact_typography_pair_is_removed_and_every_distinct_fallback_remains(): void
    {
        $prefix = "color:#667588;font-family:'Trebuchet MS',Arial,sans-serif;font-size:13px;line-height:20px;word-break:break-all;overflow-wrap:anywhere;";
        $suffix = ';font-size:13px;line-height:20px;word-break:normal;overflow-wrap:anywhere;';
        $input = '<td class="xg xk m2" style="'.$prefix.$suffix.'">Body</td>';
        $output = OutlookMobileHtmlSerialization::project($input);
        $this->assertSame(1, substr_count($output, 'font-size:13px'));
        $this->assertSame(1, substr_count($output, 'line-height:20px'));
        $this->assertSame(1, substr_count($output, 'overflow-wrap:anywhere'));
        $this->assertStringContainsString('word-break:break-all;font-size:13px;line-height:20px;word-break:normal;overflow-wrap:anywhere', $output);
        foreach (['font:inherit;', 'all:unset;', 'font-size:15px;', 'line-height:21px;'] as $intervening) {
            $changed = '<td class="xg" style="'.$prefix.$intervening.$suffix.'">Body</td>';
            $this->assertSame(2, substr_count(OutlookMobileHtmlSerialization::project($changed), 'font-size:13px'), $intervening);
            $this->assertSame(2, substr_count(OutlookMobileHtmlSerialization::project($changed), 'line-height:20px'), $intervening);
        }
        $notContact = str_replace('class="xg xk m2"', 'class="authored"', $input);
        $this->assertSame(2, substr_count(OutlookMobileHtmlSerialization::project($notContact), 'font-size:13px'));
        $priority = str_replace('font-size:13px;', 'font-size:13px!important;', $input);
        $this->assertSame(2, substr_count(OutlookMobileHtmlSerialization::project($priority), 'font-size:13px!important'));
    }

    public function test_identical_wrap_repetition_is_not_confused_with_fallback_priority_or_alias_reset(): void
    {
        $input = '<style>.a{overflow-wrap:anywhere!important;color:#142231;overflow-wrap:anywhere!important;}.b{overflow-wrap:break-word;overflow-wrap:anywhere;}'
            .'.c{overflow-wrap:anywhere;overflow-wrap:anywhere!important;}.d{overflow-wrap:anywhere;word-wrap:normal;overflow-wrap:anywhere;}'
            .'.e{overflow-wrap:anywhere;all:unset;overflow-wrap:anywhere;}</style>';
        $output = OutlookMobileHtmlSerialization::project($input);
        $this->assertStringContainsString('.a{color:#142231;overflow-wrap:anywhere!important}', $output);
        foreach (['.b{overflow-wrap:break-word;overflow-wrap:anywhere}', '.c{overflow-wrap:anywhere;overflow-wrap:anywhere!important}',
            '.d{overflow-wrap:anywhere;word-wrap:normal;overflow-wrap:anywhere}', '.e{overflow-wrap:anywhere;all:unset;overflow-wrap:anywhere}'] as $fallback) {
            $this->assertStringContainsString($fallback, $output);
        }
    }

    public static function attributeObservers(): array
    {
        return [
            ['<style>.scope{content:attr(style);}</style>'],
            ['<style>.scope{content:attr(class);}</style>'],
            ['<style>.scope{content:a\\74tr(style);}</style>'],
            ['<p style="content:attr(style);">Observer</p>'],
            ['<p style="content:a\\74tr(style);">Observer</p>'],
        ];
    }

    #[DataProvider('attributeObservers')]
    public function test_literal_attribute_observers_skip_the_whole_serialization(string $observer): void
    {
        $html = $observer.'<table width="100%" style="color: #ffffff; padding: 0pt;"><tr><td>Preserve</td></tr></table>';
        $this->assertSame($html, OutlookMobileHtmlSerialization::project($html));
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
}
