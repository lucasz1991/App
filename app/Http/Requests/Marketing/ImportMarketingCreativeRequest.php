<?php

namespace App\Http\Requests\Marketing;

use Illuminate\Foundation\Http\FormRequest;

final class ImportMarketingCreativeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->isAdmin();
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'bundle' => [
                'required',
                'file',
                'extensions:json',
                'mimetypes:application/json,text/json,text/plain,application/octet-stream',
                'max:32768',
            ],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'bundle.required' => 'Bitte wähle ein RailTime-Motivpaket aus.',
            'bundle.file' => 'Das ausgewählte Motivpaket ist keine lesbare Datei.',
            'bundle.extensions' => 'Motivpakete müssen als JSON-Datei vorliegen.',
            'bundle.mimetypes' => 'Die Datei ist kein unterstütztes JSON-Motivpaket.',
            'bundle.max' => 'Das Motivpaket darf höchstens 32 MiB groß sein.',
        ];
    }
}
