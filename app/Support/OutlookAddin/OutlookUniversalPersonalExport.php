<?php

declare(strict_types=1);

namespace App\Support\OutlookAddin;

use App\Models\User;
use App\Support\EmailTemplateBuilder;
use RuntimeException;
use Throwable;

/** Personal download boundary only. It never changes the add-in's canonical source. */
class OutlookUniversalPersonalExport
{
    private const MEDIA = [
        'b983af0049.png' => 'b983af0049926c128807d605a51a68ce90fea06c5c340f7728b105a96d7f021a',
        '9dfa25b253.png' => '9dfa25b253b065c73173b884939db2a032453c44eb2ba6e9e65434a95a072675',
        'd92bd192b6.png' => 'd92bd192b664c536fe29b1c927023bca44041de90bc9bfe34e6df03be5006d32',
        '1593d63cca.png' => '1593d63ccacf8c884b71870fe1478f218ea0f541e918d84115978bc2b75c64ff',
        'a38bc7d27c.png' => 'a38bc7d27c9c8c04c2a20815ad9576c76aebfe0bd651f9c5e90909b83bdf6925',
        'c01c48d975.png' => 'c01c48d9751490afb57f4a01622603922557cab4d46dd68f4b92867e57ef7454',
        '8a5901e027.png' => '8a5901e0273611b36d763a352dd9ee7df02e4c155fa5e4ad6812ba74a09204a6',
        '19125261c2.png' => '19125261c2bc8274933b7a1c9efd1c5db23cd751c65fc1f88d2dcae22e4481b1',
        'f9d88b899d.png' => 'f9d88b899d574851c64d02c9835a5b5fcf8d7c68e05d6c9b3f5618d3ac38bbd9',
        '1111670ac8.png' => '1111670ac86e12aff6ad7809e11959b7af3c1275d4c5f2832b60934992f8f19a',
        '033a29c0b7.png' => '033a29c0b747373ddc306dafc4abf81d8d21dc4505e9e83931fbaab4a6aec9a6',
        '097c3bb121.png' => '097c3bb121f8b1b14e36de2cabdffb0606254fd8d69532061012a12c8a60fb09',
        '14d7943c88.png' => '14d7943c888b83659542baaa26172dde70e51df8fc893a4c0cc48b14e47f6351',
    ];

    public function __construct(
        private readonly OutlookAddinUserSnapshotStore $snapshots,
        private readonly OutlookUniversalPayloadPresentation $projection,
    ) {}

    /** Preserve legacy MIME, filenames and all unsupported formats verbatim. */
    public function project(User $user, string $template, array $legacyFile): array
    {
        $mail = in_array($template, ['vorlage-html', 'vorlage-dunkel-html', 'vorlage-eml', 'vorlage-dunkel-eml'], true);
        $signature = in_array($template, ['signatur-hell', 'signatur-dunkel'], true);
        if (! OutlookUniversalPayloadPresentation::isPilot($user) || ! ($mail || $signature)) {
            return $legacyFile;
        }
        try {
            $draft = $this->draft($user);
            if ($draft === null) {
                return $legacyFile;
            }
            $eml = str_ends_with($template, '-eml');
            $media = $this->media($draft);
            $html = $this->document($draft, $signature, $media, $eml ? 'cid' : 'data');
            $content = $eml ? $this->eml($legacyFile['content'], $html, $draft, $media) : $html;

            return array_replace($legacyFile, ['content' => $content]);
        } catch (Throwable) {
            // Existing custom/nonadmitted sources retain their existing export.
            return $legacyFile;
        }
    }

    /** The established browser/Outlook clipboard route keeps HTTPS images. */
    public function signatureCopy(User $user, string $legacyHtml): string
    {
        if (! OutlookUniversalPayloadPresentation::isPilot($user)) {
            return $legacyHtml;
        }
        try {
            $draft = $this->draft($user);

            return $draft === null ? $legacyHtml : $this->document($draft, true, [], 'remote');
        } catch (Throwable) {
            return $legacyHtml;
        }
    }

    protected function canonical(User $user): array
    {
        // This may refresh the existing encrypted derived snapshot cache. It
        // never writes profiles, publications, account bindings or preferences.
        return OutlookStaticLinkedTestProfiles::payload(
            OutlookClientPresentation::payload($this->snapshots->currentForUser($user)), $user,
        );
    }

    protected function profile(User $user): array
    {
        return (new EmailTemplateBuilder($user))->profileValues();
    }

    protected function draft(User $user): ?array
    {
        return $this->projection->review($this->canonical($user), $this->profile($user), mode: 'send', user: $user)['draft'];
    }

    /** @return array<string,array{source:string,bytes:string,cid:string}> */
    private function media(array $draft): array
    {
        $media = [];
        foreach ($draft['media'] as $entry) {
            $path = parse_url($entry['url'], PHP_URL_PATH);
            if (! is_string($path) || ! preg_match('~\A/m/([0-9a-f]{10}\.png)\z~', $path, $match)
                || ! isset(self::MEDIA[$match[1]]) || isset($media[$match[1]])
                || $entry['url'] !== rtrim((string) config('app.url'), '/').$path
                || $entry['mime'] !== 'image/png' || $entry['sha256'] !== self::MEDIA[$match[1]]) {
                throw new RuntimeException('U2E_EXPORT_MEDIA_INVALID');
            }
            $file = public_path(ltrim($path, '/'));
            if (! is_file($file) || ! is_readable($file) || filesize($file) > 2 * 1024 * 1024) {
                throw new RuntimeException('U2E_EXPORT_MEDIA_INVALID');
            }
            $bytes = file_get_contents($file);
            $size = is_string($bytes) ? @getimagesizefromstring($bytes) : false;
            if (! is_string($bytes) || ! hash_equals(self::MEDIA[$match[1]], hash('sha256', $bytes))
                || ! is_array($size) || $size[0] !== $entry['width'] || $size[1] !== $entry['height']
                || ($size['mime'] ?? null) !== 'image/png') {
                throw new RuntimeException('U2E_EXPORT_MEDIA_INVALID');
            }
            $media[$match[1]] = ['source' => $entry['url'], 'bytes' => $bytes, 'cid' => 'railtime-u2e-'.substr($match[1], 0, 10).'@railtime.local'];
        }
        if ($media === [] || count($media) > count(self::MEDIA)) {
            throw new RuntimeException('U2E_EXPORT_MEDIA_INVALID');
        }

        return $media;
    }

    private function document(array $draft, bool $signatureOnly, array $media, string $delivery): string
    {
        $body = $draft['bodyHtml'];
        $signature = $draft['signatureHtml'];
        if (substr_count($draft['html'], $body) !== 1) {
            throw new RuntimeException('U2E_EXPORT_DOCUMENT_INVALID');
        }
        if ($signatureOnly) {
            $outputBody = $this->images($signature, $media, $delivery);
        } else {
            // B1 removes one author row to produce signatureHtml. Preserve the
            // exact intervening bytes, including any opaque nested markup.
            $prefix = 0;
            $limit = min(strlen($signature), strlen($body));
            while ($prefix < $limit && $signature[$prefix] === $body[$prefix]) {
                $prefix++;
            }
            $tail = substr($signature, $prefix);
            if ($tail === '' || ! str_ends_with($body, $tail)) {
                throw new RuntimeException('U2E_EXPORT_DOCUMENT_INVALID');
            }
            $author = substr($body, $prefix, strlen($body) - $prefix - strlen($tail));
            $outputBody = $this->images(substr($body, 0, $prefix), $media, $delivery)
                .$author.$this->images($tail, $media, $delivery);
        }
        if ($delivery !== 'remote') {
            preg_match_all('~<img\b[^>]*\bsrc\s*=\s*["\']([^"\']+)["\']~i', $outputBody, $images);
            foreach ($images[1] as $source) {
                if (! str_starts_with($source, $delivery === 'data' ? 'data:image/png;base64,' : 'cid:railtime-u2e-')) {
                    throw new RuntimeException('U2E_EXPORT_MEDIA_INVALID');
                }
            }
        }

        return str_replace($body, $outputBody, $draft['html']);
    }

    private function images(string $html, array $media, string $delivery): string
    {
        if ($delivery === 'remote') {
            return $html;
        }
        $sources = [];
        foreach ($media as $entry) {
            $sources[$entry['source']] = $delivery === 'data'
                ? 'data:image/png;base64,'.base64_encode($entry['bytes']) : 'cid:'.$entry['cid'];
        }

        return preg_replace_callback('~<img\b[^>]*>~i', static fn (array $tag): string => preg_replace_callback(
            '~(\bsrc\s*=\s*)(["\'])([^"\']+)\2~i', static fn (array $src): string => $src[1].$src[2].($sources[html_entity_decode($src[3], ENT_QUOTES | ENT_HTML5, 'UTF-8')] ?? $src[3]).$src[2], $tag[0],
        ) ?? $tag[0], $html) ?? $html;
    }

    /** A download-only draft: existing sender/subject/recipient headers survive. */
    private function eml(string $legacy, string $html, array $draft, array $media): string
    {
        $separator = strpos($legacy, "\r\n\r\n");
        if ($separator === false || $separator > 16384) {
            throw new RuntimeException('U2E_EXPORT_MIME_INVALID');
        }
        $headers = substr($legacy, 0, $separator);
        if (! preg_match('/^X-Unsent: 1$/m', str_replace("\r\n", "\n", $headers))
            || preg_match('/^X-RailTime-Template:/mi', $headers)) {
            throw new RuntimeException('U2E_EXPORT_MIME_INVALID');
        }
        $alt = '=_rt_u2e_alt_'.bin2hex(random_bytes(16));
        $related = '=_rt_u2e_rel_'.bin2hex(random_bytes(16));
        $count = 0;
        $headers = preg_replace('/^Content-Type:[^\r\n]*(?:\r\n[ \t]+[^\r\n]*)*/mi', 'Content-Type: multipart/alternative; boundary="'.$alt.'"', $headers, -1, $count);
        if ($count !== 1 || ! is_string($headers)) {
            throw new RuntimeException('U2E_EXPORT_MIME_INVALID');
        }
        $text = preg_replace('~<head\b[^>]*>.*?</head>~is', '', $html) ?? '';
        $text = preg_replace('~<(?:br\b[^>]*|/(?:p|tr|div))>~i', "\n", $text) ?? '';
        $text = trim(html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $encoded = static fn (string $bytes): string => rtrim(chunk_split(base64_encode($bytes), 76, "\r\n"), "\r\n");
        $parts = [$headers, 'X-RailTime-Template: '.$draft['header'], '', '--'.$alt,
            'Content-Type: text/plain; charset=utf-8', 'Content-Transfer-Encoding: base64', '', $encoded($text),
            '--'.$alt, 'Content-Type: multipart/related; boundary="'.$related.'"; type="text/html"', '',
            '--'.$related, 'Content-Type: text/html; charset=utf-8', 'Content-Transfer-Encoding: base64', '', $encoded($html)];
        foreach ($media as $name => $entry) {
            array_push($parts, '--'.$related, 'Content-Type: image/png; name="'.$name.'"', 'Content-Transfer-Encoding: base64',
                'Content-ID: <'.$entry['cid'].'>', 'Content-Disposition: inline; filename="'.$name.'"', '', $encoded($entry['bytes']));
        }
        array_push($parts, '--'.$related.'--', '--'.$alt.'--', '');

        return implode("\r\n", $parts);
    }
}
