<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\MailDocument;
use App\Support\OutlookAddin\OutlookClientPresentation;
use App\Support\OutlookAddin\OutlookComposeFrame;
use App\Support\OutlookAddin\OutlookMobileSignature;
use DOMDocument;
use DOMXPath;
use ReflectionMethod;
use Tests\TestCase;

final class OutlookTemplateMarkInsetTest extends TestCase
{
    public function test_both_real_templates_keep_an_eight_pixel_mark_inset_on_desktop_and_mobile(): void
    {
        $fixture = new OutlookBrandedSignatureIntegrationTest('test_actual_global_paired_desktop_and_mobile_documents_keep_exact_media_and_budgets');
        [$payload] = (new ReflectionMethod($fixture, 'fixture'))->invoke($fixture, true);
        $before = MailDocument::query()->orderBy('id')->get()->toArray();
        $desktop = OutlookClientPresentation::payload($payload);
        $mobile = OutlookClientPresentation::payload(OutlookMobileSignature::payload($payload));
        self::assertCount(2, $desktop['templates']);
        $variants = array_column($desktop['templates'], 'isDefault');
        sort($variants);
        self::assertSame([false, true], $variants);

        foreach ($desktop['templates'] as $index => $template) {
            foreach ([false => $template['combinedComposeHtml'], true => $mobile['templates'][$index]['mobileComposeHtml']] as $isMobile => $html) {
                $dom = new DOMDocument;
                $previous = libxml_use_internal_errors(true);
                try {
                    self::assertTrue($dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET));
                } finally {
                    libxml_clear_errors();
                    libxml_use_internal_errors($previous);
                }
                $xpath = new DOMXPath($dom);
                $preheaders = $xpath->query('//div[contains(concat(" ",normalize-space(@class)," ")," rt-outlook-template ") or contains(concat(" ",normalize-space(@class)," ")," x8 ")]/div[not(*)]');
                self::assertSame(1, $preheaders->length);
                self::assertMatchesRegularExpression('/\Adisplay:none;font-size:0;line-height:0;color:transparent;mso-hide:all;?\z/', $preheaders->item(0)->getAttribute('style'));
                self::assertStringStartsWith('Ihre Nachricht von ', $preheaders->item(0)->textContent);
                $pads = $xpath->query('//td[contains(concat(" ",normalize-space(@class)," ")," rt-native-template-pad ") or contains(concat(" ",normalize-space(@class)," ")," x6 ")]');
                self::assertSame(2, $pads->length);
                self::assertMatchesRegularExpression('/(?:^|;)padding-top:8px!important(?:;|$)/', $pads->item(0)->getAttribute('style'));
                self::assertDoesNotMatchRegularExpression('/(?:^|;)padding-top:8px!important(?:;|$)/', $pads->item(1)->getAttribute('style'));
                self::assertSame(1, $xpath->query('.//img[@width="44" and @height="44"]', $pads->item(0))->length);
                foreach ($pads as $pad) {
                    self::assertMatchesRegularExpression('/(?:^|;)padding-left:'.($isMobile ? '18px' : '0').'(?:!important)?(?:;|$)/', $pad->getAttribute('style'));
                    self::assertMatchesRegularExpression('/(?:^|;)padding-right:'.($isMobile ? '18px' : '0').'(?:!important)?(?:;|$)/', $pad->getAttribute('style'));
                }
                if ($isMobile) {
                    self::assertLessThanOrEqual(30000, intdiv(strlen(mb_convert_encoding($html, 'UTF-16LE', 'UTF-8')), 2));
                } else {
                    self::assertSame($html, OutlookComposeFrame::recipientTables($html), 'Final template gutters and mark inset remain idempotent.');
                }
            }
        }
        self::assertSame($payload['signature']['html'], OutlookComposeFrame::recipientTables($payload['signature']['html']), 'A standalone signature has no template mark inset.');
        self::assertSame($before, MailDocument::query()->orderBy('id')->get()->toArray(), 'The authoring documents remain unchanged.');
    }
}
