<?php

namespace App\Services\Marketing;

use App\Enums\MarketingCreativeFormat;
use App\Enums\MarketingCreativeStatus;
use App\Enums\MarketingCreativeType;
use App\Models\MarketingCreative;
use App\Models\MarketingCreativeVariant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use JsonException;

final class MarketingStudioService
{
    /** @var list<string> */
    private const OFFICIAL_BRAND_LOGOS = [
        '/rt-brand/img/logo-horizontal.png',
        '/rt-brand/img/logo-horizontal-darkbg.png',
    ];

    public function __construct(
        private readonly MarketingTemplateFactory $templates,
        private readonly MarketingContentBinder $binder,
        private readonly MarketingHtmlSanitizer $sanitizer,
        private readonly MarketingRenderAssetHydrator $renderAssets,
        private readonly MarketingFileSourceService $files,
    ) {}

    public function createFromTemplate(MarketingCreativeType $type, User $actor): MarketingCreative
    {
        return DB::transaction(function () use ($type, $actor): MarketingCreative {
            $definition = $this->templates->definition($type);
            $creative = MarketingCreative::query()->create([
                'type' => $type,
                'status' => MarketingCreativeStatus::Draft,
                'title' => $definition['title'],
                'shared_content' => $definition['shared_content'],
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);

            foreach (MarketingCreativeFormat::cases() as $format) {
                $template = $definition['variants'][$format->value];
                $html = $this->sanitizer->html(
                    $this->binder->bindHtml($template['html'], $definition['shared_content']),
                );
                $css = $this->sanitizer->css($template['css']);
                $builderData = $this->binder->syncBuilderData($template['builder_data'], $html);

                $creative->variants()->create([
                    'format' => $format,
                    'builder_data' => $builderData,
                    'html' => $html,
                    'css' => $css,
                    'content_hash' => $this->contentHash($builderData, $html, $css),
                    'version' => 1,
                ]);
            }

            return $creative->load('variants');
        });
    }

    /**
     * @param  array<string, mixed>  $content
     */
    public function updateSharedContent(
        MarketingCreative $creative,
        array $content,
        User $actor,
        array $expectedHashes,
        ?string $title = null,
    ): MarketingCreative {
        return DB::transaction(function () use ($creative, $content, $actor, $expectedHashes, $title): MarketingCreative {
            $this->files->lockSourceSelection();
            $locked = MarketingCreative::query()->lockForUpdate()->findOrFail($creative->id);
            $this->assertEditable($locked);
            $variants = $locked->variants()
                ->lockForUpdate()
                ->get()
                ->keyBy(fn (MarketingCreativeVariant $variant): string => $variant->format->value);
            $this->renderAssets->lockDocumentsForUpdate(
                $variants->map(fn (MarketingCreativeVariant $variant): array => [
                    'html' => (string) $variant->html,
                    'css' => (string) $variant->css,
                ]),
            );
            foreach (MarketingCreativeFormat::cases() as $format) {
                $variant = $variants->get($format->value);
                $expectedHash = strtolower((string) ($expectedHashes[$format->value] ?? ''));
                if (! $variant || strlen($expectedHash) !== 64 || ! hash_equals($variant->content_hash, $expectedHash)) {
                    throw ValidationException::withMessages([
                        'expected_hashes.'.$format->value => 'Das Motiv wurde zwischenzeitlich geändert. Bitte die aktuelle Version neu laden.',
                    ]);
                }
            }

            $mergedContent = array_replace($locked->shared_content ?? [], $content);
            $nextTitle = trim((string) ($title ?? $locked->title));
            $contentChanged = $mergedContent !== ($locked->shared_content ?? []);
            $titleChanged = $nextTitle !== $locked->title;

            if (! $contentChanged && ! $titleChanged) {
                return $locked->load('variants');
            }

            $locked->forceFill([
                'title' => $nextTitle,
                'shared_content' => $mergedContent,
                'updated_by' => $actor->id,
            ]);
            $this->resetApproval($locked);
            $locked->save();

            if ($contentChanged) {
                foreach ($variants as $variant) {
                    $html = $this->sanitizer->html($this->binder->bindHtml($variant->html, $mergedContent));
                    $builderData = $this->binder->syncBuilderData($variant->builder_data ?? [], $html);
                    $hash = $this->contentHash($builderData, $html, $variant->css);

                    if (! hash_equals($variant->content_hash, $hash)) {
                        $variant->forceFill([
                            'html' => $html,
                            'builder_data' => $builderData,
                            'content_hash' => $hash,
                            'version' => $variant->version + 1,
                        ])->save();
                    }
                }
            }

            return $locked->fresh(['variants']);
        });
    }

    /**
     * @param  array<string, mixed>  $builderData
     */
    public function saveVariant(
        MarketingCreative $creative,
        MarketingCreativeFormat $format,
        array $builderData,
        string $html,
        string $css,
        string $expectedHash,
        User $actor,
    ): MarketingCreativeVariant {
        return DB::transaction(function () use ($creative, $format, $builderData, $html, $css, $expectedHash, $actor): MarketingCreativeVariant {
            $this->files->lockSourceSelection();
            $lockedCreative = MarketingCreative::query()->lockForUpdate()->findOrFail($creative->id);
            $this->assertEditable($lockedCreative);
            $variant = $lockedCreative->variants()
                ->where('format', $format->value)
                ->lockForUpdate()
                ->firstOrFail();

            if (! hash_equals($variant->content_hash, strtolower($expectedHash))) {
                throw ValidationException::withMessages([
                    'expected_hash' => 'Das Motiv wurde zwischenzeitlich geändert. Bitte die aktuelle Version neu laden.',
                ]);
            }

            $sanitizedHtml = $this->sanitizer->html(
                $this->binder->bindHtml($html, $lockedCreative->shared_content ?? []),
            );
            $sanitizedCss = $this->sanitizer->css($css);
            if ($sanitizedHtml === '') {
                throw ValidationException::withMessages([
                    'html' => 'Das Motiv darf nach der Sicherheitsprüfung nicht leer sein.',
                ]);
            }
            $this->assertOfficialBrandLockup($sanitizedHtml);

            $builderData = $this->binder->syncBuilderData($builderData, $sanitizedHtml);
            $this->renderAssets->lockReferencesForUpdate($sanitizedHtml, $sanitizedCss);
            $hash = $this->contentHash($builderData, $sanitizedHtml, $sanitizedCss);
            if (hash_equals($variant->content_hash, $hash)) {
                return $variant;
            }

            $variant->forceFill([
                'builder_data' => $builderData,
                'html' => $sanitizedHtml,
                'css' => $sanitizedCss,
                'content_hash' => $hash,
                'version' => $variant->version + 1,
            ])->save();

            $lockedCreative->forceFill(['updated_by' => $actor->id]);
            $this->resetApproval($lockedCreative);
            $lockedCreative->save();

            return $variant->fresh();
        });
    }

    public function duplicate(MarketingCreative $creative, User $actor): MarketingCreative
    {
        return DB::transaction(function () use ($creative, $actor): MarketingCreative {
            $this->files->lockSourceSelection();
            $source = MarketingCreative::query()->lockForUpdate()->findOrFail($creative->id);
            $variants = $source->variants()->orderBy('id')->lockForUpdate()->get();
            $this->renderAssets->lockDocumentsForUpdate(
                $variants->map(fn (MarketingCreativeVariant $variant): array => [
                    'html' => (string) $variant->html,
                    'css' => (string) $variant->css,
                ]),
            );
            $copy = MarketingCreative::query()->create([
                'type' => $source->type,
                'status' => MarketingCreativeStatus::Draft,
                'title' => mb_substr($source->title.' – Kopie', 0, 160),
                'shared_content' => $source->shared_content,
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);

            foreach ($variants as $variant) {
                $copy->variants()->create([
                    'format' => $variant->format,
                    'builder_data' => $variant->builder_data,
                    'html' => $variant->html,
                    'css' => $variant->css,
                    'content_hash' => $variant->content_hash,
                    'version' => 1,
                ]);
            }

            return $copy->load('variants');
        });
    }

    public function approve(MarketingCreative $creative, User $actor): MarketingCreative
    {
        return DB::transaction(function () use ($creative, $actor): MarketingCreative {
            $this->files->lockSourceSelection();
            $locked = MarketingCreative::query()->lockForUpdate()->findOrFail($creative->id);
            $this->assertEditable($locked);

            $variants = $locked->variants()->orderBy('id')->lockForUpdate()->get();
            $formats = $variants
                ->map(fn (MarketingCreativeVariant $variant): string => $variant->format->value)
                ->all();
            $missing = array_diff(
                array_map(fn (MarketingCreativeFormat $format): string => $format->value, MarketingCreativeFormat::cases()),
                $formats,
            );
            if ($missing !== []) {
                throw ValidationException::withMessages([
                    'creative' => 'Vor der Freigabe müssen Story, Post und Webbild vollständig vorhanden sein.',
                ]);
            }

            foreach ($variants as $variant) {
                $this->assertOfficialBrandLockup((string) $variant->html);
                $actualContentHash = $this->contentHash(
                    $variant->builder_data ?? [],
                    (string) $variant->html,
                    (string) $variant->css,
                );
                if (! hash_equals((string) $variant->content_hash, $actualContentHash)) {
                    throw ValidationException::withMessages([
                        'creative' => 'Eine Motivvariante ist nicht konsistent gespeichert. Bitte erneut speichern.',
                    ]);
                }
            }

            $this->renderAssets->lockDocumentsForUpdate(
                $variants->map(fn (MarketingCreativeVariant $variant): array => [
                    'html' => (string) $variant->html,
                    'css' => (string) $variant->css,
                ]),
            );
            $approvalDependencyHash = $this->renderAssets->creativeApprovalFingerprint($variants);

            $locked->forceFill([
                'status' => MarketingCreativeStatus::Approved,
                'approved_by' => $actor->id,
                'approved_at' => now(),
                'approval_dependency_hash' => $approvalDependencyHash,
                'updated_by' => $actor->id,
            ])->save();

            $approvalFingerprints = $variants->mapWithKeys(fn (MarketingCreativeVariant $variant): array => [
                $variant->format->value => hash('sha256', implode('|', [
                    $variant->content_hash,
                    $this->renderAssets->fingerprint($variant->html, $variant->css),
                ])),
            ])->all();
            activity('marketing')
                ->causedBy($actor)
                ->performedOn($locked)
                ->withProperties([
                    'approved_at' => $locked->approved_at?->toIso8601String(),
                    'approval_dependency_hash' => $approvalDependencyHash,
                    'fingerprints' => $approvalFingerprints,
                ])
                ->log('marketing_creative_approved');

            return $locked->fresh(['variants', 'approver']);
        });
    }

    private function assertOfficialBrandLockup(string $html): void
    {
        $document = new \DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        try {
            $loaded = $document->loadHTML(
                '<?xml encoding="utf-8" ?><div data-rt-brand-root>'.$html.'</div>',
                LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD,
            );
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if ($loaded) {
            $xpath = new \DOMXPath($document);
            $logos = $xpath->query('//*[@data-rt-brand-lockup="official"]//img[@src]');
            if ($logos !== false) {
                foreach ($logos as $logo) {
                    if (in_array(trim((string) $logo->attributes?->getNamedItem('src')?->nodeValue), self::OFFICIAL_BRAND_LOGOS, true)) {
                        return;
                    }
                }
            }
        }

        throw ValidationException::withMessages([
            'html' => 'Jede Motivvariante muss das unveränderte offizielle RT-Rail-Time-Firmenlogo enthalten.',
        ]);
    }

    public function archive(MarketingCreative $creative, User $actor): MarketingCreative
    {
        return DB::transaction(function () use ($creative, $actor): MarketingCreative {
            $locked = MarketingCreative::query()->lockForUpdate()->findOrFail($creative->id);
            $locked->forceFill([
                'status' => MarketingCreativeStatus::Archived,
                'updated_by' => $actor->id,
            ])->save();

            return $locked->fresh(['variants']);
        });
    }

    /** @param array<string, mixed> $builderData */
    public function contentHash(array $builderData, string $html, string $css): string
    {
        try {
            return hash('sha256', json_encode([
                'builder_data' => $this->sortRecursively($builderData),
                'html' => $html,
                'css' => $css,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } catch (JsonException) {
            throw ValidationException::withMessages([
                'builder_data' => 'Die Builder-Daten enthalten ungültige Zeichen.',
            ]);
        }
    }

    private function resetApproval(MarketingCreative $creative): void
    {
        $creative->status = MarketingCreativeStatus::Draft;
        $creative->approved_by = null;
        $creative->approved_at = null;
        $creative->approval_dependency_hash = null;
    }

    private function assertEditable(MarketingCreative $creative): void
    {
        if ($creative->status === MarketingCreativeStatus::Archived) {
            throw ValidationException::withMessages([
                'creative' => 'Ein archiviertes Motiv kann nicht mehr bearbeitet werden.',
            ]);
        }
    }

    private function sortRecursively(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        foreach ($value as $key => $nested) {
            $value[$key] = $this->sortRecursively($nested);
        }

        return $value;
    }
}
