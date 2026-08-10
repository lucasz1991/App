<?php

namespace App\Services\Marketing;

use App\Enums\MarketingCreativeFormat;
use App\Enums\MarketingCreativeStatus;
use App\Enums\MarketingRenderStatus;
use App\Jobs\RenderMarketingCreative;
use App\Models\MarketingCreative;
use App\Models\MarketingCreativeVariant;
use App\Models\MarketingRender;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

final class MarketingRenderService
{
    public function __construct(
        private readonly MarketingRenderAssetHydrator $assets,
        private readonly MarketingFileSourceService $files,
    ) {}

    public function queue(
        MarketingCreative $creative,
        MarketingCreativeFormat $format,
        User $actor,
    ): MarketingRender {
        $disk = $this->renderDisk();

        return DB::transaction(function () use ($creative, $format, $actor, $disk): MarketingRender {
            $this->files->lockSourceSelection();
            $lockedCreative = MarketingCreative::query()->lockForUpdate()->findOrFail($creative->id);
            if ($lockedCreative->status === MarketingCreativeStatus::Archived) {
                throw ValidationException::withMessages([
                    'creative' => 'Ein archiviertes Motiv kann nicht exportiert werden.',
                ]);
            }

            $variants = $lockedCreative->variants()->orderBy('id')->lockForUpdate()->get();
            $this->assets->lockDocumentsForUpdate($this->documents($variants));
            $this->synchronizeApprovalState($lockedCreative, $variants);

            $variant = $variants->first(
                fn (MarketingCreativeVariant $candidate): bool => $candidate->format === $format,
            );
            if (! $variant) {
                throw ValidationException::withMessages([
                    'format' => 'Die angeforderte Motivvariante ist nicht vorhanden.',
                ]);
            }

            $dimensions = $format->dimensions();
            $fingerprint = $this->fingerprint($lockedCreative, $variant);
            $render = MarketingRender::query()->where('fingerprint', $fingerprint)->lockForUpdate()->first();

            if ($render
                && $render->status === MarketingRenderStatus::Completed
                && $render->path
                && $this->isPrivateDisk((string) $render->disk)
                && Storage::disk($render->disk)->exists($render->path)) {
                return $render;
            }

            if (! $render) {
                $render = MarketingRender::query()->create([
                    'marketing_creative_id' => $lockedCreative->id,
                    'marketing_creative_variant_id' => $variant->id,
                    'format' => $format,
                    'fingerprint' => $fingerprint,
                    'status' => MarketingRenderStatus::Pending,
                    'disk' => $disk,
                    'width' => $dimensions['width'],
                    'height' => $dimensions['height'],
                    'created_by' => $actor->id,
                ]);
            } elseif ($render->status === MarketingRenderStatus::Failed
                || ($render->status === MarketingRenderStatus::Completed
                    && (! $render->path
                        || ! $this->isPrivateDisk((string) $render->disk)
                        || ! Storage::disk($render->disk)->exists($render->path)))) {
                $render->forceFill([
                    'status' => MarketingRenderStatus::Pending,
                    'disk' => $disk,
                    'path' => null,
                    'mime_type' => null,
                    'error' => null,
                    'rendered_at' => null,
                    'created_by' => $actor->id,
                ])->save();
            }

            RenderMarketingCreative::dispatch($render)->afterCommit();

            return $render;
        });
    }

    public function render(MarketingRender $render): MarketingRender
    {
        $capture = DB::transaction(function () use ($render): array {
            $this->files->lockSourceSelection();
            $creative = MarketingCreative::query()->lockForUpdate()->find($render->marketing_creative_id);
            if (! $creative) {
                throw new RuntimeException('Das Motiv existiert nicht mehr.');
            }

            $variants = $creative->variants()->orderBy('id')->lockForUpdate()->get();
            $this->assets->lockDocumentsForUpdate($this->documents($variants));
            $this->synchronizeApprovalState($creative, $variants);

            $variant = $variants->firstWhere('id', (int) $render->marketing_creative_variant_id);
            $locked = MarketingRender::query()->lockForUpdate()->findOrFail($render->id);
            if (! $variant
                || (int) $locked->marketing_creative_id !== (int) $creative->id
                || (int) $locked->marketing_creative_variant_id !== (int) $variant->id) {
                throw new RuntimeException('Das Motiv oder seine Formatvariante existiert nicht mehr.');
            }

            $currentFingerprint = $this->fingerprint($creative, $variant);
            if (! hash_equals((string) $locked->fingerprint, $currentFingerprint)) {
                $this->markFailed(
                    $locked,
                    'Das Motiv oder ein verwendetes Medium wurde nach dem Exportauftrag geändert. Bitte den Export erneut starten.',
                );

                return ['render' => $locked, 'failed' => true];
            }

            if ($locked->status === MarketingRenderStatus::Completed
                && $locked->path
                && $this->isPrivateDisk((string) $locked->disk)
                && Storage::disk($locked->disk)->exists($locked->path)) {
                return ['render' => $locked, 'completed' => true];
            }

            $hydrated = $this->assets->hydrate((string) $variant->html, (string) $variant->css);
            $locked->forceFill([
                'status' => MarketingRenderStatus::Processing,
                'error' => null,
            ])->save();

            return [
                'render' => $locked,
                'html' => $hydrated['html'],
                'css' => $hydrated['css'],
                'watermark' => $this->requiresWatermarkForVariants($creative, $variants),
                'creative_public_id' => (string) $creative->public_id,
                'format' => $locked->format->value,
            ];
        });

        /** @var MarketingRender $lockedRender */
        $lockedRender = $capture['render'];
        if (($capture['failed'] ?? false) || ($capture['completed'] ?? false)) {
            return $lockedRender;
        }

        $tempDirectory = storage_path('app/private/marketing/tmp');
        if (! is_dir($tempDirectory) && ! mkdir($tempDirectory, 0775, true) && ! is_dir($tempDirectory)) {
            throw new RuntimeException('Das temporäre Render-Verzeichnis konnte nicht erstellt werden.');
        }

        $token = $lockedRender->public_id.'-'.bin2hex(random_bytes(5));
        $inputPath = $tempDirectory.DIRECTORY_SEPARATOR.$token.'.json';
        $outputPath = $tempDirectory.DIRECTORY_SEPARATOR.$token.'.png';

        try {
            $input = json_encode([
                'html' => $capture['html'],
                'css' => $capture['css'],
                'base_url' => $this->fileUrl(public_path()),
                'watermark' => $capture['watermark'],
                'creative_id' => $capture['creative_public_id'],
                'format' => $capture['format'],
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            if (file_put_contents($inputPath, $input, LOCK_EX) === false) {
                throw new RuntimeException('Die Render-Eingabedatei konnte nicht erstellt werden.');
            }

            $command = [
                (string) config('marketing.renders.node_binary', 'node'),
                (string) config('marketing.renders.script', base_path('scripts/render-marketing-creative.mjs')),
                '--input',
                $inputPath,
                '--output',
                $outputPath,
                '--width',
                (string) $lockedRender->width,
                '--height',
                (string) $lockedRender->height,
            ];
            $chrome = trim((string) config('marketing.renders.chrome_path', ''));
            if ($chrome !== '') {
                array_push($command, '--chrome', $chrome);
            }
            if ((bool) config('marketing.renders.no_sandbox', false)) {
                $command[] = '--no-sandbox';
            }

            $timeout = min(75, max(10, (int) config('marketing.renders.timeout_seconds', 75)));
            $result = Process::path(base_path())->timeout($timeout)->run($command);
            if (! $result->successful()) {
                throw new RuntimeException('Chromium-Render fehlgeschlagen: '.mb_substr(trim($result->errorOutput()), 0, 700));
            }

            $image = @getimagesize($outputPath);
            if (! is_array($image)
                || ($image['mime'] ?? null) !== 'image/png'
                || (int) ($image[0] ?? 0) !== $lockedRender->width
                || (int) ($image[1] ?? 0) !== $lockedRender->height) {
                throw new RuntimeException('Der Renderer hat keine PNG-Datei in den erwarteten Abmessungen erzeugt.');
            }

            return $this->finalize($lockedRender, $outputPath);
        } finally {
            foreach ([$inputPath, $outputPath] as $temporaryPath) {
                if (is_file($temporaryPath)) {
                    @unlink($temporaryPath);
                }
            }
        }
    }

    public function fingerprint(MarketingCreative $creative, MarketingCreativeVariant $variant): string
    {
        $builderData = json_encode(
            $variant->builder_data ?? [],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
        $documentFingerprint = hash('sha256', implode("\0", [
            is_string($builderData) ? $builderData : '',
            (string) $variant->html,
            (string) $variant->css,
        ]));

        return hash('sha256', implode('|', [
            (string) $creative->public_id,
            (string) $variant->id,
            $variant->format->value,
            (string) $variant->content_hash,
            $documentFingerprint,
            $this->assets->fingerprint((string) $variant->html, (string) $variant->css),
            $creative->status->value,
            (string) ($creative->approval_dependency_hash ?? ''),
            $this->renderDisk(),
            (string) config('marketing.renders.cache_version', 1),
            (string) $variant->format->dimensions()['width'],
            (string) $variant->format->dimensions()['height'],
        ]));
    }

    public function requiresWatermark(MarketingCreative $creative): bool
    {
        if ($creative->status !== MarketingCreativeStatus::Approved) {
            return true;
        }

        try {
            return $this->requiresWatermarkForVariants($creative, $creative->variants()->get());
        } catch (Throwable) {
            return true;
        }
    }

    public function isCurrent(MarketingRender $render): bool
    {
        try {
            return DB::transaction(fn (): bool => $this->lockedCurrentState($render) !== null);
        } catch (Throwable) {
            return false;
        }
    }

    /** @return array{contents: string, mime_type: string, size: int, creative_title: string}|null */
    public function downloadSnapshot(MarketingRender $render): ?array
    {
        try {
            return DB::transaction(function () use ($render): ?array {
                $state = $this->lockedCurrentState($render);
                if ($state === null) {
                    return null;
                }

                /** @var MarketingRender $locked */
                $locked = $state['render'];
                $contents = Storage::disk($locked->disk)->get((string) $locked->path);
                $image = @getimagesizefromstring($contents);
                if (! is_array($image)
                    || ($image['mime'] ?? null) !== 'image/png'
                    || (int) ($image[0] ?? 0) !== (int) $locked->width
                    || (int) ($image[1] ?? 0) !== (int) $locked->height) {
                    return null;
                }

                return [
                    'contents' => $contents,
                    'mime_type' => 'image/png',
                    'size' => strlen($contents),
                    'creative_title' => (string) $state['creative']->title,
                ];
            });
        } catch (Throwable) {
            return null;
        }
    }

    private function finalize(MarketingRender $render, string $outputPath): MarketingRender
    {
        $disk = $this->renderDisk();
        $path = trim((string) config('marketing.renders.directory', 'marketing/renders'), '/')
            .'/'.now()->format('Y/m').'/'.$render->fingerprint.'.png';
        $stored = false;

        try {
            return DB::transaction(function () use ($render, $outputPath, $disk, $path, &$stored): MarketingRender {
                $this->files->lockSourceSelection();
                $creative = MarketingCreative::query()->lockForUpdate()->find($render->marketing_creative_id);
                if (! $creative) {
                    throw new RuntimeException('Das Motiv existiert nicht mehr.');
                }

                $variants = $creative->variants()->orderBy('id')->lockForUpdate()->get();
                $this->assets->lockDocumentsForUpdate($this->documents($variants));
                $this->synchronizeApprovalState($creative, $variants);
                $variant = $variants->firstWhere('id', (int) $render->marketing_creative_variant_id);
                $locked = MarketingRender::query()->lockForUpdate()->findOrFail($render->id);

                if (! $variant
                    || ! hash_equals((string) $locked->fingerprint, $this->fingerprint($creative, $variant))) {
                    $this->markFailed(
                        $locked,
                        'Das Motiv oder ein verwendetes Medium wurde während des Exports geändert. Bitte den Export erneut starten.',
                    );

                    return $locked;
                }

                if ($locked->status === MarketingRenderStatus::Completed
                    && $locked->path
                    && $this->isPrivateDisk((string) $locked->disk)
                    && Storage::disk($locked->disk)->exists($locked->path)) {
                    return $locked;
                }

                $stream = fopen($outputPath, 'rb');
                if ($stream === false) {
                    throw new RuntimeException('Das gerenderte PNG konnte nicht gelesen werden.');
                }

                try {
                    $stored = Storage::disk($disk)->put($path, $stream);
                } finally {
                    fclose($stream);
                }
                if (! $stored) {
                    throw new RuntimeException('Das gerenderte PNG konnte nicht privat gespeichert werden.');
                }

                $locked->forceFill([
                    'status' => MarketingRenderStatus::Completed,
                    'disk' => $disk,
                    'path' => $path,
                    'mime_type' => 'image/png',
                    'error' => null,
                    'rendered_at' => now(),
                ])->save();

                return $locked;
            });
        } catch (Throwable $exception) {
            if ($stored) {
                try {
                    Storage::disk($disk)->delete($path);
                } catch (Throwable $cleanupException) {
                    Log::warning('Konnte fehlgeschlagenen Marketing-Export nicht entfernen.', [
                        'disk' => $disk,
                        'path' => $path,
                        'error' => $cleanupException->getMessage(),
                    ]);
                }
            }

            throw $exception;
        }
    }

    /** @return array{render: MarketingRender, creative: MarketingCreative, variant: MarketingCreativeVariant}|null */
    private function lockedCurrentState(MarketingRender $render): ?array
    {
        $this->files->lockSourceSelection();
        $creative = MarketingCreative::query()->lockForUpdate()->find($render->marketing_creative_id);
        if (! $creative) {
            return null;
        }

        $variants = $creative->variants()->orderBy('id')->lockForUpdate()->get();
        $this->assets->lockDocumentsForUpdate($this->documents($variants));
        $this->synchronizeApprovalState($creative, $variants);
        $variant = $variants->firstWhere('id', (int) $render->marketing_creative_variant_id);
        $locked = MarketingRender::query()->lockForUpdate()->find($render->id);

        if (! $variant
            || ! $locked
            || $locked->status !== MarketingRenderStatus::Completed
            || ! $locked->path
            || ! $this->isPrivateDisk((string) $locked->disk)
            || ! Storage::disk($locked->disk)->exists($locked->path)
            || ! hash_equals((string) $locked->fingerprint, $this->fingerprint($creative, $variant))) {
            return null;
        }

        return ['render' => $locked, 'creative' => $creative, 'variant' => $variant];
    }

    /** @param Collection<int, MarketingCreativeVariant> $variants */
    private function synchronizeApprovalState(MarketingCreative $creative, Collection $variants): void
    {
        if ($creative->status !== MarketingCreativeStatus::Approved) {
            return;
        }

        $current = $this->assets->creativeApprovalFingerprint($variants);
        if (is_string($creative->approval_dependency_hash)
            && strlen($creative->approval_dependency_hash) === 64
            && hash_equals($creative->approval_dependency_hash, $current)) {
            return;
        }

        $creative->forceFill([
            'status' => MarketingCreativeStatus::Draft,
            'approved_by' => null,
            'approved_at' => null,
            'approval_dependency_hash' => null,
        ])->save();
    }

    /** @param Collection<int, MarketingCreativeVariant> $variants */
    private function requiresWatermarkForVariants(MarketingCreative $creative, Collection $variants): bool
    {
        if ($creative->status !== MarketingCreativeStatus::Approved
            || ! is_string($creative->approval_dependency_hash)
            || strlen($creative->approval_dependency_hash) !== 64) {
            return true;
        }

        return ! hash_equals(
            $creative->approval_dependency_hash,
            $this->assets->creativeApprovalFingerprint($variants),
        );
    }

    /** @param Collection<int, MarketingCreativeVariant> $variants
     * @return list<array{html: string, css: string}>
     */
    private function documents(Collection $variants): array
    {
        return $variants
            ->map(fn (MarketingCreativeVariant $variant): array => [
                'html' => (string) $variant->html,
                'css' => (string) $variant->css,
            ])
            ->values()
            ->all();
    }

    private function renderDisk(): string
    {
        $disk = trim((string) config('marketing.disk', 'private'));
        if (! $this->isPrivateDisk($disk)) {
            throw new RuntimeException('Marketing-Exporte müssen auf einem privaten Dateisystem gespeichert werden.');
        }

        return $disk;
    }

    private function isPrivateDisk(string $disk): bool
    {
        return $disk !== '' && config('filesystems.disks.'.$disk.'.visibility') === 'private';
    }

    private function markFailed(MarketingRender $render, string $message): void
    {
        $disk = (string) $render->disk;
        $path = $render->path;
        $render->forceFill([
            'status' => MarketingRenderStatus::Failed,
            'path' => null,
            'mime_type' => null,
            'error' => $message,
            'rendered_at' => null,
        ])->save();

        if ($path && $this->isPrivateDisk($disk)) {
            DB::afterCommit(static function () use ($disk, $path): void {
                try {
                    Storage::disk($disk)->delete($path);
                } catch (Throwable $exception) {
                    Log::warning('Konnte veralteten Marketing-Export nicht entfernen.', [
                        'disk' => $disk,
                        'path' => $path,
                        'error' => $exception->getMessage(),
                    ]);
                }
            });
        }
    }

    private function fileUrl(string $path): string
    {
        $normalized = str_replace('\\', '/', rtrim($path, '\\/')).'/';
        if (preg_match('/^[A-Za-z]:\//', $normalized)) {
            return 'file:///'.$normalized;
        }

        return 'file://'.$normalized;
    }
}
