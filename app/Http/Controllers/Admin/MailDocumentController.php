<?php

namespace App\Http\Controllers\Admin;

use App\Enums\MailDocumentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Mail\SaveMailDocumentRequest;
use App\Models\MailDocument;
use App\Models\User;
use App\Support\Mail\EmailHtmlReport;
use App\Support\Mail\EmailHtmlSanitizer;
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

            $hash = MailDocument::contentHashFor(
                $validated['builder_data'],
                $htmlReport->html,
                $cssReport->html,
            );

            // Idempotent: ist nichts anders, wird nicht geschrieben. Sonst
            // zaehlte jedes Autosave die Version hoch.
            if (! hash_equals((string) $locked->content_hash, $hash)) {
                $locked->forceFill([
                    'builder_data' => $validated['builder_data'],
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
    ): JsonResponse {
        $actor = $this->mailAdmin($request);

        [$published, $htmlReport, $cssReport] = DB::transaction(function () use ($document, $actor, $sanitizer): array {
            $locked = $this->lock($document);
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

            $attributes = [
                'published_html' => $htmlReport->html,
                'published_css' => $cssReport->html,
                'published_at' => now(),
                'status' => MailDocumentStatus::Published,
                'updated_by' => $actor->getKey(),
            ];

            // Hat die Haertung das Markup begradigt, wird die begradigte
            // Fassung auch der neue Arbeitsstand. Sonst meldete der Editor
            // dauerhaft "nicht veroeffentlichte Aenderungen" gegen sich selbst.
            if ($htmlReport->html !== $html || $cssReport->html !== (string) $locked->css) {
                $builderData = $locked->builder_data ?: [];

                $attributes += [
                    'html' => $htmlReport->html,
                    'css' => $cssReport->html,
                    'content_hash' => MailDocument::contentHashFor($builderData, $htmlReport->html, $cssReport->html),
                    'version' => $locked->version + 1,
                ];
            }

            $locked->forceFill($attributes)->save();

            return [$locked, $htmlReport, $cssReport];
        });

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
     * @return array<string, mixed>
     */
    private function payload(MailDocument $document): array
    {
        $published = $document->publishedHtml();

        return [
            'kind' => $document->kind->value,
            'status' => $document->status->value,
            'status_label' => $document->status->label(),
            'content_hash' => (string) $document->content_hash,
            'version' => (int) $document->version,
            'published_at' => $document->published_at?->toIso8601String(),
            'published_label' => $document->published_at?->translatedFormat('d.m.Y H:i'),
            // Der veroeffentlichte Abzug bleibt in Kraft, waehrend am Entwurf
            // weitergearbeitet wird — genau das muss der Editor anzeigen.
            'has_unpublished_changes' => $published === null || $published !== trim((string) $document->html),
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
