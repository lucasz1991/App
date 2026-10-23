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

    public function test_opted_in_train_and_its_word_image_share_one_real_row_below_logo_and_person(): void
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
        $trainTable = $this->one($xpath, 'rt-delivery-logo-train');
        self::assertSame(1, $xpath->query('./tr|./tbody/tr', $frame)->length);
        self::assertSame('50%', $brand->getAttribute('width'));
        self::assertSame('50%', $contacts->getAttribute('width'));
        self::assertSame('th', $brand->tagName);
        self::assertTrue($trainTable->parentNode->isSameNode($brand));
        self::assertTrue($brand->lastElementChild->isSameNode($trainTable));
        self::assertSame('100%', $trainTable->getAttribute('width'));
        self::assertSame(1, $xpath->query('./tbody/tr/td/img[@class="rt-delivery-train"]', $trainTable)->length);
        self::assertSame(1, $xpath->query('.//img[contains(concat(" ",@class," ")," rt-logo ")]', $brand)->length);
        self::assertSame(1, $xpath->query('.//*[contains(concat(" ",@class," ")," rt-person-kopf ")]', $brand)->length);
        $image = $this->one($xpath, 'rt-delivery-train');
        self::assertSame('300', $image->getAttribute('width'));
        self::assertSame('38', $image->getAttribute('height'));
        self::assertStringContainsString('<!--[if !mso]><!-->', $output);
        self::assertStringContainsString('<!--[if mso]><img class="rt-delivery-train-mso" src="cid:train.png" width="220" height="28"', $output);
        self::assertSame($this->links($legacy), $this->links($output));
        self::assertSame($this->imageSources($legacy), $this->imageSources($output));
        self::assertSame($this->plainText($legacy), $this->plainText($output));
        self::assertStringNotContainsString('position:absolute', $output);
        self::assertStringNotContainsString('background-image:', $output);
        Http::assertNothingSent();
    }

    public function test_opt_in_reuses_the_same_css_rules_with_profile_columns_and_the_smaller_word_image(): void
    {
        $source = $this->source();
        $legacy = SignatureTableOverlapDelivery::css(str_replace(' design-train-logo', '', $source));
        $css = SignatureTableOverlapDelivery::css($source);
        self::assertSame(str_replace(['width:32%', 'width:68%', 'width:300px', 'height:38px'], ['width:50%', 'width:50%', 'width:220px', 'height:28px'], $legacy), $css);
        self::assertSame(strlen($legacy), strlen($css));
        self::assertStringContainsString('@media only screen and (max-width:860px)', $css);
        self::assertStringContainsString('display:block!important;width:100%!important;padding:0!important;border:0!important;', $css);
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
            static fn (DOMXPath $xpath) => $xpath->query('//*[contains(@class,"rt-ledger-contacts")]')->item(0)->appendChild($xpath->query('//table[@class="rt-delivery-logo-train"]')->item(0)),
            static fn (DOMXPath $xpath) => $xpath->query('//table[@class="rt-delivery-logo-train"]')->item(0)->setAttribute('width', '200%'),
            static fn (DOMXPath $xpath) => $xpath->query('//*[contains(@class,"rt-ledger-brand")]')->item(0)->setAttribute('width', '32%'),
            static fn (DOMXPath $xpath) => $xpath->query('//tr[@class="rt-delivery-train-row"]')->item(0)->appendChild($xpath->document->createElement('td', 'Unexpected')),
            static fn (DOMXPath $xpath) => $xpath->query('//table[@class="rt-delivery-logo-train"]')->item(0)->appendChild($xpath->query('//tr[@class="rt-delivery-train-row"]')->item(0)->cloneNode(true)),
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
