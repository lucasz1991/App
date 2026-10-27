<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Mail\OutlookTemplateWording;
use App\Support\OutlookAddin\OutlookMobileWording;
use DOMDocument;
use DOMElement;
use DOMXPath;
use PHPUnit\Framework\TestCase;
use TijsVerkoyen\CssToInlineStyles\CssToInlineStyles;

final class OutlookMobileWordingTest extends TestCase
{
    private const SCOPE = 'rtt123456789abc';

    private function fixture(): string
    {
        $base = 'margin-top:0px;margin-right:0px;margin-bottom:20px;margin-left:0px;font-family:Arial, Helvetica, sans-serif;';
        $roles = ['design-salutation' => $base.'font-size:26px;line-height:34px;font-weight:bold;color:rgb(20, 34, 49);',
            'design-spacer' => $base.'font-size:16px;line-height:26px;color:rgb(20, 34, 49);',
            'design-greeting' => str_replace('margin-bottom:20px', 'margin-bottom:0px', $base).'font-size:16px;line-height:26px;color:rgb(20, 34, 49);'];
        $css = '';
        $paragraphs = '';
        foreach ($roles as $role => $style) {
            $css .= '.'.self::SCOPE.' .'.$role.'{'.$style.'}';
            $inner = $role === 'design-spacer' ? '<br><br>' : '&amp;&amp; &amp;notit; &amp;copy &amp;#123; &amp;A1;';
            if ($role === 'design-greeting') {
                $inner .= '<br>Mara Muster';
            }
            $paragraphs .= '<p class="'.$role.'" style="'.$style.($role === 'design-spacer' ? 'margin:0 0 20px;' : OutlookTemplateWording::WRAP_DECLARATIONS).'">'.$inner.'</p>';
        }

        return '<style data-rt-outlook-template-css="1">'.$css.OutlookTemplateWording::wrappingCss(self::SCOPE).'</style>'
            .'<div class="rt-outlook-template '.self::SCOPE.'"><table><tr><td class="rt-pad rt-native-template-pad" style="font-family:Arial,Helvetica,sans-serif;font-size:16px;line-height:26px;color:#142231;padding:0 40px;">'
            .$paragraphs.'</td></tr></table></div><table class="signature"><tr><td style="font-size:13px;color:#718096;"><img src="cid:train.gif" width="520" height="65">Unchanged signature</td></tr></table>';
    }

    public function test_only_known_template_styles_change_and_every_text_entity_and_signature_byte_is_preserved(): void
    {
        $before = $this->fixture();
        $after = OutlookMobileWording::project($before);
        self::assertLessThan(strlen($before), strlen($after));
        self::assertSame($this->text($before), $this->text($after));
        self::assertStringContainsString('&amp;notit; &amp;copy &amp;#123; &amp;A1;', $after);
        self::assertStringContainsString('&& ', $after);
        self::assertSame(substr($before, strpos($before, '<table class="signature">')), substr($after, strpos($after, '<table class="signature">')));
        self::assertStringContainsString('rt-wc', $after);
    }

    public function test_typography_margins_and_long_word_wrap_match_with_styles_noinline_and_nohead(): void
    {
        $before = $this->fixture();
        $after = OutlookMobileWording::project($before);
        foreach (['normal', 'noinline', 'nohead'] as $mode) {
            foreach ([$before, $after] as $html) {
                if ($mode === 'noinline') {
                    $html = preg_replace('/\sstyle="[^"]*"/', '', $html);
                } elseif ($mode === 'nohead') {
                    $html = preg_replace('~<style\b[^>]*>.*?</style>~s', '', $html);
                }
                $html = (new CssToInlineStyles)->convert($html);
                $dom = new DOMDocument;
                @$dom->loadHTML('<?xml encoding="UTF-8">'.$html);
                $xpath = new DOMXPath($dom);
                foreach (['design-salutation', 'design-spacer', 'design-greeting'] as $role) {
                    $paragraph = $xpath->query('//p[@class="'.$role.'"]')->item(0);
                    $font = [];
                    for ($node = $paragraph; $node instanceof DOMElement; $node = $node->parentNode) {
                        foreach ($this->declarations($node->getAttribute('style')) as $key => $value) {
                            $font[$key] ??= $value;
                        }
                    }
                    self::assertSame('Arial,Helvetica,sans-serif', str_replace(' ', '', $font['font-family']));
                    self::assertSame($role === 'design-salutation' ? '26px' : '16px', $font['font-size']);
                    self::assertSame($role === 'design-salutation' ? '34px' : '26px', $font['line-height']);
                    self::assertContains(str_replace(' ', '', $font['color']), ['#142231', 'rgb(20,34,49)']);
                    $own = $this->declarations($paragraph->getAttribute('style'));
                    $expected = $role === 'design-greeting' ? ['0', '0', '0', '0'] : ['0', '0', '20px', '0'];
                    if (isset($own['margin'])) {
                        $m = explode(' ', $own['margin']);
                        $actual = [$m[0], $m[1] ?? $m[0], $m[2] ?? $m[0], $m[3] ?? $m[1] ?? $m[0]];
                    } else {
                        $actual = array_map(fn ($side) => $own['margin-'.$side], ['top', 'right', 'bottom', 'left']);
                    }
                    self::assertSame($expected, array_map(fn ($value) => $value === '0px' ? '0' : $value, $actual));
                    if ($role !== 'design-spacer') {
                        self::assertSame('anywhere', $own['overflow-wrap']);
                        self::assertSame('break-word', $own['word-break']);
                    }
                }
            }
        }
    }

    public function test_unknown_styles_priority_or_scope_never_get_compacted(): void
    {
        foreach ([str_replace('font-size:16px;line-height:26px;color:#142231;padding', 'font-size:16px!important;line-height:26px;color:#142231;padding', $this->fixture()),
            str_replace('</style>', '.'.self::SCOPE.' td{font-family:Other;}</style>', $this->fixture()),
            str_replace('rt-outlook-template '.self::SCOPE, 'rt-outlook-template foreign', $this->fixture()),
            str_replace('class="design-greeting"', 'class="design-greeting other"', $this->fixture())] as $html) {
            self::assertSame($html, OutlookMobileWording::project($html));
        }
    }

    private function declarations(string $style): array
    {
        $values = [];
        foreach (explode(';', $style) as $part) {
            if (str_contains($part, ':')) {
                [$key, $value] = explode(':', $part, 2);
                $values[trim($key)] = trim(str_replace('!important', '', $value));
            }
        }

        return $values;
    }

    private function text(string $html): string
    {
        $dom = new DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$html);
        $result = [];
        foreach ($dom->getElementsByTagName('p') as $paragraph) {
            $result[] = $paragraph->textContent;
        }

        return implode('|', $result);
    }
}
