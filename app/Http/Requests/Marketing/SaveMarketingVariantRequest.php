<?php

namespace App\Http\Requests\Marketing;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class SaveMarketingVariantRequest extends FormRequest
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
