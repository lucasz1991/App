<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Mail\EmailHtmlSanitizer;
use App\Support\Mail\NativeSignatureHandoffRenderer;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class NativeHeadCssAdmissionTest extends TestCase
{
    private const SCOPE = 'rt-handoff-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    public function test_the_exact_raw_head_css_is_preserved_byte_for_byte(): void
    {
        $css = NativeSignatureHandoffRenderer::headCssForScope(self::SCOPE);
        $html = $this->document($css);
        $report = (new EmailHtmlSanitizer)->assertClean($html);

        self::assertSame([], $report->findings);
        self::assertSame($html, $report->html);
        self::assertSame(6, substr_count($css, 'float:'));
        self::assertSame(1, substr_count($css, 'clear:'));
    }

    public function test_the_exact_full_template_style_comment_wrapper_is_preserved(): void
    {
        $template = file_get_contents(resource_path('mail-templates/native-signature-handoff-v1.html'));
        self::assertSame(1, preg_match('~<style data-rt-native-style="\{\{SCOPE_ID\}\}">(.*?)</style>~s', $template, $match));
        $css = strtr($match[1], ['{{SCOPE_ID}}' => self::SCOPE]);
        $html = $this->document($css);

        self::assertTrue(NativeSignatureHandoffRenderer::recognizesHeadStyleSheet($css, self::SCOPE));
        self::assertFalse(NativeSignatureHandoffRenderer::recognizesHeadCss($css, self::SCOPE));
        self::assertSame($html, (new EmailHtmlSanitizer)->assertClean($html)->html);
    }

    #[DataProvider('mutants')]
    public function test_mutated_css_uses_the_normal_rejection_path(string $case): void
    {
        $css = NativeSignatureHandoffRenderer::headCssForScope(self::SCOPE);
        $css = match ($case) {
            'changed-safe-value' => str_replace('120px', '121px', $css),
            'extra-safe-rule' => $css.'#'.self::SCOPE.' .new-rule{color:#ffffff;}',
            'extra-forbidden-rule' => $css.'#'.self::SCOPE.' .new-rule{float:right;clear:both;}',
            'extra-declaration' => str_replace('float:right!important;', 'float:right!important;position:fixed;', $css),
            'import' => '@import url(https://other.example.test/styles.css);'.$css,
            'extra-comment' => $css.'/* copied family */',
            'whitespace' => $css."\n",
            'foreign-selector-scope' => str_replace(self::SCOPE, 'rt-handoff-'.str_repeat('b', 32), $css),
            default => throw new \LogicException('Unknown fixture.'),
        };
        self::assertFalse(NativeSignatureHandoffRenderer::recognizesHeadStyleSheet($css, self::SCOPE));
        $report = (new EmailHtmlSanitizer)->clean($this->document($css));
        self::assertTrue($report->has('css.property.forbidden'));
        self::assertStringNotContainsString('float:', $report->html);
        self::assertStringNotContainsString('clear:', $report->html);

        $this->expectException(ValidationException::class);
        (new EmailHtmlSanitizer)->assertClean($this->document($css));
    }

    public static function mutants(): array
    {
        return array_map(static fn (string $case): array => [$case], ['changed-safe-value', 'extra-safe-rule', 'extra-forbidden-rule', 'extra-declaration', 'import', 'extra-comment', 'whitespace', 'foreign-selector-scope']);
    }

    #[DataProvider('foreignContexts')]
    public function test_missing_foreign_duplicate_or_non_head_source_contexts_are_not_admitted(string $case): void
    {
        $css = NativeSignatureHandoffRenderer::headCssForScope(self::SCOPE);
        $html = $this->document($css);
        $foreign = 'rt-handoff-'.str_repeat('b', 32);
        $html = match ($case) {
            'foreign-style-id' => str_replace('data-rt-native-style="'.self::SCOPE.'"', 'data-rt-native-style="'.$foreign.'"', $html),
            'foreign-body-id' => str_replace('id="'.self::SCOPE.'"', 'id="'.$foreign.'"', $html),
            'missing-style-marker' => str_replace(' data-rt-native-style="'.self::SCOPE.'"', '', $html),
            'malformed-scope' => str_replace('data-rt-native-style="'.self::SCOPE.'"', 'data-rt-native-style="rt-handoff-short"', $html),
            'missing-id' => str_replace(' id="'.self::SCOPE.'"', '', $html),
            'non-table-id' => str_replace('<table id="'.self::SCOPE.'"><tr><td>Content</td></tr></table>', '<div id="'.self::SCOPE.'">Content</div>', $html),
            'duplicate-id' => str_replace('</body>', '<div id="'.self::SCOPE.'">Duplicate</div></body>', $html),
            'duplicate-style' => str_replace('</head>', '<style data-rt-native-style="'.self::SCOPE.'">'.$css.'</style></head>', $html),
            'body-style' => '<html><head></head><body><style data-rt-native-style="'.self::SCOPE.'">'.$css.'</style><table id="'.self::SCOPE.'"><tr><td>Content</td></tr></table></body></html>',
            default => throw new \LogicException('Unknown fixture.'),
        };
        $report = (new EmailHtmlSanitizer)->clean($html);
        self::assertTrue($report->has('css.property.forbidden'));
        self::assertStringNotContainsString('float:', $report->html);
        self::assertStringNotContainsString('clear:', $report->html);
    }

    public static function foreignContexts(): array
    {
        return array_map(static fn (string $case): array => [$case], ['foreign-style-id', 'foreign-body-id', 'missing-style-marker', 'malformed-scope', 'missing-id', 'non-table-id', 'duplicate-id', 'duplicate-style', 'body-style']);
    }

    public function test_an_exact_stylesheet_does_not_bypass_html_attribute_or_image_source_checks(): void
    {
        $html = $this->document(NativeSignatureHandoffRenderer::headCssForScope(self::SCOPE));
        $html = str_replace('Content', '<img src="https://foreign.example.test/image.png" alt="Foreign" onerror="alert(1)">', $html);
        $report = (new EmailHtmlSanitizer)->clean($html);

        self::assertTrue($report->hasViolations());
        self::assertTrue($report->has('attribute.event'));
        self::assertFalse($report->has('css.property.forbidden'));
        self::assertStringNotContainsString('onerror', $report->html);
        self::assertStringNotContainsString('https://foreign.example.test/image.png', $report->html);
    }

    public function test_a_missing_template_resource_fails_closed_without_widening_the_generic_filter(): void
    {
        $css = NativeSignatureHandoffRenderer::headCssForScope(self::SCOPE);
        $original = $this->app->basePath();
        $this->app->setBasePath(sys_get_temp_dir().DIRECTORY_SEPARATOR.'rt-native-missing-'.bin2hex(random_bytes(16)));
        try {
            self::assertFalse(NativeSignatureHandoffRenderer::recognizesHeadCss($css, self::SCOPE));
            self::assertFalse(NativeSignatureHandoffRenderer::recognizesHeadStyleSheet($css, self::SCOPE));
            $report = (new EmailHtmlSanitizer)->clean($this->document($css));
            self::assertTrue($report->has('css.property.forbidden'));
            self::assertStringNotContainsString('float:', $report->html);
        } finally {
            $this->app->setBasePath($original);
        }
    }

    public function test_arbitrary_stylesheet_and_inline_float_clear_remain_forbidden(): void
    {
        $report = (new EmailHtmlSanitizer)->clean('<style>@media(max-width:759px){.anything{float:left;clear:both;}}</style><div style="float:right;clear:both">Content</div>');

        self::assertTrue($report->has('css.property.forbidden'));
        self::assertStringNotContainsString('float:', $report->html);
        self::assertStringNotContainsString('clear:', $report->html);
    }

    private function document(string $css): string
    {
        return '<html><head><style data-rt-native-style="'.self::SCOPE.'">'.$css.'</style></head><body><table id="'.self::SCOPE.'"><tr><td>Content</td></tr></table></body></html>';
    }
}
