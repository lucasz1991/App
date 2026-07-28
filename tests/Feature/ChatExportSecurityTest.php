<?php

namespace Tests\Feature;

use App\Http\Middleware\LogActivity;
use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\User;
use Illuminate\Support\Carbon;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

class ChatExportSecurityTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(LogActivity::class);
        $this->buildMinimalRailTimeSchema();
    }

    public function test_only_non_hidden_participants_can_export_a_chat(): void
    {
        $owner = User::factory()->create();
        $hiddenParticipant = User::factory()->create();
        $adminOutsider = User::factory()->create(['role' => 'admin']);
        $chat = Chat::create(['type' => 'group', 'name' => 'Leitstelle', 'created_by' => $owner->id]);

        $chat->participants()->attach([
            $owner->id => [
                'joined_at' => now()->subDay(),
                'hidden_at' => null,
            ],
            $hiddenParticipant->id => [
                'joined_at' => now()->subDay(),
                'hidden_at' => now(),
            ],
        ]);

        $this->actingAs($owner)
            ->get(route('chat.export', $chat))
            ->assertOk();

        $this->actingAs($hiddenParticipant)
            ->get(route('chat.export', $chat))
            ->assertForbidden();

        // Adminrechte duerfen die explizite Chat-Teilnahme nicht umgehen.
        $this->actingAs($adminOutsider)
            ->get(route('chat.export', $chat))
            ->assertForbidden();
    }

    public function test_export_streams_the_complete_visible_history_safely(): void
    {
        $sender = User::factory()->create(['name' => '=Boeser Absender']);
        $recipient = User::factory()->create();
        $chat = Chat::create(['type' => 'direct', 'created_by' => $sender->id]);
        $joinedAt = Carbon::parse('2026-07-25 08:00:00');
        $clearedAt = Carbon::parse('2026-07-25 10:00:00');

        $chat->participants()->attach([
            $sender->id => [
                'joined_at' => $joinedAt,
                'hidden_at' => null,
                'cleared_at' => null,
            ],
            $recipient->id => [
                'joined_at' => $joinedAt,
                'hidden_at' => null,
                'cleared_at' => $clearedAt,
            ],
        ]);

        $this->travelTo(Carbon::parse('2026-07-25 09:00:00'));
        ChatMessage::create([
            'chat_id' => $chat->id,
            'user_id' => $sender->id,
            'body' => 'Vor dem Leeren verborgen',
        ]);

        $this->travelTo(Carbon::parse('2026-07-25 11:00:00'));
        $formulaMessage = null;

        foreach (range(0, 204) as $index) {
            $message = ChatMessage::create([
                'chat_id' => $chat->id,
                'user_id' => $sender->id,
                'body' => $index === 0 ? '=HYPERLINK("https://example.invalid")' : "Sichtbare Nachricht {$index}",
            ]);

            $formulaMessage ??= $message;
        }

        ChatMessage::create([
            'chat_id' => $chat->id,
            'user_id' => $sender->id,
            'body' => 'Einmal-Geheimnis',
            'view_once' => true,
        ]);

        $formulaMessage->files()->create([
            'name' => '=payload.csv',
            'path' => 'uploads/chat/geheimer-speicherpfad.csv',
            'disk' => 'private',
            'mime_type' => 'text/csv',
            'size' => 42,
        ]);

        $response = $this->actingAs($recipient)
            ->get(route('chat.export', $chat))
            ->assertOk()
            ->assertHeader('x-content-type-options', 'nosniff');

        $this->assertStringContainsString('text/csv', (string) $response->headers->get('content-type'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('cache-control'));

        $content = $response->streamedContent();

        $this->assertStringStartsWith("\xEF\xBB\xBF", $content);
        $this->assertCount(206, explode("\n", trim($content)));
        $this->assertStringContainsString('Sichtbare Nachricht 204', $content);
        $this->assertStringNotContainsString('Vor dem Leeren verborgen', $content);
        $this->assertStringNotContainsString('Einmal-Geheimnis', $content);
        $this->assertStringContainsString('\'=HYPERLINK', $content);
        $this->assertStringContainsString('\'=Boeser Absender', $content);
        $this->assertStringContainsString('\'=payload.csv [text/csv, 42 Bytes]', $content);
        $this->assertStringNotContainsString('geheimer-speicherpfad', $content);
    }
}
