<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\OutlookAddin\OutlookCombinedComposeDocument;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class OutlookStandaloneSignatureOwnerTest extends TestCase
{
    private const FRAME = '<table class="rt-shell oi2" role="presentation" width="100%" cellpadding="0" cellspacing="0" style="width:100%;border-left:6px solid #e90032;">';

    private function signature(): string
    {
        $metadata = '';
        foreach (['RT-SIGNATURE-MANAGED-V1', 'RT-SIGNATURE-VERSION:0123456789abcdef'] as $value) {
            $metadata .= '<!-- '.$value.' --><span hidden aria-hidden="true" class="rt-office-metadata" style="display:none;font-size:0;line-height:0;">'.$value.'</span>';
        }

        return '<div class="rt-outlook-signature rts1234567890 oi1 rt-dflow-host-v4 rt-df4abcdef0123">'
            .'<style data-rt-outlook-signature-css="1">.rts1234567890 .oi2{width:100%!important;}/* rt-managed-signature-v1 is not an owner */</style>'
            .self::FRAME.'<tbody><tr><td class="rt-sign-cell" width="100%"><table data-rt-train-delivery="bounded-img-v2"><tbody><tr><td>'
            .'<img src="cid:original.png" width="180" height="31" alt="Example">'
            .'<!--[if !mso]><!--><img src="cid:original.gif" width="300" height="38"><!--<![endif]-->'
            .'<!--[if mso]><img src="cid:original-train.png" width="220" height="28"><![endif]-->'
            .'</td></tr></tbody></table></td></tr><tr><td class="rt-pad" width="100%" style="color:#718096;">Legal'.$metadata.'</td></tr></tbody></table></div>';
    }

    public function test_only_the_frame_opening_changes_and_scopes_are_idempotent_without_css_or_media_changes(): void
    {
        $html = $this->signature();
        $frame = str_replace('class="rt-shell oi2"', 'class="rt-shell oi2 rt-managed-signature-v1 rts1234567890 rt-dflow-host-v4 rt-df4abcdef0123"', self::FRAME);
        $expected = str_replace(self::FRAME, $frame, $html);
        self::assertSame($expected, OutlookCombinedComposeDocument::stabilizeStandaloneSignature($html));
        self::assertSame($expected, OutlookCombinedComposeDocument::stabilizeStandaloneSignature($expected));
        self::assertLessThan(110, strlen($expected) - strlen($html), 'Only incoming owner class tokens consume transport space.');
        self::assertStringNotContainsString('rt-outlook-signature rts1234567890 oi1 rt-dflow-host-v4 rt-df4abcdef0123 rt-managed-signature-v1', $expected);
    }

    public function test_combined_mobile_and_unmanaged_or_wider_fragments_remain_exactly_unchanged(): void
    {
        $html = $this->signature();
        $cases = [
            '<table><colgroup><col></colgroup><tr><td>Unmanaged</td></tr></table>',
            '<div class="rt-managed-combined-v1">'.$html.'</div>',
            '<div data-rt-compose-document="combined-native-v1">'.$html.'</div>',
            str_replace('rt-shell oi2', 'rt-shell oi2 rt-mobile-ledger', $html),
            $html.'<img class="rt-logo" src="cid:ordinary.png">',
            '<div class="rt-outlook-signature rts1234567890"><table><tbody><tr><td>Legacy RT-SIGNATURE-MANAGED-V1</td></tr></tbody></table></div>',
        ];
        foreach ($cases as $candidate) {
            self::assertSame($candidate, OutlookCombinedComposeDocument::stabilizeStandaloneSignature($candidate));
        }
    }

    private function personalSignature(string $style = 'width:100%;direction:ltr;padding:16px 0 0;color:#142231;'): string
    {
        return preg_replace_callback('~<table data-rt-train-delivery="bounded-img-v2"><tbody><tr><td>(.*?)</td></tr></tbody></table>~s', static fn (array $match): string => '<table class="rt-sign-content-frame" data-rt-train-delivery="bounded-img-v2"><tbody><tr><td class="rt-sign-content" style="'.$style.'">'
            .'<table class="rt-personal-columns" data-rt-personal-flow="v1" data-rt-desktop-flow="outlook-desktop-flow-v4"><tbody><tr><td>'
            .$match[1].'</td></tr></tbody></table></td></tr></tbody></table>', $this->signature());
    }

    public function test_only_verified_personal_inset_becomes_important_without_changing_css_media_or_other_inline_values(): void
    {
        $html = $this->personalSignature();
        $output = OutlookCombinedComposeDocument::stabilizeStandaloneSignature($html);
        $frame = str_replace('class="rt-shell oi2"', 'class="rt-shell oi2 rt-managed-signature-v1 rts1234567890 rt-dflow-host-v4 rt-df4abcdef0123"', self::FRAME);
        self::assertSame(str_replace([self::FRAME, 'padding:16px 0 0;'], [$frame, 'padding:16px 0 0!important;'], $html), $output);
        self::assertSame($output, OutlookCombinedComposeDocument::stabilizeStandaloneSignature($output));
        preg_match_all('~<style\b[^>]*>(.*?)</style>~s', $html, $before);
        preg_match_all('~<style\b[^>]*>(.*?)</style>~s', $output, $after);
        self::assertSame($before, $after, 'The CSS budget and all existing rules remain byte-identical.');
        foreach (['<div class="rt-managed-combined-v1">', '<div data-rt-compose-document="combined-native-v1">'] as $wrapper) {
            $candidate = $wrapper.$html.'</div>';
            self::assertSame($candidate, OutlookCombinedComposeDocument::stabilizeStandaloneSignature($candidate));
        }
    }

    public function test_unknown_or_mixed_padding_and_an_unverified_content_frame_keep_their_original_inline_styles(): void
    {
        $frame = str_replace('class="rt-shell oi2"', 'class="rt-shell oi2 rt-managed-signature-v1 rts1234567890 rt-dflow-host-v4 rt-df4abcdef0123"', self::FRAME);
        foreach (['padding:25px 0 0;', 'padding:16px 0 0;padding-top:25px;', 'padding:16px 0 0!important;'] as $style) {
            $html = $this->personalSignature('width:100%;'.$style.'color:#142231;');
            self::assertSame(str_replace(self::FRAME, $frame, $html), OutlookCombinedComposeDocument::stabilizeStandaloneSignature($html));
        }
        $html = str_replace('class="rt-sign-content-frame"', 'class="ordinary-content-frame"', $this->personalSignature());
        self::assertSame(str_replace(self::FRAME, $frame, $html), OutlookCombinedComposeDocument::stabilizeStandaloneSignature($html));
    }

    public static function contradictions(): array
    {
        return [['foreign-scope'], ['duplicate-owner'], ['duplicate-metadata'], ['visible-metadata'], ['wrong-frame'], ['quoted-owner']];
    }

    #[DataProvider('contradictions')]
    public function test_a_candidate_with_contradictory_scope_frame_or_metadata_cannot_acquire_an_owner(string $mutation): void
    {
        $html = $this->signature();
        $html = match ($mutation) {
            'foreign-scope' => str_replace('rt-shell oi2', 'rt-shell oi2 rtsffffffffff', $html),
            'duplicate-owner' => str_replace('class="rt-sign-cell"', 'class="rt-sign-cell rt-managed-signature-v1"', $html),
            'duplicate-metadata' => str_replace('Legal', 'LegalRT-SIGNATURE-MANAGED-V1', $html),
            'visible-metadata' => str_replace('<span hidden ', '<span ', $html),
            'wrong-frame' => str_replace('role="presentation"', 'role="table"', $html),
            'quoted-owner' => '<blockquote>'.$html.'</blockquote>',
        };
        $this->expectException(RuntimeException::class);
        OutlookCombinedComposeDocument::stabilizeStandaloneSignature($html);
    }
}
