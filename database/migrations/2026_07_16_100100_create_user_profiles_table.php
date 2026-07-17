<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('user_profiles', function (Blueprint $table) {
            $table->id();

            // 1:1-Beziehung zum Benutzerkonto
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();

            // Kontaktdaten
            $table->string('phone')->nullable();
            $table->string('mobile')->nullable();

            // Anschrift
            $table->string('street')->nullable();
            $table->string('postal_code')->nullable();
            $table->string('city')->nullable();
            $table->string('country')->nullable();

            // Stammdaten
            $table->date('birth_date')->nullable();
            $table->string('personnel_nr')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_profiles');
    }
};
