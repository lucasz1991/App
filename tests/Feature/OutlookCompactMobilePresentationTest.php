<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\UserProfile;
use App\Support\OutlookAddin\OutlookAddinPayloadService;
use App\Support\OutlookAddin\OutlookClientPresentation;
use App\Support\OutlookAddin\OutlookCompactMobilePresentation as Mobile;
use App\Support\OutlookAddin\OutlookCompactReferencePresentation as Compact;
use App\Support\OutlookAddin\OutlookMobileReferencePresentation;
use App\Support\OutlookAddin\OutlookMobileSignature;
use App\Support\OutlookAddin\OutlookStaticLinkedTestProfiles;
use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use RuntimeException;
use Tests\TestCase;

final class OutlookCompactMobilePresentationTest extends TestCase
{
    public function test_numeric_base_under_recipient_roots_keeps_exact_inverse_and_one_card(): void
    {
        [$mobile, $desktop] = $this->entries();
        foreach ([[$mobile['signature']['html'], 30000], [$mobile['mobileComposeHtml'], 30000], [$desktop['signature']['html'], 30000], [$desktop['combinedComposeHtml'], 99000]] as [$html, $limit]) {
            $html = OutlookMobileReferencePresentation::canonicalize($html);
            $source = Mobile::canonicalize($html);
            $result = Mobile::document($source);
            self::assertSame($source, Mobile::canonicalize($result));
            self::assertSame($result, Mobile::document($result));
            self::assertNotSame($source, $result);
            self::assertSame($this->materials($source), $this->materials($result));
            self::assertSame(substr_count($source, 'RT-SIGNATURE-VERSION:'), substr_count($result, 'RT-SIGNATURE-VERSION:'));
            self::assertSame(substr_count($source, 'RT-MOBILE-COMPOSE-VERSION:'), substr_count($result, 'RT-MOBILE-COMPOSE-VERSION:'));
            self::assertStringNotContainsString('data-rt-compact-css="1"', $result);
            self::assertStringNotContainsString('data-rt-v30-css="v30-c-1"', $result);
            self::assertStringContainsString('data-rt-compact-mobile="v17"', $result);
            self::assertLessThanOrEqual($limit, intdiv(strlen(mb_convert_encoding($result, 'UTF-16LE', 'UTF-8')), 2) + ($limit === 30000 ? 168 : 0));
            $withoutStyleBlocks = preg_replace('~<style\b[^>]*>.*?</style\s*>~is', '', $result);
            $xpath = $this->xpath($withoutStyleBlocks);
            self::assertCount(1, $xpath->query('//table[contains(concat(" ",normalize-space(@class)," ")," rt-v17-dispatch-block ")]'));
            self::assertStringContainsString('max-width:1040px!important', $this->one($xpath, 'rt-v17-columns')->getAttribute('style'));
            $logo = $this->one($xpath, 'rt-personal-logo-frame');
            self::assertSame('184', $logo->getAttribute('width'), 'Word keeps its existing wide numeric fallback.');
            self::assertStringContainsString('width:184px!important', $logo->getAttribute('style'));
            self::assertStringContainsString('float:none!important;margin:0!important', $logo->getAttribute('style'));
            foreach ($xpath->query('//*[@style]') as $element) {
                self::assertDoesNotMatchRegularExpression('/\b(?:min|max|clamp|calc)\s*\(/i', $element->getAttribute('style'), 'No recipient width may depend on CSS math filtered by Outlook.');
            }
            self::assertStringContainsString('width:100%!important', $this->one($xpath, 'rt-v9-logo')->getAttribute('style'));
            self::assertStringContainsString('width:220px!important', $this->one($xpath, 'rt-v17-identity')->getAttribute('style'));
            self::assertStringContainsString('width:280px!important', $this->one($xpath, 'rt-v17-direct')->getAttribute('style'));
            self::assertStringContainsString('width:210px!important', $this->one($xpath, 'rt-v17-company')->getAttribute('style'));
            foreach (['rt-v17-columns-cell', 'rt-v17-legal-cell'] as $class) {
                $style = $this->one($xpath, $class)->getAttribute('style');
                self::assertStringContainsString('padding-left:20px!important', $style);
                self::assertStringContainsString('padding-right:20px!important', $style);
            }
            self::assertStringContainsString('width:247px!important', $this->one($xpath, 'rt-v17-dispatch')->getAttribute('style'));
            self::assertStringContainsString('padding:0!important', $this->one($xpath, 'rt-v17-dispatch-cell')->getAttribute('style'));
            $role = $this->one($xpath, 'rt-personal-role');
            foreach (['color:#e90032!important', 'letter-spacing:1.6px!important', 'font-size:10px!important', 'line-height:15px!important'] as $paint) {
                self::assertStringContainsString($paint, $role->getAttribute('style'));
            }
            self::assertStringContainsString('background:#dde1e6!important', $this->one($xpath, 'rt-brand-legal-table')->parentNode->getAttribute('style'));
            self::assertStringContainsString('width:100%!important', $this->one($xpath, 'rt-v17-train')->getAttribute('style'));
            self::assertSame('100%', $this->one($xpath, 'rt-v17-train')->getAttribute('width'));
            self::assertStringContainsString('max-width:547px!important', $this->one($xpath, 'rt-v17-train')->getAttribute('style'));
            self::assertStringContainsString('margin-top:0!important', $this->one($xpath, 'rt-v17-train')->getAttribute('style'));
            self::assertStringContainsString('width:529px!important', $this->one($xpath, 'rt-v17-train-image')->getAttribute('style'));
            self::assertStringContainsString('max-width:100%!important', $this->one($xpath, 'rt-v17-train-image')->getAttribute('style'));
            self::assertStringContainsString('padding:0 18px 0 0', $this->one($xpath, 'rt-v17-train-cell')->getAttribute('style'));
            self::assertCount(1, $xpath->query('//img[contains(concat(" ",normalize-space(@class)," ")," rt-v17-train-image ")]'));
        }
        Http::assertNothingSent();
    }

    public function test_long_labels_and_maximum_escaped_wording_keep_the_mobile_wire_budget_and_reserve(): void
    {
        $fixture = new OutlookV30ReferencePresentationTest('test_actual_employee_fields_keep_the_published_source_and_exact_inverse_with_remote_animation');
        [, $user] = (new ReflectionMethod($fixture, 'source'))->invoke($fixture);
        self::assertSame(':memory:', config('database.connections.sqlite.database'));
        UserProfile::query()->where('user_id', $user->id)->firstOrFail()->forceFill(['first_name' => 'Alexander Christian', 'last_name' => 'von Muster-Lange und Beispielhausen', 'position' => 'Internationaler Fernverkehr und Disposition'])->saveQuietly();
        app()->forgetScopedInstances();
        $service = app(OutlookAddinPayloadService::class);
        $canonical = $service->forUser($user->fresh(), ['greeting' => str_repeat('&', 120), 'farewell' => str_repeat('&', 120), 'showGreeting' => true, 'showFarewell' => true]);
        $canonical['version']['personal'] = substr($service->sourceFingerprint($user->fresh()), 0, 16);
        $wire = OutlookStaticLinkedTestProfiles::payload(OutlookClientPresentation::payload(OutlookMobileSignature::payload($canonical)), $user->fresh());
        foreach ($wire['templates'] as $entry) {
            foreach ([$entry['signature']['html'], $entry['mobileComposeHtml']] as $html) {
                self::assertLessThanOrEqual(30000, intdiv(strlen(mb_convert_encoding($html, 'UTF-16LE', 'UTF-8')), 2) + 168);
                $html = OutlookMobileReferencePresentation::canonicalize($html);
                self::assertSame($html, Mobile::document($html));
                self::assertSame($html, Mobile::document(Mobile::canonicalize($html)));
            }
        }
        Http::assertNothingSent();
    }

    public function test_opaque_comment_and_quoted_tag_evidence_is_not_treated_as_mobile_nodes(): void
    {
        [$entry] = $this->entries();
        $source = Mobile::canonicalize($entry['mobileComposeHtml']);
        $fake = '<!-- <div class="rt-mobile-ledger"><table data-rt-desktop-flow="outlook-desktop-flow-v17" data-rt-compact-mobile="foreign"></table></div> -->';
        $source = $fake.$source;
        $source = preg_replace('/\A'.preg_quote($fake, '/').'<div\b/', $fake.'<div data-note="&lt;table class=\'x10\'&gt;"', $source, 1);
        $output = Mobile::document($source);
        self::assertSame($source, Mobile::canonicalize($output));
        self::assertStringStartsWith($fake, $output);
    }

    #[DataProvider('tampering')]
    public function test_unknown_or_ambiguous_owned_geometry_fails_closed(string $before, string $after, bool $projected): void
    {
        [$entry] = $this->entries();
        $source = Mobile::canonicalize($entry['mobileComposeHtml']);
        $html = $projected ? Mobile::document($source) : $source;
        self::assertStringContainsString($before, $html);
        $broken = str_replace($before, $after, $html);
        $this->expectException(RuntimeException::class);
        Mobile::document($broken);
    }

    public static function tampering(): iterable
    {
        yield 'unknown source width' => ['width:min(280px,52%);', 'width:51%;', false];
        yield 'unknown fluid width' => ['width:280px!important;', 'width:281px!important;', true];
        yield 'foreign flow cap' => [';max-width:1040px!important;', ';max-width:1041px!important;', true];
        yield 'foreign mobile marker' => ['data-rt-compact-mobile="v17"', 'data-rt-compact-mobile="foreign"', true];
        yield 'changed gutter' => ['padding-left:20px!important;', 'padding-left:21px!important;', true];
        yield 'changed role paint' => ['color:#e90032!important;', 'color:#e4002b!important;', true];
        yield 'changed legal paint' => ['background:#dde1e6!important;', 'background:#f3f5f7!important;', true];
        yield 'foreign train bound' => ['max-width:547px!important;', 'max-width:548px!important;', true];
        yield 'duplicate flow' => ['class="xz ', 'data-rt-desktop-flow="outlook-desktop-flow-v17" class="xz ', false];
        yield 'unknown V30 stylesheet' => ['.v30 .rt-delivery-contact-value{line-height:19px!important;word-break:normal!important}', '.v30 .rt-delivery-contact-value{line-height:20px!important;word-break:normal!important}', false];
        yield 'unknown compact stylesheet' => ['.v30 .rt-v17-dispatch{width:100%!important}', '.v30 .rt-v17-dispatch{width:99%!important}', false];
    }

    public function test_unrelated_desktop_and_unknown_mobile_layouts_are_unchanged(): void
    {
        foreach (['<p>Unrelated signature</p>', '<div class="rt-mobile-ledger">Other published design</div>', '<!-- x10 outlook-desktop-flow-v17 --><p>Message</p>'] as $html) {
            self::assertSame($html, Mobile::document($html));
            self::assertSame($html, Mobile::canonicalize($html));
        }
    }

    public function test_historical_v16_delivery_is_not_reprojected_by_numeric_v17(): void
    {
        [$mobile, $desktop] = $this->entries();
        foreach ([$mobile['signature']['html'], $desktop['signature']['html']] as $current) {
            $historical = Compact::canonicalizeToV16($current);
            self::assertStringContainsString('outlook-desktop-flow-v16', $historical);
            self::assertSame($historical, Mobile::document($historical));
            self::assertSame($historical, Mobile::canonicalize($historical));
        }
        Http::assertNothingSent();
    }

    private function entries(): array
    {
        $fixture = new OutlookV30ReferencePresentationTest('test_actual_employee_fields_keep_the_published_source_and_exact_inverse_with_remote_animation');
        [$canonical, $user] = (new ReflectionMethod($fixture, 'source'))->invoke($fixture);
        $desktop = OutlookStaticLinkedTestProfiles::payload(OutlookClientPresentation::payload($canonical), $user);
        $mobile = OutlookStaticLinkedTestProfiles::payload(OutlookClientPresentation::payload(OutlookMobileSignature::payload($canonical)), $user);

        $entries = [$mobile['templates'][0], $desktop['templates'][0]];
        foreach ($entries as &$entry) {
            foreach (['html', 'composeHtml', 'combinedComposeHtml', 'mobileComposeHtml'] as $field) {
                if (isset($entry[$field])) {
                    $entry[$field] = OutlookMobileReferencePresentation::canonicalize($entry[$field]);
                }
            }
            $entry['signature']['html'] = OutlookMobileReferencePresentation::canonicalize($entry['signature']['html']);
        }

        return $entries;
    }

    private function xpath(string $html): DOMXPath
    {
        $dom = new DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET);

        return new DOMXPath($dom);
    }

    private function one(DOMXPath $xpath, string $class): \DOMElement
    {
        $nodes = $xpath->query('//*[contains(concat(" ",normalize-space(@class)," ")," '.$class.' ")]');
        self::assertCount(1, $nodes);

        return $nodes->item(0);
    }

    private function materials(string $html): array
    {
        $xpath = $this->xpath($html);
        $materials = [];
        foreach ($xpath->query('//a|//img') as $element) {
            $materials[] = [$element->tagName, $element->getAttribute('href'), $element->getAttribute('src'), $element->getAttribute('alt')];
        }

        return $materials;
    }
}
