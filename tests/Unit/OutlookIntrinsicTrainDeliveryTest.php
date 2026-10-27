<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\OutlookAddin\OutlookIntrinsicTrainDelivery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class OutlookIntrinsicTrainDeliveryTest extends TestCase
{
    #[DataProvider('variants')]
    public function test_all_eight_approved_binary_pairs_project_only_the_modern_image(string $variant, string $extension): void
    {
        $source = $this->attachment('zug-dampf-v27-delivery-'.$variant.'.'.$extension);
        $fallback = $this->attachment('zug-dampf-v27-delivery-'.$variant.'.png');
        $new = $this->attachment('zug-dampf-outlook-intrinsic-v1-'.$variant.'.'.$extension);
        foreach ([false, true] as $mobile) {
            $html = $this->document($source['contentId'], $fallback['contentId'], $mobile);
            $payload = ['signature' => ['html' => $html, 'media' => array_values(array_column([$source, $fallback], null, 'contentId'))]];
            $before = $payload;
            $result = OutlookIntrinsicTrainDelivery::payload($payload);
            self::assertSame($before, $payload, 'Projection cannot mutate the canonical input array.');
            self::assertSame($result, OutlookIntrinsicTrainDelivery::payload($result));
            $expected = preg_replace('~(src="cid:)'.preg_quote($source['contentId'], '~').'(" width="300" height=")38~', '${1}'.$new['contentId'].'${2}65', $html, 1, $count);
            self::assertSame(1, $count);
            self::assertSame($expected, $result['signature']['html']);
            self::assertSame([$fallback, $new], $result['signature']['media']);
            self::assertStringContainsString($this->fallback($fallback['contentId']), $result['signature']['html'], 'Conditional Word PNG remains byte-exact at220×28.');
            self::assertStringContainsString('height:auto', $result['signature']['html']);
            self::assertSame([695, 151], array_slice(getimagesizefromstring(base64_decode($new['base64'], true)), 0, 2));
        }
    }

    public static function variants(): iterable
    {
        foreach (['light', 'dark', 'light-mirrored', 'dark-mirrored'] as $variant) {
            foreach (['gif', 'png'] as $extension) {
                yield $variant.'.'.$extension => [$variant, $extension];
            }
        }
    }

    public function test_payload_covers_global_active_all_template_views_and_paired_signature_without_changing_versions_or_compose(): void
    {
        $signature = $this->pair();
        $mobile = $this->pair(mobile: true);
        $template = ['id' => '42', 'html' => $signature['html'], 'media' => $signature['media'],
            'combinedComposeHtml' => '<table><tr><td>'.$signature['html'].'</td></tr></table>', 'combinedComposeMedia' => $signature['media'],
            'mobileComposeHtml' => $mobile['html'], 'mobileComposeMedia' => $mobile['media'],
            'composeHtml' => $signature['html'], 'composeMedia' => $signature['media'],
            'signature' => $signature, 'signatureVersion' => '0123456789abcdef', 'mobileComposeVersion' => 'abcdef0123456789', 'version' => '1123456789abcdef', 'hash' => 'unchanged'];
        $payload = ['signature' => $signature, 'template' => $signature, 'templates' => [$template], 'version' => ['signature' => '0123456789abcdef', 'template' => '1123456789abcdef']];
        $result = OutlookIntrinsicTrainDelivery::payload($payload);
        self::assertSame($payload['version'], $result['version']);
        self::assertSame($result['signature'], $result['template']);
        self::assertSame($result['signature'], $result['templates'][0]['signature']);
        foreach (['html', 'combinedComposeHtml', 'mobileComposeHtml'] as $html) {
            self::assertStringContainsString('height="65"', $result['templates'][0][$html]);
            self::assertNotSame($template[$html], $result['templates'][0][$html]);
        }
        foreach (['id', 'composeHtml', 'composeMedia', 'signatureVersion', 'mobileComposeVersion', 'version', 'hash'] as $key) {
            self::assertSame($template[$key], $result['templates'][0][$key], $key);
        }
        self::assertSame(OutlookIntrinsicTrainDelivery::payload($payload), $result);
    }

    public function test_same_old_cid_in_unowned_quoted_conditional_or_css_content_keeps_its_attachment(): void
    {
        $pair = $this->pair();
        $old = $pair['media'][0];
        foreach ([
            '<img src="cid:'.$old['contentId'].'" width="300" height="38">',
            '<blockquote>'.$pair['html'].'</blockquote>',
            '<!--[if mso]><img src="cid:'.$old['contentId'].'"><![endif]-->',
            '<style>.unchanged{background-image:url("cid:'.$old['contentId'].'")}</style>',
        ] as $outside) {
            $input = ['signature' => ['html' => $pair['html'].$outside, 'media' => $pair['media']]];
            $result = OutlookIntrinsicTrainDelivery::payload($input);
            self::assertStringEndsWith($outside, $result['signature']['html']);
            self::assertSame($old, $result['signature']['media'][0]);
            self::assertCount(3, $result['signature']['media']);
        }
    }

    public function test_already_registered_new_attachment_is_reused_without_touching_other_media(): void
    {
        $pair = $this->pair();
        $new = $this->attachment('zug-dampf-outlook-intrinsic-v1-light.gif');
        $pair['html'] .= '<img class="custom" src="cid:'.$new['contentId'].'">';
        $pair['media'][] = $new;
        $result = OutlookIntrinsicTrainDelivery::payload(['signature' => $pair]);
        self::assertSame([$pair['media'][1], $new], $result['signature']['media']);
    }

    public function test_known_fixed_modern_height_changes_atomically_but_word_comments_and_css_stay_exact(): void
    {
        $pair = $this->pair();
        $pair['html'] = str_replace('height:auto', 'height:38px!important', $pair['html']);
        $result = OutlookIntrinsicTrainDelivery::payload(['signature' => $pair]);
        self::assertStringContainsString('height:65px!important', $result['signature']['html']);
        self::assertStringContainsString($this->fallback($pair['media'][1]['contentId']), $result['signature']['html']);
        self::assertStringContainsString('<style>.rt-delivery-train-mso{width:220px;height:28px;}</style>', $result['signature']['html']);
    }

    public function test_unowned_custom_authored_and_older_projected_documents_remain_byte_exact(): void
    {
        $pair = $this->pair();
        foreach ([
            '<p>Custom signature</p><img class="rt-delivery-train" src="cid:'.$pair['media'][0]['contentId'].'" width="300" height="38">',
            '<div class="rt-dflow" data-rt-desktop-flow="outlook-desktop-flow-v7"><img class="rt-delivery-train" src="cid:'.$pair['media'][0]['contentId'].'" width="300" height="38"></div>',
            str_replace('outlook-desktop-flow-v7', 'outlook-desktop-flow-v6', $pair['html']),
            '<blockquote>'.$pair['html'].'</blockquote>',
            '<div class="gmail_quote">'.$pair['html'].'</div>',
            '<div id="divRplyFwdMsg"></div>'.$pair['html'],
            '<style data-rt-mail-document-css="signature">.custom{color:red}</style>'.$pair['html'],
            str_replace(['rt-mobile-hybrid-v2', 'data-rt-mobile-hybrid="v2"'], ['rt-mobile-hybrid-v1', 'data-rt-mobile-hybrid="v1"'], $this->pair(mobile: true)['html']),
        ] as $html) {
            $input = ['signature' => ['html' => $html, 'media' => $pair['media']]];
            self::assertSame($input, OutlookIntrinsicTrainDelivery::payload($input));
        }
        self::assertStringContainsString('height="65"', OutlookIntrinsicTrainDelivery::payload(['signature' => ['html' => '<style data-rt-mail-document-css="signature"></style>'.$pair['html'], 'media' => $pair['media']]])['signature']['html']);
    }

    #[DataProvider('invalidHtml')]
    public function test_owned_unknown_html_or_cid_is_rejected_atomically(string $before, string $after): void
    {
        $pair = $this->pair();
        $pair['html'] = str_replace($before, $after, $pair['html']);
        $original = $pair;
        try {
            OutlookIntrinsicTrainDelivery::payload(['signature' => $pair]);
            self::fail('Unsafe owned projection accepted.');
        } catch (RuntimeException) {
            self::assertSame($original, $pair);
        }
    }

    public static function invalidHtml(): iterable
    {
        yield 'wrong scope' => ['rt-df70123456789', 'rt-df60123456789'];
        yield 'missing artifact' => ['data-rt-artifact-version="v27"', 'data-rt-artifact-version="v26"'];
        yield 'extra row' => ['</tbody></table></td></tr></tbody></table></div>', '<tr><td>foreign</td></tr></tbody></table></td></tr></tbody></table></div>'];
        yield 'wrong group' => ['rt-personal-left', 'custom-person'];
        yield 'custom window' => ['class="rt-train-window"', 'class="rt-train-window" style="padding-top:9.2%"'];
        yield 'quoted train window' => ['class="rt-train-window"', 'class="rt-train-window" id="divRplyFwdMsg"'];
        yield 'remote source' => ['src="cid:railtime-e0f8327a1041e23bdbbb.gif" width="300"', 'src="https://example.test/custom.gif" width="300"'];
        yield 'embedded SVG' => ['src="cid:railtime-e0f8327a1041e23bdbbb.gif" width="300"', 'src="data:image/svg+xml;base64,PHN2Zy8+" width="300"'];
        yield 'missing CID' => ['src="cid:railtime-e0f8327a1041e23bdbbb.gif" width="300"', 'src="cid:missing.gif" width="300"'];
        yield 'CID traversal' => ['src="cid:railtime-e0f8327a1041e23bdbbb.gif" width="300"', 'src="cid:../train.gif" width="300"'];
        yield 'wrong width' => ['width="300" height="38"', 'width="301" height="38"'];
        yield 'wrong source height' => ['width="300" height="38"', 'width="300" height="65"'];
        yield 'conflicting image attribute' => ['class="rt-delivery-train"', 'class="rt-delivery-train" srcset="foreign.gif 2x"'];
        yield 'duplicate source' => ['class="rt-delivery-train"', 'class="rt-delivery-train" src="cid:other.gif"'];
        yield 'unknown image styling' => ['height:auto', 'height:99px'];
        yield 'additional authored image style' => ['height:auto', 'height:auto;transform:scale(2)'];
        yield 'malformed owned closing tag' => ['</div></td></tr></tbody></table></td>', '</div></div></td></tr></tbody></table></td>'];
    }

    public function test_unknown_media_hash_wrong_mime_or_duplicate_content_id_is_rejected(): void
    {
        $pair = $this->pair();
        $invalid = [];
        $invalid[] = array_replace($pair, ['media' => [$pair['media'][0], $pair['media'][0], $pair['media'][1]]]);
        $candidate = $pair;
        $candidate['media'][0]['base64'] .= '!';
        $invalid[] = $candidate;
        $candidate = $pair;
        $candidate['media'][0]['base64'] = $pair['media'][1]['base64'];
        $invalid[] = $candidate;
        $candidate = $pair;
        $candidate['media'][0]['base64'] = base64_encode('GIF89a unknown custom binary');
        $invalid[] = $candidate;
        $candidate = $pair;
        $candidate['media'][0]['name'] = 'other.gif';
        $invalid[] = $candidate;
        foreach ($invalid as $input) {
            try {
                OutlookIntrinsicTrainDelivery::payload(['signature' => $input]);
                self::fail('Invalid media accepted.');
            } catch (RuntimeException) {
                self::assertTrue(true);
            }
        }
    }

    public function test_every_changed_view_still_obeys_css_utf16_and_package_limits(): void
    {
        $pair = $this->pair();
        $invalid = [
            ['html' => '<style>'.str_repeat('a', 12288).'</style>'.$pair['html'], 'media' => $pair['media']],
            ['html' => str_repeat('😀', 15000).$pair['html'], 'media' => $pair['media']],
            ['html' => $pair['html'], 'media' => [...$pair['media'], ['name' => 'large.png', 'contentId' => 'large.png', 'base64' => base64_encode(str_repeat('x', 2097152))]]],
        ];
        foreach ($invalid as $input) {
            try {
                OutlookIntrinsicTrainDelivery::payload(['signature' => $input]);
                self::fail('Transport budget accepted.');
            } catch (RuntimeException) {
                self::assertTrue(true);
            }
        }
    }

    public function test_fingerprint_binds_all_eight_real_immutable_asset_hashes(): void
    {
        $fingerprint = OutlookIntrinsicTrainDelivery::fingerprint();
        self::assertCount(8, $fingerprint);
        $sorted = $fingerprint;
        ksort($sorted);
        self::assertSame($sorted, $fingerprint);
        foreach ($fingerprint as $path => $sha) {
            self::assertMatchesRegularExpression('~\A/mail-assets/zug-dampf-outlook-intrinsic-v1-(?:light|dark)(?:-mirrored)?\.(?:gif|png)\z~', $path);
            self::assertSame($sha, hash_file('sha256', dirname(__DIR__, 2).'/public'.$path));
        }
    }

    private function pair(bool $mobile = false): array
    {
        $gif = $this->attachment('zug-dampf-v27-delivery-light.gif');
        $png = $this->attachment('zug-dampf-v27-delivery-light.png');

        return ['html' => $this->document($gif['contentId'], $png['contentId'], $mobile), 'media' => [$gif, $png]];
    }

    private function document(string $cid, string $fallback, bool $mobile = false): string
    {
        $classes = $mobile ? ['rt-mhy-logo', 'rt-mhy-person', 'rt-mhy-company', 'rt-mhy-train'] : ['rt-personal-logo-column', 'rt-personal-left', 'rt-personal-right', 'rt-df-train'];
        $owner = $mobile ? 'rt-outlook-signature rts0123456789 rt-mobile-ledger rtm' : 'rt-outlook-signature rts0123456789 rt-df70123456789';
        $flow = $mobile ? 'class="rt-mobile-hybrid-v2" data-rt-mobile-hybrid="v2"' : 'class="rt-personal-columns" data-rt-personal-flow="v1" data-rt-desktop-flow="outlook-desktop-flow-v7"';
        $window = $mobile ? 'rt-wabc123' : 'rt-train-window';
        $image = $mobile ? 'rt-mhy-image rt-iabc123' : 'rt-delivery-train';
        $groups = '';
        foreach (array_slice($classes, 0, 3) as $kind) {
            $groups .= '<table class="'.$kind.'"><tbody><tr><td>Text &amp; unchanged</td></tr></tbody></table>';
        }

        return '<style>.rt-delivery-train-mso{width:220px;height:28px;}</style><div class="'.$owner.'"><table><tbody><tr data-rt-artifact-version="v27"><td>'
            .'<table '.$flow.' data-rt-personal-columns="v1"><tbody><tr><td>'.$groups.'</td></tr><tr><td>'
            .'<table class="'.$classes[3].'"><tbody><tr><td><!--[if !mso]><!--><div class="'.$window.'">'
            .'<img class="'.$image.'" src="cid:'.$cid.'" width="300" height="38" alt="" style="display:block;width:100%;max-width:100%;height:auto;margin:0;border:0;vertical-align:bottom;">'
            .'</div><!--<![endif]-->'.$this->fallback($fallback).'</td></tr></tbody></table>'
            .'</td></tr></tbody></table></td></tr></tbody></table></div>';
    }

    private function fallback(string $cid): string
    {
        return '<!--[if mso]><img class="rt-delivery-train-mso" src="cid:'.$cid.'" width="220" height="28" alt="" style="display:block;width:220px;height:28px;"><![endif]-->';
    }

    private function attachment(string $filename): array
    {
        $binary = file_get_contents(dirname(__DIR__, 2).'/public/mail-assets/'.$filename);
        $cid = 'railtime-'.substr(hash('sha256', $binary), 0, 20).'.'.pathinfo($filename, PATHINFO_EXTENSION);

        return ['name' => $cid, 'contentId' => $cid, 'base64' => base64_encode($binary)];
    }
}
