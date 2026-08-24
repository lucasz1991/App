<?php

namespace App\Http\Controllers\Admin;

use App\Enums\MailDocumentKind;
use App\Enums\MailDocumentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Mail\ImportMailDocumentRequest;
use App\Http\Requests\Mail\SaveMailDocumentRequest;
use App\Models\MailDocument;
use App\Models\MailDocumentVersion;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\MailDocumentTestNotification;
use App\Support\EmailTemplateBuilder;
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
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\HtmlString;
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
        $this->assertPortableMediaComplete(
            $kind,
            (string) $validated['html'],
            (array) $validated['media'],
            'media',
        );

        [$html, $css, $portableFiles] = $this->preparePortableMedia(
            (string) $validated['html'],
            (string) $validated['css'],
            (array) $validated['media'],
        );
        $html = MailDocumentAutoRepair::repairHtml($kind, $html);
        $this->assertEditableCssSource($css);

        $prototype = new MailDocument(['kind' => $kind->value]);
        $this->assertDocumentStructure($prototype, $html, $css);
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
        $this->assertDocumentStructure($prototype, $htmlReport->html, $cssReport->html);
        $compatibility = $this->auditCompatibility(
            $compatibilityAuditor,
            $kind,
            $htmlReport->html,
            $cssReport->html,
        );
        $builderData = $this->syncBuilderData($prototype, [], $htmlReport->html);
        $contentHash = MailDocument::contentHashFor(
            $builderData,
            $htmlReport->html,
            $cssReport->html,
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
                    'kind' => 'Dieses Maildokument ist bereits eingerichtet. Bitte importiere es im geöffneten Editor.',
                ]);
            }

            $this->storePortableMedia($portableFiles);

            $document = MailDocument::query()->create([
                'kind' => $kind,
                'status' => MailDocumentStatus::Draft,
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
                'open' => 1,
            ]),
            'report' => $this->reportPayload($htmlReport, $cssReport),
            'compatibility' => $compatibility->toArray(),
        ], 201);
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
        array $media,
        string $field = 'portable_media',
    ): void {
        $ids = array_values(array_filter(array_map(
            static fn ($entry): string => is_array($entry)
                ? trim((string) ($entry['id'] ?? ''))
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
            PortableMediaCatalog::requiredSystemAssetIds(
                $kind,
                SignatureArtifactVersion::detect($kind, $html),
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
            $escapedSource = htmlspecialchars(
                $source,
                ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5,
                'UTF-8',
            );
            // System-Tokens wie {{TRAIN_SRC}} binden weiterhin das
            // versionierte RailTime-Asset der Installation. Deren im Bundle
            // mitgefuehrte Bytes werden vollstaendig validiert, aber nicht als
            // unreferenzierte Dublette in storage/mail-imports abgelegt.
            $isReferenced = str_contains($html, $source)
                || str_contains($html, $escapedSource)
                || str_contains($css, $source);
            $html = str_replace(
                [$source, $escapedSource],
                [$target, htmlspecialchars($target, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8')],
                $html,
            );
            $css = str_replace($source, $target, $css);
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
            if ($disk->exists($file['path'])) {
                continue;
            }

            if (! $disk->put($file['path'], $file['binary'])) {
                throw ValidationException::withMessages([
                    'portable_media' => 'Ein importiertes Medium konnte nicht gespeichert werden.',
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
    ): JsonResponse {
        $actor = $this->mailAdmin($request);
        $validated = $request->validate([
            'expected_hash' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/i'],
        ]);

        [$published, $htmlReport, $cssReport, $compatibility] = DB::transaction(function () use ($document, $actor, $sanitizer, $compatibilityAuditor, $publishedDocuments, $validated, $versions): array {
            $locked = $this->lock($document);

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
                'updated_by' => $actor->getKey(),
            ];

            // Die Freigabe darf den Inhaltsstand nur dann hochzaehlen, wenn
            // die kanonische Builder-Struktur oder die Haertung ihn aendert.
            if (! hash_equals((string) $locked->content_hash, $contentHash)) {
                $attributes['version'] = $locked->version + 1;
            }

            $locked->forceFill($attributes)->save();
            $versions->capture($locked, $actor, 'published');

            return [$locked, $htmlReport, $cssReport, $compatibility];
        });

        $publishedDocuments->forget($published->kind);

        return response()->json([
            'document' => $this->payload($published),
            'report' => $this->reportPayload($htmlReport, $cssReport),
            'compatibility' => $compatibility->toArray(),
        ]);
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
     * @return array<string, mixed>
     */
    private function payload(MailDocument $document): array
    {
        return [
            'kind' => $document->kind->value,
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
                    default => 'Gespeichert',
                },
                'created_label' => $version->created_at?->translatedFormat('d.m.Y H:i'),
                'creator' => $version->creator?->name,
                'was_published' => (bool) $version->was_published,
                'restore_url' => route('admin.mail-documents.versions.restore', [$document, $version]),
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

            $document = MailDocument::query()->where('kind', $kind->value)->first();
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
            return EmailTemplateBuilder::buildSystemMailHtml(new HtmlString(
                '<p style="margin:0 0 16px;">RailTime Kompatibilitätsprüfung</p>'
                .'<p style="margin:0;"><a href="https://rail-time.de/">RailTime öffnen</a></p>',
            ));
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
