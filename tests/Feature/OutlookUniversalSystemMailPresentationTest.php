<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\MailDocumentKind;
use App\Http\Controllers\Admin\MailDocumentController;
use App\Models\MailDocument;
use App\Support\EmailTemplateBuilder;
use App\Support\Mail\OutlookUniversalSystemMailPresentation;
use App\Support\Mail\PublishedMailDocumentSnapshotStore;
use App\Support\Mail\SystemMailInlineImageEmbedder;
use App\Support\MailSignature;
use DOMDocument;
use DOMXPath;
use Illuminate\Mail\Markdown;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\HtmlString;
use ReflectionMethod;
use ReflectionProperty;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

final class OutlookUniversalSystemMailPresentationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $fixture = new PublishedStaticSystemDeliveryTest('test_effective_published_system_pair_uses_remote_pngs_through_the_real_inliner_and_embedder');
        (new ReflectionMethod($fixture, 'setUp'))->invoke($fixture);
        $signature = (new ReflectionProperty($fixture, 'linked'))->getValue($fixture);
        $template = (new ReflectionProperty($fixture, 'template'))->getValue($fixture);
        $mark = new ReflectionMethod(OutlookLinkedAnimatedDeliveryTest::class, 'mark');
        $marker = new OutlookLinkedAnimatedDeliveryTest('test_actual_system_default_and_explicit_published_testmail_keep_the_gif_link_without_inline_attachments');
        $signatureHtml = $mark->invoke($marker, $signature->published_html, true);
        $templateHtml = $mark->invoke($marker, $template->published_html, false);
        $signature->forceFill(['html' => $signatureHtml, 'published_html' => $signatureHtml])->saveQuietly();
        $template->forceFill(['html' => $templateHtml, 'published_html' => $templateHtml, 'published_signature_document_id' => $signature->id])->saveQuietly();
        app()->forgetScopedInstances();
        Http::preventStrayRequests();
        config(['app.url' => 'https://mail.example.test']);
    }

    public function test_canonical_system_source_projects_current_b1_and_preserves_opaque_application_content_once(): void
    {
        $author = '<p data-opaque="1">System &amp; content {{FIRMENNAME}}</p><!-- Author -->';
        $legacy = $this->legacy($author);
        $result = (new OutlookUniversalSystemMailPresentation)->project($legacy, $this->values(), new HtmlString($author));
        self::assertNotNull($result, (new OutlookUniversalSystemMailPresentation)->review($legacy, $this->values(), new HtmlString($author))['reason']);
        self::assertSame('send', $result['mode']);
        self::assertSame('f297c0bb222061eb3b78c85889e139274067d5744707d7807d5b6e8545e676b2', $result['templateHash']);
        self::assertSame(1, substr_count($result['html'], $author));
        self::assertStringContainsString('Synthetic Rail GmbH', $result['html']);
        self::assertStringNotContainsString('rt-v17-', $result['html']);
        self::assertSame(3, substr_count($result['headCss'], '@media'));
        self::assertTrue(OutlookUniversalSystemMailPresentation::isAdmittedHtml($result['html']));
        self::assertFalse(OutlookUniversalSystemMailPresentation::isAdmittedHtml($result['html'].' '));
        Http::assertNothingSent();
    }

    public function test_real_markdown_actions_tables_and_signed_url_are_formatted_without_rewriting_the_send_shell(): void
    {
        $notification = (new MailMessage)->subject('Synthetic system mail')->greeting('Hallo')
            ->line('Exact application text.')
            ->action('Zugang einrichten', 'https://example.test/invite/'.str_repeat('a', 170).'?signature=synthetic')
            ->line('Keine Nachricht wird versandt.');
        $html = (string) app(Markdown::class)->render($notification->markdown, $notification->data());
        self::assertTrue(OutlookUniversalSystemMailPresentation::isAdmittedHtml($html));
        $xpath = $this->xpath($html);
        self::assertSame(1, $xpath->query('//table[@class="rt-shell"]')->length);
        $button = $xpath->query('//a[contains(text(), "Zugang einrichten")]')->item(0);
        self::assertNotNull($button);
        self::assertMatchesRegularExpression('/padding:\s*14px 22px/', $button->getAttribute('style'));
        self::assertStringContainsString('background', $button->parentNode->getAttribute('style'));
        $url = $xpath->query('//a[starts-with(text(), "https://example.test/invite/")]')->item(0);
        self::assertNotNull($url);
        self::assertMatchesRegularExpression('/word-break:\s*break-all/', $url->getAttribute('style'));
        $grid = $xpath->query('//td[contains(concat(" ", normalize-space(@class), " "), " rt-col-person ")]')->item(0);
        self::assertStringContainsString('display:inline-block;', $grid->getAttribute('style'));
        self::assertSame(3, substr_count($html, '@media'));
        Http::assertNothingSent();
    }

    public function test_cid_embedding_preserves_author_cid_plaintext_existing_attachments_and_is_idempotent(): void
    {
        $author = '<p>Exact author.</p><img src="cid:author-image@synthetic.test" alt="Application image">';
        $legacy = $this->legacy($author);
        $result = (new OutlookUniversalSystemMailPresentation)->project($legacy, $this->values(), new HtmlString($author));
        self::assertNotNull($result);
        $email = (new Email)->from('system@example.test')->to('it-technik@rail-time.de')->subject('Local only')
            ->text('Original text part.')->html($result['html'])->attach('original attachment', 'original.txt', 'text/plain');
        self::assertSame(9, (new SystemMailInlineImageEmbedder)->embed($email));
        self::assertSame(0, (new SystemMailInlineImageEmbedder)->embed($email));
        self::assertSame('Original text part.', $email->getTextBody());
        self::assertSame(10, count($email->getAttachments()));
        self::assertSame('u2e-'.$result['version'], $email->getHeaders()->get('X-RailTime-Template')->getBodyAsString());
        self::assertStringContainsString($author, $email->getHtmlBody());
        self::assertStringNotContainsString('src="https://mail.example.test/m/', $email->getHtmlBody());
        $wire = $email->toString();
        self::assertSame(9, substr_count($wire, 'Content-ID:'));
        self::assertStringContainsString('multipart/related', $wire);
        self::assertStringContainsString('original.txt', $wire);
        Http::assertNothingSent();
    }

    public function test_custom_or_personal_sources_are_not_silently_replaced_and_author_does_not_change_version(): void
    {
        $author = '<p>First.</p>';
        $legacy = $this->legacy($author);
        $projector = new OutlookUniversalSystemMailPresentation;
        $first = $projector->project($legacy, $this->values(), new HtmlString($author));
        self::assertNotNull($first);
        $secondAuthor = '<p>Completely different application message.</p>';
        $second = $projector->project($this->legacy($secondAuthor), $this->values(), new HtmlString($secondAuthor));
        self::assertNotNull($second);
        self::assertSame($first['version'], $second['version']);
        foreach ([str_replace('</body>', '<p>Authored footer must survive.</p></body>', $legacy),
            str_replace('data-rt-system-flow="v1"', 'data-rt-personal-flow="v1"', $legacy), '<p>Custom.</p>'] as $source) {
            self::assertNull($projector->project($source, $this->values(), new HtmlString($author)));
        }
        $values = $this->values();
        $values['FIRMENNAME'] = 'Different company';
        self::assertNull($projector->project($legacy, $values, new HtmlString($author)));
        self::assertNull(OutlookUniversalSystemMailPresentation::inline($first['html'].' ', '.button{color:red}'));
    }

    public function test_extra_signature_content_duplicate_links_custom_css_and_foreign_company_identity_remain_legacy(): void
    {
        $author = '<p>Application content.</p>';
        $source = $this->legacy($author);
        $projector = new OutlookUniversalSystemMailPresentation;
        foreach ([
            str_replace('example.test</a>', 'example.test EXTRA</a>', $source),
            str_replace('Musterweg 12', 'Musterweg 12 EXTRA', $source),
            preg_replace('~(<table\b(?=[^>]*class="[^"]*\brt-v17-company\b)[^>]*>)~', '$1<caption>Additional authored company text.</caption>', $source),
            str_replace('</body>', '<img src="https://foreign.example.test/custom.png"></body>', $source),
            str_replace('</head>', '<style data-rt-mail-document-css="signature">.custom{color:red}</style></head>', $source),
            str_replace('font-size:26px;line-height:34px', 'font-size:27px;line-height:34px', $source),
            str_replace('Automatische Nachricht', 'Authored different role', $source),
        ] as $case => $changed) {
            self::assertNotSame($source, $changed, (string) $case);
            self::assertNull($projector->project($changed, $this->values(), new HtmlString($author)), (string) $case);
        }
        $store = app(PublishedMailDocumentSnapshotStore::class);
        $signature = $store->snapshot(MailDocumentKind::Signature);
        $store->useSnapshot(MailDocumentKind::Signature, $signature['html'], '.custom{color:red}');
        try {
            $actual = EmailTemplateBuilder::buildSystemMailHtml(new HtmlString($author));
            self::assertFalse(OutlookUniversalSystemMailPresentation::isAdmittedHtml($actual));
            self::assertStringContainsString('.custom{color:red}', $actual);
            self::assertStringContainsString($author, $actual);
        } finally {
            $store->forget(MailDocumentKind::Signature);
        }
    }

    public function test_worker_registry_is_bounded_and_keeps_no_raw_author_or_company_profile(): void
    {
        $author = '<p>Registry fixture original.</p>';
        $source = $this->legacy($author);
        $projector = new OutlookUniversalSystemMailPresentation;
        $first = null;
        for ($i = 0; $i < 35; $i++) {
            $currentAuthor = '<p>Registry author '.$i.'.</p>';
            $result = $projector->project(str_replace($author, $currentAuthor, $source), $this->values(), new HtmlString($currentAuthor));
            self::assertNotNull($result);
            $first ??= $result;
        }
        self::assertFalse(OutlookUniversalSystemMailPresentation::isAdmittedHtml($first['html']));
        self::assertTrue(OutlookUniversalSystemMailPresentation::isAdmittedHtml($result['html']));
        $registry = (new ReflectionProperty(OutlookUniversalSystemMailPresentation::class, 'outputs'))->getValue();
        self::assertCount(32, $registry);
        $serialized = json_encode($registry, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('Registry author', $serialized);
        self::assertStringNotContainsString('Synthetic Rail GmbH', $serialized);
        self::assertStringNotContainsString('office@example.test', $serialized);
    }

    public function test_selected_published_admin_candidate_keeps_u2e_after_the_static_preview_guards(): void
    {
        $author = '<p>Admin candidate, no mail.</p>';
        $result = (new OutlookUniversalSystemMailPresentation)->project($this->legacy($author), $this->values(), new HtmlString($author));
        self::assertNotNull($result);
        $document = MailDocument::query()->where('kind', 'signature')->whereNotNull('published_html')->get()
            ->first(fn ($item): bool => str_contains($item->published_html, 'data-rt-linked="animated-v1"'));
        self::assertNotNull($document);
        $before = $document->getRawOriginal();
        $method = new ReflectionMethod(MailDocumentController::class, 'staticLinkedSystemPreview');
        $output = $method->invoke(app(MailDocumentController::class), $document, $document->content_hash,
            $document->published_html, $document->published_css, $result['html'], true);
        self::assertSame($result['html'], $output);
        self::assertSame($before, $document->fresh()->getRawOriginal());
        Http::assertNothingSent();
    }

    public function test_national_company_phone_labels_use_only_the_same_canonical_tel_target_for_direct_and_dispatch(): void
    {
        $author = '<p>National company phone fixture.</p>';
        $source = $this->legacy($author);
        foreach ([
            ['030 5550000', '0170 5550202', '+49305550000', '+491705550202'],
            ['0049 30 5550000', '0049 170 5550202', '+49305550000', '+491705550202'],
            ['+49 (0)30 5550000', '+49 (0)170 5550202', '+49305550000', '+491705550202'],
            ['+49 30 5550000', '+49 170 5550202', '+49 30 5550000', '+49 170 5550202'],
        ] as [$landline, $emergency, $expectedLandlineLabel, $expectedEmergencyLabel]) {
            $values = $this->values();
            $values['FIRMEN_TELEFON'] = $values['DURCHWAHL'] = $landline;
            $values['MOBIL'] = $values['NOTFALLNUMMER'] = $emergency;
            $rendered = (new OutlookUniversalSystemMailPresentation)->project($source, $values, new HtmlString($author));
            self::assertNotNull($rendered);
            $xpath = $this->xpath($rendered['html']);
            $landlineLinks = $xpath->query('//a[@href="tel:+49305550000"]');
            self::assertSame(1, $landlineLinks->length);
            self::assertSame($expectedLandlineLabel, $landlineLinks->item(0)->textContent);
            $emergencyLinks = $xpath->query('//a[@href="tel:+491705550202"]');
            self::assertSame(3, $emergencyLinks->length);
            $labelledLinks = array_filter(iterator_to_array($emergencyLinks), static fn ($link): bool => trim($link->textContent) !== '');
            self::assertCount(2, $labelledLinks);
            foreach ($labelledLinks as $link) {
                self::assertSame($expectedEmergencyLabel, str_replace("\u{00a0}", ' ', $link->textContent));
            }
            self::assertSame(10, $xpath->query('//img')->length);
            self::assertCount(9, $rendered['media']);
            $mail = (new Email)->from('system@example.test')->to('synthetic@example.test')->subject('Not sent')->html($rendered['html']);
            self::assertSame(9, (new SystemMailInlineImageEmbedder)->embed($mail));
        }
        Http::assertNothingSent();
    }

    public function test_a_different_or_non_phone_label_cannot_be_silently_replaced_with_an_unrelated_canonical_target(): void
    {
        $author = '<p>Unrelated company phone fixture.</p>';
        $source = $this->legacy($author);
        foreach (['030 9990000', 'Not a number 030 5550000', '030 5550000 ext 1', "+49 30 5550000\n"] as $label) {
            $values = $this->values();
            $values['FIRMEN_TELEFON'] = $values['DURCHWAHL'] = $label;
            self::assertNull((new OutlookUniversalSystemMailPresentation)->project($source, $values, new HtmlString($author)));
        }
        foreach (['0170 9990202', 'Not a number 0170 5550202'] as $label) {
            $values = $this->values();
            $values['NOTFALLNUMMER'] = $label;
            self::assertNull((new OutlookUniversalSystemMailPresentation)->project($source, $values, new HtmlString($author)));
        }
        Http::assertNothingSent();
    }

    private function legacy(string $author): string
    {
        app()->instance(OutlookUniversalSystemMailPresentation::class, new class
        {
            public function project(...$arguments): ?array
            {
                return null;
            }
        });
        try {
            return EmailTemplateBuilder::buildSystemMailHtml(new HtmlString($author));
        } finally {
            app()->forgetInstance(OutlookUniversalSystemMailPresentation::class);
        }
    }

    private function values(): array
    {
        return MailSignature::forCompany(theme: 'light', animated: true, remoteAssets: true)->values();
    }

    private function xpath(string $html): DOMXPath
    {
        $dom = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new DOMXPath($dom);
    }
}
