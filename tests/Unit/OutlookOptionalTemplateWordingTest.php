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
