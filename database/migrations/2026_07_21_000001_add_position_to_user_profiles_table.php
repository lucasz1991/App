<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('user_profiles', function (Blueprint $table) {
            // Funktionsbezeichnung fuer E-Mail-Vorlagen/Signaturen
            // (z. B. "Geschäftsführung", "IT-Technik", "Büro-Assistenz")
            $table->string('position')->nullable()->after('personnel_nr');
        });
    }

    public function down(): void
    {
        Schema::table('user_profiles', function (Blueprint $table) {
            $table->dropColumn('position');
        });
    }
};
