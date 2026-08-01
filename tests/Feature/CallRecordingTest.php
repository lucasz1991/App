<?php

namespace Tests\Feature;

use Agence104\LiveKit\AccessToken;
use Agence104\LiveKit\AccessTokenOptions;
use App\Contracts\Calls\CallEgressGateway;
use App\Enums\RoomRecordingStatus;
use App\Http\Controllers\Calls\CallRecordingAcknowledgementController;
use App\Http\Controllers\Calls\CallRecordingPlaybackController;
use App\Http\Middleware\VerifyCsrfToken;
use App\Jobs\EnforceCallRecordingStartDeadline;
use App\Jobs\PurgeExpiredCallRecordings;
use App\Jobs\StartRoomRecording;
use App\Models\CallRecordingAcknowledgement;
use App\Models\LiveKitWebhookReceipt;
use App\Models\Room;
use App\Models\RoomRecording;
use App\Models\User;
use App\Services\Calls\CallRecordingService;
use App\Services\Calls\LiveKitEgressGateway;
use App\Services\Calls\LiveKitService;
use App\Services\Calls\RoomEventRecorder;
use App\Services\Calls\TimeoutAwareEgressServiceClient;
use App\Support\Calls\EgressSnapshot;
use Carbon\CarbonImmutable;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livekit\EgressInfo;
use Livekit\EgressStatus;
use Livekit\EncodedFileOutput;
use Livekit\EncodedFileType;
use Livekit\EncodingOptions;
use Livekit\FileInfo;
use Livekit\VideoCodec;
use Livekit\WebhookEvent;
use Mockery\MockInterface;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

class CallRecordingTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildMinimalRailTimeSchema();
        Queue::fake();
        $this->withoutMiddleware(VerifyCsrfToken::class);

        config([
            'broadcasting.default' => 'log',
            'session.driver' => 'array',
            'livekit.api_key' => 'RTTESTKEY',
            'livekit.api_secret' => str_repeat('s', 40),
            'call_recording.enabled' => true,
            'call_recording.policy_version' => 'policy-v1',
            'call_recording.start_deadline_seconds' => 30,
            'call_recording.retention_days' => 90,
            'call_recording.storage_disk' => 'call_recordings',
        ]);

        $this->mock(CallEgressGateway::class, function (MockInterface $mock): void {
            $mock->shouldReceive('isConfigured')->andReturnTrue()->byDefault();
        });

        Route::post('/_testing/calls/recording/acknowledge', CallRecordingAcknowledgementController::class);
        Route::get(
            '/_testing/calls/{room:uuid}/recordings/{recording:uuid}',
            CallRecordingPlaybackController::class,
        )->middleware(SubstituteBindings::class);
    }

    public function test_current_policy_must_be_explicitly_acknowledged_once(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/_testing/calls/recording/acknowledge', [
                'accepted' => true,
                'policyVersion' => 'outdated',
            ])
            ->assertUnprocessable();

        $this->actingAs($user)
            ->postJson('/_testing/calls/recording/acknowledge', [
                'accepted' => true,
                'policyVersion' => 'policy-v1',
            ])
            ->assertOk()
            ->assertJsonPath('acknowledged', true)
            ->assertJsonPath('policyVersion', 'policy-v1');

        $this->actingAs($user)
            ->postJson('/_testing/calls/recording/acknowledge', [
                'accepted' => true,
                'policyVersion' => 'policy-v1',
            ])
            ->assertOk();

        $this->assertSame(1, CallRecordingAcknowledgement::count());
    }

    public function test_token_endpoint_requires_acknowledgement_and_then_issues_a_non_publishing_token(): void
    {
        [$owner, $room] = $this->roomWithOwner();

        $this->actingAs($owner)
            ->postJson(route('calls.token', $room))
            ->assertStatus(428)
            ->assertJsonPath('recording.requiresAcknowledgement', true)
            ->assertJsonPath('recording.policyVersion', 'policy-v1');

        $acknowledgement = app(CallRecordingService::class)->acknowledge($owner, 'policy-v1');

        $response = $this->actingAs($owner)
            ->postJson(route('calls.token', $room))
            ->assertOk()
            ->assertJsonPath('recording.status', RoomRecordingStatus::Requested->value)
            ->assertJsonPath('recording.canPublish', false);

        $claims = $this->decodeJwtPayload($response->json('token'));

        $this->assertFalse($claims['video']['canPublish']);
        $this->assertTrue($claims['video']['canSubscribe']);
        $this->assertSame($acknowledgement->id, $room->participantFor($owner)->fresh()->call_recording_acknowledgement_id);
        $this->assertStringNotContainsString($owner->name, RoomRecording::firstOrFail()->storage_key);
        $this->assertStringNotContainsString($owner->email, RoomRecording::firstOrFail()->storage_key);

        Queue::assertPushed(StartRoomRecording::class);
        Queue::assertPushed(EnforceCallRecordingStartDeadline::class);
    }

    public function test_recording_object_path_uses_the_private_team_uuid_scope(): void
    {
        [$owner, $room] = $this->roomWithOwner();
        $team = $owner->ownedTeams()->create([
            'name' => 'Recording Team',
            'personal_team' => true,
        ]);
        $room->forceFill(['team_id' => $team->id])->save();

        $recording = app(CallRecordingService::class)->ensureRequested($room->fresh());

        $this->assertStringStartsWith(
            'recordings/'.$team->recording_storage_uuid.'/'.$room->uuid.'/',
            $recording->storage_key,
        );
        $this->assertSame($team->recording_storage_uuid, $recording->storage_scope_uuid);
    }

    public function test_active_recording_keeps_publish_blocked_until_the_sfu_confirms_permission_update(): void
    {
        [$owner, $room] = $this->roomWithOwner();
        app(CallRecordingService::class)->acknowledge($owner, 'policy-v1');
        $recording = $this->requestedRecording($room);
        $recording->forceFill([
            'status' => RoomRecordingStatus::Active,
            'livekit_egress_id' => 'EG_permission',
            'started_at' => now(),
        ])->save();

        $this->partialMock(LiveKitService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('isConfigured')->andReturnTrue();
            $mock->shouldReceive('setParticipantPublishing')->once()->andReturnFalse();
        });

        $response = $this->actingAs($owner)
            ->postJson(route('calls.token', $room))
            ->assertOk()
            ->assertJsonPath('recording.status', RoomRecordingStatus::Active->value)
            ->assertJsonPath('recording.canPublish', false);

        $this->assertFalse($this->decodeJwtPayload($response->json('token'))['video']['canPublish']);
    }

    public function test_disabled_feature_flag_keeps_the_existing_publishing_flow(): void
    {
        config(['call_recording.enabled' => false]);
        [$owner, $room] = $this->roomWithOwner();

        $response = $this->actingAs($owner)
            ->postJson(route('calls.token', $room))
            ->assertOk()
            ->assertJsonPath('recording.enabled', false)
            ->assertJsonPath('recording.canPublish', true);

        $claims = $this->decodeJwtPayload($response->json('token'));

        $this->assertTrue($claims['video']['canPublish']);
        $this->assertSame(0, RoomRecording::count());
        Queue::assertNothingPushed();
    }

    public function test_egress_active_unlocks_the_recording_and_duplicate_completion_webhooks_are_idempotent(): void
    {
        [, $room] = $this->roomWithOwner();
        $recording = $this->requestedRecording($room);

        $this->mock(CallEgressGateway::class, function (MockInterface $mock) use ($room): void {
            $mock->shouldReceive('start')->once()->andReturn(new EgressSnapshot(
                egressId: 'EG_test',
                roomName: $room->uuid,
                status: EgressStatus::EGRESS_ACTIVE,
                startedAt: CarbonImmutable::now(),
            ));
        });

        $service = app(CallRecordingService::class);

        $this->assertNull($service->start($recording));
        $this->assertSame(RoomRecordingStatus::Active, $recording->fresh()->status);

        $staleStartingEvent = new WebhookEvent([
            'event' => 'egress_updated',
            'id' => 'EV_stale_starting',
            'created_at' => now()->timestamp,
            'egress_info' => new EgressInfo([
                'egress_id' => 'EG_test',
                'room_name' => $room->uuid,
                'status' => EgressStatus::EGRESS_STARTING,
            ]),
        ]);

        $this->assertNull($service->handleWebhook($staleStartingEvent));
        $this->assertSame(RoomRecordingStatus::Active, $recording->fresh()->status);

        $event = new WebhookEvent([
            'event' => 'egress_ended',
            'id' => 'EV_complete',
            'created_at' => now()->timestamp,
            'egress_info' => new EgressInfo([
                'egress_id' => 'EG_test',
                'room_name' => $room->uuid,
                'status' => EgressStatus::EGRESS_COMPLETE,
                'started_at' => (string) (now()->subMinute()->timestamp * 1_000_000_000),
                'ended_at' => (string) (now()->timestamp * 1_000_000_000),
                'file_results' => [new FileInfo([
                    'filename' => 'ignored-livekit-name.mp4',
                    'location' => 's3://private/ignored-livekit-location.mp4',
                    'duration' => '60000000000',
                    'size' => '123456',
                ])],
            ]),
        ]);

        $roomToEnd = $service->handleWebhook($event);
        $this->assertTrue($roomToEnd?->is($room));
        $this->assertNull($service->handleWebhook($event));

        $recording->refresh();
        $this->assertSame(RoomRecordingStatus::Complete, $recording->status);
        $this->assertSame(60_000, $recording->duration_ms);
        $this->assertSame(123456, $recording->size_bytes);
        $this->assertSame(2, LiveKitWebhookReceipt::count());
        $this->assertEquals(90, $recording->completed_at->diffInDays($recording->expires_at));
        $this->assertStringNotContainsString('ignored-livekit', $recording->storage_key);
    }

    public function test_signed_egress_webhook_without_room_payload_is_processed(): void
    {
        [, $room] = $this->roomWithOwner();
        $recording = $this->requestedRecording($room);
        $recording->forceFill([
            'status' => RoomRecordingStatus::Starting,
            'livekit_egress_id' => 'EG_http',
        ])->save();

        $event = new WebhookEvent([
            'event' => 'egress_started',
            'id' => 'EV_http_active',
            'created_at' => now()->timestamp,
            'egress_info' => new EgressInfo([
                'egress_id' => 'EG_http',
                'room_name' => $room->uuid,
                'status' => EgressStatus::EGRESS_ACTIVE,
            ]),
        ]);
        $body = $event->serializeToJsonString();
        $signature = (new AccessToken(
            config('livekit.api_key'),
            config('livekit.api_secret'),
            new AccessTokenOptions(['identity' => 'webhook-test']),
        ))->setSha256(base64_encode(hash('sha256', $body, true)))->toJwt();

        $this->call(
            'POST',
            route('webhooks.livekit'),
            server: [
                'HTTP_AUTHORIZATION' => $signature,
                'CONTENT_TYPE' => 'application/webhook+json',
            ],
            content: $body,
        )->assertOk();

        $this->assertSame(RoomRecordingStatus::Active, $recording->fresh()->status);
        $this->assertDatabaseHas('livekit_webhook_receipts', [
            'event_id' => 'EV_http_active',
            'event_type' => 'egress_started',
        ]);
    }

    public function test_sdk_gateway_builds_a_private_grid_mp4_with_explicit_h264_720p30_options(): void
    {
        [, $room] = $this->roomWithOwner();
        $recording = $this->requestedRecording($room);

        config([
            'call_recording.s3.key' => 'recording-key',
            'call_recording.s3.secret' => 'recording-secret',
            'call_recording.s3.region' => 'eu-central-1',
            'call_recording.s3.bucket' => 'private-recordings',
        ]);

        $client = \Mockery::mock(TimeoutAwareEgressServiceClient::class);
        $client->shouldReceive('startRoomCompositeEgress')
            ->once()
            ->withArgs(function (
                string $roomName,
                string $layout,
                EncodedFileOutput $output,
                EncodingOptions $encoding,
                bool $audioOnly,
                bool $videoOnly,
            ) use ($room, $recording): bool {
                $this->assertSame($room->uuid, $roomName);
                $this->assertSame('grid', $layout);
                $this->assertSame(EncodedFileType::MP4, $output->getFileType());
                $this->assertSame($recording->storage_key, $output->getFilepath());
                $this->assertTrue($output->getDisableManifest());
                $this->assertSame('private-recordings', $output->getS3()->getBucket());
                $this->assertSame(1280, $encoding->getWidth());
                $this->assertSame(720, $encoding->getHeight());
                $this->assertSame(30, $encoding->getFramerate());
                $this->assertSame(VideoCodec::H264_MAIN, $encoding->getVideoCodec());
                $this->assertFalse($audioOnly);
                $this->assertFalse($videoOnly);

                return true;
            })
            ->andReturn(new EgressInfo([
                'egress_id' => 'EG_sdk',
                'room_name' => $room->uuid,
                'status' => EgressStatus::EGRESS_STARTING,
            ]));

        $gateway = new class($client) extends LiveKitEgressGateway
        {
            public function __construct(private readonly TimeoutAwareEgressServiceClient $fakeClient) {}

            protected function client(): TimeoutAwareEgressServiceClient
            {
                return $this->fakeClient;
            }
        };

        $snapshot = $gateway->start($recording);

        $this->assertSame('EG_sdk', $snapshot->egressId);
        $this->assertSame(EgressStatus::EGRESS_STARTING, $snapshot->status);
    }

    public function test_start_deadline_fails_closed(): void
    {
        [, $room] = $this->roomWithOwner();
        $recording = $this->requestedRecording($room, now()->subSecond());

        $roomToEnd = app(CallRecordingService::class)->enforceStartDeadline($recording);

        $this->assertTrue($roomToEnd?->is($room));
        $this->assertSame(RoomRecordingStatus::Failed, $recording->fresh()->status);
        $this->assertSame('egress_start_timeout', $recording->fresh()->error_code);
    }

    public function test_only_the_organizer_can_receive_a_five_minute_inline_playback_url(): void
    {
        [$owner, $room] = $this->roomWithOwner();
        $recording = $this->completedRecording($room);

        Storage::fake('call_recordings');
        Storage::disk('call_recordings')->put($recording->storage_key, 'video');
        Storage::disk('call_recordings')->buildTemporaryUrlsUsing(
            fn (string $path, $expiration, array $options): string => 'https://signed.example.test/'.$path.'?expires='.$expiration->timestamp,
        );

        $this->actingAs($owner)
            ->get('/_testing/calls/'.$room->uuid.'/recordings/'.$recording->uuid)
            ->assertRedirectContains('https://signed.example.test/')
            ->assertHeader('Cache-Control', 'max-age=0, no-store, private');

        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)
            ->get('/_testing/calls/'.$room->uuid.'/recordings/'.$recording->uuid)
            ->assertForbidden();
    }

    public function test_expired_recordings_are_deleted_but_metadata_is_retained(): void
    {
        [, $room] = $this->roomWithOwner();
        $recording = $this->completedRecording($room, now()->subMinute());

        Storage::fake('call_recordings');
        Storage::disk('call_recordings')->put($recording->storage_key, 'video');

        (new PurgeExpiredCallRecordings)->handle(app(RoomEventRecorder::class));

        Storage::disk('call_recordings')->assertMissing(
            "recordings/{$recording->storage_scope_uuid}/{$room->uuid}/{$recording->uuid}.mp4",
        );

        $recording->refresh();
        $this->assertSame(RoomRecordingStatus::Expired, $recording->status);
        $this->assertNull($recording->storage_key);
        $this->assertNotNull($recording->completed_at);
        $this->assertSame(1, $recording->room->events()->where('type', 'recording_expired')->count());
    }

    /** @return array{User, Room} */
    protected function roomWithOwner(): array
    {
        $owner = User::factory()->create(['role' => 'admin']);
        $room = Room::create([
            'name' => 'Vertraulicher Testanruf',
            'type' => 'direct',
            'status' => 'active',
            'owner_id' => $owner->id,
            'started_at' => now(),
        ]);
        $room->participants()->create([
            'user_id' => $owner->id,
            'role' => 'host',
            'connection' => 'invited',
            'livekit_identity' => 'user-'.$owner->id,
        ]);

        return [$owner, $room];
    }

    protected function requestedRecording(Room $room, $deadline = null): RoomRecording
    {
        $uuid = (string) Str::uuid();
        $scope = (string) Str::uuid();

        return RoomRecording::create([
            'uuid' => $uuid,
            'room_id' => $room->id,
            'storage_scope_uuid' => $scope,
            'status' => RoomRecordingStatus::Requested,
            'storage_disk' => 'call_recordings',
            'storage_key' => "recordings/{$scope}/{$room->uuid}/{$uuid}.mp4",
            'mime_type' => 'video/mp4',
            'requested_at' => now()->subMinute(),
            'start_deadline_at' => $deadline ?? now()->addSeconds(30),
        ]);
    }

    protected function completedRecording(Room $room, $expiresAt = null): RoomRecording
    {
        $recording = $this->requestedRecording($room);
        $recording->forceFill([
            'status' => RoomRecordingStatus::Complete,
            'started_at' => now()->subHour(),
            'completed_at' => now()->subMinute(),
            'expires_at' => $expiresAt ?? now()->addDays(90),
            'size_bytes' => 5,
            'duration_ms' => 3_600_000,
        ])->save();

        return $recording;
    }

    /** @return array<string, mixed> */
    protected function decodeJwtPayload(string $token): array
    {
        $payload = explode('.', $token)[1];
        $payload .= str_repeat('=', (4 - strlen($payload) % 4) % 4);

        return json_decode(base64_decode(strtr($payload, '-_', '+/')), true, flags: JSON_THROW_ON_ERROR);
    }
}
