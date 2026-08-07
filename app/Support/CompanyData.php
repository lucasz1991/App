<?php

namespace App\Support;

use App\Models\Setting;

class CompanyData
{
    /**
     * @return array<string, string>
     */
    public static function defaults(): array
    {
        return [
            'name' => 'RT Rail Time GmbH',
            'street' => 'Borsteler Weg 29–31',
            'postal_code' => '21423',
            'city' => 'Winsen (Luhe)',
            'country' => 'Deutschland',
            'phone' => '04171 546803',
            'emergency_phone' => '04171 546803',
            'email' => 'info@rail-time.de',
            'website' => 'https://www.rail-time.de',
            'managing_directors' => 'Durim Morina und Mazan Moslehe',
            'register_court' => 'Amtsgericht Tostedt',
            'commercial_register_number' => '204604',
            'vat_id' => 'DE169651368',
            'tax_number' => '',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function all(bool $uncached = false): array
    {
        $stored = $uncached
            ? Setting::getValueUncached('company', 'profile')
            : Setting::getValue('company', 'profile');

        if (! is_array($stored)) {
            $stored = [];
        }

        return collect(static::defaults())
            ->mapWithKeys(fn (string $default, string $key) => [
                $key => trim((string) ($stored[$key] ?? $default)),
            ])
            ->all();
    }

    public static function save(array $values): void
    {
        $normalized = collect(static::defaults())
            ->mapWithKeys(fn (string $default, string $key) => [
                $key => trim((string) ($values[$key] ?? $default)),
            ])
            ->all();

        Setting::setValue('company', 'profile', $normalized);
    }

    public static function addressLine(?array $company = null): string
    {
        $company ??= static::all();

        return implode(' · ', array_filter([
            $company['street'],
            trim($company['postal_code'].' '.$company['city']),
            $company['country'],
        ]));
    }

    /**
     * Values used by the downloadable HTML/EML templates.
     *
     * @return array<string, string>
     */
    public static function templateValues(?array $company = null): array
    {
        $company ??= static::all();

        return [
            'FIRMENNAME' => $company['name'],
            'FIRMENSTRASSE' => $company['street'],
            'FIRMEN_PLZ_ORT' => trim($company['postal_code'].' '.$company['city']),
            'FIRMENLAND' => $company['country'],
            'FIRMEN_TELEFON' => $company['phone'],
            'NOTFALLNUMMER' => $company['emergency_phone'],
            'FIRMEN_EMAIL' => $company['email'],
            'FIRMEN_WEBSITE' => $company['website'],
            'GESCHAEFTSFUEHRUNG' => $company['managing_directors'],
            'REGISTERGERICHT' => $company['register_court'],
            'HRB' => $company['commercial_register_number'],
            'UST_ID' => $company['vat_id'],
            'STEUERNUMMER' => $company['tax_number'],
        ];
    }
}
