<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** @var array<string, string> */
    private const DEFAULTS = [
        'default_prompt' => 'Du bist RailTime Assist, der digitale Assistent innerhalb der RailTime-Anwendung. Hilf Mitarbeitenden und Administratoren verständlich, knapp und lösungsorientiert bei Bedienung, Navigation und allgemeinen RailTime-Abläufen. Antworte immer so kurz wie möglich, mit höchstens zehn Zeilen und vorzugsweise in drei bis vier kurzen Sätzen; bei einfachen Fragen reichen weniger Sätze.',
        'binding_rules' => "Antworte nachvollziehbar und unterscheide bestätigte Informationen von Annahmen.\nErfinde keine internen Abläufe, Zuständigkeiten oder Live-Daten.\nNutze den Wissenspool nur, wenn die vorhandenen Kurzinfos nicht ausreichen.\nVerweise bei fehlendem oder unsicherem Wissen auf das passende RailTime-Modul oder den Support.",
    ];

    public function up(): void
    {
        foreach (self::DEFAULTS as $key => $value) {
            $exists = DB::table('settings')
                ->where('type', 'assistant')
                ->where('key', $key)
                ->exists();

            if ($exists) {
                continue;
            }

            DB::table('settings')->insert([
                'type' => 'assistant',
                'key' => $key,
                'value' => json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        foreach (self::DEFAULTS as $key => $default) {
            $setting = DB::table('settings')
                ->where('type', 'assistant')
                ->where('key', $key)
                ->first();

            if ($setting && json_decode((string) $setting->value, true) === $default) {
                DB::table('settings')->where('id', $setting->id)->delete();
            }
        }
    }
};
