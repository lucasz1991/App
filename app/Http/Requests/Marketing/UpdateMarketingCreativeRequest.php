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
}
