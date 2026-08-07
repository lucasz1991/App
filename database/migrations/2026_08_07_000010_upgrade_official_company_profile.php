<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Refresh only empty values and the obsolete defaults shipped by RailTime.
     * Deliberately preserve every value an administrator changed to something
     * else, including a privately maintained tax number.
     */
    public function up(): void
    {
        $row = DB::table('settings')
            ->where('type', 'company')
            ->where('key', 'profile')
            ->first();

        $stored = $row ? json_decode((string) $row->value, true) : [];
        $stored = is_array($stored) ? $stored : [];

        $official = [
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
            'tax_number' => (string) ($stored['tax_number'] ?? ''),
        ];

        $obsolete = [
            'phone' => ['', '0160 1881848'],
            'emergency_phone' => ['', '0160 1881848'],
            'email' => ['', 'kontakt@rail-time.de'],
            'website' => ['', (string) config('app.url')],
        ];

        foreach ($official as $key => $value) {
            $current = trim((string) ($stored[$key] ?? ''));
            if ($current === '' || in_array($current, $obsolete[$key] ?? [''], true)) {
                $stored[$key] = $value;
            }
        }

        $timestamps = $row
            ? ['value' => json_encode($stored, JSON_UNESCAPED_UNICODE), 'updated_at' => now()]
            : [
                'type' => 'company',
                'key' => 'profile',
                'value' => json_encode($stored, JSON_UNESCAPED_UNICODE),
                'created_at' => now(),
                'updated_at' => now(),
            ];

        if ($row) {
            DB::table('settings')->where('id', $row->id)->update($timestamps);
        } else {
            DB::table('settings')->insert($timestamps);
        }
    }

    public function down(): void
    {
        // Data changes may have been edited after deployment and are not
        // destructively rolled back.
    }
};
