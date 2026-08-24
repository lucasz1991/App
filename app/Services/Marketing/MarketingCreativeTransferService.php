<?php

namespace App\Services\Marketing;

use App\Enums\MarketingCreativeFormat;
use App\Enums\MarketingCreativeType;
use App\Models\File;
use App\Models\FileFolder;
use App\Models\MarketingCreative;
use App\Models\MarketingCreativeVariant;
use App\Models\User;
use App\Support\MarketingBrandAssets;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use JsonException;
use Throwable;

final class MarketingCreativeTransferService
{
    public const FORMAT = 'railtime-marketing-creative';

    public const VERSION = 1;

    private const MAX_BUNDLE_BYTES = 32 * 1024 * 1024;

    private const MAX_BUNDLE_MEDIA_BYTES = 30 * 1024 * 1024;

    private const MAX_MEDIA_COUNT = 64;

    private const TOKEN_PATTERN = '#rtmedia://(media-[a-f0-9]{64})#i';

    /** @var array<string, string> */
    private const EXTENSIONS = [
        'image/gif' => 'gif',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    public function __construct(
        private readonly MarketingFileSourceService $files,
        private readonly MarketingStudioService $studio,
    ) {}

    /** @return array<string, mixed> */
    public function export(MarketingCreative $creative): array
    {
        return DB::transaction(function () use ($creative): array {
            $this->files->lockSourceSelection();
            $locked = MarketingCreative::query()->lockForUpdate()->findOrFail($creative->getKey());
            $variants = $locked->variants()
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy(fn (MarketingCreativeVariant $variant): string => $variant->format->value);

            foreach (MarketingCreativeFormat::cases() as $format) {
                if (! $variants->has($format->value)) {
                    throw ValidationException::withMessages([
                        'creative' => 'Das Motiv ist unvollständig und kann nicht portabel exportiert werden.',
                    ]);
                }
            }

            $definition = [
                'type' => $locked->type->value,
                'title' => (string) $locked->title,
                'shared_content' => $locked->shared_content ?? [],
                'variants' => [],
            ];
            foreach (MarketingCreativeFormat::cases() as $format) {
                /** @var MarketingCreativeVariant $variant */
                $variant = $variants->get($format->value);
                $definition['variants'][$format->value] = [
                    'builder_data' => $variant->builder_data ?? [],
                    'html' => (string) $variant->html,
                    'css' => (string) $variant->css,
                ];
            }

            $serialized = $this->json($definition);
            $fileIds = $this->files->referencedFileIds(...$this->stringValues($definition));
            if (count($fileIds) > self::MAX_MEDIA_COUNT) {
                throw ValidationException::withMessages([
                    'creative' => 'Das Motiv verwendet zu viele Bilder für ein portables Paket.',
                ]);
            }
            $lockedFiles = $this->files->lockFilesForUpdate($fileIds)->keyBy('id');
            $mediaById = [];
            $fileTokens = [];

            foreach ($fileIds as $fileId) {
                /** @var File|null $file */
                $file = $lockedFiles->get($fileId);
                if (! $file) {
                    throw ValidationException::withMessages([
                        'creative' => 'Eine verwendete Marketing-Datei ist nicht mehr vorhanden.',
                    ]);
                }

                $snapshot = $this->files->validatedSnapshot($file);
                $mediaId = 'media-'.$snapshot['sha256'];
                $fileTokens[$fileId] = $mediaId;
                $mediaById[$mediaId] ??= $this->portableMedia(
                    $mediaId,
                    (string) $snapshot['name'],
                    route('admin.marketing.files.show', $file),
                    (string) $snapshot['mime_type'],
                    (string) $snapshot['contents'],
                    (int) $snapshot['width'],
                    (int) $snapshot['height'],
                    (string) $snapshot['sha256'],
                );
            }

            $publicTokens = [];
            foreach (MarketingBrandAssets::manifest() as $publicPath => $mimeType) {
                if (! str_starts_with($publicPath, '/rt-brand/social/') || ! str_contains($serialized, $publicPath)) {
                    continue;
                }

                $snapshot = $this->publicImageSnapshot($publicPath, $mimeType);
                $mediaId = 'media-'.$snapshot['sha256'];
                $publicTokens[$publicPath] = $mediaId;
                $mediaById[$mediaId] ??= $this->portableMedia(
                    $mediaId,
                    basename($publicPath),
                    $publicPath,
                    $snapshot['mime_type'],
                    $snapshot['contents'],
                    $snapshot['width'],
                    $snapshot['height'],
                    $snapshot['sha256'],
                );
            }

            $definition = $this->mapStrings(
                $definition,
                function (string $value) use ($fileTokens, $publicTokens): string {
                    $value = preg_replace_callback(
                        MarketingFileSourceService::FILE_PATTERN,
                        static function (array $matches) use ($fileTokens): string {
                            $mediaId = $fileTokens[(int) $matches[1]] ?? null;

                            return $mediaId ? 'rtmedia://'.$mediaId : $matches[0];
                        },
                        $value,
                    ) ?? $value;

                    return str_replace(
                        array_keys($publicTokens),
                        array_map(static fn (string $id): string => 'rtmedia://'.$id, array_values($publicTokens)),
                        $value,
                    );
                },
            );

            ksort($mediaById);
            if (count($mediaById) > self::MAX_MEDIA_COUNT
                || array_sum(array_map(
                    static fn (array $medium): int => strlen((string) $medium['data']),
                    $mediaById,
                )) > self::MAX_BUNDLE_MEDIA_BYTES) {
                throw ValidationException::withMessages([
                    'creative' => 'Die eingebetteten Bilder sind für ein portables Motivpaket zu groß.',
                ]);
            }

            $bundle = [
                'format' => self::FORMAT,
                'version' => self::VERSION,
                'creative' => $definition,
                'media' => array_values($mediaById),
            ];
            if (strlen($this->json($bundle)) > self::MAX_BUNDLE_BYTES) {
                throw ValidationException::withMessages([
                    'creative' => 'Das Motivpaket ist größer als 32 MiB und kann nicht portabel exportiert werden.',
                ]);
            }

            return $bundle;
        });
    }

    public function exportJson(MarketingCreative $creative): string
    {
        return $this->json($this->export($creative));
    }

    /**
     * @param  array<string, mixed>  $bundle
     */
    public function import(array $bundle, User $actor): MarketingCreative
    {
        abort_unless($actor->isAdmin(), 403);
        $validated = $this->validatedBundle($bundle);
        $storedPaths = [];

        try {
            return DB::transaction(function () use ($validated, $actor, &$storedPaths): MarketingCreative {
                $selectedFolderId = $this->files->lockSourceSelection();
                if ($selectedFolderId !== null && $selectedFolderId < 1) {
                    throw ValidationException::withMessages([
                        'bundle' => 'Die konfigurierte Marketing-Bildquelle ist nicht mehr gültig.',
                    ]);
                }

                $pool = $this->files->sourcePool();
                $folder = null;
                if ($selectedFolderId !== null) {
                    $folder = FileFolder::query()->whereKey($selectedFolderId)->lockForUpdate()->first();
                    $scopedFolder = $this->files->selectedFolder();
                    if (! $folder || ! $scopedFolder || $scopedFolder->isNot($folder)) {
                        throw ValidationException::withMessages([
                            'bundle' => 'Der konfigurierte Zielordner für Marketing-Bilder ist nicht mehr verfügbar.',
                        ]);
                    }
                }

                $tokenUrls = [];
                foreach ($validated['media'] as $medium) {
                    $extension = self::EXTENSIONS[$medium['mime_type']];
                    $path = 'uploads/files/marketing-import-'.Str::uuid().'.'.$extension;
                    if (! Storage::disk('private')->put($path, $medium['contents'])) {
                        throw ValidationException::withMessages([
                            'bundle' => 'Ein Bild aus dem Motivpaket konnte nicht gespeichert werden.',
                        ]);
                    }
                    $storedPaths[] = $path;

                    $file = $pool->files()->create([
                        'folder_id' => $folder?->getKey(),
                        'user_id' => $actor->getKey(),
                        'name' => $this->normalizedFileName($medium['name'], $extension),
                        'path' => $path,
                        'disk' => 'private',
                        'mime_type' => $medium['mime_type'],
                        'size' => $medium['bytes'],
                        'content_sha256' => $medium['sha256'],
                        'image_width' => $medium['width'],
                        'image_height' => $medium['height'],
                    ]);

                    $tokenUrls[$medium['id']] = route('admin.marketing.files.show', $file)
                        .'?v='.substr($medium['sha256'], 0, 16);
                }

                $definition = $this->mapStrings(
                    $validated['creative'],
                    static fn (string $value): string => preg_replace_callback(
                        self::TOKEN_PATTERN,
                        static fn (array $matches): string => $tokenUrls[strtolower($matches[1])] ?? $matches[0],
                        $value,
                    ) ?? $value,
                );

                if (str_contains($this->json($definition), 'rtmedia://')) {
                    throw ValidationException::withMessages([
                        'bundle' => 'Das Motivpaket enthält eine nicht auflösbare Medienreferenz.',
                    ]);
                }

                return $this->studio->createFromPortableDefinition($definition, $actor);
            });
        } catch (Throwable $exception) {
            foreach ($storedPaths as $path) {
                try {
                    if (! Storage::disk('private')->delete($path)) {
                        Log::error('Marketing-Importblob konnte nach einem Rollback nicht entfernt werden.', [
                            'path' => $path,
                        ]);
                    }
                } catch (Throwable $cleanupException) {
                    Log::error('Marketing-Importblob konnte nach einem Rollback nicht entfernt werden.', [
                        'path' => $path,
                        'exception' => $cleanupException,
                    ]);
                }
            }

            throw $exception;
        }
    }

    /**
     * @param  array<string, mixed>  $bundle
     * @return array{
     *     creative:array<string,mixed>,
     *     media:list<array{id:string,name:string,mime_type:string,bytes:int,width:int,height:int,sha256:string,contents:string}>
     * }
     */
    private function validatedBundle(array $bundle): array
    {
        $unexpectedKeys = array_diff(array_keys($bundle), ['format', 'version', 'creative', 'media']);
        if ($unexpectedKeys !== []) {
            throw ValidationException::withMessages([
                'bundle' => 'Das Motivpaket enthält unbekannte Transportfelder.',
            ]);
        }
        if (strlen($this->json($bundle)) > self::MAX_BUNDLE_BYTES) {
            throw ValidationException::withMessages([
                'bundle' => 'Das Motivpaket ist größer als 32 MiB.',
            ]);
        }

        $maxBytes = max(1, (int) config('marketing.assets.max_kilobytes', 8192)) * 1024;
        $maxEncodedBytes = (int) ceil($maxBytes / 3) * 4 + 4;
        $formats = array_map(
            static fn (MarketingCreativeFormat $format): string => $format->value,
            MarketingCreativeFormat::cases(),
        );
        $rules = [
            'format' => ['required', 'string', 'in:'.self::FORMAT],
            'version' => ['required', 'integer', 'in:'.self::VERSION],
            'creative' => ['required', 'array:'.implode(',', ['type', 'title', 'shared_content', 'variants'])],
            'creative.type' => ['required', Rule::enum(MarketingCreativeType::class)],
            'creative.title' => ['required', 'string', 'max:160'],
            'creative.variants' => ['required', 'array:'.implode(',', $formats), 'size:'.count($formats)],
            'media' => ['present', 'array', 'max:'.self::MAX_MEDIA_COUNT],
            'media.*' => ['required', 'array:id,name,source,mime_type,bytes,width,height,sha256,data'],
            'media.*.id' => ['required', 'string', 'regex:/^media-[a-f0-9]{64}$/'],
            'media.*.name' => ['required', 'string', 'max:200'],
            'media.*.source' => ['present', 'string', 'max:2048'],
            'media.*.mime_type' => ['required', 'string', Rule::in(array_keys(self::EXTENSIONS))],
            'media.*.bytes' => ['required', 'integer', 'min:1', 'max:'.$maxBytes],
            'media.*.width' => ['required', 'integer', 'min:1'],
            'media.*.height' => ['required', 'integer', 'min:1'],
            'media.*.sha256' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/'],
            'media.*.data' => ['required', 'string', 'max:'.$maxEncodedBytes],
        ];
        foreach ($formats as $format) {
            $rules['creative.variants.'.$format] = ['required', 'array:builder_data,html,css'];
            $rules['creative.variants.'.$format.'.builder_data'] = ['present', 'array'];
            $rules['creative.variants.'.$format.'.html'] = ['required', 'string', 'max:2000000'];
            $rules['creative.variants.'.$format.'.css'] = ['present', 'string', 'max:1000000'];
        }
        $rules = array_merge($rules, MarketingSharedContentSchema::rules('creative.shared_content'));

        $validator = Validator::make($bundle, $rules, [
            'format.in' => 'Die Datei ist kein RailTime-Marketing-Motivpaket.',
            'version.in' => 'Diese Version des Motivpakets wird nicht unterstützt.',
            'creative.variants.size' => 'Ein Motivpaket muss Story, Post und Web vollständig enthalten.',
        ]);
        $validator->after(static function (\Illuminate\Validation\Validator $validator) use ($bundle): void {
            MarketingSharedContentSchema::addSizeError(
                $validator,
                data_get($bundle, 'creative.shared_content', []),
                'creative.shared_content',
            );
        });
        $validated = $validator->validate();

        $creativeJson = $this->json($validated['creative']);
        if (strlen($creativeJson) > 8 * 1024 * 1024) {
            throw ValidationException::withMessages([
                'bundle' => 'Die Layoutdaten des Motivpakets sind zu groß.',
            ]);
        }

        $decodedMedia = [];
        $mediaIds = [];
        $encodedTotal = 0;
        $maxPixels = max(1, (int) config('marketing.assets.max_pixels', 40_000_000));
        foreach ($validated['media'] as $index => $medium) {
            $id = strtolower((string) $medium['id']);
            if (isset($mediaIds[$id])) {
                throw ValidationException::withMessages([
                    'media.'.$index.'.id' => 'Jede Medienkennung darf nur einmal vorkommen.',
                ]);
            }
            $mediaIds[$id] = true;

            $data = (string) $medium['data'];
            $encodedTotal += strlen($data);
            $contents = base64_decode($data, true);
            if (! is_string($contents) || base64_encode($contents) !== $data) {
                throw ValidationException::withMessages([
                    'media.'.$index.'.data' => 'Die Bilddaten sind nicht gültig Base64-kodiert.',
                ]);
            }
            if (strlen($contents) !== (int) $medium['bytes']) {
                throw ValidationException::withMessages([
                    'media.'.$index.'.bytes' => 'Die angegebene Bildgröße stimmt nicht mit dem Inhalt überein.',
                ]);
            }

            $sha256 = hash('sha256', $contents);
            if (! hash_equals(strtolower((string) $medium['sha256']), $sha256)
                || ! hash_equals(substr($id, 6), $sha256)) {
                throw ValidationException::withMessages([
                    'media.'.$index.'.sha256' => 'Die Prüfsumme eines Bildes ist ungültig.',
                ]);
            }

            $dimensions = @getimagesizefromstring($contents);
            $mimeType = is_array($dimensions) ? strtolower((string) ($dimensions['mime'] ?? '')) : '';
            $width = is_array($dimensions) ? (int) ($dimensions[0] ?? 0) : 0;
            $height = is_array($dimensions) ? (int) ($dimensions[1] ?? 0) : 0;
            if (! isset(self::EXTENSIONS[$mimeType])
                || $mimeType !== strtolower((string) $medium['mime_type'])
                || $width !== (int) $medium['width']
                || $height !== (int) $medium['height']) {
                throw ValidationException::withMessages([
                    'media.'.$index => 'MIME-Typ oder Abmessungen eines Bildes sind ungültig.',
                ]);
            }
            if ($width < 1 || $height < 1 || $width > intdiv($maxPixels, $height)) {
                throw ValidationException::withMessages([
                    'media.'.$index => 'Ein Bild überschreitet die sichere Pixelgrenze.',
                ]);
            }

            $decodedMedia[] = [
                'id' => $id,
                'name' => (string) $medium['name'],
                'mime_type' => $mimeType,
                'bytes' => strlen($contents),
                'width' => $width,
                'height' => $height,
                'sha256' => $sha256,
                'contents' => $contents,
            ];
        }
        if ($encodedTotal > self::MAX_BUNDLE_MEDIA_BYTES) {
            throw ValidationException::withMessages([
                'media' => 'Das Medienpaket ist größer als 30 MiB.',
            ]);
        }

        preg_match_all(self::TOKEN_PATTERN, $creativeJson, $matches);
        $referencedIds = array_values(array_unique(array_map('strtolower', $matches[1] ?? [])));
        sort($referencedIds);
        $providedIds = array_keys($mediaIds);
        sort($providedIds);
        if ($referencedIds !== $providedIds) {
            throw ValidationException::withMessages([
                'media' => 'Medienreferenzen und eingebettete Bilder des Motivpakets sind nicht vollständig.',
            ]);
        }
        $withoutValidTokens = preg_replace(self::TOKEN_PATTERN, '', $creativeJson) ?? $creativeJson;
        if (str_contains(strtolower($withoutValidTokens), 'rtmedia://')) {
            throw ValidationException::withMessages([
                'bundle' => 'Das Motivpaket enthält eine ungültige Medienreferenz.',
            ]);
        }

        return [
            'creative' => $validated['creative'],
            'media' => $decodedMedia,
        ];
    }

    /** @return array{id:string,name:string,source:string,mime_type:string,bytes:int,width:int,height:int,sha256:string,data:string} */
    private function portableMedia(
        string $id,
        string $name,
        string $source,
        string $mimeType,
        string $contents,
        int $width,
        int $height,
        string $sha256,
    ): array {
        return [
            'id' => $id,
            'name' => mb_substr($name, 0, 200),
            'source' => mb_substr($source, 0, 2048),
            'mime_type' => $mimeType,
            'bytes' => strlen($contents),
            'width' => $width,
            'height' => $height,
            'sha256' => $sha256,
            'data' => base64_encode($contents),
        ];
    }

    /** @return array{contents:string,mime_type:string,width:int,height:int,sha256:string} */
    private function publicImageSnapshot(string $publicPath, string $declaredMime): array
    {
        $absolutePath = MarketingBrandAssets::absolutePath($publicPath);
        $contents = $absolutePath && is_file($absolutePath) ? file_get_contents($absolutePath) : false;
        if (! is_string($contents) || $contents === '') {
            throw ValidationException::withMessages([
                'creative' => 'Ein eingebautes Motivbild ist nicht mehr vorhanden.',
            ]);
        }

        $dimensions = @getimagesizefromstring($contents);
        $mimeType = is_array($dimensions) ? strtolower((string) ($dimensions['mime'] ?? '')) : '';
        $width = is_array($dimensions) ? (int) ($dimensions[0] ?? 0) : 0;
        $height = is_array($dimensions) ? (int) ($dimensions[1] ?? 0) : 0;
        $maxBytes = max(1, (int) config('marketing.assets.max_kilobytes', 8192)) * 1024;
        $maxPixels = max(1, (int) config('marketing.assets.max_pixels', 40_000_000));
        if (strlen($contents) > $maxBytes
            || ! isset(self::EXTENSIONS[$mimeType])
            || strtolower($declaredMime) !== $mimeType
            || $width < 1
            || $height < 1
            || $width > intdiv($maxPixels, $height)) {
            throw ValidationException::withMessages([
                'creative' => 'Ein eingebautes Motivbild besitzt einen ungültigen Dateityp.',
            ]);
        }

        return [
            'contents' => $contents,
            'mime_type' => $mimeType,
            'width' => $width,
            'height' => $height,
            'sha256' => hash('sha256', $contents),
        ];
    }

    private function normalizedFileName(string $name, string $extension): string
    {
        $name = basename(str_replace(["\0", "\r", "\n", '\\', '/'], '-', trim($name)));
        $stem = trim((string) pathinfo($name, PATHINFO_FILENAME));
        $stem = preg_replace('/[^\pL\pN._ -]+/u', '-', $stem) ?? 'motivbild';
        $stem = trim($stem, '. -_');

        return mb_substr($stem !== '' ? $stem : 'motivbild', 0, 180).'.'.$extension;
    }

    private function json(mixed $value): string
    {
        try {
            return json_encode(
                $value,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            );
        } catch (JsonException) {
            throw ValidationException::withMessages([
                'bundle' => 'Das Motivpaket enthält ungültige Zeichen.',
            ]);
        }
    }

    private function mapStrings(mixed $value, callable $callback): mixed
    {
        if (is_string($value)) {
            return $callback($value);
        }
        if (! is_array($value)) {
            return $value;
        }

        foreach ($value as $key => $nested) {
            $value[$key] = $this->mapStrings($nested, $callback);
        }

        return $value;
    }

    /** @return list<string> */
    private function stringValues(mixed $value): array
    {
        if (is_string($value)) {
            return [$value];
        }
        if (! is_array($value)) {
            return [];
        }

        $strings = [];
        foreach ($value as $nested) {
            array_push($strings, ...$this->stringValues($nested));
        }

        return $strings;
    }
}
