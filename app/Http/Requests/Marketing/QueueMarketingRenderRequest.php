<?php

namespace App\Http\Requests\Marketing;

use App\Enums\MarketingCreativeFormat;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class QueueMarketingRenderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->isAdmin();
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'format' => ['required', Rule::enum(MarketingCreativeFormat::class)],
        ];
    }
}
