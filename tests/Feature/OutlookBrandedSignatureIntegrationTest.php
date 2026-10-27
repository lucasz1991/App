<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\MailDocument;
use App\Models\User;
use App\Support\EmailTemplateBuilder;
use App\Support\OutlookAddin\OutlookAddinPayloadService;
use App\Support\OutlookAddin\OutlookClientPresentation;
use App\Support\OutlookAddin\OutlookMobileSignature;
use DOMDocument;
use DOMElement;
use DOMXPath;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use ReflectionProperty;
use Tests\TestCase;

/** Real authored V27 fixtures through canonical, mobile and final CID delivery. */
final class OutlookBrandedSignatureIntegrationTest extends TestCase
{
    private const ASSETS = ['fill', 'label', 'mail', 'phone'];

    public static function layouts(): array
    {
        return ['employee' => [true], 'company-shaped' => [false]];
    }

    #[DataProvider('layouts')]
    public function test_actual_global_paired_desktop_and_mobile_documents_keep_exact_media_and_budgets(bool $personal): void
    {
        [$payload] = $this->fixture($personal);
        $before = $payload;
        $rows = MailDocument::query()->orderBy('id')->get()->toArray();
        $desktop = OutlookClientPresentation::payload($payload);
        $mobileCanonical = OutlookMobileSignature::payload($payload);
        try {
            $mobile = OutlookClientPresentation::payload($mobileCanonical);
        } catch (\RuntimeException $error) {
            $metrics = [];
            foreach ($mobileCanonical['templates'] as $template) {
                foreach (['html', 'composeHtml', 'combinedComposeHtml', 'mobileComposeHtml'] as $field) {
                    if (! isset($template[$field])) {
                        continue;
                    }
                    preg_match_all('~<style\b[^>]*>(.*?)</style\s*>~is', $template[$field], $styles);
                    $metrics[$template['id'].':'.$field] = ['utf16' => intdiv(strlen(mb_convert_encoding($template[$field], 'UTF-16LE', 'UTF-8')), 2), 'css' => array_sum(array_map('strlen', $styles[1]))];
                    if ($field === 'mobileComposeHtml') {
                        // Diagnostics only: the original production failure is
                        // rethrown below. No larger gate is used for acceptance.
                        $diagnostic = (new ReflectionMethod(OutlookClientPresentation::class, 'document'))->invoke(null, $template[$field], 99000, 24576, false);
                        preg_match_all('~<style\b[^>]*>(.*?)</style\s*>~is', $diagnostic, $finalStyles);
                        $metrics[$template['id'].':'.$field]['diagnostic_final'] = ['utf16' => intdiv(strlen(mb_convert_encoding($diagnostic, 'UTF-16LE', 'UTF-8')), 2), 'css' => array_sum(array_map('strlen', $finalStyles[1]))];
                    }
                }
            }
            throw new \RuntimeException($error->getMessage().' Pre-final fixture metrics: '.json_encode($metrics, JSON_THROW_ON_ERROR), 0, $error);
        }

        self::assertSame($before, $payload, 'Presentation never mutates the reusable canonical payload.');
        self::assertSame($rows, MailDocument::query()->orderBy('id')->get()->toArray());
        self::assertSame($desktop, OutlookClientPresentation::payload($payload));
        self::assertSame($mobileCanonical, OutlookMobileSignature::payload($payload));
        self::assertSame($payload['signature']['media'], $mobile['signature']['media']);
        $this->assertDocument($desktop['signature']['html'], $desktop['signature']['media'], false, 30000, 12288, $personal);
        $this->assertDocument($mobile['signature']['html'], $mobile['signature']['media'], true, 30000, 12288, $personal);
        $this->assertVersion($desktop['signature']['html'], 'RT-SIGNATURE-VERSION:', $desktop['version']['signature']);
        $this->assertVersion($mobile['signature']['html'], 'RT-SIGNATURE-VERSION:', $mobile['version']['signature']);

        foreach ($desktop['templates'] as $index => $template) {
            $paired = $mobile['templates'][$index];
            self::assertSame($template['signature']['media'], $paired['signature']['media']);
            self::assertSame($payload['templates'][$index]['composeHtml'], $mobileCanonical['templates'][$index]['composeHtml']);
            self::assertSame($payload['templates'][$index]['combinedComposeHtml'], $mobileCanonical['templates'][$index]['combinedComposeHtml']);
            $this->assertDocument($template['signature']['html'], $template['signature']['media'], false, 30000, 12288, $personal);
            $this->assertDocument($template['html'], $template['media'], false, 99000, 24576, $personal);
            $this->assertDocument($template['combinedComposeHtml'], $template['combinedComposeMedia'], false, 99000, 24576, $personal);
            $this->assertDocument($paired['signature']['html'], $paired['signature']['media'], true, 30000, 12288, $personal);
            $this->assertDocument($paired['mobileComposeHtml'], $paired['mobileComposeMedia'], true, 30000, 12288, $personal);
            $this->assertVersion($paired['signature']['html'], 'RT-SIGNATURE-VERSION:', $paired['signatureVersion']);
            $this->assertVersion($paired['mobileComposeHtml'], 'RT-MOBILE-COMPOSE-VERSION:', $paired['mobileComposeVersion']);
        }
        self::assertSame($desktop['template']['html'], $desktop['templates'][0]['html']);
        self::assertSame($mobile['template']['html'], $mobile['templates'][0]['html']);

        if ($personal) {
            $this->exportPreview('desktop', $desktop['signature']);
            $this->exportPreview('mobile', $mobile['signature']);
            $this->exportPreview('mobile-noinline', $mobile['signature'], true);
            $this->exportPreview('mobile-combined', ['html' => $mobile['templates'][0]['mobileComposeHtml'], 'media' => $mobile['templates'][0]['mobileComposeMedia']]);
        }
    }

    public function test_fixed_hotline_assets_have_opaque_brand_red_corners_and_white_readable_label_pixels(): void
    {
        $dimensions = ['fill' => [1, 34], 'label' => [456, 102], 'mail' => [126, 102], 'phone' => [126, 102]];
        foreach (self::ASSETS as $kind) {
            $path = public_path('mail-assets/outlook-hotline-'.$kind.'-v1.png');
            self::assertFileExists($path);
            $size = getimagesize($path);
            self::assertSame($dimensions[$kind], [$size[0], $size[1]]);
            self::assertSame('image/png', $size['mime']);
            $image = imagecreatefrompng($path);
            self::assertNotFalse($image);
            $white = 0;
            $opaque = true;
            for ($y = 0; $y < imagesy($image); $y++) {
                for ($x = 0; $x < imagesx($image); $x++) {
                    $rgba = imagecolorsforindex($image, imagecolorat($image, $x, $y));
                    $opaque = $opaque && $rgba['alpha'] === 0;
                    if ($rgba['red'] === 255 && $rgba['green'] === 255 && $rgba['blue'] === 255) {
                        $white++;
                    }
                }
            }
            self::assertTrue($opaque, 'Every banner pixel is opaque; a transparent white label is not the same contract.');
            foreach ([[0, 0], [imagesx($image) - 1, imagesy($image) - 1]] as [$x, $y]) {
                $corner = imagecolorsforindex($image, imagecolorat($image, $x, $y));
                self::assertSame([233, 0, 50, 0], [$corner['red'], $corner['green'], $corner['blue'], $corner['alpha']]);
            }
            if ($kind !== 'fill') {
                self::assertGreaterThan(100, $white, 'The label/actions retain actual white image pixels in dark-mode clients.');
            }
            imagedestroy($image);
        }
    }

    public function test_maximum_literal_ampersands_and_entity_prefix_wording_keep_the_real_final_mobile_gate(): void
    {
        [$before, $user, $case] = $this->fixture(true);
        (new ReflectionMethod($case, 'wordingVariant'))->invoke($case);
        (new ReflectionMethod($case, 'wording'))->invoke($case, str_repeat('&', 120), str_repeat('&a', 60));
        $payload = app(OutlookAddinPayloadService::class)->forUser($user->fresh());
        self::assertSame($before['signature'], $payload['signature'], 'Personal wording does not rewrite the signature or its fixed images.');
        $rows = MailDocument::query()->orderBy('id')->get()->toArray();
        $final = OutlookClientPresentation::payload(OutlookMobileSignature::payload($payload));
        foreach ($final['templates'] as $template) {
            $this->assertDocument($template['mobileComposeHtml'], $template['mobileComposeMedia'], true, 30000, 12288, true);
            if (! $template['isDefault']) {
                $text = html_entity_decode(strip_tags($template['mobileComposeHtml']), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                self::assertStringContainsString(str_repeat('&', 120), $text);
                self::assertStringContainsString(str_repeat('&a', 60), $text);
                $this->exportPreview('mobile-maximum-wording', ['html' => $template['mobileComposeHtml'], 'media' => $template['mobileComposeMedia']]);
            }
        }
        self::assertSame($rows, MailDocument::query()->orderBy('id')->get()->toArray());
    }

    public function test_outlook_template_branding_does_not_leak_into_a_later_normal_export_on_the_same_builder(): void
    {
        [, $user] = $this->fixture(true);
        $builder = new EmailTemplateBuilder($user->fresh());
        $before = $builder->build('vorlage-html')['content'];
        $outlook = $builder->buildOutlookAddinTemplateHtml('light');
        $after = $builder->build('vorlage-html')['content'];

        self::assertStringContainsString('data-rt-brand-hotline="v1"', $outlook);
        self::assertStringNotContainsString('data-rt-brand-hotline=', $before);
        self::assertSame($before, $after, 'The scoped Outlook flag is restored before the same builder exports a normal email.');
    }

    /** @return array{array, User, V27PersonalLayoutTest} */
    private function fixture(bool $personal): array
    {
        self::assertSame('sqlite', config('database.default'));
        self::assertSame(':memory:', config('database.connections.sqlite.database'));
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

        return [app(OutlookAddinPayloadService::class)->forUser($user), $user, $case];
    }

    private function assertDocument(string $html, array $media, bool $mobile, int $characters, int $cssBytes, bool $personal): void
    {
        self::assertLessThanOrEqual($characters, intdiv(strlen(mb_convert_encoding($html, 'UTF-16LE', 'UTF-8')), 2));
        preg_match_all('~<style\b[^>]*>(.*?)</style\s*>~is', $html, $styles);
        self::assertLessThan($cssBytes, array_sum(array_map('strlen', $styles[1])));
        $this->assertCids($html, $media);
        $xpath = $this->xpath($html);
        $rows = $xpath->query('//td[@data-rt-brand-hotline="v1"]');
        self::assertSame(1, $rows->length);
        $row = $rows->item(0);
        self::assertStringContainsString('rt-brand-hotline-cell', $row->getAttribute('class'));
        self::assertContains('design-hotline-row', preg_split('/\s+/', $row->getAttribute('class')), 'The existing native header-ID contract still finds the row.');
        self::assertNotContains('design-hotline-spaced', preg_split('/\s+/', $row->getAttribute('class')), 'Legacy hotline padding must not enlarge the raster row.');
        self::assertSame('', trim($row->textContent), 'Hotline lettering is rasterized with accessible ALT text, not left as dark-mode-sensitive HTML text.');
        $cells = $xpath->query('./table/tbody/tr/td|./table/tr/td', $row);
        self::assertSame(5, $cells->length);
        $images = $xpath->query('.//img', $row);
        self::assertSame(5, $images->length);
        self::assertSame($mobile ? '18' : '40', $images->item(0)->getAttribute('width'));
        self::assertSame('152', $images->item(1)->getAttribute('width'));
        self::assertSame('24/7 erreichbar', $images->item(1)->getAttribute('alt'));
        self::assertSame('42', $images->item(2)->getAttribute('width'));
        self::assertSame('42', $images->item(3)->getAttribute('width'));
        self::assertSame('100%', $images->item(4)->getAttribute('width'));
        foreach ($images as $image) {
            self::assertSame('34', $image->getAttribute('height'));
            self::assertStringStartsWith('cid:', $image->getAttribute('src'));
        }
        $links = $xpath->query('.//a', $row);
        self::assertSame(2, $links->length);
        self::assertSame('mailto:office@example.test', $links->item(0)->getAttribute('href'));
        self::assertSame('tel:+491705550202', $links->item(1)->getAttribute('href'));
        foreach ($links as $link) {
            self::assertNotSame('', trim($link->getAttribute('title').$link->getAttribute('aria-label').$link->textContent.$xpath->query('./img', $link)->item(0)?->getAttribute('alt')));
        }
        $known = array_column($media, null, 'contentId');
        foreach (self::ASSETS as $kind) {
            $assetHash = hash_file('sha256', public_path('mail-assets/outlook-hotline-'.$kind.'-v1.png'));
            $matching = array_filter($known, static fn (array $item): bool => hash('sha256', base64_decode($item['base64'], true)) === $assetHash);
            self::assertCount(1, $matching, 'Each fixed banner image has exactly one retained CID attachment.');
        }
        $roles = $xpath->query('//p[normalize-space(.)="Disposition"]');
        self::assertSame(1, $roles->length);
        foreach ($xpath->query('self::*|.//*[@style]', $roles->item(0)) as $role) {
            self::assertDoesNotMatchRegularExpression('/background(?:-color)?\s*:\s*(?:#fff(?:fff)?|white|rgb\(\s*255\s*,\s*255\s*,\s*255\s*\))/i', $role->getAttribute('style'));
        }
        if ($personal) {
            self::assertSame(1, $xpath->query('//table[contains(concat(" ",normalize-space(@class)," ")," rt-personal-logo-frame ")]')->length);
            self::assertSame(1, $xpath->query('//div[contains(concat(" ",normalize-space(@class)," ")," rt-personal-name ")]')->length);
            self::assertSame(1, $xpath->query('//table[contains(concat(" ",normalize-space(@class)," ")," rt-personal-logo-frame ")]//img')->length, 'MSO fallback comments do not become a second visible logo.');
            $modern = preg_replace('~<!--.*?-->~s', '', $html);
            $logoAt = strpos($modern, 'class="rt-personal-logo-frame');
            $nameAt = strpos($modern, 'class="rt-personal-name');
            self::assertNotFalse($logoAt);
            self::assertNotFalse($nameAt);
            self::assertSame($mobile, $logoAt < $nameAt, 'Mobile places the same logo above the name; desktop keeps it in the right column.');
            if ($mobile) {
                $logoCell = $xpath->query('//table[contains(concat(" ",normalize-space(@class)," ")," rt-personal-logo-frame ")]//td')->item(0);
                self::assertInstanceOf(DOMElement::class, $logoCell);
                self::assertMatchesRegularExpression('/padding(?:-bottom\s*:\s*10px|\s*:\s*0(?:px)?\s+0(?:px)?\s+10px)/i', $logoCell->getAttribute('style'));
            }
        }
    }

    private function assertCids(string $html, array $media): void
    {
        $known = array_column($media, null, 'contentId');
        self::assertCount(count($media), $known, 'No duplicate CID attachment names.');
        preg_match_all('~\bsrc="cid:([^"]+)"~', $html, $references);
        $cids = array_values(array_unique($references[1]));
        sort($cids);
        $keys = array_keys($known);
        sort($keys);
        self::assertSame($keys, $cids, 'Every CID is present and every retained attachment is referenced, including conditional MSO branches.');
        foreach ($known as $item) {
            self::assertNotFalse(base64_decode($item['base64'], true));
        }
    }

    private function assertVersion(string $html, string $marker, string $version): void
    {
        self::assertMatchesRegularExpression('/\A[0-9a-f]{16}\z/', $version);
        self::assertSame(2, substr_count($html, $marker.$version));
    }

    private function exportPreview(string $name, array $document, bool $noInline = false): void
    {
        $output = getenv('RAILTIME_BRAND_PREVIEW_DIR');
        if (! is_string($output) || $output === '') {
            return;
        }
        $expected = 'C:/Users/L. Zacharias/Documents/RailTime/.lmzdev/artifacts/temp/outlook-brand-darkmode-20261027-previews';
        self::assertSame(strtolower($expected), strtolower(str_replace('\\', '/', rtrim($output, '/\\'))));
        if (! is_dir($output)) {
            mkdir($output, 0777, true);
        }
        preg_match_all('~<style\b[^>]*>(.*?)</style\s*>~is', $document['html'], $styles);
        file_put_contents($output.'/'.$name.'.metrics.json', json_encode([
            'synthetic' => true,
            'transportUtf16' => intdiv(strlen(mb_convert_encoding($document['html'], 'UTF-16LE', 'UTF-8')), 2),
            'transportCssBytes' => array_sum(array_map('strlen', $styles[1])),
            'mediaCount' => count($document['media']),
            'mediaBytes' => array_sum(array_map(static fn (array $item): int => strlen(base64_decode($item['base64'], true)), $document['media'])),
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        $known = array_column($document['media'], null, 'contentId');
        $html = preg_replace_callback('~\bsrc="cid:([^"]+)"~', static fn (array $source): string => 'src="data:image/'.(str_ends_with(strtolower($known[$source[1]]['name']), '.gif') ? 'gif' : 'png').';base64,'.$known[$source[1]]['base64'].'"', $document['html']);
        if ($noInline) {
            $html = preg_replace('~\sstyle="[^"]*"~', '', $html);
        }
        file_put_contents($output.'/'.$name.'.html', '<!doctype html><html lang="de"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Synthetic actual branded signature – '.$name.'</title><style>body{margin:0;background:#fff;font-family:Arial,sans-serif;}</style><body>'.$html.'</body></html>');
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
