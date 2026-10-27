<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\OutlookAddin\OutlookBrandedSignature;
use DOMDocument;
use DOMXPath;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class OutlookCompactRecipientContactSpacingTest extends TestCase
{
    #[DataProvider('formats')]
    public function test_only_owned_contact_leaves_become_compact_and_remain_idempotent(bool $mobile): void
    {
        $source = $this->document($mobile);
        $output = OutlookBrandedSignature::compactRecipientContactSpacing($source);
        self::assertNotSame($source, $output);
        self::assertSame($output, OutlookBrandedSignature::compactRecipientContactSpacing($output));
        self::assertSame($this->content($source), $this->content($output));
        self::assertStringContainsString('<p class="rt-personal-role" style="font-size:10px;line-height:16px;">ROLE</p>', $output);
        self::assertStringContainsString('<div class="rt-personal-direct" style="padding-top:14px;">', $output);
        self::assertStringContainsString('<td style="padding:16px 0 0;">', $output);
        self::assertStringContainsString('<td style="padding:12px 40px;">Legal</td>', $output);
        self::assertStringContainsString('<style data-rt-outlook-mobile-css="1">.old{line-height:20px!important;padding-bottom:4px!important}</style>', $output);
        $xpath = $this->xpath($output);
        foreach ($xpath->query('//td[contains(concat(" ",normalize-space(@class)," ")," rt-contact-text ") or contains(concat(" ",normalize-space(@class)," ")," xg ")]') as $cell) {
            $style = $cell->getAttribute('style');
            self::assertStringContainsString('line-height:16px!important;', $style);
            self::assertStringNotContainsString('font-size:', $style);
            self::assertSame(1, substr_count($style, 'word-break:normal;'));
            self::assertSame(1, substr_count($style, 'overflow-wrap:anywhere;'));
            $paragraph = $xpath->query('./p', $cell)->item(0);
            self::assertStringContainsString('font-size:13px!important;line-height:16px!important;', $paragraph->getAttribute('style'));
            self::assertStringContainsString('word-break:break-all;', $paragraph->getAttribute('style'));
            self::assertStringNotContainsString('overflow-wrap:', $paragraph->getAttribute('style'));
            self::assertStringContainsString($cell->getAttribute('data-last') === '1' ? 'padding:0 0 0 9px;' : 'padding:0 0 1px 9px!important;', $style);
        }
        foreach ($xpath->query('//td[contains(concat(" ",normalize-space(@class)," ")," rt-contact-icon ") or contains(concat(" ",normalize-space(@class)," ")," xh ")]') as $cell) {
            self::assertStringContainsString('font-size:0;line-height:0;', $cell->getAttribute('style'));
            self::assertStringContainsString($cell->getAttribute('data-last') === '1' ? 'padding:0;' : 'padding:0 0 1px!important;', $cell->getAttribute('style'));
        }
        self::assertLessThan(strlen($source) + 250, strlen($output));
    }

    public static function formats(): iterable
    {
        yield 'desktop semantics' => [false];
        yield 'compact mobile aliases' => [true];
    }

    public function test_quoted_contacts_are_byte_exact_and_quote_only_input_is_a_noop(): void
    {
        $quote = '<blockquote>'.$this->document(false).'</blockquote>';
        $source = $this->document(true).$quote;
        $output = OutlookBrandedSignature::compactRecipientContactSpacing($source);
        self::assertStringEndsWith($quote, $output);
        self::assertSame($quote, OutlookBrandedSignature::compactRecipientContactSpacing($quote));
        $boundary = '<a name="_MailOriginal"></a>'.$this->document(false);
        self::assertSame($boundary, OutlookBrandedSignature::compactRecipientContactSpacing($boundary));
    }

    public function test_authored_nonpersonal_legacy_and_unknown_leaf_styles_are_preserved(): void
    {
        $source = $this->document(false);
        foreach ([
            '<style data-rt-mail-document-css="signature">p{line-height:24px}</style>'.$source,
            '<style>.authored{line-height:24px}</style>'.$source,
            str_replace('outlook-desktop-flow-v7', 'outlook-desktop-flow-v6', $source),
            str_replace('data-rt-personal-flow="v1"', 'data-rt-personal-flow="custom"', $source),
            str_replace('font-size:13px;', 'font-size:15px;', $source),
            str_replace('line-height:20px;', 'line-height:24px;', $source),
            str_replace('padding:0 0 4px;', 'padding:0 0 8px;', $source),
        ] as $unowned) {
            self::assertSame($unowned, OutlookBrandedSignature::compactRecipientContactSpacing($unowned));
        }
    }

    public function test_padding_priority_is_resolved_before_replacing_a_nonlast_row(): void
    {
        $source = str_replace('padding:0 0 4px;', 'padding:0 0 4px;padding-bottom:0!important;', $this->document(false));
        $output = OutlookBrandedSignature::compactRecipientContactSpacing($source);
        self::assertStringContainsString('padding:0 0 4px;padding-bottom:0!important;', $output);
        self::assertStringNotContainsString('padding:0 0 1px!important;', $output);
    }

    public function test_unknown_overflow_wrap_is_retained_on_the_paragraph(): void
    {
        $source = str_replace('overflow-wrap:anywhere;font-size:13px;line-height:20px;word-break:normal;overflow-wrap:anywhere;', 'overflow-wrap:normal;font-size:13px;line-height:20px;word-break:normal;overflow-wrap:normal;', $this->document(false));
        $output = OutlookBrandedSignature::compactRecipientContactSpacing($source);
        self::assertSame(6, substr_count($output, 'word-break:break-all;overflow-wrap:anywhere;'));
        self::assertSame(12, substr_count($output, 'overflow-wrap:normal;'));
    }

    public function test_recognized_ambiguous_owned_structure_fails_closed(): void
    {
        $this->expectException(RuntimeException::class);
        OutlookBrandedSignature::compactRecipientContactSpacing(str_replace('<p class="rt-delivery-contact-value"', '<p>Unowned sibling</p><p class="rt-delivery-contact-value"', $this->document(false)));
    }

    private function document(bool $mobile): string
    {
        $rows = '';
        foreach ([false, false, true] as $last) {
            $rows .= '<tr><td class="rt-contact-icon" data-last="'.(int) $last.'" style="width:17px;padding:'.($last ? '0' : '0 0 4px').';font-size:0;line-height:0;"><img src="cid:icon.png" width="17" height="17" alt=""></td>'
                .'<td class="rt-contact-text" data-last="'.(int) $last.'" style="padding:0 0 '.($last ? '0' : '4px').' 9px;font-size:13px;line-height:18px;word-break:normal;overflow-wrap:anywhere;font-size:13px;line-height:20px;word-break:normal;overflow-wrap:anywhere;">'
                .'<p class="rt-delivery-contact-value" style="margin:0;word-break:break-all;overflow-wrap:anywhere;"><a href="mailto:person@example.test">Contact<br>Second line</a></p></td></tr>';
        }
        $html = '<style data-rt-outlook-mobile-css="1">.old{line-height:20px!important;padding-bottom:4px!important}</style><div class="rt-outlook-signature"><table><tr data-rt-artifact-version="v27"><td style="padding:16px 0 0;">'
            .'<table data-rt-desktop-flow="outlook-desktop-flow-v7" data-rt-personal-flow="v1"><tr><td style="font-size:13px;line-height:18px;"><p class="rt-personal-role" style="font-size:10px;line-height:16px;">ROLE</p>'
            .'<div class="rt-personal-direct" style="padding-top:14px;"><table class="rt-contact"><tbody>'.$rows.'</tbody></table></div>'
            .'<div class="rt-personal-company-contacts"><table class="rt-contact rt-company-contact"><tbody>'.$rows.'</tbody></table></div></td></tr></table></td></tr><tr><td style="padding:12px 40px;">Legal</td></tr></table></div>';

        return $mobile ? strtr($html, ['data-rt-desktop-flow="outlook-desktop-flow-v7" data-rt-personal-flow="v1"' => 'class="rt-mobile-hybrid-v2" data-rt-mobile-hybrid="v2"', 'rt-outlook-signature' => 'xz', 'rt-contact-icon' => 'xh', 'rt-contact-text' => 'xg', 'rt-delivery-contact-value' => 'xt', 'rt-company-contact' => 'xj', 'rt-contact' => 'xe']) : $html;
    }

    private function xpath(string $html): DOMXPath
    {
        $dom = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML($html, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new DOMXPath($dom);
    }

    private function content(string $html): array
    {
        $xpath = $this->xpath($html);
        $content = [];
        foreach ($xpath->query('//img|//a|//text()[not(ancestor::style)]') as $node) {
            $content[] = $node->nodeName === 'img' ? $node->getAttribute('src').$node->getAttribute('width').$node->getAttribute('height') : ($node->nodeName === 'a' ? $node->getAttribute('href') : $node->nodeValue);
        }

        return $content;
    }
}
