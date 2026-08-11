<?php

namespace Database\Seeders;

use App\Enums\MailDocumentKind;
use App\Enums\MailDocumentStatus;
use App\Models\MailDocument;
use App\Models\User;
use App\Support\EmailTemplateBuilder;
use App\Support\MailSignature;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\View;
use RuntimeException;

/**
 * Legt die beiden Maildokumente mit ihrem Startinhalt an UND frischt ein
 * unberuehrtes Startlayout auf den aktuellen Stand auf.
 *
 * Auffrischung nur fuer UNBERUEHRTE Starter (isUntouchedStarter): sobald
 * jemand im Editor gespeichert oder veroeffentlicht hat, steigt version
 * ueber 1 und der Seeder laesst die Zeile unangetastet — Admin-Arbeit wird
 * nie ueberschrieben. Ein veroeffentlichtes Dokument meldet der Seeder als
 * Hinweis: dort muss die neue Fassung bewusst im Editor uebernommen und
 * erneut veroeffentlicht werden.
 *
 * Serverseitiger Aufruf nach einem Deployment:
 *
 *   php artisan db:seed --class=MailDocumentSeeder --force
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

            if ($document instanceof MailDocument) {
                $this->refresh($document, $kind, $actorId);

                continue;
            }

            $html = $this->starterHtml($kind);
            // Das Markup traegt seine Formatierung im style-Attribut und im
            // eigenen <style>-Block; eine getrennte Stilebene entsteht erst,
            // wenn jemand im Editor arbeitet.
            $css = '';
            $builderData = $this->starterBuilderData($kind, $html);

            MailDocument::query()->create([
                'kind' => $kind,
                'status' => MailDocumentStatus::Draft,
                'builder_data' => $builderData,
                'html' => $html,
                'css' => $css,
                'content_hash' => MailDocument::contentHashFor($builderData, $html, $css),
                'version' => 1,
                'created_by' => $actorId,
                'updated_by' => $actorId,
            ]);
        }
    }

    /**
     * Hebt ein vorhandenes Dokument auf die aktuelle Startfassung — aber
     * NUR, wenn es noch das unberuehrte Startlayout einer aelteren Stufe
     * traegt. Alles andere ist Admin-Arbeit und bleibt stehen.
     */
    private function refresh(MailDocument $document, MailDocumentKind $kind, ?int $actorId): void
    {
        $schema = (int) data_get($document->builder_data, 'railtime.schema', 0);

        if ($schema >= self::STARTER_SCHEMA) {
            return;
        }

        if (! $document->isUntouchedStarter($schema) && ! $this->erzwungen()) {
            $this->command?->warn(
                "Maildokument \"{$kind->value}\" wurde im Editor bearbeitet und bleibt unangetastet. "
                .'Die neue Startfassung muss dort bewusst uebernommen und erneut veroeffentlicht werden — '
                .'oder der Lauf wird mit RT_MAIL_STARTER_FORCE=1 wiederholt.'
            );

            return;
        }

        // FREIGEGEBEN IST NICHT UNBERUEHRT. Wer einen Starter unveraendert
        // veroeffentlicht, aendert seinen Inhalt nicht — publish() zaehlt
        // version deshalb NICHT hoch (MailDocumentController::publish, der
        // Vergleich der content_hash). isUntouchedStarter() haelt so ein
        // Dokument fuer unberuehrt, und die Auffrischung unten wuerde es
        // stillschweigend auf Entwurf zuruecksetzen: die Downloads fielen
        // ohne Vorwarnung auf die Blade-Quelle zurueck. Das Freigeben ist
        // eine bewusste Handlung und wird deshalb wie Editor-Arbeit
        // behandelt.
        if ($document->isPublished() && ! $this->erzwungen()) {
            $this->command?->warn(
                "Maildokument \"{$kind->value}\" ist freigegeben und bleibt unangetastet. "
                .'Die neue Startfassung muss im Editor bewusst uebernommen und erneut freigegeben werden — '
                .'oder der Lauf wird mit RT_MAIL_STARTER_FORCE=1 wiederholt.'
            );

            return;
        }

        $html = $this->starterHtml($kind);
        $builderData = $this->starterBuilderData($kind, $html);

        $document->forceFill([
            'status' => MailDocumentStatus::Draft,
            'builder_data' => $builderData,
            'html' => $html,
            'css' => '',
            // Eine liegengebliebene Freigabe wuerde sonst als Momentaufnahme
            // weiter die alte Signatur in jeden DOWNLOAD setzen. (Auf
            // versendete Mails wirkt sie ohnehin nicht — die folgen der
            // Blade-Quelle, siehe MailSignature::render.) Hierher kommt nur,
            // wer entweder gar nicht freigegeben hat oder ausdruecklich
            // erzwingt.
            'published_html' => null,
            'published_css' => null,
            'published_at' => null,
            'content_hash' => MailDocument::contentHashFor($builderData, $html, ''),
            // Version bleibt 1: das Dokument ist weiterhin ein unberuehrter
            // Starter — nur eben der aktuellen Stufe. Der naechste Lauf
            // erkennt das am Schema-Kennzeichen und laesst es in Ruhe.
            'updated_by' => $actorId ?? $document->updated_by,
        ])->save();

        $this->command?->info("Maildokument \"{$kind->value}\" auf Startfassung ".self::STARTER_SCHEMA.' aufgefrischt.');
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

        // Ausnahme Standrauch: ein nicht leerer Wert schaltet im Blade eine
        // zusaetzliche Hintergrundebene frei. Als Platzhalter waere sie immer
        // an und liefe auf den nicht animierten Wegen (Systemmail,
        // Vorlagen-Download) in ein url('') ins Leere.
        $tokens['TRAIN_IDLE_SRC'] = '';

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
     * Ausdrueckliche Anweisung, auch bearbeitete Dokumente zu ueberschreiben:
     *
     *   RT_MAIL_STARTER_FORCE=1 php artisan db:seed --class=MailDocumentSeeder --force
     *
     * Bewusst NUR ueber die Umgebung und nie Standard — der Lauf verwirft
     * Editor-Arbeit einschliesslich einer veroeffentlichten Fassung.
     */
    private function erzwungen(): bool
    {
        return filter_var(env('RT_MAIL_STARTER_FORCE'), FILTER_VALIDATE_BOOL);
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
