<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('browser_location_points', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('session_permission_grant_id')->unique()->constrained()->cascadeOnDelete();
            $table->text('position');
            $table->decimal('accuracy_m', 8, 2);
            $table->timestamp('measured_at', 6)->index();
            $table->timestamp('received_at', 6);
            $table->uuid('event_id');
            $table->char('event_hash', 64);
            $table->timestamps(6);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('browser_location_points');
    }
};
