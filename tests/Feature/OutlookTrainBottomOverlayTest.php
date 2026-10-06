<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Mail\OutlookSignatureInlineStyle;
use App\Support\Mail\SignatureTableOverlap;
use App\Support\Mail\SignatureTableOverlapDelivery;
use App\Support\Mail\TrustedOutlookSignatureCss;
use App\Support\OutlookAddin\OutlookMobileSignature;
use App\Support\OutlookAddin\OutlookTrainBottomOverlay;
use DOMDocument;
use DOMXPath;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

final class OutlookTrainBottomOverlayTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        self::assertSame('sqlite', config('database.default'));
        self::assertSame(':memory:', config('database.connections.sqlite.database'));
    }

    public static function versions(): array
    {
        return [['v27'], ['v28'], ['v29']];
    }

    #[DataProvider('versions')]
    public function test_real_img_overlay_is_exactly_reversible_and_keeps_classic_flow(string $version): void
    {
        $original = $this->native($version);
        $output = OutlookTrainBottomOverlay::project($original);
        self::assertNotSame($original, $output);
        self::assertSame($original, OutlookTrainBottomOverlay::restore($output));
        self::assertSame($output, OutlookTrainBottomOverlay::project($output));
        OutlookTrainBottomOverlay::assertRuntime($output);
        self::assertSame($original, OutlookTrainBottomOverlay::restore($original));
        self::assertStringNotContainsString('6031', $output);
        self::assertStringNotContainsString('background-image', $output);
        self::assertDoesNotMatchRegularExpression('/\sbackground\s*=/i', $output);
        self::assertDoesNotMatchRegularExpression('/(?:min-height|height):\s*(?:200|296)px/i', $output);
        self::assertSame($this->comments($original), $this->comments($output));
        self::assertSame($this->media($original), $this->media($output));
        self::assertSame(1, substr_count($output, 'src="cid:train.gif"'));
        self::assertSame(1, substr_count($output, 'src="cid:train.png"'));
        $xpath = $this->xpath($output);
        $stage = $this->one($xpath, 'rt-sign-stage');
        $frame = $this->one($xpath, 'rt-sign-content-frame');
        $train = $this->one($xpath, 'rt-delivery-train');
        $cell = $this->one($xpath, 'rt-delivery-train-cell');
        self::assertTrue($train->parentNode->isSameNode($stage));
        self::assertTrue($frame->parentNode->isSameNode($stage));
        self::assertSame(0, $xpath->query('./*', $cell)->length, 'Only the unchanged Word conditional IMG remains in the old flow row.');
        self::assertSame('300', $train->getAttribute('width'));
        self::assertSame('38', $train->getAttribute('height'));
        self::assertStringContainsString('position:relative;z-index:0;', $stage->getAttribute('style'));
        self::assertStringContainsString('position:relative;z-index:1;', $frame->getAttribute('style'));
        self::assertStringContainsString('position:absolute;bottom:0;z-index:0;', $train->getAttribute('style'));
        self::assertStringContainsString($version === 'v27' ? 'left:0;right:auto;' : 'right:0;left:auto;', $train->getAttribute('style'));
    }

    public function test_existing_trusted_mirrors_not_optional_new_style_preserve_overlay(): void
    {
        $original = $this->native('v27');
        $output = OutlookTrainBottomOverlay::project($original);
        self::assertSame(substr_count($original, '<style'), substr_count($output, '<style'));
        self::assertSame(1, preg_match('~<style '.OutlookSignatureInlineStyle::ATTRIBUTE.'="1">(.*?)</style>~s', $output, $mirror));
        self::assertStringContainsString('position:absolute;bottom:0;z-index:0;left:0;right:auto;', $mirror[1]);
        self::assertStringContainsString('position:relative;z-index:1;', $mirror[1]);
        $withoutInline = preg_replace('~\sstyle="[^"]*"~', '', $output);
        self::assertStringContainsString('position:absolute;bottom:0;z-index:0;', $withoutInline);
        $withoutStylesheets = preg_replace('~<style\b[^>]*>.*?</style>~s', '', $output);
        self::assertStringContainsString('position:absolute;bottom:0;z-index:0;', $withoutStylesheets);
        self::assertStringContainsString('<!--[if mso]><img class="rt-delivery-train-mso" src="cid:train.png" width="300" height="38"', $withoutStylesheets);
        self::assertSame(1, substr_count($withoutStylesheets, 'src="cid:train.gif"'));
    }

    public function test_canonical_hotline_before_contact_frame_remains_byte_identical(): void
    {
        $original = $this->native('v27', true);
        $output = OutlookTrainBottomOverlay::project($original);
        self::assertSame($original, OutlookTrainBottomOverlay::restore($output));
        self::assertSame(1, preg_match('~(<table class="rt-hotline-banner\b.*?</table>)~s', $original, $hotline));
        self::assertStringContainsString($hotline[1], $output);
    }

    public static function whiteCarriers(): array
    {
        return [['v27', true], ['v27', false], ['v28', true], ['v29', true]];
    }

    #[DataProvider('whiteCarriers')]
    public function test_exact_published_white_stage_prefix_and_mirror_are_byte_reversible(string $version, bool $importantMirror): void
    {
        $original = $this->native($version, true, true);
        if (! $importantMirror) {
            $original = preg_replace_callback('~<style '.OutlookSignatureInlineStyle::ATTRIBUTE.'="1">(.*?)</style>~s', static fn (array $match): string => str_replace('background-color:#ffffff!important;display:block;width:100%;overflow:visible;', 'background-color:#ffffff;display:block;width:100%;overflow:visible;', $match[0]), $original);
        }
        $output = OutlookTrainBottomOverlay::project($original);
        self::assertSame($original, OutlookTrainBottomOverlay::restore($output));
        OutlookTrainBottomOverlay::assertRuntime($output);
        self::assertStringStartsWith('background-color:#ffffff!important;display:block;width:100%;overflow:visible;position:relative;', $this->one($this->xpath($output), 'rt-sign-stage')->getAttribute('style'));
        self::assertSame(1, preg_match('~<style '.OutlookSignatureInlineStyle::ATTRIBUTE.'="1">(.*?)</style>~s', $output, $mirror));
        self::assertStringContainsString('background-color:#ffffff'.($importantMirror ? '!important' : '').';display:block;width:100%;overflow:visible;position:relative;', $mirror[1]);
        self::assertSame($this->comments($original), $this->comments($output));
        self::assertSame($this->media($original), $this->media($output));
    }

    public function test_mobile_white_carrier_prefix_keeps_existing_critical_mirrors_and_inverse(): void
    {
        $original = $this->mobileNative(true);
        $output = OutlookTrainBottomOverlay::projectMobile($original);
        self::assertSame($original, OutlookTrainBottomOverlay::restore($output));
        OutlookTrainBottomOverlay::assertRuntime($output);
        self::assertStringStartsWith('background-color:#ffffff!important;display:block;', $this->one($this->xpath($output), 'rt-sign-stage')->getAttribute('style'));
        self::assertStringContainsString('.rt-native-train-overlay{position:relative!important;z-index:0!important;}', $output);
        self::assertStringNotContainsString('!important!important', $output);
    }

    public static function invalidWhiteCarriers(): array
    {
        return [['other-color'], ['missing-importance'], ['color-case'], ['declaration-order'], ['extra-declaration'], ['mirror-other-color'], ['mirror-missing-color']];
    }

    #[DataProvider('invalidWhiteCarriers')]
    public function test_white_prefix_support_never_admits_other_style_or_mirror_variants(string $case): void
    {
        $html = $this->native('v27', true, true);
        $html = match ($case) {
            'other-color' => str_replace('background-color:#ffffff!important;display:block;', 'background-color:#eeeeee!important;display:block;', $html),
            'missing-importance' => str_replace('background-color:#ffffff!important;display:block;', 'background-color:#ffffff;display:block;', $html),
            'color-case' => str_replace('background-color:#ffffff!important;display:block;', 'background-color:#FFFFFF!important;display:block;', $html),
            'declaration-order' => str_replace('background-color:#ffffff!important;display:block;', 'display:block;background-color:#ffffff!important;', $html),
            'extra-declaration' => str_replace('background-color:#ffffff!important;display:block;', 'background-color:#ffffff!important;opacity:1;display:block;', $html),
            'mirror-other-color' => preg_replace_callback('~<style '.OutlookSignatureInlineStyle::ATTRIBUTE.'="1">(.*?)</style>~s', static fn (array $match): string => str_replace('background-color:#ffffff!important;display:block;', 'background-color:#eeeeee!important;display:block;', $match[0]), $html),
            'mirror-missing-color' => preg_replace_callback('~<style '.OutlookSignatureInlineStyle::ATTRIBUTE.'="1">(.*?)</style>~s', static fn (array $match): string => str_replace('background-color:#ffffff!important;display:block;', 'display:block;', $match[0]), $html),
        };
        $this->expectException(RuntimeException::class);
        OutlookTrainBottomOverlay::project($html);
    }

    public function test_unrelated_output_is_not_implicitly_opted_in(): void
    {
        $html = '<table><tr><td>Ordinary signature</td></tr></table>';
        self::assertSame($html, OutlookTrainBottomOverlay::project($html));
        self::assertSame($html, OutlookTrainBottomOverlay::projectMobile($html));
    }

    public function test_actual_compiled_mobile_ledger_overlay_is_byte_reversible(): void
    {
        $original = $this->mobileNative();
        $output = OutlookTrainBottomOverlay::projectMobile($original);
        self::assertNotSame($original, $output);
        self::assertSame($original, OutlookTrainBottomOverlay::restore($output));
        self::assertSame($output, OutlookTrainBottomOverlay::projectMobile($output));
        OutlookTrainBottomOverlay::assertRuntime($output);
        self::assertStringContainsString('data-rt-train-bottom-overlay="'.OutlookTrainBottomOverlay::MOBILE_MARKER.'"', $output);
        self::assertSame($this->comments($original), $this->comments($output));
        self::assertSame($this->media($original), $this->media($output));
        $xpath = $this->xpath($output);
        self::assertTrue($this->one($xpath, 'rt-delivery-train')->parentNode->isSameNode($this->one($xpath, 'rt-sign-stage')));
        self::assertSame(0, $xpath->query('./*', $this->one($xpath, 'rt-delivery-train-cell'))->length);
        self::assertSame(2, $xpath->query('//table[contains(concat(" ",@class," ")," rt-sign-ledger ")]/tbody/tr|//table[contains(concat(" ",@class," ")," rt-sign-ledger ")]/tr')->length);
    }

    public function test_mobile_overlay_uses_existing_mobile_mirror_with_critical_stage_rules(): void
    {
        $original = $this->mobileNative();
        $output = OutlookTrainBottomOverlay::projectMobile($original);
        self::assertSame(substr_count($original, '<style'), substr_count($output, '<style'));
        self::assertSame(1, preg_match('~<style data-rt-outlook-mobile-css="1">(.*?)</style>~s', $output, $mirror));
        self::assertStringContainsString('position:absolute!important;bottom:0!important;z-index:0!important;', $mirror[1]);
        self::assertStringContainsString('.m28tfc09.rtm .rt-native-train-overlay{position:relative!important;z-index:0!important;}', $mirror[1]);
        self::assertStringContainsString('.m28tfc09.rtm .rt-native-train-overlay .rt-sign-content-frame{position:relative!important;z-index:1!important;}', $mirror[1]);
        $withoutInline = preg_replace('~\sstyle="[^"]*"~', '', $output);
        self::assertStringContainsString('position:absolute!important;bottom:0!important;', $withoutInline);
        $withoutSheets = preg_replace('~<style\b[^>]*>.*?</style>~s', '', $output);
        self::assertStringContainsString('position:absolute;bottom:0;', $withoutSheets);
        self::assertStringContainsString('width="300" height="38"', $withoutSheets);
        self::assertStringNotContainsString('height:200px', $output);
        self::assertStringNotContainsString('background-image:', $output);
    }

    public static function malformedMobile(): array
    {
        return [['mirror-missing'], ['mirror-duplicate'], ['mirror-changed'], ['alias-shared'], ['scope'], ['profile'], ['rows'], ['critical-forged']];
    }

    #[DataProvider('malformedMobile')]
    public function test_mobile_projection_rejects_unproven_shape_and_mirrors(string $case): void
    {
        $html = $this->mobileNative();
        $html = match ($case) {
            'mirror-missing' => preg_replace('~<style data-rt-outlook-mobile-css="1">.*?</style>~s', '', $html),
            'mirror-duplicate' => preg_replace('~(<style data-rt-outlook-mobile-css="1">.*?</style>)~s', '$1$1', $html),
            'mirror-changed' => str_replace('max-width:600px!important;', 'max-width:601px!important;', $html),
            'alias-shared' => $this->reuseMobileTrainAlias($html),
            'scope' => str_replace('m28tfc09', 'm28tfc08', $html),
            'profile' => str_replace(' rt-mobile-ledger rtm ', ' rt-mobile-ledger other ', $html),
            'rows' => preg_replace_callback('~(class="rt-ledger-brand[^>]*\bwidth=")100%~', static fn (array $match): string => $match[1].'99%', $html),
            'critical-forged' => preg_replace('~(<style data-rt-outlook-mobile-css="1">)~', '$1.m28tfc09.rtm .rt-sign-stage{position:absolute!important;}', $html),
        };
        $this->expectException(RuntimeException::class);
        OutlookTrainBottomOverlay::projectMobile($html);
    }

    public function test_corrupted_mobile_critical_mirror_cannot_be_reentered(): void
    {
        $output = OutlookTrainBottomOverlay::projectMobile($this->mobileNative());
        $output = str_replace('.rt-sign-content-frame{position:relative!important;z-index:1!important;}', '.rt-sign-content-frame{position:relative!important;z-index:9!important;}', $output);
        $this->expectException(RuntimeException::class);
        OutlookTrainBottomOverlay::projectMobile($output);
    }

    public function test_explicit_foreign_mobile_overlay_marker_is_never_silently_skipped(): void
    {
        $this->expectException(RuntimeException::class);
        OutlookTrainBottomOverlay::projectMobile('<p data-rt-train-bottom-overlay="foreign">Not a compiled mobile signature</p>');
    }

    public function test_actual_mobile_payload_recompilation_fails_closed_without_mutating_input(): void
    {
        $original = $this->mobileNative();
        $projected = OutlookTrainBottomOverlay::projectMobile($original);
        $payload = ['signature' => ['html' => $projected, 'media' => []], 'templates' => [], 'version' => ['signature' => '0123456789abcdef']];
        $before = $payload;
        try {
            OutlookMobileSignature::payload($payload);
            self::fail('A compiled mobile payload is not a second desktop compilation input.');
        } catch (RuntimeException $error) {
            self::assertNotEmpty($error->getMessage());
        }
        self::assertSame($before, $payload);
    }

    public static function brokenInputs(): array
    {
        return [
            'forged marker' => ['forged'],
            'old percentage source' => ['old-source'],
            'missing native mirror' => ['missing-mirror'],
            'changed native mirror' => ['changed-mirror'],
            'duplicate native mirror' => ['duplicate-mirror'],
            'shared native alias' => ['shared-alias'],
            'fixed stage height' => ['fixed-stage'],
            'mso geometry change' => ['mso-size'],
            'mso media loss' => ['mso-missing'],
            'modern branch loss' => ['normal-missing'],
            'normal media duplication' => ['normal-duplicate'],
            'table structure change' => ['structure'],
            'source marker duplication' => ['marker-duplicate'],
            'native root scope loss' => ['scope-missing'],
            'duplicated root scope descendant' => ['scope-descendant'],
            'ambiguous root scope tokens' => ['scope-ambiguous'],
            'stage class on root' => ['stage-on-root'],
            'explicit bounded artifact version loss' => ['version-missing'],
            'unscoped outer body content' => ['extra-root'],
        ];
    }

    #[DataProvider('brokenInputs')]
    public function test_ambiguous_native_structure_and_mirrors_fail_closed(string $case): void
    {
        $html = $this->native('v27');
        $html = match ($case) {
            'forged' => str_replace('class="rt-sign-stage ', 'data-rt-train-bottom-overlay="foreign" class="rt-sign-stage ', $html),
            // Explicit legacy marker on an incompatible modern shape must reject.
            'old-source' => str_replace('class="rt-sign-content-frame ', 'class="rt-v27-anchor rt-sign-content-frame ', $html),
            'missing-mirror' => preg_replace('~<style '.OutlookSignatureInlineStyle::ATTRIBUTE.'="1">.*?</style>~s', '', $html),
            'changed-mirror' => preg_replace('~(\.rts0123456789\.oi[a-z0-9]+,\.rts0123456789 \.oi[a-z0-9]+\{display:block;width:100%;overflow:visible;)~', '$1height:7px;', $html),
            'duplicate-mirror' => preg_replace('~(<style '.OutlookSignatureInlineStyle::ATTRIBUTE.'="1">.*?</style>)~s', '$1$1', $html),
            'shared-alias' => $this->reuseStageAlias($html),
            'fixed-stage' => str_replace('display:block;width:100%;overflow:visible;', 'display:block;width:100%;overflow:visible;height:200px;', $html),
            'mso-size' => str_replace('class="rt-delivery-train-mso" src="cid:train.png" width="300" height="38"', 'class="rt-delivery-train-mso" src="cid:train.png" width="300" height="39"', $html),
            'mso-missing' => preg_replace('~<!--\[if mso\]><img class="rt-delivery-train-mso".*?<!\[endif\]-->~s', '', $html),
            'normal-missing' => preg_replace('~<!--\[if !mso\]><!--><img class="rt-delivery-train\b[^>]*><!--<!\[endif\]-->~', '', $html),
            'normal-duplicate' => preg_replace('~(<!--\[if !mso\]><!--><img class="rt-delivery-train\b[^>]*><!--<!\[endif\]-->)~', '$1$1', $html),
            'structure' => str_replace('class="rt-delivery-train-row"', 'class="not-the-train-row"', $html),
            'marker-duplicate' => str_replace('data-rt-train-delivery="bounded-img-v2"', 'data-rt-train-delivery="bounded-img-v2" data-rt-train-delivery="bounded-img-v2"', $html),
            'scope-missing' => str_replace('rts0123456789', 'other-scope', $html),
            'scope-descendant' => str_replace('class="rt-sign-stage ', 'class="rt-sign-stage rts0123456789 ', $html),
            'scope-ambiguous' => str_replace('class="rt-outlook-signature rts0123456789 ', 'class="rt-outlook-signature rts0123456789 rts0123456788 ', $html),
            'stage-on-root' => str_replace('class="rt-outlook-signature ', 'class="rt-outlook-signature rt-native-train-overlay ', $html),
            'version-missing' => str_replace('data-rt-artifact-version="v27"', 'data-rt-artifact-version="unknown"', $html),
            'extra-root' => $html.'<p>Must not move an unrelated outer body.</p>',
        };
        $this->expectException(RuntimeException::class);
        OutlookTrainBottomOverlay::project($html);
    }

    public static function corruptOutputs(): array
    {
        return [['style'], ['css'], ['marker'], ['location'], ['mso']];
    }

    #[DataProvider('corruptOutputs')]
    public function test_projected_output_requires_exact_inverse_guard(string $case): void
    {
        $output = OutlookTrainBottomOverlay::project($this->native('v27'));
        $output = match ($case) {
            'style' => str_replace('position:absolute;bottom:0;', 'position:absolute;bottom:1px;', $output),
            'css' => preg_replace('~(\.rts0123456789[^{}]*\{[^}]*position:absolute;)bottom:0;~', '$1bottom:1px;', $output),
            'marker' => str_replace('intrinsic-img-v1', 'intrinsic-img-v2', $output),
            'location' => str_replace('<!--<![endif]--></div>', '<!--<![endif]--><p>Unexpected extra layer</p></div>', $output),
            'mso' => str_replace('height="38" alt="" style="display:block;width:300px;', 'height="39" alt="" style="display:block;width:300px;', $output),
        };
        $this->expectException(RuntimeException::class);
        OutlookTrainBottomOverlay::assertRuntime($output);
    }

    public function test_projected_rules_cannot_style_an_older_quoted_signature_with_identical_source_scope(): void
    {
        foreach ([false, true] as $mobile) {
            $original = $mobile ? $this->mobileNative() : $this->native('v27', true);
            $output = $mobile ? OutlookTrainBottomOverlay::projectMobile($original) : OutlookTrainBottomOverlay::project($original);
            $attribute = $mobile ? 'data-rt-outlook-mobile-css' : OutlookSignatureInlineStyle::ATTRIBUTE;
            self::assertSame(1, preg_match('~<style '.$attribute.'="1">(.*?)</style>~s', $output, $mirror));
            self::assertSame(3, preg_match_all('~([^{}]+)\{[^{}]*position:(?:absolute|relative)(?:!important)?;[^{}]*\}~', $mirror[1], $rules));
            foreach ($rules[1] as $selectors) {
                foreach (explode(',', $selectors) as $selector) {
                    self::assertStringContainsString('.rt-native-train-overlay', $selector, 'Every projected positioning selector requires the new actual stage class, not the shared old rts/m scope alone.');
                    self::assertStringNotContainsString('[data-', $selector, 'Loss of data attributes must not broaden quote styling.');
                }
            }
            self::assertSame(0, $this->xpath($original)->query('//*[contains(concat(" ",@class," ")," rt-native-train-overlay ")]')->length);
            self::assertSame(1, $this->xpath($output)->query('//*[contains(concat(" ",@class," ")," rt-native-train-overlay ")]')->length);
            self::assertSame($original, OutlookTrainBottomOverlay::restore($output));
        }
    }

    public function test_desktop_mirror_removes_only_proven_impossible_root_stage_branches(): void
    {
        $original = $this->native('v27', true);
        $output = OutlookTrainBottomOverlay::project($original);
        self::assertSame(1, preg_match('~<style '.OutlookSignatureInlineStyle::ATTRIBUTE.'="1">(.*?)</style>~s', $output, $mirror));
        self::assertSame(3, preg_match_all('~([^{}]+)\{[^{}]*position:(?:absolute|relative);[^{}]*\}~', $mirror[1], $rules));
        $removedBytes = 0;
        foreach ($rules[1] as $selector) {
            self::assertStringStartsWith('.rts0123456789 .rt-native-train-overlay', $selector);
            self::assertStringNotContainsString(',', $selector);
            // The removed branch differed only in the descendant space
            // following the root scope, plus its trailing comma.
            $removedBytes += strlen($selector);
        }
        self::assertSame(131, $removedBytes);
        self::assertSame($original, OutlookTrainBottomOverlay::restore($output));
        self::assertSame(1, $this->xpath($output)->query('//*[contains(concat(" ",normalize-space(@class)," ")," rts0123456789 ")]')->length);
        self::assertSame(1, $this->xpath($output)->query('//*[contains(concat(" ",normalize-space(@class)," ")," rt-native-train-overlay ")]')->length);
    }

    public function test_output_budget_is_unchanged_and_checked_after_projection(): void
    {
        $html = $this->native('v27');
        $this->expectException(RuntimeException::class);
        OutlookTrainBottomOverlay::project(str_replace('<div class="rt-outlook-signature', '<style>'.str_repeat('x', 12288).'</style><div class="rt-outlook-signature', $html));
    }

    public function test_html_utf16_budget_is_not_relaxed_for_the_new_projection(): void
    {
        $html = $this->native('v27');
        $this->expectException(RuntimeException::class);
        OutlookTrainBottomOverlay::project(str_replace('Synthetic contact', str_repeat('😀', 16000), $html));
    }

    private function native(string $version, bool $hotline = false, bool $whiteStage = false): string
    {
        $source = trim(file_get_contents(base_path('tests/Fixtures/mail/signature-v27'.($version === 'v27' ? '-ledger' : '').'.html')));
        if ($version !== 'v27') {
            $source = SignatureTableOverlap::mirroredFromV27($source, $version);
        }
        if ($hotline) {
            $source = str_replace('<div class="rt-sign-stage" style="display:block;width:100%;overflow:visible;">', '<div class="rt-sign-stage" style="display:block;width:100%;overflow:visible;"><table class="rt-hotline-banner" role="presentation" width="100%" border="0" cellspacing="0" cellpadding="0" style="width:100%;background-color:#e90032;"><tr><td style="padding:14px 22px;color:#ffffff;">24/7 Hotline</td></tr></table>', $source);
        }
        $rows = preg_replace_callback('/\{\{([A-Z0-9_]+)\}\}/', static fn (array $match): string => match ($match[1]) {
            'TRAIN_SRC' => 'cid:train.gif',
            'FIRMEN_WEBSITE_HREF' => 'https://example.test',
            'E_MAIL', 'FIRMEN_EMAIL' => 'contact@example.test',
            default => str_ends_with($match[1], '_SRC') ? 'cid:'.strtolower($match[1]).'.png' : 'Synthetic contact',
        }, $source);
        $rows = SignatureTableOverlapDelivery::project($rows, 'cid:train.png');
        if ($whiteStage) {
            $rows = str_replace('class="rt-sign-stage" style="display:block;width:100%;overflow:visible;"', 'class="rt-sign-stage" style="background-color:#ffffff!important;display:block;width:100%;overflow:visible;"', $rows, $stageCount);
            self::assertSame(1, $stageCount);
        }
        $scope = 'rts0123456789';
        $html = TrustedOutlookSignatureCss::style($rows, '#dfe3e6', $scope)
            .'<div class="rt-outlook-signature '.$scope.'" style="display:block;width:100%;">'
            .'<table role="presentation" width="100%" border="0" cellspacing="0" cellpadding="0" style="width:100%;border-collapse:separate;border-spacing:0;mso-table-lspace:0pt;mso-table-rspace:0pt;border-left:6px solid #e90032;box-sizing:border-box;background-color:#ffffff;">'
            .'<tbody>'.$rows.'</tbody></table></div>';

        return OutlookSignatureInlineStyle::apply($html, $scope);
    }

    private function mobileNative(bool $whiteStage = false): string
    {
        $native = $this->native('v27', true, $whiteStage);
        $marker = 'RT-SIGNATURE-VERSION:0123456789abcdef';
        $metadata = '<!-- '.$marker.' --><span hidden aria-hidden="true" class="rt-office-metadata" style="display:none!important;mso-hide:all;font-size:0;line-height:0;max-height:0;overflow:hidden;">'.$marker.'</span>';
        $native = preg_replace('~(<td class="rt-sign-cell\b[^>]*>)~', '$1'.$metadata, $native, 1);
        $payload = ['signature' => ['html' => $native, 'media' => []], 'templates' => [], 'version' => ['signature' => '0123456789abcdef']];
        $compiled = OutlookMobileSignature::payload($payload);

        // Root may integrate the approved projection into the adapter while
        // this test is running; retain its exact inverse as our input fixture.
        return OutlookTrainBottomOverlay::restore($compiled['signature']['html']);
    }

    private function reuseMobileTrainAlias(string $html): string
    {
        self::assertSame(1, preg_match('~class="rt-delivery-train\b[^"\n]*\b(m[0-9a-z]+)"~', $html, $alias));

        return str_replace('</div>', '<span class="'.$alias[1].'"></span></div>', $html);
    }

    private function reuseStageAlias(string $html): string
    {
        self::assertSame(1, preg_match('~class="rt-sign-stage (oi[0-9a-z]+)"~', $html, $stage));

        return str_replace('</div>', '<span class="'.$stage[1].'"></span></div>', $html);
    }

    private function comments(string $html): array
    {
        preg_match_all('~<!--.*?-->~s', $html, $matches);
        sort($matches[0]);

        return $matches[0];
    }

    private function media(string $html): array
    {
        preg_match_all('~\b(?:src|href)="[^"]*"~', $html, $matches);
        sort($matches[0]);

        return $matches[0];
    }

    private function xpath(string $html): DOMXPath
    {
        $dom = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        try {
            self::assertTrue($dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET));
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return new DOMXPath($dom);
    }

    private function one(DOMXPath $xpath, string $class): \DOMElement
    {
        $elements = $xpath->query('//*[contains(concat(" ",normalize-space(@class)," ")," '.$class.' ")]');
        self::assertSame(1, $elements->length);

        return $elements->item(0);
    }
}
