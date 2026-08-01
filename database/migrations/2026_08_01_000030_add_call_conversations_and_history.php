<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rooms', function (Blueprint $table): void {
            $table->foreignId('call_chat_id')
                ->nullable()
                ->after('chat_id')
                ->constrained('chats')
                ->nullOnDelete();
            $table->timestamp('connected_at')->nullable()->after('started_at');
            $table->string('ended_reason', 64)->nullable()->after('ended_at');

            $table->unique('call_chat_id');
        });

        Schema::create('room_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('room_id')->constrained('rooms')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type', 64);
            $table->string('external_id')->nullable()->unique();
            $table->json('metadata')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['room_id', 'occurred_at']);
        });

        $this->backfillCallChats();
    }

    public function down(): void
    {
        Schema::dropIfExists('room_events');

        $callChatIds = DB::table('rooms')->whereNotNull('call_chat_id')->pluck('call_chat_id');
        DB::table('chats')->whereIn('id', $callChatIds)->where('type', 'call')->delete();

        Schema::table('rooms', function (Blueprint $table): void {
            $table->dropUnique(['call_chat_id']);
            $table->dropForeign(['call_chat_id']);
            $table->dropColumn(['call_chat_id', 'connected_at', 'ended_reason']);
        });
    }

    private function backfillCallChats(): void
    {
        DB::table('rooms')
            ->whereNull('call_chat_id')
            ->orderBy('id')
            ->chunkById(100, function ($rooms): void {
                foreach ($rooms as $room) {
                    $chatId = DB::table('chats')->insertGetId([
                        'type' => 'call',
                        'name' => $room->name,
                        'created_by' => $room->owner_id,
                        'created_at' => $room->created_at,
                        'updated_at' => $room->updated_at,
                    ]);

                    DB::table('rooms')->where('id', $room->id)->update(['call_chat_id' => $chatId]);

                    $participantIds = DB::table('room_participants')
                        ->where('room_id', $room->id)
                        ->whereNotNull('user_id')
                        ->whereNotNull('joined_at')
                        ->pluck('user_id')
                        ->merge(
                            DB::table('room_invitations')
                                ->where('room_id', $room->id)
                                ->where('status', 'accepted')
                                ->pluck('invitee_id')
                        )
                        ->push($room->owner_id)
                        ->map(fn ($id): int => (int) $id)
                        ->unique()
                        ->values();

                    $rows = $participantIds->map(fn (int $userId): array => [
                        'chat_id' => $chatId,
                        'user_id' => $userId,
                        'last_read_at' => $userId === (int) $room->owner_id ? $room->created_at : null,
                        'last_opened_at' => null,
                        'joined_at' => $room->created_at,
                        'hidden_at' => null,
                        'cleared_at' => null,
                        'created_at' => $room->created_at,
                        'updated_at' => $room->updated_at,
                    ])->all();

                    if ($rows !== []) {
                        DB::table('chat_user')->insertOrIgnore($rows);
                    }

                    DB::table('room_events')->insert([
                        'room_id' => $room->id,
                        'user_id' => $room->owner_id,
                        'type' => 'created',
                        'external_id' => 'backfill:room:'.$room->id.':created',
                        'metadata' => json_encode(['type' => $room->type], JSON_THROW_ON_ERROR),
                        'occurred_at' => $room->created_at,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    if (in_array($room->status, ['ended', 'cancelled'], true)) {
                        DB::table('room_events')->insert([
                            'room_id' => $room->id,
                            'user_id' => null,
                            'type' => $room->status,
                            'external_id' => 'backfill:room:'.$room->id.':'.$room->status,
                            'metadata' => null,
                            'occurred_at' => $room->ended_at ?? $room->updated_at,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }
                }
            }, 'rooms.id', 'id');
    }
};
