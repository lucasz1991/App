<?php

namespace Tests\Feature\Livewire;

use App\Livewire\Messages\MessageViewerModal;
use App\Models\Message;
use App\Models\User;
use Livewire\Livewire;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

class MessageViewerModalTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildMinimalRailTimeSchema();
    }

    public function test_open_event_loads_own_message_marks_it_as_read_and_resets_on_close(): void
    {
        $sender = User::factory()->create(['role' => 'admin']);
        $recipient = User::factory()->create();
        $message = Message::create([
            'subject' => 'Dienstplan aktualisiert',
            'message' => "Bitte prüfe die neue Einteilung.\nDanke.",
            'from_user' => $sender->id,
            'to_user' => $recipient->id,
            'status' => '1',
        ]);

        $component = Livewire::actingAs($recipient)
            ->test(MessageViewerModal::class)
            ->dispatch('message-viewer:open', messageId: $message->id)
            ->assertSet('isOpen', true)
            ->assertSet('message.id', $message->id)
            ->assertSee('Dienstplan aktualisiert')
            ->assertDispatched('inbox:refresh');

        $this->assertDatabaseHas('messages', [
            'id' => $message->id,
            'status' => '2',
        ]);

        $component->call('close')
            ->assertSet('isOpen', false)
            ->assertSet('message', null);
    }

    public function test_open_event_never_exposes_another_users_message(): void
    {
        $sender = User::factory()->create(['role' => 'admin']);
        $recipient = User::factory()->create();
        $otherUser = User::factory()->create();
        $message = Message::create([
            'subject' => 'Nur für den Empfänger',
            'message' => 'Vertraulicher Inhalt',
            'from_user' => $sender->id,
            'to_user' => $recipient->id,
            'status' => '1',
        ]);

        Livewire::actingAs($otherUser)
            ->test(MessageViewerModal::class)
            ->dispatch('message-viewer:open', messageId: $message->id)
            ->assertSet('isOpen', false)
            ->assertSet('message', null)
            ->assertDontSee('Vertraulicher Inhalt');

        $this->assertDatabaseHas('messages', [
            'id' => $message->id,
            'status' => '1',
        ]);
    }

    public function test_message_body_is_rendered_as_text_instead_of_executable_html(): void
    {
        $sender = User::factory()->create(['role' => 'admin']);
        $recipient = User::factory()->create();
        $message = Message::create([
            'subject' => 'Sichere Ausgabe',
            'message' => '<script>alert("x")</script>',
            'from_user' => $sender->id,
            'to_user' => $recipient->id,
            'status' => '1',
        ]);

        Livewire::actingAs($recipient)
            ->test(MessageViewerModal::class)
            ->dispatch('message-viewer:open', messageId: $message->id)
            ->assertSee('&lt;script&gt;', escape: false)
            ->assertDontSee('<script>alert("x")</script>', escape: false);
    }
}
