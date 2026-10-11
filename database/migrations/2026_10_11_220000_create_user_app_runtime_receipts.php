<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumns('users', ['id']) || ! Schema::hasColumns('session_permission_grants', ['id', 'user_id'])) {
            throw new RuntimeException('App-Laufzeitmeldungen benötigen zuerst Benutzer und Sitzungsfreigaben. Es wurden keine Laufzeitdaten verändert.');
        }
        Schema::create('user_app_runtime_receipts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('session_permission_grant_id')->unique()->constrained('session_permission_grants')->cascadeOnDelete();
            $table->string('platform', 10);
            $table->string('display_mode', 12);
            $table->string('notification_permission', 12);
            $table->string('geolocation_permission', 12);
            $table->dateTime('received_at', 6)->index();
            $table->timestamps(6);
            $table->index(['user_id', 'received_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_app_runtime_receipts');
    }
};
