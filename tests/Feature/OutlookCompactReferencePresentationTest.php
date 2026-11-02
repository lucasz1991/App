<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\MailDocument;
use App\Models\UserProfile;
use App\Support\EmailTemplateBuilder;
use App\Support\Mail\SystemMailCssInliner;
use App\Support\Mail\SystemMailInlineImageEmbedder;
use App\Support\OutlookAddin\OutlookAddinPayloadService;
use App\Support\OutlookAddin\OutlookClientPresentation;
use App\Support\OutlookAddin\OutlookCompactReferencePresentation as Compact;
use App\Support\OutlookAddin\OutlookMobileSignature;
use App\Support\OutlookAddin\OutlookStaticLinkedTestProfiles;
use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\HtmlString;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

final class OutlookCompactReferencePresentationTest extends TestCase
{
    public function test_real_employee_linked_delivery_is_compact_without_mutating_published_sources(): void
    {
        [$canonical, $user] = $this->source();
        $before = MailDocument::query()->orderBy('id')->get()->toArray();
        $fingerprint = app(OutlookAddinPayloadService::class)->sourceFingerprint($user);
        $original = $canonical;
        $checked = 0;
        foreach ([$canonical, OutlookMobileSignature::payload($canonical)] as $source) {
            $wire = OutlookStaticLinkedTestProfiles::payload(OutlookClientPresentation::payload($source), $user);
            $documents = [[$wire['signature']['html'], $wire['signature']['media'], 30000]];
            foreach ($wire['templates'] as $entry) {
                $documents[] = [$entry['signature']['html'], $entry['signature']['media'], 30000];
                foreach (['combinedComposeHtml' => 'combinedComposeMedia', 'mobileComposeHtml' => 'mobileComposeMedia'] as $field => $media) {
                    if (isset($entry[$field])) {
                        $documents[] = [$entry[$field], $entry[$media], $field === 'mobileComposeHtml' ? 30000 : 99000];
                    }
                }
            }
            foreach ($documents as [$html, $media, $limit]) {
                $checked++;
                self::assertSame([], $media);
                $this->assertCompact($html, false);
                self::assertSame($html, Compact::document($html));
                $prior = Compact::canonicalizeToV16($html);
                self::assertStringContainsString('outlook-desktop-flow-v16', $prior);
                self::assertStringContainsString('/mail-assets/zug-dampf-outlook-brand-v1-', $prior);
                self::assertSame($html, Compact::document($prior));
                $this->assertBudget($html, $limit);
            }
        }
        self::assertGreaterThanOrEqual(10, $checked);
        self::assertSame($original, $canonical);
        self::assertSame($fingerprint, app(OutlookAddinPayloadService::class)->sourceFingerprint($user));
        self::assertSame($before, MailDocument::query()->orderBy('id')->get()->toArray());
        Http::assertNothingSent();
    }

    public function test_actual_system_builder_inliner_keeps_three_groups_remote_media_and_source_snapshots(): void
    {
        $fixture = new PublishedStaticSystemDeliveryTest('test_effective_published_system_pair_uses_remote_pngs_through_the_real_inliner_and_embedder');
        (new ReflectionMethod($fixture, 'setUp'))->invoke($fixture);
        $signature = (new ReflectionProperty($fixture, 'linked'))->getValue($fixture);
        $template = (new ReflectionProperty($fixture, 'template'))->getValue($fixture);
        $signature->forceFill(['html' => $this->mark($signature->published_html, true), 'published_html' => $this->mark($signature->published_html, true)])->saveQuietly();
        $template->forceFill(['html' => $this->mark($template->published_html, false), 'published_html' => $this->mark($template->published_html, false), 'published_signature_document_id' => $signature->id])->saveQuietly();
        app()->forgetScopedInstances();
        $before = MailDocument::query()->orderBy('id')->get()->toArray();
        $text = 'Literal application bytes &amp; RT-SIGNATURE-VERSION:0123456789abcdef.';
        $html = (new SystemMailCssInliner)->convert(EmailTemplateBuilder::buildSystemMailHtml(new HtmlString('<p>'.$text.'</p>')));
        $this->assertCompact($html, true);
        self::assertStringContainsString($text, $html);
        self::assertSame(3, $this->xpath($html)->query('//table[contains(concat(" ",normalize-space(@class)," ")," rt-v17-column ")]')->length);
        $mail = (new Email)->from('system@example.test')->to('self@example.test')->html($html);
        self::assertSame(0, (new SystemMailInlineImageEmbedder)->embed($mail));
        self::assertCount(0, $mail->getAttachments());
        self::assertSame($before, MailDocument::query()->orderBy('id')->get()->toArray());
        Http::assertNothingSent();
    }

    #[DataProvider('longProfileValues')]
    public function test_long_profile_values_are_complete_and_mobile_budget_remains_bounded(string $first, string $last, string $role): void
    {
        [, $user] = $this->source(true);
        UserProfile::query()->where('user_id', $user->id)->firstOrFail()->forceFill(['first_name' => $first, 'last_name' => $last, 'position' => $role])->saveQuietly();
        app()->forgetScopedInstances();
        $service = app(OutlookAddinPayloadService::class);
        $canonical = $service->forUser($user->fresh());
        $canonical['version']['personal'] = substr($service->sourceFingerprint($user->fresh()), 0, 16);
        $before = MailDocument::query()->orderBy('id')->get()->toArray();
        $wire = OutlookStaticLinkedTestProfiles::payload(OutlookClientPresentation::payload(OutlookMobileSignature::payload($canonical)), $user->fresh());
        self::assertStringContainsString($first.' '.$last, $wire['signature']['html']);
        self::assertStringContainsString($role, $wire['signature']['html']);
        self::assertStringNotContainsString(' rt-v17-raised', $wire['signature']['html']);
        foreach ($wire['templates'] as $entry) {
            foreach (['signature', 'mobileComposeHtml'] as $field) {
                $html = $field === 'signature' ? $entry[$field]['html'] : ($entry[$field] ?? null);
                if (is_string($html)) {
                    $this->assertCompact($html, false);
                    $this->assertBudget($html, 30000);
                }
            }
        }
        self::assertSame($before, MailDocument::query()->orderBy('id')->get()->toArray());
        Http::assertNothingSent();
    }

    public static function longProfileValues(): array
    {
        return [
            'Unicode name' => ['Mara Änne Christina', 'von Muster-Lange und Beispielhausen', 'Disposition, internationale Verkehre'],
            'long ASCII name and role' => ['Alexander Christian', 'von Muster-Lange und Beispielhausen', 'Internationaler Fernverkehr und Disposition'],
            'role letter spacing cannot wrap into an overlap' => ['Mara', 'Muster', 'MMMMMMMMMMMMMMMMMMMM'],
        ];
    }

    #[DataProvider('invalidCompactChanges')]
    public function test_inverse_rejects_unowned_media_or_changed_compact_geometry(string $before, string $after): void
    {
        [$canonical, $user] = $this->source(true);
        $html = OutlookStaticLinkedTestProfiles::payload(OutlookClientPresentation::payload($canonical), $user)['signature']['html'];
        self::assertStringContainsString($before, $html);
        $this->expectException(RuntimeException::class);
        Compact::canonicalizeToV16(str_replace($before, $after, $html));
    }

    public static function invalidCompactChanges(): array
    {
        return [
            'variant' => [' rt-v17-compact', ' rt-v17-other'],
            'legal gutter' => ['padding:8px 40px 8px;', 'padding:8px 41px 8px;'],
            'legal background' => ['background:#dde1e6', 'background:#000000'],
            'underlap' => ['margin-top:0!important;', 'margin-top:-8px!important;'],
            'underlap extra' => ['margin-top:0!important;', 'margin-top:0!important;position:relative;top:-999px;'],
            'raised flag' => [' rt-v17-raised', ''],
            'direct responsive width' => ['width:280px!important;', 'width:281px!important;'],
            'company responsive width' => ['width:210px!important;', 'width:211px!important;'],
            'contact row spacing' => ['padding-bottom:3px!important;', 'padding-bottom:999px!important;'],
            'card separator' => ['border-top:1px solid #ffffff;', 'border-top:1px solid #000000;'],
            'unadmitted animation' => ['zug-dampf-outlook-brand-v2-', 'zug-dampf-outlook-brand-v9-'],
            'unknown WhatsApp destination' => ['https://wa.me/491601881848', 'https://outside.invalid/'],
            'caption width' => ['width="215"', 'width="216"'],
            'foreign media origin' => ['https://mail.example.test/m/', 'https://outside.invalid/m/'],
        ];
    }

    public function test_unknown_historical_or_quoted_html_never_gets_an_unbound_compact_projection(): void
    {
        foreach (['<p>Authored content.</p>', '<blockquote>Unmanaged quote.</blockquote>', '<p>outlook-desktop-flow-v17</p>'] as $html) {
            self::assertSame($html, Compact::document($html));
        }
        [$canonical, $user] = $this->source();
        $html = OutlookStaticLinkedTestProfiles::payload(OutlookClientPresentation::payload($canonical), $user)['signature']['html'];
        $prior = Compact::canonicalizeToV16($html);
        $this->expectException(RuntimeException::class);
        Compact::document('<blockquote>'.$prior.'</blockquote>');
    }

    private function assertCompact(string $html, bool $system): void
    {
        self::assertDoesNotMatchRegularExpression('/\b(?:cid|data):|data-rt-linked|data-rt-static|data-rt-system-mail-inline-assets/i', $html);
        self::assertStringContainsString('outlook-desktop-flow-v17', $html);
        self::assertStringContainsString('rt-v17-compact', $html);
        self::assertStringNotContainsString('rt-v17-mobile-dispatch', $html);
        $x = $this->xpath($html);
        foreach ($x->query('//*[@style]') as $element) {
            self::assertDoesNotMatchRegularExpression('/\b(?:min|max|clamp|calc)\s*\(/i', $element->getAttribute('style'));
        }
        $cards = $x->query('//table[contains(concat(" ",normalize-space(@class)," ")," rt-v17-dispatch-block ")]');
        self::assertCount(1, $cards);
        self::assertSame('', $cards->item(0)->getAttribute('background'));
        self::assertStringNotContainsString('background-image', $cards->item(0)->getAttribute('style'));
        self::assertStringContainsString('background-color:#e90032', str_replace(' ', '', $cards->item(0)->getAttribute('style')));
        self::assertCount(4, $x->query('.//a', $cards->item(0)));
        self::assertCount(4, $x->query('.//img', $cards->item(0)));
        self::assertCount(0, $x->query('//*[contains(concat(" ",normalize-space(@class)," ")," rt-v17-social ")]'));
        $whatsapp = $x->query('.//a[contains(concat(" ",normalize-space(@class)," ")," rt-v17-dispatch-whatsapp ")]', $cards->item(0));
        self::assertCount(1, $whatsapp);
        self::assertSame('https://wa.me/491601881848', $whatsapp->item(0)->getAttribute('href'));
        $caption = $x->query('.//img[contains(concat(" ",normalize-space(@class)," ")," rt-v17-dispatch-caption ")]', $cards->item(0));
        self::assertCount(1, $caption);
        self::assertSame('215', $caption->item(0)->getAttribute('width'));
        self::assertSame('50', $caption->item(0)->getAttribute('height'));
        foreach ($x->query('.//table[contains(concat(" ",normalize-space(@class)," ")," rt-v17-dispatch-actions ")]//img', $cards->item(0)) as $action) {
            self::assertSame('24', $action->getAttribute('width'));
            self::assertSame('24', $action->getAttribute('height'));
        }
        self::assertSame('247', $x->query('//table[contains(concat(" ",normalize-space(@class)," ")," rt-v17-dispatch ")]')->item(0)->getAttribute('width'));
        self::assertSame('210', $x->query('//table[contains(concat(" ",normalize-space(@class)," ")," rt-v17-company ")]')->item(0)->getAttribute('width'));
        $trains = $x->query('//img[contains(concat(" ",normalize-space(@class)," ")," rt-v17-train-image ")]');
        self::assertCount(1, $trains);
        self::assertStringContainsString('/mail-assets/zug-dampf-outlook-brand-v2-', $trains->item(0)->getAttribute('src'));
        $natural = getimagesize(public_path(ltrim(parse_url($trains->item(0)->getAttribute('src'), PHP_URL_PATH), '/')));
        self::assertSame([695, 151], [$natural[0], $natural[1]], 'The improved badge must retain the full smoke canvas.');
        self::assertSame('529', $trains->item(0)->getAttribute('width'));
        self::assertSame('115', $trains->item(0)->getAttribute('height'));
        self::assertStringContainsString('height:auto', str_replace(' ', '', $trains->item(0)->getAttribute('style')));
        self::assertStringContainsString('max-width:100%', str_replace(' ', '', $trains->item(0)->getAttribute('style')));
        self::assertSame(1, preg_match_all('~<img\b[^>]*src="[^\"]+\.gif"~i', $html));
        self::assertGreaterThanOrEqual($system ? 7 : 9, $x->query('//img')->length);
        foreach ($x->query('.//img', $cards->item(0)) as $image) {
            $route = parse_url($image->getAttribute('src'), PHP_URL_PATH);
            self::assertMatchesRegularExpression('~\A/m/[a-f0-9]{10}\.png\z~', $route);
            $raster = imagecreatefrompng(public_path(ltrim($route, '/')));
            self::assertNotFalse($raster);
            self::assertGreaterThanOrEqual(120, imagecolorsforindex($raster, imagecolorat($raster, 0, 0))['alpha'], 'White card media must have transparent corners, not a baked-in red matte.');
            imagedestroy($raster);
        }
    }

    private function assertBudget(string $html, int $limit): void
    {
        self::assertLessThanOrEqual($limit, intdiv(strlen(mb_convert_encoding($html, 'UTF-16LE', 'UTF-8')), 2) + ($limit === 30000 ? 168 : 0));
        preg_match_all('~<style\b[^>]*>(.*?)</style\s*>~is', $html, $styles);
        self::assertLessThanOrEqual($limit === 30000 ? 12288 : 24576, array_sum(array_map('strlen', $styles[1])));
    }

    private function source(bool $twoDirectRows = false): array
    {
        $fixture = new OutlookV30ReferencePresentationTest('test_single_dispatch_card_keeps_accessible_actions_even_when_office_loses_all_style_blocks');
        [$canonical, $user] = (new ReflectionMethod($fixture, 'source'))->invoke($fixture);
        if ($twoDirectRows) {
            UserProfile::query()->where('user_id', $user->id)->firstOrFail()->forceFill(['phone' => ''])->saveQuietly();
            app()->forgetScopedInstances();
            $service = app(OutlookAddinPayloadService::class);
            $canonical = $service->forUser($user->fresh());
            $canonical['version']['personal'] = substr($service->sourceFingerprint($user->fresh()), 0, 16);
        }

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
