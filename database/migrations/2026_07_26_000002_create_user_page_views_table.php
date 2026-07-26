<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Merkt sich je Nutzer, welche Seite er bereits gesehen hat. Grundlage fuer
 * das Willkommens-Intro beim allerersten Besuch und das automatische Oeffnen
 * der Seiteninfo beim ersten Aufruf einer Unterseite.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_page_views', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('page_key', 120);
            $table->timestamp('first_seen_at');

            $table->unique(['user_id', 'page_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_page_views');
    }
};
