<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\OutlookAddinBootstrapController;
use App\Support\Mail\OutlookSignatureInlineStyle;
use App\Support\Mail\SignatureTableOverlapDelivery;
use App\Support\Mail\TrustedOutlookSignatureCss;
use App\Support\OutlookAddin\OutlookMobileSignature;
use App\Support\OutlookAddin\OutlookNativeMetadataPlacement;
use App\Support\OutlookAddin\OutlookTrainBottomOverlay;
use DOMDocument;
use DOMXPath;
use ReflectionMethod;
use Tests\TestCase;

class OutlookMobileSignatureTest extends TestCase
{
    public function test_mobile_ledger_contact_groups_stack_structurally_with_css_removed(): void
    {
        $payload = $this->fixture();
        $original = $payload;
        $mobile = OutlookMobileSignature::payload($payload);
        $this->assertSame($original, $payload, 'The cached desktop payload must not be mutated.');
        $this->assertSame($original['signature']['media'], $mobile['signature']['media']);
        $this->assertSame($original['templates'][0]['composeHtml'], $mobile['templates'][0]['composeHtml']);
        $this->assertSame($original['automaticTemplateId'], $mobile['automaticTemplateId']);
        $this->assertSame($original['binding'], $mobile['binding']);
        $this->assertSame($original['version']['personal'], $mobile['version']['personal']);
        $this->assertNotSame($original['version']['signature'], $mobile['version']['signature']);
        $this->assertSame($mobile['version']['signature'], $mobile['templates'][0]['signatureVersion']);
        $this->assertSame($mobile, OutlookMobileSignature::payload($original));
        $this->assertSame(2, substr_count($mobile['signature']['html'], 'RT-SIGNATURE-VERSION:'.$mobile['version']['signature']));
        $this->assertSame(2, substr_count($mobile['templates'][0]['signature']['html'], 'RT-SIGNATURE-VERSION:'.$mobile['templates'][0]['signatureVersion']));

        $before = $this->xpath($original['signature']['html']);
        $after = $this->xpath($mobile['signature']['html']);
        $this->assertSame(
            $this->images($before), $this->images($after), 'Real image sources, alt text and train geometry survive.',
        );
        $this->assertSame(
            $this->links($before), $this->links($after), 'Contact links survive without duplication.',
        );
        $this->assertSame(
            $before->query('//table[contains(@class,"rt-v27-anchor")]')->item(0)->getAttribute('style'),
            $after->query('//table[contains(@class,"rt-v27-anchor")]')->item(0)->getAttribute('style'),
        );
        $this->assertStringContainsString('data-rt-outlook-mobile-css="1"', $mobile['signature']['html']);
        $this->assertStringContainsString('.m28tfc09.rtm.rtm .m', $mobile['signature']['html']);
        $this->assertStringContainsString('.rts0123456789vm{display:none!important', $mobile['signature']['html']);
        $this->assertStringContainsString('font-size:13px!important', $mobile['signature']['html']);
        $this->assertStringNotContainsString('background-image:', $mobile['signature']['html']);
        $this->assertLessThanOrEqual(30000, intdiv(strlen(mb_convert_encoding($mobile['signature']['html'], 'UTF-16LE', 'UTF-8')), 2));
        $withoutCss = preg_replace('~<style\b[^>]*>.*?</style>~is', '', $mobile['signature']['html']);
        $withoutCss = preg_replace('~\s(?:style|data-[\w-]+)="[^"]*"~', '', $withoutCss);
        $fallback = $this->xpath($withoutCss);
        foreach (['rt-ledger-brand', 'rt-ledger-contacts', 'rt-ledger-direct', 'rt-ledger-company'] as $class) {
            $cell = $fallback->query('//td[contains(concat(" ",@class," ")," '.$class.' ")]')->item(0);
            $this->assertSame('100%', $cell->getAttribute('width'));
            $this->assertSame(1, $fallback->query('./td', $cell->parentNode)->length);
        }
        $this->assertSame($this->links($after), $this->links($fallback));
        $this->assertSame(1, substr_count($mobile['signature']['html'], 'Very long example employee'));
        $this->assertSame(1, $after->query('//a[@href="mailto:long-professional-address-for-example@example.test"]')->length);
    }

    public function test_non_ledger_signatures_are_not_rewritten(): void
    {
        $payload = $this->fixture();
        $payload['signature'] = ['html' => '<p>Other published layout</p>', 'media' => []];
        unset($payload['templates'][0]['signature'], $payload['templates'][0]['signatureVersion']);
        $this->assertSame($payload, OutlookMobileSignature::payload($payload));
        $otherLedger = $this->fixture();
        $otherLedger['signature']['html'] = str_replace('data-rt-artifact-version="v27"', 'data-rt-artifact-version="v30"', $otherLedger['signature']['html']);
        unset($otherLedger['templates'][0]['signature'], $otherLedger['templates'][0]['signatureVersion']);
        $this->assertSame($otherLedger, OutlookMobileSignature::payload($otherLedger));
    }

    public function test_delivery_train_does_not_get_legacy_overlap_gap_and_logo_has_html_dimensions(): void
    {
        $payload = $this->fixture();
        $payload['signature']['html'] = str_replace('class="rt-sign-stage"', 'class="rt-sign-stage rt-delivery-train"', $payload['signature']['html']);
        unset($payload['templates'][0]['signature'], $payload['templates'][0]['signatureVersion']);
        $mobile = OutlookMobileSignature::payload($payload);
        $xpath = $this->xpath($mobile['signature']['html']);
        $cell = $xpath->query('//td[contains(@class,"rt-sign-ledger-content")]')->item(0);
        $logo = $xpath->query('//img[contains(@class,"rt-logo")]')->item(0);
        $this->assertStringContainsString('padding:20px 18px 10px', $cell->getAttribute('style'));
        $this->assertSame('175', $logo->getAttribute('width'));
        $this->assertSame('30', $logo->getAttribute('height'));
    }

    public function test_mobile_contacts_are_not_overconstrained_and_have_css_free_icon_dimensions(): void
    {
        $payload = $this->fixture();
        $dom = $this->xpath($payload['signature']['html'])->document;
        $xpath = new DOMXPath($dom);
        foreach ($xpath->query('//td[contains(@class,"rt-contact-text")]') as $cell) {
            $cell->setAttribute('width', '100%');
        }
        foreach ($xpath->query('//td[contains(@class,"rt-contact-icon")]/img') as $image) {
            $image->setAttribute('width', '44');
            $image->removeAttribute('height');
        }
        $payload['signature']['html'] = $dom->saveHTML();
        unset($payload['templates'][0]['signature'], $payload['templates'][0]['signatureVersion']);
        $mobile = OutlookMobileSignature::payload($payload);
        $internalOnly = preg_replace('~\sstyle="[^"]*"~', '', $mobile['signature']['html']);
        $xpath = $this->xpath($internalOnly);
        foreach ($xpath->query('//td[contains(@class,"rt-contact-text")]') as $cell) {
            $this->assertFalse($cell->hasAttribute('width'), 'Text must not consume 100% in addition to its icon.');
        }
        $icons = $xpath->query('//td[contains(@class,"rt-contact-icon")]/img');
        $this->assertGreaterThan(0, $icons->length);
        foreach ($icons as $image) {
            $this->assertSame('17', $image->getAttribute('width'));
            $this->assertSame('17', $image->getAttribute('height'));
            $this->assertSame('17', $image->parentNode->getAttribute('width'));
            $this->assertSame('cid:icon.png', $image->getAttribute('src'));
        }
        $this->assertStringContainsString('width:17px!important;height:17px!important', $internalOnly);
    }

    public function test_mobile_version_marker_has_internal_css_even_outside_the_visual_scope(): void
    {
        $mobile = OutlookMobileSignature::payload($this->fixture());
        $internalOnly = preg_replace('~\s(?:style|data-[\w-]+)="[^"]*"~', '', $mobile['signature']['html']);
        $xpath = $this->xpath($internalOnly);
        $markers = $xpath->query('//span[starts-with(text(),"RT-SIGNATURE-VERSION:")]');
        $this->assertSame(1, $markers->length);
        $marker = $markers->item(0);
        $this->assertSame('rts0123456789vm', $marker->getAttribute('class'));
        $this->assertSame(0, $xpath->query('ancestor::div[contains(@class,"rt-outlook-signature")]', $marker)->length);
        $this->assertStringContainsString('.rts0123456789vm{display:none!important;mso-hide:all!important;font-size:0!important;line-height:0!important;}', $internalOnly);
    }

    public function test_already_stacked_delivery_groups_are_accepted_without_duplicate_rows(): void
    {
        $dom = new DOMDocument;
        $dom->loadHTML('<table><tr><td class="first">A</td></tr><tr><td class="second">B</td></tr></table>');
        $xpath = new DOMXPath($dom);
        $table = $xpath->query('//table')->item(0);
        $method = new ReflectionMethod(OutlookMobileSignature::class, 'stack');
        $method->invoke(null, $dom, $xpath, $table, ['first', 'second']);
        $this->assertSame(2, $xpath->query('./tr|./tbody/tr', $table)->length);
        $this->assertSame('100%', $table->getAttribute('width'));
        $this->assertSame('AB', $table->textContent);
        $xpath->query('//td[contains(@class,"second")]')->item(0)->setAttribute('class', 'foreign');
        $this->expectException(\RuntimeException::class);
        $method->invoke(null, $dom, $xpath, $table, ['first', 'second']);
    }

    public function test_actual_shared_delivery_projection_survives_mobile_and_paired_internal_css_transport(): void
    {
        $payload = $this->physicalFixture();
        $document = $payload['signature'];
        $desktop = $document['html'];
        $mobile = OutlookMobileSignature::payload($payload);
        OutlookTrainBottomOverlay::assertRuntime($mobile['signature']['html']);
        SignatureTableOverlapDelivery::assertRuntime(OutlookTrainBottomOverlay::restore($mobile['signature']['html']));
        $this->assertSame($mobile['signature'], $mobile['templates'][0]['signature']);
        $this->assertSame($document['media'], $mobile['signature']['media']);
        $this->assertStringContainsString(OutlookSignatureInlineStyle::ATTRIBUTE, $desktop);
        $this->assertStringNotContainsString(OutlookSignatureInlineStyle::ATTRIBUTE, $mobile['signature']['html']);
        $this->assertStringContainsString('data-rt-outlook-mobile-css="1"', $mobile['signature']['html']);
        $internalOnly = preg_replace('~\sstyle="[^"]*"~', '', $mobile['signature']['html']);
        $xpath = $this->xpath($internalOnly);
        $train = $xpath->query('//img[contains(concat(" ",@class," ")," rt-delivery-train ")]')->item(0);
        $this->assertSame((string) SignatureTableOverlapDelivery::FALLBACK_WIDTH, $train->getAttribute('width'));
        $this->assertSame((string) SignatureTableOverlapDelivery::FALLBACK_HEIGHT, $train->getAttribute('height'));
        $this->assertLessThanOrEqual(320, (int) $train->getAttribute('width'));
        $this->assertStringNotContainsString('6031.746032%', $internalOnly);
        $this->assertStringNotContainsString('183.796856%', $internalOnly);
        $this->assertStringContainsString('padding:20px 18px 10px!important', $internalOnly);
        $this->assertStringNotContainsString('.rt-dt>tbody{display:table-row!', $internalOnly);
        $this->assertStringNotContainsString('.rt-dr{display:table-cell!', $internalOnly);
        foreach ($xpath->query('//table[contains(@class,"rt-sign-ledger") or contains(@class,"rt-delivery-contacts")]') as $table) {
            $this->assertSame(2, $xpath->query('./tr|./tbody/tr', $table)->length);
            foreach ($xpath->query('./tr|./tbody/tr', $table) as $row) {
                $this->assertSame(1, $xpath->query('./td', $row)->length);
            }
        }
        preg_match_all('~<style\b[^>]*>(.*?)</style>~is', $internalOnly, $styles);
        $this->assertLessThan(12288, array_sum(array_map('strlen', $styles[1])));
        $this->assertLessThanOrEqual(30000, strlen(mb_convert_encoding($internalOnly, 'UTF-16LE', 'UTF-8')) / 2);
        $withoutCss = preg_replace('~<style\b[^>]*>.*?</style>~is', '', $internalOnly);
        $withoutCss = preg_replace('~\sdata-[\w-]+="[^"]*"~', '', $withoutCss);
        $fallback = $this->xpath($withoutCss);
        $train = $fallback->query('//img[contains(concat(" ",@class," ")," rt-delivery-train ")]')->item(0);
        $this->assertSame('cid:train.gif', $train->getAttribute('src'));
        $this->assertSame((string) SignatureTableOverlapDelivery::FALLBACK_WIDTH, $train->getAttribute('width'));
        foreach (['rt-ledger-brand', 'rt-ledger-contacts', 'rt-ledger-direct', 'rt-ledger-company'] as $class) {
            $cell = $fallback->query('//td[contains(concat(" ",@class," ")," '.$class.' ")]')->item(0);
            $this->assertSame(1, $fallback->query('./td', $cell->parentNode)->length);
            $this->assertSame('100%', $cell->getAttribute('width'));
        }
    }

    public function test_canonical_projection_owns_mobile_typography_without_touching_author_css_or_geometry(): void
    {
        $payload = $this->physicalFixture();
        $author = '<style data-rt-mail-document-css="signature">.rts0123456789 .rt-contact-text{color:#123456!important;}</style>';
        $payload['signature']['html'] = $author.$payload['signature']['html'];
        unset($payload['templates'][0]['signature'], $payload['templates'][0]['signatureVersion']);
        $original = $payload;
        $mobile = OutlookMobileSignature::payload($payload);
        $this->assertSame($original, $payload);
        $this->assertSame($original['signature']['media'], $mobile['signature']['media']);
        $this->assertStringContainsString($author, $mobile['signature']['html']);
        $before = $this->xpath($payload['signature']['html']);
        $after = $this->xpath($mobile['signature']['html']);
        $canonical = $after->query('//style[@data-rt-outlook-signature-css="1"]')->item(0)->textContent;
        $this->assertLessThan(strlen($before->query('//style[@data-rt-outlook-signature-css="1"]')->item(0)->textContent), strlen($canonical));
        $this->assertStringNotContainsString('.rts0123456789 .rt-contact td.rt-contact-text{font-size:11px!important;line-height:15px!important;}', $canonical);
        $this->assertStringNotContainsString('.rts0123456789 .rt-sign-name{font-size:17px!important;line-height:21px!important;}', $canonical);
        $this->assertStringContainsString('.rts0123456789 .rt-sign-content-frame{border-collapse:collapse!important;}', $canonical);
        $this->assertStringContainsString('.rts0123456789 .rt-delivery-train-mso{', $canonical);
        $this->assertStringContainsString('.rts0123456789 img.rt-logo{width:138px!important;}', $canonical, 'Mixed modern/MSO wordmarks remain covered by the canonical rule.');
        $this->assertStringContainsString('.m28tfc09.rtm.rtm .m', $mobile['signature']['html']);
        $this->assertStringContainsString('font-size:13px!important;line-height:20px!important;', $mobile['signature']['html']);
        $this->assertStringContainsString('.m28tfc09.rtm .rt-native-train-overlay', $mobile['signature']['html']);
        $this->assertStringNotContainsString('.m28tfc09.rtm.rtm .rt-native-train-overlay', $mobile['signature']['html']);
        $this->assertSame($this->images($before), $this->images($after));
        $this->assertSame($this->links($before), $this->links($after));
    }

    public function test_canonical_projection_rejects_modified_runtime_before_existing_four_rule_pruning(): void
    {
        $payload = $this->physicalFixture();
        $payload['signature']['html'] = str_replace('/* RT_OUTLOOK_SIGNATURE_RUNTIME_START */', '/* RT_OUTLOOK_SIGNATURE_RUNTIME_START */.rts0123456789 .rt-contact{color:red}', $payload['signature']['html']);
        unset($payload['templates'][0]['signature'], $payload['templates'][0]['signatureVersion']);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('vollstaendigen kanonischen Server-CSS');
        OutlookMobileSignature::payload($payload);
    }

    public function test_runtime_coverage_is_per_node_strict_specificity_and_exact_property_only(): void
    {
        $xpath = $this->xpath('<div class="rts0123456789"><table class="rt-contact"><tr><td class="rt-contact-text a b m1">A</td><td class="rt-contact-text m2">B</td></tr></table></div>');
        $scope = $xpath->query('//div')->item(0);
        $coverage = [
            'm1' => [['node' => $xpath->query('//td')->item(0), 'properties' => ['font-size' => true, 'line-height' => true, 'padding' => true]]],
            'm2' => [['node' => $xpath->query('//td')->item(1), 'properties' => ['font-size' => true, 'line-height' => true]]],
        ];
        $method = new ReflectionMethod(OutlookMobileSignature::class, 'projectCoveredRuntime');
        $covered = '.rts0123456789 .rt-contact td.rt-contact-text{font-size:11px!important;line-height:15px!important;}';
        $missing = '.rts0123456789 .rt-contact-text{padding:0!important;}';
        $shorthand = '.rts0123456789 .a{padding-left:0!important;}';
        $equalSpecificity = '.rts0123456789 .rt-contact .rt-contact-text.a{font-size:11px!important;}';
        $zero = '.rts0123456789 .absent{font-size:11px!important;}';
        $unsupported = '.rts0123456789 .rt-contact>tbody td{font-size:11px!important;}';
        $source = '/* bound */@media only screen and (max-width: 480px){'.$covered.'}'.$missing.$shorthand.$equalSpecificity.$zero.$unsupported;
        $this->assertSame('/* bound */'.$missing.$shorthand.$equalSpecificity.$zero.$unsupported, $method->invoke(null, $source, $xpath, $scope, 'rts0123456789', $coverage, []));
        // One unmirrored descendant, even if its ancestor is covered, keeps all.
        unset($coverage['m2']);
        $this->assertSame($covered, $method->invoke(null, $covered, $xpath, $scope, 'rts0123456789', $coverage, []));
        $this->assertSame($covered, $method->invoke(null, $covered, $xpath, $scope, 'rts0123456789', [], []));
    }

    public function test_conditional_comment_shared_classes_block_modern_only_coverage(): void
    {
        $xpath = $this->xpath('<div id="rt-mobile-root"><div class="rts0123456789"><img class="rt-logo m1"><!--[if mso]><img class="rt&#45;logo" width="175"><![endif]--></div></div>');
        $scope = $xpath->query('//div[@class="rts0123456789"]')->item(0);
        $root = $xpath->query('//*[@id="rt-mobile-root"]')->item(0);
        $conditional = (new ReflectionMethod(OutlookMobileSignature::class, 'conditionalElements'))->invoke(null, $xpath, $root);
        $this->assertSame([['tag' => 'img', 'classes' => ['rt-logo']]], $conditional);
        $coverage = ['m1' => [['node' => $xpath->query('//img')->item(0), 'properties' => ['width' => true]]]];
        $method = new ReflectionMethod(OutlookMobileSignature::class, 'projectCoveredRuntime');
        $css = '.rts0123456789 img.rt-logo{width:138px!important;}';
        $this->assertSame('', $method->invoke(null, $css, $xpath, $scope, 'rts0123456789', $coverage, []));
        $this->assertSame($css, $method->invoke(null, $css, $xpath, $scope, 'rts0123456789', $coverage, $conditional));
    }

    public function test_coverage_rejects_invalid_or_complex_values_and_preserves_ordered_font_fallbacks(): void
    {
        $coverage = new ReflectionMethod(OutlookMobileSignature::class, 'coveredProperties');
        $this->assertSame(['line-height' => true], $coverage->invoke(null, 'font-size:bogus;line-height:20px'));
        $this->assertSame([], $coverage->invoke(null, 'font-size:bogus;font-size:13px;width:none;font-family:"Odd;Family";padding:calc(1px + 2px)'));
        $this->assertSame(['font-size' => true, 'font-family' => true], $coverage->invoke(null, 'font-size:10px;font-size:13px;font-family:"Arial",sans-serif'));
        $important = new ReflectionMethod(OutlookMobileSignature::class, 'importantDeclarations');
        $this->assertSame('font-family:"Odd;Family"!important;font-size:10px!important;font-size:13px!important;', $important->invoke(null, 'font-family:"Odd;Family";font-size:10px;font-size:13px'));
        $this->assertSame('font-family:"Odd !important;Family"!important;color:red!important;', $important->invoke(null, 'font-family:"Odd !important;Family";color:red !important'));
        $this->assertSame([], $coverage->invoke(null, 'max-width:auto;min-height:auto'));
        $xpath = $this->xpath('<div class="rts0123456789"><p class="name m1">Name</p></div>');
        $css = '.rts0123456789 .name{font-size:11px!important;}';
        $this->assertSame($css, (new ReflectionMethod(OutlookMobileSignature::class, 'projectCoveredRuntime'))->invoke(null, $css, $xpath, $xpath->query('//div')->item(0), 'rts0123456789', ['m1' => [['node' => $xpath->query('//p')->item(0), 'properties' => $coverage->invoke(null, 'font-size:bogus')]]], []));
    }

    public function test_generated_mobile_classes_do_not_reuse_authored_aliases(): void
    {
        $payload = $this->fixture();
        $payload['signature']['html'] = str_replace('class="rt-sign-name"', 'class="rt-sign-name m1"', $payload['signature']['html']);
        unset($payload['templates'][0]['signature'], $payload['templates'][0]['signatureVersion']);
        $mobile = OutlookMobileSignature::payload($payload);
        $this->assertStringNotContainsString('.m28tfc09.rtm.rtm .m1{', $mobile['signature']['html']);
        $this->assertSame(1, $this->xpath($mobile['signature']['html'])->query('//*[contains(concat(" ",@class," ")," m1 ")]')->length);
    }

    public function test_mobile_profile_is_applied_after_cache_and_identity_checks_and_before_etag(): void
    {
        $source = file_get_contents(app_path('Http/Controllers/Api/OutlookAddinBootstrapController.php'));
        $this->assertLessThan(strpos($source, 'OutlookMobileSignature::payload'), strpos($source, '$identityResolver->resolve'));
        $this->assertLessThan(strpos($source, 'OutlookMobileSignature::payload'), strpos($source, '$snapshots->currentForUser'));
        $this->assertLessThan(strpos($source, '$encoded ='), strpos($source, 'OutlookMobileSignature::payload'));
        $this->assertStringContainsString('=== OutlookMobileSignature::PROFILE', $source);
        $this->assertStringContainsString('X-RailTime-Outlook-Profile', $source);
        $headers = (new ReflectionMethod(OutlookAddinBootstrapController::class, 'headers'))
            ->invoke(new OutlookAddinBootstrapController);
        $this->assertStringContainsString('X-RailTime-Outlook-Profile', $headers['Vary']);
        $this->assertSame('private, no-store, max-age=0', $headers['Cache-Control']);
    }

    public function test_unexpected_ledger_shape_and_transport_budget_fail_closed(): void
    {
        foreach ([
            str_replace('rt-ledger-direct', 'unexpected-direct', $this->fixture()['signature']['html']),
            str_replace('Very long example employee', str_repeat('X', 31000), $this->fixture()['signature']['html']),
        ] as $invalid) {
            $payload = $this->fixture();
            $payload['signature']['html'] = $invalid;
            try {
                OutlookMobileSignature::payload($payload);
                $this->fail('Invalid/oversized mobile signature must not be emitted.');
            } catch (\RuntimeException $error) {
                $this->assertNotEmpty($error->getMessage());
            }
        }
    }

    public function test_css_compaction_preserves_scope_specificity_and_different_fallback_values(): void
    {
        $method = new ReflectionMethod(OutlookMobileSignature::class, 'compactRepeatedTypography');
        $this->assertSame(
            'font-size:10px;line-height:normal;font-size:13px;line-height:20px;color:red;color:blue',
            $method->invoke(null, 'font-size:10px;font-size:13px;line-height:20px;line-height:normal;font-size:13px;line-height:20px;color:red;color:blue'),
        );
        $complex = 'font-family:"Odd;line-height:20px;Family";line-height:20px';
        $this->assertSame($complex, $method->invoke(null, $complex));
        $this->assertSame(
            'font-family:"Arial";font-size:13px;line-height:20px',
            $method->invoke(null, 'font-family:"Arial";font-size:13px;line-height:20px;font-size:13px;line-height:20px'),
        );
        $payload = $this->fixture();
        $payload['signature']['html'] = str_replace('rts0123456789', 'rtsffffffffff', $payload['signature']['html']);
        unset($payload['templates'][0]['signature'], $payload['templates'][0]['signatureVersion']);
        $mobile = OutlookMobileSignature::payload($payload);
        $this->assertStringContainsString('.me13wu1of.rtm.rtm .m', $mobile['signature']['html']);
        $this->assertStringContainsString('rt-mobile-ledger rtm me13wu1of', $mobile['signature']['html']);
        $this->assertStringNotContainsString('.rtsffffffffff.rtm .rtm', $mobile['signature']['html']);
    }

    public function fixture(): array
    {
        $rows = file_get_contents(base_path('tests/Fixtures/mail/signature-v27-ledger.html'));
        $rows = preg_replace_callback('/\{\{([A-Z0-9_]+)\}\}/', static function ($match) {
            return match ($match[1]) {
                'VORNAME_NACHNAME' => 'Very long example employee',
                'E_MAIL' => 'long-professional-address-for-example@example.test',
                'LOGO_SRC', 'LOGO_STILL_SRC' => 'cid:logo.gif',
                'TRAIN_SRC' => 'cid:train.gif',
                'FIRMENSTRASSE' => 'Example business avenue 29-31',
                'FIRMEN_PLZ_ORT' => '21423 Example City',
                'FIRMEN_WEBSITE_HREF' => 'https://example.test',
                default => str_contains($match[1], '_SRC') ? 'cid:icon.png' : 'Example',
            };
        }, $rows);
        $html = '<!-- RT-SIGNATURE-VERSION:0123456789abcdef -->'
            .'<span style="display:none">RT-SIGNATURE-VERSION:0123456789abcdef</span>'
            .'<!-- RT-SIGNATURE-MANAGED-V1 -->'
            .TrustedOutlookSignatureCss::style($rows, scopeClass: 'rts0123456789')
            .'<div class="rt-outlook-signature rts0123456789"><table width="100%" cellspacing="0" cellpadding="0"><tbody>'.$rows.'</tbody></table></div>';
        $document = ['html' => $html, 'media' => [
            ['name' => 'train.gif', 'contentId' => 'train.gif', 'base64' => 'synthetic-test-bytes'],
            ['name' => 'logo.gif', 'contentId' => 'logo.gif', 'base64' => 'synthetic-logo-bytes'],
        ]];

        return [
            'signature' => $document,
            'templates' => [[
                'id' => 'published-default', 'isDefault' => true, 'signature' => $document,
                'signatureVersion' => '0123456789abcdef', 'composeHtml' => 'Original native template',
            ]],
            'automaticTemplateId' => 'published-default',
            'version' => ['signature' => '0123456789abcdef', 'personal' => 'desktop-cache-version'],
            'binding' => ['sender' => 'synthetic-test-mailbox'],
        ];
    }

    private function physicalFixture(): array
    {
        $payload = $this->fixture();
        preg_match('~<tbody>(.*)</tbody></table></div>~s', $payload['signature']['html'], $matches);
        $rows = SignatureTableOverlapDelivery::project($matches[1], 'cid:train-still.png');
        $html = TrustedOutlookSignatureCss::style($rows, scopeClass: 'rts0123456789')
            .'<div class="rt-outlook-signature rts0123456789"><table role="presentation" width="100%" cellspacing="0" cellpadding="0"><tbody>'.$rows.'</tbody></table></div>';
        $document = $payload['signature'];
        $document['html'] = OutlookNativeMetadataPlacement::signature(
            OutlookSignatureInlineStyle::apply($html, 'rts0123456789'),
            '<style data-rt-outlook-marker-css="1">.rt-office-metadata{display:none!important;}</style>'
                .'<!-- RT-SIGNATURE-VERSION:0123456789abcdef -->'
                .'<span hidden aria-hidden="true" class="rt-office-metadata" style="display:none">RT-SIGNATURE-VERSION:0123456789abcdef</span>',
        );
        $document['media'][] = ['name' => 'train-still.png', 'contentId' => 'train-still.png', 'base64' => 'synthetic-still'];
        $payload['signature'] = $payload['templates'][0]['signature'] = $document;

        return $payload;
    }

    private function xpath(string $html): DOMXPath
    {
        $dom = new DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$html);

        return new DOMXPath($dom);
    }

    private function images(DOMXPath $xpath): array
    {
        return array_map(static fn ($image) => [
            $image->getAttribute('src'), $image->getAttribute('alt'),
            $image->getAttribute('class') === 'rt-sign-train' ? $image->getAttribute('style') : null,
        ], iterator_to_array($xpath->query('//img')));
    }

    private function links(DOMXPath $xpath): array
    {
        return array_map(static fn ($link) => [$link->getAttribute('href'), $link->textContent], iterator_to_array($xpath->query('//a')));
    }
}
