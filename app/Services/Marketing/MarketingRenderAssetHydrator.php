<?php

namespace App\Services\Marketing;

use App\Models\File;
use App\Models\MarketingCreativeVariant;
use App\Support\MarketingBrandAssets;
use RuntimeException;
use Throwable;

final class MarketingRenderAssetHydrator
{
    private const FILE_URL_PATTERN = '#(?<![A-Za-z0-9:+./?&=%_-])(?:https?://[^\s"\')>]+)?/administrator/marketing/dateien/[1-9][0-9]*(?:\?v=[a-f0-9]{8,64})?(?=$|[\s"\')>])#i';

    public function __construct(private readonly MarketingFileSourceService $files) {}

    /** @return array{html: string, css: string} */
    public function hydrate(string $html, string $css): array
    {
        foreach ($this->fileReferences($html, $css) as $fileId => $urls) {
            $snapshot = $this->snapshot($fileId);
            $dataUri = $this->dataUri($snapshot['mime_type'], $snapshot['contents']);

            foreach ($urls as $url) {
                $html = str_replace($url, $dataUri, $html);
                $css = str_replace($url, $dataUri, $css);
            }
        }

        $combined = $html."\n".$css;
        foreach (MarketingBrandAssets::manifest() as $publicPath => $mimeType) {
            $pattern = $this->builtInPattern($publicPath);
            if (! preg_match($pattern, $combined)) {
                continue;
            }

            $absolutePath = MarketingBrandAssets::absolutePath($publicPath);
            if (! is_string($absolutePath) || ! is_file($absolutePath)) {
                throw new RuntimeException('Eine interne RailTime-Markendatei fehlt.');
            }

            $contents = file_get_contents($absolutePath);
            if (! is_string($contents) || ! $this->hasExpectedMimeType($mimeType, $contents)) {
                throw new RuntimeException('Eine interne RailTime-Markendatei ist beschädigt.');
            }

            $uri = $this->dataUri($mimeType, $contents);
            $html = preg_replace($pattern, $uri, $html) ?? $html;
            $css = preg_replace($pattern, $uri, $css) ?? $css;
        }

        return ['html' => $html, 'css' => $css];
    }

    /** Render-cache fingerprint, deliberately bound to the selected source. */
    public function fingerprint(string $html, string $css): string
    {
        return $this->dependencyFingerprint($html, $css, true);
    }

    /** Approval fingerprint: same bytes, but source moves that keep every reference valid do not revoke approval. */
    public function approvalFingerprint(string $html, string $css): string
    {
        return $this->dependencyFingerprint($html, $css, false);
    }

    /** @param iterable<MarketingCreativeVariant> $variants */
    public function creativeApprovalFingerprint(iterable $variants): string
    {
        $parts = [];
        foreach ($variants as $variant) {
            $format = $variant->format instanceof \BackedEnum ? $variant->format->value : (string) $variant->format;
            $parts[] = implode(':', [
                $format,
                (string) $variant->content_hash,
                $this->variantDocumentFingerprint($variant),
                $this->approvalFingerprint((string) $variant->html, (string) $variant->css),
            ]);
        }
        sort($parts);

        return hash('sha256', implode('|', $parts));
    }

    public function assertReferencesValid(string $html, string $css): void
    {
        $this->dependencyFingerprint($html, $css, false);
    }

    /**
     * Locks the selected source and every referenced File row in a stable order.
     * Callers must already be inside a database transaction.
     *
     * @return list<int>
     */
    public function lockReferencesForUpdate(string $html, string $css): array
    {
        return $this->lockDocumentsForUpdate([['html' => $html, 'css' => $css]]);
    }

    /**
     * @param  iterable<array{html: string, css: string}>  $documents
     * @return list<int>
     */
    public function lockDocumentsForUpdate(iterable $documents): array
    {
        $this->files->lockSourceSelection();
        $ids = [];
        foreach ($documents as $document) {
            foreach ($this->referencedFileIds($document['html'], $document['css']) as $id) {
                $ids[$id] = true;
            }
        }

        $ids = array_keys($ids);
        sort($ids);
        $files = $this->files->lockFilesForUpdate($ids);
        foreach ($files as $file) {
            $this->files->validatedSnapshot($file);
        }

        return $ids;
    }

    /** @return list<int> */
    public function referencedFileIds(string $html, string $css): array
    {
        $ids = array_keys($this->fileReferences($html, $css));
        sort($ids);

        return $ids;
    }

    private function dependencyFingerprint(string $html, string $css, bool $includeSource): string
    {
        $combined = $html."\n".$css;
        $parts = $includeSource ? ['source:'.$this->files->sourceFingerprint()] : [];

        foreach ($this->fileReferences($html, $css) as $fileId => $urls) {
            $snapshot = $this->snapshot($fileId);
            $parts[] = 'file:'.$fileId.':'.$snapshot['mime_type'].':'.$snapshot['width'].'x'.$snapshot['height'].':'.$snapshot['sha256'];
        }

        foreach (MarketingBrandAssets::manifest() as $publicPath => $mimeType) {
            if (! preg_match($this->builtInPattern($publicPath), $combined)) {
                continue;
            }

            $absolutePath = MarketingBrandAssets::absolutePath($publicPath);
            $hash = is_string($absolutePath) && is_file($absolutePath)
                ? hash_file('sha256', $absolutePath)
                : false;
            $parts[] = 'builtin:'.$publicPath.':'.$mimeType.':'.(is_string($hash) ? $hash : 'missing');
        }

        return hash('sha256', implode('|', $parts));
    }

    /** @return array<int, list<string>> */
    private function fileReferences(string $html, string $css): array
    {
        $combined = $html."\n".$css;
        preg_match_all(self::FILE_URL_PATTERN, $combined, $matches);
        $references = [];

        foreach (array_unique($matches[0] ?? []) as $url) {
            $fileId = $this->files->fileIdFromUrl($url);
            if ($fileId === null) {
                throw new RuntimeException('Ein Datei-Verweis im Marketing-Motiv ist nicht zulässig.');
            }
            $references[$fileId][] = $url;
        }

        $withoutValidReferences = preg_replace(self::FILE_URL_PATTERN, '', $combined) ?? $combined;
        if (str_contains(strtolower($withoutValidReferences), '/administrator/marketing/dateien/')) {
            throw new RuntimeException('Ein Datei-Verweis im Marketing-Motiv ist nicht zulässig.');
        }

        ksort($references);

        return $references;
    }

    /** @return array{id: int, name: string, contents: string, mime_type: string, size: int, width: int, height: int, sha256: string} */
    private function snapshot(int $fileId): array
    {
        $file = File::query()->find($fileId);
        if (! $file) {
            throw new RuntimeException('Eine verwendete Marketing-Datei ist nicht mehr vorhanden.');
        }

        try {
            return $this->files->validatedSnapshot($file);
        } catch (Throwable $exception) {
            throw new RuntimeException(
                'Eine verwendete Marketing-Datei fehlt, liegt außerhalb der Bildquelle oder wurde verändert.',
                0,
                $exception,
            );
        }
    }

    private function dataUri(string $mimeType, string $contents): string
    {
        return 'data:'.$mimeType.';base64,'.base64_encode($contents);
    }

    private function builtInPattern(string $publicPath): string
    {
        $quotedPath = preg_quote($publicPath, '#');
        $candidates = [];
        $applicationUrl = rtrim((string) config('app.url'), '/');
        if (preg_match('#^https?://#i', $applicationUrl)) {
            $candidates[] = '(?i:'.preg_quote($applicationUrl, '#').')'.$quotedPath;
        }
        $candidates[] = '(?<![A-Za-z0-9:\/?&=%._\-\]])'.$quotedPath;

        return '#(?:'.implode('|', $candidates).')(?:\?v=[a-f0-9]+)?(?=$|[\s"\'\)>])#';
    }

    private function hasExpectedMimeType(string $expectedMimeType, string $contents): bool
    {
        if ($expectedMimeType === 'image/svg+xml') {
            return (bool) preg_match('/^\s*(?:<\?xml[^>]*>\s*)?<svg\b/i', $contents);
        }

        $dimensions = @getimagesizefromstring($contents);

        return is_array($dimensions) && strtolower((string) ($dimensions['mime'] ?? '')) === $expectedMimeType;
    }

    private function variantDocumentFingerprint(mixed $variant): string
    {
        $builderData = json_encode(
            $variant->builder_data ?? [],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );

        return hash('sha256', implode("\0", [
            is_string($builderData) ? $builderData : '',
            (string) $variant->html,
            (string) $variant->css,
        ]));
    }
}
