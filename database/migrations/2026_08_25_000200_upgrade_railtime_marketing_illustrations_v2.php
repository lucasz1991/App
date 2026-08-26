<?php

use App\Enums\MarketingCreativeStatus;
use App\Models\MarketingCreative;
use App\Models\MarketingCreativeVariant;
use App\Services\Marketing\MarketingContentBinder;
use App\Services\Marketing\MarketingHtmlSanitizer;
use App\Services\Marketing\MarketingStudioService;
use App\Services\Marketing\MarketingTemplateFactory;
use App\Support\MarketingBrandAssets;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
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
        'railtime_2026_unternehmen' => '9f22a0144ebad1dff717925427516cc8a76b3aa5bdf28402c672a3aceb52decf',
        'railtime_2026_job_wagenmeister' => 'b583711cbd5b0f6608139af306f07aa98a10b47ba8e235253a2ca6b99e61ffd2',
        'railtime_2026_deutschland_netzwerk' => 'd95f72236e0f2b14f59e280d25fd84e069e7a50e93e9157f5639df186c7c47f6',
        'railtime_2026_karriere_wagenmeister_story' => 'e14b5fde3384d57f46c512f4ec13f45124bb4c6b9fda0e9960e9b6eeb671c576',
        'railtime_2026_karriere_triebfahrzeugfuehrer_story' => 'a09542902747da495c602774288878b6ef888d19ff904ffa9a2303f35a555d3e',
        'railtime_2026_karriere_arbeitszugfuehrer_story' => '6fca70c72033979462652001e45d3071c5fc415da9f5824337429c0ead6bebe0',
    ];

    /** @var array<string, string> */
    private const TARGET_ASSET_SHA256 = [
        '/rt-brand/illustrations/v2/benefit-bonus.svg' => '3894ed82827ba5422bb6df2dbbb4e566fd18ee85aedf97e2ac4066175ea86b82',
        '/rt-brand/illustrations/v2/benefit-contract.svg' => '8fde7667a07c7281f2fed15837535eb631a171a80e3b5ad1cf314112ad0d29ce',
        '/rt-brand/illustrations/v2/benefit-corporate.svg' => '649f8109ff632a372b87dfa6c2e1639e045d28c7091c8c90987b3b7443858df4',
        '/rt-brand/illustrations/v2/benefit-learning.svg' => 'e4ac273505f60cdf8c44699aa145f8f7c45e3564921b91defd8980437f066ccb',
        '/rt-brand/illustrations/v2/benefit-pension.svg' => 'a123c2af4d7f2303c4473678c221240c725a85437cfd68a4923f7e076b9406a8',
        '/rt-brand/illustrations/v2/benefit-salary.svg' => '14c6cc8814c40946b37e157c6b73e23cdc24641560b3509585effea03b9feff4',
        '/rt-brand/illustrations/v2/benefit-team.svg' => '421d7a72e9412fe5f3fecc920c3fe6d5dbc808a588050bb4242ad33f632c9c03',
        '/rt-brand/illustrations/v2/benefit-track-compact.svg' => '70960fd0bdb7a9e4ffa65ed25dc34a5a1db8dabb42f000c26ede25a8d2472bb5',
        '/rt-brand/illustrations/v2/benefit-track-u.svg' => 'f1b6806d13ce1f40566d8f5d540f12dfe86e58b59491dabedaf650e3e0af0451',
        '/rt-brand/illustrations/v2/benefit-wellbeing.svg' => 'da431fbb2aa9a25d30edaa37b36dbc56bb45a9346cf66d6fe3c4eb4031b6344e',
        '/rt-brand/illustrations/v2/freight-consist.svg' => 'd7edd3a9deec92c4bf71ddb9ad852f096794722a38d42de8b1e996875cdd7932',
        '/rt-brand/illustrations/v2/germany-rail-network.svg' => 'd716a6497cd654c40194a609b0c7d5708f21ee965b9f047cf903f18dd38a7e32',
        '/rt-brand/illustrations/v2/role-arbeitszugfuehrer.svg' => '58150e6837ebc43835f7707a214762c6c5cc5c7911307b34e13ecdaf63287c6f',
        '/rt-brand/illustrations/v2/role-triebfahrzeugfuehrer.svg' => 'c0a7b4fa5a0ea4cd28b8a9aae28d241f32f2285bdb0695bc058dad6759609bd6',
        '/rt-brand/illustrations/v2/role-wagenmeister.svg' => 'c53f649fdae5f8cb698169ece00d2c83215f4c55087dd52c19767409706f145c',
    ];

    /**
     * Each source pins a complete published catalogue state. Premium
     * releases permit every positive, uniform variant version because their
     * historical conversion chain retained the version counter. Career V5
     * has two known paths: the original pre-track release and the current
     * post-track release.
     *
     * @var array<string, array{
     *     type:string,
     *     seed:int,
     *     sources:list<array{
     *         title:string,
     *         editorial:string,
     *         versions:list<int>|null,
     *         variants:array<string,array{html:string,css:string}>
     *     }>
     * }>
     */
    private const SOURCE_RELEASES = [
        'railtime_2026_unternehmen' => [
            'type' => 'info',
            'seed' => 4,
            'sources' => [[
                'title' => 'RailTime vorstellen – Menschen, Technik, Verantwortung',
                'editorial' => '1b32ea6ea0eba9daaa9d9e6e297c6eddcf37504e5a52344b44001858d79f8836',
                'versions' => null,
                'variants' => [
                    'story' => ['html' => '8fbed4c9ec6d7bb7db3dcacabf109416e1c39cb01b90276f179dfaf4a26447c4', 'css' => '567b66ea501b460cc3fba38d0a1235b3d58bc6359808105291860f794efc8bf9'],
                    'post' => ['html' => 'b92170f098d2fcf637e4b3a8a6ff2f6e5b1a7435999834ed10877364e1282c86', 'css' => '07be25ed3612ebe4b522517412d1e00d3fdf6c2d2bdb98317350cb623deb61ec'],
                    'web' => ['html' => '57a13eec73486aaf548e42cf66c16e7ff2c54b6e5016ebc5d29024735e7af204', 'css' => '4efbcd719d3759b8b83073a9bd012421971c04308e4b03e58c6cdca8bdebf93b'],
                ],
            ]],
        ],
        'railtime_2026_job_wagenmeister' => [
            'type' => 'job',
            'seed' => 4,
            'sources' => [[
                'title' => 'Wagenmeister (m/w/d) – Gemeinsam Sicherheit bewegen',
                'editorial' => '8eb686d9693c44a3cdbeb61266a3b7af9a6a6f81789991727377d250d61e389c',
                'versions' => null,
                'variants' => [
                    'story' => ['html' => '93febff91755f3662cf57e9efd41605c31e089e7f9b9306a90202b4aea85a415', 'css' => 'a1230cb13bca4bfd3352c8f40f99bd8c6d9c9f996adac778d8730fdd779d511a'],
                    'post' => ['html' => 'f706fdd108a86c09537ce960a2482bba967e797b287d26096448c8e4c680a074', 'css' => '3d9e8a68187272682e0c12d6d7654c4a98043654d1b3f6feefa10d848153d135'],
                    'web' => ['html' => '86a4c2748db1fa1d28aad9e2df75c674156444db12331075815fc1ec6b089d24', 'css' => '195c175671bcb524ee359a95841fd045bf166bc712110f63eee9d20ba7883b98'],
                ],
            ]],
        ],
        'railtime_2026_deutschland_netzwerk' => [
            'type' => 'info',
            'seed' => 4,
            'sources' => [[
                'title' => 'Deutschlandweit im Einsatz – RailTime Netzwerk',
                'editorial' => 'f25269749c9162f587bbd30cbbd01c0e815cbc19d747c096aa5420b6146a5f22',
                'versions' => null,
                'variants' => [
                    'story' => ['html' => '9a54887a0c8457dcdbb41074bdc29b8b7c4df58f9e34fc4390057918b50eef64', 'css' => '67200035011b183d1a822382581a96d5e4c5bf7107c76cd6d69efce36f42acf0'],
                    'post' => ['html' => '30e50f8e810dbb027d2008f5f36d973d3e2b7877b5f1cc9819496bb4c00a5997', 'css' => 'c6c0a80009eaff0e69674a9c416a4647a2fdf7803f4a7763b84f5f270b5a335e'],
                    'web' => ['html' => 'b0526564612e454bea4cfaed6634eabd09288df76b9f289e2b0f3e60c8d51471', 'css' => '0af02a502165cfcbc6ef75439a14149a59d2134bf87d3407ac053a997e7a750a'],
                ],
            ]],
        ],
        'railtime_2026_karriere_wagenmeister_story' => [
            'type' => 'job',
            'seed' => 5,
            'sources' => [
                [
                    'title' => 'Wagenmeister (m/w/d) – Sicherheit beginnt mit deinem Blick',
                    'editorial' => 'd2411537ce33e98760ed4f536cdd4e19b3d7c4abd3c10c737eb99011b2b67759',
                    'versions' => [1, 2],
                    'variants' => [
                        'story' => ['html' => 'b1ac72389e9a358715313a1f7aa1820b440395bf18ffb4c6e03948163ce85247', 'css' => '37c469c703a6db173477ef0cded343a5d757a2133041db18a0c6254a21478954'],
                        'post' => ['html' => 'be3c6126e059bb5946899c89b4608e91c9d22ca8e926c0523ef766465f836963', 'css' => '582a21148d2c783272b87303d2f36624f2badd416562632a1717c7ed08d6a56b'],
                        'web' => ['html' => '331cd9c3ca56fbefc2612c986eb3d0601ad0268ec611181b1305b16e657f8913', 'css' => '55320412a5689a4393557b3f601fd713f7ece89585edaaaf4ceead4f0ab1bae3'],
                    ],
                ],
                [
                    'title' => 'Wagenmeister (m/w/d) – Sicherheit beginnt mit deinem Blick',
                    'editorial' => 'd2411537ce33e98760ed4f536cdd4e19b3d7c4abd3c10c737eb99011b2b67759',
                    'versions' => [1],
                    'variants' => [
                        'story' => ['html' => 'c7d064b307cf7e4e6446e0bf7251933177b76e096ecf028196c6b1380e102fe0', 'css' => '7630780235e7cf33b8bd4e50976932c6cae82ccd66ad4c653d2abb205f759b18'],
                        'post' => ['html' => 'be3c6126e059bb5946899c89b4608e91c9d22ca8e926c0523ef766465f836963', 'css' => '582a21148d2c783272b87303d2f36624f2badd416562632a1717c7ed08d6a56b'],
                        'web' => ['html' => '331cd9c3ca56fbefc2612c986eb3d0601ad0268ec611181b1305b16e657f8913', 'css' => '55320412a5689a4393557b3f601fd713f7ece89585edaaaf4ceead4f0ab1bae3'],
                    ],
                ],
            ],
        ],
        'railtime_2026_karriere_triebfahrzeugfuehrer_story' => [
            'type' => 'job',
            'seed' => 5,
            'sources' => [
                [
                    'title' => 'Triebfahrzeugführer (m/w/d) – Gemeinsam sicher in Bewegung',
                    'editorial' => '9d7cc8bbce035d637f80394e30d49715c39ec2b7150b9b08c439d3eaf1e3c02e',
                    'versions' => [1, 2],
                    'variants' => [
                        'story' => ['html' => 'a1d42ca1cc1cd58412bce263c427282d911efe12bbffd176f86dc02263c2472e', 'css' => '37c469c703a6db173477ef0cded343a5d757a2133041db18a0c6254a21478954'],
                        'post' => ['html' => 'bbffcc540e8c5892821c8a12cdeac627905178806877159d666991f2138f4a93', 'css' => '582a21148d2c783272b87303d2f36624f2badd416562632a1717c7ed08d6a56b'],
                        'web' => ['html' => '331cd9c3ca56fbefc2612c986eb3d0601ad0268ec611181b1305b16e657f8913', 'css' => '55320412a5689a4393557b3f601fd713f7ece89585edaaaf4ceead4f0ab1bae3'],
                    ],
                ],
                [
                    'title' => 'Triebfahrzeugführer (m/w/d) – Gemeinsam sicher in Bewegung',
                    'editorial' => '9d7cc8bbce035d637f80394e30d49715c39ec2b7150b9b08c439d3eaf1e3c02e',
                    'versions' => [1],
                    'variants' => [
                        'story' => ['html' => '6333708330473704a09d9c7a398eefd062d58d585b24f6b0cec2678399820598', 'css' => '7630780235e7cf33b8bd4e50976932c6cae82ccd66ad4c653d2abb205f759b18'],
                        'post' => ['html' => 'bbffcc540e8c5892821c8a12cdeac627905178806877159d666991f2138f4a93', 'css' => '582a21148d2c783272b87303d2f36624f2badd416562632a1717c7ed08d6a56b'],
                        'web' => ['html' => '331cd9c3ca56fbefc2612c986eb3d0601ad0268ec611181b1305b16e657f8913', 'css' => '55320412a5689a4393557b3f601fd713f7ece89585edaaaf4ceead4f0ab1bae3'],
                    ],
                ],
            ],
        ],
        'railtime_2026_karriere_arbeitszugfuehrer_story' => [
            'type' => 'job',
            'seed' => 5,
            'sources' => [
                [
                    'title' => 'Arbeitszugführer (m/w/d) – Sicherheit auf jeder Baustelle',
                    'editorial' => 'cc5756c526b7eee9bab7256006b6bc546a6054ef37e837fb86f620c67b1f47da',
                    'versions' => [1, 2],
                    'variants' => [
                        'story' => ['html' => '5a61da6501cf19cda3c25c0e10ce44c4a9a083e6682fc6a0a7a67b2dffffad68', 'css' => '37c469c703a6db173477ef0cded343a5d757a2133041db18a0c6254a21478954'],
                        'post' => ['html' => 'cad407d00a8383837f6d870b71cc854bfa28163dc4cf1e84ca96db848672f08d', 'css' => '582a21148d2c783272b87303d2f36624f2badd416562632a1717c7ed08d6a56b'],
                        'web' => ['html' => '331cd9c3ca56fbefc2612c986eb3d0601ad0268ec611181b1305b16e657f8913', 'css' => '55320412a5689a4393557b3f601fd713f7ece89585edaaaf4ceead4f0ab1bae3'],
                    ],
                ],
                [
                    'title' => 'Arbeitszugführer (m/w/d) – Sicherheit auf jeder Baustelle',
                    'editorial' => 'cc5756c526b7eee9bab7256006b6bc546a6054ef37e837fb86f620c67b1f47da',
                    'versions' => [1],
                    'variants' => [
                        'story' => ['html' => '48455f83d897695117ae1377b418adf9ae18c60e8341cec10fe3534b05f445e5', 'css' => '7630780235e7cf33b8bd4e50976932c6cae82ccd66ad4c653d2abb205f759b18'],
                        'post' => ['html' => 'cad407d00a8383837f6d870b71cc854bfa28163dc4cf1e84ca96db848672f08d', 'css' => '582a21148d2c783272b87303d2f36624f2badd416562632a1717c7ed08d6a56b'],
                        'web' => ['html' => '331cd9c3ca56fbefc2612c986eb3d0601ad0268ec611181b1305b16e657f8913', 'css' => '55320412a5689a4393557b3f601fd713f7ece89585edaaaf4ceead4f0ab1bae3'],
                    ],
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

        $this->assertTargetAssets();

        $templates = app(MarketingTemplateFactory::class);
        $binder = app(MarketingContentBinder::class);
        $sanitizer = app(MarketingHtmlSanitizer::class);
        $studio = app(MarketingStudioService::class);
        $definitions = [];

        foreach (array_keys(self::SOURCE_RELEASES) as $templateKey) {
            try {
                $definition = $templates->definitionByKey($templateKey);
            } catch (Throwable $exception) {
                throw new RuntimeException('Die Zieldefinition für '.$templateKey.' ist nicht verfügbar.', 0, $exception);
            }

            $expectedHash = self::TARGET_DEFINITION_SHA256[$templateKey] ?? '';
            if ($expectedHash === '' || ! hash_equals($expectedHash, $this->definitionHash($definition))) {
                throw new RuntimeException('Die Zieldefinition für '.$templateKey.' entspricht nicht dem freigegebenen Illustrationsstand.');
            }

            $definitions[$templateKey] = $definition;
        }

        DB::transaction(function () use ($definitions, $binder, $sanitizer, $studio): void {
            foreach (self::SOURCE_RELEASES as $templateKey => $release) {
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
                        $release,
                        $studio,
                    )) {
                        continue;
                    }

                    $this->installDefinition(
                        $creative,
                        $variants,
                        $definitions[$templateKey],
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
        // Benutzeränderungen oder spätere Freigaben werden nie zurückgesetzt.
    }

    private function assertTargetAssets(): void
    {
        foreach (self::TARGET_ASSET_SHA256 as $publicPath => $expectedHash) {
            $absolutePath = MarketingBrandAssets::absolutePath($publicPath);
            $actualHash = is_string($absolutePath) && is_file($absolutePath)
                ? hash_file('sha256', $absolutePath)
                : false;

            if (! MarketingBrandAssets::allows($publicPath)
                || ! is_string($actualHash)
                || ! hash_equals($expectedHash, strtolower($actualHash))) {
                throw new RuntimeException('Das freigegebene Marketing-Asset '.$publicPath.' fehlt oder ist verändert.');
            }
        }
    }

    /**
     * @param  Collection<int, MarketingCreativeVariant>  $variants
     * @param  array{type:string,seed:int,sources:list<array{title:string,editorial:string,versions:list<int>|null,variants:array<string,array{html:string,css:string}>}>}  $release
     */
    private function isUntouchedSourceRelease(
        MarketingCreative $creative,
        Collection $variants,
        string $templateKey,
        array $release,
        MarketingStudioService $studio,
    ): bool {
        $sharedContent = $creative->shared_content ?? [];
        if (! is_array($sharedContent)
            || $creative->trashed()
            || $creative->getRawOriginal('type') !== $release['type']
            || $creative->getRawOriginal('status') !== MarketingCreativeStatus::Draft->value
            || $creative->approved_by !== null
            || $creative->approved_at !== null
            || $creative->approval_dependency_hash !== null
            || ! is_int($creative->created_by)
            || $creative->created_by !== $creative->updated_by
            || data_get($sharedContent, 'template_key') !== $templateKey
            || data_get($sharedContent, 'seed_version') !== $release['seed']
            || array_key_exists('import_source_template_key', $sharedContent)
            || ! $this->hasCompanyFields($sharedContent)
            || $variants->count() !== count(self::FORMATS)) {
            return false;
        }

        $editorialHash = $this->editorialHash($sharedContent);
        foreach ($release['sources'] as $source) {
            if ($creative->title !== $source['title']
                || ! hash_equals($source['editorial'], $editorialHash)
                || ! $this->matchesVariants(
                    $variants,
                    $templateKey,
                    $release['seed'],
                    $source,
                    $studio,
                )) {
                continue;
            }

            return true;
        }

        return false;
    }

    /**
     * @param  Collection<int, MarketingCreativeVariant>  $variants
     * @param  array{title:string,editorial:string,versions:list<int>|null,variants:array<string,array{html:string,css:string}>}  $source
     */
    private function matchesVariants(
        Collection $variants,
        string $templateKey,
        int $seed,
        array $source,
        MarketingStudioService $studio,
    ): bool {
        $seenFormats = [];
        $seenVersions = [];

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
            $seenVersions[$variant->version] = true;

            if (! $this->matchesVariant(
                $variant,
                $templateKey,
                $format,
                $seed,
                $fingerprints,
                $studio,
            )) {
                return false;
            }
        }

        if (count($seenFormats) !== count(self::FORMATS)
            || count($seenVersions) !== 1) {
            return false;
        }

        $version = array_key_first($seenVersions);
        if (! is_int($version) || $version < 1) {
            return false;
        }

        return $source['versions'] === null || in_array($version, $source['versions'], true);
    }

    /** @param array{html:string,css:string} $fingerprints */
    private function matchesVariant(
        MarketingCreativeVariant $variant,
        string $templateKey,
        string $format,
        int $seed,
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
                'schema' => $seed,
            ])
            && $variant->version >= 1
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
