<?php

namespace Database\Seeders;

use App\Enums\MailDocumentKind;
use App\Enums\MailDocumentStatus;
use App\Models\MailDocument;
use App\Models\User;
use App\Support\CompanyData;
use App\Support\EmailTemplateBuilder;
use App\Support\Mail\EmailHtmlSanitizer;
use App\Support\Mail\PublishedMailDocumentSnapshotStore;
use App\Support\Mail\SignatureDocumentContract;
use App\Support\Mail\TemplateDocumentContract;
use App\Support\MailSignature;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;
use RuntimeException;

/**
 * Stellt nach einem Deployment genau zwei kanonische Maildokumente her.
 *
 *   php artisan db:seed --class=MailDocumentSeeder --force
 *
 * Der Lauf ist bewusst autoritativ: Entwürfe, Zwischenstände und unbekannte
 * Maildokument-Zeilen werden verworfen. Vorlage und Signatur werden vor dem
 * ersten Write vollständig vorbereitet und anschließend in einer gemeinsamen
 * Transaktion als veröffentlichte Version 1 gespeichert. Danach verwenden
 * Downloads, Vorschauen und versendete Laravel-Mails denselben Stand.
 */
final class MailDocumentSeeder extends Seeder
{
    /**
     * Kennzeichen der ausgelieferten Startfassung.
     *
     * 1  Erstauslieferung
     * 2  Symmetrische Signatur und RT-Zeichen in der Vorlage
     * 3  Sequentiell aufgebaute Logo-/Wortmarkenanimationen
     * 4  Kanonischer APPLICATION_CONTENT-Slot für Systemmails
     * 5  Antwortsichere Einzelelemente für Wortmarke und Firmenkontakte
     * 6  Vollständiges Outlook-Standbild für das animierte RT-Zeichen
     * 7  Zug-Carrier ohne Außenpadding für eine bündige Rad-/Footerkante
     * 8  Kontaktinhalt und reguläre Zugzeile ohne künstlichen Zwischenraum
     * 9  Zug in jeder finalen Ausgabe als kopierbares reguläres GIF-Bild
     * 10 Zug bereits im kanonischen Seeder-/Editorstand als regulaeres IMG
     * 11 Zug-IMG im absoluten, hoehenneutralen Layer hinter den Kontaktdaten
     * 12 Mail-sichere Presets fuer Zugbreite, Desktop- und Mobil-Ausschnitt
     * 13 Stabiler Block-Positionierungskontext und Outlook-Zugfallback
     * 14 Zugmotiv bei 60 Prozent, kompakte 1,5x-Assets und dauerhafter Idle-Rauch
     * 15 Vorschau-/Outlook-Paritaet ohne Prozenthoehe am absoluten Zug-Layer
     * 16 Mail-sicherer IMG-Fluss fuer identische Editor-/Outlook-Geometrie
     * 17 Hoehenneutraler absoluter IMG-Layer hinter den Kontaktdaten
     * 18 Signatur ohne Raster und grosses RT-Hintergrundwasserzeichen
     */
    private const STARTER_SCHEMA = 18;

    public function run(): void
    {
        $actorId = $this->actorId();

        // Beide Quellen werden gehärtet, bevor die Transaktion beginnt. Eine
        // ungültige zweite Quelle kann damit niemals einen halben Release
        // hinterlassen.
        $release = [];
        foreach (MailDocumentKind::cases() as $kind) {
            $html = $this->starterHtml($kind);
            $css = '';
            $geprüft = app(EmailHtmlSanitizer::class)->assertClean($html)->html;
            match ($kind) {
                MailDocumentKind::Template => TemplateDocumentContract::assertValid($geprüft),
                MailDocumentKind::Signature => SignatureDocumentContract::assertValid($geprüft),
            };
            $builderData = $this->starterBuilderData($kind, $geprüft);

            $release[$kind->value] = [
                'kind' => $kind,
                'status' => MailDocumentStatus::Published,
                'builder_data' => $builderData,
                'html' => $geprüft,
                'css' => $css,
                'content_hash' => MailDocument::contentHashFor($builderData, $geprüft, $css),
                'published_html' => $geprüft,
                'published_css' => $css,
                'published_at' => null,
                'version' => 1,
            ];
        }

        DB::transaction(function () use ($actorId, $release): void {
            $canonicalKinds = array_map(
                static fn (MailDocumentKind $kind): string => $kind->value,
                MailDocumentKind::cases(),
            );

            // Query Builder statt Eloquent: ein fremder kind-Wert würde beim
            // Enum-Cast bereits das Laden der Sammlung abbrechen.
            DB::table('mail_documents')
                ->whereNotIn('kind', $canonicalKinds)
                ->delete();

            $documents = MailDocument::query()
                ->whereIn('kind', $canonicalKinds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy(fn (MailDocument $document): string => $document->kind->value);

            foreach (MailDocumentKind::cases() as $kind) {
                /** @var MailDocument|null $document */
                $document = $documents->get($kind->value);
                $values = $release[$kind->value];
                $values['published_at'] = now();
                $values['updated_by'] = $actorId;

                if ($document instanceof MailDocument) {
                    $document->forceFill($values)->save();
                } else {
                    MailDocument::query()->create($values + ['created_by' => $actorId]);
                }

                $this->command?->info(
                    "Maildokument \"{$kind->value}\" als einzige kanonische Version 1 freigegeben (Startfassung ".self::STARTER_SCHEMA.').'
                );
            }
        });

        // Erst nach erfolgreichem Commit vergessen. Ein Rollback lässt damit
        // auch die request-/job-lokale Momentaufnahme konsistent unverändert.
        foreach (MailDocumentKind::cases() as $kind) {
            app(PublishedMailDocumentSnapshotStore::class)->forget($kind);
        }
    }

    /** Token-HTML; die Werte werden erst im jeweiligen Ausgabeweg eingesetzt. */
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
     * Signaturblock aus derselben Quelle wie Downloads und Systemmails, aber
     * mit Platzhaltern statt Benutzer- beziehungsweise Firmenwerten.
     */
    private function signatureStarterHtml(): string
    {
        $tokens = [];
        foreach (array_keys(MailSignature::forCompany()->values([], CompanyData::defaults())) as $key) {
            $tokens[$key] = '{{'.$key.'}}';
        }

        return View::make('emails.parts.signature', ['values' => $tokens])->render();
    }

    /** @return array<string, mixed> */
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

    /** Systeminhalt kann auch vor dem ersten Administratorkonto existieren. */
    private function actorId(): ?int
    {
        return User::query()->where('role', 'admin')->orderBy('id')->value('id');
    }
}
