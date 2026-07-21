<?php

namespace Tests\Feature;

use App\Livewire\ChatBox;
use App\Models\Chat;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

class ChatLastSelectionTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildMinimalRailTimeSchema();

        Schema::create('chats', function (Blueprint $table): void {
            $table->id();
            $table->string('type')->default('direct');
            $table->string('name')->nullable();
            $table->unsignedBigInteger('created_by');
            $table->timestamps();
        });
    }

    public function test_last_opened_chat_is_restored_and_a_new_selection_is_persisted(): void
    {
        $user = User::factory()->create();
        $partner = User::factory()->create();
        $older = Chat::create(['type' => 'direct', 'created_by' => $user->id]);
        $newer = Chat::create(['type' => 'direct', 'created_by' => $user->id]);

        $older->participants()->attach([
            $user->id => ['last_opened_at' => now()->subHours(2)],
            $partner->id => ['last_opened_at' => null],
        ]);
        $newer->participants()->attach([
            $user->id => ['last_opened_at' => now()->subHour()],
            $partner->id => ['last_opened_at' => null],
        ]);

        $this->actingAs($user);

        $firstVisit = app(ChatBox::class);
        $firstVisit->mount();
        $this->assertSame($newer->id, $firstVisit->selectedChatId);

        $firstVisit->openChat($older->id);

        $returnVisit = app(ChatBox::class);
        $returnVisit->mount();
        $this->assertSame($older->id, $returnVisit->selectedChatId);
    }

    public function test_most_recent_chat_is_selected_when_none_was_opened_before(): void
    {
        $user = User::factory()->create();
        $partner = User::factory()->create();
        $chat = Chat::create(['type' => 'direct', 'created_by' => $user->id]);

        $chat->participants()->attach([
            $user->id => ['last_opened_at' => null],
            $partner->id => ['last_opened_at' => null],
        ]);

        $this->actingAs($user);

        $component = app(ChatBox::class);
        $component->mount();

        $this->assertSame($chat->id, $component->selectedChatId);
    }
}
