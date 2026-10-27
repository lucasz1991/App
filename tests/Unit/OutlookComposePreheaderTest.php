<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\OutlookAddin\OutlookComposeFrame;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OutlookComposePreheaderTest extends TestCase
{
    private const HIDDEN = 'display:none;max-height:0;max-width:0;overflow:hidden;opacity:0;color:transparent;mso-hide:all;';

    private const ZERO = 'display:none;font-size:0;line-height:0;color:transparent;mso-hide:all;';

    private function document(bool $mobile = false): string
    {
        return '<style>.opaque{content:"<div style=hidden>";}</style><!--[if mso]><div style="'.self::HIDDEN.'">Conditional preheader</div><![endif]-->'
            .'<div class="'.($mobile ? 'x8 t2abc' : 'rt-outlook-template rtt123456789012').'">'
            .'<div style="'.self::HIDDEN.'">Ihre Nachricht von A &amp; B · &#x1F686;.<!-- untouched $1 --></div>'
            .'<table class="'.($mobile ? 'x3 t2abc' : 'rt-native-compose-frame rtt123456789012').' rt-managed-template-v1"><tr><td>Template body</td></tr></table></div>';
    }

    public static function formats(): array
    {
        return ['desktop' => [false], 'mobile aliases' => [true]];
    }

    #[DataProvider('formats')]
    public function test_only_the_managed_preheader_style_changes_and_content_stays_byte_exact(bool $mobile): void
    {
        $before = $this->document($mobile);
        $expected = str_replace('<div style="'.self::HIDDEN.'">Ihre Nachricht', '<div style="'.self::ZERO.'">Ihre Nachricht', $before);
        $actual = OutlookComposeFrame::recipientTables($before);
        self::assertSame($expected, $actual);
        self::assertSame($actual, OutlookComposeFrame::recipientTables($actual));
        self::assertLessThan(strlen($before), strlen($actual), 'Replacement removes unsupported hiding declarations without consuming the mobile budget.');
        self::assertStringContainsString('A &amp; B · &#x1F686;.<!-- untouched $1 -->', $actual);
        self::assertStringContainsString('<!--[if mso]><div style="'.self::HIDDEN.'">Conditional preheader</div><![endif]-->', $actual);
    }

    public function test_unowned_custom_nested_quoted_and_ambiguous_preheaders_remain_unchanged(): void
    {
        $before = $this->document();
        $preheader = '<div style="'.self::HIDDEN.'">Ihre Nachricht von A &amp; B · &#x1F686;.<!-- untouched $1 --></div>';
        foreach ([
            str_replace('rt-outlook-template', 'author-template', $before),
            str_replace('rt-managed-template-v1', 'author-frame', $before),
            str_replace('rt-native-compose-frame', 'author-frame', $before),
            str_replace(self::HIDDEN, self::HIDDEN.'font-size:14px;', $before),
            str_replace('max-height:0;', '', $before),
            str_replace('<div style="'.self::HIDDEN.'">Ihre Nachricht', '<div id="author" style="'.self::HIDDEN.'">Ihre Nachricht', $before),
            str_replace('Ihre Nachricht von A', '<span>Ihre Nachricht</span> von A', $before),
            str_replace($preheader, $preheader.$preheader, $before),
            str_replace($preheader, '<p>Authored content</p>'.$preheader, $before),
            '<blockquote>'.$before.'</blockquote>',
            '<div id="divRplyFwdMsg">'.$before.'</div>',
            $before.$before,
        ] as $html) {
            self::assertSame($html, OutlookComposeFrame::recipientTables($html));
        }
    }

    public function test_a_quoted_managed_template_does_not_capture_the_current_preheader(): void
    {
        $current = $this->document(true);
        $quote = '<blockquote>'.$this->document().'</blockquote>';
        self::assertSame(OutlookComposeFrame::recipientTables($current).$quote, OutlookComposeFrame::recipientTables($current.$quote));
    }
}
