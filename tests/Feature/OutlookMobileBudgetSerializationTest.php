<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\OutlookAddin\OutlookClientPresentation;
use App\Support\OutlookAddin\OutlookCombinedComposeDocument;
use App\Support\OutlookAddin\OutlookMobileCombinedComposeDocument;
use App\Support\OutlookAddin\OutlookMobileHtmlSerialization;
use App\Support\OutlookAddin\OutlookMobileSignature;
use DOMDocument;
use DOMXPath;
use ReflectionMethod;
use Tests\TestCase;

final class OutlookMobileBudgetSerializationTest extends TestCase
{
    public function test_rich_actual_compiler_document_previously_over_budget_now_preserves_full_mobile_delivery(): void
    {
        $case = new OutlookMobileCombinedComposeDocumentTest('test_actual_builder_payload_has_one_complete_mobile_document_without_mutating_desktop_or_sources');
        $desktop = (new ReflectionMethod($case, 'fixture'))->invoke($case, true);
        $source = $desktop;
        $base = $this->beforeSerialization($case, $desktop);
        // Keep all actual compiled structure/media, add ordinary authored body
        // text to reproduce the observed 30,404/30,408-unit account boundary.
        $length = 30410 - $this->units($base);
        $padding = substr(str_repeat('RailTime Nachricht ', (int) ceil($length / 18)), 0, $length);
        $desktop['templates'][0]['composeHtml'] = str_replace('Guten Tag,', 'Guten Tag,'.$padding, $desktop['templates'][0]['composeHtml']);
        $desktop['templates'][0]['combinedComposeHtml'] = OutlookCombinedComposeDocument::build($desktop['templates'][0]['composeHtml'], $desktop['templates'][0]['signature']['html']);
        $before = $this->beforeSerialization($case, $desktop);
        $this->assertGreaterThan(30400, $this->units($before));
        $mobile = OutlookMobileSignature::payload($desktop);
        $final = OutlookClientPresentation::payload($mobile);
        $template = $final['templates'][0];
        $this->assertLessThanOrEqual(30000, $this->units($template['mobileComposeHtml']));
        $this->assertGreaterThan(100, 30000 - $this->units($template['mobileComposeHtml']), 'Leave headroom after the final client-presentation projection, not only the canonical compiler.');
        $this->assertStringContainsString($padding, $template['mobileComposeHtml']);
        foreach (['Guten Tag,', 'Mit freundlichen Grüßen,', '24/7', 'Hotline'] as $content) {
            $this->assertStringContainsString($content, $template['mobileComposeHtml']);
        }
        $expected = OutlookMobileHtmlSerialization::project($before);
        $result = $mobile['templates'][0]['mobileComposeHtml'];
        $this->assertSame($this->normalizeVersion($expected), $this->normalizeVersion($result));
        $this->assertGreaterThan(1000, $this->units($before) - $this->units($result));
        $this->assertSame($source['signature']['media'], $mobile['signature']['media']);
        $this->assertSame($source['templates'][0]['signature']['media'], $template['signature']['media']);
        $this->assertSame($desktop['templates'][0]['combinedComposeHtml'], $mobile['templates'][0]['combinedComposeHtml']);
        $this->assertSame($desktop['automaticTemplateId'], $final['automaticTemplateId']);
        $this->assertSame(array_diff_key($desktop['version'], ['signature' => true]), array_diff_key($final['version'], ['signature' => true]));
        $this->assertSame(2, substr_count($template['mobileComposeHtml'], OutlookMobileCombinedComposeDocument::VERSION_MARKER.$template['mobileComposeVersion']));

        foreach ([$result, preg_replace('~<style\b[^>]*>.*?</style\s*>~is', '', $result), preg_replace('~\sstyle="[^"]*"~', '', $result)] as $variant) {
            $xpath = $this->xpath($variant);
            $this->assertSame(1, $xpath->query('//table[@data-rt-train-delivery="bounded-img-v2"]')->length);
            $this->assertGreaterThan(0, $xpath->query('//img[starts-with(@src,"cid:")]')->length);
            $this->assertSame(0, $xpath->query('//*[@background]')->length);
            foreach (['xu', 'x13'] as $role) {
                $group = $xpath->query('//td[contains(concat(" ",normalize-space(@class)," ")," '.$role.' ")]');
                $this->assertSame(1, $group->length);
                $this->assertSame('100%', $group->item(0)->getAttribute('width'));
                $this->assertSame(1, $xpath->query('./td', $group->item(0)->parentNode)->length);
            }
        }
        preg_match_all('~<style\b[^>]*>(.*?)</style\s*>~is', $template['mobileComposeHtml'], $styles);
        $this->assertLessThan(12288, array_sum(array_map('strlen', $styles[1])));
    }

    public function test_opaque_style_observers_keep_original_serialization_and_original_budget_enforcement(): void
    {
        $case = new OutlookMobileCombinedComposeDocumentTest('test_actual_builder_payload_has_one_complete_mobile_document_without_mutating_desktop_or_sources');
        $desktop = (new ReflectionMethod($case, 'fixture'))->invoke($case, true);
        $legacy = $desktop;
        foreach ($legacy['templates'] as &$template) {
            unset($template['composeDocumentMode']);
        }
        unset($template);
        $mobile = OutlookMobileSignature::payload($legacy);
        // A STYLE selector can expose CSS text itself: compaction is skipped,
        // preserving both that observation and the original size guard.
        $signature = $mobile['templates'][0]['signature'];
        $signature['html'] .= '<style>style{display:block;}</style>';
        $built = OutlookMobileCombinedComposeDocument::build($desktop['templates'][0], $desktop['templates'][0]['signature'], $signature);
        $this->assertStringContainsString('width="100%"', $built['html']);
        $this->assertStringContainsString('mso-table-lspace: 0pt;', $built['html']);
        $this->assertStringContainsString('<style>style{display:block;}</style>', $built['html']);
    }

    public function test_published_safe_selector_with_literal_or_escaped_attribute_function_keeps_inline_serialization(): void
    {
        $case = new OutlookMobileCombinedComposeDocumentTest('test_actual_builder_payload_has_one_complete_mobile_document_without_mutating_desktop_or_sources');
        $desktop = (new ReflectionMethod($case, 'fixture'))->invoke($case, true);
        $mobile = (new ReflectionMethod($case, 'standaloneMobile'))->invoke($case, $desktop);
        foreach (['attr(style)', 'a\\74tr(style)'] as $observer) {
            $signature = $mobile['templates'][0]['signature'];
            $signature['html'] .= '<style>.published-note{content:'.$observer.';}</style>';
            $built = OutlookMobileCombinedComposeDocument::build($desktop['templates'][0], $desktop['templates'][0]['signature'], $signature);
            $this->assertStringContainsString('width="100%"', $built['html']);
            $this->assertStringContainsString('mso-table-lspace: 0pt;', $built['html']);
            $this->assertStringContainsString('<style>.published-note{content:'.$observer.';}</style>', $built['html']);
        }
    }

    private function beforeSerialization(OutlookMobileCombinedComposeDocumentTest $case, array $desktop): string
    {
        $mobile = (new ReflectionMethod($case, 'standaloneMobile'))->invoke($case, $desktop);
        $html = (new ReflectionMethod($case, 'canonical'))->invoke($case, $desktop, $mobile);
        $html = str_replace('data-rt-compose-document="combined-v1"', 'data-rt-compose-document="combined-native-v1"', $html);
        $placeholder = OutlookMobileCombinedComposeDocument::VERSION_MARKER.str_repeat('0', 16);
        $metadata = '<!-- '.$placeholder.' --><span hidden aria-hidden="true" class="rt-office-metadata" '
            .'data-rt-mobile-compose-version="1" style="display:none!important;mso-hide:all;font-size:0;line-height:0;max-height:0;overflow:hidden;">'.$placeholder.'</span>';
        $html = preg_replace_callback('~<td\b[^>]*class="rt-combined-compose-cell"[^>]*>~', static fn (array $match): string => $match[0].$metadata, $html);
        $html = (new ReflectionMethod(OutlookMobileCombinedComposeDocument::class, 'compactOwnedStyles'))->invoke(null, $html);
        [$html] = (new ReflectionMethod(OutlookMobileCombinedComposeDocument::class, 'compactIdentifiers'))->invoke(null, $html);

        return $html;
    }

    private function normalizeVersion(string $html): string
    {
        return preg_replace('/RT-MOBILE-COMPOSE-VERSION:[0-9a-f]{16}/', 'RT-MOBILE-COMPOSE-VERSION:__VERSION__', $html);
    }

    private function units(string $html): int
    {
        return intdiv(strlen(mb_convert_encoding($html, 'UTF-16LE', 'UTF-8')), 2);
    }

    private function xpath(string $html): DOMXPath
    {
        $dom = new DOMDocument('1.0', 'UTF-8');
        $before = libxml_use_internal_errors(true);
        try {
            $this->assertTrue($dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET));
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($before);
        }

        return new DOMXPath($dom);
    }
}
