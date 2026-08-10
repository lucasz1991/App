<?php

namespace App\Http\Controllers\Admin;

use App\Enums\MailDocumentKind;
use App\Enums\MailDocumentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Mail\SaveMailDocumentRequest;
use App\Models\MailDocument;
use App\Models\User;
use App\Support\Mail\EmailHtmlReport;
use App\Support\Mail\EmailHtmlSanitizer;
use App\Support\Mail\PublishedMailDocumentSnapshotStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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

            $htmlReport = $sanitizer->clean((string) $validated['html']);
            $cssReport = $this->cleanStyleSheet($sanitizer, (string) $validated['css']);
            if ($locked->kind === MailDocumentKind::Signature) {
                $this->assertDocumentStructure($locked, $htmlReport->html, $cssReport->html);
            }
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
        if (is_int($schema) && $schema > 0 && $schema <= 1000) {
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
        if ($document->kind === MailDocumentKind::Template) {
            $completeDocument = preg_match('/^\s*<!doctype\s+html\b/i', $html) === 1
                && preg_match('/<html\b[^>]*>.*<head\b[^>]*>.*<\/head>.*<body\b[^>]*>.*<\/body>.*<\/html>\s*$/is', $html) === 1;

            if (! $completeDocument || substr_count($html, '{{SIGNATURE_BLOCK}}') !== 1) {
                throw ValidationException::withMessages([
                    'html' => 'Die Nachrichtenvorlage braucht eine vollstaendige HTML-Schale und genau einen Signaturblock.',
                ]);
            }

            return;
        }

        $requiredTokens = [
            '{{LOGO_SRC}}',
            '{{VORNAME_NACHNAME}}',
            '{{POSITION}}',
            '{{E_MAIL}}',
            '{{FIRMENNAME}}',
            '{{FIRMENSTRASSE}}',
            '{{FIRMEN_PLZ_ORT}}',
            '{{FIRMEN_TELEFON}}',
            '{{FIRMEN_EMAIL}}',
            '{{GESCHAEFTSFUEHRUNG}}',
            '{{REGISTERGERICHT}}',
            '{{HRB}}',
            '{{UST_ID}}',
            '{{STEUERNUMMER}}',
        ];
        $requiredMarkers = [
            'RT_PHONE_START', 'RT_PHONE_END',
            'RT_MOBILE_START', 'RT_MOBILE_END',
            'RT_WEBSITE_START', 'RT_WEBSITE_END',
            'RT_COMPANY_PHONE_START', 'RT_COMPANY_PHONE_END',
        ];

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

        if (! $this->hasCanonicalSignatureTrain($html)
            || collect([...$requiredTokens, ...$requiredMarkers])
                ->contains(fn (string $needle): bool => ! str_contains($html, $needle))) {
            throw ValidationException::withMessages([
                'html' => 'Die Signatur muss das zweizeilige Tabellenfragment, genau eine kanonische Zugquelle, alle Kontaktmarker und rechtlichen Pflichtangaben behalten; Editor-Vorschauwerte sind nicht speicherbar.',
            ]);
        }
    }

    /**
     * {{TRAIN_SRC}} darf genau einmal und nur als url() der aeusseren
     * Signaturzelle vorkommen. Klassenkopien, Texttokens und zusaetzliche
     * Zugquellen koennten sonst den neutralen Canvas-Pixel ausliefern.
     */
    private function hasCanonicalSignatureTrain(string $html): bool
    {
        if (substr_count($html, '{{TRAIN_SRC}}') !== 1) {
            return false;
        }

        $previousErrors = libxml_use_internal_errors(true);

        try {
            $document = new \DOMDocument('1.0', 'UTF-8');
            $loaded = $document->loadHTML(
                '<?xml encoding="UTF-8"><table id="rt-signature-contract"><tbody>'.$html.'</tbody></table>',
                LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING,
            );
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousErrors);
        }

        if (! $loaded) {
            return false;
        }

        $wrapper = $document->getElementById('rt-signature-contract');
        $tbody = $wrapper?->getElementsByTagName('tbody')->item(0);
        if (! $tbody instanceof \DOMElement) {
            return false;
        }

        $topRows = [];
        foreach ($tbody->childNodes as $child) {
            if ($child instanceof \DOMElement && strtolower($child->tagName) === 'tr') {
                $topRows[] = $child;
            }
        }

        if (count($topRows) !== 2) {
            return false;
        }

        $trainCells = [];
        foreach ($wrapper->getElementsByTagName('td') as $cell) {
            $classes = preg_split('/\s+/', trim($cell->getAttribute('class')), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            if (in_array('rt-sign-cell', $classes, true)) {
                $trainCells[] = $cell;
            }
        }

        if (count($trainCells) !== 1
            || ! $trainCells[0]->parentNode?->isSameNode($topRows[0])) {
            return false;
        }

        return preg_match(
            "~(?:^|;)\\s*background(?:-image)?\\s*:\\s*[^;]*url\\(\\s*(['\"]?)\\{\\{TRAIN_SRC\\}\\}\\1\\s*\\)[^;]*(?:;|$)~i",
            $trainCells[0]->getAttribute('style'),
        ) === 1;
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
