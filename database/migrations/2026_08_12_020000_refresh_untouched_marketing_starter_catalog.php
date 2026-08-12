<?php

use App\Enums\MarketingCreativeFormat;
use App\Enums\MarketingCreativeStatus;
use App\Enums\MarketingCreativeType;
use App\Models\MarketingCreative;
use App\Models\MarketingCreativeVariant;
use App\Models\User;
use App\Services\Marketing\MarketingContentBinder;
use App\Services\Marketing\MarketingHtmlSanitizer;
use App\Services\Marketing\MarketingStudioService;
use App\Services\Marketing\MarketingTemplateFactory;
use App\Support\CompanyData;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var array<string, array{type:string,title:string,html_marker:string,css_markers:array<string,string>}> */
    private const LEGACY_STARTERS = [
        MarketingTemplateFactory::JOB_WAGENMEISTER => [
            'type' => 'job',
            'title' => 'Wagenmeister (m/w/d) – Entwurf',
            'html_marker' => 'rt-job',
            'css_markers' => [
                'story' => '.rt-job-story .rt-hero{position:relative;height:650px',
                'post' => '.rt-job-post .rt-post-panel{position:absolute',
                'web' => '.rt-job-web .rt-web-copy{position:absolute',
            ],
        ],
        MarketingTemplateFactory::INFO_WAGENMEISTER_ROLE => [
            'type' => 'info',
            'title' => 'Wagenmeister-Service – 24/7',
            'html_marker' => 'rt-info',
            'css_markers' => [
                'story' => '.rt-info-story .rt-info-hero{position:relative;height:720px',
                'post' => '.rt-info-post .rt-info-post-lead{position:absolute',
                'web' => '.rt-info-web .rt-info-web-lead{position:absolute',
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

        $preferredActorId = null;

        MarketingCreative::query()
            ->whereIn('type', [MarketingCreativeType::Job->value, MarketingCreativeType::Info->value])
            ->orderBy('id')
            ->chunkById(100, function ($creatives) use ($templates, $binder, $sanitizer, $studio, &$preferredActorId): void {
                foreach ($creatives as $creative) {
                    $actorId = DB::transaction(function () use ($creative, $templates, $binder, $sanitizer, $studio): ?int {
                        $locked = MarketingCreative::withTrashed()
                            ->lockForUpdate()
                            ->find($creative->id);

                        if (! $locked instanceof MarketingCreative || $locked->trashed()) {
                            return null;
                        }

                        $variants = $locked->variants()
                            ->withTrashed()
                            ->lockForUpdate()
                            ->get();
                        $templateKey = $this->legacyTemplateKey($locked);

                        if ($templateKey === null
                            || ! $this->isUntouchedLegacyStarter($locked, $variants, $templateKey, $studio)) {
                            return null;
                        }

                        $definition = $templates->definitionByKey($templateKey);
                        $variantsByFormat = $variants->keyBy(
                            fn (MarketingCreativeVariant $variant): string => (string) $variant->getRawOriginal('format'),
                        );

                        foreach (MarketingCreativeFormat::cases() as $format) {
                            /** @var MarketingCreativeVariant $variant */
                            $variant = $variantsByFormat->get($format->value);
                            $template = $definition['variants'][$format->value];
                            $html = $sanitizer->html($binder->bindHtml(
                                (string) $template['html'],
                                $definition['shared_content'],
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

                        $locked->forceFill([
                            'title' => $definition['title'],
                            'shared_content' => $definition['shared_content'],
                            'status' => MarketingCreativeStatus::Draft,
                            'approved_by' => null,
                            'approved_at' => null,
                            'approval_dependency_hash' => null,
                        ])->save();

                        return is_int($locked->created_by) ? $locked->created_by : null;
                    });

                    $preferredActorId ??= $actorId;
                }
            });

        $this->ensureNetworkStarter($studio, $preferredActorId);
    }

    public function down(): void
    {
        // Neue oder inzwischen bearbeitete Marketinginhalte werden nicht automatisch zurückgesetzt.
    }

    private function legacyTemplateKey(MarketingCreative $creative): ?string
    {
        $templateKey = data_get($creative->shared_content, 'template_key');

        return is_string($templateKey) && array_key_exists($templateKey, self::LEGACY_STARTERS)
            ? $templateKey
            : null;
    }

    /** @param Collection<int, MarketingCreativeVariant> $variants */
    private function isUntouchedLegacyStarter(
        MarketingCreative $creative,
        $variants,
        string $templateKey,
        MarketingStudioService $studio,
    ): bool {
        $legacy = self::LEGACY_STARTERS[$templateKey];

        if ($creative->getRawOriginal('type') !== $legacy['type']
            || $creative->getRawOriginal('status') !== MarketingCreativeStatus::Draft->value
            || $creative->title !== $legacy['title']
            || $creative->approved_by !== null
            || $creative->approved_at !== null
            || $creative->approval_dependency_hash !== null
            || ! is_int($creative->created_by)
            || $creative->created_by !== $creative->updated_by
            || ! $this->matchesLegacySharedContent($creative->shared_content ?? [], $templateKey)
            || $variants->count() !== count(MarketingCreativeFormat::cases())) {
            return false;
        }

        $seenFormats = [];
        foreach ($variants as $variant) {
            if (! $variant instanceof MarketingCreativeVariant || $variant->trashed()) {
                return false;
            }

            $format = (string) $variant->getRawOriginal('format');
            if (isset($seenFormats[$format]) || ! isset($legacy['css_markers'][$format])) {
                return false;
            }
            $seenFormats[$format] = true;

            $builderData = $variant->builder_data;
            $metadata = is_array($builderData['railtime'] ?? null) ? $builderData['railtime'] : [];
            $dimensions = MarketingCreativeFormat::from($format)->dimensions();
            $storedHash = strtolower((string) $variant->content_hash);
            $actualHash = $studio->contentHash($builderData, (string) $variant->html, (string) $variant->css);

            if (! in_array($variant->version, [1, 2], true)
                || data_get($builderData, 'pages.0.component') !== $variant->html
                || data_get($builderData, 'pages.0.name') !== ucfirst($format)
                || ($builderData['styles'] ?? null) !== []
                || count($metadata) !== 3
                || ($metadata['template'] ?? null) !== $legacy['type']
                || ($metadata['format'] ?? null) !== $format
                || ($metadata['schema'] ?? null) !== 2
                || strlen($storedHash) !== 64
                || ! hash_equals($storedHash, $actualHash)
                || ! str_contains((string) $variant->html, 'class="rt-marketing-canvas '.$legacy['html_marker'])
                || ! str_contains((string) $variant->html, '/rt-brand/img/hero-railtime.jpg')
                || ! str_contains((string) $variant->html, '/rt-brand/img/logo-horizontal.png')
                || ! str_contains((string) $variant->css, 'width:'.$dimensions['width'].'px;height:'.$dimensions['height'].'px')
                || ! str_contains((string) $variant->css, $legacy['css_markers'][$format])) {
                return false;
            }
        }

        return count($seenFormats) === count(MarketingCreativeFormat::cases());
    }

    /** @param array<string, mixed> $content */
    private function matchesLegacySharedContent(array $content, string $templateKey): bool
    {
        if (array_key_exists('seed_version', $content) && $content['seed_version'] !== null) {
            return false;
        }
        unset($content['seed_version']);

        return $this->canonicalize($content) === $this->canonicalize($this->legacySharedContent($templateKey));
    }

    /** @return array<string, mixed> */
    private function legacySharedContent(string $templateKey): array
    {
        $company = CompanyData::all();
        $common = [
            'contact_phone' => $company['phone'],
            'contact_email' => $company['email'],
            'website' => preg_replace('#^https?://#i', '', rtrim($company['website'], '/')),
            'company_name' => $company['name'],
            'company_address' => CompanyData::addressLine($company),
        ];

        if ($templateKey === MarketingTemplateFactory::JOB_WAGENMEISTER) {
            return [
                'template_key' => $templateKey,
                'kicker' => 'Komm ins Team',
                'title' => 'Wagenmeister (m/w/d)',
                'subtitle' => 'Deutschlandweit im Einsatz',
                'intro' => 'Du prüfst Güterwagen mit geschultem Blick, dokumentierst zuverlässig und sorgst gemeinsam mit unserem Team für einen sicheren Bahnbetrieb.',
                'facts' => [
                    ['value' => '60+', 'label' => 'Wagenmeister'],
                    ['value' => '24/7', 'label' => 'Einsatzbereitschaft'],
                    ['value' => 'DE', 'label' => 'deutschlandweit'],
                ],
                'tasks' => [
                    'Technische Untersuchung von Güterwagen und Zügen',
                    'Bremsproben, Wagendokumentation und Schadenerfassung',
                    'Abstimmung mit Disposition, Betrieb und Werkstätten',
                ],
                'profile' => [
                    'Abgeschlossene Ausbildung oder Qualifikation als Wagenmeister',
                    'Verantwortungsbewusstsein und zuverlässige Arbeitsweise',
                    'Bereitschaft zu wechselnden Einsatzzeiten und Einsatzorten',
                    'Teamgeist und klare Kommunikation',
                ],
                'benefits' => [
                    'Unbefristete Perspektive',
                    'Planbare und abwechslungsreiche Einsätze',
                    'Wertschätzendes Team mit direkter Kommunikation',
                    'Zeitgemäße Arbeitsmittel und fachliche Weiterentwicklung',
                ],
                'cta_label' => 'Jetzt bewerben',
                'cta_url' => 'https://www.rail-time.de/de/karriere',
            ] + $common + [
                'editorial_note' => 'Aufgaben, Anforderungen und Vorteile sind ein realistischer HR-Entwurf und vor Veröffentlichung zu bestätigen.',
            ];
        }

        return [
            'template_key' => $templateKey,
            'kicker' => 'Sicher auf der Schiene',
            'title' => 'Wagenmeister-Service',
            'subtitle' => 'Verlässlich. Erfahren. Deutschlandweit.',
            'intro' => 'RailTime unterstützt Eisenbahnverkehrsunternehmen mit qualifizierten Wagenmeistern, flexibler Einsatzplanung und einem rund um die Uhr erreichbaren Notfalldienst.',
            'facts' => [
                ['value' => '60+', 'label' => 'Wagenmeister'],
                ['value' => '24/7', 'label' => 'Einsatzbereitschaft'],
                ['value' => 'DE', 'label' => 'deutschlandweit'],
            ],
            'tasks' => [
                'Technische Wagenuntersuchungen und Bremsproben',
                'Dokumentation, Schadenerfassung und betriebliche Abstimmung',
                'Kurzfristige Unterstützung und planbare Dauereinsätze',
            ],
            'profile' => [
                'Qualifiziertes und erfahrenes Fachpersonal',
                'Flexible Koordination passend zu Ihrem Betrieb',
                'Klare Kommunikation und verlässliche Dokumentation',
            ],
            'benefits' => [
                'Deutschlandweite Einsatzmöglichkeiten',
                'Persönliche Disposition',
                '24/7-Notfalldienst',
            ],
            'cta_label' => 'Einsatz anfragen',
            'cta_url' => 'https://www.rail-time.de/de/kontakt',
        ] + $common + ['editorial_note' => ''];
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

    private function ensureNetworkStarter(MarketingStudioService $studio, ?int $preferredActorId): void
    {
        DB::transaction(function () use ($studio, $preferredActorId): void {
            $exists = MarketingCreative::withTrashed()
                ->where('shared_content->template_key', MarketingTemplateFactory::INFO_GERMANY_NETWORK)
                ->lockForUpdate()
                ->exists();
            if ($exists) {
                return;
            }

            $actor = $preferredActorId === null ? null : User::query()->find($preferredActorId);
            if (! $actor instanceof User || ! $actor->isAdmin()) {
                $actor = User::query()->where('role', 'admin')->orderBy('id')->first();
            }
            if (! $actor instanceof User) {
                return;
            }

            $studio->createFromTemplate(
                MarketingCreativeType::Info,
                $actor,
                MarketingTemplateFactory::INFO_GERMANY_NETWORK,
            );
        });
    }
};
