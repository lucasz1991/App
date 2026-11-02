<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\UserProfile;
use App\Support\OutlookAddin\OutlookAddinPayloadService;
use App\Support\OutlookAddin\OutlookClientPresentation;
use App\Support\OutlookAddin\OutlookCompactReferencePresentation;
use App\Support\OutlookAddin\OutlookMobileReferencePresentation as Mobile;
use App\Support\OutlookAddin\OutlookMobileSignature;
use App\Support\OutlookAddin\OutlookStaticLinkedTestProfiles;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use RuntimeException;
use Tests\TestCase;

final class OutlookMobileReferencePresentationTest extends TestCase
{
    public function test_only_physical_mobile_fields_are_projected_with_an_exact_inverse(): void
    {
        [$mobile, $desktop] = $this->entries();
        foreach (['html', 'composeHtml', 'combinedComposeHtml'] as $field) {
            self::assertSame($mobile[$field], Mobile::document($mobile[$field]));
            self::assertSame($desktop[$field], Mobile::document($desktop[$field]));
        }
        self::assertSame($desktop['signature']['html'], Mobile::document($desktop['signature']['html']));
        foreach ([$mobile['signature']['html'], $mobile['mobileComposeHtml']] as $source) {
            $output = Mobile::document($source);
            self::assertNotSame($source, $output);
            self::assertSame($source, Mobile::canonicalize($output));
            self::assertSame($output, Mobile::document($output));
            self::assertSame(OutlookCompactReferencePresentation::canonicalizeToV16($source), OutlookCompactReferencePresentation::canonicalizeToV16($output));
            self::assertSame($this->text($source), $this->text($output));
            self::assertSame($this->materials(str_replace(['/m/4a55285090.png', 'Dispo 24/7 erreichbar'], ['/m/fc43cf451b.png', 'Dispo 24/7'], $source)), $this->materials($output));
            self::assertSame(substr_count($source, 'RT-SIGNATURE-VERSION:'), substr_count($output, 'RT-SIGNATURE-VERSION:'));
        }
        Http::assertNothingSent();
    }

    public function test_mobile_design_has_a_right_logo_two_contact_columns_then_train_one_flat_bar_and_legal(): void
    {
        [$entry] = $this->entries();
        foreach ([$entry['signature']['html'], $entry['mobileComposeHtml']] as $source) {
            $html = Mobile::document($source);
            $xpath = $this->xpath($html);
            $root = $this->one($xpath, 'rt-mobile-reference-v1');
            self::assertSame('v1', $root->getAttribute(Mobile::MARKER));
            self::assertCount(3, $xpath->query('./tbody/tr', $root));
            self::assertSame('100%', $this->one($xpath, 'rt-v17-identity')->getAttribute('width'));
            self::assertSame('52%', $this->one($xpath, 'rt-v17-direct')->getAttribute('width'));
            self::assertSame('48%', $this->one($xpath, 'rt-v17-company')->getAttribute('width'));
            $logo = $this->one($xpath, 'rt-personal-logo-frame');
            self::assertSame('104', $logo->getAttribute('width'));
            self::assertSame('right', $logo->getAttribute('align'));
            self::assertStringContainsString('float:right!important', $logo->getAttribute('style'));
            $bar = $this->one($xpath, 'rt-mobile-reference-bar');
            self::assertCount(1, $xpath->query('./tbody/tr', $bar));
            self::assertCount(2, $xpath->query('./tbody/tr/td', $bar));
            self::assertStringContainsString('table-layout:auto!important', $bar->getAttribute('style'));
            $caption = $this->one($xpath, 'rt-v17-dispatch-caption');
            self::assertSame('80', $caption->getAttribute('width'));
            self::assertSame('18', $caption->getAttribute('height'));
            self::assertStringEndsWith('/m/fc43cf451b.png', $caption->getAttribute('src'));
            $actions = $this->one($xpath, 'rt-v17-dispatch-actions');
            self::assertStringContainsString('table-layout:auto!important', $actions->getAttribute('style'));
            $cells = $xpath->query('./tbody/tr/td', $actions);
            self::assertCount(3, $cells);
            self::assertSame('46', $cells->item(0)->getAttribute('width'));
            self::assertSame('26', $cells->item(1)->getAttribute('width'));
            self::assertSame('94', $cells->item(2)->getAttribute('width'));
            self::assertStringContainsString('white-space:nowrap!important', $cells->item(2)->getAttribute('style'));
            self::assertStringContainsString('padding-right:6px!important', $cells->item(1)->getAttribute('style'));
            foreach ($xpath->query('.//img', $actions) as $icon) {
                self::assertSame('20', $icon->getAttribute('width'));
                self::assertSame('20', $icon->getAttribute('height'));
            }
            self::assertCount(1, $xpath->query('//*[contains(concat(" ",normalize-space(@class)," ")," rt-v17-dispatch-block ")]'));
            self::assertStringContainsString('border-radius:0', $this->one($xpath, 'rt-v17-dispatch-block')->getAttribute('style'));
            self::assertStringContainsString('padding:0 18px 0 0', $this->one($xpath, 'rt-v17-train-cell')->getAttribute('style'));
            self::assertSame('529', $this->one($xpath, 'rt-v17-train-image')->getAttribute('width'));
            self::assertLessThan(strpos($html, 'data-rt-mobile-reference-bar'), strpos($html, 'class="rt-v17-train'));
            self::assertLessThan(strpos($html, 'class="rt-brand-legal-table'), strpos($html, 'data-rt-mobile-reference-bar'));
            foreach ($xpath->query('//*[@style]') as $element) {
                self::assertDoesNotMatchRegularExpression('/\b(?:calc|clamp|min|max)\s*\(/i', $element->getAttribute('style'));
            }
            foreach ($xpath->query('//*[contains(concat(" ",normalize-space(@class)," ")," rt-v17-direct ")]//p | //*[contains(concat(" ",normalize-space(@class)," ")," rt-v17-company ")]//p') as $p) {
                self::assertMatchesRegularExpression('/font-size:12px(?:!important)?;/', $p->getAttribute('style'));
                self::assertMatchesRegularExpression('/line-height:18px(?:!important)?;/', $p->getAttribute('style'));
            }
        }
    }

    #[DataProvider('profiles')]
    public function test_variable_contacts_and_long_labels_keep_their_text_links_and_exact_source(array $profile): void
    {
        [$entry] = $this->entries($profile);
        foreach ([$entry['signature']['html'], $entry['mobileComposeHtml']] as $source) {
            $html = Mobile::document($source);
            self::assertSame($source, Mobile::canonicalize($html));
            self::assertSame($this->text($source), $this->text($html));
            self::assertSame($this->hrefs($source), $this->hrefs($html));
            self::assertSame(substr_count($source, '.gif'), substr_count($html, '.gif'));
        }
        Http::assertNothingSent();
    }

    public static function profiles(): iterable
    {
        yield 'no direct phone' => [['phone' => '', 'mobile' => '']];
        yield 'telephone only' => [['phone' => '+49 30 5550101', 'mobile' => '']];
        yield 'mobile only' => [['phone' => '', 'mobile' => '+49 170 5550101']];
        yield 'both phones' => [['phone' => '+49 30 5550101', 'mobile' => '+49 170 5550101']];
        yield 'long ascii labels' => [['first_name' => 'Alexander Christian', 'last_name' => 'von Muster-Lange und Beispielhausen', 'position' => 'Internationaler Fernverkehr und Disposition']];
        yield 'unicode escaped labels' => [['first_name' => 'Jörg & Élodie', 'last_name' => 'Müller <Schiene> 漢字', 'position' => 'Disposition & Logistik <Nord>']];
    }

    public function test_maximum_escaped_optional_wording_with_long_names_keeps_the_30000_wire_limit(): void
    {
        [$entry] = $this->entries(['first_name' => 'Alexander Christian', 'last_name' => 'von Muster-Lange und Beispielhausen', 'position' => 'Internationaler Fernverkehr und Disposition'], ['greeting' => str_repeat('&', 120), 'farewell' => str_repeat('&', 120), 'showGreeting' => true, 'showFarewell' => true]);
        foreach ([$entry['signature']['html'], $entry['mobileComposeHtml']] as $source) {
            $output = Mobile::document($source);
            self::assertLessThanOrEqual(30000, intdiv(strlen(mb_convert_encoding($output, 'UTF-16LE', 'UTF-8')), 2) + 168);
            self::assertSame($source, Mobile::canonicalize($output));
        }
    }

    #[DataProvider('tampering')]
    public function test_foreign_owned_geometry_and_media_are_rejected(string $before, string $after): void
    {
        [$entry] = $this->entries();
        $html = Mobile::document($entry['mobileComposeHtml']);
        self::assertStringContainsString($before, $html);
        $this->expectException(RuntimeException::class);
        Mobile::canonicalize(str_replace($before, $after, $html));
    }

    public static function tampering(): iterable
    {
        yield 'foreign marker' => ['data-rt-mobile-reference="v1"', 'data-rt-mobile-reference="v2"'];
        yield 'foreign contact width' => ['width:52%!important', 'width:53%!important'];
        yield 'foreign caption asset' => ['/m/fc43cf451b.png', '/m/fffffffff0.png'];
        yield 'foreign caption geometry' => ['width="80" height="18"', 'width="81" height="18"'];
        yield 'fixed squeezed action layout' => [';table-layout:auto!important;', ';table-layout:fixed!important;'];
        yield 'removed number nowrap' => ['width:94px;white-space:nowrap!important;', 'width:94px;white-space:normal!important;'];
        yield 'wrong number width' => ['width="94"', 'width="1"'];
        yield 'unknown logo float' => ['float:right!important', 'float:left!important'];
        yield 'foreign train gap' => ['padding:0 18px 0 0', 'padding:0 0 0 0'];
    }

    public function test_unknown_desktop_historical_quoted_and_fake_mobile_hosts_are_not_owned(): void
    {
        foreach (['<p>Unrelated</p>', '<!-- outlook-desktop-flow-v17 rt-mobile-ledger --><p>Message</p>'] as $html) {
            self::assertSame($html, Mobile::document($html));
            self::assertSame($html, Mobile::canonicalize($html));
        }
        [$entry] = $this->entries();
        $historical = OutlookCompactReferencePresentation::canonicalizeToV16($entry['mobileComposeHtml']);
        self::assertSame($historical, Mobile::document($historical));
        $this->expectException(RuntimeException::class);
        Mobile::document('<blockquote>'.$entry['signature']['html'].'</blockquote>');
    }

    private function entries(array $profile = [], ?array $wording = null): array
    {
        $fixture = new OutlookV30ReferencePresentationTest('test_actual_employee_fields_keep_the_published_source_and_exact_inverse_with_remote_animation');
        [$canonical, $user] = (new ReflectionMethod($fixture, 'source'))->invoke($fixture);
        if ($profile !== [] || $wording !== null) {
            UserProfile::query()->where('user_id', $user->id)->firstOrFail()->forceFill($profile)->saveQuietly();
            app()->forgetScopedInstances();
            $service = app(OutlookAddinPayloadService::class);
            $canonical = $service->forUser($user->fresh(), $wording);
            $canonical['version']['personal'] = substr($service->sourceFingerprint($user->fresh()), 0, 16);
        }
        $desktop = OutlookStaticLinkedTestProfiles::payload(OutlookClientPresentation::payload($canonical), $user->fresh());
        $mobile = OutlookStaticLinkedTestProfiles::payload(OutlookClientPresentation::payload(OutlookMobileSignature::payload($canonical)), $user->fresh());
        $entries = [$mobile['templates'][0], $desktop['templates'][0]];
        foreach ($entries as &$entry) {
            foreach (['html', 'composeHtml', 'combinedComposeHtml', 'mobileComposeHtml'] as $field) {
                if (isset($entry[$field])) {
                    $entry[$field] = Mobile::canonicalize($entry[$field]);
                }
            }
            $entry['signature']['html'] = Mobile::canonicalize($entry['signature']['html']);
        }

        return $entries;
    }

    private function xpath(string $html): DOMXPath
    {
        $dom = new DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET);

        return new DOMXPath($dom);
    }

    private function one(DOMXPath $xpath, string $class): DOMElement
    {
        $nodes = $xpath->query('//*[contains(concat(" ",normalize-space(@class)," ")," '.$class.' ")]');
        self::assertCount(1, $nodes);

        return $nodes->item(0);
    }

    private function text(string $html): string
    {
        $dom = $this->xpath($html);
        $parts = [];
        foreach ($dom->query('//text()[not(ancestor::style)]') as $node) {
            if (trim($node->nodeValue) !== '') {
                $parts[] = $node->nodeValue;
            }
        }
        sort($parts);

        return implode("\n", $parts);
    }

    private function hrefs(string $html): array
    {
        $hrefs = [];
        foreach ($this->xpath($html)->query('//a') as $element) {
            $hrefs[] = $element->getAttribute('href');
        }
        sort($hrefs);

        return $hrefs;
    }

    private function materials(string $html): array
    {
        $materials = [];
        foreach ($this->xpath($html)->query('//a|//img') as $element) {
            $materials[] = [$element->tagName, $element->getAttribute('href'), $element->getAttribute('src'), $element->getAttribute('alt')];
        }
        sort($materials);

        return $materials;
    }
}
