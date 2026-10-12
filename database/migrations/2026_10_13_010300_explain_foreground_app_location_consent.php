<?php

use App\Support\EmployeeLocation\EmployeeLocationSettings;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('settings')) {
            return;
        }
        DB::transaction(function (): void {
            $row = DB::table('settings')->where('type', EmployeeLocationSettings::GROUP)->where('key', EmployeeLocationSettings::KEY)->lockForUpdate()->first();
            if (! $row) {
                return;
            }
            $values = json_decode($row->value, true, 512, JSON_THROW_ON_ERROR);
            $text = $values['explanation_text'] ?? '';
            $alreadyExplained = str_starts_with($text, EmployeeLocationSettings::DEFAULTS['explanation_text']);
            if ($alreadyExplained && (int) ($values['explanation_version'] ?? 0) >= EmployeeLocationSettings::BROWSER_EXPLANATION_VERSION) {
                return;
            }
            // Preserve custom explanations as a supplement; every changed
            // collection context requires a fresh, explicit session decision.
            $custom = ! in_array($text, ['', EmployeeLocationSettings::LEGACY_EXPLANATION, EmployeeLocationSettings::LEGACY_SESSION_EXPLANATION], true);
            if (! $alreadyExplained) {
                $values['explanation_text'] = EmployeeLocationSettings::DEFAULTS['explanation_text'].($custom ? "\n\nBisherige betriebliche Zusatzinformation:\n".$text : '');
            }
            $values['explanation_version'] = max(EmployeeLocationSettings::BROWSER_EXPLANATION_VERSION, ((int) ($values['explanation_version'] ?? 0)) + 1);
            DB::table('settings')->where('id', $row->id)->update(['value' => json_encode($values, JSON_THROW_ON_ERROR), 'updated_at' => now()]);
            if (Schema::hasTable('mobile_location_installations')) {
                DB::table('mobile_location_installations')->update(['session_id' => null, 'session_started_at' => null, 'permit_id' => null, 'permit_expires_at' => null]);
            }
        });
    }

    public function down(): void
    {
        // Never restore an older permission text/version and thereby revive
        // obsolete consent. A rollback must keep existing decisions invalid.
    }
};
