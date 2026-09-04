<?php

namespace App\Http\Requests\Mail;

/** Portables v2-Bundle, das einen vorhandenen Arbeitsentwurf ersetzt. */
final class ReplaceMailDocumentDraftRequest extends ImportMailDocumentRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'expected_hash' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/i'],
        ];
    }

    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();

        if (is_string($this->input('expected_hash'))) {
            $this->merge([
                'expected_hash' => strtolower(trim($this->string('expected_hash')->toString())),
            ]);
        }
    }
}
