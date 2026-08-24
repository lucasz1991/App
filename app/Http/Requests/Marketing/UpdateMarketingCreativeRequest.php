<?php

namespace App\Http\Requests\Marketing;

use App\Services\Marketing\MarketingSharedContentSchema;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class UpdateMarketingCreativeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->isAdmin();
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return array_merge([
            'title' => ['required', 'string', 'max:160'],
            'expected_hashes' => ['required', 'array:story,post,web'],
            'expected_hashes.story' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/i'],
            'expected_hashes.post' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/i'],
            'expected_hashes.web' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/i'],
        ], MarketingSharedContentSchema::rules());
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                MarketingSharedContentSchema::addSizeError(
                    $validator,
                    $this->input('shared_content', []),
                );
            },
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
