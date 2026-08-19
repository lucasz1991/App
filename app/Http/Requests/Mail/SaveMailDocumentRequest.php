<?php

namespace App\Http\Requests\Mail;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Speicheranfrage des Maildokument-Editors.
 *
 * Bewusst Zeichengleich zum Marketing-Studio (SaveMarketingVariantRequest):
 * derselbe Editor, dieselbe optimistische Sperre, dieselben Grenzen. Der
 * Hash wird schon hier kleingeschrieben, damit der Vergleich im Controller
 * nicht an der Schreibweise scheitert.
 */
final class SaveMailDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->isAdmin();
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'builder_data' => ['required', 'array'],
            // Die Signatur traegt Logo, Zug und fuenf Symbole als Data-URI —
            // ein Maildokument ist deshalb deutlich groesser als eine
            // gewoehnliche Seite.
            'html' => ['required', 'string', 'max:600000'],
            'css' => ['present', 'string', 'max:250000'],
            'expected_hash' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/i'],
            // Nur validateCode() wertet diese Daten aus. Der normale Save
            // sendet keine Medien erneut. Das Bundle transportiert sie
            // base64-kodiert; der Controller prueft Inhalt, MIME und Hash,
            // bevor er sie als oeffentliche, inhaltsadressierte Mailassets
            // ablegt und die Quelladressen im Entwurf ersetzt.
            // 28 feste Systemmedien gehoeren bereits zum vollstaendigen
            // RailTime-Bundle. Die Anzahl darf deshalb eigene, im Dokument
            // referenzierte Bilder nicht auf nur vier Eintraege begrenzen.
            // Die eigentliche Last bleibt durch 2 MiB je Medium und 16 MiB
            // fuer das gesamte Paket strikt gedeckelt.
            'portable_media' => ['sometimes', 'array', 'max:256'],
            'portable_media.*' => ['required', 'array'],
            'portable_media.*.id' => ['required', 'string', 'max:160'],
            'portable_media.*.name' => ['required', 'string', 'max:200'],
            'portable_media.*.source' => ['required', 'string', 'max:2048'],
            'portable_media.*.mime_type' => ['required', 'string', 'in:image/gif,image/png,image/jpeg,image/webp'],
            'portable_media.*.bytes' => ['required', 'integer', 'min:1', 'max:2097152'],
            'portable_media.*.sha256' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/i'],
            'portable_media.*.data' => ['required', 'string', 'max:2796204'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('expected_hash'))) {
            $this->merge(['expected_hash' => strtolower($this->string('expected_hash')->toString())]);
        }

        // Leeres CSS ist hier der REGELFALL: die Mail traegt ihre Formatierung
        // im style-Attribut (avoidInlineStyle: false). ConvertEmptyStringsToNull
        // macht daraus aber null, und die string-Regel schluege fehl.
        if ($this->input('css') === null) {
            $this->merge(['css' => '']);
        }
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $encoded = json_encode($this->input('builder_data', []));

                if ($encoded === false || strlen($encoded) > 1_000_000) {
                    $validator->errors()->add('builder_data', 'Die Builder-Daten sind zu umfangreich.');
                }

                $media = $this->input('portable_media', []);
                if (is_array($media)) {
                    $encodedBytes = array_sum(array_map(
                        static fn ($entry): int => is_array($entry)
                            ? strlen((string) ($entry['data'] ?? ''))
                            : 0,
                        $media,
                    ));
                    if ($encodedBytes > 16 * 1024 * 1024) {
                        $validator->errors()->add('portable_media', 'Das Medienpaket ist groesser als 16 MiB.');
                    }
                }
            },
        ];
    }
}
