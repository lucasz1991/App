<?php

namespace Tests\Feature;

use App\Http\Middleware\LogActivity;
use App\Livewire\ChatBox;
use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

class ChatManagementTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(LogActivity::class);
        $this->buildMinimalRailTimeSchema();
        Storage::fake('private');
    }

    public function test_group_owner_can_open_settings_rename_group_and_synchronize_members(): void
    {
        $owner = User::factory()->create();
        $removedMember = User::factory()->create();
        $newMember = User::factory()->create();
        $group = $this->groupChat($owner, [$removedMember], 'Alte Leitstelle');
        $joinedAt = Carbon::parse('2026-07-28 11:30:00');

        $this->travelTo($joinedAt);

        Livewire::actingAs($owner)
            ->test(ChatBox::class)
            ->assertSet('selectedChatId', $group->id)
            ->call('openGroupSettings')
            ->assertSet('showGroupSettings', true)
            ->assertSet('groupEditName', 'Alte Leitstelle')
            ->assertSet('groupMemberIds', [$removedMember->id])
            ->set('groupEditName', 'Neue Leitstelle')
            ->set('groupMemberIds', [$newMember->id])
            ->call('saveGroupSettings')
            ->assertHasNoErrors()
            ->assertSet('showGroupSettings', false)
            ->assertDispatched('inbox:refresh');

        $this->assertDatabaseHas('chats', [
            'id' => $group->id,
            'name' => 'Neue Leitstelle',
        ]);
        $this->assertDatabaseHas('chat_user', [
            'chat_id' => $group->id,
            'user_id' => $owner->id,
        ]);
        $this->assertDatabaseMissing('chat_user', [
            'chat_id' => $group->id,
            'user_id' => $removedMember->id,
        ]);

        $newMemberPivot = DB::table('chat_user')
            ->where('chat_id', $group->id)
            ->where('user_id', $newMember->id)
            ->sole();

        $this->assertSame($joinedAt->toDateTimeString(), Carbon::parse($newMemberPivot->joined_at)->toDateTimeString());
        $this->assertTrue($newMember->chats()->whereKey($group->id)->exists());
        $this->assertFalse($removedMember->chats()->whereKey($group->id)->exists());

        Livewire::actingAs($removedMember)
            ->test(ChatBox::class)
            ->call('openChat', $group->id)
            ->assertForbidden();
    }

    public function test_non_owner_cannot_open_or_save_group_settings(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $candidate = User::factory()->create();
        $group = $this->groupChat($owner, [$member]);

        Livewire::actingAs($member)
            ->test(ChatBox::class)
            ->assertSet('selectedChatId', $group->id)
            ->call('openGroupSettings')
            ->assertForbidden();

        Livewire::actingAs($member)
            ->test(ChatBox::class)
            ->set('groupEditName', 'Unberechtigte Änderung')
            ->set('groupMemberIds', [$candidate->id])
            ->call('saveGroupSettings')
            ->assertForbidden();

        $this->assertDatabaseHas('chats', [
            'id' => $group->id,
            'name' => 'Leitstelle',
        ]);
        $this->assertDatabaseHas('chat_user', [
            'chat_id' => $group->id,
            'user_id' => $member->id,
        ]);
        $this->assertDatabaseMissing('chat_user', [
            'chat_id' => $group->id,
            'user_id' => $candidate->id,
        ]);
    }

    public function test_group_member_can_leave_and_owner_permanently_deletes_group_files_and_storage(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $group = $this->groupChat($owner, [$member]);
        $message = ChatMessage::create([
            'chat_id' => $group->id,
            'user_id' => $owner->id,
            'body' => 'Einsatzplan',
        ]);
        $path = "uploads/chat/{$group->id}/einsatzplan.pdf";

        Storage::disk('private')->put($path, 'pdf-content');
        $file = $message->files()->create([
            'name' => 'Einsatzplan.pdf',
            'path' => $path,
            'disk' => 'private',
            'mime_type' => 'application/pdf',
            'type' => 'chat',
            'size' => 11,
            'user_id' => $owner->id,
        ]);

        Livewire::actingAs($member)
            ->test(ChatBox::class)
            ->assertSet('selectedChatId', $group->id)
            ->call('requestDeleteChat')
            ->assertSet('showDeleteChatModal', true)
            ->assertSet('pendingDeleteChatId', $group->id)
            ->call('confirmDeleteChat')
            ->assertSet('showDeleteChatModal', false)
            ->assertSet('pendingDeleteChatId', null)
            ->assertSet('selectedChatId', null)
            ->assertDispatched('chat:pane-list');

        $this->assertDatabaseHas('chats', ['id' => $group->id]);
        $this->assertDatabaseMissing('chat_user', [
            'chat_id' => $group->id,
            'user_id' => $member->id,
        ]);
        $this->assertDatabaseHas('chat_user', [
            'chat_id' => $group->id,
            'user_id' => $owner->id,
        ]);

        Livewire::actingAs($owner)
            ->test(ChatBox::class)
            ->assertSet('selectedChatId', $group->id)
            ->call('requestDeleteChat')
            ->assertSet('showDeleteChatModal', true)
            ->assertSet('pendingDeleteChatId', $group->id)
            ->call('confirmDeleteChat')
            ->assertSet('selectedChatId', null)
            ->assertDispatched('chat:pane-list');

        $this->assertDatabaseMissing('chats', ['id' => $group->id]);
        $this->assertDatabaseMissing('chat_user', ['chat_id' => $group->id]);
        $this->assertDatabaseMissing('chat_messages', ['id' => $message->id]);
        $this->assertDatabaseMissing('files', ['id' => $file->id]);
        Storage::disk('private')->assertMissing($path);
    }

    public function test_deleting_direct_chat_only_hides_and_clears_it_for_current_user_until_a_new_message_arrives(): void
    {
        $user = User::factory()->create();
        $partner = User::factory()->create();
        $joinedAt = Carbon::parse('2026-07-28 08:00:00');
        $oldMessageAt = Carbon::parse('2026-07-28 09:00:00');
        $clearedAt = Carbon::parse('2026-07-28 10:00:00');
        $newMessageAt = Carbon::parse('2026-07-28 11:00:00');
        $chat = $this->directChat($user, $partner, $joinedAt);

        $this->travelTo($oldMessageAt);
        ChatMessage::create([
            'chat_id' => $chat->id,
            'user_id' => $partner->id,
            'body' => 'Alter Verlauf',
        ]);

        $this->travelTo($clearedAt);

        Livewire::actingAs($user)
            ->test(ChatBox::class)
            ->assertSet('selectedChatId', $chat->id)
            ->call('requestDeleteChat')
            ->assertSet('showDeleteChatModal', true)
            ->assertSet('pendingDeleteChatId', $chat->id)
            ->call('confirmDeleteChat')
            ->assertSet('selectedChatId', null)
            ->assertDispatched('chat:pane-list');

        $userPivot = DB::table('chat_user')
            ->where('chat_id', $chat->id)
            ->where('user_id', $user->id)
            ->sole();
        $partnerPivot = DB::table('chat_user')
            ->where('chat_id', $chat->id)
            ->where('user_id', $partner->id)
            ->sole();

        $this->assertSame($clearedAt->toDateTimeString(), Carbon::parse($userPivot->hidden_at)->toDateTimeString());
        $this->assertSame($clearedAt->toDateTimeString(), Carbon::parse($userPivot->cleared_at)->toDateTimeString());
        $this->assertNull($partnerPivot->hidden_at);
        $this->assertFalse($user->chats()->whereKey($chat->id)->exists());
        $this->assertTrue($partner->chats()->whereKey($chat->id)->exists());

        $this->travelTo($newMessageAt);

        Livewire::actingAs($partner)
            ->test(ChatBox::class)
            ->assertSet('selectedChatId', $chat->id)
            ->set('messageText', 'Neue Nachricht')
            ->call('send')
            ->assertHasNoErrors();

        $restoredPivot = DB::table('chat_user')
            ->where('chat_id', $chat->id)
            ->where('user_id', $user->id)
            ->sole();

        $this->assertNull($restoredPivot->hidden_at);
        $this->assertSame($clearedAt->toDateTimeString(), Carbon::parse($restoredPivot->cleared_at)->toDateTimeString());
        $this->assertTrue($user->chats()->whereKey($chat->id)->exists());

        Livewire::actingAs($user)
            ->test(ChatBox::class)
            ->assertSet('selectedChatId', $chat->id)
            ->assertSee('Neue Nachricht')
            ->assertDontSee('Alter Verlauf');
    }

    public function test_chat_list_action_can_delete_an_unselected_chat_without_closing_the_current_chat(): void
    {
        $user = User::factory()->create();
        $currentPartner = User::factory()->create();
        $otherPartner = User::factory()->create();
        $currentChat = $this->directChat($user, $currentPartner);
        $otherChat = $this->directChat($user, $otherPartner);

        Livewire::actingAs($user)
            ->test(ChatBox::class)
            ->call('openChat', $currentChat->id)
            ->assertSet('selectedChatId', $currentChat->id)
            ->call('requestDeleteChat', $otherChat->id)
            ->assertSet('pendingDeleteChatId', $otherChat->id)
            ->assertSet('showDeleteChatModal', true)
            ->call('confirmDeleteChat')
            ->assertSet('pendingDeleteChatId', null)
            ->assertSet('showDeleteChatModal', false)
            ->assertSet('selectedChatId', $currentChat->id);

        $this->assertDatabaseHas('chat_user', [
            'chat_id' => $currentChat->id,
            'user_id' => $user->id,
            'hidden_at' => null,
        ]);
        $this->assertDatabaseMissing('chat_user', [
            'chat_id' => $otherChat->id,
            'user_id' => $user->id,
            'hidden_at' => null,
        ]);
    }

    public function test_message_delete_confirmation_uses_modal_state_and_only_deletes_own_message(): void
    {
        $sender = User::factory()->create();
        $recipient = User::factory()->create();
        $chat = $this->directChat($sender, $recipient);
        $ownMessage = ChatMessage::create([
            'chat_id' => $chat->id,
            'user_id' => $sender->id,
            'body' => 'Eigene Nachricht',
        ]);
        $foreignMessage = ChatMessage::create([
            'chat_id' => $chat->id,
            'user_id' => $recipient->id,
            'body' => 'Fremde Nachricht',
        ]);

        Livewire::actingAs($sender)
            ->test(ChatBox::class)
            ->call('requestDeleteMessage', $ownMessage->id)
            ->assertSet('showDeleteMessageModal', true)
            ->assertSet('pendingDeleteMessageId', $ownMessage->id)
            ->call('confirmDeleteMessage')
            ->assertSet('showDeleteMessageModal', false)
            ->assertSet('pendingDeleteMessageId', null);

        $this->assertDatabaseMissing('chat_messages', ['id' => $ownMessage->id]);
        $this->assertDatabaseHas('chat_messages', ['id' => $foreignMessage->id]);

        Livewire::actingAs($sender)
            ->test(ChatBox::class)
            ->call('requestDeleteMessage', $foreignMessage->id)
            ->assertForbidden();

        $this->assertDatabaseHas('chat_messages', ['id' => $foreignMessage->id]);
    }

    /**
     * @param  array<int, User>  $members
     */
    protected function groupChat(User $owner, array $members, string $name = 'Leitstelle'): Chat
    {
        $chat = Chat::create([
            'type' => 'group',
            'name' => $name,
            'created_by' => $owner->id,
        ]);
        $joinedAt = now()->subDay();
        $participants = collect([$owner, ...$members])->mapWithKeys(
            fn (User $user): array => [$user->id => [
                'last_read_at' => $user->is($owner) ? $joinedAt : null,
                'last_opened_at' => $joinedAt,
                'joined_at' => $joinedAt,
                'hidden_at' => null,
                'cleared_at' => null,
            ]]
        );

        $chat->participants()->attach($participants->all());

        return $chat;
    }

    protected function directChat(User $user, User $partner, ?Carbon $joinedAt = null): Chat
    {
        $chat = Chat::create([
            'type' => 'direct',
            'created_by' => $user->id,
        ]);
        $joinedAt ??= now()->subDay();
        $chat->participants()->attach([
            $user->id => [
                'last_read_at' => $joinedAt,
                'last_opened_at' => $joinedAt,
                'joined_at' => $joinedAt,
                'hidden_at' => null,
                'cleared_at' => null,
            ],
            $partner->id => [
                'last_read_at' => null,
                'last_opened_at' => $joinedAt,
                'joined_at' => $joinedAt,
                'hidden_at' => null,
                'cleared_at' => null,
            ],
        ]);

        return $chat;
    }
}
