<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\MailDocument;
use App\Models\User;
use App\Support\OutlookAddin\OutlookAddinPayloadService;
use App\Support\OutlookAddin\OutlookClientPresentation;
use App\Support\OutlookAddin\OutlookCombinedComposeDocument;
use App\Support\OutlookAddin\OutlookMobileBrandOrder;
use App\Support\OutlookAddin\OutlookMobileSignature;
use DOMDocument;
use DOMElement;
use DOMXPath;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use ReflectionProperty;
use Tests\TestCase;

/** The actual authored V27 source through the unchanged final transport gates. */
final class OutlookMobileBrandPresentationTest extends TestCase
{
    public static function layouts(): array
    {
        return ['personal' => [true], 'system-shaped' => [false]];
    }

    #[DataProvider('layouts')]
    public function test_mobile_brand_and_white_hotline_preserve_desktop_sources_media_and_final_budgets(bool $personal): void
    {
        $desktop = $this->fixture($personal);
        $before = $desktop;
        $rows = MailDocument::query()->orderBy('id')->get()->toArray();
        $desktopPresentation = OutlookClientPresentation::payload($desktop);
        $mobile = OutlookMobileSignature::payload($desktop);
        $final = OutlookClientPresentation::payload($mobile);

        self::assertSame($before, $desktop, 'The cached desktop artifact remains canonical.');
        self::assertSame($desktopPresentation, OutlookClientPresentation::payload($desktop));
        self::assertSame($rows, MailDocument::query()->orderBy('id')->get()->toArray());
        self::assertStringNotContainsString('-webkit-text-fill-color', $desktop['signature']['html']);
        self::assertSame($desktop['signature']['media'], $mobile['signature']['media']);
        self::assertSame($this->comments($desktop['signature']['html']), $this->comments($mobile['signature']['html']), 'MSO and train branches retain their bytes.');
        self::assertSame($this->imageSources($desktop['signature']['html']), $this->imageSources($mobile['signature']['html']));

        $logo = $this->logoSource($desktop['signature']['html']);
        $this->assertMobile($final['signature']['html'], $logo, $personal);
        $this->assertVersion($final['signature']['html'], 'RT-SIGNATURE-VERSION:', $final['version']['signature']);
        self::assertSame($final['signature']['html'], OutlookMobileBrandOrder::project($final['signature']['html']));
        foreach ($desktop['templates'] as $index => $original) {
            $template = $final['templates'][$index];
            self::assertSame($original['signature']['media'], $template['signature']['media']);
            $this->assertMobile($template['signature']['html'], $logo, $personal);
            $this->assertMobile($template['mobileComposeHtml'], $logo, $personal);
            $this->assertVersion($template['signature']['html'], 'RT-SIGNATURE-VERSION:', $template['signatureVersion']);
            $this->assertVersion($template['mobileComposeHtml'], 'RT-MOBILE-COMPOSE-VERSION:', $template['mobileComposeVersion']);
            $this->assertCids($template['mobileComposeHtml'], $template['mobileComposeMedia']);
            self::assertSame($original['composeHtml'], $mobile['templates'][$index]['composeHtml'], 'Mobile adaptation does not rewrite the desktop compose artifact.');
            self::assertSame($original['combinedComposeHtml'], $mobile['templates'][$index]['combinedComposeHtml']);
        }
        self::assertSame($final['templates'][0]['html'], $final['template']['html']);
        self::assertSame($mobile, OutlookMobileSignature::payload($desktop), 'Output order and versions are deterministic.');
    }

    public static function nonWhiteStyles(): array
    {
        return ['authored non-white' => ['color:#142231;'], 'ambiguous repeated colors' => ['color:#fff;color:#142231;']];
    }

    #[DataProvider('nonWhiteStyles')]
    public function test_nonwhite_and_ambiguous_authored_banner_colors_are_not_overwritten(string $color): void
    {
        $desktop = $this->fixture();
        $patch = static function (string $html) use ($color): string {
            return preg_replace_callback('~<td\b[^>]*\bclass="[^"]*\bdesign-hotline-row\b[^"]*"[^>]*>~', static function (array $match) use ($color): string {
                return preg_replace_callback('~\bstyle="([^"]*)"~', static function (array $style) use ($color): string {
                    $withoutColor = preg_replace('/(?:^|;)\s*color\s*:[^;]*(?:;|$)/i', ';', $style[1]);

                    return 'style="'.rtrim($withoutColor, ';').';'.$color.'"';
                }, $match[0], 1);
            }, $html, 1);
        };
        $desktop['signature']['html'] = $patch($desktop['signature']['html']);
        foreach ($desktop['templates'] as &$template) {
            $template['signature']['html'] = $patch($template['signature']['html']);
            $template['combinedComposeHtml'] = OutlookCombinedComposeDocument::build($template['composeHtml'], $template['signature']['html']);
        }
        unset($template);
        $before = $desktop;
        $mobile = OutlookMobileSignature::payload($desktop);
        self::assertSame($before, $desktop);
        foreach ([$mobile['signature']['html'], ...array_column(array_column($mobile['templates'], 'signature'), 'html')] as $html) {
            $row = $this->hotline($html);
            self::assertStringContainsString($color, $row->getAttribute('style'));
            self::assertStringNotContainsString('-webkit-text-fill-color', $html, 'Unknown or intentionally non-white text keeps its authored palette.');
        }
    }

    private function fixture(bool $personal = true): array
    {
        self::assertSame('sqlite', config('database.default'));
        self::assertSame(':memory:', config('database.connections.sqlite.database'));
        // Reuse the real current authored two-signature fixture. The older
        // ClientPresentation fixture predates the personal-columns opt-in.
        $case = new V27PersonalLayoutTest('test_separate_sources_are_valid_and_company_render_remains_exactly_the_unmodified_delivery_path');
        (new ReflectionMethod($case, 'setUp'))->invoke($case);
        $user = (new ReflectionProperty($case, 'person'))->getValue($case);
        self::assertInstanceOf(User::class, $user);
        if (! $personal) {
            MailDocument::query()->whereKey(16)->update(['outlook_default' => null]);
            MailDocument::query()->whereKey(9)->update(['outlook_default' => true]);
            MailDocument::query()->whereIn('id', [12, 15])->update(['signature_document_id' => 9, 'published_signature_document_id' => 9]);
            app()->forgetScopedInstances();
        }

        return app(OutlookAddinPayloadService::class)->forUser($user);
    }

    private function assertMobile(string $html, string $logo, bool $personal): void
    {
        self::assertLessThanOrEqual(30000, intdiv(strlen(mb_convert_encoding($html, 'UTF-16LE', 'UTF-8')), 2));
        preg_match_all('~<style\b[^>]*>(.*?)</style\s*>~is', $html, $styles);
        self::assertLessThan(12288, array_sum(array_map('strlen', $styles[1])));
        $modern = preg_replace('~<!--.*?-->~s', '', $html);
        self::assertSame(1, substr_count($modern, 'src="'.htmlspecialchars($logo, ENT_QUOTES, 'UTF-8').'"'), 'Exactly one normal logo survives in the mobile document.');
        $row = $this->hotline($html);
        self::assertStringContainsString('-webkit-text-fill-color:#fff;', $row->getAttribute('style'));
        $classes = preg_split('/\s+/', trim($row->getAttribute('class')));
        $matched = false;
        foreach ($classes as $class) {
            if (preg_match('~\.'.preg_quote($class, '~').'\s*\{[^{}]*-webkit-text-fill-color:#fff!important;[^{}]*\}~', implode('', $styles[1]))) {
                $matched = true;
            }
        }
        self::assertTrue($matched, 'The actual hotline carrier has a retained white-fill CSS rule even when inline styles are removed.');
        $noInline = preg_replace('~\sstyle="[^"]*"~', '', $html);
        self::assertSame($classes, preg_split('/\s+/', trim($this->hotline($noInline)->getAttribute('class'))));
        self::assertStringContainsString('-webkit-text-fill-color:#fff!important;', $noInline);
        if ($personal) {
            $logoAt = strpos($modern, 'src="'.htmlspecialchars($logo, ENT_QUOTES, 'UTF-8').'"');
            $nameAt = strpos($modern, 'Mara Muster');
            $emailAt = strpos($modern, 'mara@example.test');
            $companyAt = strpos($modern, 'Musterweg 12');
            self::assertNotFalse($logoAt);
            self::assertNotFalse($nameAt);
            self::assertNotFalse($emailAt);
            self::assertNotFalse($companyAt);
            self::assertLessThan($nameAt, $logoAt, 'The original company logo appears above the employee name in mobile source order.');
            self::assertLessThan($companyAt, $emailAt, 'Company contacts remain below the employee contacts.');
        }
    }

    private function assertVersion(string $html, string $marker, string $version): void
    {
        self::assertMatchesRegularExpression('/\A[0-9a-f]{16}\z/', $version);
        self::assertSame(2, substr_count($html, $marker.$version));
    }

    private function assertCids(string $html, array $media): void
    {
        $known = array_column($media, null, 'contentId');
        preg_match_all('~\bsrc="cid:([^"]+)"~', $html, $cids);
        foreach ($cids[1] as $cid) {
            self::assertArrayHasKey($cid, $known);
        }
    }

    private function logoSource(string $html): string
    {
        $images = $this->xpath($html)->query('//img[contains(concat(" ",normalize-space(@class)," ")," rt-logo ")]');
        self::assertSame(1, $images->length);

        return $images->item(0)->getAttribute('src');
    }

    private function imageSources(string $html): array
    {
        preg_match_all('~<img\b[^>]*\bsrc="([^"]+)"[^>]*>~', $html, $images);
        sort($images[1]);

        return $images[1];
    }

    private function comments(string $html): array
    {
        preg_match_all('~<!--.*?-->~s', $html, $comments);

        return array_values(array_filter($comments[0], static fn (string $comment): bool => ! str_contains($comment, 'RT-SIGNATURE-VERSION:')));
    }

    private function hotline(string $html): DOMElement
    {
        $rows = $this->xpath($html)->query('//td[contains(concat(" ",normalize-space(@class)," ")," design-hotline-row ")]');
        self::assertSame(1, $rows->length);

        return $rows->item(0);
    }

    private function xpath(string $html): DOMXPath
    {
        $dom = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        try {
            self::assertTrue($dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET));
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return new DOMXPath($dom);
    }
}
