<?php

namespace App\Services\Marketing;

use App\Enums\MarketingCreativeFormat;
use App\Enums\MarketingCreativeStatus;
use App\Enums\MarketingCreativeType;
use App\Enums\MarketingRenderStatus;
use App\Models\MarketingCreative;
use App\Models\MarketingCreativeVariant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use JsonException;
use Throwable;

final class MarketingStudioService
{
    /** @var list<string> */
    private const OFFICIAL_BRAND_LOGOS = [
        '/rt-brand/img/logo-horizontal.png',
        '/rt-brand/img/logo-horizontal-darkbg.png',
    ];

    /** @var array<string, string> */
    private const PREMIUM_REDESIGN_TEMPLATES = [
        MarketingTemplateFactory::JOB_WAGENMEISTER => MarketingTemplateFactory::PREMIUM_JOB_WAGENMEISTER,
        MarketingTemplateFactory::INFO_WAGENMEISTER_ROLE => MarketingTemplateFactory::PREMIUM_COMPANY_PROFILE,
        MarketingTemplateFactory::INFO_GERMANY_NETWORK => MarketingTemplateFactory::PREMIUM_GERMANY_NETWORK,
        MarketingTemplateFactory::PREMIUM_COMPANY_PROFILE => MarketingTemplateFactory::PREMIUM_COMPANY_PROFILE,
        MarketingTemplateFactory::PREMIUM_JOB_WAGENMEISTER => MarketingTemplateFactory::PREMIUM_JOB_WAGENMEISTER,
        MarketingTemplateFactory::PREMIUM_GERMANY_NETWORK => MarketingTemplateFactory::PREMIUM_GERMANY_NETWORK,
    ];

    public function __construct(
        private readonly MarketingTemplateFactory $templates,
        private readonly MarketingContentBinder $binder,
        private readonly MarketingHtmlSanitizer $sanitizer,
        private readonly MarketingRenderAssetHydrator $renderAssets,
        private readonly MarketingFileSourceService $files,
    ) {}

    public function createFromTemplate(
        MarketingCreativeType $type,
        User $actor,
        ?string $templateKey = null,
    ): MarketingCreative {
        return DB::transaction(function () use ($type, $actor, $templateKey): MarketingCreative {
            if ($templateKey !== null && $this->templates->typeForKey($templateKey) !== $type) {
                throw ValidationException::withMessages([
                    'type' => 'Die gewählte Startvorlage gehört nicht zu diesem Motivtyp.',
                ]);
            }

            $definition = $templateKey === null
                ? $this->templates->definition($type)
                : $this->templates->definitionByKey($templateKey);
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
            $this->assertOfficialBrandLockup($sanitizedHtml, $sanitizedCss);

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

    /**
     * Replaces every format with one server-owned RailTime design while
     * preserving the creative's editorial content. The caller must present a
     * current hash for every format so a redesign can never overwrite a newer
     * edit from another tab.
     *
     * @param  array<string, string>  $expectedHashes
     * @return array{creative:MarketingCreative,preset:string,changed:bool}
     */
    public function redesignFromPreset(
        MarketingCreative $creative,
        string $preset,
        array $expectedHashes,
        User $actor,
    ): array {
        if ($preset !== 'railtime_modern') {
            throw ValidationException::withMessages([
                'preset' => 'Dieses RailTime-Design ist nicht freigegeben.',
            ]);
        }

        return DB::transaction(function () use ($creative, $preset, $expectedHashes, $actor): array {
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
                if (! $variant || strlen($expectedHash) !== 64 || ! hash_equals((string) $variant->content_hash, $expectedHash)) {
                    throw ValidationException::withMessages([
                        'expected_hashes.'.$format->value => 'Das Motiv wurde zwischenzeitlich geändert. Bitte die aktuelle Version neu laden.',
                    ]);
                }
            }

            $storedTemplateKey = data_get($locked->shared_content, 'template_key');
            if (! is_string($storedTemplateKey)
                || ! $this->templates->hasTemplateKey($storedTemplateKey)
                || $this->templates->typeForKey($storedTemplateKey) !== $locked->type
                || ! isset(self::PREMIUM_REDESIGN_TEMPLATES[$storedTemplateKey])) {
                throw ValidationException::withMessages([
                    'creative' => 'Dieses Motiv besitzt keine unterstÃ¼tzte RailTime-Startvorlage und kann nicht automatisch neu gestaltet werden.',
                ]);
            }

            $definition = $this->templates->definitionByKey(
                self::PREMIUM_REDESIGN_TEMPLATES[$storedTemplateKey],
            );
            $changed = false;
            foreach (MarketingCreativeFormat::cases() as $format) {
                $variant = $variants->get($format->value);
                $template = $definition['variants'][$format->value];
                $html = $this->sanitizer->html($this->binder->bindHtml(
                    (string) $template['html'],
                    $locked->shared_content ?? [],
                ));
                $css = $this->sanitizer->css((string) $template['css']);
                $this->assertOfficialBrandLockup($html, $css);
                $builderData = (array) $template['builder_data'];
                $builderData['railtime']['template'] = $storedTemplateKey;
                $builderData['railtime']['design_preset'] = $preset;
                $builderData = $this->binder->syncBuilderData($builderData, $html);
                $hash = $this->contentHash($builderData, $html, $css);

                if (hash_equals((string) $variant->content_hash, $hash)) {
                    continue;
                }

                $variant->forceFill([
                    'builder_data' => $builderData,
                    'html' => $html,
                    'css' => $css,
                    'content_hash' => $hash,
                    'version' => $variant->version + 1,
                ])->save();
                $changed = true;
            }

            $approvalWillReset = $locked->status !== MarketingCreativeStatus::Draft
                || $locked->approved_by !== null
                || $locked->approved_at !== null
                || $locked->approval_dependency_hash !== null;
            $changed = $changed || $approvalWillReset;

            if ($changed) {
                $locked->forceFill(['updated_by' => $actor->id]);
                $this->resetApproval($locked);
                $locked->save();

                activity('marketing')
                    ->causedBy($actor)
                    ->performedOn($locked)
                    ->withProperties([
                        'preset' => $preset,
                        'formats' => array_map(
                            static fn (MarketingCreativeFormat $format): string => $format->value,
                            MarketingCreativeFormat::cases(),
                        ),
                    ])
                    ->log('marketing_creative_redesigned');
            }

            return [
                'creative' => $locked->fresh(['variants']),
                'preset' => $preset,
                'changed' => $changed,
            ];
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
                $this->assertOfficialBrandLockup((string) $variant->html, (string) $variant->css);
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

    private function assertOfficialBrandLockup(string $html, string $css): void
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
            $lockups = $xpath->query('//*[@data-rt-brand-lockup="official"]');
            if ($lockups !== false && $lockups->length === 1) {
                $lockup = $lockups->item(0);
                $logos = $xpath->query('.//img[@src]', $lockup);
                $logo = $logos !== false && $logos->length === 1 ? $logos->item(0) : null;
                $lockupClasses = preg_split('/\s+/', trim((string) $lockup?->attributes?->getNamedItem('class')?->nodeValue)) ?: [];
                $logoClasses = preg_split('/\s+/', trim((string) $logo?->attributes?->getNamedItem('class')?->nodeValue)) ?: [];
                $allowedLockupClasses = ['rt-brand', 'rt-brand-lockup', 'rt-brand-lockup-standard', 'rt-brand-lockup-reverse'];
                $classesAreCanonical = in_array('rt-brand', $lockupClasses, true)
                    && in_array('rt-brand-lockup', $lockupClasses, true)
                    && array_diff($lockupClasses, $allowedLockupClasses) === []
                    && $logoClasses === ['rt-brand-logo'];
                $markupIsCanonical = $lockup?->attributes?->getNamedItem('style') === null
                    && $logo?->attributes?->getNamedItem('style') === null
                    && trim((string) $logo?->attributes?->getNamedItem('alt')?->nodeValue) === 'RT Rail Time GmbH'
                    && in_array(trim((string) $logo?->attributes?->getNamedItem('src')?->nodeValue), self::OFFICIAL_BRAND_LOGOS, true);
                $dangerousBrandCss = $this->cssHidesSelectors($css, [
                    '\.rt-brand(?:-lockup|-logo)?',
                    '\[data-rt-brand-lockup[^]]*\]',
                ]);
                $brandTreeIsVisible = $lockup instanceof \DOMElement
                    && $this->brandTreeIsVisible($lockup, $css);

                if ($classesAreCanonical && $markupIsCanonical && ! $dangerousBrandCss && $brandTreeIsVisible) {
                    return;
                }
            }
        }

        throw ValidationException::withMessages([
            'html' => 'Jede Motivvariante muss das unveränderte offizielle RT-Rail-Time-Firmenlogo enthalten.',
        ]);
    }

    private function brandTreeIsVisible(\DOMElement $lockup, string $css): bool
    {
        $selectors = [];
        $node = $lockup;
        while ($node instanceof \DOMElement) {
            if ($this->hasHiddenBrandDeclaration((string) $node->getAttribute('style'))) {
                return false;
            }
            $id = trim($node->getAttribute('id'));
            if ($id !== '') {
                $selectors[] = '\#'.preg_quote($id, '/').'(?![\w-])';
            }
            foreach (preg_split('/\s+/', trim($node->getAttribute('class'))) ?: [] as $class) {
                if ($class !== '') {
                    $selectors[] = '\.'.preg_quote($class, '/').'(?![\w-])';
                }
            }
            $tag = strtolower($node->tagName);
            if (in_array($tag, ['body', 'main', 'section', 'article', 'header', 'footer'], true)) {
                $selectors[] = '(?<![\w-])'.preg_quote($tag, '/').'(?![\w-])';
            }
            $node = $node->parentNode;
        }

        return ! $this->cssHidesSelectors($css, array_values(array_unique($selectors)));
    }

    /** @param list<string> $selectors */
    private function cssHidesSelectors(string $css, array $selectors): bool
    {
        if ($selectors === []) {
            return false;
        }

        return preg_match(
            '/(?:'.implode('|', $selectors).')[^{}]*\{[^{}]*'.$this->hiddenBrandDeclarationPattern().'/is',
            $css,
        ) === 1;
    }

    private function hasHiddenBrandDeclaration(string $declarations): bool
    {
        return preg_match('/'.$this->hiddenBrandDeclarationPattern().'/is', $declarations) === 1;
    }

    private function hiddenBrandDeclarationPattern(): string
    {
        return '(?:display\s*:\s*none|visibility\s*:\s*(?:hidden|collapse)|opacity\s*:\s*0(?:\.0+)?(?=\s*(?:[;}!]|$))|(?:max-)?(?:width|height)\s*:\s*0(?:px|rem|em|%|\s|;|!|$)|transform\s*:[^;}]*scale\s*\(\s*0(?:\.0+)?\s*\))';
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

    public function delete(MarketingCreative $creative, User $actor): void
    {
        abort_unless($actor->isAdmin(), 403);

        DB::transaction(function () use ($creative, $actor): void {
            $locked = MarketingCreative::query()->lockForUpdate()->findOrFail($creative->id);
            $variants = $locked->variants()->orderBy('id')->lockForUpdate()->get();
            $renders = $locked->renders()->orderBy('id')->lockForUpdate()->get();
            $renderPaths = [];

            foreach ($renders as $render) {
                if ($render->path && $this->isPrivateDisk((string) $render->disk)) {
                    $renderPaths[(string) $render->disk.'\0'.(string) $render->path] = [
                        'disk' => (string) $render->disk,
                        'path' => (string) $render->path,
                    ];
                }

                $render->forceFill([
                    'status' => MarketingRenderStatus::Failed,
                    'path' => null,
                    'mime_type' => null,
                    'error' => 'Das Motiv wurde entfernt. Der Export ist nicht mehr abrufbar.',
                    'rendered_at' => null,
                ])->save();
            }

            $locked->forceFill(['updated_by' => $actor->id])->save();

            activity('marketing')
                ->causedBy($actor)
                ->performedOn($locked)
                ->event('deleted')
                ->withProperties([
                    'public_id' => (string) $locked->public_id,
                    'title' => (string) $locked->title,
                    'type' => $locked->type->value,
                    'status' => $locked->status->value,
                    'variant_ids' => $variants->modelKeys(),
                    'render_ids' => $renders->modelKeys(),
                ])
                ->log('marketing_creative_deleted');

            // Das Model-Event soft-loescht alle Varianten. Renderdatensaetze
            // bleiben als Auditspur erhalten, koennen aber weder angezeigt
            // noch heruntergeladen werden und verweisen auf keinen Blob mehr.
            $locked->delete();

            foreach ($renderPaths as $snapshot) {
                DB::afterCommit(static function () use ($snapshot): void {
                    try {
                        Storage::disk($snapshot['disk'])->delete($snapshot['path']);
                    } catch (Throwable $exception) {
                        Log::warning('Konnte Export eines geloeschten Marketing-Motivs nicht entfernen.', [
                            'disk' => $snapshot['disk'],
                            'path' => $snapshot['path'],
                            'error' => $exception->getMessage(),
                        ]);
                    }
                });
            }
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

    private function isPrivateDisk(string $disk): bool
    {
        return $disk !== '' && config('filesystems.disks.'.$disk.'.visibility') === 'private';
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
