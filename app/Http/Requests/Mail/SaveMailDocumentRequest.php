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
            },
        ];
    }
}
