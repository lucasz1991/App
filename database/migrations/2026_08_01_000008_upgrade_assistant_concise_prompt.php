<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const OLD_DEFAULT = 'Du bist RailTime Assist, der digitale Assistent innerhalb der RailTime-Anwendung. Hilf Mitarbeitenden und Administratoren verständlich, knapp und lösungsorientiert bei Bedienung, Navigation und allgemeinen RailTime-Abläufen.';

    private const NEW_DEFAULT = 'Du bist RailTime Assist, der digitale Assistent innerhalb der RailTime-Anwendung. Hilf Mitarbeitenden und Administratoren verständlich, knapp und lösungsorientiert bei Bedienung, Navigation und allgemeinen RailTime-Abläufen. Antworte immer so kurz wie möglich, mit höchstens zehn Zeilen und vorzugsweise in drei bis vier kurzen Sätzen; bei einfachen Fragen reichen weniger Sätze.';

    public function up(): void
    {
        $setting = DB::table('settings')
            ->where('type', 'assistant')
            ->where('key', 'default_prompt')
            ->first();

        if (! $setting) {
            DB::table('settings')->insert([
                'type' => 'assistant',
                'key' => 'default_prompt',
                'value' => $this->encoded(self::NEW_DEFAULT),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } elseif ($this->decoded($setting->value) === self::OLD_DEFAULT) {
            DB::table('settings')->where('id', $setting->id)->update([
                'value' => $this->encoded(self::NEW_DEFAULT),
                'updated_at' => now(),
            ]);
        }

        Cache::forget('settings.assistant.default_prompt');
    }

    public function down(): void
    {
        $setting = DB::table('settings')
            ->where('type', 'assistant')
            ->where('key', 'default_prompt')
            ->first();

        if ($setting && $this->decoded($setting->value) === self::NEW_DEFAULT) {
            DB::table('settings')->where('id', $setting->id)->update([
                'value' => $this->encoded(self::OLD_DEFAULT),
                'updated_at' => now(),
            ]);
        }

        Cache::forget('settings.assistant.default_prompt');
    }

    private function decoded(mixed $value): mixed
    {
        if (! is_string($value)) {
            return null;
        }

        return json_decode($value, true);
    }

    private function encoded(string $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }
};
