<?php

declare(strict_types=1);

use App\Support\Mail\OutlookUniversalTransitCandidate;
use App\Support\Mail\OutlookUniversalTransitTransformer;
use Illuminate\Foundation\Application;

/**
 * Offline B1/CID bridge. No publication resolver, kernel, DB, network or mail.
 * CID URLs belong only to the admitted local B1 projection. Original incoming
 * HTML, authors and quotes are never subjected to URL replacement.
 */
final class RailTimeTransitCidProjection
{
    private const ORIGIN = 'https://app.rail-time.de';

    private const MEDIA = [
        'b983af0049.png' => 'b983af0049926c128807d605a51a68ce90fea06c5c340f7728b105a96d7f021a',
        '9dfa25b253.png' => '9dfa25b253b065c73173b884939db2a032453c44eb2ba6e9e65434a95a072675',
        'd92bd192b6.png' => 'd92bd192b664c536fe29b1c927023bca44041de90bc9bfe34e6df03be5006d32',
        '1593d63cca.png' => '1593d63ccacf8c884b71870fe1478f218ea0f541e918d84115978bc2b75c64ff',
        'a38bc7d27c.png' => 'a38bc7d27c9c8c04c2a20815ad9576c76aebfe0bd651f9c5e90909b83bdf6925',
        '8a9ee1e161.png' => '8a9ee1e1614f8e48fc374fb99ccd088ea33b4f3f156a63ed5294d23ab840e50d',
        '4b1a2aaba8.png' => '4b1a2aaba8a4720445c7b80d1599765da5b728776eb876ea2e31facd8866db1c',
        '19125261c2.png' => '19125261c2bc8274933b7a1c9efd1c5db23cd751c65fc1f88d2dcae22e4481b1',
        'f9d88b899d.png' => 'f9d88b899d574851c64d02c9835a5b5fcf8d7c68e05d6c9b3f5618d3ac38bbd9',
        '1111670ac8.png' => '1111670ac86e12aff6ad7809e11959b7af3c1275d4c5f2832b60934992f8f19a',
        'b4b1fbea07.png' => 'b4b1fbea07044c782344b98404fc91c41b5a9e844ef5535f150d7649d62f3160',
        'f1b083c20b.png' => 'f1b083c20b84f813007ca8d1b08c01ef77e08a819f12dc701a1ed94a2d7fd3fc',
        '14d7943c88.png' => '14d7943c888b83659542baaa26172dde70e51df8fc893a4c0cc48b14e47f6351',
    ];

    public static function transform(string $html, array $headers, array $projection, array $cidUrls,
        bool $trustedIngress = false, string $envelopeSender = ''): array
    {
        $transformer = new OutlookUniversalTransitTransformer;
        // Perform the existing immutable-template/version/structure admission
        // before changing any local projection bytes. The result is not sent.
        $admitted = $transformer->transform($html, $headers, $projection, $trustedIngress, $envelopeSender);
        if (! $admitted['changed']) {
            return $admitted;
        }
        try {
            $manifest = [];
            foreach ($projection['media'] ?? [] as $entry) {
                $source = $entry['url'] ?? null;
                if (! is_string($source) || ! str_starts_with($source, self::ORIGIN.'/m/')) {
                    throw new RuntimeException('cid_media_invalid');
                }
                $name = substr($source, strlen(self::ORIGIN.'/m/'));
                if (! isset(self::MEDIA[$name]) || isset($manifest[$source])
                    || ($entry['mime'] ?? null) !== 'image/png'
                    || ($entry['sha256'] ?? null) !== self::MEDIA[$name]) {
                    throw new RuntimeException('cid_media_invalid');
                }
                $manifest[$source] = true;
            }
            if ($manifest === [] || count($manifest) !== count($cidUrls)) {
                throw new RuntimeException('cid_media_invalid');
            }
            foreach ($cidUrls as $source => $cid) {
                if (! isset($manifest[$source]) || ! is_string($cid)
                    || strlen($cid) > 204 || ! preg_match('/\Acid:[A-Za-z0-9._+\-]+@[A-Za-z0-9.\-]+\z/', $cid)) {
                    throw new RuntimeException('cid_media_invalid');
                }
            }
            if (count(array_unique($cidUrls)) !== count($cidUrls)) {
                throw new RuntimeException('cid_media_invalid');
            }
            $body = $projection['bodyHtml'];
            $inspection = OutlookUniversalTransitCandidate::inspect($body, $projection['headers'], true);
            if (! $inspection['eligible'] || ($inspection['candidate']['kind'] ?? null) !== 'combined') {
                throw new RuntimeException('cid_projection_invalid');
            }
            $candidate = $inspection['candidate'];
            $author = substr($body, $candidate['authorStart'], $candidate['authorEnd'] - $candidate['authorStart']);
            $used = [];
            $images = static function (string $source) use ($cidUrls, &$used): string {
                return preg_replace_callback('~<img\b[^>]*>~i', static function (array $tag) use ($cidUrls, &$used): string {
                    $count = 0;
                    $output = preg_replace_callback('~(\s+src\s*=\s*)(["\'])([^"\']*)\2~i',
                        static function (array $match) use ($cidUrls, &$used): string {
                            $url = html_entity_decode($match[3], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                            if (! isset($cidUrls[$url])) {
                                throw new RuntimeException('cid_media_invalid');
                            }
                            $used[$url] = true;

                            return $match[1].$match[2].$cidUrls[$url].$match[2];
                        }, $tag[0], -1, $count);
                    if (! is_string($output) || $count !== 1) {
                        throw new RuntimeException('cid_projection_invalid');
                    }

                    return $output;
                }, $source) ?? throw new RuntimeException('cid_projection_invalid');
            };
            // Only immutable B1 template fragments are changed. Even B1's own
            // source author row is opaque, then replaced by incoming author bytes.
            $cidBody = $images(substr($body, 0, $candidate['authorStart']))
                .$author.$images(substr($body, $candidate['authorEnd']));
            if (count($used) !== count($cidUrls) || substr_count($projection['html'], $body) !== 1) {
                throw new RuntimeException('cid_projection_invalid');
            }
            $cidProjection = $projection;
            $cidProjection['bodyHtml'] = $cidBody;
            $cidProjection['html'] = str_replace($body, $cidBody, $projection['html']);

            return $transformer->transform($html, $headers, $cidProjection, $trustedIngress, $envelopeSender);
        } catch (Throwable) {
            return ['html' => $html, 'changed' => false, 'reason' => 'cid_projection_invalid',
                'version' => $admitted['version'], 'scopeId' => null, 'headCss' => '', 'preservation' => []];
        }
    }
}

if (! defined('RAILTIME_CID_BRIDGE_LIBRARY_ONLY')) {
    require dirname(__DIR__, 2).'/vendor/autoload.php';
    try {
        new Application(dirname(__DIR__, 2));
        $raw = file_get_contents('php://stdin', false, null, 0, 4000001);
        if (! is_string($raw) || strlen($raw) > 4000000) {
            throw new RuntimeException('input_budget');
        }
        $request = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        if (! is_array($request) || ($request['offlineTrustedFixture'] ?? false) !== true
            || ! is_string($request['html'] ?? null) || ! is_array($request['headers'] ?? null)
            || ! is_array($request['projection'] ?? null) || ! is_array($request['cidUrls'] ?? null)
            || ! is_string($request['envelopeSender'] ?? null)) {
            throw new RuntimeException('input_contract');
        }
        $result = RailTimeTransitCidProjection::transform($request['html'], $request['headers'],
            $request['projection'], $request['cidUrls'], true, $request['envelopeSender']);
        echo json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    } catch (Throwable) {
        echo '{"changed":false,"reason":"offline_cid_bridge_failed","html":""}';
        exit(1);
    }
}
