<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Mail\OutlookUniversalTransitCandidate;
use App\Support\Mail\OutlookUniversalTransitTransformer;
use App\Support\OutlookAddin\OutlookUniversalPresentation;
use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\HtmlString;
use Tests\TestCase;

/** HTML boundary only: these tests do not claim MIME/TNEF/SMTP integration. */
final class OutlookUniversalTransitTransformerTest extends TestCase
{
    private const PILOT = 'it-technik@rail-time.de';

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.url' => 'https://mail.example.test']);
        Http::preventStrayRequests();
    }

    public function test_trusted_word_structure_uses_the_admitted_b1_send_projection_and_preserves_the_complete_raw_author_cell(): void
    {
        $projection = $this->projection();
        $author = "<p class=MsoNormal data-test=author>Grüße &amp; 🚂 <b>exact</b></p>\r\n"
            .'<p><a href="https://author.example.test/path?q=1&amp;x=2">Author link</a><span>{{AUTHOR_CONTENT}}</span></p>';
        $input = $this->word($projection['version'], $author);
        $candidate = $this->candidate($input, $projection);
        $result = $this->transform($input, $projection);

        self::assertTrue($result['changed'], (string) $result['reason']);
        self::assertSame($projection['version'], $result['version']);
        self::assertNotSame($input, $result['html']);
        self::assertStringContainsString('Muster Transit GmbH', $result['html']);
        self::assertStringContainsString('mailto:alex@example.invalid', $result['html']);
        self::assertStringContainsString('maps.google.com', $result['html']);
        self::assertSame(1, substr_count($result['html'], $author));
        self::assertSame($author, $this->author($result['html'], $projection));
        self::assertTrue($result['preservation']['authorPreserved']);
        self::assertTrue($result['preservation']['bodyPrefixPreserved']);
        self::assertTrue($result['preservation']['suffixPreserved']);
        self::assertSame(hash('sha256', $author), $result['preservation']['authorSha256']);
        self::assertSame(hash('sha256', substr($input, $candidate['end'])), $result['preservation']['suffixSha256']);
        $this->assertPrefixPreservedApartFromTheHeadInsertion($input, $result['html'], $candidate);
        self::assertStringEndsWith(substr($input, $candidate['end']), $result['html']);
        self::assertSame(2, substr_count($result['headCss'], '@media'));
        self::assertStringContainsString('max-width:600px', $result['headCss']);
        Http::assertNothingSent();
    }

    public function test_direct_editor_input_retains_existing_greeting_author_markup_and_farewell_exactly_once(): void
    {
        $projection = $this->projection();
        $author = "<p data-test=opaque>Author &amp; unchanged 🚂</p>\r\n<!-- OPAQUE-AUTHOR -->";
        $editor = (new OutlookUniversalPresentation)->render($this->descriptor(), new HtmlString($author), $this->renderOptions('editor'));
        self::assertSame($projection['version'], $editor['version']);
        $input = '<html><head><meta charset="utf-8"></head><body>'.$editor['html'].'</body></html>';
        $raw = $this->author($input, $projection);
        $before = hash('sha256', $editor['html']);
        $result = $this->transform($input, $projection);

        self::assertTrue($result['changed'], (string) $result['reason']);
        self::assertSame($raw, $this->author($result['html'], $projection));
        self::assertSame(1, substr_count($result['html'], 'Synthetic greeting'));
        self::assertSame(1, substr_count($result['html'], 'Synthetic farewell'));
        self::assertSame(1, substr_count($result['html'], $author));
        self::assertSame($before, hash('sha256', $editor['html']));
    }

    public function test_scoped_send_css_cannot_target_the_unchanged_quote_with_the_same_classes_and_version(): void
    {
        $projection = $this->projection();
        $own = $this->body($this->word($projection['version'], '<p>OWN AUTHOR</p>'));
        $quoted = (new OutlookUniversalPresentation)->render($this->descriptor(), new HtmlString('<p>QUOTE-SENTINEL</p>'), $this->renderOptions('editor'))['html'];
        $quote = "\r\n<hr><div id=divRplyFwdMsg>Historical header</div><blockquote>".$quoted.'</blockquote></body></html>';
        $prefix = '<html><head><title>Synthetic transit</title></head><body><!-- PREFIX-BYTES -->';
        $input = $prefix.$own.$quote;
        $candidate = $this->candidate($input, $projection);
        $result = $this->transform($input, $projection);

        self::assertTrue($result['changed'], (string) $result['reason']);
        $this->assertPrefixPreservedApartFromTheHeadInsertion($input, $result['html'], $candidate);
        self::assertStringEndsWith(substr($input, $candidate['end']), $result['html']);
        self::assertSame(1, substr_count($result['html'], $quote));
        self::assertMatchesRegularExpression('/\A[A-Za-z][A-Za-z0-9_-]{1,100}\z/', $result['scopeId']);
        self::assertStringNotContainsString($result['scopeId'], $input);
        $xpath = $this->xpath($result['html']);
        $scope = $xpath->query('//*[contains(concat(" ",normalize-space(@class)," ")," '.$result['scopeId'].' ")]');
        self::assertSame(1, $scope->length);
        self::assertSame('table', $scope->item(0)->nodeName);
        self::assertStringNotContainsString('QUOTE-SENTINEL', $scope->item(0)->textContent);
        $css = preg_replace('~/\*.*?\*/~s', '', $result['headCss']);
        preg_match_all('/([^{}]+)\{/', $css, $rules);
        $selectors = 0;
        foreach ($rules[1] as $rule) {
            if (str_starts_with(trim($rule), '@media')) {
                continue;
            }
            foreach (explode(',', $rule) as $selector) {
                self::assertMatchesRegularExpression('/\A\.'.preg_quote($result['scopeId'], '/').'(?=[.\s>])/', trim($selector));
                $selectors++;
            }
        }
        self::assertGreaterThan(40, $selectors);
    }

    public function test_untrusted_sender_missing_duplicate_invalid_or_unadmitted_source_inputs_return_the_original_html(): void
    {
        $projection = $this->projection();
        $input = $this->word($projection['version'], '<p>Preserve author</p>');
        $headers = $this->headers($projection);
        $invalid = $projection;
        $invalid['version'] = str_repeat('b', 24);
        $missing = $projection;
        unset($missing['version']);
        $cases = [
            [$headers, $projection, false, self::PILOT],
            [$headers, $projection, true, 'other@example.test'],
            [[], $projection, true, self::PILOT],
            [['X-RailTime-Template' => [$projection['header'], $projection['header']]], $projection, true, self::PILOT],
            [['X-RailTime-Template' => $projection['header'], 'x-railtime-template' => $projection['header']], $projection, true, self::PILOT],
            [['X-RailTime-Template' => "u2e-invalid\r\nX-Other: unsafe"], $projection, true, self::PILOT],
            [$headers, [], true, self::PILOT],
            [$headers, $missing, true, self::PILOT],
            [$headers, $invalid, true, self::PILOT],
        ];
        foreach ($cases as [$caseHeaders, $source, $trusted, $sender]) {
            $result = (new OutlookUniversalTransitTransformer)->transform($input, $caseHeaders, $source, $trusted, $sender);
            self::assertFalse($result['changed']);
            self::assertSame($input, $result['html']);
            self::assertSame('', $result['headCss']);
        }
    }

    public function test_quoted_only_ambiguous_invalid_and_headless_html_inputs_are_never_rewritten(): void
    {
        $projection = $this->projection();
        $body = $this->body($this->word($projection['version'], '<p>Preserved author</p>'));
        $headers = $this->headers($projection);
        $cases = [
            ['<html><head></head><body><div id="divRplyFwdMsg">Historical header</div>'.$body.'</body></html>', $headers],
            ['<html><head></head><body>'.$body.$body.'</body></html>', $headers],
            ['<html><head></head><body>'.str_replace('width="280"', 'width="281"', $body).'</body></html>', $headers],
            [$body."\0", $headers],
            [$body, $headers],
            ['<html><head><meta name="format-detection" content="telephone=yes"></head><body>'.$body.'</body></html>', $headers],
            ['<html><head></head><head></head><body>'.$body.'</body></html>', $headers],
            // This only proves that the HTML boundary leaves invalid binary
            // bytes unchanged. TNEF/MIME routing belongs to the future adapter.
            ["\x78\x9f\x3e\x22\0BINARY-TNEF-SENTINEL", array_replace($headers, ['Content-Type' => 'application/ms-tnef'])],
        ];
        foreach ($cases as [$input, $caseHeaders]) {
            $result = (new OutlookUniversalTransitTransformer)->transform($input, $caseHeaders, $projection, true, self::PILOT);
            self::assertFalse($result['changed']);
            self::assertSame($input, $result['html']);
        }
    }

    public function test_pinned_css_hash_template_and_send_mode_cannot_be_replaced_by_a_lookalike_projection(): void
    {
        $projection = $this->projection();
        $input = $this->word($projection['version'], '<p>Preserved author</p>');
        $cases = [];
        foreach (['headCss' => $projection['headCss'].' .rt-shell{color:red}', 'headCssHash' => str_repeat('0', 64),
            'templateHash' => str_repeat('0', 64), 'designSourceHash' => str_repeat('0', 64),
            'sourceHash' => 'invalid', 'mode' => 'editor'] as $key => $value) {
            $cases[] = array_replace($projection, [$key => $value]);
        }
        $rehashedCss = $cases[0];
        $rehashedCss['headCssHash'] = hash('sha256', $rehashedCss['headCss']);
        $cases[] = $rehashedCss;
        foreach ($cases as $source) {
            $result = $this->transform($input, $source);
            self::assertFalse($result['changed']);
            self::assertSame($input, $result['html']);
            self::assertSame('projection_invalid', $result['reason']);
        }
    }

    public function test_a_different_valid_b1_projection_does_not_admit_the_incoming_header_version(): void
    {
        $projection = $this->projection();
        $descriptor = $this->descriptor();
        $descriptor['identity']['role'] = 'Synthetic different role';
        $other = (new OutlookUniversalPresentation)->render($descriptor, new HtmlString(''), $this->renderOptions('send'));
        self::assertNotSame($projection['version'], $other['version']);
        $input = $this->word($projection['version'], '<p>Keep this source version</p>');
        $result = (new OutlookUniversalTransitTransformer)->transform($input, $this->headers($projection), $other, true, self::PILOT);
        self::assertFalse($result['changed']);
        self::assertSame($input, $result['html']);
        self::assertSame('version_not_admitted', $result['reason']);
    }

    public function test_duplicate_or_contradictory_known_head_metadata_is_never_silently_replaced(): void
    {
        $projection = $this->projection();
        $original = $this->word($projection['version'], '<p>Preserved author</p>');
        foreach (['<meta charset="utf-8">', '<meta charset="windows-1252">',
            '<meta name="format-detection" content="telephone=yes">',
            '<meta name="viewport" content="width=1">',
            '<meta name="format-detection" content="telephone=no,date=no,address=no,email=no"><meta name="format-detection" content="telephone=no,date=no,address=no,email=no">'] as $meta) {
            $input = str_replace('</head>', $meta.'</head>', $original);
            $result = $this->transform($input, $projection);
            self::assertFalse($result['changed']);
            self::assertSame('meta_conflict', $result['reason']);
            self::assertSame($input, $result['html']);
        }
    }

    public function test_a_head_inside_body_or_outside_the_matching_html_document_is_not_an_insertion_point(): void
    {
        $projection = $this->projection();
        $body = $this->body($this->word($projection['version'], '<p>Preserved author</p>'));
        foreach (['<html><body><head></head>'.$body.'</body></html>',
            '<head></head><html><body>'.$body.'</body></html>',
            '<html><head></head></html><html><body>'.$body.'</body></html>'] as $input) {
            $result = $this->transform($input, $projection);
            self::assertFalse($result['changed']);
            self::assertSame($input, $result['html']);
        }
    }

    public function test_a_second_transform_is_an_exact_noop_without_another_style_or_body_write(): void
    {
        $projection = $this->projection();
        $first = $this->transform($this->word($projection['version'], '<p>One author</p>'), $projection);
        self::assertTrue($first['changed']);
        $second = $this->transform($first['html'], $projection);
        self::assertFalse($second['changed']);
        self::assertSame('already_transformed', $second['reason']);
        self::assertSame($first['html'], $second['html']);
        self::assertSame('', $second['headCss']);
        self::assertSame(1, substr_count($second['html'], 'data-rt-transit-style='));
    }

    public function test_a_transit_scope_in_an_older_quote_never_skips_the_current_untransformed_block(): void
    {
        $projection = $this->projection();
        $previous = $this->transform($this->word($projection['version'], '<p>QUOTED-TRANSIT-AUTHOR</p>'), $projection);
        self::assertTrue($previous['changed']);
        $quote = '<div id=divRplyFwdMsg>Historical header</div><blockquote>'.$this->body($previous['html']).'</blockquote></body></html>';
        $own = $this->body($this->word($projection['version'], '<p>Current untransformed author</p>'));
        $input = '<html><head></head><body>'.$own.$quote;
        $result = $this->transform($input, $projection);
        self::assertTrue($result['changed'], (string) $result['reason']);
        self::assertNotSame($previous['scopeId'], $result['scopeId']);
        self::assertStringEndsWith($quote, $result['html']);
        self::assertSame(1, substr_count($result['html'], $previous['scopeId']));
    }

    private function transform(string $html, array $projection): array
    {
        return (new OutlookUniversalTransitTransformer)->transform($html, $this->headers($projection), $projection, true, self::PILOT);
    }

    private function projection(): array
    {
        return (new OutlookUniversalPresentation)->render($this->descriptor(), new HtmlString(''), $this->renderOptions('send'));
    }

    private function renderOptions(string $mode): array
    {
        return ['mode' => $mode, 'showGreeting' => true, 'greeting' => 'Synthetic greeting',
            'showFarewell' => true, 'farewell' => 'Synthetic farewell', 'farewellName' => false];
    }

    private function descriptor(): array
    {
        return [
            'sourceHash' => hash('sha256', 'published synthetic transit source'), 'kind' => 'employee',
            'identity' => ['name' => 'Alex Beispiel', 'role' => 'IT-TECHNIK'],
            'direct' => [['type' => 'email', 'value' => 'alex@example.invalid', 'label' => 'alex@example.invalid'], ['type' => 'phone', 'value' => '+491705550101', 'label' => '+49 170 5550101']],
            'company' => ['name' => 'Muster Transit GmbH', 'website' => 'https://example.invalid', 'websiteLabel' => 'example.invalid', 'street' => 'Musterweg 12', 'postalCity' => '10115 Berlin', 'registration' => 'Geschäftsführung: A. Beispiel · HRB 12345', 'confidentiality' => 'Vertrauliche synthetische Nachricht.'],
            'dispatch' => ['email' => 'dispo@example.invalid', 'whatsapp' => 'https://wa.me/491601881848', 'phone' => '+491601881839', 'phoneLabel' => '+49 160 1881839'],
        ];
    }

    private function headers(array $projection): array
    {
        return ['X-RailTime-Template' => $projection['header'], 'Content-Type' => 'text/html; charset=utf-8'];
    }

    private function candidate(string $html, array $projection): array
    {
        $result = OutlookUniversalTransitCandidate::inspect($html, $this->headers($projection), true);
        self::assertTrue($result['eligible']);

        return $result['candidate'];
    }

    private function author(string $html, array $projection): string
    {
        $candidate = $this->candidate($html, $projection);

        return substr($html, $candidate['authorStart'], $candidate['authorEnd'] - $candidate['authorStart']);
    }

    private function word(string $version, string $author): string
    {
        $html = file_get_contents(dirname(__DIR__).'/Fixtures/mail/u2e-transit/received-classic-2333.redacted.html');
        // The immutable Word fixture has no HEAD. Add a synthetic one only in
        // memory: the HTML transformer requires a proven head insertion point.
        $html = str_replace('<html>', '<html><head><meta charset="utf-8"></head>', $html);
        $html = str_replace(str_repeat('1', 24), $version, $html);
        $result = OutlookUniversalTransitCandidate::inspect($html, ['X-RailTime-Template' => 'u2e-'.$version], true);
        self::assertTrue($result['eligible']);
        $candidate = $result['candidate'];

        return substr($html, 0, $candidate['authorStart']).$author.substr($html, $candidate['authorEnd']);
    }

    private function body(string $html): string
    {
        self::assertSame(1, preg_match('~<body\b[^>]*>(.*?)</body>~is', $html, $match));

        return $match[1];
    }

    private function assertPrefixPreservedApartFromTheHeadInsertion(string $input, string $output, array $candidate): void
    {
        $headClose = strpos($input, '</head>');
        self::assertIsInt($headClose);
        self::assertStringStartsWith(substr($input, 0, $headClose), $output);
        $betweenHeadAndCandidate = substr($input, $headClose, $candidate['start'] - $headClose);
        self::assertStringContainsString($betweenHeadAndCandidate, $output);
    }

    private function xpath(string $html): DOMXPath
    {
        $dom = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        try {
            $dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return new DOMXPath($dom);
    }
}
