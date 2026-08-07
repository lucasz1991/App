<?php

namespace App\Http\Requests\Marketing;

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
        return [
            'title' => ['required', 'string', 'max:160'],
            'shared_content' => ['required', 'array'],
            'expected_hashes' => ['required', 'array:story,post,web'],
            'expected_hashes.story' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/i'],
            'expected_hashes.post' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/i'],
            'expected_hashes.web' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/i'],
            'shared_content.kicker' => ['nullable', 'string', 'max:80'],
            'shared_content.title' => ['nullable', 'string', 'max:180'],
            'shared_content.subtitle' => ['nullable', 'string', 'max:220'],
            'shared_content.intro' => ['nullable', 'string', 'max:1000'],
            'shared_content.facts' => ['nullable', 'array', 'max:6'],
            'shared_content.facts.*.value' => ['nullable', 'string', 'max:24'],
            'shared_content.facts.*.label' => ['nullable', 'string', 'max:80'],
            'shared_content.tasks' => ['nullable', 'array', 'max:12'],
            'shared_content.tasks.*' => ['nullable', 'string', 'max:240'],
            'shared_content.profile' => ['nullable', 'array', 'max:12'],
            'shared_content.profile.*' => ['nullable', 'string', 'max:240'],
            'shared_content.benefits' => ['nullable', 'array', 'max:12'],
            'shared_content.benefits.*' => ['nullable', 'string', 'max:240'],
            'shared_content.cta_label' => ['nullable', 'string', 'max:80'],
            'shared_content.cta_url' => ['nullable', 'url:http,https', 'max:500'],
            'shared_content.contact_phone' => ['nullable', 'string', 'max:80'],
            'shared_content.contact_email' => ['nullable', 'email:rfc', 'max:190'],
            'shared_content.website' => ['nullable', 'string', 'max:190'],
            'shared_content.hero_image_url' => ['prohibited'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $encoded = json_encode($this->input('shared_content', []));
                if ($encoded === false || strlen($encoded) > 100_000) {
                    $validator->errors()->add('shared_content', 'Die gemeinsamen Motivinhalte sind zu umfangreich.');
                }
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
