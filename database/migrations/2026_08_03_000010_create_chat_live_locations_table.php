<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_live_locations', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('chat_message_id')
                ->unique()
                ->constrained('chat_messages')
                ->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('duration_minutes');
            // Coordinates are encrypted by ChatLiveLocation's encrypted:array
            // cast. They are deliberately not searchable or indexed.
            $table->longText('latest_position');
            // dateTime avoids legacy MySQL's implicit zero-default rules for
            // consecutive non-null TIMESTAMP columns while preserving UTC
            // application semantics through the model casts.
            $table->dateTime('last_position_at');
            $table->dateTime('expires_at');
            $table->dateTime('stopped_at')->nullable();
            $table->string('stop_reason', 32)->nullable();
            $table->unsignedBigInteger('version')->default(1);
            $table->timestamps();

            $table->index(
                ['user_id', 'stopped_at', 'expires_at'],
                'chat_live_location_owner_active_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_live_locations');
    }
};
