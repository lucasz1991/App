<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_live_locations', function (Blueprint $table): void {
            $table->dateTime('profile_history_accepted_at', 6)->nullable();
            $table->unsignedSmallInteger('profile_history_version')->nullable();
            $table->dateTime('profile_history_withdrawn_at', 6)->nullable();
        });
        Schema::create('chat_location_points', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('chat_live_location_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->longText('position');
            $table->decimal('accuracy_m', 6, 2);
            $table->dateTime('measured_at', 6);
            $table->dateTime('received_at', 6);
            $table->unique(['chat_live_location_id', 'measured_at'], 'chat_location_sample_unique');
            $table->index(['user_id', 'measured_at', 'id'], 'chat_location_person_history');
            $table->index('measured_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_location_points');
        Schema::table('chat_live_locations', fn (Blueprint $table) => $table->dropColumn([
            'profile_history_accepted_at', 'profile_history_version', 'profile_history_withdrawn_at',
        ]));
    }
};
