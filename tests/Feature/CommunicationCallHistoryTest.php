<?php

namespace Tests\Feature;

use App\Http\Middleware\LogActivity;
use App\Livewire\Calls\CallHistory;
use App\Models\Room;
use App\Models\User;
use App\Services\Calls\LiveKitService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

class CommunicationCallHistoryTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildMinimalRailTimeSchema();
        $this->withoutMiddleware(LogActivity::class);
        config(['broadcasting.default' => 'log', 'call_recording.enabled' => false]);
        Http::preventStrayRequests();
        Queue::fake();
        $this->mock(LiveKitService::class, function ($mock): void {
            $mock->shouldReceive('createRoom')->andReturnTrue();
        });
    }

    public function test_embedded_history_is_one_compact_search_filter_list_without_a_second_meeting_hub(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $room = $this->roomFor($user, 'Eigener Audioanruf');

        $component = Livewire::actingAs($user)->test(CallHistory::class, ['embedded' => true])
            ->assertOk()
            ->assertSeeHtml('rt-floating-toolbar')
            ->assertSeeHtml('wire:model.live.debounce.250ms="search"')
            ->assertSeeHtml('data-floating-search')
            ->assertSeeHtml('wire:click="setFilter(\'active\')"')
            ->assertSeeHtml('role="menuitemradio"')
            ->assertSeeHtml('aria-label="Anrufe filtern"')
            ->assertSeeHtml('data-communication-call-list')
            ->assertSeeHtml('rt-comm-card')
            ->assertSeeHtml('newChat: true')
            ->assertSee($room->name)
            ->assertDontSeeHtml('wire:submit="createMeeting"')
            ->assertDontSee(__('app.calls_history_intro'));
        $new = $this->roomFor($user, 'Später hinzugekommener Anruf');
        $component->dispatch('communication:call-ready', roomUuid: $new->uuid)->assertSee($new->name);
        $component->set('filter', 'active');
        $new->update(['status' => 'ended', 'ended_at' => now()]);
        $component->dispatch('communication:call-released')->assertDontSee($new->name);
    }

    public function test_standalone_history_retains_the_native_meeting_hub(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        Livewire::actingAs($user)->test(CallHistory::class)
            ->assertSeeHtml('wire:submit="createMeeting"')
            ->assertDontSeeHtml('data-communication-call-list');
    }

    public function test_search_and_filters_cannot_reveal_a_foreign_call_and_reset_pagination(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $other = User::factory()->create(['role' => 'admin']);
        $own = $this->roomFor($user, 'Eigene Kontrolle');
        $this->roomFor($user, 'Eigene Besprechung');
        $this->roomFor($other, 'Fremde Kontrolle');

        Livewire::actingAs($user)->test(CallHistory::class, ['embedded' => true])
            ->set('search', 'Kontrolle')
            ->assertSee($own->name)
            ->assertDontSee('Eigene Besprechung')
            ->assertDontSee('Fremde Kontrolle')
            ->set('filter', 'active')
            ->assertSee($own->name)
            ->set('filter', 'missed')
            ->assertDontSee($own->name)
            ->set('filter', 'invalid')
            ->assertSet('filter', 'all')
            ->assertSet('paginators.page', 1)
            ->set('search', 'Keine Treffer')
            ->assertSee(__('app.calls_history_empty'))
            ->assertDontSee($own->name);
    }

    public function test_embedded_meeting_start_dispatches_the_authorized_room_without_navigation(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $component = Livewire::actingAs($user)->test(CallHistory::class, ['embedded' => true])
            ->set('name', 'Isoliertes eingebettetes Meeting')
            ->set('video', false)
            ->call('createMeeting')
            ->assertNoRedirect();
        $room = Room::where('name', 'Isoliertes eingebettetes Meeting')->firstOrFail();
        $component->assertDispatched('communication:call', roomUuid: $room->uuid);
        $this->assertSame($user->id, $room->owner_id);
        $this->assertFalse($room->startsWithVideo());
        $this->assertDatabaseHas('room_participants', ['room_id' => $room->id, 'user_id' => $user->id, 'role' => 'host']);
        $this->assertNotNull($room->call_chat_id);
    }

    public function test_embedded_meeting_join_preserves_the_server_participation_contract(): void
    {
        $host = User::factory()->create(['role' => 'admin']);
        $guest = User::factory()->create(['role' => 'admin']);
        $room = $this->roomFor($host, 'Offene Besprechung');

        Livewire::actingAs($guest)->test(CallHistory::class, ['embedded' => true])
            ->call('join', $room->id)
            ->assertNoRedirect()
            ->assertDispatched('communication:call', roomUuid: $room->uuid);
        $this->assertDatabaseHas('room_participants', ['room_id' => $room->id, 'user_id' => $guest->id, 'connection' => 'invited']);
    }

    public function test_standalone_join_still_redirects_and_removed_participants_stay_blocked(): void
    {
        $host = User::factory()->create(['role' => 'admin']);
        $guest = User::factory()->create(['role' => 'admin']);
        $room = $this->roomFor($host, 'Standalone Besprechung');
        Livewire::actingAs($guest)->test(CallHistory::class)
            ->call('join', $room->id)->assertRedirect(route('calls.window', $room));
        $room->participants()->where('user_id', $guest->id)->update(['connection' => 'disconnected', 'left_at' => now()]);
        Livewire::actingAs($guest)->test(CallHistory::class, ['embedded' => true])
            ->call('join', $room->id)->assertForbidden()->assertNotDispatched('communication:call');
        $this->assertSame('disconnected', $room->participants()->where('user_id', $guest->id)->value('connection'));
    }

    public function test_private_meeting_without_planning_schema_fails_closed_before_creating_participation(): void
    {
        $host = User::factory()->create(['role' => 'admin']);
        $guest = User::factory()->create(['role' => 'admin']);
        $room = $this->roomFor($host, 'Privater Meetingraum');
        $room->forceFill(['settings' => ['communication_meeting_id' => 999]])->save();
        Livewire::actingAs($guest)->test(CallHistory::class, ['embedded' => true])
            ->assertDontSee($room->name)
            ->call('join', $room->id)->assertForbidden()->assertNotDispatched('communication:call');
        $this->assertDatabaseMissing('room_participants', ['room_id' => $room->id, 'user_id' => $guest->id]);
        $this->assertDatabaseCount('room_invitations', 0);
    }

    public function test_embedded_pagination_does_not_inherit_or_publish_the_workspace_page(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        for ($index = 1; $index <= 17; $index++) {
            $this->roomFor($user, 'Pager '.$index);
        }

        $embedded = Livewire::withQueryParams(['page' => 4])->actingAs($user)
            ->test(CallHistory::class, ['embedded' => true])
            ->assertSet('paginators.page', 1)
            ->call('gotoPage', 2)
            ->assertSet('paginators.page', 2);
        $this->assertArrayNotHasKey('url', $embedded->effects);

        $standalone = Livewire::withQueryParams(['page' => 2])->actingAs($user)
            ->test(CallHistory::class)->assertSet('paginators.page', 2)
            ->call('gotoPage', 1)->assertSet('paginators.page', 1);
        $this->assertArrayNotHasKey('url', $standalone->effects);
    }

    private function roomFor(User $owner, string $name): Room
    {
        $room = Room::create(['name' => $name, 'type' => 'meeting', 'status' => 'active', 'owner_id' => $owner->id]);
        $room->participants()->create(['user_id' => $owner->id, 'role' => 'host', 'connection' => 'joined', 'livekit_identity' => 'user-'.$owner->id]);

        return $room;
    }
}
