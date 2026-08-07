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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;

final class MarketingRenderService
{
    public function __construct(private readonly MarketingRenderAssetHydrator $assets) {}

    public function queue(
        MarketingCreative $creative,
        MarketingCreativeFormat $format,
        User $actor,
    ): MarketingRender {
        return DB::transaction(function () use ($creative, $format, $actor): MarketingRender {
            $lockedCreative = MarketingCreative::query()->lockForUpdate()->findOrFail($creative->id);
            if ($lockedCreative->status === MarketingCreativeStatus::Archived) {
                throw ValidationException::withMessages([
                    'creative' => 'Ein archiviertes Motiv kann nicht exportiert werden.',
                ]);
            }

            $variant = $lockedCreative->variants()->where('format', $format->value)->firstOrFail();
            $dimensions = $format->dimensions();
            $fingerprint = $this->fingerprint($lockedCreative, $variant);
            $render = MarketingRender::query()->where('fingerprint', $fingerprint)->lockForUpdate()->first();

            if ($render
                && $render->status === MarketingRenderStatus::Completed
                && $render->path
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
                    'disk' => (string) config('marketing.disk', 'private'),
                    'width' => $dimensions['width'],
                    'height' => $dimensions['height'],
                    'created_by' => $actor->id,
                ]);
            } elseif ($render->status === MarketingRenderStatus::Failed
                || ($render->status === MarketingRenderStatus::Completed && ! Storage::disk($render->disk)->exists((string) $render->path))) {
                $render->forceFill([
                    'status' => MarketingRenderStatus::Pending,
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
        $render = DB::transaction(function () use ($render): MarketingRender {
            $locked = MarketingRender::query()->lockForUpdate()->findOrFail($render->id);
            if ($locked->status === MarketingRenderStatus::Completed
                && $locked->path
                && Storage::disk($locked->disk)->exists($locked->path)) {
                return $locked;
            }

            $locked->forceFill([
                'status' => MarketingRenderStatus::Processing,
                'error' => null,
            ])->save();

            return $locked;
        });

        if ($render->status === MarketingRenderStatus::Completed) {
            return $render;
        }

        $render->load(['creative', 'variant']);
        if (! $render->creative || ! $render->variant) {
            throw new RuntimeException('Das Motiv oder seine Formatvariante existiert nicht mehr.');
        }

        if (! hash_equals($render->fingerprint, $this->fingerprint($render->creative, $render->variant))) {
            $render->forceFill([
                'status' => MarketingRenderStatus::Failed,
                'error' => 'Das Motiv wurde nach dem Exportauftrag geändert. Bitte den Export erneut starten.',
            ])->save();

            return $render;
        }

        $hydrated = $this->assets->hydrate($render->variant->html, $render->variant->css);
        $tempDirectory = storage_path('app/private/marketing/tmp');
        if (! is_dir($tempDirectory) && ! mkdir($tempDirectory, 0775, true) && ! is_dir($tempDirectory)) {
            throw new RuntimeException('Das temporäre Render-Verzeichnis konnte nicht erstellt werden.');
        }

        $token = $render->public_id.'-'.bin2hex(random_bytes(5));
        $inputPath = $tempDirectory.DIRECTORY_SEPARATOR.$token.'.json';
        $outputPath = $tempDirectory.DIRECTORY_SEPARATOR.$token.'.png';

        try {
            $input = json_encode([
                'html' => $hydrated['html'],
                'css' => $hydrated['css'],
                'base_url' => $this->fileUrl(public_path()),
                'watermark' => $render->creative->status !== MarketingCreativeStatus::Approved,
                'creative_id' => $render->creative->public_id,
                'format' => $render->format->value,
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
                (string) $render->width,
                '--height',
                (string) $render->height,
            ];
            $chrome = trim((string) config('marketing.renders.chrome_path', ''));
            if ($chrome !== '') {
                array_push($command, '--chrome', $chrome);
            }
            if ((bool) config('marketing.renders.no_sandbox', false)) {
                $command[] = '--no-sandbox';
            }

            $timeout = min(85, max(10, (int) config('marketing.renders.timeout_seconds', 85)));
            $result = Process::path(base_path())->timeout($timeout)->run($command);
            if (! $result->successful()) {
                throw new RuntimeException('Chromium-Render fehlgeschlagen: '.mb_substr(trim($result->errorOutput()), 0, 700));
            }

            $image = @getimagesize($outputPath);
            if (! is_array($image)
                || ($image['mime'] ?? null) !== 'image/png'
                || (int) ($image[0] ?? 0) !== $render->width
                || (int) ($image[1] ?? 0) !== $render->height) {
                throw new RuntimeException('Der Renderer hat keine PNG-Datei in den erwarteten Abmessungen erzeugt.');
            }

            $path = trim((string) config('marketing.renders.directory', 'marketing/renders'), '/')
                .'/'.now()->format('Y/m').'/'.$render->fingerprint.'.png';
            $stream = fopen($outputPath, 'rb');
            if ($stream === false) {
                throw new RuntimeException('Das gerenderte PNG konnte nicht gelesen werden.');
            }

            try {
                $stored = Storage::disk((string) config('marketing.disk', 'private'))->put($path, $stream);
            } finally {
                fclose($stream);
            }
            if (! $stored) {
                throw new RuntimeException('Das gerenderte PNG konnte nicht privat gespeichert werden.');
            }

            $render->forceFill([
                'status' => MarketingRenderStatus::Completed,
                'disk' => (string) config('marketing.disk', 'private'),
                'path' => $path,
                'mime_type' => 'image/png',
                'error' => null,
                'rendered_at' => now(),
            ])->save();

            return $render->fresh();
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
        return hash('sha256', implode('|', [
            (string) $creative->public_id,
            (string) $variant->id,
            $variant->format->value,
            $variant->content_hash,
            $creative->status->value,
            (string) config('marketing.renders.cache_version', 1),
            (string) $variant->format->dimensions()['width'],
            (string) $variant->format->dimensions()['height'],
        ]));
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
