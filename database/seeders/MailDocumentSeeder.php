<?php

namespace Database\Seeders;

use App\Enums\MailDocumentKind;
use App\Enums\MailDocumentStatus;
use App\Models\MailDocument;
use App\Models\User;
use App\Support\EmailTemplateBuilder;
use App\Support\Mail\EmailHtmlSanitizer;
use App\Support\Mail\PublishedMailDocumentSnapshotStore;
use App\Support\MailSignature;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;
use RuntimeException;

/**
 * Setzt die beiden Maildokumente auf die ausgelieferte Startfassung — und
 * GIBT SIE SOFORT FREI, damit unmittelbar danach eine Systemnachricht
 * geprueft werden kann.
 *
 *   php artisan db:seed --class=MailDocumentSeeder --force
 *
 * UEBERSCHREIBT BEIDE DOKUMENTE OHNE RUECKFRAGE. Das ist ausdruecklich so
 * gewollt: Der Lauf gehoert ans Ende eines Deployments und stellt Vorlage
 * und Signatur als EINEN atomaren Release her. Editor-Arbeit geht dabei
 * verloren. Wer sie behalten will, setzt beim Lauf
 *
 *   RT_MAIL_STARTER_KEEP=1
 *
 * Dann bleiben alle vorhandenen Dokumente unangetastet und nur fehlende
 * werden angelegt.
 *
 * WAS DIE FREIGABE BEWIRKT — UND WAS NICHT:
 * Sie wirkt auf die DOWNLOADS (Vorlage und Signaturdateien) und auf die
 * Vorschau. Eine VERSENDETE Systemmail folgt dagegen immer der
 * Blade-Quelle, also dem ausgelieferten Code (Begruendung in
 * App\Support\MailSignature::render — die Systemmail braucht den Zug als
 * Bildzeile, die ein gespeicherter Markup-Stand nicht tragen kann). Nach
 * diesem Lauf zeigen beide Wege denselben Stand, weil die Startfassung aus
 * genau derselben Blade-Quelle erzeugt wird.
 */
final class MailDocumentSeeder extends Seeder
{
    /**
     * Kennzeichen der ausgelieferten Startfassung. Bei jeder Erneuerung des
     * Startlayouts hochzaehlen, sonst frisst der naechste Auffrischungslauf
     * dieselben Zeilen erneut.
     *
     *   1  Erstauslieferung
     *   2  Symmetrische Signatur (Person links, Firma rechts, Wortmarke
     *      ohne Zeichen, Anschrift einmal) + RT-Zeichen in der Vorlage
     *   3  Sequentiell aufgebaute Logo-/Wortmarkenanimationen
     */
    private const STARTER_SCHEMA = 3;

    public function run(): void
    {
        $actorId = $this->actorId();

        // Beide Quellen werden vorbereitet und gehaertet, bevor die erste
        // Datenbankzeile geschrieben wird. So kann kein halber Release
        // entstehen, wenn etwa die neue Signatur ungueltig ist.
        $release = [];
        foreach (MailDocumentKind::cases() as $kind) {
            $html = $this->starterHtml($kind);
            // Das Markup traegt seine Formatierung im style-Attribut und im
            // eigenen <style>-Block; eine getrennte Stilebene entsteht erst,
            // wenn jemand im Editor arbeitet.
            $css = '';
            $builderData = $this->starterBuilderData($kind, $html);

            // Gehaertet wie im Editor: was dort nicht freigegeben werden
            // duerfte, darf es hier auch nicht. Schlaegt die Pruefung fehl,
            // bricht der Lauf mit der Meldung des Editors ab statt
            // stillschweigend etwas Unerlaubtes zu veroeffentlichen.
            $geprueft = app(EmailHtmlSanitizer::class)->assertClean($html)->html;

            $release[$kind->value] = [
                'kind' => $kind,
                'status' => MailDocumentStatus::Published,
                'builder_data' => $builderData,
                'html' => $geprueft,
                'css' => $css,
                'content_hash' => MailDocument::contentHashFor($builderData, $geprueft, $css),
                // SOFORT FREIGEGEBEN: Downloads und Vorschau zeigen den
                // neuen Stand ohne weiteren Handgriff im Editor.
                'published_html' => $geprueft,
                'published_css' => $css,
                'published_at' => null,
            ];

        }

        /** @var list<MailDocumentKind> $releasedKinds */
        $releasedKinds = DB::transaction(function () use ($actorId, $release): array {
            $documents = MailDocument::query()
                ->whereIn('kind', array_keys($release))
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy(fn (MailDocument $document): string => $document->kind->value);
            $released = [];

            foreach (MailDocumentKind::cases() as $kind) {
                /** @var MailDocument|null $document */
                $document = $documents->get($kind->value);

                if ($document instanceof MailDocument && $this->behalten($document, $kind)) {
                    continue;
                }

                $werte = $release[$kind->value];
                $werte['published_at'] = now();
                $werte['updated_by'] = $actorId;

                if ($document instanceof MailDocument) {
                    $currentBuilderData = is_array($document->builder_data)
                        ? $document->builder_data
                        : [];
                    $currentContentHash = MailDocument::contentHashFor(
                        $currentBuilderData,
                        (string) $document->html,
                        (string) $document->css,
                    );
                    $contentChanged = ! hash_equals(
                        (string) $werte['content_hash'],
                        $currentContentHash,
                    );
                    $alreadyReleased = ! $contentChanged
                        && $document->status === MailDocumentStatus::Published
                        && $document->published_at !== null
                        && trim((string) $document->published_html) === trim((string) $werte['published_html'])
                        && trim((string) $document->published_css) === trim((string) $werte['published_css'])
                        && hash_equals((string) $document->content_hash, (string) $werte['content_hash']);

                    if ($alreadyReleased) {
                        $this->command?->info(
                            "Maildokument \"{$kind->value}\" ist bereits als Startfassung ".self::STARTER_SCHEMA.' veroeffentlicht.'
                        );

                        continue;
                    }

                    // Eine Version bezeichnet einen Inhaltsstand. Nur ein
                    // echter Inhaltswechsel zaehlt hoch; die erneute Freigabe
                    // desselben Arbeitsstands bleibt versionsneutral.
                    $werte['version'] = $contentChanged
                        ? max(1, (int) $document->version) + 1
                        : max(1, (int) $document->version);

                    $document->forceFill($werte)->save();
                    $this->command?->info(
                        "Maildokument \"{$kind->value}\" als Version {$werte['version']} freigegeben (Startfassung ".self::STARTER_SCHEMA.').'
                    );
                } else {
                    $werte['version'] = 1;
                    MailDocument::query()->create($werte + ['created_by' => $actorId]);
                    $this->command?->info(
                        "Maildokument \"{$kind->value}\" als Version 1 angelegt und freigegeben (Startfassung ".self::STARTER_SCHEMA.').'
                    );
                }

                $released[] = $kind;
            }

            return $released;
        });

        // Erst nach dem Commit die pro Anfrage gehaltene Momentaufnahme
        // vergessen. Ein Rollback kann dadurch keinen Cache-Mischstand bauen.
        foreach ($releasedKinds as $kind) {
            app(PublishedMailDocumentSnapshotStore::class)->forget($kind);
        }
    }

    /**
     * Soll ein vorhandenes Dokument stehen bleiben?
     *
     * Standard ist NEIN — der Lauf stellt einen bekannten Zustand her. Nur
     * mit RT_MAIL_STARTER_KEEP=1 bleibt Editor-Arbeit erhalten.
     */
    private function behalten(MailDocument $document, MailDocumentKind $kind): bool
    {
        if (! $this->schonend()) {
            return false;
        }

        $this->command?->warn(
            "Maildokument \"{$kind->value}\" bleibt unangetastet (RT_MAIL_STARTER_KEEP=1). "
            .'Ohne diese Kennzeichnung wuerde der Lauf es auf die Startfassung setzen und freigeben.'
        );

        return true;
    }

    /**
     * Startinhalt als Token-HTML — die {{PLATZHALTER}} bleiben stehen.
     *
     * Sie sind der Grund, warum ein einziges Dokument genuegt: Palette (hell
     * und dunkel), Profilwerte und Bildquellen werden erst beim Rendern
     * eingesetzt.
     */
    private function starterHtml(MailDocumentKind $kind): string
    {
        $html = match ($kind) {
            MailDocumentKind::Template => (string) file_get_contents(
                EmailTemplateBuilder::masterPath('email-master.html')
            ),
            MailDocumentKind::Signature => $this->signatureStarterHtml(),
        };

        $html = trim($html);
        if ($html === '') {
            throw new RuntimeException(
                "Das Maildokument \"{$kind->value}\" hat keinen Startinhalt: die Quelle ist leer."
            );
        }

        return $html;
    }

    /**
     * Der Signaturblock aus derselben einzigen Quelle wie Downloads und
     * Systemmails — nur mit Platzhaltern statt Werten.
     *
     * Bewusst nicht ueber MailSignature::render(): dort raeumt
     * stripEmptyContactRows() am Ende ALLE RT_*-Marker weg. Ein Dokument mit
     * Platzhaltern braucht sie aber noch, weil erst nach dem Einsetzen der
     * Werte feststeht, welche Kontaktzeile leer bleibt.
     */
    private function signatureStarterHtml(): string
    {
        $tokens = [];
        foreach (array_keys(MailSignature::forCompany()->values()) as $key) {
            $tokens[$key] = '{{'.$key.'}}';
        }

        // FRUEHER STAND HIER EINE AUSNAHME fuer TRAIN_IDLE_SRC: der Wert
        // wurde geleert, weil ein Platzhalter die Ebene immer eingeschaltet
        // haette und sie auf nicht animierten Wegen in ein leeres url()
        // gelaufen waere. Die Ruhefahne wird inzwischen IMMER gesetzt
        // (MailSignature::values), damit entfaellt der Grund — und mit der
        // Ausnahme fehlte die Ebene in jeder veroeffentlichten Fassung.

        return View::make('emails.parts.signature', ['values' => $tokens])->render();
    }

    /**
     * @return array<string, mixed>
     */
    private function starterBuilderData(MailDocumentKind $kind, string $html): array
    {
        return [
            'pages' => [[
                'name' => $kind->label(),
                'component' => $html,
            ]],
            'styles' => [],
            'railtime' => [
                'document' => $kind->value,
                'schema' => self::STARTER_SCHEMA,
            ],
        ];
    }

    /**
     * Ausdrueckliche Anweisung, vorhandene Dokumente NICHT zu ueberschreiben:
     *
     *   RT_MAIL_STARTER_KEEP=1 php artisan db:seed --class=MailDocumentSeeder --force
     *
     * Ohne sie stellt der Lauf den ausgelieferten Zustand beider Dokumente
     * atomar her und gibt ihn frei — das ist der Sinn des Aufrufs am Ende
     * eines Deployments.
     */
    private function schonend(): bool
    {
        return filter_var(env('RT_MAIL_STARTER_KEEP'), FILTER_VALIDATE_BOOL);
    }

    /**
     * Ersteller ist der erste Administrator, falls es schon einen gibt. Ohne
     * Administrator bleiben die Spalten leer — die Dokumente selbst sind
     * Systeminhalt und haengen an niemandem.
     */
    private function actorId(): ?int
    {
        return User::query()->where('role', 'admin')->orderBy('id')->value('id');
    }
}
