<?php

namespace App\Services\Marketing;

use Illuminate\Support\Facades\Validator as ValidatorFacade;
use Illuminate\Validation\Validator;

final class MarketingSharedContentSchema
{
    public const MAX_JSON_BYTES = 100_000;

    /** @var list<string> */
    private const KEYS = [
        'contact_phone',
        'contact_email',
        'website',
        'company_name',
        'company_address',
        'template_key',
        'seed_version',
        'preferred_preview_format',
        'import_source_template_key',
        'kicker',
        'title',
        'subtitle',
        'intro',
        'facts',
        'tasks',
        'profile',
        'benefits',
        'cta_label',
        'cta_url',
        'editorial_note',
    ];

    /** @return array<string, mixed> */
    public static function rules(string $prefix = 'shared_content'): array
    {
        return [
            $prefix => ['required', 'array:'.implode(',', self::KEYS)],
            $prefix.'.kicker' => ['nullable', 'string', 'max:80'],
            $prefix.'.title' => ['nullable', 'string', 'max:180'],
            $prefix.'.subtitle' => ['nullable', 'string', 'max:220'],
            $prefix.'.intro' => ['nullable', 'string', 'max:1000'],
            $prefix.'.facts' => ['nullable', 'array', 'max:6'],
            $prefix.'.facts.*' => ['required', 'array:value,label'],
            $prefix.'.facts.*.value' => ['nullable', 'string', 'max:24'],
            $prefix.'.facts.*.label' => ['nullable', 'string', 'max:80'],
            $prefix.'.tasks' => ['nullable', 'array', 'max:12'],
            $prefix.'.tasks.*' => ['nullable', 'string', 'max:240'],
            $prefix.'.profile' => ['nullable', 'array', 'max:12'],
            $prefix.'.profile.*' => ['nullable', 'string', 'max:240'],
            $prefix.'.benefits' => ['nullable', 'array', 'max:12'],
            $prefix.'.benefits.*' => ['nullable', 'string', 'max:240'],
            $prefix.'.cta_label' => ['nullable', 'string', 'max:80'],
            $prefix.'.cta_url' => ['nullable', 'url:http,https', 'max:500'],
            $prefix.'.contact_phone' => ['nullable', 'string', 'max:80'],
            $prefix.'.contact_email' => ['nullable', 'email:rfc', 'max:190'],
            $prefix.'.website' => ['nullable', 'string', 'max:190'],
            $prefix.'.company_name' => ['nullable', 'string', 'max:190'],
            $prefix.'.company_address' => ['nullable', 'string', 'max:500'],
            $prefix.'.template_key' => ['nullable', 'string', 'max:190'],
            $prefix.'.seed_version' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            $prefix.'.preferred_preview_format' => ['nullable', 'string', 'in:story,post,web'],
            $prefix.'.import_source_template_key' => ['nullable', 'string', 'max:190'],
            $prefix.'.editorial_note' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /** @return array<string, mixed> */
    public static function validate(array $content): array
    {
        $validator = ValidatorFacade::make(
            ['shared_content' => $content],
            self::rules(),
        );
        $validator->after(static function (Validator $validator) use ($content): void {
            self::addSizeError($validator, $content);
        });

        return $validator->validate()['shared_content'];
    }

    public static function addSizeError(
        Validator $validator,
        mixed $content,
        string $attribute = 'shared_content',
    ): void {
        $encoded = json_encode($content);
        if ($encoded === false || strlen($encoded) > self::MAX_JSON_BYTES) {
            $validator->errors()->add($attribute, 'Die gemeinsamen Motivinhalte sind zu umfangreich.');
        }
    }
}
