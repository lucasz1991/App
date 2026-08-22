<?php

namespace App\Http\Controllers\Admin;

use App\Enums\MailDocumentKind;
use App\Enums\MailDocumentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Mail\SaveMailDocumentRequest;
use App\Http\Requests\Mail\ImportMailDocumentRequest;
use App\Models\MailDocument;
use App\Models\User;
use App\Support\Mail\CssSemantic;
use App\Support\Mail\EmailHtmlReport;
use App\Support\Mail\EmailHtmlSanitizer;
use App\Support\Mail\PublishedMailDocumentSnapshotStore;
use App\Support\Mail\SignatureDocumentContract;
use App\Support\Mail\TemplateDocumentContract;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
 *  2. Beanstandungen gehen an den Bearbeiter zurueck. Beim Speichern als
 *     Bericht (es wurde bereinigt), beim Veroeffentlichen als Fehler — was
 *     an echte Empfaenger geht, wird nicht stillschweigend repariert.
 */
final class MailDocumentController extends Controller
{
    /**
     * Legt ein noch fehlendes Maildokument ausschliesslich aus einem zuvor
     * exportierten v2-Bundle an. Dieser explizite Erstimport ersetzt den
     * autoritativen Seeder und ueberschreibt niemals vorhandene Inhalte.
     */
    public function import(
        ImportMailDocumentRequest $request,
        EmailHtmlSanitizer $sanitizer,
    ): JsonResponse {
        $actor = $this->mailAdmin($request);
        $validated = $request->validated();
        $kind = MailDocumentKind::from((string) $validated['kind']);

        [$html, $css, $portableFiles] = $this->preparePortableMedia(
            (string) $validated['html'],
            (string) $validated['css'],
            (array) $validated['media'],
        );
        $this->assertEditableCssSource($css);

        $prototype = new MailDocument(['kind' => $kind->value]);
        $this->assertDocumentStructure($prototype, $html, $css);
        $htmlReport = $sanitizer->assertClean($html);
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
        ): MailDocument {
            if (MailDocument::query()->where('kind', $kind->value)->lockForUpdate()->exists()) {
                throw ValidationException::withMessages([
                    'kind' => 'Dieses Maildokument ist bereits eingerichtet. Bitte importiere es im geöffneten Editor.',
                ]);
            }

            $this->storePortableMedia($portableFiles);

            return MailDocument::query()->create([
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
        });

        return response()->json([
            'document' => $this->payload($document),
            'redirect' => route('admin.mail-documents.editor', [
                'dokument' => $kind->value,
                'open' => 1,
            ]),
            'report' => $this->reportPayload($htmlReport, $cssReport),
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
    ): JsonResponse {
        $this->mailAdmin($request);
        $validated = $request->validated();

        if (! $document->matchesContentHash((string) $validated['expected_hash'])) {
            throw ValidationException::withMessages([
                'expected_hash' => 'Das Dokument wurde zwischenzeitlich geändert. Bitte lade die Seite vor dem Codeimport neu.',
            ]);
        }

        [$html, $css, $portableFiles] = $this->preparePortableMedia(
            (string) $validated['html'],
            (string) $validated['css'],
            (array) ($validated['portable_media'] ?? []),
        );
        $this->assertEditableCssSource($css);
        $this->assertDocumentStructure($document, $html, $css);
        $htmlReport = $sanitizer->assertClean($html);
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
        ]);
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
            $html = str_replace(
                [$source, htmlspecialchars($source, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8')],
                [$target, htmlspecialchars($target, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8')],
                $html,
            );
            $css = str_replace($source, $target, $css);
            $files[] = ['path' => $path, 'binary' => $binary];
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
    ): JsonResponse {
        $actor = $this->mailAdmin($request);
        $validated = $request->validated();

        [$saved, $htmlReport, $cssReport] = DB::transaction(function () use ($document, $validated, $actor, $sanitizer): array {
            $locked = $this->lock($document);

            // Der Hashvergleich ist ohne Sperre wertlos: zwei parallele
            // Autosaves ueberschrieben sich sonst trotz gueltigem Hash.
            if (! $locked->matchesContentHash((string) $validated['expected_hash'])) {
                throw ValidationException::withMessages([
                    'expected_hash' => 'Das Dokument wurde zwischenzeitlich geändert. Bitte lade die Seite neu — sonst gehen fremde Änderungen verloren.',
                ]);
            }

            // Struktur- und Reservierungsvertraege gelten bereits fuer die
            // eingereichte Quelle. Der Sanitizer darf einen zusaetzlichen
            // style-Block oder Zugtoken nicht still entfernen und dadurch
            // aus einem verbotenen Entwurf scheinbar einen gueltigen machen.
            $this->assertEditableCssSource((string) $validated['css']);
            $this->assertDocumentStructure(
                $locked,
                (string) $validated['html'],
                (string) $validated['css'],
            );
            $htmlReport = $sanitizer->clean((string) $validated['html']);
            $cssReport = $this->cleanStyleSheet($sanitizer, (string) $validated['css']);
            $this->assertDocumentStructure($locked, $htmlReport->html, $cssReport->html);
            $builderData = $this->syncBuilderData(
                $locked,
                $validated['builder_data'],
                $htmlReport->html,
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
            }

            return [$locked, $htmlReport, $cssReport];
        });

        return response()->json([
            'document' => $this->payload($saved),
            'report' => $this->reportPayload($htmlReport, $cssReport),
        ]);
    }

    public function publish(
        Request $request,
        MailDocument $document,
        EmailHtmlSanitizer $sanitizer,
        PublishedMailDocumentSnapshotStore $publishedDocuments,
    ): JsonResponse {
        $actor = $this->mailAdmin($request);
        $validated = $request->validate([
            'expected_hash' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/i'],
        ]);

        [$published, $htmlReport, $cssReport] = DB::transaction(function () use ($document, $actor, $sanitizer, $validated): array {
            $locked = $this->lock($document);

            // Auch die Freigabe ist ein Schreibvorgang. Ohne denselben
            // Vergleich wie beim Speichern koennte ein veralteter Tab den
            // inzwischen geaenderten Serverentwurf unbemerkt freigeben.
            if (! $locked->matchesContentHash((string) $validated['expected_hash'])) {
                throw ValidationException::withMessages([
                    'expected_hash' => 'Das Dokument wurde zwischenzeitlich geändert. Bitte lade die Seite neu, bevor du es veröffentlichst.',
                ]);
            }

            $html = (string) $locked->html;

            if (trim($html) === '') {
                throw ValidationException::withMessages([
                    'html' => 'Das Dokument ist leer und kann nicht veröffentlicht werden.',
                ]);
            }

            // Streng statt bereinigend: der Bearbeiter soll sehen, was nicht
            // durchgeht, statt eine stillschweigend beschnittene Fassung an
            // echte Empfaenger zu schicken.
            $this->assertEditableCssSource((string) $locked->css);
            $htmlReport = $sanitizer->assertClean($html);
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

            return [$locked, $htmlReport, $cssReport];
        });

        $publishedDocuments->forget($published->kind);

        return response()->json([
            'document' => $this->payload($published),
            'report' => $this->reportPayload($htmlReport, $cssReport),
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
        $pageName = data_get($builderData, 'pages.0.name', $document->kind->label());
        if (! is_string($pageName) || trim($pageName) === '') {
            $pageName = $document->kind->label();
        }

        $metadata = is_array($builderData['railtime'] ?? null)
            ? $builderData['railtime']
            : [];
        $schema = $metadata['schema'] ?? null;

        $railtime = ['document' => $document->kind->value];
        if ($document->kind === MailDocumentKind::Signature) {
            // Der Serververtrag ist autoritativ. Ein importiertes Bundle
            // enthaelt absichtlich keine vertrauenswuerdigen Builderdaten.
            $railtime['schema'] = SignatureDocumentContract::SCHEMA;
        } elseif (is_int($schema) && $schema > 0 && $schema <= 1000) {
            $railtime['schema'] = $schema;
        }

        return [
            'pages' => [[
                'name' => mb_substr(trim($pageName), 0, 80),
                'component' => $html,
            ]],
            'styles' => [],
            'railtime' => $railtime,
        ];
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
        ];
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
