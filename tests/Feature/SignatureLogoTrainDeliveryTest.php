<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Mail\SignatureTableOverlap;
use App\Support\Mail\SignatureTableOverlapDelivery;
use App\Support\Mail\TrustedEmailCss;
use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

final class SignatureLogoTrainDeliveryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        self::assertSame(':memory:', config('database.connections.sqlite.database'));
        Http::preventStrayRequests();
    }

    public function test_opted_in_train_is_the_final_row_and_person_and_company_share_the_desktop_contact_row(): void
    {
        $source = $this->source();
        $before = $source;
        SignatureTableOverlap::assertValid($source);
        $rendered = str_replace('{{TRAIN_SRC}}', 'cid:train.gif', $source);
        $legacy = SignatureTableOverlapDelivery::project(str_replace(' design-train-logo', '', $rendered), 'cid:train.png');
        $output = SignatureTableOverlapDelivery::project($rendered, 'cid:train.png');
        self::assertSame($before, $source);
        self::assertStringContainsString('width="1%"', $source);
        self::assertStringContainsString('width="6031.746032%"', $source);
        self::assertTrue(SignatureTableOverlapDelivery::usesLogoTrain($source));
        self::assertSame($output, SignatureTableOverlapDelivery::project($output, 'cid:train.png'));
        SignatureTableOverlapDelivery::assertRuntime($output, 'cid:train.gif', 'cid:train.png');
        $xpath = $this->xpath($output);
        $frame = $this->one($xpath, 'rt-sign-content-frame');
        $brand = $this->one($xpath, 'rt-ledger-brand');
        $contacts = $this->one($xpath, 'rt-ledger-contacts');
        $trainRow = $this->one($xpath, 'rt-delivery-train-row');
        $trainCell = $this->one($xpath, 'rt-delivery-train-cell');
        $gap = $this->one($xpath, 'rt-delivery-train-gap');
        $content = $this->one($xpath, 'rt-sign-content');
        $heading = $this->one($xpath, 'rt-delivery-brand-heading');
        self::assertSame(2, $xpath->query('./tr|./tbody/tr', $frame)->length);
        self::assertSame('2', $content->getAttribute('colspan'));
        self::assertSame('50%', $brand->getAttribute('width'));
        self::assertSame('50%', $contacts->getAttribute('width'));
        self::assertSame('th', $brand->tagName);
        self::assertStringContainsString('padding:25px 0 0;', $content->getAttribute('style'));
        self::assertStringNotContainsString('padding:25px 40px', $content->getAttribute('style'));
        self::assertStringContainsString('padding:0 40px 0 0;', $brand->getAttribute('style'));
        self::assertStringContainsString('border-right:1px solid transparent;', $brand->getAttribute('style'));
        self::assertStringContainsString('padding:0 62px 0 0;', $contacts->getAttribute('style'));
        self::assertSame('padding:0 0 0 40px;', $heading->getAttribute('style'));
        self::assertSame('table', $brand->firstElementChild->tagName);
        self::assertTrue($xpath->query('ancestor::table[1]', $heading)->item(0)->isSameNode($brand->firstElementChild));
        self::assertSame(1, $brand->childElementCount);
        self::assertTrue($trainRow->previousElementSibling->isSameNode($content->parentNode));
        self::assertTrue($trainRow->parentNode->lastElementChild->isSameNode($trainRow));
        self::assertSame('50%', $trainCell->getAttribute('width'));
        self::assertSame('50%', $gap->getAttribute('width'));
        self::assertSame('', $gap->textContent);
        self::assertStringContainsString('padding:0 40px 0 0;', $trainCell->getAttribute('style'));
        self::assertStringContainsString('border-right:1px solid transparent;', $trainCell->getAttribute('style'));
        self::assertSame(1, $xpath->query('./img[@class="rt-delivery-train"]', $trainCell)->length);
        $direct = $this->one($xpath, 'rt-ledger-direct');
        $company = $this->one($xpath, 'rt-ledger-company');
        self::assertSame('50%', $direct->getAttribute('width'));
        self::assertSame('50%', $company->getAttribute('width'));
        self::assertTrue($direct->nextElementSibling->isSameNode($company));
        self::assertSame(2, $xpath->query('./td', $direct->parentNode)->length);
        self::assertSame(1, $xpath->query('.//img[contains(concat(" ",@class," ")," rt-logo ")]', $brand)->length);
        self::assertSame(1, $xpath->query('.//*[contains(concat(" ",@class," ")," rt-person-kopf ")]', $brand)->length);
        self::assertSame(1, $xpath->query('.//img[contains(concat(" ",@class," ")," rt-logo ")]', $heading)->length);
        self::assertSame(1, $xpath->query('.//*[contains(concat(" ",@class," ")," rt-person-kopf ")]', $heading)->length);
        $image = $this->one($xpath, 'rt-delivery-train');
        self::assertSame('300', $image->getAttribute('width'));
        self::assertSame('38', $image->getAttribute('height'));
        self::assertSame('display:block;width:100%;max-width:100%;height:auto;margin:0;border:0;vertical-align:bottom;', $image->getAttribute('style'));
        self::assertStringContainsString('<!--[if !mso]><!-->', $output);
        self::assertStringContainsString('<!--[if mso]><img class="rt-delivery-train-mso" src="cid:train.png" width="220" height="28"', $output);
        self::assertSame($this->links($legacy), $this->links($output));
        self::assertSame($this->imageSources($legacy), $this->imageSources($output));
        self::assertSame($this->plainText($legacy), $this->plainText($output));
        self::assertStringNotContainsString('position:absolute', $output);
        self::assertStringNotContainsString('background-image:', $output);
        Http::assertNothingSent();
    }

    public function test_opt_in_keeps_the_train_at_the_outer_edge_and_the_heading_inset_while_default_gutters_stay_intact(): void
    {
        $source = $this->source();
        $legacy = SignatureTableOverlapDelivery::css(str_replace(' design-train-logo', '', $source));
        $css = SignatureTableOverlapDelivery::css($source);
        $scope = 'tr[data-rt-artifact-version="v27"]';
        self::assertStringContainsString($scope.' .rt-delivery-train{display:block!important;width:100%!important;max-width:100%!important;height:auto!important;margin:0!important;border:0!important;vertical-align:bottom!important;}', $css);
        self::assertStringContainsString($scope.' .rt-delivery-train{display:block!important;width:100%!important;max-width:600px!important;', $legacy);
        self::assertStringContainsString('.rt-sign-ledger-content{padding:25px 0 0!important;}', $css);
        self::assertStringContainsString('.rt-sign-cell .rt-sign-content{width:100%!important;vertical-align:top!important;height:auto!important;padding-bottom:0!important;', $css);
        self::assertStringContainsString('.rt-ledger-brand.rt-delivery-wide-column{width:50%!important;padding:0 40px 0 0!important;border-right:1px solid transparent!important;', $css);
        self::assertStringContainsString('.rt-ledger-contacts.rt-delivery-wide-column{width:50%!important;padding:0 62px 0 0!important;', $css);
        self::assertStringContainsString('.rt-delivery-train-cell{width:50%!important;padding:0 40px 0 0!important;border-right:1px solid transparent!important;', $css);
        self::assertStringContainsString('.rt-delivery-brand-heading{padding-left:40px!important;}', $css);
        self::assertStringContainsString('@media only screen and (min-width:1360px){'
            .$scope.' table.rt-sign-ledger{width:70%!important;}'
            .$scope.' .rt-ledger-brand.rt-delivery-wide-column{width:28.571429%!important;}'
            .$scope.' .rt-ledger-contacts.rt-delivery-wide-column{width:71.428571%!important;}}', $css);
        self::assertStringNotContainsString('min-width:1360px', $legacy);
        self::assertStringContainsString('@media only screen and (max-width:860px)', $css);
        self::assertStringContainsString('display:block!important;width:100%!important;border:0!important;', $css);
        self::assertStringContainsString('.rt-sign-ledger-content{padding:23px 0 0!important;}', $css);
        self::assertStringContainsString($scope.' .rt-ledger-brand.rt-delivery-wide-column{padding:0 22px 0 0!important;}', $css);
        self::assertStringContainsString($scope.' .rt-ledger-contacts.rt-delivery-wide-column{padding:14px 22px 0!important;}', $css);
        self::assertStringContainsString('.rt-delivery-brand-heading{padding-left:22px!important;}', $css);
        self::assertStringContainsString('.rt-delivery-group-cell{display:table-cell!important;width:50%!important;', $css);
        self::assertStringContainsString('.rt-delivery-group-cell{display:block!important;width:100%!important;}', $css);
        self::assertStringContainsString('.rt-sign-content-frame{table-layout:auto!important;}', $css);
        self::assertStringContainsString('.rt-delivery-train-cell{width:100%!important;padding:0 22px 0 0!important;border:0!important;}', $css);
        self::assertStringContainsString('.rt-delivery-train-gap{display:none!important;}', $css);
        self::assertStringContainsString('.rt-sign-ledger-content{padding:25px 40px 15px!important;}', $legacy);
        self::assertStringContainsString('.rt-ledger-brand.rt-delivery-wide-column{width:32%!important;padding:0 22px 0 0!important;', $legacy);
        self::assertStringContainsString('.rt-ledger-contacts.rt-delivery-wide-column{width:68%!important;padding:0 0 0 22px!important;', $legacy);
        self::assertStringNotContainsString('rt-delivery-brand-heading', $legacy);
        self::assertFalse(SignatureTableOverlapDelivery::usesLogoTrain(str_replace('v27', 'v28', $source)));
        self::assertFalse(SignatureTableOverlapDelivery::usesLogoTrain(str_replace('design-train-logo', 'design-train-logo-other', $source)));
        self::assertFalse(SignatureTableOverlapDelivery::usesLogoTrain(str_replace(' design-train-logo', '', $source).'<p class="design-train-logo">Unrelated text</p>'));
        $rendered = SignatureTableOverlapDelivery::project(str_replace('{{TRAIN_SRC}}', 'cid:train.gif', $source), 'cid:train.png');
        self::assertStringContainsString('width:50%!important', TrustedEmailCss::forDocument($rendered));
    }

    public function test_only_the_known_empty_25px_authoring_reserves_are_removed_for_this_profile(): void
    {
        $xpath = $this->xpath($this->source());
        foreach (['rt-sign-ledger', 'rt-sign-stage'] as $class) {
            $node = $this->one($xpath, $class);
            $spacer = $xpath->document->createElement('div', "\u{00a0}");
            $spacer->setAttribute('style', 'height:25px;line-height:25px;font-size:0;');
            $node->parentNode->insertBefore($spacer, $node->nextSibling);
        }
        $content = $this->one($xpath, 'rt-sign-content');
        $other = $xpath->document->createElement('div', "\u{00a0}");
        $other->setAttribute('style', 'height:18px;line-height:18px;font-size:0;');
        $content->appendChild($other);
        $root = $xpath->query('//table[@id="test-root"]/tbody')->item(0);
        $source = '';
        foreach ($root->childNodes as $child) {
            $source .= $xpath->document->saveHTML($child);
        }
        $source = str_ireplace(['%7B', '%7D'], ['{', '}'], $source);
        $output = SignatureTableOverlapDelivery::project(str_replace('{{TRAIN_SRC}}', 'cid:train.gif', $source), 'cid:train.png');
        self::assertStringNotContainsString('height:25px;line-height:25px;font-size:0;', $output);
        self::assertStringContainsString('height:18px;line-height:18px;font-size:0;', $output);
        $legacy = SignatureTableOverlapDelivery::project(str_replace(['{{TRAIN_SRC}}', ' design-train-logo'], ['cid:train.gif', ''], $source), 'cid:train.png');
        self::assertSame(2, substr_count($legacy, 'height:25px;line-height:25px;font-size:0;'));
    }

    public function test_profile_runtime_rejects_detached_duplicate_or_changed_layout_and_fallbacks(): void
    {
        $source = str_replace('{{TRAIN_SRC}}', 'cid:train.gif', $this->source());
        $html = SignatureTableOverlapDelivery::project($source, 'cid:train.png');
        $mutations = [
            static fn (DOMXPath $xpath) => $xpath->query('//*[contains(@class,"rt-ledger-contacts")]')->item(0)->appendChild($xpath->query('//tr[@class="rt-delivery-train-row"]')->item(0)),
            static fn (DOMXPath $xpath) => $xpath->query('//*[contains(@class,"rt-ledger-contacts")]')->item(0)->appendChild($xpath->query('//td[@class="rt-delivery-brand-heading"]')->item(0)),
            static fn (DOMXPath $xpath) => $xpath->query('//td[@class="rt-delivery-train-cell"]')->item(0)->setAttribute('width', '200%'),
            static fn (DOMXPath $xpath) => $xpath->query('//td[@class="rt-delivery-train-gap"]')->item(0)->appendChild($xpath->document->createTextNode('Keep me')),
            static fn (DOMXPath $xpath) => $xpath->query('//*[contains(@class,"rt-ledger-brand")]')->item(0)->setAttribute('width', '32%'),
            static fn (DOMXPath $xpath) => $xpath->query('//tr[@class="rt-delivery-train-row"]')->item(0)->appendChild($xpath->document->createElement('td', 'Unexpected')),
            static fn (DOMXPath $xpath) => $xpath->query('//tr[@class="rt-delivery-train-row"]')->item(0)->parentNode->appendChild($xpath->query('//tr[@class="rt-delivery-train-row"]')->item(0)->cloneNode(true)),
            static function (DOMXPath $xpath): void {
                $cell = $xpath->query('//td[@class="rt-delivery-train-cell"]')->item(0);
                foreach (iterator_to_array($cell->childNodes) as $node) {
                    if ($node instanceof \DOMComment && str_starts_with($node->textContent, '[if mso]>')) {
                        $cell->parentNode->parentNode->parentNode->appendChild($node);
                    }
                }
            },
        ];
        foreach ($mutations as $mutation) {
            $xpath = $this->xpath($html);
            $mutation($xpath);
            $changed = $xpath->document->saveHTML();
            $this->assertRejected($changed);
        }
        foreach ([
            str_replace(' design-train-logo', '', $html),
            str_replace('<!--<![endif]-->', '', $html),
            str_replace('src="cid:train.png"', 'src="javascript:alert(1)"', $html),
            str_replace('width="220" height="28"', 'width="220" height="7500"', $html),
        ] as $changed) {
            $this->assertRejected($changed);
        }
    }

    private function source(): string
    {
        return str_replace('class="rt-sign-layout rt-sign-ledger"', 'class="rt-sign-layout rt-sign-ledger design-train-logo"', trim(file_get_contents(base_path('tests/Fixtures/mail/signature-v27-ledger.html'))));
    }

    private function xpath(string $html): DOMXPath
    {
        $dom = new DOMDocument('1.0', 'UTF-8');
        @$dom->loadHTML('<?xml encoding="UTF-8"><table id="test-root"><tbody>'.$html.'</tbody></table>', LIBXML_NONET);

        return new DOMXPath($dom);
    }

    private function one(DOMXPath $xpath, string $class): \DOMElement
    {
        $matches = $xpath->query('//*[contains(concat(" ",normalize-space(@class)," ")," '.$class.' ")]');
        self::assertCount(1, $matches);

        return $matches->item(0);
    }

    private function links(string $html): array
    {
        $links = array_map(static fn ($node) => [$node->getAttribute('href'), $node->textContent], iterator_to_array($this->xpath($html)->query('//a')));
        sort($links);

        return $links;
    }

    private function imageSources(string $html): array
    {
        preg_match_all('~<img\b[^>]*\bsrc="([^"]*)"~', $html, $images);
        sort($images[1]);

        return $images[1];
    }

    private function plainText(string $html): string
    {
        return preg_replace('/\s+/u', ' ', str_replace("\u{00a0}", ' ', $this->xpath($html)->document->textContent));
    }

    private function assertRejected(string $html): void
    {
        try {
            SignatureTableOverlapDelivery::assertRuntime($html);
            self::fail('The changed logo-train shape must be rejected.');
        } catch (RuntimeException) {
            self::assertTrue(true);
        }
    }
}
