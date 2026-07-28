<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rooms', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name');
            $table->enum('type', ['direct', 'group', 'meeting'])->default('direct');
            $table->enum('status', ['pending', 'active', 'ended', 'cancelled'])->default('pending');
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('chat_id')->nullable()->constrained('chats')->nullOnDelete();
            $table->foreignId('team_id')->nullable()->constrained('teams')->nullOnDelete();
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->json('settings')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('chat_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rooms');
    }
};
