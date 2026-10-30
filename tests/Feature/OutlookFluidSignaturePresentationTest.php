<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\MailDocument;
use App\Models\User;
use App\Support\OutlookAddin\OutlookAddinPayloadService;
use App\Support\OutlookAddin\OutlookClientPresentation;
use App\Support\OutlookAddin\OutlookContentFramePresentation;
use App\Support\OutlookAddin\OutlookFluidSignaturePresentation;
use App\Support\OutlookAddin\OutlookMobileSignature;
use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use ReflectionMethod;
use Tests\TestCase;

final class OutlookFluidSignaturePresentationTest extends TestCase
{
    public function test_portable_source_fits_the_measured_226_pixel_host_without_a_stylesheet(): void
    {
        $user = $this->fixture();
        $canonical = app(OutlookAddinPayloadService::class)->forUser($user);
        $published = MailDocument::query()->orderBy('id')->get()->toArray();
        foreach ([$canonical, OutlookMobileSignature::payload($canonical)] as $payload) {
            $result = OutlookClientPresentation::payload($payload);
            $xpath = $this->xpath($result['signature']['html']);
            $root = $xpath->query('//table[@data-rt-desktop-flow="outlook-desktop-flow-v12"]')->item(0);
            self::assertSame('100%', $root->getAttribute('width'));
            $main = $xpath->query('./tbody/tr[1]/td', $root)->item(0);
            self::assertStringContainsString('padding:10px 0 0 18px;', $main->getAttribute('style'));
            $contentAncestor = $xpath->query('//td[contains(concat(" ",normalize-space(@class)," ")," rt-sign-content ")]')->item(0);
            self::assertNull($contentAncestor, 'V12 removes the redundant content TD and its historical mobile padding alias.');
            self::assertStringContainsString('rt-sign-content-frame', $root->getAttribute('class'));
            self::assertSame('100%', $main->getAttribute('width'));
            $columns = $xpath->query('./table', $main);
            self::assertCount(4, $columns);
            foreach ([176, 204, 172, 164] as $position => $width) {
                $column = $columns->item($position);
                self::assertSame((string) $width, $column->getAttribute('width'));
                self::assertSame('left', $column->getAttribute('align'));
                self::assertLessThanOrEqual(226, $width + 18 + 3, 'Each source column fits the actual minimum host including the shared gutter and stripe.');
                foreach ($xpath->query('.//table', $column) as $nested) {
                    self::assertFalse($nested->hasAttribute('align'), 'Only the four outer groups may become Word floating tables.');
                    self::assertContains($nested->getAttribute('width'), ['100%', '160']);
                }
            }
            $train = $xpath->query('//table[contains(@class,"rt-v12-train")]')->item(0);
            $image = $xpath->query('//img[contains(@class,"rt-v12-train-image")]')->item(0);
            self::assertSame('218', $train->getAttribute('width'));
            self::assertSame('200', $image->getAttribute('width'));
            self::assertSame('43', $image->getAttribute('height'));
            self::assertLessThanOrEqual(226, 218 + 3);
            self::assertSame(1, $xpath->query('.//br[@clear="all"]', $main)->length);
            self::assertSame(1, $xpath->query('//img[contains(@class,"rt-v12-dispatch-label")][@width="160"][@height="22"]')->length);
            self::assertStringContainsString('padding:0 18px;', $xpath->query('//td[@class="rt-v12-legal-cell"]')->item(0)->getAttribute('style'));
            self::assertSame($result['signature']['html'], OutlookContentFramePresentation::document($result['signature']['html']));
        }
        self::assertSame($published, MailDocument::query()->orderBy('id')->get()->toArray());
    }

    public function test_identity_and_direct_contacts_are_separate_once_with_a_deterministic_177_pixel_slot(): void
    {
        $user = $this->fixture();
        User::withoutEvents(fn () => $user->update(['email' => 'it-technikxxxxx@rail-time.de']));
        $result = OutlookClientPresentation::payload(app(OutlookAddinPayloadService::class)->forUser($user->fresh()));
        $xpath = $this->xpath($result['signature']['html']);
        $identity = $xpath->query('//table[contains(concat(" ",normalize-space(@class)," ")," rt-v12-identity ")]')->item(0);
        $direct = $xpath->query('//table[contains(concat(" ",normalize-space(@class)," ")," rt-v12-direct ")]')->item(0);
        self::assertStringContainsString('Mara Muster', $identity->textContent);
        self::assertSame(1, $xpath->query('.//table[contains(@class,"rt-personal-logo-frame")]', $identity)->length);
        self::assertSame(0, $xpath->query('.//a[starts-with(@href,"mailto:")]', $identity)->length);
        self::assertSame(1, $xpath->query('.//a[@href="mailto:it-technikxxxxx@rail-time.de"]', $direct)->length);
        self::assertStringNotContainsString('Mara Muster', $direct->textContent);
        foreach ($xpath->query('.//td[contains(@class,"rt-contact-icon")]', $direct) as $icon) {
            self::assertSame('27', $icon->getAttribute('width'));
            self::assertStringContainsString('padding:0!important;', $icon->getAttribute('style'));
            self::assertSame('18', $xpath->query('.//img', $icon)->item(0)->getAttribute('width'));
        }
        foreach ($xpath->query('.//td[contains(@class,"rt-contact-text")]', $direct) as $text) {
            self::assertStringContainsString('padding:0!important;', $text->getAttribute('style'));
            self::assertStringNotContainsString('9px', $text->getAttribute('style'));
        }
        self::assertSame(177, (int) $direct->getAttribute('width') - 27);
        self::assertSame(1, $xpath->query('.//a[@href="mailto:it-technikxxxxx@rail-time.de"]/parent::p[contains(@style,"font-size:13px")]', $direct)->length);
    }

    public function test_new_media_are_exact_pinned_colored_train_and_tight_caption_with_no_orphan_cids(): void
    {
        $user = $this->fixture();
        $signature = OutlookClientPresentation::payload(app(OutlookAddinPayloadService::class)->forUser($user))['signature'];
        $xpath = $this->xpath($signature['html']);
        $expected = ['rt-v12-train-image' => 'zug-dampf-outlook-brand-v1-light.gif', 'rt-v12-dispatch-label' => 'outlook-hotline-label-inktop-v1.png'];
        foreach ($expected as $class => $filename) {
            $image = $xpath->query('//img[contains(@class,"'.$class.'")]')->item(0);
            $cid = substr($image->getAttribute('src'), 4);
            $media = array_values(array_filter($signature['media'], static fn (array $asset): bool => $asset['contentId'] === $cid));
            self::assertCount(1, $media);
            self::assertSame(hash_file('sha256', public_path('mail-assets/'.$filename)), hash('sha256', base64_decode($media[0]['base64'], true)));
        }
        foreach ($signature['media'] as $asset) {
            self::assertStringContainsString('cid:'.$asset['contentId'], $signature['html']);
        }
        $custom = '<style data-rt-mail-document-css="signature">.custom{color:purple}</style>'.$signature['html'];
        self::assertSame($custom, OutlookFluidSignaturePresentation::document($custom));
    }

    public function test_template_gutters_match_the_signature_and_legal_gutters_in_every_compose_variant(): void
    {
        $user = $this->fixture();
        $payload = OutlookClientPresentation::payload(app(OutlookAddinPayloadService::class)->forUser($user));
        foreach ($payload['templates'] as $template) {
            foreach (['composeHtml', 'combinedComposeHtml', 'mobileComposeHtml'] as $field) {
                if (! isset($template[$field])) {
                    continue;
                }
                $xpath = $this->xpath($template[$field]);
                foreach ($xpath->query('//td[contains(@class,"rt-v12-template-pad")]') as $cell) {
                    self::assertMatchesRegularExpression('/padding:[^;]*18px[^;]*18px!important;/', $cell->getAttribute('style'));
                    self::assertStringNotContainsString('padding-left:0!important', $cell->getAttribute('style'));
                }
            }
        }
    }

    public function test_runtime_gutters_do_not_rewrite_a_nested_table_inside_message_content(): void
    {
        $html = '<table class="rt-shell design-v27"><tbody><tr><td style="padding:0 40px 26px;">'
            .'RT-TEMPLATE-MANAGED-V1<table class="rt-shell design-v27"><tbody><tr><td style="padding:99px;">External content</td></tr></tbody></table>'
            .'</td></tr></tbody></table>';
        $result = OutlookFluidSignaturePresentation::document($html);
        $xpath = $this->xpath($result);
        self::assertStringContainsString('padding:0 18px 26px 18px!important;', $xpath->query('//table[not(ancestor::table)]/tbody/tr/td')->item(0)->getAttribute('style'));
        self::assertSame('padding:99px;', $xpath->query('//table[ancestor::table]/tbody/tr/td')->item(0)->getAttribute('style'));
        self::assertStringContainsString('External content', $result);
    }

    private function fixture(): User
    {
        $case = new OutlookBrandedSignatureIntegrationTest('test_actual_global_paired_desktop_and_mobile_documents_keep_exact_media_and_budgets');
        [, $user] = (new ReflectionMethod($case, 'fixture'))->invoke($case, true);
        Http::preventStrayRequests();
        config(['app.url' => 'https://mail.example.test']);
        URL::forceRootUrl('https://mail.example.test');
        URL::forceScheme('https');

        return $user;
    }

    private function xpath(string $html): DOMXPath
    {
        $document = new DOMDocument;
        $before = libxml_use_internal_errors(true);
        try {
            $document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($before);
        }

        return new DOMXPath($document);
    }
}
