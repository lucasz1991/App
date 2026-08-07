<?php

namespace App\Http\Requests\Marketing;

use App\Enums\MarketingCreativeType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreMarketingCreativeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->isAdmin();
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'type' => ['required', Rule::enum(MarketingCreativeType::class)],
        ];
    }
}
