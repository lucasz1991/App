<?php

namespace App\Services\Marketing;

use App\Models\MarketingAsset;
use Illuminate\Support\Facades\Storage;

final class MarketingRenderAssetHydrator
{
    /** @return array{html: string, css: string} */
    public function hydrate(string $html, string $css): array
    {
        $combined = $html."\n".$css;
        preg_match_all(
            '#(?:https?://[^\s"\')]+)?/administrator/marketing/(?:medien|assets)/([0-9a-f-]{36})#i',
            $combined,
            $matches,
        );

        foreach (array_unique($matches[1] ?? []) as $publicId) {
            $asset = MarketingAsset::query()->where('public_id', $publicId)->first();
            if (! $asset || ! Storage::disk($asset->disk)->exists($asset->path)) {
                continue;
            }

            $dataUri = $this->dataUri(
                $asset->mime_type,
                Storage::disk($asset->disk)->get($asset->path),
            );
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

    private function dataUri(string $mimeType, string $contents): string
    {
        return 'data:'.$mimeType.';base64,'.base64_encode($contents);
    }
}
