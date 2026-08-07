<?php

namespace App\Http\Requests\Marketing;

use Illuminate\Foundation\Http\FormRequest;

final class StoreMarketingAssetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->isAdmin();
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $mimeTypes = config('marketing.assets.allowed_mime_types', [
            'image/jpeg', 'image/png', 'image/webp', 'image/gif',
        ]);

        return [
            'file' => [
                'required',
                'file',
                'max:'.max(1, (int) config('marketing.assets.max_kilobytes', 8192)),
                'mimetypes:'.implode(',', is_array($mimeTypes) ? $mimeTypes : []),
            ],
        ];
    }
}
