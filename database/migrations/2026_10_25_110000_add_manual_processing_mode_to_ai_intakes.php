<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ai_intakes') && ! Schema::hasColumn('ai_intakes', 'processing_mode')) {
            Schema::table('ai_intakes', fn (Blueprint $table) => $table->string('processing_mode', 20)->default('assisted'));
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('ai_intakes', 'processing_mode')) {
            if (DB::table('ai_intakes')->where('processing_mode', 'manual')->exists()) {
                throw new RuntimeException('Manuell übernommene Eingänge vorhanden; Rücknahme würde ihre Bearbeitungsverantwortung löschen.');
            }
            Schema::table('ai_intakes', fn (Blueprint $table) => $table->dropColumn('processing_mode'));
        }
    }
};
