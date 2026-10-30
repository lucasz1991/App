<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\Admin\MailDocumentController;
use App\Models\MailDocument;
use App\Support\EmailTemplateBuilder;
use App\Support\Mail\SystemMailCssInliner;
use App\Support\Mail\SystemMailInlineImageEmbedder;
use App\Support\OutlookAddin\OutlookAddinPayloadService;
use App\Support\OutlookAddin\OutlookAnimatedSignaturePresentation;
use App\Support\OutlookAddin\OutlookClientPresentation;
use App\Support\OutlookAddin\OutlookLinkedAnimatedDelivery;
use App\Support\OutlookAddin\OutlookMobileSignature;
use App\Support\OutlookAddin\OutlookStaticLinkedTestProfiles;
use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\HtmlString;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;
use Symfony\Component\Mime\Email;
use Tests\TestCase;
use TijsVerkoyen\CssToInlineStyles\CssToInlineStyles;

final class OutlookLinkedAnimatedDeliveryTest extends TestCase
{
    public function test_both_employee_devices_and_the_real_published_pair_link_one_animated_train_and_pngs_without_cids(): void
    {
        $fixture = new OutlookBrandedSignatureIntegrationTest('test_actual_global_paired_desktop_and_mobile_documents_keep_exact_media_and_budgets');
        [, $user] = (new ReflectionMethod($fixture, 'fixture'))->invoke($fixture, true);
        Http::preventStrayRequests();
        config(['app.url' => 'https://mail.example.test']);
        foreach (MailDocument::query()->get() as $document) {
            if (trim((string) $document->published_html) === '') {
                continue;
            }
            $html = $this->mark($document->published_html, $document->kind->value === 'signature');
            $document->forceFill(['html' => $html, 'published_html' => $html])->saveQuietly();
        }
        app()->forgetScopedInstances();
        $service = app(OutlookAddinPayloadService::class);
        $canonical = $service->forUser($user);
        $canonical['version']['personal'] = substr($service->sourceFingerprint($user), 0, 16);
        $before = MailDocument::query()->orderBy('id')->get()->toArray();
        foreach ([$canonical, OutlookMobileSignature::payload($canonical)] as $source) {
            $wire = OutlookClientPresentation::payload($source);
            self::assertSame($wire['signature']['html'], OutlookAnimatedSignaturePresentation::document($wire['signature']['html']));
            $result = OutlookStaticLinkedTestProfiles::payload($wire, $user);
            $documents = [[$result['signature']['html'], $result['signature']['media'], 30000]];
            self::assertStringContainsString('RT-SIGNATURE-VERSION:'.$result['version']['signature'], $result['signature']['html']);
            foreach ($result['templates'] as $entry) {
                self::assertSame(substr($entry['hash'], 0, 16), $entry['version']);
                self::assertStringContainsString('RT-SIGNATURE-VERSION:'.$entry['signatureVersion'], $entry['signature']['html']);
                $documents[] = [$entry['signature']['html'], $entry['signature']['media'], 30000];
                foreach (['html' => 'media', 'combinedComposeHtml' => 'combinedComposeMedia', 'mobileComposeHtml' => 'mobileComposeMedia'] as $field => $media) {
                    if (isset($entry[$field])) {
                        $documents[] = [$entry[$field], $entry[$media], $field === 'mobileComposeHtml' ? 30000 : 99000];
                    }
                }
            }
            foreach ($documents as [$html, $media, $limit]) {
                self::assertSame([], $media);
                self::assertDoesNotMatchRegularExpression('/\b(?:cid|data):|data-rt-linked|data-rt-static/i', $html);
                $xpath = $this->xpath($html);
                self::assertSame(1, $xpath->query('//table[@data-rt-desktop-flow="outlook-desktop-flow-v15"]')->length);
                $trains = $xpath->query('//img[contains(concat(" ",normalize-space(@class)," ")," rt-v15-train-image ")]');
                self::assertCount(1, $trains);
                self::assertStringContainsString('https://mail.example.test/mail-assets/zug-dampf-outlook-brand-v1-', $trains->item(0)->getAttribute('src'));
                self::assertStringEndsWith('.gif', $trains->item(0)->getAttribute('src'));
                self::assertSame('224', $trains->item(0)->getAttribute('width'));
                self::assertSame('49', $trains->item(0)->getAttribute('height'));
                self::assertStringContainsString('width:224px;', $trains->item(0)->getAttribute('style'));
                self::assertStringNotContainsString('min(', $trains->item(0)->getAttribute('style'));
                self::assertSame(1, $xpath->query('//table[contains(@class,"rt-v15-train")][@width="242"]')->length);
                foreach ($xpath->query('//img') as $image) {
                    if ($image !== $trains->item(0)) {
                        self::assertStringStartsWith('https://mail.example.test/m/', $image->getAttribute('src'));
                        self::assertStringEndsWith('.png', $image->getAttribute('src'));
                    }
                }
                self::assertLessThanOrEqual($limit, intdiv(strlen(mb_convert_encoding($html, 'UTF-16LE', 'UTF-8')), 2) + ($limit === 30000 ? 168 : 0));
            }
        }
        self::assertSame($before, MailDocument::query()->orderBy('id')->get()->toArray());
        Http::assertNothingSent();
    }

    public function test_actual_system_default_and_explicit_published_testmail_keep_the_gif_link_without_inline_attachments(): void
    {
        $fixture = new PublishedStaticSystemDeliveryTest('test_effective_published_system_pair_uses_remote_pngs_through_the_real_inliner_and_embedder');
        (new ReflectionMethod($fixture, 'setUp'))->invoke($fixture);
        $signature = (new ReflectionProperty($fixture, 'linked'))->getValue($fixture);
        $template = (new ReflectionProperty($fixture, 'template'))->getValue($fixture);
        $signatureHtml = $this->mark($signature->published_html, true);
        $signature->forceFill(['html' => $signatureHtml, 'published_html' => $signatureHtml])->saveQuietly();
        $templateHtml = $this->mark($template->published_html, false);
        $template->forceFill(['html' => $templateHtml, 'published_html' => $templateHtml, 'published_signature_document_id' => $signature->id])->saveQuietly();
        app()->forgetScopedInstances();
        $before = MailDocument::query()->orderBy('id')->get()->toArray();
        $compiled = EmailTemplateBuilder::buildSystemMailHtml(new HtmlString('<p>Bounded linked animation. Literal RT-SIGNATURE-VERSION:0123456789abcdef must stay unchanged.</p>'));
        $normal = (new SystemMailCssInliner)->convert($compiled);
        $previewHtml = (new CssToInlineStyles)->convert($compiled);
        $testmail = (new ReflectionMethod(MailDocumentController::class, 'staticLinkedSystemPreview'))->invoke(
            new MailDocumentController, $signature, $signature->content_hash, $signatureHtml, (string) $signature->published_css, $previewHtml, true,
        );
        foreach ([$normal, $testmail] as $html) {
            self::assertStringContainsString('Literal RT-SIGNATURE-VERSION:0123456789abcdef must stay unchanged.', $html);
            self::assertSame(3, $this->xpath($html)->query('//table[contains(concat(" ",normalize-space(@class)," ")," rt-v15-column ")]')->length);
            self::assertSame(1, $this->xpath($html)->query('//img[contains(@src,"/mail-assets/zug-dampf-outlook-brand-v1-")][contains(@src,".gif")]')->length);
            self::assertDoesNotMatchRegularExpression('/\b(?:cid|data):|data-rt-linked|data-rt-static|data-rt-system-mail-inline-assets/i', $html);
            $message = (new Email)->from('system@example.test')->to('self@example.test')->html($html);
            self::assertSame(0, (new SystemMailInlineImageEmbedder)->embed($message));
            self::assertCount(0, $message->getAttachments());
            self::assertStringNotContainsString('Content-ID:', $message->toString());
        }
        self::assertSame($before, MailDocument::query()->orderBy('id')->get()->toArray());
        Http::assertNothingSent();
    }

    public function test_profile_admission_rejects_mixed_duplicate_or_quoted_markers_and_unknown_inputs_remain_exact(): void
    {
        self::assertSame('<p>Author HTML</p>', OutlookAnimatedSignaturePresentation::document('<p>Author HTML</p>'));
        self::assertSame(['html' => 'unchanged'], OutlookLinkedAnimatedDelivery::entry(['html' => 'unchanged'], null, 'https://mail.example.test'));
        $source = '<tr data-rt-artifact-version="v27" data-rt-linked="animated-v1"><td>Signature</td></tr>';
        self::assertSame(OutlookLinkedAnimatedDelivery::PROFILE, OutlookStaticLinkedTestProfiles::signatureProfile(['html' => $source]));
        self::assertSame(OutlookLinkedAnimatedDelivery::PROFILE, OutlookStaticLinkedTestProfiles::signatureProfile(['html' => str_replace('"', "'", $source)]));
        foreach ([str_replace('<tr ', '<tr data-rt-static="v1" ', $source), str_replace('<tr ', '<tr data-rt-linked="animated-v1" ', $source), '<blockquote>'.$source.'</blockquote>', str_replace('animated-v1', 'unknown', $source)] as $bad) {
            try {
                OutlookStaticLinkedTestProfiles::signatureProfile(['html' => $bad]);
                self::fail('Ambiguous source profile must fail before media projection.');
            } catch (RuntimeException $error) {
                self::assertStringContainsString('nicht eindeutig', $error->getMessage());
            }
        }
    }

    private function mark(string $html, bool $signature): string
    {
        $html = preg_replace('/\sdata-rt-static="v1"/', '', $html);
        $pattern = $signature ? '~<tr\b(?=[^>]*\bdata-rt-artifact-version="v27")~' : '~<table\b(?=[^>]*\bclass="[^"]*\brt-shell\b)(?=[^>]*\bclass="[^"]*\bdesign-v27\b)~';
        $result = preg_replace($pattern, '$0 data-rt-linked="animated-v1"', $html, -1, $count);
        self::assertSame(1, $count);

        return $result;
    }

    private function xpath(string $html): DOMXPath
    {
        $document = new DOMDocument;
        $old = libxml_use_internal_errors(true);
        try {
            $document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($old);
        }

        return new DOMXPath($document);
    }
}
