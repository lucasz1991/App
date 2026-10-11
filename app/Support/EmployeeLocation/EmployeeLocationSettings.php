<?php

namespace App\Support\EmployeeLocation;

use App\Models\MobileLocationInstallation;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;

final class EmployeeLocationSettings
{
    public const GROUP = 'employee_location';

    public const KEY = 'employee_location';

    public const LEGACY_EXPLANATION = 'RailTime erfasst auf deinem zugeordneten Geschäftshandy während laufender Arbeitszeit zu Beginn und anschließend ungefähr alle 15 Minuten einen präzisen Standort. Das Betriebssystem kann dazwischen Positionen liefern; diese Zwischenwerte werden verworfen und weder gespeichert noch übertragen. Bei Pause, Arbeitsende, Widerruf oder um Mitternacht endet die Freigabe. Die Punkte werden sieben Tage aufbewahrt. Berechtigte Disponenten sehen den letzten geeigneten Punkt für Einsatzkoordination und Anreisehinweise. Für eine konkrete Routenberechnung können Koordinaten an TomTom übertragen werden. Deinen eigenen Verlauf kannst du einsehen und löschen. Hintergrundortung benötigt Geräteberechtigungen und Akku; Netz- oder Betriebssystemunterbrechungen können Lücken verursachen. Du kannst ablehnen oder jederzeit widerrufen. Deine Zeiterfassung bleibt ohne Standort nutzbar. Standortdaten erzeugen oder korrigieren keine Arbeitszeiten und werden in dieser Version nicht für Lohn- oder Anwesenheitsentscheidungen verwendet.';

    public const DEFAULTS = [
        'enabled' => false, 'interval_seconds' => 900, 'retention_days' => 7, 'accuracy_m' => 50,
        'explanation_version' => 2,
        'explanation_text' => 'RailTime erfasst auf deinem zugeordneten Geschäftshandy während laufender Arbeitszeit zu Beginn und anschließend ungefähr alle 15 Minuten einen präzisen Standort. Das Betriebssystem kann dazwischen Positionen liefern; diese Zwischenwerte werden verworfen und weder gespeichert noch übertragen. Deine Zustimmung gilt für diese aktive Anmeldesitzung und wird bei tatsächlicher App-Nutzung zusammen mit der Sitzung verlängert. Bei Abmeldung, Sitzungsablauf oder Widerruf endet sie. Bei Pause und Arbeitsende stoppt die Erfassung; ein erneuter Start benötigt weiterhin deinen sichtbaren Start des Standortdienstes. Push-Benachrichtigungen und Standort werden getrennt freigegeben. In deinem Profil kannst du beide Entscheidungen jederzeit ändern oder widerrufen. Die Punkte werden sieben Tage aufbewahrt. Berechtigte Disponenten sehen den letzten geeigneten Punkt für Einsatzkoordination und Anreisehinweise. Für eine konkrete Routenberechnung können Koordinaten an TomTom übertragen werden. Deinen eigenen Verlauf kannst du einsehen und löschen. Hintergrundortung benötigt Geräteberechtigungen und Akku; Netz- oder Betriebssystemunterbrechungen können Lücken verursachen. Du kannst ablehnen oder jederzeit widerrufen. Deine Zeiterfassung bleibt ohne Standort nutzbar. Standortdaten erzeugen oder korrigieren keine Arbeitszeiten und werden in dieser Version nicht für Lohn- oder Anwesenheitsentscheidungen verwendet.',
        'android_install_url' => '', 'ios_install_url' => '', 'android_package' => 'de.railtime.location',
        'ios_bundle_id' => 'de.railtime.location', 'ios_app_id' => '', 'android_certificate_sha256' => '',
    ];

    public static function ready(): bool
    {
        return Schema::hasTable('session_permission_grants') && Schema::hasTable('employee_location_points') && Schema::hasTable('employee_location_consents')
            && Schema::hasTable('mobile_location_installations') && Schema::hasTable('mobile_location_codes');
    }

    public static function read(): array
    {
        if (! Schema::hasTable('settings')) {
            return self::DEFAULTS;
        }

        return array_replace(self::DEFAULTS, array_intersect_key((array) Setting::getValueUncached(self::GROUP, self::KEY), self::DEFAULTS));
    }

    public static function save(array $input, ?User $actor = null): void
    {
        $actor ??= auth()->user();
        abort_unless($actor instanceof User && $actor->isActive() && $actor->isSuperAdmin() && $actor->can('settings.manage'), 403);
        $values = array_replace(self::read(), array_intersect_key($input, self::DEFAULTS));
        $values = Validator::make($values, [
            'enabled' => 'required|boolean', 'interval_seconds' => 'required|integer|in:900', 'retention_days' => 'required|integer|in:7', 'accuracy_m' => 'required|integer|in:50',
            'explanation_version' => 'required|integer|min:1', 'explanation_text' => 'required|string|min:100|max:10000',
            'android_install_url' => 'nullable|url:https|max:2048', 'ios_install_url' => 'nullable|url:https|max:2048',
            'android_package' => 'required|string|regex:/^[a-zA-Z][a-zA-Z0-9_]*(\.[a-zA-Z][a-zA-Z0-9_]*)+$/|max:180',
            'ios_bundle_id' => 'required|string|regex:/^[a-zA-Z0-9.-]+$/|max:180', 'ios_app_id' => 'nullable|string|regex:/^[A-Z0-9]{10}\.[a-zA-Z0-9.-]+$/|max:200',
            'android_certificate_sha256' => 'nullable|string|regex:/^(?:[A-Fa-f0-9]{2}:){31}[A-Fa-f0-9]{2}$/',
        ])->validate();
        $values['enabled'] = (bool) $values['enabled'];
        foreach (['interval_seconds', 'retention_days', 'accuracy_m', 'explanation_version'] as $field) {
            $values[$field] = (int) $values[$field];
        }
        if ($values['enabled']) {
            abort_unless(self::ready(), 422, 'Standorttabellen müssen vor der Aktivierung eingerichtet sein.');
        }
        DB::transaction(function () use ($values): void {
            $current = Setting::where('type', self::GROUP)->where('key', self::KEY)->lockForUpdate()->first();
            $previous = array_replace(self::DEFAULTS, (array) $current?->value);
            if ($previous['explanation_text'] !== $values['explanation_text']) {
                $values['explanation_version'] = max((int) $previous['explanation_version'] + 1, (int) $values['explanation_version']);
            } else {
                $values['explanation_version'] = max((int) $previous['explanation_version'], (int) $values['explanation_version']);
            }
            Setting::setValue(self::GROUP, self::KEY, $values);
            if (self::ready() && (! $values['enabled'] || $values['explanation_version'] !== $previous['explanation_version'])) {
                MobileLocationInstallation::whereNull('revoked_at')->update(['session_id' => null, 'session_started_at' => null, 'permit_id' => null, 'permit_expires_at' => null]);
            }
        }, 3);
    }
}
