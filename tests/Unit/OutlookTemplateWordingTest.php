<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Mail\OutlookTemplateWording;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class OutlookTemplateWordingTest extends TestCase
{
    private const SOURCE = '<table><tr><td><p class="design-salutation" style="margin:0;">{{OUTLOOK_ANREDE}}</p><p class="design-spacer"><br><br></p><p class="design-greeting" style="color:#123456;">{{OUTLOOK_GRUSS}}</p></td></tr></table>';

    public function test_only_explicit_roles_consume_defaults_and_append_the_canonical_name(): void
    {
        self::assertSame('<p>Guten Tag,</p>', OutlookTemplateWording::render('<p>Guten Tag,</p>'));
        $rendered = OutlookTemplateWording::render(self::SOURCE, null, 'Mara Muster');
        self::assertSame(strtr(self::SOURCE, [OutlookTemplateWording::GREETING_TOKEN => 'Guten Tag,',
            OutlookTemplateWording::FAREWELL_TOKEN => 'Mit freundlichen Grüßen,<br>Mara Muster']), $rendered);
        self::assertStringContainsString('>Mit freundlichen Grüßen,</p>', OutlookTemplateWording::render(self::SOURCE));
    }

    public function test_plain_text_is_escaped_once_and_never_becomes_another_template_substitution(): void
    {
        $rendered = OutlookTemplateWording::render(self::SOURCE, ['greeting' => 'Hallo & <script>bad</script>,',
            'farewell' => '{{SIGNATURE_BLOCK}} & {{OUTLOOK_ANREDE}}'], 'Mara "<Name>"');
        self::assertStringContainsString('Hallo &amp; &lt;script&gt;bad&lt;/script&gt;,', $rendered);
        self::assertStringContainsString('{{SIGNATURE_BLOCK}} &amp; {{OUTLOOK_ANREDE}}<br>Mara &quot;&lt;Name&gt;&quot;', $rendered);
        self::assertStringNotContainsString('<script>', $rendered);
    }

    public function test_malformed_partial_attribute_nested_entity_or_ambiguous_optins_are_rejected(): void
    {
        $cases = [
            str_replace(OutlookTemplateWording::FAREWELL_TOKEN, 'Literal', self::SOURCE),
            self::SOURCE.self::SOURCE,
            str_replace('design-salutation', 'other', self::SOURCE),
            str_replace(OutlookTemplateWording::GREETING_TOKEN, '<span>{{OUTLOOK_ANREDE}}</span>', self::SOURCE),
            str_replace(OutlookTemplateWording::FAREWELL_TOKEN, '</td><td>{{OUTLOOK_GRUSS}}', self::SOURCE),
            str_replace(OutlookTemplateWording::GREETING_TOKEN, '{{OUTLOOK_GRUSS}}', str_replace(OutlookTemplateWording::FAREWELL_TOKEN, '{{OUTLOOK_ANREDE}}', self::SOURCE)),
            str_replace(OutlookTemplateWording::GREETING_TOKEN, '&#123;&#123;OUTLOOK_ANREDE&#125;&#125;', self::SOURCE).'<p title="{{OUTLOOK_ANREDE}}">x</p>',
            str_replace(OutlookTemplateWording::GREETING_TOKEN, '&#123;&#123;OUTLOOK_ANREDE&#125;&#125;', self::SOURCE).'<p data-class="design-salutation">{{OUTLOOK_ANREDE}}</p>',
            str_replace(OutlookTemplateWording::GREETING_TOKEN, '&#123;&#123;OUTLOOK_ANREDE&#125;&#125;', self::SOURCE).'<!--[if mso]><p class="design-salutation">{{OUTLOOK_ANREDE}}</p><![endif]-->',
        ];
        foreach ($cases as $source) {
            try {
                OutlookTemplateWording::render($source);
                self::fail('Malformed explicit wording must not render.');
            } catch (RuntimeException $error) {
                self::assertNotSame('', $error->getMessage());
            }
        }
    }
}
