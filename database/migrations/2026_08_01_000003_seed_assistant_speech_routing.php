<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $exists = DB::table('settings')
            ->where('type', 'assistant')
            ->where('key', 'speech_routing')
            ->exists();

        if ($exists) {
            return;
        }

        DB::table('settings')->insert([
            'type' => 'assistant',
            'key' => 'speech_routing',
            'value' => json_encode([
                'mode' => 'local_with_external_fallback',
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        $setting = DB::table('settings')
            ->where('type', 'assistant')
            ->where('key', 'speech_routing')
            ->first();

        if (! $setting) {
            return;
        }

        $value = json_decode((string) $setting->value, true);

        // Eine nach dem Deployment bewusst geaenderte Betriebsentscheidung
        // darf ein Rollback der Datenmigration nicht stillschweigend loeschen.
        if ($value === ['mode' => 'local_with_external_fallback']) {
            DB::table('settings')
                ->where('id', $setting->id)
                ->delete();
        }
    }
};
