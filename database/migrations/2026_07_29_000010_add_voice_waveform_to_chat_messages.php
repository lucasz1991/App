<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('chat_messages', 'voice_waveform')) {
            Schema::table('chat_messages', function (Blueprint $table): void {
                // Pegelwerte (0-100) der Aufnahme, clientseitig beim
                // Aufnehmen erfasst. Grundlage fuer eine Wellenform, die
                // zur Nachricht passt statt eines Einheitsmusters.
                $table->json('voice_waveform')->nullable()->after('voice_duration_seconds');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('chat_messages', 'voice_waveform')) {
            Schema::table('chat_messages', function (Blueprint $table): void {
                $table->dropColumn('voice_waveform');
            });
        }
    }
};
