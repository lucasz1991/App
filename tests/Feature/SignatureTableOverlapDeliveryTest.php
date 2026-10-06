<?php

namespace Tests\Feature;

use App\Support\Mail\SignatureDocumentContract;
use App\Support\Mail\SignatureTableOverlap;
use App\Support\Mail\SignatureTableOverlapDelivery;
use App\Support\Mail\TrustedEmailCss;
use App\Support\Mail\TrustedOutlookSignatureCss;
use App\Support\OutlookAddin\OutlookMobileSignature;
use DOMDocument;
use DOMXPath;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class SignatureTableOverlapDeliveryTest extends TestCase
{
    public static function versions(): array
    {
        return [['v27'], ['v28'], ['v29']];
    }

    #[DataProvider('versions')]
    public function test_delivery_is_bounded_structural_and_leaves_the_source_contract_intact(string $version): void
    {
        $source = trim(file_get_contents(base_path('tests/Fixtures/mail/signature-v27.html')));
        if ($version !== 'v27') {
            $source = SignatureTableOverlap::mirroredFromV27($source, $version);
        }
        $before = $source;
        $rendered = str_replace('{{TRAIN_SRC}}', 'cid:train.gif', $source);
        $projected = SignatureTableOverlapDelivery::project($rendered, 'cid:train.png');
        SignatureDocumentContract::assertValid($source);
        $this->assertSame($before, $source);
        SignatureTableOverlapDelivery::assertRuntime($projected, 'cid:train.gif', 'cid:train.png');
        $this->assertSame($projected, SignatureTableOverlapDelivery::project($projected, 'cid:train.png'));
        $this->assertStringNotContainsString('6031.746032', $projected);
        $this->assertStringNotContainsString('rt-v27-anchor', $projected);
        $this->assertStringNotContainsString('background-image:', $projected);
        $this->assertStringContainsString('<!--[if !mso]><!--><img class="rt-delivery-train"', $projected);
        $this->assertStringContainsString('width="300" alt=""', $projected);
        $this->assertStringContainsString('height="38"', $projected);
        $this->assertStringContainsString('<!--[if mso]><img class="rt-delivery-train-mso" src="cid:train.png" width="300" height="38"', $projected);
        $this->assertLessThan(strpos($projected, 'rt-delivery-train-row'), strpos($projected, 'rt-sign-content"'));
        $css = TrustedEmailCss::forDocument($projected);
        $this->assertStringContainsString('max-width:600px', $css);
        $this->assertStringNotContainsString('width:183.796856%', $css);
        $this->assertStringNotContainsString('width:6031.746032%', $css);
        $outlookCss = TrustedOutlookSignatureCss::responsive($projected);
        $this->assertStringContainsString('.rt-delivery-train', $outlookCss);
        $this->assertLessThanOrEqual(12288, strlen($outlookCss));
        $dom = new DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8"><table>'.$projected.'</table>');
        $xpath = new DOMXPath($dom);
        foreach (['rt-sign-heading-person', 'rt-sign-heading-logo', 'rt-sign-identity', 'rt-sign-company'] as $class) {
            $cell = $xpath->query('//td[contains(concat(" ",normalize-space(@class)," ")," '.$class.' ")]')->item(0);
            $this->assertSame('100%', $cell->getAttribute('width'));
            $this->assertSame(1, $xpath->query('./td', $cell->parentNode)->length);
        }
    }

    public function test_ledger_contact_groups_remain_usable_without_any_css(): void
    {
        $source = file_get_contents(base_path('tests/Fixtures/mail/signature-v27-ledger.html'));
        $html = SignatureTableOverlapDelivery::project(str_replace(['{{TRAIN_SRC}}', '{{E_MAIL}}'], ['cid:train.gif', 'employee@example.test'], $source), 'cid:train.png');
        $withoutCss = preg_replace('~\sstyle="[^"]*"~', '', $html);
        $dom = new DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8"><table>'.$withoutCss.'</table>');
        $xpath = new DOMXPath($dom);
        foreach (['rt-ledger-brand', 'rt-ledger-contacts', 'rt-ledger-direct', 'rt-ledger-company'] as $class) {
            $cells = $xpath->query('//*[self::td or self::th][contains(concat(" ",normalize-space(@class)," ")," '.$class.' ")]');
            $this->assertCount(1, $cells);
            $outer = in_array($class, ['rt-ledger-brand', 'rt-ledger-contacts'], true);
            $this->assertSame($outer ? ($class === 'rt-ledger-brand' ? '32%' : '68%') : '100%', $cells->item(0)->getAttribute('width'));
            $this->assertSame($outer ? 2 : 1, $xpath->query('./td|./th', $cells->item(0)->parentNode)->length);
            $this->assertSame($outer ? 'th' : 'td', $cells->item(0)->tagName);
            $this->assertStringContainsString($outer ? 'rt-delivery-wide-column' : 'rt-delivery-group-cell', $cells->item(0)->getAttribute('class'));
        }
        $image = $xpath->query('//img[@class="rt-delivery-train"]')->item(0);
        $this->assertSame('300', $image->getAttribute('width'));
        $this->assertSame('38', $image->getAttribute('height'));
        $logo = $xpath->query('//img[@class="rt-logo"]')->item(0);
        $this->assertSame('180', $logo->getAttribute('width'));
        $this->assertSame('31', $logo->getAttribute('height'));
        $this->assertSame(1, substr_count($html, '{{VORNAME_NACHNAME}}'));
        $this->assertSame(1, substr_count($html, 'mailto:employee@example.test'));
        $this->assertStringContainsString('max-width:860px', TrustedEmailCss::forDocument($html));
        foreach ([TrustedEmailCss::forDocument($html), TrustedOutlookSignatureCss::responsive($html)] as $css) {
            $this->assertStringNotContainsString('.rt-dt', $css);
            $this->assertStringNotContainsString('.rt-dr', $css);
            $this->assertStringNotContainsString('.rt-lr', $css);
            $this->assertStringNotContainsString('.rt-cr', $css);
            $this->assertStringContainsString('.rt-delivery-group-cell{display:table-cell!important;', $css);
        }
        $this->assertSame(0, $xpath->query('//table[contains(concat(" ",@class," ")," rt-sign-layout ")]')->length);
    }

    public function test_media_mapping_preserves_cids_and_only_projects_known_train_filenames(): void
    {
        $this->assertSame('cid:railtime-train', SignatureTableOverlapDelivery::source('cid:railtime-train'));
        $this->assertSame('https://example.test/mail-assets/zug-dampf-v27-delivery-light-mirrored.gif?p=123', SignatureTableOverlapDelivery::source('https://example.test/mail-assets/zug-dampf-v27-light-mirrored.gif?p=123'));
        foreach (['v27', 'v28', 'v29'] as $version) {
            foreach (['light', 'dark'] as $theme) {
                foreach ([true, false] as $animated) {
                    $asset = SignatureTableOverlapDelivery::asset($theme, $animated, $version);
                    $size = getimagesize(public_path('mail-assets/'.$asset));
                    $this->assertSame($theme === 'light' ? [1205, 151] : [1204, 151], array_slice($size, 0, 2));
                    $this->assertSame($animated ? 'image/gif' : 'image/png', $size['mime']);
                }
            }
        }
    }

    public function test_mobile_delivery_css_retains_headroom_for_personal_profile_transport(): void
    {
        $source = file_get_contents(base_path('tests/Fixtures/mail/signature-v27-ledger.html'));
        $rows = preg_replace_callback('/\{\{([A-Z0-9_]+)\}\}/', static fn (array $match): string => match ($match[1]) {
            'VORNAME_NACHNAME' => 'Very long example employee',
            'E_MAIL' => 'long-professional-address-for-example@example.test',
            'LOGO_SRC', 'LOGO_STILL_SRC' => 'cid:logo.gif',
            'TRAIN_SRC' => 'cid:train.gif',
            'FIRMEN_WEBSITE_HREF' => 'https://example.test',
            default => str_contains($match[1], '_SRC') ? 'cid:icon.png' : 'Example business contact',
        }, $source);
        $rows = SignatureTableOverlapDelivery::project($rows, 'cid:train-still.png');
        $html = '<!-- RT-SIGNATURE-VERSION:0123456789abcdef -->'
            .'<span style="display:none">RT-SIGNATURE-VERSION:0123456789abcdef</span>'
            .TrustedOutlookSignatureCss::style($rows, scopeClass: 'rts0123456789')
            .'<div class="rt-outlook-signature rts0123456789"><table width="100%" cellspacing="0" cellpadding="0"><tbody>'.$rows.'</tbody></table></div>';
        $payload = [
            'signature' => ['html' => $html, 'media' => []],
            'templates' => [],
            'version' => ['signature' => '0123456789abcdef', 'personal' => 'example-personal'],
        ];
        $mobile = OutlookMobileSignature::payload($payload);
        preg_match_all('~<style\b[^>]*>(.*?)</style>~is', $mobile['signature']['html'], $styles);
        $this->assertLessThanOrEqual(11000, array_sum(array_map('strlen', $styles[1])), 'Reserve at least 1 KiB below the combined 12 KiB transport limit.');
        $this->assertLessThanOrEqual(28000, strlen(mb_convert_encoding($mobile['signature']['html'], 'UTF-16LE', 'UTF-8')) / 2);
        $this->assertSame($html, $payload['signature']['html']);
    }

    public function test_long_realistic_contact_values_use_real_paragraphs_without_changing_links_or_text(): void
    {
        $source = file_get_contents(base_path('tests/Fixtures/mail/signature-v27-ledger.html'));
        $name = 'Alexandra Christine von RailTime Musterhausen';
        $email = 'alexandra.christine@operations.railtime-example.test';
        $source = str_replace(['{{TRAIN_SRC}}', '{{VORNAME_NACHNAME}}', '{{E_MAIL}}'], ['cid:train.gif', $name, $email], $source);
        $html = SignatureTableOverlapDelivery::project($source, 'cid:train.png');
        $dom = new DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8"><table>'.$html.'</table>');
        $xpath = new DOMXPath($dom);
        foreach ($xpath->query('//td[contains(concat(" ",@class," ")," rt-contact-text ")]') as $cell) {
            $this->assertFalse($cell->hasAttribute('width'));
            $this->assertSame(1, $xpath->query('./p[@class="rt-delivery-contact-value"]', $cell)->length);
            $this->assertStringContainsString('word-break:break-all', $cell->firstElementChild->getAttribute('style'));
        }
        $link = $xpath->query('//a[@href="mailto:'.$email.'"]')->item(0);
        $this->assertSame($email, $link->textContent);
        $this->assertSame(1, substr_count($html, $name));
        $this->assertSame(0, $xpath->query('//wbr')->length);
        $css = TrustedOutlookSignatureCss::responsive($html);
        $this->assertStringContainsString('.rt-delivery-contact-value{margin:0!important;word-break:break-all!important;', $css);
        $this->assertStringNotContainsString('>tbody{display:table-row', $css);
        $this->assertStringNotContainsString('.rt-delivery-ledger-group{display:table-cell', $css);
    }

    public function test_outlook_fallback_contract_rejects_changed_numeric_geometry_and_untrusted_sources(): void
    {
        $source = file_get_contents(base_path('tests/Fixtures/mail/signature-v27-ledger.html'));
        $html = SignatureTableOverlapDelivery::project(str_replace('{{TRAIN_SRC}}', 'cid:train.gif', $source), 'cid:train.png');
        foreach ([
            str_replace('width="300" height="38"', 'width="60000" height="38"', $html),
            str_replace('width="300" height="38"', 'width="300" height="7500"', $html),
            str_replace('src="cid:train.png"', 'src="javascript:alert(1)"', $html),
            str_replace('<!--<![endif]-->', '', $html),
        ] as $mutation) {
            try {
                SignatureTableOverlapDelivery::assertRuntime($mutation);
                $this->fail('A changed Outlook fallback must be rejected.');
            } catch (\RuntimeException $exception) {
                $this->assertStringContainsString('Outlook-Fallback', $exception->getMessage());
            }
        }
    }

    public function test_downlevel_train_contract_does_not_depend_on_image_attribute_order(): void
    {
        $source = file_get_contents(base_path('tests/Fixtures/mail/signature-v27-ledger.html'));
        $source = str_replace(
            '<img class="rt-sign-train" data-rt-train src="{{TRAIN_SRC}}" width="1216" alt=""',
            '<img src="{{TRAIN_SRC}}" width="1216" alt="" class="rt-sign-train" data-rt-train',
            $source,
        );
        $html = SignatureTableOverlapDelivery::project(str_replace('{{TRAIN_SRC}}', 'cid:train.gif', $source), 'cid:train.png');
        $this->assertStringContainsString('<img src="cid:train.gif" width="300" alt="" class="rt-delivery-train"', $html);
        SignatureTableOverlapDelivery::assertRuntime($html, 'cid:train.gif', 'cid:train.png');
        $this->assertSame($html, SignatureTableOverlapDelivery::project($html, 'cid:train.png'));
        $this->assertSame(1, substr_count($html, '<!--<![endif]-->'));
    }
}
