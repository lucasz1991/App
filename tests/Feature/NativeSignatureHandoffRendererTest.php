<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Mail\EmailHtmlSanitizer;
use App\Support\Mail\NativeSignatureHandoffRenderer;
use DOMDocument;
use DOMXPath;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

final class NativeSignatureHandoffRendererTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['app.url' => 'https://mail.example.test']);
    }

    public function test_native_document_signature_and_head_parts_are_scoped_without_corner_or_body_style_images(): void
    {
        $result = $this->render();
        $xpath = $this->xpath($result['html']);
        self::assertSame('rt-handoff-'.str_repeat('a', 32), $result['scopeId']);
        self::assertSame(1, $xpath->query('//head/style')->length);
        self::assertSame(0, $xpath->query('//body//style')->length);
        self::assertSame(0, $xpath->query('//img[contains(@src,"rund-dispo-ecke")]')->length);
        self::assertSame(1, $xpath->query('//*[@id="'.$result['scopeId'].'"]')->length);
        self::assertSame(1, $xpath->query('//img[contains(@src,"brand-v2-light.gif")]')->length);
        $train = $xpath->query('//img[contains(@src,"brand-v2-light.gif")]')->item(0);
        self::assertSame('529', $train->getAttribute('width'));
        self::assertSame('115', $train->getAttribute('height'));
        self::assertStringContainsString('border-radius:8px', $result['html']);
        self::assertStringContainsString('width="1040"', $result['html'], 'The original explicit Word shell is retained.');
        self::assertStringNotContainsString('{{SCOPE_ID}}', $result['headCss']);
        self::assertSame(NativeSignatureHandoffRenderer::DESIGN_SOURCE, '8210c549eb27aec006bca093b4197fbb3f19da21fc853bb49f6ffc00640f2916');
        self::assertStringContainsString($result['bodyHtml'], $result['html']);
        self::assertStringNotContainsString('Synthetic author content', $result['signatureHtml']);
        self::assertSame(10, count($result['media']));
        foreach ($result['media'] as $asset) {
            self::assertStringStartsWith('https://mail.example.test/', $asset['url']);
            self::assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', $asset['sha256']);
        }
        preg_match_all('/([^{}]+)\{/', preg_replace('~/\*.*?\*/~s', '', $result['headCss']), $rules);
        foreach ($rules[1] as $selector) {
            if (str_starts_with(trim($selector), '@media')) {
                continue;
            }
            foreach (explode(',', $selector) as $part) {
                self::assertMatchesRegularExpression('/\A#'.preg_quote($result['scopeId'], '/').'(?: |-(?:message|spacer)\z)/', trim($part));
            }
        }
        foreach (['min-width:1040px', 'min-width:760px', 'max-width:1039px', 'max-width:848px', 'max-width:759px', 'max-width:340px'] as $boundary) {
            self::assertStringContainsString($boundary, $result['headCss']);
        }
        self::assertStringContainsString('#'.$result['scopeId'].'-message{padding-top:26px!important;padding-bottom:24px!important;padding-left:20px!important;padding-right:20px!important;}', $result['headCss']);
        $filtered = (new EmailHtmlSanitizer)->clean($result['html']);
        self::assertFalse($filtered->has('css.property.forbidden'));
        self::assertSame($xpath->query('//head/style')->item(0)->textContent, $this->xpath($filtered->html)->query('//head/style')->item(0)->textContent);
    }

    public function test_author_html_is_read_once_and_preserved_byte_exact_without_reinterpreting_tokens_or_quote_content(): void
    {
        $bytes = "<p>Unchanged &amp; {{IDENTITY_NAME}}.</p>\r\n<pre>  two spaces\r\nnext</pre><!-- RT_NATIVE_MESSAGE_END -->";
        $content = new class($bytes) implements Htmlable
        {
            public int $reads = 0;

            public function __construct(private readonly string $value) {}

            public function toHtml(): string
            {
                $this->reads++;

                return $this->value;
            }
        };
        $result = (new NativeSignatureHandoffRenderer)->render($this->descriptor(), $content);
        self::assertSame(1, $content->reads);
        self::assertSame(hash('sha256', $bytes), $result['authorContentHash']);
        self::assertSame(1, substr_count($result['html'], $bytes));
        self::assertSame(1, substr_count($result['bodyHtml'], $bytes));
        self::assertSame(0, substr_count($result['signatureHtml'], $bytes));
        self::assertStringContainsString('{{IDENTITY_NAME}}', $result['html']);
    }

    public function test_only_the_exact_generated_head_is_admitted_without_relaxing_generic_css_rules(): void
    {
        $result = $this->render();
        self::assertSame($result['headCss'], NativeSignatureHandoffRenderer::headCssForScope($result['scopeId']));
        self::assertSame(hash('sha256', $result['headCss']), $result['headCssHash']);
        self::assertTrue(NativeSignatureHandoffRenderer::recognizesHeadCss($result['headCss'], $result['scopeId']));
        self::assertFalse(NativeSignatureHandoffRenderer::recognizesHeadCss($result['headCss'].'body{display:none}', $result['scopeId']));
        self::assertFalse(NativeSignatureHandoffRenderer::recognizesHeadCss(str_replace('120px', '121px', $result['headCss']), $result['scopeId']));
        self::assertFalse(NativeSignatureHandoffRenderer::recognizesHeadCss($result['headCss'], 'rt-handoff-'.str_repeat('b', 32)));
        self::assertFalse(NativeSignatureHandoffRenderer::recognizesHeadCss($result['headCss'], 'body'));
    }

    public function test_the_new_signature_css_root_cannot_restyle_opaque_historical_quote_classes(): void
    {
        $renderer = new NativeSignatureHandoffRenderer;
        $old = $renderer->render($this->descriptor(), new HtmlString('Previous content'), ['scopeId' => str_repeat('b', 32)]);
        $quote = '<blockquote class="history">'.$old['bodyHtml'].'</blockquote>';
        $current = $renderer->render($this->descriptor(), new HtmlString($quote), ['scopeId' => str_repeat('c', 32)]);
        self::assertStringContainsString($quote, $current['html']);
        $xpath = $this->xpath($current['html']);
        self::assertSame(0, $xpath->query('//*[@id="'.$current['scopeId'].'"]//*[@id="'.$old['scopeId'].'"]')->length);
        self::assertSame(0, $xpath->query('//*[@id="'.$current['scopeId'].'"]//blockquote')->length);
        self::assertSame(1, $xpath->query('//*[@id="'.$current['scopeId'].'-message"]//*[@id="'.$old['scopeId'].'"]')->length);
        self::assertSame(1, $xpath->query('//*[@id="'.$current['scopeId'].'"]')->length);
        self::assertSame($current['headCss'], NativeSignatureHandoffRenderer::headCssForScope($current['scopeId']));
    }

    public function test_named_dispatch_routes_are_independent_and_word_spacing_is_physical(): void
    {
        $result = $this->render();
        $xpath = $this->xpath($result['html']);
        self::assertSame(1, $xpath->query('//a[@href="mailto:alex@example.invalid"]')->length);
        self::assertSame(2, $xpath->query('//a[@href="mailto:dispo@example.invalid"]')->length);
        self::assertSame(2, $xpath->query('//a[@href="https://wa.me/491601881848"]')->length);
        self::assertSame(4, $xpath->query('//a[@href="tel:+491601881839"]')->length);
        $tables = $xpath->query('//table[contains(concat(" ",normalize-space(@class)," ")," r784-actions ")]');
        self::assertSame(2, $tables->length);
        foreach ($tables as $i => $table) {
            $cells = $xpath->query('./tbody/tr/td', $table);
            self::assertSame($i === 0 ? ['75', '32', '108'] : ['48', '28', '94'], $this->widths($cells));
            $left = $xpath->query('.//table[@class="r784-links"]/tbody/tr/td', $table);
            $phone = $xpath->query('.//table[@class="r784-phone"]/tbody/tr/td', $table);
            self::assertSame($i === 0 ? ['24', '8', '24'] : ['20', '8', '20'], $this->widths($left));
            self::assertSame($i === 0 ? ['2', '24', '6'] : ['2', '20', '6'], $this->widths($phone));
        }
        self::assertSame(2, $xpath->query('//img[contains(@src,"ef89fd23c6")]')->length);
        self::assertSame('+49 160 1881839', $xpath->query('//img[contains(@src,"ef89fd23c6")]')->item(0)->getAttribute('alt'));
    }

    public function test_generic_phone_keeps_live_text_and_uses_the_same_unified_structure(): void
    {
        $data = $this->descriptor();
        $data['dispatch']['phone'] = '+442079460958';
        $data['dispatch']['phoneLabel'] = '+44 20 7946 0958';
        $result = (new NativeSignatureHandoffRenderer)->render($data, new HtmlString('Generic phone'));
        $xpath = $this->xpath($result['html']);
        self::assertSame(0, $xpath->query('//img[contains(@src,"ef89fd23c6")]')->length);
        self::assertSame(4, $xpath->query('//a[@href="tel:+442079460958"]')->length);
        self::assertSame(2, substr_count($result['html'], '+44 20 7946 0958'));
        self::assertSame(['62', '32', '121'], $this->widths($xpath->query('(//table[contains(concat(" ",normalize-space(@class)," ")," r784-actions ")])[1]/tbody/tr/td')));
        self::assertCount(9, $result['media']);
    }

    public function test_saved_wording_flags_are_explicit_and_no_default_greeting_is_invented(): void
    {
        $hidden = $this->render();
        self::assertStringNotContainsString('<h1', $hidden['html']);
        self::assertStringNotContainsString('Guten Tag', $hidden['html']);
        $shown = $this->render(['showGreeting' => true, 'greeting' => 'Hallo <Team>', 'showFarewell' => true, 'farewell' => 'Beste Grüße', 'farewellName' => false]);
        self::assertStringContainsString('Hallo &lt;Team&gt;', $shown['html']);
        self::assertStringContainsString('Beste Grüße', $shown['html']);
        self::assertSame(1, substr_count($shown['html'], 'Alex Beispiel'));
        self::assertNotSame($hidden['version'], $shown['version']);
    }

    public function test_system_and_employee_use_the_same_renderer_with_company_only_system_identity_and_escaped_fields(): void
    {
        $data = $this->descriptor();
        $data['kind'] = 'system';
        $data['identity'] = ['name' => $data['company']['name'], 'role' => 'AUTOMATISCHE NACHRICHT'];
        $data['direct'] = [['type' => 'email', 'value' => 'office@example.invalid', 'label' => 'office@example.invalid'], ['type' => 'phone', 'value' => '+49305550102', 'label' => '+49 30 5550102']];
        $result = (new NativeSignatureHandoffRenderer)->render($data, new HtmlString('<p>System content.</p>'));
        self::assertStringNotContainsString('alex@example.invalid', $result['html']);
        self::assertStringNotContainsString('Alex Beispiel', $result['html']);
        self::assertStringContainsString('data-rt-native-kind="system"', $result['html']);
        self::assertStringContainsString('office@example.invalid', $result['html']);
        $data['kind'] = 'employee';
        $data['identity']['name'] = 'A <script>alert(1)</script> & Probe';
        $escaped = (new NativeSignatureHandoffRenderer)->render($data, new HtmlString('Trusted content'));
        self::assertStringNotContainsString('<script>', $escaped['html']);
        self::assertStringContainsString('&lt;script&gt;', $escaped['html']);
    }

    public function test_default_scope_ids_are_fresh_for_quote_isolation_and_local_mime_keeps_full_head(): void
    {
        $renderer = new NativeSignatureHandoffRenderer;
        $a = $renderer->render($this->descriptor(), new HtmlString('Same content'));
        $b = $renderer->render($this->descriptor(), new HtmlString('Same content'));
        self::assertNotSame($a['scopeId'], $b['scopeId']);
        self::assertSame($a['version'], $b['version']);
        self::assertStringNotContainsString('#'.$a['scopeId'], $b['headCss']);
        $message = (new Email)->from('system@example.invalid')->to('self@example.invalid')->subject('Synthetic render interface')->html($a['html'])->text('Synthetic render interface');
        self::assertSame($a['html'], $message->getHtmlBody());
        self::assertCount(0, $message->getAttachments());
        self::assertStringContainsString('Content-Type: text/html', $message->toString());
        self::assertSame(0, $this->xpath($message->getHtmlBody())->query('//body//style')->length);
    }

    #[DataProvider('invalidDescriptors')]
    public function test_unknown_invalid_or_ambiguous_descriptor_inputs_fail_closed(string $case): void
    {
        $data = $this->descriptor();
        $options = [];
        switch ($case) {
            case 'source': $data['sourceHash'] = 'not-a-source';
                break;
            case 'kind': $data['kind'] = 'reply';
                break;
            case 'field': $data['arbitraryHtml'] = '<b>not allowed</b>';
                break;
            case 'email': $data['dispatch']['email'] = "x@example.invalid\r\nBcc:y@example.invalid";
                break;
            case 'wa': $data['dispatch']['whatsapp'] = 'https://evil.example/491601881848';
                break;
            case 'phone': $data['dispatch']['phoneLabel'] = '+49 160 0000000';
                break;
            case 'site': $data['company']['website'] = 'javascript:alert(1)';
                break;
            case 'system-person': $data['kind'] = 'system';
                break;
            case 'scope': $options['scopeId'] = 'x" data-extra="1';
                break;
            case 'scope-null': $options['scopeId'] = null;
                break;
            case 'flag': $options['showGreeting'] = 'true';
                break;
            case 'wording': $options['showGreeting'] = true;
                break;
            case 'unknown-option': $options['bodyHtml'] = '<b>extra</b>';
                break;
            default: self::fail('Unknown fixture.');
        }
        $this->expectException(RuntimeException::class);
        (new NativeSignatureHandoffRenderer)->render($data, new HtmlString('Trusted'), $options);
    }

    public static function invalidDescriptors(): array
    {
        return array_map(static fn (string $value): array => [$value], ['source', 'kind', 'field', 'email', 'wa', 'phone', 'site', 'system-person', 'scope', 'scope-null', 'flag', 'wording', 'unknown-option']);
    }

    public function test_foreign_media_root_is_not_accepted(): void
    {
        $this->expectException(RuntimeException::class);
        (new NativeSignatureHandoffRenderer('https://other.example.test'))->render($this->descriptor(), new HtmlString('Trusted'));
    }

    private function render(array $options = []): array
    {
        return (new NativeSignatureHandoffRenderer)->render($this->descriptor(), new HtmlString('<p>Synthetic author content.</p>'), array_replace(['scopeId' => str_repeat('a', 32)], $options));
    }

    private function descriptor(): array
    {
        return ['sourceHash' => hash('sha256', 'published synthetic source'), 'kind' => 'employee', 'identity' => ['name' => 'Alex Beispiel', 'role' => 'IT-TECHNIK'],
            'direct' => [['type' => 'email', 'value' => 'alex@example.invalid', 'label' => 'alex@example.invalid'], ['type' => 'phone', 'value' => '+491705550101', 'label' => '+49 170 5550101']],
            'company' => ['name' => 'Muster Rail GmbH', 'website' => 'https://example.invalid', 'websiteLabel' => 'example.invalid', 'street' => 'Musterweg 12', 'postalCity' => '10115 Berlin', 'registration' => 'Geschäftsführung: A. Beispiel · HRB 12345', 'confidentiality' => 'Vertrauliche Nachricht. Bei irrtümlichem Empfang bitte Absender informieren.'],
            'dispatch' => ['email' => 'dispo@example.invalid', 'whatsapp' => 'https://wa.me/491601881848', 'phone' => '+491601881839', 'phoneLabel' => '+49 160 1881839']];
    }

    private function widths(\DOMNodeList $nodes): array
    {
        return array_map(static fn ($node): string => $node->getAttribute('width'), iterator_to_array($nodes));
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
