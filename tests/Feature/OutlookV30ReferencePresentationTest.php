<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\MailDocument;
use App\Support\EmailTemplateBuilder;
use App\Support\Mail\EmailCompatibilityAuditor;
use App\Support\Mail\SystemMailCssInliner;
use App\Support\Mail\SystemMailInlineImageEmbedder;
use App\Support\OutlookAddin\OutlookAddinPayloadService;
use App\Support\OutlookAddin\OutlookClientPresentation;
use App\Support\OutlookAddin\OutlookMobileSignature;
use App\Support\OutlookAddin\OutlookStaticLinkedTestProfiles;
use App\Support\OutlookAddin\OutlookV30ReferencePresentation as Reference;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\HtmlString;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

final class OutlookV30ReferencePresentationTest extends TestCase
{
    public function test_mobile_dispatch_actions_have_meaningful_alternatives_for_the_real_linked_image_auditor(): void
    {
        [$canonical] = $this->source();
        foreach ([$canonical, OutlookMobileSignature::payload($canonical)] as $input) {
            $html = OutlookClientPresentation::payload($input)['signature']['html'];
            self::assertSame(1, preg_match('~<div class="rt-v16-mobile-dispatch"[^>]*>.*?</div>~s', $html, $match));
            $bar = $match[0];
            $dom = new \DOMDocument;
            @$dom->loadHTML('<?xml encoding="UTF-8">'.$bar, LIBXML_NONET);
            $images = (new \DOMXPath($dom))->query('//a/img');
            self::assertCount(2, $images);
            self::assertSame('E-Mail', $images->item(0)->getAttribute('alt'));
            self::assertSame('Telefon', $images->item(1)->getAttribute('alt'));
            self::assertStringStartsWith('mailto:', $images->item(0)->parentNode->getAttribute('href'));
            self::assertStringStartsWith('tel:', $images->item(1)->parentNode->getAttribute('href'));
            $audit = app(EmailCompatibilityAuditor::class)->audit($bar, '', ['document_kind' => 'signature']);
            self::assertNotContains('EMAIL-IMAGE-001', array_column($audit->findings, 'rule_id'));
            $broken = str_replace('alt="E-Mail"', 'alt=""', $bar);
            self::assertNotSame($bar, $broken);
            $negative = app(EmailCompatibilityAuditor::class)->audit($broken, '', ['document_kind' => 'signature']);
            self::assertContains('EMAIL-IMAGE-001', array_column($negative->findings, 'rule_id'), 'The normal auditor must still reject an unnamed linked action.');
        }
        Http::assertNothingSent();
    }

    public function test_actual_employee_fields_keep_the_published_source_and_exact_inverse_with_remote_animation(): void
    {
        [$canonical, $user] = $this->source();
        $before = MailDocument::query()->orderBy('id')->get()->toArray();
        foreach ([$canonical, OutlookMobileSignature::payload($canonical)] as $device) {
            $wire = OutlookClientPresentation::payload($device);
            $result = OutlookStaticLinkedTestProfiles::payload($wire, $user);
            $documents = [$wire['signature']['html']];
            foreach ($wire['templates'] as $entry) {
                $documents[] = $entry['signature']['html'];
                foreach (['html', 'combinedComposeHtml', 'mobileComposeHtml'] as $field) {
                    if (isset($entry[$field])) {
                        $documents[] = $entry[$field];
                    }
                }
            }
            foreach ($documents as $html) {
                self::assertSame($html, Reference::document($html));
                $previous = Reference::canonicalizeToV15($html);
                self::assertStringContainsString('outlook-desktop-flow-v15', $previous);
                self::assertSame($html, Reference::document($previous));
                self::assertSame(1, substr_count($html, 'class="rt-v16-mobile-dispatch"'));
                self::assertStringContainsString('width="220" align="left"', $html);
                self::assertStringContainsString('width="280" align="left"', $html);
                self::assertStringContainsString('width="529"', $html);
                self::assertStringContainsString('height="115"', $html);
                self::assertStringContainsString('padding:12px 40px 0 40px;', $html);
                self::assertStringContainsString('padding:12px 40px 16px;', $html);
                self::assertStringContainsString('width:15px!important;height:15px!important;', $html);
            }
            self::assertSame([], $result['signature']['media']);
            self::assertSame(1, substr_count($result['signature']['html'], '/mail-assets/zug-dampf-outlook-brand-v1-'));
            self::assertDoesNotMatchRegularExpression('/\b(?:cid|data):/i', $result['signature']['html']);
        }
        self::assertSame($before, MailDocument::query()->orderBy('id')->get()->toArray());
        Http::assertNothingSent();
    }

    public function test_actual_system_inliner_emits_three_groups_and_derived_mobile_bar_without_attachments(): void
    {
        $fixture = new PublishedStaticSystemDeliveryTest('test_effective_published_system_pair_uses_remote_pngs_through_the_real_inliner_and_embedder');
        (new ReflectionMethod($fixture, 'setUp'))->invoke($fixture);
        $signature = (new ReflectionProperty($fixture, 'linked'))->getValue($fixture);
        $template = (new ReflectionProperty($fixture, 'template'))->getValue($fixture);
        $signature->forceFill(['html' => $this->mark($signature->published_html, true), 'published_html' => $this->mark($signature->published_html, true)])->saveQuietly();
        $template->forceFill(['html' => $this->mark($template->published_html, false), 'published_html' => $this->mark($template->published_html, false), 'published_signature_document_id' => $signature->id])->saveQuietly();
        app()->forgetScopedInstances();
        $before = MailDocument::query()->orderBy('id')->get()->toArray();
        $html = (new SystemMailCssInliner)->convert(EmailTemplateBuilder::buildSystemMailHtml(new HtmlString('<p>Application content stays literal.</p>')));
        self::assertStringContainsString('outlook-desktop-flow-v16', $html);
        self::assertSame(3, substr_count($html, ' rt-v16-column '));
        self::assertSame(1, substr_count($html, 'class="rt-v16-mobile-dispatch"'));
        self::assertStringContainsString('Application content stays literal.', $html);
        self::assertDoesNotMatchRegularExpression('/\b(?:cid|data):|data-rt-system-mail-inline-assets/i', $html);
        $message = (new Email)->from('system@example.test')->to('self@example.test')->html($html);
        self::assertSame(0, (new SystemMailInlineImageEmbedder)->embed($message));
        self::assertCount(0, $message->getAttachments());
        self::assertSame($before, MailDocument::query()->orderBy('id')->get()->toArray());
        Http::assertNothingSent();
    }

    public function test_system_frames_and_media_queries_remain_fluid_without_inlining_mobile_visibility_into_desktop(): void
    {
        $fixture = new PublishedStaticSystemDeliveryTest('test_effective_published_system_pair_uses_remote_pngs_through_the_real_inliner_and_embedder');
        (new ReflectionMethod($fixture, 'setUp'))->invoke($fixture);
        $signature = (new ReflectionProperty($fixture, 'linked'))->getValue($fixture);
        $template = (new ReflectionProperty($fixture, 'template'))->getValue($fixture);
        $signature->forceFill(['html' => $this->mark($signature->published_html, true), 'published_html' => $this->mark($signature->published_html, true)])->saveQuietly();
        $template->forceFill(['html' => $this->mark($template->published_html, false), 'published_html' => $this->mark($template->published_html, false), 'published_signature_document_id' => $signature->id])->saveQuietly();
        app()->forgetScopedInstances();
        $before = MailDocument::query()->orderBy('id')->get()->toArray();
        $compiled = EmailTemplateBuilder::buildSystemMailHtml(new HtmlString('<p>Opaque application bytes &amp; text.</p>'));
        self::assertSame($compiled, Reference::document($compiled));
        $previous = Reference::canonicalizeToV15($compiled);
        self::assertStringContainsString('outlook-desktop-flow-v15', $previous);
        self::assertStringContainsString('box-sizing:border-box;border-left:3px solid #e90032;overflow:hidden;', $previous);
        self::assertSame($compiled, Reference::document($previous));
        $sourceDom = new \DOMDocument;
        @$sourceDom->loadHTML('<?xml encoding="UTF-8">'.$compiled, LIBXML_NONET);
        $sourceXpath = new \DOMXPath($sourceDom);
        $sourceShell = $sourceXpath->query('//table[contains(concat(" ",normalize-space(@class)," ")," rt-shell ")]')->item(0);
        self::assertStringContainsString(';table-layout:fixed!important;', $sourceShell->getAttribute('style'));
        $ancestors = $sourceXpath->query('ancestor::table', $sourceShell);
        self::assertCount(1, $ancestors);
        self::assertStringContainsString(';table-layout:fixed!important;', $ancestors->item(0)->getAttribute('style'));
        foreach (['rt-v16-columns', 'rt-v16-train'] as $class) {
            $table = $sourceXpath->query('//table[contains(concat(" ",normalize-space(@class)," ")," '.$class.' ")]')->item(0);
            self::assertStringContainsString(';table-layout:fixed!important;', $table->getAttribute('style'));
        }
        self::assertStringContainsString('box-sizing:border-box;border-left:0!important;overflow:hidden;', $compiled);
        self::assertStringContainsString('@media (max-width:520px)', $compiled);
        self::assertStringNotContainsString('@media(max-width:520px)', $compiled);
        self::assertStringContainsString('.rt-personal-logo-frame{float:none!important;width:184px!important;margin:0!important}', $compiled);
        self::assertStringContainsString('.rt-v16-company{width:100%!important}', $compiled);
        foreach ([
            [';table-layout:fixed!important;', ';table-layout:auto!important;'],
            ['box-sizing:border-box;border-left:0!important;overflow:hidden;', 'box-sizing:border-box;border-left:9px solid #e90032;overflow:hidden;'],
            ['@media (max-width:520px)', '@media(max-width:520px)'],
        ] as [$old, $new]) {
            try {
                Reference::canonicalizeToV15(str_replace($old, $new, $compiled));
                self::fail('A changed system frame or CSS contract must not be inverted.');
            } catch (RuntimeException $error) {
                self::assertStringContainsString('keinen eindeutigen', $error->getMessage());
            }
        }
        $html = (new SystemMailCssInliner)->convert($compiled);
        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET);
        $xpath = new \DOMXPath($dom);
        $dispatch = $xpath->query('//table[contains(concat(" ",normalize-space(@class)," ")," rt-v16-dispatch ")]');
        self::assertCount(1, $dispatch);
        self::assertDoesNotMatchRegularExpression('/display\s*:\s*none/i', $dispatch->item(0)->getAttribute('style'));
        $shell = $xpath->query('//table[contains(concat(" ",normalize-space(@class)," ")," rt-shell ")]')->item(0);
        self::assertMatchesRegularExpression('/table-layout\s*:\s*fixed!important/i', $shell->getAttribute('style'));
        self::assertMatchesRegularExpression('/border-left\s*:\s*0!important/i', $shell->parentNode->getAttribute('style'));
        self::assertStringContainsString('.rt-sign-content-frame{table-layout:auto!important;}', $html, 'Retain historical media CSS; the scoped inline fallback must beat it.');
        foreach (['rt-v16-columns', 'rt-v16-train'] as $class) {
            $table = $xpath->query('//table[contains(concat(" ",normalize-space(@class)," ")," '.$class.' ")]')->item(0);
            self::assertMatchesRegularExpression('/(?:^|;)\s*table-layout\s*:\s*fixed!important\s*(?:;|$)/i', $table->getAttribute('style'));
        }
        self::assertSame($before, MailDocument::query()->orderBy('id')->get()->toArray());
        Http::assertNothingSent();
    }

    public function test_unrecognized_and_authored_layouts_remain_byte_exact(): void
    {
        self::assertSame('<p>Authored content</p>', Reference::document('<p>Authored content</p>'));
        [$canonical] = $this->source();
        $current = OutlookClientPresentation::payload($canonical)['signature']['html'];
        $previous = Reference::canonicalizeToV15($current);
        $authored = '<style data-rt-mail-document-css="signature">.author{color:blue}</style>'.$previous;
        self::assertSame($authored, Reference::document($authored));
        $quoted = '<blockquote><p>Ordinary quoted text, without a managed source.</p></blockquote>';
        self::assertSame($quoted, Reference::document($quoted));
    }

    public function test_template_only_uses_the_same_shell_and_responsive_gutters_without_adding_a_signature(): void
    {
        [$canonical, $user] = $this->source();
        $before = MailDocument::query()->orderBy('id')->get()->toArray();
        $wire = OutlookStaticLinkedTestProfiles::payload(OutlookClientPresentation::payload($canonical), $user);
        $checked = 0;
        foreach ($wire['templates'] as $entry) {
            $html = $entry['composeHtml'] ?? '';
            if (! str_contains($html, 'RT-TEMPLATE-MANAGED-V1:NATIVE-SIGNATURE')) {
                continue;
            }
            $checked++;
            self::assertSame($html, Reference::document($html));
            self::assertStringNotContainsString('RT-SIGNATURE-MANAGED-V1', $html);
            self::assertStringContainsString('padding:32px 40px 30px 40px;', $html);
            self::assertStringContainsString('.v30 .rt-v16-template-pad{padding-left:20px!important;padding-right:20px!important}', $html);
            $dom = new \DOMDocument;
            @$dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET);
            $xpath = new \DOMXPath($dom);
            $frames = $xpath->query('//table[contains(concat(" ",normalize-space(@class)," ")," rt-native-compose-frame ")]');
            self::assertCount(1, $frames);
            self::assertStringContainsString('border-left:0!important;', $frames->item(0)->getAttribute('style'));
            self::assertStringContainsString('max-width:1040px!important;', $frames->item(0)->getAttribute('style'));
        }
        self::assertGreaterThan(0, $checked);
        self::assertSame($before, MailDocument::query()->orderBy('id')->get()->toArray());
        Http::assertNothingSent();
    }

    public function test_template_only_projection_never_changes_a_quoted_or_surrounded_old_template(): void
    {
        $previous = file_get_contents(base_path('tests/Fixtures/mail/outlook-v30-reference/native-template-v15.html'));
        self::assertStringNotContainsString('data-rt-v30-template-css', $previous);
        self::assertStringContainsString('data-rt-v30-template-css', Reference::document($previous));
        foreach (['<blockquote>'.$previous.'</blockquote>', '<div id="divRplyFwdMsg">Quote header</div>'.$previous,
            '<p>Unmanaged content.</p>'.$previous, '<div class="gmail_quote">'.$previous.'</div>',
            'Unmanaged text bytes.'.$previous, $previous.'Unmanaged trailing text.'] as $quoted) {
            self::assertSame($quoted, Reference::document($quoted));
        }
    }

    public function test_unmarked_and_static_template_only_sources_stay_byte_exact(): void
    {
        $previous = file_get_contents(base_path('tests/Fixtures/mail/outlook-v30-reference/native-template-v15.html'));
        foreach (['', ' data-rt-static="v1"', ' data-rt-linked="other-v1"'] as $marker) {
            $historical = str_replace(' data-rt-linked="animated-v1"', $marker, $previous);
            self::assertNotSame($previous, $historical);
            self::assertSame($historical, Reference::document($historical));
        }
    }

    #[DataProvider('changedTemplateOnly')]
    public function test_template_only_rejects_changed_owned_geometry_on_a_second_projection(string $before, string $after): void
    {
        $previous = file_get_contents(base_path('tests/Fixtures/mail/outlook-v30-reference/native-template-v15.html'));
        $html = Reference::document($previous);
        self::assertStringContainsString($before, $html);
        $this->expectException(RuntimeException::class);
        Reference::document(str_replace($before, $after, $html));
    }

    public static function changedTemplateOnly(): array
    {
        return [
            ['padding:32px 40px 30px 40px;', 'padding:32px 999px 30px 40px;'],
            ['max-width:1040px!important;', 'max-width:1041px!important;'],
            ['border-left:0!important;', 'border-left:3px solid red!important;'],
            ['padding:0 40px 0 40px;', 'padding:0 40px 0 40px;padding-left:999px!important;'],
            ['padding:0 40px 0 40px;', 'padding:0 40px 0 40px;padding:999px;'],
            ['border-left:0!important;', 'border-left:0!important;border-left:3px solid red;'],
        ];
    }

    #[DataProvider('changedSource')]
    public function test_only_the_exact_new_source_contract_can_be_inverted(string $before, string $after): void
    {
        [$canonical] = $this->source();
        $html = OutlookClientPresentation::payload($canonical)['signature']['html'];
        self::assertStringContainsString($before, $html);
        $this->expectException(RuntimeException::class);
        Reference::canonicalizeToV15(str_replace($before, $after, $html));
    }

    public static function changedSource(): array
    {
        return [
            'column width' => ['width="220" align="left"', 'width="221" align="left"'],
            'main gutter' => ['padding:12px 40px 0 40px;', 'padding:12px 41px 0 40px;'],
            'legal gutter' => ['padding:12px 40px 16px;', 'padding:12px 41px 16px;'],
            'train geometry' => ['width="529"', 'width="530"'],
            'mobile paint' => ['display:none;mso-hide:all;padding:7px 20px;background-color:#e90032;', 'display:none;mso-hide:all;padding:7px 20px;background-color:#000000;'],
            'mobile link' => ['margin-left:12px;', 'margin-left:13px;'],
            'source css' => ['@media(max-width:520px)', '@media(max-width:900px)'],
            'contact geometry' => ['width:26px!important;min-width:26px!important;', 'width:25px!important;min-width:25px!important;'],
            'phone disagreement' => ['Dispo <span style="font-style:italic;">24/7</span>', 'Foreign <span style="font-style:italic;">24/7</span>'],
            'unnamed mail action' => ['alt="E-Mail" style="display:inline-block;', 'alt="" style="display:inline-block;'],
            'unnamed phone action' => ['alt="Telefon" style="display:inline-block;', 'alt="" style="display:inline-block;'],
        ];
    }

    private function source(): array
    {
        $fixture = new OutlookBrandedSignatureIntegrationTest('test_actual_global_paired_desktop_and_mobile_documents_keep_exact_media_and_budgets');
        [, $user] = (new ReflectionMethod($fixture, 'fixture'))->invoke($fixture, true);
        Http::preventStrayRequests();
        config(['app.url' => 'https://mail.example.test']);
        foreach (MailDocument::query()->get() as $document) {
            if (trim((string) $document->published_html) !== '') {
                $html = $this->mark($document->published_html, $document->kind->value === 'signature');
                $document->forceFill(['html' => $html, 'published_html' => $html])->saveQuietly();
            }
        }
        app()->forgetScopedInstances();
        $service = app(OutlookAddinPayloadService::class);
        $canonical = $service->forUser($user);
        $canonical['version']['personal'] = substr($service->sourceFingerprint($user), 0, 16);

        return [$canonical, $user];
    }

    private function mark(string $html, bool $signature): string
    {
        $html = preg_replace('/\sdata-rt-static="v1"/', '', $html);
        $pattern = $signature ? '~<tr\b(?=[^>]*\bdata-rt-artifact-version="v27")~' : '~<table\b(?=[^>]*\bclass="[^\"]*\brt-shell\b)(?=[^>]*\bclass="[^\"]*\bdesign-v27\b)~';
        $result = preg_replace($pattern, '$0 data-rt-linked="animated-v1"', $html, -1, $count);
        self::assertSame(1, $count);

        return $result;
    }
}
