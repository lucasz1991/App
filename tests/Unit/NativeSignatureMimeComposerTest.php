<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Mail\NativeSignatureHandoffRenderer;
use App\Support\Mail\NativeSignatureMimeComposer;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\HtmlString;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Part\Multipart\AlternativePart;
use Tests\TestCase;

final class NativeSignatureMimeComposerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['app.url' => 'https://mail.example.test']);
        Http::preventStrayRequests();
        Mail::fake();
    }

    public function test_full_head_html_and_mixed_author_quote_line_endings_survive_the_actual_mime_encoding_byte_exact(): void
    {
        $author = "<p>Technischer Test: Grüße &amp; Rückfragen.</p>\r\n<blockquote><p>Opaque quoted {{IDENTITY_NAME}}.</p>\n<pre>  original\r\n bytes</pre></blockquote>";
        $options = ['scopeId' => str_repeat('a', 32), 'showGreeting' => true, 'greeting' => 'Guten Tag,', 'showFarewell' => true, 'farewell' => 'Freundliche Grüße,'];
        $expected = (new NativeSignatureHandoffRenderer)->render($this->descriptor(), new HtmlString($author), $options);
        $email = (new NativeSignatureMimeComposer)->itSelfTest($this->descriptor(), new HtmlString($author), $options);
        self::assertInstanceOf(AlternativePart::class, $email->getBody());
        self::assertSame($expected['html'], $email->getHtmlBody());
        $parts = $this->decodeMime($email);
        self::assertSame(['plain', 'html'], array_keys($parts));
        self::assertSame($expected['html'], $parts['html']);
        self::assertSame($email->getTextBody(), $parts['plain']);
        self::assertSame(1, substr_count($parts['html'], $author));
        self::assertStringContainsString($expected['headCss'], $parts['html']);
        self::assertStringContainsString('@media only screen and (max-width:759px)', $parts['html']);
        self::assertStringContainsString('<style data-rt-native-style="'.$expected['scopeId'].'">', $parts['html']);
        self::assertSame(1, substr_count($parts['html'], '<style'));
        self::assertStringNotContainsString('<style', substr($parts['html'], strpos($parts['html'], '</head>')));
        foreach ($expected['media'] as $media) {
            self::assertStringContainsString($media['url'], $parts['html']);
        }
        self::assertStringContainsString('https://mail.example.test/mail-assets/zug-dampf-outlook-brand-v2-light.gif', $parts['html']);
        self::assertStringNotContainsString('cid:', $parts['html']);
        self::assertCount(0, $email->getAttachments());
        Mail::assertNothingSent();
        Mail::assertNothingQueued();
        Http::assertNothingSent();
    }

    public function test_it_envelope_is_fixed_and_the_factory_does_not_claim_to_send_as_the_descriptor_employee(): void
    {
        $email = $this->compose();
        self::assertSame([NativeSignatureMimeComposer::MAILBOX], array_map(fn ($value) => $value->getAddress(), $email->getFrom()));
        self::assertSame([NativeSignatureMimeComposer::MAILBOX], array_map(fn ($value) => $value->getAddress(), $email->getTo()));
        self::assertSame([NativeSignatureMimeComposer::MAILBOX], array_map(fn ($value) => $value->getAddress(), $email->getReplyTo()));
        self::assertSame('RailTime IT-Test', $email->getFrom()[0]->getName());
        self::assertSame(NativeSignatureMimeComposer::SUBJECT, $email->getSubject());
        self::assertSame([], $email->getCc());
        self::assertSame([], $email->getBcc());
        self::assertNull($email->getSender());
        self::assertFalse($email->getHeaders()->has('In-Reply-To'));
        self::assertFalse($email->getHeaders()->has('References'));
        self::assertSame('local-only-v1', $email->getHeaders()->get('X-RailTime-Native-Preparation')->getBodyAsString());
        Mail::assertNothingSent();
    }

    public function test_a_read_once_author_contract_keeps_quoted_styles_opaque_in_html_but_removes_css_and_managed_tokens_from_plain_text(): void
    {
        $value = '<p>Current author.</p><blockquote><style>.old{color:red}</style><p>Previous reply &amp; original text.</p><span style="font-size:0">RT-SIGNATURE-MANAGED-V1 RT-SIGNATURE-VERSION:0123456789abcdef</span><!-- private comment --></blockquote>';
        $author = new class($value) implements Htmlable
        {
            public int $reads = 0;

            public function __construct(private readonly string $html) {}

            public function toHtml(): string
            {
                $this->reads++;

                return $this->html;
            }
        };
        $email = (new NativeSignatureMimeComposer)->itSelfTest($this->descriptor(), $author);
        self::assertSame(1, $author->reads);
        self::assertSame(1, substr_count($email->getHtmlBody(), $value));
        self::assertStringContainsString('Current author.', $email->getTextBody());
        self::assertStringContainsString('Previous reply & original text.', $email->getTextBody());
        self::assertStringContainsString('Telefon: +49 160 1881839', $email->getTextBody());
        self::assertStringNotContainsString('.old{', $email->getTextBody());
        self::assertStringNotContainsString('RT-SIGNATURE', $email->getTextBody());
        self::assertStringNotContainsString('RT_NATIVE', $email->getTextBody());
        self::assertStringNotContainsString('private comment', $email->getTextBody());
        self::assertStringNotContainsString('<', $email->getTextBody());
        self::assertSame($email->getHtmlBody(), $this->decodeMime($email)['html']);
    }

    public function test_reply_headers_are_explicit_finite_ids_and_do_not_change_recipient_or_original_content(): void
    {
        $author = '<blockquote>Unchanged historical content.</blockquote>';
        $email = (new NativeSignatureMimeComposer)->itSelfTest($this->descriptor(), new HtmlString($author), [], '<current.123@example.invalid>', ['older.123@example.invalid', '<current.123@example.invalid>']);
        self::assertSame('<current.123@example.invalid>', $email->getHeaders()->get('In-Reply-To')->getBodyAsString());
        self::assertStringContainsString('<older.123@example.invalid> <current.123@example.invalid>', $email->getHeaders()->get('References')->getBodyAsString());
        self::assertSame('AW: '.NativeSignatureMimeComposer::SUBJECT, $email->getSubject());
        self::assertSame(NativeSignatureMimeComposer::MAILBOX, $email->getTo()[0]->getAddress());
        self::assertStringContainsString($author, $this->decodeMime($email)['html']);
        $single = (new NativeSignatureMimeComposer)->itSelfTest($this->descriptor(), new HtmlString('Reply'), [], 'one@example.invalid');
        self::assertSame('<one@example.invalid>', $single->getHeaders()->get('References')->getBodyAsString());
    }

    #[DataProvider('invalidThreads')]
    public function test_header_injection_ambiguous_threads_and_unbounded_reference_lists_are_rejected_before_composition(?string $reply, array $references): void
    {
        $this->expectException(RuntimeException::class);
        (new NativeSignatureMimeComposer)->itSelfTest($this->descriptor(), new HtmlString('Safe body'), [], $reply, $references);
    }

    public static function invalidThreads(): array
    {
        return [
            'CRLF' => ["id@example.invalid\r\nBcc:foreign@example.invalid", []],
            'LF' => ["id@example.invalid\nX-Test: injected", []],
            'CR' => ["id@example.invalid\rX-Test: injected", []],
            'NUL' => ["id@example.invalid\0", []],
            'space' => [' id@example.invalid', []],
            'two ids' => ['<one@example.invalid><two@example.invalid>', []],
            'long id' => [str_repeat('a', 255).'@example.invalid', []],
            'nonascii' => ['grüße@example.invalid', []],
            'unbalanced' => ['<id@example.invalid', []],
            'empty local atom' => ['id..suffix@example.invalid', []],
            'empty domain label' => ['id@example..invalid', []],
            'invalid domain label' => ['id@-example.invalid', []],
            'references without reply' => [null, ['id@example.invalid']],
            'different last reply' => ['one@example.invalid', ['two@example.invalid']],
            'duplicate reference' => ['one@example.invalid', ['one@example.invalid', '<one@example.invalid>']],
            'reference injection' => ['one@example.invalid', ["old@example.invalid\r\nBcc:x", 'one@example.invalid']],
            'reference not string' => ['one@example.invalid', [7, 'one@example.invalid']],
            'associative references' => ['one@example.invalid', ['hidden' => 'one@example.invalid']],
            'too many references' => ['one@example.invalid', array_fill(0, 21, 'one@example.invalid')],
        ];
    }

    public function test_arbitrary_rendered_html_or_envelope_options_cannot_be_substituted_for_the_native_renderer_input(): void
    {
        foreach ([['from' => 'foreign@example.invalid'], ['to' => 'foreign@example.invalid'], ['html' => '<html><body>Unapproved</body></html>']] as $extra) {
            try {
                (new NativeSignatureMimeComposer)->itSelfTest($this->descriptor(), new HtmlString('Content'), $extra);
                self::fail('Unknown rendering/envelope option was admitted.');
            } catch (RuntimeException) {
                self::assertTrue(true);
            }
        }
        $data = $this->descriptor();
        $data['identity']['name'] = "Injected\r\nFrom: foreign@example.invalid";
        $this->expectException(RuntimeException::class);
        (new NativeSignatureMimeComposer)->itSelfTest($data, new HtmlString('Content'));
    }

    public function test_system_kind_uses_the_same_renderer_without_activating_the_existing_system_mail_pipeline(): void
    {
        $data = $this->descriptor();
        $data['kind'] = 'system';
        $data['identity'] = ['name' => $data['company']['name'], 'role' => 'Systemnachricht'];
        $email = (new NativeSignatureMimeComposer)->itSelfTest($data, new HtmlString('<p>Synthetic system content.</p>'));
        self::assertStringContainsString('Synthetic system content.', $email->getHtmlBody());
        self::assertStringContainsString($data['company']['name'], $email->getTextBody());
        self::assertSame(NativeSignatureMimeComposer::MAILBOX, $email->getFrom()[0]->getAddress());
        self::assertSame($email->getHtmlBody(), $this->decodeMime($email)['html']);
        Mail::assertNothingSent();
    }

    private function compose(): Email
    {
        return (new NativeSignatureMimeComposer)->itSelfTest($this->descriptor(), new HtmlString('<p>Synthetic local preparation.</p>'));
    }

    private function descriptor(): array
    {
        return ['sourceHash' => hash('sha256', 'synthetic-admitted-local-source'), 'kind' => 'employee', 'identity' => ['name' => 'Alex Beispiel', 'role' => 'IT-TECHNIK'],
            'direct' => [['type' => 'email', 'value' => 'alex@example.invalid', 'label' => 'alex@example.invalid'], ['type' => 'phone', 'value' => '+491705550101', 'label' => '+49 170 5550101']],
            'company' => ['name' => 'Muster Rail GmbH', 'website' => 'https://example.invalid', 'websiteLabel' => 'example.invalid', 'street' => 'Musterweg 12', 'postalCity' => '10115 Berlin', 'registration' => 'Geschäftsführung: A. Beispiel · HRB 12345', 'confidentiality' => 'Vertrauliche Testnachricht.'],
            'dispatch' => ['email' => 'dispo@example.invalid', 'whatsapp' => 'https://wa.me/491601881848', 'phone' => '+491601881839', 'phoneLabel' => '+49 160 1881839']];
    }

    /** Decode the serialized wire MIME independently of Email::getHtmlBody(). */
    private function decodeMime(Email $email): array
    {
        $wire = $email->toString();
        self::assertSame(1, preg_match('/Content-Type: multipart\/alternative;\s+boundary="?([A-Za-z0-9_-]+)"?\r\n/', $wire, $match), 'The wire header must identify one multipart/alternative boundary.');
        $parts = [];
        foreach (explode('--'.$match[1], $wire) as $part) {
            if (! preg_match('/\A\r\nContent-Type: text\/(plain|html); charset=utf-8\r\nContent-Transfer-Encoding: base64\r\n\r\n(.*?)\r\n\z/s', $part, $body)) {
                continue;
            }
            $parts[$body[1]] = base64_decode(str_replace(["\r", "\n"], '', $body[2]), true);
        }
        self::assertCount(2, $parts);

        return $parts;
    }
}
