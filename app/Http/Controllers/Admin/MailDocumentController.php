<?php

namespace App\Http\Controllers\Admin;

use App\Enums\MailDocumentKind;
use App\Enums\MailDocumentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Mail\ImportMailDocumentRequest;
use App\Http\Requests\Mail\ReplaceMailDocumentDraftRequest;
use App\Http\Requests\Mail\SaveMailDocumentRequest;
use App\Models\MailDocument;
use App\Models\MailDocumentVersion;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\MailDocumentTestNotification;
use App\Support\Mail\CssSemantic;
use App\Support\Mail\EmailCompatibilityAuditor;
use App\Support\Mail\EmailCompatibilityCatalogException;
use App\Support\Mail\EmailCompatibilityReport;
use App\Support\Mail\EmailHtmlReport;
use App\Support\Mail\EmailHtmlSanitizer;
use App\Support\Mail\MailDocumentAutoRepair;
use App\Support\Mail\MailDocumentVersionStore;
use App\Support\Mail\PortableMediaCatalog;
use App\Support\Mail\PublishedMailDocumentSnapshotStore;
use App\Support\Mail\SignatureArtifactVersion;
use App\Support\Mail\SignatureDocumentContract;
use App\Support\Mail\TemplateDocumentContract;
use App\Support\OutlookAddin\OutlookAddinSnapshotRefreshScheduler;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Mail\Markdown;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;

/**
 * Speichern und Veroeffentlichen der beiden Maildokumente.
 *
 * Zwei Dinge sind hier nicht verhandelbar:
 *
 *  1. Die Haertung (EmailHtmlSanitizer) laeuft VOR dem Fingerabdruck.
 *     Gespeichert wird genau das, was spaeter ausgeliefert wird — sonst
 *     passt der Hash nicht zum Inhalt und der naechste Speichervorgang
 *     scheitert ohne erkennbaren Grund.
 *  2. Eindeutig erkannte RailTime-Altvertraege werden vor jedem Pfad auf den
 *     aktuellen Vertrag gehoben. Alles Mehrdeutige geht an den Bearbeiter
 *     zurueck: beim Speichern als Bericht, beim Veroeffentlichen als Fehler.
 */
final class MailDocumentController extends Controller
{
    /**
     * Leichte Wiederherstellungsseite fuer Entwurfsimporte.
     *
     * Diese Route instanziiert weder die Livewire-Editorkomponente noch deren
     * Medienkatalog oder GrapesJS-Konfiguration. Sie bleibt damit auch dann
     * benutzbar, wenn ein grosser Builder-Entwurf den Browser blockiert.
     */
    public function draftImportPage(Request $request): View
    {
        $this->mailAdmin($request);

        $documents = collect();
        if (Schema::hasTable('mail_documents')) {
            $columns = [
                'id',
                'public_id',
                'kind',
                'name',
                'status',
                'content_hash',
                'version',
                'updated_at',
            ];
            $hasActiveColumn = Schema::hasColumn('mail_documents', 'is_active');
            if ($hasActiveColumn) {
                $columns[] = 'is_active';
            }

            $documents = MailDocument::query()
                ->select($columns)
                ->orderBy('kind')
                ->when($hasActiveColumn, static fn ($query) => $query->orderByDesc('is_active'))
                ->orderBy('name')
                ->get();
        }

        return view('mail-documents.import', [
            'documents' => $documents,
            'importConfig' => [
                'maxBytes' => 16 * 1024 * 1024,
                'documents' => $documents->map(static fn (MailDocument $document): array => [
                    'id' => $document->public_id,
                    'kind' => $document->kind->value,
                    'kindLabel' => $document->kind->label(),
                    'name' => (string) ($document->name ?: $document->kind->label()),
                    'status' => $document->status->label(),
                    'active' => $document->isActive(),
                    'version' => (int) $document->version,
                    'contentHash' => (string) $document->content_hash,
                    'endpoint' => route('admin.mail-documents.draft-import', $document),
                    'editorUrl' => route('admin.mail-documents.editor', [
                        'dokument' => $document->kind->value,
                        'slot' => $document->public_id,
                        'open' => 1,
                    ]),
                ])->values()->all(),
            ],
        ]);
    }

    /**
     * Legt ein noch fehlendes Maildokument ausschliesslich aus einem zuvor
     * exportierten v2-Bundle an. Dieser explizite Erstimport ersetzt die
     * fruehere Initialisierung und ueberschreibt niemals vorhandene Inhalte.
     */
    public function import(
        ImportMailDocumentRequest $request,
        EmailHtmlSanitizer $sanitizer,
        EmailCompatibilityAuditor $compatibilityAuditor,
        MailDocumentVersionStore $versions,
    ): JsonResponse {
        $actor = $this->mailAdmin($request);
        $validated = $request->validated();
        $kind = MailDocumentKind::from((string) $validated['kind']);
        $prototype = new MailDocument(['kind' => $kind->value]);
        [$htmlReport, $cssReport, $portableFiles, $compatibility, $builderData, $contentHash] = $this->preparePortableDraft(
            $prototype,
            (string) $validated['html'],
            (string) $validated['css'],
            (array) $validated['media'],
            $sanitizer,
            $compatibilityAuditor,
            'media',
        );

        $document = DB::transaction(function () use (
            $kind,
            $actor,
            $portableFiles,
            $builderData,
            $htmlReport,
            $cssReport,
            $contentHash,
            $versions,
        ): MailDocument {
            if (MailDocument::query()->where('kind', $kind->value)->lockForUpdate()->exists()) {
                throw ValidationException::withMessages([
                    'kind' => 'Dieses Maildokument ist bereits eingerichtet. Bitte lade die Importseite neu und wähle den vorhandenen Zielentwurf.',
                ]);
            }

            $this->storePortableMedia($portableFiles);

            $document = MailDocument::query()->create([
                'kind' => $kind,
                'name' => $kind === MailDocumentKind::Signature ? 'Standardsignatur' : 'Standardvorlage',
                'status' => MailDocumentStatus::Draft,
                'is_active' => null,
                'builder_data' => $builderData,
                'html' => $htmlReport->html,
                'css' => $cssReport->html,
                'published_html' => null,
                'published_css' => null,
                'published_at' => null,
                'content_hash' => $contentHash,
                'version' => 1,
                'created_by' => $actor->getKey(),
                'updated_by' => $actor->getKey(),
            ]);
            $versions->capture($document, $actor, 'imported');

            return $document;
        });

        return response()->json([
            'document' => $this->payload($document),
            'redirect' => route('admin.mail-documents.editor', [
                'dokument' => $kind->value,
                'slot' => $document->public_id,
                'open' => 1,
            ]),
            'report' => $this->reportPayload($htmlReport, $cssReport),
            'compatibility' => $compatibility->toArray(),
        ], 201);
    }

    /**
     * Ersetzt ausschliesslich den Arbeitsstand eines vorhandenen Design-Slots.
     * Der aktive/veroeffentlichte Snapshot bleibt dabei unveraendert in Kraft.
     */
    public function importDraft(
        ReplaceMailDocumentDraftRequest $request,
        MailDocument $document,
        EmailHtmlSanitizer $sanitizer,
        EmailCompatibilityAuditor $compatibilityAuditor,
        MailDocumentVersionStore $versions,
    ): JsonResponse {
        $actor = $this->mailAdmin($request);
        $validated = $request->validated();
        $bundleKind = MailDocumentKind::from((string) $validated['kind']);

        if ($document->kind !== $bundleKind) {
            throw ValidationException::withMessages([
                'kind' => 'Das Bundle passt nicht zur gewählten Dokumentart.',
            ]);
        }

        [$htmlReport, $cssReport, $portableFiles, $compatibility, $builderData, $contentHash] = $this->preparePortableDraft(
            $document,
            (string) $validated['html'],
            (string) $validated['css'],
            (array) $validated['media'],
            $sanitizer,
            $compatibilityAuditor,
            'media',
        );

        [$saved, $changed] = DB::transaction(function () use (
            $document,
            $validated,
            $bundleKind,
            $portableFiles,
            $builderData,
            $htmlReport,
            $cssReport,
            $contentHash,
            $actor,
            $versions,
        ): array {
            $locked = $this->lock($document);

            if (! $locked->matchesContentHash((string) $validated['expected_hash'])) {
                throw ValidationException::withMessages([
                    'expected_hash' => 'Der Entwurf wurde zwischenzeitlich geändert. Bitte lade die Importseite neu, damit keine Änderungen überschrieben werden.',
                ]);
            }

            if ($locked->kind !== $bundleKind) {
                throw ValidationException::withMessages([
                    'kind' => 'Das Bundle passt nicht zur gewählten Dokumentart.',
                ]);
            }

            $this->storePortableMedia($portableFiles);
            $changed = ! hash_equals((string) $locked->content_hash, $contentHash);

            if ($changed) {
                $locked->forceFill([
                    'builder_data' => $builderData,
                    'html' => $htmlReport->html,
                    'css' => $cssReport->html,
                    'content_hash' => $contentHash,
                    'version' => $locked->version + 1,
                    'updated_by' => $actor->getKey(),
                ])->save();
                $versions->capture($locked, $actor, 'imported');
            }

            return [$locked, $changed];
        });

        return response()->json([
            'message' => $changed
                ? 'Der Entwurf wurde importiert. Die veröffentlichte Systemmail bleibt unverändert.'
                : 'Das Bundle entspricht bereits exakt dem gewählten Entwurf.',
            'changed' => $changed,
            'document' => $this->payload($saved),
            'report' => $this->reportPayload($htmlReport, $cssReport),
            'compatibility' => $compatibility->toArray(),
        ]);
    }

    /**
     * Prueft importierten HTML-/CSS-Code mit exakt denselben Vertraegen wie
     * ein Save, schreibt aber kein einziges Feld. Erst diese autoritative
     * Antwort darf der Editor in seine Leinwand laden.
     */
    public function validateCode(
        SaveMailDocumentRequest $request,
        MailDocument $document,
        EmailHtmlSanitizer $sanitizer,
        EmailCompatibilityAuditor $compatibilityAuditor,
    ): JsonResponse {
        $this->mailAdmin($request);
        $validated = $request->validated();

        if (! $document->matchesContentHash((string) $validated['expected_hash'])) {
            throw ValidationException::withMessages([
                'expected_hash' => 'Das Dokument wurde zwischenzeitlich geändert. Bitte lade die Seite vor dem Codeimport neu.',
            ]);
        }

        $portableMedia = (array) ($validated['portable_media'] ?? []);
        if ($portableMedia !== []) {
            $this->assertPortableMediaComplete(
                $document->kind,
                (string) $validated['html'],
                (string) $validated['css'],
                $portableMedia,
            );
        }
        [$html, $css, $portableFiles] = $this->preparePortableMedia(
            (string) $validated['html'],
            (string) $validated['css'],
            $portableMedia,
        );
        $html = MailDocumentAutoRepair::repairHtml($document->kind, $html);
        $this->assertEditableCssSource($css);
        $this->assertDocumentStructure($document, $html, $css);
        $htmlReport = $this->assertCleanHtml($sanitizer, $html);
        $cssReport = $this->cleanStyleSheet($sanitizer, $css);
        if ($cssReport->hasViolations()) {
            throw ValidationException::withMessages([
                'css' => array_merge(
                    ['Die Stilregeln enthalten Syntax, die in E-Mails nicht erlaubt ist.'],
                    $cssReport->violationMessages(),
                ),
            ]);
        }
        $this->assertDocumentStructure($document, $htmlReport->html, $cssReport->html);
        $compatibility = $this->auditCompatibility(
            $compatibilityAuditor,
            $document->kind,
            $htmlReport->html,
            $cssReport->html,
        );
        $builderData = $this->syncBuilderData(
            $document,
            $validated['builder_data'],
            $htmlReport->html,
        );
        $this->storePortableMedia($portableFiles);

        $candidate = $this->payload($document);
        $candidate['builder_data'] = $builderData;
        $candidate['html'] = $htmlReport->html;
        $candidate['css'] = $cssReport->html;

        return response()->json([
            'document' => $candidate,
            'report' => $this->reportPayload($htmlReport, $cssReport),
            'compatibility' => $compatibility->toArray(),
        ]);
    }

    /**
     * Kompiliert den aktuellen, noch nicht gespeicherten Editorstand mit dem
     * produktiven Systemmail-Compiler. Die Snapshots leben ausschliesslich in
     * diesem Request; Entwurf, Freigabe und Versionshistorie bleiben unberuehrt.
     */
    public function deliveryPreview(
        SaveMailDocumentRequest $request,
        MailDocument $document,
        EmailHtmlSanitizer $sanitizer,
        EmailCompatibilityAuditor $compatibilityAuditor,
        PublishedMailDocumentSnapshotStore $snapshots,
    ): JsonResponse {
        $this->mailAdmin($request);
        $validated = $request->validated();

        if (! $document->matchesContentHash((string) $validated['expected_hash'])) {
            throw ValidationException::withMessages([
                'expected_hash' => 'Das Dokument wurde zwischenzeitlich geändert. Bitte lade die Seite vor der Versandvorschau neu.',
            ]);
        }

        // Anders als der ausdrueckliche Import darf eine Vorschau niemals
        // Dateien ablegen. Portable Medien muessen zuerst geprueft importiert
        // werden und stehen danach als normale HTTPS-Mailassets zur Verfuegung.
        if ((array) ($validated['portable_media'] ?? []) !== []) {
            throw ValidationException::withMessages([
                'portable_media' => 'Die Versandvorschau nimmt keine Mediendateien entgegen und hat nichts gespeichert.',
            ]);
        }

        $html = MailDocumentAutoRepair::repairHtml(
            $document->kind,
            (string) $validated['html'],
        );
        $css = (string) $validated['css'];
        $this->assertEditableCssSource($css);
        $this->assertDocumentStructure($document, $html, $css);

        $htmlReport = $this->assertCleanHtml($sanitizer, $html);
        $cssReport = $this->cleanStyleSheet($sanitizer, $css);
        if ($cssReport->hasViolations()) {
            throw ValidationException::withMessages([
                'css' => array_merge(
                    ['Die Stilregeln enthalten Syntax, die in E-Mails nicht erlaubt ist.'],
                    $cssReport->violationMessages(),
                ),
            ]);
        }
        $this->assertDocumentStructure($document, $htmlReport->html, $cssReport->html);

        // Synchronisiert und validiert nur den Projektvertrag. Es folgt kein
        // save(), kein Versionsabzug und kein Medien-Write.
        $builderData = $this->syncBuilderData(
            $document,
            $validated['builder_data'],
            $htmlReport->html,
        );
        $sourceCompatibility = $this->auditCompatibility(
            $compatibilityAuditor,
            $document->kind,
            $htmlReport->html,
            $cssReport->html,
        );
        $finalHtml = $this->compileFinalSystemMailCandidate(
            $snapshots,
            $document->kind,
            $htmlReport->html,
            $cssReport->html,
        );
        if ($finalHtml === null) {
            throw ValidationException::withMessages([
                'preview' => 'Die Versandvorschau benötigt eine gültige Vorlage und Signatur.',
            ]);
        }
        $compatibility = $this->auditCompiledSystemMail($compatibilityAuditor, $finalHtml);

        $candidate = $this->payload($document);
        $candidate['builder_data'] = $builderData;
        $candidate['html'] = $htmlReport->html;
        $candidate['css'] = $cssReport->html;

        return response()->json([
            'preview' => [
                'html' => $finalHtml,
                'html_bytes' => strlen($finalHtml),
                'rendering' => 'compiled-system-mail',
            ],
            'document' => $candidate,
            'report' => $this->reportPayload($htmlReport, $cssReport),
            'source_compatibility' => $sourceCompatibility->toArray(),
            'compatibility' => $compatibility->toArray(),
        ])->withHeaders([
            'Cache-Control' => 'no-store, private',
            'Pragma' => 'no-cache',
        ]);
    }

    /**
     * Der Browser bietet nur eine schnelle Vorpruefung. Der Server bestimmt
     * den Pflichtbestand erneut aus dem Kandidaten-HTML, damit ein direkter
     * Request weder Medien auslassen noch v7/v8 miteinander vermischen kann.
     *
     * @param  list<array<string, mixed>>  $media
     */
    private function assertPortableMediaComplete(
        MailDocumentKind $kind,
        string $html,
        string $css,
        array $media,
        string $field = 'portable_media',
    ): void {
        $ids = array_values(array_filter(array_map(
            static fn ($entry): string => is_array($entry)
                ? PortableMediaCatalog::canonicalAssetId((string) ($entry['id'] ?? ''))
                : '',
            $media,
        ), static fn (string $id): bool => $id !== ''));
        $counts = array_count_values($ids);
        if (array_filter($counts, static fn (int $count): bool => $count !== 1) !== []) {
            throw ValidationException::withMessages([
                $field => 'Jede Medienkennung darf nur einmal vorkommen.',
            ]);
        }

        $missing = array_values(array_diff(
            PortableMediaCatalog::requiredBundleAssetIds(
                $kind,
                $html,
                $css,
            ),
            array_keys($counts),
        ));
        if ($missing !== []) {
            throw ValidationException::withMessages([
                $field => 'Im Bundle fehlen erforderliche Medien: '.implode(', ', $missing).'.',
            ]);
        }
    }

    /**
     * Bereitet ein portables Bundle fuer Erstimport und Entwurfsersatz ueber
     * exakt dieselbe Reparatur-, Haertungs- und Kompatibilitaetsstrecke vor.
     *
     * @param  list<array<string, mixed>>  $media
     * @return array{0: EmailHtmlReport, 1: EmailHtmlReport, 2: list<array{path: string, binary: string}>, 3: EmailCompatibilityReport, 4: array<string, mixed>, 5: string}
     */
    private function preparePortableDraft(
        MailDocument $document,
        string $html,
        string $css,
        array $media,
        EmailHtmlSanitizer $sanitizer,
        EmailCompatibilityAuditor $compatibilityAuditor,
        string $mediaField = 'portable_media',
    ): array {
        $this->assertPortableMediaComplete(
            $document->kind,
            $html,
            $css,
            $media,
            $mediaField,
        );

        [$html, $css, $portableFiles] = $this->preparePortableMedia($html, $css, $media);
        $html = MailDocumentAutoRepair::repairHtml($document->kind, $html);
        $this->assertEditableCssSource($css);
        $this->assertDocumentStructure($document, $html, $css);

        $htmlReport = $this->assertCleanHtml($sanitizer, $html);
        $cssReport = $this->cleanStyleSheet($sanitizer, $css);
        if ($cssReport->hasViolations()) {
            throw ValidationException::withMessages([
                'css' => array_merge(
                    ['Die Stilregeln enthalten Syntax, die in E-Mails nicht erlaubt ist.'],
                    $cssReport->violationMessages(),
                ),
            ]);
        }

        $this->assertDocumentStructure($document, $htmlReport->html, $cssReport->html);
        $compatibility = $this->auditCompatibility(
            $compatibilityAuditor,
            $document->kind,
            $htmlReport->html,
            $cssReport->html,
        );
        $builderData = $this->syncBuilderData($document, [], $htmlReport->html);
        $contentHash = MailDocument::contentHashFor(
            $builderData,
            $htmlReport->html,
            $cssReport->html,
        );

        return [
            $htmlReport,
            $cssReport,
            $portableFiles,
            $compatibility,
            $builderData,
            $contentHash,
        ];
    }

    /**
     * Prueft ein portables Medienpaket vollstaendig im Speicher und ersetzt
     * nur seine exakten alten Quelladressen durch inhaltsadressierte URLs der
     * aktuellen Installation. Geschrieben wird erst, nachdem auch HTML, CSS
     * und Dokumentvertrag akzeptiert wurden.
     *
     * @param  list<array<string, mixed>>  $media
     * @return array{0: string, 1: string, 2: list<array{path: string, binary: string}>}
     */
    private function preparePortableMedia(string $html, string $css, array $media): array
    {
        $files = [];
        $sources = [];
        $importedReferences = PortableMediaCatalog::referencedImportedAssetSources($html, $css);
        $imageSources = PortableMediaCatalog::referencedImageSources($html, $css);
        $extensions = [
            'image/gif' => 'gif',
            'image/png' => 'png',
            'image/jpeg' => 'jpg',
            'image/webp' => 'webp',
        ];

        foreach ($media as $index => $entry) {
            if (! is_array($entry)) {
                throw ValidationException::withMessages([
                    'portable_media' => 'Das Medienpaket enthaelt einen ungueltigen Eintrag.',
                ]);
            }

            $id = trim((string) ($entry['id'] ?? ''));
            $source = trim((string) ($entry['source'] ?? ''));
            $mime = strtolower(trim((string) ($entry['mime_type'] ?? '')));
            $sha256 = strtolower(trim((string) ($entry['sha256'] ?? '')));
            $declaredBytes = (int) ($entry['bytes'] ?? 0);
            $encoded = (string) ($entry['data'] ?? '');
            $scheme = strtolower((string) parse_url($source, PHP_URL_SCHEME));
            $isSystemAsset = basename($id) === $id
                && preg_match('/^[A-Za-z0-9._-]+\.(?:gif|png|jpe?g|webp)$/i', $id) === 1
                && is_file(public_path('mail-assets/'.$id));
            $isImportedAsset = preg_match(
                '~^mail-imports/([a-f0-9]{64})\.(gif|png|jpg|webp)$~i',
                $id,
                $importedId,
            ) === 1;

            if ((! $isSystemAsset && ! $isImportedAsset)
                || $source === ''
                || (! str_starts_with($source, '/') && ! in_array($scheme, ['http', 'https'], true))
                || str_contains($source, '{{')
                || isset($sources[$source])
                || ! isset($extensions[$mime])
                || preg_match('/^[A-Za-z0-9+\/]*={0,2}$/', $encoded) !== 1
                || strlen($encoded) % 4 !== 0) {
                throw ValidationException::withMessages([
                    'portable_media' => 'Das Medienpaket enthaelt doppelte oder nicht portable Quellen.',
                ]);
            }

            $binary = base64_decode($encoded, true);
            if (! is_string($binary)
                || $binary === ''
                || strlen($binary) !== $declaredBytes
                || strlen($binary) > 2 * 1024 * 1024
                || ! hash_equals($sha256, hash('sha256', $binary))) {
                throw ValidationException::withMessages([
                    'portable_media.'.$index => 'Groesse oder SHA-256 des Mediums stimmt nicht.',
                ]);
            }

            if ($isImportedAsset) {
                $expectedExtension = $extensions[$mime];
                if (! hash_equals(strtolower((string) $importedId[1]), $sha256)
                    || strtolower((string) $importedId[2]) !== $expectedExtension) {
                    throw ValidationException::withMessages([
                        'portable_media.'.$index => 'Kennung, Dateityp und SHA-256 des importierten Mediums stimmen nicht ueberein.',
                    ]);
                }
            }

            set_error_handler(static fn (): bool => true);
            try {
                $image = getimagesizefromstring($binary);
            } finally {
                restore_error_handler();
            }
            $actualMime = strtolower((string) ($image['mime'] ?? ''));
            $width = (int) ($image[0] ?? 0);
            $height = (int) ($image[1] ?? 0);
            if (! is_array($image)
                || $actualMime !== $mime
                || $width < 1
                || $height < 1
                || $width > 4096
                || $height > 4096
                || ($width * $height) > 16_000_000) {
                throw ValidationException::withMessages([
                    'portable_media.'.$index => 'Das Medium ist kein erlaubtes GIF-, PNG-, JPEG- oder WebP-Bild.',
                ]);
            }

            $path = 'mail-imports/'.$sha256.'.'.$extensions[$mime];
            $target = URL::to(Storage::disk('public')->url($path));
            // System-Tokens wie {{TRAIN_SRC}} binden weiterhin das
            // versionierte RailTime-Asset der Installation. Deren im Bundle
            // mitgefuehrte Bytes werden vollstaendig validiert, aber nicht als
            // unreferenzierte Dublette in storage/mail-imports abgelegt.
            // Bei Importmedien ist allein die parser-seitig erkannte Referenz
            // fuer exakt diese Hash-ID autoritativ. `entry.source` ist
            // Bundle-Eingabe und darf niemals als kurzer globaler Teilstring
            // (etwa `/storage`) das gesamte Dokument umschreiben.
            $decodedSource = html_entity_decode(
                $source,
                ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5,
                'UTF-8',
            );
            $referencedAliases = $isImportedAsset
                ? ($importedReferences[strtolower($id)] ?? [])
                : (in_array($decodedSource, $imageSources, true) ? [$decodedSource] : []);
            $isReferenced = $referencedAliases !== [];
            $htmlReplacements = [];
            $cssReplacements = [];
            foreach ($referencedAliases as $alias) {
                $htmlReplacements[$alias] = $target;
                $htmlReplacements[htmlspecialchars(
                    $alias,
                    ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5,
                    'UTF-8',
                )] = htmlspecialchars(
                    $target,
                    ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5,
                    'UTF-8',
                );
                $cssReplacements[$alias] = $target;
            }
            uksort(
                $htmlReplacements,
                static fn (string $left, string $right): int => strlen($right) <=> strlen($left),
            );
            uksort(
                $cssReplacements,
                static fn (string $left, string $right): int => strlen($right) <=> strlen($left),
            );
            $html = strtr($html, $htmlReplacements);
            $css = strtr($css, $cssReplacements);
            if ($isReferenced) {
                $files[] = ['path' => $path, 'binary' => $binary];
            }
            $sources[$source] = true;
        }

        return [$html, $css, $files];
    }

    /** @param  list<array{path: string, binary: string}>  $files */
    private function storePortableMedia(array $files): void
    {
        $disk = Storage::disk('public');
        foreach ($files as $file) {
            $path = (string) ($file['path'] ?? '');
            $binary = (string) ($file['binary'] ?? '');
            $sha256 = hash('sha256', $binary);

            if (preg_match(
                '~^mail-imports/([a-f0-9]{64})\.(?:gif|png|jpg|webp)$~',
                $path,
                $match,
            ) !== 1 || ! hash_equals($match[1], $sha256)) {
                throw ValidationException::withMessages([
                    'portable_media' => 'Der inhaltsadressierte Medienpfad stimmt nicht mit seinem SHA-256 ueberein.',
                ]);
            }

            $matches = false;
            if ($disk->exists($path)) {
                try {
                    $existing = $disk->get($path);
                    $matches = hash_equals($sha256, hash('sha256', $existing));
                } catch (\Throwable) {
                    $matches = false;
                }
            }

            if ($matches) {
                continue;
            }

            if (! $disk->put($path, $binary)) {
                throw ValidationException::withMessages([
                    'portable_media' => 'Ein importiertes Medium konnte nicht gespeichert werden.',
                ]);
            }

            try {
                $stored = $disk->get($path);
            } catch (\Throwable) {
                $stored = null;
            }
            if (! is_string($stored) || ! hash_equals($sha256, hash('sha256', $stored))) {
                throw ValidationException::withMessages([
                    'portable_media' => 'Das importierte Medium konnte nicht unveraendert gespeichert werden.',
                ]);
            }
        }
    }

    public function update(
        SaveMailDocumentRequest $request,
        MailDocument $document,
        EmailHtmlSanitizer $sanitizer,
        EmailCompatibilityAuditor $compatibilityAuditor,
        MailDocumentVersionStore $versions,
    ): JsonResponse {
        $actor = $this->mailAdmin($request);
        $validated = $request->validated();

        [$saved, $htmlReport, $cssReport, $compatibility] = DB::transaction(function () use ($document, $validated, $actor, $sanitizer, $compatibilityAuditor, $versions): array {
            $locked = $this->lock($document);

            // Der Hashvergleich ist ohne Sperre wertlos: zwei parallele
            // Autosaves ueberschrieben sich sonst trotz gueltigem Hash.
            if (! $locked->matchesContentHash((string) $validated['expected_hash'])) {
                throw ValidationException::withMessages([
                    'expected_hash' => 'Das Dokument wurde zwischenzeitlich geändert. Bitte lade die Seite neu — sonst gehen fremde Änderungen verloren.',
                ]);
            }

            // Vor der Strukturpruefung werden nur exakt bekannte Altvertraege
            // repariert. Der Sanitizer darf einen zusaetzlichen style-Block
            // oder Zugtoken nicht still entfernen und dadurch aus einem
            // verbotenen Entwurf scheinbar einen gueltigen machen.
            $html = MailDocumentAutoRepair::repairHtml(
                $locked->kind,
                (string) $validated['html'],
            );
            $this->assertEditableCssSource((string) $validated['css']);
            $this->assertDocumentStructure(
                $locked,
                $html,
                (string) $validated['css'],
            );
            $htmlReport = $sanitizer->clean($html);
            $cssReport = $this->cleanStyleSheet($sanitizer, (string) $validated['css']);
            $this->assertDocumentStructure($locked, $htmlReport->html, $cssReport->html);
            $builderData = $this->syncBuilderData(
                $locked,
                $validated['builder_data'],
                $htmlReport->html,
            );
            $compatibility = $this->auditCompatibility(
                $compatibilityAuditor,
                $locked->kind,
                $htmlReport->html,
                $cssReport->html,
            );

            $hash = MailDocument::contentHashFor(
                $builderData,
                $htmlReport->html,
                $cssReport->html,
            );

            // Idempotent: ist nichts anders, wird nicht geschrieben. Sonst
            // zaehlte jedes Autosave die Version hoch.
            if (! hash_equals((string) $locked->content_hash, $hash)) {
                $locked->forceFill([
                    'builder_data' => $builderData,
                    'html' => $htmlReport->html,
                    'css' => $cssReport->html,
                    'content_hash' => $hash,
                    'version' => $locked->version + 1,
                    'updated_by' => $actor->getKey(),
                ])->save();
                $versions->capture($locked, $actor, 'saved');
            }

            return [$locked, $htmlReport, $cssReport, $compatibility];
        });

        return response()->json([
            'document' => $this->payload($saved),
            'report' => $this->reportPayload($htmlReport, $cssReport),
            'compatibility' => $compatibility->toArray(),
        ]);
    }

    public function publish(
        Request $request,
        MailDocument $document,
        EmailHtmlSanitizer $sanitizer,
        EmailCompatibilityAuditor $compatibilityAuditor,
        PublishedMailDocumentSnapshotStore $publishedDocuments,
        MailDocumentVersionStore $versions,
        OutlookAddinSnapshotRefreshScheduler $outlookSnapshots,
    ): JsonResponse {
        $actor = $this->mailAdmin($request);
        $validated = $request->validate([
            'expected_hash' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/i'],
        ]);

        return $this->publishForActor($actor, $document, (string) $validated['expected_hash']);
    }

    /** Shared strict release path for HTTP and the lightweight Livewire library. */
    public function publishForActor(User $actor, MailDocument $document, string $expectedHash): JsonResponse
    {
        abort_unless($actor->isAdmin(), 403);
        $validated = validator(['expected_hash' => $expectedHash], [
            'expected_hash' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/i'],
        ])->validate();
        $sanitizer = app(EmailHtmlSanitizer::class);
        $compatibilityAuditor = app(EmailCompatibilityAuditor::class);
        $publishedDocuments = app(PublishedMailDocumentSnapshotStore::class);
        $versions = app(MailDocumentVersionStore::class);
        $outlookSnapshots = app(OutlookAddinSnapshotRefreshScheduler::class);

        [$published, $htmlReport, $cssReport, $compatibility] = DB::transaction(function () use ($document, $actor, $sanitizer, $compatibilityAuditor, $publishedDocuments, $validated, $versions): array {
            $locked = $this->lockDesignSlotsFor($document);

            // Auch die Freigabe ist ein Schreibvorgang. Ohne denselben
            // Vergleich wie beim Speichern koennte ein veralteter Tab den
            // inzwischen geaenderten Serverentwurf unbemerkt freigeben.
            if (! $locked->matchesContentHash((string) $validated['expected_hash'])) {
                throw ValidationException::withMessages([
                    'expected_hash' => 'Das Dokument wurde zwischenzeitlich geändert. Bitte lade die Seite neu, bevor du es veröffentlichst.',
                ]);
            }

            $html = MailDocumentAutoRepair::repairHtml($locked->kind, (string) $locked->html);

            if (trim($html) === '') {
                throw ValidationException::withMessages([
                    'html' => 'Das Dokument ist leer und kann nicht veröffentlicht werden.',
                ]);
            }

            // Nach der verlustfreien Vertragsmigration bleibt die Freigabe
            // streng: unbekannte Inhalte werden niemals still beschnitten
            // und an echte Empfaenger geschickt.
            $this->assertEditableCssSource((string) $locked->css);
            $this->assertDocumentStructure($locked, $html, (string) $locked->css);
            $htmlReport = $this->assertCleanHtml($sanitizer, $html);
            $cssReport = $this->cleanStyleSheet($sanitizer, (string) $locked->css);

            if ($cssReport->hasViolations()) {
                throw ValidationException::withMessages([
                    'css' => array_merge(
                        ['Die Stilregeln enthalten Syntax, die in E-Mails nicht erlaubt ist.'],
                        $cssReport->violationMessages(),
                    ),
                ]);
            }

            $this->assertDocumentStructure($locked, $htmlReport->html, $cssReport->html);
            $sourceCompatibility = $this->auditCompatibility(
                $compatibilityAuditor,
                $locked->kind,
                $htmlReport->html,
                $cssReport->html,
            );
            $this->assertCompatibilityPublishable($sourceCompatibility);
            $compatibility = $this->auditFinalCompatibility(
                $compatibilityAuditor,
                $publishedDocuments,
                $locked->kind,
                $htmlReport->html,
                $cssReport->html,
                $sourceCompatibility,
            );
            $this->assertCompatibilityPublishable($compatibility);

            $builderData = $this->syncBuilderData(
                $locked,
                $locked->builder_data ?: [],
                $htmlReport->html,
            );
            $contentHash = MailDocument::contentHashFor($builderData, $htmlReport->html, $cssReport->html);

            $attributes = [
                'builder_data' => $builderData,
                'html' => $htmlReport->html,
                'css' => $cssReport->html,
                'content_hash' => $contentHash,
                'published_html' => $htmlReport->html,
                'published_css' => $cssReport->html,
                'published_at' => now(),
                'status' => MailDocumentStatus::Published,
                'is_active' => $locked->isOutlookTemplate() ? null : true,
                'updated_by' => $actor->getKey(),
            ];

            if ($locked->isOutlookTemplate()) {
                $attributes['outlook_released'] = true;
            }

            // Die Freigabe darf den Inhaltsstand nur dann hochzaehlen, wenn
            // die kanonische Builder-Struktur oder die Haertung ihn aendert.
            if (! hash_equals((string) $locked->content_hash, $contentHash)) {
                $attributes['version'] = $locked->version + 1;
            }

            if (! $locked->isOutlookTemplate() && Schema::hasColumn($locked->getTable(), 'is_active')) {
                MailDocument::query()
                    ->where('kind', $locked->kind->value)
                    ->whereKeyNot($locked->getKey())
                    ->where('is_active', true)
                    ->update([
                        'is_active' => null,
                        'status' => MailDocumentStatus::Draft->value,
                        'updated_by' => $actor->getKey(),
                        'updated_at' => now(),
                    ]);
            }

            $locked->forceFill($attributes)->save();
            $versions->capture($locked, $actor, 'published');

            return [$locked, $htmlReport, $cssReport, $compatibility];
        });

        $publishedDocuments->forget($published->kind);
        $outlookSnapshots->scheduleAll();

        return response()->json([
            'document' => $this->payload($published),
            'report' => $this->reportPayload($htmlReport, $cssReport),
            'compatibility' => $compatibility->toArray(),
        ]);
    }

    /** Dupliziert einen gespeicherten Entwurf als unabhaengigen Design-Slot. */
    public function createDesignSlot(
        Request $request,
        MailDocument $document,
        MailDocumentVersionStore $versions,
    ): JsonResponse {
        $actor = $this->mailAdmin($request);
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'expected_hash' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/i'],
        ]);

        return $this->duplicateForActor($actor, $document, (string) $validated['name'], (string) $validated['expected_hash']);
    }

    public function duplicateForActor(User $actor, MailDocument $document, string $name, string $expectedHash): JsonResponse
    {
        abort_unless($actor->isAdmin(), 403);
        $validated = validator(['name' => $name, 'expected_hash' => $expectedHash], [
            'name' => ['required', 'string', 'max:80'],
            'expected_hash' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/i'],
        ])->validate();
        $versions = app(MailDocumentVersionStore::class);
        $name = $this->normalizedSlotName((string) $validated['name']);

        $created = DB::transaction(function () use ($document, $validated, $name, $actor, $versions): MailDocument {
            $slots = MailDocument::query()
                ->where('kind', $document->kind->value)
                ->lockForUpdate()
                ->get();
            $source = $slots->firstWhere($document->getKeyName(), $document->getKey());
            abort_unless($source instanceof MailDocument, 404);

            if (! $source->matchesContentHash((string) $validated['expected_hash'])) {
                throw ValidationException::withMessages([
                    'expected_hash' => 'Der Ausgangsentwurf wurde zwischenzeitlich geändert. Bitte speichere erneut.',
                ]);
            }
            if (! $source->isOutlookTemplate() && $slots->filter(fn (MailDocument $slot): bool => ! $slot->isOutlookTemplate())->count() >= 20) {
                throw ValidationException::withMessages([
                    'name' => 'Pro Dokumentart können höchstens 20 Design-Slots angelegt werden.',
                ]);
            }
            $this->assertUniqueSlotName($slots, $name);

            $slot = MailDocument::query()->create([
                'kind' => $source->kind,
                'name' => $name,
                'status' => MailDocumentStatus::Draft,
                'is_active' => null,
                'builder_data' => $source->builder_data ?: [],
                'html' => (string) $source->html,
                'css' => (string) $source->css,
                'published_html' => null,
                'published_css' => null,
                'published_at' => null,
                'content_hash' => (string) $source->content_hash,
                'version' => 1,
                'created_by' => $actor->getKey(),
                'updated_by' => $actor->getKey(),
                ...(Schema::hasColumn('mail_documents', 'is_outlook_template') ? [
                    'is_outlook_template' => $source->isOutlookTemplate(),
                    'outlook_released' => false,
                    'outlook_default' => null,
                ] : []),
            ]);
            $versions->capture($slot, $actor, 'duplicated');

            return $slot;
        });

        return response()->json([
            'document' => $this->payload($created),
            'redirect' => $this->slotEditorUrl($created),
        ], 201);
    }

    /** Aendert nur den Anzeigenamen; Entwurf und Historie bleiben unberuehrt. */
    public function renameDesignSlot(Request $request, MailDocument $document): JsonResponse
    {
        $actor = $this->mailAdmin($request);
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'expected_hash' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/i'],
        ]);
        $name = $this->normalizedSlotName((string) $validated['name']);

        $renamed = DB::transaction(function () use ($document, $validated, $name, $actor): MailDocument {
            $slots = MailDocument::query()
                ->where('kind', $document->kind->value)
                ->lockForUpdate()
                ->get();
            $locked = $slots->firstWhere($document->getKeyName(), $document->getKey());
            abort_unless($locked instanceof MailDocument, 404);
            if (! $locked->matchesContentHash((string) $validated['expected_hash'])) {
                throw ValidationException::withMessages([
                    'expected_hash' => 'Der Design-Slot wurde zwischenzeitlich geändert. Bitte lade die Seite neu.',
                ]);
            }
            $this->assertUniqueSlotName($slots, $name, $locked);
            $locked->forceFill(['name' => $name, 'updated_by' => $actor->getKey()])->save();

            return $locked;
        });

        return response()->json(['document' => $this->payload($renamed)]);
    }

    /** Entfernt nur inaktive Slots und bewahrt immer mindestens einen Entwurf. */
    public function deleteDesignSlot(Request $request, MailDocument $document): JsonResponse
    {
        $this->mailAdmin($request);
        $validated = $request->validate([
            'expected_hash' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/i'],
        ]);

        $redirect = DB::transaction(function () use ($document, $validated): string {
            $slots = MailDocument::query()
                ->where('kind', $document->kind->value)
                ->lockForUpdate()
                ->get();
            $locked = $slots->firstWhere($document->getKeyName(), $document->getKey());
            abort_unless($locked instanceof MailDocument, 404);
            if (! $locked->matchesContentHash((string) $validated['expected_hash'])) {
                throw ValidationException::withMessages([
                    'expected_hash' => 'Der Design-Slot wurde zwischenzeitlich geändert. Bitte lade die Seite neu.',
                ]);
            }
            if ($locked->isActive()) {
                throw ValidationException::withMessages([
                    'slot' => 'Das aktive, veröffentlichte Design kann nicht gelöscht werden. Aktiviere zuerst einen anderen Slot.',
                ]);
            }
            if ($slots->count() <= 1) {
                throw ValidationException::withMessages([
                    'slot' => 'Der letzte Design-Slot dieser Dokumentart kann nicht gelöscht werden.',
                ]);
            }

            $fallback = $slots->first(fn (MailDocument $slot): bool => $slot->getKey() !== $locked->getKey() && $slot->isActive())
                ?? $slots->first(fn (MailDocument $slot): bool => $slot->getKey() !== $locked->getKey());
            $locked->delete();

            return $this->slotEditorUrl($fallback);
        });

        return response()->json(['redirect' => $redirect]);
    }

    /** Loescht einen Historieneintrag, niemals aber die einzige Rueckfallebene. */
    public function deleteVersion(
        Request $request,
        MailDocument $document,
        MailDocumentVersion $version,
    ): JsonResponse {
        $this->mailAdmin($request);
        $validated = $request->validate([
            'expected_hash' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/i'],
        ]);
        abort_unless($version->mail_document_id === $document->getKey(), 404);

        $updated = DB::transaction(function () use ($document, $version, $validated): MailDocument {
            $locked = $this->lock($document);
            if (! $locked->matchesContentHash((string) $validated['expected_hash'])) {
                throw ValidationException::withMessages([
                    'expected_hash' => 'Der Design-Slot wurde zwischenzeitlich geändert. Bitte lade die Seite neu.',
                ]);
            }
            $versions = $locked->versions()->lockForUpdate()->get();
            $lockedVersion = $versions->firstWhere($version->getKeyName(), $version->getKey());
            abort_unless($lockedVersion instanceof MailDocumentVersion, 404);
            if ($versions->count() <= 1) {
                throw ValidationException::withMessages([
                    'version' => 'Die einzige gespeicherte Version eines Design-Slots kann nicht gelöscht werden.',
                ]);
            }
            $lockedVersion->delete();

            return $locked->refresh();
        });

        return response()->json(['document' => $this->payload($updated)]);
    }

    public function restoreVersion(
        Request $request,
        MailDocument $document,
        MailDocumentVersion $version,
        EmailHtmlSanitizer $sanitizer,
        MailDocumentVersionStore $versions,
    ): JsonResponse {
        $actor = $this->mailAdmin($request);
        $validated = $request->validate([
            'expected_hash' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/i'],
        ]);

        return $this->restoreForActor($actor, $document, $version, (string) $validated['expected_hash']);
    }

    public function restoreForActor(User $actor, MailDocument $document, MailDocumentVersion $version, string $expectedHash): JsonResponse
    {
        abort_unless($actor->isAdmin(), 403);
        $validated = validator(['expected_hash' => $expectedHash], [
            'expected_hash' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/i'],
        ])->validate();
        $sanitizer = app(EmailHtmlSanitizer::class);
        $versions = app(MailDocumentVersionStore::class);
        abort_unless($version->mail_document_id === $document->getKey(), 404);

        $restored = DB::transaction(function () use ($document, $version, $validated, $actor, $sanitizer, $versions): MailDocument {
            $locked = $this->lock($document);
            if (! $locked->matchesContentHash((string) $validated['expected_hash'])) {
                throw ValidationException::withMessages([
                    'expected_hash' => 'Das Dokument wurde zwischenzeitlich geändert. Bitte lade die Seite neu.',
                ]);
            }
            if (hash_equals((string) $locked->content_hash, (string) $version->content_hash)) {
                throw ValidationException::withMessages(['version' => 'Diese Version ist bereits der aktuelle Entwurf.']);
            }

            $versionHtml = MailDocumentAutoRepair::repairHtml($locked->kind, (string) $version->html);
            $htmlReport = $this->assertCleanHtml($sanitizer, $versionHtml);
            $cssReport = $this->cleanStyleSheet($sanitizer, (string) $version->css);
            if ($cssReport->hasViolations()) {
                throw ValidationException::withMessages(['css' => $cssReport->violationMessages()]);
            }
            $this->assertDocumentStructure($locked, $htmlReport->html, $cssReport->html);
            $builderData = $this->syncBuilderData($locked, $version->builder_data ?: [], $htmlReport->html);
            $hash = MailDocument::contentHashFor($builderData, $htmlReport->html, $cssReport->html);

            $locked->forceFill([
                'builder_data' => $builderData,
                'html' => $htmlReport->html,
                'css' => $cssReport->html,
                'content_hash' => $hash,
                'version' => $locked->version + 1,
                'updated_by' => $actor->getKey(),
            ])->save();
            $versions->capture($locked, $actor, 'restored');

            return $locked;
        });

        return response()->json(['document' => $this->payload($restored)]);
    }

    public function testMail(
        Request $request,
        MailDocument $document,
        EmailHtmlSanitizer $sanitizer,
        EmailCompatibilityAuditor $compatibilityAuditor,
        PublishedMailDocumentSnapshotStore $snapshots,
    ): JsonResponse {
        $this->mailAdmin($request);
        $validated = $request->validate([
            'expected_hash' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/i'],
        ]);
        $document->refresh();
        if (! $document->matchesContentHash((string) $validated['expected_hash'])) {
            throw ValidationException::withMessages([
                'expected_hash' => 'Der Testmail-Entwurf ist nicht mehr aktuell. Bitte speichere erneut.',
            ]);
        }

        $recipient = trim((string) Setting::getValueUncached('mails', 'admin_email'));
        if (filter_var($recipient, FILTER_VALIDATE_EMAIL) === false) {
            throw ValidationException::withMessages([
                'recipient' => 'In den Systemeinstellungen ist keine gültige Admin-E-Mail-Adresse hinterlegt.',
            ]);
        }

        $repairedHtml = MailDocumentAutoRepair::repairHtml($document->kind, (string) $document->html);
        $htmlReport = $this->assertCleanHtml($sanitizer, $repairedHtml);
        $html = $htmlReport->html;
        $cssReport = $this->cleanStyleSheet($sanitizer, (string) $document->css);
        if ($cssReport->hasViolations()) {
            throw ValidationException::withMessages(['css' => $cssReport->violationMessages()]);
        }
        $this->assertDocumentStructure($document, $html, $cssReport->html);
        $sourceCompatibility = $this->auditCompatibility(
            $compatibilityAuditor,
            $document->kind,
            $html,
            $cssReport->html,
        );
        $this->assertCompatibilityPublishable($sourceCompatibility);
        $compatibility = $this->auditFinalCompatibility(
            $compatibilityAuditor,
            $snapshots,
            $document->kind,
            $html,
            $cssReport->html,
            $sourceCompatibility,
        );
        $this->assertCompatibilityPublishable($compatibility);
        $artifactVersion = SignatureArtifactVersion::detect($document->kind, $html);
        $shortHash = substr(strtolower((string) $document->content_hash), 0, 12);
        $snapshots->useSnapshot($document->kind, $html, $cssReport->html);

        try {
            Notification::route('mail', $recipient)->notify(
                new MailDocumentTestNotification(
                    $document->kind,
                    (int) $document->version,
                    $artifactVersion,
                    (string) $document->content_hash,
                ),
            );
        } finally {
            $snapshots->forget($document->kind);
        }

        $identity = 'Layout '.($artifactVersion ?? 'nicht gekennzeichnet')
            .' · Dokumentversion '.(int) $document->version
            .' · Prüfung '.$shortHash;

        return response()->json([
            'message' => 'Testmail ('.$identity.') wurde an '.$recipient.' gesendet.',
            'recipient' => $recipient,
            'layout_version' => $artifactVersion,
            'document_version' => (int) $document->version,
            'content_hash' => (string) $document->content_hash,
            'report' => $this->reportPayload($htmlReport, $cssReport),
            'compatibility' => $compatibility->toArray(),
        ]);
    }

    private function lock(MailDocument $document): MailDocument
    {
        $locked = MailDocument::query()
            ->whereKey($document->getKey())
            ->lockForUpdate()
            ->first();

        abort_unless($locked instanceof MailDocument, 404);

        return $locked;
    }

    /** Sperrt alle Slots einer Art, damit zwei Freigaben nicht nebeneinander aktiv werden. */
    private function lockDesignSlotsFor(MailDocument $document): MailDocument
    {
        if (! Schema::hasColumn($document->getTable(), 'is_active')) {
            return $this->lock($document);
        }

        $slots = MailDocument::query()
            ->where('kind', $document->kind->value)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
        $locked = $slots->firstWhere($document->getKeyName(), $document->getKey());
        abort_unless($locked instanceof MailDocument, 404);

        return $locked;
    }

    private function normalizedSlotName(string $name): string
    {
        $normalized = trim((string) preg_replace('/\s+/u', ' ', $name));
        if ($normalized === '') {
            throw ValidationException::withMessages(['name' => 'Bitte gib einen Namen für den Design-Slot ein.']);
        }

        return $normalized;
    }

    /** @param  Collection<int, MailDocument>  $slots */
    private function assertUniqueSlotName(
        Collection $slots,
        string $name,
        ?MailDocument $except = null,
    ): void {
        $key = mb_strtolower($name, 'UTF-8');
        $duplicate = $slots->contains(static fn (MailDocument $slot): bool => ($except === null || $slot->getKey() !== $except->getKey())
            && mb_strtolower(trim((string) $slot->name), 'UTF-8') === $key
        );

        if ($duplicate) {
            throw ValidationException::withMessages([
                'name' => 'Ein Design-Slot mit diesem Namen ist bereits vorhanden.',
            ]);
        }
    }

    private function slotEditorUrl(MailDocument $document): string
    {
        return route('admin.mail-documents.editor', [
            'dokument' => $document->kind->value,
            'slot' => $document->public_id,
            'open' => 1,
        ]);
    }

    /**
     * Die Stilregeln des Editors liegen in einer eigenen Spalte, der
     * Sanitizer prueft Stilregeln aber nur im <style>-Element. Das CSS
     * bekommt deshalb eine Huelle, die danach wieder abfaellt.
     */
    private function cleanStyleSheet(EmailHtmlSanitizer $sanitizer, string $css): EmailHtmlReport
    {
        if (trim($css) === '') {
            return new EmailHtmlReport($css);
        }

        // Ein "</style" im CSS wuerde die Huelle von innen aufbrechen: der
        // Parser beendet das Element frueher, der Rest landet als Text im
        // Dokument und die Ruecknahme der Huelle greift daneben. Solche
        // Stilregeln gibt es in echtem CSS nicht.
        if (stripos($css, '</style') !== false) {
            return new EmailHtmlReport('', [
                EmailHtmlReport::finding(
                    'css.unreadable',
                    'Die Stilregeln enthalten ein schliessendes style-Element und wurden verworfen.',
                ),
            ]);
        }

        $report = $sanitizer->clean('<style>'.$css.'</style>');

        if (preg_match('~^<style[^>]*>(.*)</style>$~s', trim($report->html), $matches) !== 1) {
            return new EmailHtmlReport('', array_merge($report->findings, [
                EmailHtmlReport::finding(
                    'css.unreadable',
                    'Die Stilregeln liessen sich nicht eindeutig lesen und wurden verworfen.',
                ),
            ]));
        }

        return new EmailHtmlReport($matches[1], $report->findings);
    }

    /** Freie CSS-Spalten duerfen serverkontrollierte Mailregeln nie schlagen. */
    private function assertEditableCssSource(string $css): void
    {
        if (CssSemantic::containsForbiddenAnimationOrProtectedSelector($css)) {
            throw ValidationException::withMessages([
                'css' => CssSemantic::PROTECTED_EDITABLE_CSS_MESSAGE,
            ]);
        }

        if (CssSemantic::containsImportant($css)) {
            throw ValidationException::withMessages([
                'css' => 'Separate Mail-CSS-Regeln duerfen kein !important enthalten.',
            ]);
        }

        if (CssSemantic::containsReservedRuntimeToken($css)) {
            throw ValidationException::withMessages([
                'css' => 'Reservierte Runtime-Platzhalter duerfen nicht in separaten Mail-CSS-Regeln verwendet werden.',
            ]);
        }

    }

    /**
     * Das Builder-Projekt ist kein zweiter, ungepruefter HTML-Speicher.
     *
     * GrapesJS laedt component beim naechsten Aufruf wieder in den Editor.
     * Darum muss dort exakt das bereits gehaertete HTML stehen; CSS lebt nur
     * in der separat geprueften Spalte. Editor-Metadaten werden auf den
     * dokumentierten, serverseitig kontrollierten Vertrag reduziert.
     *
     * @param  array<string, mixed>  $builderData
     * @return array<string, mixed>
     */
    private function syncBuilderData(MailDocument $document, array $builderData, string $html): array
    {
        return MailDocumentAutoRepair::synchronizeBuilderData(
            $document->kind,
            $builderData,
            $html,
            $document->kind->label(),
        );
    }

    /**
     * Fragment-HTML bleibt ueber Import, Save, Version und Testmail
     * byte-stabil. libxml fuegt bei bestimmten gueltigen Tabellenformen
     * (etwa rowspan im responsiven Signaturlayout) am Dokumentrand einen
     * Zeilenumbruch an; fuer eine Mail ist er bedeutungslos, fuer Hash und
     * Versionsvergleich aber nicht.
     */
    private function assertCleanHtml(EmailHtmlSanitizer $sanitizer, string $html): EmailHtmlReport
    {
        $report = $sanitizer->assertClean(trim($html));

        return new EmailHtmlReport(trim($report->html), $report->findings);
    }

    /**
     * Syntaxsauber allein ist nicht auslieferbar: die Nachrichtenschale muss
     * ihren Signatur-Einhaengepunkt behalten, die Signatur ihre zwei
     * Tabellenzeilen, optionalen Kontaktmarker und gesetzlichen Pflichtwerte.
     */
    private function assertDocumentStructure(MailDocument $document, string $html, string $css = ''): void
    {
        $this->assertEditableCssSource($css);
        $this->assertTransportableImageSources($html, $css);

        if ($document->kind === MailDocumentKind::Template) {
            try {
                TemplateDocumentContract::assertValid($html);
            } catch (\RuntimeException $exception) {
                throw ValidationException::withMessages([
                    'html' => $exception->getMessage(),
                ]);
            }

            return;
        }

        if (stripos($html, 'data-rt-mail-') !== false
            || str_contains($html, EmailHtmlSanitizer::PREVIEW_TRANSPARENT_PIXEL)) {
            throw ValidationException::withMessages([
                'html' => 'Editor-Vorschauattribute und der transparente Vorschaupixel sind in einer Signatur nicht speicherbar.',
            ]);
        }

        if (stripos($css, 'data-rt-mail-') !== false
            || str_contains($css, EmailHtmlSanitizer::PREVIEW_TRANSPARENT_PIXEL)) {
            throw ValidationException::withMessages([
                'css' => 'Editor-Vorschauselektoren und der transparente Vorschaupixel sind in Signatur-CSS nicht speicherbar.',
            ]);
        }

        try {
            SignatureDocumentContract::assertValid($html);
        } catch (\RuntimeException $exception) {
            throw ValidationException::withMessages([
                'html' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * Gespeicherte Editor-Dokumente transportieren keine freien MIME-Parts.
     * Bekannte RailTime-CIDs entstehen ausschliesslich nach der kontrollierten
     * Runtime-Tokenbindung; eine feste cid:-Quelle wuerde ansonsten erst beim
     * Outlook-Snapshot oder Versand ohne zugehoerigen Anhang scheitern.
     */
    private function assertTransportableImageSources(string $html, string $css): void
    {
        $errors = [];
        if (PortableMediaCatalog::untransportableCidImageSources($html) !== []) {
            $errors['html'] = 'Direkte cid:-Bildquellen besitzen im gespeicherten Dokument keinen transportierbaren Anhang. Verwende einen freigegebenen Bildquellen-Platzhalter oder ein portables Importmedium.';
        }
        if (PortableMediaCatalog::untransportableCidImageSources('', $css) !== []) {
            $errors['css'] = 'Direkte cid:-Bildquellen besitzen im gespeicherten CSS keinen transportierbaren Anhang. Verwende einen freigegebenen Bildquellen-Platzhalter oder ein portables Importmedium.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(MailDocument $document): array
    {
        return [
            'id' => $document->public_id,
            'kind' => $document->kind->value,
            'name' => (string) ($document->name ?: $document->kind->label()),
            'is_active' => $document->isActive(),
            'is_outlook_template' => $document->isOutlookTemplate(),
            'outlook_released' => $document->isOutlookTemplate() && $document->isPublished(),
            'outlook_default' => $document->outlook_default === true,
            'status' => $document->status->value,
            'status_label' => $document->status->label(),
            'content_hash' => (string) $document->content_hash,
            'version' => (int) $document->version,
            // Serverautoritative Werte: nach der Haertung darf der Client
            // sein lokales, noch ungeprueftes Projekt nicht weiterverwenden.
            'builder_data' => $document->builder_data ?: [],
            'html' => (string) $document->html,
            'css' => (string) $document->css,
            'published_at' => $document->published_at?->toIso8601String(),
            'published_label' => $document->published_at?->translatedFormat('d.m.Y H:i'),
            // Der veroeffentlichte Abzug bleibt in Kraft, waehrend am Entwurf
            // weitergearbeitet wird — genau das muss der Editor anzeigen.
            'has_unpublished_changes' => $document->hasUnpublishedChanges(),
            'versions' => $this->versionPayload($document),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function versionPayload(MailDocument $document): array
    {
        if (! Schema::hasTable('mail_document_versions')) {
            return [];
        }

        return $document->versions()->with('creator:id,name')->limit(40)->get()->map(
            static fn (MailDocumentVersion $version): array => [
                'id' => $version->public_id,
                'revision' => (int) $version->revision,
                'action' => $version->action,
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
        )->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function reportPayload(EmailHtmlReport $html, EmailHtmlReport $css): array
    {
        $findings = array_merge($html->findings, $css->findings);

        return [
            'clean' => $findings === [],
            'findings' => $findings,
            'messages' => array_merge($html->messages(), $css->messages()),
        ];
    }

    /**
     * Der Sicherheits-Sanitizer bleibt die erste, unabhaengige Schranke.
     * Erst sein kanonisches Ergebnis wird gegen den versionierten fachlichen
     * Katalog geprueft. Ein fehlender/ungueltiger Katalog ist ein sichtbarer
     * BLOCK-Bericht, darf einen Entwurf aber weiterhin speicherbar lassen.
     */
    private function auditCompatibility(
        EmailCompatibilityAuditor $auditor,
        MailDocumentKind $kind,
        string $html,
        string $css,
    ): EmailCompatibilityReport {
        try {
            return $auditor->audit($html, $css, [
                'document_kind' => $kind->value,
                // An dieser Stelle sind HTML und CSS bereits durch den
                // unveraendert strengen Sanitizer gelaufen. Damit darf der
                // Auditor bekannte Systemkomponenten bewerten, ohne die
                // Benutzer-Allowlist fuer Animation/Position zu oeffnen.
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
     * Veroeffentlichung und Testmail messen nicht nur das Builder-Fragment,
     * sondern eine final zusammengesetzte, personalisierte Systemmail. Der
     * request-lokale Snapshot verbindet dabei exakt den Kandidaten mit dem
     * jeweils anderen aktuellen Baustein, ohne einen bestehenden Abzug zu
     * migrieren oder dauerhaft umzuschreiben.
     */
    private function auditFinalCompatibility(
        EmailCompatibilityAuditor $auditor,
        PublishedMailDocumentSnapshotStore $snapshots,
        MailDocumentKind $candidateKind,
        string $candidateHtml,
        string $candidateCss,
        EmailCompatibilityReport $fallback,
    ): EmailCompatibilityReport {
        $finalHtml = $this->compileFinalSystemMailCandidate(
            $snapshots,
            $candidateKind,
            $candidateHtml,
            $candidateCss,
        );
        if ($finalHtml === null) {
            return $fallback;
        }

        return $this->auditCompiledSystemMail($auditor, $finalHtml);
    }

    /**
     * Verwendet exakt den Compiler, der auch Laravel-Systemmails ausliefert.
     * Der jeweils andere Mailbaustein kommt aus seiner Freigabe; nur wenn es
     * noch keine gibt, dient dessen aktueller Entwurf als Einrichtungsfallback.
     */
    private function compileFinalSystemMailCandidate(
        PublishedMailDocumentSnapshotStore $snapshots,
        MailDocumentKind $candidateKind,
        string $candidateHtml,
        string $candidateCss,
    ): ?string {
        $documents = [];
        foreach (MailDocumentKind::cases() as $kind) {
            if ($kind === $candidateKind) {
                $documents[$kind->value] = ['html' => $candidateHtml, 'css' => $candidateCss];

                continue;
            }

            $document = MailDocument::query()
                ->where('kind', $kind->value)
                ->active()
                ->first()
                ?? MailDocument::query()->where('kind', $kind->value)->orderBy('id')->first();
            if (! $document instanceof MailDocument) {
                return null;
            }

            $publishedHtml = trim((string) $document->published_html);
            $documents[$kind->value] = [
                'html' => $publishedHtml !== '' ? $publishedHtml : (string) $document->html,
                'css' => $publishedHtml !== '' ? (string) $document->published_css : (string) $document->css,
            ];
        }

        foreach (MailDocumentKind::cases() as $kind) {
            $snapshot = $documents[$kind->value];
            if (trim($snapshot['html']) === '') {
                return null;
            }
        }

        foreach (MailDocumentKind::cases() as $kind) {
            $snapshot = $documents[$kind->value];
            $snapshots->useSnapshot($kind, $snapshot['html'], $snapshot['css']);
        }

        try {
            // Wie beim echten Notification-Versand: Die Nachrichtenschale
            // allein ist noch nicht die Mail. Markdown fuehrt anschliessend
            // auch den CSS-Inliner aus (einschliesslich dessen Kaskade).
            $message = (new MailMessage)
                ->greeting('RailTime Kompatibilitätsprüfung')
                ->line('Vorschau der aktuellen Nachrichtenvorlage und Signatur.')
                ->action('RailTime öffnen', 'https://rail-time.de/');

            return (string) app(Markdown::class)
                ->render($message->markdown ?: 'notifications::email', $message->data());
        } catch (\Throwable $exception) {
            throw ValidationException::withMessages([
                'compatibility' => 'Die finale Systemmail konnte nicht kompiliert werden: '.$exception->getMessage(),
            ]);
        } finally {
            foreach (MailDocumentKind::cases() as $kind) {
                $snapshots->forget($kind);
            }
        }
    }

    /** Bewertet das bereits final zusammengesetzte Versand-HTML. */
    private function auditCompiledSystemMail(
        EmailCompatibilityAuditor $auditor,
        string $finalHtml,
    ): EmailCompatibilityReport {
        try {
            return $auditor->audit($finalHtml, '', [
                'document_kind' => 'system_mail',
                'plain_text' => "RailTime Kompatibilitätsprüfung\nhttps://rail-time.de/",
                'trusted_system_css' => true,
                'allow_template_tokens' => false,
            ]);
        } catch (EmailCompatibilityCatalogException $exception) {
            return EmailCompatibilityReport::unavailable($exception, strlen($finalHtml));
        }
    }

    /** WARN bleibt freigabefaehig; nur BLOCK stoppt produktive Ausgabe. */
    private function assertCompatibilityPublishable(EmailCompatibilityReport $report): void
    {
        if (! $report->blocksPublication()) {
            return;
        }

        $messages = array_values(array_map(
            static function (array $finding): string {
                $message = '['.$finding['rule_id'].'] '.$finding['message'];
                $fix = trim((string) ($finding['fix'] ?? ''));

                return $fix === '' ? $message : $message.' Lösung: '.$fix;
            },
            array_filter(
                $report->findings,
                static fn (array $finding): bool => $finding['enforcement'] === 'BLOCK',
            ),
        ));

        throw ValidationException::withMessages([
            'compatibility' => $messages === []
                ? ['Die E-Mail-Kompatibilitätsprüfung blockiert die produktive Ausgabe.']
                : $messages,
        ]);
    }

    /**
     * role:admin ist fuer diese Endpunkte abgeschaltet: RoleMiddleware
     * antwortet bei falscher Rolle mit einer Weiterleitung auf HTML statt
     * mit 403 — im Editor kaeme eine Anmeldeseite als vermeintliche
     * JSON-Antwort an. Deshalb hier die ausdrueckliche Pruefung.
     */
    private function mailAdmin(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->isAdmin(), 403);

        return $user;
    }
}
