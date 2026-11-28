<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $changed = DB::transaction(function (): bool {
            $row = DB::table('settings')
                ->where('type', 'company')
                ->where('key', 'profile')
                ->lockForUpdate()
                ->first();

            $stored = $row ? json_decode((string) $row->value, true, flags: JSON_THROW_ON_ERROR) : [];

            if (! is_array($stored)) {
                throw new RuntimeException('The company profile must be an object before adding its WhatsApp number.');
            }

            // Preserve existing values, including an explicitly stored empty value.
            if (array_key_exists('whatsapp_phone', $stored)) {
                return false;
            }

            $stored['whatsapp_phone'] = '+49 160 1881848';
            $value = json_encode($stored, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

            if ($row) {
                DB::table('settings')->where('id', $row->id)->update([
                    'value' => $value,
                    'updated_at' => now(),
                ]);
            } else {
                DB::table('settings')->insert([
                    'type' => 'company',
                    'key' => 'profile',
                    'value' => $value,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            return true;
        });

        if ($changed) {
            Cache::forget('settings.company.profile');
        }
    }

    public function down(): void
    {
        // The number may have been edited after deployment; preserve company data.
    }
};
