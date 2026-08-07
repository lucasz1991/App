<?php

namespace App\Services\Marketing;

use App\Models\MarketingAsset;
use App\Models\MarketingCreative;
use App\Models\MarketingCreativeVariant;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;
use Throwable;

final class MarketingAssetService
{
    private const MAX_BYTES = 8 * 1024 * 1024;

    private const MAX_PIXELS = 40_000_000;

    /** @var array<string, string> */
    private const EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
    ];

    public function store(UploadedFile $upload, User $actor): MarketingAsset
    {
        $normalized = $this->normalize($upload);
        $path = $this->path($normalized['extension']);

        $disk = $this->disk();
        if (! Storage::disk($disk)->put($path, $normalized['contents'])) {
            throw ValidationException::withMessages([
                'file' => 'Das Bild konnte nicht im privaten Medienspeicher abgelegt werden.',
            ]);
        }

        try {
            return MarketingAsset::query()->create([
                'original_name' => $this->originalName($upload),
                'disk' => $disk,
                'path' => $path,
                'mime_type' => $normalized['mime_type'],
                'extension' => $normalized['extension'],
                'size' => strlen($normalized['contents']),
                'width' => $normalized['width'],
                'height' => $normalized['height'],
                'sha256' => hash('sha256', $normalized['contents']),
                'created_by' => $actor->id,
            ]);
        } catch (Throwable $exception) {
            Storage::disk($disk)->delete($path);
            throw $exception;
        }
    }

    public function replace(MarketingAsset $asset, UploadedFile $upload, User $actor): MarketingAsset
    {
        $normalized = $this->normalize($upload);
        $newPath = $this->path($normalized['extension']);
        $disk = $this->disk();
        if (! Storage::disk($disk)->put($newPath, $normalized['contents'])) {
            throw ValidationException::withMessages([
                'file' => 'Das Ersatzbild konnte nicht im privaten Medienspeicher abgelegt werden.',
            ]);
        }

        try {
            return DB::transaction(function () use ($asset, $upload, $actor, $normalized, $newPath, $disk): MarketingAsset {
                $locked = MarketingAsset::query()->lockForUpdate()->findOrFail($asset->id);
                $oldDisk = $locked->disk;
                $oldPath = $locked->path;

                $locked->forceFill([
                    'original_name' => $this->originalName($upload),
                    'disk' => $disk,
                    'path' => $newPath,
                    'mime_type' => $normalized['mime_type'],
                    'extension' => $normalized['extension'],
                    'size' => strlen($normalized['contents']),
                    'width' => $normalized['width'],
                    'height' => $normalized['height'],
                    'sha256' => hash('sha256', $normalized['contents']),
                    'created_by' => $actor->id,
                ])->save();

                DB::afterCommit(static function () use ($oldDisk, $oldPath): void {
                    Storage::disk($oldDisk)->delete($oldPath);
                });

                return $locked;
            });
        } catch (Throwable $exception) {
            Storage::disk($disk)->delete($newPath);
            throw $exception;
        }
    }

    public function delete(MarketingAsset $asset): void
    {
        if ($this->isUsed($asset)) {
            throw ValidationException::withMessages([
                'asset' => 'Dieses Medium wird noch in mindestens einem Motiv verwendet und kann nicht gelöscht werden.',
            ]);
        }

        DB::transaction(function () use ($asset): void {
            $locked = MarketingAsset::query()->lockForUpdate()->findOrFail($asset->id);
            $disk = $locked->disk;
            $path = $locked->path;
            $locked->delete();

            DB::afterCommit(static function () use ($disk, $path): void {
                Storage::disk($disk)->delete($path);
            });
        });
    }

    public function isUsed(MarketingAsset $asset): bool
    {
        $needle = $asset->public_id;

        return MarketingCreativeVariant::query()
            ->where('html', 'like', '%'.$needle.'%')
            ->orWhere('css', 'like', '%'.$needle.'%')
            ->exists()
            || MarketingCreative::query()
                ->where('shared_content', 'like', '%'.$needle.'%')
                ->exists();
    }

    /**
     * @return array{contents: string, mime_type: string, extension: string, width: int, height: int}
     */
    private function normalize(UploadedFile $upload): array
    {
        if (! $upload->isValid() || ! is_file($upload->getPathname())) {
            throw ValidationException::withMessages(['file' => 'Das hochgeladene Bild ist ungültig.']);
        }

        $uploadSize = (int) ($upload->getSize() ?: 0);
        if ($uploadSize < 1 || $uploadSize > $this->maxBytes()) {
            throw ValidationException::withMessages(['file' => 'Das Bild darf höchstens 8 MB groß sein.']);
        }

        $dimensions = @getimagesize($upload->getPathname());
        if (! is_array($dimensions) || ! isset($dimensions[0], $dimensions[1], $dimensions['mime'])) {
            throw ValidationException::withMessages(['file' => 'Die Bilddatei konnte nicht sicher gelesen werden.']);
        }

        $width = (int) $dimensions[0];
        $height = (int) $dimensions[1];
        $mimeType = strtolower((string) $dimensions['mime']);
        if ($width < 1 || $height < 1 || ! isset(self::EXTENSIONS[$mimeType])) {
            throw ValidationException::withMessages(['file' => 'Erlaubt sind JPEG-, PNG-, WebP- und GIF-Bilder.']);
        }

        $maxPixels = max(1, (int) config('marketing.assets.max_pixels', self::MAX_PIXELS));
        if ($width > intdiv($maxPixels, $height)) {
            throw ValidationException::withMessages(['file' => 'Das Bild überschreitet die sichere Pixelgrenze.']);
        }

        try {
            $image = (new ImageManager(new Driver, strip: true))->read($upload->getPathname());
            $encoded = match ($mimeType) {
                'image/jpeg' => $image->toJpeg(90, false, true),
                'image/png' => $image->toPng(),
                'image/webp' => $image->toWebp(90, true),
                'image/gif' => $image->toGif(),
            };
            $contents = (string) $encoded;
        } catch (Throwable) {
            throw ValidationException::withMessages(['file' => 'Das Bild konnte nicht sicher normalisiert werden.']);
        }

        if ($contents === '' || strlen($contents) > $this->maxBytes()) {
            throw ValidationException::withMessages(['file' => 'Das normalisierte Bild überschreitet die zulässige Größe von 8 MB.']);
        }

        return [
            'contents' => $contents,
            'mime_type' => $mimeType,
            'extension' => self::EXTENSIONS[$mimeType],
            'width' => $width,
            'height' => $height,
        ];
    }

    private function path(string $extension): string
    {
        return trim((string) config('marketing.assets.directory', 'marketing/assets'), '/')
            .'/'.now()->format('Y/m').'/'.Str::uuid().'.'.$extension;
    }

    private function originalName(UploadedFile $upload): string
    {
        return mb_substr(basename(str_replace('\\', '/', $upload->getClientOriginalName())), 0, 255);
    }

    private function maxBytes(): int
    {
        return max(1, (int) config('marketing.assets.max_kilobytes', intdiv(self::MAX_BYTES, 1024))) * 1024;
    }

    private function disk(): string
    {
        return (string) config('marketing.disk', 'private');
    }
}
