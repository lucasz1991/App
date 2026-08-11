<?php

namespace App\Http\Requests\Marketing;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class RedesignMarketingCreativeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->isAdmin();
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'preset' => ['required', 'string', Rule::in(['railtime_modern'])],
            'expected_hashes' => ['required', 'array:story,post,web'],
            'expected_hashes.story' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/i'],
            'expected_hashes.post' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/i'],
            'expected_hashes.web' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/i'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $hashes = $this->input('expected_hashes');
        if (is_array($hashes)) {
            $this->merge([
                'expected_hashes' => array_map(
                    static fn (mixed $hash): mixed => is_string($hash) ? strtolower($hash) : $hash,
                    $hashes,
                ),
            ]);
        }
    }
}
