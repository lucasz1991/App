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
use Illuminate\Support\Facades\View;
use RuntimeException;

/**
 * Setzt die beiden Maildokumente auf die ausgelieferte Startfassung — und
 * GIBT SIE SOFORT FREI, damit unmittelbar danach eine Systemnachricht
 * geprueft werden kann.
 *
 *   php artisan db:seed --class=MailDocumentSeeder --force
 *
 * UEBERSCHREIBT OHNE RUECKFRAGE. Das ist ausdruecklich so gewollt: der
 * Lauf gehoert ans Ende eines Deployments und stellt einen bekannten,
 * pruefbaren Zustand her. Editor-Arbeit am Maildokument geht dabei
 * verloren. Wer sie behalten will, setzt beim Lauf
 *
 *   RT_MAIL_STARTER_KEEP=1
 *
 * dann bleiben bearbeitete oder freigegebene Dokumente unangetastet und
 * nur fehlende werden angelegt.
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
     */
    private const STARTER_SCHEMA = 2;

    public function run(): void
    {
        $actorId = $this->actorId();

        foreach (MailDocumentKind::cases() as $kind) {
            $document = MailDocument::query()->where('kind', $kind->value)->first();

            if ($document instanceof MailDocument && $this->behalten($document, $kind)) {
                continue;
            }

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

            $werte = [
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
                'published_at' => now(),
                'version' => 1,
                'updated_by' => $actorId,
            ];

            if ($document instanceof MailDocument) {
                $document->forceFill($werte)->save();
                $this->command?->info("Maildokument \"{$kind->value}\" ueberschrieben und freigegeben (Startfassung ".self::STARTER_SCHEMA.').');
            } else {
                MailDocument::query()->create($werte + ['created_by' => $actorId]);
                $this->command?->info("Maildokument \"{$kind->value}\" angelegt und freigegeben (Startfassung ".self::STARTER_SCHEMA.').');
            }

            // Die Momentaufnahme lebt pro Anfrage. Ohne dieses Vergessen
            // liefe der Rest DIESES Laufs — etwa eine gleich danach
            // gerenderte Probemail — noch auf dem alten Stand.
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

        $schema = (int) data_get($document->builder_data, 'railtime.schema', 0);

        if ($document->isUntouchedStarter($schema) && ! $document->isPublished() && $schema < self::STARTER_SCHEMA) {
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
     * Ohne sie stellt der Lauf den ausgelieferten Zustand her und gibt ihn
     * frei — das ist der Sinn des Aufrufs am Ende eines Deployments.
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
