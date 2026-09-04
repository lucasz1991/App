<?php

namespace App\Support\OutlookAddin;

use App\Enums\MailDocumentKind;
use App\Models\User;
use App\Support\EmailTemplateBuilder;
use App\Support\Mail\PublishedMailDocumentSnapshotStore;
use App\Support\Mail\SignatureArtifactVersion;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

final class OutlookAddinPayloadService
{
    /** Bei jeder Aenderung der Compilersemantik bewusst anheben. */
    private const RENDERER_REVISION = 4;

    private const MAX_SIGNATURE_CHARACTERS = 30000;

    private const MAX_MEDIA_BYTES = 2097152;

    private const MAX_DATA_IMAGE_BYTES = 512000;

    private const MAX_IMAGE_DIMENSION = 4096;

    private const MAX_IMAGE_PIXELS = 16000000;

    /** @var array<string, string> */
    private const MIME_BY_EXTENSION = [
        'gif' => 'image/gif',
        'jpeg' => 'image/jpeg',
        'jpg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
    ];

    /** @var array<string, string> */
    private const EXTENSION_BY_MIME = [
        'image/gif' => 'gif',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    private const TRANSPARENT_PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M/wHwAF/gL+Qn5ZAAAAAElFTkSuQmCC';

    private const SIGNATURE_RESPONSIVE_CSS = <<<'CSS'
<style>
table,td{border-collapse:collapse;mso-table-lspace:0;mso-table-rspace:0}img{border:0;outline:none;text-decoration:none}.rt-sign-stage,.rt-sign-content-frame{width:100%}.rt-sign-train-layer{overflow:hidden}.rt-sign-content{position:relative;z-index:1}
@media only screen and (max-width:600px){
.rt-pad{box-sizing:border-box!important;padding-left:16px!important;padding-right:16px!important}.rt-sign-stage,.rt-sign-content-frame{height:auto!important;max-height:none!important;min-height:0!important;overflow:visible!important}.rt-sign-train-layer{position:static!important;width:100%!important;height:auto!important;max-height:none!important;max-width:none!important;margin:0!important;overflow:hidden!important}.rt-sign-train-frame,.rt-sign-train-slot{height:auto!important}.rt-sign-train,.rt-sign-train-mso{display:block!important;width:135%!important;max-width:none!important;height:auto!important;margin:0 0 0 -35%!important}.rt-sign-layout,.rt-sign-layout>tbody,.rt-sign-top-row,.rt-sign-company-row{display:block!important;width:100%!important}.rt-sign-top-row>td,.rt-sign-company-row>td{box-sizing:border-box!important;display:block!important;width:100%!important}.rt-sign-logo{padding:12px 0 14px!important;text-align:left!important}.rt-sign-logo img{width:150px!important;margin-left:0!important}.rt-sign-identity{padding:10px 0 0!important}.rt-sign-company{padding:14px 0 0!important;border-left:0!important;border-top:1px solid #dfe3e6!important;text-align:left!important}.rt-company-contact{float:none!important;display:table!important;width:100%!important;margin:10px 0 0!important}.rt-company-contact td{text-align:left!important}.rt-contact img{width:18px!important;height:18px!important}.rt-contact td.rt-contact-icon{width:18px!important}.rt-contact td.rt-contact-text{padding-left:7px!important;font-size:12px!important;line-height:17px!important}
}
</style>
CSS;

    public function __construct(
        private readonly PublishedMailDocumentSnapshotStore $publishedDocuments,
    ) {}

    /**
     * Vollstaendiger Eingabefingerabdruck fuer einen personenbezogenen Abzug.
     *
     * Er basiert ausdruecklich auf den veroeffentlichten Bytes statt auf dem
     * Entwurfs-Content-Hash und schliesst die wirklich transportierten
     * statischen Medien ein.
     */
    public function sourceFingerprint(User $user): string
    {
        try {
            $templateSnapshot = $this->publishedDocuments->freshSnapshot(MailDocumentKind::Template);
            $signatureSnapshot = $this->publishedDocuments->freshSnapshot(MailDocumentKind::Signature);

            if ($templateSnapshot === null || $signatureSnapshot === null) {
                throw new RuntimeException('Vorlage und Signatur muessen aktiv veroeffentlicht sein.');
            }

            $profileValues = (new EmailTemplateBuilder($user))->profileValues();
            ksort($profileValues);

            $input = [
                'payload_schema' => 1,
                'renderer_revision' => self::RENDERER_REVISION,
                'marker' => (string) config('outlook_addin.marker', 'RT-SIGNATURE-MANAGED-V1'),
                'user_id' => (int) $user->getKey(),
                'user_active' => $user->isActive(),
                'email_verified_at' => $user->email_verified_at?->toIso8601String(),
                'profile' => $profileValues,
                'published' => [
                    'signature' => hash('sha256', $signatureSnapshot['html']."\0".$signatureSnapshot['css']),
                    'template' => hash('sha256', $templateSnapshot['html']."\0".$templateSnapshot['css']),
                ],
                'assets' => $this->sourceAssetHashes(
                    $templateSnapshot['html'],
                    $templateSnapshot['css'],
                    $signatureSnapshot['html'],
                    $signatureSnapshot['css'],
                ),
            ];

            return hash('sha256', json_encode(
                $input,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ));
        } catch (OutlookAddinException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new OutlookAddinException(
                'Der aktuelle Outlook-Abzug konnte nicht bestimmt werden.',
                409,
                'outlook_addin_snapshot_source_invalid',
                $exception,
            );
        }
    }

    /** @return array<string, mixed> */
    public function forUser(User $user): array
    {
        try {
            $templateSnapshot = EmailTemplateBuilder::publishedDocumentSnapshot(MailDocumentKind::Template);
            $signatureSnapshot = EmailTemplateBuilder::publishedDocumentSnapshot(MailDocumentKind::Signature);

            if ($templateSnapshot === null || $signatureSnapshot === null) {
                throw new RuntimeException('Vorlage und Signatur muessen aktiv veroeffentlicht sein.');
            }

            $builder = new EmailTemplateBuilder($user);
            [$signatureHtml, $signatureMedia] = $this->localizeRemoteImages(
                $this->withMarker(
                    self::SIGNATURE_RESPONSIVE_CSS.$builder->buildOutlookAddinSignatureHtml('light'),
                ),
            );
            $signatureHtml = $this->compactSignature($signatureHtml);
            [$templateHtml, $templateMedia] = $this->materializeTemplateCids(
                $this->withMarker($builder->buildOutlookAddinTemplateHtml('light')),
                $signatureSnapshot['html'],
            );

            if (mb_strlen($signatureHtml, 'UTF-8') > self::MAX_SIGNATURE_CHARACTERS) {
                throw new RuntimeException('Die veroeffentlichte Signatur ueberschreitet das Outlook-Limit von 30.000 Zeichen ('.mb_strlen($signatureHtml, 'UTF-8').').');
            }

            return [
                'schema' => 1,
                'marker' => (string) config('outlook_addin.marker', 'RT-SIGNATURE-MANAGED-V1'),
                'signature' => [
                    'html' => $signatureHtml,
                    'media' => $signatureMedia,
                ],
                'template' => [
                    'html' => $templateHtml,
                    'media' => $templateMedia,
                ],
                'version' => [
                    'signature' => $this->snapshotHash($signatureSnapshot),
                    'template' => $this->snapshotHash($templateSnapshot),
                ],
            ];
        } catch (OutlookAddinException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new OutlookAddinException(
                'Die veroeffentlichte Outlook-Vorlage ist noch nicht einsatzbereit.',
                409,
                'outlook_addin_publication_invalid',
                $exception,
            );
        }
    }

    private function withMarker(string $html): string
    {
        $marker = preg_replace(
            '/[^A-Z0-9_-]/i',
            '',
            (string) config('outlook_addin.marker', 'RT-SIGNATURE-MANAGED-V1'),
        ) ?: 'RT-SIGNATURE-MANAGED-V1';

        return "<!-- {$marker} -->\n"
            .'<span aria-hidden="true" style="display:none!important;mso-hide:all;font-size:0;line-height:0;max-height:0;overflow:hidden;">'
            .$marker
            .'</span>'
            .$html;
    }

    private function compactSignature(string $html): string
    {
        $html = preg_replace_callback(
            '~<!--(.*?)-->~s',
            static function (array $match): string {
                $comment = (string) $match[1];

                return str_contains($comment, '[if')
                    || str_contains($comment, 'RT-SIGNATURE-MANAGED-V1')
                    ? $match[0]
                    : '';
            },
            $html,
        ) ?? $html;
        $html = preg_replace('~>\s+<~', '><', $html) ?? $html;

        return trim($html);
    }

    /**
     * @return array{0: string, 1: list<array{name: string, contentId: string, base64: string}>}
     */
    private function localizeRemoteImages(string $html, array $knownCidAttachments = []): array
    {
        $mediaByContentId = [];
        $knownByAlias = [];
        foreach ($knownCidAttachments as $alias => $attachment) {
            if (! is_string($alias) || ! is_array($attachment)) {
                throw new RuntimeException('Der Outlook-CID-Vertrag ist ungueltig.');
            }

            $this->assertContentId($alias);
            $this->registerAttachment($mediaByContentId, $attachment);
            $knownByAlias[strtolower($alias)] = $attachment;
        }

        $mediaByPath = [];
        $localize = function (string $encodedSource) use (&$mediaByContentId, &$mediaByPath, $knownByAlias): string {
            $source = $this->decodedImageSource($encodedSource);
            if (str_starts_with(strtolower($source), 'cid:')) {
                $contentId = substr($source, 4);
                $this->assertContentId($contentId);
                $attachment = $knownByAlias[strtolower($contentId)]
                    ?? $mediaByContentId[$contentId]
                    ?? null;
                if (! is_array($attachment)) {
                    throw new RuntimeException("Das Outlook-Medium {$contentId} besitzt keinen freigegebenen CID-Anhang.");
                }

                return 'cid:'.$attachment['contentId'];
            }

            if (str_starts_with(strtolower($source), 'data:')) {
                $attachment = $this->dataImageAttachment($source);
                $this->registerAttachment($mediaByContentId, $attachment);

                return 'cid:'.$attachment['contentId'];
            }

            $path = $this->preferStaticPng($this->mailAssetPath($source));
            if (! array_key_exists($path, $mediaByPath)) {
                $mediaByPath[$path] = $this->attachment($path);
                $this->registerAttachment($mediaByContentId, $mediaByPath[$path]);
            }

            return 'cid:'.$mediaByPath[$path]['contentId'];
        };

        $replaced = $this->rewriteHtmlImageSources($html, $localize);
        $replaced = $this->rewriteCssImageSources($replaced, $localize);
        $media = array_values($mediaByContentId);

        $this->assertMediaSize($media);
        $this->assertCidAttachmentContract($replaced, $media);

        return [$replaced, $media];
    }

    private function mailAssetPath(string $source): string
    {
        $parts = parse_url($source);
        if (! is_array($parts)
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['fragment'])
            || ! in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
            || ! isset($parts['scheme'], $parts['host'], $parts['path'])) {
            throw new RuntimeException('Die Signatur enthaelt eine nicht freigegebene Bildquelle.');
        }

        foreach ($this->assetLocations() as $location) {
            $rawPath = (string) $parts['path'];
            if (str_contains($rawPath, '%') || ! str_starts_with($rawPath, $location['path'])) {
                continue;
            }

            $filename = substr($rawPath, strlen($location['path']));
            if (preg_match($location['filename_pattern'], $filename) !== 1) {
                continue;
            }

            $root = realpath($location['root']);
            $file = realpath($location['root'].DIRECTORY_SEPARATOR.$filename);
            if ($root !== false
                && $file !== false
                && strcasecmp(dirname($file), $root) === 0
                && is_file($file)
                && is_readable($file)) {
                return $file;
            }
        }

        throw new RuntimeException(
            'Die Signatur enthaelt eine nicht freigegebene oder fehlende Bildquelle: '.substr($source, 0, 180),
        );
    }

    private function preferStaticPng(string $path): string
    {
        if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) !== 'gif') {
            return $path;
        }

        $png = preg_replace('/\.gif$/i', '.png', $path);

        return is_string($png) && is_file($png) ? $png : $path;
    }

    /** @return array{name: string, contentId: string, base64: string} */
    private function attachment(string $path, ?string $contentId = null): array
    {
        $binary = file_get_contents($path);
        if (! is_string($binary)) {
            throw new RuntimeException('Ein Outlook-Signaturbild konnte nicht gelesen werden.');
        }

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $expectedMime = self::MIME_BY_EXTENSION[$extension] ?? null;
        if ($expectedMime === null) {
            throw new RuntimeException('Die Signatur enthaelt ein nicht unterstuetztes Bildformat.');
        }

        return $this->attachmentFromBinary($binary, $expectedMime, $contentId, self::MAX_MEDIA_BYTES);
    }

    /** @return array{name: string, contentId: string, base64: string} */
    private function dataImageAttachment(string $source): array
    {
        if (preg_match(
            '#\Adata:\s*(image/(?:png|gif|jpe?g|webp))\s*;\s*base64\s*,([A-Za-z0-9+/]*={0,2})\z#i',
            $source,
            $matches,
        ) !== 1 || strlen($matches[2]) % 4 !== 0) {
            throw new RuntimeException('Das eingebettete Outlook-Bild ist nicht gueltig base64-kodiert.');
        }

        $binary = base64_decode($matches[2], true);
        if (! is_string($binary)) {
            throw new RuntimeException('Das eingebettete Outlook-Bild ist nicht gueltig base64-kodiert.');
        }

        return $this->attachmentFromBinary(
            $binary,
            $this->normalizeImageMime((string) $matches[1]),
            maxBytes: self::MAX_DATA_IMAGE_BYTES,
        );
    }

    /** @return array{name: string, contentId: string, base64: string} */
    private function attachmentFromBinary(
        string $binary,
        string $expectedMime,
        ?string $contentId = null,
        int $maxBytes = self::MAX_MEDIA_BYTES,
    ): array {
        $image = $this->inspectImageBinary($binary, $expectedMime, $maxBytes);
        $extension = self::EXTENSION_BY_MIME[$image['mime']];
        $contentId ??= 'railtime-'.substr(hash('sha256', $binary), 0, 20).'.'.$extension;
        $this->assertContentId($contentId);

        if (! str_ends_with(strtolower($contentId), '.'.$extension)) {
            throw new RuntimeException('Dateiname und Bildtyp des Outlook-Anhangs stimmen nicht ueberein.');
        }

        return [
            'name' => $contentId,
            'contentId' => $contentId,
            'base64' => base64_encode($binary),
        ];
    }

    /**
     * @return array{0: string, 1: list<array{name: string, contentId: string, base64: string}>}
     */
    private function materializeTemplateCids(string $html, string $signatureDocument): array
    {
        $mapping = $this->templateMediaPaths($signatureDocument);
        $requested = $this->referencedContentIds($html);
        $knownCidAttachments = [];

        foreach ($requested as $contentId) {
            $mappingId = strtolower($contentId);
            if ($mappingId === 'railtime-train-idle') {
                $binary = base64_decode(self::TRANSPARENT_PNG, true);
                if (! is_string($binary)) {
                    throw new RuntimeException('Das Outlook-Standbild ist beschaedigt.');
                }

                $knownCidAttachments[$contentId] = $this->attachmentFromBinary(
                    $binary,
                    'image/png',
                    'railtime-train-idle.png',
                );

                continue;
            }

            $path = $mapping[$mappingId] ?? null;
            if (! is_string($path) || ! is_file($path) || ! is_readable($path)) {
                throw new RuntimeException("Das Outlook-Medium {$contentId} fehlt.");
            }

            $knownCidAttachments[$contentId] = $this->attachment($path);
        }

        return $this->localizeRemoteImages($html, $knownCidAttachments);
    }

    /** @return array<string, string> */
    private function templateMediaPaths(string $signatureDocument): array
    {
        $artifactVersion = SignatureArtifactVersion::detect(
            MailDocumentKind::Signature,
            $signatureDocument,
        );
        $mark = str_replace('.gif', '.png', EmailTemplateBuilder::emailMarkAsset('light', $artifactVersion));
        $logo = str_replace('.gif', '.png', EmailTemplateBuilder::signatureLogoAsset('light', $artifactVersion));
        $train = $this->mailAssetPath(EmailTemplateBuilder::signatureTrainStillUrl('light', $artifactVersion));

        return [
            'railtime-mark' => public_path('mail-assets/'.$mark),
            'railtime-mark-still' => public_path('mail-assets/'.$mark),
            'railtime-logo' => public_path('mail-assets/'.$logo),
            'railtime-logo-still' => public_path('mail-assets/'.$logo),
            'railtime-train' => $train,
            'railtime-train-still' => $train,
            'railtime-icon-location' => public_path('mail-assets/contact-location.png'),
            'railtime-icon-phone' => public_path('mail-assets/contact-phone.png'),
            'railtime-icon-mobile' => public_path('mail-assets/contact-mobile.png'),
            'railtime-icon-email' => public_path('mail-assets/contact-email.png'),
            'railtime-icon-web' => public_path('mail-assets/contact-web.png'),
        ];
    }

    /** @return array<string, string> */
    private function sourceAssetHashes(string ...$documents): array
    {
        $signatureDocument = $documents[2] ?? '';
        $mapping = $this->templateMediaPaths($signatureDocument);
        $paths = array_values($mapping);
        $sources = [
            ...$this->imageSources($documents[0] ?? ''),
            ...$this->cssImageSources($documents[1] ?? ''),
            ...$this->imageSources($documents[2] ?? ''),
            ...$this->cssImageSources($documents[3] ?? ''),
        ];
        $hashes = [];

        foreach (array_values(array_unique($sources)) as $encodedSource) {
            $source = $this->decodedImageSource((string) $encodedSource);
            if (str_contains($source, '{{')) {
                continue;
            }

            if (str_starts_with(strtolower($source), 'cid:')) {
                $contentId = substr($source, 4);
                $this->assertContentId($contentId);
                $mappingId = strtolower($contentId);
                if ($mappingId !== 'railtime-train-idle' && ! array_key_exists($mappingId, $mapping)) {
                    throw new RuntimeException("Das Outlook-Medium {$contentId} ist nicht bekannt.");
                }

                continue;
            }

            if (str_starts_with(strtolower($source), 'data:')) {
                $attachment = $this->dataImageAttachment($source);
                $hashes['inline:'.$attachment['contentId']] = hash(
                    'sha256',
                    (string) base64_decode($attachment['base64'], true),
                );

                continue;
            }

            $paths[] = $this->preferStaticPng($this->mailAssetPath($source));
        }

        foreach (array_values(array_unique($paths)) as $path) {
            if (! is_file($path) || ! is_readable($path)) {
                throw new RuntimeException('Ein Outlook-Medium fehlt auf dem Server.');
            }

            $attachment = $this->attachment($path);
            $binary = base64_decode($attachment['base64'], true);
            if (! is_string($binary)) {
                throw new RuntimeException('Ein Outlook-Medium konnte nicht geprueft werden.');
            }

            $realPath = realpath($path) ?: $path;
            $hashes['file:'.hash('sha256', strtolower(str_replace('\\', '/', $realPath)))] = hash('sha256', $binary);
        }

        ksort($hashes);

        return $hashes;
    }

    private function decodedImageSource(string $source): string
    {
        $decoded = trim(html_entity_decode($source, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if (strlen($decoded) >= 2
            && (($decoded[0] === '"' && str_ends_with($decoded, '"'))
                || ($decoded[0] === "'" && str_ends_with($decoded, "'")))) {
            $decoded = trim(substr($decoded, 1, -1));
        }

        return $decoded;
    }

    /**
     * @return array{mime: string, width: int, height: int}
     */
    private function inspectImageBinary(string $binary, string $expectedMime, int $maxBytes): array
    {
        if ($binary === '' || strlen($binary) > $maxBytes) {
            throw new RuntimeException('Das Outlook-Bild ueberschreitet das sichere Dateilimit.');
        }

        set_error_handler(static fn (): bool => true);
        try {
            $image = getimagesizefromstring($binary);
        } finally {
            restore_error_handler();
        }

        $expectedMime = $this->normalizeImageMime($expectedMime);
        $actualMime = is_array($image)
            ? $this->normalizeImageMime((string) ($image['mime'] ?? ''))
            : '';
        $width = is_array($image) ? (int) ($image[0] ?? 0) : 0;
        $height = is_array($image) ? (int) ($image[1] ?? 0) : 0;

        if (! isset(self::EXTENSION_BY_MIME[$actualMime])
            || $actualMime !== $expectedMime
            || $width < 1
            || $height < 1
            || $width > self::MAX_IMAGE_DIMENSION
            || $height > self::MAX_IMAGE_DIMENSION
            || ($width * $height) > self::MAX_IMAGE_PIXELS) {
            throw new RuntimeException('Das Outlook-Medium ist kein erlaubtes oder typkorrektes Bild.');
        }

        return [
            'mime' => $actualMime,
            'width' => $width,
            'height' => $height,
        ];
    }

    private function normalizeImageMime(string $mime): string
    {
        $mime = strtolower(trim($mime));

        return $mime === 'image/jpg' ? 'image/jpeg' : $mime;
    }

    /** @param callable(string): string $localize */
    private function rewriteHtmlImageSources(string $html, callable $localize): string
    {
        $replaced = preg_replace_callback(
            "~<[A-Za-z][A-Za-z0-9:-]*(?:\"[^\"]*\"|'[^']*'|[^'\">])*>~s",
            fn (array $match): string => $this->rewriteTagAttributeValues(
                (string) $match[0],
                ['background', 'src'],
                static fn (string $source): string => htmlspecialchars(
                    $localize($source),
                    ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5,
                    'UTF-8',
                ),
            ),
            $html,
        );

        if (! is_string($replaced)) {
            throw new RuntimeException('Die HTML-Bildquellen konnten nicht in CID-Anhaenge umgewandelt werden.');
        }

        return $replaced;
    }

    /** @param callable(string): string $localize */
    private function rewriteCssImageSources(string $html, callable $localize): string
    {
        $replaced = preg_replace_callback(
            '~(<style\b[^>]*>)(.*?)(</style\s*>)~is',
            fn (array $match): string => $match[1]
                .$this->rewriteCssValue((string) $match[2], $localize)
                .$match[3],
            $html,
        );
        if (! is_string($replaced)) {
            throw new RuntimeException('Die CSS-Bildquellen konnten nicht in CID-Anhaenge umgewandelt werden.');
        }

        $replaced = preg_replace_callback(
            "~<[A-Za-z][A-Za-z0-9:-]*(?:\"[^\"]*\"|'[^']*'|[^'\">])*>~s",
            fn (array $match): string => $this->rewriteTagAttributeValues(
                (string) $match[0],
                ['style'],
                fn (string $css): string => $this->rewriteCssValue($css, $localize),
            ),
            $replaced,
        );
        if (! is_string($replaced)) {
            throw new RuntimeException('Die Inline-CSS-Bildquellen konnten nicht in CID-Anhaenge umgewandelt werden.');
        }

        return $replaced;
    }

    /** @param callable(string): string $localize */
    private function rewriteCssValue(string $css, callable $localize): string
    {
        $replaced = preg_replace_callback(
            '~url\(\s*(?:(["\'])(.*?)\1|([^)]*?))\s*\)~is',
            static function (array $match) use ($localize): string {
                $source = isset($match[1]) && $match[1] !== ''
                    ? (string) ($match[2] ?? '')
                    : (string) ($match[3] ?? '');

                // Der erzeugte CID-Wert besteht nur aus dem streng geprueften
                // ASCII-Vorrat und benoetigt deshalb keine CSS-Anfuehrung.
                return 'url('.$localize($source).')';
            },
            $css,
        );

        if (! is_string($replaced)) {
            throw new RuntimeException('Eine CSS-Bildquelle konnte nicht verarbeitet werden.');
        }

        return $replaced;
    }

    /**
     * Aendert ausschliesslich vollstaendige Attributtoken eines Starttags.
     * Texte innerhalb anderer Attributwerte sowie data-src/data-background
     * sind damit keine Treffer.
     *
     * @param  list<string>  $names
     * @param  callable(string): string  $transform
     */
    private function rewriteTagAttributeValues(string $tag, array $names, callable $transform): string
    {
        $names = array_fill_keys(array_map('strtolower', $names), true);
        $edits = [];

        foreach ($this->tagAttributes($tag) as $attribute) {
            if (! isset($names[$attribute['name']])) {
                continue;
            }

            $value = substr($tag, $attribute['value_start'], $attribute['value_length']);
            $edits[] = [
                'length' => $attribute['value_length'],
                'start' => $attribute['value_start'],
                'value' => $transform($value),
            ];
        }

        usort($edits, static fn (array $left, array $right): int => $right['start'] <=> $left['start']);
        foreach ($edits as $edit) {
            $tag = substr_replace($tag, $edit['value'], $edit['start'], $edit['length']);
        }

        return $tag;
    }

    /**
     * @return list<array{name: string, value_start: int, value_length: int}>
     */
    private function tagAttributes(string $tag): array
    {
        $attributes = [];
        $length = strlen($tag);
        $cursor = 1;

        while ($cursor < $length
            && ! $this->isTagWhitespace($tag[$cursor])
            && ! in_array($tag[$cursor], ['/', '>'], true)) {
            $cursor++;
        }

        while ($cursor < $length) {
            while ($cursor < $length && $this->isTagWhitespace($tag[$cursor])) {
                $cursor++;
            }

            if ($cursor >= $length || $tag[$cursor] === '>') {
                break;
            }
            if ($tag[$cursor] === '/') {
                $cursor++;

                continue;
            }

            $nameStart = $cursor;
            while ($cursor < $length
                && ! $this->isTagWhitespace($tag[$cursor])
                && ! in_array($tag[$cursor], ['=', '/', '>'], true)) {
                $cursor++;
            }

            if ($cursor === $nameStart) {
                $cursor++;

                continue;
            }

            $name = strtolower(substr($tag, $nameStart, $cursor - $nameStart));
            while ($cursor < $length && $this->isTagWhitespace($tag[$cursor])) {
                $cursor++;
            }
            if ($cursor >= $length || $tag[$cursor] !== '=') {
                continue;
            }

            $cursor++;
            while ($cursor < $length && $this->isTagWhitespace($tag[$cursor])) {
                $cursor++;
            }
            if ($cursor >= $length) {
                break;
            }

            $quote = in_array($tag[$cursor], ['"', "'"], true) ? $tag[$cursor] : null;
            if ($quote !== null) {
                $cursor++;
                $valueStart = $cursor;
                while ($cursor < $length && $tag[$cursor] !== $quote) {
                    $cursor++;
                }
                $valueLength = $cursor - $valueStart;
                if ($cursor < $length) {
                    $cursor++;
                }
            } else {
                $valueStart = $cursor;
                while ($cursor < $length
                    && ! $this->isTagWhitespace($tag[$cursor])
                    && $tag[$cursor] !== '>') {
                    $cursor++;
                }
                $valueLength = $cursor - $valueStart;
            }

            $attributes[] = [
                'name' => $name,
                'value_start' => $valueStart,
                'value_length' => $valueLength,
            ];
        }

        return $attributes;
    }

    private function isTagWhitespace(string $character): bool
    {
        return str_contains(" \t\r\n\f", $character);
    }

    /** @return list<string> */
    private function imageSources(string $html): array
    {
        $sources = [];
        preg_match_all(
            "~<[A-Za-z][A-Za-z0-9:-]*(?:\"[^\"]*\"|'[^']*'|[^'\">])*>~s",
            $html,
            $tags,
        );
        foreach ($tags[0] ?? [] as $tag) {
            foreach ($this->tagAttributes((string) $tag) as $attribute) {
                $value = substr((string) $tag, $attribute['value_start'], $attribute['value_length']);
                if (in_array($attribute['name'], ['background', 'src'], true)) {
                    $sources[] = $value;
                } elseif ($attribute['name'] === 'style') {
                    array_push($sources, ...$this->cssImageSources($value));
                }
            }
        }

        preg_match_all('~<style\b[^>]*>(.*?)</style\s*>~is', $html, $styleBlocks);
        foreach ($styleBlocks[1] ?? [] as $css) {
            array_push($sources, ...$this->cssImageSources((string) $css));
        }

        return $sources;
    }

    /** @return list<string> */
    private function cssImageSources(string $css): array
    {
        preg_match_all(
            '~url\(\s*(?:(["\'])(.*?)\1|([^)]*?))\s*\)~is',
            $css,
            $matches,
            PREG_SET_ORDER,
        );

        return array_map(
            static fn (array $match): string => isset($match[1]) && $match[1] !== ''
                ? (string) ($match[2] ?? '')
                : (string) ($match[3] ?? ''),
            $matches,
        );
    }

    /** @return list<string> */
    private function referencedContentIds(string $html): array
    {
        $contentIds = [];
        foreach ($this->imageSources($html) as $encodedSource) {
            $source = $this->decodedImageSource($encodedSource);
            if (! str_starts_with(strtolower($source), 'cid:')) {
                continue;
            }

            $contentId = substr($source, 4);
            $this->assertContentId($contentId);
            $contentIds[] = $contentId;
        }

        return array_values(array_unique($contentIds));
    }

    private function assertContentId(string $contentId): void
    {
        if (preg_match('/\A[A-Za-z0-9](?:[A-Za-z0-9._@-]{0,190}[A-Za-z0-9])?\z/D', $contentId) !== 1) {
            throw new RuntimeException('Die Outlook-Vorlage enthaelt eine ungueltige CID-Referenz.');
        }
    }

    /**
     * @param  array<string, array{name: string, contentId: string, base64: string}>  $mediaByContentId
     * @param  array{name: string, contentId: string, base64: string}  $attachment
     */
    private function registerAttachment(array &$mediaByContentId, array $attachment): void
    {
        $name = $attachment['name'] ?? null;
        $contentId = $attachment['contentId'] ?? null;
        $encoded = $attachment['base64'] ?? null;
        if (! is_string($name)
            || ! is_string($contentId)
            || ! is_string($encoded)
            || $name !== $contentId
            || preg_match('/\A[A-Za-z0-9+\/]*={0,2}\z/D', $encoded) !== 1
            || strlen($encoded) % 4 !== 0) {
            throw new RuntimeException('Ein Outlook-CID-Anhang ist ungueltig.');
        }

        $this->assertContentId($contentId);
        $extension = strtolower(pathinfo($contentId, PATHINFO_EXTENSION));
        $expectedMime = self::MIME_BY_EXTENSION[$extension] ?? null;
        $binary = base64_decode($encoded, true);
        if ($expectedMime === null || ! is_string($binary)) {
            throw new RuntimeException('Ein Outlook-CID-Anhang besitzt keinen sicheren Bildtyp.');
        }
        $this->inspectImageBinary($binary, $expectedMime, self::MAX_MEDIA_BYTES);

        $existing = $mediaByContentId[$contentId] ?? null;
        if (is_array($existing) && $existing !== $attachment) {
            throw new RuntimeException("Die CID {$contentId} ist mehreren unterschiedlichen Anhaengen zugeordnet.");
        }

        $mediaByContentId[$contentId] = $attachment;
    }

    /** @param list<array{name: string, contentId: string, base64: string}> $media */
    private function assertCidAttachmentContract(string $html, array $media): void
    {
        $attachmentCounts = [];
        foreach ($media as $attachment) {
            $contentId = (string) ($attachment['contentId'] ?? '');
            $this->assertContentId($contentId);
            $attachmentCounts[$contentId] = ($attachmentCounts[$contentId] ?? 0) + 1;
        }

        $referenced = [];
        foreach ($this->imageSources($html) as $encodedSource) {
            $source = $this->decodedImageSource($encodedSource);
            if (! str_starts_with(strtolower($source), 'cid:')) {
                throw new RuntimeException('Die Outlook-Vorlage enthaelt eine nicht portable Bildquelle.');
            }

            $contentId = substr($source, 4);
            $this->assertContentId($contentId);
            if (($attachmentCounts[$contentId] ?? 0) !== 1) {
                throw new RuntimeException("Die CID {$contentId} benoetigt genau einen Bildanhang.");
            }
            $referenced[$contentId] = true;
        }

        foreach ($attachmentCounts as $contentId => $count) {
            if ($count !== 1 || ! isset($referenced[$contentId])) {
                throw new RuntimeException("Der Outlook-Anhang {$contentId} besitzt keine eindeutige Bildreferenz.");
            }
        }
    }

    /**
     * @return list<array{filename_pattern: string, path: string, root: string}>
     */
    private function assetLocations(): array
    {
        $probeFilename = '__railtime_outlook_probe__.png';
        $locations = [
            $this->assetLocation(
                '/mail-assets/'.$probeFilename,
                $probeFilename,
                public_path('mail-assets'),
                '/\A[A-Za-z0-9][A-Za-z0-9._-]*\.(?:gif|jpe?g|png|webp)\z/i',
            ),
            $this->assetLocation(
                Storage::disk('public')->url('mail-imports/'.$probeFilename),
                $probeFilename,
                Storage::disk('public')->path('mail-imports'),
                '/\A[a-f0-9]{64}\.(?:gif|jpg|png|webp)\z/i',
            ),
        ];

        return array_values(array_filter($locations));
    }

    /**
     * @return array{filename_pattern: string, path: string, root: string}|null
     */
    private function assetLocation(
        string $probeUrl,
        string $probeFilename,
        string $root,
        string $filenamePattern,
    ): ?array {
        $path = parse_url($probeUrl, PHP_URL_PATH);
        if (! is_string($path) || ! str_ends_with($path, $probeFilename)) {
            return null;
        }

        return [
            'filename_pattern' => $filenamePattern,
            'path' => substr($path, 0, -strlen($probeFilename)),
            'root' => $root,
        ];
    }

    /** @param list<array{name: string, contentId: string, base64: string}> $media */
    private function assertMediaSize(array $media): void
    {
        $bytes = 0;
        foreach ($media as $attachment) {
            $binary = base64_decode((string) ($attachment['base64'] ?? ''), true);
            if (! is_string($binary)) {
                throw new RuntimeException('Ein Outlook-CID-Anhang ist nicht gueltig base64-kodiert.');
            }
            $bytes += strlen($binary);
        }

        if ($bytes > self::MAX_MEDIA_BYTES) {
            throw new RuntimeException('Die Outlook-Medien ueberschreiten das sichere Paketlimit.');
        }
    }

    /** @param array{html: string, css: string} $snapshot */
    private function snapshotHash(array $snapshot): string
    {
        return substr(hash('sha256', $snapshot['html']."\0".$snapshot['css']), 0, 16);
    }
}
