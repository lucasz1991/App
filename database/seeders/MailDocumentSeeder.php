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
 * Legt die beiden Maildokumente mit ihrem Startinhalt an.
 *
 * Der Seeder legt NUR an, was fehlt. Ein vorhandenes Dokument wird nie
 * angefasst — auch dann nicht, wenn es noch das Startlayout traegt: eine
 * spaetere Auffrischung ist Sache einer Migration, die dafuer version 1 UND
 * railtime.schema prueft (Muster aus 2026_08_07_000300).
 */
final class MailDocumentSeeder extends Seeder
{
    /**
     * Kennzeichen der ausgelieferten Startfassung. Wird das Startlayout
     * spaeter erneuert, muss dieser Zaehler mit hochgezogen werden, sonst
     * frisst der naechste Auffrischungslauf dieselben Zeilen erneut.
     */
    private const STARTER_SCHEMA = 1;

    public function run(): void
    {
        $actorId = $this->actorId();

        foreach (MailDocumentKind::cases() as $kind) {
            if (MailDocument::query()->where('kind', $kind->value)->exists()) {
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
     * Ersteller ist der erste Administrator, falls es schon einen gibt. Ohne
     * Administrator bleiben die Spalten leer — die Dokumente selbst sind
     * Systeminhalt und haengen an niemandem.
     */
    private function actorId(): ?int
    {
        return User::query()->where('role', 'admin')->orderBy('id')->value('id');
    }
}
