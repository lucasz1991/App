<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\MailDocumentKind;
use App\Models\MailDocument;
use App\Models\User;
use App\Support\EmailTemplateBuilder;
use App\Support\Mail\PublishedMailDocumentSnapshotStore;
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
use TijsVerkoyen\CssToInlineStyles\CssToInlineStyles;

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
            $this->assertDocument($template['html'], $template['media'], false, 99000, 24576, $personal, false);
            $this->assertDocument($template['combinedComposeHtml'], $template['combinedComposeMedia'], false, 99000, 24576, $personal);
            $this->assertDocument($paired['signature']['html'], $paired['signature']['media'], true, 30000, 12288, $personal);
            $this->assertDocument($paired['mobileComposeHtml'], $paired['mobileComposeMedia'], true, 30000, 12288, $personal);
            $this->assertVersion($paired['signature']['html'], 'RT-SIGNATURE-VERSION:', $paired['signatureVersion']);
            $this->assertVersion($paired['mobileComposeHtml'], 'RT-MOBILE-COMPOSE-VERSION:', $paired['mobileComposeVersion']);
            if ($personal) {
                $name = 'desktop-combined-'.($template['isDefault'] ? 'default' : 'greeting');
                $document = ['html' => $template['combinedComposeHtml'], 'media' => $template['combinedComposeMedia']];
                $this->exportPreview($name, $document);
                $this->exportPreview($name.'-noinline', $document, true);
            }
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
        $dimensions = ['fill' => [1, 34], 'label' => [411, 102], 'mail' => [126, 102], 'phone' => [126, 102]];
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
        $this->matchCanonicalTemplateFixtureFragments();
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

    public function test_important_legacy_hotline_runtime_padding_cannot_add_an_outer_gutter_to_any_output_path(): void
    {
        [$payload, , $case] = $this->fixture(true);
        $personalDocument = (new ReflectionProperty($case, 'personal'))->getValue($case);
        self::assertSame('', $personalDocument->fresh()->published_css, 'The adversarial important rule is generated by the real trusted runtime; forbidden editable CSS is not injected.');
        $desktop = OutlookClientPresentation::payload($payload);
        $mobile = OutlookClientPresentation::payload(OutlookMobileSignature::payload($payload));
        $desktopHtml = $desktop['signature']['html'];
        $retainsAdversarialPadding = preg_match('/\.design-hotline-row[^{}]*\{[^{}]*padding\s*:\s*0\s+40px\s*!important/i', $desktopHtml) === 1
            || (preg_match('/\.design-hotline-row[^{}]*\{[^{}]*padding-left\s*:\s*40px\s*!important/i', $desktopHtml) === 1
                && preg_match('/\.design-hotline-row[^{}]*\{[^{}]*padding-right\s*:\s*40px\s*!important/i', $desktopHtml) === 1);
        self::assertTrue($retainsAdversarialPadding, 'The actual legacy runtime rule is retained by desktop inlining, including normalized side declarations; the owned inline priority must defeat it.');
        $documents = [
            [$desktop['signature']['html'], $desktop['signature']['media'], false, 30000, 12288],
            [$mobile['signature']['html'], $mobile['signature']['media'], true, 30000, 12288],
        ];
        foreach ($desktop['templates'] as $index => $template) {
            $paired = $mobile['templates'][$index];
            $documents[] = [$template['html'], $template['media'], false, 99000, 24576, false];
            $documents[] = [$template['signature']['html'], $template['signature']['media'], false, 30000, 12288];
            $documents[] = [$template['combinedComposeHtml'], $template['combinedComposeMedia'], false, 99000, 24576];
            $documents[] = [$paired['signature']['html'], $paired['signature']['media'], true, 30000, 12288];
            $documents[] = [$paired['mobileComposeHtml'], $paired['mobileComposeMedia'], true, 30000, 12288];
        }
        foreach ($documents as $document) {
            [$html, $media, $physicalMobile, $characters, $cssBytes] = $document;
            $this->assertDocument($html, $media, $physicalMobile, $characters, $cssBytes, true, $document[5] ?? true);
        }
        $this->exportPreview('desktop-published-padding', $desktop['signature']);
        $this->exportPreview('desktop-published-padding-noinline', $desktop['signature'], true);
        $this->exportPreview('mobile-published-padding', $mobile['signature']);
        $this->exportPreview('mobile-published-padding-noinline', $mobile['signature'], true);
    }

    /** @return array{array, User, V27PersonalLayoutTest} */
    private function fixture(bool $personal): array
    {
        self::assertSame('sqlite', config('database.default'));
        self::assertSame(':memory:', config('database.connections.sqlite.database'));
        $case = new V27PersonalLayoutTest('test_separate_sources_are_valid_and_company_render_remains_exactly_the_unmodified_delivery_path');
        (new ReflectionMethod($case, 'setUp'))->invoke($case);
        $this->matchCanonicalTemplateFixtureFragments();
        $user = (new ReflectionProperty($case, 'person'))->getValue($case);
        self::assertInstanceOf(User::class, $user);
        $system = (new ReflectionProperty($case, 'system'))->getValue($case);
        $personalDocument = (new ReflectionProperty($case, 'personal'))->getValue($case);
        $template = (new ReflectionProperty($case, 'template'))->getValue($case);
        $variant = (new ReflectionProperty($case, 'variant'))->getValue($case);
        if (! $personal) {
            self::assertSame(1, MailDocument::query()->whereKey($personalDocument->getKey())->update(['outlook_default' => null]));
            self::assertSame(1, MailDocument::query()->whereKey($system->getKey())->update(['outlook_default' => true]));
            self::assertSame(2, MailDocument::query()->whereKey([$template->getKey(), $variant->getKey()])->update(['signature_document_id' => $system->getKey(), 'published_signature_document_id' => $system->getKey()]));
            app(PublishedMailDocumentSnapshotStore::class)->forget(MailDocumentKind::Signature);
            app()->forgetScopedInstances();
        }

        return [app(OutlookAddinPayloadService::class)->forUser($user), $user, $case];
    }

    /** PHP nowdocs use LF even when the checked-out canonical resource uses CRLF. */
    private function matchCanonicalTemplateFixtureFragments(): void
    {
        $master = file_get_contents(resource_path('mail-templates/email-master.html'));
        self::assertIsString($master);
        $patterns = ['~<style\b[^>]*>.*?</style\s*>~is', '~<!-- RT_TEMPLATE_MARK_START -->.*?<!-- RT_TEMPLATE_MARK_END -->~s'];
        $canonical = [];
        foreach ($patterns as $pattern) {
            self::assertSame(1, preg_match_all($pattern, $master, $matches));
            $canonical[] = $matches[0][0];
        }
        $documents = MailDocument::query()->where('kind', 'template')->orderBy('id')->get();
        self::assertCount(2, $documents);
        foreach ($documents as $document) {
            self::assertSame($document->html, $document->published_html);
            $source = $document->html;
            foreach ($patterns as $index => $pattern) {
                $source = preg_replace_callback($pattern, static fn (): string => $canonical[$index], $source, -1, $count);
                self::assertSame(1, $count, 'Only the exact canonical head/mark fixture fragments are copied.');
            }
            if ($source === $document->html) {
                continue;
            }
            $builder = $document->builder_data;
            self::assertIsString($builder['pages'][0]['component']);
            foreach ($patterns as $index => $pattern) {
                $builder['pages'][0]['component'] = preg_replace_callback($pattern, static fn (): string => $canonical[$index], $builder['pages'][0]['component'], -1, $count);
                self::assertSame(1, $count);
            }
            $document->forceFill([
                'html' => $source, 'published_html' => $source, 'builder_data' => $builder,
                'content_hash' => MailDocument::contentHashFor($builder, $source, $document->css),
            ])->saveQuietly();
        }
    }

    private function assertDocument(string $html, array $media, bool $mobile, int $characters, int $cssBytes, bool $personal, bool $mirrored = true): void
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
        $this->assertZeroOuterPadding($row);
        self::assertContains('design-hotline-row', preg_split('/\s+/', $row->getAttribute('class')), 'The existing native header-ID contract still finds the row.');
        self::assertNotContains('design-hotline-spaced', preg_split('/\s+/', $row->getAttribute('class')), 'Legacy hotline padding must not enlarge the raster row.');
        self::assertSame('', trim($row->textContent), 'Hotline lettering is rasterized with accessible ALT text, not left as dark-mode-sensitive HTML text.');
        $frame = $xpath->query('./table', $row);
        self::assertSame(1, $frame->length);
        self::assertMatchesRegularExpression('/(?:^|;)\s*table-layout\s*:\s*fixed\s*(?:;|$)/i', $frame->item(0)->getAttribute('style'));
        $cells = $xpath->query('./table/tbody/tr/td|./table/tr/td', $row);
        self::assertSame(5, $cells->length);
        $images = $xpath->query('.//img', $row);
        self::assertSame(5, $images->length);
        self::assertSame($mobile ? '18' : '40', $cells->item(0)->getAttribute('width'));
        self::assertSame($mobile ? '18' : '40', $images->item(0)->getAttribute('width'));
        self::assertSame('137', $images->item(1)->getAttribute('width'));
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
        if ($personal) {
            foreach ($xpath->query('self::*|.//*[@style]', $roles->item(0)) as $role) {
                self::assertDoesNotMatchRegularExpression('/background(?:-color)?\s*:\s*(?:#fff(?:fff)?|white|rgb\(\s*255\s*,\s*255\s*,\s*255\s*\))/i', $role->getAttribute('style'));
            }
        }
        $trainCells = $xpath->query('//td[contains(concat(" ",normalize-space(@class)," ")," rt-dfc-train ")]');
        if ($trainCells->length === 0) {
            $trainCells = $xpath->query('//td[contains(concat(" ",normalize-space(@class)," ")," rt-delivery-train-cell ") or contains(concat(" ",normalize-space(@class)," ")," xq ")]');
        }
        self::assertSame(1, $trainCells->length);
        $trainStyle = $trainCells->item(0)->getAttribute('style');
        self::assertMatchesRegularExpression('/(?:^|;)\s*padding\s*:\s*0\s+'.($mobile ? '18' : '40').'px\s+0\s+0\s*(?:!important\s*)?(?:;|$)/i', $trainStyle, 'Only mobile changes the train right gutter to 18px; the desktop projection retains 40px.');
        if ($mobile) {
            self::assertMatchesRegularExpression('/(?:^|;)\s*box-sizing\s*:\s*border-box\s*(?:;|$)/i', $trainStyle);
            self::assertSame(0, $xpath->query('//*[contains(concat(" ",normalize-space(@class)," ")," rt-train-window ")]')->length);
            $trainImages = $xpath->query('.//img[contains(concat(" ",normalize-space(@class)," ")," rt-delivery-train ") or contains(concat(" ",normalize-space(@class)," ")," xr ")]', $trainCells->item(0));
            self::assertSame(1, $trainImages->length);
            self::assertMatchesRegularExpression('/(?:^|;)\s*height\s*:\s*auto\s*(?:!important\s*)?(?:;|$)/i', $trainImages->item(0)->getAttribute('style'), 'The full train and smoke keep their natural aspect ratio.');
        }
        $footerCells = $xpath->query('//td[not(.//td) and contains(.,"Diese E-Mail kann vertrauliche Informationen enthalten.")]');
        self::assertSame(1, $footerCells->length);
        $this->assertHorizontalGutter($footerCells->item(0), $mobile ? 18 : 40);
        $templatePads = $xpath->query('//td[contains(concat(" ",normalize-space(@class)," ")," rt-native-template-pad ") or contains(concat(" ",normalize-space(@class)," ")," x6 ")]');
        if ($templatePads->length === 0) {
            $templatePads = $xpath->query('//table[contains(concat(" ",normalize-space(@class)," ")," rt-shell ")]/tbody/tr/td[contains(concat(" ",normalize-space(@class)," ")," rt-pad ")] | //table[contains(concat(" ",normalize-space(@class)," ")," rt-shell ")]/tr/td[contains(concat(" ",normalize-space(@class)," ")," rt-pad ")]');
        }
        $templateRoots = $xpath->query('//*[contains(concat(" ",normalize-space(@class)," ")," rt-outlook-template ") or contains(concat(" ",normalize-space(@class)," ")," x8 ") or contains(concat(" ",normalize-space(@class)," ")," rt-shell ")]');
        if ($templateRoots->length > 0) {
            self::assertGreaterThan(0, $templatePads->length, 'A real template must expose at least one inspected content gutter.');
        }
        foreach ($templatePads as $templatePad) {
            $this->assertHorizontalGutter($templatePad, $mobile ? 18 : 40);
        }
        if ($personal) {
            $companyContacts = $xpath->query('//div[contains(concat(" ",normalize-space(@class)," ")," rt-personal-company-contacts ")]');
            self::assertSame(1, $companyContacts->length);
            self::assertMatchesRegularExpression('/padding-top\s*:\s*14px/i', $companyContacts->item(0)->getAttribute('style'));
            $contactTable = $xpath->query('.//table[contains(concat(" ",normalize-space(@class)," ")," rt-company-contact ") or contains(concat(" ",normalize-space(@class)," ")," xj ")]', $companyContacts->item(0));
            self::assertSame(1, $contactTable->length);
            $addressCells = $xpath->query('./tbody/tr[1]/td|./tr[1]/td', $contactTable->item(0));
            self::assertSame(2, $addressCells->length);
            self::assertSame('top', $addressCells->item(0)->getAttribute('valign'));
            self::assertMatchesRegularExpression('/vertical-align\s*:\s*top/i', $addressCells->item(0)->getAttribute('style'));
            self::assertMatchesRegularExpression('/(?:^|;)\s*color\s*:\s*#142231\s*;/i', $addressCells->item(1)->getAttribute('style'));
            self::assertDoesNotMatchRegularExpression('/(?:^|;)\s*color\s*:[^;]*!important/i', $addressCells->item(1)->getAttribute('style'), 'Outlook dark-mode inline colors must outrank the fallback color mirror.');
            self::assertSame(0, $xpath->query('.//a', $addressCells->item(1))->length, 'Address formatting does not invent a link.');
            $this->assertPersonalPostalForeground($html, $mirrored);
            self::assertSame(1, $xpath->query('//table[contains(concat(" ",normalize-space(@class)," ")," rt-personal-logo-frame ")]')->length);
            self::assertSame(1, $xpath->query('//div[contains(concat(" ",normalize-space(@class)," ")," rt-personal-name ")]')->length);
            self::assertSame(1, $xpath->query('//table[contains(concat(" ",normalize-space(@class)," ")," rt-personal-logo-frame ")]//img')->length, 'MSO fallback comments do not become a second visible logo.');
            $modern = preg_replace('~<!--.*?-->~s', '', $html);
            $logoAt = strpos($modern, 'class="rt-personal-logo-frame');
            $nameAt = strpos($modern, 'class="rt-personal-name');
            self::assertNotFalse($logoAt);
            self::assertNotFalse($nameAt);
            self::assertSame($mobile, $logoAt < $nameAt, 'Mobile places the same logo above the name; desktop keeps it in the right column.');
            $personalCells = $xpath->query('//td[contains(concat(" ",normalize-space(@class)," ")," rt-personal-cell-left ")]');
            self::assertSame(1, $personalCells->length);
            $this->assertHorizontalGutter($personalCells->item(0), $mobile ? 18 : 40);
            if ($mobile) {
                $logoCell = $xpath->query('//table[contains(concat(" ",normalize-space(@class)," ")," rt-personal-logo-frame ")]//td')->item(0);
                self::assertInstanceOf(DOMElement::class, $logoCell);
                self::assertMatchesRegularExpression('/padding(?:-bottom\s*:\s*10px|\s*:\s*0(?:px)?\s+0(?:px)?\s+10px)/i', $logoCell->getAttribute('style'));
            }
        } else {
            self::assertSame(0, $xpath->query('//div[contains(concat(" ",normalize-space(@class)," ")," rt-personal-name ")]')->length, 'The company-shaped provider actually uses the separate system signature source.');
            self::assertSame(0, $xpath->query('//table[contains(concat(" ",normalize-space(@class)," ")," rt-personal-logo-frame ")]')->length);
        }
    }

    /** Inspect actual personal text paint after the real cascade, not only a raw TD fallback. */
    private function assertPersonalPostalForeground(string $html, bool $mirrored): void
    {
        self::assertSame($mirrored, preg_match('~data-rt-outlook-(?:signature-inline|mobile)-css~', $html) === 1, 'Every native signature/combined document must retain a desktop or mobile style mirror; only the explicitly identified legacy full template lacks one.');
        $variants = ['inline and styles' => $html];
        if ($mirrored) {
            $variants['retained style mirrors'] = preg_replace('~\sstyle="[^"]*"~', '', $html);
        }
        foreach ($variants as $mode => $source) {
            $computed = (new CssToInlineStyles)->convert($source);
            $xpath = $this->xpath($computed);
            $tables = $xpath->query('//table[contains(concat(" ",normalize-space(@class)," ")," rt-company-contact ") or contains(concat(" ",normalize-space(@class)," ")," xj ")]');
            self::assertCount(1, $tables, $mode.': the personal company table must be present, including compact mobile aliases.');
            $table = $tables->item(0);
            $postal = $xpath->query('./tbody/tr[1]/td[2]|./tr[1]/td[2]', $table);
            self::assertCount(1, $postal, $mode.': a missing postal cell cannot skip the color check.');
            self::assertStringContainsString('Musterweg 12', $postal->item(0)->textContent);
            self::assertStringContainsString('10115 Berlin', $postal->item(0)->textContent);
            $links = $xpath->query('.//a', $table);
            self::assertCount(3, $links, $mode.': inspect the own company telephone, email and website references.');
            $reference = null;
            foreach ($links as $link) {
                $text = $xpath->query('.//text()[normalize-space(.)!=""]', $link);
                self::assertGreaterThan(0, $text->length);
                foreach ($text as $leaf) {
                    $color = $this->paintedForeground($leaf->parentNode);
                    self::assertSame('#142231', $color, $mode.': the authored contact foreground must stay unchanged.');
                    $reference ??= $color;
                    self::assertSame($reference, $color);
                }
            }
            self::assertNotNull($reference);
            $postalText = $xpath->query('.//text()[normalize-space(.)!=""]', $postal->item(0));
            self::assertCount(2, $postalText, $mode.': both street and postal-city text leaves are checked.');
            foreach ($postalText as $leaf) {
                self::assertSame($reference, $this->paintedForeground($leaf->parentNode), $mode.': each personal postal leaf must paint in its own contact foreground.');
            }
        }
    }

    private function paintedForeground(DOMElement $element): string
    {
        $color = $this->inheritedStyleValue($element, 'color');
        self::assertNotNull($color, 'An actual inherited foreground declaration must be present.');
        $fill = $this->inheritedStyleValue($element, '-webkit-text-fill-color');

        return $this->normalizedColor($fill === null || strtolower($fill) === 'currentcolor' ? $color : $fill);
    }

    private function inheritedStyleValue(DOMElement $element, string $property): ?string
    {
        for ($node = $element; $node instanceof DOMElement; $node = $node->parentNode) {
            preg_match_all('~(?:^|;)\s*'.preg_quote($property, '~').'\s*:\s*([^;]+)~i', $node->getAttribute('style'), $matches);
            $values = $matches[1];
            if ($values === []) {
                continue;
            }
            $important = array_values(array_filter($values, static fn (string $value): bool => preg_match('~!important\s*\z~i', $value) === 1));
            $value = trim(preg_replace('~\s*!important\s*\z~i', '', $important !== [] ? end($important) : end($values)));
            if (in_array(strtolower($value), ['inherit', 'unset'], true)) {
                continue;
            }

            return $value;
        }

        return null;
    }

    private function normalizedColor(string $value): string
    {
        $value = strtolower(trim($value));
        if (preg_match('/\A#([0-9a-f])([0-9a-f])([0-9a-f])\z/', $value, $short)) {
            return '#'.$short[1].$short[1].$short[2].$short[2].$short[3].$short[3];
        }
        if (preg_match('/\Argb\(\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)\s*\)\z/', $value, $rgb)) {
            return sprintf('#%02x%02x%02x', $rgb[1], $rgb[2], $rgb[3]);
        }

        return $value;
    }

    private function assertZeroOuterPadding(DOMElement $row): void
    {
        ['sides' => $sides, 'priority' => $priority] = $this->inlinePadding($row);
        foreach ($sides as $side => $value) {
            self::assertContains($value, ['0', '0px'], 'The owned header keeps zero outer '.$side.' padding after inlining.');
            self::assertSame(1, $priority[$side][0], 'The zero outer padding retains inline !important priority.');
        }
    }

    private function assertHorizontalGutter(DOMElement $element, int $pixels): void
    {
        ['sides' => $sides] = $this->inlinePadding($element);
        foreach (['left', 'right'] as $side) {
            self::assertSame($pixels.'px', $sides[$side], 'The '.trim($element->getAttribute('class')).' '.$side.' gutter aligns with the header and personal name.');
        }
    }

    private function inlinePadding(DOMElement $row): array
    {
        $sides = array_fill_keys(['top', 'right', 'bottom', 'left'], null);
        $priority = array_fill_keys(array_keys($sides), [-1, -1]);
        preg_match_all('/(?:^|;)\s*(padding(?:-(?:top|right|bottom|left))?)\s*:\s*([^;]+)/i', $row->getAttribute('style'), $declarations, PREG_SET_ORDER);
        foreach ($declarations as $order => $declaration) {
            $important = preg_match('/\s*!important\s*$/i', $declaration[2]) === 1;
            $value = trim(preg_replace('/\s*!important\s*$/i', '', $declaration[2]));
            if (strtolower($declaration[1]) === 'padding') {
                $values = preg_split('/\s+/', $value);
                self::assertContains(count($values), [1, 2, 3, 4]);
                $expanded = ['top' => $values[0], 'right' => $values[1] ?? $values[0], 'bottom' => $values[2] ?? $values[0], 'left' => $values[3] ?? $values[1] ?? $values[0]];
            } else {
                $expanded = [substr(strtolower($declaration[1]), 8) => $value];
            }
            foreach ($expanded as $side => $part) {
                if ([(int) $important, $order] >= $priority[$side]) {
                    $priority[$side] = [(int) $important, $order];
                    $sides[$side] = $part;
                }
            }
        }

        return ['sides' => $sides, 'priority' => $priority];
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
