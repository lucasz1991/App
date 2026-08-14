<?php

use App\Enums\MarketingCreativeFormat;
use App\Enums\MarketingCreativeStatus;
use App\Enums\MarketingCreativeType;
use App\Models\MarketingCreative;
use App\Models\MarketingCreativeVariant;
use App\Services\Marketing\MarketingContentBinder;
use App\Services\Marketing\MarketingHtmlSanitizer;
use App\Services\Marketing\MarketingStudioService;
use App\Services\Marketing\MarketingTemplateFactory;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var list<string> */
    private const PRESERVED_COMPANY_FIELDS = [
        'contact_phone',
        'contact_email',
        'website',
        'company_name',
        'company_address',
    ];

    private const OLD_EDITORIAL_HASH = 'f7f211de5fbfae7fc4a337d05c9078cf2cdcfbbb8ade341b55c4b2a9e8dacaf9';

    /** @var array<string, array{html:string,css:string}> */
    private const OLD_VARIANT_FINGERPRINTS = [
        'story' => [
            'html' => 'c3a69b9f28f3c78cc97cf461c242e2ace52cbb7bad252cf94886080c5e88e987',
            'css' => '9450c08ae1ec9123e4797e67a6ce4c158ae7fca0149236f1b8d845e0f7bc01fe',
        ],
        'post' => [
            'html' => '1ec4fba2037151d43cde74e721b069a350062dbde3f795c375bff5337d759882',
            'css' => 'f24176b5aa449ec6498f0f4bfa76d840b2f23a1f25bfa133f5f5338c73362d85',
        ],
        'web' => [
            'html' => 'e62c8bb8b19ad21fbd875c09f7d50a20df5e46d61497ad44123961f0e09be8bf',
            'css' => '6335bfd68b24c5da5ffd494cc60ccf811f1de61d82d6144a138d3a264f6abf36',
        ],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('marketing_creatives')
            || ! Schema::hasTable('marketing_creative_variants')) {
            return;
        }

        $templates = app(MarketingTemplateFactory::class);
        $binder = app(MarketingContentBinder::class);
        $sanitizer = app(MarketingHtmlSanitizer::class);
        $studio = app(MarketingStudioService::class);

        DB::transaction(function () use ($templates, $binder, $sanitizer, $studio): void {
            $definition = $templates->definitionByKey(MarketingTemplateFactory::PREMIUM_JOB_WAGENMEISTER);
            $creatives = MarketingCreative::withTrashed()
                ->where('shared_content->template_key', MarketingTemplateFactory::PREMIUM_JOB_WAGENMEISTER)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            foreach ($creatives as $creative) {
                $variants = $creative->variants()
                    ->withTrashed()
                    ->lockForUpdate()
                    ->get();

                if (! $this->isUntouchedOldV4Job($creative, $variants, $studio)) {
                    continue;
                }

                $this->installDefinition(
                    $creative,
                    $variants,
                    $definition,
                    $binder,
                    $sanitizer,
                    $studio,
                );
            }
        });
    }

    public function down(): void
    {
        // Bearbeitete oder freigegebene Motive werden nie automatisch zurückgesetzt.
    }

    /** @param Collection<int, MarketingCreativeVariant> $variants */
    private function isUntouchedOldV4Job(
        MarketingCreative $creative,
        Collection $variants,
        MarketingStudioService $studio,
    ): bool {
        $sharedContent = $creative->shared_content ?? [];

        if ($creative->trashed()
            || $creative->getRawOriginal('type') !== MarketingCreativeType::Job->value
            || $creative->getRawOriginal('status') !== MarketingCreativeStatus::Draft->value
            || $creative->title !== 'Wagenmeister (m/w/d) – Gemeinsam Sicherheit bewegen'
            || $creative->approved_by !== null
            || $creative->approved_at !== null
            || $creative->approval_dependency_hash !== null
            || data_get($sharedContent, 'template_key') !== MarketingTemplateFactory::PREMIUM_JOB_WAGENMEISTER
            || data_get($sharedContent, 'seed_version') !== MarketingTemplateFactory::PREMIUM_SEED_VERSION
            || ! $this->hasCompanyFields($sharedContent)
            || ! hash_equals(self::OLD_EDITORIAL_HASH, $this->editorialHash($sharedContent))
            || $variants->count() !== count(MarketingCreativeFormat::cases())) {
            return false;
        }

        $seenFormats = [];
        foreach ($variants as $variant) {
            if ($variant->trashed()) {
                return false;
            }

            $format = (string) $variant->getRawOriginal('format');
            $fingerprints = self::OLD_VARIANT_FINGERPRINTS[$format] ?? null;
            if (isset($seenFormats[$format]) || ! is_array($fingerprints)) {
                return false;
            }
            $seenFormats[$format] = true;

            $builderData = $variant->builder_data;
            $pages = is_array($builderData['pages'] ?? null) ? $builderData['pages'] : [];
            $page = is_array($pages[0] ?? null) ? $pages[0] : [];
            $metadata = is_array($builderData['railtime'] ?? null) ? $builderData['railtime'] : [];
            $storedHash = strtolower((string) $variant->content_hash);

            if (count($builderData) !== 3
                || count($pages) !== 1
                || count($page) !== 2
                || ($page['name'] ?? null) !== ucfirst($format)
                || ($page['component'] ?? null) !== $variant->html
                || ($builderData['styles'] ?? null) !== []
                || count($metadata) !== 3
                || $this->canonicalize($metadata) !== $this->canonicalize([
                    'template' => MarketingTemplateFactory::PREMIUM_JOB_WAGENMEISTER,
                    'format' => $format,
                    'schema' => 4,
                ])
                || $variant->version < 1
                || ! preg_match('/^[a-f0-9]{64}$/', $storedHash)
                || ! hash_equals(
                    $storedHash,
                    $studio->contentHash($builderData, (string) $variant->html, (string) $variant->css),
                )
                || ! hash_equals($fingerprints['html'], $this->htmlStructureHash((string) $variant->html))
                || ! hash_equals($fingerprints['css'], hash('sha256', (string) $variant->css))) {
                return false;
            }
        }

        return count($seenFormats) === count(MarketingCreativeFormat::cases());
    }

    /**
     * @param  Collection<int, MarketingCreativeVariant>  $variants
     * @param  array{title:string,shared_content:array<string,mixed>,variants:array<string,array{builder_data:array<string,mixed>,html:string,css:string}>}  $definition
     */
    private function installDefinition(
        MarketingCreative $creative,
        Collection $variants,
        array $definition,
        MarketingContentBinder $binder,
        MarketingHtmlSanitizer $sanitizer,
        MarketingStudioService $studio,
    ): void {
        $sharedContent = $definition['shared_content'];
        foreach (self::PRESERVED_COMPANY_FIELDS as $field) {
            $sharedContent[$field] = $creative->shared_content[$field];
        }

        $variantsByFormat = $variants->keyBy(
            fn (MarketingCreativeVariant $variant): string => (string) $variant->getRawOriginal('format'),
        );

        foreach (MarketingCreativeFormat::cases() as $format) {
            /** @var MarketingCreativeVariant $variant */
            $variant = $variantsByFormat->get($format->value);
            $template = $definition['variants'][$format->value];
            $html = $sanitizer->html($binder->bindHtml(
                (string) $template['html'],
                $sharedContent,
            ));
            $css = $sanitizer->css((string) $template['css']);
            $builderData = $binder->syncBuilderData((array) $template['builder_data'], $html);

            $variant->forceFill([
                'builder_data' => $builderData,
                'html' => $html,
                'css' => $css,
                'content_hash' => $studio->contentHash($builderData, $html, $css),
                'version' => $variant->version + 1,
            ])->save();
        }

        $creative->forceFill([
            'title' => $definition['title'],
            'shared_content' => $sharedContent,
            'status' => MarketingCreativeStatus::Draft,
            'approved_by' => null,
            'approved_at' => null,
            'approval_dependency_hash' => null,
        ])->save();
    }

    /** @param array<string, mixed> $content */
    private function hasCompanyFields(array $content): bool
    {
        foreach (self::PRESERVED_COMPANY_FIELDS as $field) {
            if (! array_key_exists($field, $content)) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, mixed> $content */
    private function editorialHash(array $content): string
    {
        foreach (self::PRESERVED_COMPANY_FIELDS as $field) {
            unset($content[$field]);
        }

        try {
            return hash('sha256', json_encode(
                $this->canonicalize($content),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ));
        } catch (JsonException) {
            return '';
        }
    }

    private function htmlStructureHash(string $html): string
    {
        $document = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadHTML(
            '<?xml encoding="utf-8" ?><div data-rt-catalog-root="1">'.$html.'</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET,
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (! $loaded) {
            return '';
        }

        $xpath = new DOMXPath($document);
        foreach (['data-rt-binding', 'data-rt-binding-list', 'data-rt-binding-facts'] as $attribute) {
            $nodes = $xpath->query('//*[@'.$attribute.']');
            if ($nodes === false) {
                continue;
            }

            foreach ($nodes as $node) {
                while ($node->firstChild) {
                    $node->removeChild($node->firstChild);
                }
            }
        }

        foreach (['href', 'src'] as $attribute) {
            $nodes = $xpath->query('//*[@data-rt-binding-'.$attribute.']');
            if ($nodes === false) {
                continue;
            }

            foreach ($nodes as $node) {
                $node->removeAttribute($attribute);
            }
        }

        $root = $xpath->query('//*[@data-rt-catalog-root="1"]')?->item(0);
        if (! $root instanceof DOMElement) {
            return '';
        }

        $output = '';
        foreach ($root->childNodes as $child) {
            $output .= $document->saveHTML($child) ?: '';
        }

        return hash('sha256', trim($output));
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        foreach ($value as $key => $nested) {
            $value[$key] = $this->canonicalize($nested);
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }
};
