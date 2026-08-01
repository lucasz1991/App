<?php

namespace Tests\Feature;

use App\Events\CallInvitationRinging;
use App\Http\Middleware\LogActivity;
use App\Livewire\Calls\CallWindow;
use App\Livewire\Calls\IncomingCallOverlay;
use App\Models\Chat;
use App\Models\Room;
use App\Models\User;
use App\Notifications\RailtimeWebPushNotification;
use App\Services\Calls\CallInvitationService;
use App\Services\Calls\LiveKitService;
use App\Support\Push\PushDelivery;
use App\Support\Sound\SoundLibrary;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Minishlink\WebPush\VAPID;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

/**
 * Klingeln, hoerbar und sichtbar.
 *
 * Beim Angerufenen laeuft ein vollstaendiges Klingelmotiv in Schleife, beim
 * Anrufer das Freizeichen. Entscheidend ist der Unterschied zwischen "wird
 * angerufen" (Einladung nur verschickt) und "klingelt" (die Gegenseite meldet,
 * dass ihr Geraet wirklich laeutet) — gemeldet aus einer offenen Browser-
 * Sitzung oder aus dem Service Worker der installierten App.
 */
class CallRingingSignalTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildMinimalRailTimeSchema();
        $this->withoutMiddleware(LogActivity::class);

        config(['broadcasting.default' => 'log']);
        Queue::fake();

        $this->partialMock(LiveKitService::class, function ($mock): void {
            foreach (['createRoom', 'deleteRoom', 'removeParticipant', 'muteParticipantAudio', 'setParticipantPublishing'] as $method) {
                $mock->shouldReceive($method)->andReturn(true);
            }
        });
    }

    public function test_a_call_rings_with_a_full_ringtone_not_a_single_chime(): void
    {
        // Das Ereignis 'call' darf nicht auf einem Einzelanschlag liegen.
        $this->assertSame('ring', SoundLibrary::defaults()['call']);

        $script = File::get(public_path('js/rt-sounds.js'));

        // Der Klingelton wiederholt sein Motiv (zwei Durchlaeufe je Aufruf) ...
        $this->assertMatchesRegularExpression('/^\s+ring\s*:\s*function/m', $script);
        $this->assertStringContainsString('[0, 0.62].forEach', $script);

        // ... und laeuft in Schleife, statt einmal zu piepen.
        $this->assertStringContainsString('startRinging:', $script);
        $this->assertStringContainsString('stopRinging:', $script);
        $this->assertStringContainsString('setInterval(tick', $script);
    }

    public function test_the_caller_hears_a_repeating_ringback_tone(): void
    {
        $script = File::get(public_path('js/rt-sounds.js'));

        // Freizeichen: gerader Ton mit flacher Huellkurve, nicht ausklingend.
        $this->assertMatchesRegularExpression('/^\s+ringback\s*:\s*function/m', $script);
        $this->assertStringContainsString('from: 425', $script);
        $this->assertStringContainsString('hold: 0.9', $script);
        $this->assertStringContainsString('startRingback:', $script);
        $this->assertStringContainsString('stopRingback:', $script);

        $calls = File::get(resource_path('js/calls.js'));

        // Der Ton haengt am Zustand, nicht an einer einzelnen Stelle: er endet
        // beim Fehler, beim Auflegen und sobald wirklich jemand da ist.
        $this->assertStringContainsString('syncRingback()', $calls);
        $this->assertStringContainsString('this.remoteCount === 0', $calls);
        $this->assertStringContainsString('startRingback()', $calls);
        $this->assertStringContainsString('stopRingback()', $calls);
    }

    public function test_the_call_window_shows_a_central_signal_with_a_caption(): void
    {
        $blade = File::get(resource_path('views/livewire/calls/call-window.blade.php'));

        // Animation in der Mitte ...
        $this->assertStringContainsString('rt-call-signal', $blade);
        $this->assertSame(3, substr_count($blade, 'rt-call-signal__wave'));
        $this->assertStringContainsString('rt-call-signal--live', $blade);

        // ... mit kleinem Text darunter, der beide Zustaende benennt.
        $this->assertStringContainsString('outgoingRinging ? ringStatusLabel : statusLabel', $blade);
        $this->assertStringContainsString("beingCalled: @js(__('app.calls_being_called'))", $blade);
        $this->assertStringContainsString("ringsThere: @js(__('app.calls_rings_there'))", $blade);
        $this->assertSame('wird angerufen', __('app.calls_being_called', [], 'de'));
        $this->assertSame('klingelt', __('app.calls_rings_there', [], 'de'));

        // Das vorgebaute Tailwind setzt Utilities mit !important — Sichtbarkeit
        // muss deshalb ueber x-show.important laufen.
        $this->assertStringContainsString('x-show.important="! failed && (! connected || outgoingRinging)"', $blade);

        // Und die Wellen-Animation existiert wirklich im Stylesheet.
        $css = File::get(resource_path('css/app.css'));
        $this->assertStringContainsString('@keyframes rt-call-wave', $css);
        $this->assertStringContainsString('.rt-call-signal--live .rt-call-signal__core', $css);
    }

    public function test_the_own_video_preview_follows_the_device_format(): void
    {
        $blade = File::get(resource_path('views/livewire/calls/call-window.blade.php'));

        // Kein festes Hochformat mehr in den Klassen ...
        $this->assertStringNotContainsString('aspect-[9/16]', $blade);
        $this->assertStringContainsString(':style="selfPreviewStyle"', $blade);

        $calls = File::get(resource_path('js/calls.js'));

        // ... sondern hochkant nur beim aufrecht gehaltenen Touch-Geraet.
        $this->assertStringContainsString("matchMedia('(orientation: portrait) and (pointer: coarse)')", $calls);
        $this->assertStringContainsString("'9 / 16' : '16 / 9'", $calls);
        $this->assertStringContainsString('aspect-ratio:', $calls);

        // Format-, Buehnen- und Panelwechsel vermessen dieselbe Vorschau neu,
        // ohne ihre letzte sichere Position zu verlieren.
        $this->assertStringContainsString('new ResizeObserver(refresh)', $calls);
        $this->assertStringContainsString("addEventListener('orientationchange', refresh", $calls);
        $this->assertStringContainsString('this.selfPreviewLastSafeX', $calls);
    }

    public function test_the_own_video_preview_can_be_docked_and_restored_accessibly(): void
    {
        $blade = File::get(resource_path('views/livewire/calls/call-window.blade.php'));
        $calls = File::get(resource_path('js/calls.js'));

        $this->assertStringContainsString('data-rt-self-preview-stage', $blade);
        $this->assertStringContainsString('data-rt-self-preview-minimize', $blade);
        $this->assertStringContainsString('data-rt-self-preview-restore', $blade);
        $this->assertStringContainsString('h-11 w-11', $blade);
        $this->assertStringContainsString("__('app.calls_minimize_self_video')", $blade);
        $this->assertStringContainsString("__('app.calls_restore_self_video')", $blade);

        $this->assertStringContainsString('isSelfPreviewCenterOutside(candidate', $calls);
        $this->assertStringContainsString('this.minimizeSelfPreview(candidate)', $calls);
        $this->assertStringContainsString('this.selfPreviewMinimized = false;', $calls);
        $this->assertStringContainsString('this.selfPreviewResizeCleanup?.();', $calls);

        // Die lokale Kamera gewinnt im PiP gegen die eigene Freigabe; remote
        // bleibt eine Bildschirmfreigabe die bevorzugte grosse Kachel.
        $this->assertStringContainsString('isLocal ? Track.Source.Camera : Track.Source.ScreenShare', $calls);
    }

    public function test_an_open_browser_session_reports_that_it_is_ringing(): void
    {
        Event::fake([CallInvitationRinging::class]);

        [$caller, $callee, $chat] = $this->twoUsersWithCallRights();
        $room = $this->roomWithInvitation($caller, $callee, $chat);
        $invitation = $room->invitations()->firstOrFail();

        $this->assertNull($invitation->ringing_at);

        Livewire::actingAs($callee)
            ->test(IncomingCallOverlay::class)
            ->call('reportRinging', $invitation->id);

        $invitation->refresh();
        $this->assertNotNull($invitation->ringing_at);
        $this->assertSame('browser', $invitation->ringing_via);

        Event::assertDispatchedTimes(CallInvitationRinging::class, 1);

        // Zweite Meldung desselben Geraets aendert nichts und sendet nicht erneut.
        Livewire::actingAs($callee)
            ->test(IncomingCallOverlay::class)
            ->call('reportRinging', $invitation->id);

        Event::assertDispatchedTimes(CallInvitationRinging::class, 1);
    }

    public function test_the_overlay_reports_ringing_as_soon_as_it_appears(): void
    {
        $blade = File::get(resource_path('views/livewire/calls/incoming-call-overlay.blade.php'));

        $this->assertStringContainsString('window.RTSound?.startRinging()', $blade);
        $this->assertStringContainsString('window.RTSound?.stopRinging()', $blade);
        $this->assertStringContainsString('$wire.reportRinging(detail.invitationId)', $blade);

        // Der alte Einzelton-Aufruf ist verschwunden.
        $this->assertStringNotContainsString("play('call')", $blade);
    }

    public function test_a_foreign_invitation_cannot_be_reported_as_ringing(): void
    {
        [$caller, $callee, $chat] = $this->twoUsersWithCallRights();
        $room = $this->roomWithInvitation($caller, $callee, $chat);
        $invitation = $room->invitations()->firstOrFail();

        $stranger = User::factory()->create();

        Livewire::actingAs($stranger)
            ->test(IncomingCallOverlay::class)
            ->call('reportRinging', $invitation->id);

        $this->assertNull($invitation->fresh()->ringing_at);
    }

    public function test_the_installed_app_reports_ringing_through_a_signed_url(): void
    {
        Event::fake([CallInvitationRinging::class]);

        [$caller, $callee, $chat] = $this->twoUsersWithCallRights();
        $room = $this->roomWithInvitation($caller, $callee, $chat);
        $invitation = $room->invitations()->firstOrFail();

        // Ohne Signatur kein Zugriff: die URL IST die Autorisierung.
        $this->postJson(route('calls.ring-ack', ['invitation' => $invitation->id]))
            ->assertForbidden();
        $this->assertNull($invitation->fresh()->ringing_at);

        // Der Service Worker ruft ohne Sitzung auf — die Signatur genuegt.
        $signed = URL::temporarySignedRoute(
            'calls.ring-ack',
            now()->addMinutes(5),
            ['invitation' => $invitation->id],
        );

        $this->postJson($signed)
            ->assertOk()
            ->assertJson(['ringing' => true]);

        $invitation->refresh();
        $this->assertNotNull($invitation->ringing_at);
        $this->assertSame('app', $invitation->ringing_via);
        Event::assertDispatchedTimes(CallInvitationRinging::class, 1);
    }

    public function test_the_push_payload_carries_the_ring_acknowledgement_url(): void
    {
        // Ohne konfiguriertes Web-Push und ein echtes Abo liefert via() ein
        // leeres Kanal-Array – der Fake wuerde dann gar nichts aufzeichnen.
        $vapid = VAPID::createVapidKeys();
        config([
            'webpush.enabled' => true,
            'webpush.vapid.subject' => 'mailto:webpush@example.test',
            'webpush.vapid.public_key' => $vapid['publicKey'],
            'webpush.vapid.private_key' => $vapid['privateKey'],
        ]);
        Notification::fake();

        [$caller, $callee, $chat] = $this->twoUsersWithCallRights();
        $callee->enableDefaultPushPreferences();
        $callee->pushSubscriptions()->create([
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/ring-ack',
            'public_key' => 'public-key',
            'auth_token' => 'auth-token',
            'content_encoding' => 'aes128gcm',
        ]);

        $room = $this->roomWithInvitation($caller, $callee, $chat);
        $invitation = $room->invitations()->firstOrFail();

        app(PushDelivery::class)->incomingCall($invitation);

        Notification::assertSentTo(
            $callee,
            RailtimeWebPushNotification::class,
            function (RailtimeWebPushNotification $notification): bool {
                $url = $notification->extraData['ring_ack_url'] ?? null;

                return is_string($url)
                    && str_contains($url, '/calls/ring/')
                    && str_contains($url, 'signature=');
            },
        );

        // Und der Service Worker meldet erst NACH dem Anzeigen zurueck.
        $worker = File::get(public_path('service-worker.js'));
        $this->assertStringContainsString('reportRinging(data.ring_ack_url)', $worker);
        $this->assertStringContainsString('isInsideRegistrationScope(target)', $worker);
    }

    public function test_the_call_window_lists_who_is_still_being_called(): void
    {
        [$caller, $callee, $chat] = $this->twoUsersWithCallRights();
        $room = $this->roomWithInvitation($caller, $callee, $chat);
        $invitation = $room->invitations()->firstOrFail();

        $component = Livewire::actingAs($caller)->test(CallWindow::class, ['room' => $room]);

        $waiting = $component->instance()->waitingFor();
        $this->assertCount(1, $waiting);
        $this->assertSame($callee->name, $waiting[0]['name']);
        $this->assertFalse($waiting[0]['ringing'], 'Ohne Rueckmeldung gilt nur "wird angerufen".');

        // Sobald die Gegenseite laeutet, wechselt der Zustand — und das Fenster
        // bekommt ihn als Browser-Ereignis, weil Alpine x-data nach einem
        // Livewire-Re-Render nicht neu auswertet.
        app(CallInvitationService::class)->markRinging($invitation, 'browser');

        $component->call('onWaitingChanged')->assertDispatched('rt-call-waiting');

        $waiting = $component->instance()->waitingFor();
        $this->assertTrue($waiting[0]['ringing']);

        // Angenommen heisst: raus aus der Warteliste, Freizeichen endet.
        app(CallInvitationService::class)->accept($invitation->fresh());
        $this->assertSame([], $component->instance()->waitingFor());
    }

    public function test_an_answered_or_expired_invitation_is_no_longer_ringing(): void
    {
        [$caller, $callee, $chat] = $this->twoUsersWithCallRights();
        $room = $this->roomWithInvitation($caller, $callee, $chat);
        $invitation = $room->invitations()->firstOrFail();

        app(CallInvitationService::class)->decline($invitation);

        // Eine beantwortete Einladung darf nicht nachtraeglich zu klingeln
        // beginnen — sonst stuende beim Anrufer "klingelt", waehrend die
        // Gegenseite laengst abgelehnt hat.
        $this->assertFalse(app(CallInvitationService::class)->markRinging($invitation->fresh()));
        $this->assertNull($invitation->fresh()->ringing_at);
    }

    /** @return array{0: User, 1: User, 2: Chat} */
    protected function twoUsersWithCallRights(): array
    {
        $caller = User::factory()->create();
        $callee = User::factory()->create();

        foreach ([$caller, $callee] as $user) {
            $team = $user->ownedTeams()->create([
                'name' => 'Team '.$user->id,
                'personal_team' => true,
                'rbac_permissions' => ['calls.start' => true, 'calls.join' => true],
            ]);

            $user->forceFill(['current_team_id' => $team->id])->save();
        }

        $chat = Chat::create(['type' => 'direct', 'created_by' => $caller->id]);
        $chat->participants()->attach([
            $caller->id => ['last_read_at' => now(), 'last_opened_at' => null],
            $callee->id => ['last_read_at' => now(), 'last_opened_at' => null],
        ]);

        return [$caller, $callee, $chat];
    }

    protected function roomWithInvitation(User $caller, User $callee, Chat $chat): Room
    {
        $room = Room::create([
            'name' => 'Testanruf',
            'type' => 'direct',
            'status' => 'pending',
            'owner_id' => $caller->id,
            'chat_id' => $chat->id,
        ]);

        $room->participants()->create([
            'user_id' => $caller->id,
            'role' => 'host',
            'connection' => 'invited',
            'livekit_identity' => 'user-'.$caller->id,
        ]);

        app(CallInvitationService::class)->inviteOne($room, $caller, $callee);

        return $room;
    }
}
