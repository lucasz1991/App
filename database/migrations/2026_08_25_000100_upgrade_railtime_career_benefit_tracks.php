<?php

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
    private const SOURCE_SEED_VERSION = 5;

    /** @var list<string> */
    private const FORMATS = ['story', 'post', 'web'];

    /** @var list<string> */
    private const PRESERVED_COMPANY_FIELDS = [
        'contact_phone',
        'contact_email',
        'website',
        'company_name',
        'company_address',
    ];

    /** @var array<string, string> */
    private const TARGET_DEFINITION_SHA256 = [
        'railtime_2026_karriere_wagenmeister_story' => 'cb54b8ad4c0a456e8cb32e34a0fc745411744ec92cba736e694654952739e6d6',
        'railtime_2026_karriere_triebfahrzeugfuehrer_story' => '7f7cd3fa0ff4ef9ed59738ad369441031a6b21b2d716a7a3795b12dee43f8138',
        'railtime_2026_karriere_arbeitszugfuehrer_story' => '5af4f40f97ffa3d712ecfcf5985186c2a37f318ea9515f45216e1ff413ecc0d3',
    ];

    /**
     * Fingerprints pin the complete, untouched V5 release. A single changed
     * title, content field, variant, approval or layout keeps the motive out
     * of this automatic visual upgrade.
     *
     * @var array<string, array{
     *     title:string,
     *     editorial:string,
     *     variants:array<string,array{html:string,css:string}>
     * }>
     */
    private const SOURCE_RELEASE = [
        'railtime_2026_karriere_wagenmeister_story' => [
            'title' => 'Wagenmeister (m/w/d) – Sicherheit beginnt mit deinem Blick',
            'editorial' => 'd2411537ce33e98760ed4f536cdd4e19b3d7c4abd3c10c737eb99011b2b67759',
            'variants' => [
                'story' => [
                    'html' => 'c7d064b307cf7e4e6446e0bf7251933177b76e096ecf028196c6b1380e102fe0',
                    'css' => '7630780235e7cf33b8bd4e50976932c6cae82ccd66ad4c653d2abb205f759b18',
                ],
                'post' => [
                    'html' => 'be3c6126e059bb5946899c89b4608e91c9d22ca8e926c0523ef766465f836963',
                    'css' => '582a21148d2c783272b87303d2f36624f2badd416562632a1717c7ed08d6a56b',
                ],
                'web' => [
                    'html' => '331cd9c3ca56fbefc2612c986eb3d0601ad0268ec611181b1305b16e657f8913',
                    'css' => '55320412a5689a4393557b3f601fd713f7ece89585edaaaf4ceead4f0ab1bae3',
                ],
            ],
        ],
        'railtime_2026_karriere_triebfahrzeugfuehrer_story' => [
            'title' => 'Triebfahrzeugführer (m/w/d) – Gemeinsam sicher in Bewegung',
            'editorial' => '9d7cc8bbce035d637f80394e30d49715c39ec2b7150b9b08c439d3eaf1e3c02e',
            'variants' => [
                'story' => [
                    'html' => '6333708330473704a09d9c7a398eefd062d58d585b24f6b0cec2678399820598',
                    'css' => '7630780235e7cf33b8bd4e50976932c6cae82ccd66ad4c653d2abb205f759b18',
                ],
                'post' => [
                    'html' => 'bbffcc540e8c5892821c8a12cdeac627905178806877159d666991f2138f4a93',
                    'css' => '582a21148d2c783272b87303d2f36624f2badd416562632a1717c7ed08d6a56b',
                ],
                'web' => [
                    'html' => '331cd9c3ca56fbefc2612c986eb3d0601ad0268ec611181b1305b16e657f8913',
                    'css' => '55320412a5689a4393557b3f601fd713f7ece89585edaaaf4ceead4f0ab1bae3',
                ],
            ],
        ],
        'railtime_2026_karriere_arbeitszugfuehrer_story' => [
            'title' => 'Arbeitszugführer (m/w/d) – Sicherheit auf jeder Baustelle',
            'editorial' => 'cc5756c526b7eee9bab7256006b6bc546a6054ef37e837fb86f620c67b1f47da',
            'variants' => [
                'story' => [
                    'html' => '48455f83d897695117ae1377b418adf9ae18c60e8341cec10fe3534b05f445e5',
                    'css' => '7630780235e7cf33b8bd4e50976932c6cae82ccd66ad4c653d2abb205f759b18',
                ],
                'post' => [
                    'html' => 'cad407d00a8383837f6d870b71cc854bfa28163dc4cf1e84ca96db848672f08d',
                    'css' => '582a21148d2c783272b87303d2f36624f2badd416562632a1717c7ed08d6a56b',
                ],
                'web' => [
                    'html' => '331cd9c3ca56fbefc2612c986eb3d0601ad0268ec611181b1305b16e657f8913',
                    'css' => '55320412a5689a4393557b3f601fd713f7ece89585edaaaf4ceead4f0ab1bae3',
                ],
            ],
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
        $definitions = [];

        foreach (array_keys(self::SOURCE_RELEASE) as $templateKey) {
            try {
                $definition = $templates->definitionByKey($templateKey);
            } catch (Throwable) {
                continue;
            }

            $expectedHash = self::TARGET_DEFINITION_SHA256[$templateKey] ?? '';
            if ($expectedHash === '' || ! hash_equals($expectedHash, $this->definitionHash($definition))) {
                continue;
            }

            $definitions[$templateKey] = $definition;
        }

        DB::transaction(function () use ($definitions, $binder, $sanitizer, $studio): void {
            foreach (self::SOURCE_RELEASE as $templateKey => $source) {
                $definition = $definitions[$templateKey] ?? null;
                if (! is_array($definition)) {
                    continue;
                }

                $creatives = MarketingCreative::withTrashed()
                    ->where('shared_content->template_key', $templateKey)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();

                foreach ($creatives as $creative) {
                    $variants = $creative->variants()
                        ->withTrashed()
                        ->lockForUpdate()
                        ->get();

                    if (! $this->isUntouchedSourceRelease(
                        $creative,
                        $variants,
                        $templateKey,
                        $source,
                        $studio,
                    )) {
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
            }
        });
    }

    public function down(): void
    {
        // Spätere Benutzeränderungen werden durch ein Rollback nie zurückgesetzt.
    }

    /**
     * @param  Collection<int, MarketingCreativeVariant>  $variants
     * @param  array{title:string,editorial:string,variants:array<string,array{html:string,css:string}>}  $source
     */
    private function isUntouchedSourceRelease(
        MarketingCreative $creative,
        Collection $variants,
        string $templateKey,
        array $source,
        MarketingStudioService $studio,
    ): bool {
        $sharedContent = $creative->shared_content ?? [];
        if (! is_array($sharedContent)) {
            return false;
        }

        if ($creative->trashed()
            || $creative->getRawOriginal('type') !== MarketingCreativeType::Job->value
            || $creative->getRawOriginal('status') !== MarketingCreativeStatus::Draft->value
            || $creative->title !== $source['title']
            || $creative->approved_by !== null
            || $creative->approved_at !== null
            || $creative->approval_dependency_hash !== null
            || data_get($sharedContent, 'template_key') !== $templateKey
            || data_get($sharedContent, 'seed_version') !== self::SOURCE_SEED_VERSION
            || ! $this->hasCompanyFields($sharedContent)
            || ! hash_equals($source['editorial'], $this->editorialHash($sharedContent))
            || $variants->count() !== count(self::FORMATS)) {
            return false;
        }

        $seenFormats = [];
        foreach ($variants as $variant) {
            if (! $variant instanceof MarketingCreativeVariant || $variant->trashed()) {
                return false;
            }

            $format = (string) $variant->getRawOriginal('format');
            $fingerprints = $source['variants'][$format] ?? null;
            if (isset($seenFormats[$format]) || ! is_array($fingerprints)) {
                return false;
            }
            $seenFormats[$format] = true;

            if (! $this->matchesVariant(
                $variant,
                $templateKey,
                $format,
                $fingerprints,
                $studio,
            )) {
                return false;
            }
        }

        return count($seenFormats) === count(self::FORMATS);
    }

    /** @param array{html:string,css:string} $fingerprints */
    private function matchesVariant(
        MarketingCreativeVariant $variant,
        string $templateKey,
        string $format,
        array $fingerprints,
        MarketingStudioService $studio,
    ): bool {
        $builderData = $variant->builder_data;
        if (! is_array($builderData)) {
            return false;
        }

        $pages = is_array($builderData['pages'] ?? null) ? $builderData['pages'] : [];
        $page = is_array($pages[0] ?? null) ? $pages[0] : [];
        $metadata = is_array($builderData['railtime'] ?? null) ? $builderData['railtime'] : [];
        $storedHash = strtolower((string) $variant->content_hash);

        return count($builderData) === 3
            && count($pages) === 1
            && count($page) === 2
            && ($page['name'] ?? null) === ucfirst($format)
            && ($page['component'] ?? null) === $variant->html
            && ($builderData['styles'] ?? null) === []
            && $this->canonicalize($metadata) === $this->canonicalize([
                'template' => $templateKey,
                'format' => $format,
                'schema' => self::SOURCE_SEED_VERSION,
            ])
            && $variant->version === 1
            && preg_match('/^[a-f0-9]{64}$/', $storedHash) === 1
            && hash_equals(
                $storedHash,
                $studio->contentHash($builderData, (string) $variant->html, (string) $variant->css),
            )
            && hash_equals($fingerprints['html'], $this->htmlStructureHash((string) $variant->html))
            && hash_equals($fingerprints['css'], hash('sha256', (string) $variant->css));
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

        foreach (self::FORMATS as $format) {
            /** @var MarketingCreativeVariant $variant */
            $variant = $variantsByFormat->get($format);
            $template = $definition['variants'][$format];
            $html = $sanitizer->html($binder->bindHtml((string) $template['html'], $sharedContent));
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

    /** @param array<string, mixed> $definition */
    private function definitionHash(array $definition): string
    {
        if (! is_array($definition['shared_content'] ?? null)) {
            return '';
        }

        foreach (self::PRESERVED_COMPANY_FIELDS as $field) {
            unset($definition['shared_content'][$field]);
        }

        try {
            return hash('sha256', json_encode(
                $this->canonicalize($definition),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ));
        } catch (JsonException) {
            return '';
        }
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
