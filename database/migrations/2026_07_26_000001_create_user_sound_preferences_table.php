<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Persoenliche Ton-Zuordnung je Nutzer. Die systemweiten Standards liegen in
 * der settings-Tabelle (type 'sounds'); hier stehen nur die bewussten
 * Abweichungen eines Nutzers, damit eine spaetere Aenderung des Standards bei
 * allen anderen weiterhin greift.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_sound_preferences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('event', 40);
            $table->string('signature', 40);
            $table->timestamps();

            $table->unique(['user_id', 'event']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_sound_preferences');
    }
};
