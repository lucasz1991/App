<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kopplung des Outlook-Add-ins mit der RailTime-App auf genau einem PC.
 * Das Add-in fordert eine kurzlebige Anfrage an (nur der Hash der Nonce wird
 * gespeichert), die App auf dem PC bestätigt sie mit ihrem Gerätezugang, das
 * Add-in erhält danach einmalig einen Kopplungsschlüssel (ebenfalls nur als Hash).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('outlook_desktop_pairings')) {
            return;
        }

        Schema::create('outlook_desktop_pairings', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->nullable()->constrained('device_desktop_clients')->nullOnDelete();
            $table->char('nonce_hash', 64)->nullable()->unique();
            $table->char('key_hash', 64)->nullable()->unique();
            // pending → confirmed → paired; sonst expired, rejected oder revoked.
            $table->string('status', 16)->default('pending')->index();
            $table->timestamp('expires_at');
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('paired_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outlook_desktop_pairings');
    }
};
