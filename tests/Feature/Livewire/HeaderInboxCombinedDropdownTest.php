<?php

namespace Tests\Feature\Livewire;

use App\Livewire\Tools\HeaderInbox;
use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\Message;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

/**
 * Chats und Nachrichten liegen in EINEM Topbar-Dropdown mit EINEM Zaehler,
 * der beide Bereiche zusammenzaehlt.
 */
class HeaderInboxCombinedDropdownTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildMinimalRailTimeSchema();

        // Wie in den uebrigen Chat-Tests: die chats-Tabelle gehoert nicht zum
        // gemeinsamen Minimalschema und wird hier ergaenzt.
        Schema::create('chats', function (Blueprint $table): void {
            $table->id();
            $table->string('type')->default('direct');
            $table->string('name')->nullable();
            $table->unsignedBigInteger('created_by');
            $table->timestamps();
        });
    }

    public function test_one_trigger_shows_the_combined_unread_count_for_chats_and_messages(): void
    {
        $sender = User::factory()->create(['role' => 'admin']);
        $recipient = User::factory()->create();

        // Zwei ungelesene Posteingangs-Nachrichten ...
        foreach (['Dienstinfo', 'Zweite Info'] as $subject) {
            Message::create([
                'subject' => $subject,
                'message' => 'Bitte lesen.',
                'from_user' => $sender->id,
                'to_user' => $recipient->id,
                'status' => 1,
            ]);
        }

        // ... und eine ungelesene Chat-Nachricht.
        $chat = Chat::create(['type' => 'direct', 'created_by' => $sender->id]);
        $chat->participants()->attach([$sender->id, $recipient->id]);
        ChatMessage::create([
            'chat_id' => $chat->id,
            'user_id' => $sender->id,
            'body' => 'Hallo aus dem Chat!',
        ]);

        $component = Livewire::actingAs($recipient)
            ->test(HeaderInbox::class)
            ->assertSet('unreadMessagesCount', 2)
            ->assertSet('unreadChatMessagesCount', 1);

        // Genau EIN Trigger, dessen Zaehler beides zusammenfasst (2 + 1).
        $html = $component->html();
        $this->assertSame(1, substr_count($html, 'data-inbox-trigger="true"'));
        $this->assertStringContainsString('data-inbox-total="3"', $html);

        // Beide Bereiche stecken im selben Dropdown ...
        $component
            ->assertSee(__('app.chats'))
            ->assertSee(__('app.messages'))
            ->assertSee(__('app.view_all_chats'))
            ->assertSee(__('app.view_all_messages'))
            // ... inklusive Chat-Eintrag mit Deep-Link und Nachrichten-Betreff.
            ->assertSee('Hallo aus dem Chat!')
            ->assertSee('Dienstinfo')
            ->assertSee(route('chat', ['chat' => $chat->id]), escape: false);
    }

    public function test_trigger_reports_zero_without_any_unread_entries(): void
    {
        $user = User::factory()->create();

        $html = Livewire::actingAs($user)
            ->test(HeaderInbox::class)
            ->assertSet('unreadMessagesCount', 0)
            ->assertSet('unreadChatMessagesCount', 0)
            ->assertSee(__('app.no_chats'))
            ->assertSee(__('app.no_messages'))
            ->html();

        $this->assertStringContainsString('data-inbox-total="0"', $html);
    }

    public function test_view_once_chat_messages_are_not_previewed_in_the_dropdown(): void
    {
        $sender = User::factory()->create();
        $recipient = User::factory()->create();

        $chat = Chat::create(['type' => 'direct', 'created_by' => $sender->id]);
        $chat->participants()->attach([$sender->id, $recipient->id]);
        ChatMessage::create([
            'chat_id' => $chat->id,
            'user_id' => $sender->id,
            'body' => 'Streng geheimer Einmal-Inhalt',
            'view_once' => true,
        ]);

        Livewire::actingAs($recipient)
            ->test(HeaderInbox::class)
            ->assertDontSee('Streng geheimer Einmal-Inhalt');
    }
}
