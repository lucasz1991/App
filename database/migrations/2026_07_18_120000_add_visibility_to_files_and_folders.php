<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('file_folders', function (Blueprint $table) {
            // Sichtbarkeitsfenster + automatisches Loeschen + Team-Sichtbarkeit
            $table->date('visible_from')->nullable()->after('permissions');
            $table->date('visible_until')->nullable()->after('visible_from');
            $table->boolean('auto_delete')->default(false)->after('visible_until');
            $table->json('visible_teams')->nullable()->after('auto_delete');
        });

        Schema::table('files', function (Blueprint $table) {
            // files besitzen bereits expires_at (= Ablaufdatum). Ergaenzt wird der
            // Sichtbarkeits-Start, Auto-Loeschen und die Team-Sichtbarkeit.
            $table->date('visible_from')->nullable()->after('expires_at');
            $table->boolean('auto_delete')->default(false)->after('visible_from');
            $table->json('visible_teams')->nullable()->after('auto_delete');
        });
    }

    public function down(): void
    {
        Schema::table('file_folders', function (Blueprint $table) {
            $table->dropColumn(['visible_from', 'visible_until', 'auto_delete', 'visible_teams']);
        });

        Schema::table('files', function (Blueprint $table) {
            $table->dropColumn(['visible_from', 'auto_delete', 'visible_teams']);
        });
    }
};
