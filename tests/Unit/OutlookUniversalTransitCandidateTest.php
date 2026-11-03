<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Mail\OutlookUniversalTransitCandidate;
use PHPUnit\Framework\TestCase;

final class OutlookUniversalTransitCandidateTest extends TestCase
{
    public function test_received_word_structure_is_a_candidate_after_trusted_ingress_without_requiring_ids(): void
    {
        foreach ([$this->fixture(), $this->markerless(), "\xEF\xBB\xBF".$this->markerless()] as $html) {
            $result = $this->inspect($html);
            self::assertTrue($result['eligible']);
            self::assertSame('candidate', $result['reason']);
            self::assertSame(str_repeat('1', 24), $result['version']);
            self::assertSame('combined', $result['candidate']['kind']);
            self::assertSame(13, $result['candidate']['imageCount']);
            $slice = substr($html, $result['candidate']['start'], $result['candidate']['end'] - $result['candidate']['start']);
            self::assertStringStartsWith('<table', $slice);
            self::assertStringEndsWith('</table>', $slice);
            self::assertSame(hash('sha256', $slice), $result['candidate']['htmlHash']);
        }
    }

    public function test_ids_and_a_header_never_authorize_an_untrusted_message(): void
    {
        $result = OutlookUniversalTransitCandidate::inspect($this->fixture(), $this->headers());
        self::assertFalse($result['eligible']);
        self::assertSame('untrusted_ingress', $result['reason']);
        self::assertNull($result['candidate']);
        self::assertNull($result['version']);
    }

    public function test_missing_ambiguous_or_malformed_headers_keep_the_message_unmatched(): void
    {
        $cases = [
            [[], 'header_missing'],
            [['x-railtime-template' => [$this->header(), $this->header()]], 'header_ambiguous'],
            [['x-railtime-template' => $this->header(), 'X-RailTime-Template' => $this->header()], 'header_ambiguous'],
            [['x-railtime-template' => $this->header()."\r\nX-Other: value"], 'header_invalid'],
            [['x-railtime-template' => 'u2e-short'], 'header_invalid'],
            [['x-railtime-template' => true], 'header_invalid'],
        ];
        foreach ($cases as [$headers, $reason]) {
            $result = OutlookUniversalTransitCandidate::inspect($this->fixture(), $headers, true);
            self::assertFalse($result['eligible']);
            self::assertSame($reason, $result['reason']);
        }
    }

    public function test_header_version_is_a_candidate_hint_not_an_identity_or_current_content_claim(): void
    {
        $result = OutlookUniversalTransitCandidate::inspect($this->fixture(), ['X-RailTime-Template' => 'u2e-'.str_repeat('2', 24)], true);
        self::assertTrue($result['eligible']);
        self::assertSame(str_repeat('2', 24), $result['version']);
        self::assertArrayNotHasKey('current', $result);
        self::assertArrayNotHasKey('authorized', $result);
        self::assertArrayNotHasKey('html', $result['candidate']);
    }

    public function test_quotes_without_any_ids_are_not_current_candidates(): void
    {
        $body = $this->body($this->markerless());
        foreach (['<blockquote>'.$body.'</blockquote>', '<div id="divRplyFwdMsg">Historical header</div>'.$body,
            '<div class="gmail_quote">'.$body.'</div>',
            '<div style="border-top:solid #e1e1e1 1pt"><p><b>Von:</b> old@example.test<br><b>Gesendet:</b> Earlier<br><b>An:</b> own@example.test<br><b>Betreff:</b> Earlier</p></div>'.$body] as $quote) {
            $result = $this->inspect('<html><body>'.$quote.'</body></html>');
            self::assertFalse($result['eligible']);
            self::assertSame('quoted_only', $result['reason']);
            self::assertSame(1, $result['quotedCandidateCount']);
            self::assertNull($result['candidate']);
        }
    }

    public function test_the_single_current_candidate_is_separate_from_the_unchanged_quote_bytes(): void
    {
        $body = $this->body($this->markerless());
        $quote = '<hr><div id="divRplyFwdMsg">Historical header</div>'.$body;
        $html = '<html><body>'.$body.$quote.'</body></html>';
        $before = hash('sha256', $html);
        $result = $this->inspect($html);
        self::assertTrue($result['eligible']);
        self::assertSame(1, $result['quotedCandidateCount']);
        self::assertLessThan(strpos($html, $quote), $result['candidate']['end']);
        self::assertSame($before, hash('sha256', $html));
        self::assertStringEndsWith($quote.'</body></html>', $html);
    }

    public function test_ambiguous_layouts_and_unknown_visible_prefixes_are_never_selected(): void
    {
        $body = $this->body($this->markerless());
        $duplicate = $this->inspect('<html><body>'.$body.$body.'</body></html>');
        self::assertFalse($duplicate['eligible']);
        self::assertSame('structure_ambiguous', $duplicate['reason']);
        $unknownQuote = $this->inspect('<html><body><p>Unscoped earlier message</p>'.$body.'</body></html>');
        self::assertFalse($unknownQuote['eligible']);
        self::assertSame('ambiguous_prefix', $unknownQuote['reason']);
    }

    public function test_a_candidate_crossing_a_quote_boundary_is_never_allowed_to_own_the_quote(): void
    {
        $html = preg_replace('~(<td[^>]*colspan="4"[^>]*>)~', '$1<blockquote>Historical quote</blockquote>', $this->markerless(), 1, $count);
        self::assertSame(1, $count);
        $result = $this->inspect($html);
        self::assertFalse($result['eligible']);
        self::assertSame('quote_boundary_crossed', $result['reason']);
        self::assertNull($result['candidate']);
    }

    public function test_material_geometry_and_required_images_cannot_be_replaced_by_an_id(): void
    {
        $source = $this->fixture();
        $mutations = [
            str_replace('width="1040"', 'width="1039"', $source),
            str_replace('width="280"', 'width="281"', $source),
            str_replace('rowspan="2"', 'rowspan="1"', $source),
            str_replace('width="247"', 'width="248"', $source),
            str_replace('height="83"', 'height="84"', $source),
            preg_replace('~<img\b[^>]*>~i', '', $source),
            preg_replace('~(<table\b[^>]*width="1040")~', '$1 hidden', $source, 1),
        ];
        foreach ($mutations as $html) {
            self::assertNotSame($source, $html);
            $result = $this->inspect($html);
            self::assertFalse($result['eligible']);
            self::assertSame('structure_absent', $result['reason']);
        }
    }

    public function test_active_malformed_and_oversized_input_is_not_a_transport_candidate(): void
    {
        foreach ([$this->fixture().'<script>unsafe()</script>', str_replace('</table>', '</div>', $this->fixture()),
            $this->fixture()."\0", str_repeat('x', 1000001)] as $html) {
            $result = $this->inspect($html);
            self::assertFalse($result['eligible']);
            self::assertSame('html_invalid', $result['reason']);
        }
    }

    public function test_fixture_has_redacted_provenance_and_records_actual_cell_id_survival(): void
    {
        $manifest = json_decode(file_get_contents($this->directory().'/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(hash('sha256', str_replace("\r\n", "\n", $this->fixture())), $manifest['fixtureHtmlSha256']);
        self::assertSame(hash('sha256', str_replace("\r\n", "\n", file_get_contents($this->directory().'/received-classic-2333.headers.txt'))), $manifest['fixtureHeadersSha256']);
        self::assertSame(0, $manifest['sourceShellIdCount']);
        self::assertSame(1, $manifest['sourceCellIdCount']);
        self::assertTrue($manifest['allTextReplaced']);
        self::assertTrue($manifest['allUrlsReplaced']);
        self::assertFalse($manifest['pixelOrNativeClientProof']);
        self::assertStringNotContainsString('@', $this->fixture());
        self::assertStringNotContainsString('rail-time', $this->fixture());
    }

    private function inspect(string $html): array
    {
        return OutlookUniversalTransitCandidate::inspect($html, $this->headers(), true);
    }

    private function fixture(): string
    {
        return file_get_contents($this->directory().'/received-classic-2333.redacted.html');
    }

    private function markerless(): string
    {
        return preg_replace('/\s+id="(?:x_)?rt-sig-u2-[0-9a-f]{24}(?:-cell)?"/', '', $this->fixture());
    }

    private function body(string $html): string
    {
        preg_match('~<body\b[^>]*>(.*?)</body>~is', $html, $match);

        return $match[1];
    }

    private function directory(): string
    {
        return dirname(__DIR__).'/Fixtures/mail/u2e-transit';
    }

    private function header(): string
    {
        return 'u2e-'.str_repeat('1', 24);
    }

    private function headers(): array
    {
        return ['x-railtime-template' => $this->header()];
    }
}
