<?php

namespace Tests\Feature;

use App\Models\Chat;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

class ChatParticipantLifecycleTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildMinimalRailTimeSchema();
    }

    public function test_lifecycle_migration_is_repeatable_and_backfills_joined_at(): void
    {
        Schema::drop('chat_user');
        Schema::create('chat_user', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('chat_id');
            $table->unsignedBigInteger('user_id');
            $table->timestamp('last_read_at')->nullable();
            $table->timestamp('last_opened_at')->nullable();
            $table->timestamps();
        });

        $createdAt = Carbon::parse('2026-07-12 09:30:00');
        DB::table('chat_user')->insert([
            'chat_id' => 10,
            'user_id' => 20,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);

        $migration = require database_path(
            'migrations/2026_07_28_000001_add_lifecycle_timestamps_to_chat_user_table.php'
        );
        $migration->up();
        $migration->up();

        $this->assertTrue(Schema::hasColumns('chat_user', [
            'joined_at',
            'hidden_at',
            'cleared_at',
        ]));
        $this->assertSame(
            $createdAt->toDateTimeString(),
            Carbon::parse(DB::table('chat_user')->value('joined_at'))->toDateTimeString()
        );
    }

    public function test_user_chats_hide_hidden_pivots_while_participants_keep_lifecycle_data(): void
    {
        $user = User::factory()->create();
        $partner = User::factory()->create();
        $joinedAt = Carbon::parse('2026-07-20 10:00:00');
        $hiddenAt = Carbon::parse('2026-07-21 10:00:00');
        $visible = Chat::create(['type' => 'direct', 'created_by' => $user->id]);
        $hidden = Chat::create(['type' => 'direct', 'created_by' => $user->id]);

        $visible->participants()->attach($user->id, [
            'joined_at' => $joinedAt,
            'cleared_at' => null,
            'hidden_at' => null,
        ]);
        $hidden->participants()->attach($user->id, [
            'joined_at' => $joinedAt,
            'cleared_at' => $hiddenAt,
            'hidden_at' => $hiddenAt,
        ]);

        $this->assertEquals([$visible->id], $user->chats()->pluck('chats.id')->all());

        $participant = $hidden->participants()
            ->where('users.id', $user->id)
            ->firstOrFail();

        $this->assertSame(
            $joinedAt->toDateTimeString(),
            Carbon::parse($participant->pivot->joined_at)->toDateTimeString()
        );
        $this->assertSame(
            $hiddenAt->toDateTimeString(),
            Carbon::parse($participant->pivot->hidden_at)->toDateTimeString()
        );
        $this->assertSame(
            $hiddenAt->toDateTimeString(),
            Carbon::parse($participant->pivot->cleared_at)->toDateTimeString()
        );
    }

    public function test_helpers_respect_participation_visibility_and_group_ownership(): void
    {
        $creator = User::factory()->create();
        $member = User::factory()->create();
        $outsider = User::factory()->create();
        $joinedAt = Carbon::parse('2026-07-20 08:00:00');
        $clearedAt = Carbon::parse('2026-07-22 14:00:00');
        $group = Chat::create([
            'type' => 'group',
            'name' => 'Disposition',
            'created_by' => $creator->id,
        ]);

        $group->participants()->attach([
            $creator->id => [
                'joined_at' => $joinedAt,
                'cleared_at' => $clearedAt,
            ],
            $member->id => [
                'joined_at' => $joinedAt,
                'cleared_at' => null,
            ],
        ]);
        $group->load('participants');

        $this->assertSame($creator->id, $group->participantFor($creator)?->id);
        $this->assertSame($member->id, $group->participantFor($member->id)?->id);
        $this->assertNull($group->participantFor($outsider));
        $this->assertSame(
            $clearedAt->toDateTimeString(),
            $group->visibleSinceFor($creator)?->toDateTimeString()
        );
        $this->assertSame(
            $joinedAt->toDateTimeString(),
            $group->visibleSinceFor($member)?->toDateTimeString()
        );
        $this->assertNull($group->visibleSinceFor($outsider));
        $this->assertTrue($group->canManageGroup($creator));
        $this->assertFalse($group->canManageGroup($member));
        $this->assertFalse($group->canManageGroup($outsider));

        $direct = Chat::create(['type' => 'direct', 'created_by' => $creator->id]);
        $direct->participants()->attach($creator->id, ['joined_at' => $joinedAt]);

        $this->assertFalse($direct->canManageGroup($creator));
    }

    public function test_direct_between_initializes_and_restores_participant_lifecycle(): void
    {
        $initiator = User::factory()->create();
        $recipient = User::factory()->create();
        $joinedAt = Carbon::parse('2026-07-24 09:00:00');

        $this->travelTo($joinedAt);
        $chat = Chat::directBetween($initiator, $recipient);

        foreach ([$initiator, $recipient] as $user) {
            $pivot = DB::table('chat_user')
                ->where('chat_id', $chat->id)
                ->where('user_id', $user->id)
                ->first();

            $this->assertSame($joinedAt->toDateTimeString(), Carbon::parse($pivot->joined_at)->toDateTimeString());
            $this->assertNull($pivot->hidden_at);
            $this->assertNull($pivot->cleared_at);
        }

        $hiddenAt = Carbon::parse('2026-07-25 13:00:00');
        DB::table('chat_user')
            ->where('chat_id', $chat->id)
            ->update(['hidden_at' => $hiddenAt]);
        DB::table('chat_user')
            ->where('chat_id', $chat->id)
            ->where('user_id', $initiator->id)
            ->update([
                'joined_at' => null,
                'cleared_at' => $hiddenAt,
            ]);

        $this->assertFalse($initiator->chats()->whereKey($chat->id)->exists());
        $this->assertFalse($recipient->chats()->whereKey($chat->id)->exists());

        $restored = Chat::directBetween($initiator, $recipient);

        $this->assertSame($chat->id, $restored->id);
        $this->assertSame(1, Chat::query()->where('type', 'direct')->count());

        $restoredPivots = DB::table('chat_user')
            ->where('chat_id', $chat->id)
            ->get()
            ->keyBy('user_id');

        $this->assertNull($restoredPivots[$initiator->id]->hidden_at);
        $this->assertNull($restoredPivots[$recipient->id]->hidden_at);
        $this->assertSame(
            $joinedAt->toDateTimeString(),
            Carbon::parse($restoredPivots[$initiator->id]->joined_at)->toDateTimeString()
        );
        $this->assertSame(
            $hiddenAt->toDateTimeString(),
            Carbon::parse($restoredPivots[$initiator->id]->cleared_at)->toDateTimeString()
        );
        $this->assertTrue($initiator->chats()->whereKey($chat->id)->exists());
        $this->assertTrue($recipient->chats()->whereKey($chat->id)->exists());
    }
}
