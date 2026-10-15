<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_messages', function (Blueprint $table): void {
            $table->foreignId('call_room_id')->nullable()->constrained('rooms')->nullOnDelete();
            $table->unique(['chat_id', 'call_room_id']);
        });

        Schema::create('communication_meetings', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('title', 120);
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('team_id')->nullable()->constrained('teams')->nullOnDelete();
            $table->foreignId('chat_id')->nullable()->constrained('chats')->nullOnDelete();
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->string('timezone', 64);
            $table->boolean('video')->default(true);
            $table->string('status', 24)->default('scheduled');
            $table->timestamps();
            $table->index(['status', 'starts_at']);
        });

        Schema::create('communication_meeting_participants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('communication_meeting_id')->constrained('communication_meetings', indexName: 'comm_meeting_participant_meeting_fk')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('response', 16)->default('pending');
            $table->timestamp('responded_at')->nullable();
            $table->timestamps();
            $table->unique(['communication_meeting_id', 'user_id'], 'communication_meeting_user_unique');
        });
        Schema::table('rooms', function (Blueprint $table): void {
            $table->foreignId('communication_meeting_id')->nullable()->constrained('communication_meetings')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('rooms', fn (Blueprint $table) => $table->dropConstrainedForeignId('communication_meeting_id'));
        Schema::dropIfExists('communication_meeting_participants');
        Schema::dropIfExists('communication_meetings');
        Schema::table('chat_messages', function (Blueprint $table): void {
            $table->dropUnique(['chat_id', 'call_room_id']);
            $table->dropConstrainedForeignId('call_room_id');
        });
    }
};
