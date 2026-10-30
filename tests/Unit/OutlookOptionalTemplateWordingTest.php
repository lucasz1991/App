<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Mail\OutlookOptionalTemplateWording;
use PHPUnit\Framework\TestCase;

final class OutlookOptionalTemplateWordingTest extends TestCase
{
    private const SOURCE = '<table><tr><td><!-- RT_APPLICATION_CONTENT_START --><p class="design-spacer" style="margin:0;"><br><br></p><!-- RT_APPLICATION_CONTENT_END --></td></tr></table>';

    public function test_all_four_visibility_states_preserve_the_exact_writing_anchor_and_escape_plain_text(): void
    {
        foreach ([false, true] as $greeting) {
            foreach ([false, true] as $farewell) {
                $html = OutlookOptionalTemplateWording::render(self::SOURCE,
                    ['showGreeting' => $greeting, 'showFarewell' => $farewell, 'greeting' => 'Hallo & <Test>', 'farewell' => 'Beste Grüße,'], 'Mara <Name>');
                self::assertSame((int) $greeting, substr_count($html, 'class="design-salutation"'));
                self::assertSame((int) $farewell, substr_count($html, 'class="design-greeting"'));
                self::assertStringContainsString('<p class="design-spacer" style="margin:0;"><br><br></p>', $html);
                if ($greeting) {
                    self::assertStringContainsString('Hallo &amp; &lt;Test&gt;', $html);
                    self::assertLessThan(strpos($html, 'design-spacer'), strpos($html, 'design-salutation'));
                }
                if ($farewell) {
                    self::assertStringContainsString('Beste Grüße,<br>Mara &lt;Name&gt;', $html);
                    self::assertGreaterThan(strpos($html, 'design-spacer'), strpos($html, 'design-greeting'));
                }
                if (! $greeting && ! $farewell) {
                    self::assertSame(self::SOURCE, $html);
                }
            }
        }
        self::assertSame(self::SOURCE, OutlookOptionalTemplateWording::render(self::SOURCE, null, 'Mara'));
        self::assertTrue(OutlookOptionalTemplateWording::eligible(str_replace('<br><br>', '{{APPLICATION_CONTENT}}', self::SOURCE)));
    }

    public function test_compaction_preserves_dom_text_and_computed_margins_including_entity_prefixes_bidi_and_priority(): void
    {
        foreach (['&&&&', '&copy; &notit; &a &#123; &#x41;', '"'."'".' & 🚂', "\u{202E}א&\u{2067}ع"] as $text) {
            $preferences = ['showGreeting' => true, 'showFarewell' => true, 'greeting' => $text, 'farewell' => $text];
            $html = OutlookOptionalTemplateWording::render(self::SOURCE, $preferences, 'Mara & Name');
            $html = str_replace('style="margin:0 0 20px;', 'style="margin-top:0px;margin-right:0px;margin-bottom:20px;margin-left:0px;margin:0 0 20px;', $html);
            $next = OutlookOptionalTemplateWording::compactGenerated($html, self::SOURCE, $preferences, 'Mara & Name');
            self::assertSame($this->paragraphFacts($html), $this->paragraphFacts($next));
            self::assertStringNotContainsString('style="margin-top:0px;', $next);
            self::assertStringContainsString('margin-top:20px;', $next, 'The later farewell margin remains effective.');
            self::assertStringContainsString('<p class="design-spacer" style="margin:0;"><br><br></p>', $next);
            self::assertSame($next, OutlookOptionalTemplateWording::compactGenerated($next, self::SOURCE, $preferences, 'Mara & Name'));
            $important = str_replace('style="margin-top:0px;', 'style="margin-left:0!important;margin-top:0px;', $html);
            $result = OutlookOptionalTemplateWording::compactGenerated($important, self::SOURCE, $preferences, 'Mara & Name');
            self::assertSame($this->paragraphFacts($important), $this->paragraphFacts($result));
            self::assertStringContainsString('margin-left:0!important;', $result);
        }
    }

    public function test_compaction_never_rewrites_unproven_text_or_other_paragraphs(): void
    {
        $preferences = ['showGreeting' => true, 'showFarewell' => false, 'greeting' => 'Hello &&'];
        $html = OutlookOptionalTemplateWording::render(self::SOURCE, $preferences, 'Mara');
        $unexpected = str_replace('Hello', 'Authored', $html);
        self::assertSame($unexpected, OutlookOptionalTemplateWording::compactGenerated($unexpected, self::SOURCE, $preferences, 'Mara'));
        self::assertSame($html, OutlookOptionalTemplateWording::compactGenerated($html, '<p>Foreign</p>', $preferences, 'Mara'));
    }

    private function paragraphFacts(string $html): array
    {
        $document = new \DOMDocument('1.0', 'UTF-8');
        $before = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($before);
        $result = [];
        foreach ($document->getElementsByTagName('p') as $paragraph) {
            $margins = [];
            $important = [];
            foreach (explode(';', $paragraph->getAttribute('style')) as $declaration) {
                if (! str_contains($declaration, ':')) {
                    continue;
                }
                [$key, $value] = array_map('trim', explode(':', $declaration, 2));
                $priority = str_contains($value, '!important');
                $value = trim(str_replace('!important', '', $value));
                if ($key === 'margin') {
                    $parts = preg_split('/\s+/', $value);
                    $values = [$parts[0], $parts[1] ?? $parts[0], $parts[2] ?? $parts[0], $parts[3] ?? ($parts[1] ?? $parts[0])];
                    foreach (['top', 'right', 'bottom', 'left'] as $index => $side) {
                        if (! ($important[$side] ?? false) || $priority) {
                            $margins[$side] = preg_replace('/^0px$/', '0', $values[$index]);
                            $important[$side] = $priority;
                        }
                    }
                } elseif (str_starts_with($key, 'margin-')) {
                    $side = substr($key, 7);
                    if (! ($important[$side] ?? false) || $priority) {
                        $margins[$side] = preg_replace('/^0px$/', '0', $value);
                        $important[$side] = $priority;
                    }
                }
            }
            ksort($margins);
            ksort($important);
            $result[] = [$paragraph->getAttribute('class'), $paragraph->textContent, $margins, $important];
        }

        return $result;
    }

    public function test_authored_content_quoted_hidden_duplicate_or_unmarked_html_is_never_wrapped(): void
    {
        $preferences = ['showGreeting' => true, 'showFarewell' => true];
        foreach ([str_replace('<br><br>', 'User text', self::SOURCE), self::SOURCE.self::SOURCE,
            '<blockquote>'.self::SOURCE.'</blockquote>', str_replace('<td>', '<td hidden>', self::SOURCE),
            str_replace('RT_APPLICATION_CONTENT_START', 'OTHER', self::SOURCE),
            str_replace('<br><br>', '<span></span>', self::SOURCE),
            str_replace('<p class="design-spacer"', '<p class="design-greeting"></p><p class="design-spacer"', self::SOURCE)] as $html) {
            self::assertFalse(OutlookOptionalTemplateWording::eligible($html));
            self::assertSame($html, OutlookOptionalTemplateWording::render($html, $preferences, 'Mara'));
        }
    }
}
