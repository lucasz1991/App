<?php

namespace App\Support;

use App\Models\Setting;
use App\Support\OutlookAddin\OutlookAddinSnapshotRefreshScheduler;
use Illuminate\Validation\ValidationException;

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
            'whatsapp_phone' => '+49 160 1881848',
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

        $values = collect(static::defaults())
            ->mapWithKeys(fn (string $default, string $key) => [
                $key => trim((string) ($stored[$key] ?? $default)),
            ])
            ->all();

        // Die Beschriftung "HRB" kommt in allen Mail-/Exportlayouts bereits
        // aus der Vorlage. Altdaten wie "HRB 204604" werden deshalb beim
        // Lesen bereinigt, ohne dafuer die Datenbank umschreiben zu muessen.
        $values['commercial_register_number'] = static::commercialRegisterNumber(
            $values['commercial_register_number']
        );

        return $values;
    }

    public static function save(array $values): void
    {
        if (array_key_exists('whatsapp_phone', $values)) {
            if (! is_string($values['whatsapp_phone']) || ! static::validWhatsappPhone($values['whatsapp_phone'])) {
                throw ValidationException::withMessages([
                    'company.whatsapp_phone' => __('app.company_whatsapp_invalid'),
                ]);
            }
        } else {
            // A caller with an older field list must not reset a configured number.
            $values['whatsapp_phone'] = static::all(uncached: true)['whatsapp_phone'];
        }

        $normalized = collect(static::defaults())
            ->mapWithKeys(fn (string $default, string $key) => [
                $key => trim((string) ($values[$key] ?? $default)),
            ])
            ->all();

        $normalized['commercial_register_number'] = static::commercialRegisterNumber(
            $normalized['commercial_register_number']
        );

        Setting::setValue('company', 'profile', $normalized);

        // Erst nach dem Cache-Reset in Setting::setValue planen, damit der
        // Renderer garantiert die gerade gespeicherten Firmenwerte liest.
        app(OutlookAddinSnapshotRefreshScheduler::class)->scheduleAll();
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

    public static function whatsappHref(?array $company = null): string
    {
        $company ??= static::all();
        $phone = $company['whatsapp_phone'] ?? static::defaults()['whatsapp_phone'];
        $number = is_string($phone) ? static::whatsappNumber($phone) : null;

        return $number === null ? '' : 'https://wa.me/'.$number;
    }

    public static function validWhatsappPhone(string $number): bool
    {
        return static::whatsappNumber($number) !== null;
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
            'FIRMEN_WHATSAPP' => $company['whatsapp_phone'] ?? static::defaults()['whatsapp_phone'],
            'FIRMEN_WHATSAPP_HREF' => static::whatsappHref($company),
            'FIRMEN_EMAIL' => $company['email'],
            'FIRMEN_WEBSITE' => $company['website'],
            'GESCHAEFTSFUEHRUNG' => $company['managing_directors'],
            'REGISTERGERICHT' => $company['register_court'],
            'HRB' => $company['commercial_register_number'],
            'UST_ID' => $company['vat_id'],
            'STEUERNUMMER' => $company['tax_number'],
        ];
    }

    private static function commercialRegisterNumber(string $value): string
    {
        return trim((string) preg_replace('/^HRB\s*[:#.-]?\s*/iu', '', trim($value)));
    }

    private static function whatsappNumber(string $number): ?string
    {
        $number = trim($number);

        // Accept phone notation, never turn arbitrary text or a URL into a number.
        if (! preg_match('/\A\+?[0-9](?:[0-9 .\/-]|\([0-9]+\))*\z/', $number)) {
            return null;
        }

        $digits = (string) preg_replace('/[ .\/()-]/', '', str_replace('(0)', '', $number));

        if (str_starts_with($digits, '+')) {
            $digits = substr($digits, 1);
        } elseif (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        } elseif (str_starts_with($digits, '0')) {
            $digits = '49'.substr($digits, 1);
        }

        return preg_match('/\A[1-9][0-9]{2,14}\z/', $digits) ? $digits : null;
    }
}
