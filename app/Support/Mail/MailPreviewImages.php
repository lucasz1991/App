<?php

namespace App\Support\Mail;

use Illuminate\Support\Facades\Storage;

/** Embed imported IMG assets for the data-only preview, never fetch a URL. */
final class MailPreviewImages
{
    public static function embed(string $html): string
    {
        $replacements = [];
        $disk = Storage::disk('public');
        $root = realpath($disk->path('mail-imports'));
        if ($root === false) {
            return $html;
        }

        foreach (PortableMediaCatalog::referencedImportedAssetSources($html) as $id => $sources) {
            $path = realpath($disk->path($id));
            if ($path === false || strcasecmp(dirname($path), $root) !== 0
                || ! is_file($path) || ! is_readable($path)
                || filesize($path) > 2 * 1024 * 1024) {
                continue;
            }

            $bytes = file_get_contents($path);
            $extension = pathinfo($id, PATHINFO_EXTENSION);
            $mime = ['png' => 'image/png', 'gif' => 'image/gif', 'jpg' => 'image/jpeg', 'webp' => 'image/webp'][$extension] ?? null;
            $dimensions = is_string($bytes) ? @getimagesizefromstring($bytes) : false;
            if (! is_string($bytes) || ! hash_equals(pathinfo($id, PATHINFO_FILENAME), hash('sha256', $bytes))
                || ! is_array($dimensions) || ($dimensions['mime'] ?? null) !== $mime
                || $dimensions[0] > 4096 || $dimensions[1] > 4096
                || $dimensions[0] * $dimensions[1] > 16000000) {
                continue;
            }

            foreach ($sources as $source) {
                $replacements[$source] = 'data:'.$mime.';base64,'.base64_encode($bytes);
            }
        }

        return preg_replace_callback('/<img\b[^>]*>/i', static function (array $tag) use ($replacements): string {
            return preg_replace_callback('/(\s+src\s*=\s*)(["\'])(.*?)\2/is', static function (array $attribute) use ($replacements): string {
                $source = html_entity_decode($attribute[3], ENT_QUOTES | ENT_HTML5, 'UTF-8');

                return isset($replacements[$source])
                    ? $attribute[1].$attribute[2].$replacements[$source].$attribute[2]
                    : $attribute[0];
            }, $tag[0]) ?? $tag[0];
        }, $html) ?? $html;
    }
}
