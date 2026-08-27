<?php

namespace App\Livewire\Admin;

use App\Enums\MailDocumentKind;
use App\Models\MailDocument;
use App\Support\EmailTemplateBuilder;
use App\Support\Mail\EmailCompatibilityAuditor;
use App\Support\Mail\EmailCompatibilityCatalog;
use App\Support\Mail\EmailCompatibilityCatalogException;
use App\Support\Mail\EmailCompatibilityReport;
use App\Support\Mail\MailDocumentAutoRepair;
use App\Support\Mail\PortableMediaCatalog;
use App\Support\Mail\SignatureArtifactVersion;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Livewire\Component;
use Throwable;

/**
 * Editorseite der beiden Maildokumentarten und ihrer Design-Slots.
 *
 * `?dokument=` waehlt die Art, `?slot=` einen konkreten Arbeitsentwurf. Ohne
 * Slot wird die aktive Freigabe oder der erste vorhandene Entwurf geoeffnet.
 *
 * Die Rollenpruefung steht in mount() UND render(): eine Livewire-Komponente
 * lebt ueber viele Anfragen, und die Rolle kann sich zwischendurch aendern.
 */
class MailDocumentEditor extends Component
{
    public string $kind = MailDocumentKind::Template->value;

    public ?string $slotId = null;

    public function mount(): void
    {
        abort_unless(auth()->user()?->isAdmin(), 403);

        $requested = (string) request()->query('dokument', MailDocumentKind::Template->value);
        $this->kind = MailDocumentKind::tryFrom($requested)?->value ?? MailDocumentKind::Template->value;
        $requestedSlot = trim((string) request()->query('slot', ''));
        $this->slotId = $requestedSlot !== '' ? $requestedSlot : null;
    }

    public function render()
    {
        abort_unless(auth()->user()?->isAdmin(), 403);

        [$documents, $documentSlots] = $this->documentState();
        $current = $documents[$this->kind] ?? null;
        $active = collect($documentSlots)->first(
            static fn (MailDocument $document): bool => $document->isActive(),
        );

        return view('livewire.admin.mail-document-editor', [
            'currentKind' => $this->kind,
            'documents' => $documents,
            'documentSlots' => $documentSlots,
            'activeDocument' => $active,
            'currentDocument' => $current,
            'editorConfig' => $current === null ? null : $this->editorConfig($documents),
            'editorPreviewSources' => $current === null ? [] : [
                'light' => [
                    'label' => 'Hell',
                    'url' => route('admin.mail-documents.preview', [$current, 'theme' => 'light', 'animate' => 1]),
                    'width' => 1920,
                    'height' => $current->kind === MailDocumentKind::Signature ? 360 : 820,
                ],
                'dark' => [
                    'label' => 'Dunkel',
                    'url' => route('admin.mail-documents.preview', [$current, 'theme' => 'dark', 'animate' => 1]),
                    'width' => 1920,
                    'height' => $current->kind === MailDocumentKind::Signature ? 360 : 820,
                ],
            ],
        ])->layout('layouts.master', ['area' => 'admin']);
    }

    /**
     * Die vorhandenen Dokumente, nach Art abgelegt.
     *
     * Fehlt die Tabelle (Migration noch nicht eingespielt), ist das kein
     * Fehlerfall: die Seite erklaert dann, was noch fehlt, statt mit einer
     * Ausnahme abzubrechen.
     *
     * @return array<string, MailDocument>
     */
    private function documentState(): array
    {
        try {
            if (! Schema::hasTable('mail_documents')) {
                return [[], []];
            }

            $all = MailDocument::query()
                ->with('updater:id,name')
                ->when(
                    Schema::hasTable('mail_document_versions'),
                    static fn ($query) => $query->withCount('versions'),
                )
                ->orderBy('id')
                ->get();
            $documents = [];
            $currentSlots = [];

            foreach (MailDocumentKind::cases() as $kind) {
                $slots = $all
                    ->filter(static fn (MailDocument $document): bool => $document->kind === $kind)
                    ->values();
                if ($kind->value === $this->kind) {
                    $currentSlots = $slots->all();
                }

                $selected = null;
                if ($kind->value === $this->kind && $this->slotId !== null) {
                    $selected = $slots->first(
                        fn (MailDocument $document): bool => hash_equals(
                            (string) $document->public_id,
                            (string) $this->slotId,
                        ),
                    );
                }
                $selected ??= $slots->first(
                    static fn (MailDocument $document): bool => $document->isActive(),
                );
                $selected ??= $slots->first();
                if ($selected instanceof MailDocument) {
                    $documents[$kind->value] = $selected;
                }
            }

            return [$documents, $currentSlots];
        } catch (Throwable) {
            return [[], []];
        }
    }

    /**
     * @param  array<string, MailDocument>  $documents
     * @return array<string, mixed>
     */
    private function editorConfig(array $documents): array
    {
        $payload = [];
        $catalog = app(EmailCompatibilityCatalog::class);
        try {
            $compatibilityManifest = $catalog->builderManifestForJs();
        } catch (EmailCompatibilityCatalogException $exception) {
            $compatibilityManifest = [
                'schema_version' => 'unavailable',
                'catalog_version' => 'unavailable',
                'rules' => [],
                'error' => $exception->getMessage(),
            ];
        }
        $compatibilityAuditor = new EmailCompatibilityAuditor($catalog);
        $contactIconUrls = EmailTemplateBuilder::contactIconUrls();
        $signatureDocument = $documents[MailDocumentKind::Signature->value] ?? null;
        $signatureArtifactVersion = $signatureDocument instanceof MailDocument
            ? SignatureArtifactVersion::detect(MailDocumentKind::Signature, (string) $signatureDocument->html)
            : null;
        $lightLogoAsset = EmailTemplateBuilder::signatureLogoAsset('light', $signatureArtifactVersion);
        $darkLogoAsset = EmailTemplateBuilder::signatureLogoAsset('dark', $signatureArtifactVersion);
        $lightMarkAsset = EmailTemplateBuilder::emailMarkAsset('light', $signatureArtifactVersion);
        $darkMarkAsset = EmailTemplateBuilder::emailMarkAsset('dark', $signatureArtifactVersion);
        $editorImageAsset = static function (string $filename, string $name): array {
            $path = public_path('mail-assets/'.$filename);
            $dimensions = is_file($path) ? getimagesize($path) : false;

            return [
                'src' => EmailTemplateBuilder::mailAssetUrl($filename),
                'name' => $name,
                'type' => 'image',
                'mime_type' => 'image/gif',
                'animated' => true,
                'width' => is_array($dimensions) ? (int) $dimensions[0] : 1,
                'height' => is_array($dimensions) ? (int) $dimensions[1] : 1,
                'category' => 'RailTime Marke',
            ];
        };
        $mailAssets = [
            $editorImageAsset($lightLogoAsset, 'RailTime Wortmarke hell'),
            $editorImageAsset($darkLogoAsset, 'RailTime Wortmarke dunkel'),
            $editorImageAsset($lightMarkAsset, 'RT-Zeichen hell'),
            $editorImageAsset($darkMarkAsset, 'RT-Zeichen dunkel'),
        ];
        foreach ([
            'LOCATION' => 'Standort-Icon',
            'PHONE' => 'Telefon-Icon',
            'MOBILE' => 'Mobil-Icon',
            'EMAIL' => 'E-Mail-Icon',
            'WEB' => 'Web-Icon',
        ] as $icon => $name) {
            $source = (string) ($contactIconUrls['ICON_'.$icon.'_SRC'] ?? '');
            if ($source !== '') {
                $mailAssets[] = [
                    'src' => $source,
                    'name' => $name,
                    'type' => 'image',
                    'mime_type' => 'image/png',
                    'animated' => false,
                    'width' => 44,
                    'height' => 44,
                    'category' => 'Kontakt',
                ];
            }
        }

        foreach ($documents as $key => $document) {
            $source = MailDocumentAutoRepair::editorSource(
                $document->kind,
                $document->builder_data ?: [],
                (string) $document->html,
                $document->kind->label(),
            );
            $payload[$key] = [
                'id' => $document->public_id,
                'name' => (string) ($document->name ?: $document->kind->label()),
                'isActive' => $document->isActive(),
                'label' => $document->kind->label(),
                'builderData' => $source['builderData'],
                // Der Template-Serializer editiert nur den <body> und baut
                // die geschuetzte Dokumenthuelle beim Speichern aus dieser
                // serverautoritativen Fassung wieder auf. Ohne `html` waere
                // die Baseline beim ersten Save leer; der Browser erzeugte
                // daraus zwar <html>/<head>/<body>, aber ohne den kanonischen
                // Style- und Markenvertrag.
                'html' => $source['html'],
                'css' => (string) $document->css,
                'compatibility' => $this->editorCompatibility(
                    $compatibilityAuditor,
                    $document,
                    $source['html'],
                    (string) $document->css,
                )->toArray(),
                // Nur eine transiente Projektion: Ein GET veraendert weder
                // Entwurf noch Version. Der naechste ausdrueckliche Save
                // uebernimmt den reparierten RailTime-Vertrag.
                'autoRepaired' => $source['repaired'],
                'contentHash' => (string) $document->content_hash,
                'version' => (int) $document->version,
                'status' => $document->status->value,
                'publishedLabel' => $document->published_at?->translatedFormat('d.m.Y H:i'),
                'hasUnpublishedChanges' => $document->hasUnpublishedChanges(),
                'endpoints' => [
                    'update' => route('admin.mail-documents.update', $document),
                    'validate' => route('admin.mail-documents.validate-code', $document),
                    'deliveryPreview' => route('admin.mail-documents.delivery-preview', $document),
                    'publish' => route('admin.mail-documents.publish', $document),
                    'testMail' => route('admin.mail-documents.test-mail', $document),
                ],
                'versions' => Schema::hasTable('mail_document_versions')
                    ? $document->versions()->with('creator:id,name')->limit(40)->get()->map(
                        static fn ($version): array => [
                            'id' => $version->public_id,
                            'revision' => (int) $version->revision,
                            'action_label' => match ($version->action) {
                                'imported' => 'Importiert',
                                'published' => 'Veröffentlicht',
                                'restored' => 'Wiederhergestellt',
                                'duplicated' => 'Dupliziert',
                                default => 'Gespeichert',
                            },
                            'created_label' => $version->created_at?->translatedFormat('d.m.Y H:i'),
                            'creator' => $version->creator?->name,
                            'was_published' => (bool) $version->was_published,
                            'restore_url' => route('admin.mail-documents.versions.restore', [$document, $version]),
                            'delete_url' => route('admin.mail-documents.versions.destroy', [$document, $version]),
                        ],
                    )->all()
                    : [],
            ];
        }

        return [
            'currentDocument' => $this->kind,
            'currentSlot' => $documents[$this->kind]?->public_id,
            'documents' => $payload,
            'designSlotsEnabled' => Schema::hasColumn('mail_documents', 'is_active')
                && Schema::hasColumn('mail_documents', 'name'),
            'compatibilityManifest' => $compatibilityManifest,
            // Nur diese oeffentlichen, stabilen Mail-URLs duerfen normale
            // Inhaltsbilder ersetzen. Private Admin-Dateien, Uploads und
            // freie Fremd-URLs bleiben im E-Mail-Editor ausgeschlossen.
            'mailAssets' => $mailAssets,
            // Das portable Editor-Bundle transportiert die tatsaechlichen
            // Bildbytes mit. Die oeffentliche Quelladresse ist nur die
            // Zuordnung; beim Import bindet der Browser ueber SHA-256 an das
            // Asset der aktuellen Installation zurueck. Dadurch gelangen
            // weder private FilePool-URLs noch Vorschau-Data-URIs in einen
            // gespeicherten Mailentwurf.
            'portableMedia' => $this->portableMediaAssets($documents),
            // Import und Direkt-Reexport waehlen den Pflichtbestand anhand
            // des Kandidaten-HTMLs. Die bisherige required-Markierung der
            // geoeffneten v7 durfte ein korrektes v8-Bundle nicht blockieren.
            'portableMediaRequirements' => [
                MailDocumentKind::Template->value => PortableMediaCatalog::requiredSystemAssetContracts(
                    MailDocumentKind::Template,
                ),
                MailDocumentKind::Signature->value => PortableMediaCatalog::requiredSystemAssetContracts(
                    MailDocumentKind::Signature,
                ),
            ],
            // Nur fuer das isolierte Editor-iframe: Die gespeicherten
            // {{...}}-Tokens bleiben unangetastet, waehrend Logo, Zug und
            // Kontakticons in Hell und Dunkel trotzdem real dargestellt
            // werden. Oeffentliche, gleich-originige Mailasset-URLs halten
            // den Livewire-DOM klein; Base64-GIFs wuerden die Konfiguration
            // schon vor dem GrapesJS-Start um mehrere MiB vergroessern.
            'previewAssets' => [
                'light' => [
                    'logo' => EmailTemplateBuilder::mailAssetUrl(
                        EmailTemplateBuilder::signatureLogoAsset('light', $signatureArtifactVersion),
                    ),
                    'mark' => EmailTemplateBuilder::mailAssetUrl(
                        EmailTemplateBuilder::emailMarkAsset('light', $signatureArtifactVersion),
                    ),
                    'train' => EmailTemplateBuilder::signatureTrainUrl(
                        'light',
                        animated: true,
                        artifactVersion: $signatureArtifactVersion,
                    ),
                ],
                'dark' => [
                    'logo' => EmailTemplateBuilder::mailAssetUrl(
                        EmailTemplateBuilder::signatureLogoAsset('dark', $signatureArtifactVersion),
                    ),
                    'mark' => EmailTemplateBuilder::mailAssetUrl(
                        EmailTemplateBuilder::emailMarkAsset('dark', $signatureArtifactVersion),
                    ),
                    'train' => EmailTemplateBuilder::signatureTrainUrl(
                        'dark',
                        animated: true,
                        artifactVersion: $signatureArtifactVersion,
                    ),
                ],
                'icons' => [
                    'location' => $contactIconUrls['ICON_LOCATION_SRC'] ?? '',
                    'phone' => $contactIconUrls['ICON_PHONE_SRC'] ?? '',
                    'mobile' => $contactIconUrls['ICON_MOBILE_SRC'] ?? '',
                    'email' => $contactIconUrls['ICON_EMAIL_SRC'] ?? '',
                    'web' => $contactIconUrls['ICON_WEB_SRC'] ?? '',
                ],
            ],
            // Ausschliesslich fuer das GrapesJS-iframe: dieselben zentralen
            // Media Queries wie im spaeteren Versand. Sie werden nicht in
            // builder_data, HTML oder die editierbare CSS-Spalte geschrieben.
            // Dadurch ist Tablet/Mobil eine echte Layoutvorschau statt nur
            // einer optisch verkleinerten Desktop-Leinwand.
            'previewResponsiveCss' => [
                'light' => EmailTemplateBuilder::responsiveCss('#dfe3e6'),
                'dark' => EmailTemplateBuilder::responsiveCss('#313944'),
            ],
            'vendor' => [
                'builderJs' => asset('vendor/lmz-builder/2.4.5/lmz-builder.js'),
                'builderCss' => asset('vendor/lmz-builder/2.4.5/lmz-builder.css'),
                'coreJs' => asset('vendor/lmz-builder/2.4.5/lmz-builder-core.js'),
                'coreCss' => asset('vendor/lmz-builder/2.4.5/lmz-builder-core.css'),
            ],
        ];
    }

    private function editorCompatibility(
        EmailCompatibilityAuditor $auditor,
        MailDocument $document,
        string $html,
        string $css,
    ): EmailCompatibilityReport {
        try {
            return $auditor->audit($html, $css, [
                'document_kind' => $document->kind->value,
                'trusted_system_css' => true,
                'allow_template_tokens' => true,
            ]);
        } catch (EmailCompatibilityCatalogException $exception) {
            return EmailCompatibilityReport::unavailable(
                $exception,
                strlen($html),
                strlen($css),
            );
        }
    }

    /**
     * Medien, die zum aktuell geoeffneten Dokumentvertrag gehoeren.
     *
     * @param  array<string, MailDocument>  $documents
     * @return list<array<string, bool|int|string>>
     */
    private function portableMediaAssets(array $documents): array
    {
        $activeDocument = $documents[$this->kind] ?? null;
        $artifactVersion = $activeDocument instanceof MailDocument
            ? SignatureArtifactVersion::detect($this->kind, (string) $activeDocument->html)
            : null;
        $includedSystemAssets = array_fill_keys(
            PortableMediaCatalog::requiredSystemAssetIds($this->kind, $artifactVersion),
            true,
        );
        $assets = array_map(
            static fn (string $path): string => basename($path),
            glob(public_path('mail-assets/*.{gif,png,jpg,jpeg,webp}'), GLOB_BRACE) ?: [],
        );
        sort($assets, SORT_NATURAL | SORT_FLAG_CASE);

        $portable = array_values(array_filter(array_map(static function (string $asset) use ($includedSystemAssets): ?array {
            $path = public_path('mail-assets/'.$asset);
            if (! is_file($path)) {
                return null;
            }

            $extension = strtolower((string) pathinfo($asset, PATHINFO_EXTENSION));
            $mime = match ($extension) {
                'gif' => 'image/gif',
                'jpg', 'jpeg' => 'image/jpeg',
                'webp' => 'image/webp',
                default => 'image/png',
            };
            $dimensions = @getimagesize($path);

            return [
                'id' => $asset,
                'name' => str_replace(['-', '_'], ' ', (string) pathinfo($asset, PATHINFO_FILENAME)),
                'source' => EmailTemplateBuilder::mailAssetUrl($asset),
                'mime_type' => $mime,
                'bytes' => (int) filesize($path),
                'sha256' => hash_file('sha256', $path),
                'width' => (int) ($dimensions[0] ?? 0),
                'height' => (int) ($dimensions[1] ?? 0),
                // Alle vorhandenen System-IDs bleiben fuer alte Bundles
                // bekannt. Exportiert und fuer neue Bundles verlangt werden
                // aber nur die Medien des aktuell geoeffneten Dokuments.
                'required' => isset($includedSystemAssets[$asset]),
                'included' => isset($includedSystemAssets[$asset]),
            ];
        }, $assets)));

        // Bereits importierte Inhaltsbilder liegen absichtlich nicht im
        // Markenbestand. Sie muessen bei jedem spaeteren Re-Export erneut
        // mitkommen, sonst waere ein einmal transportierter Entwurf nicht
        // mehr vollstaendig portabel. Eingesammelt werden nur Dateien, die
        // in einem der beiden aktuellen Dokumente tatsaechlich vorkommen.
        $current = $documents[$this->kind] ?? null;
        $source = $current instanceof MailDocument
            ? html_entity_decode(
                (string) $current->html."\n".(string) $current->css,
                ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5,
                'UTF-8',
            )
            : '';
        preg_match_all(
            '~/storage/mail-imports/([a-f0-9]{64}\.(?:gif|png|jpe?g|webp))(?:\?[^\s"\'()<>]*)?~i',
            $source,
            $matches,
        );

        $disk = Storage::disk('public');
        foreach (array_values(array_unique(array_map('strtolower', $matches[1] ?? []))) as $filename) {
            $storagePath = 'mail-imports/'.$filename;
            if (! $disk->exists($storagePath)) {
                continue;
            }

            try {
                $path = $disk->path($storagePath);
            } catch (Throwable) {
                continue;
            }
            if (! is_file($path)) {
                continue;
            }

            $extension = strtolower((string) pathinfo($filename, PATHINFO_EXTENSION));
            $mime = match ($extension) {
                'gif' => 'image/gif',
                'jpg', 'jpeg' => 'image/jpeg',
                'webp' => 'image/webp',
                default => 'image/png',
            };
            $dimensions = @getimagesize($path);
            $portable[] = [
                'id' => $storagePath,
                'name' => 'Importiertes Mailmedium '.substr($filename, 0, 12),
                'source' => URL::to($disk->url($storagePath)),
                'mime_type' => $mime,
                'bytes' => (int) filesize($path),
                'sha256' => hash_file('sha256', $path),
                'width' => (int) ($dimensions[0] ?? 0),
                'height' => (int) ($dimensions[1] ?? 0),
                'required' => true,
                'included' => true,
            ];
        }

        usort($portable, static fn (array $left, array $right): int => strnatcasecmp(
            (string) $left['id'],
            (string) $right['id'],
        ));

        return $portable;
    }
}
