<?php

namespace App\Support\Mail;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Part\DataPart;

/**
 * Ueberfuehrt die verlinkten RailTime-Bilder erst unmittelbar vor dem Versand
 * in echte MIME-Inline-Anhaenge. Vorschau, Editor und Exporte behalten damit
 * ihren jeweiligen Darstellungsweg, waehrend Antworten und Weiterleitungen
 * die Bilddateien zusammen mit der Systemmail erhalten.
 */
final class SystemMailInlineImageEmbedder
{
    public const RUNTIME_ATTRIBUTE = 'data-rt-system-mail-inline-assets="1"';

    private const TEMPLATE_START_MARKER = '<!-- RT_TEMPLATE_MARK_START -->';

    private const TEMPLATE_END_MARKER = '<!-- RT_TEMPLATE_MARK_END -->';

    private const HTML_ATTRIBUTE_PATTERN = '~\s+([a-z_:][a-z0-9_.:-]*)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'=<>`]+)))?~i';

    /** @var array<string, string> */
    private const MIME_BY_EXTENSION = [
        'gif' => 'image/gif',
        'jpeg' => 'image/jpeg',
        'jpg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
    ];

    /**
     * Kennzeichnet ausschliesslich den fertig kompilierten Systemmail-HTML-
     * Pfad. Gespeicherte Editor-Dokumente werden dadurch nicht veraendert.
     */
    public static function mark(string $html): string
    {
        if (str_contains($html, self::RUNTIME_ATTRIBUTE)) {
            return $html;
        }

        $marked = preg_replace(
            '/<body\b/i',
            '<body '.self::RUNTIME_ATTRIBUTE,
            $html,
            1,
            $count,
        );

        if (! is_string($marked) || $count !== 1) {
            return $html;
        }

        return $marked;
    }

    /**
     * @return int Anzahl neu eingebetteter, eindeutiger Dateien
     */
    public function embed(Email $message): int
    {
        $html = $message->getHtmlBody();

        if (! is_string($html) || ! $this->isRailTimeSystemMail($html)) {
            return 0;
        }

        $locations = $this->assetLocations();
        if ($locations === []) {
            return 0;
        }

        /** @var array<string, array{cid: string, filename: string, mime: string, path: string}> $assets */
        $assets = [];
        $rewritten = preg_replace_callback(
            '~<img\b(?:"[^"]*"|\'[^\']*\'|[^\'">])*>~i',
            function (array $tagMatch) use (&$assets, $locations): string {
                $tag = $tagMatch[0];

                return preg_replace_callback(
                    '~(\ssrc\s*=\s*)(["\'])(.*?)\2~is',
                    function (array $sourceMatch) use (&$assets, $locations): string {
                        $asset = $this->resolveAsset($sourceMatch[3], $locations);

                        if ($asset === null) {
                            return $sourceMatch[0];
                        }

                        $assets[$asset['path']] = $asset;

                        return $sourceMatch[1].$sourceMatch[2].'cid:'.$asset['cid'].$sourceMatch[2];
                    },
                    $tag,
                    1,
                ) ?? $tag;
            },
            $html,
        );

        if (is_string($rewritten)) {
            $rewritten = $this->embedSignatureBackgrounds($rewritten, $assets, $locations);
        }

        if (! is_string($rewritten) || $assets === []) {
            return 0;
        }

        $existingContentIds = [];
        foreach ($message->getAttachments() as $attachment) {
            if ($attachment->hasContentId()) {
                $existingContentIds[$attachment->getContentId()] = true;
            }
        }

        $message->html($rewritten, $message->getHtmlCharset() ?? 'utf-8');

        $embedded = 0;
        foreach ($assets as $asset) {
            if (isset($existingContentIds[$asset['cid']])) {
                continue;
            }

            $message->addPart(
                DataPart::fromPath($asset['path'], $asset['filename'], $asset['mime'])
                    ->asInline()
                    ->setContentId($asset['cid']),
            );
            $existingContentIds[$asset['cid']] = true;
            $embedded++;
        }

        return $embedded;
    }

    private function isRailTimeSystemMail(string $html): bool
    {
        return substr_count($html, self::RUNTIME_ATTRIBUTE) === 1
            && substr_count($html, self::TEMPLATE_START_MARKER) === 1
            && substr_count($html, self::TEMPLATE_END_MARKER) === 1;
    }

    /**
     * V22/V23 verwenden einen echten, optionalen CSS-Hintergrund. Nur der bereits
     * validierte direkte Signatur-Carrier darf hier eine lokale Bildreferenz
     * erhalten; beliebige CSS-URLs und andere Layoutversionen bleiben unberuehrt.
     *
     * @param  array<string, array{cid: string, filename: string, mime: string, path: string}>  $assets
     * @param  list<array{filename_pattern: string, host: string, namespace: string, path: string, port: int, root: string, scheme: string}>  $locations
     */
    private function embedSignatureBackgrounds(string $html, array &$assets, array $locations): string
    {
        return preg_replace_callback(
            '~(<tr\b(?:"[^"]*"|\'[^\']*\'|[^\'">])*>)(\s*)(<td\b(?:"[^"]*"|\'[^\']*\'|[^\'">])*>)~i',
            function (array $match) use (&$assets, $locations): string {
                $row = $this->tagAttributes($match[1]);
                $cell = $this->tagAttributes($match[3]);

                if (! SignatureArtifactVersion::usesOptionalBackground($row['data-rt-artifact-version']['value'] ?? null)
                    || ($cell['data-rt-signature-background']['value'] ?? null) !== '1'
                    || ! preg_match('/(?:\A|\s)rt-sign-cell(?:\s|\z)/', $cell['class']['value'] ?? '')
                    || ! isset($cell['style'])) {
                    return $match[0];
                }

                $style = $cell['style']['value'];
                if (preg_match_all('/(?:\A|;)\s*background-image\s*:/i', $style) !== 1) {
                    return $match[0];
                }

                $rewrittenStyle = preg_replace_callback(
                    '~((?:\A|;)\s*background-image\s*:\s*)url\(\s*(?:(["\'])(.*?)\2|([^\s"\'()]+))\s*\)(\s*(?:!important\s*)?)(?=;|\z)~is',
                    function (array $sourceMatch) use (&$assets, $locations): string {
                        $asset = $this->resolveAsset($sourceMatch[3] !== '' ? $sourceMatch[3] : $sourceMatch[4], $locations);
                        if ($asset === null) {
                            return $sourceMatch[0];
                        }

                        $assets[$asset['path']] = $asset;

                        return $sourceMatch[1]."url('cid:".$asset['cid']."')".$sourceMatch[5];
                    },
                    $style,
                );

                if (! is_string($rewrittenStyle) || $rewrittenStyle === $style) {
                    return $match[0];
                }

                $rewrittenCell = substr_replace(
                    $match[3],
                    ' style="'.htmlspecialchars($rewrittenStyle, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8').'"',
                    $cell['style']['offset'],
                    $cell['style']['length'],
                );

                return $match[1].$match[2].$rewrittenCell;
            },
            $html,
        ) ?? $html;
    }

    /** @return array<string, array{value: string, offset: int, length: int}> */
    private function tagAttributes(string $tag): array
    {
        preg_match_all(self::HTML_ATTRIBUTE_PATTERN, $tag, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL);
        $attributes = [];
        foreach ($matches as $match) {
            $name = strtolower($match[1][0]);
            if (isset($attributes[$name])) {
                return [];
            }

            $attributes[$name] = [
                'value' => html_entity_decode($match[2][0] ?? $match[3][0] ?? $match[4][0] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                'offset' => $match[0][1],
                'length' => strlen($match[0][0]),
            ];
        }

        return $attributes;
    }

    /**
     * @param  list<array{filename_pattern: string, host: string, namespace: string, path: string, port: int, root: string, scheme: string}>  $locations
     * @return array{cid: string, filename: string, mime: string, path: string}|null
     */
    private function resolveAsset(string $encodedSource, array $locations): ?array
    {
        $source = html_entity_decode($encodedSource, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $parts = parse_url($source);

        if (! is_array($parts)
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['fragment'])
            || ! isset($parts['scheme'], $parts['host'], $parts['path'])) {
            return null;
        }

        foreach ($locations as $location) {
            if (strtolower($parts['scheme']) !== $location['scheme']
                || strtolower($parts['host']) !== $location['host']
                || $this->effectivePort($parts) !== $location['port']) {
                continue;
            }

            $rawPath = (string) $parts['path'];
            if (str_contains($rawPath, '%') || ! str_starts_with($rawPath, $location['path'])) {
                continue;
            }

            $filename = substr($rawPath, strlen($location['path']));
            if (! preg_match($location['filename_pattern'], $filename, $match)) {
                continue;
            }

            $root = realpath($location['root']);
            $path = realpath($location['root'].DIRECTORY_SEPARATOR.$filename);
            if ($root === false
                || $path === false
                || strcasecmp(dirname($path), $root) !== 0
                || ! is_file($path)
                || ! is_readable($path)) {
                continue;
            }

            $extension = strtolower($match[1]);
            $mime = self::MIME_BY_EXTENSION[$extension] ?? null;
            if ($mime === null || mime_content_type($path) !== $mime) {
                continue;
            }

            return [
                'cid' => 'railtime-'.substr(
                    hash('sha256', $location['namespace'].'/'.strtolower($filename)),
                    0,
                    24,
                ).'@inline.rail-time.de',
                'filename' => $filename,
                'mime' => $mime,
                'path' => $path,
            ];
        }

        return null;
    }

    /**
     * @return list<array{filename_pattern: string, host: string, namespace: string, path: string, port: int, root: string, scheme: string}>
     */
    private function assetLocations(): array
    {
        $probeFilename = '__railtime_inline_probe__.png';
        $locations = [
            $this->assetLocation(
                URL::asset('mail-assets/'.$probeFilename),
                $probeFilename,
                public_path('mail-assets'),
                'mail-assets',
                '/\A[A-Za-z0-9][A-Za-z0-9._-]*\.(gif|jpe?g|png|webp)\z/i',
            ),
            $this->assetLocation(
                URL::to(Storage::disk('public')->url('mail-imports/'.$probeFilename)),
                $probeFilename,
                Storage::disk('public')->path('mail-imports'),
                'mail-imports',
                '/\A[a-f0-9]{64}\.(gif|jpg|png|webp)\z/i',
            ),
        ];

        return array_values(array_filter($locations));
    }

    /**
     * @return array{filename_pattern: string, host: string, namespace: string, path: string, port: int, root: string, scheme: string}|null
     */
    private function assetLocation(
        string $probeUrl,
        string $probeFilename,
        string $root,
        string $namespace,
        string $filenamePattern,
    ): ?array {
        $parts = parse_url($probeUrl);

        if (! is_array($parts)
            || ! isset($parts['scheme'], $parts['host'], $parts['path'])
            || ! str_ends_with($parts['path'], $probeFilename)) {
            return null;
        }

        return [
            'filename_pattern' => $filenamePattern,
            'host' => strtolower($parts['host']),
            'namespace' => $namespace,
            'path' => substr($parts['path'], 0, -strlen($probeFilename)),
            'port' => $this->effectivePort($parts),
            'root' => $root,
            'scheme' => strtolower($parts['scheme']),
        ];
    }

    /** @param array<string, mixed> $parts */
    private function effectivePort(array $parts): int
    {
        if (isset($parts['port'])) {
            return (int) $parts['port'];
        }

        return strtolower((string) ($parts['scheme'] ?? '')) === 'https' ? 443 : 80;
    }
}
