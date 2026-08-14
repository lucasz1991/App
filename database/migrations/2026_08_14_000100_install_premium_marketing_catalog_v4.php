<?php

use App\Enums\MarketingCreativeFormat;
use App\Enums\MarketingCreativeStatus;
use App\Models\MarketingCreative;
use App\Models\MarketingCreativeVariant;
use App\Models\User;
use App\Services\Marketing\MarketingContentBinder;
use App\Services\Marketing\MarketingHtmlSanitizer;
use App\Services\Marketing\MarketingStudioService;
use App\Services\Marketing\MarketingTemplateFactory;
use Illuminate\Database\Migrations\Migration;
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

    /**
     * Portable fingerprints of the complete version-3 catalogue. Company
     * profile fields are deliberately excluded from the editorial hash and
     * retained during conversion.
     *
     * @var array<string, array{
     *     legacy_key:string,
     *     type:string,
     *     title:string,
     *     editorial_hash:string,
     *     variants:array<string, array{html:string,css:string}>
     * }>
     */
    private const RELEASES = [
        MarketingTemplateFactory::PREMIUM_COMPANY_PROFILE => [
            'legacy_key' => MarketingTemplateFactory::INFO_WAGENMEISTER_ROLE,
            'type' => 'info',
            'title' => 'Beruf Wagenmeister – Sicherheit am Zug',
            'editorial_hash' => 'debde48dfd5618762750895031fa34957d43abf89f23a0e666966ac07a619f0e',
            'variants' => [
                'story' => [
                    'html' => '0ab30578efde7a105e0d2bb766d53146e755fb1c68d9aceb868689573b3c3e38',
                    'css' => '6c24e695ea910ce6e40215cbe300499d5a8c5488f1bc8ee639f076de458d1c4c',
                ],
                'post' => [
                    'html' => '1c3083fa9ea71c8565cbaf3cfcb1e6b9c78334713e47360915cbd2c7f4e5ce7b',
                    'css' => '36fe2b26996da033c27829451b66fcc1b680a8bbc9552c0638652b384f009299',
                ],
                'web' => [
                    'html' => 'b8091d3c9fb4258d7c221f66b30c1e6b41c790de4aac16be3cd6e3791532e81c',
                    'css' => '3fac88b1875a06a96cfc9127f428393fc49d5e82f4a4d5afa9e857201dbd6cf2',
                ],
            ],
        ],
        MarketingTemplateFactory::PREMIUM_JOB_WAGENMEISTER => [
            'legacy_key' => MarketingTemplateFactory::JOB_WAGENMEISTER,
            'type' => 'job',
            'title' => 'Wagenmeister (m/w/d) – Karriere',
            'editorial_hash' => 'e08fe8ea905317fe3c4c5520e2707aa27c03f57beba955339714f7390c8a5143',
            'variants' => [
                'story' => [
                    'html' => 'c619e76f8117842d9f8b6b9cb59e99915b37cdc99d606fd3ac01cce1a3ed18fb',
                    'css' => 'e6c14a8dbbd23b7bac8493ab7642da0048faf265f8c9a25bb9fe4d48f84e2541',
                ],
                'post' => [
                    'html' => '8461d34022e0c414eaa30b36d1f954ba1ff78138d26f0dd0eff965d1deb500b4',
                    'css' => '30e09ec53365e7ec65ef7fab6a22705b12b99afc8943bbc3747a9267d19c2cf4',
                ],
                'web' => [
                    'html' => '7c9bd5635908277d31540a2eaeabfc857d0c45ca2e49d9cf78eae5094e495b80',
                    'css' => '67810a19f51d5f35ceec145ff02e3536088f2b6d712207d24939ddd1c2aac8cf',
                ],
            ],
        ],
        MarketingTemplateFactory::PREMIUM_GERMANY_NETWORK => [
            'legacy_key' => MarketingTemplateFactory::INFO_GERMANY_NETWORK,
            'type' => 'info',
            'title' => 'Deutschlandweit im Einsatz – RailTime Netzwerk',
            'editorial_hash' => 'dca8ee89da7a567b4ab6d3d24ed5764c1ad26fae7509b5e1c68228d73f59fbb0',
            'variants' => [
                'story' => [
                    'html' => '688c5087767c398b26920606034d38f00372568558efaea8114db95d77ebb70f',
                    'css' => 'dff9e4954c8960ff2ee551a51baecd79c5139b85ecff1428fb32e5f31a721a61',
                ],
                'post' => [
                    'html' => 'e5db0745caf22cfffd4328984e3d4fb3501c02942cb5c427259a678390117fc6',
                    'css' => '921fda380e94e70ca0e35711438616eb97809ffb8b48b288dc807816c6eb9ccb',
                ],
                'web' => [
                    'html' => 'e307986cc09f2e98972894096783c3482b35d472cfc23e395b665512603f0bf9',
                    'css' => '2c58799ab33a5bd5cf8ad29302025c299e4e0ce059e211b7ec78a168f335b00e',
                ],
            ],
        ],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('marketing_creatives')
            || ! Schema::hasTable('marketing_creative_variants')
            || ! Schema::hasTable('users')) {
            return;
        }

        $templates = app(MarketingTemplateFactory::class);
        $binder = app(MarketingContentBinder::class);
        $sanitizer = app(MarketingHtmlSanitizer::class);
        $studio = app(MarketingStudioService::class);

        DB::transaction(function () use ($templates, $binder, $sanitizer, $studio): void {
            $actor = null;
            $actorWasResolved = false;

            foreach (self::RELEASES as $premiumKey => $release) {
                $premiumExists = MarketingCreative::withTrashed()
                    ->where('shared_content->template_key', $premiumKey)
                    ->lockForUpdate()
                    ->exists();
                if ($premiumExists) {
                    continue;
                }

                $legacyCreatives = MarketingCreative::withTrashed()
                    ->where('shared_content->template_key', $release['legacy_key'])
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();
                $candidates = $legacyCreatives
                    ->filter(fn (MarketingCreative $creative): bool => $this->isUntouchedV3Starter(
                        $creative,
                        $release,
                        $studio,
                    ))
                    ->values();

                if ($candidates->count() === 1) {
                    $this->installDefinition(
                        $candidates->first(),
                        $templates->definitionByKey($premiumKey),
                        $binder,
                        $sanitizer,
                        $studio,
                    );

                    continue;
                }

                if (! $actorWasResolved) {
                    $actor = $this->actor();
                    $actorWasResolved = true;
                }

                // A fresh database has no administrator while migrations run.
                // The idempotent MarketingStudioSeeder fills these records as
                // soon as an administrator exists.
                if (! $actor instanceof User) {
                    continue;
                }

                $studio->createFromTemplate(
                    $templates->typeForKey($premiumKey),
                    $actor,
                    $premiumKey,
                );
            }
        });
    }

    public function down(): void
    {
        // Veröffentlichte oder inzwischen bearbeitete Motive werden nie automatisch zurückgesetzt.
    }

    /**
     * @param  array{
     *     legacy_key:string,
     *     type:string,
     *     title:string,
     *     editorial_hash:string,
     *     variants:array<string, array{html:string,css:string}>
     * }  $release
     */
    private function isUntouchedV3Starter(
        MarketingCreative $creative,
        array $release,
        MarketingStudioService $studio,
    ): bool {
        if ($creative->trashed()
            || $creative->getRawOriginal('type') !== $release['type']
            || $creative->getRawOriginal('status') !== MarketingCreativeStatus::Draft->value
            || $creative->title !== $release['title']
            || $creative->approved_by !== null
            || $creative->approved_at !== null
            || $creative->approval_dependency_hash !== null
            || data_get($creative->shared_content, 'seed_version') !== MarketingTemplateFactory::SEED_VERSION
            || $this->editorialHash($creative->shared_content ?? []) !== $release['editorial_hash']) {
            return false;
        }

        $variants = $creative->variants()
            ->withTrashed()
            ->lockForUpdate()
            ->get();
        if ($variants->count() !== count(MarketingCreativeFormat::cases())) {
            return false;
        }

        $seenFormats = [];
        foreach ($variants as $variant) {
            if (! $variant instanceof MarketingCreativeVariant || $variant->trashed()) {
                return false;
            }

            $format = (string) $variant->getRawOriginal('format');
            $fingerprints = $release['variants'][$format] ?? null;
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
                    'template' => $release['legacy_key'],
                    'format' => $format,
                    'schema' => 3,
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
     * @param  array{title:string,shared_content:array<string,mixed>,variants:array<string,array{builder_data:array<string,mixed>,html:string,css:string}>}  $definition
     */
    private function installDefinition(
        MarketingCreative $creative,
        array $definition,
        MarketingContentBinder $binder,
        MarketingHtmlSanitizer $sanitizer,
        MarketingStudioService $studio,
    ): void {
        $sharedContent = $definition['shared_content'];
        foreach (self::PRESERVED_COMPANY_FIELDS as $field) {
            if (array_key_exists($field, $creative->shared_content ?? [])) {
                $sharedContent[$field] = $creative->shared_content[$field];
            }
        }

        $variants = $creative->variants()
            ->lockForUpdate()
            ->get()
            ->keyBy(fn (MarketingCreativeVariant $variant): string => (string) $variant->getRawOriginal('format'));

        foreach (MarketingCreativeFormat::cases() as $format) {
            /** @var MarketingCreativeVariant $variant */
            $variant = $variants->get($format->value);
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

    private function actor(): ?User
    {
        $admins = User::query()->where('role', 'admin');
        $preferredEmail = trim((string) config('marketing.seed_admin_email', ''));
        if ($preferredEmail !== '') {
            $preferred = (clone $admins)->where('email', $preferredEmail)->first();
            if ($preferred instanceof User) {
                return $preferred;
            }
        }

        return $admins->orderBy('id')->first();
    }
};
