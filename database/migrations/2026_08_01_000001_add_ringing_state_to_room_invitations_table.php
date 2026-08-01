<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rueckmeldung "es klingelt tatsaechlich".
 *
 * 'pending' heisst nur: die Einladung wurde verschickt. Ob das Geraet der
 * Gegenseite laeutet, weiss der Anrufer damit noch nicht — genau diesen
 * Unterschied zeigt das Anruf-Fenster als "wird angerufen" vs. "klingelt".
 * ringing_via haelt fest, woher die Bestaetigung kam ('browser' = offene
 * Sitzung, 'app' = Service Worker der installierten App).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('room_invitations', function (Blueprint $table) {
            $table->timestamp('ringing_at')->nullable()->after('expires_at');
            $table->string('ringing_via', 20)->nullable()->after('ringing_at');
        });
    }

    public function down(): void
    {
        Schema::table('room_invitations', function (Blueprint $table) {
            $table->dropColumn(['ringing_at', 'ringing_via']);
        });
    }
};
