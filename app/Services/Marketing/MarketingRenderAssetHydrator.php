<?php

namespace App\Services\Marketing;

use App\Models\MarketingAsset;
use Illuminate\Support\Facades\Storage;

final class MarketingRenderAssetHydrator
{
    private const ASSET_PATTERN = '#(?:https?://[^\s"\')]+)?/administrator/marketing/(?:medien|assets)/([0-9a-f-]{36})#i';

    /** @return array{html: string, css: string} */
    public function hydrate(string $html, string $css): array
    {
        $combined = $html."\n".$css;
        preg_match_all(self::ASSET_PATTERN, $combined, $matches);

        foreach (array_unique($matches[1] ?? []) as $publicId) {
            $asset = MarketingAsset::query()->where('public_id', $publicId)->first();
            if (! $asset || ! Storage::disk($asset->disk)->exists($asset->path)) {
                continue;
            }

            $contents = Storage::disk($asset->disk)->get($asset->path);
            if (! hash_equals($asset->sha256, hash('sha256', $contents))) {
                continue;
            }

            $dataUri = $this->dataUri($asset->mime_type, $contents);
            $pattern = '#(?:https?://[^\s"\')]+)?/administrator/marketing/(?:medien|assets)/'.preg_quote($publicId, '#').'(?:\?v=[a-f0-9]+)?#i';
            $html = preg_replace($pattern, $dataUri, $html) ?? $html;
            $css = preg_replace($pattern, $dataUri, $css) ?? $css;
        }

        foreach (['img/hero-railtime.jpg', 'rt-logo.svg'] as $relativePath) {
            $absolutePath = public_path('rt-brand/'.$relativePath);
            if (! is_file($absolutePath)) {
                continue;
            }

            $mime = $relativePath === 'rt-logo.svg' ? 'image/svg+xml' : 'image/jpeg';
            $uri = $this->dataUri($mime, (string) file_get_contents($absolutePath));
            $pattern = '#(?:https?://[^\s"\')]+)?/?rt-brand/'.preg_quote($relativePath, '#').'#i';
            $html = preg_replace($pattern, $uri, $html) ?? $html;
            $css = preg_replace($pattern, $uri, $css) ?? $css;
        }

        return ['html' => $html, 'css' => $css];
    }

    public function fingerprint(string $html, string $css): string
    {
        $combined = $html."\n".$css;
        $assetIds = $this->referencedAssetPublicIds($html, $css);
        $parts = [];

        if ($assetIds !== []) {
            $assets = MarketingAsset::query()
                ->whereIn('public_id', $assetIds)
                ->get()
                ->keyBy('public_id');
            foreach ($assetIds as $publicId) {
                $parts[] = 'asset:'.$publicId.':'.($assets->get($publicId)?->sha256 ?? 'missing');
            }
        }

        foreach (['img/hero-railtime.jpg', 'rt-logo.svg'] as $relativePath) {
            if (! preg_match('#(?:^|[/(])rt-brand/'.preg_quote($relativePath, '#').'(?:[?"\')]|$)#i', $combined)) {
                continue;
            }

            $absolutePath = public_path('rt-brand/'.$relativePath);
            $parts[] = 'builtin:'.$relativePath.':'.(is_file($absolutePath) ? hash_file('sha256', $absolutePath) : 'missing');
        }

        return hash('sha256', implode('|', $parts));
    }

    /** @return list<string> */
    public function referencedAssetPublicIds(string $html, string $css): array
    {
        preg_match_all(self::ASSET_PATTERN, $html."\n".$css, $matches);
        $assetIds = array_values(array_unique($matches[1] ?? []));
        sort($assetIds);

        return $assetIds;
    }

    private function dataUri(string $mimeType, string $contents): string
    {
        return 'data:'.$mimeType.';base64,'.base64_encode($contents);
    }
}
